<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Models;

use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * GAPS-RUN-2 B7-P1: a planned staff shift. Not a cash-drawer session (pos_shifts)
 * and not worked time (StaffTimeClock) — this is the rota. Only published shifts are
 * visible to staff.
 */
class StaffShift extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'published' => 'boolean',
        'is_open' => 'boolean',
    ];

    public function staff()
    {
        return $this->belongsTo(StaffMember::class, 'staff_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
