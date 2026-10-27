<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\TemplateRolloutStore;

/**
 * One A/B rollout experiment: a target template applied to a random slice
 * (variant arm) of a store segment, the rest left as-is (control), with orders
 * per arm compared over the window since started_at.
 */
class TemplateRollout extends Model
{
    protected $guarded = [];

    protected $casts = [
        'segment' => 'array',
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    /** @return HasMany<TemplateRolloutStore, $this> */
    public function stores(): HasMany
    {
        return $this->hasMany(TemplateRolloutStore::class, 'rollout_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }
}
