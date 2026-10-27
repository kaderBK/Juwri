<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * HPMS A2 — one row per (room, night) a booking holds. The
 * UNIQUE(room_id, night) on this table is THE double-book race guard: two
 * racing confirms both try to insert the same (room, night), and the loser hits
 * a QueryException the BookingService turns into "room just taken". Cancel /
 * no-show / early-checkout DELETE the freed rows.
 */
class HotelBookingNight extends Model
{
    use BelongsToAccount;

    protected $table = 'hotel_booking_nights';

    protected $guarded = [];

    protected $casts = [
        'night' => 'date',
    ];

    /** @return BelongsTo<HotelBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(HotelBooking::class, 'booking_id');
    }
}
