<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Services\Verticals\VerticalRegistry;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Storefront\Models\ThemeTemplateMeta;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Juwri\Modules\Storefront\Services\Theme\ThemeAliases;

/**
 * Single source of truth for menu templates (resources/views/store_templates/*).
 *
 * The filesystem stays the source of truth for the template CODE and default
 * metadata (theme-info.txt + preview.png); post_template_meta is an admin
 * overlay for display name / description / preview image / featured / enabled.
 *
 * Fallback rule: a restaurant whose saved template no longer exists on disk
 * renders the admin-configured fallback template instead of erroring.
 * Disabling a template only hides it from the user picker — restaurants
 * already using it keep rendering it (grandfathered), same model as
 * WordPress multisite network themes.
 */
class StorefrontThemeService
{
    public const LAST_RESORT = 'crave';

    /**
     * Retired template slugs that have a first-party successor rendering the
     * same store data. Checked before the generic fallback, so a store saved on
     * a removed template keeps its content instead of landing on a theme that
     * cannot show it (flipbook stores are image_menus-backed, like folio).
     *
     * @var array<string,string>
     */
    public const RETIRED_SUCCESSORS = [
        'flipbook' => 'folio',
    ];

    /**
     * rev/multi-vertical: LAST_RESORT is a FOOD theme (crave). A non-food
     * store falling all the way through to it would render restaurant chrome
     * on a retail/service storefront — offer that store's own shared theme
     * first instead, keyed by VerticalRegistry::themeSet() ('retail'|'service';
     * 'food' is intentionally absent — food keeps LAST_RESORT unchanged).
     *
     * @var array<string,string>
     */
    private const VERTICAL_LAST_RESORT = [
        'retail' => 'shelf',
        'service' => 'bookla',
    ];

    /** @var array|null request-lifetime cache of discover() */
    private ?array $discovered = null;

