<?php

/**
 * @file plugins/generic/plagiarism/tests/PlagiarismWebhookTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PlagiarismWebhookTest
 *
 * @brief Unit tests for the webhook store/update/re-claim/self-heal mechanism
 */

namespace APP\plugins\generic\plagiarism\tests;

use APP\core\Application;
use APP\plugins\generic\plagiarism\classes\IThenticateWebhookManager;
use APP\plugins\generic\plagiarism\controllers\PlagiarismWebhookHandler;
use APP\plugins\generic\plagiarism\IThenticate;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;
use APP\plugins\generic\plagiarism\TestIThenticate;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use PKP\context\Context;
use PKP\plugins\Hook;
use PKP\services\PKPSchemaService;
use PKP\tests\PKPTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(IThenticateWebhookManager::class)]
#[CoversClass(PlagiarismWebhookHandler::class)]
class PlagiarismWebhookTest extends PKPTestCase
{
    private const URL = 'https://x.turnitin.com';
    private const KEY = 'API-KEY-abc123';
    private const SITE_URL = 'https://site.example/index.php/index/$$$call$$$/plugins/generic/plagiarism/controllers/plagiarism-webhook/handle';

    /** @var resource */
    protected $tmpErrorLog;
    protected string $originalErrorLog;

    protected function setUp(): void
    {
        parent::setUp();
        // COMPONENT_ROUTER_PATHINFO_MARKER is a file-level define() in PKPComponentRouter; ensure that file
        // is autoloaded so restoreSiteContextSegment() can reference the marker in these isolated tests
        // (at runtime it is always defined — getSiteWebhookUrl() calls the dispatcher first).
        class_exists(\PKP\core\PKPComponentRouter::class);

        // Redirect error_log to a throwaway file so the suite does not spam the real error log
        $this->originalErrorLog = ini_get('error_log');
        $this->tmpErrorLog = tmpfile();
        ini_set('error_log', stream_get_meta_data($this->tmpErrorLog)['uri']);

        // The registry-encryption assertions run real Crypt::encrypt/decrypt. Swap in a deterministic
        // throwaway encrypter so these tests never depend on the deployment's real app_key (or on one
        // being configured at all) — the key lives only for the duration of the test.
        Crypt::swap(new Encrypter(str_repeat('a', 32), 'aes-256-cbc'));
    }

    protected function tearDown(): void
    {
        // Restore the real error_log target.
        ini_set('error_log', $this->originalErrorLog);

        // Drop the test encrypter so any later test resolves the real one from config again.
        Crypt::clearResolvedInstance('encrypter');
        app()->forgetInstance('encrypter');
        parent::tearDown();
    }

    /**
     * Build an IThenticateWebhookManager backed by a PlagiarismPlugin double as an in-memory store
     *
     * @return array{0: PlagiarismPluginWebhookTestDouble, 1: IThenticateWebhookManagerTestDouble}
     */
    private function makeWebhookManager(?IThenticate $mock = null): array
    {
        $plugin = new PlagiarismPluginWebhookTestDouble();
        $plugin->mock = $mock;
        $plugin->serviceAccess = [self::URL, self::KEY];

        $manager = new IThenticateWebhookManagerTestDouble($plugin);
        $manager->siteUrl = self::SITE_URL;

        return [$plugin, $manager];
    }

    private function sampleEntry(): array
    {
        return [
            'webhookId' => 'wh-uuid-1',
            'signingSecret' => 'secret-32-chars',
            'updatedAt' => '2026-07-29 00:00:00'
        ];
    }

    //
    // Credential fingerprint — the grouping-only digest that collapses contexts sharing the
    // same (api_url, api_key) onto one credential scope.
    //

    public function testTrailingSlashDoesNotAffectFingerprint(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $this->assertSame(
            $manager->credentialFingerprint(self::URL, self::KEY),
            $manager->credentialFingerprint(self::URL . '/', self::KEY)
        );
    }

