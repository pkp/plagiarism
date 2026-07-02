<?php

/**
 * @file plugins/generic/plagiarism/classes/notification/PlagiarismErrorManager.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PlagiarismErrorManager
 *
 * @brief Records, queries and clears iThenticate processing errors as stored
 *        notifications instead of per-manager toast notifications.
 *
 * Every core toast/badge/count query is scoped to the current user
 * (`Notification::scopeWithUserId()` => `where user_id = ?`), a null-user
 * notification is structurally invisible to the login toast flood that issue
 * pkp/pkp-lib#12760 is about, yet remains queryable by assoc for the workflow UI.
 *
 *  - Workflow errors  => assoc (ASSOC_TYPE_SUBMISSION, submissionId), shown as a single stack of
 *                        dismissible workflow toasts and dismissed via the built-in `date_read` flag.
 *                        A file-specific error carries a `submissionFileId` + `fileName` setting for
 *                        attribution and replaces any prior error for that same file (latest-wins per
 *                        file); a submission-wide error is deduped by `code`. There is no auto-clear —
 *                        an error stays until the editor dismisses it.
 *  - Config-level     => no assoc, context-scoped, deduped by `code` with an occurrence `count`
 *                        (shown on the plugin settings page, never in the workflow).
 */

namespace APP\plugins\generic\plagiarism\classes\notification;

use APP\core\Application;
use APP\notification\NotificationManager;
use Carbon\Carbon;
use PKP\db\DAORegistry;
use PKP\notification\Notification;
use PKP\notification\NotificationSettingsDAO;
use PKP\submissionFile\SubmissionFile;
use Throwable;

class PlagiarismErrorManager
{
    /**
     * Plugin-specific notification type for stored iThenticate processing errors.
     * High value, outside the core/app NOTIFICATION_TYPE_* ranges.
     */
    public const NOTIFICATION_TYPE_PLAGIARISM = 0x6000001;

    /**
     * Stable error-code identifiers, grouped by the surface each is recorded on. The `code` is the
     * dedup key (config diagnostics dedup + occurrence count; submission-wide recurrence-replace), so
     * these VALUES are persisted in notification_settings and must stay stable — do not rename a value
     * without accounting for already-stored rows. File-error codes are identifiers/log only (file
     * errors dedup by submissionFileId). Centralised here so multi-call-site codes cannot drift apart.
     */

    // Config-diagnostic error codes (deduped by code, with an occurrence count).
    public const CONFIG_ERROR_CODE_SERVICE_ACCESS_INVALID = 'serviceAccessInvalid';
    public const CONFIG_ERROR_CODE_WEBHOOK_CONFIGURATION_MISSING = 'webhook.configuration.missing';
    public const CONFIG_ERROR_CODE_WEBHOOK_HEADERS_MISSING = 'webhook.headers.missing';
    public const CONFIG_ERROR_CODE_WEBHOOK_EVENT_INVALID = 'webhook.event.invalid';
    public const CONFIG_ERROR_CODE_WEBHOOK_SIGNATURE_INVALID = 'webhook.signature.invalid';
    public const CONFIG_ERROR_CODE_WEBHOOK_SUBMISSION_ID_INVALID = 'webhook.submissionId.invalid';
    public const CONFIG_ERROR_CODE_WEBHOOK_SUBMISSION_FILE_ASSOCIATION_INVALID = 'webhook.submissionFileAssociation.invalid';

    // Submission-wide error codes (deduped by code; a recurrence replaces the prior toast).
    public const SUBMISSION_ERROR_CODE_WEBHOOK_REGISTRATION_FAILED = 'webhook.registration.failed';
    public const SUBMISSION_ERROR_CODE_STAMPED_EULA_MISSING = 'stamped.eula.missing';
    public const SUBMISSION_ERROR_CODE_UPLOAD_COMPLETE_FAILED = 'upload.complete.failed';
    public const SUBMISSION_ERROR_CODE_MISSING_PRIMARY_AUTHOR = 'missingPrimaryAuthor';

    // File-specific error codes (identifier/log; file errors dedup by submissionFileId).
    public const FILE_ERROR_CODE_SUBMISSION_CREATE_FAILED = 'submission.create.failed';
    public const FILE_ERROR_CODE_UPLOAD_FAILED = 'file.upload.failed';
    public const FILE_ERROR_CODE_SIMILARITY_SCHEDULE_PREVIOUSLY = 'similarity.schedule.previously';
    public const FILE_ERROR_CODE_SIMILARITY_SCHEDULE_FAILURE = 'similarity.schedule.failure';
    public const FILE_ERROR_CODE_SIMILARITY_SCHEDULE_ERROR = 'similarity.schedule.error';

    /**
     * Append an actionable "why" detail (e.g. an iThenticate HTTP status/reason from
     * IThenticate::getLastErrorSummary(), or a caught exception's message) to a base error message.
     */
    public static function withDetail(string $message, ?string $detail): string
    {
        return $detail
            ? __('plugins.generic.plagiarism.error.withDetail', ['message' => $message, 'detail' => $detail])
            : $message;
    }

