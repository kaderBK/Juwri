<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use Carbon\CarbonInterface;
use Juwri\Core\Models\Branch;
use Juwri\Modules\Ordering\Contracts\HeldSlotCounts;

/** P3-11: the default {@see HeldSlotCounts}: nothing is held. */
final class NoHeldSlots implements HeldSlotCounts
{
    public function pickups(int $storeId, ?Branch $branch, CarbonInterface $start, CarbonInterface $end, int $interval): array
    {
        return [];
    }
}
