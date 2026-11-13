<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Models;

use App\Models\StoreTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * HPMS A1 — a sellable hotel room type (Standard / Deluxe / Suite). Rooms
 * (store_tables rows, area_type='room') point here via room_type_id.
 * base_rate is the fallback nightly price when no per-date room_rates row
 * overrides a date. Store-scoped by store_id.
 *
 * P1-05: `base_rate` is stored as `base_rate_minor` in the row's `currency_code` (the store's; a catalogue row — an edited amount moves to the store's current currency).
 * It keeps the number shape of the old `float` cast. Query with `base_rate_minor`.
 *
 * @property float $base_rate major amount
 * @property int $base_rate_minor
 * @property string|null $currency_code
 */
class RoomType extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $table = 'room_types';

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['base_rate' => 'base_rate_minor'];

    protected $casts = [
        'max_occupancy' => 'int',
        'sort' => 'int',
        'active' => 'boolean',
    ];

    /**
     * @param  Builder<RoomType>  $q
     * @return Builder<RoomType>
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

    /**
     * The rooms (store_tables rows) assigned to this type.
     *
     * @return HasMany<StoreTable, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(StoreTable::class, 'room_type_id');
    }

    /** @return HasMany<RoomRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(RoomRate::class, 'room_type_id');
    }
}
