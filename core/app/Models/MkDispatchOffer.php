<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * Marketplace T4-2: an offer of a delivery order to one fleet driver.
 * status: offered | accepted | rejected | expired | cancelled.
 */
class MkDispatchOffer extends Model
{
    use BelongsToAccount;

    protected $table = 'marketplace_dispatch_offers';

    public const STATUSES = ['offered', 'accepted', 'rejected', 'expired', 'cancelled'];

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    /** Offers still waiting for a driver response. */
    public function scopePending($query)
    {
        return $query->where('status', 'offered');
    }

    public function isPending(): bool
    {
        return $this->status === 'offered';
    }

    public function isExpiredByClock(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now());
    }
}
