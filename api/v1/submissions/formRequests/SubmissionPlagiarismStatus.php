<?php

/**
 * @file plugins/generic/plagiarism/api/v1/submissions/formRequests/SubmissionPlagiarismStatus.php
 *
 * Copyright (c) 2013-2025 Simon Fraser University
 * Copyright (c) 2013-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SubmissionPlagiarismStatus
 *
 * @brief  Form request to validate the submission plagiarism status API request
 */

namespace APP\plugins\generic\plagiarism\api\v1\submissions\formRequests;

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
            'stageId' => [
                'required',
                'integer',
                Rule::in([
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
}
