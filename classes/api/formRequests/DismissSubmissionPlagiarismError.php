<?php

/**
 * @file plugins/generic/plagiarism/classes/api/formRequests/DismissSubmissionPlagiarismError.php
 *
 * Copyright (c) 2013-2025 Simon Fraser University
 * Copyright (c) 2013-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DismissSubmissionPlagiarismError
 *
 * @brief  Form request to validate a submission-level plagiarism error dismissal request
 */

namespace APP\plugins\generic\plagiarism\classes\api\formRequests;

use Illuminate\Foundation\Http\FormRequest;

class DismissSubmissionPlagiarismError extends FormRequest
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
            'notificationId' => [
                'required',
                'integer',
            ],
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'submissionId' => $this->route('submissionId'),
        ]);
    }
}
