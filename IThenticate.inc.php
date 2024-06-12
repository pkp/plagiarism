<?php

/**
 * @file IThenticate.inc.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
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
     * Should suppress the exception on api request and log request details and exception instead
     * 
     * @var bool
     */
    protected $suppressApiRequestException = true;

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
     * The list of valid permission for owner and submitter when creating a new submission
     * @see https://developers.turnitin.com/docs/tca#create-a-submission
     * 
     * @var array
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
     * Will deactivate the exception suppression on api request and throw exception
     * 
     * @return self
     */
    public function withoutSuppressingApiRequestException() {
        $this->suppressApiRequestException = false;

        return $this;
    }

    /**
     * Get the json details of all enable features or get certiain feature details
     * To get certain or nested feature details, pass the feature params in dot(.) notation
     * For Example
     *  - to get specific feature as `similarity`, call as getEnabledFeature('similarity')
     *  - to get nested feature as `viewer_modes` in `similarity`, call as getEnabledFeature('similarity.viewer_modes')
     * @see https://developers.turnitin.com/docs/tca#get-features-enabled
     * 
     * @param  mixed $feature The specific or nested feature details to get
     * @return string|array|null
     * 
     * @throws \Exception
     */
    public function getEnabledFeature($feature = null) {
        
        static $result;

        if (!isset($result) && !$this->validateAccess($result)) {
            return $this->suppressApiRequestException
                ? []
                : throw new \Exception('unable to validate access details');
        }

        if (!$feature) {
            return json_decode($result, true);
        }

        return data_get(
            json_decode($result, true),
            $feature,
            fn () => $this->suppressApiRequestException
                ? null
                : throw new \Exception("Feature details {$feature} does not exist")
        );
    }

    /**
     * Validate the service access by retrieving the enabled feature
     * @see https://developers.turnitin.com/docs/tca#get-features-enabled
     * @see https://developers.turnitin.com/turnitin-core-api/best-practice/exposing-tca-settings
     * 
     * @param  mixed $result    This may contains the returned enabled feature details from 
     *                          request validation api end point if validated successfully.
     * @return bool
     */
    public function validateAccess(&$result = null) {

        $response = $this->makeApiRequest('GET', $this->getApiPath('features-enabled'), [
            'headers' => $this->getRequiredHeaders(),
            'verify' => false,
            'exceptions' => false,
            'http_errors' => false,
        ]);

        if ($response && $response->getStatusCode() === 200) {
            $result = $response->getBody()->getContents();
            return true;
        }

        return false;
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
        
        $response = $this->makeApiRequest(
            'POST',
            $this->getApiPath("eula/{$this->getApplicableEulaVersion()}/accept"),
            [
                'headers' => array_merge($this->getRequiredHeaders(), [
                    'Content-Type' => 'application/json'
                ]),
                'json' => [
                    'user_id' => $this->getGeneratedId('submitter', $user->getId()),
                    'accepted_timestamp' => \Carbon\Carbon::now()->toIso8601String(),
                    'language' => $this->getApplicableLocale($context->getPrimaryLocale()),
                ],
                'verify' => false,
                'exceptions' => false,
            ]
        );

        return $response ? $response->getStatusCode() === 200 : false;
    }
    
    /**
     * Create a new submission at service's end
     * @see https://developers.turnitin.com/docs/tca#create-a-submission
     * 
     * @param Site          $site                   The core site of submission system
     * @param Submission    $submission             The article submission to check for plagiarism
     * @param User          $user                   The user who is making submitting the submission
     * @param Author        $author                 The author/owner of the submission
     * @param string        $authorPermission       Submission author/owner permission set
     * @param string        $submitterPermission    Submission submitter permission set
     *
     * @return string|null              if succeed, it will return the created submission UUID from 
     *                                  service's end and at failure, will return null
     * 
     * @throws \Exception
     */
    public function createSubmission($site, $submission, $user, $author, $authorPermission, $submitterPermission) {

        if (!$this->validatePermission($authorPermission, static::SUBMISSION_PERMISSION_SET)) {
            throw new \Exception("in valid owner permission {$authorPermission} given");
        }

        if (!$this->validatePermission($submitterPermission, static::SUBMISSION_PERMISSION_SET)) {
            throw new \Exception("in valid submitter permission {$submitterPermission} given");
        }

        $publication = $submission->getCurrentPublication(); /** @var Publication $publication */
        $author ??= $publication->getPrimaryAuthor();

        $response = $this->makeApiRequest(
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
                    'owner_default_permission_set' => $authorPermission,
                    'submitter_default_permission_set' => $submitterPermission,
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

        if ($response && $response->getStatusCode() === 201) {
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
        
        $response = $this->makeApiRequest(
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

        return $response ? $response->getStatusCode() === 202 : false;
    }

    /**
     * Get the submission details
     * @see https://developers.turnitin.com/docs/tca#get-submission-info
     *
     * @param string $submissionTacId   The submission UUID return back from service
     * @return string|null              On successful retrieval of submission details it will return
     *                                  details JSON data and on failure, will return null.
     */
    public function getSubmissionInfo($submissionUuid) {

        $response = $this->makeApiRequest(
            'GET',
            $this->getApiPath("submissions/{$submissionUuid}"),
            [
                'headers' => $this->getRequiredHeaders(),
                'verify' => false,
                'exceptions' => false,
            ]
        );

        if ($response->getStatusCode() === 200) {
            return $response->getBody()->getContents();
        }

        return null;
    }

    /**
     * Schedule the similarity report generation process
     * @see https://developers.turnitin.com/docs/tca#generate-similarity-report
     *
     * @param string    $submissionUuid The submission UUID return back from service
     * @param array     $settings       The specific few settings
     * 
     * @return bool
     */
    public function scheduleSimilarityReportGenerationProcess($submissionUuid, $settings = []) {
        
        $response = $this->makeApiRequest(
            'PUT',
            $this->getApiPath("submissions/{$submissionUuid}/similarity"),
            [
                'headers' => array_merge($this->getRequiredHeaders(), [
                    'Content-Type' => 'application/json'
                ]),
                'json' => [
                    // section `indexing_settings` settings
                    'indexing_settings' => [
                        'add_to_index' => $settings['addToIndex'] ?? true,
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
                        'auto_exclude_self_matching_scope' => $settings['autoExcludeSelfMatchingScope'] ?? 'ALL',
                        'priority' => $settings['priority'] ?? 'HIGH',
                    ],

                    // section `view_settings` settings
                    'view_settings' => [
                        'exclude_quotes'                    => $settings['excludeQuotes']                   ?? false,
                        'exclude_bibliography'              => $settings['excludeBibliography']             ?? false,
                        'exclude_citations'                 => $settings['excludeCitations']                ?? false,
                        'exclude_abstract'                  => $settings['excludeAbstract']                 ?? false,
                        'exclude_methods'                   => $settings['excludeMethods']                  ?? false,
                        'exclude_custom_sections'           => $settings['excludeCustomSections']           ?? false,
                        'exclude_preprints'                 => $settings['excludePreprints']                ?? false,
                        'exclude_small_matches'             => (int) $settings['excludeSmallMatches'] >= self::EXCLUDE_SAMLL_MATCHES_MIN  
                                                                ? (int) $settings['excludeSmallMatches'] 
                                                                : self::EXCLUDE_SAMLL_MATCHES_MIN,
                        'exclude_internet'                  => $settings['excludeInternet']                 ?? false,
                        'exclude_publications'              => $settings['excludePublications']             ?? false,
                        'exclude_crossref'                  => $settings['excludeCrossref']                 ?? false,
                        'exclude_crossref_posted_content'   => $settings['excludeCrossrefPostedContent']    ?? false,
                        'exclude_submitted_works'           => $settings['excludeSubmittedWorks']           ?? false,
                    ],
                ],
                'exceptions' => false,
            ]
        );

        return $response ? $response->getStatusCode() === 202 : false;
    }

    /**
     * Get the similarity result info
     * @see https://developers.turnitin.com/docs/tca#get-similarity-report-info
     *
     * @param string $submissionUuid The submission UUID return back from service
     * @return string|null
     */
    public function getSimilarityResult($submissionUuid) {

        $response = $this->makeApiRequest(
            'GET',
            $this->getApiPath("submissions/{$submissionUuid}/similarity"),
            [
                'headers' => $this->getRequiredHeaders(),
                'exceptions' => false,
            ]
        );

        return $response && $response->getStatusCode() === 200
            ? $response->getBody()->getContents()
            : null;
    }

    /**
     * Create the viewer launch url
     * @see https://developers.turnitin.com/docs/tca#create-viewer-launch-url
     *
     * @param string    $submissionUuid         The submission UUID return back from service
     * @param User      $user                   The viewing user
     * @param string    $locale                 The preferred locale
     * @param string    $viewerPermission       The viewing user permission
     * @param bool      $allowUpdateInViewer    Should allow to update in the viewer and save it which will
     *                                          cause the update of similarity score
     * 
     * @return string|null
     */
    public function createViewerLaunchUrl($submissionUuid, $user, $locale, $viewerPermission, $allowUpdateInViewer) {

        $response = $this->makeApiRequest(
            'POST',
            $this->getApiPath("submissions/{$submissionUuid}/viewer-url"),
            [
                'headers' => array_merge($this->getRequiredHeaders(), [
                    'Content-Type' => 'application/json'
                ]),
                'json' => [
                    'viewer_user_id' => $this->getGeneratedId('submitter', $user->getId()),
                    'locale' => $locale,
                    'viewer_default_permission_set' => $viewerPermission,
                    'similarity' => [
                        'view_settings' => [
                            'save_changes' => $allowUpdateInViewer
                        ],
                    ],
                ],
                'exceptions' => false,
            ]
        );
        
        if ($response && $response->getStatusCode() === 200) {
            $result = json_decode($response->getBody()->getContents());
            return $result->viewer_url;
        }

        return null;
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

        $response = $this->makeApiRequest(
            'GET',
            $this->getApiPath("eula/{$version}/accept/" . $this->getGeneratedId('submitter' ,$user->getId())),
            [
                'headers' => $this->getRequiredHeaders(),
                'exceptions' => false,
            ]
        );
        
        return $response ? $response->getStatusCode() === 200 : false;
    }

    /**
     * Validate/Retrieve the given EULA version
     * @see https://developers.turnitin.com/docs/tca#get-eula-version-info
     *
     * @param string $version
     * @return bool
     */
    public function validateEulaVersion($version) {

        $response = $this->makeApiRequest('GET', $this->getApiPath("eula/{$version}"), [
            'headers' => $this->getRequiredHeaders(),
            'exceptions' => false,
        ]);
        
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

        $response = $this->makeApiRequest('POST', $this->getApiPath('webhooks'), [
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
        ]);

        if ($response && $response->getStatusCode() === 201) {
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
     * Make the api request
     * 
     * @param string                                $method  HTTP method.
     * @param string|\Psr\Http\Message\UriInterface $uri     URI object or string.
     * @param array                                 $options Request options to apply. See \GuzzleHttp\RequestOptions.
     * 
     * @return \Psr\Http\Message\ResponseInterface|null
     * 
     * @throws \Throwable
     */
    public function makeApiRequest($method, $url, $options = []) {
        
        $response = null;

        try {
            $response = Application::get()->getHttpClient()->request($method, $url, $options);
        } catch (\Throwable $exception) {
            error_log(
                sprintf(
                    'iThenticate API request to %s for %s method failed with options %s',
                    $url,
                    $method,
                    print_r($options, true)
                )
            );

            $this->suppressApiRequestException
                ? error_log($exception->__toString())
                : throw $exception;
        }

        return $response;
    }

    /**
     * Get the applicable EULA Url
     * 
     * @param  string|array|null $locale
     * @return string
     * 
     * @throws \Exception
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
     * Convert given submission/context locale to service compatible and acceptable locale format
     * @see https://developers.turnitin.com/docs/tca#eula
     * 
     * @param string|array $locales
     * @param string|null  $eulaVersion
     * 
     * @return string
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
     * Get the corresponding available locale or return null
     * 
     * @param string $locales
     * @return string|null
     */
    protected function getCorrespondingLocaleAvailable($locale) {
        $eulaLangs = $this->eulaVersionDetails['available_languages'];
        $locale = str_replace("_", "-", substr($locale, 0, 5));
        
        return in_array($locale, $eulaLangs) ? $locale : null;
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
     * @param  bool     $silent     Silently return the passed `$id` if no matching entity mapping
     *                              not found. Default to `false` and when set to `true`, will not throw
     *                              exception.
     * 
     * @return mixed
     */
    protected function getGeneratedId($entity, $id, $silent = false) {
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

    /**
     * Validate the existence of a permission against a given permission set
     * 
     * @param  string   $permission     The specific permission to check for existence
     * @param  array    $permissionSet  The permission list to check against
     * 
     * @return bool True/False if the permission exists in the given permission set
     */
    protected function validatePermission($permission, $permissionSet) {
        return in_array($permission, $permissionSet);
    }
}
