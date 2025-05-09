<?php

/**
 * @file plugins/generic/plagiarism/api/v1/submissions/SubmissionPlagiarismController.php
 *
 * Copyright (c) 2013-2025 Simon Fraser University
 * Copyright (c) 2013-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SubmissionPlagiarismController
 *
 * @brief  API controller class to retrieve itenticate details for submission.
 */

namespace APP\plugins\generic\plagiarism\api\v1\submissions;

use APP\facades\Repo;
use APP\plugins\generic\plagiarism\api\v1\submissions\formRequests\SubmissionPlagiarismStatus;
use PKP\security\Role;
use PKP\core\PKPRequest;
use APP\core\Application;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use APP\API\v1\submissions\SubmissionController;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;
use PKP\security\authorization\SubmissionAccessPolicy;
use PKP\security\authorization\internal\SubmissionCompletePolicy;

class SubmissionPlagiarismController extends SubmissionController
{
    /**
     * API controller constructor
     */
    public function __construct(protected PlagiarismPlugin $plugin)
    {

    }

    /**
     * @copydoc \PKP\core\PKPBaseController::authorize()
     */
    public function authorize(PKPRequest $request, array &$args, array $roleAssignments): bool
    {
        $illuminateRequest = $args[0]; /** @var \Illuminate\Http\Request $illuminateRequest */
        $actionName = static::getRouteActionName($illuminateRequest);

        if ($actionName === 'plagiarismStatus') {
            $this->addPolicy(new SubmissionAccessPolicy($request, $args, $roleAssignments));
            $this->addPolicy(new SubmissionCompletePolicy($request, $args));
        }

        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * @copydoc \PKP\core\PKPBaseController::getGroupRoutes()
     */
    public function getGroupRoutes(): void
    {
        parent::getGroupRoutes();

        Route::middleware([
            self::roleAuthorizer([
                Role::ROLE_ID_SITE_ADMIN,
                Role::ROLE_ID_MANAGER,
                Role::ROLE_ID_SUB_EDITOR,
                Role::ROLE_ID_ASSISTANT,
            ]),
        ])->group(function () {
            Route::post('{submissionId}/plagiarism/status', $this->plagiarismStatus(...))
                ->name('submission.plagiarism.status')
                ->whereNumber('submissionId');
        });
    }

    /**
     * Get the plagiarism status and details for a submission
     */
    public function plagiarismStatus(SubmissionPlagiarismStatus $illuminateRequest): JsonResponse
    {
        $submission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);

        $request = $this->getRequest();
        $context = $request->getContext();
        
        // Here need to re-retrieve user to make sure the schema updates
        // in the plagiarism plugin applied to user data
        $user = Repo::user()->get($request->getUser()->getId());

        $submissionFiles = Repo::submissionFile()
            ->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->getMany();
        $fileStatuses = [];

        foreach ($submissionFiles as $submissionFile) {

            $fileStatuses[$submissionFile->getId()] = [
                'ithenticateUploadAllowed' => !$this->plugin->isSubmissionFileTypeRestricted($submissionFile),
                'ithenticateFileId' => $submissionFile->getData('ithenticateFileId'),
                'ithenticateId' => $submissionFile->getData('ithenticateId'),
                'ithenticateSimilarityScheduled' => (bool)$submissionFile->getData('ithenticateSimilarityScheduled'),
                'ithenticateSimilarityResult' => $submissionFile->getData('ithenticateSimilarityResult')
                    ? json_decode($submissionFile->getData('ithenticateSimilarityResult'), true)['overall_match_percentage']
                    : null,
                'ithenticateSubmissionAcceptedAt' => $submissionFile->getData('ithenticateSubmissionAcceptedAt'),
                'ithenticateRevisionHistory' => $submissionFile->getData('ithenticateRevisionHistory'),
                'ithenticateLogo' => $this->plugin->getIThenticateLogoUrl(),
                'ithenticateViewerUrl' => $this->plugin->getPlagiarismActionUrl($request, 'launchViewer', $submissionFile),
                'ithenticateUploadUrl' => $this->plugin->getPlagiarismActionUrl($request, 'submitSubmission', $submissionFile),
                'ithenticateReportScheduleUrl' => $this->plugin->getPlagiarismActionUrl($request, 'scheduleSimilarityReport', $submissionFile),
                'ithenticateReportRefreshUrl' => $this->plugin->getPlagiarismActionUrl($request, 'refreshSimilarityResult', $submissionFile),
            ];
        }

        return response()->json([
            'context' => [
                'eulaRequired' => (bool)$this->plugin->getContextEulaDetails($context, 'require_eula'),
            ],
            'submission' => [
                'ithenticateEulaVersion' => $submission->getData('ithenticateEulaVersion'),
                'ithenticateSubmissionCompletedAt' => $submission->getData('ithenticateSubmissionCompletedAt'),
                'ithenticateEulaUrl' => $submission->getData('ithenticateEulaUrl'),
            ],
            'user' => [
                'ithenticateEulaVersion' => $user->getData('ithenticateEulaVersion'),
                'ithenticateEulaConfirmedAt' => $user->getData('ithenticateEulaConfirmedAt'),
                'ithenticateActionAllowedRoles' => [
                    Role::ROLE_ID_SITE_ADMIN,
                    Role::ROLE_ID_MANAGER,
                    Role::ROLE_ID_SUB_EDITOR,
                    Role::ROLE_ID_ASSISTANT,
                ],
            ],
            'files' => $fileStatuses,
        ], Response::HTTP_OK);
    }
}
