<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** SYSTEMS-RUN T-P1: a team chat channel. */
class TeamChannel extends Model
{
    use BelongsToAccount;

    protected $guarded = [];
    protected $casts = ['require_ack' => 'boolean'];

    public function members()
    {
        return $this->hasMany(TeamChannelMember::class, 'channel_id');
    }

    public function messages()
    {
        return $this->hasMany(TeamMessage::class, 'channel_id');
    }

    public function hasStaff(int $staffId): bool
    {
        return $this->members()->where('staff_id', $staffId)->exists();
    }
}
