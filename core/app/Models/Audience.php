<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/** SYSTEMS-RUN C-P2: a saved audience (named rule set) for campaign targeting. */
class Audience extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = ['rules' => 'array'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
