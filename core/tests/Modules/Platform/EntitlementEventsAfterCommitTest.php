<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Juwri\Core\Events\FeatureGranted;
use Juwri\Core\Events\FeatureRevoked;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Tests\TestCase;

/**
 * P6-61: FeatureGranted / FeatureRevoked fire only after the writer's transaction commits. Before, the listener
 * busted the serve-time cache inside the transaction, so a concurrent request could re-cache the OLD decision
 * before the commit and serve it for the whole TTL.
 */
class EntitlementEventsAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'crm_suite';

    protected function setUp(): void
    {
        parent::setUp();
        // A plan-gated key with the platform kill-switch ON, so the answer depends on the override alone.
        Feature::where('key', self::KEY)->update(['plan_gated' => true]);
        config(['settings.admin_allow_'.self::KEY => 1]);
        FeatureRegistry::clearCache();
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();
    }

    private function owner(): array
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'group_id' => 'free', 'user_type' => 'user']);
        $store = Store::factory()->create(['user_id' => $user->id]);

        return [$user, $store];
    }

    public function test_rollback_fires_no_event(): void
    {
        [$user] = $this->owner();
        $seen = [];
        Event::listen(FeatureGranted::class, function () use (&$seen) {
            $seen[] = 'granted';
        });
        Event::listen(FeatureRevoked::class, function () use (&$seen) {
            $seen[] = 'revoked';
        });

        try {
            DB::transaction(function () use ($user) {
                EntitlementWriter::grant($user, self::KEY, 'admin', null, '1', null, 'test');
                throw new \RuntimeException('billing failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $seen);
        $this->assertFalse(FeatureAccess::allows($user->fresh(), self::KEY));
    }

    public function test_event_waits_for_outer_commit_and_read_after_grant_sees_new_state(): void
    {
        [$user, $store] = $this->owner();
        $seen = [];
        Event::listen(FeatureGranted::class, function () use (&$seen) {
            $seen[] = 'granted';
        });

        $this->assertFalse(FeatureAccess::storeAllowsCached($store->id, self::KEY)); // cache the "no" decision

        DB::transaction(function () use ($user, &$seen) {
            EntitlementWriter::grant($user, self::KEY, 'admin', null, '1', null, 'test');
            $this->assertSame([], $seen, 'event fired before the outer transaction committed');
        });

        $this->assertSame(['granted'], $seen);
        FeatureAccess::flush();
        $this->assertTrue(FeatureAccess::storeAllowsCached($store->id, self::KEY), 'read after grant served the stale decision');
    }

    /** A concurrent reader re-caches the old decision between the write and the commit: the bust must come after it. */
    public function test_cache_is_not_re_poisoned_by_a_read_before_commit(): void
    {
        [$user, $store] = $this->owner();
        $cacheKey = 'feature_access:serve:'.$store->id.':'.self::KEY;

        DB::transaction(function () use ($user, $cacheKey) {
            EntitlementWriter::grant($user, self::KEY, 'admin', null, '1', null, 'test');
            // The other request still sees the committed (old) state and caches "no".
            Cache::put($cacheKey, false, FeatureAccess::SERVE_TTL);
        });

        FeatureAccess::flush();
        $this->assertFalse(Cache::has($cacheKey), 'stale decision survived the commit');
        $this->assertTrue(FeatureAccess::storeAllowsCached($store->id, self::KEY));

        DB::transaction(function () use ($user, $cacheKey) {
            EntitlementWriter::revoke($user, self::KEY, 'admin', null, 'test');
            Cache::put($cacheKey, true, FeatureAccess::SERVE_TTL);
        });

        FeatureAccess::flush();
        $this->assertFalse(Cache::has($cacheKey), 'stale grant survived the revoke commit');
        $this->assertFalse(FeatureAccess::storeAllowsCached($store->id, self::KEY));
    }
}
