<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use App\Models\Order;

/**
 * P1: an order-scoped customer Web Push subscription (one browser/device that a
 * diner opted into on a track page). Keyed by the order — no customer account.
 */
class CustomerPushSubscription extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'failed_at' => 'datetime',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
