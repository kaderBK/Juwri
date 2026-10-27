<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

class ThemeSetting extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = ['settings' => 'array', 'draft' => 'array', 'layout' => 'array', 'layout_draft' => 'array'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
