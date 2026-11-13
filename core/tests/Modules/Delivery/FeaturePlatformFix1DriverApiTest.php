<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Driver;
use App\Models\Order;
use App\Services\Entitlements;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Integrations\Models\ApiToken;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * F1-1 (feature-platform gap fix): the REST v1 driver/dispatch endpoints get
 * web-equivalent gating.
 *
 * The audit's bypass: on the web, dispatch (map, feed, assign, report) and the
 * driver PWA shell all sit behind `feature:driver_app`, a plan-gated Premium
 * key. The API equivalents were guarded by token ABILITIES alone — so an owner
 * with no driver_app entitlement minted a `drivers:write` token and drove the
 * entire dispatch suite for free, and a superadmin kill-switch flip did not
 * stop them either.
 *
 * The contract frozen here, per endpoint:
 *   unentitled owner  → JSON 403 (never a redirect: these are API routes)
 *   entitled owner    → 200/2xx
 *   killed feature    → JSON 403 even for an entitled owner (kill veto wins)
 */
class FeaturePlatformFix1DriverApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        // The key is plan_gated (PremiumBundleSeeder does this in prod) and the
        // platform kill-switch is ON — the baseline for every case below.
        Feature::where('key', 'driver_app')->update(['plan_gated' => true]);
        FeatureRegistry::clearCache(); // mass update fires no model event
        $this->killSwitch(true);
        $this->forgetGatedMemo();
    }

    // ------------------------------------------------------------- fixtures

    private function killSwitch(bool $on): void
    {
        config(['settings.admin_allow_driver_app' => $on ? 1 : 0]);
        FeatureRegistry::clearCache();
    }

    /** The plan_gated set is memoised per request; a test spans several. */
    private function forgetGatedMemo(): void
    {
        app()->forgetInstance('features.plan_gated');
    }

    /** @return array{0:User,1:Store} an owner with one restaurant, on a bare plan. */
    private function owner(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999], 'status' => 1,
        ]);
        $user = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $store];
    }

    private function token(User $user): string
    {
        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $user->id, 'name' => 't',
            'token' => hash('sha256', $raw), 'abilities' => ['read', 'write'],
        ]);

        return $raw;
    }

    private function entitle(User $user): void
    {
        EntitlementWriter::grant($user, 'driver_app', 'admin', null, '1', null, 'test-fixture');
    }

    private function driver(User $owner, Store $store): Driver
    {
        return Driver::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'D'.Str::random(4),
            'token' => hash('sha256', Str::random(40)), 'active' => true,
        ]);
    }

    private function order(Store $store): Order
    {
        return Order::create([
            'store_id' => $store->id, 'type' => 'delivery', 'status' => 'pending',
            'created_at' => now(),
        ]);
    }

    /**
     * Every gated endpoint as [method, url-factory, payload]. One list drives
     * all three states, so a future endpoint added to the block cannot be
     * gated in one state and not another.
     *
     * @return array<string, array{0:string,1:\Closure,2:array}>
     */
    private function endpoints(User $owner, Store $store): array
    {
        $driver = fn () => $this->driver($owner, $store)->id;

        return [
            'drivers.index' => ['getJson',    fn () => '/api/v1/drivers', []],
            'drivers.store' => ['postJson',   fn () => '/api/v1/drivers', ['name' => 'New Driver']],
            'drivers.update' => ['putJson',    fn () => '/api/v1/drivers/'.$driver(), ['name' => 'Renamed']],
            'drivers.rotate' => ['postJson',   fn () => '/api/v1/drivers/'.$driver().'/rotate-token', []],
            'drivers.destroy' => ['deleteJson', fn () => '/api/v1/drivers/'.$driver(), []],
            'deliveries' => ['getJson',    fn () => '/api/v1/stores/'.$store->id.'/deliveries', []],
        ];
    }

    private function hit(string $verb, string $url, array $payload, string $token)
    {
        $headers = ['Authorization' => 'Bearer '.$token];

        // getJson takes headers second; post/put/deleteJson take (url, data, headers).
        return $verb === 'getJson'
            ? $this->getJson($url, $headers)
            : $this->{$verb}($url, $payload, $headers);
    }

    // ------------------------------------------------------- (a) unentitled

    public function test_unentitled_token_is_refused_with_json_403_on_every_driver_endpoint(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$owner, $store] = $this->owner();
        $token = $this->token($owner);

        $this->assertFalse(
            Entitlements::allows($owner->fresh(), 'driver_app'),
            'the fixture owner must start with no driver_app entitlement'
        );

        foreach ($this->endpoints($owner, $store) as $name => [$verb, $url, $payload]) {
            $this->forgetGatedMemo();
            $res = $this->hit($verb, $url(), $payload, $token);

            $res->assertStatus(403, $name.' should refuse an unentitled token');
            // API routes must answer in JSON — a redirect to the locked page
            // would be unusable for a machine client.
            $this->assertSame('driver_app', $res->json('feature'), $name.' should name the missing feature');
        }
    }

    public function test_unentitled_token_cannot_assign_a_driver_or_move_delivery_status(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$owner, $store] = $this->owner();
        $token = $this->token($owner);
        $driver = $this->driver($owner, $store);
        $order = $this->order($store);

        $this->forgetGatedMemo();
        $this->postJson(
            '/api/v1/stores/'.$store->id.'/orders/'.$order->id.'/assign',
            ['driver_id' => $driver->id],
            ['Authorization' => 'Bearer '.$token]
        )->assertStatus(403);

        $this->forgetGatedMemo();
        $this->putJson(
            '/api/v1/stores/'.$store->id.'/orders/'.$order->id.'/delivery-status',
            ['delivery_status' => 'picked_up'],
            ['Authorization' => 'Bearer '.$token]
        )->assertStatus(403);

        $this->assertNull($order->fresh()->driver_id, 'no driver may be assigned through the ungated path');
    }

    // --------------------------------------------------------- (b) entitled

    public function test_entitled_token_passes_every_driver_endpoint(): void
    {
        [$owner, $store] = $this->owner();
        $this->entitle($owner);
        $token = $this->token($owner);

        foreach ($this->endpoints($owner, $store) as $name => [$verb, $url, $payload]) {
            $this->forgetGatedMemo();
            $res = $this->hit($verb, $url(), $payload, $token);

            $this->assertTrue(
                $res->getStatusCode() < 300,
                $name.' should pass for an entitled owner, got '.$res->getStatusCode().': '.$res->getContent()
            );
        }
    }

    public function test_entitled_token_can_assign_a_driver(): void
    {
        [$owner, $store] = $this->owner();
        $this->entitle($owner);
        $token = $this->token($owner);
        $driver = $this->driver($owner, $store);
        $order = $this->order($store);

        $this->forgetGatedMemo();
        $this->postJson(
            '/api/v1/stores/'.$store->id.'/orders/'.$order->id.'/assign',
            ['driver_id' => $driver->id],
            ['Authorization' => 'Bearer '.$token]
        )->assertOk();

        $this->assertSame($driver->id, (int) $order->fresh()->driver_id);
    }

    // ------------------------------------------------------------ (c) killed

    public function test_kill_switch_vetoes_even_an_entitled_token(): void
    {
        [$owner, $store] = $this->owner();
        $this->entitle($owner);
        $token = $this->token($owner);

        $this->killSwitch(false);

        foreach ($this->endpoints($owner, $store) as $name => [$verb, $url, $payload]) {
            $this->forgetGatedMemo();
            $this->hit($verb, $url(), $payload, $token)
                ->assertStatus(403, $name.' must stay closed while the feature is killed');
        }
    }

    public function test_kill_switch_vetoes_assign_and_delivery_status_for_an_entitled_token(): void
    {
        [$owner, $store] = $this->owner();
        $this->entitle($owner);
        $token = $this->token($owner);
        $driver = $this->driver($owner, $store);
        $order = $this->order($store);

        $this->killSwitch(false);
        $this->forgetGatedMemo();

        $this->postJson(
            '/api/v1/stores/'.$store->id.'/orders/'.$order->id.'/assign',
            ['driver_id' => $driver->id],
            ['Authorization' => 'Bearer '.$token]
        )->assertStatus(403);

        $this->assertNull($order->fresh()->driver_id);
    }

    // ------------------------------------------------------------- guardrails

    public function test_the_gate_is_owner_scoped_not_session_scoped(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        // An entitled owner logged into the dashboard must not lend their
        // entitlement to a DIFFERENT owner's API token.
        [$entitledOwner] = $this->owner();
        $this->entitle($entitledOwner);

        [$poorOwner] = $this->owner();
        $token = $this->token($poorOwner);

        $this->forgetGatedMemo();
        $this->actingAs($entitledOwner)
            ->getJson('/api/v1/drivers', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403);
    }

    public function test_an_ungated_key_keeps_kill_only_behavior(): void
    {
        // Regression guard for the shared middleware: with plan_gated off, the
        // API behaves exactly as before the fix (abilities + kill only).
        Feature::where('key', 'driver_app')->update(['plan_gated' => false]);
        FeatureRegistry::clearCache(); // mass update fires no model event
        $this->forgetGatedMemo();

        [$owner] = $this->owner();
        $token = $this->token($owner);

        $this->getJson('/api/v1/drivers', ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->killSwitch(false);
        $this->forgetGatedMemo();
        $this->getJson('/api/v1/drivers', ['Authorization' => 'Bearer '.$token])->assertStatus(403);
    }
}
