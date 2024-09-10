<?php

/**
 * @file plugins/generic/plagiarism/grids/SimilarityActionGridColumn.inc.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SimilarityActionGridColumn
 *
 * @brief GridColumn handler to show similarity score and actions related to iThenticate
 */

namespace APP\plugins\generic\plagiarism\grids;

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\submission\Submission;
use APP\template\TemplateManager;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;
use PKP\core\PKPRequest;
use PKP\user\User;
use PKP\context\Context;
use PKP\controllers\grid\GridHandler;
use PKP\controllers\grid\GridColumn;
use PKP\linkAction\request\RemoteActionConfirmationModal;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\LinkAction;
use PKP\security\Role;
use PKP\submissionFile\SubmissionFile;
use PKP\db\DAORegistry;
use PKP\controllers\grid\ColumnBasedGridCellProvider;


class SimilarityActionGridColumn extends GridColumn
{
	/** 
	 * The grid column id name where similarity score/action will be visible
	 */
	public const SIMILARITY_ACTION_GRID_COLUMN_ID = 'score';
	
	/** 
	 * The Plagiarism Plugin itself
	 */
	protected PlagiarismPlugin $_plugin;

	/**
	 * Constructor
	 *
	 * @param PlagiarismPlugin  $plugin The Plagiarism Plugin itself
	 */
	public function __construct(PlagiarismPlugin $plugin) {

		$this->_plugin = $plugin;

		$cellProvider = new ColumnBasedGridCellProvider();

		parent::__construct(
			self::SIMILARITY_ACTION_GRID_COLUMN_ID,
			'plugins.generic.plagiarism.similarity.action.column.score.title',
			null,
			null, 
			$cellProvider,
			['width' => 30, 'alignment' => GridColumn::COLUMN_ALIGNMENT_LEFT, 'anyhtml' => true]
		);
	}

	/**
	 * Method expected by ColumnBasedGridCellProvider to render a cell in this column.
	 *
	 * @copydoc ColumnBasedGridCellProvider::getTemplateVarsFromRowColumn()
	 */
	public function getTemplateVarsFromRow($row)
	{
		$request = Application::get()->getRequest();
		
		if ($this->_plugin::isOPS()) { // For OPS
			$articleGalley = $row->getData(); /** @var ArticleGalley $articleGalley */
			
			if (!$articleGalley->getData('submissionFileId')) {
				return ['label' => ''];
			}

			$submissionFile = Repo::submissionFile()->get($articleGalley->getData('submissionFileId')); /** @var SubmissionFile $submissionFile */

			$articleGalleyDao = DAORegistry::getDAO('ArticleGalleyDAO'); /** @var ArticleGalleyDAO $articleGalleyDao */
			$articleGalley = $articleGalleyDao->getByFileId($submissionFile->getId()); /** @var ArticleGalley $articleGalley */
		} else {
			$submissionFileData = $row->getData();
			$submissionFile = $submissionFileData['submissionFile']; /** @var SubmissionFile $submissionFile */			
		}

		assert($submissionFile instanceof SubmissionFile);

		// Not going to allow plagiarism action on a zip file
		if ($this->isSubmissionFileTypeRestricted($submissionFile)) {
			return ['label' => __('plugins.generic.plagiarism.similarity.action.invalidFileType')];
		}

        // submission similarity score is available
        if ($submissionFile->getData('ithenticateSimilarityScheduled') == true &&
            $submissionFile->getData('ithenticateSimilarityResult')) {
            
            $similarityResult = json_decode(
                $submissionFile->getData('ithenticateSimilarityResult'),
            );

			$templateManager = TemplateManager::getManager();
			$templateManager->assign([
				'logoUrl' => $this->_plugin->getIThenticateLogoUrl(),
				'score' => $similarityResult->overall_match_percentage,
				'viewerUrl' => $request->getDispatcher()->url(
					$request,
					Application::ROUTE_COMPONENT,
					$request->getContext()->getData('urlPath'),
					'plugins.generic.plagiarism.controllers.PlagiarismIthenticateActionHandler',
					'launchViewer',
					null,
					[
						'stageId' => $this->getStageId($request),
						'submissionId' => $submissionFile->getData('submissionId'),
						'submissionFileId' => $submissionFile->getId(),
					]
				)
			]);

            return [
				'label' => $templateManager->fetch(
					$this->_plugin->getTemplateResource('similarityScore.tpl')
				)
			];
        }

        return ['label' => ''];
	}

