<?php

/**
 * @file plugins/generic/plagiarism/classes/IThenticateWebhookManager.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IThenticateWebhookManager
 *
 * @brief Owns the iThenticate webhook lifecycle for the plugin
 */

namespace APP\plugins\generic\plagiarism\classes;

use APP\core\Application;
use APP\plugins\generic\plagiarism\controllers\PlagiarismWebhookHandler;
use APP\plugins\generic\plagiarism\IThenticate;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;
use APP\plugins\generic\plagiarism\TestIThenticate;
use Illuminate\Support\Facades\Crypt;
use PKP\config\Config;
use PKP\context\Context;
use PKP\core\Core;
use PKP\plugins\Hook;
use PKP\services\PKPSchemaService;
use Throwable;

class IThenticateWebhookManager
{
    /**
     * The plugin setting name under which the site-level webhook registry JSON is stored.
     * It lives at the site level (Application::SITE_CONTEXT_ID; PluginSettingsDAO collapses
     * null/0 via COALESCE(context_id,0)), keyed by credential fingerprint with Entry shape as
     * [
     *   'webhookId' => ...,
     *   'signingSecret' => ...,
     *   'updatedAt' => ...
     * ]
     * NO credentials are stored; those are always resolved live via the plugin's getServiceAccess().
     */
    public const WEBHOOK_REGISTRY_SETTING = 'ithenticateWebhookRegistry';

    /**
     * iThenticate's hard limit of webhooks per credential scope (one api_url + api_key pair).
     */
    public const MAX_WEBHOOKS_PER_SCOPE = 10;

    public function __construct(protected PlagiarismPlugin $plugin)
    {
    }

    /**
     * Register the LoadComponentHandler hook that routes the context-independent site webhook
     * URL to its handler.
     */
    public function registerComponentRoute(): void
    {
        Hook::add('LoadComponentHandler', $this->handleWebhookRouteComponent(...));
    }

    /**
     * Register the Schema::get::context hook that adds the legacy per-context webhook fields.
     * Called by the plugin AFTER the enabled gate but BEFORE the service-access gate
     */
    public function registerContextSchemaHook(): void
    {
        Hook::add('Schema::get::' . PKPSchemaService::SCHEMA_CONTEXT, $this->addIthenticateConfigSettingsToContextSchema(...));
    }

    /**
     * Compute a deterministic fingerprint of a credential scope (api_url + api_key) so that
     * contexts resolving to the same credentials share one fingerprint and therefore one webhook
     */
    public function credentialFingerprint(string $apiUrl, string $apiKey): string
    {
        return hash('sha256', $this->normalizeApiUrl($apiUrl) . "\0" . $apiKey);
    }

    /**
     * Grouping-only normalization of an api_url for fingerprinting
     */
    protected function normalizeApiUrl(string $apiUrl): string
    {
        return strtolower(rtrim(trim($apiUrl), "/\\"));
    }

    /**
     * Read the site-level webhook registry (fingerprint => entry) with each scope's signing
     * secret decrypted for use. A missing or corrupt value self-heals to an empty array, and
     * an individual entry whose secret cannot be decrypted (e.g. app_key rotated or the DB was
     * restored onto a different host) is dropped so that scope degrades to "re-register".
     */
    public function getWebhookRegistry(): array
    {
        $decoded = json_decode((string) $this->plugin->getSetting(Application::SITE_CONTEXT_ID, self::WEBHOOK_REGISTRY_SETTING), true);
        if (!is_array($decoded)) {
            return [];
        }

        foreach ($decoded as $fingerprint => $entry) {
            if (empty($entry['signingSecret'])) {
                continue;
            }
            try {
                $decoded[$fingerprint]['signingSecret'] = Crypt::decrypt($entry['signingSecret']);
            } catch (Throwable $exception) {
                unset($decoded[$fingerprint]);
            }
        }

        return $decoded;
    }

    /**
     * Persist the site-level webhook registry as a JSON string, encrypting each scope's signing
     * secret at rest (webhookId, updatedAt and the fingerprint key stay readable). Callers always
     * hand us plaintext secrets (their array is sourced from getWebhookRegistry()), so each secret
     * is encrypted exactly once. Relies on the app_key that has been required since OJS 3.5.
     */
    public function saveWebhookRegistry(array $registry): void
    {
        foreach ($registry as $fingerprint => $entry) {
            if (!empty($entry['signingSecret'])) {
                $registry[$fingerprint]['signingSecret'] = Crypt::encrypt($entry['signingSecret']);
            }
        }

        $this->plugin->updateSetting(
            Application::SITE_CONTEXT_ID,
            self::WEBHOOK_REGISTRY_SETTING,
            json_encode($registry),
            'string'
        );
    }

