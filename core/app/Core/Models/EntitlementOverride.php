<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P1 (feature-platform): a per-user entitlement grant outside the plan chain —
 * admin grants, promos, app-granted features, boolean add-ons. A resolver
 * source in Entitlements (most-generous-wins; the kill-switch still vetoes).
 */
class EntitlementOverride extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = ['expires_at' => 'datetime'];

    /**
     * Invalidate the per-request entitlement memos on any override write, so a
     * grant/revoke/expiry edit is seen by a same-request has() re-check.
     * (same pattern as the Billing add-on model).
     */
    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\Entitlements::flushBundleMemo());
        static::deleted(fn () => \App\Services\Entitlements::flushBundleMemo());
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
