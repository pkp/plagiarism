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
	 * Launch the iThenticate similarity report viewer
	 *
	 * @param array $args
	 * @param Request $request
	 */
	public function launchViewer($args, $request) {
		$context = $request->getContext();
		$user = $request->getUser();

		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /** @var SubmissionFileDAO $submissionFileDao */
		$submissionFile = $submissionFileDao->getById($args['submissionFileId']);
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

		/** @var SubmissionFileDAO $submissionFileDao */
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$submissionFile = $submissionFileDao->getById($args['submissionFileId']);

		/** @var IThenticate $ithenticate */
		$ithenticate = static::$_plugin->initIthenticate(
			...static::$_plugin->getServiceAccess($context)
		);

		$scheduleSimilarityReport = $ithenticate->scheduleSimilarityReportGenerationProcess(
			$submissionFile->getData('ithenticateId'),
			static::$_plugin->getSimilarityConfigSettings($context)
		);

		if (!$scheduleSimilarityReport) {
			static::$_plugin->sendErrorMessage("Failed to schedule the similarity report generation process for iThenticate submission id " . $submissionFile->getData('ithenticateId'), $submissionFile->getData('submissionId'));
			$this->generateUserNotification(
				$request,
				NOTIFICATION_TYPE_ERROR, 
				__('plugins.generic.plagiarism.action.scheduleSimilarityReport.error')
			);
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

		/** @var SubmissionFileDAO $submissionFileDao */
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$submissionFile = $submissionFileDao->getById($args['submissionFileId']);

		/** @var IThenticate $ithenticate */
		$ithenticate = static::$_plugin->initIthenticate(
			...static::$_plugin->getServiceAccess($context)
		);

		$similarityScoreResult = $ithenticate->getSimilarityResult(
			$submissionFile->getData('ithenticateId')
		);

		if (!$similarityScoreResult) {
			static::$_plugin->sendErrorMessage("Unable to retrieve similarity result for submission file id " . $submissionFile->getData('ithenticateId'), $submissionFile->getData('submissionId'));
			$this->generateUserNotification(
				$request,
				NOTIFICATION_TYPE_ERROR, 
				__('plugins.generic.plagiarism.action.refreshSimilarityResult.error')
			);
			return $this->triggerDataChangedEvent($submissionFile);
		}

		$similarityScoreResult = json_decode($similarityScoreResult);

		if ($similarityScoreResult->status !== 'COMPLETE') {
			static::$_plugin->sendErrorMessage("Similarity result info not yet complete for submission file id " . $submissionFile->getData('ithenticateId'), $submissionFile->getData('submissionId'));
			$this->generateUserNotification(
				$request,
				NOTIFICATION_TYPE_WARNING, 
				__('plugins.generic.plagiarism.action.refreshSimilarityResult.warning')
			);
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

		/** @var SubmissionFileDAO $submissionFileDao */
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$submissionFile = $submissionFileDao->getById($args['submissionFileId']); /** @var SubmissionFile $submissionFile */
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
	 * Accept the EULA, stamp it to proper entity (Submission/User or both) and may run
	 * one of following intended action
	 * 	- Upload submission file
	 * 	- Schedule similarity report generation process
	 *  - Refresh the similarity report scores
	 *
	 * @param array $args
	 * @param Request $request
	 */
	public function acceptEulaAndExecuteIntendedAction($args, $request) {
		$context = $request->getContext();
		$user = $request->getUser();

		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /** @var SubmissionFileDAO $submissionFileDao */
		$submissionFile = $submissionFileDao->getById($args['submissionFileId']);
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

		$onAcceptAction = $args['onAcceptAction'];

		return $this->{$onAcceptAction}($args, $request);
	}

	/**
	 * Show the EULA confirmation modal before the intended action
	 *
	 * @param array $args
	 * @param Request $request
	 */
	public function confirmEula($args, $request) {
		$context = $request->getContext();

		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /** @var SubmissionFileDAO $submissionFileDao */
		$submissionFile = $submissionFileDao->getById($args['submissionFileId']);
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
				'onAcceptAction' => $args['onAcceptAction'],
			]
		);

		$templateManager = TemplateManager::getManager();
		$templateManager->assign([
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
