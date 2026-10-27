<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use App\Models\StoreTable;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Contracts\Hotel\RoomCharge;

/**
 * The one gate for a GUEST (storefront) charge-to-room, outside the room-service order form: the stay to charge, or
 * null to refuse. J57 (owner): the room is named by its QR key only (never a room number, a numeric id, a guest name
 * on its own or "the only checked-in stay"); the visitor's scanned room token must verify for that room whenever the
 * store requires it (the default); the room must have an active stay; the FULL registered guest name must match.
 * Staff manual posting (POS) does not go through here.
 */
final class GuestRoomCharge
{
    public static function stay(int $storeId, mixed $roomKey, mixed $guestName): ?Model
    {
        if (! is_string($roomKey) || trim($roomKey) === '') {
            return null;
        }
        $charge = app(RoomCharge::class);

        $room = StoreTable::query()
            ->where('store_id', $storeId)
            ->where('area_type', 'room')
            ->where('key', $roomKey)
            ->first();
        if ($room === null) {
            return null;
        }

        if ($charge->tokenRequired($storeId)) {
            $token = session('hotel_room_token');
            if (! is_string($token) || $token === '' || ! $charge->verifyToken($room, $token)) {
                return null;
            }
        }

        $stay = $charge->activeStayForRoom($storeId, (int) $room->id);
        if ($stay === null) {
            return null;
        }

        return $charge->guestNameMatches($stay, is_string($guestName) ? $guestName : null) ? $stay : null;
    }
}
