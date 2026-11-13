<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Money\Money;
use App\Models\Order;

/**
 * P1-05: `total` is stored as `total_minor` in the row's `currency_code` — the order's (stamped on first save, a fact),
 * the same currency as its shares (check_payments). It keeps the store-precision string shape of the old StoreMoney
 * cast. Query with `total_minor`.
 *
 * @property string|float $total
 * @property int $total_minor
 * @property string|null $currency_code
 */
class TableCheck extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasFactory;
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['total' => 'total_minor,store'];

    protected $casts = [
        'parts' => 'integer',
    ];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /** A check's currency is its order's (its shares use the same), else the store's. */
    public function defaultMinorMoneyCurrency(): string
    {
        $code = ! empty($this->attributes['order_id'])
            ? DB::table('orders')->where('id', $this->attributes['order_id'])->value('currency_code') : null;

        return Currencies::normalize($code) ?: $this->defaultStoreOrPlatformCurrency();
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payments()
    {
        return $this->hasMany(CheckPayment::class)->orderBy('share_no');
    }

    /** P1-05: the check's currency — its own `currency_code` (the order's, stamped at creation). */
    public function moneyCurrency(): string
    {
        return $this->minorMoneyCurrency();
    }

    /** P1-05: shares are `amount_minor` in the order's currency — an exact integer sum. */
    public function paidAmount(): float
    {
        return Money::majorFromMinor((int) $this->payments()->where('status', 'paid')->sum('amount_minor'), $this->moneyCurrency());
    }

    public function remaining(): float
    {
        return round((float) $this->total - $this->paidAmount(), Currencies::exponent($this->moneyCurrency()));
    }
}
