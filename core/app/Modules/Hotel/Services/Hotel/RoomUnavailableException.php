<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Services\Hotel;

/**
 * HPMS A4 — thrown when a room cannot be held for the requested dates: no free
 * room of the type, or the hotel_booking_nights UNIQUE(room_id, night) fired
 * because a concurrent booking took the same room/night first ("the loser's
 * signal"). Controllers turn this into a friendly 409/422 "room just taken".
 */
class RoomUnavailableException extends \RuntimeException
{
}
