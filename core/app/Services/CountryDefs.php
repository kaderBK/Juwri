<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

/**
 * SEO Growth W3.0 — country landing-page definition loader.
 *
 * Each country page is a plain-PHP array file under
 * resources/views/marketing/data/country-defs/{cc}.php,
 * with per-locale content blocks (at least one of `en`/`ar`; a country ships
 * whichever languages its SERP wants — see KEYWORDS §Countries). The route
 * whitelist IS the set of files in that directory: no defs file, no page
 * (`for/{country}` 404s for an un-authored country).
 *
 * The directory is resolved through config so the feature test can point the
 * loader at a fixtures dir without shipping a stub country live — the same
 * idiom as CompareDefs / GuideDefs.
 */
class CountryDefs
{
    public static function dir(): string
    {
        $override = config('seo.country_defs_path');

        return is_string($override) && $override !== ''
            ? $override
            : resource_path('views/marketing/data/country-defs');
    }

    /**
     * Whitelisted country codes = the *.php basenames in the defs dir.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        $dir = self::dir();
        if (! is_dir($dir)) {
            return [];
        }

        $slugs = [];
        foreach (glob($dir.'/*.php') ?: [] as $path) {
            $slugs[] = pathinfo($path, PATHINFO_FILENAME);
        }
        sort($slugs);

        return $slugs;
    }

    /**
     * Load a country's defs, or null when the code is not whitelisted. The code
     * regex blocks path traversal before any file touch.
     *
     * @return array<string,mixed>|null
     */
    public static function find(string $cc): ?array
    {
        if (! preg_match('/^[a-z0-9-]+$/', $cc)) {
            return null;
        }

        $file = self::dir().'/'.$cc.'.php';
        if (! is_file($file)) {
            return null;
        }

        $data = require $file;
        if (! is_array($data)) {
            return null;
        }

        $data['cc'] = $cc;

        return $data;
    }

    /**
     * Resolve the locale-appropriate content block. A country ships its own
     * primary language(s); the request locale wins when present, else the defs'
     * declared default (`_default`), else the first available block.
     *
     * @param  array<string,mixed>  $country
     * @return array<string,mixed>
     */
    public static function localize(array $country, string $locale): array
    {
        $default = is_string($country['_default'] ?? null) ? $country['_default'] : 'en';

        $block = $country[$locale] ?? $country[$default] ?? $country['en'] ?? null;

        // No matching/declared block — fall back to the first content block present.
        if (! is_array($block)) {
            foreach ($country as $k => $v) {
                if ($k !== 'cc' && $k !== '_default' && is_array($v)) {
                    $block = $v;
                    break;
                }
            }
        }

        return is_array($block) ? $block : [];
    }

    /**
     * cc => display name for the footer/nav "Regions" label. This is a
     * NAVIGATIONAL label, so it follows the UI locale — not the page's content
     * language. A FR-first page (ma/tn) must still read "Morocco"/"Tunisia" on
     * the English site, so we take the region_name from the EXACT locale block
     * when one exists, otherwise a canonical English name (never the fallback
     * block's language, which is how "Maroc"/"Tunisie" leaked onto /en).
     */
    private const CANONICAL_EN = [
        'sy' => 'Syria', 'jo' => 'Jordan', 'sa' => 'Saudi Arabia', 'ae' => 'United Arab Emirates',
        'eg' => 'Egypt', 'ma' => 'Morocco', 'tn' => 'Tunisia', 'pk' => 'Pakistan',
        'sg' => 'Singapore', 'my' => 'Malaysia',
    ];

    /**
     * Footer "Regions" column: [cc => localized short name] for every
     * whitelisted country, ready to link to route('country.landing', cc).
     * Empty until the first country defs file ships (W3.1+).
     *
     * @return array<string,string>
     */
    public static function menu(string $locale): array
    {
        $out = [];
        foreach (self::slugs() as $cc) {
            $def = self::find($cc);
            if ($def === null) {
                continue;
            }
            // exact-locale block wins (its region_name is truly in $locale);
            // else the canonical English name; else the uppercased code.
            $exact = is_array($def[$locale] ?? null)
                ? ($def[$locale]['region_name'] ?? $def[$locale]['name'] ?? null)
                : null;
            $out[$cc] = (string) ($exact ?: (self::CANONICAL_EN[$cc] ?? strtoupper($cc)));
        }

        return $out;
    }
}
