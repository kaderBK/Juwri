<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * Dedupe record for the staff PWA's offline outbox — one row per client_op_id ensures
 * a replayed status write applies exactly once.
 */
class StaffSyncedOp extends Model
{
    use BelongsToAccount;

    protected $table = 'staff_synced_ops';
    public $timestamps = false;
    protected $guarded = [];
}
