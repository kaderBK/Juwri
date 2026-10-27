<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio;

use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;

/**
 * Theme Studio B0.2: read and write a store's layout document for a sectioned
 * theme. Stored on the store's `theme_settings` row (versionless `mp:<slug>`
 * key, like the customizer values), so a theme update keeps the layout.
 * Publishing, discarding and reverting go through ThemeCustomizerService,
 * which carries the layout alongside the settings.
 */
class StoreLayouts
{
    /** C5: post option holding the owner's pending layout-migration notices (JSON list). */
    public const NOTICES_OPTION = 'studio_notices';

    /** C5: keep at most this many notices. */
    protected const NOTICES_MAX = 20;

    /**
     * The layout to render: the draft when asked for (falling back to the
     * published one), else the published layout; null = theme default.
     *
     * @return array<string,mixed>|null
     */
    public static function forStore(int $storeId, MarketplaceTheme $theme, bool $draft = false): ?array
    {
        $row = ThemeSetting::where('store_id', $storeId)
            ->where('template', self::key($theme))
            ->first(['layout', 'layout_draft']);
        if (! $row) {
            return null;
        }

        $layout = $draft ? ($row->layout_draft ?? $row->layout) : $row->layout;

        return is_array($layout) ? $layout : null;
    }

    /**
     * Normalize + sanitize + validate (store-scoped) an incoming layout and
     * store it as the draft.
     *
     * @param  array<string,mixed>  $raw
     *
     * @throws ValidationException on any structural limit or schema violation
     */
    public static function saveDraft(Store $store, MarketplaceTheme $theme, array $raw): ThemeSetting
    {
        if (! $theme->isSectioned()) {
            throw ValidationException::withMessages(['layout' => 'This theme has no sections to arrange.']);
        }

        try {
            $layout = LayoutDocument::normalize($raw);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['layout' => $e->getMessage()]);
        }

        // Canonicalize first (what is stored is exactly what passed), then
        // validate against THIS store: pickers may only name its own rows.
        $layout = LayoutDocument::sanitize($theme, $layout);
        $layout['i18n'] = LayoutDocument::sanitizeI18n($theme, $layout, $store);
        $errors = LayoutDocument::validate($theme, $layout, $store);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $ts = ThemeSetting::firstOrNew(['store_id' => $store->id, 'template' => self::key($theme)]);
        if (! $ts->exists) {
            $ts->settings = [];
        }
        $ts->layout_draft = $layout;
        $ts->save();

