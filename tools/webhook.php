<?php

/**
 * @file plugins/generic/plagiarism/tools/webhook.php
 *
 * Copyright (c) 2025 Simon Fraser University
 * Copyright (c) 2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class RegisterWebhooks
 *
 * @brief CLI tools to update iThenticate webhooks for all Journals/Presses/Servers
 */

namespace APP\plugins\generic\plagiarism\tools;

use APP\core\Application;
use APP\plugins\generic\plagiarism\classes\DummyWebhookManager;
use APP\plugins\generic\plagiarism\IThenticate;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;
use APP\plugins\generic\plagiarism\TestIThenticate;
use Exception;
use Illuminate\Support\Facades\Http;
use PKP\cliTool\CommandInterface;
use PKP\cliTool\CommandLineTool;
use PKP\cliTool\traits\HasCommandInterface;
use PKP\cliTool\traits\HasParameterList;
use PKP\context\Context;
use PKP\context\ContextDAO;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\InvalidArgumentException as CommandInvalidArgumentException;
use Throwable;

error_reporting(E_ALL & ~E_DEPRECATED);

define('APP_ROOT', dirname(__FILE__, 5));
require_once APP_ROOT . '/tools/bootstrap.php';

class Webhook extends CommandLineTool
{
    use HasParameterList;
    use HasCommandInterface;

    public const REACHABILITY_TIMEOUT = 10;

    protected const AVAILABLE_OPTIONS = [
        'register' => 'plugins.generic.plagiarism.tools.registerWebhooks.register.description',
        'update' => 'plugins.generic.plagiarism.tools.registerWebhooks.update.description',
        'delete' => 'plugins.generic.plagiarism.tools.registerWebhooks.delete.description',
        'validate' => 'plugins.generic.plagiarism.tools.registerWebhooks.validate.description',
        'list' => 'plugins.generic.plagiarism.tools.registerWebhooks.list.description',
        'work' => 'plugins.generic.plagiarism.tools.registerWebhooks.work.description',
        'usage' => 'plugins.generic.plagiarism.tools.registerWebhooks.usage.description',
    ];

    /**
     * @var null|string Which option will be call?
     */
    protected ?string $option = null;

    /**
     * @var Context The context for given context path or id
     */
    protected Context $context;

    /**
     * @var PlagiarismPlugin The plagiarism plugin instance
     */
    protected PlagiarismPlugin $plagiarismPlugin;

    /**
     * @var IThenticate|TestIThenticate The iThenticate service instance
     */
    protected IThenticate|TestIThenticate $ithenticate;

    /**
     * Constructor.
     *
     * @param array $argv command-line arguments
     */
    public function __construct($argv = [])
    {
        parent::__construct($argv);

        array_shift($argv);

        $this->setParameterList($argv);

        if (!isset($this->getParameterList()[0])) {
            throw new CommandNotFoundException(
                __('plugins.generic.plagiarism.tools.registerWebhooks.empty.option'),
                array_keys(self::AVAILABLE_OPTIONS)
            );
        }

        $this->option = $this->getParameterList()[0];

        $this->setCommandInterface();
    }

