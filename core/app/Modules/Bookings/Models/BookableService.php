<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\StoresUtc;
use Juwri\Core\Money\Money;

/**
 * P3-10: a bookable service — duration, buffers before/after (cleaning, setup; never shown to the customer), price
 * and an optional deposit (a fixed amount or a percent of the price), and where it can be done (venue / at home).
 *
 * @property int $id
 * @property string|null $public_id
 * @property int $store_id
 * @property string $name
 * @property string|null $description
 * @property int $duration_min
 * @property int $buffer_before_min
 * @property int $buffer_after_min
 * @property int $price_minor
 * @property string $currency
 * @property string $deposit_type
 * @property int $deposit_value
 * @property bool $venue
 * @property bool $at_home
 * @property bool $active
 * @property int $position
 */
class BookableService extends Model
{
    use BelongsToAccount;
    use StoresUtc;
    use HasUuids;

    protected $table = 'booking_services';

    protected $guarded = ['id', 'account_id'];

    protected $casts = [
        'store_id' => 'integer',
        'duration_min' => 'integer',
        'buffer_before_min' => 'integer',
        'buffer_after_min' => 'integer',
        'price_minor' => 'integer',
        'deposit_value' => 'integer',
        'venue' => 'boolean',
        'at_home' => 'boolean',
        'active' => 'boolean',
        'position' => 'integer',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsToMany<BookingStaff, $this> */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(BookingStaff::class, 'booking_staff_services', 'service_id', 'staff_id');
    }

    /** The deposit this service asks for, in minor units of its currency (never more than the price). */
    public function depositMinor(): int
    {
        $price = max(0, $this->price_minor);

        $deposit = match ($this->deposit_type) {
            'amount' => max(0, $this->deposit_value),
            'percent' => Money::ofMinor($price, $this->currency)->percent(max(0, min(100, $this->deposit_value)))->minor(),
            default => 0,
        };

        return min($deposit, $price);
    }
}
