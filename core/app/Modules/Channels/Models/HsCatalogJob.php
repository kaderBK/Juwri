<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class HsCatalogJob extends Model
{
    use BelongsToAccount;

    protected $table = 'hungerstation_catalog_jobs';
    protected $guarded = [];
    protected $casts = [
        'payload'      => 'array',
        'result'       => 'array',
        'completed_at' => 'datetime',
    ];
}
