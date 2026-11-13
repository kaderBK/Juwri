<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Controllers\User\StoreController;
use App\Models\Order;
use App\Services\Entitlements;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * F2-3 (feature-platform gap fix): `curbside` reads its entitlement.
 *
 * The audit found the arrival-ping route carrying `feature:curbside`, i.e. the
 * KILL SWITCH ONLY — the $0.99/mo SKU bought nothing.
 *
 * The fix could NOT be "leave the middleware and flip plan_gated": that
 * middleware resolves its subject from the session, and every caller of this
 * route is an anonymous diner tapping "I'm here" on the order-status page. The
 * flag flip alone would have 403'd every arrival ping on the platform. So the
 * gate moved into the controller, store-scoped on the ORDER's restaurant owner.
 *
 * Two entry points, both covered: minting a curbside order (submitOrder) and
 * pinging arrival on one.
 *
 * Contract frozen below:
 *   unentitled     → 403 on the ping, and no curbside order can be minted
 *   entitled       → 200
 *   grandfathered  → 200
 *   killed         → 403 even for an entitled owner (kill veto wins)
 *   key not gated  → kill-only, exactly as before the fix
 */
class FeaturePlatformFix2CurbsideTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'curbside';

    protected function setUp(): void
    {
        parent::setUp();
        // Keep NO middleware: the gate under test is in the controller, and the
        // storefront stack (install/csrf/locale) is noise here.
        $this->withoutMiddleware();

        // FeatureAccess memoizes store->allowed in a static that outlives a single
        // test method; without this a store id reused after RefreshDatabase would
        // inherit the previous test's verdict.
        FeatureAccess::flush();

        $this->killSwitch(true);
        $this->planGate(true);
    }

    // ------------------------------------------------------------- fixtures

    private function killSwitch(bool $on): void
    {
        config(['settings.admin_allow_'.self::KEY => $on ? 1 : 0]);
        FeatureRegistry::clearCache();
    }

    /** Self-sufficient: does not depend on the sibling gate-flip seeder. */
    private function planGate(bool $on): void
    {
        Feature::updateOrCreate(['key' => self::KEY], ['label' => 'Curbside Pickup', 'plan_gated' => $on]);
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();
    }

    /** @return array{0:Store,1:User,2:Order} store with curbside on, its owner, a curbside order. */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(6), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreSettings::set($store->id, 'curbside_enabled', '1');
        $order = Order::create([
            'store_id' => $store->id,
            'status' => 'preparing',
            'type' => 'takeaway',
            'customer_name' => 'Sam',
            'is_curbside' => 1,
            'track_token' => Str::random(32),
            'created_at' => now(),
        ]);

        return [$store, $owner, $order];
    }

    private function entitle(User $owner, string $sourceType = 'admin', string $reason = 'test-fixture'): void
    {
        EntitlementWriter::grant($owner, self::KEY, $sourceType, null, '1', null, $reason);
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();
    }

    private function ping(Order $order)
    {
        return $this->postJson(route('store.curbsideArrived', $order->track_token));
    }

    // ----------------------------------------------------------- unentitled

    public function test_an_unentitled_store_cannot_receive_an_arrival_ping(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store, $owner, $order] = $this->store();

        $this->assertFalse(
            Entitlements::allows($owner->fresh(), self::KEY),
            'the fixture owner must start unentitled'
        );

        $this->ping($order)->assertStatus(403);
        $this->assertNull($order->fresh()->curbside_arrived_at, 'a refused ping must not stamp arrival');
    }

    public function test_an_unentitled_store_cannot_mint_a_curbside_order(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store] = $this->store();

        $this->assertFalse(
            StoreController::curbsideAllowed((int) $store->id),
            'submitOrder must not be able to flag a new order curbside for an unentitled store'
        );
    }

    // ------------------------------------------------------------- entitled

    public function test_an_entitled_store_receives_the_arrival_ping(): void
    {
        [$store, $owner, $order] = $this->store();
        $this->entitle($owner);

        $this->ping($order)->assertOk()->assertJson(['success' => true]);
        $this->assertNotNull($order->fresh()->curbside_arrived_at);
        $this->assertTrue(StoreController::curbsideAllowed((int) $store->id));
    }

    // -------------------------------------------------------- grandfathered

    public function test_a_grandfathered_store_keeps_curbside(): void
    {
        [$store, $owner, $order] = $this->store();
        // Exactly what `entitlements:grandfather --apply` writes: non-expiring.
        $this->entitle($owner, 'grandfather', 'grandfather-2026-07');

        $this->ping($order)->assertOk()->assertJson(['success' => true]);
        $this->assertNotNull($order->fresh()->curbside_arrived_at);
    }

    // --------------------------------------------------------------- killed

    public function test_the_kill_switch_wins_over_entitlement(): void
    {
        [$store, $owner, $order] = $this->store();
        $this->entitle($owner);
        $this->killSwitch(false);

        $this->ping($order)->assertStatus(403);
        $this->assertNull($order->fresh()->curbside_arrived_at);
        $this->assertFalse(StoreController::curbsideAllowed((int) $store->id));
    }

    // -------------------------------------------------------------- ungated

    public function test_an_ungated_key_keeps_kill_only_behavior(): void
    {
        $this->planGate(false);
        [$store, $owner, $order] = $this->store();

        $this->ping($order)->assertOk()->assertJson(['success' => true]);
        $this->assertTrue(StoreController::curbsideAllowed((int) $store->id));
    }
}
