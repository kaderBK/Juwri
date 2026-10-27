<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Slots;

use App\Models\Order;
use Carbon\CarbonInterface;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Contracts\DeliverySlotBook;
use Juwri\Modules\Ordering\Models\SlotHold;

/**
 * P3-09: {@see DeliverySlotBook} — the slots ({@see DeliverySlots}; lane C: + the food schedule-ahead slots,
 * {@see SchedulingSlots}, for a store without a delivery-slot rule) and their holds ({@see SlotHolds}).
 */
final class SlotBook implements DeliverySlotBook
{
    public function __construct(private readonly DeliverySlots $slots, private readonly SlotHolds $holds, private readonly SchedulingSlots $scheduled) {}

    public function offers(Store $store, ?Branch $branch, string $orderType): bool
    {
        return $this->slots->offers($store, $branch, $orderType);
    }

    public function schedules(Store $store, ?Branch $branch, string $orderType): bool
    {
        return ! $this->slots->offers($store, $branch, $orderType) && $this->scheduled->offers($store, $branch, $orderType);
    }

    public function timezone(Store $store, ?Branch $branch = null): string
    {
        return $this->slots->timezone($store, $branch);
    }

    public function days(Store $store, ?Branch $branch, string $orderType, ?string $from = null, int $days = 3): array
    {
        return $this->schedules($store, $branch, $orderType)
            ? $this->scheduled->days($store, $branch, $orderType, $from, $days)
            : $this->slots->days($store, $branch, $orderType, $from, $days);
    }

    public function express(Store $store, ?Branch $branch, string $orderType): ?array
    {
        return $this->slots->express($store, $branch, $orderType);
    }

    public function find(Store $store, ?Branch $branch, string $slotId): ?array
    {
        return $this->holds->findSlot($store, $branch, $slotId);
    }

    public function nextFree(Store $store, array $slot): ?array
    {
        return $this->holds->nextFreeSlot($store, $slot);
    }

    public function inRadius(Store $store, ?Branch $branch, array $address): ?bool
    {
        return $this->slots->inRadius($store, $branch, $address);
    }

    public function radiusApplies(Store $store, ?Branch $branch): bool
    {
        return $this->slots->radiusApplies($store, $branch);
    }

    public function radiusCenter(Store $store, ?Branch $branch): ?array
    {
        return $this->slots->radiusCenter($store, $branch);
    }

    public function hold(Store $store, ?Branch $branch, string $slotId, ?string $holderKey, ?string $idempotencyKey = null): array
    {
        return $this->holds->hold($store, $branch, $slotId, $holderKey, $idempotencyKey);
    }

    public function release(Store $store, string $holdId, ?string $token): ?Order
    {
        return $this->holds->release($store, $holdId, $token);
    }

    public function findHold(Store $store, string $holdId): ?SlotHold
    {
        return SlotHold::query()->where('store_id', $store->id)->where('public_id', $holdId)->first();
    }

    public function ensureLive(Store $store, ?Branch $branch, string $holdId, ?string $token, ?string $slotId): SlotHold
    {
        return $this->holds->ensureLive($store, $branch, $holdId, $token, $slotId);
    }

    public function lockForOrder(Store $store, string $holdId, ?string $token, ?string $slotId): SlotHold
    {
        return $this->holds->lockForOrder($store, $holdId, $token, $slotId);
    }

    public function bind(SlotHold $hold, Order $order, ?CarbonInterface $windowEnd): void
    {
        $this->holds->bind($hold, $order, $windowEnd);
    }

    public function settleForOrder(Order $order): void
    {
        $this->holds->settleForOrder($order);
    }

    public function releaseForOrder(Order $order): void
    {
        $this->holds->releaseForOrder($order);
    }

    public function retakeForOrder(Order $order): bool
    {
        return $this->holds->retakeForOrder($order);
    }
}
