<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Juwri\Core\Contracts\Hotel\RoomCharge;

/**
 * J52b: wraps the bound {@see RoomCharge} on a migrated install (installed only while legacy_compat.enabled) so a
 * printed room QR token minted under the OLD app key still verifies (plan §5.3). Every other call, including
 * mintToken() (always the current key), goes straight to the wrapped implementation.
 *
 * verifyToken() = current-key check (unchanged) OR old-key check bound to the same store id and room id. A token for
 * another room or another store, an altered token, or anything that is not a 40-char hex HMAC is refused by both.
 */
final class LegacyRoomCharge implements RoomCharge
{
    public function __construct(
        private readonly RoomCharge $inner,
        private readonly LegacyRoomTokenKeys $legacyKeys,
    ) {}

    public function inner(): RoomCharge
    {
        return $this->inner;
    }

    public function verifyToken(Model $room, ?string $token): bool
    {
        if ($this->inner->verifyToken($room, $token)) {
            return true;
        }

        // Re-checked at call time: switching the module off stops old-key tokens at once.
        if (! LegacyCompatRoutes::enabled()) {
            return false;
        }

        return $this->legacyKeys->verifyLegacy((int) $room->getAttribute('store_id'), (int) $room->getKey(), $token);
    }

    public function mintToken(Model $room): string
    {
        return $this->inner->mintToken($room);
    }

    public function enabled(mixed $store): bool
    {
        return $this->inner->enabled($store);
    }

    public function available(mixed $store): bool
    {
        return $this->inner->available($store);
    }

    public function settings(int $storeId): array
    {
        return $this->inner->settings($storeId);
    }

    public function trayCharge(int $storeId): array
    {
        return $this->inner->trayCharge($storeId);
    }

    public function tokenRequired(int $storeId): bool
    {
        return $this->inner->tokenRequired($storeId);
    }

    public function activeStayForRoom(int $storeId, int $roomId): ?Model
    {
        return $this->inner->activeStayForRoom($storeId, $roomId);
    }

    public function checkedInStays(int $storeId): Collection
    {
        return $this->inner->checkedInStays($storeId);
    }

    public function guestNameMatches(Model $stay, ?string $name): bool
    {
        return $this->inner->guestNameMatches($stay, $name);
    }

    public function chargeOrderForTender(Model $order, Model $stay): array
    {
        return $this->inner->chargeOrderForTender($order, $stay);
    }

    public function orderChargeReversalState(Model $order): string
    {
        return $this->inner->orderChargeReversalState($order);
    }

    public function reverseOrderCharge(Model $order): array
    {
        return $this->inner->reverseOrderCharge($order);
    }

    public function serviceRequests(int $storeId, int $roomId, int $limit): Collection
    {
        return $this->inner->serviceRequests($storeId, $roomId, $limit);
    }

    public function statement(Model $stay): array
    {
        return $this->inner->statement($stay);
    }

    public function availableRoomTypes(int $storeId, string $checkIn, string $checkOut): array
    {
        return $this->inner->availableRoomTypes($storeId, $checkIn, $checkOut);
    }

    public function customerBookings(int $storeId, int $customerId, int $limit): array
    {
        return $this->inner->customerBookings($storeId, $customerId, $limit);
    }

    public function upsertStay(array $match, array $values): ?Model
    {
        return $this->inner->upsertStay($match, $values);
    }
}
