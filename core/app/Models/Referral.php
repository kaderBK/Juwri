<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;

class Referral extends Model
{
    protected $guarded = [];

    protected $casts = [
        'clicks' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function conversions()
    {
        return $this->hasMany(ReferralConversion::class);
    }

    public function pendingConversions()
    {
        return $this->hasMany(ReferralConversion::class)->where('status', 'pending');
    }

    public function approvedConversions()
    {
        return $this->hasMany(ReferralConversion::class)->whereIn('status', ['approved', 'paid']);
    }

    public function totalEarnings(): float
    {
        return (float) $this->approvedConversions()->sum('reward_amount');
    }

    // Auto-generate a unique 8-char alphanumeric code
    public static function generateCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('code', $code)->exists());
        return $code;
    }

    // Get or create the referral record for a user
    public static function forUser(User $user): self
    {
        return static::firstOrCreate(
            ['user_id' => $user->id],
            ['code' => static::generateCode()]
        );
    }

    public function getReferralLinkAttribute(): string
    {
        // Registration lives at /signup (route name "register"); ?ref= is read by RegisterController.
        return route('register', ['ref' => $this->code]);
    }
}
