<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class WhatsAppUsage extends Model
{
    use BelongsToAccount;

    protected $table = 'whatsapp_usage';

    protected $guarded = [];

    protected $casts = [
        'billable'   => 'boolean',
        'est_cost'   => 'decimal:5',
        'sell_price' => 'decimal:5',
    ];
}
