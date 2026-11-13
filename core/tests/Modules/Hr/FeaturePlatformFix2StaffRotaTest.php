<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hr;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use App\Services\Entitlements;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Hr\Services\Rota\RotaService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * FIX-2 F2-7 (feature-platform gap audit): `staff_rota` is enforced.
 *
 * The audit cited routes/web.php's `feature:staff_rota` group — kill-only,
 * because the key was not plan_gated. The SIDE DOOR is the staff PWA
 * (`StaffAppController::myShifts`, `/staff-app/shifts`): it authenticates a
 * STAFF member on the `staff` guard, so there is no dashboard owner and the
 * entitlement subject must be the employing store's OWNER — the merchant is
 * who buys the $1.99/mo SKU. `RotaService::enabledFor()` is that one
 * service-level check; `createShift()` re-asserts it on the write door.
 *
 * States: unentitled → refused · entitled → 200 · grandfathered → 200 ·
 * killed → refused even when entitled · not plan_gated → old kill-only behavior.
 */
class FeaturePlatformFix2StaffRotaTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'staff_rota';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        $this->killSwitch(true);
        $this->planGate(true);
    }

    // ------------------------------------------------------------- fixtures

    private function killSwitch(bool $on): void
    {
        config(['settings.admin_allow_'.self::KEY => $on ? 1 : 0]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    private function planGate(bool $on): void
    {
        Feature::updateOrCreate(
            ['key' => self::KEY],
            ['label' => 'Staff Rota & Payroll', 'plan_gated' => $on]
        );
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();
    }

    /** @return array{0:Store,1:User,2:StaffMember} store, owner, one staff member. */
    private function store(bool $entitled = false, string $source = 'admin'): array
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
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Sam',
            'role' => 'waiter', 'email' => 'staff'.Str::random(6).'@ex.com',
            'password' => Hash::make('secret'), 'active' => 1,
        ]);

        if ($entitled) {
            EntitlementWriter::grant($owner, self::KEY, $source, null, '1', null, 'test-fixture');
        }

        FeatureAccess::flush();

        return [$store, $owner, $staff];
    }

    /** A published shift, written while the gate is open. */
    private function publishedShift(Store $store, StaffMember $staff): void
    {
        $gate = EnsureFeatureEnabled::planGated(self::KEY);
        $this->planGate(false);
        new RotaService()->createShift([
            'store_id' => $store->id, 'staff_id' => $staff->id,
            'starts_at' => now()->addDay()->format('Y-m-d 09:00'),
            'ends_at' => now()->addDay()->format('Y-m-d 17:00'),
            'published' => true,
        ]);
        $this->planGate($gate);
    }

    // ----------------------------------------------------------- unentitled

    public function test_the_staff_pwa_shows_no_shifts_for_an_unentitled_store(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store, $owner, $staff] = $this->store();
        $this->publishedShift($store, $staff);

        $this->assertFalse(Entitlements::allows($owner->fresh(), self::KEY));

        $this->actingAs($staff, 'staff')->getJson('/staff-app/shifts')
            ->assertOk()->assertJsonCount(0, 'shifts');
    }

    public function test_the_service_refuses_an_unentitled_store(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store, , $staff] = $this->store();

        $this->assertFalse(new RotaService()->enabledFor($store->id));

        try {
            new RotaService()->createShift([
                'store_id' => $store->id, 'staff_id' => $staff->id,
                'starts_at' => now()->addDay()->format('Y-m-d 09:00'),
                'ends_at' => now()->addDay()->format('Y-m-d 17:00'),
            ]);
            $this->fail('createShift should refuse an unentitled store');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertDatabaseCount('staff_shifts', 0);
    }

    public function test_the_route_middleware_blocks_an_unentitled_owner(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [, $owner] = $this->store();
        Route::middleware(['web', 'feature:'.self::KEY])->get('/_t/rota', fn () => 'in');

        $res = $this->actingAs($owner)->get('/_t/rota');
        $this->assertContains(
            $res->getStatusCode(),
            [302, 403],
            'unentitled owner must be 403ed or redirected, got '.$res->getStatusCode()
        );
    }

    // ------------------------------------------------------------- entitled

    public function test_the_staff_pwa_serves_shifts_for_an_entitled_store(): void
    {
        [$store, , $staff] = $this->store(entitled: true);
        $this->publishedShift($store, $staff);

        $this->actingAs($staff, 'staff')->getJson('/staff-app/shifts')
            ->assertOk()->assertJsonCount(1, 'shifts');
    }

    public function test_the_route_middleware_lets_an_entitled_owner_through(): void
    {
        [, $owner] = $this->store(entitled: true);
        Route::middleware(['web', 'feature:'.self::KEY])->get('/_t/rota-ok', fn () => 'in');

        $this->actingAs($owner)->get('/_t/rota-ok')->assertOk()->assertSee('in');
    }

    // -------------------------------------------------------- grandfathered

    public function test_a_grandfathered_store_keeps_its_rota(): void
    {
        [$store, $owner, $staff] = $this->store(entitled: true, source: 'grandfather');
        $this->publishedShift($store, $staff);

        $this->assertDatabaseHas('entitlement_overrides', [
            'user_id' => $owner->id, 'feature_key' => self::KEY, 'source_type' => 'grandfather',
        ]);
        $this->assertTrue(new RotaService()->enabledFor($store->id));
        $this->actingAs($staff, 'staff')->getJson('/staff-app/shifts')
            ->assertOk()->assertJsonCount(1, 'shifts');
    }

    // --------------------------------------------------------------- killed

    public function test_the_kill_switch_beats_entitlement_on_the_staff_pwa(): void
    {
        [$store, , $staff] = $this->store(entitled: true);
        $this->publishedShift($store, $staff);
        $this->killSwitch(false);

        $this->actingAs($staff, 'staff')->getJson('/staff-app/shifts')
            ->assertOk()->assertJsonCount(0, 'shifts');
    }

    public function test_the_route_middleware_403s_an_entitled_owner_while_killed(): void
    {
        [, $owner] = $this->store(entitled: true);
        Route::middleware(['web', 'feature:'.self::KEY])->get('/_t/rota-killed', fn () => 'in');
        $this->killSwitch(false);

        $this->actingAs($owner)->get('/_t/rota-killed')->assertForbidden();
    }

    // -------------------------------------------------------------- ungated

    public function test_an_ungated_key_keeps_kill_only_behavior(): void
    {
        $this->planGate(false);
        [$store, , $staff] = $this->store();

        $this->assertTrue(new RotaService()->enabledFor($store->id));
        $this->publishedShift($store, $staff);

        $this->actingAs($staff, 'staff')->getJson('/staff-app/shifts')
            ->assertOk()->assertJsonCount(1, 'shifts');
    }
}
