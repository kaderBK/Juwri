<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** SYSTEMS-RUN H-P2: a leave type (annual/sick/unpaid/custom). */
class LeaveType extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = ['paid' => 'boolean', 'active' => 'boolean'];

    public const TEMPLATE = [
        ['name' => 'Annual', 'paid' => true, 'annual_allowance_days' => 21],
        ['name' => 'Sick', 'paid' => true, 'annual_allowance_days' => 10],
        ['name' => 'Unpaid', 'paid' => false, 'annual_allowance_days' => 0],
    ];

    public static function ensureSeeded(int $storeId): void
    {
        if (self::where('store_id', $storeId)->exists()) {
            return;
        }
        foreach (self::TEMPLATE as $t) {
            self::create($t + ['store_id' => $storeId]);
        }
    }
}
