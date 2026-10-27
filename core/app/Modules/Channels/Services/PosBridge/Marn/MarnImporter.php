<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Marn;

use App\Models\MenuCategory;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Channels\Models\PosItemMap;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\MenuAdminService;

/**
 * N4-P1 — Marn menu import (mirrors the Foodics importer). Pulls a location's products
 * (items/categories/modifiers), previews a dry-run diff, then commits into our
 * Menu/MenuCategory + writes PosItemMap (our menu id ↔ Marn product_key). Same import
 * safety as Foodics: never deletes owner items; re-import updates mapped items only.
 */
class MarnImporter
{
    public function __construct(private MarnClientInterface $client) {}

    public function pull(string $locationKey): array
    {
        $out = [];
        foreach ($this->client->fetchLocationProducts($locationKey)['data'] ?? [] as $p) {
            $out[] = [
                'external_id' => (string) ($p['product_key'] ?? ''),
                'name' => $p['name'] ?? '',
                'name_localized' => $p['name_ar'] ?? null,
                'price' => (float) ($p['price'] ?? 0),
                'category' => [
                    'name' => $p['category']['name'] ?? 'Menu',
                    'name_localized' => $p['category']['name_ar'] ?? null,
                ],
                'modifiers' => $p['modifier_groups'] ?? [],
            ];
        }

        return $out;
    }

    public function preview(int $storeId, string $locationKey): array
    {
        $products = $this->pull($locationKey);
        $mapped = PosItemMap::where('provider', 'marn')->where('store_id', $storeId)
            ->pluck('menu_id', 'external_id')->all();
        $existingCats = MenuCategory::where('store_id', $storeId)
            ->get()->keyBy(fn ($c) => strtolower($c->getRawOriginal('name')));

        $new = 0;
        $updated = 0;
        $newCats = [];
        foreach ($products as $p) {
            isset($mapped[$p['external_id']]) ? $updated++ : $new++;
            $catKey = strtolower($p['category']['name']);
            if (! $existingCats->has($catKey)) {
                $newCats[$catKey] = true;
            }
        }

        return [
            'total' => count($products), 'items_new' => $new, 'items_updated' => $updated,
            'categories_created' => count($newCats),
            'sample' => array_slice(array_map(fn ($p) => ['name' => $p['name'], 'price' => $p['price'], 'category' => $p['category']['name']], $products), 0, 10),
        ];
    }

    public function import(int $storeId, string $locationKey): array
    {
        $products = $this->pull($locationKey);
        $created = 0;
        $updated = 0;
        $skippedItems = 0;
        $skippedCats = 0;

        // Plan caps apply to a bulk import too: create up to the limit, then skip
        // and report the rest (never silently truncate). null = unlimited/no owner.
        $menuRemaining = MenuAdminService::remainingFor($storeId, 'menu');
        $catRemaining = MenuAdminService::remainingFor($storeId, 'category');

        DB::transaction(function () use ($storeId, $products, &$created, &$updated, &$skippedItems, &$skippedCats, &$menuRemaining, &$catRemaining) {
            $catCache = [];
            foreach ($products as $p) {
                $catId = $this->resolveCategory($storeId, $p['category'], $catCache, $catRemaining, $skippedCats);
                $existingMenuId = PosItemMap::where('provider', 'marn')
                    ->where('store_id', $storeId)->where('external_id', $p['external_id'])->value('menu_id');

                $attrs = ['store_id' => $storeId, 'category_id' => $catId, 'name' => $p['name'], 'price' => $p['price'], 'active' => '1'];
                if (! empty($p['name_localized'])) {
                    $attrs['translations'] = (object) ['ar' => (object) ['title' => $p['name_localized']]];
                }

                if ($existingMenuId && ($menu = Menu::find($existingMenuId))) {
                    $menu->update($attrs);
                    $updated++;
                } else {
                    // New item: honour the plan menu limit.
                    if ($menuRemaining !== null && $menuRemaining <= 0) {
                        $skippedItems++;

                        continue;
                    }
                    $menu = Menu::create($attrs + ['position' => 999]);
                    PosItemMap::updateOrCreate(
                        ['provider' => 'marn', 'menu_id' => $menu->id],
                        ['store_id' => $storeId, 'external_id' => $p['external_id'], 'external_name' => $p['name']],
                    );
                    if ($menuRemaining !== null) {
                        $menuRemaining--;
                    }
                    $created++;
                }
            }
        });

        return ['created' => $created, 'updated' => $updated, 'skipped_items' => $skippedItems, 'skipped_categories' => $skippedCats];
    }

    private function resolveCategory(int $storeId, array $cat, array &$cache, ?int &$catRemaining, int &$skippedCats): ?int
    {
        $key = strtolower($cat['name']);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $existing = MenuCategory::where('store_id', $storeId)->whereRaw('LOWER(name) = ?', [$key])->first();
        if (! $existing) {
            // At the plan category cap: leave these items uncategorised rather than
            // minting a category past the limit (reported via skipped_categories).
            if ($catRemaining !== null && $catRemaining <= 0) {
                $skippedCats++;

                return $cache[$key] = null;
            }
            $data = ['store_id' => $storeId, 'name' => $cat['name'], 'active' => '1', 'position' => 999];
            if (! empty($cat['name_localized'])) {
                $data['translations'] = (object) ['ar' => (object) ['title' => $cat['name_localized']]];
            }
            $existing = MenuCategory::create($data);
            if ($catRemaining !== null) {
                $catRemaining--;
            }
        }

        return $cache[$key] = $existing->id;
    }
}
