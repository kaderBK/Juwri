<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Retail;

use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;

/**
 * P3-08 (API-6): stock as a state — `in_stock`, `low`, `out`, `untracked` — with the exact quantity only at or below
 * the low-stock threshold (the variant's own, else the item's). One rule for the item page, the variant matrix, the
 * catalogue badges and the headless API (kit/api `StockState {state, qty, label}`).
 */
final class StockState
{
    /**
     * The item's own stock (simple-mode `stock_qty`), or `out` when it is marked sold out.
     *
     * @return array{state: string, qty: int|null, label?: string}
     */
    public static function forItem(Menu $item): array
    {
        if ((bool) $item->is_sold_out) {
            return ['state' => 'out', 'qty' => null, 'label' => ___('Sold out')];
        }
        if (! $item->track_inventory || $item->stock_qty === null) {
            return ['state' => 'untracked', 'qty' => null];
        }

        return self::of((int) $item->stock_qty, self::threshold($item, null), null);
    }

    /**
     * One cell of the variant matrix. A cell of a sold-out item, or an inactive cell, is `out`; a cell without its
     * own stock follows the item.
     *
     * @return array{state: string, qty: int|null, label?: string}
     */
    public static function forVariant(Menu $item, MenuVariant $variant, ?string $cellLabel = null): array
    {
        if ((bool) $item->is_sold_out || ! $variant->active) {
            return ['state' => 'out', 'qty' => null, 'label' => ___('Sold out')];
        }
        if ($variant->stock_qty === null) {
            return self::forItem($item);
        }

        return self::of((int) $variant->stock_qty, self::threshold($item, $variant), $cellLabel);
    }

    /** Can a shopper buy `$qty` of this item / cell now (the check before the race-safe take)? */
    public static function canSell(Menu $item, ?MenuVariant $variant, int $qty): bool
    {
        $state = $variant !== null ? self::forVariant($item, $variant) : self::forItem($item);
        if ($state['state'] === 'out') {
            return false;
        }
        if ($variant !== null && $variant->stock_qty !== null) {
            return (int) $variant->stock_qty >= $qty;
        }
        if ($item->track_inventory && $item->stock_qty !== null) {
            return (int) $item->stock_qty >= $qty;
        }

        return true;
    }

    /** @return array{state: string, qty: int|null, label?: string} */
    private static function of(int $qty, int $threshold, ?string $cellLabel): array
    {
        if ($qty <= 0) {
            return ['state' => 'out', 'qty' => null, 'label' => ___('Sold out')];
        }
        if ($qty <= $threshold) {
            return ['state' => 'low', 'qty' => $qty, 'label' => $cellLabel !== null && $cellLabel !== ''
                ? ___('Only :count left in :variant', ['count' => (string) $qty, 'variant' => $cellLabel])
                : ___('Only :count left', ['count' => (string) $qty])];
        }

        return ['state' => 'in_stock', 'qty' => null, 'label' => ___('In stock')];
    }

    private static function threshold(Menu $item, ?MenuVariant $variant): int
    {
        if ($variant !== null && $variant->low_stock_threshold !== null) {
            return max(0, (int) $variant->low_stock_threshold);
        }

        return max(0, (int) ($item->low_stock_threshold ?? 5));
    }
}
