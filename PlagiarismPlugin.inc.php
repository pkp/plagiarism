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
	 * The default permission of submission primary author's to pass to the iThenticate service
	 */
	public const SUBMISSION_AUTOR_ITHENTICATE_DEFAULT_PERMISSION = 'USER';

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
	 * Running in test mode
	 * 
	 * @return bool
	 */
	public static function isRunningInTestMode() {
		return static::ITHENTICATE_TEST_MODE_ENABLE;
	}

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
		HookRegistry::register('Schema::get::' . SCHEMA_SUBMISSION_FILE, [$this, 'addPlagiarismCheckDataToSubmissionFileSchema']);
		HookRegistry::register('Schema::get::' . SCHEMA_CONTEXT, [$this, 'addIthenticateConfigSettingsToContextSchema']);
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

		if (!$this->isServiceAccessAvailable($context)) {
			error_log("ithenticate service access not set for context id {$context->getId()}");
			return false;
		}

		$eulaDetails = $this->getContextEulaDetails($context);

		// if EULA confirmation is not required, so no need to show EULA as part of submission checklist
		if ($eulaDetails['require_eula'] === false) {
			return false;
		}
		
		foreach($context->getData('submissionChecklist') as $locale => $checklist) {
			array_push($checklist, [
				'order' => (collect($checklist)->pluck('order')->sort(SORT_REGULAR)->last() ?? 0) + 1,
				'content' => __('plugins.generic.plagiarism.submission.checklist.eula', [
					'localizedEulaUrl' => $eulaDetails[$locale]['url']
				]),
			]);

			$context->setData('submissionChecklist', $checklist, $locale);
		}
		
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
	 * Add properties for this type of public identifier to the submission file entity's list for
	 * storage in the database.
	 * 
	 * @param string $hookName `Schema::get::submissionFile`
	 * @param array $params
	 * 
	 * @return bool
	 */
	public function addPlagiarismCheckDataToSubmissionFileSchema($hookName, $params) {
		$schema =& $params[0];

		$schema->properties->ithenticate_id = (object) [
			'type' => 'string',
			'description' => 'The iThenticate submission id for submission file',
			'apiSummary' => true,
			'validation' => ['nullable'],
		];

		$schema->properties->ithenticate_similarity_scheduled = (object) [
			'type' => 'boolean',
			'description' => 'The status which identify if the iThenticate similarity process has been scheduled for this submission file',
			'apiSummary' => true,
			'validation' => ['nullable'],
		];

		$schema->properties->ithenticate_similarity_result = (object) [
			'type' => 'string',
			'description' => 'The similarity check result for this submission file in json format',
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
	public function addIthenticateConfigSettingsToContextSchema($hookName, $params) {
		$schema =& $params[0];

		$schema->properties->ithenticate_webhook_signing_secret = (object) [
			'type' => 'string',
			'description' => 'The iThenticate service webook registration signing secret',
			'writeOnly' => true,
			'validation' => ['nullable'],
		];

		$schema->properties->ithenticate_webhook_id = (object) [
			'type' => 'string',
			'description' => 'The iThenticate service webook id that return back after successful webhook registration',
			'writeOnly' => true,
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
	 * Send the editor an error message
	 * 
	 * @param string 	$message			The error/exception message to set as notification and log in error file
	 * @param int|null 	$submissionid		The submission id for which error/exception has generated
	 * 
	 * @return void
	 */
	public function sendErrorMessage($message, $submissionId = null) {

		$request = Application::get()->getRequest(); /** @var Request $request */
		$context = $request->getContext(); /** @var Context $context */
		$message = $submissionId
			? __(
				'plugins.generic.plagiarism.errorMessage', [
					'submissionId' => $submissionId,
					'errorMessage' => $message
				]
			) : __(
				'plugins.generic.plagiarism.general.errorMessage', [
					'errorMessage' => $message
				]
			);
		
		import('classes.notification.NotificationManager');
		$notificationManager = new NotificationManager();
		$roleDao = DAORegistry::getDAO('RoleDAO'); /** @var RoleDAO $roleDao  */
		
		// Get the managers.
		$managers = $roleDao->getUsersByRoleId(ROLE_ID_MANAGER, $context->getId()); /** @var DAOResultFactory $managers */
		while ($manager = $managers->next()) {
			$notificationManager->createTrivialNotification(
				$manager->getId(), 
				NOTIFICATION_TYPE_ERROR, 
				['contents' => $message]
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

		if (!static::isRunningInTestMode() && !$this->isServiceAccessAvailable($context)) {
			$this->sendErrorMessage("ithenticate service access not set for context id {$context->getId()}", $submission->getId());
			return false;
		}

		if ($this->getContextEulaDetails($context, 'require_eula') === false) {
			// EULA confirmation is not required, so no stamping of EULA with the submission
			return false;
		}

		$eulaDetails = $this->getContextEulaDetails(
			Application::get()->getRequest()->getContext(), 
			$submission->getData('locale')
		);

		$submission->setData('ithenticate_eula_version', $eulaDetails['version']);
		$submission->setData('ithenticate_eula_url', $eulaDetails['url']);

		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
		$submissionDao->updateObject($submission);

		return false;
	}

	/**
	 * Complete the submission process at iThenticate service's end
	 * The steps follows as:
	 * 	- Check if proper service credentials(API Url and Key) are available
	 *  - Register webhook for context if not already registered
	 *  - Check for EULA confrimation requirement
	 * 		- Required
	 * 			- Only allow to proceed if EULA is stamped to submission at initial stage
	 * 			- Stamp the EULA confirmation to submitter if has not confirmed the version already
	 * 		- Not Required
	 * 			- No Need to check the submission stamping to EULA
	 * 			- No need to stamp the EULA to the submitter
	 *  - Traversing the submission files
	 *  	- Create new submission at ithenticate's end for each submission file
	 * 		- Upload the file for newly created submission uuid return back from ithenticate
	 * 		- Stamp the retuning iThenticate submission id with submission file
	 * 	- Return bool to indicate the status of process completion
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
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /** @var SubmissionFileDAO $submissionFileDao */
		$context = $request->getContext();
		$publication = $submission->getCurrentPublication();
		$author = $publication->getPrimaryAuthor();
		$user = $request->getUser();

		if (!static::isRunningInTestMode() && !$this->isServiceAccessAvailable($context)) {
			$this->sendErrorMessage("ithenticate service access not set for context id {$context->getId()}", $submission->getId());
			return false;
		}

		$ithenticate = $this->initIthenticate(...$this->getServiceAccess($context)); /** @var \IThenticate $ithenticate */

		// If no webhook previously registered for this Context, register it
		if (!$context->getData('ithenticate_webhook_id')) {
			$this->registerIthenticateWebhook($ithenticate, $context);
		}

		// If EULA confirmation is not required,
		// no need to check the EULA stamping to submission and submitter
		if ($this->getContextEulaDetails($context, 'require_eula') !== false) {

			// if EULA details not stamped to submission, not going to sent it for plagiarism check
			if (!$submission->getData('ithenticate_eula_version') || !$submission->getData('ithenticate_eula_url')) {
				$this->sendErrorMessage('Unable to obtain the stamped EULA details to submission', $submission->getId());
				return false;
			}

			$submissionEulaVersion = $submission->getData('ithenticate_eula_version');
			$ithenticate->setApplicableEulaVersion($submissionEulaVersion);
			
			// Check if EULA stamped to submitter and if not, try to stamp it
			if (!$user->getData('ithenticateEulaVersion') ||
				$user->getData('ithenticateEulaVersion') !== $submissionEulaVersion) {
				
				// Check if user has ever already accepted this EULA version and if so, stamp it to user
				// Or, try to confirm the EULA for user and upon succeeding, stamp it to user
				if ($ithenticate->verifyUserEulaAcceptance($user, $submissionEulaVersion) ||
					$ithenticate->confirmEula($user, $context)) {
					$this->stampEulaVersionToSubmittingUser($user, $submissionEulaVersion);
				} else {
					$this->sendErrorMessage('Unable to stamp the EULA details to submission submitter', $submission->getId());
					return false;
				}
			}
		}

		/** @var DAOResultIterator $submissionFiles */
		$submissionFiles = Services::get('submissionFile')->getMany([
            'submissionIds' => [$submission->getId()],
		]);

		try {
			foreach($submissionFiles as $submissionFile) { /** @var SubmissionFile $submissionFile */
				// Create a new submission at iThenticate's end
				$submissionUuid = $ithenticate->createSubmission(
					$request->getSite(),
					$submission,
					$user,
					$author,
					static::SUBMISSION_AUTOR_ITHENTICATE_DEFAULT_PERMISSION,
					$this->getSubmitterPermission($context, $user)
				);
	
				if (!$submissionUuid) {
					$this->sendErrorMessage("Could not create the submission at iThenticate for file id {$submissionFile->getId()}", $submission->getId());
					return false;
				}
	
				$file = Services::get('file')->get($submissionFile->getData('fileId'));
				$uploadStatus = $ithenticate->uploadFile(
					$submissionUuid, 
					$submissionFile->getData("name", $publication->getData("locale")),
					Services::get('file')->fs->read($file->path),
				);
	
				// Upload submission files for successfully created submission at iThenticate's end
				if (!$uploadStatus) {
					$this->sendErrorMessage('Could not complete the file upload at iThenticate for file id ' . $submissionFile->getData("name", $publication->getData("locale")), $submission->getId());
					return false;
				}
	
				$submissionFile->setData('ithenticate_id', $submissionUuid);
				$submissionFile->setData('ithenticate_similarity_scheduled', 0);
				$submissionFileDao->updateObject($submissionFile);
			}
		} catch (\Throwable $exception) {
			$this->sendErrorMessage($exception->getMessage(), $submission->getId());
			return false;
		}

		return true;
	}

	/**
	 * Register the webhook for this context
	 * 
	 * @param \IThenticate|\TestIThenticate $ithenticate
	 * @param Context|null 					$context
	 * 
	 * @return void
	 */
	public function registerIthenticateWebhook($ithenticate, $context = null) {

		$request = Application::get()->getRequest();
		$context ??= $request->getContext();

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
			error_log("unable to complete the iThenticate webhook registration for context id {$context->getId()}");
		}
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
								[
									'verb' => 'settings',
									'plugin' => $this->getName(),
									'category' => 'generic'
								]
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
				$form = new PlagiarismSettingsForm($this, $context);

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
	 * @param Context 	$context
	 * @param mixed 	$locale
	 * @param mixed 	$default
	 * 
	 * @return mixed
	 */
	public function getContextEulaDetails($context, $key = null, $default = null) {
		/** @var \FileCache $cache */
		$cache = CacheManager::getManager()
			->getCache(
				'ithenticate_eula', 
				$context->getId(),
				[$this, 'retrieveEulaDetails']
			);
		
		// if running on ithenticate test mode, set the cache life time to 60 seconds
		$cacheLifetime = static::isRunningInTestMode() ? 60 : static::EULA_CACHE_LIFETIME;
		if (time() - $cache->getCacheTime() > $cacheLifetime) {
			$cache->flush();
		}

		// $cache->flush();

		$eulaDetails = $cache->get($context->getId());

		return $key 
			? data_get($eulaDetails, $key, $default)
			: $eulaDetails;
	}

	/**
	 * Retrieved and generate the localized EULA details and EULA confirmation requirement
	 * for given context and cache it in following format
	 * [
	 *   'require_eula' => null/true/false, // null => not possible to retrived, 
	 * 										// true => EULA confirmation required, 
	 * 										// false => EULA confirmation not required
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
	public function retrieveEulaDetails($cache, $cacheId) {
		$context = Application::get()->getRequest()->getContext();
		$ithenticate = $this->initIthenticate(...$this->getServiceAccess($context)); /** @var \IThenticate $ithenticate */
		$eulaDetails = [];

		$eulaDetails['require_eula'] = $ithenticate->getEnabledFeature('tenant.require_eula');

		// If `require_eula` is set to `true` that is EULA confirmation is required
		// and default EULA version is verified
		// we will map and store locale key to eula details (version and url) in following structure
		//   'en_US' => [
		//     'version' => '',
		//     'url' => '',
		//   ],
		//   ...
		if ($eulaDetails['require_eula'] === true &&
			$ithenticate->validateEulaVersion($ithenticate::DEFAULT_EULA_VERSION)) {

			foreach($context->getSupportedSubmissionLocaleNames() as $localeKey => $localeName) {
				$eulaDetails[$localeKey] = [
					'version' 	=> $ithenticate->getApplicableEulaVersion(),
					'url' 		=> $ithenticate->getApplicableEulaUrl($localeKey),
				];
			}
		}

		$cache->setEntireCache([$cacheId => $eulaDetails]);

		return $eulaDetails;
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

		if (static::isRunningInTestMode()) {
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
	public function getServiceAccess($context) {
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
	 * Fetch credentials from config.inc.php, if available
	 * 
	 * @return array api url and api key, or null(s)
	 */
	public function getForcedCredentials() {
		$context = Application::get()->getRequest()->getContext(); /** @var Context $context */
		$contextPath = $context ? $context->getPath() : 'index';

		$apiUrl = $this->getForcedConfigSetting($contextPath, 'api_url');
		$apiKey = $this->getForcedConfigSetting($contextPath, 'api_key');

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
	 * Get the configuration settings for ithenticate similarity report generation process
	 * 
	 * @param Context $context
	 * @return array
	 */
	public function getSimilarityConfigSettings($context) {
		$contextPath = $context->getPath();

		return [
			'addToIndex' 			=> $this->getForcedConfigSetting($contextPath, 'addToIndex') 			?? $this->getSetting($context->getId(), 'addToIndex'),
			'excludeQuotes' 		=> $this->getForcedConfigSetting($contextPath, 'excludeQuotes') 		?? $this->getSetting($context->getId(), 'excludeQuotes'),
			'excludeBibliography' 	=> $this->getForcedConfigSetting($contextPath, 'excludeBibliography') 	?? $this->getSetting($context->getId(), 'excludeBibliography'),
			'excludeCitations' 		=> $this->getForcedConfigSetting($contextPath, 'excludeCitations') 		?? $this->getSetting($context->getId(), 'excludeCitations'),
			'excludeAbstract' 		=> $this->getForcedConfigSetting($contextPath, 'excludeAbstract') 		?? $this->getSetting($context->getId(), 'excludeAbstract'),
			'excludeMethods' 		=> $this->getForcedConfigSetting($contextPath, 'excludeMethods') 		?? $this->getSetting($context->getId(), 'excludeMethods'),
			'excludeSmallMatches' 	=> $this->getForcedConfigSetting($contextPath, 'excludeSmallMatches') 	?? $this->getSetting($context->getId(), 'excludeSmallMatches'),
		];
	}

	/**
	 * Get the forced config at global or context level if defined
	 * 
	 * @param string $contextPath
	 * @param string $configKeyName
	 * 
	 * @return mixed
	 */
	protected function getForcedConfigSetting($contextPath, $configKeyName) {
		return Config::getVar(
			'ithenticate',
			"{$configKeyName}[{$contextPath}]",
			Config::getVar('ithenticate', $configKeyName)
		);
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
	protected function stampEulaVersionToSubmittingUser($user, $version) {
		$userDao = DAORegistry::getDAO('UserDAO'); /** @var UserDAO $userDao */

		$user->setData('ithenticateEulaVersion', $version);
		$user->setData('ithenticateEulaConfirmedAt', Core::getCurrentDate());

		$userDao->updateObject($user);
	}

	/**
	 * Get the submission submitter's appropriate permission based on role in the submission context
	 * 
	 * @param Context 	$context
	 * @param User 		$user
	 * 
	 * @return string
	 */
	protected function getSubmitterPermission($context, $user) {
		
		if ($user->hasRole([ROLE_ID_SITE_ADMIN, ROLE_ID_MANAGER], $context->getId())) {
			return 'ADMINISTRATOR';
		}

		if ($user->hasRole([ROLE_ID_SUB_EDITOR], $context->getId())) {
			return 'EDITOR';
		}

		if ($user->hasRole([ROLE_ID_AUTHOR], $context->getId())) {
			return 'USER';
		}

		return 'UNDEFINED';
	}
}
