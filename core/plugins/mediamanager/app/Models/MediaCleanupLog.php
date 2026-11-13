<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Plugins\mediamanager\app\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaCleanupLog extends Model
{
    use HasFactory;

    protected $table = 'plugin_mediamanager_logs';

    protected $fillable = [
        'user_id',
        'action',
        'file_path',
        'file_size',
        'details',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'file_size' => 'integer',
    ];

    /**
     * User who performed the action.
     */
    public function user()
    {
        return $this->belongsTo(\Juwri\Core\Models\User::class, 'user_id');
    }

    /**
     * Helper to log a deletion action.
     */
    public static function logDelete($userId, $filePath, $fileSize, $details = null)
    {
        return self::create([
            'user_id' => $userId,
            'action' => 'delete',
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'details' => $details,
        ]);
    }

    /**
     * Helper to log a scan action.
     */
    public static function logScan($userId, $details = null)
    {
        return self::create([
            'user_id' => $userId,
            'action' => 'scan',
            'file_path' => '',
            'file_size' => 0,
            'details' => $details,
        ]);
    }
}
