<?php

/**
 * @file controllers/PlagiarismWebhookHandler.inc.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlagiarismWebhookHandler
 * @ingroup plugins_generic_plagiarism
 *
 * @brief Handle the incoming webhook events for plagiarism check
 */

use Illuminate\Database\Capsule\Manager as Capsule;

import("plugins.generic.plagiarism.controllers.PlagiarismComponentHandler");
import("plugins.generic.plagiarism.IThenticate");

class PlagiarismWebhookHandler extends PlagiarismComponentHandler {

	/**
	 * Authorize this request.
	 *
	 * @return bool
	 */
	public function authorize($request, &$args, $roleAssignments) {
		return true;
	}

	/**
	 * Handle the incoming webhook request from iThenticate service
	 *
	 * @return void
	 */
	public function handle() {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$headers = collect(array_change_key_case(getallheaders(), CASE_LOWER));
		$payload = file_get_contents('php://input');

		if (!$context->getData('ithenticateWebhookId') || !$context->getData('ithenticateWebhookSigningSecret')) {
			static::$_plugin->sendErrorMessage(__('plugins.generic.plagiarism.webhook.configuration.missing', [
				'contextId' => $context->getId(),
			]));
			return;
		}

		if (!$headers->has(['x-turnitin-eventtype', 'x-turnitin-signature'])) {
			static::$_plugin->sendErrorMessage(__('plugins.generic.plagiarism.webhook.headers.missing'));
			return;
		}
		
		if (!in_array($headers->get('x-turnitin-eventtype'), IThenticate::DEFAULT_WEBHOOK_EVENTS)) {
			static::$_plugin->sendErrorMessage(__('plugins.generic.plagiarism.webhook.event.invalid', [
				'event' => $headers->get('x-turnitin-eventtype'),
			]));
			return;
		}

		if ($headers->get('x-turnitin-signature') !== hash_hmac("sha256", $payload, $context->getData('ithenticateWebhookSigningSecret'))) {
			static::$_plugin->sendErrorMessage(__('plugins.generic.plagiarism.webhook.signature.invalid'));
			return;
		}
		
		switch($headers->get('x-turnitin-eventtype')) {
			case 'SUBMISSION_COMPLETE' :
				$this->handleSubmissionCompleteEvent($context, $payload, $headers->get('x-turnitin-eventtype'));
				break;
			case 'SIMILARITY_COMPLETE' :
			case 'SIMILARITY_UPDATED' :
				$this->storeSimilarityScore($context, $payload, $headers->get('x-turnitin-eventtype'));
				break;
			default:
				error_log("Handling the iThenticate webhook event {$headers->get('x-turnitin-eventtype')} is not implemented yet");
		}
	}