    /**
     * Resolve the registry entry for a credential scope, or null if none is registered.
     */
    public function getRegistryEntryForCredentials(string $apiUrl, string $apiKey): ?array
    {
        return $this->getWebhookRegistry()[$this->credentialFingerprint($apiUrl, $apiKey)] ?? null;
    }

    /**
     * Insert or replace the registry entry for a fingerprint.
     */
    public function putRegistryEntry(string $fingerprint, array $entry): void
    {
        $registry = $this->getWebhookRegistry();
        $registry[$fingerprint] = $entry;
        $this->saveWebhookRegistry($registry);
    }

    /**
     * Remove the registry entry for a fingerprint, if present.
     */
    public function removeRegistryEntry(string $fingerprint): void
    {
        $registry = $this->getWebhookRegistry();
        unset($registry[$fingerprint]);
        $this->saveWebhookRegistry($registry);
    }

    /**
     * Ensure the single site webhook exists for the credential scope of the given context.
     *
     * If a registry entry already exists for the resolved credentials it returns immediately
     * with NO API call, preserving the per-submission performance of the previous
     * per-context guard. Only a missing entry triggers a register/reuse round-trip.
     *
     * Pass $revalidate = true to skip that fast-path and always run the full register/reuse,
     * which validates the stored webhook id at the API and re-registers it if it 
     * has become invalid/gone.
     */
    public function ensureWebhookForContext(Context $context, bool $revalidate = false): bool
    {
        list($apiUrl, $apiKey) = $this->plugin->getServiceAccess($context);

        if (empty($apiUrl) || empty($apiKey)) {
            return false;
        }

        if (!$revalidate && $this->getRegistryEntryForCredentials($apiUrl, $apiKey)) {
            return true;
        }

        return $this->registerOrReuseWebhookForScope($apiUrl, $apiKey);
    }

    /**
     * Register (or reuse) the one site webhook for a credential scope, following
     * iThenticate's guidance of List-first to stay within the 10-webhooks-per-scope limit
     *
     *  - reuse a stored webhook id that still validates at the API;
     *  - otherwise List within this scope: a webhook already at our site URL is a stale OJS/OMP/OPS
     *    registration whose signing_secret List/Get never returns, so it cannot be reused —
     *    delete and recreate with our own secret (this only ever touches a prior SITE
     *    webhook; legacy per-context webhooks live at a different URL and are left alone);
     *  - store {webhookId, signingSecret, updatedAt} in the site registry
     */
    public function registerOrReuseWebhookForScope(string $apiUrl, string $apiKey): bool
    {
        $fingerprint = $this->credentialFingerprint($apiUrl, $apiKey);
        $siteUrl = $this->getSiteWebhookUrl();
        $ithenticate = $this->plugin->initIthenticate($apiUrl, $apiKey);

        $entry = $this->getWebhookRegistry()[$fingerprint] ?? null;
        if ($entry && ($entry['webhookId'] ?? null) && $ithenticate->validateWebhook($entry['webhookId'])) {
            return true;
        }

        if ($existingId = $ithenticate->findWebhookIdByUrl($siteUrl)) {
            $ithenticate->deleteWebhook($existingId);
        }

        $signingSecret = \Illuminate\Support\Str::random(32);
        $webhookId = $ithenticate->registerWebhook(
            $signingSecret,
            $siteUrl,
            $this->resolveWebhookAllowInsecure($siteUrl)
        );

        // An install already at iThenticate's per-scope webhook limit cannot create the site webhook
        // for want of a free slot. Reclaim ONE of our OWN legacy per-context webhook slots and retry once.
        if (!$webhookId && $this->reclaimOneLegacyWebhookSlotForScope($fingerprint, $ithenticate)) {
            $webhookId = $ithenticate->registerWebhook(
                $signingSecret,
                $siteUrl,
                $this->resolveWebhookAllowInsecure($siteUrl)
            );
        }

        if (!$webhookId) {
            error_log("Plagiarism plugin: unable to register the iThenticate site webhook for credential scope {$fingerprint}");
            return false;
        }

        $this->putRegistryEntry($fingerprint, [
            'webhookId' => $webhookId,
            'signingSecret' => $signingSecret,
            'updatedAt' => Core::getCurrentDate(),
        ]);

        return true;
    }

