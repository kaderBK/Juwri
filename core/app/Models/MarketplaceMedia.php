<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * stores P1-3: a screenshot/banner attached to a Theme or App listing. The file
 * itself lives on the public disk at `disk_path` (generated name); this row is
 * the ordered index. Polymorphic — mediable = Theme|App.
 */
class MarketplaceMedia extends Model
{
    protected $table = 'marketplace_media';

    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
    ];

    public function mediable()
    {
        return $this->morphTo();
    }

    /** Public URL for the stored file (S3/CDN-aware via media_url()). */
    public function url(): string
    {
        $dir = rtrim(dirname($this->disk_path), '/') . '/';

        return media_url($dir, basename($this->disk_path));
    }
}
