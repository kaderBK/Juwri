<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class WhatsAppInbound extends Model
{
    use BelongsToAccount;

    protected $table = 'whatsapp_inbound_messages';

    protected $guarded = [];

    protected $casts = [
        'media'       => 'array',
        'handled'     => 'boolean',
        'replied_at'  => 'datetime',
        'received_at' => 'datetime',
    ];
}
