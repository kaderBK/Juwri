<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Contracts;

use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;

/**
 * The scheduled (sale / happy-hour / day-part) price of an item for modules that sell from the menu without the
 * order API — the goods cart and checkout (Verticals). Same answer as the storefront display and every other order
 * path (Menu's MenuScheduleService), including the promotion block ({@see PromotionRules}).
 */
interface ItemPricing
{
    /** The unit price a customer pays now for $base (the item or variant price before any schedule). */
    public function unitPrice(Menu $menu, float $base, ?string $orderType = null): float;

    /**
     * The order-line columns that snapshot the scheduled price (unit_price, price_schedule_id, price_label), or []
     * when no schedule applies.
     *
     * @return array{unit_price?: float, price_schedule_id?: int, price_label?: string}
     */
    public function snapshot(Menu $menu, ?MenuVariant $variant = null, ?string $orderType = null, ?int $branchId = null): array;
}
