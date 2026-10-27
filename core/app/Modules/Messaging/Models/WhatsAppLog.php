<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class WhatsAppLog extends Model
{
    use BelongsToAccount;

    protected $table = 'whatsapp_logs';

    protected $guarded = [];

    protected $casts = [
        'delivered_at' => 'datetime',
        'read_at'      => 'datetime',
    ];
}