    /**
     * Record an iThenticate processing error, routing it to the surface where it is actionable:
     *  - a file-specific error  => a dismissible workflow toast tagged with the file (its name is shown),
     *  - a submission-only error => a dismissible workflow toast,
     *  - neither (config/setup)  => configuration diagnostics on the plugin settings page.
     *
     * File-specific and submission-wide errors share the one workflow toast stack and the single
     * mark-read dismissal; only the settings-page diagnostics are a separate surface.
     *
     * Always writes to the error log first
     *
     * @param string              $message        The localized error message.
     * @param int|null            $submissionId   The related submission id, if any (for logging + submission-level routing).
     * @param SubmissionFile|null $submissionFile The related submission file, if the error is file-specific.
     * @param string|null         $code           A stable error code used for dedup and dismissal.
     * @param string|null         $guidance       Optional actionable guidance shown under the toast; passed
     *                                            explicitly by the caller (a toast shows a guidance line only when given one).
     */
    public function record(
        string $message,
        ?int $submissionId = null,
        ?SubmissionFile $submissionFile = null,
        ?string $code = null,
        ?string $guidance = null
    ): void {
        error_log('iThenticate plagiarism error' . ($submissionId ? " for submission {$submissionId}" : '') . ": {$message}");

        // Make sure a notification write does not break any core mechanism
        try {
            $contextId = Application::get()->getRequest()->getContext()?->getId();

            if ($submissionFile) {
                $this->recordFileError($submissionFile, $contextId, $code ?? 'file.error', $message, $guidance);
                return;
            }

            if ($submissionId) {
                $this->recordSubmissionError($submissionId, $contextId, $code ?? 'submission.error', $message, $guidance);
                return;
            }

            $this->recordConfigError($contextId, $code ?? 'config.error', $message);
        } catch (Throwable $exception) {
            error_log('PlagiarismErrorManager::record() could not persist the notification (error logged above): ' . $exception->getMessage());
        }
    }

    /**
     * Record a file-specific error and tagged with the file's id and name for attribution.
     */
    public function recordFileError(SubmissionFile $submissionFile, ?int $contextId, string $code, string $message, ?string $guidance = null): void
    {
        $submissionId = (int) $submissionFile->getData('submissionId');

        $this->deleteSubmissionError($submissionId, $code, $submissionFile->getId());

        $this->create(
            $contextId,
            Application::ASSOC_TYPE_SUBMISSION,
            $submissionId,
            $code,
            $message,
            $guidance,
            [
                'submissionFileId' => ['value' => $submissionFile->getId(), 'type' => 'int'],
                'fileName' => $submissionFile->getLocalizedData('name'),
            ]
        );
    }

    /**
     * Record a submission-level error (shown as a persistent dismissible toast in the workflow).
     * Deduped by `code`; a recurrence replaces any prior (read or unread) entry so it re-surfaces.
     */
    public function recordSubmissionError(int $submissionId, ?int $contextId, string $code, string $message, ?string $guidance = null): void
    {
        $this->deleteSubmissionError($submissionId, $code);
        $this->create($contextId, Application::ASSOC_TYPE_SUBMISSION, $submissionId, $code, $message, $guidance);
    }

    /**
     * Record a configuration/setup error (no submission context) for the plugin settings
     * diagnostics panel. Deduped by `code` within the context with an occurrence count.
     */
    public function recordConfigError(?int $contextId, string $code, string $message): void
    {
        // A config error with no resolvable context can only be logged (handled by the caller).
        if (!$contextId) {
            return;
        }

        $existing = $this->configQuery($contextId)->get()
            ->first(fn (Notification $notification) => ($this->settingsFor($notification)['code'] ?? null) === $code);

        if ($existing) {
            $count = (int) ($this->settingsFor($existing)['count'] ?? 0) + 1;
            $this->settingsDao()->updateNotificationSetting($existing->getId(), 'count', $count, false, 'int');
            $this->settingsDao()->updateNotificationSetting($existing->getId(), 'contents', $message);
            $existing->dateCreated = Carbon::now();
            $existing->save();
            return;
        }

        $this->create($contextId, null, null, $code, $message, null, ['count' => ['value' => 1, 'type' => 'int']]);
    }

    /**
     * Dismiss a single submission-level error by marking that one notification read
     */
    public function dismissSubmissionError(int $submissionId, int $notificationId): void
    {
        $notification = $this->submissionQuery($submissionId)
            ->withRead(false)
            ->whereKey($notificationId)
            ->first();

        if (!$notification) {
            return;
        }

        $notification->dateRead = Carbon::now();
        $notification->save();
    }

    /**
     * Clear all configuration diagnostics for a context.
     */
    public function clearConfigDiagnostics(int $contextId): void
    {
        $this->configQuery($contextId)->delete();
    }

