<?php

/**
 * @file PlagiarismPlugin.inc.php
 *
 * Copyright (c) 2003-2024 Simon Fraser University
 * Copyright (c) 2003-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Plagiarism plugin
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('lib.pkp.classes.db.DAORegistry');

class PlagiarismPlugin extends GenericPlugin {

	/**
	 * Specify a default integration name for iThenticate service
	 */
	public const PLUGIN_INTEGRATION_NAME = 'Plagiarism plugin for OJS/OMP/OPS';

	/**
	 * Number of seconds EULA details for a context should be cached before refreshing it
	 */
	public const EULA_CACHE_LIFETIME = 60 * 60 * 24;

	/**
	 * Set the value to `true` to enable test mode that will log instead of interacting with 
	 * iThenticate API service.
	 */
	protected const ITHENTICATE_TEST_MODE_ENABLE = false;

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);

		$this->addLocaleData();
		
		if (!($success || $this->getEnabled())) {
			return false;	
		}

		// Need to register both of TemplateManager display and fetch hook as both of these
		// get called when presenting the submission 
		HookRegistry::register('TemplateManager::display', [$this, 'addEulaToChecklist']);
		HookRegistry::register('TemplateManager::fetch', [$this, 'addEulaToChecklist']);

		HookRegistry::register('Submission::add', [$this, 'stampEulaToSubmission']);
		HookRegistry::register('submissionsubmitstep4form::execute', [$this, 'submitForPlagiarismCheck']);
		HookRegistry::register('Schema::get::' . SCHEMA_SUBMISSION, [$this, 'addPlagiarismCheckDataToSubmissionSchema']);
		HookRegistry::register('Schema::get::' . SCHEMA_CONTEXT, [$this, 'addPlagiarismCheckWebhookDataToSchema']);
		HookRegistry::register('userdao::getAdditionalFieldNames', [$this, 'handleAdditionalEulaConfirmationFieldNames']);
		HookRegistry::register('LoadComponentHandler', [$this, 'setupWebhookHandler']);

		return $success;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.generic.plagiarism.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.generic.plagiarism.description');
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanEnable()
	 */
	public function getCanEnable($contextId = null) {
		return !Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanDisable()
	 */
	public function getCanDisable($contextId = null) {
		return !Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * @copydoc LazyLoadPlugin::getEnabled()
	 */
	public function getEnabled($contextId = null) {
		return parent::getEnabled($contextId) || Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * Add the IThenticate EULA url as a checklist of submission process
	 * 
	 * @param string $hookName `TemplateManager::display` or `TemplateManager::fetch`
	 * @param array $params
	 * 
	 * @return bool
	 */
	public function addEulaToChecklist($hookName, $params) {
		$templateManager = & $params[0]; /** @var TemplateManager $templateManager */
		$context = $templateManager->getTemplateVars('currentContext'); /** @var Context $context */
		
		if (!$context || strtolower($templateManager->getTemplateVars('requestedPage') ?? '') !== 'submission') {
			return false;
		}

		$eualDetails = $this->getContextEulaDetails($context);
		
		foreach($context->getData('submissionChecklist') as $locale => $checklist) {
			array_push($checklist, [
				'order' => (collect($checklist)->pluck('order')->sort(SORT_REGULAR)->last() ?? 0) + 1,
				'content' => __('plugins.generic.plagiarism.submission.checklist.eula', [
					'localizedEulaUrl' => $eualDetails[$locale]['url']
				]),
			]);

			$context->setData('submissionChecklist', $checklist, $locale);
		}

		$templateManager->assign('currentContext', $context);
		
		return false;
	}

	/**
	 * Add properties for this type of public identifier to the submission entity's list for
	 * storage in the database.
	 * 
	 * @param string $hookName `Schema::get::submission`
	 * @param array $params
	 * 
	 * @return bool
	 */
	public function addPlagiarismCheckDataToSubmissionSchema($hookName, $params) {
		$schema =& $params[0];

		$schema->properties->ithenticate_id = (object) [
			'type' => 'string',
			'description' => 'The iThenticate service submission uuid that return back after a submission has been successful submitted',
			'apiSummary' => true,
			'validation' => ['nullable'],
		];

		$schema->properties->ithenticate_eula_version = (object) [
			'type' => 'string',
			'description' => 'The iThenticate EULA version which has been agreed at submission checklist',
			'apiSummary' => true,
			'validation' => ['nullable'],
		];

		$schema->properties->ithenticate_eula_url = (object) [
			'type' => 'string',
			'description' => 'The iThenticate EULA url which has been agreen at submission checklist',
			'apiSummary' => true,
			'validation' => ['nullable'],
		];

		return false;
	}

	/**
	 * Add properties for this type of public identifier to the context entity's list for
	 * storage in the database.
	 * 
	 * @param string $hookName `Schema::get::context`
	 * @param array $params
	 * 
	 * @return bool
	 */
	public function addPlagiarismCheckWebhookDataToSchema($hookName, $params) {
		$schema =& $params[0];

		$schema->properties->ithenticate_webhook_signing_secret = (object) [
			'type' => 'string',
			'description' => 'The iThenticate service webook registration signing secret',
			'apiSummary' => true,
			'validation' => ['nullable'],
		];

		$schema->properties->ithenticate_webhook_id = (object) [
			'type' => 'string',
			'description' => 'The iThenticate service webook id that return back after successful webhook registration',
			'apiSummary' => true,
			'validation' => ['nullable'],
		];

		return false;
	}

	/**
	 * Add additional fields for users to stamp EULA details
	 * 
	 * @param string $hookName `userdao::getAdditionalFieldNames`
	 * @param array $params
	 * 
	 * @return bool
	 */
	public function handleAdditionalEulaConfirmationFieldNames($hookName, $params) {

		$fields =& $params[1];

		$fields[] = 'ithenticateEulaVersion';
		$fields[] = 'ithenticateEulaConfirmedAt';

		return false;
	}

	/**
	 * Fetch credentials from config.inc.php, if available
	 * 
	 * @return array api url and api key, or null(s)
	 */
	public function getForcedCredentials() {
		$request = Application::get()->getRequest(); /** @var Request $request */
		$context = $request->getContext(); /** @var Context $context */
		$contextPath = $context?->getPath();

		$apiUrl = Config::getVar(
			'ithenticate',
			'api_url[' . $contextPath . ']',
			Config::getVar('ithenticate', 'api_url')
		);

		$apiKey = Config::getVar(
			'ithenticate',
			'api_key[' . $contextPath . ']',
			Config::getVar('ithenticate', 'api_key')
		);

		return [$apiUrl, $apiKey];
	}

	/**
	 * Check and determine if plagiarism checking service creds has been set forced in config.inc.php
	 * 
	 * @return bool
	 */
	public function hasForcedCredentials() {
		list($apiUrl, $apiKey) = $this->getForcedCredentials();
		return !empty($apiUrl) && !empty($apiKey);
	}

	/**
	 * Send the editor an error message
	 * 
	 * @param int $submissionid
	 * @param string $message
	 * 
	 * @return void
	 */
	public function sendErrorMessage($submissionId, $message) {
		$request = Application::get()->getRequest(); /** @var Request $request */
		$context = $request->getContext(); /** @var Context $context */
		
		import('classes.notification.NotificationManager');
		$notificationManager = new NotificationManager();
		$roleDao = DAORegistry::getDAO('RoleDAO'); /** @var RoleDAO $roleDao  */
		
		// Get the managers.
		$managers = $roleDao->getUsersByRoleId(ROLE_ID_MANAGER, $context->getId()); /** @var DAOResultFactory $managers */
		while ($manager = $managers->next()) {
			$notificationManager->createTrivialNotification(
				$manager->getId(), 
				NOTIFICATION_TYPE_ERROR, 
				['contents' => __(
					'plugins.generic.plagiarism.errorMessage', [
						'submissionId' => $submissionId,
						'errorMessage' => $message
					]
				)]
			);
		}

		error_log("iThenticate submission {$submissionId} failed: {$message}");
	}

	/**
	 * Setup the handler for webhook request
	 * 
	 * @param string $hookName `LoadComponentHandler`
	 * @param array $params
	 * 
	 * @return bool
	 */
	public function setupWebhookHandler($hookName, $params) {
		$component =& $params[0];

		if ($component == 'plugins.generic.plagiarism.controllers.PlagiarismWebhookHandler') {
			import($component);
			PlagiarismWebhookHandler::setPlugin($this);
			return true;
		}

		return false;
	}

	/**
	 * Stamp the iThenticate EULA with the submission
	 * 
	 * @param string $hookName `Submission::add`
	 * @param array $args
	 * 
	 * @return bool
	 */
	public function stampEulaToSubmission($hookName, $args) {
		$submission =& $args[0]; /** @var Submission $submission */
		$context = Application::get()->getRequest()->getContext();

		if (!static::ITHENTICATE_TEST_MODE_ENABLE && !$this->isServiceAccessAvailable($context)) {
			$this->sendErrorMessage($submission->getId(), "ithenticate service access not set for context id {$context->getId()}");
			return false;
		}

		$eualDetails = $this->getContextEulaDetails(
			Application::get()->getRequest()->getContext(), 
			$submission->getData('locale')
		);

		$submission->setData('ithenticate_eula_version', $eualDetails['version']);
		$submission->setData('ithenticate_eula_url', $eualDetails['url']);

		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
		$submissionDao->updateObject($submission);

		return false;
	}

	/**
	 * Confirm EULA, create submission and upload submission files to iThenticate service
	 * 
	 * @param string $hookName `submissionsubmitstep4form::execute`
	 * @param array $args
	 * 
	 * @return bool
	 */
	public function submitForPlagiarismCheck($hookName, $args) {
		$request = Application::get()->getRequest();
		$form =& $args[0]; /** @var SubmissionSubmitStep4Form $form */
		$submission = $form->submission; /** @var Submission $submission */
		$context = $request->getContext();
		$publication = $submission->getCurrentPublication();
		$author = $publication->getPrimaryAuthor();
		$user = $request->getUser();

		if (!static::ITHENTICATE_TEST_MODE_ENABLE && !$this->isServiceAccessAvailable($context)) {
			$this->sendErrorMessage($submission->getId(), "ithenticate service access not set for context id {$context->getId()}");
			return false;
		}

		// if EULA details not stamped to submission, not going to sent it for plagiarism check
		if (!$submission->getData('ithenticate_eula_version') || !$submission->getData('ithenticate_eula_url')) {
			$this->sendErrorMessage($submission->getId(), 'Unable to obtain the stamped EULA details to submission');
			return false;
		}

		$submissionEulaVersion = $submission->getData('ithenticate_eula_version');
		$ithenticate = $this->initIthenticate(...$this->getServiceAccess($context)); /** @var \IThenticate $ithenticate */
		$ithenticate->setApplicableEulaVersion($submissionEulaVersion);
		
		// Check if EULA stamped to submitter and if not, try to stamp it
		if (!$user->getData('ithenticateEulaVersion') ||
			$user->getData('ithenticateEulaVersion') !== $submissionEulaVersion) {
			
			// Check if user has ever already accepted this EULA version and if so, stamp it to user
			// Or, try to confirm the EULA for user and upon succeeding, stamp it to user
			if ($ithenticate->verifyUserEulaAcceptance($user, $submissionEulaVersion) ||
				$ithenticate->confirmEula($user, $context)) {
				$this->stampEualVersionToSubmittingUser($user, $submissionEulaVersion);
			} else {
				$this->sendErrorMessage($submission->getId(), 'Unable to stamp the EULA details to submission submitter');
				return false;
			}
		}

		// Create the submission at iThenticate's end
		$submissionUuid = $ithenticate->submitSubmission(
			$submission,
			$user,
			$author,
			$request->getSite()
		);

		if (!$submissionUuid) {
			$this->sendErrorMessage($submission->getId(), 'Could not submit the submission at iThenticate.');
			return false;
		}

		$submission->setData('ithenticate_id', $submissionUuid);
		
		// Upload submission files for successfully created submission at iThenticate's end
		if (!$ithenticate->uploadSubmissionFile($submissionUuid, $submission)) {
			$this->sendErrorMessage($submission->getId(), 'Could not complete the file upload at iThenticate.');
			return false;
		}

		// If no webhook previously registered for this Context, register it
		if (!$context->getData('ithenticate_webhook_id')) {
			$signingSecret = \Illuminate\Support\Str::random(12);
			
			$webhookUrl = $request->getDispatcher()->url(
                $request,
                ROUTE_COMPONENT,
                $context->getData('urlPath'),
                'plugins.generic.plagiarism.controllers.PlagiarismWebhookHandler',
                'handle'
            );

			if ($webhookId = $ithenticate->registerWebhook($signingSecret, $webhookUrl)) {
				$context->setData('ithenticate_webhook_signing_secret', $signingSecret);
				$context->setData('ithenticate_webhook_id', $webhookId);
				Application::get()->getContextDAO()->updateObject($context);
			} else {
				error_log("unable to complete the iThenticate webhook registration during the submission process of ID : {$submission->getId()}");
			}
		}

		return true;
	}

	/**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb) {
        $router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
        
		return array_merge(
			$this->getEnabled() 
				? [
					new LinkAction(
                    	'settings',
                    	new AjaxModal(
                            $router->url(
								$request, 
								null, 
								null, 
								'manage', 
								null, 
								['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']
							),
                        	$this->getDisplayName()
                    	),
                    	__('manager.plugins.settings'),
                    	null
            		),
				] : [],
			parent::getActions($request, $verb)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request) {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $context = $request->getContext(); /** @var Context $context */

                AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);
                $templateMgr = TemplateManager::getManager($request); /** @var TemplateManager $templateMgr */
                $templateMgr->registerPlugin('function', 'plugin_url', [$this, 'smartyPluginUrl']);

                $this->import('PlagiarismSettingsForm');
                $form = new PlagiarismSettingsForm($this, $context->getId());

                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }
                return new JSONMessage(true, $form->fetch($request));
        }

        return parent::manage($args, $request);
    }

	/**
	 * Get the cached EULA details form Context
	 * 
	 * @param Context 		$context
	 * @param string|null 	$locale
	 * 
	 * @return array
	 */
	public function getContextEulaDetails($context, $locale = null) {
		/** @var \FileCache $cache */
		$cache = CacheManager::getManager()
			->getCache(
				'ithenticate_eula', 
				"CONTEXT_ID_{$context->getId()}",
				[$this, 'retrieveApplicableEulaDetails']
			);
		
		// if running on ithenticate test mode, set the cache life time to 60 seconds
		$cacheLifetime = static::ITHENTICATE_TEST_MODE_ENABLE ? 60 : static::EULA_CACHE_LIFETIME;
		if (time() - $cache->getCacheTime() > $cacheLifetime) {
			$cache->flush();
		}

		$eulaDetails = $cache->get("CONTEXT_ID_{$context->getId()}");

		return $locale ? $eulaDetails[$locale] : $eulaDetails;
	}

	/**
	 * Retrived and generate the localized EULA details for given context 
	 * and cache it in following format
	 * [
	 *   'en_US' => [
	 *     'version' => '',
	 *     'url' => '',
	 *   ],
	 *   ...
	 * ]
	 * 
	 * @param GenericCache 	$cache
	 * @param mixed 		$cacheId
	 * 
	 * @return array
	 */
	public function retrieveApplicableEulaDetails($cache, $cacheId) {
		$context = Application::get()->getRequest()->getContext();
		$ithenticate = $this->initIthenticate(...$this->getServiceAccess($context)); /** @var \IThenticate $ithenticate */
		$eualDetails = [];
		
		if ($ithenticate->validateEulaVersion($ithenticate::DEFAULT_EULA_VERSION)) {
			foreach($context->getSupportedSubmissionLocaleNames() as $localeKey => $localeName) {
				$eualDetails[$localeKey] = [
					'version' 	=> $ithenticate->getApplicableEulaVersion(),
					'url' 		=> $ithenticate->getApplicableEulaUrl($localeKey),
				];
			}
		}

		$cache->setEntireCache([$cacheId => $eualDetails]);

		return $eualDetails;
	}

	/**
	 * Create and return an instance of service class responsible to handle the
	 * communication with iThenticate service.
	 * 
	 * If const `ITHENTICATE_TEST_MODE_ENABLE` set to true, it will return an
	 * instance of mock class `TestIThenticate` instead of actual commucation
	 * responsible class.
	 * 
	 * @param string $apiUrl
	 * @param string $apiKey
	 * 
	 * @return \IThenticate|\TestIThenticate
	 */
	public function initIthenticate($apiUrl, $apiKey) {

		if (static::ITHENTICATE_TEST_MODE_ENABLE) {
			import("plugins.generic.plagiarism.TestIThenticate");
			return new \TestIThenticate(
				$apiUrl,
				$apiKey,
				static::PLUGIN_INTEGRATION_NAME,
				$this->getCurrentVersion()->getData('current')
			);
		}

		import("plugins.generic.plagiarism.IThenticate");

		return new \IThenticate(
			$apiUrl,
			$apiKey,
			static::PLUGIN_INTEGRATION_NAME,
			$this->getCurrentVersion()->getData('current')
		);
	}

	/**
	 * Get the ithenticate service access as array in format [API_URL, API_KEY]
	 * 
	 * @param Context $context
	 * @return array
	 */
	protected function getServiceAccess($context) {
		// try to get credentials for current context otherwise use default config
		list($apiUrl, $apiKey) = $this->hasForcedCredentials()
			? $this->getForcedCredentials()
			: [
				$this->getSetting($context->getId(), 'ithenticateApiUrl'), 
				$this->getSetting($context->getId(), 'ithenticateApiKey')
			];
		
		return [$apiUrl, $apiKey];
	}

	/**
	 * Check is ithenticate service access details(API URL & KEY) available for given context
	 * 
	 * @param Context $context
	 * @return bool
	 */
	protected function isServiceAccessAvailable($context) {
		return !collect($this->getServiceAccess($context))->filter()->isEmpty();
	}

	/**
	 * Stamp the EULA version and confirmation datetime for submitting user
	 * 
	 * @param User 		$user
	 * @param string 	$version
	 * 
	 * @return void
	 */
	protected function stampEualVersionToSubmittingUser($user, $version) {
		$userDao = DAORegistry::getDAO('UserDAO'); /** @var UserDAO $userDao */

		$user->setData('ithenticateEulaVersion', $version);
		$user->setData('ithenticateEulaConfirmedAt', Core::getCurrentDate());

		$userDao->updateObject($user);
	}
}
