<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * HPMS A1 — a rate plan (Flexible / Non-refundable / Advance-Purchase). A plan
 * can derive from a parent via a percent/fixed modifier (RateService applies it
 * during quote resolution). refundable + cancel_before_hours describe the
 * cancellation policy. Every store gets one auto-created "Standard" flexible
 * plan on first PMS use so single-rate hotels never meet the concept.
 * Store-scoped by store_id.
 *
 * P1-05 (split by type, as the owner decided for price_schedules): a PERCENT modifier stays a decimal rate in
 * `modifier_value`; a FIXED modifier is money, stored as `modifier_amount_minor` in the row's `currency_code` (the
 * store's; a catalogue row). `modifier_value` stays the one public attribute — read/written as before (a number,
 * the percent or the fixed amount by `modifier_type`), so forms, JSON and RateService are unchanged.
 *
 * @property float|null $modifier_value the percent, or the fixed amount (major units), by modifier_type
 * @property float|null $modifier_amount fixed modifier, major amount
 * @property int|null $modifier_amount_minor
 * @property string|null $currency_code
 */
class RatePlan extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney {
        save as protected saveMinorMoney;
    }

    protected $table = 'rate_plans';

    public const MODIFIER_PERCENT = 'percent';

    public const MODIFIER_FIXED = 'fixed';

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['modifier_amount' => 'modifier_amount_minor'];

    protected $casts = [
        'refundable' => 'boolean',
        'cancel_before_hours' => 'int',
        'active' => 'boolean',
    ];

    /**
     * The percent (percent plans) or the fixed amount (fixed plans) — the shape the old float column had. An unsaved
     * fixed value still sits in the percent column until save() moves it.
     */
    public function getModifierValueAttribute(mixed $value): ?float
    {
        if ($this->modifier_type === self::MODIFIER_FIXED && ($value === null || $value === '')) {
            return $this->modifier_amount;
        }

        return ($value === null || $value === '') ? null : (float) $value;
    }

    /**
     * P1-05: move a fixed modifier into `modifier_amount_minor` (money) and keep only a percent in `modifier_value`.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = [])
    {
        $raw = $this->attributes['modifier_value'] ?? null;
        if ($this->modifier_type === self::MODIFIER_FIXED) {
            if ($raw !== null && $raw !== '') {
                $this->modifier_amount = $raw;
            }
            $this->attributes['modifier_value'] = null;
        } elseif (($this->attributes['modifier_amount_minor'] ?? null) !== null || $this->pendingMinorMoney('modifier_amount') !== null) {
            $this->modifier_amount = null; // a percent (or no) modifier carries no amount
        }

        return $this->saveMinorMoney($options);
    }

    /**
     * @param  Builder<RatePlan>  $q
     * @return Builder<RatePlan>
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

    /** @return BelongsTo<RatePlan, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class, 'parent_id');
    }
}
