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
 * P1-05: the drawer amounts are stored as `*_minor` in the row's `currency_code` (the store's when the shift opened —
 * a fact, never re-interpreted after a store currency change). They keep the store-precision string shape of the old
 * StoreMoney cast. Query with the `*_minor` columns.
 *
 * @property string|float $opening_float
 * @property string|float|null $expected_cash
 * @property string|float|null $counted_cash
 * @property string|float|null $over_short
 * @property int $opening_float_minor
 * @property int|null $expected_cash_minor
 * @property int|null $counted_cash_minor
 * @property int|null $over_short_minor
 * @property string|null $currency_code
 */
class PosShift extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = [
        'opening_float' => 'opening_float_minor,store',
        'expected_cash' => 'expected_cash_minor,store',
        'counted_cash' => 'counted_cash_minor,store',
        'over_short' => 'over_short_minor,store',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'pos_shift_id');
    }

    /** A shift's store_id IS the store id. */
    public function storeIdForMoney(): ?int
    {
        return $this->store_id;
    }
}
