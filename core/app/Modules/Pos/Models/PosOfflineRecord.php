<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** Offline POS replay (OFFLINE-POS-SPEC §3): see the pos_offline_records migration. */
class PosOfflineRecord extends Model
{
    use BelongsToAccount;

    protected $table = 'pos_offline_records';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected $casts = ['store_id' => 'integer', 'order_id' => 'integer', 'result' => 'array', 'flags' => 'array',
        'device_created_at' => 'immutable_datetime', 'business_at' => 'immutable_datetime'];
}
