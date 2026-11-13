<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use App\Models\Order;

/**
 * T4: lifecycle + tracking for a third-party courier delivery (Uber Direct, DoorDash).
 * Own-fleet deliveries do not use this — they live on orders.driver_id/delivery_status.
 *
 * P1-05: `fee` is stored as `fee_minor` in the row's `currency_code` (the store's at dispatch — a fact). It keeps the
 * store-precision string shape of the old StoreMoney cast.
 *
 * @property string|float $fee
 * @property int $fee_minor
 * @property string|null $currency_code
 */
class CourierDelivery extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['fee' => 'fee_minor,store'];

    protected $casts = [
        'eta_minutes' => 'integer',
        'courier_lat' => 'float',
        'courier_lng' => 'float',
        'courier_updated_at' => 'datetime',
    ];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
