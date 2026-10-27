<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services;

use App\Services\CountryDefs;

/**
 * rev/mv-marketing — per-vertical landing-page definition loader (the
 * industry pages at `industries/{slug}`), modelled exactly on
 * {@see CountryDefs}. Each page is a plain-PHP array file under
 * resources/views/marketing/data/vertical-defs/{slug}.php
 * with per-locale content blocks (`en`, `ar`, …). The route whitelist IS the
 * set of files in that directory: no defs file, no page (`industries/{slug}`
 * 404s for an un-authored vertical), which also blocks a fabricated industry
 * from ever being served.
 *
 * The directory is resolved through config so the feature test can point the
 * loader at a fixtures dir — the same idiom as CountryDefs/CompareDefs/GuideDefs.
 */
class VerticalLandingDefs
{
    public static function dir(): string
    {
        $override = config('seo.vertical_defs_path');

        return is_string($override) && $override !== ''
            ? $override
            : resource_path('views/marketing/data/vertical-defs');
    }

    /**
     * Whitelisted slugs = the *.php basenames in the defs dir.
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
     * Load a vertical's defs, or null when the slug is not whitelisted. The
     * slug regex blocks path traversal before any file touch.
     *
     * @return array<string,mixed>|null
     */
    public static function find(string $slug): ?array
    {
        if (! preg_match('/^[a-z0-9-]+$/', $slug)) {
            return null;
        }

        $file = self::dir().'/'.$slug.'.php';
        if (! is_file($file)) {
            return null;
        }

        $data = require $file;
        if (! is_array($data)) {
            return null;
        }

        $data['slug'] = $slug;

        return $data;
    }

    /**
     * The locale-appropriate content block, falling back to the def's declared
     * `_default`, then `en`, then the first block present.
     *
     * @param  array<string,mixed>  $def
     * @return array<string,mixed>
     */
    public static function localize(array $def, string $locale): array
    {
        $default = is_string($def['_default'] ?? null) ? $def['_default'] : 'en';
        $block = $def[$locale] ?? $def[$default] ?? $def['en'] ?? null;

        if (! is_array($block)) {
            foreach ($def as $k => $v) {
                if ($k !== 'slug' && $k !== '_default' && is_array($v)) {
                    $block = $v;
                    break;
                }
            }
        }

        return is_array($block) ? $block : [];
    }
}
