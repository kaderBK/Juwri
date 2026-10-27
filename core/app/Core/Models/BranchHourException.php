<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P6-291: one date exception to a branch's weekly hours — closed all day (holiday) or special open/close hours.
 * `date` is a calendar date in the branch's own timezone.
 *
 * @property int $id
 * @property int $store_id
 * @property int $branch_id
 * @property Carbon $date
 * @property bool $closed
 * @property string|null $open HH:MM (special hours only)
 * @property string|null $close HH:MM (close < open = past midnight)
 * @property string|null $note
 */
class BranchHourException extends Model
{
    use BelongsToAccount;

    protected $table = 'branch_hour_exceptions';

    protected $guarded = ['id', 'account_id'];

    protected $casts = ['store_id' => 'integer', 'branch_id' => 'integer', 'date' => 'date:Y-m-d', 'closed' => 'boolean'];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
