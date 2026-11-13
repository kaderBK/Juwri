<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * C2: durable evidence of what the platform backup machinery actually did.
 * `kind` is one of: backup | restore_check | media (media is WI-M7).
 */
class PlatformBackupRun extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'ok' => 'boolean',
        'mirrored' => 'boolean',
        'size_bytes' => 'integer',
        'file_count' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * P6-97: is the weekly restore drill on? The `platform_backup_restore_check_enabled` setting wins when the
     * operator set it ('1' / '0'); never set → ON in production, OFF elsewhere.
     */
    public static function restoreCheckEnabled(): bool
    {
        $v = config('settings.platform_backup_restore_check_enabled');
        if ($v === null || $v === '') {
            return config('app.env') === 'production';
        }

        return in_array($v, ['1', 1, true, 'true', 'on'], true);
    }

    /** Newest run of a kind, or null. */
    public static function latestOf(string $kind): ?self
    {
        return static::where('kind', $kind)->orderByDesc('id')->first();
    }
}
