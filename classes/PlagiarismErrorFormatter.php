<?php

/**
 * @file plugins/generic/plagiarism/classes/PlagiarismErrorFormatter.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PlagiarismErrorFormatter
 *
 * @brief Formats iThenticate processing error messages for display
 */

namespace APP\plugins\generic\plagiarism\classes;

class PlagiarismErrorFormatter
{
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
     * Build a translatable error descriptor to persist on the entity. The locale key and its params
     * are stored (not the resolved text) so the message is translated at view time in the viewer's
     * locale — see resolve(). A param value may itself be a descriptor (resolved recursively); an
     * optional non-translatable $detail (e.g. an iThenticate HTTP status/reason) is wrapped in via
     * the translatable error.withDetail format.
     *
     * @param array<string,mixed> $params
     * @return array{key:string,params?:array,detail?:string}
     */
    public static function make(string $key, array $params = [], ?string $detail = null): array
    {
        $descriptor = ['key' => $key];

        if ($params) {
            $descriptor['params'] = $params;
        }

        if ($detail !== null && $detail !== '') {
            $descriptor['detail'] = $detail;
        }

        return $descriptor;
    }

    /**
     * Resolve a stored error descriptor into a translated, display-ready string in the current
     * request locale. Params that are themselves descriptors are resolved recursively. A plain
     * string is returned as-is (defensive: tolerates any legacy/pre-i18n resolved value).
     *
     * @param array{key:string,params?:array,detail?:string}|string|null $descriptor
     */
    public static function resolve(array|string|null $descriptor): string
    {
        if (!is_array($descriptor)) {
            return (string) $descriptor;
        }

        // Legacy/pre-i18n entries stored the already-resolved text under 'message'.
        if (!isset($descriptor['key'])) {
            return (string) ($descriptor['message'] ?? '');
        }

        $params = array_map(
            fn ($value) => (is_array($value) && isset($value['key'])) ? self::resolve($value) : $value,
            $descriptor['params'] ?? []
        );

        return self::withDetail(__($descriptor['key'], $params), $descriptor['detail'] ?? null);
    }
}
