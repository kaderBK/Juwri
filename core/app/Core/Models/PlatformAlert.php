<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * O4 — a persisted platform alert row (see AlertDispatcher).
 */
class PlatformAlert extends Model
{
    protected $table = 'platform_alerts';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'day' => 'date',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
