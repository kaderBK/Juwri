<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class StudioSession extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Public token for share links + referral codes. Distinct from guest_token
     * (a session credential — the resume link restores a session with it) so
     * publishing a share link can never hand over the session itself.
     */
    public function ensureShareToken(): string
    {
        if (empty($this->share_token)) {
            $this->update(['share_token' => \Illuminate\Support\Str::random(48)]);
        }

        return $this->share_token;
    }

    /** Guest-scoped, unguessable storage path for this session's uploads. */
    public function storagePath(string $suffix = ''): string
    {
        return 'studio/'.$this->guest_token.($suffix !== '' ? '/'.ltrim($suffix, '/') : '');
    }

    /** Delete this session's uploaded files (guest privacy). */
    public function purgeFiles(): void
    {
        Storage::disk('local')->deleteDirectory('studio/'.$this->guest_token);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
