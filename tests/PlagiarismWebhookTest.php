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
 * @brief Unit tests for the "one webhook per credential scope" mechanism
 */

namespace APP\plugins\generic\plagiarism\tests;

use APP\core\Application;
use APP\plugins\generic\plagiarism\classes\IThenticateWebhookManager;
use APP\plugins\generic\plagiarism\controllers\PlagiarismWebhookHandler;
use APP\plugins\generic\plagiarism\IThenticate;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;
use APP\plugins\generic\plagiarism\TestIThenticate;
use PKP\context\Context;
use PKP\tests\PKPTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(IThenticateWebhookManager::class)]
#[CoversClass(PlagiarismWebhookHandler::class)]
class PlagiarismWebhookTest extends PKPTestCase
{
    private const URL = 'https://x.turnitin.com';
    private const KEY = 'API-KEY-abc123';
    private const SITE_URL = 'https://site.example/index.php/index/$$$call$$$/plugins/generic/plagiarism/controllers/plagiarism-webhook/handle';

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

    public function testSaveRegistryPersistsAsJsonString(): void
    {
        [$plugin, $manager] = $this->makeWebhookManager();
        $fingerprint = $manager->credentialFingerprint(self::URL, self::KEY);

        $manager->saveWebhookRegistry([$fingerprint => $this->sampleEntry()]);

        $raw = $plugin->store[Application::SITE_CONTEXT_ID]['ithenticateWebhookRegistry'];
        $this->assertIsString($raw);
        $this->assertSame([$fingerprint => $this->sampleEntry()], json_decode($raw, true));
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

    public function getSiteWebhookUrl(): string
    {
        return $this->siteUrl;
    }

    public function registerOrReuseWebhookForScope(string $apiUrl, string $apiKey): bool
    {
        $this->registerOrReuseCalls++;
        return parent::registerOrReuseWebhookForScope($apiUrl, $apiKey);
    }
}
