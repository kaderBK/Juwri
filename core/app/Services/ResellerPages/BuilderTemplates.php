<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\ResellerPages;

/**
 * The html-mode builder's template gallery. Each template is a folder under
 * assets/builder/templates/<id>/ holding template.html (an HTML fragment: a scoped
 * <style> block + markup, what a new draft starts from), index.json (title,
 * description, optional `surface`) and an optional thumb.svg/png/jpg.
 *
 * The editor (GrapesJS, juwri WI-05d) loads a draft from its HTML, so a template needs
 * no editor-specific state: design() is null and a page's stored `base` is only a
 * record of where it started. Ids are kept stable across the Builder.js → GrapesJS
 * switch so existing rows' `base` stays valid.
 */
class BuilderTemplates
{
    /** The blank canvas (always listed first). */
    public const BLANK = 'Y0_Blank';

    /** Prefix of the storefront custom-block templates (Theme Studio phase A). */
    public const STOREFRONT_PREFIX = 'storefront-';

    private const REL = 'assets/builder/templates';

    /** Filesystem dir (repo-root/assets, one level above the Laravel app root). */
    private static function dir(): string
    {
        return dirname(base_path()).'/'.self::REL;
    }

    /** Web base for a template id (served statically at /assets/...). */
    public static function baseUrl(string $id): string
    {
        return asset(self::REL.'/'.$id.'/');
    }

    /** True when the id is a real, safe template dir (no traversal). */
    public static function exists(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $id)
            && is_file(self::dir().'/'.$id.'/template.html');
    }

    /**
     * Gallery list: [{id, title, description, thumb, baseUrl}] for the picker + editor themes tab.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        // Storefront block templates (storefront-*) belong to the custom-blocks
        // surface only — page/bio/CMS galleries never list them.
        return self::gallery(fn (string $id) => ! str_starts_with($id, self::STOREFRONT_PREFIX));
    }

    /**
     * The gallery shown on one authoring surface. `storefront` (custom blocks) =
     * the blank canvas + every storefront-* template; anything else = all().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forSurface(string $surface): array
    {
        if ($surface !== 'storefront') {
            return self::all();
        }

        return self::gallery(fn (string $id) => $id === self::BLANK || str_starts_with($id, self::STOREFRONT_PREFIX));
    }

    /**
     * @param  callable(string): bool  $keep
     * @return array<int, array<string, mixed>>
     */
    private static function gallery(callable $keep): array
    {
        $base = self::dir();
        if (! is_dir($base)) {
            return [];
        }
        $out = [];
        foreach (scandir($base) ?: [] as $id) {
            if (! self::exists($id) || ! $keep($id)) {
                continue;
            }
            $meta = self::meta($id);
            $out[] = [
                'id' => $id,
                'title' => $meta['title'] ?? $id,
                'description' => $meta['description'] ?? '',
                'thumb' => self::thumbUrl($id),
                'baseUrl' => self::baseUrl($id),
            ];
        }
        // Blank first, then alphabetical by title.
        usort($out, fn ($a, $b) => ($a['id'] === self::BLANK ? -1 : ($b['id'] === self::BLANK ? 1 : strcmp($a['title'], $b['title']))));

        return $out;
    }

    /**
     * index.json metadata for a template.
     *
     * @return array<string, mixed>
     */
    public static function meta(string $id): array
    {
        if (! self::exists($id)) {
            return [];
        }
        $f = self::dir().'/'.$id.'/index.json';

        return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    }

    /**
     * Editor state a new draft starts with. Always null: the editor builds its state
     * from the template's HTML (kept for the callers that seed `design_json`).
     *
     * @return array<string, mixed>|null
     */
    public static function design(string $id): ?array
    {
        return null;
    }

    /** The template's HTML fragment — seeds a new draft's html. */
    public static function html(string $id): string
    {
        if (! self::exists($id)) {
            return '';
        }

        return (string) file_get_contents(self::dir().'/'.$id.'/template.html');
    }

    private static function thumbUrl(string $id): ?string
    {
        foreach (['svg', 'png', 'jpg'] as $ext) {
            if (is_file(self::dir().'/'.$id.'/thumb.'.$ext)) {
                return asset(self::REL.'/'.$id.'/thumb.'.$ext);
            }
        }

        return null;
    }
}
