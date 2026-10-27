<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Storefront;

/**
 * P4-16: a food storefront (table, brew, basket) with a very large catalogue draws only its first cards; every other
 * category keeps its heading (so the category bar, anchors and counts work) and loads its cards in place on request.
 *
 * The view model keeps the whole catalogue (`catalog.items` and every category's `item_ids`: sections such as featured, offers
 * and reorder read cards by id). Only the catalogue section draws a slice: `shown[<category id>]` cards of that category.
 */
final class FoodCatalogPaging
{
    public const THEMES = ['table', 'brew', 'basket'];

    public static function pageSize(): int
    {
        return max(12, (int) config('perf.storefront_home_page_items', 60));
    }

    /**
     * @param  array<string, mixed>  $catalog  view model `catalog`
     * @param  string|null  $expand  a category id to draw in full (the no-script "Show more" link)
     * @return array{page: int, total: int, shown: array<string, int>, url: string}|null null = draw everything
     */
    public static function plan(array $catalog, ?string $expand = null): ?array
    {
        $total = 0;
        foreach ($catalog['categories'] ?? [] as $category) {
            $total += count($category['item_ids'] ?? []);
        }
        if ($total <= max(1, (int) config('perf.storefront_home_page_threshold', 200))) {
            return null;
        }
        $left = self::pageSize();
        $shown = [];
        foreach ($catalog['categories'] as $category) {
            $n = count($category['item_ids'] ?? []);
            if ($n === 0) {
                continue;
            }
            $take = min($n, $left);
            $left -= $take;
            $shown[(string) $category['id']] = (string) $category['id'] === $expand ? $n : $take;
        }

        return ['page' => self::pageSize(), 'total' => $total, 'shown' => $shown, 'url' => '?fragment=cat'];
    }
}
