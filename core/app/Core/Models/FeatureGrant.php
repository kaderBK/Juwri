<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * When a user GAINED a feature key, and from where. The entitlement tables know
 * what a user has but not since when, so this is what "new for 14 days" asks.
 * One live row per user+key: re-granting refreshes granted_at, revoking deletes.
 */
class FeatureGrant extends Model
{
    use BelongsToAccount;

    protected $fillable = ['user_id', 'feature_key', 'granted_at', 'source', 'last_used_at'];

    protected $casts = ['granted_at' => 'datetime', 'last_used_at' => 'datetime'];

    public const SOURCES = ['plan', 'addon', 'app', 'admin'];

    /** How long a freshly granted key wears the NEW badge (B2). */
    public const NEW_FOR_DAYS = 14;

    /**
     * A grant/revoke write invalidates FeatureSpotlight's per-request grant memo,
     * so a same-request for() re-check (e.g. a test that grants then asserts the
     * "new" state) is not answered from a stale set. (same pattern as the Billing add-on model).
     */
    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\FeatureSpotlight::flush());
        static::deleted(fn () => \App\Services\FeatureSpotlight::flush());
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Was this key granted recently enough to still count as new? */
    public function isNew(): bool
    {
        $grantedAt = $this->getAttribute('granted_at'); // null on an unsaved grant

        return $grantedAt !== null && $grantedAt->gt(now()->subDays(self::NEW_FOR_DAYS));
    }
}