	/**
	 * Initiate the iThenticate similarity report generation process for given 
	 * iThenticate submission id at receiving webhook event `SUBMISSION_COMPLETE`
	 * 
	 * @param Context 	$context 	The current context for which the webhook request has initiated
	 * @param string 	$payload	The incoming request payload through webhook
	 * @param string 	$event		The incoming webhook request event
	 *
	 * @return void
	 */
	protected function handleSubmissionCompleteEvent($context, $payload, $event) {
		$payload = json_decode($payload);

		$ithenticateSubmission = $this->getIthenticateSubmission($payload->id);

		if (!$ithenticateSubmission) {
			static::$_plugin->sendErrorMessage(__('plugins.generic.plagiarism.webhook.submissionId.invalid', [
				'submissionUuid' => $payload->id,
				'event' => $event,
			]));
			return;
		}

		/** @var SubmissionFileDAO $submissionFileDao */
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$submissionFile = $submissionFileDao->getById($ithenticateSubmission->submission_file_id);

		if (!$this->verifySubmissionFileAssociationWithContext($context, $submissionFile)) {
			static::$_plugin->sendErrorMessage(__('plugins.generic.plagiarism.webhook.submissionFileAssociationWithContext.invalid', [
				'submissionFileId' => $submissionFile->getId(),
				'contextId' => $context->getId(),
			]));
			return;
		}

		if ($payload->status !== 'COMPLETE') {
			// If the status not `COMPLETE`, then it's `ERROR`
			static::$_plugin->sendErrorMessage(
				__('plugins.generic.plagiarism.webhook.similarity.schedule.error', [
					'submissionFileId' => $submissionFile->getId(),
					'error' => __("plugins.generic.plagiarism.ithenticate.submission.error.{$payload->error_code}"),
				]),
				$submissionFile->getData('submissionId')
			);
			return;
		}
		
		if ((int)$submissionFile->getData('ithenticateSimilarityScheduled')) {
			static::$_plugin->sendErrorMessage(
				__('plugins.generic.plagiarism.webhook.similarity.schedule.previously', [
					'submissionFileId' => $submissionFile->getId(),
				]),
				$submissionFile->getData('submissionId')
			);
			return;
		}
		
		list($apiUrl, $apiKey) = static::$_plugin->getServiceAccess($context);
		$ithenticate = static::$_plugin->initIthenticate($apiUrl, $apiKey);

		$scheduleSimilarityReport = $ithenticate->scheduleSimilarityReportGenerationProcess(
			$payload->id,
			static::$_plugin->getSimilarityConfigSettings($context)
		);

		if (!$scheduleSimilarityReport) {
			static::$_plugin->sendErrorMessage(
				__('plugins.generic.plagiarism.webhook.similarity.schedule.failure', [
					'submissionFileId' => $submissionFile->getId(),
				]),
				$submissionFile->getData('submissionId')
			);
			return;
		}

		$submissionFile->setData('ithenticateSimilarityScheduled', 1);
		$submissionFileDao->updateObject($submissionFile);
	}

	/**
	 * Store or Update the result of similarity check for a submission file at receiving
	 * the webook event `SIMILARITY_COMPLETE` or `SIMILARITY_UPDATED`
	 * 
	 * @param Context 	$context 	The current context for which the webhook request has initiated
	 * @param string 	$payload	The incoming request payload through webhook
	 * @param string 	$event		The incoming webhook request event
	 *
	 * @return void
	 */
	protected function storeSimilarityScore($context, $payload, $event) {
		$payload = json_decode($payload);

		// we will not store similarity check result unless it has completed
		if ($payload->status !== 'COMPLETE') {
			return;
		}

		$ithenticateSubmission = $this->getIthenticateSubmission($payload->submission_id);

		if (!$ithenticateSubmission) {
			static::$_plugin->sendErrorMessage(__('plugins.generic.plagiarism.webhook.submissionId.invalid', [
				'submissionUuid' => $payload->submission_id,
				'event' => $event,
			]));
			return;
		}

		/** @var SubmissionFileDAO $submissionFileDao */
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$submissionFile = $submissionFileDao->getById($ithenticateSubmission->submission_file_id);

		if (!$this->verifySubmissionFileAssociationWithContext($context, $submissionFile)) {
			static::$_plugin->sendErrorMessage(__('plugins.generic.plagiarism.webhook.submissionFileAssociationWithContext.invalid', [
				'submissionFileId' => $submissionFile->getId(),
				'contextId' => $context->getId(),
			]));
			return;
		}

		$submissionFile->setData('ithenticateSimilarityResult', json_encode($payload));
		$submissionFileDao->updateObject($submissionFile);
	}

	/**
	 * Verify if the given submission file is associated with current running/set context
	 * 
	 * @param Context $context
	 * @param SubmissionFile $submissionFile
	 * 
	 * @return bool
	 */
	protected function verifySubmissionFileAssociationWithContext($context, $submissionFile) {
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
		$submission = $submissionDao->getById($submissionFile->getData('submissionId'));

		return (int) $submission->getData('contextId') === (int) $context->getId();
	}

	/**
	 * Get the row data as object from submission file settings table or null if none found
	 * 
	 * @param string 	$id 	The given iThenticate submission id in UUID format
	 * @return object|null
	 */
	private function getIthenticateSubmission($id) {
		
		/** @var SubmissionFileDAO $submissionFileDao */
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');

		return Capsule::table($submissionFileDao->settingsTableName)
			->where('setting_name', 'ithenticateId')
			->where('setting_value', $id)
			->first();
	}
}
