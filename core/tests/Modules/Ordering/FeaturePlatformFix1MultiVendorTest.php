<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\Entitlements;
use App\Services\FeatureRegistry;
use App\Services\MultiVendor\CrossCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Menu\Models\Menu;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * F1-5 (feature-platform gap fix): `multivendor_cart` reads its plan_gated lane.
 *
 * The audit found the flag dead. `multivendor_cart` is plan_gated (Premium, or
 * the $3.99 add-on/app), but OrderHubController and CrossCartService both
 * guarded with `feature_enabled()` — the KILL SWITCH ONLY — and no
 * `feature:multivendor_cart` middleware existed anywhere. Any store could be
 * dropped into a cross-store bag whether or not its owner had paid.
 *
 * WHOSE entitlement: /order-hub and /fusion are public shopper surfaces with no
 * session user, so the subject is the STORE OWNER — what the catalog sells is a
 * store's right to participate in a multi-vendor bag. See the guard() docblock
 * in CrossCartService for the dead end that rules out route middleware here.
 *
 * Contract frozen below:
 *   unentitled store → 403 (and it cannot reach checkout)
 *   entitled store   → works
 *   killed feature   → blocked even for an entitled store (kill veto wins)
 *   key not gated    → kill-only, exactly as before the fix
 */
class FeaturePlatformFix1MultiVendorTest extends TestCase
{
    use RefreshDatabase;

    private CrossCartService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        $this->killSwitch(true);
        $this->planGate(true);
        $this->svc = new CrossCartService;
    }

    // ------------------------------------------------------------- fixtures

    private function killSwitch(bool $on): void
    {
        config(['settings.admin_allow_multivendor_cart' => $on ? 1 : 0]);
        FeatureRegistry::clearCache();
    }

    private function planGate(bool $on): void
    {
        Feature::where('key', 'multivendor_cart')->update(['plan_gated' => $on]);
        FeatureRegistry::clearCache(); // mass update fires no model event
        app()->forgetInstance('features.plan_gated');
    }

    /** A fresh service — the entitlement memo is per instance (per request). */
    private function freshService(): CrossCartService
    {
        app()->forgetInstance('features.plan_gated');

        return $this->svc = new CrossCartService;
    }

    /** @return array{0:Store,1:Menu,2:User} store, one item, its owner. */
    private function store(bool $entitled = false): array
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
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        if ($entitled) {
            EntitlementWriter::grant($owner, 'multivendor_cart', 'admin', null, '1', null, 'test-fixture');
        }

        return [$store, $menu, $owner];
    }

    // ----------------------------------------------------------- unentitled

    public function test_an_unentitled_store_cannot_be_added_to_the_bag(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store, $menu, $owner] = $this->store();

        $this->assertFalse(
            Entitlements::allows($owner->fresh(), 'multivendor_cart'),
            'the fixture owner must start unentitled'
        );

        try {
            $this->svc->addItem($store->id, $menu->id, 1);
            $this->fail('addItem should refuse a store whose owner has not bought multivendor_cart');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame([], $this->freshService()->cart(), 'nothing may be persisted for an unentitled store');
    }

    public function test_the_order_hub_add_endpoint_refuses_an_unentitled_store(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store, $menu] = $this->store();

        $this->postJson('/order-hub/add', [
            'store_id' => $store->id, 'item_id' => $menu->id, 'quantity' => 1,
        ])->assertStatus(403);
    }

    public function test_stepper_and_fulfillment_writes_refuse_an_unentitled_store(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store, $menu] = $this->store();

        foreach ([
            'setItem' => fn () => $this->svc->setItem($store->id, $menu->id, 2),
            'setFulfillment' => fn () => $this->svc->setFulfillment($store->id, 'pickup'),
            'setTip' => fn () => $this->svc->setTip($store->id, 1.0),
            'setCoupon' => fn () => $this->svc->setCoupon($store->id, 'SAVE10'),
        ] as $name => $call) {
            try {
                $call();
                $this->fail($name.' should refuse an unentitled store');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode(), $name.' should 403');
            }
        }
    }

    public function test_an_unentitled_store_in_a_mixed_bag_fails_alone_at_checkout(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        // Bag built while the gate is open, then the paid flag lands: checkout
        // is per-store fault tolerant, so the paying store still gets its order.
        $this->planGate(false);
        $this->freshService();
        [$paid, $paidItem] = $this->store(entitled: true);
        [$free, $freeItem] = $this->store();
        $this->svc->addItem($paid->id, $paidItem->id, 1);
        $this->svc->addItem($free->id, $freeItem->id, 1);

        $this->planGate(true);
        $results = $this->freshService()->checkout(['name' => 'Sam', 'phone' => '123']);

        $byStore = collect($results)->keyBy('store_id');
        $this->assertTrue($byStore[$paid->id]['ok'], 'the entitled store should still check out');
        $this->assertFalse($byStore[$free->id]['ok'], 'the unentitled store must not produce an order');
        $this->assertNull($byStore[$free->id]['order_id']);
    }

    // ------------------------------------------------------------- entitled

    public function test_an_entitled_store_works_end_to_end(): void
    {
        [$store, $menu] = $this->store(entitled: true);

        $this->svc->addItem($store->id, $menu->id, 2);
        $this->assertSame(2, $this->svc->cart()[$store->id][0]['quantity']);

        $results = $this->freshService()->checkout(['name' => 'Sam', 'phone' => '123']);
        $this->assertTrue($results[0]['ok'], 'an entitled store checks out');
        $this->assertNotNull($results[0]['order_id']);
    }

    public function test_the_order_hub_add_endpoint_accepts_an_entitled_store(): void
    {
        [$store, $menu] = $this->store(entitled: true);

        $res = $this->postJson('/order-hub/add', [
            'store_id' => $store->id, 'item_id' => $menu->id, 'quantity' => 1,
        ]);

        $this->assertTrue($res->getStatusCode() < 300, 'entitled store should be addable, got '.$res->getStatusCode());
    }

    // --------------------------------------------------------------- killed

    public function test_kill_switch_wins_over_entitlement(): void
    {
        [$store, $menu] = $this->store(entitled: true);

        $this->killSwitch(false);
        $this->freshService();

        $this->expectException(ValidationException::class);
        $this->svc->addItem($store->id, $menu->id, 1);
    }

    public function test_kill_switch_wins_over_entitlement_on_the_endpoint(): void
    {
        [$store, $menu] = $this->store(entitled: true);
        $this->killSwitch(false);

        $this->postJson('/order-hub/add', [
            'store_id' => $store->id, 'item_id' => $menu->id, 'quantity' => 1,
        ])->assertStatus(404); // OrderHubController::gate() 404s a killed hub
    }

    // ------------------------------------------------------------ ungated

    public function test_an_ungated_key_keeps_kill_only_behavior(): void
    {
        // Before the catalog flip, an unentitled store must behave exactly as it
        // did pre-fix — otherwise the fix would break every live install that
        // has not run PremiumBundleSeeder yet.
        $this->planGate(false);
        $this->freshService();

        [$store, $menu] = $this->store();
        $this->svc->addItem($store->id, $menu->id, 1);

        $this->assertSame(1, $this->svc->cart()[$store->id][0]['quantity']);
    }
}
