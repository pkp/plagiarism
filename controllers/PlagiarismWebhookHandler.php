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
	 * Authentication is by HMAC over the raw request body, not by an session
	 * so the request itself is always authorized to reach handle().
	 *
	 * @return bool
	 */
	public function authorize($request, &$args, $roleAssignments)
	{
		return true;
	}

	/**
	 * Handle the incoming webhook request from iThenticate service.
	 *
	 * @return void
	 */
	public function handle()
	{
		$headers = collect(array_change_key_case(getallheaders(), CASE_LOWER));

		// Raw body, byte-exact — required for HMAC verification before any JSON decode.
		$payload = file_get_contents('php://input');
		if ($payload === false) {
			$payload = '';
		}

		if (!$headers->has(['x-turnitin-eventtype', 'x-turnitin-signature'])) {
			error_log('iThenticate webhook: missing required headers; ignoring.');
			return;
		}

		$event = $headers->get('x-turnitin-eventtype');

		if (!in_array($event, IThenticate::DEFAULT_WEBHOOK_EVENTS)) {
			error_log("iThenticate webhook: invalid event type {$event}; ignoring.");
			return;
		}

		// Authenticate over the RAW body before decoding attacker-controlled JSON or touching
		// the database. No DB read / JSON decode / mutation happens until the HMAC passes.
		$auth = $this->authenticate($payload, (string) $headers->get('x-turnitin-signature'));

		if (!$auth) {
			error_log("iThenticate webhook ({$event}): signature verification failed against all known secrets; ignoring.");
			return;
		}

		match ($event) {
			'SUBMISSION_COMPLETE'
				=> $this->handleSubmissionCompleteEvent($auth, $payload, $event),
			'SIMILARITY_COMPLETE', 'SIMILARITY_UPDATED'
				=> $this->storeSimilarityScore($auth, $payload, $event),
			default
				// Authenticated but not actionable (e.g. PDF_STATUS, GROUP_ATTACHMENT_COMPLETE
				// carry no submission UUID we act on).
				=> null,
		};
	}

	/**
	 * Build the candidate signing-secret set and match the delivery's HMAC against it.
	 *
	 * @return array|null Matched descriptor ['via'=>'registry','fingerprint'=>...] or
	 *                    ['via'=>'legacy','contextId'=>...], or null on no match.
	 */
	protected function authenticate(string $rawBody, string $signature): ?array
	{
		$candidates = [];

		foreach ($this->_plugin->getWebhookManager()->getWebhookRegistry() as $fingerprint => $entry) {
			if (!empty($entry['signingSecret'])) {
				$candidates[] = [
					'via' => 'registry',
					'fingerprint' => $fingerprint,
					'secret' => $entry['signingSecret'],
				];
			}
		}

		// A legacy per-context webhook still POSTs to its context path, so the request resolves
		// to that context; honour its stored secret so existing webhooks keep authenticating.
		$context = Application::get()->getRequest()->getContext();
		if ($context && $context->getData('ithenticateWebhookSigningSecret')) {
			$candidates[] = [
				'via' => 'legacy',
				'contextId' => (int) $context->getId(),
				'secret' => $context->getData('ithenticateWebhookSigningSecret'),
			];
		}

		return static::matchSigningSecret($rawBody, $signature, $candidates);
	}

	/**
	 * Match an HMAC-SHA256 signature against a set of candidate secrets, returning on the first hit.
	 * 
	 * @param array $candidates each ['via'=>..., 'secret'=>..., ...descriptor keys]
	 *
	 * @return array|null The matched candidate WITHOUT its 'secret' key, or null if none match.
	 */
	public static function matchSigningSecret(string $rawBody, string $signature, array $candidates): ?array
	{
		foreach ($candidates as $candidate) {
			$expected = hash_hmac('sha256', $rawBody, (string) ($candidate['secret'] ?? ''));
			if (hash_equals($expected, $signature)) {
				unset($candidate['secret']);
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Initiate the iThenticate similarity report generation process for the referenced
	 * iThenticate submission id on receiving the `SUBMISSION_COMPLETE` webhook event.
	 *
	 * @param array 	$auth		The authenticated scope descriptor from authenticate()
	 * @param string 	$payload	The incoming request payload through webhook
	 * @param string 	$event		The incoming webhook request event
	 *
	 * @return void
	 */
	protected function handleSubmissionCompleteEvent(array $auth, string $payload, string $event): void
	{
		$payload = json_decode($payload);
		if (!is_object($payload) || !isset($payload->id)) {
			error_log("iThenticate webhook ({$event}): invalid or incomplete JSON payload; ignoring.");
			return;
		}

		$submissionFile = $this->resolveSubmissionFile($payload->id, $event);
		if (!$submissionFile) {
			return;
		}

		$context = $this->bindDeliveryToScope($auth, $submissionFile, $event);
		if (!$context) {
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

		$ithenticate = $this->_plugin->initIthenticate(...$this->_plugin->getServiceAccess($context));

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
	 * @param array 	$auth		The authenticated scope descriptor from authenticate()
	 * @param string 	$payload	The incoming request payload through webhook
	 * @param string 	$event		The incoming webhook request event
	 *
	 * @return void
	 */
	protected function storeSimilarityScore(array $auth, string $payload, string $event): void
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

		$submissionFile = $this->resolveSubmissionFile($payload->submission_id, $event);
		if (!$submissionFile) {
			return;
		}

		if (!$this->bindDeliveryToScope($auth, $submissionFile, $event)) {
			return;
		}

		$submissionFile->setData('ithenticateSimilarityResult', json_encode($payload));
		$submissionFile->setData('ithenticateProcessingError', null);
		Repo::submissionFile()->edit($submissionFile, []);
	}

	/**
	 * Resolve the submission file referenced by an iThenticate submission UUID, or null
	 * (logging the reason) when the mapping or the file cannot be found.
	 */
	protected function resolveSubmissionFile(string $ithenticateSubmissionId, string $event): ?SubmissionFile
	{
		$ithenticateSubmission = $this->getIthenticateSubmission($ithenticateSubmissionId);

		if (!$ithenticateSubmission) {
			error_log("iThenticate webhook ({$event}): no submission file found for iThenticate submission id {$ithenticateSubmissionId}; ignoring.");
			return null;
		}

		$submissionFile = Repo::submissionFile()->get($ithenticateSubmission->submission_file_id);
		if (!$submissionFile) {
			error_log("iThenticate webhook ({$event}): submission file {$ithenticateSubmission->submission_file_id} not found; ignoring.");
			return null;
		}

		return $submissionFile;
	}

	/**
	 * Bind an authenticated delivery to the acting context, rejecting cross-tenant references.
	 *
	 *  - legacy: the acting context IS the delivering context; verify the submission file
	 *    belongs to it
	 *  - registry: derive the acting context from the submission, then require the authenticated
	 *    scope fingerprint to equal the fingerprint of that context's currently-resolved
	 *    credentials.
	 *
	 * @return Context|null The acting context, or null when the delivery must be rejected.
	 */
	protected function bindDeliveryToScope(array $auth, SubmissionFile $submissionFile, string $event): ?Context
	{
		$submission = Repo::submission()->get($submissionFile->getData('submissionId'));
		if (!$submission) {
			error_log("iThenticate webhook ({$event}): submission not found for file {$submissionFile->getId()}; ignoring.");
			return null;
		}

		$context = app()->get('context')->get($submission->getData('contextId'));
		if (!$context) {
			error_log("iThenticate webhook ({$event}): context {$submission->getData('contextId')} not found for file {$submissionFile->getId()}; ignoring.");
			return null;
		}

		// legacy: the acting context IS the delivering (authenticated) context; it must own the file.
		if ($auth['via'] === 'legacy') {
			if ((int) $submission->getData('contextId') !== $auth['contextId']) {
				error_log("iThenticate webhook ({$event}): submission file {$submissionFile->getId()} is not associated with context {$auth['contextId']}; ignoring.");
				return null;
			}
			return $context;
		}

		// registry: the authenticated scope fingerprint must equal the fingerprint of the file's own
		// context's currently-resolved credentials
		$expectedFingerprint = $this->_plugin->getWebhookManager()->credentialFingerprint(...$this->_plugin->getServiceAccess($context));

		if (!hash_equals($auth['fingerprint'], $expectedFingerprint)) {
			error_log("iThenticate webhook ({$event}): authenticated scope does not own submission file {$submissionFile->getId()}; rejecting.");
			return null;
		}

		return $context;
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
