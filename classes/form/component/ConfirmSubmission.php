<?php

/**
 * @file plugins/generic/plagiarism/classes/form/component/ConfirmSubmission.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ConfirmSubmission
 *
 * @brief Override the core `ConfirmSubmission` form to attach EULA confirmation
 */

namespace APP\plugins\generic\plagiarism\classes\form\component;

use PKP\context\Context;
use PKP\components\forms\FieldOptions;
use PKP\components\forms\submission\ConfirmSubmission as PKPConfirmSubmission;

class ConfirmSubmission extends PKPConfirmSubmission
{
    /**
     * @copydoc \PKP\components\forms\submission\ConfirmSubmission::__construct
     **/
    public function __construct(string $action, Context $context, array $params)
    {
        parent::__construct($action, $context);

        $this->addField(new FieldOptions('confirmSubmissionEula', [
            'label' => __('plugins.generic.plagiarism.submission.eula.acceptance.confirm.label'),
            'description' => __('plugins.generic.plagiarism.submission.eula.acceptance.message', [
				'localizedEulaUrl' => $params['localizedEulaUrl'],
			]),
            'options' => [
                [
                    'value' => true,
                    'label' => __('plugins.generic.plagiarism.submission.eula.acceptance.confirm'),
                ],
            ],
            'value' => false,
        ]));
    }
}
