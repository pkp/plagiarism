<?php

/**
 * @file plugins/generic/plagiarism/classes/DummyWebhookManager.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DummyWebhookManager
 *
 * @brief Simulates iThenticate webhook behavior for local testing by polling
 *        for pending files and triggering webhook processing
 */

namespace APP\plugins\generic\plagiarism\classes;

use APP\core\Application;
use APP\plugins\generic\plagiarism\TestIthenticate;
use APP\facades\Repo;
use APP\plugins\generic\plagiarism\IThenticate;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;
use PKP\submissionFile\SubmissionFile;

class DummyWebhookManager
{
    public const DEFAULT_MAX_CYCLES = 10;
    public const DEFAULT_CYCLE_INTERVAL = 30;

    /**
     * The context to process
     */
    protected Context $context;

    /**
     * The plagiarism plugin instance
     */
    protected PlagiarismPlugin $plugin;

    /**
     * The iThenticate service instance
     */
    protected IThenticate|TestIthenticate $ithenticate;

    /**
     * @var callable Output callback for logging
     */
    protected $outputCallback;

    /**
     * Verbose mode flag
     */
    protected bool $verbose = false;

    /**
     * Dry run mode (don't actually process)
     */
    protected bool $dryRun = false;

    /**
     * Maximum files to process per cycle (rate limiting)
     */
    protected int $maxProcessPerCycle = 50;

    /**
     * Cycle counter for tracking
     */
    protected int $cycleCount = 0;

    /**
     * Flag to control daemon loop
     */
    protected bool $shouldContinue = true;

    /**
     * Maximum cycles before automatic shutdown (0 = unlimited)
     */
    protected int $maxCycles = self::DEFAULT_MAX_CYCLES;

    /**
     * Constructor
     *
     * @param Context $context The context to process webhooks for
     * @param PlagiarismPlugin $plugin The plugin instance
     * @param IThenticate|TestIthenticate $ithenticate The iThenticate service instance
     * @param callable|null $outputCallback Callback for output (receives string messages)
     */
    public function __construct(
        Context $context,
        PlagiarismPlugin $plugin,
        IThenticate|TestIthenticate $ithenticate,
        ?callable $outputCallback = null
    ) {
        $this->context = $context;
        $this->plugin = $plugin;
        $this->ithenticate = $ithenticate;
        
        // Default callback just echoes messages with a newline for clarity
        $this->outputCallback = $outputCallback ?? function ($msg): void {
            echo $msg . PHP_EOL;
        };

        // Verify prerequisites
        $this->validatePrerequisites();

        // Setup signal handlers for graceful shutdown
        $this->setupSignalHandlers();
    }

    /**
     * Set verbose mode
     */
    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    /**
     * Set dry run mode
     */
    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    /**
     * Set maximum files to process per cycle
     */
    public function setMaxProcessPerCycle(int $max): self
    {
        $this->maxProcessPerCycle = $max;
        return $this;
    }

    /**
     * Set maximum cycles before automatic shutdown
     *
     * @param int $maxCycles Maximum number of cycles (0 = unlimited)
     */
    public function setMaxCycles(int $maxCycles): self
    {
        $this->maxCycles = $maxCycles;
        return $this;
    }

