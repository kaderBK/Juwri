<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** SYSTEMS-RUN T-P1: channel membership. */
class TeamChannelMember extends Model
{
    use BelongsToAccount;

    protected $guarded = [];
    protected $casts = ['is_owner' => 'boolean'];
}
