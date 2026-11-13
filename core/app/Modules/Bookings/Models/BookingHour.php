<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\StoresUtc;

/**
 * P3-10: one weekly hours row — the store's (staff_id null) or one staff member's. `open` rows are working time,
 * `break` rows are time off inside it. Times are store-local "HH:MM"; an `ends_local` of "24:00" means midnight.
 *
 * @property int $id
 * @property int $store_id
 * @property int|null $staff_id
 * @property int $weekday
 * @property string $kind
 * @property string $starts_local
 * @property string $ends_local
 */
class BookingHour extends Model
{
    use BelongsToAccount;
    use StoresUtc;

    protected $table = 'booking_hours';

    public $timestamps = false;

    protected $guarded = ['id', 'account_id'];

    protected $casts = ['store_id' => 'integer', 'staff_id' => 'integer', 'weekday' => 'integer'];
}
