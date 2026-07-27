<?php
/**
 * @file plugins/generic/plagiarism/TestIThenticate.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class TestIThenticate
 *
 * @brief   Low-budget mock class for IThenticate -- set the config setting
 *          `test_mdoe` to `On` in the `config.inc.php` to log API usage
 *          instead of interacting with the iThenticate service. Also can
 *          can set the `test_mode_eula` to `On` to test the EUAL confirmation
 *          process as mock testing.
 */

namespace APP\plugins\generic\plagiarism;

use APP\core\Application;
use APP\submission\Submission;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PKP\author\Author;
use PKP\config\Config;
use PKP\context\Context;
use PKP\site\Site;
use PKP\user\User;

class TestIThenticate
{
    /**
     * Cache key used to persist a one-shot arm across HTTP requests. The arm
     * is consumed atomically (Cache::pull) on the first matching call, so a
     * single test arming carries cleanly across the editorial reconfirmation
     * flow's three requests (submit → modal → accept) without re-arming.
     */
    public const ARM_CACHE_KEY = 'test_iThenticate_arm';

    /**
     * @copydoc IThenticate::$eulaVersion
     */
    protected ?string $eulaVersion = null;

    /**
     * @copydoc IThenticate::$eulaVersionDetails
     */
    // IMPORTANT: when bumping `version` here for a rotation rehearsal, bump the
    // version segment in `url` too. Otherwise this mock will produce DB rows like
    // (ithenticateEulaVersion=v2, ithenticateEulaUrl=…/v1beta/…) — internally
    // inconsistent state that masks real bugs in the UI layer.
    protected ?array $eulaVersionDetails = [
        // "version" => "v1beta",
        "version" => "v2",
        "valid_from" => "2018-04-30T17:00:00Z",
        "valid_until" => null,
        // "url" => "https://static.turnitin.com/eula/v1beta/en-US/eula.html",
        "url" => "https://drtspb56m845b.cloudfront.net/eula/en-US/0000000002/0000000000/eula.html",
        "available_languages" => [
          "sv-SE",
          "zh-CN",
          "ja-JP",
          "ko-KR",
          "es-MX",
          "nl-NL",
          "ru-RU",
          "zh-TW",
          "ar-SA",
          "pt-BR",
          "de-DE",
          "el-GR",
          "nb-NO",
          "cs-CZ",
          "da-DK",
          "tr-TR",
          "pl-PL",
          "fi-FI",
          "it-IT",
          "vi-VN",
          "fr-FR",
          "en-US",
          "ro-RO",
        ],
    ];

    /**
     * @copydoc IThenticate::$suppressApiRequestException
     */
    protected bool $suppressApiRequestException = true;

    /**
     * Cached enabled features response to avoid repeated API calls
     */
    protected ?string $cachedEnabledFeatures = null;

    /**
     * @copydoc IThenticate::$lastResponseDetails
     */
    protected ?array $lastResponseDetails = null;

    /**
     * @copydoc IThenticate::$lastEulaError
     */
    protected bool $lastEulaError = false;

    /**
     * @copydoc IThenticate::DEFAULT_EULA_VERSION
     */
    public const DEFAULT_EULA_VERSION = 'latest';

    /**
     * @copydoc IThenticate::DEFAULT_EULA_LANGUAGE
     */
    public const DEFAULT_EULA_LANGUAGE = 'en-US';

    /**
     * The test iThenticate uuid prefix on test mode
     */
    public const ITHENTICATE_SUBMISSION_UUID_PREFIX = 'test-submission-uuid-';

    /**
     * @copydoc IThenticate::DEFAULT_WEBHOOK_EVENTS
     */
    public const DEFAULT_WEBHOOK_EVENTS = [
        'SUBMISSION_COMPLETE',
        'SIMILARITY_COMPLETE',
        'SIMILARITY_UPDATED',
        'PDF_STATUS',
        'GROUP_ATTACHMENT_COMPLETE',
    ];

    /**
     * @copydoc IThenticate::SUBMISSION_PERMISSION_SET
     */
    public const SUBMISSION_PERMISSION_SET = [
        'ADMINISTRATOR',
        'APPLICANT',
        'EDITOR',
        'INSTRUCTOR',
        'LEARNER',
        'UNDEFINED',
        'USER',
    ];

