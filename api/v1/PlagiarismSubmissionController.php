<?php

namespace APP\plugins\generic\plagiarism\api\v1;

use APP\facades\Repo;
use PKP\security\Role;
use PKP\core\PKPRequest;
use APP\core\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use APP\API\v1\submissions\SubmissionController;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;

class PlagiarismSubmissionController extends SubmissionController
{
    protected PlagiarismPlugin $plugin;

    public function __construct(PlagiarismPlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * @copydoc \PKP\API\v1\submissions\PKPSubmissionFileController::authorize()
     */
    public function authorize(PKPRequest $request, array &$args, array $roleAssignments): bool
    {
        if (!parent::authorize($request, $args, $roleAssignments)) {
            return false;
        }

        $illuminateRequest = $args[0]; /** @var \Illuminate\Http\Request $illuminateRequest */
        $actionName = static::getRouteActionName($illuminateRequest);

        if (!in_array($actionName, ['submissionPlagiarismStatus', 'submissionFilePlagiarismStatus'])) {
            return true;
        }

        return $this->plugin->isServiceAccessAvailable();
    }

    public function getGroupRoutes(): void
    {
        Route::middleware([
            self::roleAuthorizer([
                Role::ROLE_ID_MANAGER,
                Role::ROLE_ID_SITE_ADMIN,
                Role::ROLE_ID_SUB_EDITOR,
                Role::ROLE_ID_ASSISTANT,
            ]),
        ])->group(function () {

            Route::get('plagiarism/status', $this->submissionPlagiarismStatus(...))
                ->name('submission.plagiarism.status');

        })->whereNumber('submissionId');

        parent::getGroupRoutes();
    }

    public function submissionPlagiarismStatus(Request $illuminateRequest): JsonResponse
    {
        $submission = Repo::submissionFile()->get($illuminateRequest->route('submissionId'));

        if (!$submission) {
            return response()->json([
                'message' => 'Submission or user not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $request = $this->getRequest();

        $user = Repo::user()->get($request->getUser()->getId());

        return response()->json([
            'submission' => [
                'ithenticateEulaVersion' => $submission->getData('ithenticateEulaVersion'),
                'ithenticateSubmissionCompletedAt' => $submission->getData('ithenticateSubmissionCompletedAt'),
                'ithenticateEulaUrl' => $submission->getData('ithenticateEulaUrl'),
            ],
            'user' => [
                'ithenticateEulaVersion' => $user->getData('ithenticateEulaVersion'),
                'ithenticateEulaConfirmedAt' => $user->getData('ithenticateEulaConfirmedAt'),
            ],
        ], Response::HTTP_OK);
    }
}
