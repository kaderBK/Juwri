<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services\Stylist;

use Juwri\Modules\Menu\Models\Menu;
use Illuminate\Support\Str;

/**
 * Deterministic mock stylist. `suggest()` keyword-matches the query against the
 * store's active items (name/description), falling back to a curated sample;
 * `visualMatch()` returns a stable sample of the store's items. Everything is a
 * REAL product id so the maison UI links to buyable items. Swap for a real
 * vision/recommender provider later.
 */
class MockStylistProvider implements StylistProvider
{
    /**
     * @return list<int>
     */
    public function suggest(?string $query, ?int $storeId = null): array
    {
        if ($storeId === null) {
            return [];
        }

        $items = Menu::where('store_id', $storeId)
            ->where('active', '1') // enum('0','1') string [mysql-enum-active-index-trap]
            ->get(['id', 'name', 'description']);

        $q = Str::lower(trim((string) $query));
        if ($q !== '') {
            $terms = array_filter(explode(' ', $q), fn ($t) => strlen($t) > 2);
            $matched = $items->filter(function (Menu $m) use ($terms) {
                $hay = Str::lower($m->name.' '.strip_tags((string) $m->description));
                return array_any($terms, fn($t) => str_contains($hay, $t));
            });
            if ($matched->isNotEmpty()) {
                return $matched->take(6)->pluck('id')->map(fn ($id) => (int) $id)->all();
            }
        }

        // No query / no match — a curated sample of the catalog.
        return $items->take(6)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return list<int>
     */
    public function visualMatch(?string $imageData, ?int $storeId = null): array
    {
        if ($storeId === null) {
            return [];
        }
        $seed = crc32((string) ($imageData ?: 'demo'));

        // Deterministic-by-image order: the same capture always yields the same
        // sample. Collection::shuffle() takes no seed (and modern Laravel shuffles
        // via a CSPRNG that ignores mt_srand), so order by a stable hash instead.
        return Menu::where('store_id', $storeId)
            ->where('active', '1')
            ->get(['id'])
            ->sortBy(fn (Menu $m) => crc32($seed.'|'.$m->id))
            ->take(4)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