    /**
     * Build the context-independent SITE webhook URL.
     *
     * Format: BASE_URL/index.php/index/$$$call$$$/plugins/generic/plagiarism/controllers/plagiarism-webhook/handle
     *
     * NOTE : When a site base-URL override (config `base_url[index]`) is set, App's component-router URL
     * builder collapses the `index` context to null and drops BOTH the `index` segment and the
     * `index.php` entry point — producing `{base_url[index]}/$$$call$$$/…`, whose `$$$call$$$` marker
     * lands at path position 0. The component router requires the marker at position 1 with the
     * site-context segment `index` at position 0 (PKPComponentRouter::_retrieveServiceEndpointParts()),
     * so that collapsed URL never routes back to this handler. We therefore detect the override and
     * rebuild the URL ourselves, keeping the `index` segment + entry point so the inbound PATH_INFO is
     * `/index/$$$call$$$/…` regardless of the override's host or subdirectory. Without an override the
     * dispatcher already emits a resolvable URL, so we delegate to it unchanged.
     */
    public function getSiteWebhookUrl(): string
    {
        $request = Application::get()->getRequest();

        // The dispatcher is the single source of truth for the component/op path (handler class + op).
        $url = Application::get()->getDispatcher()->url(
            $request,
            Application::ROUTE_COMPONENT,
            Application::SITE_CONTEXT_PATH,
            'plugins.generic.plagiarism.controllers.PlagiarismWebhookHandler',
            'handle'
        );

        if (empty(Config::getVar('general', 'base_url[' . Application::SITE_CONTEXT_PATH . ']'))) {
            return $url;
        }

        // if override as base_url[index] is set, restore the site-context segment for webhook
        return $this->restoreSiteContextSegment($url, $request->isRestfulUrlsEnabled());
    }

    /**
     * Get the webhook URL for a given context (the legacy per-context endpoint). Retained for
     * the CLI, which uses it to locate legacy per-context webhooks for reporting/cleanup.
     *
     * Format: BASE_URL/index.php/CONTEXT_PATH/$$$call$$$/plugins/generic/plagiarism/controllers/plagiarism-webhook/handle
     */
    public function getWebhookUrl(Context $context): string
    {
        $request = Application::get()->getRequest();

        return Application::get()->getDispatcher()->url(
            $request,
            Application::ROUTE_COMPONENT,
            $context->getData('urlPath'),
            'plugins.generic.plagiarism.controllers.PlagiarismWebhookHandler',
            'handle'
        );
    }

    /**
     * Route the context-independent site webhook to its handler, unconditionally.
     *
     * @param string $hookName `LoadComponentHandler`
     */
    public function handleWebhookRouteComponent(string $hookName, array $params): bool
    {
        $component =& $params[0]; /** @var string $component */
        $componentInstance =& $params[2]; /** @var mixed $componentInstance */

        if ($component !== 'plugins.generic.plagiarism.controllers.PlagiarismWebhookHandler') {
            return Hook::CONTINUE;
        }

        $componentInstance = new PlagiarismWebhookHandler($this->plugin);

        return Hook::ABORT;
    }

    /**
     * Add the legacy per-context webhook properties to the context entity's schema for storage
     * in <CONTEXT>_settings. Retained (alongside the site registry) so existing per-context
     * webhooks keep verifying and the CLI can locate/clean them
     *
     * @param string $hookName `Schema::get::context`
     */
    public function addIthenticateConfigSettingsToContextSchema(string $hookName, array $params): bool
    {
        $schema =& $params[0];

        $schema->properties->ithenticateWebhookSigningSecret = (object) [
            'type' => 'string',
            'description' => 'The iThenticate service webook registration signing secret',
            'writeOnly' => true,
            'validation' => ['nullable'],
        ];

        $schema->properties->ithenticateWebhookId = (object) [
            'type' => 'string',
            'description' => 'The iThenticate service webook id that return back after successful webhook registration',
            'writeOnly' => true,
            'validation' => ['nullable'],
        ];

        return Hook::CONTINUE;
    }

