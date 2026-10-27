<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/** P6-09 V-14: a caller ID the store has blocked from AI phone ordering (number stored as digits only). */
class VoiceCallerBlock extends Model
{
    use BelongsToAccount;

    protected $guarded = [];
}
