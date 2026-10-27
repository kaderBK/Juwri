<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Models\LedgerAccount;

/**
 * HPMS A2 — a banquet / venue event. Two billing modes (A8):
 *   linked      — hotel_stay_id set: items post to that stay's hotel_folio account.
 *   standalone  — folio_account_id set: its own LedgerAccount kind 'hotel_event'.
 * Status flow enquiry → confirmed → completed | cancelled. Store-scoped by
 * store_id.
 */
class HotelEvent extends Model
{
    use BelongsToAccount;

    protected $table = 'hotel_events';

    public const STATUS_ENQUIRY = 'enquiry';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ENQUIRY, self::STATUS_CONFIRMED, self::STATUS_COMPLETED, self::STATUS_CANCELLED,
    ];

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'attendees' => 'int',
    ];

    public function isLinkedToStay(): bool
    {
        return ! empty($this->hotel_stay_id);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<HotelEvent>  $q
     * @return \Illuminate\Database\Eloquent\Builder<HotelEvent>
     */
    public function scopeForRestaurant($q, int $storeId)
    {
        return $q->where('store_id', $storeId);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** @return BelongsTo<HotelStay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(HotelStay::class, 'hotel_stay_id');
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function folioAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'folio_account_id');
    }

    /** @return HasMany<HotelEventItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(HotelEventItem::class, 'event_id');
    }
}