    /**
     * The minimum value of similarity report's view_setting's `exclude_small_matches` option
     * @see https://developers.turnitin.com/docs/tca#generate-similarity-report
     *
     * @var int
     */
    public const EXCLUDE_SMALL_MATCHES_MIN = 8;

    /**
     * Arm a one-shot EULA-error simulation that survives across HTTP requests
     * and is consumed atomically (Cache::pull) on the first matching call.
     *
     *   \APP\plugins\generic\plagiarism\TestIthenticate::armOnce('createSubmission');
     *
     * @param string $endpoint   One of 'confirmEula', 'createSubmission'.
     * @param int    $ttlSeconds How long the arm waits to be consumed (default 1h).
     */
    public static function armOnce(string $endpoint, int $ttlSeconds = 3600): void
    {
        Cache::put(static::ARM_CACHE_KEY, $endpoint, $ttlSeconds);
    }

    /**
     * Consume the arm only if it matches the given endpoint. Peek first, then
     * forget on a verified match
     */
    protected static function consumeArm(string $endpoint): bool
    {
        if (Cache::get(static::ARM_CACHE_KEY) !== $endpoint) {
            return false;
        }
        Cache::forget(static::ARM_CACHE_KEY);
        return true;
    }

    /**
     * Peek at the pending arm without consuming it. Used by GET-flavored mock
     * methods (e.g. verifyUserEulaAcceptance) that need to branch on whether
     * an arm is pending but must not consume an arm targeted at a different
     * (POST) endpoint.
     */
    protected static function peekArm(): ?string
    {
        return Cache::get(static::ARM_CACHE_KEY);
    }

    /**
     * @copydoc IThenticate::__construct()
     */
    public function __construct(
        string $apiUrl,
        string $apiKey,
        string $integrationName,
        string $integrationVersion,
        ?string $eulaVersion = null
    )
    {
        // These following 2 conditions are to facilitate the mock the EULA requirement
        if ($eulaVersion) {
            $this->eulaVersion = $eulaVersion;
        }

        if (!$eulaVersion && Config::getVar('ithenticate', 'test_mode_eula', true)) {
            $this->validateEulaVersion('');
        }

        error_log(
            sprintf(
                "Constructing iThenticate with API URL : {$apiUrl} \n
                API Key : {$apiKey} \n
                Integration Name : {$integrationName} \n
                Integration Version : {$integrationVersion} \n
                EULA Version : {$this->eulaVersion}"
            )
        );
    }

    /**
     * @copydoc IThenticate::withoutSuppressingApiRequestException()
     */
    public function withoutSuppressingApiRequestException(): static
    {
        $this->suppressApiRequestException = false;
        error_log('deactivating api request exception suppression');

        return $this;
    }

    /**
     * @copydoc IThenticate::getEnabledFeature()
     */
    public function getEnabledFeature(mixed $feature = null): string|array|null
    {
        $this->cachedEnabledFeatures = '{
            "similarity": {
                "viewer_modes": {
                    "match_overview": true,
                    "all_sources": true
                },
                "generation_settings": {
                    "search_repositories": [
                        "INTERNET",
                        "PUBLICATION",
                        "CROSSREF",
                        "CROSSREF_POSTED_CONTENT",
                        "SUBMITTED_WORK"
                    ],
                    "submission_auto_excludes": true
                },
                "view_settings": {
                    "exclude_bibliography": true,
                    "exclude_quotes": true,
                    "exclude_abstract": true,
                    "exclude_methods": true,
                    "exclude_small_matches": true,
                    "exclude_internet": true,
                    "exclude_publications": true,
                    "exclude_crossref": true,
                    "exclude_crossref_posted_content": true,
                    "exclude_submitted_works": true,
                    "exclude_citations": true,
                    "exclude_preprints": true
                }
            },
            "tenant": {
                "require_eula": '.($this->eulaVersion ? "true" : "false").'
            },
            "product_name": "Turnitin Originality",
            "access_options": [
                "NATIVE",
                "CORE_API",
                "DRAFT_COACH"
            ]
        }';


        if (!$feature) {
            error_log("iThenticate enabled feature details {$this->cachedEnabledFeatures}");
            return json_decode($this->cachedEnabledFeatures, true);
        }

