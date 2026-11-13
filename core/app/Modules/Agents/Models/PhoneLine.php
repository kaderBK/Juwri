<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/** T6: maps an inbound phone number to a restaurant + AI-agent config. */
class PhoneLine extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'orders_enabled' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** Resolve an active line by the dialled number for a provider. */
    public static function resolve(string $provider, string $number): ?self
    {
        return static::where('provider', $provider)
            ->where('number', $number)
            ->where('active', true)
            ->first();
    }
}