        return $ts;
    }

    /**
     * Theme Studio C1: the theme's own templates/index.json as a normalized
     * layout document — what a store without a layout renders.
     *
     * @return array<string,mixed>
     */
    public static function themeDefault(MarketplaceTheme $theme): array
    {
        if (! $theme->isSectioned()) {
            return LayoutDocument::normalize([]);
        }

        try {
            return LayoutDocument::normalize($theme->templateJson());
        } catch (\InvalidArgumentException $e) {
            report($e);

            return LayoutDocument::normalize([]);
        }
    }

    /**
     * Theme Studio C1: the layout the editor works on — the draft, else the
     * published layout, else the theme default. Always a full document.
     *
     * @return array<string,mixed>
     */
    public static function editing(int $storeId, MarketplaceTheme $theme): array
    {
        return self::forStore($storeId, $theme, true) ?? self::themeDefault($theme);
    }

    /**
     * Theme Studio C1: the live layout (published, else theme default).
     *
     * @return array<string,mixed>
     */
    public static function published(int $storeId, MarketplaceTheme $theme): array
    {
        return self::forStore($storeId, $theme) ?? self::themeDefault($theme);
    }

    /** Theme Studio C1: does the store have an unpublished layout edit? */
    public static function hasDraft(int $storeId, MarketplaceTheme $theme): bool
    {
        return ThemeSetting::where('store_id', $storeId)
            ->where('template', self::key($theme))
            ->whereNotNull('layout_draft')
            ->exists();
    }

    /**
     * C4: the store's menu languages — `default` (its text lives in the layout
     * settings) and every `codes` entry (default first).
     *
     * @return array{default:string,codes:list<string>}
     */
    public static function languages(Store $store): array
    {
        $options = store_options($store->id);
        $default = strtolower((string) ($options->default_language ?? config('app.locale')));
        $codes = $options->menu_languages ?? [];
        if (is_string($codes)) {
            $decoded = json_decode($codes, true);
            $codes = is_array($decoded) ? $decoded : [$codes];
        }
        $codes = array_map('strtolower', array_filter(array_map('strval', is_array($codes) ? $codes : (array) $codes)));

        return ['default' => $default, 'codes' => array_values(array_unique(array_merge([$default], $codes)))];
    }

    /**
     * C5: bring the store's published + draft layouts for this theme in line
     * with the theme's current section code (LayoutDocument::migrate): removed
     * section / block types are dropped, renamed settings follow the schema's
     * `migrations` map. Every removal becomes an owner notice (post option
     * NOTICES_OPTION) the editor shows until dismissed. Idempotent.
     *
     * @return list<array<string,string>> the notices this run added
     */
    public static function migrate(Store $store, MarketplaceTheme $theme): array
    {
        if (! $theme->isSectioned()) {
            return [];
        }
        $row = ThemeSetting::where('store_id', $store->id)->where('template', self::key($theme))->first();
        if (! $row) {
            return [];
        }

        $notices = [];
        $dirty = false;
        foreach (['layout', 'layout_draft'] as $column) {
            $layout = $row->{$column};
            if (! is_array($layout) || ! is_array($layout['sections'] ?? null)) {
                continue;
            }
            $result = LayoutDocument::migrate($theme, $layout);
            if ($result['changed']) {
                $row->{$column} = $result['layout'];
                $dirty = true;
            }
            foreach ($result['notices'] as $notice) {
                $notices[self::noticeId($notice)] = $notice;
            }
        }
        if ($dirty) {
            $row->save();
        }
        if ($notices === []) {
            return [];
        }

        $stored = self::notices($store);
        $known = array_column($stored, 'id');
        $added = [];
        foreach ($notices as $id => $notice) {
            if (! in_array($id, $known, true)) {
                $added[] = ['id' => $id, 'theme' => $theme->slug, 'version' => (string) $theme->version, 'at' => now()->toIso8601String()] + $notice;
            }
        }
        if ($added !== []) {
            StoreOption::updateStoreOption($store->id, self::NOTICES_OPTION, json_encode(array_slice(array_merge($stored, $added), -self::NOTICES_MAX), JSON_UNESCAPED_UNICODE));
        }

        return $added;
    }

    /**
     * C5: the owner notices not dismissed yet (oldest first).
     *
     * @return list<array<string,string>>
     */
    public static function notices(Store $store): array
    {
        $raw = StoreSettings::legacy((int) $store->id, self::NOTICES_OPTION);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? array_values(array_filter($decoded, static fn ($n) => is_array($n) && isset($n['id']))) : [];
    }

    /** C5: the owner read the notices — clear them. */
    public static function dismissNotices(Store $store): void
    {
        StoreSettings::forget((int) $store->id, self::NOTICES_OPTION);
        StoreOption::flushOptionMemo();
    }

    /** @param  array<string,string>  $notice */
    protected static function noticeId(array $notice): string
    {
        return substr(sha1(implode('|', [$notice['kind'] ?? '', $notice['sid'] ?? '', $notice['bid'] ?? '', $notice['type'] ?? ''])), 0, 12);
    }

    /** The versionless storage key (`mp:<slug>`). */
    public static function key(MarketplaceTheme $theme): string
    {
        return ThemeCustomizerService::settingsKey('mp:'.$theme->slug.'@'.$theme->version);
    }
}
