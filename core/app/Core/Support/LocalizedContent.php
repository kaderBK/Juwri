<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * The ONE place that resolves a per-locale content field to a display string.
 * No other code re-implements locale fallback — Blade partials and controllers
 * call resolve()/resolveBlocks() here.
 *
 * Universal (flat string) fields pass through unchanged. Localized fields are
 * {locale: value} maps resolved as: exact locale → fallback locale →
 * first non-empty value → ''. NEVER returns null.
 */
class LocalizedContent
{
    /**
     * @param  array<string,mixed>|string|null  $field
     */
    public static function resolve($field, string $locale, ?string $fallback = null): string
    {
        // Universal field (already a flat string) or nullish.
        if ($field === null) {
            return '';
        }
        if (is_string($field)) {
            return $field;
        }
        if (!is_array($field)) {
            return (string) $field;
        }

        $fallback = $fallback ?: (string) config('app.locale', 'en');

        // Exact locale.
        if (isset($field[$locale]) && self::nonEmpty($field[$locale])) {
            return (string) $field[$locale];
        }
        // Fallback locale.
        if (isset($field[$fallback]) && self::nonEmpty($field[$fallback])) {
            return (string) $field[$fallback];
        }
        // First non-empty value.
        foreach ($field as $value) {
            if (self::nonEmpty($value)) {
                return (string) $value;
            }
        }
        return '';
    }

    /** Which locales have any non-empty content across a page's blocks (for editor tab badges). */
    public static function localesOf(?array $blocks): array
    {
        $locales = [];
        foreach ($blocks['blocks'] ?? ($blocks ?? []) as $block) {
            foreach (($block['content'] ?? []) as $field) {
                if (is_array($field)) {
                    foreach ($field as $loc => $value) {
                        if (self::nonEmpty($value)) {
                            $locales[$loc] = true;
                        }
                    }
                }
            }
        }
        return array_keys($locales);
    }

    private static function nonEmpty($value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }
}