    /**
     * Parse and execute the command
     */
    public function execute()
    {
        if (!isset(self::AVAILABLE_OPTIONS[$this->option])) {
            throw new CommandNotFoundException(
                __(
                    'plugins.generic.plagiarism.tools.registerWebhooks.option.doesnt.exists',
                    ['option' => $this->option]
                ),
                array_keys(self::AVAILABLE_OPTIONS)
            );
        }

        $isIncludeApi = $this->isIncludeApi();
        $hasExplicitCreds = $this->hasExplicitApiCredentials();
        $hasContext = $this->getParameterValue('context') !== null;
        $hasWebhookId = $this->getParameterValue('webhook-id') !== null;

        // Commands that ALWAYS need --context
        $alwaysNeedContext = ['register', 'update', 'work'];

        // Commands that need --context UNLESS --include-api with explicit creds + webhook-id
        $conditionalContext = ['delete', 'validate'];

        // Determine if context is required
        $contextRequired = false;
        if (in_array($this->option, $alwaysNeedContext)) {
            $contextRequired = true;
        } elseif (in_array($this->option, $conditionalContext)) {
            $contextRequired = !($isIncludeApi && $hasExplicitCreds && $hasWebhookId);
        } elseif ($this->option === 'list' && $isIncludeApi) {
            $contextRequired = !$hasExplicitCreds;
        }

        if ($contextRequired && !$hasContext) {
            throw new CommandNotFoundException(
                __(
                    'plugins.generic.plagiarism.tools.registerWebhooks.required.parameters.missing',
                    ['parameter' => 'context', 'command' => $this->option]
                ),
                [__('plugins.generic.plagiarism.tools.registerWebhooks.required.parameters.missing.example', ['command' => $this->option])]
            );
        }

        // Load context if provided
        if ($hasContext) {
            $this->context = $this->getContext();
        }

        // Determine if API access is needed
        $needsApi = in_array($this->option, ['register', 'update', 'delete', 'validate', 'work'])
            || ($this->option === 'list' && $isIncludeApi);

        if ($needsApi) {
            $this->plagiarismPlugin = new PlagiarismPlugin();
            $this->initIthenticate();
        }

        $this->{$this->option}();
    }

    /**
     * Check if the --include-api flag is set
     */
    protected function isIncludeApi(): bool
    {
        return $this->hasFlagSet('--include-api');
    }

    /**
     * Check if explicit API credentials are provided via --api-url and --api-key
     */
    protected function hasExplicitApiCredentials(): bool
    {
        return $this->getParameterValue('api-url') !== null
            && $this->getParameterValue('api-key') !== null;
    }

    /**
     * Resolve the webhook ID for the current context
     *
     * Uses a tiered approach:
     * 1. Explicit --webhook-id parameter (highest priority)
     * 2. If --include-api and context available, find by URL at API
     * 3. Fall back to DB-stored webhook ID
     */
    protected function findWebhookIdForContext(): ?string
    {
        // 1. Explicit --webhook-id always wins
        $explicitWebhookId = $this->getParameterValue('webhook-id');
        if ($explicitWebhookId) {
            return $explicitWebhookId;
        }

        // 2. If --include-api and context available, find by URL at API
        if ($this->isIncludeApi() && isset($this->context)) {
            $webhookUrl = $this->plagiarismPlugin->getWebhookUrl($this->context);
            $apiWebhookId = $this->ithenticate->findWebhookIdByUrl($webhookUrl);
            if ($apiWebhookId) {
                return $apiWebhookId;
            }
        }

        // 3. Fall back to DB
        if (isset($this->context)) {
            return $this->context->getData('ithenticateWebhookId');
        }

        return null;
    }

    /**
     * Get the context for given context path or id
     */
    protected function getContext(): Context
    {
        $contextPathOrId = $this->getParameterValue('context');
        $contextDao = Application::getContextDAO(); /** @var ContextDAO $contextDao */

        /** @var Context $context */
        $context = $contextDao->getByPath((string)$contextPathOrId) ?? $contextDao->getById((int)$contextPathOrId);

        if (!$context) {
            throw new CommandInvalidArgumentException(
                __(
                    'plugins.generic.plagiarism.tools.registerWebhooks.context.not.found',
                    ['contextPathOrId' => $contextPathOrId]
                )
            );
        }

        return $context;
    }

    /**
     * Validate the required parameters
     */
    protected function validateRequiredParameters(): bool
    {
        if ($this->getParameterValue('context') === null) {
            return false;
        }

        return true;
    }

