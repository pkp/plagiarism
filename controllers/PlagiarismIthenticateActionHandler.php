<?php

/**
 * @file plugins/generic/plagiarism/controllers/PlagiarismIthenticateActionHandler.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlagiarismIthenticateActionHandler
 *
 * @brief Handle the different iThenticate service related actions
 */

namespace APP\plugins\generic\plagiarism\controllers; 

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;
use APP\notification\NotificationManager;
use APP\submission\Submission;
use APP\template\TemplateManager;
use APP\plugins\generic\plagiarism\IThenticate;
use APP\plugins\generic\plagiarism\controllers\PlagiarismComponentHandler;
use Illuminate\Support\Arr;
use PKP\context\Context;
use PKP\core\Core;
use PKP\db\DAO;
use PKP\core\JSONMessage;
use PKP\session\SessionManager;
use PKP\notification\PKPNotification;
use PKP\site\SiteDAO;
use PKP\db\DAORegistry;
use PKP\submissionFile\SubmissionFile;
use PKP\security\authorization\SubmissionFileAccessPolicy;
use PKP\security\Role;

class PlagiarismIthenticateActionHandler extends PlagiarismComponentHandler
{
	/**
	 * @copydoc PKPHandler::__construct()
	 */
	public function __construct(PlagiarismPlugin $plugin)
	{
		parent::__construct($plugin);

		$this->addRoleAssignment(
			[
				Role::ROLE_ID_MANAGER,
				Role::ROLE_ID_SUB_EDITOR,
				Role::ROLE_ID_ASSISTANT, 
				Role::ROLE_ID_SITE_ADMIN
			],
			[
				'launchViewer',
				'scheduleSimilarityReport',
				'refreshSimilarityResult',
				'submitSubmission',
				'acceptEulaAndExecuteIntendedAction',
				'confirmEula',
			]
		);
	}

