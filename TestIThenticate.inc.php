<?php

/**
 * @file TestIThenticate.inc.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief   Low-budget mock class for IThenticate -- set the config setting 
 *          `test_mdoe` to `On` in the `config.inc.php` to log API usage 
 *          instead of interacting with the iThenticate service.
 */

class TestIThenticate {

    /**
     * @copydoc IThenticate::$eulaVersion
     */
    protected $eulaVersion = null;

    /**
     * @copydoc IThenticate::$eulaVersionDetails
     *
     * Bumped to v2 to make the mock representative of the post-rotation iThenticate
     * state. Swap back to v1beta + the static.turnitin.com URL to simulate the
     * pre-rotation baseline.
     * 
     * IMPORTANT: when bumping `version` here for a rotation rehearsal, bump the
     * version segment in `url` too. Otherwise this mock will produce DB rows like
     * (ithenticateEulaVersion=v2, ithenticateEulaUrl=…/v1beta/…) — internally
     * inconsistent state that masks real bugs in the UI layer.
     */

    protected $eulaVersionDetails = [
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
    protected $suppressApiRequestException = true;

    /**
     * @copydoc IThenticate::$lastEulaError
     */
    protected $lastEulaError = false;

    /**
     * Cache name segment used for the on-disk FileCache that backs the mock arm.
     * Resolved disk path: cache/fc-test_iThenticate_arm-global.php
     */
    public const ARM_CACHE_NAME = 'test_iThenticate_arm';

    /**
     * @copydoc IThenticate::DEFAULT_EULA_VERSION
     */
    public const DEFAULT_EULA_VERSION = 'latest';
    
    /**
     * @copydoc IThenticate::DEFAULT_EULA_LANGUAGE
     */
    public const DEFAULT_EULA_LANGUAGE = 'en-US';

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
    public const EXCLUDE_SAMLL_MATCHES_MIN = 8;

    /**
     * Build the FileCache instance that backs the cross-request mock arm.
     * Mirrors the pattern PlagiarismPlugin::getEulaCacheInstance() uses for
     * `ithenticate_eula` so a tester reading either understands both. Single-key
     * cache (no per-context scoping needed for a test arm) — the empty-loader
     * initialises the cache to an empty array on first access so subsequent
     * peek/get calls just see "no arm pending".
     *
     * @return FileCache
     */
    public static function getArmCacheInstance() {
        return CacheManager::getManager()->getCache(
            static::ARM_CACHE_NAME,
            'global',
            function ($cache, $cacheId) {
                $cache->setEntireCache([]);
                return null;
            }
        );
    }

    /**
     * Arm a one-shot EULA-error simulation that fires on the next matching
     * mock call. Survives across HTTP requests via PKP's FileCache, so it
     * covers multi-request flows like the editorial reconfirmation (POST
     * submitSubmission → GET confirmEula → POST acceptEulaAndExecuteIntendedAction).
     *
     * Set from tinker:
     *     \TestIThenticate::armOnce('createSubmission');   // or 'confirmEula'
     *
     * Clear manually if a test was aborted before the arm fired:
     *     \TestIThenticate::getArmCacheInstance()->flush();
     *
     * @param string $endpoint Either 'confirmEula' or 'createSubmission'.
     */
    public static function armOnce($endpoint) {
        static::getArmCacheInstance()->setEntireCache(['arm' => $endpoint]);
    }

    /**
     * Consume the arm if it matches the given endpoint. Single-shot — the cache
     * is flushed after a successful match so the recovery's inner retry (in the
     * same request or any later one) proceeds normally.
     *
     * Two FileCache calls (get + flush) are not atomic across concurrent
     * requests; acceptable for a test harness — concurrent armed tests aren't
     * a real scenario.
     *
     * @param string $endpoint
     * @return bool
     */
    protected static function consumeArm($endpoint) {
        $cache = static::getArmCacheInstance();
        if ($cache->get('arm') === $endpoint) {
            $cache->flush();
            return true;
        }
        return false;
    }

    /**
     * Read the arm without consuming it. Used by verifyUserEulaAcceptance so
     * it doesn't accidentally swallow a createSubmission-targeted arm.
     *
     * @return string|null
     */
    protected static function peekArm() {
        return static::getArmCacheInstance()->get('arm');
    }

    /**
     * @copydoc IThenticate::__construct()
     */
    public function __construct($apiUrl, $apiKey, $integrationName, $integrationVersion, $eulaVersion = null) {

        // These following 2 conditions are to facilitate the mock the EULA requirement
        if ($eulaVersion) {
            $this->eulaVersion = $eulaVersion;
        }

        if (!$eulaVersion && Config::getVar('ithenticate', 'test_mode_eula', true)) {
            // Route through validateEulaVersion so $this->eulaVersion is synced from
            // $this->eulaVersionDetails['version'] instead of being hardcoded to
            // 'v1beta'. Without this, retrieveEulaDetails() rebuilds the cache with
            // the pre-rotation version after a clearEulaCache, defeating recovery.
            $this->validateEulaVersion('');
        }

        error_log("Constructing iThenticate with API URL : {$apiUrl}, API Key : {$apiKey}, Integration Name : {$integrationName}, Integration Version : {$integrationVersion} and EUlA Version : {$this->eulaVersion}");
    }

    /**
     * @copydoc IThenticate::hasDetectedEulaError()
     */
    public function hasDetectedEulaError() {
        return $this->lastEulaError;
    }

    /**
     * @copydoc IThenticate::withoutSuppressingApiRequestException()
     */
    public function withoutSuppressingApiRequestException() {
        $this->suppressApiRequestException = false;
        error_log('deactivating api request exception suppression');
        return $this;
    }

    /**
     * @copydoc IThenticate::getEnabledFeature()
     */
    public function getEnabledFeature($feature = null) {
        
        static $result;

        $result = '{
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
            error_log("iThenticate enabled feature details {$result}");
            return json_decode($result, true);
        }

        $self = $this;
        $featureStatus = data_get(
            json_decode($result, true),
            $feature,
            function () use ($self, $feature) {
                if ($self->suppressApiRequestException) {
                    return null;
                }

                throw new \Exception("Feature details {$feature} does not exist");
            }
        );

        error_log("iThenticate specific enable feature details {$featureStatus}");
        return $featureStatus;
    }

