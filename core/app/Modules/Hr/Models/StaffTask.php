<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * Staff PWA SP8 #36/#37: a checklist item a manager defines for a restaurant,
 * optionally targeted at a single role (null = everyone). Daily completion lives
 * in StaffTaskCompletion, keyed by business date.
 */
class StaffTask extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'active'   => 'boolean',
        'position' => 'integer',
    ];

    public function completions()
    {
        return $this->hasMany(StaffTaskCompletion::class);
    }
}
