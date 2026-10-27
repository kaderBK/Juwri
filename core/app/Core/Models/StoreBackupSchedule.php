<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * A recurring auto-backup rule for one restaurant. Advanced by the daily
 * `backups:run-scheduled` command, which fires the same GenerateStoreBackupJob
 * a manual backup uses.
 */
class StoreBackupSchedule extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'store_id', 'user_id', 'frequency', 'datasets', 'format',
        'branch_id', 'orders_window', 'notify_email', 'active',
        'next_run_at', 'last_run_at',
    ];

    protected $casts = [
        'datasets'    => 'array',
        'active'      => 'boolean',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public const FREQUENCIES = ['weekly', 'monthly'];

    /** Compute the next run time from a frequency, relative to a base moment. */
    public static function nextRunFor(string $frequency, ?\Carbon\Carbon $from = null): \Carbon\Carbon
    {
        $from = $from ? $from->copy() : now();

        return $frequency === 'monthly'
            ? $from->addMonth()->startOfDay()->addHours(3) // ~03:00 next month
            : $from->addWeek()->startOfDay()->addHours(3);
    }
}
