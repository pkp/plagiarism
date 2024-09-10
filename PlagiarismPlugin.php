<?php

/**
 * @file plugins/generic/plagiarism/PlagiarismPlugin.php
 *
 * Copyright (c) 2013-2024 Simon Fraser University
 * Copyright (c) 2013-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PlagiarismPlugin
 *
 * @brief  Coar plugin class of plagiarism plugin 
 */

namespace APP\plugins\generic\plagiarism;

use APP\core\Request;
use APP\core\Services;
use APP\facades\Repo;
use APP\template\TemplateManager;
use APP\notification\NotificationManager;
use APP\submission\Submission;
use APP\plugins\generic\plagiarism\PlagiarismSubmissionSubmitListener;
use APP\plugins\generic\plagiarism\PlagiarismSettingsForm;
use APP\plugins\generic\plagiarism\IThenticate;
use APP\plugins\generic\plagiarism\classes\form\component\ConfirmSubmission;
use APP\plugins\generic\plagiarism\controllers\PlagiarismArticleGalleyGridHandler;
use APP\plugins\generic\plagiarism\controllers\PlagiarismIthenticateActionHandler;
use APP\plugins\generic\plagiarism\controllers\PlagiarismWebhookHandler;
use APP\plugins\generic\plagiarism\grids\SimilarityActionGridColumn;
use APP\plugins\generic\plagiarism\grids\RearrangeColumnsFeature;
use PKP\core\PKPRequest;
use PKP\cache\FileCache;
use PKP\cache\GenericCache;
use PKP\components\forms\FormComponent;
use PKP\services\PKPSchemaService;
use PKP\plugins\Hook;
use PKP\notification\PKPNotification;
use PKP\cache\CacheManager;
use PKP\core\Core;
use PKP\user\User;
use PKP\controllers\grid\files\review\EditorReviewFilesGridHandler;
use PKP\controllers\grid\files\submission\EditorSubmissionDetailsFilesGridHandler;
use PKP\submissionFile\SubmissionFile;
use PKP\context\Context;
use PKP\config\Config;
use PKP\security\Role;
use APP\core\Application;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\plugins\GenericPlugin;
use PKP\linkAction\request\AjaxModal;
use PKP\pages\submission\PKPSubmissionHandler;
use Illuminate\Support\Facades\Event;
use Throwable;

class PlagiarismPlugin extends GenericPlugin
{
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
	 */
	public array $similaritySettings = [
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
	 */
	public array $uploadRestrictedArchiveMimeTypes = [
		'application/gzip',
		'application/zip',
		'application/x-tar',
	];

	/**
	 * List of valid url components
	 */
	protected array $validRouteComponentHandlers = [
		'plugins.generic.plagiarism.controllers.PlagiarismWebhookHandler',
		'plugins.generic.plagiarism.controllers.PlagiarismIthenticateActionHandler',
	];

	/**
	 * Determine if running application is OPS or not
	 */
	public static function isOPS(): bool
	{
		return strtolower(Application::get()->getName()) === 'ops';
	}

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null)
	{
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

		Hook::add('Schema::get::' . PKPSchemaService::SCHEMA_SUBMISSION, [$this, 'addPlagiarismCheckDataToSubmissionSchema']);
		Hook::add('Schema::get::' . PKPSchemaService::SCHEMA_SUBMISSION_FILE, [$this, 'addPlagiarismCheckDataToSubmissionFileSchema']);
		Hook::add('Schema::get::' . PKPSchemaService::SCHEMA_CONTEXT, [$this, 'addIthenticateConfigSettingsToContextSchema']);
		Hook::add('SubmissionFile::edit', [$this, 'updateIthenticateRevisionHistory']);

		Hook::add('Schema::get::' . PKPSchemaService::SCHEMA_USER, [$this, 'stampPlagiarismDataToUserSchema']);
		Services::get('schema')->get(PKPSchemaService::SCHEMA_USER, true);

		Hook::add('LoadComponentHandler', [$this, 'handleRouteComponent']);

		Hook::add('editorsubmissiondetailsfilesgridhandler::initfeatures', [$this, 'addActionsToSubmissionFileGrid']);
		Hook::add('editorreviewfilesgridhandler::initfeatures', [$this, 'addActionsToSubmissionFileGrid']);

		Event::subscribe(new PlagiarismSubmissionSubmitListener($this));
		Hook::add('TemplateManager::display', [$this, 'addEulaAcceptanceConfirmation']);

		return $success;
	}

