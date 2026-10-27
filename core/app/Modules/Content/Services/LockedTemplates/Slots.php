<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\LockedTemplates;


/**
 * Typed slot values: validation (on save) and escaping (on render).
 *
 * The template HTML is trusted (superadmin-authored); slot VALUES are not. Every
 * value is validated against its slot type before it is stored, and escaped again
 * at render (defence in depth — a row written by an older/buggy path is still safe).
 * Placeholders only ever sit in text content or in a QUOTED attribute value
 * (the manifest validator rejects anything else), so ENT_QUOTES escaping plus URL
 * scheme allow-listing is sufficient in both contexts.
 */
class Slots
{
    public const TYPES = ['text', 'image', 'link', 'icon', 'video'];

    public const KEY_RE = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

    /** `~` appears only in keys the server derives (new items, section copies). */
    public const PLACEHOLDER_RE = '/\{\{slot:([a-z0-9][a-z0-9_.~-]{0,95})\}\}/';

    public const HEX_RE = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    /** Which permission cap governs a slot type. */
    public static function capFor(string $type): string
    {
        return match ($type) {
            'image', 'video' => 'image',     // "Replace images and videos"
            'link' => 'link',
            'icon' => 'icon',
            default => 'text',
        };
    }

    /**
     * Validate + normalise a user-supplied value for a slot. Returns null when the
     * value is not acceptable (caller keeps the previous/default value).
     *
     * @param  array<string, mixed>  $slot
     */
    public static function clean(array $slot, mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return match ((string) ($slot['type'] ?? 'text')) {
            'image' => self::cleanImageUrl($value),
            'video' => self::cleanVideoUrl($value),
            'link' => self::cleanHref($value),
            'icon' => self::cleanIcon($value),
            default => self::cleanText($value, (int) ($slot['max'] ?? 300), (bool) ($slot['multiline'] ?? false)),
        };
    }

    public static function cleanText(string $value, int $max = 300, bool $multiline = false): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // Strip control chars (keep \n only for multiline slots).
        $value = (string) preg_replace($multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $value);
        $value = trim($value);

        return mb_substr($value, 0, max(1, min($max, 5000)));
    }

    /** Image src: https URL, or a site-relative /storage/ or /assets/ path. */
    public static function cleanImageUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 500 || preg_match('/[\s"\'<>`\\\\]/', $value)) {
            return null;
        }
        if (preg_match('#^https://[a-z0-9.-]+(?::\d+)?/#i', $value)) {
            return $value;
        }
        if (preg_match('#^/(?:storage|assets)/[A-Za-z0-9._~/%-]+$#', $value) && ! str_contains($value, '..')) {
            return $value;
        }

        return null;
    }

    /** Video src: same origins as images, and a video file extension (mp4 / webm). */
    public static function cleanVideoUrl(string $value): ?string
    {
        $url = self::cleanImageUrl($value);

        return $url !== null && preg_match('#\.(?:mp4|webm)(?:[?\#][^\s"\'<>]*)?$#i', $url) ? $url : null;
    }

    /** Link href: http(s), mailto:, tel:, #anchor or a site-relative /path (not //host). */
    public static function cleanHref(string $value): ?string
    {
        $value = trim($value);
        // tel: may legitimately contain spaces; its pattern admits nothing that can
        // break out of an attribute, so it is checked before the whitespace ban.
        if (preg_match('/^tel:\+?[0-9 ().-]{3,32}$/i', $value)) {
            return $value;
        }
        if ($value === '' || strlen($value) > 500 || preg_match('/[\s"\'<>`\\\\]/', $value)) {
            return null;
        }
        if (preg_match('#^https?://[a-z0-9.-]+(?::\d+)?(?:[/?\#].*)?$#i', $value)) {
            return $value;
        }
        if (preg_match('/^mailto:[^@\s]+@[^@\s]+$/i', $value)) {
            return $value;
        }
        if (preg_match('/^#[A-Za-z0-9_-]*$/', $value)) {
            return $value;
        }
        if (preg_match('#^/(?!/)[A-Za-z0-9._~/%?=&\#-]*$#', $value)) {
            return $value;
        }

        return null;
    }

    /** Icon slot value: a library icon NAME, or '' for the template's original icon. */
    public static function cleanIcon(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return Icons::has($value) ? $value : null;
    }

    public static function cleanColor(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::HEX_RE, trim($value)) ? strtolower(trim($value)) : null;
    }

    /**
     * Escape a (validated) value for output in text or a quoted attribute.
     *
     * @param  array<string, mixed>  $slot
     */
    public static function render(array $slot, string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        if (($slot['type'] ?? 'text') === 'text' && ! empty($slot['multiline'])) {
            return nl2br($escaped, false);
        }

        return $escaped;
    }
}
