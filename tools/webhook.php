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

use PKP\cliTool\CommandInterface;
use Exception;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\InvalidArgumentException as CommandInvalidArgumentException;
use PKP\cliTool\traits\HasCommandInterface;
use PKP\cliTool\traits\HasParameterList;
use Throwable;
use PKP\context\Context;
use APP\core\Application;
use PKP\context\ContextDAO;
use PKP\cliTool\CommandLineTool;
use APP\plugins\generic\plagiarism\IThenticate;
use APP\plugins\generic\plagiarism\TestIThenticate;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;

error_reporting(E_ALL & ~E_DEPRECATED);

define('APP_ROOT', dirname(__FILE__, 5));
require_once APP_ROOT . '/tools/bootstrap.php';

class Webhook extends CommandLineTool
{
    use HasParameterList;
    use HasCommandInterface;

    public const REACHABILITY_TIMEOUT = 10;
    
    protected const AVAILABLE_OPTIONS = [
        'register'  => 'plugins.generic.plagiarism.tools.registerWebhooks.register.description',
        'update'    => 'plugins.generic.plagiarism.tools.registerWebhooks.update.description',
        'validate'  => 'plugins.generic.plagiarism.tools.registerWebhooks.validate.description',
        'list'      => 'plugins.generic.plagiarism.tools.registerWebhooks.list.description',
        'usage'     => 'plugins.generic.plagiarism.tools.registerWebhooks.usage.description',
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

        if (in_array($this->option, ['register', 'update', 'validate'])) {
            if (!$this->validateRequiredParameters()) {
                throw new CommandNotFoundException(
                    __(
                        'plugins.generic.plagiarism.tools.registerWebhooks.required.parameters.missing',
                        ['parameter' => 'context', 'command' => $this->option]
                    ),
                    [__('plugins.generic.plagiarism.tools.registerWebhooks.required.parameters.missing.example', ['command' => $this->option])]
                );
            }

            $this->context = $this->getContext();
            $this->plagiarismPlugin = new PlagiarismPlugin();
            $this->initIthenticate();
        }

        $this->{$this->option}();
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
     * @return string The plugin version from the release tag
     * 
     * @throws Exception If the version.xml file is not found or cannot be read
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
     */
    protected function initIthenticate(): void
    {
        if (!$this->plagiarismPlugin->isServiceAccessAvailable($this->context)) {
            throw new CommandInvalidArgumentException(
                __('plugins.generic.plagiarism.manager.settings.serviceAccessMissing')
            );
        }

        // Get service access credentials
        list($apiUrl, $apiKey) = $this->plagiarismPlugin->getServiceAccess($this->context);

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
    protected function delete(): bool
    {
        // If there is a already registered webhook for this context, need to delete it first
        // before creating a new one as webhook URL when remains same, will return 409(HTTP_CONFLICT)
        $existingWebhookId = $this->context->getData('ithenticateWebhookId');
        
        if (!$existingWebhookId) {
            return true;
        }

        if (!$this->ithenticate->deleteWebhook($existingWebhookId)) {
            // if the force flag not passed, will not continue to delete the webhook from database
            // will only print the error and return
            if (!in_array('--force', $this->getParameterList())) {
                $this->getCommandInterface()->getOutput()->error(
                    __(
                        'plugins.generic.plagiarism.tools.registerWebhooks.webhook.deleted.error',
                        ['webhookId' => $existingWebhookId, 'contextId' => $this->context->getId()]
                    )
                );

                $this->displayApiResponseDetails("Deleting ithenticate webhook id : {$existingWebhookId} failed via iThenticate API");

                return false;
            }
        }

        $contextService = app()->get('context'); /** @var \APP\services\ContextService $contextService */
        $this->context = $contextService->edit($this->context, [
            'ithenticateWebhookSigningSecret' => null,
            'ithenticateWebhookId' => null
        ], Application::get()->getRequest());

        $this->getCommandInterface()->getOutput()->success(
            __(
                'plugins.generic.plagiarism.tools.registerWebhooks.webhook.deleted.success',
                ['webhookId' => $existingWebhookId, 'contextId' => $this->context->getId()]
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
    protected function register(): void
    {
        if ($this->context->getData('ithenticateWebhookId')) {
            $this->getCommandInterface()->getOutput()->warning(
                __('plugins.generic.plagiarism.tools.registerWebhooks.webhook.already.configured')
            );
            return;
        }

        $this->update();
    }

    /**
     * Update the iThenticate webhook for given context
     */
    protected function update(): void
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
    protected function validate(): bool
    {
        // Re-fetch the context to ensure the latest data is loaded
        $this->context = $this->getContext();

        if (!$this->context->getData('ithenticateWebhookId')) {
            $this->getCommandInterface()->getOutput()->error(
                __(
                    'plugins.generic.plagiarism.webhook.configuration.missing',
                    ['contextId' => $this->context->getId()]
                )
            );
            return false;
        }

        $webhookResult = null;

        $validity = $this->ithenticate->validateWebhook($this->context->getData('ithenticateWebhookId'), $webhookResult);

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
            $this->displayApiResponseDetails("Failed validating webhook id : {$this->context->getData('ithenticateWebhookId')}");
        }

        return $validity;
    }

    /**
     * List the iThenticate webhooks for all available and enabled contexts
     */
    public function list(): void
    {
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

try {
    $tool = new Webhook($argv ?? []);
    $tool->execute();
} catch (Throwable $e) {
    $output = new CommandInterface;

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
