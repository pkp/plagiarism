<?php

/**
 * @file plugins/generic/plagiarism/classes/api/PlagiarismApiActionManager.php
 *
 * Copyright (c) 2013-2025 Simon Fraser University
 * Copyright (c) 2013-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PlagiarismApiActionManager
 *
 * @brief  API manager class to retrieve itenticate details for submission.
 */

namespace APP\plugins\generic\plagiarism\classes\api;

use APP\core\Application;
use APP\facades\Repo;
use APP\submission\Submission;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;
use PKP\security\Role;
use PKP\core\PKPRequest;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlagiarismApiActionManager
{
    /**
     * Maximum time (in seconds) for streaming responses
     */
    public const MAX_STREAM_TIME = 600;

    /**
     * Plagiarism plugin instance
     */
    protected PlagiarismPlugin $plugin;

    /**
     * Constructor
     */
    public function __construct(PlagiarismPlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Get the plagiarism status and details for a submission
     */
    public function plagiarismStatus(Submission $submission, ?PKPRequest $request = null): JsonResponse
    {
        $request ??= Application::get()->getRequest();

        return response()->json(
            $this->getPlagiarismStatusData($submission, $request),
            Response::HTTP_OK
        );
    }

    /**
     * Get the plagiarism status as stream response
     */
    public function streamPlagiarismStatus(Submission $submission, ?PKPRequest $request = null): StreamedResponse
    {
        $request ??= Application::get()->getRequest();
        $originalMaxExecutionTime = (int) ini_get('max_execution_time');
        
        // If max_execution_time is 0 (infinite) or >= MAX_STREAM_TIME, use it (capped at MAX_STREAM_TIME)
        if ($originalMaxExecutionTime === 0 || $originalMaxExecutionTime >= static::MAX_STREAM_TIME) {
            $maxDuration = static::MAX_STREAM_TIME - 5; // Cap at (MAX_STREAM_TIME - 5) seconds with buffer
        } else {
            // Attempt to set max_execution_time to MAX_STREAM_TIME seconds
            $setSuccess = ini_set('max_execution_time', static::MAX_STREAM_TIME);
            $currentMaxExecutionTime = (int) ini_get('max_execution_time');

            if ($setSuccess !== false && $currentMaxExecutionTime === static::MAX_STREAM_TIME) {
                $maxDuration = static::MAX_STREAM_TIME - 5; // (MAX_STREAM_TIME - 5) seconds with buffer
            } else {
                // Failsafe: Use min(original max_execution_time, MAX_STREAM_TIME) - 5
                $maxDuration = $originalMaxExecutionTime > 0 && $originalMaxExecutionTime <= static::MAX_STREAM_TIME
                    ? $originalMaxExecutionTime - 5
                    : static::MAX_STREAM_TIME - 5;
            }
        }
    
        $maxDuration = max(1, $maxDuration);
        $startTime = time();

        $response = new StreamedResponse(function () use ($submission, $request, $startTime, $maxDuration, $originalMaxExecutionTime, $setSuccess, $currentMaxExecutionTime) {

            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            ob_implicit_flush(true);

            // Send initial headers
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            header('X-Cache: MISS');
            flush();

            // Send maxDuration and iniSetFailed flag to client
            echo "data: " . json_encode([
                'maxDuration' => $maxDuration,
                'iniSetFailed' => !(
                    $originalMaxExecutionTime === 0 || 
                    $originalMaxExecutionTime >= static::MAX_STREAM_TIME
                ) && (
                    $setSuccess === false || 
                    $currentMaxExecutionTime !== static::MAX_STREAM_TIME
                )
            ]) . "\n\n";
            flush();

            echo ": init\n\n";
            flush();

            while (true) {
                if ((time() - $startTime) >= $maxDuration) {
                    echo "event: stream_end\ndata: {\"message\": \"Stream ended after {$maxDuration} seconds\"}\n\n";
                    flush();
                    break;
                }

                $data = $this->getPlagiarismStatusData($submission, $request);

                echo "data: " . json_encode($data) . "\n\n";
                
                // Pad output to ensure immediate flush (for some servers)
                echo str_pad("", 4096) . "\n";

                flush();

                sleep(10);

                if (connection_aborted()) {
                    break;
                }
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('X-Cache', 'MISS');

        return $response;
    }

    /**
     * Get the structured plagiarism status data
     */
    public function getPlagiarismStatusData(Submission $submission, PKPRequest $request): array
    {
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

        return [
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
        ];
    }
}
