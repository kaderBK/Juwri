<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

class SlugRedirect extends Model
{
    use BelongsToAccount;

    protected $fillable = ['old_slug', 'store_id'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
