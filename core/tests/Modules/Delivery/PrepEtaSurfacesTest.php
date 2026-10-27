<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\Agents\AgentToolsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AbuseRateLimit;
use Juwri\Core\Http\Middleware\BranchRateLimit;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Orders\EtaEstimator;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * ZERO-TOUCH Track C — prep-time ETA on every surface (C2.x / C3.1):
 * tracking JSON + Blade view, checkout (submitOrder) response, staff API
 * (FormatsOrders), agent createOrder eta_range, KDS p50 target, and the
 * owner prep_eta_static setting (validation + persistence).
 */
class PrepEtaSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private const FROZEN = '2026-07-16 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Carbon::setTestNow(Carbon::parse(self::FROZEN));
        Cache::flush();

        $settings = new class(['theme_color' => '#111111', 'site_title' => 'juwri', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'store_order_whatsapp_message' => 'msg'])
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

        $freePlan = TestPlans::create([
            'name' => 'Free '.Str::random(4), 'description' => 'free', 'monthly_price' => 0,
            'annual_price' => 0, 'lifetime_price' => 0,
            'settings' => ['allow_viewonly' => '1', 'allow_pro' => '1'], 'status' => 1,
        ]);
        config(['settings.free_membership_plan' => $freePlan]);

        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect(), 'plan' => $freePlan,
        ]);

        // Targeted (NOT blanket): keep StartSession + api token auth alive.
        $this->withoutMiddleware([
            PreventRequestForgery::class,
            ThrottleRequests::class,
            AbuseRateLimit::class,
            AbuseHoldWall::class,
            BranchRateLimit::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            LaravelLocalizationRedirectFilter::class,
            Installed::class,
            AuthenticateSession::class,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function storeFixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => [
                'restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999,
                'allow_ordering' => '1', 'hide_branding' => '1', 'ai_chatbot' => '1',
            ],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Pizza Paradise', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);

        return compact('owner', 'r', 'menu');
    }

    /** Non-overlapping 20-minute prep history (idle load → ×0.8 → "15–20"). */
    private function seedFlatHistory(Store $r, Menu $menu, int $count = 20): void
    {
        for ($i = 0; $i < $count; $i++) {
            $preparing = Carbon::parse(self::FROZEN)->subMinutes(120 + $i * 60);
            $order = Order::create([
                'store_id' => $r->id, 'status' => 'completed', 'type' => 'takeaway',
                'customer_name' => 'H', 'track_token' => Str::random(32),
                'created_at' => $preparing->copy()->subMinutes(2),
                'preparing_at' => $preparing,
                'ready_at' => $preparing->copy()->addMinutes(20),
                'completed_at' => $preparing->copy()->addMinutes(21),
            ]);
            OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1]);
        }
    }

    private function pendingOrder(Store $r, ?Menu $menu = null, string $status = 'pending'): Order
    {
        $order = Order::create([
            'store_id' => $r->id, 'status' => $status, 'type' => 'takeaway',
            'customer_name' => 'John Doe', 'track_token' => Str::random(32), 'created_at' => now(),
        ]);
        if ($menu) {
            OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1]);
        }

        return $order;
    }

    private function submitOrderPayload(Menu $menu): array
    {
        return [
            'items' => json_encode([['id' => $menu->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'tip_amount' => '0', 'name' => 'Jane', 'ordering-type' => 'takeaway',
            'phone-number' => '555-1', 'pay_via' => 'cod',
        ];
    }

    private function apiToken(User $user, array $abilities): string
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 'T', 'token' => hash('sha256', $raw), 'abilities' => $abilities]);

        return $raw;
    }

    private function baseUpdatePayload(Store $r): array
    {
        return [
            'color' => '#ff5500', 'title' => $r->title, 'description' => $r->description,
            'address' => $r->address, 'store_template' => 'aurora',
            'currency_code' => 'USD', 'currency_sign' => '$', 'currency_pos' => '1',
        ];
    }

    // ---------------------------------------------------- tracking page (C2.1)

    public function test_track_json_carries_heuristic_eta_when_estimable(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->seedFlatHistory($r, $menu);
        $order = $this->pendingOrder($r, $menu);

        $res = $this->getJson(route('store.orderTrack', $order->track_token))->assertOk();
        $res->assertJsonPath('prep_eta.source', 'heuristic');
        $res->assertJsonPath('prep_eta.p25', 15);
        $res->assertJsonPath('prep_eta.p75', 20);
        $res->assertJsonPath('prep_eta.text', '15–20');
        $this->assertStringContainsString('15–20', $res->json('prep_eta.line'));
    }

    public function test_track_json_uses_static_fallback_without_history(): void
    {
        ['r' => $r] = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'prep_eta_static', '25-35');
        $order = $this->pendingOrder($r);

        $res = $this->getJson(route('store.orderTrack', $order->track_token))->assertOk();
        $res->assertJsonPath('prep_eta.source', 'static');
        $res->assertJsonPath('prep_eta.p25', 25);
        $res->assertJsonPath('prep_eta.p75', 35);
    }

    public function test_track_json_has_null_eta_when_neither_source_exists(): void
    {
        ['r' => $r] = $this->storeFixture();
        $order = $this->pendingOrder($r);

        $this->getJson(route('store.orderTrack', $order->track_token))
            ->assertOk()
            ->assertJsonPath('prep_eta', null)
            ->assertJsonPath('status', 'pending');
    }

    public function test_track_json_hides_eta_once_ready_and_after(): void
    {
        ['r' => $r] = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'prep_eta_static', '25-35');
        $order = $this->pendingOrder($r);

        $this->getJson(route('store.orderTrack', $order->track_token))
            ->assertJsonPath('prep_eta.p25', 25);

        foreach (['ready' => 'ready_at', 'completed' => 'completed_at', 'cancelled' => 'cancelled_at'] as $status => $stamp) {
            $order->status = $status;
            $order->{$stamp} = now();
            $order->save();

            $this->getJson(route('store.orderTrack', $order->track_token))
                ->assertOk()
                ->assertJsonPath('prep_eta', null);
        }
    }

    public function test_track_page_renders_eta_line_and_hides_it_after_ready(): void
    {
        ['r' => $r] = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'prep_eta_static', '25-35');
        $order = $this->pendingOrder($r);

        // aurora fallback view (no store_template option set)
        $page = $this->get(route('store.orderTrack', $order->track_token))->assertOk();
        $page->assertSee('prepEtaLine');
        $page->assertSee('Estimated ready in about 25–35 min');

        // per-theme view (crave) includes the same shared partial
        StoreOption::updateStoreOption($r->id, 'store_template', 'crave');
        $this->get(route('store.orderTrack', $order->track_token))
            ->assertOk()
            ->assertSee('Estimated ready in about 25–35 min');

        // once ready: line renders empty + hidden (JS keeps it hidden onward)
        $order->status = 'ready';
        $order->ready_at = now();
        $order->save();

        $page = $this->get(route('store.orderTrack', $order->track_token))->assertOk();
        $page->assertDontSee('Estimated ready in about');
        $page->assertSee('prepEtaLine'); // element present (hidden) for the poll
    }

    // ------------------------------------------- checkout + agent seam (C2.2)

    public function test_send_order_response_gains_prep_eta_additively(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'prep_eta_static', '25-35');
        StoreOption::updateStoreOption($r->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($r->id, 'quickorder_enable', 0);

        $res = $this->post(route('store.submitOrder', $r->id), $this->submitOrderPayload($menu))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNotEmpty($res->json('track_url'), 'existing keys must be untouched');
        $this->assertSame('25–35', $res->json('prep_eta.text'));
        $this->assertSame('static', $res->json('prep_eta.source'));
    }

    public function test_send_order_prep_eta_null_when_no_estimate(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($r->id, 'quickorder_enable', 0);

        $res = $this->post(route('store.submitOrder', $r->id), $this->submitOrderPayload($menu))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue(array_key_exists('prep_eta', $res->json()), 'key must exist');
        $this->assertNull($res->json('prep_eta'));
    }

    public function test_agent_create_order_gains_nullable_eta_range(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'prep_eta_static', '20-30');

        $res = AgentToolsService::createOrder($r, [['item_id' => $menu->id, 'quantity' => 1]], ['type' => 'takeaway']);

        $this->assertTrue($res['ok']);
        // existing contract untouched
        $this->assertNotEmpty($res['order_id']);
        $this->assertNotEmpty($res['track_token']);
        $this->assertNotEmpty($res['continue_url']);
        // additive seam (C→A)
        $this->assertSame('20-30', $res['eta_range']);
    }

    public function test_agent_create_order_eta_range_null_without_estimate(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();

        $res = AgentToolsService::createOrder($r, [['item_id' => $menu->id, 'quantity' => 1]], ['type' => 'takeaway']);

        $this->assertTrue($res['ok']);
        $this->assertArrayHasKey('eta_range', $res);
        $this->assertNull($res['eta_range']);
    }

    // ------------------------------------------------------ staff API (C2.3)

    public function test_staff_api_order_detail_includes_prep_eta(): void
    {
        ['owner' => $owner, 'r' => $r, 'menu' => $menu] = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'prep_eta_static', '25-35');
        $order = $this->pendingOrder($r, $menu);
        $token = $this->apiToken($owner, ['orders:read']);

        $this->withToken($token)
            ->getJson(route('api.v1.orders.show', [$r->id, $order->id]))
            ->assertOk()
            ->assertJsonPath('prep_eta', '25-35');

        $order->status = 'ready';
        $order->ready_at = now();
        $order->save();

        $this->withToken($token)
            ->getJson(route('api.v1.orders.show', [$r->id, $order->id]))
            ->assertOk()
            ->assertJsonPath('prep_eta', null);
    }

    // ------------------------------------------------------------ KDS (C2.4)

    public function test_kds_poll_carries_p50_target_hint(): void
    {
        ['owner' => $owner, 'r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->seedFlatHistory($r, $menu); // p50 = 20
        $this->pendingOrder($r, $menu, 'preparing');

        $res = $this->actingAs($owner)
            ->getJson(route('kds.poll', ['store_id' => $r->id]))
            ->assertOk();

        $this->assertSame(20, $res->json('0.prep_target_min'));
    }

    // --------------------------------------------------- owner setting (C1.2)

    public function test_owner_setting_saves_validates_and_clears(): void
    {
        ['owner' => $owner, 'r' => $r] = $this->storeFixture();

        // valid → saved
        $this->actingAs($owner)
            ->put(route('stores.update', $r), array_merge($this->baseUpdatePayload($r), [
                'prep_eta_static' => '25-35',
            ]))
            ->assertRedirect();
        $this->assertSame('25-35', StoreOption::getStoreOption($r->id, 'prep_eta_static'));

        // invalid format → rejected (update() flashes an alert + back()), the
        // stored value stays untouched — the behavioral contract that matters.
        $this->actingAs($owner)
            ->put(route('stores.update', $r), array_merge($this->baseUpdatePayload($r), [
                'prep_eta_static' => 'banana',
            ]))
            ->assertRedirect();
        $this->assertSame('25-35', StoreOption::getStoreOption($r->id, 'prep_eta_static'));

        // cleared → estimator falls back to nothing
        $this->actingAs($owner)
            ->put(route('stores.update', $r), array_merge($this->baseUpdatePayload($r), [
                'prep_eta_static' => '',
            ]))
            ->assertRedirect();
        $this->assertEmpty(StoreOption::getStoreOption($r->id, 'prep_eta_static'));
        $this->assertNull(EtaEstimator::estimate($r));
    }
}
