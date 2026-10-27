<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Concerns\StoresUtc;
use Juwri\Core\Models\Store;

/**
 * P3-10: an appointment. Statuses (BookingRef.status): pending_payment (deposit due within the 15-minute payment
 * window, API-10), confirmed, pending_approval (an accepted late deposit whose time was gone, API-27), cancelled,
 * completed, no_show. Deposit states: none, pending, paid, refunded, kept (D8), received_late (API-21).
 *
 * @property int $id
 * @property string|null $public_id
 * @property int $store_id
 * @property int $staff_id
 * @property string $number
 * @property string $status
 * @property string $location
 * @property string $customer_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $note
 * @property string|null $address
 * @property string|null $lat
 * @property string|null $lng
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon $block_starts_at
 * @property Carbon $block_ends_at
 * @property int $duration_min
 * @property int $total_minor
 * @property int $deposit_minor
 * @property int $tax_minor
 * @property string $currency
 * @property string $deposit_status
 * @property int|null $deposit_transaction_id
 * @property Carbon|null $payment_expires_at
 * @property Carbon $cancel_deadline_at
 * @property string $manage_nonce
 * @property bool $remind_email
 * @property bool $remind_sms
 * @property bool $remind_whatsapp
 * @property Carbon|null $reminder_sent_at
 * @property int $no_show_fee_minor
 * @property string $source
 * @property string|null $client_key
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_by
 * @property Carbon|null $created_at
 * @property-read BookingStaff|null $staff
 * @property-read Store|null $store
 * @property-read Collection<int, BookingLine> $lines
 */
class Booking extends Model
{
    use BelongsToAccount;
    use StoresUtc;
    use HasUuids;

    /** Statuses a customer can still cancel or reschedule from. */
    public const ACTIVE = ['pending_payment', 'confirmed'];

    protected $table = 'bookings';

    protected $guarded = ['id', 'account_id'];

    protected $hidden = ['manage_nonce'];

    protected $casts = [
        'store_id' => 'integer',
        'staff_id' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'block_starts_at' => 'datetime',
        'block_ends_at' => 'datetime',
        'duration_min' => 'integer',
        'total_minor' => 'integer',
        'deposit_minor' => 'integer',
        'tax_minor' => 'integer',
        'deposit_transaction_id' => 'integer',
        'payment_expires_at' => 'datetime',
        'cancel_deadline_at' => 'datetime',
        'remind_email' => 'boolean',
        'remind_sms' => 'boolean',
        'remind_whatsapp' => 'boolean',
        'reminder_sent_at' => 'datetime',
        'no_show_fee_minor' => 'integer',
        'cancelled_at' => 'datetime',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<BookingStaff, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(BookingStaff::class, 'staff_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** @return HasMany<BookingLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BookingLine::class, 'booking_id')->orderBy('position');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** Before the cancellation deadline (free cancel / reschedule, API-9). */
    public function beforeDeadline(): bool
    {
        return now()->lt($this->cancel_deadline_at);
    }
}
