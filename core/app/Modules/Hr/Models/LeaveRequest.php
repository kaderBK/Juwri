<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Models;

use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** SYSTEMS-RUN H-P2: a staff leave request through its lifecycle. */
class LeaveRequest extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'half_day' => 'boolean',
        'decided_at' => 'datetime',
    ];

    public function staff()
    {
        return $this->belongsTo(StaffMember::class, 'staff_id');
    }

    public function type()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    /** Inclusive day count, halved for a half-day request. */
    public function days(): float
    {
        $d = $this->from_date->diffInDays($this->to_date) + 1;

        return $this->half_day ? $d * 0.5 : (float) $d;
    }
}
