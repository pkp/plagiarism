<?php

/**
 * @file plugins/generic/plagiarism/tests/IThenticateApiTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IThenticateApiTest
 *
 * @brief HTTP-contract tests for the IThenticate API client — the full surface, including
 *        the 409 webhook-registration recovery
 */

namespace APP\plugins\generic\plagiarism\tests;

use APP\journal\Journal;
use APP\plugins\generic\plagiarism\IThenticate;
use APP\submission\Submission;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PKP\author\Author;
use PKP\core\Registry;
use PKP\site\Site;
use PKP\tests\PKPTestCase;
use PKP\user\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[CoversClass(IThenticate::class)]
class IThenticateApiTest extends PKPTestCase
{
    /** @var resource Temp file backing error_log for the duration of each test. */
    protected $tmpErrorLog;
    protected string $originalErrorLog;
    
    private const API_URL = 'https://test.turnitin.com';
    private const SIGNING_SECRET = 'secret123abc';
    private const WEBHOOK_URL = 'https://journal.example.com/index.php/test/$$$call$$$/plugins/generic/plagiarism/controllers/plagiarism-webhook/handle';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalErrorLog = ini_get('error_log');
        $this->tmpErrorLog = tmpfile();
        ini_set('error_log', stream_get_meta_data($this->tmpErrorLog)['uri']);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->originalErrorLog);

        parent::tearDown();
    }

    /**
     * The 409 CONFLICT body iThenticate returns for a duplicate webhook URL.
     */
    private function conflictBody(): string
    {
        return json_encode([
            'success' => false,
            'status' => 409,
            'code' => 'CONFLICT',
            'message' => 'A webhook with the same URL already exists. Either update or delete the existing webhook.',
        ]);
    }

    /**
     * Install a real Guzzle client backed by the given queued responses so that
     * Application::get()->getHttpClient() returns it during the test run, and return the
     * MockHandler so the test can assert how many queued responses were consumed.
     */
    private function installGuzzleQueue(array $responses): MockHandler
    {
        $mock = new MockHandler($responses);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        Registry::set(PKPTestCase::MOCKED_GUZZLE_CLIENT_NAME, $client);

        return $mock;
    }

    private function ithenticate(?string $eulaVersion = null): IThenticate
    {
        return new IThenticate(
            self::API_URL,
            'test-api-key',
            'Plagiarism plugin for OJS/OMP/OPS',
            '1.0.0.0',
            $eulaVersion
        );
    }

    private function user(int $id = 7): User
    {
        $user = new User();
        $user->setId($id);

        return $user;
    }

    // ---------------------------------------------------------------------------------
    // validateAccess() — the one GET that opts out of http_errors, so a non-2xx is
    // RETURNED (not thrown) and the status check runs.
    // ---------------------------------------------------------------------------------

    public function testValidateAccessReturnsTrueAndCapturesBodyOn200(): void
    {
        $features = json_encode(['similarity' => ['viewer_modes' => ['match_overview' => true]]]);
        $mock = $this->installGuzzleQueue([new Response(200, [], $features)]);

        $result = null;
        $ok = $this->ithenticate()->validateAccess($result);

        $this->assertTrue($ok);
        $this->assertSame($features, $result);
        $this->assertSame(0, $mock->count());
    }

    public function testValidateAccessReturnsFalseOn4xxWithoutThrowing(): void
    {
        // http_errors => false here, so a 403 is returned, not thrown: validateAccess
        // simply reports false rather than bubbling an exception.
        $this->installGuzzleQueue([new Response(403, [], json_encode(['message' => 'forbidden']))]);

        $result = 'untouched';
        $ok = $this->ithenticate()->validateAccess($result);

        $this->assertFalse($ok);
        $this->assertSame('untouched', $result, '$result must be left untouched on failure');
    }

    // ---------------------------------------------------------------------------------
    // getEnabledFeature() — builds on validateAccess(); caches the features JSON.
    // ---------------------------------------------------------------------------------

    public function testGetEnabledFeatureReturnsDecodedFeaturesAndSupportsDotNotation(): void
    {
        $features = json_encode([
            'similarity' => ['viewer_modes' => ['match_overview' => true, 'all_sources' => false]],
        ]);
        // Only one request: the features response is cached after the first validateAccess.
        $mock = $this->installGuzzleQueue([new Response(200, [], $features)]);

        $ithenticate = $this->ithenticate();

        $this->assertSame(
            ['match_overview' => true, 'all_sources' => false],
            $ithenticate->getEnabledFeature('similarity.viewer_modes')
        );
        // Second lookup hits the cache, not the network.
        $this->assertIsArray($ithenticate->getEnabledFeature());
        $this->assertSame(0, $mock->count(), 'features must be fetched once and cached');
    }

    // ---------------------------------------------------------------------------------
    // Submission lifecycle methods (string/array inputs).
    // ---------------------------------------------------------------------------------

    public function testGetSubmissionInfoReturnsBodyOn200(): void
    {
        $body = json_encode(['id' => 'sub-uuid', 'status' => 'COMPLETE']);
        $this->installGuzzleQueue([new Response(200, [], $body)]);

        $this->assertSame($body, $this->ithenticate()->getSubmissionInfo('sub-uuid'));
    }

    public function testScheduleSimilarityReportReturnsTrueOn202(): void
    {
        $this->installGuzzleQueue([new Response(202, [], json_encode(['message' => 'scheduled']))]);

        // Pass excludeSmallMatches explicitly: unlike the other settings it is read without
        // a ?? default in the plugin, so omitting it raises an undefined-key notice (an
        // unrelated latent issue, out of scope for this change).
        $this->assertTrue(
            $this->ithenticate()->scheduleSimilarityReportGenerationProcess(
                'sub-uuid',
                ['priority' => 'HIGH', 'excludeSmallMatches' => 10]
            )
        );
    }

    public function testGetSimilarityResultReturnsBodyOn200(): void
    {
        $body = json_encode(['overall_match_percentage' => 12]);
        $this->installGuzzleQueue([new Response(200, [], $body)]);

        $this->assertSame($body, $this->ithenticate()->getSimilarityResult('sub-uuid'));
    }

    public function testUploadFileReturnsTrueOn202(): void
    {
        $this->installGuzzleQueue([new Response(202, [], json_encode(['message' => 'accepted']))]);

        $this->assertTrue($this->ithenticate()->uploadFile('sub-uuid', 'paper.pdf', 'binary-bytes'));
    }

    public function testCreateViewerLaunchUrlReturnsUrlOn200(): void
    {
        $this->installGuzzleQueue([
            new Response(200, [], json_encode(['viewer_url' => 'https://viewer.turnitin.com/abc'])),
        ]);

        $url = $this->ithenticate()->createViewerLaunchUrl('sub-uuid', $this->user(), 'en-US', 'INSTRUCTOR', false);

        $this->assertSame('https://viewer.turnitin.com/abc', $url);
    }

    /**
     * createSubmission() validates the owner/submitter permission BEFORE any HTTP call,
     * so an invalid permission throws without touching the (dummy) domain objects or the
     * network. This covers the guard cheaply; the full 201 path needs a populated
     * Submission/Publication graph and is exercised by integration tests, not here.
     */
    public function testCreateSubmissionThrowsOnInvalidOwnerPermission(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('in valid owner permission INVALID given');

        $this->ithenticate()->createSubmission(
            new Site(),
            new Submission(),
            $this->user(),
            new Author(),
            'INVALID',
            'USER'
        );
    }

    public function testCreateSubmissionThrowsOnInvalidSubmitterPermission(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('in valid submitter permission INVALID given');

        $this->ithenticate()->createSubmission(
            new Site(),
            new Submission(),
            $this->user(),
            new Author(),
            'EDITOR',
            'INVALID'
        );
    }

    // ---------------------------------------------------------------------------------
    // EULA methods.
    // ---------------------------------------------------------------------------------

    public function testValidateEulaVersionReturnsTrueAndStoresDetailsOn200(): void
    {
        $details = ['version' => 'v1beta', 'available_languages' => ['en-US']];
        $this->installGuzzleQueue([new Response(200, [], json_encode($details))]);

        $ithenticate = $this->ithenticate();

        $this->assertTrue($ithenticate->validateEulaVersion('v1beta'));
        $this->assertSame($details, $ithenticate->getEulaDetails());
        // With no version preset, validateEulaVersion adopts the one from the response.
        $this->assertSame('v1beta', $ithenticate->getApplicableEulaVersion());
    }

    public function testVerifyUserEulaAcceptanceReturnsTrueOn200(): void
    {
        $this->installGuzzleQueue([new Response(200, [], json_encode(['accepted' => true]))]);

        $this->assertTrue($this->ithenticate()->verifyUserEulaAcceptance($this->user(), 'v1beta'));
    }

    public function testVerifyUserEulaAcceptanceReturnsFalseWhenSwallowedTo404(): void
    {
        // No http_errors => false here, so the 404 throws and is swallowed to null;
        // verifyUserEulaAcceptance reports false (it cannot tell 404 from a transport error).
        $this->installGuzzleQueue([new Response(404, [], json_encode(['message' => 'not accepted']))]);

        $this->assertFalse($this->ithenticate()->verifyUserEulaAcceptance($this->user(), 'v1beta'));
    }

    /**
     * confirmEula() resolves the request language via getApplicableLocale(), which lazily
     * calls validateEulaVersion() (GET) when no EULA details are cached yet, then POSTs the
     * acceptance. Two requests, in that order.
     */
    public function testConfirmEulaAcceptsOnHappyPath(): void
    {
        $mock = $this->installGuzzleQueue([
            new Response(200, [], json_encode(['version' => 'v1beta', 'available_languages' => ['en-US']])),
            new Response(200, [], json_encode(['message' => 'accepted'])),
        ]);

        $context = new Journal();
        $context->setData('primaryLocale', 'en-US');

        $this->assertTrue($this->ithenticate('v1beta')->confirmEula($this->user(), $context));
        $this->assertSame(0, $mock->count(), 'confirmEula makes the EULA-version GET then the accept POST');
    }

    // ---------------------------------------------------------------------------------
    // Webhook helpers other than registerWebhook (covered by IThenticateWebhookTest).
    // ---------------------------------------------------------------------------------

    public function testDeleteWebhookReturnsTrueOn204(): void
    {
        $this->installGuzzleQueue([new Response(204)]);

        $this->assertTrue($this->ithenticate()->deleteWebhook('webhook-id'));
    }

    public function testValidateWebhookReturnsTrueAndCapturesBodyOn200(): void
    {
        $body = json_encode(['id' => 'webhook-id', 'url' => 'https://x/y']);
        $this->installGuzzleQueue([new Response(200, [], $body)]);

        $result = null;
        $this->assertTrue($this->ithenticate()->validateWebhook('webhook-id', $result));
        $this->assertSame($body, $result);
    }

    public function testListWebhooksReturnsArrayOn200(): void
    {
        $webhooks = [
            ['id' => 'a', 'url' => 'https://x/a'],
            ['id' => 'b', 'url' => 'https://x/b'],
        ];
        $this->installGuzzleQueue([new Response(200, [], json_encode($webhooks))]);

        $this->assertSame($webhooks, $this->ithenticate()->listWebhooks());
    }

    public function testListWebhooksReturnsEmptyArrayOnInvalidJson(): void
    {
        $this->installGuzzleQueue([new Response(200, [], 'not-json')]);

        $this->assertSame([], $this->ithenticate()->listWebhooks());
    }

    public function testFindWebhookIdByUrlMatchesOnUrl(): void
    {
        $this->installGuzzleQueue([new Response(200, [], json_encode([
            ['id' => 'a', 'url' => 'https://x/a'],
            ['id' => 'b', 'url' => 'https://x/b'],
        ]))]);

        $this->assertSame('b', $this->ithenticate()->findWebhookIdByUrl('https://x/b'));
    }

    public function testFindWebhookIdByUrlReturnsNullWhenNoMatch(): void
    {
        $this->installGuzzleQueue([new Response(200, [], json_encode([
            ['id' => 'a', 'url' => 'https://x/a'],
        ]))]);

        $this->assertNull($this->ithenticate()->findWebhookIdByUrl('https://x/missing'));
    }

    // ---------------------------------------------------------------------------------
    // registerWebhook() — 409-conflict recovery (pkp/plagiarism#112). These drive the real
    // http_errors middleware: a Mockery stub of Client::request() would bypass it and could
    // not tell the fixed code from the broken code.
    // ---------------------------------------------------------------------------------

    /**
     * Recovery happy path: POST 409 -> GET list (orphan found) -> DELETE 204 -> POST 201.
     * Asserts the new webhook id from the retry is returned — proving the 409 branch
     * executed end to end (it would return null if the 409 were thrown-and-swallowed).
     */
    public function testRegisterWebhookRecoversFrom409Conflict(): void
    {
        $mock = $this->installGuzzleQueue([
            new Response(409, [], $this->conflictBody()),
            new Response(200, [], json_encode([
                ['id' => 'orphaned-webhook-id', 'url' => self::WEBHOOK_URL],
            ])),
            new Response(204),
            new Response(201, [], json_encode(['id' => 'new-webhook-id', 'url' => self::WEBHOOK_URL])),
        ]);

        $webhookId = $this->ithenticate()->registerWebhook(self::SIGNING_SECRET, self::WEBHOOK_URL, false);

        $this->assertSame('new-webhook-id', $webhookId);
        $this->assertSame(0, $mock->count(), 'Recovery should consume all four queued responses');
    }

    /**
     * Regression guard: even when recovery ultimately fails (orphan not found in the
     * list), the 409 branch must still be REACHED — i.e. the list call must be made.
     * If the 409 were thrown and swallowed to null (the pre-fix bug), only the first
     * POST would be consumed and the registration would bail before listing.
     */
    public function testRegisterWebhookReachesRecoveryBranchOn409(): void
    {
        $mock = $this->installGuzzleQueue([
            new Response(409, [], $this->conflictBody()),
            new Response(200, [], json_encode([])), // empty list -> orphan not found
        ]);

        $webhookId = $this->ithenticate()->registerWebhook(self::SIGNING_SECRET, self::WEBHOOK_URL, false);

        $this->assertNull($webhookId, 'No orphan found means registration cannot recover');
        $this->assertSame(0, $mock->count(), 'The 409 branch must reach the list call (both responses consumed)');
    }

    /**
     * Happy path sanity: a clean 201 still returns the new id and makes exactly one call.
     */
    public function testRegisterWebhookSucceedsOnFirstAttempt(): void
    {
        $mock = $this->installGuzzleQueue([
            new Response(201, [], json_encode(['id' => 'fresh-webhook-id', 'url' => self::WEBHOOK_URL])),
        ]);

        $webhookId = $this->ithenticate()->registerWebhook(self::SIGNING_SECRET, self::WEBHOOK_URL, false);

        $this->assertSame('fresh-webhook-id', $webhookId);
        $this->assertSame(0, $mock->count());
    }

    /**
     * The caller-resolved allow_insecure flag must land verbatim in the webhook registration
     * body — proving the plugin-side derivation actually reaches Turnitin.
     */
    public function testRegisterWebhookSendsAllowInsecureFromParameter(): void
    {
        $mock = $this->installGuzzleQueue([
            new Response(201, [], json_encode(['id' => 'wh-id', 'url' => self::WEBHOOK_URL])),
        ]);

        $this->ithenticate()->registerWebhook(self::SIGNING_SECRET, self::WEBHOOK_URL, true);

        $body = json_decode((string) $mock->getLastRequest()->getBody(), true);
        $this->assertTrue($body['allow_insecure'], 'allow_insecure=true must be sent when passed');
    }

    /**
     * A false flag is sent verbatim too — the service class carries the caller's decision without
     * reading app config itself.
     */
    public function testRegisterWebhookSendsAllowInsecureFalseWhenPassed(): void
    {
        $mock = $this->installGuzzleQueue([
            new Response(201, [], json_encode(['id' => 'wh-id', 'url' => self::WEBHOOK_URL])),
        ]);

        $this->ithenticate()->registerWebhook(self::SIGNING_SECRET, self::WEBHOOK_URL, false);

        $body = json_decode((string) $mock->getLastRequest()->getBody(), true);
        $this->assertFalse($body['allow_insecure'], 'allow_insecure=false must be sent when passed');
    }

    // ---------------------------------------------------------------------------------
    // makeApiRequest() behavior: suppression, opt-out throwing, EULA detection, capture.
    // ---------------------------------------------------------------------------------

    public function testMakeApiRequestSuppressesExceptionToNullByDefault(): void
    {
        // Default options (no http_errors => false) + default suppression: a 500 throws
        // inside Guzzle and is swallowed to null.
        $this->installGuzzleQueue([new Response(500, [], 'boom')]);

        $response = $this->ithenticate()->makeApiRequest('GET', self::API_URL . '/api/v1/whatever');

        $this->assertNull($response);
    }

    public function testMakeApiRequestRethrowsWhenSuppressionDisabled(): void
    {
        $this->installGuzzleQueue([new Response(500, [], 'boom')]);

        $this->expectException(ServerException::class);

        $this->ithenticate()
            ->withoutSuppressingApiRequestException()
            ->makeApiRequest('GET', self::API_URL . '/api/v1/whatever');
    }

    public function testMakeApiRequestCapturesLastResponseDetailsOn200(): void
    {
        $body = json_encode(['ok' => true]);
        $this->installGuzzleQueue([new Response(200, ['X-Test' => 'yes'], $body)]);

        $ithenticate = $this->ithenticate();
        $ithenticate->makeApiRequest('GET', self::API_URL . '/api/v1/ok', ['http_errors' => false]);

        $details = $ithenticate->getLastResponseDetails();
        $this->assertSame(200, $details['status_code']);
        $this->assertSame($body, $ithenticate->getLastResponseBody());
    }

    /**
     * EULA detection via the SUCCESS path: validateAccess() opts out of http_errors, so a
     * 451 is returned (not thrown) and isEulaMismatchResponse() flags it (Rule 0).
     */
    public function testMakeApiRequestDetectsEulaErrorViaSuccessPathOn451(): void
    {
        $this->installGuzzleQueue([new Response(451, [], json_encode(['message' => 'EULA required']))]);

        $ithenticate = $this->ithenticate();
        $ok = $ithenticate->validateAccess();

        $this->assertFalse($ok);
        $this->assertTrue($ithenticate->hasDetectedEulaError());
    }

    /**
     * EULA detection via the CATCH path: a POST /eula/{v}/accept that 404s throws (no
     * http_errors opt-out), and the catch block still runs isEulaMismatchResponse() (Rule A)
     * before swallowing the exception to null.
     */
    public function testMakeApiRequestDetectsEulaErrorViaCatchPathOn404Accept(): void
    {
        $this->installGuzzleQueue([new Response(404, [], json_encode(['message' => 'no such eula']))]);

        $ithenticate = $this->ithenticate();
        $response = $ithenticate->makeApiRequest(
            'POST',
            self::API_URL . '/api/v1/eula/v1beta/accept'
        );

        $this->assertNull($response, 'the 404 is thrown then swallowed to null');
        $this->assertTrue($ithenticate->hasDetectedEulaError());
    }

    // ---------------------------------------------------------------------------------
    // Characterization of the KNOWN LATENT DEFECT (exceptions vs http_errors).
    // These assert the CURRENT behavior. If a future change adds/update the current
    // http_errors => false to these methods, update these expectations deliberately.
    // ---------------------------------------------------------------------------------

    public function testGetSubmissionInfoSwallows404ToNull_knownLatentDefect(): void
    {
        $this->installGuzzleQueue([new Response(404, [], json_encode(['message' => 'not found']))]);

        // Indistinguishable from a transport failure today: both yield null.
        $this->assertNull($this->ithenticate()->getSubmissionInfo('missing-uuid'));
    }

    public function testListWebhooksSwallows403ToEmptyArray_knownLatentDefect(): void
    {
        $this->installGuzzleQueue([new Response(403, [], json_encode(['message' => 'forbidden']))]);

        // A 403 is indistinguishable from "no webhooks registered" today.
        $this->assertSame([], $this->ithenticate()->listWebhooks());
    }
}
