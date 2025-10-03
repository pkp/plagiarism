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
            $this->plugin->sendErrorMessage(__('plugins.generic.plagiarism.manager.settings.serviceAccessInvalid'));
            return;
        }
        
        $this->plugin->stampEulaToSubmission($event->context, $event->submission);
        $this->plugin->stampEulaToSubmittingUser(
            $event->context,
            $event->submission,
            Application::get()->getRequest()->getUser()
        );
        
        $this->plugin->submitForPlagiarismCheck(
            $event->context,
            Repo::submission()->get($event->submission->getId())
        );
    }
}