    /**
     * Main daemon run method
     *
     * @param int $interval Seconds between polling cycles
     * @param bool $once Run once and exit (no loop)
     * @param int|null $submissionId Optional specific submission ID to process
     */
    public function run(
        int $interval = self::DEFAULT_CYCLE_INTERVAL,
        bool $once = false,
        ?int $submissionId = null
    ): void
    {
        $this->log("Webhook Daemon Started", true);
        $this->log("Context: {$this->context->getId()} ({$this->context->getLocalizedName()})", true);
        $this->log("Test mode: " . ($this->isTestMode() ? 'ENABLED' : 'DISABLED'), true);
        $this->log("Polling interval: {$interval} seconds", true);
        $this->log("Dry run: " . ($this->dryRun ? 'YES' : 'NO'), true);
        if ($this->maxCycles > 0) {
            $this->log("Max cycles: {$this->maxCycles}", true);
        }
        if ($submissionId) {
            $this->log("Filtering to submission ID: {$submissionId}", true);
        }
        $this->log("Press Ctrl+C to stop", true);
        $this->log(str_repeat('-', 60), true);

        do {
            $this->cycleCount++;
            $this->log("", true);
            $this->log("Cycle {$this->cycleCount}" . ($this->maxCycles > 0 ? "/{$this->maxCycles}" : "") . ": Scanning for pending files...", true);

            try {
                $this->processCycle($submissionId);
            } catch (\Throwable $e) {
                $this->log("ERROR in cycle {$this->cycleCount}: " . $e->getMessage(), true);
                $this->log("Stack trace: " . $e->getTraceAsString());
            }

            if ($once) {
                $this->log("Single cycle complete. Exiting.", true);
                break;
            }

            // Check if max cycles reached
            if ($this->maxCycles > 0 && $this->cycleCount >= $this->maxCycles) {
                $this->log("", true);
                $this->log("Maximum cycles ({$this->maxCycles}) reached. Exiting.", true);
                break;
            }

            if ($this->shouldContinue) {
                $this->log("Cycle {$this->cycleCount} complete. Sleeping for {$interval} seconds...", true);

                // Sleep with signal handling check
                $this->interruptibleSleep($interval);
            }

        } while ($this->shouldContinue);

        $this->log("Webhook Daemon Stopped", true);
    }

    /**
     * Process one polling cycle
     */
    protected function processCycle(?int $submissionId): void
    {
        // Find files awaiting SUBMISSION_COMPLETE
        $filesAwaitingAcceptance = $this->getFilesAwaitingSubmissionComplete($submissionId);
        $this->log("Found " . count($filesAwaitingAcceptance) . " file(s) awaiting SUBMISSION_COMPLETE", true);

        // Find files awaiting SIMILARITY_COMPLETE
        $filesAwaitingSimilarity = $this->getFilesAwaitingSimilarityComplete($submissionId);
        $this->log("Found " . count($filesAwaitingSimilarity) . " file(s) awaiting SIMILARITY_COMPLETE", true);

        $totalProcessed = 0;

        // Process SUBMISSION_COMPLETE events
        foreach ($filesAwaitingAcceptance as $file) {
            if ($totalProcessed >= $this->maxProcessPerCycle) {
                $this->log("Rate limit reached ({$this->maxProcessPerCycle}). Remaining files will be processed in next cycle.", true);
                break;
            }

            $this->processSubmissionCompleteEvent($file);
            $totalProcessed++;
        }

        // Process SIMILARITY_COMPLETE events
        foreach ($filesAwaitingSimilarity as $file) {
            if ($totalProcessed >= $this->maxProcessPerCycle) {
                $this->log("Rate limit reached ({$this->maxProcessPerCycle}). Remaining files will be processed in next cycle.", true);
                break;
            }

            $this->processSimilarityCompleteEvent($file);
            $totalProcessed++;
        }

        $this->log("Processed {$totalProcessed} file(s) in this cycle", true);
    }

