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
     * The base api url in the format of schema://host
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
     * The EULA(end user license agreement) that user need to confirmm before making request
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
     * The entity(e.g. submission owner, submitter etc) to id prefix mapping
     * This helps to identify the type of entity associated with requesting system
     * For example, `author/1` rather than only `1` identify as author entity of requesting system
     * 
     * @var array
     */
    public const ENTITY_ID_PREFIXES = [
        'owner' => 'author_',
        'submitter' => 'user_',
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
        $this->apiUrl               = rtrim(trim($apiUrl ?? ''), '/\\');
        $this->apiKey               = $apiKey;
        $this->integrationName      = $integrationName;
        $this->integrationVersion   = $integrationVersion;
        $this->eulaVersion          = $eulaVersion;
    }

    /**
     * Validate the service access by retrieving the enabled feature
     * @see https://developers.turnitin.com/docs/tca#get-features-enabled
     * @see https://developers.turnitin.com/turnitin-core-api/best-practice/exposing-tca-settings
     * 
     * @return bool
     */
    public function validateAccess() {

        try {
            $response = Application::get()->getHttpClient()->request(
                'GET',
                $this->getApiPath('features-enabled'),
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
                    'user_id' => $this->getGeneratedId('submitter', $user->getId()),
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
     * @param Submission    $submission The article submission to check for plagiarism
     * @param User          $user       The user who is making submitting the submission
     * @param Author        $author     The author/owher of the submission
     * @param Site          $site       The core site of submission system
     *
     * @return string|null              if succeed, it will return the created submission UUID from 
     *                                  service's end and at failure, will return null
     */
    public function createSubmission($submission, $user, $author, $site) {

        $publication = $submission->getCurrentPublication(); /** @var Publication $publication */
        $author ??= $publication->getPrimaryAuthor();

        $response = Application::get()->getHttpClient()->request(
            'POST',
            $this->getApiPath("submissions"),
            [
                'headers' => array_merge($this->getRequiredHeaders(), [
                    'Content-Type' => 'application/json'
                ]),
                'json' => [
                    'owner' => $this->getGeneratedId('owner', $author->getId()),
                    'title' => $publication->getLocalizedTitle($publication->getData('locale')),
                    'submitter' => $this->getGeneratedId('submitter', $user->getId()),
                    'metadata' => [
                        'owners' => [
                            [
                                'id' => $this->getGeneratedId('owner', $author->getId()),
                                'given_name' => $author->getGivenName($publication->getData('locale')),
                                'family_name' => $author->getFamilyName($publication->getData('locale')),
                                'email' => $author->getEmail(),
                            ]
                        ],
                        'submitter' => [
                            'id' => $this->getGeneratedId('submitter', $user->getId()),
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
     * Schedule the similarity report generation process
     * @see https://developers.turnitin.com/docs/tca#generate-similarity-report
     *
     * @param string $submissionUuid The submission UUID return back from service
     * @return bool
     */
    public function scheduleSimilarityReportGenerationProcess($submissionUuid) {

        $response = Application::get()->getHttpClient()->request(
            'PUT',
            $this->getApiPath("submissions/{$submissionUuid}/similarity"),
            [
                'headers' => array_merge($this->getRequiredHeaders(), [
                    'Content-Type' => 'application/json'
                ]),
                'json' => [
                    // section `indexing_settings` settings
                    'indexing_settings' => [
                        'add_to_index' => true,
                    ],

                    // section `generation_settings` settings
                    'generation_settings' => [
                        'search_repositories' => [
                            'INTERNET',
                            'SUBMITTED_WORK',
                            'PUBLICATION',
                            'CROSSREF',
                            'CROSSREF_POSTED_CONTENT'
                        ],
                        'auto_exclude_self_matching_scope' => 'ALL',
                        'priority' => 'HIGH',
                    ],

                    // section `view_settings` settings
                    'view_settings' => [
                        'exclude_quotes' => true,
                        'exclude_bibliography' => true,
                        'exclude_citations' => false,
                        'exclude_abstract' => false,
                        'exclude_methods' => false,
                        'exclude_custom_sections' => false,
                        'exclude_preprints' => false,
                        'exclude_small_matches' => 8,
                        'exclude_internet' => false,
                        'exclude_publications' => false,
                        'exclude_crossref' => false,
                        'exclude_crossref_posted_content' => false,
                        'exclude_submitted_works' => false,
                    ],
                ],
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
    public function verifyUserEulaAcceptance($user, $version) {

        $response = Application::get()->getHttpClient()->request(
            'GET',
            $this->getApiPath("eula/{$version}/accept/" . $this->getGeneratedId('submitter' ,$user->getId())),
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
                    'Content-Type' => 'application/json',
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

        $eulaLangs = $this->getEulaDetails()['available_languages'];
        $locale = str_replace("_", "-", substr($locale, 0, 5));

        return in_array($locale, $eulaLangs) ? $locale : static::DEFAULT_EULA_LANGUAGE;
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
     * @return \GuzzleHttp\Psr7\Uri
     */
    protected function getApiPath($apiPathSegment) {
        $apiRequestUrl = str_replace('API_URL', $this->apiUrl, $this->apiBasePath) . $apiPathSegment;
        return new \GuzzleHttp\Psr7\Uri($apiRequestUrl);
    }

    /**
     * Generate and return unique entity id by concatenating the prefix to given id
     * 
     * @param  string   $entity     The entity name (e.g. owner/submitter etc).
     * @param  mixed    $id         Entity id associated with requesting system.
     * @param  bool     $silent     Silently return the passed `$id` is no matching entity mapping
     *                              not found. Default to `true` and when set to `false`, will throw
     *                              exception.
     * 
     * @return mixed
     */
    protected function getGeneratedId($entity, $id, $silent = true) {
        if (!in_array($entity, array_keys(static::ENTITY_ID_PREFIXES))) {
            if ($silent) {
                return $id;
            }

            throw new Exception(
                sprintf(
                    'Invalid entity %s given, must be among [%s]',
                    $entity,
                    implode(', ', array_keys(static::ENTITY_ID_PREFIXES))
                )
            );
        }

        return static::ENTITY_ID_PREFIXES[$entity] . $id;
    }
}
