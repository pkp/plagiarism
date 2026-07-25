<?php

/**
 * @file plugins/generic/plagiarism/controllers/PlagiarismWebhookHandler.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlagiarismWebhookHandler
 *
 * @brief Handle the incoming webhook events for plagiarism check
 */

namespace APP\plugins\generic\plagiarism\controllers;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\plagiarism\IThenticate;
use APP\plugins\generic\plagiarism\classes\PlagiarismErrorFormatter;
use APP\plugins\generic\plagiarism\controllers\PlagiarismComponentHandler;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\context\Context;
use PKP\submissionFile\SubmissionFile;


class PlagiarismWebhookHandler extends PlagiarismComponentHandler
{
	/**
	 * Authorize this request.
	 *
	 * @return bool
	 */
	public function authorize($request, &$args, $roleAssignments)
	{
		return true;
	}

	/**
	 * Handle the incoming webhook request from iThenticate service
	 *
	 * @return void
	 */
	public function handle()
	{
		$request = Application::get()->getRequest();
		$context = $request->getContext();

		if (!$context) {
			error_log('iThenticate webhook received with no resolvable context; ignoring.');
			return;
		}

		$headers = collect(array_change_key_case(getallheaders(), CASE_LOWER));

		$payload = file_get_contents('php://input');
		if ($payload === false) {
			$payload = '';
		}

		if (!$context->getData('ithenticateWebhookId') || !$context->getData('ithenticateWebhookSigningSecret')) {
			error_log("iThenticate webhook not configured for context {$context->getId()}; ignoring.");
			return;
		}

		if (!$headers->has(['x-turnitin-eventtype', 'x-turnitin-signature'])) {
			error_log("iThenticate webhook (context {$context->getId()}): missing required headers; ignoring.");
			return;
		}

		if (!in_array($headers->get('x-turnitin-eventtype'), IThenticate::DEFAULT_WEBHOOK_EVENTS)) {
			error_log("iThenticate webhook (context {$context->getId()}): invalid event type " . $headers->get('x-turnitin-eventtype') . "; ignoring.");
			return;
		}

		if ($headers->get('x-turnitin-signature') !== hash_hmac("sha256", $payload, $context->getData('ithenticateWebhookSigningSecret'))) {
			error_log("iThenticate webhook (context {$context->getId()}): signature verification failed; ignoring.");
			return;
		}

		match ($headers->get('x-turnitin-eventtype')) {
			'SUBMISSION_COMPLETE'
				=> $this->handleSubmissionCompleteEvent($context, $payload, $headers->get('x-turnitin-eventtype')),
			'SIMILARITY_COMPLETE', 'SIMILARITY_UPDATED'
				=> $this->storeSimilarityScore($context, $payload, $headers->get('x-turnitin-eventtype')),
			default
				=> error_log("Handling the iThenticate webhook event {$headers->get('x-turnitin-eventtype')} is not implemented yet"),
		};
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
	protected function handleSubmissionCompleteEvent(Context $context, string $payload, string $event): void
	{
		$payload = json_decode($payload);
		if (!is_object($payload) || !isset($payload->id)) {
			error_log("iThenticate webhook ({$event}): invalid or incomplete JSON payload; ignoring.");
			return;
		}

		$ithenticateSubmission = $this->getIthenticateSubmission($payload->id);

		if (!$ithenticateSubmission) {
			error_log("iThenticate webhook ({$event}): no submission file found for iThenticate submission id {$payload->id}; ignoring.");
			return;
		}

		$submissionFile = Repo::submissionFile()->get($ithenticateSubmission->submission_file_id);
		if (!$submissionFile) {
			error_log("iThenticate webhook ({$event}): submission file {$ithenticateSubmission->submission_file_id} not found; ignoring.");
			return;
		}

		if (!$this->verifySubmissionFileAssociationWithContext($context, $submissionFile)) {
			error_log("iThenticate webhook ({$event}): submission file " . $submissionFile->getId() . " is not associated with context {$context->getId()}; ignoring.");
			return;
		}

		if (($payload->status ?? null) !== 'COMPLETE') {
			// If the status is not `COMPLETE`, then it's `ERROR`. Fall back to a generic reason when the
			// payload carries no error_code, so the message never renders a missing-translation sentinel.
			$errorKey = isset($payload->error_code)
				? "plugins.generic.plagiarism.ithenticate.submission.error.{$payload->error_code}"
				: 'plugins.generic.plagiarism.submission.status.ERROR';
			$this->_plugin->recordSubmissionFileError(
				$submissionFile,
				PlagiarismErrorFormatter::make('plugins.generic.plagiarism.webhook.similarity.schedule.error', [
					'submissionFileId' => $submissionFile->getId(),
					'error' => PlagiarismErrorFormatter::make($errorKey),
				])
			);
			return;
		}

		$submissionFile->setData('ithenticateSubmissionAcceptedAt', Core::getCurrentDate());
		$submissionFile->setData('ithenticateProcessingError', null);
		Repo::submissionFile()->edit($submissionFile, []);
		
		$submissionFile = Repo::submissionFile()->get($submissionFile->getId());
		
		if ((int)$submissionFile->getData('ithenticateSimilarityScheduled')) {
			$this->_plugin->recordSubmissionFileError(
				$submissionFile,
				PlagiarismErrorFormatter::make('plugins.generic.plagiarism.webhook.similarity.schedule.previously', [
					'submissionFileId' => $submissionFile->getId(),
				])
			);
			return;
		}
		
		list($apiUrl, $apiKey) = $this->_plugin->getServiceAccess($context);
		$ithenticate = $this->_plugin->initIthenticate($apiUrl, $apiKey);

		$scheduleSimilarityReport = $ithenticate->scheduleSimilarityReportGenerationProcess(
			$payload->id,
			$this->_plugin->getSimilarityConfigSettings($context)
		);

		if (!$scheduleSimilarityReport) {
			$this->_plugin->recordSubmissionFileError(
				$submissionFile,
				PlagiarismErrorFormatter::make(
					'plugins.generic.plagiarism.webhook.similarity.schedule.failure',
					['submissionFileId' => $submissionFile->getId()],
					$ithenticate->getLastErrorSummary()
				)
			);
			return;
		}

		$submissionFile->setData('ithenticateSimilarityScheduled', 1);
		Repo::submissionFile()->edit($submissionFile, []);
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
	protected function storeSimilarityScore(Context $context, string $payload, string $event): void
	{
		$payload = json_decode($payload);
		if (!is_object($payload)) {
			error_log("iThenticate webhook ({$event}): invalid JSON payload; ignoring.");
			return;
		}

		// Similarity reports have only PROCESSING/COMPLETE (no ERROR state), and the webhook fires only
		// on the transition to COMPLETE. A non-COMPLETE payload is a not-ready/unexpected event we
		// ignore; real failures surface on the submission (handleSubmissionCompleteEvent) or the
		// schedule call (schedule.failure), not here.
		if (($payload->status ?? null) !== 'COMPLETE') {
			return;
		}

		if (!isset($payload->submission_id)) {
			error_log("iThenticate webhook ({$event}): payload missing submission_id; ignoring.");
			return;
		}

		$ithenticateSubmission = $this->getIthenticateSubmission($payload->submission_id);

		if (!$ithenticateSubmission) {
			error_log("iThenticate webhook ({$event}): no submission file found for iThenticate submission id {$payload->submission_id}; ignoring.");
			return;
		}

		$submissionFile = Repo::submissionFile()->get($ithenticateSubmission->submission_file_id);
		if (!$submissionFile) {
			error_log("iThenticate webhook ({$event}): submission file {$ithenticateSubmission->submission_file_id} not found; ignoring.");
			return;
		}

		if (!$this->verifySubmissionFileAssociationWithContext($context, $submissionFile)) {
			error_log("iThenticate webhook ({$event}): submission file " . $submissionFile->getId() . " is not associated with context {$context->getId()}; ignoring.");
			return;
		}

		$submissionFile->setData('ithenticateSimilarityResult', json_encode($payload));
		$submissionFile->setData('ithenticateProcessingError', null);
		Repo::submissionFile()->edit($submissionFile, []);
	}

	/**
	 * Verify if the given submission file is associated with current running/set context
	 */
	protected function verifySubmissionFileAssociationWithContext(Context $context, SubmissionFile $submissionFile): bool
	{
		$submission = Repo::submission()->get($submissionFile->getData('submissionId'));

		return (int) $submission->getData('contextId') === (int) $context->getId();
	}

	/**
	 * Get the row data as object from submission file settings table or null if none found
	 * 
	 * @param string 	$id 	The given iThenticate submission id in UUID format
	 * @return object|null
	 */
	private function getIthenticateSubmission(string $id): ?object
	{
		return DB::table(Repo::submissionFile()->getCollector()->dao->settingsTable)
			->where('setting_name', 'ithenticateId')
			->where('setting_value', $id)
			->first();
	}
}
