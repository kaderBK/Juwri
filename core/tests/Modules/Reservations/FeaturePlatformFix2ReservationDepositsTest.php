<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Reservation;
use App\Models\StoreOption;
use App\Services\Entitlements;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\ReservationDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Payments\Contracts\Gateways;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * FIX-2 F2-5 (feature-platform gap audit): `reservation_deposits` is enforced.
 *
 * The audit found `ReservationDepositService::enabled()` consulting the global
 * kill-switch and the per-store toggle only — buying the $1.49/mo SKU changed
 * nothing, and a free store kept taking deposits. The service now asks the
 * STORE OWNER's entitlement (the diner mid-booking is anonymous, so the
 * dashboard-owner subject does not exist on that request).
 *
 * Contract frozen below, per the batch done-condition:
 *   unentitled    → refused (service says no, the money door 403s)
 *   entitled      → works
 *   grandfathered → works (non-expiring override, source_type='grandfather')
 *   killed        → refused EVEN WHEN entitled (kill veto wins)
 *   not plan_gated→ byte-for-byte the old kill-only behavior
 *
 * Deliberate carve-out, asserted below: markPaid/refund/keep are NOT gated.
 * They continue a deposit already taken from a diner; stranding that money
 * behind a lapsed merchant subscription is the worse failure.
 */
class FeaturePlatformFix2ReservationDepositsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'reservation_deposits';

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
            ['label' => 'Reservation Deposits', 'plan_gated' => $on]
        );
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();
    }

    /** @return array{0:Store,1:User} a store with deposits switched ON, and its owner. */
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

        StoreOption::updateStoreOption($store->id, 'reservation_deposit_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'reservation_deposit_amount', '20');
        StoreOption::updateStoreOption($store->id, 'reservation_deposit_min_party', '2');

        if ($entitled) {
            // Grandfathering is the same mechanism with a different source_type —
            // a non-expiring override written by EntitlementWriter.
            EntitlementWriter::grant($owner, self::KEY, $source, null, '1', null, 'test-fixture');
        }

        FeatureAccess::flush();

        return [$store, $owner];
    }

    private function reservation(Store $store): Reservation
    {
        return Reservation::create([
            'store_id' => $store->id, 'name' => 'Sam', 'party_size' => 4,
            'reserved_at' => now()->addDay(), 'status' => 'pending', 'token' => Str::random(40),
        ]);
    }

    // ----------------------------------------------------------- unentitled

    public function test_an_unentitled_store_cannot_take_a_deposit(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store, $owner] = $this->store();

        $this->assertFalse(
            Entitlements::allows($owner->fresh(), self::KEY),
            'the fixture owner must start unentitled'
        );
        $this->assertFalse(ReservationDepositService::enabled($store->id), 'enabled() must refuse');
        $this->assertFalse(ReservationDepositService::required($store->id, 8), 'required() must refuse');
    }

    public function test_the_money_door_403s_for_an_unentitled_store(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store] = $this->store();
        $res = $this->reservation($store);

        try {
            ReservationDepositService::initiate($res, 'stripe');
            $this->fail('initiate() should refuse an unentitled store');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertNull($res->fresh()->deposit_transaction_id, 'no charge may be opened');
    }

    public function test_the_route_middleware_blocks_an_unentitled_owner(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [, $owner] = $this->store();
        Route::middleware(['web', 'feature:'.self::KEY])->get('/_t/resdep', fn () => 'in');

        $res = $this->actingAs($owner)->get('/_t/resdep');
        $this->assertContains(
            $res->getStatusCode(),
            [302, 403],
            'an unentitled owner must be 403ed or redirected to the upsell, got '.$res->getStatusCode()
        );
    }

    // ------------------------------------------------------------- entitled

    public function test_an_entitled_store_takes_deposits(): void
    {
        [$store] = $this->store(entitled: true);

        $this->assertTrue(ReservationDepositService::enabled($store->id));
        $this->assertTrue(ReservationDepositService::required($store->id, 4));

        $tx = ReservationDepositService::initiate($this->reservation($store), 'stripe');
        $this->assertSame('reservation_deposit', $tx->transaction_method);
        $this->assertEquals(20.0, (float) $tx->amount);
    }

    public function test_the_route_middleware_lets_an_entitled_owner_through(): void
    {
        [, $owner] = $this->store(entitled: true);
        Route::middleware(['web', 'feature:'.self::KEY])->get('/_t/resdep-ok', fn () => 'in');

        $this->actingAs($owner)->get('/_t/resdep-ok')->assertOk()->assertSee('in');
    }

    // -------------------------------------------------------- grandfathered

    public function test_a_grandfathered_store_keeps_its_deposits(): void
    {
        [$store, $owner] = $this->store(entitled: true, source: 'grandfather');

        $this->assertDatabaseHas('entitlement_overrides', [
            'user_id' => $owner->id, 'feature_key' => self::KEY, 'source_type' => 'grandfather',
        ]);
        $this->assertTrue(ReservationDepositService::enabled($store->id));
        $this->assertNotNull(ReservationDepositService::initiate($this->reservation($store), 'stripe')->id);
    }

    // --------------------------------------------------------------- killed

    public function test_the_kill_switch_beats_entitlement(): void
    {
        [$store] = $this->store(entitled: true);
        $this->killSwitch(false);

        $this->assertFalse(ReservationDepositService::enabled($store->id), 'kill veto must win');

        try {
            ReservationDepositService::initiate($this->reservation($store), 'stripe');
            $this->fail('initiate() should refuse while the feature is killed');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_the_route_middleware_403s_an_entitled_owner_while_killed(): void
    {
        [, $owner] = $this->store(entitled: true);
        Route::middleware(['web', 'feature:'.self::KEY])->get('/_t/resdep-killed', fn () => 'in');
        $this->killSwitch(false);

        $this->actingAs($owner)->get('/_t/resdep-killed')->assertForbidden();
    }

    // -------------------------------------------------------------- carve-out

    public function test_an_already_paid_deposit_still_refunds_when_the_store_lapses(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store] = $this->store(entitled: true);
        $res = $this->reservation($store);
        $tx = ReservationDepositService::initiate($res, 'stripe');
        ReservationDepositService::markPaid($res->fresh(), $tx);

        // Subscription lapses: every override gone.
        EntitlementOverride::query()->delete();
        FeatureAccess::flush();
        $this->assertFalse(ReservationDepositService::enabled($store->id));

        // P6-336: refund() goes through the store gateway; the provider confirms (fake, no network)
        $this->mock(Gateways::class, fn ($m) => $m->shouldReceive('refundStorePayment')->once()
            ->andReturn(['automatic' => true, 'gateway' => 'stripe', 'reference' => 're_t', 'message' => null]));
        $refunded = ReservationDepositService::refund($res->fresh());
        $this->assertEquals(20.0, $refunded, "a diner's paid deposit must not be trapped by a lapsed merchant plan");
        $this->assertSame('refunded', $res->fresh()->deposit_status);
    }

    // -------------------------------------------------------------- ungated

    public function test_an_ungated_key_keeps_kill_only_behavior(): void
    {
        // Before the catalog flip, an unentitled store must behave exactly as it
        // did pre-fix — otherwise the fix breaks every install that has not run
        // the gate-flip seeder yet.
        $this->planGate(false);
        [$store] = $this->store();

        $this->assertTrue(ReservationDepositService::enabled($store->id));
        $this->assertNotNull(ReservationDepositService::initiate($this->reservation($store), 'stripe')->id);
    }
}
