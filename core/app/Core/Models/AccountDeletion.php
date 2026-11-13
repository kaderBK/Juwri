<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Owner self-service account deletion request (see AccountDeletionService).
 *
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property Carbon $requested_at
 * @property Carbon $purge_after
 * @property Carbon|null $restored_at
 * @property Carbon|null $purged_at
 * @property int $previous_status
 * @property array<string, mixed>|null $purge_report
 * @property list<int>|null $deactivated_staff_ids
 */
class AccountDeletion extends Model
{
    public const PENDING = 'pending';

    public const RESTORED = 'restored';

    public const PURGED = 'purged';

    /** P6-247: purge failed {@see \Juwri\Core\Console\Commands\PurgeDeletedAccountsCommand::MAX_ATTEMPTS} times; skipped by the daily run until an operator resets it. */
    public const FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'purge_after' => 'datetime',
        'restored_at' => 'datetime',
        'purged_at' => 'datetime',
        'previous_status' => 'integer',
        'purge_report' => 'array',
        'deactivated_staff_ids' => 'array',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function pendingFor(int $userId): ?self
    {
        return static::where('user_id', $userId)->where('status', self::PENDING)->latest('id')->first();
    }
}
