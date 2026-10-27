<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Superadmin-curated shared media pool. Not tenant-scoped: any owner may browse
 * the active rows and COPY an image into their own MediaAsset (StorageService
 * quota enforced). Files live under storage/public-media/.
 */
class PublicMediaAsset extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'tags' => 'array',
        'active' => 'boolean',
    ];

    protected $appends = ['url'];

    public const STORAGE_PATH = 'storage/public-media/';

    public const CATEGORIES = ['food', 'drink', 'dessert', 'banner', 'interior', 'other'];

    public static function boot()
    {
        parent::boot();

        self::deleting(function ($asset) {
            if (! empty($asset->filename)) {
                $stillUsed = static::where('filename', $asset->filename)
                    ->where('id', '!=', $asset->id)
                    ->exists();
                if (! $stillUsed) {
                    // Platform-level asset (storeId null) — never on a tenant S3.
                    remove_file(self::STORAGE_PATH, $asset->filename, null);
                }
            }
        });
    }

    public function getUrlAttribute(): string
    {
        if (empty($this->filename)) {
            return '';
        }

        return media_url(self::STORAGE_PATH, $this->filename, null);
    }
}
