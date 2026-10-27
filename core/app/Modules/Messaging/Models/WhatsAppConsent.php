<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class WhatsAppConsent extends Model
{
    use BelongsToAccount;

    protected $table = 'whatsapp_consents';

    protected $guarded = [];

    protected $casts = [
        'opted_in_at' => 'datetime',
        'opted_out_at' => 'datetime',
    ];

    /** Explicit marketing opt-in on record and not withdrawn. */
    public static function isOptedIn(string $phone, int $storeId): bool
    {
        return static::where('store_id', $storeId)
            ->where('phone', $phone)
            ->whereNotNull('opted_in_at')
            ->whereNull('opted_out_at')
            ->exists();
    }

    public static function optIn(string $phone, int $storeId, string $source = 'order'): void
    {
        static::updateOrCreate(
            ['store_id' => $storeId, 'phone' => $phone],
            ['source' => $source, 'opted_in_at' => now(), 'opted_out_at' => null]
        );
    }

    public static function optOut(string $phone, int $storeId): void
    {
        static::where('store_id', $storeId)
            ->where('phone', $phone)
            ->update(['opted_out_at' => now()]);
    }
}
