<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * A requested per-restaurant data export. Lifecycle:
 * pending → processing → ready | failed. See the migration for the "why".
 */
class StoreBackup extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'store_id', 'user_id', 'token', 'status', 'progress', 'current_step',
        'include_pii', 'datasets', 'format', 'orders_from', 'orders_to',
        'branch_id', 'encrypted', 'password_enc', 'notify_email', 'schedule_id',
        'path', 'size_bytes', 'error', 'expires_at',
    ];

    protected $casts = [
        'include_pii' => 'boolean',
        'encrypted'   => 'boolean',
        'datasets'    => 'array',
        'size_bytes'  => 'integer',
        'progress'    => 'integer',
        'orders_from' => 'date',
        'orders_to'   => 'date',
        'expires_at'  => 'datetime',
    ];

    /** Hide the transient encrypted password from any array/json rendering. */
    protected $hidden = ['password_enc'];

    public const STATUSES = ['pending', 'processing', 'ready', 'failed'];

    /** Max archives kept per store; older ones are pruned on each new create. */
    public const MAX_PER_STORE = 5;

    public static function newToken(): string
    {
        return Str::lower(Str::random(40));
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->path && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Human filename offered to the browser on download. */
    public function downloadName(): string
    {
        return 'backup-store-' . $this->store_id . '-' . $this->created_at->format('Y-m-d') . '.zip';
    }

    /**
     * WI-B4: keep only the most recent MAX_PER_STORE archives for this owner's
     * store; delete older rows and their files. Called on every create path
     * (manual + scheduled) so per-store disk use stays bounded.
     */
    public static function pruneForStore(int $storeId, int $userId): void
    {
        $stale = static::where('store_id', $storeId)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->skip(self::MAX_PER_STORE)
            ->take(50)
            ->get();

        foreach ($stale as $old) {
            if ($old->path) {
                @unlink(storage_path('app/' . $old->path));
            }
            $old->delete();
        }
    }
}
