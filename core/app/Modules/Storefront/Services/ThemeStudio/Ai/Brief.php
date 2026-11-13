<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\Ai;

use App\Services\Verticals\VerticalRegistry;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Services\ThemeStudio\Recipes;

/**
 * Theme Studio phase D step 1: the brief. Normalizes the owner's optional
 * input (contract: text ≤ 500, vibe chips, priority, ≤ 3 reference colours)
 * and derives the automatic inputs from the render vars (spec §4 D1): store
 * name + type + vertical group, tagline, category names, menu stats, logo +
 * cover, languages (→ RTL / Arabic), enabled features, ordering, branches.
 *
 * No AI here. The result is plain data: the compose prompt embeds it inside a
 * data sentinel, and the rule-based composer and render check read it too.
 */
class Brief
{
    public const VIBES = ['warm', 'minimal', 'bold', 'luxury', 'playful', 'traditional', 'modern', 'family'];

    public const TEXT_MAX = 500;

    /**
     * The owner's part, cleaned: unknown vibes / priority / non-hex colours
     * are dropped (the controller validates first; this is the safety net).
     *
     * @param  array<string,mixed>  $raw
     * @return array{text:string,vibes:list<string>,priority:?string,colors:list<string>}
     */
    public static function owner(array $raw): array
    {
        $text = is_string($raw['text'] ?? null) ? trim(strip_tags($raw['text'])) : '';
        $text = mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, self::TEXT_MAX);

        $vibes = [];
        foreach (is_array($raw['vibes'] ?? null) ? $raw['vibes'] : [] as $vibe) {
            $vibe = strtolower(trim((string) $vibe));
            if (in_array($vibe, self::VIBES, true) && ! in_array($vibe, $vibes, true)) {
                $vibes[] = $vibe;
            }
        }

        $priority = is_string($raw['priority'] ?? null) && in_array($raw['priority'], Recipes::PRIORITIES, true)
            ? $raw['priority'] : null;

        $colors = [];
        foreach (is_array($raw['colors'] ?? null) ? $raw['colors'] : [] as $color) {
            if (is_string($color) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', trim($color))) {
                $colors[] = strtolower(trim($color));
            }
        }

        return ['text' => $text, 'vibes' => $vibes, 'priority' => $priority, 'colors' => array_slice(array_values(array_unique($colors)), 0, 3)];
    }

    /**
     * The full brief: owner part + everything derived from the store.
     *
     * @param  array<string,mixed>  $vars  StoreVars::forStore / forDemo
     * @param  array<string,mixed>  $owner  self::owner()
     * @return array<string,mixed>
     */
    public static function build(array $vars, array $owner): array
    {
        /** @var Store $store */
        $store = $vars['store'];
        $storeType = strtolower((string) ($store->store_type ?: 'restaurant'));
        $registry = app(VerticalRegistry::class);
        if (! $registry->exists($storeType)) {
            $storeType = $registry->default();
        }
        $group = (string) ($registry->config($storeType)['group'] ?? 'food');

        $menus = is_array($vars['total_menus'] ?? null) ? $vars['total_menus'] : [];
        $categories = [];
        $prices = [];
        $withPhoto = 0;
        foreach ($menus as $menu) {
            $cat = $menu->category ?? null;
            $name = $cat ? trim((string) ($cat->name ?? '')) : '';
            if ($name !== '' && ! in_array($name, $categories, true) && count($categories) < 12) {
                $categories[] = mb_substr($name, 0, 40);
            }
            if (is_numeric($menu->price ?? null) && (float) $menu->price > 0) {
                $prices[] = (float) $menu->price;
            }
            if (self::usableImage((string) ($menu->image ?? ''))) {
                $withPhoto++;
            }
        }

        $languages = [];
        foreach ($vars['menu_languages'] ?? [] as $language) {
            $languages[] = [
                'code' => strtolower((string) $language->code),
                'rtl' => strtolower((string) ($language->direction ?? '')) === 'rtl',
            ];
        }
        $default = strtolower((string) ($vars['default_menu_language']->code ?? ($languages[0]['code'] ?? 'en')));
        if ($languages === []) {
            $languages[] = ['code' => $default, 'rtl' => $default === 'ar'];
        }
        // Default language first.
        usort($languages, fn ($a, $b) => ($b['code'] === $default) <=> ($a['code'] === $default));

        $features = [];
        foreach ((array) ($vars['extras']['features'] ?? []) as $key => $on) {
            if ($on) {
                $features[] = (string) $key;
            }
        }

        $cover = (string) ($store->cover_image ?? '');

        return [
            'store' => [
                'name' => mb_substr(trim((string) $store->title), 0, 80),
                'tagline' => mb_substr(trim(strip_tags((string) ($store->sub_title ?? ''))), 0, 140),
                'store_type' => $storeType,
                'vertical_group' => $group,
            ],
            'menu' => [
                'categories' => $categories,
                'items' => count($menus),
                'photo_share' => count($menus) > 0 ? round($withPhoto / count($menus), 2) : 0.0,
                'price_min' => $prices === [] ? null : min($prices),
                'price_max' => $prices === [] ? null : max($prices),
            ],
            'languages' => array_map(fn ($l) => $l['code'], $languages),
            'default_language' => $default,
            'rtl' => $languages[0]['rtl'],
            'arabic' => (bool) array_filter($languages, fn ($l) => str_starts_with($l['code'], 'ar')),
            'ordering' => (bool) ($vars['flags']['allow_order'] ?? false),
            'features' => $features,
            'branches' => is_countable($vars['branches'] ?? null) ? count($vars['branches']) : 0,
            'has_cover' => self::usableImage($cover),
            'has_logo' => self::usableImage((string) ($store->main_image ?? '')),
            'owner' => $owner,
        ];
    }

    /** A real uploaded image (not empty, not the platform placeholder). */
    public static function usableImage(string $file): bool
    {
        return $file !== '' && $file !== 'default.png' && ! str_contains($file, '..');
    }
}