    public function testUrlCaseDoesNotAffectFingerprint(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $this->assertSame(
            $manager->credentialFingerprint(self::URL, self::KEY),
            $manager->credentialFingerprint('https://X.Turnitin.COM', self::KEY)
        );
    }

    public function testSurroundingWhitespaceDoesNotAffectFingerprint(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $this->assertSame(
            $manager->credentialFingerprint(self::URL, self::KEY),
            $manager->credentialFingerprint('  ' . self::URL . '  ', self::KEY)
        );
    }

    public function testApiKeyIsByteExactCaseSensitive(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $this->assertNotSame(
            $manager->credentialFingerprint(self::URL, 'api-key-abc123'),
            $manager->credentialFingerprint(self::URL, 'API-KEY-abc123')
        );
    }

    public function testDifferentHostProducesDifferentFingerprint(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $this->assertNotSame(
            $manager->credentialFingerprint('https://a.turnitin.com', self::KEY),
            $manager->credentialFingerprint('https://b.turnitin.com', self::KEY)
        );
    }

    public function testFieldSeparatorPreventsRunTogetherCollision(): void
    {
        [, $manager] = $this->makeWebhookManager();
        // Without a separator, ('ab','c') and ('a','bc') would hash identically.
        $this->assertNotSame(
            $manager->credentialFingerprint('https://x.turnitin.comab', 'c'),
            $manager->credentialFingerprint('https://x.turnitin.coma', 'bc')
        );
    }

