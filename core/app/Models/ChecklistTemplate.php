<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** SYSTEMS-RUN T-P2: an opening/closing/daily checklist template. */
class ChecklistTemplate extends Model
{
    use BelongsToAccount;

    protected $guarded = [];
    protected $casts = ['items' => 'array', 'active' => 'boolean'];

    public function runs()
    {
        return $this->hasMany(ChecklistRun::class, 'checklist_template_id');
    }
}