    /**
     * @copydoc IThenticate::validateAccess()
     */
    public function validateAccess(&$result = null) {
        error_log("Confirming the service access validation for given access details");
        return true;
    }

    /**
     * @copydoc IThenticate::confirmEula()
     */
    public function confirmEula($user, $context) {
        if (static::consumeArm('confirmEula')) {
            $this->lastEulaError = true;
            error_log("TestIThenticate: simulated EULA 400 on confirmEula for user {$user->getId()}");
            return false;
        }
        $this->lastEulaError = false;
        error_log("Confirming EULA for user {$user->getId()} with language ".$this->getApplicableLocale($context->getPrimaryLocale())." for version {$this->getApplicableEulaVersion()}");
        return true;
    }

    /**
     * @copydoc IThenticate::createSubmission()
     */
    public function createSubmission($site, $submission, $user, $author, $authorPermission, $submitterPermission) {

        if (!$this->validatePermission($authorPermission, static::SUBMISSION_PERMISSION_SET)) {
            throw new \Exception("in valid owner permission {$authorPermission} given");
        }

        if (!$this->validatePermission($submitterPermission, static::SUBMISSION_PERMISSION_SET)) {
            throw new \Exception("in valid submitter permission {$submitterPermission} given");
        }

        if (static::consumeArm('createSubmission')) {
            $this->lastEulaError = true;
            error_log("TestIThenticate: simulated EULA 400 on createSubmission for submission {$submission->getId()}");
            return null;
        }
        $this->lastEulaError = false;
        error_log("Creating a new submission with id {$submission->getId()} by submitter {$user->getId()} for owner {$author->getId()} with owner permission as {$authorPermission} and submitter permission as {$submitterPermission}");

        return \Illuminate\Support\Str::uuid()->__toString();
    }

    /**
     * @copydoc IThenticate::uploadFile()
     */
    public function uploadFile($submissionTacId, $fileName, $fileContent) {
        error_log("Uploading submission file named {$fileName} for submission UUID {$submissionTacId}");
        return true;
    }