    public function testFingerprintIsSha256Hex(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $manager->credentialFingerprint(self::URL, self::KEY));
    }

    //
    // Site-level webhook registry — fingerprint => {webhookId, signingSecret, updatedAt},
    // stored as JSON at the site context, no credentials persisted.
    //

    public function testEmptyRegistryReturnsEmptyArray(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $this->assertSame([], $manager->getWebhookRegistry());
    }

    public function testPutEntryRoundTrips(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $fingerprint = $manager->credentialFingerprint(self::URL, self::KEY);

        $manager->putRegistryEntry($fingerprint, $this->sampleEntry());

        $this->assertSame($this->sampleEntry(), $manager->getWebhookRegistry()[$fingerprint]);
    }

    public function testGetEntryForCredentialsResolvesByFingerprint(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $manager->putRegistryEntry($manager->credentialFingerprint(self::URL, self::KEY), $this->sampleEntry());

        $this->assertSame($this->sampleEntry(), $manager->getRegistryEntryForCredentials(self::URL, self::KEY));
    }

    public function testGetEntryForUnknownCredentialsReturnsNull(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $this->assertNull($manager->getRegistryEntryForCredentials(self::URL, self::KEY));
    }

    public function testRemoveEntryDeletesIt(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $fingerprint = $manager->credentialFingerprint(self::URL, self::KEY);
        $manager->putRegistryEntry($fingerprint, $this->sampleEntry());

        $manager->removeRegistryEntry($fingerprint);

        $this->assertSame([], $manager->getWebhookRegistry());
    }

    public function testMalformedStoredJsonSelfHealsToEmpty(): void
    {
        [$plugin, $manager] = $this->makeWebhookManager();
        $plugin->store[Application::SITE_CONTEXT_ID]['ithenticateWebhookRegistry'] = 'not-valid-json{';

        $this->assertSame([], $manager->getWebhookRegistry());
    }

    public function testSaveRegistryEncryptsSigningSecretAndPersistsAsJsonString(): void
    {
        [$plugin, $manager] = $this->makeWebhookManager();
        $fingerprint = $manager->credentialFingerprint(self::URL, self::KEY);

        $manager->saveWebhookRegistry([$fingerprint => $this->sampleEntry()]);

        $raw = $plugin->store[Application::SITE_CONTEXT_ID]['ithenticateWebhookRegistry'];
        $this->assertIsString($raw);
        $stored = json_decode($raw, true);

        // webhookId, updatedAt and the fingerprint key are stored readable at rest ...
        $this->assertSame('wh-uuid-1', $stored[$fingerprint]['webhookId']);
        $this->assertSame('2026-07-29 00:00:00', $stored[$fingerprint]['updatedAt']);
        // ... but the signing secret is encrypted (not the plaintext) and decrypts back to it.
        $this->assertNotSame('secret-32-chars', $stored[$fingerprint]['signingSecret']);
        $this->assertSame('secret-32-chars', Crypt::decrypt($stored[$fingerprint]['signingSecret']));

        // Reading through the manager transparently decrypts back to the original entry.
        $this->assertSame($this->sampleEntry(), $manager->getWebhookRegistry()[$fingerprint]);
    }

    public function testEntryWithUndecryptableSecretIsDroppedOnRead(): void
    {
        [$plugin, $manager] = $this->makeWebhookManager();
        $fingerprint = $manager->credentialFingerprint(self::URL, self::KEY);

        // A stored entry whose signing secret is not valid ciphertext (e.g. app_key rotated or
        // the DB restored onto a different host) is dropped on read so the scope re-registers.
        $plugin->store[Application::SITE_CONTEXT_ID]['ithenticateWebhookRegistry'] = json_encode([
            $fingerprint => [
                'webhookId' => 'wh-uuid-1',
                'signingSecret' => 'not-valid-ciphertext',
                'updatedAt' => '2026-07-29 00:00:00',
            ],
        ]);

        $this->assertSame([], $manager->getWebhookRegistry());
    }

    //
    // List-first register/reuse (one webhook per scope, within the 10-per-scope limit) and the
    // per-submission hot path (ensureWebhookForContext).
    //

    public function testFreshScopeCreatesAndStoresWebhook(): void
    {
        $mock = $this->createMock(IThenticate::class);
        $mock->expects($this->never())->method('validateWebhook');
        $mock->method('findWebhookIdByUrl')->willReturn(null);
        $mock->expects($this->once())->method('registerWebhook')->willReturn('new-webhook-id');

        [, $manager] = $this->makeWebhookManager($mock);

        $this->assertTrue($manager->registerOrReuseWebhookForScope(self::URL, self::KEY));

        $entry = $manager->getRegistryEntryForCredentials(self::URL, self::KEY);
        $this->assertSame('new-webhook-id', $entry['webhookId']);
        $this->assertSame(32, strlen($entry['signingSecret']));
    }

    public function testValidExistingEntryIsReusedWithoutRegistering(): void
    {
        $mock = $this->createMock(IThenticate::class);
        $mock->method('validateWebhook')->with('wh-existing')->willReturn(true);
        $mock->expects($this->never())->method('findWebhookIdByUrl');
        $mock->expects($this->never())->method('registerWebhook');

        [, $manager] = $this->makeWebhookManager($mock);
        $fingerprint = $manager->credentialFingerprint(self::URL, self::KEY);
        $manager->putRegistryEntry($fingerprint, ['webhookId' => 'wh-existing', 'signingSecret' => 's', 'updatedAt' => 't']);

        $this->assertTrue($manager->registerOrReuseWebhookForScope(self::URL, self::KEY));
        $this->assertSame('wh-existing', $manager->getWebhookRegistry()[$fingerprint]['webhookId']);
    }

    public function testStaleEntryWithListHitDeletesAndRecreates(): void
    {
        $mock = $this->createMock(IThenticate::class);
        $mock->method('validateWebhook')->with('wh-stale')->willReturn(false);
        $mock->method('findWebhookIdByUrl')->willReturn('wh-listed');
        $mock->expects($this->once())->method('deleteWebhook')->with('wh-listed')->willReturn(true);
        $mock->expects($this->once())->method('registerWebhook')->willReturn('wh-new');

        [, $manager] = $this->makeWebhookManager($mock);
        $fingerprint = $manager->credentialFingerprint(self::URL, self::KEY);
        $manager->putRegistryEntry($fingerprint, ['webhookId' => 'wh-stale', 'signingSecret' => 's', 'updatedAt' => 't']);

        $this->assertTrue($manager->registerOrReuseWebhookForScope(self::URL, self::KEY));
        $this->assertSame('wh-new', $manager->getWebhookRegistry()[$fingerprint]['webhookId']);
    }

    public function testListHitWithoutStoredEntryDeletesAndRecreates(): void
    {
        $mock = $this->createMock(IThenticate::class);
        $mock->method('findWebhookIdByUrl')->willReturn('wh-listed');
        $mock->expects($this->once())->method('deleteWebhook')->with('wh-listed')->willReturn(true);
        $mock->expects($this->once())->method('registerWebhook')->willReturn('wh-new');

        [, $manager] = $this->makeWebhookManager($mock);

        $this->assertTrue($manager->registerOrReuseWebhookForScope(self::URL, self::KEY));
        $this->assertSame('wh-new', $manager->getRegistryEntryForCredentials(self::URL, self::KEY)['webhookId']);
    }

    public function testRegisterFailureReturnsFalseAndLeavesRegistryUnchanged(): void
    {
        $mock = $this->createMock(IThenticate::class);
        $mock->method('findWebhookIdByUrl')->willReturn(null);
        $mock->method('registerWebhook')->willReturn(null);

        [, $manager] = $this->makeWebhookManager($mock);

        $this->assertFalse($manager->registerOrReuseWebhookForScope(self::URL, self::KEY));
        $this->assertSame([], $manager->getWebhookRegistry());
    }

    public function testEnsureWebhookIsNoopWhenScopeAlreadyRegistered(): void
    {
        $mock = $this->createMock(IThenticate::class);
        $mock->expects($this->never())->method('registerWebhook');

        [, $manager] = $this->makeWebhookManager($mock);
        $manager->putRegistryEntry(
            $manager->credentialFingerprint(self::URL, self::KEY),
            ['webhookId' => 'wh-existing', 'signingSecret' => 's', 'updatedAt' => 't']
        );

        $this->assertTrue($manager->ensureWebhookForContext($this->createMock(Context::class)));
        $this->assertSame(0, $manager->registerOrReuseCalls);
    }

    public function testEnsureWebhookRegistersWhenScopeMissing(): void
    {
        $mock = $this->createMock(IThenticate::class);
        $mock->method('findWebhookIdByUrl')->willReturn(null);
        $mock->method('registerWebhook')->willReturn('wh-new');

        [, $manager] = $this->makeWebhookManager($mock);

        $this->assertTrue($manager->ensureWebhookForContext($this->createMock(Context::class)));
        $this->assertSame(1, $manager->registerOrReuseCalls);
        $this->assertSame('wh-new', $manager->getRegistryEntryForCredentials(self::URL, self::KEY)['webhookId']);
    }

    public function testEnsureWebhookRevalidateReusesHealthyWebhook(): void
    {
        $mock = $this->createMock(IThenticate::class);
        $mock->method('validateWebhook')->with('wh-existing')->willReturn(true);
        $mock->expects($this->never())->method('registerWebhook');

        [, $manager] = $this->makeWebhookManager($mock);
        $manager->putRegistryEntry(
            $manager->credentialFingerprint(self::URL, self::KEY),
            ['webhookId' => 'wh-existing', 'signingSecret' => 's', 'updatedAt' => 't']
        );

        // revalidate=true bypasses the entry-exists fast-path and validates the stored id at the API;
        // a healthy webhook is reused (no re-registration).
        $this->assertTrue($manager->ensureWebhookForContext($this->createMock(Context::class), true));
        $this->assertSame(1, $manager->registerOrReuseCalls);
        $this->assertSame('wh-existing', $manager->getRegistryEntryForCredentials(self::URL, self::KEY)['webhookId']);
    }

    public function testEnsureWebhookRevalidateHealsStaleWebhook(): void
    {
        $mock = $this->createMock(IThenticate::class);
        $mock->method('validateWebhook')->with('wh-stale')->willReturn(false);
        $mock->method('findWebhookIdByUrl')->willReturn('wh-listed');
        $mock->expects($this->once())->method('deleteWebhook')->with('wh-listed')->willReturn(true);
        $mock->expects($this->once())->method('registerWebhook')->willReturn('wh-new');

        [, $manager] = $this->makeWebhookManager($mock);
        $manager->putRegistryEntry(
            $manager->credentialFingerprint(self::URL, self::KEY),
            ['webhookId' => 'wh-stale', 'signingSecret' => 's', 'updatedAt' => 't']
        );

        // revalidate=true finds the stored webhook invalid at the API and re-registers it (heal).
        $this->assertTrue($manager->ensureWebhookForContext($this->createMock(Context::class), true));
        $this->assertSame(1, $manager->registerOrReuseCalls);
        $this->assertSame('wh-new', $manager->getRegistryEntryForCredentials(self::URL, self::KEY)['webhookId']);
    }

    //
    // Self healing: an install already at iThenticate's 10-webhooks-per-scope limit
    // cannot create the site webhook (no free slot). On a full-scope create failure the manager
    // reclaims exactly ONE of its OWN legacy webhook slots and retries once. The capacity gate
    // (count(listWebhooks()) >= MAX) doubles as the auth/network guard, because a failed/auth-less
    // listWebhooks() returns [] (count 0), suppressing any destructive reclaim.
    //

    private function fullScopeList(): array
    {
        return array_fill(0, IThenticateWebhookManager::MAX_WEBHOOKS_PER_SCOPE, ['id' => 'x', 'url' => 'u']);
    }

    public function testFullScopeReclaimsOneSlotThenRetriesAndStores(): void
    {
        $mock = $this->createMock(IThenticate::class);
        $mock->method('findWebhookIdByUrl')->willReturn(null);
        $mock->method('listWebhooks')->willReturn($this->fullScopeList());
        // First create fails (scope full); after reclaiming a slot the retry succeeds.
        $mock->expects($this->exactly(2))->method('registerWebhook')
            ->willReturnOnConsecutiveCalls(null, 'wh-after-reclaim');

        [, $manager] = $this->makeWebhookManager($mock);
        $manager->reclaimable = [$this->createMock(Context::class), 'legacy-id-1'];

        $this->assertTrue($manager->registerOrReuseWebhookForScope(self::URL, self::KEY));
        $this->assertSame(1, $manager->deleteLegacyCalls);
        $this->assertSame('wh-after-reclaim', $manager->getRegistryEntryForCredentials(self::URL, self::KEY)['webhookId']);
    }

    public function testFullScopeGuardSkipsReclaimWhenListBelowCapacity(): void
    {
        $mock = $this->createMock(IThenticate::class);
        $mock->method('findWebhookIdByUrl')->willReturn(null);
        $mock->method('listWebhooks')->willReturn(array_fill(0, 3, ['id' => 'x', 'url' => 'u'])); // below capacity
        $mock->method('registerWebhook')->willReturn(null);

        [, $manager] = $this->makeWebhookManager($mock);
        $manager->reclaimable = [$this->createMock(Context::class), 'legacy-id-1']; // present, but gate must skip it

        $this->assertFalse($manager->registerOrReuseWebhookForScope(self::URL, self::KEY));
        $this->assertSame(0, $manager->deleteLegacyCalls); // no destructive reclaim on a non-capacity failure
        $this->assertSame([], $manager->getWebhookRegistry());
    }

    public function testFullScopeWithNoReclaimablePluginWebhookReturnsFalse(): void
    {
        $mock = $this->createMock(IThenticate::class);
        $mock->method('findWebhookIdByUrl')->willReturn(null);
        $mock->method('listWebhooks')->willReturn($this->fullScopeList());
        $mock->method('registerWebhook')->willReturn(null);

        [, $manager] = $this->makeWebhookManager($mock);
        $manager->reclaimable = null; // all slots foreign / none plugin-owned → nothing to reclaim

        $this->assertFalse($manager->registerOrReuseWebhookForScope(self::URL, self::KEY));
        $this->assertSame(0, $manager->deleteLegacyCalls);
        $this->assertSame([], $manager->getWebhookRegistry());
    }

    public function testFullScopeGuardTreatsEmptyListAsBelowCapacity(): void
    {
        $mock = $this->createMock(IThenticate::class);
        $mock->method('findWebhookIdByUrl')->willReturn(null);
        $mock->method('listWebhooks')->willReturn([]); // auth/network failure surfaces as an empty list
        $mock->method('registerWebhook')->willReturn(null);

        [, $manager] = $this->makeWebhookManager($mock);
        $manager->reclaimable = [$this->createMock(Context::class), 'legacy-id-1'];

        $this->assertFalse($manager->registerOrReuseWebhookForScope(self::URL, self::KEY));
        $this->assertSame(0, $manager->deleteLegacyCalls); // never reclaim when the scope state is unknown
    }

    public function testReclaimDeletesAtMostOneWhenRetryStillFails(): void
    {
        $mock = $this->createMock(IThenticate::class);
        $mock->method('findWebhookIdByUrl')->willReturn(null);
        $mock->method('listWebhooks')->willReturn($this->fullScopeList());
        $mock->expects($this->exactly(2))->method('registerWebhook')->willReturn(null); // both attempts fail

        [, $manager] = $this->makeWebhookManager($mock);
        $manager->reclaimable = [$this->createMock(Context::class), 'legacy-id-1'];

        $this->assertFalse($manager->registerOrReuseWebhookForScope(self::URL, self::KEY));
        $this->assertSame(1, $manager->deleteLegacyCalls); // exactly one delete, no cascade
        $this->assertSame([], $manager->getWebhookRegistry());
    }

    //
    // HMAC authentication matcher (PlagiarismWebhookHandler::matchSigningSecret): the handler
    // authenticates against BOTH the registry secrets and the delivering context's legacy secret,
    // returning on the first hash_equals hit and never leaking the matched secret.
    //

    private const BODY = '{"id":"3c1f...uuid","status":"COMPLETE"}';

    private function signWith(string $secret): string
    {
        return hash_hmac('sha256', self::BODY, $secret);
    }

    public function testMatchesCorrectSecretEvenWhenNotFirstCandidate(): void
    {
        $candidates = [
            ['via' => 'registry', 'fingerprint' => 'fpA', 'secret' => 'secret-A'],
            ['via' => 'registry', 'fingerprint' => 'fpB', 'secret' => 'secret-B'],
        ];

        $match = PlagiarismWebhookHandler::matchSigningSecret(self::BODY, $this->signWith('secret-B'), $candidates);

        $this->assertSame(['via' => 'registry', 'fingerprint' => 'fpB'], $match);
    }

    public function testReturnsNullWhenNoSecretMatches(): void
    {
        $candidates = [
            ['via' => 'registry', 'fingerprint' => 'fpA', 'secret' => 'secret-A'],
            ['via' => 'legacy', 'contextId' => 7, 'secret' => 'secret-legacy'],
        ];

        $match = PlagiarismWebhookHandler::matchSigningSecret(self::BODY, $this->signWith('some-other-secret'), $candidates);

        $this->assertNull($match);
    }

    public function testIdentifiesLegacyContextMatchWithoutLeakingSecret(): void
    {
        $candidates = [
            ['via' => 'registry', 'fingerprint' => 'fpA', 'secret' => 'secret-A'],
            ['via' => 'legacy', 'contextId' => 42, 'secret' => 'secret-legacy'],
        ];

        $match = PlagiarismWebhookHandler::matchSigningSecret(self::BODY, $this->signWith('secret-legacy'), $candidates);

        $this->assertSame(['via' => 'legacy', 'contextId' => 42], $match);
        $this->assertArrayNotHasKey('secret', $match);
    }

    public function testTamperedBodyDoesNotMatch(): void
    {
        $candidates = [['via' => 'registry', 'fingerprint' => 'fpA', 'secret' => 'secret-A']];

        // Signature computed over the real body, but a different body is presented.
        $signature = $this->signWith('secret-A');
        $match = PlagiarismWebhookHandler::matchSigningSecret('{"id":"tampered"}', $signature, $candidates);

        $this->assertNull($match);
    }

    public function testEmptyCandidateSetReturnsNull(): void
    {
        $this->assertNull(PlagiarismWebhookHandler::matchSigningSecret(self::BODY, $this->signWith('x'), []));
    }

    //
    // Webhook-critical schema wiring: the context-independent delivery loads the plugin at the site
    // context where it MAY NOT enabled, so the submission-file schema hook must be registered
    // unconditionally (before the enabled gate in register()). Without it, EntityDAO::fromRow skips
    // the ithenticate settings on read and sanitize() strips them on write — the delivery returns 200
    // while silently persisting nothing.
    //

    public function testWebhookSchemaHookExposesSubmissionFilePropsWhenPluginNotEnabled(): void
    {
        $plugin = new PlagiarismPluginWebhookTestDouble();
        $plugin->registerWebhookSchemaHooks();

        $schema = (object) ['properties' => new \stdClass()];
        Hook::call('Schema::get::' . PKPSchemaService::SCHEMA_SUBMISSION_FILE, [&$schema]);

        // The fields the webhook handler reads/writes must be present so EntityDAO::fromRow loads
        // them and sanitize()/updateSettings persist them.
        $this->assertObjectHasProperty('ithenticateId', $schema->properties);
        $this->assertObjectHasProperty('ithenticateSimilarityScheduled', $schema->properties);
        $this->assertObjectHasProperty('ithenticateSimilarityResult', $schema->properties);
        $this->assertObjectHasProperty('ithenticateSubmissionAcceptedAt', $schema->properties);
        $this->assertObjectHasProperty('ithenticateProcessingError', $schema->properties);
    }

    //
    // Site webhook URL under a `base_url[index]` override. OJS's dispatcher collapses the `index` context
    // and drops `/index.php` when that override is set, yielding `{override}/$$$call$$$/…` (marker at path
    // position 0), which never routes back to the handler. restoreSiteContextSegment() takes that collapsed
    // dispatcher output and puts the site-context segment `index` (position 0) + entry point back, so the
    // inbound PATH_INFO is `/index/$$$call$$$/…` for a bare host or any subdirectory, in both restful modes —
    // reusing the marker-onward suffix verbatim so the handler path is never duplicated. (Branch selection in
    // getSiteWebhookUrl() — reading base_url[index] + restful_urls — is glue covered by the manual e2e.)
    //
    private const ENDPOINT = '$$$call$$$/plugins/generic/plagiarism/controllers/plagiarism-webhook/handle';

    public function testOverriddenSiteUrlBareHostNonRestful(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $this->assertSame(
            'https://myinstallation.com/index.php/index/' . self::ENDPOINT,
            $manager->exposeRestoreSiteContextSegment('https://myinstallation.com/' . self::ENDPOINT, false)
        );
    }

    public function testOverriddenSiteUrlBareHostRestful(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $this->assertSame(
            'https://myinstallation.com/index/' . self::ENDPOINT,
            $manager->exposeRestoreSiteContextSegment('https://myinstallation.com/' . self::ENDPOINT, true)
        );
    }

    public function testOverriddenSiteUrlWithSubdirectoryNonRestful(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $this->assertSame(
            'https://myinstallation.com/admin/index.php/index/' . self::ENDPOINT,
            $manager->exposeRestoreSiteContextSegment('https://myinstallation.com/admin/' . self::ENDPOINT, false)
        );
    }

    public function testOverriddenSiteUrlWithSubdirectoryRestful(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $this->assertSame(
            'https://myinstallation.com/admin/index/' . self::ENDPOINT,
            $manager->exposeRestoreSiteContextSegment('https://myinstallation.com/admin/' . self::ENDPOINT, true)
        );
    }

    public function testOverriddenSiteUrlWithMultiSegmentSubdirectory(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $this->assertSame(
            'https://myinstallation.com/manager/admin/index.php/index/' . self::ENDPOINT,
            $manager->exposeRestoreSiteContextSegment('https://myinstallation.com/manager/admin/' . self::ENDPOINT, false)
        );
    }

    public function testOverriddenSiteUrlDoesNotDoubleAppendIndexPhp(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $this->assertSame(
            'https://myinstallation.com/index.php/index/' . self::ENDPOINT,
            $manager->exposeRestoreSiteContextSegment('https://myinstallation.com/index.php/' . self::ENDPOINT, false)
        );
    }

    public function testWebhookUrlWithoutMarkerIsReturnedUnchanged(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $unexpected = 'https://myinstallation.com/some/other/path';
        $this->assertSame($unexpected, $manager->exposeRestoreSiteContextSegment($unexpected, false));
    }

    public function testResolveWebhookUrlForScopeReturnsSiteUrlWhenScopeRegistered(): void
    {
        [, $manager] = $this->makeWebhookManager();
        $manager->putRegistryEntry($manager->credentialFingerprint(self::URL, self::KEY), $this->sampleEntry());

        $this->assertSame(self::SITE_URL, $manager->resolveWebhookUrlForScope(self::URL, self::KEY));
    }
}

