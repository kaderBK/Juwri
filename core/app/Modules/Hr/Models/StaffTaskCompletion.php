<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * Staff PWA SP8 #36: one tick of a StaffTask for a given business date, recording
 * which staff member completed it and when.
 */
class StaffTaskCompletion extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    // task_date is left uncast: we store/query the plain 'Y-m-d' business date, so a
    // date cast (which serialises to 'Y-m-d 00:00:00') would break firstOrCreate matching.
    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(StaffTask::class, 'staff_task_id');
    }
}
