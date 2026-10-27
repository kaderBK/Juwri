<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\Ai;

use App\Models\Theme;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;

/**
 * Theme Studio phase D: which Studio (library-based, sectioned) theme a
 * proposal runs on. A recipe maps to the starter theme built from it
 * (theme.json `recipe`), else to a theme of the recipe's vertical group
 * (theme.json `vertical`), else to the first Studio theme. Only FREE themes
 * are candidates (a proposal must never need a purchase), in-repo themes
 * first, then published marketplace Studio themes; `*-dev` reference themes
 * are never offered.
 */
class StudioThemes
{
    /** Recipe vertical → the theme vertical group it belongs to. */
    public const RECIPE_GROUP = [
        'restaurant' => 'food', 'cafe' => 'food', 'bakery' => 'food',
        'retail' => 'goods', 'cosmetic' => 'goods', 'clothing' => 'goods', 'carparts' => 'goods', 'pharmacy' => 'goods',
        'laundry' => 'services', 'hotel' => 'stays',
    ];

    /** @return list<MarketplaceTheme> */
    public static function all(): array
    {
        $out = [];
        $known = [];
        try {
            $rows = Theme::where('status', 'published')->get();
        } catch (\Throwable $e) {
            report($e);
            $rows = collect();
        }
        foreach ($rows as $row) {
            $known[strtolower((string) $row->slug)] = $row;
        }

        foreach (glob(resource_path('liquid-themes').'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);
            if (str_ends_with($slug, '-dev') || str_contains($slug, '-dev-')) {
                continue;
            }
            $row = $known[strtolower($slug)] ?? null;
            if ($row !== null && (float) $row->price > 0) {
                continue;
            }
            $versions = array_map('basename', glob($dir.'/*', GLOB_ONLYDIR) ?: []);
            usort($versions, 'version_compare');
            $version = end($versions);
            $theme = is_string($version) ? MarketplaceThemeResolver::resolve('mp:'.$slug.'@'.$version) : null;
            if ($theme && $theme->isSectioned() && $theme->library() !== null) {
                $out[$slug] = $theme;
            }
        }

        foreach ($known as $slug => $row) {
            if (isset($out[$slug]) || $row->isBlade() || (float) $row->price > 0) {
                continue;
            }
            $version = $row->versions()->where('status', 'published')->orderByDesc('id')->first();
            $theme = $version ? MarketplaceThemeResolver::resolve('mp:'.$row->slug.'@'.$version->semver) : null;
            if ($theme && $theme->isSectioned() && $theme->library() !== null) {
                $out[$slug] = $theme;
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<MarketplaceTheme>|null  $themes
     */
    public static function forRecipe(string $recipeId, ?array $themes = null): ?MarketplaceTheme
    {
        $themes ??= self::all();
        foreach ($themes as $theme) {
            if (($theme->manifest()['recipe'] ?? null) === $recipeId) {
                return $theme;
            }
        }
        $group = self::RECIPE_GROUP[strtok($recipeId, '/') ?: ''] ?? null;
        foreach ($themes as $theme) {
            $manifest = $theme->manifest();
            $groups = (array) ($manifest['vertical_groups'] ?? $manifest['vertical'] ?? []);
            if ($group !== null && in_array($group, $groups, true)) {
                return $theme;
            }
        }

        return $themes[0] ?? null;
    }

    public static function template(MarketplaceTheme $theme): string
    {
        return 'mp:'.$theme->slug.'@'.$theme->version;
    }
}
