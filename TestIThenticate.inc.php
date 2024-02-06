<?php

/**
 * @file TestIThenticate.inc.php
 *
 * Copyright (c) 2003-2024 Simon Fraser University
 * Copyright (c) 2003-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief   Low-budget mock class for \IThenticate -- Replace the
 *          constructor in PlagiarismPlugin::submitForPlagiarismCheck with this class name 
 *          to log API usage instead of interacting with the iThenticate service.
 */

class TestIThenticate {

    /**
     * The EULA(end user license agreement) that user need to confrim before making request
     * 
     * @var string|null
     */
    protected $eulaVersion = 'v1beta';

    /**
     * The EULA details for a specific version
     * 
     * @var array|null
     */
    protected $eualVersionDetails = null;

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
     * @var string
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
     * Confirm the EUAL on the user's behalf for given version
     * @see https://developers.turnitin.com/docs/tca#accept-eula-version
     * 
     * @param User      $user
     * @param Context   $context
     * @param string    $version
     *
     * @return bool
     */
    public function confirmEula($user, $context, $version = self::DEFAULT_EULA_VERSION) {
        error_log("Confirming EULA for user {$user->getId()} with language ".$this->getEualConfirmationLocale($context->getPrimaryLocale())." for version {$this->getApplicationEulaVersion()}");
        return true;
    }
    
    /**
     * Create a new submission at service's end
     * @see https://developers.turnitin.com/docs/tca#create-a-submission
     * 
     * @param Submission    $submission
     * @param User          $user
     * @param Author|null   $author
     *
     * @return string|null  if succeed, it will return the created submission UUID at service's end and 
     *                      at failure, will return null
     */
    public function submitSubmission($submission, $user, $author = null) {
        error_log("Creating a new submission with id {$submission->getId()} by submitter {$user->getId()} for owner {$author->getId()}");
        return \Illuminate\Support\Str::uuid()->__toString();
    }

    /**
     * Upload all submission files to the service's end
     *
     * @param string        $submissionTacId The submission UUID return back from service
     * @param Submission    $submission
     *
     * @return bool         If all submission files uploaded successfully, only then it will 
     *                      return TRUE and return FALSE on a single failure
     */
    public function uploadSubmissionFile($submissionTacId, $submission)
    {
        error_log("Preparing to start process of uploading file for submission {$submission->getId()} with iThenticate submission UUID {$submissionTacId}");

        $submissionFiles = Services::get('submissionFile')->getMany([
			'submissionIds' => [$submission->getId()],
		]);
        
        $publication = $submission->getCurrentPublication(); /** @var Publication $publication */

        foreach($submissionFiles as $submissionFile) {
            $this->uploadFile(
                $submissionTacId, 
                $submissionFile->getData("name", $publication->getData("locale")),
                '',
            );
        }

        return true;
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
     * Verify if user has already confirmed the given EUAL version
     * @see https://developers.turnitin.com/docs/tca#get-eula-acceptance-info
     *
     * @param Author|User   $user
     * @param string        $version
     *
     * @return bool
     */
    public function verifyUserEulaAcceptance($user, $version) {
        error_log("Verifying if user with id {$user->getId()} has already confirmed the given EUAL version {$version}");
        return true;
    }

    /**
     * Validate/Retrieve the given EUAL version
     * @see https://developers.turnitin.com/docs/tca#get-eula-version-info
     *
     * @param string $version
     * @return bool
     */
    public function validateEulaVersion($version) {
        error_log("Validating/Retrieving the given EUAL version {$version}");
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
    public function getEualDetails() {
        return [
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
    }

    /**
     * Get the applicable EULA version
     * 
     * @return string
     * @throws \Exception
     */
    public function getApplicationEulaVersion() {
        return $this->eulaVersion;
    }

    /**
     * Convert given submission/context locale to service compatible and acceptable locale format
     * @see https://developers.turnitin.com/docs/tca#eula
     * 
     * @param string $locale
     * @return string
     */
    protected function getEualConfirmationLocale($locale) {
        if (!$this->getEualDetails()) {
            return static::DEFAULT_EULA_LANGUAGE;
        }

        $euleLangs = $this->getEualDetails()['available_languages'];
        $locale = str_replace("_", "-", substr($locale, 0, 5));

        return in_array($locale, $euleLangs) ? $locale : static::DEFAULT_EULA_LANGUAGE;
    }
}