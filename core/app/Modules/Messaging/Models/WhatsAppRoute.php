<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class WhatsAppRoute extends Model
{
    use BelongsToAccount;

    protected $table = 'whatsapp_routes';

    protected $guarded = [];

    /** Resolve which restaurant owns a given provider sender identity. */
    public static function restaurantFor(string $provider, ?string $senderId): ?int
    {
        if (!$senderId) {
            return null;
        }
        return static::where('provider', $provider)
            ->where('sender_id', $senderId)
            ->value('store_id');
    }
}
