<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Catalog;

use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;

/**
 * rev/multi-vertical Lane A6 — barcode source-of-truth resolve (O-BARCODE).
 *
 * Decision (default taken, POS already treats `plu` as the barcode
 * everywhere it scans — see PosController's `'plu' => $m->plu, // barcode /
 * PLU for the scanner wedge`): ALIAS, don't duplicate.
 *   - Item-level barcode = `menu.plu` (via Menu::barcode accessor/mutator).
 *   - Variant-level barcode = the new `menu_variants.barcode` column (A2) —
 *     variants had no pre-existing code column to alias.
 *
 * This resolver is the one place that looks up EITHER, scoped to a store, so
 * a scanner wedge (or a future retail POS flow) can resolve a scanned code to
 * either an item or a specific variant without the caller knowing which.
 */
class BarcodeResolver
{
    /**
     * @return array{type: 'item'|'variant', menu: Menu, variant: MenuVariant|null}|null
     */
    public function resolve(string $code, int $storeId): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $menu = Menu::findByBarcode($code, $storeId);
        if ($menu) {
            return ['type' => 'item', 'menu' => $menu, 'variant' => null];
        }

        // MenuVariant has no `menu()` relation (only the inverse Menu::variants()),
        // so the tenancy check goes through an explicit join instead of whereHas.
        $variant = MenuVariant::query()
            ->join('items', 'items.id', '=', 'menu_variants.menu_id')
            ->where('menu_variants.barcode', $code)
            ->where('items.store_id', $storeId)
            ->select('menu_variants.*')
            ->first();

        if ($variant) {
            $menu = Menu::find($variant->menu_id);
            if ($menu) {
                return ['type' => 'variant', 'menu' => $menu, 'variant' => $variant];
            }
        }

        return null;
    }
}