    /**
	 * @copydoc GridColumn::getCellActions()
	 */
	public function getCellActions($request, $row, $position = GridHandler::GRID_ACTION_POSITION_DEFAULT)
	{
		$cellActions = parent::getCellActions($request, $row, $position);
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$user = Repo::user()->get($request->getUser()->getId());

		// User can not perform any of following actions if not a Journal Manager or Editor
		//      - Upload file for plagiarism check if failed
		//      - Schedule similarity report generation if not scheduled already
		//      - Refresh the similarity report scores
		//      - Launch similarity report viewer
		if (!$user->hasRole([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR], $context->getId())) {
			return $cellActions;
		}

		if ($this->_plugin::isOPS()) { // For OPS
			$articleGalley = $row->getData(); /** @var ArticleGalley $articleGalley */

			if (!$articleGalley->getData('submissionFileId')) {
				return $cellActions;
			}

			$submissionFile = Repo::submissionFile()->get($articleGalley->getData('submissionFileId')); /** @var SubmissionFile $submissionFile */
		} else {
			$submissionFileData = $row->getData();
			$submissionFile = $submissionFileData['submissionFile']; /** @var SubmissionFile $submissionFile */
		}

		// Not going to allow plagiarism action on a zip file
		if ($this->isSubmissionFileTypeRestricted($submissionFile)) {
			return $cellActions;
		}

		$submission = Repo::submission()->get($submissionFile->getData('submissionId'));

		// There was an error and submission not completed, 
		// Ask for confirmation and try to complete the submission process
		if (!$submissionFile->getData('ithenticateId')) {

			// first check if curernt user has already EULA confirmed that is associated with submission
			// If not confirmed, need to confirm EULA first before uploading submission to iThenticate

			if ($this->isEulaConfirmationRequired($context, $submission, $user)) {

				$cellActions[] = new LinkAction(
					"plagiarism-eula-confirmation-{$submissionFile->getId()}",
					new AjaxModal(
						$request->getDispatcher()->url(
							$request,
							Application::ROUTE_COMPONENT,
							$context->getData('urlPath'),
							'plugins.generic.plagiarism.controllers.PlagiarismIthenticateActionHandler',
							'confirmEula',
							null,
							[
								'stageId' => $this->getStageId($request),
								'submissionId' => $submission->getId(),
								'submissionFileId' => $submissionFile->getId(),
							]
						),
						__('plugins.generic.plagiarism.similarity.action.confirmEula.title')
					),
					__('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title')
				);

				return $cellActions;
            }

			$cellActions[] = new LinkAction(
				"plagiarism-submission-submit-{$submissionFile->getId()}",
				new RemoteActionConfirmationModal(
					$request->getSession(),
					__('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.confirmation'),
					__('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title'),
					$request->getDispatcher()->url(
						$request,
						Application::ROUTE_COMPONENT,
						$context->getData('urlPath'),
						'plugins.generic.plagiarism.controllers.PlagiarismIthenticateActionHandler',
						'submitSubmission',
						null,
						[
							'stageId' => $this->getStageId($request),
							'submissionId' => $submission->getId(),
							'submissionFileId' => $submissionFile->getId(),
						]
					)
				),
				__('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title')
			);

			return $cellActions;
		}
        
		// Submission similarity report generation has not scheduled
		if ($submissionFile->getData('ithenticateSimilarityScheduled') == false) {
			$cellActions[] = new LinkAction(
				"plagiarism-similarity-report-schedule-{$submissionFile->getId()}",
				new RemoteActionConfirmationModal(
					$request->getSession(),
					__('plugins.generic.plagiarism.similarity.action.generateReport.confirmation'),
					__('plugins.generic.plagiarism.similarity.action.generateReport.title'),
					$request->getDispatcher()->url(
						$request,
						Application::ROUTE_COMPONENT,
						$context->getData('urlPath'),
						'plugins.generic.plagiarism.controllers.PlagiarismIthenticateActionHandler',
						'scheduleSimilarityReport',
						null,
						[
							'stageId' => $this->getStageId($request),
							'submissionId' => $submission->getId(),
							'submissionFileId' => $submissionFile->getId(),
						]
					)
				),
				__('plugins.generic.plagiarism.similarity.action.generateReport.title'),
			);

			return $cellActions;
		}

		// Generate the action for similarity score refresh
		$similarityResultRefreshAction = new LinkAction(
			"plagiarism-similarity-score-refresh-{$submissionFile->getId()}",
			new RemoteActionConfirmationModal(
				$request->getSession(),
				__('plugins.generic.plagiarism.similarity.action.refreshReport.confirmation'),
				__('plugins.generic.plagiarism.similarity.action.refreshReport.title'),
				$request->getDispatcher()->url(
					$request,
					Application::ROUTE_COMPONENT,
					$context->getData('urlPath'),
					'plugins.generic.plagiarism.controllers.PlagiarismIthenticateActionHandler',
					'refreshSimilarityResult',
					null,
					[
						'stageId' => $this->getStageId($request),
						'submissionId' => $submission->getId(),
						'submissionFileId' => $submissionFile->getId(),
					]
				)
			),
			__('plugins.generic.plagiarism.similarity.action.refreshReport.title')
		);

		// If similarity score not available
		// show as cell action and upon it's available, show it as part of row action
		$submissionFile->getData('ithenticateSimilarityResult')
			? $row->addAction($similarityResultRefreshAction)
			: array_push($cellActions, $similarityResultRefreshAction);

		return $cellActions;
	}

