<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services;

/**
 * SEO Growth W5.2/W5.3 — long-form compliance-guide loader.
 *
 * Each guide is a plain-PHP array file under
 * resources/views/marketing/data/guide-defs/{slug}.php,
 * with per-locale content blocks (at minimum `en`; `ar` where the guide is
 * bilingual). The route whitelist IS the set of files in that directory.
 *
 * The directory is resolved through config so the feature test can point the
 * loader at a fixtures dir without shipping a stub guide live.
 */
class GuideDefs
{
    public static function dir(): string
    {
        $override = config('seo.guide_defs_path');

        return is_string($override) && $override !== ''
            ? $override
            : resource_path('views/marketing/data/guide-defs');
    }

    /**
     * Whitelisted guide slugs = the *.php basenames in the defs dir.
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
     * Load a guide's defs, or null when the slug is not whitelisted. The slug
     * regex blocks path traversal before any file touch.
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
     * Resolve the locale-appropriate content block. Guides ship `en` (+ `ar`
     * where bilingual); any other request locale falls back to `en`.
     *
     * @param  array<string,mixed>  $guide
     * @return array<string,mixed>
     */
    public static function localize(array $guide, string $locale): array
    {
        $block = $guide[$locale] ?? $guide['en'] ?? null;

        // Single-locale guides (e.g. a TR-only or ES-only compliance guide) have
        // neither the requested locale nor `en`; fall back to the first content
        // block present so the page never renders empty.
        if (! is_array($block)) {
            foreach ($guide as $key => $value) {
                if (in_array($key, ['slug', 'country'], true)) {
                    continue;
                }
                if (is_array($value) && isset($value['title'])) {
                    $block = $value;
                    break;
                }
            }
        }

        return is_array($block) ? $block : [];
    }
}
