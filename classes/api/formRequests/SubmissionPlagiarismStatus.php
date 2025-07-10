<?php

/**
 * @file plugins/generic/plagiarism/classes/api/formRequests/SubmissionPlagiarismStatus.php
 *
 * Copyright (c) 2013-2025 Simon Fraser University
 * Copyright (c) 2013-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SubmissionPlagiarismStatus
 *
 * @brief  Form request to validate the submission plagiarism status API request
 */

namespace APP\plugins\generic\plagiarism\classes\api\formRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmissionPlagiarismStatus extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'submissionId' => [
                'required',
                'integer',
            ],
            'stageId' => [
                'required',
                'integer',
                Rule::in([ // probably replace with Application::getApplicationStages()
                    WORKFLOW_STAGE_ID_PUBLISHED,
                    WORKFLOW_STAGE_ID_SUBMISSION,
                    WORKFLOW_STAGE_ID_INTERNAL_REVIEW,
                    WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
                    WORKFLOW_STAGE_ID_EDITING,
                    WORKFLOW_STAGE_ID_PRODUCTION,
                ])
            ]
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'submissionId' => $this->route('submissionId'),
        ]);
    }
}
