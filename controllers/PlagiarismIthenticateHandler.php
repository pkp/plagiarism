<?php

/**
 * @file plugins/generic/plagiarism/controllers/PlagiarismIthenticateHandler.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlagiarismIthenticateHandler
 *
 * @brief Handle the different iThenticate service related actions
 */

namespace APP\plugins\generic\plagiarism\controllers; 

use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;
use APP\notification\NotificationManager;
use PKP\notification\Notification;
use APP\submission\Submission;
use APP\template\TemplateManager;
use APP\plugins\generic\plagiarism\IThenticate;
use APP\plugins\generic\plagiarism\controllers\PlagiarismComponentHandler;
use APP\plugins\generic\plagiarism\classes\PlagiarismErrorFormatter;
use Illuminate\Support\Arr;
use PKP\context\Context;
use PKP\core\Core;
use PKP\db\DAO;
use PKP\core\JSONMessage;
use PKP\site\SiteDAO;
use PKP\db\DAORegistry;
use PKP\submissionFile\SubmissionFile;
use PKP\security\authorization\SubmissionFileAccessPolicy;
use PKP\security\Role;

class PlagiarismIthenticateHandler extends PlagiarismComponentHandler
{
	/**
	 * @copydoc PKPHandler::__construct()
	 */
	public function __construct(PlagiarismPlugin $plugin)
	{
		parent::__construct($plugin);

		$this->addRoleAssignment(
			[
				Role::ROLE_ID_MANAGER,
				Role::ROLE_ID_SUB_EDITOR,
				Role::ROLE_ID_ASSISTANT, 
				Role::ROLE_ID_SITE_ADMIN
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
	public function authorize($request, &$args, $roleAssignments)
	{
		$this->markRoleAssignmentsChecked();

		$this->addPolicy(
			new SubmissionFileAccessPolicy(
				$request,
				$args,
				$roleAssignments,
				SubmissionFileAccessPolicy::SUBMISSION_FILE_ACCESS_READ,
				(int) $args['submissionFileId']
			)
		);
		
		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * Launch the iThenticate similarity report viewer
	 */
	public function launchViewer(array $args, Request $request)
	{
		$context = $request->getContext();
		$user = $request->getUser();
		$submissionFile = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */
		$submission = Repo::submission()->get($submissionFile->getData('submissionId'));
		$siteDao = DAORegistry::getDAO("SiteDAO"); /** @var SiteDAO $siteDao */
		$site = $siteDao->getSite();

		/** @var IThenticate $ithenticate */
		$ithenticate = $this->_plugin->initIthenticate(
			...$this->_plugin->getServiceAccess($context)
		);

		// If EULA is required and submission has EULA stamped, we set the applicable EULA
		// Otherwise get the current EULA from default one and set the applicable
		// Basically we need to retrieve the available langs details from EULA details
		$this->_plugin->getContextEulaDetails($context, 'require_eula') == true &&
		$submission->getData('ithenticateEulaVersion')
			? $ithenticate->setApplicableEulaVersion($submission->getData('ithenticateEulaVersion'))
			: $ithenticate->validateEulaVersion($ithenticate::DEFAULT_EULA_VERSION);

		$locale = $ithenticate
			->getApplicableLocale(
				collect([$submission->getData("locale")])
					->merge(Arr::wrap($user->getData("locales")))
					->merge([$context->getPrimaryLocale(), $site->getPrimaryLocale()])
					->unique()
					->filter()
					->toArray()
			);

		$viewerUrl = $ithenticate->createViewerLaunchUrl(
			$submissionFile->getData('ithenticateId'),
			$user,
			$locale,
			$this->_plugin->getSubmitterPermission($context, $user),
			(bool)$this->_plugin->getSimilarityConfigSettings($context, 'allowViewerUpdate')
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
	 */
	public function scheduleSimilarityReport(array $args, Request $request)
	{
		$context = $request->getContext();
		$submissionFile = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */

		/** @var IThenticate $ithenticate */
		$ithenticate = $this->_plugin->initIthenticate(
			...$this->_plugin->getServiceAccess($context)
		);

		// If no confirmation of submission file completed the processing at iThenticate service'e end
		// we first need to check it's processing status to see has set to `COMPLETED`
		// see more at https://developers.turnitin.com/turnitin-core-api/best-practice/retry-polling
		if (!$submissionFile->getData('ithenticateSubmissionAcceptedAt')) {
			$submissionInfo = $ithenticate->getSubmissionInfo($submissionFile->getData('ithenticateId'));

			// submission info not available to schedule report generation process
			if (!$submissionInfo) {
				return new JSONMessage(false, __('plugins.generic.plagiarism.webhook.similarity.schedule.error', [
					'submissionFileId' => $submissionFile->getId(),
					'error' => __('plugins.generic.plagiarism.submission.status.unavailable'),
				]));
			}

			$submissionInfo = json_decode($submissionInfo);

			// submission has not completed yet to schedule report generation process
			if ($submissionInfo->status !== 'COMPLETE') {
				// Resolve the per-status reason key. A terminal ERROR uses the iThenticate error_code
				// (falling back to a generic message when absent); CREATED/PROCESSING are transient states.
				$reasonKey = match ($submissionInfo->status) {
					'CREATED' => 'plugins.generic.plagiarism.submission.status.CREATED',
					'PROCESSING' => 'plugins.generic.plagiarism.submission.status.PROCESSING',
					'ERROR' => property_exists($submissionInfo, 'error_code')
						? "plugins.generic.plagiarism.ithenticate.submission.error.{$submissionInfo->error_code}"
						: 'plugins.generic.plagiarism.submission.status.ERROR',
					default => 'plugins.generic.plagiarism.submission.status.ERROR',
				};

				// Build the error descriptor (locale key + params, resolved at read time)
				$errorDescriptor = PlagiarismErrorFormatter::make(
					'plugins.generic.plagiarism.webhook.similarity.schedule.error',
					[
						'submissionFileId' => $submissionFile->getId(),
						'error' => PlagiarismErrorFormatter::make($reasonKey),
					]
				);

				// A terminal ERROR means the file will not process as-is: persist it (as the webhook does)
				// so the workflow surfaces the error icon and offers re-upload even where webhooks are not
				// delivered. CREATED/PROCESSING are transient — report them but do not persist, keeping the
				// in-progress icon so the editor can retry once processing finishes.
				if ($submissionInfo->status === 'ERROR') {
					$this->_plugin->recordSubmissionFileError($submissionFile, $errorDescriptor);
				}

				return new JSONMessage(false, PlagiarismErrorFormatter::resolve($errorDescriptor));
			}

			$submissionFile->setData('ithenticateSubmissionAcceptedAt', Core::getCurrentDate());
			Repo::submissionFile()->edit($submissionFile, []);
		}

		$scheduleSimilarityReport = $ithenticate->scheduleSimilarityReportGenerationProcess(
			$submissionFile->getData('ithenticateId'),
			$this->_plugin->getSimilarityConfigSettings($context)
		);

		if (!$scheduleSimilarityReport) {
			return new JSONMessage(false, __('plugins.generic.plagiarism.webhook.similarity.schedule.failure', [
				'submissionFileId' => $submissionFile->getId(),
			]));
		}

		$submissionFile->setData('ithenticateSimilarityScheduled', 1);
		$submissionFile->setData('ithenticateProcessingError', null);
		Repo::submissionFile()->edit($submissionFile, []);

		$this->_plugin->clearSubmissionErrors(Repo::submission()->get($submissionFile->getData('submissionId')));

		return new JSONMessage(true, __('plugins.generic.plagiarism.action.scheduleSimilarityReport.success'));
    }

	/**
	 * Refresh the submission's similarity score result
	 */
	public function refreshSimilarityResult(array $args, Request $request)
	{
		$context = $request->getContext();
		$submissionFile = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */

		/** @var IThenticate $ithenticate */
		$ithenticate = $this->_plugin->initIthenticate(
			...$this->_plugin->getServiceAccess($context)
		);

		$similarityScoreResult = $ithenticate->getSimilarityResult(
			$submissionFile->getData('ithenticateId')
		);

		if (!$similarityScoreResult) {
			return new JSONMessage(false, __('plugins.generic.plagiarism.action.refreshSimilarityResult.error', [
				'submissionFileId' => $submissionFile->getId(),
			]));
		}

		$similarityScoreResult = json_decode($similarityScoreResult);

		if ($similarityScoreResult->status !== 'COMPLETE') {
			return new JSONMessage(true, __('plugins.generic.plagiarism.action.refreshSimilarityResult.warning', [
				'submissionFileId' => $submissionFile->getId(),
			]));
		}

		$submissionFile->setData('ithenticateSimilarityResult', json_encode($similarityScoreResult));
		$submissionFile->setData('ithenticateProcessingError', null);
		Repo::submissionFile()->edit($submissionFile, []);

		$this->_plugin->clearSubmissionErrors(Repo::submission()->get($submissionFile->getData('submissionId')));

		return new JSONMessage(true, __('plugins.generic.plagiarism.action.refreshSimilarityResult.success'));
    }

	/**
	 * Upload the submission file and create a new submission at iThenticate service's end
	 */
	public function submitSubmission(array $args, Request $request)
	{
		$context = $request->getContext();
		$user = $request->getUser();

		$submissionFile = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */
		$submission = Repo::submission()->get($submissionFile->getData('submissionId'));

		/** @var IThenticate $ithenticate */
		$ithenticate = $this->_plugin->initIthenticate(
			...$this->_plugin->getServiceAccess($context)
		);

		// If no webhook previously registered for this Context, register it
		if (!$context->getData('ithenticateWebhookId')) {
			if (!$this->_plugin->registerIthenticateWebhook($ithenticate, $context)) {
				error_log("Webhook registration failed for context {$context->getId()} during manual submission");
			}
		}

		// As the submission has been already and should be stamped with an EULA at the
		// confirmation stage, need to set it
		if ($submission->getData('ithenticateEulaVersion')) {
			$ithenticate->setApplicableEulaVersion($submission->getData('ithenticateEulaVersion'));
		}

		$publication = $submission->getCurrentPublication();
		$author = $publication?->getPrimaryAuthor();

		if (!$author) {
			return $this->getSubmitSubmissionResponse(
				$request,
				$submissionFile,
				Notification::NOTIFICATION_TYPE_ERROR,
				__('plugins.generic.plagiarism.action.submitSubmission.missingPrimaryAuthor.error')
			);
		}

		if (!$this->_plugin->createNewSubmission($request, $user, $submission, $submissionFile, $ithenticate)) {
			// createNewSubmission busts the EULA cache on a detected mismatch and sets
			// hasLastEulaError() so we can pick a meaningful notification. The frontend
			// refetches the plagiarism status after this response, re-evaluating
			// isEulaConfirmationRequired against the fresh cache — naturally surfacing
			// the EULA modal on the user's next click. No reconfirmation signal needed.
			return $this->getSubmitSubmissionResponse(
				$request,
				$submissionFile,
				Notification::NOTIFICATION_TYPE_ERROR,
				__($this->_plugin->hasLastEulaError()
					? 'plugins.generic.plagiarism.action.submitSubmission.eulaUpdated'
					: 'plugins.generic.plagiarism.action.submitSubmission.error')
			);
		}

		return $this->getSubmitSubmissionResponse(
			$request,
			$submissionFile,
			Notification::NOTIFICATION_TYPE_SUCCESS,
			__('plugins.generic.plagiarism.action.submitSubmission.success')
		);
	}

	/**
	 * Accept the EULA, stamp it to proper entity (Submission/User or both) and upload
	 * submission file
	 */
	public function acceptEulaAndExecuteIntendedAction(array $args, Request $request)
	{
		$context = $request->getContext();
		$user = Repo::user()->get($request->getUser()->getId());

		$submissionFile = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION_FILE); /** @var SubmissionFile $submissionFile */
		$submission = Repo::submission()->get($submissionFile->getData('submissionId'));

		$eulaVersion = $this->_plugin->getContextEulaDetails($context, 'eula_version');

		$confirmSubmissionEula = $args['confirmSubmissionEula'] ?? false;

		if (!$confirmSubmissionEula) {

			$templateManager = $this->getEulaConfirmationTemplate(
				$request,
				$args,
				$context,
				$submission,
				$submissionFile
			);

			$request->getSession()->put('confirmSubmissionEulaError', true);

			return new JSONMessage(
				true,
				$templateManager->fetch($this->_plugin->getTemplateResource('confirmEula.tpl'))
			);
        }

		if ($submission->getData('ithenticateEulaVersion') !== $eulaVersion) {
			$this->_plugin->stampEulaToSubmission($context, $submission);
			$submission = Repo::submission()->get($submission->getId()); // refetch the submission after latest EULA stamped
		}

		if ($user->getData('ithenticateEulaVersion') !== $eulaVersion) {
			if (!$this->_plugin->stampEulaToSubmittingUser($context, $submission, $user)) {
				// stampEulaToSubmittingUser busts the EULA cache on a detected mismatch.
				// Toast the EULA-specific notification when applicable and refresh so the
				// frontend re-evaluates against the fresh cache; the user retries.
				return $this->getSubmitSubmissionResponse(
					$request,
					$submissionFile,
					Notification::NOTIFICATION_TYPE_ERROR,
					__($this->_plugin->hasLastEulaError()
						? 'plugins.generic.plagiarism.action.submitSubmission.eulaUpdated'
						: 'plugins.generic.plagiarism.action.submitSubmission.error')
				);
			}
		}

		return $this->submitSubmission($args, $request);
	}

	/**
	 * Show the EULA confirmation modal before the uploading submission file to iThenticate
	 */
	public function confirmEula(array $args, Request $request)
	{
		$context = $request->getContext();

		/** @var SubmissionFile $submissionFile */
		$submissionFile = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION_FILE);
		$submission = Repo::submission()->get($submissionFile->getData('submissionId'));

		$request->getSession()->remove('confirmSubmissionEulaError');

		$templateManager = $this->getEulaConfirmationTemplate(
			$request,
			$args,
			$context,
			$submission,
			$submissionFile
		);

		return new JSONMessage(
			true,
			$templateManager->fetch($this->_plugin->getTemplateResource('confirmEula.tpl'))
		);
	}

	/**
	 * Get the template manager to handle the EULA confirmation as the before action of 
	 * intended action.
	 */
	protected function getEulaConfirmationTemplate(
		Request $request,
		array $args,
		Context $context,
		Submission $submission,
		SubmissionFile $submissionFile
	): TemplateManager
	{
		// Always pull EULA details from the cache (single source of truth across
		// wizard, editorial workflow, and stamping).
		$eulaVersionDetails = $this->_plugin->getContextEulaDetails($context, [
			$submission->getData('locale'),
			$context->getPrimaryLocale(),
			$request->getSite()->getPrimaryLocale(),
			IThenticate::DEFAULT_EULA_LANGUAGE
		]);
		
		$actionUrl = $request->getDispatcher()->url(
			$request,
			Application::ROUTE_COMPONENT,
			$context->getData('urlPath'),
			'plugins.generic.plagiarism.controllers.PlagiarismIthenticateHandler',
			'acceptEulaAndExecuteIntendedAction',
			null,
			[
				'version' => $eulaVersionDetails['version'],
				'submissionFileId' => $submissionFile->getId(),
				'stageId' => $request->getUserVar('stageId'),
				'redirectForm' => 'confirmEula',
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
	 * Get the response of attempted submission file upload to iThenticate response 
	 */
	protected function getSubmitSubmissionResponse(
		Request $request,
		SubmissionFile $submissionFile,
		int $notificationType,
		string $notificationContent,
	): JSONMessage
	{
		if ($request->getUserVar('redirectForm') === 'confirmEula' ) {
			$this->generateUserNotification(
				$request,
				$notificationType, 
				$notificationContent
			);
			return $this->triggerDataChangedEvent($submissionFile);
		}

		return new JSONMessage(
			$notificationType == Notification::NOTIFICATION_TYPE_SUCCESS,
			$notificationContent
		);
	}

	/**
	 * Generate the user friendly notification upon a response received for an action
	 */
	protected function generateUserNotification(Request $request, int $notificationType, string $notificationContent): void
	{
		$notificationMgr = new NotificationManager();
		$notificationMgr->createTrivialNotification(
			$request->getUser()->getId(), 
			$notificationType, 
			['contents' => $notificationContent]
		);
	}

	/**
	 * Trigger the data change event to refresh the grid view
	 */
	protected function triggerDataChangedEvent(SubmissionFile $submissionFile): JSONMessage
	{
		if ($this->_plugin::isOPS()) {
			$submission = Repo::submission()->get($submissionFile->getData("submissionId"));

			// A submission file's galley may live on any publication version, not just the current one
			// (OPS is versioned). Search galleys across ALL of the submission's publications so the right
			// galley grid row refreshes regardless of which version owns the file.
			$publicationIds = $submission
				? $submission->getData("publications")->map(fn ($publication) => $publication->getId())->all()
				: [];

			if (!empty($publicationIds)) {
				$galley = Repo::galley()
					->getCollector()
					->filterByPublicationIds($publicationIds)
					->getMany()
					->filter(fn ($galley) => $galley->getData("submissionFileId") == $submissionFile->getId())
					->first();

				if ($galley) {
					return DAO::getDataChangedEvent($galley->getId());
				}
			}
		}

		return DAO::getDataChangedEvent($submissionFile->getId());
	}

}
