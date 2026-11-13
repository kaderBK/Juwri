<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Models;

use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class StaffToken extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $hidden = ['token'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function staff()
    {
        return $this->belongsTo(StaffMember::class, 'staff_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /** Issue a token for a staff member; returns the raw value (shown once). */
    public static function issue(StaffMember $staff, ?string $name = null, ?int $expiresDays = null): string
    {
        $raw = Str::random(48);

        static::create([
            'staff_id' => $staff->id,
            'name' => $name,
            'token' => hash('sha256', $raw),
            'expires_at' => $expiresDays ? now()->addDays($expiresDays) : null,
        ]);

        return $raw;
    }
}
