<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** SYSTEMS-RUN T-P1: a chat message. */
class TeamMessage extends Model
{
    use BelongsToAccount;

    protected $guarded = [];
    protected $casts = ['from_owner' => 'boolean'];

    public function channel()
    {
        return $this->belongsTo(TeamChannel::class, 'channel_id');
    }

    public function reads()
    {
        return $this->hasMany(TeamMessageRead::class, 'message_id');
    }
}
