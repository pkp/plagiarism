<?php

/**
 * @file plugins/generic/plagiarism/controllers/PlagiarismIthenticateActionHandler.inc.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
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

		/** @var \IThenticate $ithenticate */
        $ithenticate = static::$_plugin->initIthenticate(
            ...static::$_plugin->getServiceAccess($context)
        );

		$locale = $ithenticate
			->setApplicableEulaVersion($submission->getData('ithenticate_eula_version'))
			->getApplicableLocale(
				collect([$submission->getData("locale")])
					->merge($user->getData("locales"))
					->merge([$context->getPrimaryLocale(), $site->getPrimaryLocale()])
					->unique()
					->toArray()
			);

		$viewerUrl = $ithenticate->createViewerLaunchUrl(
			$submissionFile->getData('ithenticate_id'),
			$user,
			$locale,
			static::$_plugin->getSubmitterPermission($context, $user),
			(bool)static::$_plugin->getSimilarityConfigSettings($context, 'allowViewerUpdate')
		);

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

		/** @var \IThenticate $ithenticate */
        $ithenticate = static::$_plugin->initIthenticate(
            ...static::$_plugin->getServiceAccess($context)
        );

		$scheduleSimilarityReport = $ithenticate->scheduleSimilarityReportGenerationProcess(
			$submissionFile->getData('ithenticate_id'),
			static::$_plugin->getSimilarityConfigSettings($context)
		);

		if (!$scheduleSimilarityReport) {
			static::$_plugin->sendErrorMessage("Failed to schedule the similarity report generation process for iThenticate submission id " . $submissionFile->getData('ithenticate_id'), $submissionFile->getData('submissionId'));
			return new JSONMessage(false);
		}

		$submissionFile->setData('ithenticate_similarity_scheduled', 1);
		$submissionFileDao->updateObject($submissionFile);

		return DAO::getDataChangedEvent($submissionFile->getId());
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

		/** @var \IThenticate $ithenticate */
        $ithenticate = static::$_plugin->initIthenticate(
            ...static::$_plugin->getServiceAccess($context)
        );

		$similarityScoreResult = $ithenticate->getSimilarityResult(
			$submissionFile->getData('ithenticate_id')
		);

		if (!$similarityScoreResult) {
			static::$_plugin->sendErrorMessage("Unable to retrieve similarity result for submission file id " . $submissionFile->getData('ithenticate_id'), $submissionFile->getData('submissionId'));
			return new JSONMessage(false);
		}

		$similarityScoreResult = json_decode($similarityScoreResult);

		if ($similarityScoreResult->status !== 'COMPLETE') {
			static::$_plugin->sendErrorMessage("Similarity result info not yet complete for submission file id " . $submissionFile->getData('ithenticate_id'), $submissionFile->getData('submissionId'));
			return new JSONMessage(false);
		}

		$submissionFile->setData('ithenticate_similarity_result', json_encode($similarityScoreResult));
		$submissionFileDao->updateObject($submissionFile);

		return DAO::getDataChangedEvent($submissionFile->getId());
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

		/** @var \IThenticate $ithenticate */
        $ithenticate = static::$_plugin->initIthenticate(
            ...static::$_plugin->getServiceAccess($context)
        );

		if (!static::$_plugin->createNewSubmission($request, $user, $submission, $submissionFile, $ithenticate)) {
			return new JSONMessage(false);
		}

		return DAO::getDataChangedEvent($submissionFile->getId());
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

		if (!$submission->getData('ithenticate_eula_version')) {
            static::$_plugin->stampEulaToSubmission('', [$submission]);
        }

        if (!$user->getData('ithenticateEulaVersion')) {
            static::$_plugin->stampEulaToSubmittingUser('', [$submission]);
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

		$eulaVersionDetails = $submission->getData('ithenticate_eula_version')
			? [
				'version' 	=> $submission->getData('ithenticate_eula_version'),
				'url' 		=> $submission->getData('ithenticate_eula_url')
			] : static::$_plugin->getContextEulaDetails($context, [
				$submission->getData('locale'),
				$request->getSite()->getPrimaryLocale(),
				\IThenticate::DEFAULT_EULA_LANGUAGE
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

}