    /**
     * Get plugin version from version.xml file
     *
     * @throws Exception If the version.xml file is not found or cannot be read
     *
     * @return string The plugin version from the release tag
     *
     */
    protected function getPluginVersion(): ?string
    {
        // Go up one level from tools/ to plugin root directory
        $versionFile = dirname(__FILE__, 1) . '/../version.xml';

        if (!file_exists($versionFile)) {
            error_log("Plugin version file not found: {$versionFile}");
            return null;
        }

        try {
            $xmlContent = file_get_contents($versionFile);

            if ($xmlContent === false) {
                throw new Exception('Failed to read version.xml file');
            }

            $xml = simplexml_load_string($xmlContent);

            if ($xml === false) {
                throw new Exception('Failed to parse version.xml');
            }

            $version = (string)$xml->release;

            if (empty($version)) {
                throw new Exception('No release tag found in version.xml');
            }

            return $version;

        } catch (Throwable $e) {
            error_log("Failed to read plugin version from {$versionFile}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Initialize the iThenticate service
     *
     * Supports both explicit credentials (--api-url/--api-key) and context-based credentials.
     * Explicit credentials take priority when provided.
     */
    protected function initIthenticate(): void
    {
        $apiUrl = $this->getParameterValue('api-url');
        $apiKey = $this->getParameterValue('api-key');

        // If explicit creds not provided, get from context
        if (!$apiUrl || !$apiKey) {
            if (!isset($this->context)) {
                throw new CommandInvalidArgumentException(
                    __('plugins.generic.plagiarism.tools.registerWebhooks.credentials.missing')
                );
            }

            if (!$this->plagiarismPlugin->isServiceAccessAvailable($this->context)) {
                throw new CommandInvalidArgumentException(
                    __('plugins.generic.plagiarism.manager.settings.serviceAccessMissing')
                );
            }

            [$apiUrl, $apiKey] = $this->plagiarismPlugin->getServiceAccess($this->context);
        }

        // Get plugin version from version.xml for CLI context
        $pluginVersion = $this->getPluginVersion();

        // Initialize iThenticate with explicit version
        $this->ithenticate = $this->plagiarismPlugin->initIthenticate(
            $apiUrl,
            $apiKey,
            PlagiarismPlugin::PLUGIN_INTEGRATION_NAME,
            $pluginVersion
        );

        if (!$this->ithenticate->validateAccess()) {
            throw new CommandInvalidArgumentException(
                __('plugins.generic.plagiarism.manager.settings.serviceAccessInvalid')
            );
        }
    }

    /**
     * Display the full API response details for debugging
     *
     * @param string|null $errorMessage Optional custom error message to display
     */
    protected function displayApiResponseDetails(?string $errorMessage = null): void
    {
        $errorMessage ??= __('plugins.generic.plagiarism.tools.registerWebhooks.error');
        $this->getCommandInterface()->getOutput()->newLine();
        $this->getCommandInterface()->getOutput()->error($errorMessage);

        $responseDetails = $this->ithenticate->getLastResponseDetails();
        if (!$responseDetails) {
            $this->getCommandInterface()->getOutput()->writeln(
                '<fg=red>No response details available (request may not have been made)</>'
            );
            return;
        }

        $this->getCommandInterface()->getOutput()->section('Full API Response Details');

        // Display status code and reason
        $this->getCommandInterface()->getOutput()->writeln(
            sprintf(
                '<fg=yellow>Status Code:</>  %d %s',
                $responseDetails['status_code'],
                $responseDetails['reason']
            )
        );

        // Display response body
        $this->getCommandInterface()->getOutput()->newLine();
        $this->getCommandInterface()->getOutput()->writeln('<fg=yellow>Response Body:</>');
        $bodyContent = $responseDetails['body'];

        // Try to pretty-print JSON if it's valid JSON
        $decodedBody = json_decode($bodyContent, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $this->getCommandInterface()->getOutput()->writeln(
                json_encode($decodedBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        } else {
            $this->getCommandInterface()->getOutput()->writeln($bodyContent);
        }

        // Display response headers
        $this->getCommandInterface()->getOutput()->newLine();
        $this->getCommandInterface()->getOutput()->writeln('<fg=yellow>Response Headers:</>');
        foreach ($responseDetails['headers'] as $header => $values) {
            $headerValues = is_array($values) ? implode(', ', $values) : $values;
            $this->getCommandInterface()->getOutput()->writeln(
                sprintf('  <fg=cyan>%s:</> %s', $header, $headerValues)
            );
        }
    }

    /**
     * Delete the iThenticate webhook for given context
     */
    public function delete(): bool
    {
        $webhookId = $this->findWebhookIdForContext();

        if (!$webhookId) {
            if ($this->isIncludeApi()) {
                $this->getCommandInterface()->getOutput()->info(
                    __('plugins.generic.plagiarism.tools.registerWebhooks.webhook.includeApi.nothingToDelete')
                );
            } else {
                $this->getCommandInterface()->getOutput()->info(
                    __('plugins.generic.plagiarism.tools.registerWebhooks.webhook.nothingToDelete')
                );
            }
            return true;
        }

        if (!$this->ithenticate->deleteWebhook($webhookId)) {
            // Check if webhook simply doesn't exist at API (404 — already deleted)
            $responseDetails = $this->ithenticate->getLastResponseDetails();
            $isNotFound = $responseDetails && ($responseDetails['status_code'] ?? 0) === 404;

            if ($isNotFound) {
                // Webhook already gone from API — warn and continue to clean up DB
                $this->getCommandInterface()->getOutput()->warning(
                    __(
                        'plugins.generic.plagiarism.tools.registerWebhooks.webhook.deleted.notFoundAtApi',
                        ['webhookId' => $webhookId]
                    )
                );
            } elseif (!$this->hasFlagSet('--force')) {
                // Real API failure — error and abort (unless --force)
                $this->getCommandInterface()->getOutput()->error(
                    __(
                        'plugins.generic.plagiarism.tools.registerWebhooks.webhook.deleted.error',
                        [
                            'webhookId' => $webhookId,
                            'contextId' => isset($this->context) ? $this->context->getId() : 'N/A'
                        ]
                    )
                );

                $this->displayApiResponseDetails("Deleting ithenticate webhook id : {$webhookId} failed via iThenticate API");

                return false;
            }
        }

        // Clear DB only if the deleted webhook ID matches what's stored
        if (isset($this->context) && $this->context->getData('ithenticateWebhookId')) {
            $dbWebhookId = $this->context->getData('ithenticateWebhookId');

            if ($dbWebhookId === $webhookId) {
                $contextService = app()->get('context'); /** @var \APP\services\ContextService $contextService */
                $this->context = $contextService->edit($this->context, [
                    'ithenticateWebhookSigningSecret' => null,
                    'ithenticateWebhookId' => null
                ], Application::get()->getRequest());
            } else {
                // Deleted webhook differs from DB record — preserve DB
                $this->getCommandInterface()->getOutput()->info(
                    __(
                        'plugins.generic.plagiarism.tools.registerWebhooks.webhook.deleted.dbPreserved',
                        ['deletedId' => $webhookId, 'dbId' => $dbWebhookId]
                    )
                );
            }
        }

        $this->getCommandInterface()->getOutput()->success(
            __(
                'plugins.generic.plagiarism.tools.registerWebhooks.webhook.deleted.success',
                [
                    'webhookId' => $webhookId,
                    'contextId' => isset($this->context) ? $this->context->getId() : 'N/A'
                ]
            )
        );

        return true;
    }

    /**
     * Print command usage information.
     */
    public function usage(): void
    {
        $this->getCommandInterface()->line('<comment>' . __('admin.cli.tool.usage.title') . '</comment>');
        $this->getCommandInterface()->line(__('admin.cli.tool.usage.parameters') . PHP_EOL);
        $this->getCommandInterface()->line('<comment>' . __('admin.cli.tool.available.commands', ['namespace' => 'webhook']) . '</comment>');

        $this->printCommandList(self::AVAILABLE_OPTIONS);
    }

    /**
     * Register a new iThenticate webhook for given context
     */
    public function register(): void
    {
        if ($this->isIncludeApi()) {
            // Check BOTH DB and API for existing webhook
            $webhookUrl = $this->plagiarismPlugin->getWebhookUrl($this->context);
            $apiWebhookId = $this->ithenticate->findWebhookIdByUrl($webhookUrl);
            $dbWebhookId = $this->context->getData('ithenticateWebhookId');

            if ($dbWebhookId && $apiWebhookId) {
                if ($dbWebhookId === $apiWebhookId) {
                    // Healthy — same webhook in both DB and API
                    $this->getCommandInterface()->getOutput()->warning(
                        __('plugins.generic.plagiarism.tools.registerWebhooks.webhook.already.configured')
                    );
                    return;
                }

                // Mismatch — DB points to different webhook than API URL match
                $this->getCommandInterface()->getOutput()->warning(
                    __(
                        'plugins.generic.plagiarism.tools.registerWebhooks.webhook.includeApi.dbApiMismatch',
                        ['dbId' => $dbWebhookId, 'apiId' => $apiWebhookId, 'url' => $webhookUrl]
                    )
                );
                return;
            }

            if (!$dbWebhookId && $apiWebhookId) {
                // Orphaned at API -- inform user
                $this->getCommandInterface()->getOutput()->warning(
                    __(
                        'plugins.generic.plagiarism.tools.registerWebhooks.webhook.includeApi.orphaned',
                        ['webhookId' => $apiWebhookId, 'url' => $webhookUrl]
                    )
                );
                return;
            }

            // If DB has it but API doesn't, or neither has it -- proceed to register
        } else {
            // DB-only check (current behavior)
            if ($this->context->getData('ithenticateWebhookId')) {
                $this->getCommandInterface()->getOutput()->warning(
                    __('plugins.generic.plagiarism.tools.registerWebhooks.webhook.already.configured')
                );
                return;
            }
        }

        $this->update();
    }

    /**
     * Update the iThenticate webhook for given context
     */
    public function update(): void
    {
        if (!$this->delete()) {
            $this->getCommandInterface()->getOutput()->error(__('plugins.generic.plagiarism.tools.registerWebhooks.error'));
            return;
        }

        $webhookUpdateStatus = $this->plagiarismPlugin->registerIthenticateWebhook(
            $this->ithenticate,
            $this->context
        );

        if ($webhookUpdateStatus) {
            $this->getCommandInterface()->getOutput()->success(__('plugins.generic.plagiarism.tools.registerWebhooks.success'));
            $this->validate();
            return;
        }

        // Display the detailed API response with reason of failure
        $this->displayApiResponseDetails('Failed Registering new webhook');
    }

    /**
     * Validate the iThenticate webhook for given context
     */
    public function validate(): bool
    {
        // Re-fetch the context to ensure the latest data is loaded
        if (isset($this->context)) {
            $this->context = $this->getContext();
        }

        $webhookId = $this->findWebhookIdForContext();

        if (!$webhookId) {
            $this->getCommandInterface()->getOutput()->error(
                __(
                    'plugins.generic.plagiarism.webhook.configuration.missing',
                    ['contextId' => isset($this->context) ? $this->context->getId() : 'N/A']
                )
            );
            return false;
        }

        $webhookResult = null;

        $validity = $this->ithenticate->validateWebhook($webhookId, $webhookResult);

        if ($webhookResult) {
            $webhookResult = json_decode($webhookResult, true);
            $collection = collect($webhookResult);
            $headers = $collection->keys()->all();
            $values = $collection->map(function ($value) {
                if (is_array($value)) {
                    return implode(', ', $value);
                } elseif (is_bool($value)) {
                    return $value ? 'true' : 'false';
                } else {
                    return (string) $value;
                }
            })->values()->all();

            // verify the reachability of the webhook URL
            array_push($headers, 'url reachable');
            try {
                $url = $webhookResult['url'];

                // Use HEAD for minimal bandwidth (checks existence/reachability without body)
                $response = Http::timeout(static::REACHABILITY_TIMEOUT)->head($url);

                // Fallback to GET if HEAD isn't supported by the server
                if (!$response->successful()) {
                    $response = Http::timeout(static::REACHABILITY_TIMEOUT)->get($url);
                }

                array_push($values, $response->successful() ? 'YES' : 'NO');

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                array_push($values, "FAILED - Message: {$e->getMessage()}");
            } catch (\Exception $e) {
                array_push($values, "FAILED - Message: {$e->getMessage()}");
            }

            $table = new \Symfony\Component\Console\Helper\Table($this->getCommandInterface()->getOutput());
            $rows = [];
            foreach ($headers as $index => $header) {
                $rows[] = [$header, $values[$index] ?? 'N/A'];
            }
            $table->setHeaders(['Property', 'Value']);
            $table->setRows($rows);
            $table->setColumnMaxWidth(1, 120);
            $table->render();
        }

        // If validation failed, display the full API response for debugging
        if (!$validity) {
            $this->displayApiResponseDetails("Failed validating webhook id : {$webhookId}");
        }

        // Inform user if the validated webhook ID differs from what's in DB
        if ($this->isIncludeApi() && isset($this->context)) {
            $dbWebhookId = $this->context->getData('ithenticateWebhookId');
            if ($dbWebhookId && $dbWebhookId !== $webhookId) {
                $this->getCommandInterface()->getOutput()->warning(
                    __(
                        'plugins.generic.plagiarism.tools.registerWebhooks.webhook.includeApi.validateDbMismatch',
                        ['validatedId' => $webhookId, 'dbId' => $dbWebhookId]
                    )
                );
            }
        }

        return $validity;
    }

    /**
     * List the iThenticate webhooks for all available and enabled contexts
     */
    public function list(): void
    {
        if ($this->isIncludeApi()) {
            $this->listApiWebhooks();
        }

        // DB-based listing (always runs when context is available or --include-api is not set)
        if (!$this->isIncludeApi() || isset($this->context)) {
            if ($this->isIncludeApi()) {
                $this->getCommandInterface()->getOutput()->newLine();
                $this->getCommandInterface()->getOutput()->section('Database Webhook Status');
            }

            $contextDao = Application::getContextDAO();
            $contexts = $contextDao->getAll(true);

            $rows = [];
            while ($context = $contexts->next()) { /** @var Context $context */
                $rows[] = [
                    $context->getId(),
                    $context->getPath(),
                    $context->getData('ithenticateWebhookId') ?? 'Not configured',
                    $context->getData('ithenticateWebhookId') ? 'Yes' : 'No'
                ];
            }

            $this->getCommandInterface()->getOutput()->table(
                ['ID', 'Path', 'Webhook ID', 'Configured'],
                $rows
            );
        }
    }

    /**
     * List webhooks registered at iThenticate API for the current credentials
     */
    protected function listApiWebhooks(): void
    {
        $output = $this->getCommandInterface()->getOutput();
        $output->section('API Webhooks (iThenticate)');

        $webhooks = $this->ithenticate->listWebhooks();

        if (empty($webhooks)) {
            $output->info(
                __('plugins.generic.plagiarism.tools.registerWebhooks.webhook.includeApi.list.empty')
            );
            return;
        }

        // Build context webhook URL for comparison (if context available)
        $contextWebhookUrl = isset($this->context)
            ? $this->plagiarismPlugin->getWebhookUrl($this->context)
            : null;
        $dbWebhookId = isset($this->context)
            ? $this->context->getData('ithenticateWebhookId')
            : null;

        $total = count($webhooks);
        $index = 0;

        foreach ($webhooks as $webhook) {
            $index++;
            $webhookId = $webhook['id'] ?? 'N/A';
            $webhookUrl = $webhook['url'] ?? 'N/A';
            $eventTypes = isset($webhook['event_types'])
                ? implode(', ', $webhook['event_types'])
                : 'N/A';
            $createdTime = $webhook['created_time'] ?? 'N/A';

            // Determine match status
            $matchStatus = '-';
            $urlMatch = $contextWebhookUrl && $webhookUrl === $contextWebhookUrl;
            $dbMatch = $dbWebhookId && $webhookId === $dbWebhookId;

            if ($urlMatch && $dbMatch) {
                $matchStatus = 'YES (URL + DB)';
            } elseif ($urlMatch) {
                $matchStatus = 'YES (URL match, not in DB)';
            } elseif ($dbMatch) {
                $matchStatus = 'YES (DB match, URL differs)';
            }

            $output->writeln("Webhook {$index} of {$total}");

            $table = new \Symfony\Component\Console\Helper\Table($output);
            $table->setHeaders(['Property', 'Value']);
            $table->setRows([
                ['Webhook ID', $webhookId],
                ['URL', $webhookUrl],
                ['Events', $eventTypes],
                ['Created', $createdTime],
                ['Matches Context', $matchStatus],
            ]);
            $table->setColumnMaxWidth(1, 80);
            $table->render();

            if ($index < $total) {
                $output->newLine();
            }
        }
    }

    /**
     * Run the webhook daemon to simulate iThenticate webhooks in test mode
     */
    public function work(): void
    {
        // Check if --force flag is provided to skip confirmation
        $forceRun = in_array('--force', $this->getParameterList());

        // Display safety warning and require confirmation
        if (!$forceRun && !$this->displayWorkCommandWarningAndConfirm()) {
            $this->getCommandInterface()->getOutput()->warning('Operation cancelled by user');
            return;
        }

        // Get optional parameters
        $interval = (int) ($this->getParameterValue('interval')
            ?? DummyWebhookManager::DEFAULT_CYCLE_INTERVAL);

        // Default max-cycles to 10, but allow override from CLI (0 = unlimited)
        $maxCycles = $this->getParameterValue('max-cycles') !== null
            ? (int) $this->getParameterValue('max-cycles')
            : DummyWebhookManager::DEFAULT_MAX_CYCLES; // Default to 10 cycles if not specified

        $once = in_array('--once', $this->getParameterList());
        $verbose = in_array('--verbose', $this->getParameterList());
        $dryRun = in_array('--dry-run', $this->getParameterList());
        $submissionId = $this->getParameterValue('submission') ? (int) $this->getParameterValue('submission') : null;

        // Validate interval
        if ($interval < 5) {
            $this->getCommandInterface()->getOutput()->error('Interval must be at least 5 seconds');
            return;
        }

        if ($interval > 3600) {
            $this->getCommandInterface()->getOutput()->error('Interval cannot exceed 3600 seconds (1 hour)');
            return;
        }

        // Validate max cycles
        if ($maxCycles < 0) {
            $this->getCommandInterface()->getOutput()->error('Max cycles must be 0 (unlimited) or positive');
            return;
        }

        // Create output callback that uses CommandInterface
        $outputCallback = function (string $message) {
            $this->getCommandInterface()->getOutput()->writeln($message);
        };

        try {
            // Create and configure the daemon
            $daemon = new DummyWebhookManager(
                $this->context,
                $this->plagiarismPlugin,
                $this->ithenticate,
                $outputCallback
            );

            $daemon
                ->setVerbose($verbose)
                ->setDryRun($dryRun)
                ->setMaxCycles($maxCycles);

            // Run the daemon
            $daemon->run($interval, $once, $submissionId);

        } catch (\Throwable $e) {
            $this->getCommandInterface()->getOutput()->error('Daemon failed: ' . $e->getMessage());
            if ($verbose) {
                $this->getCommandInterface()->getOutput()->writeln($e->getTraceAsString());
            }
        }
    }

    /**
     * Display warning message and prompt for confirmation before running work command
     *
     * @return bool True if user confirms, false otherwise
     */
    protected function displayWorkCommandWarningAndConfirm(): bool
    {
        $output = $this->getCommandInterface()->getOutput();

        // Display warning banner
        $output->newLine();
        $output->writeln('<fg=red;options=bold>╔═══════════════════════════════════════════════════════════════════════════╗</>');
        $output->writeln('<fg=red;options=bold>║                              ⚠️  WARNING  ⚠️                                ║</>');
        $output->writeln('<fg=red;options=bold>╚═══════════════════════════════════════════════════════════════════════════╝</>');
        $output->newLine();

        $output->writeln('<fg=yellow>You are about to start the Webhook Daemon in WORK mode.</>');
        $output->newLine();

        $output->writeln('<fg=yellow;options=bold>IMPORTANT SAFETY INFORMATION:</>');
        $output->writeln('  • This daemon <fg=red;options=bold>SIMULATES iThenticate webhook events</> for local testing');
        $output->writeln('  • It will <fg=red;options=bold>AUTOMATICALLY PROCESS FILES</> with pending webhook events');
        $output->writeln('  • Only works in <fg=cyan>TEST MODE</> (ithenticate.test_mode = On)');
        $output->writeln('  • Only processes submissions with <fg=cyan>test IDs</> (' . TestIthenticate::ITHENTICATE_SUBMISSION_UUID_PREFIX . '*)');
        $output->writeln('  • Makes <fg=cyan>HTTP requests to webhook handler</> on this server');
        $output->newLine();

        $output->writeln('<fg=red;options=bold>⚠️  DO NOT RUN THIS ON PRODUCTION SERVERS  ⚠️</>');
        $output->newLine();

        $output->writeln('<fg=yellow>Current context:</>');
        $output->writeln("  • Context ID: <fg=cyan>{$this->context->getId()}</>");
        $output->writeln("  • Context Path: <fg=cyan>{$this->context->getPath()}</>");
        $output->writeln("  • Context Name: <fg=cyan>{$this->context->getLocalizedName()}</>");
        $output->writeln('  • Test Mode: <fg=cyan>' . (PlagiarismPlugin::isRunningInTestMode() ? 'ENABLED' : 'DISABLED') . '</>');
        $output->newLine();

        // Check if running in production-like environment
        $isPotentiallyProduction = !PlagiarismPlugin::isRunningInTestMode();

        if ($isPotentiallyProduction) {
            $output->writeln('<fg=red;options=bold>❌ CANNOT RUN: Test mode is NOT enabled!</>');
            $output->writeln('<fg=red>Set [ithenticate] test_mode = On in config.inc.php</>');
            $output->newLine();
            return false;
        }

        // Prompt for confirmation
        $output->writeln('<fg=yellow;options=bold>Are you sure you want to continue?</>');
        $output->write('<fg=yellow>Type </><fg=green;options=bold>YES</><fg=yellow> (in uppercase) to confirm, or anything else to cancel: </>');

        // Read user input
        $handle = fopen('php://stdin', 'r');
        $confirmation = trim(fgets($handle));
        fclose($handle);

        $output->newLine();

        if ($confirmation === 'YES') {
            $output->writeln('<fg=green>✓ Confirmation received. Starting webhook daemon...</>');
            $output->newLine();
            return true;
        }

        return false;
    }
}

try {
    $tool = new Webhook($argv ?? []);
    $tool->execute();
} catch (Throwable $e) {
    $output = new CommandInterface();

    if ($e instanceof CommandInvalidArgumentException) {
        $output->errorBlock([$e->getMessage()]);
        return;
    }

    if ($e instanceof CommandNotFoundException) {
        $alternatives = $e->getAlternatives();
        $message = __('plugins.generic.plagiarism.tools.registerWebhooks.option.mean.those')
            . PHP_EOL
            . implode(PHP_EOL, $alternatives);
        $output->errorBlock([$e->getMessage(), $message]);
        return;
    }

    throw $e;
}
