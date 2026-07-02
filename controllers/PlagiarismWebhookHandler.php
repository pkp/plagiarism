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
use APP\plugins\generic\plagiarism\classes\notification\PlagiarismErrorManager;
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

		// A webhook with no resolvable context cannot be validated or routed — bail cleanly.
		if (!$context) {
			error_log('iThenticate webhook received with no resolvable context; ignoring.');
			return;
		}

		// getallheaders() is only defined under some SAPIs (Apache/FastCGI); fall back to $_SERVER.
		$rawHeaders = function_exists('getallheaders') ? (getallheaders() ?: []) : $this->serverHeaders();
		$headers = collect(array_change_key_case($rawHeaders, CASE_LOWER));

		$payload = file_get_contents('php://input');
		if ($payload === false) {
			$payload = '';
		}

		if (!$context->getData('ithenticateWebhookId') || !$context->getData('ithenticateWebhookSigningSecret')) {
			$this->_plugin->getErrorManager()->record(
				__('plugins.generic.plagiarism.webhook.configuration.missing', [
					'contextId' => $context->getId(),
				]),
				null,
				null,
				PlagiarismErrorManager::CONFIG_ERROR_CODE_WEBHOOK_CONFIGURATION_MISSING
			);
			return;
		}

		if (!$headers->has(['x-turnitin-eventtype', 'x-turnitin-signature'])) {
			$this->_plugin->getErrorManager()->record(
				__('plugins.generic.plagiarism.webhook.headers.missing'),
				null,
				null,
				PlagiarismErrorManager::CONFIG_ERROR_CODE_WEBHOOK_HEADERS_MISSING
			);
			return;
		}

		if (!in_array($headers->get('x-turnitin-eventtype'), IThenticate::DEFAULT_WEBHOOK_EVENTS)) {
			$this->_plugin->getErrorManager()->record(
				__('plugins.generic.plagiarism.webhook.event.invalid', [
					'event' => $headers->get('x-turnitin-eventtype'),
				]),
				null,
				null,
				PlagiarismErrorManager::CONFIG_ERROR_CODE_WEBHOOK_EVENT_INVALID
			);
			return;
		}

		if ($headers->get('x-turnitin-signature') !== hash_hmac("sha256", $payload, $context->getData('ithenticateWebhookSigningSecret'))) {
			$this->_plugin->getErrorManager()->record(
				__('plugins.generic.plagiarism.webhook.signature.invalid'),
				null,
				null,
				PlagiarismErrorManager::CONFIG_ERROR_CODE_WEBHOOK_SIGNATURE_INVALID
			);
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
			$this->_plugin->getErrorManager()->record(
				__('plugins.generic.plagiarism.webhook.submissionId.invalid', [
					'submissionUuid' => $payload->id,
					'event' => $event,
				]),
				null,
				null,
				PlagiarismErrorManager::CONFIG_ERROR_CODE_WEBHOOK_SUBMISSION_ID_INVALID
			);
			return;
		}

		$submissionFile = Repo::submissionFile()->get($ithenticateSubmission->submission_file_id);
		if (!$submissionFile) {
			error_log("iThenticate webhook ({$event}): submission file {$ithenticateSubmission->submission_file_id} not found; ignoring.");
			return;
		}

		if (!$this->verifySubmissionFileAssociationWithContext($context, $submissionFile)) {
			$this->_plugin->getErrorManager()->record(
				__('plugins.generic.plagiarism.webhook.submissionFileAssociationWithContext.invalid', [
					'submissionFileId' => $submissionFile->getId(),
					'contextId' => $context->getId(),
				]),
				null,
				null,
				PlagiarismErrorManager::CONFIG_ERROR_CODE_WEBHOOK_SUBMISSION_FILE_ASSOCIATION_INVALID
			);
			return;
		}

		if (($payload->status ?? null) !== 'COMPLETE') {
			// If the status is not `COMPLETE`, then it's `ERROR`. Fall back to a generic reason when the
			// payload carries no error_code, so the message never renders a missing-translation sentinel.
			$errorText = isset($payload->error_code)
				? __("plugins.generic.plagiarism.ithenticate.submission.error.{$payload->error_code}")
				: __('plugins.generic.plagiarism.submission.status.ERROR');
			$this->_plugin->getErrorManager()->record(
				__('plugins.generic.plagiarism.webhook.similarity.schedule.error', [
					'submissionFileId' => $submissionFile->getId(),
					'error' => $errorText,
				]),
				$submissionFile->getData('submissionId'),
				$submissionFile,
				$payload->error_code ?? PlagiarismErrorManager::FILE_ERROR_CODE_SIMILARITY_SCHEDULE_ERROR
			);
			return;
		}

		$submissionFile->setData('ithenticateSubmissionAcceptedAt', Core::getCurrentDate());
		Repo::submissionFile()->edit($submissionFile, []);
		
		$submissionFile = Repo::submissionFile()->get($submissionFile->getId());
		
		if ((int)$submissionFile->getData('ithenticateSimilarityScheduled')) {
			$this->_plugin->getErrorManager()->record(
				__('plugins.generic.plagiarism.webhook.similarity.schedule.previously', [
					'submissionFileId' => $submissionFile->getId(),
				]),
				$submissionFile->getData('submissionId'),
				$submissionFile,
				PlagiarismErrorManager::FILE_ERROR_CODE_SIMILARITY_SCHEDULE_PREVIOUSLY
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
			$this->_plugin->getErrorManager()->record(
				PlagiarismErrorManager::withDetail(
					__('plugins.generic.plagiarism.webhook.similarity.schedule.failure', [
						'submissionFileId' => $submissionFile->getId(),
					]),
					$ithenticate->getLastErrorSummary()
				),
				$submissionFile->getData('submissionId'),
				$submissionFile,
				PlagiarismErrorManager::FILE_ERROR_CODE_SIMILARITY_SCHEDULE_FAILURE,
				__('plugins.generic.plagiarism.guidance.similarity.schedule.failure')
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

		// we will not store similarity check result unless it has completed
		if (($payload->status ?? null) !== 'COMPLETE') {
			return;
		}

		if (!isset($payload->submission_id)) {
			error_log("iThenticate webhook ({$event}): payload missing submission_id; ignoring.");
			return;
		}

		$ithenticateSubmission = $this->getIthenticateSubmission($payload->submission_id);

		if (!$ithenticateSubmission) {
			$this->_plugin->getErrorManager()->record(
				__('plugins.generic.plagiarism.webhook.submissionId.invalid', [
					'submissionUuid' => $payload->submission_id,
					'event' => $event,
				]),
				null,
				null,
				PlagiarismErrorManager::CONFIG_ERROR_CODE_WEBHOOK_SUBMISSION_ID_INVALID
			);
			return;
		}

		$submissionFile = Repo::submissionFile()->get($ithenticateSubmission->submission_file_id);
		if (!$submissionFile) {
			error_log("iThenticate webhook ({$event}): submission file {$ithenticateSubmission->submission_file_id} not found; ignoring.");
			return;
		}

		if (!$this->verifySubmissionFileAssociationWithContext($context, $submissionFile)) {
			$this->_plugin->getErrorManager()->record(
				__('plugins.generic.plagiarism.webhook.submissionFileAssociationWithContext.invalid', [
					'submissionFileId' => $submissionFile->getId(),
					'contextId' => $context->getId(),
				]),
				null,
				null,
				PlagiarismErrorManager::CONFIG_ERROR_CODE_WEBHOOK_SUBMISSION_FILE_ASSOCIATION_INVALID
			);
			return;
		}

		$submissionFile->setData('ithenticateSimilarityResult', json_encode($payload));
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
	 * Fallback header reader for SAPIs where getallheaders() is unavailable (e.g. some CLI/nginx setups).
	 * Rebuilds header names from the $_SERVER HTTP_* entries.
	 */
	private function serverHeaders(): array
	{
		$headers = [];
		foreach ($_SERVER as $key => $value) {
			if (str_starts_with($key, 'HTTP_')) {
				$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
				$headers[$name] = $value;
			}
		}

		return $headers;
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
