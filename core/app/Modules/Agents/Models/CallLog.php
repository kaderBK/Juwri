<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use App\Models\Order;

/** T6: AI phone-agent call record (idempotent per call_sid). */
class CallLog extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'transcript' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
