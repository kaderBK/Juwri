<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * A booking deposit refund the gateway layer refused during a cancel (lane C bug fix): pending until the owner's
 * "Retry refund" (or any later refund of the deposit) goes through, then done. One row per booking.
 *
 * @property int $id
 * @property int $store_id
 * @property int $booking_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property-read Booking|null $booking
 */
class BookingDepositRefund extends Model
{
    use BelongsToAccount;

    protected $table = 'booking_pending_refunds';

    protected $guarded = ['id', 'account_id'];

    protected $casts = [
        'store_id' => 'integer',
        'booking_id' => 'integer',
        'amount_minor' => 'integer',
        'attempts' => 'integer',
        'last_attempt_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
