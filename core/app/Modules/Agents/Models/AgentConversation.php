<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class AgentConversation extends Model
{
    use BelongsToAccount;

    protected $guarded = [];
    protected $casts = ['meta' => 'array', 'last_activity_at' => 'datetime'];

    public function messages()
    {
        return $this->hasMany(AgentMessage::class, 'conversation_id');
    }
}
