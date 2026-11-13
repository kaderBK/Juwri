<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;

/**
 * P6-09 V-19: the AR/EN language of a phone agent is a property of the LINE, not of the
 * request. An explicit `phone_lines.language` (en|ar) wins; otherwise the store's
 * `default_language` decides ("ar…" → ar, everything else → en). Every user-facing voice
 * string is rendered through {@see with()} / {@see withLocale()} so a caller hears the
 * line's language even when the inbound request locale is something else.
 */
final class VoiceLocale
{
    /** Locales the phone agent speaks. */
    public const LOCALES = ['en', 'ar'];

    /** Fallback when neither the line nor the store names a locale. */
    public const DEFAULT = 'en';

    /** The line's language: its own setting, else the store default, else en. */
    public static function forLine(PhoneLine $line): string
    {
        $language = (string) ($line->language ?? '');

        return in_array($language, self::LOCALES, true) ? $language : self::forStore((int) $line->store_id);
    }

    /** The store default language ("ar…" → ar, otherwise en). Never throws. */
    public static function forStore(int $storeId): string
    {
        try {
            $default = (string) (store_options($storeId)->default_language ?? '');
        } catch (\Throwable $e) {
            $default = '';
        }

        return str_starts_with($default, 'ar') ? 'ar' : self::DEFAULT;
    }

    /** The language for a call, resolved through the line it opened on (store when the line is gone). */
    public static function forCallLog(CallLog $log): string
    {
        $line = $log->phone_line_id ? PhoneLine::find($log->phone_line_id) : null;

        return $line !== null ? self::forLine($line) : self::forStore((int) $log->store_id);
    }

    /** Run $fn with the line's locale active, restoring the previous locale afterwards. */
    public static function with(PhoneLine $line, callable $fn): mixed
    {
        return self::withLocale(self::forLine($line), $fn);
    }

    /** Run $fn under an explicit locale ("en"/"ar"), restoring the previous locale afterwards. */
    public static function withLocale(string $locale, callable $fn): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            return $fn();
        } finally {
            app()->setLocale($previous);
        }
    }
}
