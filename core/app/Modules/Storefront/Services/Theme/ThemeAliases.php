<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Theme;

use App\Services\ThemeCustomizerService;
use Juwri\Modules\Storefront\Models\ThemeSetting;

/**
 * P3-12 — the alias map from an old theme slug to a kit theme + preset (config/theme-successors.php).
 *
 *   target()     the alias entry of a stored slug, only while it applies: aliases on, the slug mapped, and the kit theme
 *                built (it has Blade views). A row whose kit theme is not built yet returns null: the old theme renders.
 *   effective()  the template a stored slug renders as (the kit slug, or the stored one unchanged)
 *   values()     the theme-customizer values of an aliased store: the preset's values, overridden by whatever the store
 *                saved under its old template for a field the kit theme also declares, overridden by anything saved
 *                under the kit theme's own key (the owner switched to it, or customised it after the alias)
 *
 * Nothing is rewritten in the database: the stored slug stays what the owner chose; only the reading side maps it.
 */
final class ThemeAliases
{
    public static function enabled(): bool
    {
        return (bool) config('theme-successors.enabled', true);
    }

    /** @return array{theme: string, preset: string}|null the configured row of a slug (whether or not its kit theme is built) */
    public static function entry(?string $slug): ?array
    {
        $slug = (string) $slug;
        if ($slug === '' || ! self::enabled()) {
            return null;
        }
        $row = config('theme-successors.map.'.$slug);

        return is_array($row) && count($row) === 2 ? ['theme' => (string) $row[0], 'preset' => (string) $row[1]] : null;
    }

    /** @return array{theme: string, preset: string}|null */
    public static function target(?string $slug): ?array
    {
        $entry = self::entry($slug);
        if ($entry === null || ! KitThemes::isKit($entry['theme']) || ! view()->exists('store_templates.'.$entry['theme'].'.index')) {
            return null;
        }

        return $entry;
    }

    public static function effective(?string $stored): string
    {
        return self::target($stored)['theme'] ?? (string) $stored;
    }

    /**
     * @return array<string, mixed>|null null when the stored slug is not aliased (the caller reads the store's own row)
     */
    public static function values(int $storeId, ?string $stored, bool $withDraft = false): ?array
    {
        $target = self::target($stored);
        if ($target === null) {
            return null;
        }
        $theme = $target['theme'];
        $schema = ThemeCustomizerService::schemaFor($theme);
        $values = ThemeCustomizerService::presetValues($schema, $target['preset']) ?? ThemeCustomizerService::defaults($schema);

        $old = ThemeSetting::query()->where('store_id', $storeId)->where('template', ThemeCustomizerService::settingsKey((string) $stored))->first();
        if ($old !== null) {
            $saved = $old->settings;
            if ($withDraft && is_array($old->draft)) {
                $saved = array_merge($saved, $old->draft);
            }
            $saved = array_intersect_key($saved, ThemeCustomizerService::fields($schema));
            // a value the kit theme's schema refuses never reaches a page
            $saved = array_diff_key($saved, ThemeCustomizerService::validate($schema, $saved));
            $values = array_merge($values, $saved);
        }

        $own = ThemeSetting::query()->where('store_id', $storeId)->where('template', $theme)->first();
        if ($own !== null) {
            $values = array_merge($values, $own->settings);
        }

        return $values;
    }
}
