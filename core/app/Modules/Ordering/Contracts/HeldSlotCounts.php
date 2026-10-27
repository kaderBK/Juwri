<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Contracts;

use Carbon\CarbonInterface;
use Juwri\Core\Models\Branch;

/**
 * P3-11 (API-23): places that live holds have taken in scheduled-order slots. SchedulingService counts placed orders
 * per slot; a module that holds slots for a customer who is still checking out (Laundry's 10-minute pickup + delivery
 * hold) implements this, so the same capacity rule sees both. The default answers nothing (no holds).
 */
interface HeldSlotCounts
{
    /**
     * Held places per slot start within [$start, $end], keyed `Y-m-d H:i` in the store's wall clock (the zone of
     * $start), bucketed like the order counts by `$interval` minutes.
     *
     * @return array<string, int>
     */
    public function pickups(int $storeId, ?Branch $branch, CarbonInterface $start, CarbonInterface $end, int $interval): array;
}