    /**
     * Break a full-scope registration deadlock by reclaiming ONE of the plugin's OWN legacy
     * per-context webhook slots, so the single site webhook can be created.
     */
    protected function reclaimOneLegacyWebhookSlotForScope(string $fingerprint, IThenticate|TestIThenticate $ithenticate): bool
    {
        if (count($ithenticate->listWebhooks()) < self::MAX_WEBHOOKS_PER_SCOPE) {
            return false;
        }

        $reclaimable = $this->findReclaimableLegacyWebhook($fingerprint);
        if (!$reclaimable) {
            error_log("Plagiarism plugin: credential scope {$fingerprint} is at the iThenticate webhook limit and holds no reclaimable plugin webhook; an administrator must free a slot");
            return false;
        }

        list($context, $legacyId) = $reclaimable;

        return $this->deleteLegacyWebhookForContext($context, $legacyId);
    }

    /**
     * Find one plugin-owned legacy per-context webhook whose context resolves to $fingerprint.
     *
     * Only returns a webhook id the plugin itself persisted (a context's ithenticateWebhookId),
     * so the reclaim can never touch a foreign integration's webhook. Returns [context, legacyId]
     * or null when the scope holds no plugin-owned legacy webhook to reclaim.
     *
     * @return array{0: Context, 1: string}|null
     */
    protected function findReclaimableLegacyWebhook(string $fingerprint): ?array
    {
        $contexts = Application::getContextDAO()->getAll(true);
        while ($context = $contexts->next()) { /** @var Context $context */
            list($apiUrl, $apiKey) = $this->plugin->getServiceAccess($context);
            if (empty($apiUrl) || empty($apiKey) || $this->credentialFingerprint($apiUrl, $apiKey) !== $fingerprint) {
                continue;
            }

            $legacyId = $context->getData('ithenticateWebhookId');
            if ($legacyId) {
                return [$context, $legacyId];
            }
        }

        return null;
    }

    /**
     * Delete a plugin-owned legacy per-context webhook at the API and clear its stored fields.
     * A 404 (already gone at the API) still counts as freed. Returns whether a slot was freed.
     */
    protected function deleteLegacyWebhookForContext(Context $context, string $legacyId): bool
    {
        $client = $this->plugin->initIthenticate(...$this->plugin->getServiceAccess($context));
        $deleted = $client->deleteWebhook($legacyId);
        $responseDetails = $client->getLastResponseDetails();
        $goneAtApi = $responseDetails && ($responseDetails['status_code'] ?? 0) === 404;

        if (!$deleted && !$goneAtApi) {
            error_log("Plagiarism plugin: failed to delete legacy webhook {$legacyId} for context {$context->getPath()} while reclaiming a webhook slot");
            return false;
        }

        $contextService = app()->get('context'); /** @var \APP\services\ContextService $contextService */
        $contextService->edit($context, [
            'ithenticateWebhookId' => null,
            'ithenticateWebhookSigningSecret' => null,
        ], Application::get()->getRequest());

        return true;
    }

    /**
     * Resolve the `allow_insecure` flag for the webhook registration body.
     *
     * Honours an explicit `[ithenticate] webhook_allow_insecure` (On/Off) when set; otherwise derives
     * from the URL scheme (http => true, https => false), as the Turnitin TCA API requires.
     */
    protected function resolveWebhookAllowInsecure(string $webhookUrl): bool
    {
        $configured = Config::getVar('ithenticate', 'webhook_allow_insecure', null);
        if ($configured !== null) {
            return (bool) $configured;
        }

        return strtolower((string) parse_url($webhookUrl, PHP_URL_SCHEME)) === 'http';
    }

    /**
     * Restore the site-context segment (and entry point) that a `base_url[index]` override strips from a
     * site component URL, keeping the URL routable without duplicating the handler path.
     */
    protected function restoreSiteContextSegment(string $collapsedUrl, bool $restfulUrlsEnabled): string
    {
        $needle = '/' . COMPONENT_ROUTER_PATHINFO_MARKER . '/';   // '/$$$call$$$/'
        $markerPos = strpos($collapsedUrl, $needle);
        if ($markerPos === false) {
            return $collapsedUrl;   // unexpected shape (marker missing) — leave untouched
        }

        $base = rtrim(substr($collapsedUrl, 0, $markerPos), '/');
        $endpoint = substr($collapsedUrl, $markerPos + 1);   // 'index'-less: $$$call$$$/plugins/…/handle
        if (!$restfulUrlsEnabled && !str_ends_with($base, '/index.php')) {
            $base .= '/index.php';
        }

        return $base . '/' . Application::SITE_CONTEXT_PATH . '/' . $endpoint;
    }
}
