<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Models\Driver;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P6-236: the public track page shows the driver's phone/position only while
 * the delivery is live. P6-237: driver PWA never caches API/HTML; logout
 * sends Clear-Site-Data.
 */
class DriverPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_driver_app' => true]);
    }

    /** @return array{0: Order, 1: Driver, 2: string} */
    private function scenario(array $order = [], array $driver = []): array
    {
        $owner = User::factory()->create();
        $store = Store::create([
            'user_id' => $owner->id, 'title' => 'R', 'slug' => 'r-'.$owner->id,
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '123',
        ]);
        $raw = Str::random(40);
        $d = Driver::create($driver + [
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Dan',
            'phone' => UniquePhone::next('5'), 'token' => hash('sha256', $raw), 'active' => true,
            'last_lat' => 24.7, 'last_lng' => 46.6, 'last_location_at' => now(),
        ]);
        $o = Order::create($order + [
            'store_id' => $store->id, 'type' => 'delivery', 'status' => 'preparing',
            'delivery_status' => 'out_for_delivery', 'driver_id' => $d->id,
        ]);
        $o->forceFill(['track_token' => Str::random(32)])->save();

        return [$o->fresh(), $d, $raw];
    }

    private function track(Order $o): array
    {
        return $this->getJson('/api/v1/track/'.$o->track_token)->assertOk()->json();
    }

    public function test_live_delivery_shows_driver_and_position(): void
    {
        [$o, $d] = $this->scenario();
        $j = $this->track($o);
        $this->assertSame($d->phone, $j['driver']['phone']);
        $this->assertEqualsWithDelta(24.7, $j['driver']['lat'], 0.001);
    }

    public function test_cancelled_order_hides_driver(): void
    {
        [$o] = $this->scenario(['status' => 'cancelled']);
        $this->assertNull($this->track($o)['driver']);
    }

    public function test_delivered_later_hides_driver(): void
    {
        // delivery_status stuck in picked_up but the order was closed later.
        [$o] = $this->scenario(['status' => 'completed', 'delivery_status' => 'picked_up']);
        $this->assertNull($this->track($o)['driver']);
        [$o2] = $this->scenario(['delivery_status' => 'picked_up', 'delivered_at' => now()]);
        $this->assertNull($this->track($o2)['driver']);
    }

    public function test_offline_fleet_driver_hidden(): void
    {
        [$o] = $this->scenario([], ['is_fleet' => true, 'shift_status' => 'offline']);
        $this->assertNull($this->track($o)['driver']);
    }

    public function test_stale_position_hidden(): void
    {
        [$o] = $this->scenario([], ['last_location_at' => now()->subMinutes(30)]);
        $j = $this->track($o);
        $this->assertNull($j['driver']['lat']);
        $this->assertNull($j['driver']['lng']);
    }

    public function test_sw_never_caches_api_or_html(): void
    {
        $js = $this->get('/driver/sw.js')->assertOk()->getContent();
        $this->assertStringContainsString("startsWith('/api/')", $js);
        $this->assertStringContainsString("req.mode === 'navigate'", $js);
        $this->assertStringContainsString('STATIC_RE', $js);
        $this->assertStringContainsString("'driver-clear'", $js);
        $this->assertStringContainsString('purge(CACHE)', $js);
        $this->assertStringNotContainsString("const CACHE = 'driver-v1'", $js);
    }

    public function test_logout_sends_clear_site_data(): void
    {
        [, , $raw] = $this->scenario();
        $r = $this->postJson('/api/v1/driver/logout', [], ['Authorization' => 'Bearer '.$raw])->assertOk();
        $this->assertStringContainsString('"cache"', (string) $r->headers->get('Clear-Site-Data'));
        $this->assertStringContainsString('"storage"', (string) $r->headers->get('Clear-Site-Data'));
    }
}
