<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use Juwri\Core\Models\Store;
use App\Services\ThemeCustomizerService;

/**
 * Theme Studio B0.3: the values behind the Liquid `settings` global — the
 * theme-level settings a theme declares in config/settings_schema.json.
 *
 *   schema defaults ← published customizer values ← layout `theme.settings`
 *
 * Only ids the schema declares are ever exposed, and only scalars, so neither
 * stale customizer keys nor `sections.*` values leak into templates.
 */
class ThemeSettingValues
{
    /**
     * @param  array<string,mixed>|null  $customizer  published theme_settings.settings (flat)
     * @param  array<string,mixed>|null  $layout  published store layout document
     * @return array<string,scalar|null>
     */
    public static function resolve(MarketplaceTheme $theme, ?array $customizer = null, ?array $layout = null): array
    {
        $defs = static::definitions($theme);
        if ($defs === []) {
            return [];
        }

        $values = [];
        foreach ($defs as $id => $def) {
            if (array_key_exists('default', $def) && static::isScalar($def['default'])) {
                $values[$id] = $def['default'];
            }
        }

        $layoutSettings = is_array($layout['theme']['settings'] ?? null) ? $layout['theme']['settings'] : [];
        foreach ([$customizer ?? [], $layoutSettings] as $source) {
            foreach ($source as $id => $value) {
                if (isset($defs[$id]) && static::isScalar($value)) {
                    $values[$id] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * Validate layout `theme.settings` against the schema (same rules as
     * section settings). With a store, picker ids and media filenames must be
     * that store's own rows. Returns [id => error].
     *
     * @param  array<string,mixed>  $values  sanitized first (sanitize())
     * @return array<string,string>
     */
    public static function validate(MarketplaceTheme $theme, array $values, ?Store $store = null): array
    {
        return ThemeCustomizerService::validateSectionSettings(static::schema($theme), $values, $store);
    }

    /**
     * Canonicalize layout `theme.settings` (richtext allow-list, numeric
     * strings → numbers, trimmed urls) — call before validate().
     *
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    public static function sanitize(MarketplaceTheme $theme, array $values): array
    {
        return ThemeCustomizerService::sanitizeSectionSettings(static::schema($theme), $values);
    }

    /**
     * The theme-level settings as one {% schema %}-shaped section.
     *
     * @return array{settings:list<array<string,mixed>>}
     */
    protected static function schema(MarketplaceTheme $theme): array
    {
        return ['settings' => array_values(static::definitions($theme))];
    }

    /**
     * Setting definitions keyed by id, flattened across the schema's groups.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function definitions(MarketplaceTheme $theme): array
    {
        $defs = [];
        foreach ($theme->configSettingsSchema() ?? [] as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach ($group['settings'] ?? $group['fields'] ?? [] as $def) {
                if (is_array($def) && ! empty($def['id']) && is_string($def['id'])) {
                    $defs[$def['id']] = $def;
                }
            }
        }

        return $defs;
    }

    protected static function isScalar(mixed $value): bool
    {
        return $value === null || is_scalar($value);
    }
}