        $featureStatus = data_get(
            json_decode($this->cachedEnabledFeatures, true),
            $feature,
            fn () => $this->suppressApiRequestException
                ? null
                : throw new Exception("Feature details {$feature} does not exist")
        );

        error_log("iThenticate specific enable feature details {$featureStatus}");
        return $featureStatus;
    }

    /**
     * @copydoc IThenticate::validateAccess()
     */
    public function validateAccess(mixed &$result = null): bool
    {
        error_log("Confirming the service access validation for given access details");
        return true;
    }

    /**
     * @copydoc IThenticate::hasDetectedEulaError()
     */
    public function hasDetectedEulaError(): bool
    {
        return $this->lastEulaError;
    }

    /**
     * @copydoc IThenticate::confirmEula()
     */
    public function confirmEula(User $user, Context $context): bool
    {
        if (static::consumeArm('confirmEula')) {
            $this->lastEulaError = true;
            error_log("TestIThenticate: simulated EULA 400 on confirmEula for user {$user->getId()} version {$this->getApplicableEulaVersion()}");
            return false;
        }
        $this->lastEulaError = false;
        error_log("Confirming EULA for user {$user->getId()} with language ".$this->getApplicableLocale($context->getPrimaryLocale())." for version {$this->getApplicableEulaVersion()}");
        return true;
    }

    /**
     * @copydoc IThenticate::createSubmission()
     */
    public function createSubmission(
        Site $site,
        Submission $submission,
        User $user,
        Author $author,
        string $authorPermission,
        string $submitterPermission
    ): ?string
    {
        if (!$this->validatePermission($authorPermission, static::SUBMISSION_PERMISSION_SET)) {
            throw new Exception("in valid owner permission {$authorPermission} given");
        }

        if (!$this->validatePermission($submitterPermission, static::SUBMISSION_PERMISSION_SET)) {
            throw new Exception("in valid submitter permission {$submitterPermission} given");
        }

        if (static::consumeArm('createSubmission')) {
            $this->lastEulaError = true;
            // Mock a failure response so getLastErrorSummary() surfaces a realistic "why" in test mode.
            $this->lastResponseDetails = [
                'status_code' => 400,
                'reason' => 'Bad Request',
                'body' => '{"message":"TestIThenticate: simulated EULA 400 on createSubmission."}',
                'headers' => [],
            ];
            error_log("TestIThenticate: simulated EULA 400 on createSubmission for submission {$submission->getId()}");
            return null;
        }

        $this->lastEulaError = false;
        error_log("Creating a new submission with id {$submission->getId()} by submitter {$user->getId()} for owner {$author->getId()} with owner permission as {$authorPermission} and submitter permission as {$submitterPermission}");

        return static::ITHENTICATE_SUBMISSION_UUID_PREFIX . \Illuminate\Support\Str::uuid()->__toString();
    }

    /**
     * @copydoc IThenticate::uploadFile()
     */
    public function uploadFile(string $submissionTacId, string $fileName, mixed $fileContent): bool
    {
        error_log("Uploading submission file named {$fileName} for submission UUID {$submissionTacId}");
        return true;
    }

    /**
     * @copydoc IThenticate::getSubmissionInfo()
     */
    public function getSubmissionInfo(string $submissionUuid): ?string
    {
        return '{
            "id": "'.$submissionUuid.'",
            "owner": "a9c14691-9523-4f44-b5fc-4a673c5d4a35",
            "title": "History 101 Final Esssay",
            "status": "COMPLETE",
            "content_type": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
            "page_count": 3,
            "word_count": 145,
            "character_count": 760,
            "created_time": "2023-08-30T22:13:41Z",
            "capabilities" : [
                  "INDEX",
                  "VIEWER",
                  "SIMILARITY"
              ]
          }';
    }

    /**
     * @copydoc IThenticate::scheduleSimilarityReportGenerationProcess()
     */
    public function scheduleSimilarityReportGenerationProcess(string $submissionUuid, array $settings = []): bool
    {
        error_log(
            sprintf(
                'Scheduled similarity report generation process for submission UUID %s with similarity config %s',
                $submissionUuid,
                print_r($settings, true)
            )
        );
        return true;
    }

    /**
     * @copydoc IThenticate::getSimilarityResult()
     */
    public function getSimilarityResult(string $submissionUuid): ?string
    {
        error_log("Similarity report result retrived for iThenticate submission id : {$submissionUuid}");
        return '{
            "submission_id": "'.$submissionUuid.'",
            "overall_match_percentage": 15,
            "internet_match_percentage": 12,
            "publication_match_percentage": 10,
            "submitted_works_match_percentage": 0,
            "status": "COMPLETE",
            "time_requested": "2017-11-06T19:14:31.828Z",
            "time_generated": "2017-11-06T19:14:45.993Z",
            "top_source_largest_matched_word_count": 193,
            "top_matches": []
        }';
    }

    /**
     * @copydoc IThenticate::createViewerLaunchUrl()
     */
    public function createViewerLaunchUrl(
        string $submissionUuid,
        User $user,
        string $locale,
        string $viewerPermission,
        bool $allowUpdateInViewer
    ): ?string
    {
        error_log("Similarity report viewer launch url generated for iThenticate submission id : {$submissionUuid} with locale : {$locale}, viewer permission : {$viewerPermission} and update viewer permission : {$allowUpdateInViewer}");
        return Application::get()->getRequest()->getBaseUrl();
    }

    /**
     * @copydoc IThenticate::verifyUserEulaAcceptance()
     *
     * Test contract: return true (= "user already accepted") when no
     * EULA-error simulation is armed, so the happy path short-circuits over
     * confirmEula(). Return false when an arm IS pending, so the flow reaches
     * confirmEula() where the arm can fire. This keeps the mock quiet on
     * default-path runs and only invokes confirmEula() when we actively want
     * to test it.
     *
     * Uses peekArm() — verify is a GET in the real client and must not consume
     * an arm targeted at a POST endpoint (e.g. createSubmission). The arm
     * still fires on whichever method consumes it via consumeArm().
     */
    public function verifyUserEulaAcceptance(Author|User $user, string $version): bool
    {
        $pendingArm = static::peekArm();
        $accepted = ($pendingArm === null);
        error_log(sprintf(
            "TestIThenticate::verifyUserEulaAcceptance user=%d version=%s arm=%s -> %s",
            $user->getId(),
            $version,
            $pendingArm ?? 'null',
            $accepted ? 'true' : 'false'
        ));
        return $accepted;
    }

    /**
     * @copydoc IThenticate::validateEulaVersion()
     */
    public function validateEulaVersion(string $version): bool
    {
        // Set the eula version form the mock details
        $this->eulaVersion = $this->eulaVersionDetails['version'];

        error_log("Validating/Retrieving the given EULA version {$version}");
        return true;
    }

    /**
     * @copydoc IThenticate::registerWebhook()
     */
    public function registerWebhook(
        string $signingSecret,
        string $url,
        array $events = self::DEFAULT_WEBHOOK_EVENTS
    ): ?string
    {
        error_log(
            sprintf(
                "Register webhook end point with singing secret : %s, url : %s and events : [%s]",
                $signingSecret,
                $url,
                implode(', ',$events)
            )
        );
        return Str::uuid()->__toString();
    }

    /**
     * @copydoc IThenticate::deleteWebhook()
     */
    public function deleteWebhook(string $webhookId): bool
    {
        error_log("ithenticate webhook with id : {$webhookId} removed");
        return true;
    }

    /**
     * @copydoc IThenticate::validateWebhook()
     */
    public function validateWebhook(string $webhookId, ?string &$result = null): bool
    {
        error_log("Validating webhook with id : {$webhookId}");
        
        $result = '{
            "id": "f3852140-1264-4135-b316-ed46d60a6ca2",
            "url": "https://my-own-test-server.com/turnitin-callbacks",
            "description": "my webhook",
            "created_time": "2017-10-19T16:08:00.908Z",
            "event_types": [
                "SIMILARITY_COMPLETE",
                "SUBMISSION_COMPLETE",
                "SIMILARITY_UPDATED",
                "PDF_STATUS",
                "GROUP_ATTACHMENT_COMPLETE"
            ],
            "allow_insecure": false
        }';

        return true;
    }

    /**
     * @copydoc IThenticate::listWebhooks()
     */
    public function listWebhooks(): array
    {
        error_log("Listing all registered webhooks");
        return [];
    }

    /**
     * @copydoc IThenticate::findWebhookIdByUrl()
     */
    public function findWebhookIdByUrl(string $url): ?string
    {
        error_log("Finding webhook by URL: {$url}");
        return null;
    }

    /**
     * @copydoc IThenticate::getEulaDetails()
     */
    public function getEulaDetails(): ?array
    {
        return $this->eulaVersionDetails;
    }

    /**
     * @copydoc IThenticate::getApplicableEulaVersion()
     */
    public function getApplicableEulaVersion(): string
    {
        return $this->eulaVersion;
    }

    /**
     * @copydoc IThenticate::setApplicableEulaVersion()
     */
    public function setApplicableEulaVersion(string $version): static
    {
        $this->eulaVersion = $version;

        return $this;
    }

    /**
     * @copydoc IThenticate::getApplicableEulaUrl()
     */
    public function getApplicableEulaUrl(string|array|null $locales = null): string
    {
        if (!$this->eulaVersion) {
            throw new Exception('No EULA version set yet');
        }

        $applicableEulaLanguage = $this->getApplicableLocale($locales ?? static::DEFAULT_EULA_LANGUAGE);

        $eulaUrl = $this->eulaVersionDetails['url'];

        return str_replace(
            strtolower(static::DEFAULT_EULA_LANGUAGE),
            strtolower($applicableEulaLanguage),
            $eulaUrl
        );
    }

    /**
     * @copydoc IThenticate::getApplicableLocale()
     */
    public function getApplicableLocale(string|array $locales, ?string $eulaVersion = null): string
    {
        if (!$this->getEulaDetails() && !$this->validateEulaVersion($eulaVersion ?? $this->eulaVersion)) {
            return static::DEFAULT_EULA_LANGUAGE;
        }

        if (is_string($locales)) {
            return $this->getCorrespondingLocaleAvailable($locales) ?? static::DEFAULT_EULA_LANGUAGE;
        }

        foreach ($locales as $locale) {
            $correspondingLocale = $this->getCorrespondingLocaleAvailable($locale);
            if ($correspondingLocale) {
                return $correspondingLocale;
            }
        }

        return static::DEFAULT_EULA_LANGUAGE;
    }

    /**
     * @copydoc IThenticate::getLastResponseDetails()
     */
    public function getLastResponseDetails(): ?array
    {
        return $this->lastResponseDetails;
    }

    /**
     * @copydoc IThenticate::getLastResponseBody()
     */
    public function getLastResponseBody(): ?string
    {
        return $this->lastResponseDetails['body'] ?? null;
    }

    /**
     * @copydoc IThenticate::getLastErrorSummary()
     */
    public function getLastErrorSummary(): ?string
    {
        if (!$this->lastResponseDetails) {
            return null;
        }

        $status = $this->lastResponseDetails['status_code'] ?? null;
        $reason = $this->lastResponseDetails['reason'] ?? null;

        $bodyMessage = null;
        $body = $this->lastResponseDetails['body'] ?? null;
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body);
            if (is_object($decoded)) {
                $bodyMessage = $decoded->message ?? $decoded->error ?? null;
            }
        }

        $summary = trim(($status !== null ? "HTTP {$status}" : '') . ($reason ? " {$reason}" : ''));
        if ($bodyMessage) {
            $summary = $summary === '' ? $bodyMessage : "{$summary} — {$bodyMessage}";
        }

        return $summary === '' ? null : $summary;
    }

    /**
     * @copydoc IThenticate::isCorrespondingLocaleAvailable()
     */
    protected function getCorrespondingLocaleAvailable(string $locale): ?string
    {
        $eulaLangs = $this->eulaVersionDetails['available_languages'];
        $language = \Locale::getPrimaryLanguage($locale);
        $region = \Locale::getRegion($locale) ?? null;
        $localeAndRegion = $language . '-' . $region;

        return in_array($localeAndRegion, $eulaLangs)
            ? $localeAndRegion
            : collect($eulaLangs)
                ->filter(
                    fn(string $lang) =>
                        collect(explode("-", $lang))->first() === $language
                )->first();
    }

    /**
     * @copydoc IThenticate::validatePermission()
     */
    protected function validatePermission(string $permission, array $permissionSet): bool
    {
        return in_array($permission, $permissionSet);
    }
}
