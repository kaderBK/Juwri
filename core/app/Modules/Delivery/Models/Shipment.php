<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use App\Models\Order;

/**
 * rev/multi-vertical B4 — the parcel/shipment record for a physical-goods
 * order. One row per order (v1). The dashboard order-detail marks it shipped
 * with a tracking number/carrier; the storefront reads it back to show the
 * customer a status.
 */
class Shipment extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    public const STATUSES = ['pending', 'shipped', 'delivered'];

    protected $casts = [
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function markShipped(?string $carrier, ?string $trackingNumber): void
    {
        $this->status = 'shipped';
        $this->carrier = $carrier;
        $this->tracking_number = $trackingNumber;
        $this->shipped_at = now();
        $this->save();
    }

    public function markDelivered(): void
    {
        $this->status = 'delivered';
        $this->delivered_at = now();
        $this->save();
    }
}
