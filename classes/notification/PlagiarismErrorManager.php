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
 * @brief Records, queries and clears iThenticate processing errors as stored notifications
 */

namespace APP\plugins\generic\plagiarism\classes\notification;

use APP\core\Application;
use APP\notification\NotificationManager;
use Illuminate\Support\Facades\DB;
use PKP\db\DAORegistry;
use PKP\notification\Notification;
use PKP\notification\NotificationSettingsDAO;
use PKP\submissionFile\SubmissionFile;
use Throwable;

class PlagiarismErrorManager
{
    /**
     * The notification_settings tag that marks a row as owned by this plugin
     */
    private const SETTING_PLUGIN_NAME = 'pluginName';
    private const PLUGIN_NAME = 'plagiarism';

    /**
     * Append detail (e.g. an iThenticate HTTP status/reason from IThenticate::getLastErrorSummary()
     * or a caught exception's message) to a base error message.
     */
    public static function withDetail(string $message, ?string $detail): string
    {
        return $detail
            ? __('plugins.generic.plagiarism.error.withDetail', ['message' => $message, 'detail' => $detail])
            : $message;
    }

    /**
     * Record an iThenticate processing error, always writes to the error log first
     */
    public function record(string $message, ?int $contextId, ?int $submissionId = null, ?SubmissionFile $submissionFile = null): void
    {
        error_log('iThenticate plagiarism error' . ($submissionId ? " for submission {$submissionId}" : '') . ": {$message}");

        // Make sure a notification write does not break any core mechanism
        try {
            if ($submissionFile) {
                $this->recordFileError($submissionFile, $contextId, $message);
                return;
            }

            if ($submissionId) {
                $this->recordSubmissionError($submissionId, $contextId, $message);
                return;
            }
        } catch (Throwable $exception) {
            error_log('PlagiarismErrorManager::record() could not persist the notification (error logged above): ' . $exception->getMessage());
        }
    }

    /**
     * Record a file-specific error, tagged with the file's name for attribution.
     */
    public function recordFileError(SubmissionFile $submissionFile, ?int $contextId, string $message): void
    {
        $submissionId = (int) $submissionFile->getData('submissionId');

        $this->create(
            $contextId,
            Application::ASSOC_TYPE_SUBMISSION,
            $submissionId,
            $message,
            [
                'fileName' => $submissionFile->getLocalizedData('name'),
            ]
        );
    }

    /**
     * Record a submission-level error (shown as a persistent dismissible toast in the workflow).
     */
    public function recordSubmissionError(int $submissionId, ?int $contextId, string $message): void
    {
        $this->create($contextId, Application::ASSOC_TYPE_SUBMISSION, $submissionId, $message);
    }

    /**
     * Dismiss and delete a single submission-level error by deleting that stored notification
     */
    public function dismissError(int $submissionId, int $notificationId): void
    {
        $notification = $this->submissionQuery($submissionId)
            ->whereKey($notificationId)
            ->first();

        if (!$notification || !$this->belongsToPlugin($notification)) {
            return;
        }

        $notification->delete();
    }

    /**
     * Get the unread workflow errors for a submission — both submission-wide and file-specific errors
     *
     * @return array<int,array>
     */
    public function getErrors(int $submissionId): array
    {
        $rows = DB::table('notifications as n')
            ->join('notification_settings as owner', function ($join) {
                $join->on('owner.notification_id', '=', 'n.notification_id')
                    ->where('owner.setting_name', '=', self::SETTING_PLUGIN_NAME)
                    ->where('owner.setting_value', '=', self::PLUGIN_NAME);
            })
            ->join('notification_settings as ns', 'ns.notification_id', '=', 'n.notification_id')
            ->where('n.assoc_type', '=', Application::ASSOC_TYPE_SUBMISSION)
            ->where('n.assoc_id', '=', $submissionId)
            ->where('n.type', '=', Notification::NOTIFICATION_TYPE_PLUGIN_BASE)
            ->whereNull('n.user_id')
            ->whereNull('n.date_read')
            ->get(['n.notification_id', 'n.date_created', 'ns.setting_name', 'ns.setting_value']);

        $errors = [];
        foreach ($rows->groupBy('notification_id') as $notificationId => $settingRows) {
            $settings = $settingRows->pluck('setting_value', 'setting_name');

            $errors[] = [
                'id' => (int) $notificationId,
                'message' => $settings['contents'] ?? null,
                'fileName' => $settings['fileName'] ?? null,
                'dateOccurred' => $settingRows->first()->date_created,
            ];
        }

        return $errors;
    }

    /**
     * Create a stored (shared, never-popping) plagiarism notification.
     *
     * @param array<string,mixed> $extraParams Extra notification_settings params. A value may be a
     *                                          scalar, or an ['value' => ..., 'type' => 'int'] pair.
     */
    protected function create(?int $contextId, ?int $assocType, ?int $assocId, string $message, array $extraParams = []): void
    {
        $params = array_filter([
            'contents' => $message,
        ], fn ($value) => !is_null($value));

        $notification = (new NotificationManager())->createNotification(
            null,
            Notification::NOTIFICATION_TYPE_PLUGIN_BASE,
            $contextId,
            $assocType,
            $assocId,
            Notification::NOTIFICATION_LEVEL_NORMAL,
            $params
        );

        if (!$notification) {
            return;
        }

        // Tag the row as owned by this plugin so queries identify it by name
        $this->settingsDao()->updateNotificationSetting($notification->getId(), self::SETTING_PLUGIN_NAME, self::PLUGIN_NAME);

        foreach ($extraParams as $name => $value) {
            is_array($value)
                ? $this->settingsDao()->updateNotificationSetting($notification->getId(), $name, $value['value'], false, $value['type'])
                : $this->settingsDao()->updateNotificationSetting($notification->getId(), $name, $value);
        }
    }

    protected function submissionQuery(int $submissionId)
    {
        return Notification::withType(Notification::NOTIFICATION_TYPE_PLUGIN_BASE)
            ->withUserId(null)
            ->withAssoc(Application::ASSOC_TYPE_SUBMISSION, $submissionId);
    }

    /**
     * Load a notification's stored settings (contents, fileName, pluginName)
     *
     * @return array<string,mixed> setting name => value
     */
    protected function settingsFor(Notification $notification): array
    {
        return $this->settingsDao()->getNotificationSettings($notification->getId());
    }

    /**
     * Whether the given stored notification is one of this plugin's rows
     */
    protected function belongsToPlugin(Notification $notification): bool
    {
        return ($this->settingsFor($notification)[self::SETTING_PLUGIN_NAME] ?? null) === self::PLUGIN_NAME;
    }

    protected function settingsDao(): NotificationSettingsDAO
    {
        return DAORegistry::getDAO('NotificationSettingsDAO');
    }
}
