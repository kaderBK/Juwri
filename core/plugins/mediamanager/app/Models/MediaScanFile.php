<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Plugins\mediamanager\app\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaScanFile extends Model
{
    use HasFactory;

    protected $table = 'plugin_mediamanager_files';

    protected $fillable = [
        'directory',
        'filename',
        'full_path',
        'size',
        'modified_at',
        'is_referenced',
        'reference_table',
        'reference_column',
        'reference_id',
        'reference_name',
        'reference_owner',
        'is_duplicate',
        'duplicate_group_id',
        'is_protected',
        'status',
        'scanned_at',
    ];

    protected $casts = [
        'is_referenced' => 'boolean',
        'is_duplicate' => 'boolean',
        'is_protected' => 'boolean',
        'size' => 'integer',
        'reference_id' => 'integer',
        'modified_at' => 'datetime',
        'scanned_at' => 'datetime',
    ];

    /**
     * Scopes
     */
    public function scopeOrphans($query) { return $query->where('status', 'orphaned'); }
    public function scopeUsed($query) { return $query->where('status', 'used'); }
    public function scopeUnknown($query) { return $query->where('status', 'unknown'); }
    public function scopeProtected($query) { return $query->where('status', 'protected'); }
    public function scopeDuplicates($query) { return $query->where('is_duplicate', true); }

    /**
     * URL to view the file.
     */
    public function getUrlAttribute(): string
    {
        return asset($this->full_path);
    }

    /**
     * Absolute filesystem path.
     */
    public function getAbsolutePathAttribute(): string
    {
        return base_path('../' . $this->full_path);
    }

    /**
     * Trash path.
     */
    public function getTrashPathAttribute(): string
    {
        $relativePath = $this->full_path;
        if (str_starts_with($relativePath, 'storage/')) {
            $relativePath = substr($relativePath, 8);
        }
        return base_path('../storage/.mediamanager-trash/' . $relativePath);
    }

    /**
     * Readable "used by" summary.
     */
    public function getUsedBySummaryAttribute(): ?string
    {
        $parts = [];
        if (!empty($this->reference_owner)) {
            $parts[] = $this->reference_owner;
        }
        if (!empty($this->reference_name)) {
            $parts[] = $this->reference_name;
        }
        return !empty($parts) ? implode(' › ', $parts) : null;
    }

    /**
     * Status badge color (Bootstrap class).
     */
    public function getStatusBadgeClassAttribute(): string
    {
        return match($this->status) {
            'used' => 'success',
            'orphaned' => 'danger',
            'unknown' => 'warning',
            'protected' => 'primary',
            default => 'secondary',
        };
    }
}
