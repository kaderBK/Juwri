<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Marketplace;

use Juwri\Core\Editions\Community\NoShopperBag;

/**
 * P1-13 13d extension point: the marketplace shopper side of the Community cross-store cart
 * (App\Services\MultiVendor\CrossCartService, the /order-hub bag). The Marketplace module (ee) owns the shopper
 * accounts (auth guard 'shopper'), the per-store customer link and the Fusion bag quote; Community has no shoppers
 * ({@see NoShopperBag}): carts belong to the guest cookie token only and nothing is quoted.
 */
interface ShopperBag
{
    /** The signed-in marketplace shopper's id (guard 'shopper'), or null. */
    public function shopperId(): ?int;

    /** The signed-in marketplace shopper (id, name, phone), or null. */
    public function shopper(): ?object;

    /**
     * The store's own customer id for this shopper — linked (and created by phone) on the shopper's first order at
     * the store; null when the shopper has no phone.
     *
     * @param  array<string, mixed>  $contact
     */
    public function linkStoreCustomer(object $shopper, int $storeId, array $contact): ?int;

    /**
     * The marketplace bag quote of this cart at the shopper's pin (stores, fees, totals).
     *
     * @return array<string, mixed>
     */
    public function quote(object $cart, ?float $lat, ?float $lng): array;

    /**
     * The couponed bag quote at the shopper's pin (its 'summary' carries the round-up donation).
     *
     * @return array<string, mixed>
     */
    public function quoteWithCoupons(?float $lat, ?float $lng): array;
}