	/**
	 * @copydoc PlagiarismComponentHandler::authorize()
	 */
	public function authorize($request, &$args, $roleAssignments)
	{
		$this->markRoleAssignmentsChecked();

		$this->addPolicy(
			new SubmissionFileAccessPolicy(
				$request,
				$args,
				$roleAssignments,
				SUBMISSION_FILE_ACCESS_READ,
				(int) $args['submissionFileId']
			)
		);
		
		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * Launch the iThenticate similarity report viewer
	 */
	public function launchViewer(array $args, Request $request)
	{
		$context = $request->getContext();
		$user = $request->getUser();
		$submissionFile = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */
		$submission = Repo::submission()->get($submissionFile->getData('submissionId'));
		$siteDao = DAORegistry::getDAO("SiteDAO"); /** @var SiteDAO $siteDao */
		$site = $siteDao->getSite();

		/** @var IThenticate $ithenticate */
		$ithenticate = $this->_plugin->initIthenticate(
			...$this->_plugin->getServiceAccess($context)
		);

		// If EULA is required and submission has EULA stamped, we set the applicable EULA
		// Otherwise get the current EULA from default one and set the applicable
		// Basically we need to retrieve the available langs details from EULA details
		$this->_plugin->getContextEulaDetails($context, 'require_eula') == true &&
		$submission->getData('ithenticateEulaVersion')
			? $ithenticate->setApplicableEulaVersion($submission->getData('ithenticateEulaVersion'))
			: $ithenticate->validateEulaVersion($ithenticate::DEFAULT_EULA_VERSION);

		$locale = $ithenticate
			->getApplicableLocale(
				collect([$submission->getData("locale")])
					->merge(Arr::wrap($user->getData("locales")))
					->merge([$context->getPrimaryLocale(), $site->getPrimaryLocale()])
					->unique()
					->filter()
					->toArray()
			);

		$viewerUrl = $ithenticate->createViewerLaunchUrl(
			$submissionFile->getData('ithenticateId'),
			$user,
			$locale,
			$this->_plugin->getSubmitterPermission($context, $user),
			(bool)$this->_plugin->getSimilarityConfigSettings($context, 'allowViewerUpdate')
		);

		if (!$viewerUrl) {
			return $request->redirect(
				null,
				'user',
				'authorizationDenied',
				null,
				['message' => 'plugins.generic.plagiarism.action.launchViewer.error']
			);
		}

		return $request->redirectUrl($viewerUrl);
	}

	/**
	 * Schedule the similarity report generate process at iThenticate services's end
	 */
	public function scheduleSimilarityReport(array $args, Request $request)
	{
		$context = $request->getContext();
		$submissionFile = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */

		/** @var IThenticate $ithenticate */
		$ithenticate = $this->_plugin->initIthenticate(
			...$this->_plugin->getServiceAccess($context)
		);

		// If no confirmation of submission file completed the processing at iThenticate service'e end
		// we first need to check it's processing status to see has set to `COMPLETED`
		// see more at https://developers.turnitin.com/turnitin-core-api/best-practice/retry-polling
		if (!$submissionFile->getData('ithenticateSubmissionAcceptedAt')) {
			$submissionInfo = $ithenticate->getSubmissionInfo($submissionFile->getData('ithenticateId'));

			// submission info not available to schedule report generation process
			if (!$submissionInfo) {
				$this->generateUserNotification(
					$request,
					PKPNotification::NOTIFICATION_TYPE_ERROR,
					__('plugins.generic.plagiarism.webhook.similarity.schedule.error', [
						'submissionFileId' => $submissionFile->getId(),
						'error' => __('plugins.generic.plagiarism.submission.status.unavailable'),
					])
				);
				return $this->triggerDataChangedEvent($submissionFile);
			}

			$submissionInfo = json_decode($submissionInfo);

			// submission has not completed yet to schedule report generation process
			if ($submissionInfo->status !== 'COMPLETE') {
				$similaritySchedulingError = '';

				switch($submissionInfo->status) {
					case 'CREATED' :
						$similaritySchedulingError = __('plugins.generic.plagiarism.submission.status.CREATED');
						break;
					case 'PROCESSING' :
						$similaritySchedulingError = __('plugins.generic.plagiarism.submission.status.PROCESSING');
						break;
					case 'ERROR' :
						$similaritySchedulingError = property_exists($submissionInfo, 'error_code')
							? __("plugins.generic.plagiarism.ithenticate.submission.error.{$submissionInfo->error_code}")
							: __('plugins.generic.plagiarism.submission.status.ERROR');
						break;
				}

				$this->generateUserNotification(
					$request,
					PKPNotification::NOTIFICATION_TYPE_ERROR,
					__('plugins.generic.plagiarism.webhook.similarity.schedule.error', [
						'submissionFileId' => $submissionFile->getId(),
						'error' => $similaritySchedulingError,
					])
				);

				return $this->triggerDataChangedEvent($submissionFile);
			}

			$submissionFile->setData('ithenticateSubmissionAcceptedAt', Core::getCurrentDate());
			Repo::submissionFile()->edit($submissionFile, []);
		}

		$scheduleSimilarityReport = $ithenticate->scheduleSimilarityReportGenerationProcess(
			$submissionFile->getData('ithenticateId'),
			$this->_plugin->getSimilarityConfigSettings($context)
		);

		if (!$scheduleSimilarityReport) {
			$message = __('plugins.generic.plagiarism.webhook.similarity.schedule.failure', [
				'submissionFileId' => $submissionFile->getId(),
			]);
			$this->generateUserNotification($request, PKPNotification::NOTIFICATION_TYPE_ERROR, $message);
			return $this->triggerDataChangedEvent($submissionFile);
		}

		$submissionFile->setData('ithenticateSimilarityScheduled', 1);
		Repo::submissionFile()->edit($submissionFile, []);

		$this->generateUserNotification(
			$request,
			PKPNotification::NOTIFICATION_TYPE_SUCCESS, 
			__('plugins.generic.plagiarism.action.scheduleSimilarityReport.success')
		);
		
		return $this->triggerDataChangedEvent($submissionFile);
    }

	/**
	 * Refresh the submission's similarity score result
	 */
	public function refreshSimilarityResult(array $args, Request $request)
	{
		$context = $request->getContext();
		$submissionFile = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */

		/** @var IThenticate $ithenticate */
		$ithenticate = $this->_plugin->initIthenticate(
			...$this->_plugin->getServiceAccess($context)
		);

		$similarityScoreResult = $ithenticate->getSimilarityResult(
			$submissionFile->getData('ithenticateId')
		);

		if (!$similarityScoreResult) {
			$message = __('plugins.generic.plagiarism.action.refreshSimilarityResult.error', [
				'submissionFileId' => $submissionFile->getId(),
			]);
			$this->generateUserNotification($request, PKPNotification::NOTIFICATION_TYPE_ERROR, $message);
			return $this->triggerDataChangedEvent($submissionFile);
		}

		$similarityScoreResult = json_decode($similarityScoreResult);

		if ($similarityScoreResult->status !== 'COMPLETE') {
			$message = __('plugins.generic.plagiarism.action.refreshSimilarityResult.warning', [
				'submissionFileId' => $submissionFile->getId(),
			]);
			$this->generateUserNotification($request, PKPNotification::NOTIFICATION_TYPE_WARNING, $message);
			return $this->triggerDataChangedEvent($submissionFile);
		}

		$submissionFile->setData('ithenticateSimilarityResult', json_encode($similarityScoreResult));
		Repo::submissionFile()->edit($submissionFile, []);

		$this->generateUserNotification(
			$request,
			PKPNotification::NOTIFICATION_TYPE_SUCCESS, 
			__('plugins.generic.plagiarism.action.refreshSimilarityResult.success')
		);

		return $this->triggerDataChangedEvent($submissionFile);
    }

	/**
	 * Upload the submission file and create a new submission at iThenticate service's end
	 */
	public function submitSubmission(array $args, Request $request)
	{
		$context = $request->getContext();
		$user = $request->getUser();

		$submissionFile = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */
		$submission = Repo::submission()->get($submissionFile->getData('submissionId'));

		/** @var IThenticate $ithenticate */
		$ithenticate = $this->_plugin->initIthenticate(
			...$this->_plugin->getServiceAccess($context)
		);

		// If no webhook previously registered for this Context, register it
		if (!$context->getData('ithenticateWebhookId')) {
			if (!$this->_plugin->registerIthenticateWebhook($ithenticate, $context)) {
				error_log("Webhook registration failed for context {$context->getId()} during manual submission");
			}
		}

		// As the submission has been already and should be stamped with an EULA at the
		// confirmation stage, need to set it
		if ($submission->getData('ithenticateEulaVersion')) {
			$ithenticate->setApplicableEulaVersion($submission->getData('ithenticateEulaVersion'));
		}

		if (!$this->_plugin->createNewSubmission($request, $user, $submission, $submissionFile, $ithenticate)) {
			$this->generateUserNotification(
				$request,
				PKPNotification::NOTIFICATION_TYPE_ERROR, 
				__('plugins.generic.plagiarism.action.submitSubmission.error')
			);
			return $this->triggerDataChangedEvent($submissionFile);
		}

		$this->generateUserNotification(
			$request,
			PKPNotification::NOTIFICATION_TYPE_SUCCESS, 
			__('plugins.generic.plagiarism.action.submitSubmission.success')
		);

		return $this->triggerDataChangedEvent($submissionFile);
	}

	/**
	 * Accept the EULA, stamp it to proper entity (Submission/User or both) and upload
	 * submission file
	 */
	public function acceptEulaAndExecuteIntendedAction(array $args, Request $request)
	{
		$context = $request->getContext();
		$user = $request->getUser();

		$submissionFile = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */
		$submission = Repo::submission()->get($submissionFile->getData('submissionId'));

		$confirmSubmissionEula = $args['confirmSubmissionEula'] ?? false;

		if (!$confirmSubmissionEula) {

			$templateManager = $this->getEulaConfirmationTemplate(
				$request,
				$args,
				$context,
				$submission,
				$submissionFile
			);

			SessionManager::getManager()->getUserSession()->setSessionVar('confirmSubmissionEulaError', true);

			return new JSONMessage(
				true,
				$templateManager->fetch($this->_plugin->getTemplateResource('confirmEula.tpl'))
			);
        }

		if (!$submission->getData('ithenticateEulaVersion')) {
			$this->_plugin->stampEulaToSubmission($context, $submission);
		}

		if (!$user->getData('ithenticateEulaVersion')) {
			$this->_plugin->stampEulaToSubmittingUser($context, $submission, $user);
		}

		return $this->submitSubmission($args, $request);
	}

	/**
	 * Show the EULA confirmation modal before the uploading submission file to iThenticate
	 */
	public function confirmEula(array $args, Request $request)
	{
		$context = $request->getContext();

		/** @var SubmissionFile $submissionFile */
		$submissionFile = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION_FILE);
		$submission = Repo::submission()->get($submissionFile->getData('submissionId'));

		$templateManager = $this->getEulaConfirmationTemplate(
			$request,
			$args,
			$context,
			$submission,
			$submissionFile
		);

		return new JSONMessage(
			true,
			$templateManager->fetch($this->_plugin->getTemplateResource('confirmEula.tpl'))
		);
	}

