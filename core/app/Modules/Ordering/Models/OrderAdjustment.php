<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\ResolvesStoreForMoney;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use App\Models\Order;

/**
 * Append-only audit row for a void / refund / comp. Never updated or deleted.
 *
 * @property string|float $amount P1-05: major amount (store-precision string on read)
 * @property int $amount_minor P1-05: minor units of currency_code (the order's currency)
 * @property string|null $currency_code
 */
class OrderAdjustment extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;
    use ResolvesStoreForMoney;

    protected $guarded = [];
    /**
     * P1-05: `amount` (store-precision string, as the old StoreMoney cast) is stored as `amount_minor`.
     *
     * @var array<string, string>
     */
    protected array $minorMoney = ['amount' => 'amount_minor,store'];

    /** P1-05: a line's amounts are in its order's currency (stamped on the order at creation). */
    public function defaultMinorMoneyCurrency(): string
    {
        $orderId = $this->getAttribute('order_id');
        $code = $orderId ? \Illuminate\Support\Facades\DB::table('orders')->where('id', $orderId)->value('currency_code') : null;

        return $code ? strtoupper((string) $code) : $this->defaultStoreOrPlatformCurrency();
    }

    /** P1-05: the order's currency is a fact of the sale — never re-interpreted by a store-currency change. */
    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public const TYPES = ['void', 'refund', 'comp'];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** HasMinorMoney (row currency): an adjustment belongs to an order. */
    public function storeIdForMoney(): ?int
    {
        return $this->storeIdVia('orders', 'order_id', 'store_id');
    }
}
