<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Contracts;

use Juwri\Modules\Menu\Models\Menu;

/**
 * P1-23 follow-up (DECISIONS 2026-10-04, pharmacy promotion block): items no promotion may touch. Asked per item by
 * every pricing seam — the scheduled / sale / happy-hour price (MenuScheduleService::quote), combos and bundles
 * (ComboService::sellable / resolve), loyalty points earned and redeemed (LoyaltyAccrual, OrderPricing) and
 * coupons (OrderPricing, through Ordering's SalesRestrictions). Community default: NoPromotionRules (nothing blocked);
 * the Pharmacy module binds its per-market rule (medicine lines of a pharmacy in a market that blocks promotions).
 */
interface PromotionRules
{
    /** Whether promotions must not apply to this item (its line keeps the list price and earns/redeems no points). */
    public function blocksPromotions(Menu $item): bool;

    /** Whether any promotion is blocked on some item of this store — a cheap pre-check before per-item questions. */
    public function storeBlocksPromotions(int $storeId): bool;

    /** The short reason shown to the customer when a promotion is refused because of this rule. */
    public function reason(): string;
}
