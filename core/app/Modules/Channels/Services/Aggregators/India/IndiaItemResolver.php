<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\India;

use Illuminate\Support\Facades\Schema;
use Juwri\Modules\Menu\Models\Menu;

/**
 * PARITY-RUN T7 — shared item-code resolution for the Indian aggregators.
 *
 * Both Zomato and Swiggy echo back the item code WE published in the menu push,
 * and `CatalogExport` publishes `external_sku ?? id`. So the resolution order is
 * the same on both sides of the wire:
 *
 *   1. exact `menu.external_sku` match (scoped to the tenant), when the column exists;
 *   2. a bare numeric id;
 *   3. the trailing digits of a prefixed code (`sku_12`, `ZOM-12`) — the shape the
 *      menu pushers emit so a partner console that mangles the raw id still resolves.
 *
 * Tenant scoping is not optional: the restaurant comes from the stored
 * AggregatorConnection, never from the payload, so a code from another store's
 * catalogue resolves to nothing rather than to somebody else's dish.
 *
 * NOTE the table is `menu` (singular) — `Schema::hasColumn('menus', …)` silently
 * returns false forever, which is why this helper asks the model for its table.
 */
class IndiaItemResolver
{
    /** Resolve a partner item code to a local Menu id for THIS restaurant, or null. */
    public static function menuId(int $storeId, string $code): ?int
    {
        return self::menu($storeId, $code)?->id;
    }

    public static function menu(int $storeId, string $code): ?Menu
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $table = new Menu()->getTable();
        $base = fn () => Menu::where('store_id', $storeId);

        if (Schema::hasColumn($table, 'external_sku')) {
            $byExt = $base()->where('external_sku', $code)->first();
            if ($byExt) {
                return $byExt;
            }
        }

        if (ctype_digit($code)) {
            return $base()->find((int) $code);
        }

        // `sku_12` / `ZOM-12` → 12. Anchored to the END so a prefix can change
        // without a code change, which is the whole point of the mapping layer.
        if (preg_match('/(\d+)$/', $code, $m)) {
            return $base()->find((int) $m[1]);
        }

        return null;
    }
}
