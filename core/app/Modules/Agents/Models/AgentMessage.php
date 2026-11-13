<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Models;

use Illuminate\Database\Eloquent\Model;

class AgentMessage extends Model
{
    protected $guarded = [];
    protected $casts = ['tool_calls' => 'array'];

    public function conversation()
    {
        return $this->belongsTo(AgentConversation::class, 'conversation_id');
    }
}
