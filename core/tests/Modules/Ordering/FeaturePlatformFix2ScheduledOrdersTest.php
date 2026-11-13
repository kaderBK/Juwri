<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Services\Entitlements;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * F2-2 (feature-platform gap fix): `scheduled_orders` reads its entitlement.
 *
 * The audit found SchedulingService::enabled() consulting only the platform
 * kill-switch plus the per-store `allow_order_scheduling` toggle — so the
 * $0.99/mo SKU bought nothing, and turning the kill-switch off punished
 * paying merchants. enabled() is the single choke point behind every entry
 * point (the public schedule-slots endpoint, StoreController::submitOrder,
 * OrderApiService::create, the hotel guest partial), so the gate lives there.
 *
 * WHOSE entitlement: the STORE OWNER. Every caller is an anonymous diner, so
 * a session-subject route middleware would 403 the whole world — see the
 * comment on the schedule-slots/curbside routes in web.php.
 *
 * Contract frozen below:
 *   unentitled     → scheduling off (endpoint reports enabled:false, validate throws)
 *   entitled       → scheduling on
 *   grandfathered  → scheduling on (non-expiring override, source_type=grandfather)
 *   killed         → off even for an entitled owner (kill veto wins)
 *   key not gated  → kill-only, exactly as before the fix
 */
class FeaturePlatformFix2ScheduledOrdersTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'scheduled_orders';

    protected function setUp(): void
    {
        parent::setUp();
        // Full disable: every gate under test lives in SchedulingService, not in
        // middleware, and the storefront group's own middleware stack is noise here.
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

    /**
     * Self-sufficient: the gate-flip seeder is a sibling row in this batch, so
     * this test must not depend on it having run (or on its ordering).
     */
    private function planGate(bool $on): void
    {
        Feature::updateOrCreate(
            ['key' => self::KEY],
            ['label' => 'Scheduled Orders', 'plan_gated' => $on]
        );
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();
    }

    /** @return array{0:Store,1:User} a store with scheduling switched on, and its owner. */
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
        StoreSettings::set($store->id, 'allow_order_scheduling', '1');

        return [$store, $owner];
    }

    private function entitle(User $owner, string $sourceType = 'admin', string $reason = 'test-fixture'): void
    {
        EntitlementWriter::grant($owner, self::KEY, $sourceType, null, '1', null, $reason);
    }

    /** A fresh service — the store->entitled memo is per instance (per request). */
    private function svc(): SchedulingService
    {
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();

        return new SchedulingService;
    }

    private function tomorrowNoon(Store $store): string
    {
        $tz = new SchedulingService()->timezone($store->id);

        return Carbon::now($tz)->addDay()->setTime(12, 0)->format('Y-m-d H:i');
    }

    // ----------------------------------------------------------- unentitled

    public function test_an_unentitled_owner_gets_no_scheduling(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store, $owner] = $this->store();

        $this->assertFalse(
            Entitlements::allows($owner->fresh(), self::KEY),
            'the fixture owner must start unentitled'
        );
        $this->assertFalse($this->svc()->enabled($store->id), 'enabled() must refuse an unentitled store');
        $this->assertSame([], $this->svc()->slotsFor($store->id, null, 'takeaway', now()->addDay()->format('Y-m-d')));
    }

    public function test_the_public_slots_endpoint_reports_disabled_for_an_unentitled_store(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store] = $this->store();

        $this->getJson(route('store.scheduleSlots', $store).'?type=takeaway')
            ->assertOk()
            ->assertJson(['enabled' => false, 'slots' => []]);
    }

    public function test_order_placement_refuses_a_scheduled_time_for_an_unentitled_store(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store] = $this->store();

        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->validate($store->id, null, 'takeaway', $this->tomorrowNoon($store));
    }

    // ------------------------------------------------------------- entitled

    public function test_an_entitled_owner_gets_scheduling(): void
    {
        [$store, $owner] = $this->store();
        $this->entitle($owner);

        $this->assertTrue($this->svc()->enabled($store->id));
        $this->assertNotEmpty($this->svc()->slotsFor($store->id, null, 'takeaway', now()->addDay()->format('Y-m-d')));
        $this->assertNotNull($this->svc()->validate($store->id, null, 'takeaway', $this->tomorrowNoon($store)));
    }

    public function test_the_public_slots_endpoint_serves_an_entitled_store(): void
    {
        [$store, $owner] = $this->store();
        $this->entitle($owner);

        $this->getJson(route('store.scheduleSlots', $store).'?type=takeaway')
            ->assertOk()
            ->assertJson(['enabled' => true]);
    }

    // -------------------------------------------------------- grandfathered

    public function test_a_grandfathered_owner_keeps_scheduling(): void
    {
        [$store, $owner] = $this->store();
        // Exactly what `entitlements:grandfather --apply` writes: non-expiring.
        $this->entitle($owner, 'grandfather', 'grandfather-2026-07');

        $this->assertTrue($this->svc()->enabled($store->id), 'a grandfathered merchant must not lose a live feature');
        $this->getJson(route('store.scheduleSlots', $store).'?type=takeaway')
            ->assertOk()
            ->assertJson(['enabled' => true]);
    }

    // --------------------------------------------------------------- killed

    public function test_the_kill_switch_wins_over_entitlement(): void
    {
        [$store, $owner] = $this->store();
        $this->entitle($owner);
        $this->killSwitch(false);

        $this->assertFalse($this->svc()->enabled($store->id), 'platform OFF must beat a paid entitlement');
        $this->getJson(route('store.scheduleSlots', $store).'?type=takeaway')
            ->assertOk()
            ->assertJson(['enabled' => false]);
    }

    // -------------------------------------------------------------- ungated

    public function test_an_ungated_key_keeps_kill_only_behavior(): void
    {
        // Installs that have not run the gate-flip seeder must behave exactly as
        // they did before this fix — the toggle alone still decides.
        $this->planGate(false);
        [$store] = $this->store();

        $this->assertTrue($this->svc()->enabled($store->id));
    }
}
