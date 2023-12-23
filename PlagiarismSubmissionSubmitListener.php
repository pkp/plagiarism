<?php

/**
 * @file plugins/generic/plagiarism/PlagiarismSubmissionSubmitListener.php
 *
 * Copyright (c) 2013-2023 Simon Fraser University
 * Copyright (c) 2013-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PlagiarismSubmissionSubmitListener
 *
 * @brief  Listener class to handle event on submission being submitted
 */

namespace APP\plugins\generic\plagiarism;

use PKP\observers\events\SubmissionSubmitted;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;
use Illuminate\Events\Dispatcher;

class PlagiarismSubmissionSubmitListener
{
    protected PlagiarismPlugin $plugin;

    public function __construct(PlagiarismPlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Maps methods with correspondent events to listen
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(
            SubmissionSubmitted::class,
            [static::class, 'handle']
        );
    }

    /**
     * Handle the listener call
     */
    public function handle(SubmissionSubmitted $event): void
    {
        $this->plugin->sendSubmissionFiles($event->context, $event->submission);
    }
}
