<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Hotel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * P1-13 extension point: the hotel features code below the Hotel module touches (Core/App models and helpers, Ordering,
 * Pos, Verticals — layers that may not import Hotel) — charge-to-room on the storefront, POS and goods checkout, the
 * signed room QR token, tray charge, the in-room guest panel, refund reversal of a folio charge, and the read-only
 * booking lookups the AI agents use. Bound by the Hotel module (Community, P1-13 13c-R).
 *
 * Models cross this seam as plain {@see Model}s (a room is a `store_tables` row, a stay a `hotel_stays` row,
 * an order an `orders` row); the Hotel implementation narrows them.
 */
interface RoomCharge
{
    /** Hotel mode live for this store (owner toggle AND plan). Accepts a store id, a store or null. */
    public function enabled(mixed $store): bool;

    /** The plan grants hotel (whatever the owner toggle says). */
    public function available(mixed $store): bool;

    /** @return array<string, mixed> the store's `hotel_settings` (wifi, house rules, breakfast, departments) */
    public function settings(int $storeId): array;

    /** @return array{type: string, value: float} room-service tray charge */
    public function trayCharge(int $storeId): array;

    /** The store requires the signed room token for charge-to-room. */
    public function tokenRequired(int $storeId): bool;

    /** The room-bound token for this room's QR URL. */
    public function mintToken(Model $room): string;

    public function verifyToken(Model $room, ?string $token): bool;

    /** The checked-in stay on a room, or null. */
    public function activeStayForRoom(int $storeId, int $roomId): ?Model;

    /** @return Collection<int, covariant Model> the store's checked-in stays */
    public function checkedInStays(int $storeId): Collection;

    public function guestNameMatches(Model $stay, ?string $name): bool;

    /** @return array{charged_to_room: bool, room_folio_message: string} */
    public function chargeOrderForTender(Model $order, Model $stay): array;

    /** 'none' | 'charged' | 'reversed' */
    public function orderChargeReversalState(Model $order): string;

    /** @return array{state: string, amount?: float, tx?: string, stay_id?: int} */
    public function reverseOrderCharge(Model $order): array;

    /** @return Collection<int, covariant Model> the room's CURRENT stay's recent open / in-progress / done guest requests, newest first (empty without an active stay) */
    public function serviceRequests(int $storeId, int $roomId, int $limit): Collection;

    /** @return array<string, mixed> the stay's folio statement */
    public function statement(Model $stay): array;

    /**
     * Room-type cards (id, name, base_rate, available, nightly_total) for a date range; empty when the booking
     * engine is absent or errors.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableRoomTypes(int $storeId, string $checkIn, string $checkOut): array;

    /** @return array<int, array<string, mixed>> a customer's hotel bookings, newest first */
    public function customerBookings(int $storeId, int $customerId, int $limit): array;

    /**
     * Create or update a stay (demo/docs seeders); null without a hotel module.
     *
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $values
     */
    public function upsertStay(array $match, array $values): ?Model;
}
