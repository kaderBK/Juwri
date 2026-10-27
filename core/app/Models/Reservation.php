<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Modules\Messaging\Services\MobilePush\MobilePushService;
use Juwri\Modules\Reservations\Jobs\PushRwgBookingUpdateJob;

/**
 * P1-05: `deposit_amount` is stored as `deposit_amount_minor` in the row's `currency` (the store's, stamped on first
 * save; the deposit is a charge — never re-interpreted). It keeps the store-precision string shape of the old
 * StoreMoney cast. Query with `deposit_amount_minor`.
 *
 * @property string|float $deposit_amount major amount (store-precision string on read, as the old StoreMoney cast)
 * @property int $deposit_amount_minor
 * @property string|null $currency
 */
class Reservation extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;
    use HasUuids;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['deposit_amount' => 'deposit_amount_minor,store'];

    protected ?string $minorMoneyCurrencyColumn = 'currency';

    protected $casts = [
        'reserved_at' => 'datetime',
        'party_size' => 'integer',
    ];

    /* PARITY-RUN T9: notify the store's installed apps that a booking came in.
       Additive and guarded in the shape Order::booted() already uses — a push
       may never break a reservation write, whichever path created it. */
    protected static function booted(): void
    {
        static::created(function (Reservation $reservation) {
            try {
                MobilePushService::reservationCreated($reservation);
            } catch (\Throwable $e) {
                // best-effort only
            }
        });

        /* GOOGLE-PARTNER Lane C (C6): push a Reserve-with-Google real-time update on
           any status transition of a Google-sourced booking. Hooked on the MODEL
           (not the controller) on purpose — status is also written outside the
           controller (ReservationDepositService::markPaid, and any future staff
           path), and a controller-only hook would silently desync those. Only
           Google bookings (rwg_booking_id set) push; store-native rows never do.
           Queued + fail-soft + dark (the client no-ops when unconfigured). */
        static::updated(function (Reservation $reservation) {
            if (! $reservation->wasChanged('status') || empty($reservation->rwg_booking_id)) {
                return;
            }
            try {
                PushRwgBookingUpdateJob::dispatch((int) $reservation->id);
            } catch (\Throwable $e) {
                // best-effort only
            }
        });
    }

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function scopeForRestaurant($query, int $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    /**
     * reserved_at holds the diner's local wall-clock, so "upcoming" must be judged
     * against now in the STORE's timezone. Pass the restaurant id to do so; the
     * default (null) keeps the platform timezone for back-compatibility.
     */
    public function scopeUpcoming($query, ?int $storeId = null)
    {
        return $query->where('reserved_at', '>=', now($storeId ? store_tz($storeId) : null))
            ->whereIn('status', ['pending', 'confirmed']);
    }

    /**
     * P1-03 (J25-1): UUIDv7 public id (HasUuids) for URLs/APIs; the bigint id stays the primary key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
