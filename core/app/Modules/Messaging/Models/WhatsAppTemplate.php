<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class WhatsAppTemplate extends Model
{
    use BelongsToAccount;

    protected $table = 'whatsapp_templates';

    protected $guarded = [];

    protected $casts = ['variables' => 'array'];

    /** Approved template names available to a restaurant (its own + platform). */
    public static function approvedNames(int $storeId, ?string $provider = null): array
    {
        return static::where('status', 'approved')
            ->when($provider, fn ($q) => $q->where('provider', $provider))
            ->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)->orWhereNull('store_id');
            })
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }
}
