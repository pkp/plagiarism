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
    protected $eulaVersion = 'v1beta';

    /**
     * @copydoc IThenticate::$eulaVersionDetails
     */
    protected $eulaVersionDetails = [
        "version" => "v1beta",
        "valid_from" => "2018-04-30T17:00:00Z",
        "valid_until" => null,
        "url" => "https://static.turnitin.com/eula/v1beta/en-us/eula.html",
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
     * @copydoc IThenticate::__construct()
     */
    public function __construct($apiUrl, $apiKey, $integrationName, $integrationVersion, $eulaVersion = null) {
        error_log("Constructing iThenticate with API URL : {$apiUrl}, API Key : {$apiKey}, Integration Name : {$integrationName}, Integration Version : {$integrationVersion} and EUlA Version : {$eulaVersion}");
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
        
        static $result = '{
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
                "require_eula": true
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

        $featureStatus = data_get(
            json_decode($result, true),
            $feature,
            fn () => $this->suppressApiRequestException
                ? null
                : throw new \Exception("Feature details {$feature} does not exist")
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
        error_log("Verifying if user with id {$user->getId()} has already confirmed the given EULA version {$version}");
        return true;
    }

    /**
     * @copydoc IThenticate::validateEulaVersion()
     */
    public function validateEulaVersion($version) {
        error_log("Validating/Retrieving the given EULA version {$version}");
        return true;
    }

    /**
     * @copydoc IThenticate::registerWebhook()
     */
    public function registerWebhook($signingSecret, $url, $events = self::DEFAULT_WEBHOOK_EVENTS) {
        error_log(
            sprintf(
                "Register webhook end point with singing secret : %s, url : %s and events : [%s]",
                $signingSecret, 
                $url, 
                implode(', ',$events)
            )
        );
        return \Illuminate\Support\Str::uuid()->__toString();
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
