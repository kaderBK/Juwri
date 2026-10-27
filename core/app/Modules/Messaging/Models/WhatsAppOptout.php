<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class WhatsAppOptout extends Model
{
    use BelongsToAccount;

    protected $table = 'whatsapp_optouts';

    protected $guarded = [];

    /**
     * Opted out for this restaurant, or globally (store_id null).
     */
    public static function isOptedOut(string $phone, ?int $storeId = null): bool
    {
        return static::where('phone', $phone)
            ->where(function ($q) use ($storeId) {
                $q->whereNull('store_id');
                if ($storeId) {
                    $q->orWhere('store_id', $storeId);
                }
            })
            ->exists();
    }

    public static function optOut(string $phone, ?int $storeId = null, string $source = 'reply'): void
    {
        static::updateOrCreate(
            ['phone' => $phone, 'store_id' => $storeId],
            ['source' => $source]
        );
    }

    public static function optIn(string $phone, ?int $storeId = null): void
    {
        static::where('phone', $phone)->where('store_id', $storeId)->delete();
    }
}
