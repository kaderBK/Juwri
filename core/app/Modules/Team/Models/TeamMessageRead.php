<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** SYSTEMS-RUN T-P1: a message read receipt / acknowledgement. */
class TeamMessageRead extends Model
{
    use BelongsToAccount;

    protected $guarded = [];
    protected $casts = ['by_owner' => 'boolean', 'acknowledged' => 'boolean', 'read_at' => 'datetime'];
}
