<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * D8 — one named POS device (register / till). Heartbeats `last_seen_at` while a
 * register session is unlocked on it, and may pin a `printer_profile_id` that
 * overrides the branch printer at print resolve. `retired_at` soft-hides it.
 *
 * @property int $store_id
 * @property int|null $printer_profile_id
 */
class PosTerminal extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** @return BelongsTo<PrinterProfile, $this> */
    public function printerProfile(): BelongsTo
    {
        return $this->belongsTo(PrinterProfile::class, 'printer_profile_id');
    }

    /**
     * Live terminals only (not retired).
     *
     * @param  Builder<PosTerminal>  $query
     * @return Builder<PosTerminal>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    /** Considered "online" when it heartbeat within the last 2 minutes. */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(2));
    }
}
