<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Foodics;

use App\Models\MenuCategory;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Channels\Models\PosItemMap;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\MenuAdminService;

/**
 * N1-P2 — Foodics two-way menu import. Pulls branches/categories/products(+modifiers)
 * from Foodics, normalizes, previews a DRY-RUN diff (like GloriaFood), then commits into
 * our Menu/MenuCategory models AND writes PosItemMap (our menu id ↔ Foodics product id)
 * so order push can reference their catalog.
 *
 * IMPORT SAFETY (locked): imports NEVER delete owner-created items; a re-import UPDATES
 * mapped items only (matched by PosItemMap external id), and creates the unmapped-new.
 */
class FoodicsImporter
{
    public function __construct(private FoodicsClientInterface $client) {}

    /** Pull + normalize the Foodics catalog into a flat structure we can diff/import. */
    public function pull(): array
    {
        $products = [];
        $page = 1;
        do {
            $res = $this->client->listProducts($page);
            foreach ($res['data'] ?? [] as $p) {
                $products[] = $this->normalizeProduct($p);
            }
            $last = (int) ($res['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $last && $page < 50);

        return $products;
    }

    private function normalizeProduct(array $p): array
    {
        $modifiers = [];
        foreach ($p['modifiers'] ?? [] as $mg) {
            $modifiers[] = [
                'external_id' => (string) ($mg['id'] ?? ''),
                'name' => $mg['name'] ?? '',
                'required' => (bool) ($mg['is_required'] ?? false),
                'min' => (int) ($mg['min_options'] ?? 0),
                'max' => (int) ($mg['max_options'] ?? 1),
                'options' => array_map(fn ($o) => [
                    'external_id' => (string) ($o['id'] ?? ''),
                    'name' => $o['name'] ?? '',
                    'price' => (float) ($o['price'] ?? 0),
                ], $mg['options'] ?? []),
            ];
        }

        return [
            'external_id' => (string) ($p['id'] ?? ''),
            'name' => $p['name'] ?? '',
            'name_localized' => $p['name_localized'] ?? null,
            'price' => (float) ($p['price'] ?? 0),
            'sku' => $p['sku'] ?? null,
            'category' => [
                'external_id' => (string) ($p['category']['id'] ?? 'uncat'),
                'name' => $p['category']['name'] ?? 'Menu',
                'name_localized' => $p['category']['name_localized'] ?? null,
            ],
            'modifiers' => $modifiers,
        ];
    }

    /** Dry-run: what a commit would create/update/skip — writes nothing. */
    public function preview(int $storeId): array
    {
        $products = $this->pull();

        $mapped = PosItemMap::where('provider', 'foodics')->where('store_id', $storeId)
            ->pluck('menu_id', 'external_id')->all();
        $existingCats = MenuCategory::where('store_id', $storeId)
            ->get()->keyBy(fn ($c) => strtolower($c->getRawOriginal('name')));

        $new = 0;
        $updated = 0;
        $newCats = [];
        foreach ($products as $p) {
            if (isset($mapped[$p['external_id']])) {
                $updated++;
            } else {
                $new++;
            }
            $catKey = strtolower($p['category']['name']);
            if (! $existingCats->has($catKey)) {
                $newCats[$catKey] = $p['category']['name'];
            }
        }

        return [
            'total' => count($products),
            'items_new' => $new,
            'items_updated' => $updated,
            'categories_created' => count($newCats),
            'sample' => array_slice(array_map(fn ($p) => [
                'name' => $p['name'], 'price' => $p['price'], 'category' => $p['category']['name'],
                'modifiers' => count($p['modifiers']),
            ], $products), 0, 10),
        ];
    }

    /**
     * Commit the import. Returns ['created'=>n,'updated'=>n]. Never deletes; re-import
     * updates only items already mapped to a Foodics product.
     */
    public function import(int $storeId): array
    {
        $products = $this->pull();
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

                $existingMenuId = PosItemMap::where('provider', 'foodics')
                    ->where('store_id', $storeId)
                    ->where('external_id', $p['external_id'])
                    ->value('menu_id');

                $attrs = [
                    'store_id' => $storeId,
                    'category_id' => $catId,
                    'name' => $p['name'],
                    'price' => $p['price'],
                    'active' => '1',
                ];
                if (! empty($p['name_localized'])) {
                    $attrs['translations'] = (object) ['ar' => (object) ['title' => $p['name_localized']]];
                }

                if ($existingMenuId && ($menu = Menu::find($existingMenuId))) {
                    // Re-import: update mapped item only (price/name/category), never delete.
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
                        ['provider' => 'foodics', 'menu_id' => $menu->id],
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
        $existing = MenuCategory::where('store_id', $storeId)
            ->whereRaw('LOWER(name) = ?', [$key])->first();
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
