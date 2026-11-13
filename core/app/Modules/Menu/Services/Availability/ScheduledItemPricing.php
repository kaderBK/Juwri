<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Availability;

use Juwri\Modules\Menu\Contracts\ItemPricing;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;

/** {@see ItemPricing} through the ONE pricing authority, {@see MenuScheduleService}. */
final class ScheduledItemPricing implements ItemPricing
{
    public function unitPrice(Menu $menu, float $base, ?string $orderType = null): float
    {
        return (float) MenuScheduleService::quote($menu, $base, null, $orderType)['price'];
    }

    public function snapshot(Menu $menu, ?MenuVariant $variant = null, ?string $orderType = null, ?int $branchId = null): array
    {
        return MenuScheduleService::snapshotFor($menu, $variant, $orderType, $branchId);
    }
}
