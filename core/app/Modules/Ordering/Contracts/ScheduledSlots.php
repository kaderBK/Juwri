<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Contracts;

use Carbon\Carbon;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;

/**
 * P3-11 (API-23): the scheduling facts a module that books slots of its own (Laundry's pickup + delivery-back hold)
 * needs from Ordering — the same lead time, interval, capacity and opening-hours rules a scheduled order meets, and the
 * order types a store offers. Implemented by Services\Orders\ScheduledSlotsService (over SchedulingService and
 * FulfillmentSettingsService), bound in OrderingServiceProvider, so the module does not reach into Ordering's services.
 */
interface ScheduledSlots
{
    /**
     * Core order-type keys the store offers (`delivery`, `pickup_slot`, ...).
     *
     * @return list<string>
     */
    public function offeredModes(Store $store): array;

    public function timezone(int $storeId, ?Branch $branch = null): string;

    public function leadMinutes(int $storeId): int;

    public function slotInterval(int $storeId): int;

    public function maxDays(int $storeId): int;

    /** Per-slot capacity; 0 = unlimited. */
    public function slotCapacity(int $storeId): int;

    /** Is the slot containing `$at` at or over capacity right now? */
    public function slotIsFull(int $storeId, ?Branch $branch, Carbon $at): bool;

    /**
     * Bookable slots of a local date (Y-m-d): `at` (tz-aware Carbon), `label` (H:i), `full` (bool).
     *
     * @return list<array<string, mixed>>
     */
    public function slotsFor(int $storeId, ?Branch $branch, string $orderType, string $date): array;

    /**
     * A requested time as a store-zone Carbon, or null for ASAP.
     *
     * @throws \InvalidArgumentException with a customer-safe message
     */
    public function validate(int $storeId, ?Branch $branch, string $orderType, ?string $requested): ?Carbon;
}
