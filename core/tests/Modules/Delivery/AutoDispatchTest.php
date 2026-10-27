<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Delivery\Models\CourierDelivery;
use Juwri\Modules\Delivery\Services\Couriers\DispatchService;
use Juwri\Modules\Delivery\Services\Couriers\UberDirect\FakeUberDirectClient;
use Juwri\Modules\Delivery\Services\Couriers\UberDirect\UberDirectClientInterface;
use Tests\Support\TestPlans;
use Tests\TestCase;

class AutoDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        FakeUberDirectClient::reset();
        $this->app->bind(UberDirectClientInterface::class, fn () => new FakeUberDirectClient);
    }

    private function order(string $mode, float $ownCharge = 8.0): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'dispatch_mode', $mode);
        StoreOption::updateStoreOption($r->id, 'restaurant_delivery_charge', $ownCharge);
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');
        $order = Order::create([
            'store_id' => $r->id, 'type' => 'delivery', 'status' => 'new',
            'delivery_status' => 'pending', 'address' => '10 Rd', 'phone_number' => '+1',
            'track_token' => Str::lower(Str::random(32)), 'created_at' => now(),
        ]);

        return [$owner, $r, $order];
    }

    public function test_manual_mode_does_not_auto_dispatch(): void
    {
        [, , $order] = $this->order('manual');
        $this->assertNull(DispatchService::autoDispatch($order));
        $this->assertSame(0, CourierDelivery::count());
    }

    public function test_own_mode_keeps_own_fleet(): void
    {
        [, , $order] = $this->order('own');
        $this->assertNull(DispatchService::autoDispatch($order)); // own fleet → no courier row
        $this->assertSame('pending', $order->fresh()->delivery_status);
        $this->assertSame(0, CourierDelivery::count());
    }

    public function test_cheapest_mode_picks_lowest_fee(): void
    {
        // Own 8.00, Uber Direct 6.99, DoorDash 5.49 → cheapest = doordash.
        [, , $order] = $this->order('cheapest', 8.0);
        $delivery = DispatchService::autoDispatch($order);
        $this->assertNotNull($delivery);
        $this->assertSame('doordash', $delivery->provider);
    }

    public function test_cheapest_prefers_own_when_own_is_cheaper(): void
    {
        // Own fleet free (0); Uber Direct 6.99 → cheapest = own (no courier row).
        [, , $order] = $this->order('cheapest', 0.0);
        $this->assertNull(DispatchService::autoDispatch($order));
        $this->assertSame(0, CourierDelivery::count());
    }

    public function test_escalate_to_third_party_excludes_own(): void
    {
        [, , $order] = $this->order('overflow', 0.0);
        $delivery = DispatchService::escalateToThirdParty($order);
        $this->assertNotNull($delivery);
        // Own excluded even though free; cheapest 3rd-party = doordash (5.49).
        $this->assertSame('doordash', $delivery->provider);
    }

    public function test_dispatch_screen_renders(): void
    {
        [$owner, $r, $order] = $this->order('manual');
        // share theme vars for the layout
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'languages' => collect(),
            'settings' => (object) ['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'site_favicon' => 'f.png']]);
        $this->actingAs($owner)->get(route('stores.dispatch.index').'?store_id='.$r->id)->assertOk();
    }

    public function test_track_api_includes_courier_position(): void
    {
        [, $r, $order] = $this->order('cheapest', 8.0);
        DispatchService::dispatch($order, 'uber_direct');
        // Simulate a courier location update.
        CourierDelivery::where('order_id', $order->id)->update(['courier_lat' => 25.3, 'courier_lng' => 51.5, 'eta_minutes' => 12]);

        $res = $this->getJson('/api/v1/track/'.$order->track_token)->assertOk();
        $this->assertSame('uber_direct', $res->json('courier.provider'));
        $this->assertEqualsWithDelta(25.3, (float) $res->json('courier.lat'), 0.001);
        $this->assertSame(12, $res->json('courier.eta_minutes'));
    }
}
