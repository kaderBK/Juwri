<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\LockedTemplates;

use Juwri\Modules\Content\Models\LockedTemplatePermission;
use Juwri\Core\Settings\PlatformSettings;

/**
 * What a user may change on a locked-template page. The superadmin sets these per
 * user (locked_template_permissions); a user without a row gets the global default
 * (Option `locked_template_default_caps`), and if that was never saved, DEFAULTS.
 *
 * Enforcement is SERVER-side on save (Content::merge ignores any value the user's
 * caps don't allow) — the editor UI only hides controls as a convenience.
 */
class Permissions
{
    public const OPTION_KEY = 'locked_template_default_caps';

    /** Capability => human label (admin UI order). */
    public const CAPS = [
        'text' => 'Edit text',
        'text_format' => 'Format text (bold, italic, links, highlight)',
        'text_style' => 'Style text and icons (size, weight, colour, alignment)',
        'image' => 'Replace images and videos',
        'image_style' => 'Crop, resize and add effects to images',
        'link' => 'Change links',
        'icon' => 'Change icons',
        'color' => 'Change brand colours',
        'theme' => 'Switch light / dark',
        'items' => 'Add, duplicate, remove and reorder items (cards, lists, menu links)',
        'section_style' => 'Section background and spacing',
        'sections_toggle' => 'Show / hide sections',
        'sections_reorder' => 'Reorder sections',
        'sections_duplicate' => 'Duplicate sections',
        'sections_add' => 'Add ready-made sections from the template library',
    ];

    /**
     * Out-of-the-box default: content and styling yes, rearranging the page no.
     * Adding library sections is on: they are the template's own designed blocks,
     * inserted by the server, and removable again.
     */
    public const DEFAULTS = [
        'text' => true,
        'text_format' => true,
        'text_style' => true,
        'image' => true,
        'image_style' => true,
        'link' => true,
        'icon' => true,
        'color' => true,
        'theme' => true,
        'items' => true,
        'section_style' => true,
        'sections_toggle' => false,
        'sections_reorder' => false,
        'sections_duplicate' => false,
        'sections_add' => true,
    ];

    /** @return array<string, bool> */
    public static function defaults(): array
    {
        $raw = PlatformSettings::legacy(self::OPTION_KEY);
        $saved = is_string($raw) ? json_decode($raw, true) : null;

        // Caps added after the defaults were saved take their built-in default.
        return self::normalize(is_array($saved) ? $saved : self::DEFAULTS, self::DEFAULTS);
    }

    /** @param  array<string, mixed>  $caps */
    public static function saveDefaults(array $caps): void
    {
        PlatformSettings::set(self::OPTION_KEY, self::normalize($caps));
    }

    /** @return array<string, bool> */
    public static function forUser(?int $userId): array
    {
        $row = $userId ? LockedTemplatePermission::query()->where('user_id', $userId)->first() : null;

        // Caps added after this override was saved follow the global default.
        return $row ? self::normalize((array) $row->caps, self::defaults()) : self::defaults();
    }

    public static function hasOverride(?int $userId): bool
    {
        return $userId !== null && LockedTemplatePermission::query()->where('user_id', $userId)->exists();
    }

    /**
     * Template ids this user may start pages from; null = every published template.
     *
     * @return array<int, int>|null
     */
    public static function allowedTemplates(?int $userId): ?array
    {
        $row = $userId ? LockedTemplatePermission::query()->where('user_id', $userId)->first() : null;
        if (! $row || $row->allowed_templates === null) {
            return null;
        }

        return array_values(array_map('intval', (array) $row->allowed_templates));
    }

    public static function mayUseTemplate(?int $userId, int $templateId): bool
    {
        $allowed = self::allowedTemplates($userId);

        return $allowed === null || in_array($templateId, $allowed, true);
    }

    /**
     * @param  array<string, mixed>  $caps
     * @param  array<int, int>|null  $allowedTemplates
     */
    public static function saveForUser(int $userId, array $caps, ?array $allowedTemplates, ?int $by): void
    {
        LockedTemplatePermission::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'caps' => self::normalize($caps),
                'allowed_templates' => $allowedTemplates === null ? null : array_values(array_unique(array_map('intval', $allowedTemplates))),
                'updated_by' => $by,
            ]
        );
    }

    /** Drop a user's override so they fall back to the global default. */
    public static function resetUser(int $userId): void
    {
        LockedTemplatePermission::query()->where('user_id', $userId)->delete();
    }

    /**
     * Keep only known caps as strict booleans; unknown keys are dropped. A missing
     * key is false (deny by default) — or, when reading a stored set, the $fallback
     * value, so a capability introduced later doesn't silently deny everyone.
     * Saving always stores every key (the admin forms post the full set).
     *
     * @param  array<string, mixed>  $caps
     * @param  array<string, bool>|null  $fallback
     * @return array<string, bool>
     */
    public static function normalize(array $caps, ?array $fallback = null): array
    {
        $out = [];
        foreach (array_keys(self::CAPS) as $cap) {
            $out[$cap] = array_key_exists($cap, $caps)
                ? filter_var($caps[$cap], FILTER_VALIDATE_BOOLEAN)
                : (bool) ($fallback[$cap] ?? false);
        }

        return $out;
    }
}
