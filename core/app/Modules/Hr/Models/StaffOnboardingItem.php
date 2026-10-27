<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Models;

use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** SYSTEMS-RUN H-P1: one onboarding-checklist item for a staff member. */
class StaffOnboardingItem extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = ['done' => 'boolean', 'done_at' => 'datetime'];

    public const TEMPLATE = [
        'Documents uploaded',
        'PIN set',
        'Trained on POS',
        'Trained on KDS',
        'Uniform issued',
    ];

    public function staff()
    {
        return $this->belongsTo(StaffMember::class, 'staff_id');
    }
}
