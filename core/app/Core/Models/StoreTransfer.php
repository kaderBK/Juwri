<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * A pending or completed ownership transfer (WI-G7). `kind` is 'store' (move a
 * Post to another user) or 'branch' (move a Branch to another store). Lifecycle:
 * pending → accepted | declined | cancelled | expired (or forced by superadmin).
 */
class StoreTransfer extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'kind', 'token', 'from_user_id', 'to_email', 'to_user_id',
        'store_id', 'branch_id', 'target_store_id',
        'status', 'manifest', 'by_superadmin', 'responded_at', 'expires_at',
    ];

    protected $casts = [
        'manifest' => 'array',
        'by_superadmin' => 'boolean',
        'responded_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public const DEFAULT_TTL_DAYS = 7;

    public static function newToken(): string
    {
        return Str::lower(Str::random(40));
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function fromUser()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }
}
