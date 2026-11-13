<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Contracts;

use App\Models\Order;
use Carbon\CarbonInterface;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Data\SlotException;
use Juwri\Modules\Ordering\Models\SlotHold;

/**
 * P3-09 (kit/api `GET /delivery/slots`, `POST/DELETE /holds`; API-3, API-11, API-24a): a store's delivery / collection
 * slots with live capacity, express and radius, and the 10-minute slot holds with their DB-level capacity guard.
 * Implemented by Services\Slots\SlotBook (DeliverySlots + SchedulingSlots + SlotHolds), bound in OrderingServiceProvider.
 *
 * A slot is an array: id (opaque), order_type (core), branch_key, starts_at / ends_at (UTC), local_starts_at /
 * local_ends_at (store time), capacity (0 = no limit), remaining (null = no limit), state (available|few|full),
 * fee_minor.
 */
interface DeliverySlotBook
{
    /** Does the store list time slots for this core order type (`delivery`, `pickup_slot`)? */
    public function offers(Store $store, ?Branch $branch, string $orderType): bool;

    /**
     * Lane C: does the store list OPTIONAL schedule-ahead slots for this core order type — a food store's pre-order
     * times (`takeaway`, `delivery`; SchedulingService settings) when it has no delivery-slot rule? The order may still
     * be "as soon as possible"; a chosen slot is held like any other (`pslot_` ids).
     */
    public function schedules(Store $store, ?Branch $branch, string $orderType): bool;

    public function timezone(Store $store, ?Branch $branch = null): string;

    /** @return list<array{date: string, slots: list<array<string, mixed>>}> */
    public function days(Store $store, ?Branch $branch, string $orderType, ?string $from = null, int $days = 3): array;

    /** @return array{fee_minor: int, eta_from: int, eta_to: int}|null */
    public function express(Store $store, ?Branch $branch, string $orderType): ?array;

    /** @return array<string, mixed>|null the slot when it is this store's, offered and bookable now */
    public function find(Store $store, ?Branch $branch, string $slotId): ?array;

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>|null
     */
    public function nextFree(Store $store, array $slot): ?array;

    /** @param  array<string, mixed>  $address  null = cannot tell (accepted) */
    public function inRadius(Store $store, ?Branch $branch, array $address): ?bool;

    /** Does the store check delivery addresses against a radius? Then a delivery without a pin is refused. */
    public function radiusApplies(Store $store, ?Branch $branch): bool;

    /** @return array{0: float, 1: float}|null where the radius is measured from; null = no radius */
    public function radiusCenter(Store $store, ?Branch $branch): ?array;

    /**
     * @return array{hold: SlotHold, token: string, slot: array<string, mixed>, replayed: bool}
     *
     * @throws SlotException
     */
    public function hold(Store $store, ?Branch $branch, string $slotId, ?string $holderKey, ?string $idempotencyKey = null): array;

    /**
     * @return Order|null the pending hosted order the release cancelled (API-24a)
     *
     * @throws SlotException
     */
    public function release(Store $store, string $holdId, ?string $token): ?Order;

    /** A hold of the store by public id (no token: the quote only checks that it holds the cart's slot). */
    public function findHold(Store $store, string $holdId): ?SlotHold;

    /** @throws SlotException */
    public function ensureLive(Store $store, ?Branch $branch, string $holdId, ?string $token, ?string $slotId): SlotHold;

    /** @throws SlotException */
    public function lockForOrder(Store $store, string $holdId, ?string $token, ?string $slotId): SlotHold;

    public function bind(SlotHold $hold, Order $order, ?CarbonInterface $windowEnd): void;

    /** A hosted order was paid or its card hold authorised: the slot is the order's for good. */
    public function settleForOrder(Order $order): void;

    public function releaseForOrder(Order $order): void;

    /** API-27: an accepted late payment takes its order's slot again — false when the slot passed or is full. */
    public function retakeForOrder(Order $order): bool;
}
