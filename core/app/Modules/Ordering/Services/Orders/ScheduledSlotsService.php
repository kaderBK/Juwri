<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use Carbon\Carbon;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Contracts\ScheduledSlots;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;

/** P3-11: {@see ScheduledSlots} over the scheduling rules of scheduled orders. */
final class ScheduledSlotsService implements ScheduledSlots
{
    public function __construct(private SchedulingService $scheduling, private FulfillmentSettingsService $settings) {}

    /** @return list<string> */
    public function offeredModes(Store $store): array
    {
        return $this->settings->offeredModes($store);
    }

    public function timezone(int $storeId, ?Branch $branch = null): string
    {
        return $this->scheduling->timezone($storeId, $branch);
    }

    public function leadMinutes(int $storeId): int
    {
        return $this->scheduling->leadMinutes($storeId);
    }

    public function slotInterval(int $storeId): int
    {
        return $this->scheduling->slotInterval($storeId);
    }

    public function maxDays(int $storeId): int
    {
        return $this->scheduling->maxDays($storeId);
    }

    public function slotCapacity(int $storeId): int
    {
        return $this->scheduling->slotCapacity($storeId);
    }

    public function slotIsFull(int $storeId, ?Branch $branch, Carbon $at): bool
    {
        return $this->scheduling->slotIsFull($storeId, $branch, $at);
    }

    public function slotsFor(int $storeId, ?Branch $branch, string $orderType, string $date): array
    {
        return $this->scheduling->slotsFor($storeId, $branch, $orderType, $date);
    }

    public function validate(int $storeId, ?Branch $branch, string $orderType, ?string $requested): ?Carbon
    {
        return $this->scheduling->validate($storeId, $branch, $orderType, $requested);
    }
}