	/**
	 * Check for the requrement of EULA confirmation
	 *
	 * @param Context       $context
	 * @param Submission    $submission
	 * @param User          $user
	 * 
	 * @return bool
	 */
	protected function isEulaConfirmationRequired(Context $context, Submission $submission, User $user): bool
	{
		// Check if EULA confirmation required for this tenant
		if ($this->_plugin->getContextEulaDetails($context, 'require_eula') == false) {
			return false;
		}

		// If no EULA is stamped with submission
		// means submission never passed through iThenticate process
		if (!$submission->getData('ithenticateEulaVersion')) {
			return true;
		}
		
		// If no EULA is stamped with submitting user
		// means user has never previously interacted with iThenticate process

		if (!$user->getData('ithenticateEulaVersion')) {
			return true;
		}

		// If user and submission EULA do not match
		// means users previously agreed upon different EULA
		if ($user->getData('ithenticateEulaVersion') !== $submission->getData('ithenticateEulaVersion')) {
			return true;
		}

		return false;
    }

	/**
	 * Check if submission file type in valid for plagiarism action
	 * Restricted for ZIP file
	 *
	 * @param SubmissionFile $submissionFile
	 * @return bool
	 */
	protected function isSubmissionFileTypeRestricted(SubmissionFile $submissionFile): bool
	{
		$pkpFileService = Services::get('file'); /** @var \PKP\Services\PKPFileService $pkpFileService */
		$file = $pkpFileService->get($submissionFile->getData('fileId'));
		
		return in_array($file->mimetype, $this->_plugin->uploadRestrictedArchiveMimeTypes);
	}

	/**
	 * Get the proper workflow stage id for iThenticate actions
	 *
	 * @param Request $request
	 * @return int
	 */
	protected function getStageId(PKPRequest $request): int
	{
		if ($this->_plugin::isOPS()) {
			return WORKFLOW_STAGE_ID_PRODUCTION;
		}

		return $request->getUserVar('stageId');
	}

}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\plagiarism\grids\SimilarityActionGridColumn', '\SimilarityActionGridColumn');
}