    /**
     * Get files awaiting SUBMISSION_COMPLETE event
     */
    protected function getFilesAwaitingSubmissionComplete(?int $submissionId): array
    {
        // Build optimized SQL query to find matching submission_file_ids
        $query = DB::table('submission_file_settings as sfs_id')
            ->select('sfs_id.submission_file_id')
            ->join('submission_files as sf', 'sf.submission_file_id', '=', 'sfs_id.submission_file_id')
            ->join('submissions as s', 's.submission_id', '=', 'sf.submission_id')

            // Must have ithenticateId starting with test prefix
            ->where('sfs_id.setting_name', 'ithenticateId')
            ->where('sfs_id.setting_value', 'LIKE', TestIthenticate::ITHENTICATE_SUBMISSION_UUID_PREFIX . '%')

            // Must belong to this context
            ->where('s.context_id', $this->context->getId())

            // Must NOT have been accepted yet
            ->whereNotExists(function ($subQuery) {
                $subQuery->select(DB::raw(1))
                    ->from('submission_file_settings as sfs_accepted')
                    ->whereColumn('sfs_accepted.submission_file_id', 'sfs_id.submission_file_id')
                    ->where('sfs_accepted.setting_name', 'ithenticateSubmissionAcceptedAt');
            })

            // Must NOT be scheduled yet (or scheduled = 0)
            ->where(function ($orQuery) {
                // Case 1: No ithenticateSimilarityScheduled setting exists
                $orQuery->whereNotExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))
                        ->from('submission_file_settings as sfs_scheduled')
                        ->whereColumn('sfs_scheduled.submission_file_id', 'sfs_id.submission_file_id')
                        ->where('sfs_scheduled.setting_name', 'ithenticateSimilarityScheduled');
                })
                // Case 2: Or it exists but is set to '0'
                ->orWhereExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))
                        ->from('submission_file_settings as sfs_scheduled')
                        ->whereColumn('sfs_scheduled.submission_file_id', 'sfs_id.submission_file_id')
                        ->where('sfs_scheduled.setting_name', 'ithenticateSimilarityScheduled')
                        ->where('sfs_scheduled.setting_value', '0');
                });
            });

        // Optional filter by specific submission
        if ($submissionId) {
            $query->where('s.submission_id', $submissionId);
        }

        // Execute query and get file IDs
        $fileIds = $query->pluck('submission_file_id')->toArray();

        if (empty($fileIds)) {
            return [];
        }

        // Batch load only the matched submission files
        $submissionFiles = Repo::submissionFile()
            ->getCollector()
            ->getQueryBuilder()
            ->whereIn('sf.submission_file_id', $fileIds)
            ->get();

        // Convert to SubmissionFile objects
        return $submissionFiles
            ->map(fn ($row) => Repo::submissionFile()->dao->fromRow($row))
            ->toArray();
    }

    /**
     * Get files awaiting SIMILARITY_COMPLETE event
     */
    protected function getFilesAwaitingSimilarityComplete(?int $submissionId): array
    {
        // Build optimized SQL query to find matching submission_file_ids
        $query = DB::table('submission_file_settings as sfs_id')
            ->select('sfs_id.submission_file_id')
            ->join('submission_files as sf', 'sf.submission_file_id', '=', 'sfs_id.submission_file_id')
            ->join('submissions as s', 's.submission_id', '=', 'sf.submission_id')

            // Must have ithenticateId starting with test prefix
            ->where('sfs_id.setting_name', 'ithenticateId')
            ->where('sfs_id.setting_value', 'LIKE', TestIthenticate::ITHENTICATE_SUBMISSION_UUID_PREFIX . '%')

            // Must belong to this context
            ->where('s.context_id', $this->context->getId())

            // Must be scheduled (ithenticateSimilarityScheduled = 1)
            ->whereExists(function ($subQuery) {
                $subQuery->select(DB::raw(1))
                    ->from('submission_file_settings as sfs_scheduled')
                    ->whereColumn('sfs_scheduled.submission_file_id', 'sfs_id.submission_file_id')
                    ->where('sfs_scheduled.setting_name', 'ithenticateSimilarityScheduled')
                    ->where('sfs_scheduled.setting_value', '1');
            })

            // Must NOT have result yet
            ->whereNotExists(function ($subQuery) {
                $subQuery->select(DB::raw(1))
                    ->from('submission_file_settings as sfs_result')
                    ->whereColumn('sfs_result.submission_file_id', 'sfs_id.submission_file_id')
                    ->where('sfs_result.setting_name', 'ithenticateSimilarityResult');
            });

        // Optional filter by specific submission
        if ($submissionId) {
            $query->where('s.submission_id', $submissionId);
        }

        // Execute query and get file IDs
        $fileIds = $query->pluck('submission_file_id')->toArray();

        if (empty($fileIds)) {
            return [];
        }

        // Batch load only the matched submission files
        $submissionFiles = Repo::submissionFile()
            ->getCollector()
            ->getQueryBuilder()
            ->whereIn('sf.submission_file_id', $fileIds)
            ->get();

        // Convert to SubmissionFile objects
        return $submissionFiles
            ->map(fn ($row) => Repo::submissionFile()->dao->fromRow($row))
            ->toArray();
    }

    /**
     * Process a SUBMISSION_COMPLETE event for a file
     */
    protected function processSubmissionCompleteEvent(SubmissionFile $file): void
    {
        $ithenticateId = $file->getData('ithenticateId');
        $this->log("", true);
        $this->log("Processing SUBMISSION_COMPLETE for file {$file->getId()}", true);
        $this->log("  iThenticate ID: {$ithenticateId}");

        if ($this->dryRun) {
            $this->log("  [DRY RUN] Would trigger SUBMISSION_COMPLETE webhook");
            return;
        }

        try {
            // Send webhook event to local handler
            $webhookUrl = $this->getWebhookUrl();
            $payload = json_encode([
                'id' => $ithenticateId,
                'status' => 'COMPLETE',
                'content_type' => 'application/pdf',
            ]);

            $response = Http::withHeaders([
                'X-Turnitin-EventType' => 'SUBMISSION_COMPLETE',
                'X-Turnitin-Signature' => hash_hmac('sha256', $payload, $this->context->getData('ithenticateWebhookSigningSecret')),
                'Content-Type' => 'application/json',
            ])->post($webhookUrl, json_decode($payload, true));

            if ($response->successful()) {
                $this->log("  ✓ SUBMISSION_COMPLETE event processed successfully", true);
            } else {
                $this->log("  ✗ Failed to process SUBMISSION_COMPLETE event (HTTP {$response->status()})", true);
                $this->log("    Response: " . $response->body());
            }

        } catch (\Throwable $e) {
            $this->log("  ✗ Exception processing SUBMISSION_COMPLETE: " . $e->getMessage(), true);
        }
    }

    /**
     * Process a SIMILARITY_COMPLETE event for a file
     */
    protected function processSimilarityCompleteEvent(SubmissionFile $file): void
    {
        $ithenticateId = $file->getData('ithenticateId');
        $this->log("", true);
        $this->log("Processing SIMILARITY_COMPLETE for file {$file->getId()}", true);
        $this->log("  iThenticate ID: {$ithenticateId}");

        if ($this->dryRun) {
            $this->log("  [DRY RUN] Would trigger SIMILARITY_COMPLETE webhook");
            return;
        }

        try {
            // Fetch similarity result from iThenticate
            $similarityResult = $this->ithenticate->getSimilarityResult($ithenticateId);

            if (!$similarityResult) {
                $this->log("  ⚠ Similarity result not yet available, will retry in next cycle");
                return;
            }

            // Send webhook event to local handler
            $webhookUrl = $this->getWebhookUrl();
            $resultData = json_decode($similarityResult, true);

            $payload = json_encode([
                'id' => $ithenticateId,
                'status' => $resultData['status'] ?? 'COMPLETE',
                'overall_match_percentage' => $resultData['overall_match_percentage'] ?? 0,
                'submission_id' => $resultData['submission_id'] ?? $ithenticateId,
            ]);

            $response = Http::withHeaders([
                'X-Turnitin-EventType' => 'SIMILARITY_COMPLETE',
                'X-Turnitin-Signature' => hash_hmac('sha256', $payload, $this->context->getData('ithenticateWebhookSigningSecret')),
                'Content-Type' => 'application/json',
            ])->post($webhookUrl, json_decode($payload, true));

            if ($response->successful()) {
                $matchPercentage = $resultData['overall_match_percentage'] ?? 0;
                $this->log("  ✓ SIMILARITY_COMPLETE event processed successfully ({$matchPercentage}% match)", true);
            } else {
                $this->log("  ✗ Failed to process SIMILARITY_COMPLETE event (HTTP {$response->status()})", true);
                $this->log("    Response: " . $response->body());
            }

        } catch (\Throwable $e) {
            $this->log("  ✗ Exception processing SIMILARITY_COMPLETE: " . $e->getMessage(), true);
        }
    }

    /**
     * Get the webhook URL for this context
     */
    protected function getWebhookUrl(): string
    {
        $request = Application::get()->getRequest();
        $dispatcher = $request->getDispatcher();

        return $dispatcher->url(
            $request,
            Application::ROUTE_COMPONENT,
            $this->context->getPath(),
            'plugins.generic.plagiarism.controllers.PlagiarismWebhookHandler',
            'handle'
        );
    }

    /**
     * Check if an ithenticateId is a test submission
     */
    protected function isTestSubmission(string $ithenticateId): bool
    {
        // Test IDs from TestIThenticate.php follow pattern: TestIthenticate::ITHENTICATE_SUBMISSION_UUID_PREFIX . '{hash}'
        return str_starts_with($ithenticateId, TestIthenticate::ITHENTICATE_SUBMISSION_UUID_PREFIX);
    }

    /**
     * Check if running in test mode
     */
    protected function isTestMode(): bool
    {
        return PlagiarismPlugin::isRunningInTestMode();
    }

    /**
     * Validate prerequisites for running the daemon
     */
    protected function validatePrerequisites(): void
    {
        // Check: Must be in test mode
        if (!$this->isTestMode()) {
            throw new Exception(
                'Webhook daemon only works in test mode. ' .
                'Set [ithenticate] test_mode = On in config.inc.php'
            );
        }

        // Check: Context must have webhook configured
        if (!$this->context->getData('ithenticateWebhookId')) {
            throw new Exception(
                "Context {$this->context->getId()} does not have a webhook configured. " .
                "Run: php plugins/generic/plagiarism/tools/webhook.php register --context={$this->context->getPath()}"
            );
        }

        // Check: Context must have webhook configured with signing secret set
        if (!$this->context->getData('ithenticateWebhookSigningSecret')) {
            throw new Exception(
                "Context {$this->context->getId()} does not have a webhook signing secret. " .
                "Run: php plugins/generic/plagiarism/tools/webhook.php register --context={$this->context->getPath()}"
            );
        }

        // Check: Plugin must be enabled
        if (!$this->plugin->getEnabled($this->context->getId())) {
            throw new Exception(
                "Plagiarism plugin is not enabled for context {$this->context->getId()}"
            );
        }
    }

    /**
     * Setup signal handlers for graceful shutdown
     */
    protected function setupSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            // PCNTL not available (Windows or disabled)
            return;
        }

        // Enable async signals (PHP 7.1+)
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        // Handle SIGINT (Ctrl+C)
        pcntl_signal(SIGINT, function () {
            $this->log("", true);
            $this->log("Received SIGINT (Ctrl+C), shutting down gracefully...", true);
            $this->shouldContinue = false;
        });

        // Handle SIGTERM
        pcntl_signal(SIGTERM, function () {
            $this->log("", true);
            $this->log("Received SIGTERM, shutting down gracefully...", true);
            $this->shouldContinue = false;
        });
    }

    /**
     * Interruptible sleep that checks for signals periodically
     *
     * @param int $seconds Number of seconds to sleep
     */
    protected function interruptibleSleep(int $seconds): void
    {
        // If pcntl not available, use regular sleep
        if (!function_exists('pcntl_signal_dispatch')) {
            sleep($seconds);
            return;
        }

        $sleepInterval = 1; // Check for signals every 1 second
        $elapsed = 0;

        while ($elapsed < $seconds && $this->shouldContinue) {
            sleep(min($sleepInterval, $seconds - $elapsed));
            $elapsed += $sleepInterval;

            // Dispatch pending signals
            pcntl_signal_dispatch();

            // If signal received, break out early
            if (!$this->shouldContinue) {
                break;
            }
        }
    }

    /**
     * Log a message
     */
    protected function log(string $message, bool $alwaysShow = false): void
    {
        if (!$this->verbose && !$alwaysShow) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = "[{$timestamp}] {$message}";

        call_user_func($this->outputCallback, $formattedMessage);
    }
}
