<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Models;

use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** SYSTEMS-RUN H-P1: a staff document (contract/id/health cert/custom) with expiry. */
class StaffDocument extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = ['expires_at' => 'date', 'uploaded_by_staff' => 'boolean'];

    public function staff()
    {
        return $this->belongsTo(StaffMember::class, 'staff_id');
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->expires_at && $this->expires_at->lte(now()->addDays($days));
    }
}
