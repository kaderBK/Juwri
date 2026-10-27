<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * The public order-track GET is polled by every storefront theme (a 5s status
 * poller + a 15s prep-ETA poller = ~16 req/min per open tab). It was the only
 * member of the order/track/{token} family with no throttle. These tests pin the
 * `public-poll` limiter: a normal poll burst is never touched, but an extreme
 * burst (token enumeration / hammering) is eventually blocked.
 *
 * NOTE: this suite must NOT call withoutMiddleware() — that would strip the very
 * throttle it is asserting.
 */
class OrderTrackThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        // Only the JSON poll path is exercised (it returns before any view), but
        // share a minimal settings object anyway so group middleware/composers
        // never trip. Disable ONLY the three non-throttle middleware — leaving
        // the throttle in place is the whole point of this suite.
        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function order(): Order
    {
        $owner = User::factory()->create();
        $store = Store::create([
            'user_id' => $owner->id,
            'title' => 'Pizza Paradise',
            'slug' => 'pizza-'.Str::lower(Str::random(6)),
            'color' => '#ff5500',
            'sub_title' => 'T',
            'description' => 'T',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 Test Street',
            'phone' => '1234567890',
        ]);

        return Order::create([
            'store_id' => $store->id,
            'customer_name' => 'John Doe',
            'status' => 'pending',
            'track_token' => Str::random(32),
        ]);
    }

    /**
     * A normal-to-heavy poll burst (well above the ~16 req/min a single tab
     * generates, allowing for a couple of open tabs) must all succeed — the
     * throttle may never 429 a legitimate poller.
     */
    public function test_normal_poll_burst_is_not_throttled(): void
    {
        $order = $this->order();

        for ($i = 0; $i < 30; $i++) {
            $this->getJson(route('store.orderTrack', $order->track_token))
                ->assertStatus(200);
        }
    }

    /**
     * An extreme burst against a single token eventually trips the limiter — the
     * abuse surface the missing throttle left open is now closed.
     */
    public function test_extreme_burst_eventually_returns_429(): void
    {
        $order = $this->order();

        $sawTooMany = false;
        $firstThirtyAllOk = true;

        for ($i = 0; $i < 200; $i++) {
            $status = $this->getJson(route('store.orderTrack', $order->track_token))->getStatusCode();

            if ($i < 30 && $status !== 200) {
                $firstThirtyAllOk = false;
            }
            if ($status === 429) {
                $sawTooMany = true;
                break;
            }
        }

        $this->assertTrue($firstThirtyAllOk, 'A normal 30-request poll burst must never be throttled.');
        $this->assertTrue($sawTooMany, 'An extreme burst against the track token should eventually return 429.');
    }

    /**
     * Per-token keying: one hammered token being throttled must NOT starve a
     * different order polled from the same IP (the shared venue-wifi case). Kept
     * comfortably under the per-IP backstop so only the per-token bucket is in
     * play here.
     */
    public function test_throttle_is_keyed_per_token_not_only_per_ip(): void
    {
        $a = $this->order();
        $b = $this->order();

        // Burn token A past its per-minute budget so it starts 429ing.
        $aThrottled = false;
        for ($i = 0; $i < 80; $i++) {
            if ($this->getJson(route('store.orderTrack', $a->track_token))->getStatusCode() === 429) {
                $aThrottled = true;
                break;
            }
        }
        $this->assertTrue($aThrottled, 'Token A should throttle after a sustained burst.');

        // Token B, same IP, still polls fine on its own bucket.
        for ($i = 0; $i < 20; $i++) {
            $this->getJson(route('store.orderTrack', $b->track_token))
                ->assertStatus(200);
        }
    }
}
