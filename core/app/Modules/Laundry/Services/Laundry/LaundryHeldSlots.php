<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Laundry\Services\Laundry;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Juwri\Core\Models\Branch;
use Juwri\Modules\Laundry\Models\LaundryHold;
use Juwri\Modules\Ordering\Contracts\HeldSlotCounts;

/**
 * P3-11: the Laundry module's answer to Ordering's {@see HeldSlotCounts}: every live hold takes one place at its pickup
 * slot, so a slot a customer is still checking out with is not offered to the next one (and a placed order that did
 * not come through the hold meets the same capacity).
 */
final class LaundryHeldSlots implements HeldSlotCounts
{
    public function pickups(int $storeId, ?Branch $branch, CarbonInterface $start, CarbonInterface $end, int $interval): array
    {
        $tz = $start->timezone;
        $rows = LaundryHold::query()->where('store_id', $storeId)->where('status', 'active')->where('expires_at', '>', now('UTC'))
            ->whereBetween('pickup_at', [Carbon::instance($start)->utc()->format('Y-m-d H:i:s'), Carbon::instance($end)->utc()->format('Y-m-d H:i:s')])
            ->pluck('pickup_at');

        $counts = [];
        foreach ($rows as $at) {
            $key = self::bucket($at, $tz, $interval);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /** `Y-m-d H:i` of the slot a UTC instant falls in, in the store's wall clock, floored to the interval. */
    public static function bucket(CarbonInterface $at, \DateTimeZone|string $tz, int $interval): string
    {
        $local = Carbon::instance($at)->setTimezone($tz);
        $mins = (int) floor(($local->hour * 60 + $local->minute) / $interval) * $interval;

        return $local->copy()->startOfDay()->addMinutes($mins)->format('Y-m-d H:i');
    }
}
