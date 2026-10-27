<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use Juwri\Core\Models\Concerns\ResolvesStoreForMoney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * D1 — one itemised line of a refund: how much of ONE order line came back and
 * for how much money. Append-only, like OrderAdjustment; the parent adjustment
 * is the money event, these are its per-item detail.
 *
 * @property int $order_id
 * @property int $order_item_id
 * @property int $qty
 * @property string|float $amount P1-05: major amount (store-precision string on read)
 * @property int $amount_minor P1-05: minor units of currency_code (the order's currency)
 * @property string|null $currency_code
 */
class OrderRefundItem extends Model implements MinorMoneyModel
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

    /** P1-05: a refund line is in its order's currency (stamped on the order at creation). */
    public function defaultMinorMoneyCurrency(): string
    {
        $orderId = $this->getAttribute('order_id');
        $code = $orderId ? DB::table('orders')->where('id', $orderId)->value('currency_code') : null;

        return $code ? strtoupper((string) $code) : $this->defaultStoreOrPlatformCurrency();
    }

    /** P1-05: a refund is a fact of the sale — never re-interpreted by a store-currency change. */
    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /** Money: a refund item belongs to an order. */
    public function storeIdForMoney(): ?int
    {
        return $this->storeIdVia('orders', 'order_id', 'store_id');
    }
}
