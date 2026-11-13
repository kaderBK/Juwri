<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services;

/**
 * SEO Growth W4.0 — comparison-hub definition loader.
 *
 * Each competitor page is a plain-PHP array file under
 * resources/views/marketing/data/compare-defs/{slug}.php.
 * The route whitelist IS the set of files in that directory: no defs file, no
 * page (a bare /compare/{slug} 404s). This keeps the marketing surface to
 * competitors we have actually cite-checked — the run's hard rule that every
 * competitor claim is verifiable from their public site at build time.
 *
 * The directory is resolved through config so the feature test can point the
 * loader at a fixture directory without shipping a fabricated competitor live.
 */
class CompareDefs
{
    /**
     * Absolute path to the defs directory. Config override exists purely so
     * tests can substitute a fixtures dir; production always uses the theme
     * partial path.
     */
    public static function dir(): string
    {
        $override = config('seo.compare_defs_path');

        return is_string($override) && $override !== ''
            ? $override
            : resource_path('views/marketing/data/compare-defs');
    }

    /**
     * Whitelisted competitor slugs = the *.php basenames in the defs dir.
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
     * Load and normalise one competitor's defs, or null when the slug is not
     * whitelisted. The slug regex blocks path traversal before any file touch.
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

        // The filename is the canonical slug; a defs file never overrides it.
        $data['slug'] = $slug;

        return $data;
    }

    /**
     * Resolve the display block for a locale and apply field defaults.
     *
     * Two defs shapes are supported. A FLAT defs file is single-locale — its
     * top-level keys ARE the display block. A LOCALIZED defs file carries a
     * per-locale block under `en` (required) / `ar` / `tr` … plus shared
     * top-level keys (`name`, `slug`); the requested locale is picked, falling
     * back to `en`, and the shared keys are merged in.
     *
     * @param  array<string,mixed>  $cmp
     * @return array<string,mixed>
     */
    public static function present(array $cmp, string $locale): array
    {
        if (isset($cmp['en']) && is_array($cmp['en'])) {
            $block = $cmp[$locale] ?? $cmp['en'];
            if (! is_array($block)) {
                $block = $cmp['en'];
            }
            foreach (['slug', 'name'] as $shared) {
                if (! isset($block[$shared]) && isset($cmp[$shared])) {
                    $block[$shared] = $cmp[$shared];
                }
            }
            $cmp = $block;
        }

        return $cmp + self::defaults();
    }

    /**
     * Field defaults so a sparse defs file never breaks the template.
     *
     * @return array<string,mixed>
     */
    private static function defaults(): array
    {
        return [
            'eyebrow' => 'Comparison',
            'sub' => null,
            'meta_title' => null,
            'meta' => null,
            'intro' => [],
            'groups' => [],
            'better_fit' => null,
            'verdict' => null,
            'notes' => [],
            'faqs' => [],
        ];
    }

    /**
     * Footer compare links [route, label] for a list of compare slugs (null for unknown slugs).
     *
     * @param  array<int, string>  $slugs
     * @return array<int, array{0: string, 1: string}|null>
     */
    public static function footerLinks(array $slugs): array
    {
        $out = [];
        foreach ($slugs as $slug) {
            $def = self::find($slug);
            $out[] = $def ? [route('compare.show', $slug), ___('vs').' '.$def['name']] : null;
        }

        return $out;
    }
}
