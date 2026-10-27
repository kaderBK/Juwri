<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

class NpsResponse extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
