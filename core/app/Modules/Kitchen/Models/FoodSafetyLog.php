<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Kitchen\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * GAPS-RUN-2 B10-P1: a food-safety log entry (temperature reading, cleaning sign-off,
 * or free-form incident).
 */
class FoodSafetyLog extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'value'     => 'decimal:2',
        'breach'    => 'boolean',
        'logged_at' => 'datetime',
    ];

    public function check()
    {
        return $this->belongsTo(FoodSafetyCheck::class, 'check_id');
    }
}
