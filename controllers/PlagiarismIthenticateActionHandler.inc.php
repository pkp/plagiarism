<?php

/**
 * @file controllers/PlagiarismIthenticateActionHandler.inc.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlagiarismIthenticateActionHandler
 * @ingroup plugins_generic_plagiarism
 *
 * @brief Handle the different iThenticate service related actions
 */

import("plugins.generic.plagiarism.controllers.PlagiarismComponentHandler");
import('lib.pkp.classes.core.JSONMessage'); 
import('lib.pkp.classes.db.DAORegistry');
import('classes.notification.NotificationManager');
import("plugins.generic.plagiarism.IThenticate");

class PlagiarismIthenticateActionHandler extends PlagiarismComponentHandler {

	/**
	 * @copydoc PKPHandler::__construct()
	 */
	public function __construct() {
		parent::__construct();

		$this->addRoleAssignment(
			[
				ROLE_ID_MANAGER,
				ROLE_ID_SUB_EDITOR,
				ROLE_ID_ASSISTANT, 
				ROLE_ID_SITE_ADMIN
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
	public function authorize($request, &$args, $roleAssignments) {
		$this->markRoleAssignmentsChecked();

		import('lib.pkp.classes.security.authorization.SubmissionFileAccessPolicy');
		$this->addPolicy(new SubmissionFileAccessPolicy($request, $args, $roleAssignments, SUBMISSION_FILE_ACCESS_READ, (int) $args['submissionFileId']));
		
		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * Launch the iThenticate similarity report viewer
	 *
	 * @param array $args
	 * @param Request $request
	 */
	public function launchViewer($args, $request) {
		$context = $request->getContext();
		$user = $request->getUser();
		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
		$submission = $submissionDao->getById($submissionFile->getData('submissionId'));
		$siteDao = DAORegistry::getDAO("SiteDAO"); /** @var SiteDAO $siteDao */
		$site = $siteDao->getSite();

		/** @var IThenticate $ithenticate */
		$ithenticate = static::$_plugin->initIthenticate(
			...static::$_plugin->getServiceAccess($context)
		);

		$locale = $ithenticate
			->setApplicableEulaVersion($submission->getData('ithenticateEulaVersion'))
			->getApplicableLocale(
				collect([$submission->getData("locale")])
					->merge($user->getData("locales"))
					->merge([$context->getPrimaryLocale(), $site->getPrimaryLocale()])
					->unique()
					->toArray()
			);

		$viewerUrl = $ithenticate->createViewerLaunchUrl(
			$submissionFile->getData('ithenticateId'),
			$user,
			$locale,
			static::$_plugin->getSubmitterPermission($context, $user),
			(bool)static::$_plugin->getSimilarityConfigSettings($context, 'allowViewerUpdate')
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
	 *
	 * @param array $args
	 * @param Request $request
	 */
	public function scheduleSimilarityReport($args, $request) {

		$context = $request->getContext();

		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /** @var SubmissionFileDAO $submissionFileDao */
		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */

		/** @var IThenticate $ithenticate */
		$ithenticate = static::$_plugin->initIthenticate(
			...static::$_plugin->getServiceAccess($context)
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
					NOTIFICATION_TYPE_ERROR,
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
					NOTIFICATION_TYPE_ERROR,
					__('plugins.generic.plagiarism.webhook.similarity.schedule.error', [
						'submissionFileId' => $submissionFile->getId(),
						'error' => $similaritySchedulingError,
					])
				);

				return $this->triggerDataChangedEvent($submissionFile);
			}

			$submissionFile->setData('ithenticateSubmissionAcceptedAt', Core::getCurrentDate());
			$submissionFileDao->updateObject($submissionFile);
		}

		$scheduleSimilarityReport = $ithenticate->scheduleSimilarityReportGenerationProcess(
			$submissionFile->getData('ithenticateId'),
			static::$_plugin->getSimilarityConfigSettings($context)
		);

		if (!$scheduleSimilarityReport) {
			$message = __('plugins.generic.plagiarism.webhook.similarity.schedule.failure', [
				'submissionFileId' => $submissionFile->getId(),
			]);
			$this->generateUserNotification($request, NOTIFICATION_TYPE_ERROR, $message);
			return $this->triggerDataChangedEvent($submissionFile);
		}

		$submissionFile->setData('ithenticateSimilarityScheduled', 1);
		$submissionFileDao->updateObject($submissionFile);

		$this->generateUserNotification(
			$request,
			NOTIFICATION_TYPE_SUCCESS, 
			__('plugins.generic.plagiarism.action.scheduleSimilarityReport.success')
		);
		
		return $this->triggerDataChangedEvent($submissionFile);
    }

	/**
	 * Refresh the submission's similarity score result
	 *
	 * @param array $args
	 * @param Request $request
	 */
	public function refreshSimilarityResult($args, $request) {
		$context = $request->getContext();

		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /** @var SubmissionFileDAO $submissionFileDao */
		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */

		/** @var IThenticate $ithenticate */
		$ithenticate = static::$_plugin->initIthenticate(
			...static::$_plugin->getServiceAccess($context)
		);

		$similarityScoreResult = $ithenticate->getSimilarityResult(
			$submissionFile->getData('ithenticateId')
		);

		if (!$similarityScoreResult) {
			$message = __('plugins.generic.plagiarism.action.refreshSimilarityResult.error', [
				'submissionFileId' => $submissionFile->getId(),
			]);
			$this->generateUserNotification($request, NOTIFICATION_TYPE_ERROR, $message);
			return $this->triggerDataChangedEvent($submissionFile);
		}

		$similarityScoreResult = json_decode($similarityScoreResult);

		if ($similarityScoreResult->status !== 'COMPLETE') {
			$message = __('plugins.generic.plagiarism.action.refreshSimilarityResult.warning', [
				'submissionFileId' => $submissionFile->getId(),
			]);
			$this->generateUserNotification($request, NOTIFICATION_TYPE_WARNING, $message);
			return $this->triggerDataChangedEvent($submissionFile);
		}

		$submissionFile->setData('ithenticateSimilarityResult', json_encode($similarityScoreResult));
		$submissionFileDao->updateObject($submissionFile);

		$this->generateUserNotification(
			$request,
			NOTIFICATION_TYPE_SUCCESS, 
			__('plugins.generic.plagiarism.action.refreshSimilarityResult.success')
		);

		return $this->triggerDataChangedEvent($submissionFile);
    }

	/**
	 * Upload the submission file and create a new submission at iThenticate service's end
	 *
	 * @param array $args
	 * @param Request $request
	 */
	public function submitSubmission($args, $request) {
		$context = $request->getContext();
		$user = $request->getUser();

		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
		$submission = $submissionDao->getById($submissionFile->getData('submissionId')); /** @var Submission $submission*/

		/** @var IThenticate $ithenticate */
		$ithenticate = static::$_plugin->initIthenticate(
			...static::$_plugin->getServiceAccess($context)
		);

		// If no webhook previously registered for this Context, register it
		if (!$context->getData('ithenticateWebhookId')) {
			static::$_plugin->registerIthenticateWebhook($ithenticate, $context);
		}

		// As the submission has been already and should be stamped with an EULA at the
		// confirmation stage, need to set it
		if ($submission->getData('ithenticateEulaVersion')) {
			$ithenticate->setApplicableEulaVersion($submission->getData('ithenticateEulaVersion'));
		}

		if (!static::$_plugin->createNewSubmission($request, $user, $submission, $submissionFile, $ithenticate)) {
			$this->generateUserNotification(
				$request,
				NOTIFICATION_TYPE_ERROR, 
				__('plugins.generic.plagiarism.action.submitSubmission.error')
			);
			return $this->triggerDataChangedEvent($submissionFile);
		}

		$this->generateUserNotification(
			$request,
			NOTIFICATION_TYPE_SUCCESS, 
			__('plugins.generic.plagiarism.action.submitSubmission.success')
		);

		return $this->triggerDataChangedEvent($submissionFile);
	}

	/**
	 * Accept the EULA, stamp it to proper entity (Submission/User or both) and upload
	 * submission file
	 *
	 * @param array $args
	 * @param Request $request
	 */
	public function acceptEulaAndExecuteIntendedAction($args, $request) {
		$context = $request->getContext();
		$user = $request->getUser();

		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
		$submission = $submissionDao->getById($submissionFile->getData('submissionId'));

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
				$templateManager->fetch(static::$_plugin->getTemplateResource('confirmEula.tpl'))
			);
        }

		if (!$submission->getData('ithenticateEulaVersion')) {
			static::$_plugin->stampEulaToSubmission($context, $submission);
		}

		if (!$user->getData('ithenticateEulaVersion')) {
			static::$_plugin->stampEulaToSubmittingUser($context, $submission, $user);
		}

		return $this->submitSubmission($args, $request);
	}

	/**
	 * Show the EULA confirmation modal before the uploading submission file to iThenticate
	 *
	 * @param array $args
	 * @param Request $request
	 */
	public function confirmEula($args, $request) {
		$context = $request->getContext();

		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
		$submission = $submissionDao->getById($submissionFile->getData('submissionId'));

		$templateManager = $this->getEulaConfirmationTemplate(
			$request,
			$args,
			$context,
			$submission,
			$submissionFile
		);

		return new JSONMessage(
			true,
			$templateManager->fetch(static::$_plugin->getTemplateResource('confirmEula.tpl'))
		);
	}

	/**
	 * Get the template manager to handle the EULA confirmation as the before action of 
	 * intended action.
	 *
	 * @param Request 			$request
	 * @param array 			$args
	 * @param Context 			$context
	 * @param Submission 		$submission
	 * @param SubmissionFile 	$submissionFile
	 * 
	 * @return TemplateManager
	 */
	protected function getEulaConfirmationTemplate($request, $args, $context, $submission, $submissionFile) {

		$eulaVersionDetails = $submission->getData('ithenticateEulaVersion')
			? [
				'version' 	=> $submission->getData('ithenticateEulaVersion'),
				'url' 		=> $submission->getData('ithenticateEulaUrl')
			] : static::$_plugin->getContextEulaDetails($context, [
				$submission->getData('locale'),
				$request->getSite()->getPrimaryLocale(),
				IThenticate::DEFAULT_EULA_LANGUAGE
			]);
		
		$actionUrl = $request->getDispatcher()->url(
			$request,
			ROUTE_COMPONENT,
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
	 *
	 * @param Request 	$request
	 * @param int 		$notificationType
	 * @param string 	$notificationContent
	 * 
	 * @return void
	 */
	protected function generateUserNotification($request, $notificationType, $notificationContent) {
		$notificationMgr = new NotificationManager();
		$notificationMgr->createTrivialNotification(
			$request->getUser()->getId(), 
			$notificationType, 
			['contents' => $notificationContent]
		);
	}

	/**
	 * Trigger the data change event to refresh the grid view
	 *
	 * @param SubmissionFile $submissionFile
	 * @return JSONMessage
	 */
	protected function triggerDataChangedEvent($submissionFile) {
		if (static::$_plugin::isOPS()) {
			$articleGalleyDao = DAORegistry::getDAO('ArticleGalleyDAO'); /** @var ArticleGalleyDAO $articleGalleyDao */
			$daoResultFactory = $articleGalleyDao->getByFileId($submissionFile->getId()); /** @var DAOResultFactory $daoResultFactory */
			$articleGalley = $daoResultFactory->next(); /** @var ArticleGalley $articleGalley */
			
			if ($articleGalley) {
				return DAO::getDataChangedEvent($articleGalley->getId());
			}
		}

		return DAO::getDataChangedEvent($submissionFile->getId());
	}

}
