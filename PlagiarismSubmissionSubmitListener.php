<?php

/**
 * @file plugins/generic/plagiarism/PlagiarismSubmissionSubmitListener.php
 *
 * Copyright (c) 2013-2024 Simon Fraser University
 * Copyright (c) 2013-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PlagiarismSubmissionSubmitListener
 *
 * @brief  Listener class to handle event on submission being submitted
 */

namespace APP\plugins\generic\plagiarism;

use APP\facades\Repo;
use PKP\observers\events\SubmissionSubmitted;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;
use Illuminate\Events\Dispatcher;
use PKP\user\User;

class PlagiarismSubmissionSubmitListener
{
    protected PlagiarismPlugin $plugin;
    
    protected User $user;
    
    public function __construct(PlagiarismPlugin $plugin, User $user)
    {
        $this->plugin = $plugin;
        $this->user = $user;
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
        $this->plugin->stampEulaToSubmission($event->context, $event->submission);
        $this->plugin->stampEulaToSubmittingUser($event->context, $event->submission, $this->user);
        
        $this->plugin->submitForPlagiarismCheck(
            $event->context,
            Repo::submission()->get($event->submission->getId())
        );
    }
}
