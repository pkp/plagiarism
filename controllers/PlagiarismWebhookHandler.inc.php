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
 * @brief Handle the webhook calls for plagiarism check
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
			error_log("iThenticate webhook not configured for context id {$context->getId()}");
			return;
		}

		if (!$headers->has(['x-turnitin-eventtype', 'x-turnitin-signature'])) {
			error_log('Missing required iThenticate webhook headers');
			return;
		}
		
		if (!in_array($headers->get('x-turnitin-eventtype'), \Ithenticate::DEFAULT_WEBHOOK_EVENTS)) {
			error_log("Invalid iThenticate webhook event type {$headers->get('x-turnitin-eventtype')}");
			return;
		}

		if ($headers->get('x-turnitin-signature') !== hash_hmac("sha256", $payload, $context->getData('ithenticate_webhook_signing_secret'))) {
			error_log('Invalid iThenticate webhook signature');
			return;
		}
		
		switch($headers->get('x-turnitin-eventtype')) {
			case 'SUBMISSION_COMPLETE' :
				$this->handleSubmissionCompletedEvent($context, $payload);
				break;
			default:
				error_log("Handling the iThenticate webhook event {$headers->get('x-turnitin-eventtype')} is not implemented yet");
		}
	}

	/**
	 * Initiate the iThenticate similarity report generation process for given 
	 * iThenticate submission id
	 * 
	 * @param Context 	$context 	The current context for which the webhook request has initiated
	 * @param string 	$payload	The incoming request payload through webhook
	 *
	 * @return void
	 */
	protected function handleSubmissionCompletedEvent($context, $payload) {
		$payload = json_decode($payload);
		if ($payload->status !== 'COMPLETE') {
			error_log("Unable to schedule the similarity report generation process with given ithenticate submission processing status {$payload->status} for iThenticate submission id {$payload->id}");
			return;
		}

		/** @var SubmissionFileDAO $submissionFileDao */
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$ithenticateSubmission = Capsule::table($submissionFileDao->settingsTableName)
			->where('setting_name', 'ithenticate_id')
			->where('setting_value', $payload->id)
			->first();
		
		if (!$ithenticateSubmission) {
			error_log("Invalid iThenticate submission id {$payload->id} given");
			return;
		}

		$submissionFile = $submissionFileDao->getById($ithenticateSubmission->submission_file_id);
		
		if ((int)$submissionFile->getData('ithenticate_similarity_scheduled')) {
			error_log("Similarity report generation process has already been scheduled for iThenticate submission id {$payload->id}");
			return;
		}

		$ithenticate = static::$_plugin->initIthenticate(
			...static::$_plugin->getServiceAccess($context)
		);
		if (!$ithenticate->scheduleSimilarityReportGenerationProcess($payload->id)) {
			error_log("Failed to schedule the similarity report generation process for iThenticate submission id {$payload->id}");
			return;
		}

		$submissionFile->setData('ithenticate_similarity_scheduled', 1);
		$submissionFileDao->updateObject($submissionFile);
	}
}
