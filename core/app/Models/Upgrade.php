<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\User;

class Upgrade extends Model
{
    use BelongsToAccount;
    use HasFactory;

    public const STATUS_ACTIVE = 'Active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_SUSPENDED = 'suspended';

    public $timestamps = false;

    public $primaryKey = 'upgrade_id';

    protected $casts = [
        'grace_until' => 'datetime',
        'last_retry_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    public function isPastDue(): bool
    {
        return $this->status === self::STATUS_PAST_DUE;
    }

    /** @var list<string> */
    protected $fillable = [
        'sub_id',
        'user_id',
        'pay_mode',
        'interval',
        'upgrade_lasttime',
        'upgrade_expires',
        'unique_id',
        'status',
        'grace_until',
        'retry_count',
        'last_retry_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
