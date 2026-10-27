<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * T3: maps an external aggregator store to a platform restaurant/branch. The trusted
 * source of tenant identity for an inbound aggregator webhook (never the payload).
 *
 * @property-read Store|null $store
 */
class AggregatorConnection extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'settings' => 'array',
    ];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    /** Resolve the connection for a provider + external store id. */
    public static function resolve(string $provider, string $externalStoreId): ?self
    {
        return static::where('provider', $provider)
            ->where('external_store_id', $externalStoreId)
            ->first();
    }
}