    /**
     * @copydoc IThenticate::getSubmissionInfo()
     */
    public function getSubmissionInfo($submissionUuid) {

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
    public function scheduleSimilarityReportGenerationProcess($submissionUuid, $settings = []) {
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
    public function getSimilarityResult($submissionUuid) {
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
    public function createViewerLaunchUrl($submissionUuid, $user, $locale, $viewerPermission, $allowUpdateInViewer) {
        error_log("Similarity report viewer launch url generated for iThenticate submission id : {$submissionUuid} with locale : {$locale}, viewer permission : {$viewerPermission} and update viewer permission : {$allowUpdateInViewer}");
        return Application::get()->getRequest()->getBaseUrl();
    }

    /**
     * @copydoc IThenticate::verifyUserEulaAcceptance()
     */
    public function verifyUserEulaAcceptance($user, $version) {
        $pendingArm = static::peekArm();
        $accepted = ($pendingArm === null);
        error_log(sprintf(
            'TestIThenticate::verifyUserEulaAcceptance user=%d version=%s arm=%s -> %s',
            $user->getId(),
            $version,
            $pendingArm === null ? 'null' : $pendingArm,
            $accepted ? 'true' : 'false'
        ));
        return $accepted;
    }

    /**
     * @copydoc IThenticate::validateEulaVersion()
     */
    public function validateEulaVersion($version) {
        // Sync the mock's applicable version from the configured details. The
        // real iThenticate's validateEulaVersion('latest') returns the live
        // current version; the mock mirrors that by reading $eulaVersionDetails.
        // Without this sync, retrieveEulaDetails repopulates the cache with the
        // constructor's stale version after a clearEulaCache, defeating recovery.
        $this->eulaVersion = $this->eulaVersionDetails['version'];

        error_log("Validating/Retrieving the given EULA version {$version}");
        return true;
    }

    /**
     * @copydoc IThenticate::registerWebhook()
     */
    public function registerWebhook($signingSecret, $url, $allowInsecure, $events = self::DEFAULT_WEBHOOK_EVENTS) {
        error_log(
            sprintf(
                "Register webhook end point with singing secret : %s, url : %s, events : [%s] and allow_insecure : %s",
                $signingSecret,
                $url,
                implode(', ',$events),
                $allowInsecure ? 'true' : 'false'
            )
        );
        return \Illuminate\Support\Str::uuid()->__toString();
    }

    /**
     * @copydoc IThenticate::deleteWebhook()
     */
    public function deleteWebhook($webhookId) {
        error_log("ithenticate webhook with id : {$webhookId} removed");
        return true;
    }

    /**
     * @copydoc IThenticate::listWebhooks()
     */
    public function listWebhooks() {
        return [];
    }

    /**
     * @copydoc IThenticate::findWebhookIdByUrl()
     */
    public function findWebhookIdByUrl($url) {
        return null;
    }

    /**
     * @copydoc IThenticate::getEulaDetails()
     */
    public function getEulaDetails() {
        return $this->eulaVersionDetails;
    }

    /**
     * @copydoc IThenticate::getApplicableEulaVersion()
     */
    public function getApplicableEulaVersion() {
        return $this->eulaVersion;
    }

    /**
     * @copydoc IThenticate::setApplicableEulaVersion()
     */
    public function setApplicableEulaVersion($version) {
        $this->eulaVersion = $version;

        return $this;
    }

    /**
     * @copydoc IThenticate::getApplicableEulaUrl()
     */
    public function getApplicableEulaUrl($locales = null) {
        if (!$this->eulaVersion) {
            throw new \Exception('No EULA version set yet');
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
    public function getApplicableLocale($locales, $eulaVersion = null) {
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
     * @copydoc IThenticate::isCorrespondingLocaleAvailable()
     */
    protected function getCorrespondingLocaleAvailable($locale) {
        $eulaLangs = $this->eulaVersionDetails['available_languages'];
        $locale = str_replace("_", "-", substr($locale, 0, 5));
        
        return in_array($locale, $eulaLangs) ? $locale : null;
    }

    /**
     * @copydoc IThenticate::validatePermission()
     */
    protected function validatePermission($permission, $permissionSet) {
        return in_array($permission, $permissionSet);
    }
}
