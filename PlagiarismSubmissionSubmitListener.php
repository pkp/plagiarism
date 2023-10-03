<?php

namespace APP\plugins\generic\plagiarism;

use PKP\observers\events\SubmissionSubmitted;
use Illuminate\Events\Dispatcher;

class PlagiarismSubmissionSubmitListener
{

    /** @var PlagiarismPlugin $plugin */
    private $plugin;

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
            PlagiarismSubmissionSubmitListener::class
        );
    }

    /**
     * Handle the listener call
     */
    public function handle(SubmissionSubmitted $event)
    {
        $this->plugin->sendSubmissionFiles($event->context, $event->submission);
    }
}
