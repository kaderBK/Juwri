<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P3-10: a day without bookings — for the whole store (staff_id null: holiday) or one staff member (day off).
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $staff_id
 * @property Carbon $date
 * @property string|null $note
 */
class BookingClosedDay extends Model
{
    use BelongsToAccount;

    protected $table = 'booking_closed_days';

    public $timestamps = false;

    protected $guarded = ['id', 'account_id'];

    protected $casts = ['store_id' => 'integer', 'staff_id' => 'integer', 'date' => 'date'];
}
