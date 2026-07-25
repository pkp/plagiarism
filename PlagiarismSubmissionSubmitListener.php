<?php

/**
 * @file plugins/generic/plagiarism/PlagiarismSubmissionSubmitListener.php
 *
 * Copyright (c) 2013-2025 Simon Fraser University
 * Copyright (c) 2013-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PlagiarismSubmissionSubmitListener
 *
 * @brief  Listener class to handle event on submission being submitted
 */

namespace APP\plugins\generic\plagiarism;

use APP\core\Application;
use APP\facades\Repo;
use PKP\observers\events\SubmissionSubmitted;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;
use APP\plugins\generic\plagiarism\classes\PlagiarismErrorFormatter;
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
        if (!$this->plugin->isServiceAccessAvailable($event->context)) {
            $this->plugin->recordSubmissionError(
                $event->submission,
                PlagiarismErrorFormatter::make('plugins.generic.plagiarism.manager.settings.serviceAccessInvalid')
            );
            return;
        }

        // Auto-submission disabled --> should not do any stamping as no confirmation option present
        // as auto sent of submission files are disable at the time of submitting submission from the 
        // submission wizard.
        if ($this->plugin->hasAutoSubmissionDisabled($event->context)) {
            return;
        }

        $user = Application::get()->getRequest()->getUser();
        if (!$user) {
            error_log('Plagiarism: SubmissionSubmitted has no request user (e.g. CLI import); skipping EULA stamping/upload.');
            return;
        }

        $this->plugin->stampEulaToSubmission($event->context, $event->submission);
        $this->plugin->stampEulaToSubmittingUser(
            $event->context,
            Repo::submission()->get($event->submission->getId()), // refetch the submission after latest EULA stamp
            $user
        );
        
        $this->plugin->submitForPlagiarismCheck(
            $event->context,
            Repo::submission()->get($event->submission->getId())
        );
    }
}
