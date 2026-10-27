<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Kitchen\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * GAPS-RUN-2 B10-P1: a configured food-safety check (fridge temp, appliance, etc.).
 */
class FoodSafetyCheck extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'target_min' => 'decimal:2',
        'target_max' => 'decimal:2',
        'active'     => 'boolean',
    ];

    /** Is a measured value outside this check's target band? */
    public function isBreach(?float $value): bool
    {
        if ($value === null) {
            return false;
        }
        if ($this->target_min !== null && $value < (float) $this->target_min) {
            return true;
        }
        if ($this->target_max !== null && $value > (float) $this->target_max) {
            return true;
        }
        return false;
    }
}
