<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Models\Country;

/**
 * A platform billing tax applied to plan checkouts.
 *
 * P1-05 (split by type, as the owner decided for price_schedules): a PERCENTAGE tax stays a decimal rate in `value`;
 * a FIXED tax is money, stored as `value_amount_minor` in the row's `currency_code` (the platform currency; a
 * catalogue row). `value` stays the one public attribute — read/written as before (a number: the percent or the
 * fixed amount by `value_type`), so the admin screens, checkout and invoices are unchanged.
 *
 * @property float|null $value the percent, or the fixed amount (major units), by value_type
 * @property float|null $value_amount fixed tax, major amount
 * @property int|null $value_amount_minor
 * @property string|null $currency_code
 */
class Tax extends Model implements MinorMoneyModel
{
    use HasFactory;
    use HasMinorMoney {
        save as protected saveMinorMoney;
    }

    /** @var list<string> */
    protected $fillable = [
        'internal_name',
        'name',
        'description',
        'value',
        'value_type',
        'type',
        'billing_type',
        'countries',
    ];

    /** @var array<string, string> */
    protected array $minorMoney = ['value_amount' => 'value_amount_minor'];

    /**
     * The percent (percentage taxes) or the fixed amount (fixed taxes). A value set on the model and not saved yet
     * (an unsaved fixed value, or a computed amount the invoice screens assign for display) is read as it is.
     */
    public function getValueAttribute(mixed $value): ?float
    {
        if ($this->value_type === 'fixed' && ($value === null || $value === '')) {
            return $this->value_amount;
        }

        return ($value === null || $value === '') ? null : (float) $value;
    }

    /**
     * P1-05: move a fixed tax into `value_amount_minor` (money) and keep only a percentage in `value`.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = [])
    {
        $raw = $this->attributes['value'] ?? null;
        if ($this->value_type === 'fixed') {
            if ($raw !== null && $raw !== '') {
                $this->value_amount = $raw;
            }
            $this->attributes['value'] = null;
        } elseif (($this->attributes['value_amount_minor'] ?? null) !== null || $this->pendingMinorMoney('value_amount') !== null) {
            $this->value_amount = null; // a percentage (or untyped) tax carries no amount
        }

        return $this->saveMinorMoney($options);
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }
}
