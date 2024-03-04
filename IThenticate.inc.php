<?php

/**
 * @file IThenticate.inc.php
 *
 * Copyright (c) 2003-2024 Simon Fraser University
 * Copyright (c) 2003-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Service class to handle API communication with iThenticate service
 */

class IThenticate
{
    /** 
     * The base api url in the format of schems://host
     * 
     * @var string
     */
    protected $apiUrl;

    /**
     * The required API Key to make the request
     * @see https://developers.turnitin.com/docs/tca#required-headers
     * 
     * @var string
     */
    protected $apiKey;

    /**
     * Describes the platform/plugin integrating with TCA
     * @see https://developers.turnitin.com/docs/tca#required-headers
     * 
     * @var string
     */
    protected $integrationName;

    /**
     * The version of the code that is integrating with TCA
     * @see https://developers.turnitin.com/docs/tca#required-headers
     * 
     * @var string
     */
    protected $integrationVersion;
    
    /**
     * The EULA(end user license agreement) that user need to confrim before making request
     * 
     * @var string|null
     */
    protected $eulaVersion = null;

    /**
     * The EULA details for a specific version
     * 
     * @var array|null
     */
    protected $eulaVersionDetails = null;

    /**
     * Base API path
     * The string `API_URL` need to be replaced with provided api url to generate fully qualified base url
     * 
     * @var string
     */
    protected $apiBasePath = "API_URL/api/v1/";

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
        $this->apiUrl               = rtrim(trim($apiUrl), '/\\');
        $this->apiKey               = $apiKey;
        $this->integrationName      = $integrationName;
        $this->integrationVersion   = $integrationVersion;
        $this->eulaVersion          = $eulaVersion;
    }

    /**
     * Validate the service access by retrieving the the enabled feature
     * @see https://developers.turnitin.com/docs/tca#get-features-enabled
     * @see https://developers.turnitin.com/turnitin-core-api/best-practice/exposing-tca-settings
     * 
     * @return bool
     */
    public function validateAccess() {

        try {
            $response = Application::get()->getHttpClient()->request(
                'GET',
                $this->getApiPath("features-enabled"),
                [
                    'headers' => $this->getRequiredHeaders(),
                    'verify' => false,
                    'exceptions' => false,
                    'http_errors' => false,
                ]
            );
        } catch (\GuzzleHttp\Exception\ConnectException $exception) {
            return false;
        } catch (\Throwable $exception) {
            throw $exception;
        }

        return $response->getStatusCode() === 200;
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
        
        $response = Application::get()->getHttpClient()->request(
            'POST',
            $this->getApiPath("eula/{$this->getApplicableEulaVersion()}/accept"),
            [
                'headers' => array_merge($this->getRequiredHeaders(), [
                    'Content-Type' => 'application/json'
                ]),
                'json' => [
                    'user_id' => $user->getId(),
                    'accepted_timestamp' => \Carbon\Carbon::now()->toIso8601String(),
                    'language' => $this->getEulaConfirmationLocale($context->getPrimaryLocale()),
                ],
                'verify' => false,
                'exceptions' => false,
            ]
        );

        return $response->getStatusCode() === 200;
    }
    
    /**
     * Create a new submission at service's end
     * @see https://developers.turnitin.com/docs/tca#create-a-submission
     * 
     * @param Submission    $submission
     * @param User          $user
     * @param Author|null   $author
     * @param Site|null     $site
     *
     * @return string|null  if succeed, it will return the created submission UUID at service's end and 
     *                      at failure, will return null
     */
    public function submitSubmission($submission, $user, $author = null, $site = null) {

        $publication = $submission->getCurrentPublication(); /** @var Publication $publication */
        $author ??= $publication->getPrimaryAuthor();

        if (!$site) {
            import('lib.pkp.classes.db.DAORegistry');
            $siteDao = DAORegistry::getDAO("SiteDAO"); /** @var SiteDAO $siteDao */
            $site = $siteDao->getSite(); /** @var Site $site */
        }

        $response = Application::get()->getHttpClient()->request(
            'POST',
            $this->getApiPath("submissions"),
            [
                'headers' => array_merge($this->getRequiredHeaders(), [
                    'Content-Type' => 'application/json'
                ]),
                'json' => [
                    'owner' => $author->getId(),
                    'title' => $publication->getLocalizedTitle($publication->getData('locale')),
                    'submitter' => $user->getId(),
                    'metadata' => [
                        'owners' => [
                            [
                                'id' => $author->getId(),
                                'given_name' => $author->getGivenName($publication->getData('locale')),
                                'family_name' => $author->getFamilyName($publication->getData('locale')),
                                'email' => $author->getEmail(),
                            ]
                        ],
                        'submitter' => [
                            'id' => $user->getId(),
                            'given_name' => $user->getGivenName($site->getPrimaryLocale()),
                            'family_name' => $user->getFamilyName($site->getPrimaryLocale()),
                            'email' => $user->getEmail(),
                        ],
                        'original_submitted_time' => \Carbon\Carbon::now()->toIso8601String(),
                    ],

                ],
                'verify' => false,
                'exceptions' => false,
            ]
        );

        if ($response->getStatusCode() === 201) {
            $result = json_decode($response->getBody()->getContents());
            return $result->id;
        }

        return null;
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
        $submissionFiles = Services::get('submissionFile')->getMany([
            'submissionIds' => [$submission->getId()],
		]);
        
        $publication = $submission->getCurrentPublication(); /** @var Publication $publication */

        foreach($submissionFiles as $submissionFile) {
            $file = Services::get('file')->get($submissionFile->getData('fileId'));
            $uploadStatus = $this->uploadFile(
                $submissionTacId, 
                $submissionFile->getData("name", $publication->getData("locale")),
                Services::get('file')->fs->read($file->path),
            );

            if (!$uploadStatus) {
                return false;
            }
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
        
        $response = Application::get()->getHttpClient()->request(
            'PUT',
            $this->getApiPath("submissions/{$submissionTacId}/original"),
            [
                'headers' => array_merge($this->getRequiredHeaders(), [
                    'Content-Type' => 'binary/octet-stream',
                    'Content-Disposition' => urlencode('inline; filename="'.$fileName.'"'),
                ]),
                'body' => $fileContent,
                'verify' => false,
                'exceptions' => false,
            ]
        );

        return $response->getStatusCode() === 202;
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
    public function verifyUserEulaAcceptance($user, $version)
    {
        $response = Application::get()->getHttpClient()->request(
            'GET',
            $this->getApiPath("eula/{$version}/accept/{$user->getId()}"),
            [
                'headers' => $this->getRequiredHeaders(),
                'exceptions' => false,
            ]
        );
        
        return $response->getStatusCode() === 200;
    }

    /**
     * Validate/Retrieve the given EULA version
     * @see https://developers.turnitin.com/docs/tca#get-eula-version-info
     *
     * @param string $version
     * @return bool
     */
    public function validateEulaVersion($version) {

        $response = Application::get()->getHttpClient()->request(
            'GET',
            $this->getApiPath("eula/{$version}"),
            [
                'headers' => $this->getRequiredHeaders(),
                'exceptions' => false,
            ]
        );
        
        if ($response->getStatusCode() === 200) {
            $this->eulaVersionDetails = json_decode($response->getBody()->getContents(), true);
            
            if (!$this->eulaVersion) {
                $this->eulaVersion = $this->eulaVersionDetails['version'];
            }

            return true;
        }

        return false;
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

        $response = Application::get()->getHttpClient()->request(
            'POST',
            $this->getApiPath('webhooks'),
            [
                'headers' => array_merge($this->getRequiredHeaders(), [
                    'Content-Type' => ' application/json',
                ]),
                'json' => [
                    'signing_secret' => base64_encode($signingSecret),
                    'url' => $url,
                    'event_types' => $events,
                    'allow_insecure' => true,
                ],
                'verify' => false,
                'exceptions' => false,
            ]
        );

        if ($response->getStatusCode() === 201) {
            $result = json_decode($response->getBody()->getContents());
            return $result->id;
        }

        return null;
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
        if (!$this->eulaVersion) {
            throw new \Exception('No EULA version set yet');
        }

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

        $euleLangs = $this->getEulaDetails()['available_languages'];
        $locale = str_replace("_", "-", substr($locale, 0, 5));

        return in_array($locale, $euleLangs) ? $locale : static::DEFAULT_EULA_LANGUAGE;
    }

    /**
     * Get the required headers that need to be sent with every request at service's end
     * @see https://developers.turnitin.com/docs/tca#required-headers
     * 
     * @return array
     */
    protected function getRequiredHeaders(){
        return [
            'X-Turnitin-Integration-Name'       => $this->integrationName,
            'X-Turnitin-Integration-Version'    => $this->integrationVersion,
            'Authorization'                     => 'Bearer ' . $this->apiKey,
        ];
    }

    /**
     * Generate and return the final API end point to make request
     * 
     * @return string
     */
    protected function getApiPath($apiPathSegment) {
        return str_replace('API_URL', $this->apiUrl, $this->apiBasePath) . $apiPathSegment;
    }
}
