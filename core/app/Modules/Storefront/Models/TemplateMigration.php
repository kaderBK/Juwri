<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\User;

/**
 * One store→theme migration batch. `moves` holds each affected store's pre-move
 * template so undo() can restore them exactly. `undone_at` guards against a
 * double revert.
 */
class TemplateMigration extends Model
{
    protected $guarded = [];

    protected $casts = [
        'moves' => 'array',
        'undone_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
    }
}
