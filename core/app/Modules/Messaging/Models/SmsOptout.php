<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class SmsOptout extends Model
{
    use BelongsToAccount;

    protected $table = 'sms_optouts';

    protected $guarded = [];

    /**
     * Is this phone opted out — either globally (store_id null) or for the
     * given restaurant?
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
}
