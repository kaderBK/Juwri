<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * GAPS-RUN-2 B1: idempotency ledger for POS pushes. Unique on (order_id, provider)
 * so a job replay never double-pushes an order to the same POS.
 */
class PosOrderPush extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'pushed_at'     => 'datetime',
        'provider_meta' => 'array',
    ];

    public function isPushed(): bool
    {
        return $this->status === 'pushed' && !empty($this->external_id);
    }
}
