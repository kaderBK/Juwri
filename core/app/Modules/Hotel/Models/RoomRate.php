<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * HPMS A1 — a per-date price override for (room_type, rate_plan, date). A row
 * with a NULL rate_plan_id is the type's base per-date price; a plan-specific
 * row overrides it. UNIQUE(room_type_id, rate_plan_id, date) keeps one price per
 * cell. Store-scoped by store_id.
 *
 * `date` is deliberately NOT cast to a `date` object: a `date` cast serialises to
 * `Y-m-d 00:00:00`, so an `updateOrCreate` keyed on a plain `Y-m-d` string (the
 * A11 rates.bulk upsert) would never match an existing row and would duplicate
 * the base row (whose NULL rate_plan_id the UNIQUE cannot enforce). Stored and
 * read as a plain `Y-m-d` string; RateService parses it via Carbon::parse.
 *
 * P1-05: `price` is stored as `price_minor` in the row's `currency_code` (the store's; a catalogue row — an edited amount moves to the store's current currency).
 * It keeps the number shape of the old `float` cast. Query with `price_minor`.
 *
 * @property float $price major amount
 * @property int $price_minor
 * @property string|null $currency_code
 */
class RoomRate extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $table = 'room_rates';

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['price' => 'price_minor'];

    protected $casts = [
    ];

    /**
     * @param  Builder<RoomRate>  $q
     * @return Builder<RoomRate>
     */
    public function scopeForRestaurant($q, int $storeId)
    {
        return $q->where('store_id', $storeId);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    /** @return BelongsTo<RatePlan, $this> */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class, 'rate_plan_id');
    }
}
