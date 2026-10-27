<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use App\Models\ThemeVersion;

/**
 * P2-9: parses the `mp:<slug>@<version>` value stored in `store_template`
 * for marketplace/Liquid themes and locates the version on disk. Legacy plain
 * slugs return null and take the unchanged first-party Blade path.
 *
 * IMPORTANT integration note: call this with the RAW option value, before
 * `resolve_store_theme()` — that helper rewrites unknown slugs (anything
 * `mp:`-prefixed) to the fallback Blade theme.
 *
 * Lookup order: the P2-6 marketplace store (storage/) first, then the in-repo
 * reference-theme dir (first-party ports like crave live in the repo).
 */
class MarketplaceThemeResolver
{
    /**
     * @param  bool  $gate  P2-6: when a DB row exists for this slug@version,
     *                      serve it only if PUBLISHED (review/rejected/delisted
     *                      versions never reach a storefront). Reference themes
     *                      without rows are unaffected. Pass false for internal
     *                      paths (installer, review pipeline preview).
     */
    public static function resolve(?string $raw, bool $gate = true): ?MarketplaceTheme
    {
        $raw = (string) $raw;
        if (! str_starts_with($raw, 'mp:')) {
            return null;
        }

        // Strict slug@version — regex is the traversal guard (no /, no dots-runs).
        if (! preg_match('/^mp:([a-z0-9][a-z0-9\-]*)@([a-z0-9][a-z0-9.\-]*)$/i', $raw, $m)) {
            return null;
        }
        [, $slug, $version] = $m;

        if ($gate && ! static::versionServable($slug, $version)) {
            return null;
        }

        foreach (static::basePaths() as $base) {
            $dir = $base.'/'.$slug.'/'.$version;
            // Entry point: single-file (index.liquid) or OS 2.0 sectioned (index.json).
            if (is_file($dir.'/templates/index.liquid') || is_file($dir.'/templates/index.json')) {
                return new MarketplaceTheme($slug, $version, $dir);
            }
        }

        return null;
    }

    /** No DB row → servable (in-repo reference themes); row → must be published. */
    protected static function versionServable(string $slug, string $version): bool
    {
        try {
            $row = ThemeVersion::query()
                ->whereHas('theme', fn ($q) => $q->where('slug', strtolower($slug)))
                ->where('semver', $version)
                ->first();

            return $row === null || $row->status === 'published';
        } catch (\Throwable $e) {
            report($e); // schema missing/unreachable — behave like no ledger

            return true;
        }
    }

    /** @return list<string> */
    protected static function basePaths(): array
    {
        return [
            storage_path('app/marketplace-themes'),   // P2-6 versioned store
            resource_path('liquid-themes'),           // in-repo reference themes
        ];
    }
}
