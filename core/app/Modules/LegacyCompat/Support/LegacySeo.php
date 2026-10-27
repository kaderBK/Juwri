<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Support;

use Juwri\Core\Models\Language;
use Juwri\Core\Settings\PlatformSettings;

/**
 * SEO parity of the migrated install (BL-031, BL-034): which language set the old site served and the page titles that
 * differ from the shipped copy. Every value is an install setting (config legacy_compat.seo.*); nothing names a domain
 * or brand, and with the module off or the settings empty nothing changes.
 */
final class LegacySeo
{
    /**
     * Page titles (without the brand suffix) the old site served where the shipped copy differs. Keys are the
     * `seo_title_override()` keys of the marketing views; English only (the only language compared).
     *
     * @return array<string, array<string, string>>
     */
    public const TITLES = [
        'contact' => ['en' => 'Contact Us'],
        'compare.foodics' => ['en' => 'Free Foodics Alternative: QR Menu & WhatsApp Ordering'],
    ];

    /** Merge the old titles into config('seo.title_overrides') (an explicit install override wins). Called on activation. */
    public static function applyTitles(): void
    {
        if (! LegacyCompatRoutes::enabled() || ! config('legacy_compat.seo.title_parity', true)) {
            return;
        }
        $current = (array) config('seo.title_overrides', []);
        $merged = self::TITLES;
        foreach ($current as $key => $perLocale) {
            $merged[$key] = array_replace($merged[$key] ?? [], (array) $perLocale);
        }
        config(['seo.title_overrides' => $merged]);
    }

    /**
     * The configured language set; null when none is configured (nothing to apply).
     *
     * @return array{default: string, locales: list<string>, prefix: bool}|null
     */
    public static function plan(): ?array
    {
        $default = (string) config('legacy_compat.seo.locale_default', '');
        $locales = array_values(array_unique(array_map('strtolower', (array) config('legacy_compat.seo.locales', []))));
        if ($default === '' && $locales === []) {
            return null;
        }
        if ($default !== '' && ! in_array($default, $locales, true)) {
            array_unshift($locales, $default);
        }

        return [
            'default' => $default !== '' ? $default : $locales[0],
            'locales' => $locales,
            'prefix' => (bool) config('legacy_compat.seo.locale_prefix', true),
        ];
    }

    /**
     * Compare (and with $apply set, write) the platform settings and language rows against the plan.
     *
     * @param  array{default: string, locales: list<string>, prefix: bool}  $plan
     * @return array{plan: array{default: string, locales: list<string>, prefix: bool}, changes: list<string>, missing: list<string>, hreflang: int, ok: bool}
     */
    public static function reconcile(array $plan, bool $apply): array
    {
        $changes = [];
        $missing = [];

        if (PlatformSettings::string('lang', '') !== $plan['default']) {
            $changes[] = "lang: set to {$plan['default']}";
            $apply && PlatformSettings::set('lang', $plan['default']);
        }
        if (PlatformSettings::bool('include_language_code', false) !== $plan['prefix']) {
            $changes[] = 'include_language_code: '.($plan['prefix'] ? 'on' : 'off');
            $apply && PlatformSettings::set('include_language_code', $plan['prefix'] ? '1' : '0');
        }

        $known = [];
        foreach (Language::query()->get() as $row) {
            $known[strtolower((string) $row->code)] = true;
            $want = in_array(strtolower((string) $row->code), $plan['locales'], true);
            if ((bool) $row->active !== $want) {
                $changes[] = "language {$row->code}: ".($want ? 'activate' : 'deactivate');
                if ($apply) {
                    $row->active = $want;
                    $row->save();
                }
            }
        }
        foreach ($plan['locales'] as $code) {
            if (! isset($known[$code])) {
                $missing[] = $code;
            }
        }

        return [
            'plan' => $plan,
            'changes' => $changes,
            'missing' => $missing,
            'hreflang' => $plan['prefix'] ? count($plan['locales']) + 1 : 0,
            'ok' => $missing === [] && ($apply || $changes === []),
        ];
    }
}
