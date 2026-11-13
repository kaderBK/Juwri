<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-346 + P6-347 — the storefront checkout's store-level "is this store taking orders
 * right now?" gate.
 *
 * P6-346: the saved store option `prevent_order_on_close` ("Prevent orders when closed",
 * saved by StoreController/StoreSettingsService/StoreSettingKeys) was read nowhere. The
 * shared helper store_accepts_orders_now() now honours it: when it is explicitly OFF
 * ('0' / 0 / false) the opening-hours check is SKIPPED, but the manual open/close
 * switch (open_close_store='0') and a paused branch (accepting_orders=false) still
 * block. A MISSING value keeps today's behaviour (the check runs).
 *
 * P6-347: store_open_now picked the timezone from the first active branch with hours
 * — when the week came from the free-text `timing`, the first such branch was the
 * auto-created "Main" (no hours, no timezone) and the helper fell back to the app tz,
 * which is wrong for a KSA store. The shared helper now uses StoreClock::tz($store->id)
 * for the free-text path, and uses StoreStatus::for-style logic (open if ANY active
 * branch is open + accepting) for the structured-hours path.
 *
 * The test exercises the helper through the actual storefront checkout route
 * (route('store.submitOrder')) so the message text and the scheduled_at exemption
 * stay in lockstep with the controller.
 */
class WebCheckoutClosedStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->shareLayoutGlobals();
        Carbon::setTestNow(); // every test resets; each one re-freezes as it likes
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Store, 1: Menu} */
    private function makeStore(array $overrides = [], array $opts = []): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(array_merge([
            'user_id' => $owner->id, 'slug' => 's-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'S', 'sub_title' => 't', 'description' => 'd',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ], $overrides));

        foreach ($opts as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C']);
        $menu = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Item', 'description' => 'd', 'price' => 10,
            'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);

        return [$store, $menu];
    }

    private function payload(Menu $menu): array
    {
        return [
            'items' => json_encode([['id' => $menu->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'tip_amount' => '0', 'name' => 'Guest', 'ordering-type' => 'takeaway',
            'phone-number' => '555-1', 'pay_via' => 'cod',
        ];
    }

    private function postCheckout(Store $store, array $payload)
    {
        return $this->post(route('store.submitOrder', $store), $payload);
    }

    /** (a) prevent_order_on_close = '0': opening-hours check is skipped. */
    public function test_setting_off_accepts_a_store_that_is_outside_its_hours(): void
    {
        // The store's free-text hours say 09:00-18:00, but we order at 22:00.
        Carbon::setTestNow(Carbon::parse('2026-10-19 22:00', 'UTC'));
        [$store, $menu] = $this->makeStore(
            ['timing' => '09:00-18:00', 'address' => '1 St', 'phone' => '123'],
            ['timezone' => 'UTC', 'prevent_order_on_close' => '0']
        );

        $res = $this->postCheckout($store, $this->payload($menu));

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, Order::where('store_id', $store->id)->count(), 'order placed');
    }

    /** (a) prevent_order_on_close = '1' (or missing/default): opening-hours check still runs. */
    public function test_setting_on_refuses_a_store_that_is_outside_its_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 22:00', 'UTC'));
        [$store, $menu] = $this->makeStore(
            ['timing' => '09:00-18:00', 'address' => '1 St', 'phone' => '123'],
            ['timezone' => 'UTC', 'prevent_order_on_close' => '1']
        );

        $res = $this->postCheckout($store, $this->payload($menu));

        $res->assertOk()->assertJson(['success' => false]);
        $this->assertStringContainsString('closed', strtolower((string) $res->json('message')));
        $this->assertSame(0, Order::where('store_id', $store->id)->count(), 'no order');
    }

    /** (a) prevent_order_on_close = '0' + open_close_store = '0': manual switch still blocks. */
    public function test_setting_off_but_manual_switch_off_still_refuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 22:00', 'UTC'));
        [$store, $menu] = $this->makeStore(
            ['timing' => '09:00-18:00', 'address' => '1 St', 'phone' => '123'],
            ['timezone' => 'UTC', 'prevent_order_on_close' => '0', 'open_close_store' => '0']
        );

        $res = $this->postCheckout($store, $this->payload($menu));

        $res->assertOk()->assertJson(['success' => false]);
        $this->assertStringContainsString('closed', strtolower((string) $res->json('message')));
        $this->assertSame(0, Order::where('store_id', $store->id)->count(), 'no order');
    }

    /**
     * (b) free-text timing evaluated in the STORE timezone (StoreClock::tz), not the
     * app timezone. Asia/Riyadh store with timing "10:00-23:00" — at 00:30 Riyadh
     * (= 21:30 UTC the previous day) it is closed, at 22:00 Riyadh it is open.
     */
    public function test_free_text_timing_uses_store_timezone_at_00_30_riyadh(): void
    {
        // 00:30 Asia/Riyadh (UTC+3) = 21:30 UTC the previous day.
        Carbon::setTestNow(Carbon::parse('2026-10-18 21:30', 'UTC'));
        [$store, $menu] = $this->makeStore(
            ['timing' => '10:00-23:00'],
            ['timezone' => 'Asia/Riyadh']
        );

        $res = $this->postCheckout($store, $this->payload($menu));

        $res->assertOk()->assertJson(['success' => false]);
        $this->assertStringContainsString('closed', strtolower((string) $res->json('message')));
        $this->assertSame(0, Order::where('store_id', $store->id)->count(), 'closed at 00:30 Riyadh');
    }

    public function test_free_text_timing_uses_store_timezone_at_22_00_riyadh(): void
    {
        // 22:00 Asia/Riyadh (UTC+3) = 19:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-19 19:00', 'UTC'));
        [$store, $menu] = $this->makeStore(
            ['timing' => '10:00-23:00'],
            ['timezone' => 'Asia/Riyadh']
        );

        $res = $this->postCheckout($store, $this->payload($menu));

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, Order::where('store_id', $store->id)->count(), 'open at 22:00 Riyadh');
    }

    /**
     * (c) two branches with structured hours — first closed, second open.
     * The shared helper is open iff ANY active branch is open + accepting
     * (StoreStatus::for-style), so the order goes through.
     */
    public function test_two_structured_branches_one_open_one_closed_accepts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 12:00', 'UTC')); // 12:00 UTC, every day
        [$store, $menu] = $this->makeStore([], ['timezone' => 'UTC']);

        // Branch 1 — closed at 12:00 (hours 22:00-23:00 every day, closed elsewhere).
        Branch::create([
            'store_id' => $store->id, 'name' => 'Late', 'active' => true,
            'accepting_orders' => true, 'timezone' => 'UTC', 'position' => 1,
            'hours' => [
                'mon' => ['open' => '22:00', 'close' => '23:00', 'closed' => false],
                'tue' => ['open' => '22:00', 'close' => '23:00', 'closed' => false],
                'wed' => ['open' => '22:00', 'close' => '23:00', 'closed' => false],
                'thu' => ['open' => '22:00', 'close' => '23:00', 'closed' => false],
                'fri' => ['open' => '22:00', 'close' => '23:00', 'closed' => false],
                'sat' => ['open' => '22:00', 'close' => '23:00', 'closed' => false],
                'sun' => ['open' => '22:00', 'close' => '23:00', 'closed' => false],
            ],
        ]);
        // Branch 2 — open at 12:00 (hours 09:00-22:00 every day).
        Branch::create([
            'store_id' => $store->id, 'name' => 'Day', 'active' => true,
            'accepting_orders' => true, 'timezone' => 'UTC', 'position' => 2,
            'hours' => [
                'mon' => ['open' => '09:00', 'close' => '22:00', 'closed' => false],
                'tue' => ['open' => '09:00', 'close' => '22:00', 'closed' => false],
                'wed' => ['open' => '09:00', 'close' => '22:00', 'closed' => false],
                'thu' => ['open' => '09:00', 'close' => '22:00', 'closed' => false],
                'fri' => ['open' => '09:00', 'close' => '22:00', 'closed' => false],
                'sat' => ['open' => '09:00', 'close' => '22:00', 'closed' => false],
                'sun' => ['open' => '09:00', 'close' => '22:00', 'closed' => false],
            ],
        ]);

        $res = $this->postCheckout($store, $this->payload($menu));

        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, Order::where('store_id', $store->id)->count(), 'second branch keeps the store open');
    }
}