    /**
     * Get the unread workflow errors for a submission — both submission-wide and file-specific errors
     *
     * @return array<int,array>
     */
    public function getSubmissionErrors(int $submissionId): array
    {
        return $this->submissionQuery($submissionId)
            ->withRead(false)
            ->get()
            ->map(fn (Notification $notification) => $this->format($notification))
            ->values()
            ->all();
    }

    /**
     * Get the configuration diagnostics for a context (for the settings page).
     *
     * @return array<int,array>
     */
    public function getConfigDiagnostics(int $contextId): array
    {
        return $this->configQuery($contextId)
            ->get()
            ->map(function (Notification $notification) {
                $settings = $this->settingsFor($notification);
                return [
                    'code' => $settings['code'] ?? null,
                    'message' => $settings['contents'] ?? null,
                    'count' => (int) ($settings['count'] ?? 1),
                    'lastOccurredAt' => $notification->dateCreated?->format('Y-m-d H:i:s'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Create a stored (shared, never-popping) plagiarism notification.
     *
     * @param array<string,mixed> $extraParams Extra notification_settings params. A value may be a
     *                                          scalar, or an ['value' => ..., 'type' => 'int'] pair.
     */
    protected function create(
        ?int $contextId,
        ?int $assocType,
        ?int $assocId,
        string $code,
        string $message,
        ?string $guidance,
        array $extraParams = []
    ): void
    {
        $params = array_filter([
            'contents' => $message,
            'code' => $code,
            'guidance' => $guidance,
        ], fn ($value) => !is_null($value));

        $notification = (new NotificationManager())->createNotification(
            null,
            self::NOTIFICATION_TYPE_PLAGIARISM,
            $contextId,
            $assocType,
            $assocId,
            Notification::NOTIFICATION_LEVEL_NORMAL,
            $params
        );

        if (!$notification) {
            return;
        }

        foreach ($extraParams as $name => $value) {
            is_array($value)
                ? $this->settingsDao()->updateNotificationSetting($notification->getId(), $name, $value['value'], false, $value['type'])
                : $this->settingsDao()->updateNotificationSetting($notification->getId(), $name, $value);
        }
    }

    /**
     * Shape a notification (and its stored settings) into the structure consumed by the API payload.
     */
    protected function format(Notification $notification): array
    {
        $settings = $this->settingsFor($notification);

        return [
            // Unique notification id — the client dismisses an error by this id (see
            // dismissSubmissionError()), so exactly one stored notice is marked read.
            'id' => (int) $notification->getId(),
            'code' => $settings['code'] ?? null,
            'message' => $settings['contents'] ?? null,
            'guidance' => $settings['guidance'] ?? null,
            // File attribution for file-specific errors (null for submission-wide errors), so the toast
            // can name the file the error belongs to now that it no longer sits in the file's own row.
            'submissionFileId' => isset($settings['submissionFileId']) ? (int) $settings['submissionFileId'] : null,
            'fileName' => $settings['fileName'] ?? null,
            'dateOccurred' => $notification->dateCreated?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Delete existing stored workflow errors for a submission that match a dedup key (read or unread):
     *  - file-specific ($submissionFileId given) => every prior error for that same file, any code
     *    (latest-wins per file);
     *  - submission-wide ($submissionFileId null) => prior errors with the same code and no file.
     * The file-id partition keeps two different files that fail with the same code from colliding.
     */
    protected function deleteSubmissionError(int $submissionId, string $code, ?int $submissionFileId = null): void
    {
        $this->submissionQuery($submissionId)
            ->get()
            ->filter(function (Notification $notification) use ($code, $submissionFileId) {
                $settings = $this->settingsFor($notification);
                $fileId = isset($settings['submissionFileId']) ? (int) $settings['submissionFileId'] : null;

                return $submissionFileId !== null
                    ? $fileId === $submissionFileId
                    : ($fileId === null && ($settings['code'] ?? null) === $code);
            })
            ->each(fn (Notification $notification) => $notification->delete());
    }

    protected function submissionQuery(int $submissionId)
    {
        return Notification::withType(self::NOTIFICATION_TYPE_PLAGIARISM)
            ->withUserId(null)
            ->withAssoc(Application::ASSOC_TYPE_SUBMISSION, $submissionId);
    }

    protected function configQuery(int $contextId)
    {
        return Notification::withType(self::NOTIFICATION_TYPE_PLAGIARISM)
            ->withUserId(null)
            ->withContextId($contextId)
            ->whereNull('assoc_type');
    }

    /**
     * Load a notification's stored settings (contents, code, guidance, count)
     *
     * @return array<string,mixed> setting name => value
     */
    protected function settingsFor(Notification $notification): array
    {
        return $this->settingsDao()->getNotificationSettings($notification->getId());
    }

    protected function settingsDao(): NotificationSettingsDAO
    {
        return DAORegistry::getDAO('NotificationSettingsDAO');
    }
}
