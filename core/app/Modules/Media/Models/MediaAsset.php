<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

class MediaAsset extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $guarded = [];

    protected $appends = ['url'];

    protected $casts = [
        'attribution' => 'array',
    ];

    public static function boot()
    {
        parent::boot();

        /* On `deleted` so this row is already out of the reference count.
           remove_file() now checks every table that can name the file — menu
           items and their galleries included, not just sibling assets. */
        self::deleted(function ($asset) {
            if (! empty($asset->filename) && $asset->filename !== 'default.png') {
                remove_file('storage/menu/', $asset->filename, $asset->store_id);
            }
        });
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function getUrlAttribute(): string
    {
        if (empty($this->filename)) {
            return '';
        }

        return media_url('storage/menu/', $this->filename, $this->store_id);
    }
}