	public function addEulaAcceptanceConfirmation(string $hookName, array $params): bool
	{
		$templateManager =& $params[0]; /** @var TemplateManager $templateManager */
		$templatePath = $params[1]; /** @var string $templatePath */

		if ($templateManager->getTemplateVars('requestedPage') !== 'submission' || $templatePath !== 'submission/wizard.tpl') {
			return Hook::CONTINUE;
		}

		$request = Application::get()->getRequest();
		$context = $request->getContext();

		// plugin can not function if the iThenticate service access not available at global/context level 
		if (!$this->isServiceAccessAvailable($context)) {
			error_log("ithenticate service access not set for context id : " . ($context ? $context->getId() : 'undefined'));
			return Hook::CONTINUE;
		}

		// if the auto upload to ithenticate disable
		// not going to do the EULA confirmation at submission time
		if ($this->hasAutoSubmissionDisabled()) {
			return Hook::CONTINUE;
		}

		// EULA confirmation is not required, so no need for the checking of EULA acceptance
		if ($this->getContextEulaDetails($context, 'require_eula') == false) {
			return Hook::CONTINUE;
		}

		$submission = $templateManager->getTemplateVars('submission'); /** @var Submission $submission */
		$user = Repo::user()->get($request->getUser()->getId());

		// If submission has EULA stamped and user has EULA stamped and both are same version
		// so there is no need to confirm EULA again
		if ($submission->getData('ithenticateEulaVersion') &&
			$submission->getData('ithenticateEulaVersion') == $user->getData('ithenticateEulaVersion')) {
			
			return Hook::CONTINUE;
		}

		$eulaVersionDetails = $this->getContextEulaDetails($context, [
			$submission->getData('locale'),
			$request->getSite()->getPrimaryLocale(),
			IThenticate::DEFAULT_EULA_LANGUAGE
		]);

		$steps = $templateManager->getState('steps'); /** @var array $steps */
		
		$reviewStep = collect($steps)->filter(fn ($step) => $step['id'] === 'review');
		$reviewStepIndex = $reviewStep->keys()->first();
		$reviewStepSections = collect($reviewStep->first())->get('sections');
		$reviewStepSectionConfirm = collect($reviewStepSections)
			->filter(fn ($section) => $section['id'] === FORM_CONFIRM_SUBMISSION);

		if ($reviewStepSectionConfirm->count() <= 0) {
			$confirmForm = new ConfirmSubmission(
				FormComponent::ACTION_EMIT,
				$context,
				[
					'localizedEulaUrl' => $eulaVersionDetails['url'],
				]
			);

			$reviewStepSections[] = [
				'id' => $confirmForm->id,
                'name' => __('author.submit.confirmation'),
                'type' => PKPSubmissionHandler::SECTION_TYPE_CONFIRM,
                'description' => '<p>' . __('submission.wizard.confirm') . '</p>',
                'form' => $confirmForm->getConfig(),
			];
		} else {
			
			$reviewStepSectionConfirmIndex = array_key_first($reviewStepSectionConfirm->toArray());
			$reviewStepSectionConfirm = $reviewStepSectionConfirm->first();
			$reviewStepSectionConfirm['form'] = (new ConfirmSubmission(
				$reviewStepSectionConfirm['form']['action'],
				Application::get()->getRequest()->getContext(),
				[
					'localizedEulaUrl' => $eulaVersionDetails['url'],
				]
			))->getConfig();
			
			$reviewStepSections[$reviewStepSectionConfirmIndex] = $reviewStepSectionConfirm;
		}

		$steps[$reviewStepIndex]['sections'] = $reviewStepSections;

		$templateManager->setState(['steps' => $steps]);
		
		return Hook::CONTINUE;
	}