/**
 * In-memory PlagiarismPlugin mocked for the webhook tests
 */
class PlagiarismPluginWebhookTestDouble extends PlagiarismPlugin
{
    public array $store = [];
    public ?IThenticate $mock = null;
    public array $serviceAccess = ['', ''];

    public function getSetting($contextId, $name)
    {
        return $this->store[$contextId][$name] ?? null;
    }

    public function updateSetting($contextId, $name, $value, $type = null)
    {
        $this->store[$contextId][$name] = $value;
    }

    public function initIthenticate(
        string $apiUrl,
        string $apiKey,
        string $integrationName = self::PLUGIN_INTEGRATION_NAME,
        ?string $integrationVersion = null
    ): IThenticate|TestIThenticate {
        return $this->mock;
    }

    public function getServiceAccess(?Context $context = null): array
    {
        return $this->serviceAccess;
    }
}

/**
 * IThenticateWebhookManager mocked double
 */
class IThenticateWebhookManagerTestDouble extends IThenticateWebhookManager
{
    public string $siteUrl = '';
    public int $registerOrReuseCalls = 0;

    // Reclaim seams: the real implementations enumerate contexts via the DAO and mutate
    // context settings (DB-backed, covered by the manual e2e); the double stubs them so the
    // capacity-gate + reclaim/retry orchestration is exercised in isolation.
    public ?array $reclaimable = null;      // [Context, legacyWebhookId] or null
    public int $deleteLegacyCalls = 0;
    public bool $deleteLegacyReturn = true;

    public function getSiteWebhookUrl(): string
    {
        return $this->siteUrl;
    }

    // Expose the real (protected) base_url[index] URL rebuilder for direct unit testing.
    public function exposeRestoreSiteContextSegment(string $collapsedUrl, bool $restfulUrlsEnabled): string
    {
        return $this->restoreSiteContextSegment($collapsedUrl, $restfulUrlsEnabled);
    }

    public function registerOrReuseWebhookForScope(string $apiUrl, string $apiKey): bool
    {
        $this->registerOrReuseCalls++;
        return parent::registerOrReuseWebhookForScope($apiUrl, $apiKey);
    }

    protected function findReclaimableLegacyWebhook(string $fingerprint): ?array
    {
        return $this->reclaimable;
    }

    protected function deleteLegacyWebhookForContext(Context $context, string $legacyId): bool
    {
        $this->deleteLegacyCalls++;
        return $this->deleteLegacyReturn;
    }
}
