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
import("plugins.generic.plagiarism.IThenticate");

class PlagiarismIthenticateActionHandler extends PlagiarismComponentHandler {

	/**
	 * Launch the iThenticate similarity report viewer
	 *
	 * @param array $args
	 * @param Request $request
	 * 
	 * @return void
	 */
	public function launchViewer($args, $request) {
		$context = $request->getContext();
        $user = $request->getUser();

		/** @var SubmissionFileDAO $submissionFileDao */
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$submissionFile = $submissionFileDao->getById($args['submissionFileId']);

		/** @var \IThenticate $ithenticate */
        $ithenticate = static::$_plugin->initIthenticate(
            ...static::$_plugin->getServiceAccess($context)
        );

		$viewerUrl = $ithenticate->createViewerLaunchUrl(
			$submissionFile->getData('ithenticate_id'),
			$user,
			'en-US' // Need to update it based on user/submission appropriate locale
		);

		return $request->redirectUrl($viewerUrl);
	}

    /**
	 * Upload the submission file and create a new submission at iThenticate service's end
	 *
	 * @param array $args
	 * @param Request $request
	 * 
	 * @return void
	 */
	public function submitSubmission($args, $request) {
		$context = $request->getContext();
		$user = $request->getUser();

		/** @var SubmissionFileDAO $submissionFileDao */
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$submissionFile = $submissionFileDao->getById($args['submissionFileId']); /** @var SubmissionFile $submissionFile */
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
		$submission = $submissionDao->getById($submissionFile->getData('submissionId')); /** @var Submission $submission*/
		$publication = $submission->getCurrentPublication();
		$author = $publication->getPrimaryAuthor();

		/** @var \IThenticate $ithenticate */
        $ithenticate = static::$_plugin->initIthenticate(
            ...static::$_plugin->getServiceAccess($context)
        );

		$submissionUuid = $ithenticate->createSubmission(
			$request->getSite(),
			$submission,
			$user,
			$author,
			static::$_plugin::SUBMISSION_AUTOR_ITHENTICATE_DEFAULT_PERMISSION,
			static::$_plugin->getSubmitterPermission($context, $user)
		);

		if (!$submissionUuid) {
			static::$_plugin->sendErrorMessage("Could not create the submission at iThenticate for file id {$submissionFile->getId()}", $submission->getId());
			return new JSONMessage(false);
		}

		$file = Services::get('file')->get($submissionFile->getData('fileId'));
		$uploadStatus = $ithenticate->uploadFile(
			$submissionUuid, 
			$submissionFile->getData("name", $publication->getData("locale")),
			Services::get('file')->fs->read($file->path),
		);

		// Upload submission files for successfully created submission at iThenticate's end
		if (!$uploadStatus) {
			static::$_plugin->sendErrorMessage('Could not complete the file upload at iThenticate for file id ' . $submissionFile->getData("name", $publication->getData("locale")), $submission->getId());
			return new JSONMessage(false);
		}

		$submissionFile->setData('ithenticate_id', $submissionUuid);
		$submissionFile->setData('ithenticate_similarity_scheduled', 0);
		$submissionFileDao->updateObject($submissionFile);

		return DAO::getDataChangedEvent($submissionFile->getId());
	}

	/**
	 * Schedule the similarity report generate process at iThenticate services's end
	 *
	 * @param array $args
	 * @param Request $request
	 * 
	 * @return void
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
	 * 
	 * @return void
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

}
