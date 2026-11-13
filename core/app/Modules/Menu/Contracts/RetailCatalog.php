<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Contracts;

use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\SizeGuide;

/**
 * P3-08: the retail side of the catalogue for modules above Menu — the colour × size matrix on `menu_variants`
 * (cells keyed by the item's own option groups), stock as a state (API-6), the race-safe stock take of a customer
 * order, swatches and size guides. Implemented by Services\Retail\RetailCatalogService, bound in MenuServiceProvider.
 */
interface RetailCatalog
{
    /**
     * The active cell of the item's matrix for these picks ({option group id: option index}); null when the picks
     * do not name every axis or no cell has that combination.
     *
     * @param  array<int, int>  $picks
     */
    public function variantForPicks(Menu $item, array $picks): ?MenuVariant;

    /**
     * Option group ids that are axes of the item's matrix, in the item's group order.
     *
     * @return list<int>
     */
    public function axes(Menu $item): array;

    /** "Sand · M": the option labels of a cell. */
    public function cellLabel(Menu $item, MenuVariant $variant): string;

    /**
     * API-6 stock state of the item, or of one cell: {state: in_stock|low|out|untracked, qty (only when low), label?}.
     *
     * @return array{state: string, qty: int|null, label?: string}
     */
    public function stockState(Menu $item, ?MenuVariant $variant = null): array;

    /** Can a shopper buy `$qty` now (the check before the race-safe take)? */
    public function canSell(Menu $item, ?MenuVariant $variant, int $qty): bool;

    /**
     * The item's matrix for a storefront / the headless API: every active cell with its option public ids
     * ({group public id: option public id}), price, compare-at price (minor units, store currency), SKU, GTIN, image
     * file, stock state and whether a back-in-stock request can be made for it.
     *
     * @return list<array{public_id: string, sku: string|null, gtin: string|null, options: array<string, string>, price_minor: int, compare_at_minor: int|null, image: string|null, stock: array{state: string, qty: int|null, label?: string}, notify_available: bool, label: string}>
     */
    public function matrix(Store $store, Menu $item): array;

    /**
     * The swatch colours of the item's swatch groups (`modifier_groups.display = swatch`): option public id => #RRGGBB.
     *
     * @return array<string, string>
     */
    public function swatches(Menu $item): array;

    /** The item's active size guide of the store, or null. */
    public function sizeGuide(Store $store, Menu $item): ?SizeGuide;

    /** A size guide of the store by public id (active only), or null. */
    public function sizeGuideById(Store $store, string $publicId): ?SizeGuide;
}
