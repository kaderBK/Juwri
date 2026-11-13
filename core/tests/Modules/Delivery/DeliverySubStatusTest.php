<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\Installed;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * T3 — coarse delivery sub-status on the public track page.
 *
 * `orders.delivery_status` reaches the diner as a STRING ONLY — the public
 * token-auth track JSON must never carry driver name/phone/coordinates (§0.10).
 * All 17 themes render the shared sub-status line; lime's "Live" pill is gated
 * on the real out_for_delivery state.
 */
class DeliverySubStatusTest extends TestCase
{
    use RefreshDatabase;

    /** Exactly the keys the public track JSON may expose — no driver PII. */
    private const SAFE_JSON_KEYS = [
        'status', 'preparing_at', 'ready_at', 'completed_at', 'cancelled_at',
        'prep_eta', 'token_number', 'delivery_status',
    ];

    private const THEMES = KeptTemplates::MODERN;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Cache::flush();

        $settings = new class(['theme_color' => '#111', 'site_title' => 'MB', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            ThrottleRequests::class,
            Installed::class,
        ]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Pizza', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
    }

    private function order(Store $r, string $type, ?string $deliveryStatus): Order
    {
        return Order::create([
            'store_id' => $r->id, 'status' => 'ready', 'type' => $type,
            'customer_name' => 'John', 'track_token' => Str::random(32),
            'delivery_status' => $deliveryStatus,
        ]);
    }

    public function test_delivery_order_exposes_delivery_status_string(): void
    {
        $r = $this->storeFixture();
        $order = $this->order($r, 'delivery', 'out_for_delivery');

        $this->getJson(route('store.orderTrack', $order->track_token))
            ->assertOk()
            ->assertJsonPath('delivery_status', 'out_for_delivery');
    }

    public function test_pickup_order_never_carries_delivery_status(): void
    {
        $r = $this->storeFixture();
        $order = $this->order($r, 'takeaway', null);

        $this->getJson(route('store.orderTrack', $order->track_token))
            ->assertOk()
            ->assertJsonPath('delivery_status', null);
    }

    public function test_public_track_json_leaks_no_driver_pii(): void
    {
        $r = $this->storeFixture();
        $order = $this->order($r, 'delivery', 'out_for_delivery');

        $json = $this->getJson(route('store.orderTrack', $order->track_token))->assertOk()->json();
        $keys = array_keys($json);
        sort($keys);
        $expected = self::SAFE_JSON_KEYS;
        sort($expected);
        // Any new key here is a leak risk; driver name/phone/lat/lng must never appear.
        $this->assertSame($expected, $keys, 'Track JSON exposed an unexpected key (possible PII leak).');
        foreach (['driver', 'phone', 'lat', 'lng', 'name', 'last_lat', 'last_lng'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $json);
        }
        // delivery_status is a plain string, not a nested object.
        $this->assertIsString($json['delivery_status']);
    }

    public function test_all_17_render_the_shared_sub_status_line(): void
    {
        foreach (self::THEMES as $theme) {
            $src = file_get_contents(resource_path("views/store_templates/{$theme}/track.blade.php"));
            $this->assertStringContainsString(
                "@include('store_templates._partials.delivery-substatus')",
                $src,
                "[$theme] missing delivery sub-status include"
            );
        }
    }

    public function test_sub_status_partial_maps_all_states_and_hides_pii(): void
    {
        $p = file_get_contents(resource_path('views/store_templates/_partials/delivery-substatus.blade.php'));
        foreach (['assigned', 'picked_up', 'out_for_delivery', 'delivered', 'failed'] as $state) {
            $this->assertStringContainsString($state.':', $p, "sub-status label missing for [$state]");
        }
        $this->assertStringContainsString('window.mbTrackPoll(', $p);
        $this->assertStringContainsString('res.delivery_status', $p);
        // Painted via textContent (never innerHTML with server strings).
        $this->assertStringContainsString('.textContent', $p);
        $this->assertStringNotContainsString('innerHTML', $p);
    }

    public function test_lime_live_pill_is_gated_on_out_for_delivery(): void
    {
        $lime = file_get_contents(resource_path('views/store_templates/lime/track.blade.php'));
        $this->assertStringContainsString('id="lmCcLive"', $lime);
        $this->assertStringContainsString("delivery_status !== 'out_for_delivery'", $lime);
    }

    /** Found by the e2eA driver journey: once the driver marked the order delivered the track page showed nothing. */
    public function test_a_delivered_order_shows_the_delivered_line_on_first_paint(): void
    {
        $r = $this->storeFixture();
        $order = $this->order($r, 'delivery', 'delivered');

        $html = view('store_templates._partials.delivery-substatus', ['order' => $order])->render();

        $this->assertStringContainsString('Delivered — enjoy!', explode('<script', $html)[0]);
        $this->assertStringNotContainsString(' hidden', explode('<script', $html)[0]);
    }
}
