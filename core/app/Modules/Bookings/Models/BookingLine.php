<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\StoresUtc;

/**
 * P3-10: one service of a booking, frozen at booking time (name, duration, price, deposit) — a reschedule keeps
 * these, so the customer keeps the price they booked at (API-8).
 *
 * @property int $id
 * @property int $booking_id
 * @property int|null $service_id
 * @property string $name
 * @property int $duration_min
 * @property int $price_minor
 * @property int $deposit_minor
 * @property int $position
 */
class BookingLine extends Model
{
    use BelongsToAccount;
    use StoresUtc;

    protected $table = 'booking_lines';

    public $timestamps = false;

    protected $guarded = ['id', 'account_id'];

    protected $casts = [
        'store_id' => 'integer',
        'booking_id' => 'integer',
        'service_id' => 'integer',
        'duration_min' => 'integer',
        'price_minor' => 'integer',
        'deposit_minor' => 'integer',
        'position' => 'integer',
    ];
}
