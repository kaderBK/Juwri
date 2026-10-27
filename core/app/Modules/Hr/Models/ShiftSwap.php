<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** SYSTEMS-RUN T-P3: a shift swap request lifecycle. */
class ShiftSwap extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    public function shift()
    {
        return $this->belongsTo(StaffShift::class, 'shift_id');
    }
}
