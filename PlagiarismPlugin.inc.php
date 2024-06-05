<?php

/**
 * @file PlagiarismPlugin.inc.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
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
	 * Mapping of similarity settings with value type
	 * 
	 * @var array
	 */
	public $similaritySettings = [
		'addToIndex' 			=> 'bool',
		'excludeQuotes' 		=> 'bool',
		'excludeBibliography' 	=> 'bool',
		'excludeCitations' 		=> 'bool',
		'excludeAbstract' 		=> 'bool',
		'excludeMethods' 		=> 'bool',
		'excludeSmallMatches' 	=> 'int',
		'allowViewerUpdate' 	=> 'bool',
	];

	/**
	 * List of archive mime type that will not be uploaded to iThenticate service
	 * 
	 * @var array
	 */
	public $uploadRestrictedArchiveMimeTypes = [
		'application/gzip',
		'application/zip',
		'application/x-tar',
	];

	/**
	 * List of valid url components
	 * 
	 * @var array
	 */
	protected $validRouteComponentHandlers = [
		'plugins.generic.plagiarism.controllers.PlagiarismWebhookHandler',
		'plugins.generic.plagiarism.controllers.PlagiarismEulaAcceptanceHandler',
		'plugins.generic.plagiarism.controllers.PlagiarismIthenticateActionHandler',
	];

	/**
	 * Determine if running application is OPS or not
	 * 
	 * @return bool
	 */
	public static function isOPS() {
		return strtolower(Application::get()->getName()) === 'ops';
	}

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);

		$this->addLocaleData();

		// if plugin hasn't registered, not allow loading plugin
		if (!$success) {
			return false;
		}

		// Plugin has been registered but not enabled
		// will allow to load plugin but no plugin feature will be executed
		if (!$this->getEnabled($mainContextId)) {
			return $success;
		}
		
		HookRegistry::register('submissionsubmitstep4form::display', [$this, 'confirmEulaAcceptance']);
		HookRegistry::register('submissionsubmitstep4form::execute', [$this, 'submitForPlagiarismCheck']);

		HookRegistry::register('Schema::get::' . SCHEMA_SUBMISSION, [$this, 'addPlagiarismCheckDataToSubmissionSchema']);
		HookRegistry::register('Schema::get::' . SCHEMA_SUBMISSION_FILE, [$this, 'addPlagiarismCheckDataToSubmissionFileSchema']);
		HookRegistry::register('Schema::get::' . SCHEMA_CONTEXT, [$this, 'addIthenticateConfigSettingsToContextSchema']);
		
		HookRegistry::register('userdao::getAdditionalFieldNames', [$this, 'handleAdditionalEulaConfirmationFieldNames']);

		HookRegistry::register('LoadComponentHandler', [$this, 'handleRouteComponent']);

		HookRegistry::register('editorsubmissiondetailsfilesgridhandler::initfeatures', [$this, 'addActionsToSubmissionFileGrid']);
		HookRegistry::register('editorreviewfilesgridhandler::initfeatures', [$this, 'addActionsToSubmissionFileGrid']);

		return $success;
	}

	/**
	 * Running in test mode
	 * 
	 * @return bool
	 */
	public static function isRunningInTestMode() {
		return Config::getVar('ithenticate', 'test_mode', false);
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
		if (!Config::getVar('ithenticate', 'ithenticate')) {
			return false;
		}

		return !$this->getEnabled($contextId);
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanDisable()
	 */
	public function getCanDisable($contextId = null) {
		if (!Config::getVar('ithenticate', 'ithenticate')) {
			return false;
		}

		return !$this->getEnabled($contextId);
	}

	/**
	 * @copydoc LazyLoadPlugin::getEnabled()
	 */
	public function getEnabled($contextId = null) {
		return parent::getEnabled($contextId) && Config::getVar('ithenticate', 'ithenticate');
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

		$schema->properties->ithenticateEulaVersion = (object) [
			'type' => 'string',
			'description' => 'The iThenticate EULA version which has been agreed at submission checklist',
			'writeOnly' => true,
			'validation' => ['nullable'],
		];

		$schema->properties->ithenticateEulaUrl = (object) [
			'type' => 'string',
			'description' => 'The iThenticate EULA url which has been agreen at submission checklist',
			'writeOnly' => true,
			'validation' => ['nullable'],
		];

		$schema->properties->ithenticateSubmissionCompletedAt = (object) [
			'type' => 'string',
			'description' => 'The timestamp at which this submission successfully completed uploading all files at iThenticate service end',
			'writeOnly' => true,
			'validation' => [
				'date:Y-m-d H:i:s',
				'nullable',
			],
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

		$schema->properties->ithenticateId = (object) [
			'type' => 'string',
			'description' => 'The iThenticate submission id for submission file',
			'writeOnly' => true,
			'validation' => ['nullable'],
		];

		$schema->properties->ithenticateSimilarityScheduled = (object) [
			'type' => 'boolean',
			'description' => 'The status which identify if the iThenticate similarity process has been scheduled for this submission file',
			'writeOnly' => true,
			'validation' => ['nullable'],
		];

		$schema->properties->ithenticateSimilarityResult = (object) [
			'type' => 'string',
			'description' => 'The similarity check result for this submission file in json format',
			'writeOnly' => true,
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

		$schema->properties->ithenticateWebhookSigningSecret = (object) [
			'type' => 'string',
			'description' => 'The iThenticate service webook registration signing secret',
			'writeOnly' => true,
			'validation' => ['nullable'],
		];

		$schema->properties->ithenticateWebhookId = (object) [
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
	 * Handle the plugin specific route component requests
	 * 
	 * @param string $hookName `LoadComponentHandler`
	 * @param array $params
	 * 
	 * @return bool
	 */
	public function handleRouteComponent($hookName, $params) {
		$component =& $params[0];

		if (static::isOPS() && $component === 'grid.articleGalleys.ArticleGalleyGridHandler') {
			$this->import('controllers.PlagiarismArticleGalleyGridHandler');
			PlagiarismArticleGalleyGridHandler::setPlugin($this);
			$params[0] = "plugins.generic.plagiarism.controllers.PlagiarismArticleGalleyGridHandler";
			return true;
		}

		if (!in_array($component, $this->validRouteComponentHandlers)) {
			return false;
		}

		import($component);
		$componentName = last(explode('.', $component));
		$componentName::setPlugin($this);

		return true;
	}

	/**
	 * Check at the final stage of submission if the submitting user has already confirmed
	 * or accepted the EULA version associated with submission
	 * 
	 * @param string $hookName `submissionsubmitstep4form::display`
	 * @param array $params
	 * 
	 * @return bool
	 */
	public function confirmEulaAcceptance($hookName, $params) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();

		// plugin can not function if the iThenticate service access not available at global/context level 
		if (!$this->isServiceAccessAvailable($context)) {
			error_log("ithenticate service access not set for context id : " . ($context ? $context->getId() : 'undefined'));
			return false;
		}

		// if the auto upload to ithenticate disable
		// not going to do the EULA confirmation at submission time
		if ($this->hasAutoSubmissionDisabled()) {
			return false;
		}
		
		$user = $request->getUser();
		$form = & $params[0]; /** @var SubmissionSubmitStep4Form $form */
		$submission = $form->submission; /** @var Submission $submission */

		// EULA confirmation is not required, so no need for the checking of EULA acceptance
		if ($this->getContextEulaDetails($context, 'require_eula') == false) {
			return false;
		}

		// If submission has EULA stamped and user has EULA stamped and both are save version
		// so there is no need to confirm EULA again
		if ($submission->getData('ithenticateEulaVersion') &&
			$submission->getData('ithenticateEulaVersion') == $user->getData('ithenticateEulaVersion')) {
			
			return false;
		}

		$actionUrl = $request->getDispatcher()->url(
			$request,
			ROUTE_COMPONENT,
			$context->getData('urlPath'),
			'plugins.generic.plagiarism.controllers.PlagiarismEulaAcceptanceHandler',
			'handle',
			null,
		);

		// As submitting user has not confrimed/accepted the EULA,
		// we will override the submission's final stage confirmation view 
		// with a EULA confirmation view
		$form->_template = $this->getTemplateResource('confirmEula.tpl');

		import("plugins.generic.plagiarism.IThenticate");
		$eulaVersionDetails = $this->getContextEulaDetails($context, [
			$submission->getData('locale'),
			$request->getSite()->getPrimaryLocale(),
			IThenticate::DEFAULT_EULA_LANGUAGE
		]);
		
		$templateManager = TemplateManager::getManager();
		$templateManager->assign([
			'submissionId' => $submission->getId(),
			'actionUrl' => $actionUrl,
			'eulaAcceptanceMessage' => __('plugins.generic.plagiarism.submission.eula.acceptance.message', [
				'localizedEulaUrl' => $eulaVersionDetails['url'],
			]),
			'cancelWarningMessage' => __('submission.submit.cancelSubmission'),
			'cancelRedirect' => $request->getDispatcher()->url(
				$request,
				ROUTE_PAGE,
				$context->getData('urlPath'),
				'submissions'
			)
		]);

		return false;
	}

	/**
	 * Complete the submission process at iThenticate service's end
	 * The steps follows as:
	 * 	- Check if proper service credentials(API Url and Key) are available
	 *  - Register webhook for context if not already registered
	 *  - Check for EULA confirmation requirement
	 * 		- Check if EULA is stamped to submission
	 * 			- if not stamped, not allowed to submit at iThenticate
	 * 		- Check if EULA is stamped to submitting user
	 * 			- if not stamped, not allowed to submit at iThenticate
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
		$context = $request->getContext();

		// plugin can not function if the iThenticate service access not available at global/context level
		if (!$this->isServiceAccessAvailable($context)) {
			error_log("ithenticate service access not set for context id : " . ($context ? $context->getId() : 'undefined'));
			return false;
		}

		// if the auto upload to ithenticate disable
		// not going to upload files to iThenticate at submission time
		if ($this->hasAutoSubmissionDisabled()) {
			return false;
		}
		
		$form =& $args[0]; /** @var SubmissionSubmitStep4Form $form */
		$submission = $form->submission; /** @var Submission $submission */
		$user = $request->getUser();

		$ithenticate = $this->initIthenticate(...$this->getServiceAccess($context)); /** @var IThenticate $ithenticate */

		// If no webhook previously registered for this Context, register it
		if (!$context->getData('ithenticateWebhookId')) {
			$this->registerIthenticateWebhook($ithenticate, $context);
		}

		$ithenticate->setApplicableEulaVersion($submission->getData('ithenticateEulaVersion'));

		// Check EULA stamped to submission or submitter only if it is required
		if ($this->getContextEulaDetails($context, 'require_eula') !== false) {
			// not going to sent it for plagiarism check if EULA not stamped to submission or submitter
			if (!$submission->getData('ithenticateEulaVersion') || !$user->getData('ithenticateEulaVersion')) {
				$this->sendErrorMessage(__('plugins.generic.plagiarism.stamped.eula.missing'), $submission->getId());
				return false;
			}
		}

		/** @var DAOResultIterator $submissionFiles */
		$submissionFiles = Services::get('submissionFile')->getMany([
			'submissionIds' => [$submission->getId()],
		]);

		try {
			foreach($submissionFiles as $submissionFile) { /** @var SubmissionFile $submissionFile */
				if (!$this->createNewSubmission($request, $user, $submission, $submissionFile, $ithenticate)) {
					return false;
				}
			}
		} catch (\Throwable $exception) {
			error_log('submit for plagiarism check failed with excaption ' . $exception->__toString());
			$this->sendErrorMessage(__('plugins.generic.plagiarism.ithenticate.upload.complete.failed'), $submission->getId());
			return false;
		}

		$submission->setData('ithenticateSubmissionCompletedAt', Core::getCurrentDate());
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
		$submissionDao->updateObject($submission);

		return false;
	}

	/**
	 * Add ithenticate related data and actions to submission file grid view
	 * 
	 * @param string $hookName `editorsubmissiondetailsfilesgridhandler::initfeatures` or `editorreviewfilesgridhandler::initfeatures`
	 * @param array $params
	 * 
	 * @return bool
	 */
	public function addActionsToSubmissionFileGrid($hookName, $params) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();

		// plugin can not function if the iThenticate service access not available at global/context level
		if (!$this->isServiceAccessAvailable($context)) {
			error_log("ithenticate service access not set for context id : " . ($context ? $context->getId() : 'undefined'));
			return false;
		}

		$user = $request->getUser();
		if (!$user->hasRole([ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_REVIEWER], $context->getId())) {
			return false;
		}

		/** @var EditorSubmissionDetailsFilesGridHandler|EditorReviewFilesGridHandler $submissionDetailsFilesGridHandler */
		$submissionDetailsFilesGridHandler = & $params[0];

		$this->import('grids.SimilarityActionGridColumn');
		$submissionDetailsFilesGridHandler->addColumn(new SimilarityActionGridColumn($this));

		$this->import('grids.RearrangeColumnsFeature');
		$features =& $params[3]; /** @var array $features */
		$features[] = new RearrangeColumnsFeature($submissionDetailsFilesGridHandler);

		return false;
	}

	/**
	 * Stamp the iThenticate EULA with the submission
	 * 
	 * @param Context $context
	 * @param Submission $submission
	 * 
	 * @return bool
	 */
	public function stampEulaToSubmission($context, $submission) {

		$request = Application::get()->getRequest();

		$eulaDetails = $this->getContextEulaDetails($context, [
			$submission->getData('locale'),
			$request->getSite()->getPrimaryLocale(),
			IThenticate::DEFAULT_EULA_LANGUAGE
		]);

		$submission->setData('ithenticateEulaVersion', $eulaDetails['version']);
		$submission->setData('ithenticateEulaUrl', $eulaDetails['url']);

		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
		$submissionDao->updateObject($submission);

		return true;
	}

	/**
	 * Stamp the iThenticate EULA to the submitting user
	 * 
	 * @param Context 		$context
	 * @param Submission 	$submission
	 * @param User|null 	$user
	 * 
	 * @return bool
	 */
	public function stampEulaToSubmittingUser($context, $submission, $user = null) {
		$request = Application::get()->getRequest();
		$user ??= $request->getUser();

		if (is_null($submission->getData('ithenticateEulaVersion'))) {
			$submissionDao = DAORegistry::getDAO("SubmissionDAO"); /** @var SubmissionDAO $submissionDao */
			$submission = $submissionDao->getById(1); /** @var Submission $submission */
		}

		$submissionEulaVersion = $submission->getData('ithenticateEulaVersion');

		// If submission EULA version has already been stamped to user
		// no need to do the confirmation and stamping again
		if ($user->getData('ithenticateEulaVersion') === $submissionEulaVersion) {
			return false;
		}

		$ithenticate = $this->initIthenticate(...$this->getServiceAccess($context)); /** @var IThenticate $ithenticate */
		$ithenticate->setApplicableEulaVersion($submissionEulaVersion);
		
		// Check if user has ever already accepted this EULA version and if so, stamp it to user
		// Or, try to confirm the EULA for user and upon succeeding, stamp it to user
		if ($ithenticate->verifyUserEulaAcceptance($user, $submissionEulaVersion) ||
			$ithenticate->confirmEula($user, $context)) {
			$this->stampEulaVersionToUser($user, $submissionEulaVersion);
			return true;
		}

		return false;
	}

	/**
	 * Create a new submission at iThenticate service's end
	 * 
	 * @param Request 						$request
	 * @param User 							$user
	 * @param Submission 					$submission
	 * @param SubmissionFile 				$submissionFile
	 * @param IThenticate|TestIThenticate 	$ithenticate
	 * 
	 * @return bool
	 */
	public function createNewSubmission($request, $user, $submission, $submissionFile, $ithenticate) {
		$context = $request->getContext();
		$publication = $submission->getCurrentPublication();
		$author = $publication->getPrimaryAuthor();
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /** @var SubmissionFileDAO $submissionFileDao */

		$submissionUuid = $ithenticate->createSubmission(
			$request->getSite(),
			$submission,
			$user,
			$author,
			static::SUBMISSION_AUTOR_ITHENTICATE_DEFAULT_PERMISSION,
			$this->getSubmitterPermission($context, $user)
		);

		if (!$submissionUuid) {
			$this->sendErrorMessage(
				__('plugins.generic.plagiarism.ithenticate.submission.create.failed', [
					'submissionFileId' => $submissionFile->getId(),
				]), 
				$submission->getId()
			);
			return false;
		}

		$pkpFileService = Services::get('file'); /** @var \PKP\Services\PKPFileService $pkpFileService */
		$file = $pkpFileService->get($submissionFile->getData('fileId'));

		if (in_array($file->mimetype, $this->uploadRestrictedArchiveMimeTypes)) {
			return true;
		}

		$uploadStatus = $ithenticate->uploadFile(
			$submissionUuid, 
			$submissionFile->getData("name", $publication->getData("locale")),
			$pkpFileService->fs->read($file->path),
		);

		// Upload submission files for successfully created submission at iThenticate's end
		if (!$uploadStatus) {
			$this->sendErrorMessage(
				__('plugins.generic.plagiarism.ithenticate.file.upload.failed', [
					'submissionFileId' => $submissionFile->getId(),
				]), 
				$submission->getId()
			);
			return false;
		}

		$submissionFile->setData('ithenticateId', $submissionUuid);
		$submissionFile->setData('ithenticateSimilarityScheduled', 0);
		$submissionFileDao->updateObject($submissionFile);

		return true;
	}

	/**
	 * Register the webhook for this context
	 * 
	 * @param IThenticate|TestIThenticate $ithenticate
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
			$contextService = Services::get('context'); /** @var \PKP\Services\PKPContextService $contextService */
			$context = $contextService->edit($context, [
				'ithenticateWebhookSigningSecret' => $signingSecret,
				'ithenticateWebhookId' => $webhookId
			], $request);
		} else {
			error_log("unable to complete the iThenticate webhook registration for context id {$context->getId()}");
		}
	}

	/**
	 * Get the cached EULA details form Context
	 * The eula details structure is in the following format
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
	 * Based on the `key` param defined, it will return in following format
	 * 	- 	if null, will return the whole details in above structure
	 * 	- 	if array, will try to find the first matching `key` index value and return that
	 * 	- 	if array and not found any match or if string, will return value based on last
	 * 		array index or string value and considering the default value along with it
	 * 
	 * @param Context 			$context
	 * @param string|array|null $keys
	 * @param mixed 			$default
	 * 
	 * @return mixed
	 */
	public function getContextEulaDetails($context, $keys = null, $default = null) {
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

		$eulaDetails = $cache->get($context->getId());

		if (!$keys) {
			return $eulaDetails;
		}

		if (is_array($keys)) {
			foreach ($keys as $key) {
				$value = data_get($eulaDetails, $key);
				if ($value) {
					return $value;
				}
			}
		}

		return data_get(
			$eulaDetails,
			last(\Illuminate\Support\Arr::wrap($keys)),
			$default
		);
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
		$ithenticate = $this->initIthenticate(...$this->getServiceAccess($context)); /** @var IThenticate $ithenticate */
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

		// Also store the default iThenticate language version details
		if (!isset($eulaDetails[$ithenticate::DEFAULT_EULA_LANGUAGE])) {
			$eulaDetails[$ithenticate::DEFAULT_EULA_LANGUAGE] = [
				'version' 	=> $ithenticate->getApplicableEulaVersion(),
				'url' 		=> $ithenticate->getApplicableEulaUrl($ithenticate::DEFAULT_EULA_LANGUAGE),
			];
		}

		$cache->setEntireCache([$cacheId => $eulaDetails]);

		return $eulaDetails;
	}

	/**
	 * Create and return an instance of service class responsible to handle the
	 * communication with iThenticate service.
	 * 
	 * If the test mode is enable, it will return an instance of mock class 
	 * `TestIThenticate` instead of actual commucation responsible class.
	 * 
	 * @param string $apiUrl
	 * @param string $apiKey
	 * 
	 * @return IThenticate|TestIThenticate
	 */
	public function initIthenticate($apiUrl, $apiKey) {

		if (static::isRunningInTestMode()) {
			$this->import('TestIThenticate');
			return new TestIThenticate(
				$apiUrl,
				$apiKey,
				static::PLUGIN_INTEGRATION_NAME,
				$this->getCurrentVersion()->getData('current')
			);
		}

		$this->import('IThenticate');

		return new IThenticate(
			$apiUrl,
			$apiKey,
			static::PLUGIN_INTEGRATION_NAME,
			$this->getCurrentVersion()->getData('current')
		);
	}

	/**
	 * Stamp the EULA version and confirmation datetime for submitting user
	 * 
	 * @param User 		$user
	 * @param string 	$version
	 * 
	 * @return void
	 */
	public function stampEulaVersionToUser($user, $version) {
		$userDao = DAORegistry::getDAO('UserDAO'); /** @var UserDAO $userDao */

		$user->setData('ithenticateEulaVersion', $version);
		$user->setData('ithenticateEulaConfirmedAt', Core::getCurrentDate());

		$userDao->updateObject($user);
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
	 * Get the ithenticate service access as array in format [API_URL, API_KEY]
	 * Will try to get credentials for current context otherwise use default config
	 * 
	 * @param Context|null $context
	 * @return array	The service access creds in format as [API_URL, API_KEY]
	 */
	public function getServiceAccess($context = null) {

		if ($this->hasForcedCredentials($context)) {
			list($apiUrl, $apiKey) = $this->getForcedCredentials($context);
			return [$apiUrl, $apiKey];
		}

		if ($context && $context instanceof Context) {
			return [
				$this->getSetting($context->getId(), 'ithenticateApiUrl'), 
				$this->getSetting($context->getId(), 'ithenticateApiKey')
			];
		}

		return ['', ''];
	}

	/**
	 * Fetch credentials from config.inc.php, if available
	 * 
	 * @param Context|null $context
	 * @return array api url and api key, or null(s)
	 */
	public function getForcedCredentials($context = null) {
		$contextPath = $context ? $context->getPath() : 'index';

		$apiUrl = $this->getForcedConfigSetting($contextPath, 'api_url');
		$apiKey = $this->getForcedConfigSetting($contextPath, 'api_key');

		return [$apiUrl, $apiKey];
	}

	/**
	 * Check and determine if plagiarism checking service creds has been set forced in config.inc.php
	 * 
	 * @param Context|null $context
	 * @return bool
	 */
	public function hasForcedCredentials($context = null) {
		list($apiUrl, $apiKey) = $this->getForcedCredentials($context);
		return !empty($apiUrl) && !empty($apiKey);
	}

	/**
	 * Get the configuration settings(all or specific) for ithenticate similarity report generation process
	 * 
	 * @param Context 		$context
	 * @param string|null 	$settingName
	 * 
	 * @return array|string|null
	 */
	public function getSimilarityConfigSettings($context, $settingName = null) {
		$contextPath = $context->getPath();
		$similarityConfigSettings = [];

		foreach(array_keys($this->similaritySettings) as $settingOption) {
			$similarityConfigSettings[$settingOption] = $this->getForcedConfigSetting($contextPath, $settingOption)
				?? $this->getSetting($context->getId(), $settingOption);
		}

		return $settingName
			? ($similarityConfigSettings[$settingName] ?? null)
			: $similarityConfigSettings;
	}

	/**
	 * Check if auto upload of submission file has been disable globally or context level
	 * 
	 * @return bool
	 */
	public function hasAutoSubmissionDisabled() {
		$context = Application::get()->getRequest()->getContext(); /** @var Context $context */
		$contextPath = $context ? $context->getPath() : 'index';

		return $this->getForcedConfigSetting($contextPath, 'disableAutoSubmission')
			?? $this->getSetting($context->getId(), 'disableAutoSubmission');
	}

	/**
	 * Get the submission submitter's appropriate permission based on role in the submission context
	 * 
	 * @param Context 	$context
	 * @param User 		$user
	 * 
	 * @return string
	 */
	public function getSubmitterPermission($context, $user) {
		
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

	/**
	 * Send the editor an error message
	 * 
	 * @param string 	$message 		The error/exception message to set as notification and log in error file
	 * @param int|null 	$submissionid 	The submission id for which error/exception has generated
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
	 * Get the iThenticate logo URL
	 * 
	 * @return string
	 */
	public function getIThenticateLogoUrl() {
		return Application::get()->getRequest()->getBaseUrl()
			. '/'
			. $this->getPluginPath()
			. '/'
			. 'assets/logo/ithenticate-badge-rec-positive.png';
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
	 * Check is ithenticate service access details(API URL & KEY) available at global level or
	 * for the given context
	 * 
	 * @param Context|null $context
	 * @return bool
	 */
	protected function isServiceAccessAvailable($context = null) {
		return !collect($this->getServiceAccess($context))->filter()->isEmpty();
	}
}
