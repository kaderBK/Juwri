<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services;

use App\Models\MenuCategory;
use App\Services\PlanMenuVisibility;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

/**
 * C7 — one-click starter menus. Seeds a fresh store from a bundled preset
 * (database/starter-menus/{preset}.json) so a new owner is not staring at an empty menu.
 *
 * Idempotent by construction: it REFUSES when the store already has any category, so a
 * second click (or a preset chosen after the owner started editing) can never duplicate or
 * clobber real menu data. Everything is written through the Eloquent models — never raw SQL
 * against the prefix-sensitive `menu` table.
 */
class StarterMenuService
{
    /** The bundled presets (also the request `preset` whitelist). */
    public const PRESETS = ['cafe', 'pizzeria', 'burger', 'bakery', 'lounge'];

    public static function isPreset(string $preset): bool
    {
        return in_array($preset, self::PRESETS, true);
    }

    /**
     * Seed a store's menu from a preset. Returns counts; `seeded` is false (with a reason)
     * when the store already has menus or the preset is unknown, so the caller can message
     * the owner without treating it as an error.
     *
     * @return array{seeded: bool, reason?: string, categories: int, items: int, skipped?: int}
     */
    public function seed(Store $store, string $preset): array
    {
        if (! self::isPreset($preset)) {
            return ['seeded' => false, 'reason' => 'unknown_preset', 'categories' => 0, 'items' => 0];
        }

        // Idempotence guard: never seed a store that already has any category.
        if (MenuCategory::where('store_id', $store->id)->exists()) {
            return ['seeded' => false, 'reason' => 'not_empty', 'categories' => 0, 'items' => 0];
        }

        $data = $this->load($preset);
        if ($data === null || empty($data['categories'])) {
            return ['seeded' => false, 'reason' => 'empty_preset', 'categories' => 0, 'items' => 0];
        }

        $categories = 0;
        $items = 0;
        $skipped = 0;

        /* The preset is a BULK write: it must land inside the owner's plan, or
           a free plan would end up with 4 categories and 17 items — more than
           the plan sells. Clamp to the owner's caps and report what was left
           out (the controller tells the merchant, so the cap is never silent). */
        $caps = PlanMenuVisibility::caps($store->user);
        $categoryCap = $caps['category'];
        $itemCap = $caps['item'];

        DB::transaction(function () use ($store, $data, &$categories, &$items, &$skipped, $categoryCap, $itemCap) {
            $catPos = 1;
            foreach ($data['categories'] as $cat) {
                if ($categoryCap !== null && $categories >= $categoryCap) {
                    // Count what this category would have contributed.
                    $skipped += count($cat['items'] ?? []);

                    continue;
                }

                $category = MenuCategory::create([
                    'store_id' => $store->id,
                    'name' => (string) ($cat['name'] ?? ___('Menu')),
                    'active' => '1',
                    'position' => $catPos++,
                    'translations' => $this->nameTranslations($cat['name_ar'] ?? null),
                ]);
                $categories++;

                $itemPos = 1;
                $itemsInCategory = 0;
                foreach (($cat['items'] ?? []) as $item) {
                    // `menu_limit` is "max items per category" (the canonical
                    // counter is per category), so each category gets its own cap.
                    if ($itemCap !== null && $itemsInCategory >= $itemCap) {
                        $skipped++;

                        continue;
                    }

                    Menu::create([
                        'store_id' => $store->id,
                        'category_id' => $category->id,
                        'name' => (string) ($item['name'] ?? ___('Item')),
                        'description' => (string) ($item['description'] ?? ''),
                        'price' => (float) ($item['price'] ?? 0),
                        'active' => '1',
                        'position' => $itemPos++,
                        'is_vegan' => ! empty($item['vegan']) ? 1 : 0,
                        'is_popular' => ! empty($item['popular']) ? 1 : 0,
                        'translations' => $this->itemTranslations($item),
                    ]);
                    $items++;
                    $itemsInCategory++;
                }
            }
        });

        return [
            'seeded' => true,
            'categories' => $categories,
            'items' => $items,
            'skipped' => $skipped,
        ];
    }

    /**
     * Read and decode a preset file.
     *
     * @return array<string, mixed>|null
     */
    private function load(string $preset): ?array
    {
        $path = database_path('starter-menus/'.$preset.'.json');
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Category/item name translation payload in the platform's shape
     * (`translations->{lang}->title`); null when there is no Arabic name.
     *
     * @return array<string, array<string, string>>|null
     */
    private function nameTranslations(?string $ar): ?array
    {
        $ar = trim((string) $ar);

        return $ar === '' ? null : ['ar' => ['title' => $ar]];
    }

    /**
     * Item translations: Arabic title + description in the platform shape.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, array<string, string>>|null
     */
    private function itemTranslations(array $item): ?array
    {
        $ar = [];
        if (! empty($item['name_ar'])) {
            $ar['title'] = (string) $item['name_ar'];
        }
        if (! empty($item['description_ar'])) {
            $ar['description'] = (string) $item['description_ar'];
        }

        return $ar === [] ? null : ['ar' => $ar];
    }
}
