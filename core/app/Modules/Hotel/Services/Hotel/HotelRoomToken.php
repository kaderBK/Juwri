<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Services\Hotel;

use App\Models\StoreTable;

/**
 * B3 (behind a flag) — signed room token for hotel charge-to-room.
 *
 * The charge-to-room path resolves the room from a client-controlled, guessable
 * param (room id / number), so the tightened name gate is the only thing stopping a
 * guest who knows another occupied room's number + registered name from charging that
 * room's folio. This binds the charge to the room the diner physically SCANNED: the
 * room QR carries a room-bound HMAC token (`rt=`), the storefront stashes it in the
 * session on scan, and the charge-to-room gate requires it to match the resolved room.
 *
 * ON BY DEFAULT since J57 (owner decision): `enabled()` is true unless the owner explicitly
 * turned it off in the hotel settings. Printed room QR codes carry the token (mint() below
 * feeds it into the QR URL); codes printed without it must be reprinted for charge-to-room.
 */
class HotelRoomToken
{
    /**
     * Per-store flag: require a valid scanned-room token for charge-to-room. J57 (owner): ON by default, so an
     * unset value means required; only an explicit "off" saved by the owner turns it off.
     */
    public static function enabled(int $storeId): bool
    {
        $value = store_options($storeId, 'hotel_charge_room_token_required');
        if ($value === null || $value === false || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** The room-bound token embedded in this room's QR URL (`rt=`). */
    public static function mint(StoreTable $room): string
    {
        return substr(hash_hmac('sha256', self::payload($room), self::key()), 0, 40);
    }

    /** True iff $token is the valid token for THIS room (constant-time compare). */
    public static function verify(StoreTable $room, ?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals(self::mint($room), (string) $token);
    }

    /** Room-scoped payload — a token for room A never validates room B. */
    private static function payload(StoreTable $room): string
    {
        return 'room|'.(int) $room->store_id.'|'.(int) $room->id;
    }

    private static function key(): string
    {
        // The app key is a stable per-deployment secret; the token only needs to be
        // unforgeable without it, not reversible.
        return (string) config('app.key');
    }
}
