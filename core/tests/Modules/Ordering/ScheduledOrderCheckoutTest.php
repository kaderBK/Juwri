<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Jobs\SendPushNotificationJob;
use Tests\Support\TestPlans;
use Tests\TestCase;

class ScheduledOrderCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $k): mixed
            {
                return $this->data[$k] ?? null;
            }

            public function __set(string $k, mixed $v): void
            {
                $this->data[$k] = $v;
            }

            public function __isset(string $k): bool
            {
                return array_key_exists($k, $this->data);
            }
        };
        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
        ]);
        $this->withoutMiddleware();
    }

    private function storeFixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999, 'allow_ordering' => '1', 'hide_branding' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);

        return compact('owner', 'r', 'menu');
    }

    private function opt(Store $r, array $kv): void
    {
        foreach ($kv as $k => $v) {
            StoreOption::updateStoreOption($r->id, $k, $v);
        }
    }

    private function payload(Menu $menu, array $extra = []): array
    {
        return array_merge([
            'items' => json_encode([['id' => $menu->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'tip_amount' => '0', 'name' => 'Jane', 'ordering-type' => 'takeaway',
            'phone-number' => '555-1', 'pay_via' => 'cod',
        ], $extra);
    }

    public function test_valid_scheduled_time_sets_scheduled_and_fire_at(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->opt($r, [
            'allow_order_scheduling' => '1', 'scheduling_slot_interval' => 30,
            'scheduling_lead_minutes' => 30, 'scheduling_prep_minutes' => 20,
            'store_submit_order_notification' => 0, 'quickorder_enable' => 0, 'timezone' => 'UTC',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00', 'UTC'));
        $when = '2026-08-01 13:00';
        $this->post(route('store.submitOrder', $r), $this->payload($menu, ['scheduled_at' => $when]))
            ->assertOk()->assertJson(['success' => true]);
        Carbon::setTestNow();

        $order = Order::where('store_id', $r->id)->latest('id')->firstOrFail();
        $this->assertSame('2026-08-01 13:00', $order->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('2026-08-01 12:40', $order->fire_at->format('Y-m-d H:i')); // 20m prep
    }

    public function test_invalid_scheduled_time_is_rejected_and_no_order_created(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->opt($r, [
            'allow_order_scheduling' => '1', 'scheduling_slot_interval' => 30,
            'scheduling_lead_minutes' => 60, 'store_submit_order_notification' => 0,
            'quickorder_enable' => 0, 'timezone' => 'UTC',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00', 'UTC'));
        // 12:15 is inside the 60-min lead window → rejected.
        $this->post(route('store.submitOrder', $r), $this->payload($menu, ['scheduled_at' => '2026-08-01 12:15']))
            ->assertOk()->assertJson(['success' => false]);
        Carbon::setTestNow();

        $this->assertSame(0, Order::where('store_id', $r->id)->count());
    }

    public function test_asap_order_still_works_with_scheduling_enabled(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->opt($r, [
            'allow_order_scheduling' => '1', 'store_submit_order_notification' => 0,
            'quickorder_enable' => 0, 'timezone' => 'UTC',
        ]);

        $this->post(route('store.submitOrder', $r), $this->payload($menu)) // no scheduled_at
            ->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $r->id)->latest('id')->firstOrFail();
        $this->assertNull($order->scheduled_at);
        $this->assertNull($order->fire_at);
    }

    public function test_array_scheduled_at_does_not_500_and_is_treated_as_asap(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->opt($r, [
            'allow_order_scheduling' => '1', 'store_submit_order_notification' => 0,
            'quickorder_enable' => 0, 'timezone' => 'UTC',
        ]);

        // A crafted scheduled_at[]=x posts an ARRAY into validate()'s ?string param.
        // It must not raise a TypeError → 500; a non-scalar is meaningless → ASAP.
        $this->post(route('store.submitOrder', $r), $this->payload($menu, ['scheduled_at' => ['x']]))
            ->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $r->id)->latest('id')->firstOrFail();
        $this->assertNull($order->scheduled_at, 'garbage array is ignored → ASAP order');
    }

    public function test_slots_endpoint_returns_slots_when_enabled(): void
    {
        ['r' => $r] = $this->storeFixture();
        $this->opt($r, [
            'allow_order_scheduling' => '1', 'scheduling_slot_interval' => 60,
            'scheduling_lead_minutes' => 0, 'timezone' => 'UTC',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00', 'UTC'));
        $res = $this->getJson(route('store.scheduleSlots', $r).'?type=takeaway&date=2026-08-01')
            ->assertOk()->assertJson(['enabled' => true]);
        Carbon::setTestNow();

        $this->assertNotEmpty($res->json('slots'));
        $this->assertContains('2026-08-01 10:00', array_column($res->json('slots'), 'value'));
    }

    public function test_slots_endpoint_disabled_when_off(): void
    {
        ['r' => $r] = $this->storeFixture();
        $this->getJson(route('store.scheduleSlots', $r).'?type=takeaway')
            ->assertOk()->assertJson(['enabled' => false]);
    }

    public function test_scheduled_order_does_not_push_staff_alert_at_creation(): void
    {
        Queue::fake();
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->opt($r, [
            'allow_order_scheduling' => '1', 'scheduling_slot_interval' => 30,
            'scheduling_lead_minutes' => 30, 'store_submit_order_notification' => 0,
            'quickorder_enable' => 0, 'timezone' => 'UTC',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00', 'UTC'));
        $this->post(route('store.submitOrder', $r), $this->payload($menu, ['scheduled_at' => '2026-08-01 13:00']))
            ->assertOk()->assertJson(['success' => true]);
        Carbon::setTestNow();

        // Scheduled order must NOT alert the kitchen at creation — release tick does that.
        Queue::assertNotPushed(SendPushNotificationJob::class);
    }

    public function test_asap_order_pushes_staff_alert_at_creation(): void
    {
        Queue::fake();
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->opt($r, ['store_submit_order_notification' => 0, 'quickorder_enable' => 0]);

        $this->post(route('store.submitOrder', $r), $this->payload($menu))
            ->assertOk()->assertJson(['success' => true]);

        Queue::assertPushed(SendPushNotificationJob::class);
    }
}
