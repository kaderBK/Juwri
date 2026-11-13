<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Modules\Menu\Models\MenuExtra;

/**
 * @property float|null $price P1-05: major amount
 * @property int|null $price_minor P1-05: minor units of currency_code (the order's currency)
 * @property string|null $currency_code
 */
class OrderItemExtra extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * P1-05: the snapshotted modifier price (a number, as the old uncast column) is stored as `price_minor`.
     *
     * @var array<string, string>
     */
    protected array $minorMoney = ['price' => 'price_minor'];

    /** P1-05: a modifier's amount is in its order's currency (through its order line). */
    public function defaultMinorMoneyCurrency(): string
    {
        $lineId = $this->getAttribute('order_item_id');
        $code = $lineId ? \Illuminate\Support\Facades\DB::table('order_items')->where('order_items.id', $lineId)
            ->join('orders', 'orders.id', '=', 'order_items.order_id')->value('orders.currency_code') : null;

        return $code ? strtoupper((string) $code) : $this->defaultStoreOrPlatformCurrency();
    }

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    /** @return BelongsTo<MenuExtra, $this> */
    public function extra(): BelongsTo
    {
        return $this->belongsTo(MenuExtra::class, 'extra_id', 'id');
    }
}
