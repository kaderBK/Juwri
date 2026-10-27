<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * GAPS-RUN-2 B8-P1: a catering request through its lifecycle.
 *
 * @property string|float|null $quote_total P1-05: major amounts (store-precision strings on read)
 * @property string|float|null $deposit_amount
 * @property int|null $quote_total_minor P1-05: minor units of `currency`
 * @property int|null $deposit_amount_minor
 * @property string|null $currency
 */
class CateringRequest extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $guarded = [];

    /**
     * P1-05: quote/deposit (store-precision strings, as the old StoreMoney cast) stored in minor units.
     *
     * @var array<string, string>
     */
    protected array $minorMoney = [
        'quote_total' => 'quote_total_minor,store',
        'deposit_amount' => 'deposit_amount_minor,store',
    ];

    /** The request's currency lives in the existing `currency` column (also read by the deposit ledger). */
    protected ?string $minorMoneyCurrencyColumn = 'currency';

    /** A quote/deposit is a fact in the currency it was made in. */
    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    protected $casts = [
        'event_at' => 'datetime',
        'items' => 'array',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
