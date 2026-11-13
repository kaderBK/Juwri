<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Contracts;

use App\Models\MenuCategory;
use Illuminate\Support\Collection;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

/**
 * P3-02: what a storefront may show of a store's catalogue — the one read path the storefront view model (and,
 * after it, the headless storefront API) uses instead of querying Menu models itself. Implemented by the Menu
 * module (StorefrontCatalogReader), bound in MenuServiceProvider. Every method is strictly scoped to `$store`.
 *
 * Names and descriptions come back in the storefront visitor's language (the models' storefront translation),
 * so a caller sets the language before reading.
 */
interface StorefrontCatalog
{
    /**
     * Active categories in storefront order (position, then id).
     *
     * @return Collection<int, MenuCategory>
     */
    public function categories(Store $store): Collection;

    /**
     * Items a shopper may see now, in storefront order: active, in an active category (or none), available at
     * `$branch` with its price override, the scheduled (happy-hour) price applied in memory, and — unless the
     * owner shows unavailable items greyed out — only items inside their availability window. Variants, choice
     * groups, add-ons, brand and allergens are loaded. `$categoryId` (P4-16) reads one category's items only.
     *
     * @return Collection<int, Menu>
     */
    public function items(Store $store, ?Branch $branch = null, ?string $orderType = null, ?int $categoryId = null): Collection;

    /** One item of the store by its public id, as items() shapes it, or null (other store, inactive, hidden). */
    public function item(Store $store, string $publicId, ?Branch $branch = null, ?string $orderType = null): ?Menu;

    /**
     * The item's choice groups (option groups and add-on groups) in storefront order, prices in minor units of
     * the store currency.
     *
     * P3-08: a variant group also says how a storefront draws it (`display`: swatch | size | chips, null = radio /
     * checkbox) and a colour option its `swatch` (#RRGGBB). P3-09: the weight steps of an item sold by weight have
     * `display` = weight and each option its `weight_g`.
     *
     * @return list<array{public_id: string, title: string, kind: 'variant'|'addon', display?: string|null, required: bool, min: int, max: int|null,
     *     options: list<array{public_id: string, title: string, price_minor: int, active: bool, swatch?: string, weight_g?: int}>}>
     */
    public function optionGroups(Menu $item): array;

    /**
     * The item's active priced variants (size x colour matrix, priced combos): public id, sku, the option cell
     * with group ids replaced by public ids, price and stock (null = not stock-tracked).
     *
     * @return list<array{public_id: string, sku: string|null, options: array<string, string>, price_minor: int, stock: int|null}>
     */
    public function variants(Menu $item): array;

    /**
     * Allergen names of the store's allergen catalogue, by id.
     *
     * @return array<int, string>
     */
    public function allergenNames(Store $store): array;

    /**
     * P6-49: a fingerprint of everything time-dependent that items() decorates (live schedules, open windows), worked
     * out without loading the items. Same signature + same menu version = same decorated items.
     */
    public function decorationSignature(Store $store, ?string $orderType = null): string;
}
