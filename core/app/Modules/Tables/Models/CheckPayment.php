<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Models;

use Juwri\Core\Models\Concerns\ResolvesStoreForMoney;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * One share of a split bill.
 *
 * @property string|float $amount P1-05: major amount (store-precision string on read, as the old StoreMoney cast)
 * @property int $amount_minor P1-05: minor units of currency_code (the order's currency)
 * @property string|null $currency_code
 */
class CheckPayment extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasFactory;
    use HasMinorMoney;
    use ResolvesStoreForMoney;

    protected $guarded = [];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    /**
     * P1-05: `amount` is stored as `amount_minor`; SQL sums use {@see HasMinorMoney::minorMoneySql()}.
     *
     * @var array<string, string>
     */
    protected array $minorMoney = ['amount' => 'amount_minor,store'];

    /** P1-05: a share is in its check's order currency (stamped on the order at creation), else the store's. */
    public function defaultMinorMoneyCurrency(): string
    {
        $checkId = $this->getAttribute('table_check_id');
        $code = $checkId ? DB::table('table_checks')->join('orders', 'orders.id', '=', 'table_checks.order_id')
            ->where('table_checks.id', $checkId)->value('orders.currency_code') : null;

        return $code ? strtoupper((string) $code) : $this->defaultStoreOrPlatformCurrency();
    }

    /** P1-05: a payment is a fact in the currency it was taken in. */
    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /** @return BelongsTo<TableCheck, $this> */
    public function tableCheck(): BelongsTo
    {
        return $this->belongsTo(TableCheck::class);
    }

    /** Money: a payment belongs to a check, which belongs to a store. */
    public function storeIdForMoney(): ?int
    {
        return $this->storeIdVia('table_checks', 'table_check_id', 'store_id');
    }
}