	/**
	 * Get the template manager to handle the EULA confirmation as the before action of 
	 * intended action.
	 */
	protected function getEulaConfirmationTemplate(
		Request $request,
		array $args,
		Context $context,
		Submission $submission,
		SubmissionFile $submissionFile
	): TemplateManager
	{
		$eulaVersionDetails = $submission->getData('ithenticateEulaVersion')
			? [
				'version' 	=> $submission->getData('ithenticateEulaVersion'),
				'url' 		=> $submission->getData('ithenticateEulaUrl')
			] : $this->_plugin->getContextEulaDetails($context, [
				$submission->getData('locale'),
				$request->getSite()->getPrimaryLocale(),
				IThenticate::DEFAULT_EULA_LANGUAGE
			]);
		
		$actionUrl = $request->getDispatcher()->url(
			$request,
			Application::ROUTE_COMPONENT,
			$context->getData('urlPath'),
			'plugins.generic.plagiarism.controllers.PlagiarismIthenticateActionHandler',
			'acceptEulaAndExecuteIntendedAction',
			null,
			[
				'version' => $eulaVersionDetails['version'],
				'submissionFileId' => $submissionFile->getId(),
				'stageId' => $request->getUserVar('stageId'),
			]
		);

		$templateManager = TemplateManager::getManager();
		$templateManager->assign([
			'submissionId' => $submission->getId(),
			'actionUrl' => $actionUrl,
			'eulaAcceptanceMessage' => __('plugins.generic.plagiarism.submission.eula.acceptance.message', [
				'localizedEulaUrl' => $eulaVersionDetails['url'],
			]),
		]);

		return $templateManager;
	}

	/**
	 * Generate the user friendly notification upon a response received for an action
	 */
	protected function generateUserNotification(Request $request, int $notificationType, string $notificationContent): void
	{
		$notificationMgr = new NotificationManager();
		$notificationMgr->createTrivialNotification(
			$request->getUser()->getId(), 
			$notificationType, 
			['contents' => $notificationContent]
		);
	}

	/**
	 * Trigger the data change event to refresh the grid view
	 */
	protected function triggerDataChangedEvent(SubmissionFile $submissionFile): JSONMessage
	{
		if ($this->_plugin::isOPS()) {
			$submission = Repo::submission()->get($submissionFile->getData("submissionId"));
			$publication = $submission->getCurrentPublication();

			$galley = Repo::galley()
				->getCollector()
				->filterByPublicationIds([$publication->getId()])
				->getMany()
				->filter(fn ($gallye) => $gallye->getData("submissionFileId") == $submissionFile->getId())
				->first();
			
			if ($galley) {
				return DAO::getDataChangedEvent($galley->getId());
			}
		}

		return DAO::getDataChangedEvent($submissionFile->getId());
	}

}
