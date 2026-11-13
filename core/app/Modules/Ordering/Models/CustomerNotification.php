<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use App\Models\Customer;
use App\Models\Order;

/**
 * A single customer-facing notification (order-status update) for a logged-in
 * diner's inbox. See rev/notif-center-spec.md and the create migration.
 *
 * Written best-effort from CustomerPushService::orderStatusChanged() for orders
 * with a customer_id; read only through the auth:customer-guarded endpoints,
 * always scoped to the authenticated customer (no cross-tenant reads).
 */
class CustomerNotification extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<CustomerNotification>  $query
     * @return \Illuminate\Database\Eloquent\Builder<CustomerNotification>
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