	/**
	 * Running in test mode
	 * 
	 * @return bool
	 */
	public static function isRunningInTestMode(): bool
	{
		return Config::getVar('ithenticate', 'test_mode', false);
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName()
	{
		return __('plugins.generic.plagiarism.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription()
	{
		return __('plugins.generic.plagiarism.description');
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanEnable()
	 */
	public function getCanEnable($contextId = null)
	{
		return !Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanDisable()
	 */
	public function getCanDisable($contextId = null)
	{
		return !Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * @copydoc LazyLoadPlugin::getEnabled()
	 */
	public function getEnabled($contextId = null)
	{
		// This check is required as plugin can be forced enable by setting `ithenticate` to `On`
		// in the config file which cuase the hooks to run but unavailable
		// in the installation mode by setting `installed` to `Off`
		if (!Config::getVar('general', 'installed')) {
			return false;
		}

		// This allow to force enable the plugin into the system if `ithenticate` set to `On` but the plugin
		// itself still disable as in `plugin_setings` table, the `enabled` value not set or set to `0`
		// for more details, see https://github.com/pkp/plagiarism/issues/49
		if (Config::getVar('ithenticate', 'ithenticate') && !parent::getEnabled($contextId)) {
			$this->setEnabled(true);
		}
		
		return parent::getEnabled($contextId) || Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * Add properties for this type of public identifier to the user entity's list for
	 * storage in the database.
	 * 
	 * @param string $hookName `Schema::get::user`
	 */
	public function stampPlagiarismDataToUserSchema(string $hookName, array $params): bool
	{
		$schema =& $params[0];

		$schema->properties->ithenticateEulaVersion = (object) [
			'type' => 'string',
			'description' => 'The iThenticate EULA version which has been agreed at submission file uploading to iThenticate',
			'writeOnly' => true,
			'validation' => ['nullable'],
		];

		$schema->properties->ithenticateEulaConfirmedAt = (object) [
			'type' => 'string',
			'description' => 'The timestamp at which this submission successfully completed uploading all files at iThenticate service end',
			'writeOnly' => true,
			'validation' => [
				'date:Y-m-d H:i:s',
				'nullable',
			],
		];

		return Hook::CONTINUE;
	}

	/**
	 * Add properties for this type of public identifier to the submission entity's list for
	 * storage in the database.
	 * 
	 * @param string $hookName `Schema::get::submission`
	 */
	public function addPlagiarismCheckDataToSubmissionSchema(string $hookName, array $params): bool
	{
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

		return Hook::CONTINUE;
	}

	/**
	 * Add properties for this type of public identifier to the submission file entity's list for
	 * storage in the database.
	 * 
	 * @param string $hookName `Schema::get::submissionFile`
	 */
	public function addPlagiarismCheckDataToSubmissionFileSchema(string $hookName, array $params): bool
	{
		$schema =& $params[0];

		$schema->properties->ithenticateFileId = (object) [
			'type' => 'integer',
			'description' => 'The file id from the files table',
			'writeOnly' => true,
			'validation' => ['nullable'],
		];

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

		$schema->properties->ithenticateSubmissionAcceptedAt = (object) [
			'type' => 'string',
			'description' => 'The timestamp at which this submission file successfully accepted at iThenticate service end',
			'writeOnly' => true,
			'validation' => [
				'date:Y-m-d H:i:s',
				'nullable',
			],
		];

		$schema->properties->ithenticateRevisionHistory = (object) [
			'type' => 'string',
			'description' => 'The similarity check action history on the previous revisions of this submission file',
			'writeOnly' => true,
			'validation' => ['nullable'],
		];

		return Hook::CONTINUE;
	}

	/**
	 * Add properties for this type of public identifier to the context entity's list for
	 * storage in the database.
	 * 
	 * @param string $hookName `Schema::get::context`
	 */
	public function addIthenticateConfigSettingsToContextSchema(string $hookName, array $params): bool
	{
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

		return Hook::CONTINUE;
	}

	/**
	 * Add plagiarism action history for revision files.
	 * Only contains action history for files that has been sent for plagiarism check.
	 * 
	 * @param string $hookName `SubmissionFile::edit`
	 */
	public function updateIthenticateRevisionHistory(string $hookName, array $params): bool
	{
		$submissionFile =& $params[0]; /** @var SubmissionFile $submissionFile */
		$currentSubmissionFile = $params[1]; /** @var SubmissionFile $currentSubmissionFile */

		// Do not track for plagiarism revision history until marked for tracking
		if (is_null($currentSubmissionFile->getData('ithenticateFileId'))) {
			return Hook::CONTINUE;
		}

		// If file has not changed, no change in plagiarism revision history
		if ($currentSubmissionFile->getData('fileId') === $submissionFile->getData('fileId')) {
			return Hook::CONTINUE;
		}

		// new file revision added, so add/update itnenticate revision hisotry
		$revisionHistory = json_decode($currentSubmissionFile->getData('ithenticateRevisionHistory') ?? '{}', true);
		$submissionFile->setData('ithenticateFileId', $submissionFile->getData('fileId'));

		// If the previous file not sent schedule for plagiarism check
		// no need to store it's plagiarism revision history
		if (is_null($currentSubmissionFile->getData('ithenticateId'))) {
			return Hook::CONTINUE;
		}

		array_push($revisionHistory, [
			'ithenticateFileId' => $currentSubmissionFile->getData('ithenticateFileId'),
			'ithenticateId' => $currentSubmissionFile->getData('ithenticateId'),
			'ithenticateSimilarityResult' => $currentSubmissionFile->getData('ithenticateSimilarityResult'),
			'ithenticateSimilarityScheduled' => $currentSubmissionFile->getData('ithenticateSimilarityScheduled'),
			'ithenticateSubmissionAcceptedAt' => $currentSubmissionFile->getData('ithenticateSubmissionAcceptedAt'),
		]);
		
		$submissionFile->setData('ithenticateRevisionHistory', json_encode($revisionHistory));
		$submissionFile->setData('ithenticateId', null);
		$submissionFile->setData('ithenticateSimilarityResult', null);
		$submissionFile->setData('ithenticateSimilarityScheduled', 0);
		$submissionFile->setData('ithenticateSubmissionAcceptedAt', null);

		return Hook::CONTINUE;
	}

	/**
	 * Handle the plugin specific route component requests
	 * 
	 * @param string $hookName `LoadComponentHandler`
	 * @param array $params
	 * 
	 * @return bool
	 */
	public function handleRouteComponent(string $hookName, array $params): bool
	{
		$component =& $params[0];

		if (static::isOPS() && $component === 'grid.articleGalleys.ArticleGalleyGridHandler') {
			PlagiarismArticleGalleyGridHandler::setPlugin($this);
			$params[0] = "plugins.generic.plagiarism.controllers.PlagiarismArticleGalleyGridHandler";
			return Hook::ABORT;
		}

		if (!in_array($component, $this->validRouteComponentHandlers)) {
			return Hook::CONTINUE;
		}

		$componentName = last(explode('.', $component));

		match($componentName) {
			'PlagiarismWebhookHandler' => PlagiarismWebhookHandler::setPlugin($this),
			'PlagiarismIthenticateActionHandler' => PlagiarismIthenticateActionHandler::setPlugin($this),
		};

		return Hook::ABORT;
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
	 */
	public function submitForPlagiarismCheck(Context $context, Submission $submission): bool
	{
		$request = Application::get()->getRequest();

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

		$submissionFiles = Repo::submissionFile()
			->getCollector()
			->filterBySubmissionIds([$submission->getId()])
			->getMany();

		try {
			foreach($submissionFiles as $submissionFile) { /** @var SubmissionFile $submissionFile */
				if (!$this->createNewSubmission($request, $user, $submission, $submissionFile, $ithenticate)) {
					return false;
				}
			}
		} catch (Throwable $exception) {
			error_log('submit for plagiarism check failed with excaption ' . $exception->__toString());
			$this->sendErrorMessage(__('plugins.generic.plagiarism.ithenticate.upload.complete.failed'), $submission->getId());
			return false;
		}

		$submission->setData('ithenticateSubmissionCompletedAt', Core::getCurrentDate());
		Repo::submission()->edit($submission, []);

		return true;
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
			return Hook::CONTINUE;
		}

		$user = $request->getUser();
		if (!$user->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_REVIEWER], $context->getId())) {
			return Hook::CONTINUE;
		}

		/** @var EditorSubmissionDetailsFilesGridHandler|EditorReviewFilesGridHandler $submissionDetailsFilesGridHandler */
		$submissionDetailsFilesGridHandler = & $params[0];

		$submissionDetailsFilesGridHandler->addColumn(new SimilarityActionGridColumn($this));

		$features =& $params[3]; /** @var array $features */
		$features[] = new RearrangeColumnsFeature($submissionDetailsFilesGridHandler);

		return Hook::CONTINUE;
	}

	/**
	 * Stamp the iThenticate EULA with the submission
	 * 
	 * @param Context $context
	 * @param Submission $submission
	 * 
	 * @return bool
	 */
	public function stampEulaToSubmission(Context $context, Submission $submission): bool
	{
		$request = Application::get()->getRequest();

		$eulaDetails = $this->getContextEulaDetails($context, [
			$submission->getData('locale'),
			$request->getSite()->getPrimaryLocale(),
			IThenticate::DEFAULT_EULA_LANGUAGE
		]);

		Repo::submission()->edit($submission, [
			'ithenticateEulaVersion' => $eulaDetails['version'],
			'ithenticateEulaUrl' => $eulaDetails['url'],
		]);

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
	public function stampEulaToSubmittingUser(Context $context, Submission $submission, ?User $user = null): bool
	{
		$request = Application::get()->getRequest();
		$user ??= $request->getUser();

		$submissionEulaVersion = $submission->getData('ithenticateEulaVersion');

		if (is_null($submissionEulaVersion)) {
			$eulaDetails = $this->getContextEulaDetails($context, [
				$submission->getData('locale'),
				$request->getSite()->getPrimaryLocale(),
				IThenticate::DEFAULT_EULA_LANGUAGE
			]);

			$submissionEulaVersion = $eulaDetails['version'];
		}

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
	 * @param PKPRequest 					$request
	 * @param User 							$user
	 * @param Submission 					$submission
	 * @param SubmissionFile 				$submissionFile
	 * @param IThenticate|TestIThenticate 	$ithenticate
	 * 
	 * @return bool
	 */
	public function createNewSubmission(
		PKPRequest $request,
		User $user,
		Submission $submission,
		SubmissionFile $submissionFile,
		IThenticate|TestIThenticate $ithenticate
	): bool
	{
		$context = $request->getContext();
		$publication = $submission->getCurrentPublication();
		$author = $publication->getPrimaryAuthor();

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
		$submissionFile->setData('ithenticateFileId', $submissionFile->getData('fileId'));
		$submissionFile->setData('ithenticateSimilarityScheduled', 0);

		Repo::submissionFile()->edit($submissionFile, []);

		return true;
	}

	/**
	 * Register the webhook for this context
	 * 
	 * @param IThenticate|TestIThenticate $ithenticate
	 * @param Context|null 					$context
	 * 
	 * @return bool
	 */
	public function registerIthenticateWebhook(IThenticate|TestIThenticate $ithenticate, ?Context $context = null): bool
	{
		$request = Application::get()->getRequest();
		$context ??= $request->getContext();

		$signingSecret = \Illuminate\Support\Str::random(12);
		$webhookUrl = Application::get()->getDispatcher()->url(
			$request,
			Application::ROUTE_COMPONENT,
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

			return true;
		}

		error_log("unable to complete the iThenticate webhook registration for context id {$context->getId()}");

		return false;
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
	public function getContextEulaDetails(
		Context $context,
		string|array|null $keys = null,
		mixed $default = null
	): mixed
	{
		/** @var FileCache $cache */
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
	public function retrieveEulaDetails(GenericCache $cache, mixed $cacheId): array
	{
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
	 */
	public function initIthenticate(string $apiUrl, string $apiKey): IThenticate|TestIThenticate
	{
		if (static::isRunningInTestMode()) {
			return new TestIThenticate(
				$apiUrl,
				$apiKey,
				static::PLUGIN_INTEGRATION_NAME,
				$this->getCurrentVersion()->getData('current')
			);
		}

		return new IThenticate(
			$apiUrl,
			$apiKey,
			static::PLUGIN_INTEGRATION_NAME,
			$this->getCurrentVersion()->getData('current')
		);
	}

	/**
	 * Stamp the EULA version and confirmation datetime for submitting user
	 */
	public function stampEulaVersionToUser(User $user, string $version): void
	{
		$user->setData('ithenticateEulaVersion', $version);
		$user->setData('ithenticateEulaConfirmedAt', Core::getCurrentDate());

		Repo::user()->edit($user);
	}

	/**
     * @copydoc Plugin::getActions()
     */
    function getActions($request, $verb)
    {
        $router = $request->getRouter();

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
                                    'verb'      => 'settings',
                                    'plugin'    => $this->getName(),
                                    'category'  => 'generic'
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
    function manage($args, $request) 
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $context = $request->getContext();
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->registerPlugin('function', 'plugin_url', [$this, 'smartyPluginUrl']);
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
	public function getServiceAccess(?Context $context = null): array
	{
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
	public function getForcedCredentials(?Context $context = null): array
	{
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
	public function hasForcedCredentials(?Context $context = null): bool
	{
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
	public function getSimilarityConfigSettings(Context $context, ?string $settingName = null): array|string|null
	{
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
	public function hasAutoSubmissionDisabled(): bool
	{
		$context = Application::get()->getRequest()->getContext(); /** @var Context $context */
		$contextPath = $context ? $context->getPath() : 'index';

		return (bool)(
			$this->getForcedConfigSetting($contextPath, 'disableAutoSubmission')
				?? $this->getSetting($context->getId(), 'disableAutoSubmission')
		);
	}

	/**
	 * Get the submission submitter's appropriate permission based on role in the submission context
	 * 
	 * @param Context 	$context
	 * @param User 		$user
	 * 
	 * @return string
	 */
	public function getSubmitterPermission(Context $context, User $user): string
	{	
		if ($user->hasRole([Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_MANAGER], $context->getId())) {
			return 'ADMINISTRATOR';
		}

		if ($user->hasRole([Role::ROLE_ID_SUB_EDITOR], $context->getId())) {
			return 'EDITOR';
		}

		if ($user->hasRole([Role::ROLE_ID_AUTHOR], $context->getId())) {
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
	public function sendErrorMessage(string $message, ?int $submissionId = null): void
	{
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
		
		$notificationManager = new NotificationManager();
		$managers = Repo::userGroup()
			->getCollector()
			->filterByContextIds([$context->getId()])
			->filterByRoleIds([Role::ROLE_ID_MANAGER])
			->getMany();

		while ($manager = $managers->next()) {
			$notificationManager->createTrivialNotification(
				$manager->getId(),
				PKPNotification::NOTIFICATION_TYPE_ERROR,
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
	public function getIThenticateLogoUrl(): string
	{
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
	protected function getForcedConfigSetting(string $contextPath, string $configKeyName): mixed
	{
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
	protected function isServiceAccessAvailable(?Context $context = null): bool
	{
		return !collect($this->getServiceAccess($context))->filter()->isEmpty();
	}
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\plagiarism\PlagiarismPlugin', '\PlagiarismPlugin');
}
