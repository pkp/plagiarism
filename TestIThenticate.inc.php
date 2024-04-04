<?php

/**
 * @file TestIThenticate.inc.php
 *
 * Copyright (c) 2003-2024 Simon Fraser University
 * Copyright (c) 2003-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief   Low-budget mock class for \IThenticate -- set the const `ITHENTICATE_TEST_MODE_ENABLE`
 *          value in the `PlagiarismPlugin` class to `true` to log API usage instead of 
 *          interacting with the iThenticate service.
 */

class TestIThenticate {

    /**
     * The EULA(end user license agreement) that user need to confirm before making request
     * 
     * @var string|null
     */
    protected $eulaVersion = 'v1beta';

    /**
     * The EULA details for a specific version
     * 
     * @var array|null
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
     * The default EULA version placeholder to retrieve the current latest version
     * 
     * @var string
     */
    public const DEFAULT_EULA_VERSION = 'latest';
    
    /**
     * The default EULA confirming language
     * 
     * @var string
     */
    public const DEFAULT_EULA_LANGUAGE = 'en-US';

    /**
     * The default webhook events for which webhook request will be received
     * @see https://developers.turnitin.com/docs/tca#event-types
     * 
     * @var array
     */
    public const DEFAULT_WEBHOOK_EVENTS = [
        'SUBMISSION_COMPLETE',
        'SIMILARITY_COMPLETE',
        'SIMILARITY_UPDATED',
        'PDF_STATUS',
        'GROUP_ATTACHMENT_COMPLETE',
    ];

    /**
     * Create a new instance
     * 
     * @param string        $apiUrl
     * @param string        $apiKey
     * @param string        $integrationName
     * @param string        $integrationVersion
     * @param string|null   eulaVersion
     */
    public function __construct($apiUrl, $apiKey, $integrationName, $integrationVersion, $eulaVersion = null) {
        error_log("Constructing iThenticate with API URL : {$apiUrl}, API Key : {$apiKey}, Integration Name : {$integrationName}, Integration Version : {$integrationVersion} and EUlA Version : {$eulaVersion}");
    }

    /**
     * Validate the service access by retrieving the enabled feature
     * @see https://developers.turnitin.com/docs/tca#get-features-enabled
     * @see https://developers.turnitin.com/turnitin-core-api/best-practice/exposing-tca-settings
     * 
     * @return bool
     */
    public function validateAccess() {
        error_log("Confirming the service access validation for given access details");
        return true;
    }

    /**
     * Confirm the EULA on the user's behalf for given version
     * @see https://developers.turnitin.com/docs/tca#accept-eula-version
     * 
     * @param User      $user
     * @param Context   $context
     *
     * @return bool
     */
    public function confirmEula($user, $context) {
        error_log("Confirming EULA for user {$user->getId()} with language ".$this->getEulaConfirmationLocale($context->getPrimaryLocale())." for version {$this->getApplicableEulaVersion()}");
        return true;
    }
    
    /**
     * Create a new submission at service's end
     * @see https://developers.turnitin.com/docs/tca#create-a-submission
     * 
     * @param Submission    $submission The article submission to check for plagiarism
     * @param User          $user       The user who is making submitting the submission
     * @param Author        $author     The author/owher of the submission
     * @param Site          $site       The core site of submission system
     *
     * @return string|null              if succeed, it will return the created submission UUID from 
     *                                  service's end and at failure, will return null
     */
    public function createSubmission($submission, $user, $author, $site) {
        error_log("Creating a new submission with id {$submission->getId()} by submitter {$user->getId()} for owner {$author->getId()}");
        return \Illuminate\Support\Str::uuid()->__toString();
    }

    /**
     * Upload single submission file to the service's end
     * @see https://developers.turnitin.com/docs/tca#upload-submission-file-contents
     *
     * @param string $submissionTacId The submission UUID return back from service
     * @param string $fileName
     * @param mixed  fileContent   
     *
     * @return bool
     */
    public function uploadFile($submissionTacId, $fileName, $fileContent) {
        error_log("Uploading submission file named {$fileName} for submission UUID {$submissionTacId}");
        return true;
    }

    /**
     * Schedule the similarity report generation process
     * @see https://developers.turnitin.com/docs/tca#generate-similarity-report
     *
     * @param string $submissionUuid The submission UUID return back from service
     * @return bool
     */
    public function scheduleSimilarityReportGenerationProcess($submissionUuid) {
        error_log("Scheduled similarity report generation process for submission UUID {$submissionUuid}");
        return true;
    }

    /**
     * Verify if user has already confirmed the given EULA version
     * @see https://developers.turnitin.com/docs/tca#get-eula-acceptance-info
     *
     * @param Author|User   $user
     * @param string        $version
     *
     * @return bool
     */
    public function verifyUserEulaAcceptance($user, $version) {
        error_log("Verifying if user with id {$user->getId()} has already confirmed the given EULA version {$version}");
        return true;
    }

    /**
     * Validate/Retrieve the given EULA version
     * @see https://developers.turnitin.com/docs/tca#get-eula-version-info
     *
     * @param string $version
     * @return bool
     */
    public function validateEulaVersion($version) {
        error_log("Validating/Retrieving the given EULA version {$version}");
        return true;
    }

    /**
     * Register webhook end point
     * @see https://developers.turnitin.com/docs/tca#create-webhook
     *
     * @param string $signingSecret
     * @param string $url
     * @param array  $events
     * 
     * @return string|null The UUID of register webhook if succeed or null if failed
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
     * Get the stored EULA details
     * 
     * @return array|null
     */
    public function getEulaDetails() {
        return $this->eulaVersionDetails;
    }

    /**
     * Get the applicable EULA version
     * 
     * @return string
     * @throws \Exception
     */
    public function getApplicableEulaVersion() {
        return $this->eulaVersion;
    }

    /**
     * Set the applicable EULA version
     * 
     * @param string $version
     * @return self
     */
    public function setApplicableEulaVersion($version) {
        $this->eulaVersion = $version;

        return $this;
    }

    /**
     * Get the applicable EULA Url
     * 
     * @param  string|null $locale
     * @return string
     * 
     * @throws \Exception
     */
    public function getApplicableEulaUrl($locale = null) {
        if (!$this->eulaVersion) {
            throw new \Exception('No EULA version set yet');
        }

        $applicableEulaLanguage = $this->getEulaConfirmationLocale($locale ?? static::DEFAULT_EULA_LANGUAGE);

        $eulaUrl = $this->eulaVersionDetails['url'];

        return str_replace(static::DEFAULT_EULA_LANGUAGE, $applicableEulaLanguage, $eulaUrl);
    }

    /**
     * Convert given submission/context locale to service compatible and acceptable locale format
     * @see https://developers.turnitin.com/docs/tca#eula
     * 
     * @param string $locale
     * @return string
     */
    protected function getEulaConfirmationLocale($locale) {
        if (!$this->getEulaDetails()) {
            return static::DEFAULT_EULA_LANGUAGE;
        }

        $eulaLangs = $this->getEulaDetails()['available_languages'];
        $locale = str_replace("_", "-", substr($locale, 0, 5));

        return in_array($locale, $eulaLangs) ? $locale : static::DEFAULT_EULA_LANGUAGE;
    }
}
