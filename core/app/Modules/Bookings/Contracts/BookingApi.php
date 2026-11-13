<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Contracts;

use Carbon\CarbonImmutable;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Data\BookingRefusal;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingHold;

/**
 * P3-06b phase D: the booking engine (P3-10) as the headless storefront API uses it — services, staff, days, slots,
 * 10-minute holds, the deposit quote, booking, the signed manage link, cancel and same-price reschedule. Every call is
 * scoped to the given store; ids in and out are public ids (services, staff, holds, bookings), slot ids are opaque.
 * A refused step throws {@see BookingRefusal} with the kit error code. Implemented by Bookings' BookingApiService on
 * the engine's own services (SlotEngine, HoldService, BookingService, DepositService, ManageLink).
 *
 * Slot rows: `array{id: string, staff_id: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, free: bool}`
 * (UTC instants; `staff_id` is the staff public id that would do it).
 */
interface BookingApi
{
    /** Plan feature `booking_api` (API-5). */
    public function apiAllowed(Store $store): bool;

    /** D13 "no hours = no bookings": hours, staff, a service and the `booking` plan feature are in place. */
    public function open(Store $store): bool;

    public function timezone(Store $store): string;

    /** @return array{staff_choice: bool, cancel_window_hours: int, deposit_refunds: bool, at_home: bool} */
    public function rules(Store $store): array;

    /**
     * Are these all active bookable service public ids of the store (a booking cart, not a goods cart)?
     *
     * @param  list<string>  $ids
     */
    public function areServices(Store $store, array $ids): bool;

    /**
     * Staff who do every one of the services (empty when the store turned staff choice off: "any staff" only).
     *
     * @param  list<string>  $serviceIds
     * @return list<array{id: string, name: string, role: string, photo: string, service_ids: list<string>}>
     *
     * @throws BookingRefusal
     */
    public function staff(Store $store, array $serviceIds): array;

    /**
     * The day strip from a store-local date: per day `closed` (nobody works), `full`, `few` (≤ 2 free starts) or
     * `available`, and the first free start.
     *
     * @param  list<string>  $serviceIds
     * @return list<array{date: string, state: string, first_slot_at: CarbonImmutable|null}>
     *
     * @throws BookingRefusal
     */
    public function days(Store $store, array $serviceIds, ?string $staffId, string $location, string $from, int $days, ?Booking $reschedule = null): array;

    /**
     * Start times of a store-local date ("any staff": one row per time, done by the first free staff member).
     *
     * @param  list<string>  $serviceIds
     * @return array{duration_min: int, slots: list<array{id: string, staff_id: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, free: bool}>}
     *
     * @throws BookingRefusal
     */
    public function slots(Store $store, array $serviceIds, ?string $staffId, string $location, string $date, ?Booking $reschedule = null): array;

    /**
     * The first free start at or after `$after` within the booking horizon, or null.
     *
     * @param  list<string>  $serviceIds
     * @return array{id: string, staff_id: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, free: bool}|null
     */
    public function nextFree(Store $store, array $serviceIds, ?string $staffId, string $location, CarbonImmutable $after, ?Booking $reschedule = null): ?array;

    /**
     * Hold a listed slot for 10 minutes (API-11); with `$reschedule` the hold is tied to that booking (API-8).
     *
     * @param  list<string>  $serviceIds
     * @param  array{lat?: float|null, lng?: float|null}  $where
     * @return array{hold: BookingHold, token: string}
     *
     * @throws BookingRefusal
     */
    public function hold(Store $store, string $slotId, array $serviceIds, string $holderKey, ?Booking $reschedule = null, array $where = []): array;

    /** A booking hold of the store by its public id (any status), or null. */
    public function findHold(Store $store, string $holdId): ?BookingHold;

    /**
     * The service public ids of a hold, in its order.
     *
     * @return list<string>
     */
    public function holdServiceIds(BookingHold $hold): array;

    /**
     * Release a hold (API-24a, API-26). A plain hold is freed (null). A hold bound to a booking still waiting for its
     * deposit cancels that booking when the store has "Release holds during payment" on (the booking is returned),
     * else refuses with `hold_in_use`. The customer gets the deposit-not-paid message only when the store turned
     * "Message when a customer cancels a pending payment" on (API-29).
     *
     * @throws BookingRefusal
     */
    public function releaseHold(Store $store, BookingHold $hold, string $token): ?Booking;

    /**
     * What these services cost: lines, total, the deposit due now and what is due at the visit (minor units).
     *
     * @param  list<string>  $serviceIds
     * @return array{currency: string, lines: list<array{id: string, name: string, price_minor: int, deposit_minor: int, duration_min: int}>, total_minor: int, deposit_minor: int, due_later_minor: int, duration_min: int}
     *
     * @throws BookingRefusal
     */
    public function quote(Store $store, array $serviceIds, string $location): array;

    /**
     * Book from a live hold (idempotent per client key). `$customer`: name, phone, email?, note?, address?, lat?, lng?,
     * source (api | kiosk). `$returnUrl`: where the hosted deposit page sends the customer afterwards (already checked
     * against the token's allowed origins).
     *
     * @param  array<string, mixed>  $customer
     * @return array{booking: Booking, replayed: bool}
     *
     * @throws BookingRefusal
     */
    public function book(Store $store, string $holdId, string $holdToken, array $customer, string $clientKey, ?string $returnUrl = null): array;

    /** The store's booking signed by this manage token, or null; an unpaid booking past its window is closed first. */
    public function byManageToken(Store $store, string $token): ?Booking;

    public function manageToken(Booking $booking): string;

    /** @return array{manage: string, calendar: string, pay: string} */
    public function urls(Booking $booking, Store $store): array;

    /** @return list<string> the booking's service public ids */
    public function serviceIds(Booking $booking): array;

    /** The public id of the booking's staff member. */
    public function staffId(Booking $booking): string;

    /**
     * Cancel (D7/D8): `refunded` | `kept` | `none` (what happened to the deposit), or `refund_pending` (the refund was
     * refused by the gateway layer and waits for the owner's retry; the deposit is still paid).
     *
     * @throws BookingRefusal
     */
    public function cancel(Booking $booking): string;

    /**
     * Move a confirmed booking to the time of its reschedule hold: same services and price, no money moves (API-8,
     * API-9).
     *
     * @throws BookingRefusal
     */
    public function reschedule(Store $store, Booking $booking, string $holdId, string $holdToken): Booking;
}
