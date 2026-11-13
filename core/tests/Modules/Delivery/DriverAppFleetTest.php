<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Models\Driver;
use App\Models\MenuCategory;
use App\Models\MkDispatchOffer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\Marketplace\DispatchOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Data\OtpScope;
use Juwri\Modules\Messaging\Services\SmsOtpService;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Marketplace T4-3: fleet driver app — OTP login, auth guard, offers scoping,
 * accept/reject via the orchestrator, shift toggle, location ping, earnings
 * math and the full dispatch → delivered lifecycle.
 */
class DriverAppFleetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.dispatch.offer_batch' => 1, 'marketplace.dispatch.offer_ttl_seconds' => 60]);
    }

    /* --------------------------------------------------------- fixtures */

    private function store(array $mk = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create(array_merge([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Fleet Store', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
            'mk_delivery_modes' => ['fleet'], 'mk_city_id' => 1, 'mk_lat' => 24.7136, 'mk_lng' => 46.6753,
        ], $mk));
    }

    /**
     * Delivery order with one line item priced $itemPrice — orders have NO
     * total column; Order::total is computed from items.
     */
    private function deliveryOrder(Store $store, array $overrides = [], float $itemPrice = 40): Order
    {
        $order = Order::create(array_merge([
            'store_id' => $store->id, 'type' => 'delivery', 'status' => 'new',
            'delivery_status' => 'pending', 'address' => '12 Palm St',
            'created_at' => now(),
        ], $overrides));

        $cat = MenuCategory::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'C'],
            ['parent' => null, 'position' => 1]
        );
        $menu = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item '.Str::random(4),
            'description' => 'd', 'price' => $itemPrice, 'image' => 'default.png',
            'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1]);

        return $order;
    }

    /** @return array{0: Driver, 1: string} driver + raw bearer token */
    private function fleetDriver(array $overrides = []): array
    {
        $raw = Str::random(40);

        $driver = Driver::create(array_merge([
            'owner_id' => null, 'store_id' => null,
            'name' => 'Fleet '.Str::random(4), 'phone' => UniquePhone::next('05'),
            'token' => hash('sha256', $raw),
            'active' => true, 'is_fleet' => true, 'city_id' => 1,
            'shift_status' => 'online', 'capacity' => 2, 'active_load' => 0,
            'last_lat' => 24.7200, 'last_lng' => 46.6800,
        ], $overrides));

        return [$driver, $raw];
    }

    private function hdr(string $raw): array
    {
        return ['Authorization' => 'Bearer '.$raw, 'Accept' => 'application/json'];
    }

    /* ------------------------------------------------------------- auth */

    public function test_every_fleet_endpoint_requires_a_driver_token(): void
    {
        foreach ([
            ['getJson', '/api/v1/driver/offers'],
            ['postJson', '/api/v1/driver/offers/1/accept'],
            ['postJson', '/api/v1/driver/offers/1/reject'],
            ['postJson', '/api/v1/driver/shift'],
            ['getJson', '/api/v1/driver/earnings'],
        ] as [$method, $uri]) {
            $this->{$method}($uri)->assertStatus(401);
        }
    }

    public function test_otp_login_rotates_the_token_and_it_authenticates(): void
    {
        [$driver] = $this->fleetDriver(['phone' => '0511122233']);

        $this->mock(SmsOtpService::class, function ($mock) {
            $mock->shouldReceive('request')->once()->andReturn([true, 'sent']);
            $mock->shouldReceive('verify')->once()->with('0511122233', '123456', \Mockery::on(fn ($s) => $s instanceof OtpScope && $s->key === 'driver'))->andReturn(true);
        });

        $this->postJson('/api/v1/driver/login/request', ['phone' => '0511122233'])
            ->assertOk()->assertJson(['ok' => true]);

        $res = $this->postJson('/api/v1/driver/login/verify', ['phone' => '0511122233', 'code' => '123456'])
            ->assertOk()->assertJsonStructure(['token', 'driver' => ['id', 'name', 'shift_status']]);

        $raw = $res->json('token');
        $this->assertSame(hash('sha256', $raw), $driver->fresh()->token);

        $this->getJson('/api/v1/driver/me', $this->hdr($raw))
            ->assertOk()->assertJsonPath('id', $driver->id)->assertJsonPath('is_fleet', true);
    }

    public function test_otp_login_rejects_a_bad_code(): void
    {
        $this->fleetDriver(['phone' => '0599988877']);

        $this->mock(SmsOtpService::class, function ($mock) {
            $mock->shouldReceive('verify')->once()->andReturn(false);
        });

        $this->postJson('/api/v1/driver/login/verify', ['phone' => '0599988877', 'code' => '000000'])
            ->assertStatus(422);
    }

    /* ----------------------------------------------------------- offers */

    public function test_offers_list_is_scoped_to_the_authenticated_driver(): void
    {
        $store = $this->store();
        [$mine, $raw] = $this->fleetDriver();
        [$other] = $this->fleetDriver();

        $myOrder = $this->deliveryOrder($store);
        $otherOrder = $this->deliveryOrder($store);
        MkDispatchOffer::create(['order_id' => $myOrder->id, 'driver_id' => $mine->id, 'status' => 'offered', 'expires_at' => now()->addMinute()]);
        MkDispatchOffer::create(['order_id' => $otherOrder->id, 'driver_id' => $other->id, 'status' => 'offered', 'expires_at' => now()->addMinute()]);

        $res = $this->getJson('/api/v1/driver/offers', $this->hdr($raw))->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame($myOrder->id, $res->json('data.0.order_id'));
        $this->assertSame('Fleet Store', $res->json('data.0.store'));
    }

    public function test_accept_assigns_via_the_orchestrator_and_cancels_siblings(): void
    {
        $store = $this->store();
        [$a, $rawA] = $this->fleetDriver();
        [$b] = $this->fleetDriver();

        $order = $this->deliveryOrder($store);
        $offerA = MkDispatchOffer::create(['order_id' => $order->id, 'driver_id' => $a->id, 'status' => 'offered', 'expires_at' => now()->addMinute()]);
        $offerB = MkDispatchOffer::create(['order_id' => $order->id, 'driver_id' => $b->id, 'status' => 'offered', 'expires_at' => now()->addMinute()]);

        $this->postJson("/api/v1/driver/offers/{$offerA->id}/accept", [], $this->hdr($rawA))
            ->assertOk()->assertJsonPath('id', $order->id)->assertJsonPath('delivery_status', 'assigned');

        $order->refresh();
        $this->assertSame($a->id, $order->driver_id);
        $this->assertNotNull($order->assigned_at);
        $this->assertSame('accepted', $offerA->fresh()->status);
        $this->assertSame('cancelled', $offerB->fresh()->status);
        $this->assertSame(1, $a->fresh()->active_load);
    }

    public function test_accept_of_a_stale_offer_returns_409(): void
    {
        $store = $this->store();
        [$a, $rawA] = $this->fleetDriver();
        $order = $this->deliveryOrder($store);
        $offer = MkDispatchOffer::create(['order_id' => $order->id, 'driver_id' => $a->id, 'status' => 'cancelled', 'expires_at' => now()->addMinute()]);

        $this->postJson("/api/v1/driver/offers/{$offer->id}/accept", [], $this->hdr($rawA))->assertStatus(409);
        $this->assertNull($order->fresh()->driver_id);
    }

    public function test_cannot_touch_another_drivers_offer(): void
    {
        $store = $this->store();
        [, $rawA] = $this->fleetDriver();
        [$b] = $this->fleetDriver();
        $offer = MkDispatchOffer::create(['order_id' => $this->deliveryOrder($store)->id, 'driver_id' => $b->id, 'status' => 'offered', 'expires_at' => now()->addMinute()]);

        $this->postJson("/api/v1/driver/offers/{$offer->id}/accept", [], $this->hdr($rawA))->assertStatus(404);
        $this->postJson("/api/v1/driver/offers/{$offer->id}/reject", [], $this->hdr($rawA))->assertStatus(404);
        $this->assertSame('offered', $offer->fresh()->status);
    }

    public function test_reject_cascades_the_offer_to_the_next_driver(): void
    {
        $store = $this->store();
        [$near, $rawNear] = $this->fleetDriver(['last_lat' => 24.7150, 'last_lng' => 46.6760]);
        [$far] = $this->fleetDriver(['last_lat' => 24.9000, 'last_lng' => 46.9000]);

        $order = $this->deliveryOrder($store);
        $this->assertSame('offered', (new DispatchOrchestrator)->dispatch($order));
        $offer = MkDispatchOffer::where('order_id', $order->id)->firstOrFail();
        $this->assertSame($near->id, $offer->driver_id);

        $this->postJson("/api/v1/driver/offers/{$offer->id}/reject", [], $this->hdr($rawNear))->assertOk();

        $this->assertSame('rejected', $offer->fresh()->status);
        $this->assertSame($far->id, MkDispatchOffer::where('order_id', $order->id)->pending()->value('driver_id'));
    }

    /* ---------------------------------------------------- shift + location */

    public function test_shift_toggle_via_fleet_registry(): void
    {
        [$driver, $raw] = $this->fleetDriver(['shift_status' => 'offline']);

        $this->postJson('/api/v1/driver/shift', ['status' => 'online'], $this->hdr($raw))
            ->assertOk()->assertJsonPath('shift_status', 'online');
        $this->assertSame('online', $driver->fresh()->shift_status);

        $this->postJson('/api/v1/driver/shift', ['status' => 'break'], $this->hdr($raw))
            ->assertOk()->assertJsonPath('shift_status', 'break');

        $this->postJson('/api/v1/driver/shift', ['status' => 'napping'], $this->hdr($raw))->assertStatus(422);
    }

    public function test_location_ping_updates_last_coordinates(): void
    {
        [$driver, $raw] = $this->fleetDriver(['last_lat' => null, 'last_lng' => null, 'last_location_at' => null]);

        $this->putJson('/api/v1/driver/location', ['lat' => 24.5, 'lng' => 46.5], $this->hdr($raw))
            ->assertOk()->assertJson(['ok' => true]);

        $driver->refresh();
        $this->assertEqualsWithDelta(24.5, $driver->last_lat, 0.0001);
        $this->assertEqualsWithDelta(46.5, $driver->last_lng, 0.0001);
        $this->assertNotNull($driver->last_location_at);

        $this->putJson('/api/v1/driver/location', ['lat' => 999, 'lng' => 46.5], $this->hdr($raw))->assertStatus(422);
    }

    /* --------------------------------------------------------- earnings */

    public function test_earnings_groups_delivered_orders_per_day(): void
    {
        $store = $this->store();
        [$driver, $raw] = $this->fleetDriver();

        $this->deliveryOrder($store, ['driver_id' => $driver->id, 'delivery_status' => 'delivered', 'tip_amount' => 5, 'delivered_at' => now()], 40);
        $this->deliveryOrder($store, ['driver_id' => $driver->id, 'delivery_status' => 'delivered', 'delivered_at' => now()], 25);
        $this->deliveryOrder($store, ['driver_id' => $driver->id, 'delivery_status' => 'delivered', 'delivered_at' => now()->subDay()], 10);
        // Not mine / not delivered — must not count.
        $this->deliveryOrder($store, ['driver_id' => $driver->id, 'delivery_status' => 'out_for_delivery'], 99);
        $this->deliveryOrder($store, ['delivery_status' => 'delivered', 'delivered_at' => now()], 99);

        $res = $this->getJson('/api/v1/driver/earnings', $this->hdr($raw))->assertOk();

        $this->assertSame(3, $res->json('summary.deliveries'));
        // Order::total is items + tip: (40+5) + 25 + 10 = 80.
        $this->assertEqualsWithDelta(80.0, $res->json('summary.total'), 0.001);
        $this->assertEqualsWithDelta(5.0, $res->json('summary.tips'), 0.001);

        $today = collect($res->json('data'))->firstWhere('date', now()->toDateString());
        $this->assertSame(2, $today['deliveries']);
        $this->assertEqualsWithDelta(70.0, $today['total'], 0.001);
        // MC-43: single-currency driver → one by_currency bucket == the flat total.
        $this->assertCount(1, $res->json('summary.by_currency'));
        $this->assertEqualsWithDelta(80.0, $res->json('summary.by_currency.0.total'), 0.001);
    }

    public function test_earnings_split_per_currency_for_cross_currency_driver(): void
    {
        config(['settings.currency_code' => 'USD']);
        $store = $this->store();
        [$driver, $raw] = $this->fleetDriver();

        // Two USD deliveries (item 40, item 25) + one OMR delivery (item 10 + tip 2).
        $this->deliveryOrder($store, ['driver_id' => $driver->id, 'delivery_status' => 'delivered', 'currency_code' => 'USD', 'delivered_at' => now()], 40);
        $this->deliveryOrder($store, ['driver_id' => $driver->id, 'delivery_status' => 'delivered', 'currency_code' => 'USD', 'delivered_at' => now()], 25);
        $this->deliveryOrder($store, ['driver_id' => $driver->id, 'delivery_status' => 'delivered', 'currency_code' => 'OMR', 'tip_amount' => 2, 'delivered_at' => now()], 10);

        $res = $this->getJson('/api/v1/driver/earnings', $this->hdr($raw))->assertOk();

        // The honest per-currency split, highest total first: USD 65, OMR 12.
        $byCur = collect($res->json('summary.by_currency'));
        $this->assertSame(['USD', 'OMR'], $byCur->pluck('currency')->all());
        $this->assertEqualsWithDelta(65.0, $byCur->firstWhere('currency', 'USD')['total'], 0.001);
        $this->assertEqualsWithDelta(12.0, $byCur->firstWhere('currency', 'OMR')['total'], 0.001);
        $this->assertEqualsWithDelta(2.0, $byCur->firstWhere('currency', 'OMR')['tips'], 0.001);
        // Mixed → summary.currency falls back to base; per-day currency is null.
        $this->assertSame('USD', $res->json('summary.currency'));
        $this->assertNull(collect($res->json('data'))->firstWhere('date', now()->toDateString())['currency']);
    }

    /** A legacy delivered order with no stamped currency counts in its OWN store's currency, not the platform's. */
    public function test_earnings_bucket_a_legacy_unstamped_order_in_its_stores_currency(): void
    {
        config(['settings.currency_code' => 'USD', 'settings.platform_currency_code' => null]);
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        [$driver, $raw] = $this->fleetDriver();

        $order = $this->deliveryOrder($store, ['driver_id' => $driver->id, 'delivery_status' => 'delivered', 'delivered_at' => now()], 30);
        DB::table('orders')->where('id', $order->id)->update(['currency_code' => null]); // legacy row

        $res = $this->getJson('/api/v1/driver/earnings', $this->hdr($raw))->assertOk();

        $this->assertSame(['SAR'], collect($res->json('summary.by_currency'))->pluck('currency')->all()); // before: USD
    }

    /* ------------------------------------------------------------ shell */

    public function test_fleet_shell_and_manifest_render(): void
    {
        $this->get('/driver-app')->assertOk()->assertSee(___('Fusion Driver'));
        $this->get('/driver-app/manifest.webmanifest')->assertOk()->assertJsonPath('start_url', url('/driver-app'));
    }

    /* -------------------------------------------------------- lifecycle */

    public function test_full_fleet_delivery_lifecycle(): void
    {
        $store = $this->store();
        [$driver, $raw] = $this->fleetDriver();
        $order = $this->deliveryOrder($store);

        // dispatch → offer to the (only) online fleet driver
        $this->assertSame('offered', (new DispatchOrchestrator)->dispatch($order));

        $offers = $this->getJson('/api/v1/driver/offers', $this->hdr($raw))->assertOk()->json('data');
        $this->assertCount(1, $offers);

        // accept → assigned via orchestrator
        $this->postJson("/api/v1/driver/offers/{$offers[0]['id']}/accept", [], $this->hdr($raw))
            ->assertOk()->assertJsonPath('delivery_status', 'assigned');
        $this->assertSame(1, $driver->fresh()->active_load);

        // picked_up → out_for_delivery → delivered through the existing status endpoint
        foreach (['picked_up', 'out_for_delivery', 'delivered'] as $status) {
            $this->putJson("/api/v1/driver/deliveries/{$order->id}/status",
                ['delivery_status' => $status], $this->hdr($raw))
                ->assertOk()->assertJsonPath('delivery_status', $status);
        }

        $order->refresh();
        $this->assertSame('delivered', $order->delivery_status);
        $this->assertNotNull($order->picked_up_at);
        $this->assertNotNull($order->out_for_delivery_at);
        $this->assertNotNull($order->delivered_at);

        // terminal transition released fleet capacity (T4-2 seam)
        $this->assertSame(0, $driver->fresh()->active_load);

        // and the day's earnings now include the order
        $this->getJson('/api/v1/driver/earnings', $this->hdr($raw))
            ->assertOk()->assertJsonPath('summary.deliveries', 1);
    }
}
