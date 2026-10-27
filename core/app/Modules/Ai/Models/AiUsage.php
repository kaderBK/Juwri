<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiUsage extends Model
{
    use HasFactory;

    protected $table = 'ai_usage';

    protected $guarded = [];

    protected $casts = [
        'prompt_tokens'     => 'integer',
        'completion_tokens' => 'integer',
        'audio_seconds'     => 'integer',
        'fell_back'         => 'boolean',
    ];

    public function getTotalTokensAttribute(): int
    {
        return $this->prompt_tokens + $this->completion_tokens;
    }
}
