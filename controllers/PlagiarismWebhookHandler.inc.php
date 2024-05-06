<?php

/**
 * @file plugins/generic/plagiarism/controllers/grid/PlagiarismWebhookHandler.inc.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlagiarismWebhookHandler
 * @ingroup plugins_generic_plagiarism
 *
 * @brief Handle the incoming webhook events for plagiarism check
 */

use Illuminate\Database\Capsule\Manager as Capsule;

import('lib.pkp.classes.handler.PKPHandler');
import("plugins.generic.plagiarism.IThenticate");

class PlagiarismWebhookHandler extends PKPHandler {

	/** 
	 * The Plagiarism Plugin itself
	 * 
	 * @var PlagiarismPlugin 
	 */
	protected static $_plugin;

	/**
	 * Get the plugin
	 * 
	 * @return PlagiarismPlugin
	 */
	public static function getPlugin() {
		return static::$_plugin;
	}

	/**
	 * Set the Plugin
	 * 
	 * @param PlagiarismPlugin $plugin
	 */
	public static function setPlugin($plugin) {
		static::$_plugin = $plugin;
	}

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

		if (!$context->getData('ithenticate_webhook_id') || !$context->getData('ithenticate_webhook_signing_secret')) {
			static::$_plugin->sendErrorMessage("iThenticate webhook not configured for context id {$context->getId()}");
			return;
		}

		if (!$headers->has(['x-turnitin-eventtype', 'x-turnitin-signature'])) {
			static::$_plugin->sendErrorMessage('Missing required iThenticate webhook headers');
			return;
		}
		
		if (!in_array($headers->get('x-turnitin-eventtype'), \Ithenticate::DEFAULT_WEBHOOK_EVENTS)) {
			static::$_plugin->sendErrorMessage("Invalid iThenticate webhook event type {$headers->get('x-turnitin-eventtype')}");
			return;
		}

		if ($headers->get('x-turnitin-signature') !== hash_hmac("sha256", $payload, $context->getData('ithenticate_webhook_signing_secret'))) {
			static::$_plugin->sendErrorMessage('Invalid iThenticate webhook signature');
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
			static::$_plugin->sendErrorMessage("Invalid iThenticate submission id {$payload->id} given for webhook event {$event}");
			return;
		}

		/** @var SubmissionFileDAO $submissionFileDao */
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$submissionFile = $submissionFileDao->getById($ithenticateSubmission->submission_file_id);

		if ($payload->status !== 'COMPLETE') {
			// If the status not `COMPLETE`, then it's `ERROR`
			static::$_plugin->sendErrorMessage(
				"Unable to schedule the similarity report generation for file id {$submissionFile->getId()} with error : " . __("plugins.generic.plagiarism.ithenticate.submission.error.{$payload->error_code}"),
				$submissionFile->getData('submissionId')
			);
			return;
		}
		
		if ((int)$submissionFile->getData('ithenticate_similarity_scheduled')) {
			static::$_plugin->sendErrorMessage("Similarity report generation process has already been scheduled for iThenticate submission id {$payload->id}", $submissionFile->getData('submissionId'));
			return;
		}

		$ithenticate = static::$_plugin->initIthenticate(
			...static::$_plugin->getServiceAccess($context)
		);
		if (!$ithenticate->scheduleSimilarityReportGenerationProcess($payload->id)) {
			static::$_plugin->sendErrorMessage("Failed to schedule the similarity report generation process for iThenticate submission id {$payload->id}", $submissionFile->getData('submissionId'));
			return;
		}

		$submissionFile->setData('ithenticate_similarity_scheduled', 1);
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
			static::$_plugin->sendErrorMessage("Invalid iThenticate submission id {$payload->submission_id} given for webhook event {$event}");
			return;
		}

		/** @var SubmissionFileDAO $submissionFileDao */
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$submissionFile = $submissionFileDao->getById($ithenticateSubmission->submission_file_id);
		$submissionFile->setData('ithenticate_similarity_result', json_encode($payload));
		$submissionFileDao->updateObject($submissionFile);
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
			->where('setting_name', 'ithenticate_id')
			->where('setting_value', $id)
			->first();
	}
}
