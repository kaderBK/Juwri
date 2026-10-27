<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * Customizer v2: one immutable snapshot of a store's published theme settings.
 * Revert never edits history — it appends another version (WI-V1).
 */
class ThemeSettingVersion extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'layout' => 'array',
        'published_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