    /**
     * Filesystem discovery: every store_templates/<dir> that ships a
     * theme-info.txt. Same shape the user-facing picker always used:
     * [slug => ['folder','name','description','category','featured','image']]
     */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $templates = [];
        $temPaths = array_filter(glob(base_path().'/resources/views/store_templates/*'), 'is_dir');
        foreach ($temPaths as $temp) {
            $arr = explode('/', $temp);
            $tempname = end($arr);

            $filepath = public_path('assets/store_templates/'.$tempname.'/theme-info.txt');
            if (! file_exists($filepath)) {
                continue;
            }

            $themeinfo = [];
            $themefile = fopen($filepath, 'r');
            while (! feof($themefile)) {
                $lineRead = fgets($themefile);
                if (strpos($lineRead, ':') !== false) {
                    $line = explode(':', $lineRead);
                    $themeinfo[trim($line[0])] = trim($line[1]);
                }
            }
            fclose($themefile);

            $templates[$tempname] = [
                'folder' => $tempname,
                'name' => $themeinfo['Theme Name'] ?? $tempname,
                'description' => $themeinfo['Description'] ?? 'A premium template for your QR menu.',
                'category' => $themeinfo['Category'] ?? 'Classic',
                'featured' => filter_var($themeinfo['Featured'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
                'image' => $this->defaultPreviewImage($tempname),
            ];
        }

        return $this->discovered = $templates;
    }

    /**
     * Discovery merged with the admin overlay. Adds 'enabled' to each entry.
     * Templates with no meta row are enabled with their on-disk metadata.
     */
    public function all(): array
    {
        $templates = $this->discover();

        $meta = [];
        try {
            $meta = ThemeTemplateMeta::all()->keyBy('slug');
        } catch (\Throwable $e) {
            // Table not migrated yet — behave exactly as before the overlay existed.
        }

        foreach ($templates as $slug => &$tpl) {
            $tpl['enabled'] = true;
            // No meta row = newly discovered template. Sort it LAST, not first:
            // defaultSlug() falls back to array_key_first(available()) when no
            // admin default is set, so a 0 here would silently make every new
            // theme the signup default (bit us when folio shipped).
            $tpl['sort_order'] = PHP_INT_MAX;
            $row = $meta[$slug] ?? null;
            if (! $row) {
                continue;
            }
            if (! empty($row->display_name)) {
                $tpl['name'] = $row->display_name;
            }
            if (! empty($row->description)) {
                $tpl['description'] = $row->description;
            }
            if (! empty($row->preview_image)) {
                $tpl['image'] = asset('storage/template_previews/'.$row->preview_image);
            }
            if (! is_null($row->featured)) {
                $tpl['featured'] = (bool) $row->featured;
            }
            $tpl['enabled'] = (bool) $row->enabled;
            $tpl['sort_order'] = (int) $row->sort_order;
        }
        unset($tpl);

        // Stable ascending sort — rows without meta sort last and preserve
        // their discovery (alphabetical) order among themselves.
        uasort($templates, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return $templates;
    }

    /**
     * Enabled templates only — what the user-facing template picker shows.
     */
    public function available(): array
    {
        return array_filter($this->all(), fn ($tpl) => $tpl['enabled']);
    }

    /**
     * A slug is renderable when its view directory still exists on disk.
     */
    public function exists(string $slug): bool
    {
        return $slug !== ''
            && preg_match('/^[A-Za-z0-9_-]+$/', $slug)
            && view()->exists('store_templates.'.$slug.'.index');
    }

    /**
     * Admin-configured fallback, guarded so it always returns something renderable.
     *
     * rev/multi-vertical: pass the store's `store_type` so a retail/service
     * store that falls through to LAST_RESORT lands on its own vertical's
     * shared theme (shelf/bookla) instead of the food-only LAST_RESORT.
     * Omitted (existing callers) keeps the exact prior behavior — themeSet(null)
     * resolves to 'food', which never triggers the vertical branch below.
     */
    public function fallbackSlug(?string $storeType = null): string
    {
        $configured = (string) config('settings.fallback_store_theme', '');
        if ($configured !== '' && $this->exists($configured)) {
            return $configured;
        }

        $themeSet = app(VerticalRegistry::class)->themeSet($storeType);
        $verticalLastResort = self::VERTICAL_LAST_RESORT[$themeSet] ?? null;
        if ($verticalLastResort !== null && $this->exists($verticalLastResort)) {
            return $verticalLastResort;
        }

        if ($this->exists(self::LAST_RESORT)) {
            return self::LAST_RESORT;
        }
        foreach (array_keys($this->discover()) as $slug) {
            if ($this->exists($slug)) {
                return $slug;
            }
        }

        return self::LAST_RESORT;
    }

    /**
     * The template a brand-new restaurant is seeded onto.
     *
     * Distinct from fallbackSlug(): the fallback *rescues existing* stores whose
     * saved template vanished; the default simply *seeds new* stores with a
     * starting template. Guarded to an enabled, on-disk template so signups
     * never land on a hidden or broken theme.
     */
    public function defaultSlug(): string
    {
        $available = $this->available();

        $configured = (string) config('settings.default_post_template', '');
        if ($configured !== '' && isset($available[$configured])) {
            return $configured;
        }

        // ISS-B-003: with no configured default, prefer a KEPT MODERN template
        // over array_key_first($available), which returns whatever legacy theme
        // sorts first (e.g. 'Ice-cream-theme') and ships 404'd classic assets —
        // so brand-new stores rendered unstyled. This is a code-path default
        // only; it does not touch the app-wide settings.default_post_template.
        foreach (KeptTemplates::MODERN as $slug) {
            if (isset($available[$slug])) {
                return $slug;
            }
        }

        $first = array_key_first($available);
        if ($first !== null) {
            return $first;
        }

        return $this->fallbackSlug();
    }

    /**
     * Menu-content language (item / category / extra names and descriptions) is read by the models from the
     * Quick_user_lang_code cookie. The 17 modern templates switch language by the URL locale PREFIX, not that cookie, so in
     * prefix mode the cookie is pinned to the already-resolved app locale for them — content and chrome always agree.
     * Legacy templates (cookie + reload switchers) are left alone. Used by the storefront page and the order track page
     * (which printed the English item names under Arabic chrome before; found by the P1-12 Arabic journey).
     */
    public static function pinMenuContentLanguage(?string $template): void
    {
        if (config('settings.include_language_code') && KeptTemplates::isModern($template)) {
            $_COOKIE['Quick_user_lang_code'] = get_lang();
        }
    }

    /**
     * Resolve a restaurant's saved template to a renderable one. Missing or
     * deleted templates fall back; disabled-but-present templates still
     * render (grandfathered).
     *
     * rev/multi-vertical: optional `$storeType` threads through to
     * fallbackSlug() so a non-food store's fallback is vertical-aware. Existing
     * one-arg callers are unaffected (see fallbackSlug() docblock).
     */
    public function resolve(?string $requested, ?string $storeType = null): string
    {
        $requested = (string) $requested;
        // P3-12: an old theme slug renders as the kit theme it is aliased to (config/theme-successors.php), once that
        // theme is built. The old theme's files stay on disk and render again if the alias is switched off.
        $alias = ThemeAliases::target($requested);
        if ($alias !== null) {
            return $alias['theme'];
        }
        if ($requested !== '' && $this->exists($requested)) {
            return $requested;
        }
        $successor = self::RETIRED_SUCCESSORS[$requested] ?? null;
        if ($successor !== null && $this->exists($successor)) {
            return $successor;
        }

        $fallback = $this->fallbackSlug($storeType);

        return ThemeAliases::target($fallback)['theme'] ?? $fallback;
    }

    /**
     * The template a store's storefront actually renders, read from its saved
     * option: a saved slug that no longer exists maps the same way resolve()
     * does, so shared sections gated on the rendered template follow the
     * fallback. An empty saved value stays '' (callers treat it as "none").
     */
    public function resolveSaved(object $store): string
    {
        $saved = (string) (store_options($store->id)->store_template ?? '');

        return $saved === '' ? '' : $this->resolve($saved, $store->store_type ?? null);
    }

    /**
     * How many restaurants use each template: [slug => count].
     * Includes slugs whose template directory was deleted.
     */
    public function usageCounts(): array
    {
        try {
            // Stores with an empty/null template would group into a blank slug
            // and surface as a phantom "deleted template still in use" row.
            $counts = [];
            foreach (StoreSettings::valuesFor('store_template') as $slug) {
                if ($slug !== '') {
                    $counts[$slug] = ($counts[$slug] ?? 0) + 1;
                }
            }

            return $counts;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Preserves the historical language-specific preview behavior for the
     * handful of legacy themes that ship preview-<lang>.webp variants.
     */
    private function defaultPreviewImage(string $tempname): string
    {
        try {
            $langNames = ['English', 'Français', 'Arabic', 'Turkish', 'Urdu'];
            $langThemes = ['yellow-theme', 'show-theme', 'modern-theme', 'lala-theme', 'arrow-theme', 'red-blue-theme', 'dark-theme'];
            if (in_array(current_language()->name, $langNames) && in_array($tempname, $langThemes)) {
                return asset('assets/store_templates/'.$tempname.'/preview-'.get_lang().'.webp');
            }
        } catch (\Throwable $e) {
            // No language context (queue/console) — plain preview below.
        }

        return asset('assets/store_templates/'.$tempname.'/preview.png');
    }
}
