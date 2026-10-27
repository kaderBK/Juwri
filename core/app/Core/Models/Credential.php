<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class Credential extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'secrets' => 'encrypted:array',
        'active' => 'boolean',
    ];

    // H11: the ONE read-path memo of this model lives in StorageService::$urlCredMemo (url() only) and is dropped
    // on any model-event-firing write — the Media module registers those saved/deleted listeners
    // (MediaServiceProvider; P1-08b: Core may not import Media). Do NOT add a memo to for() itself — see the
    // note on that method: some features are cleared with query-builder mass deletes that never reach these hooks.

    /**
     * Get the restaurant (post) relation
     */
    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * Resolve effective credentials for a feature
     *
     * NOTE: deliberately NOT memoized. Credentials are written through several
     * paths — including mass updates that bypass model events (e.g. the Firebase
     * clear) — so a per-request cache would serve a stale/cleared key within the
     * same request. The WI-G5 menu-editor N+1 is addressed by memoizing the
     * repeated store_options() read instead (see StoreOption), which is only ever
     * written via the model.
     *
     * H11: the single exception is StorageService::url(), the read-only image-URL
     * path, which memoises the ('storage', $storeId) answer per request
     * (StorageService::$urlCredMemo) because a storefront render calls it 100+
     * times. That memo is invalidated by booted() above and never covers a write
     * path. Do not widen it here.
     */
    public static function for(string $feature, ?int $storeId = null): ?array
    {
        // 1. Try to find active merchant override
        if ($storeId) {
            $merchantCredential = self::where('store_id', $storeId)
                ->where('feature', $feature)
                ->where('active', true)
                ->first();

            if ($merchantCredential && ! empty($merchantCredential->secrets)) {
                return $merchantCredential->secrets;
            }
        }

        // 2. Fallback to active platform default
        $platformCredential = self::whereNull('store_id')
            ->where('feature', $feature)
            ->where('active', true)
            ->first();

        if ($platformCredential && ! empty($platformCredential->secrets)) {
            return $platformCredential->secrets;
        }

        return null;
    }

    /**
     * Resolve a RESELLER-scoped credential row for a feature (e.g. BYOK payment
     * gateway keys, feature='reseller_gateway'). Reseller rows carry reseller_id and
     * a null store_id, so they never collide with per-restaurant credentials.
     * Returns the model (caller needs provider + secrets), or null.
     */
    public static function forReseller(string $feature, int $resellerId): ?self
    {
        return self::where('reseller_id', $resellerId)
            ->where('feature', $feature)
            ->where('active', true)
            ->first();
    }
}
