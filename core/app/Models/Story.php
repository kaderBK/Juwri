<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * theme-pack-blade · F3 Story / reel promo.
 * Media (`media_url`) is a library filename (storage/menu/), same convention as
 * MenuBanner->image; the resolved public URL is exposed as `media_full_url`.
 */
class Story extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'sort' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $appends = ['media_full_url'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Active, currently within its (optional) start/expiry window, sorted for display.
     */
    public function scopeVisible(Builder $query): Builder
    {
        $now = now();

        return $query->where('active', true)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            })
            ->orderBy('sort')
            ->orderBy('id');
    }

    public function getMediaFullUrlAttribute(): string
    {
        // Story media is library-managed (folder=story/banner); never unlinked on row delete.
        return ! empty($this->media_url)
            ? media_url('storage/menu/', $this->media_url, $this->store_id)
            : '';
    }
}
