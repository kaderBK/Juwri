<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AbuseRateLimit;
use Juwri\Core\Http\Middleware\BranchRateLimit;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Kitchen\Services\OrderBoard\DailyTokenService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T2-3/T2-4: the owner's Order board settings tab, and the customer
 * tie-in — "your number is N" on the checkout response and the tracking page
 * every confirmation funnels into.
 */
class OrderBoardSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_order_board' => true]);
        DailyTokenService::clearMemo();
        FeatureAccess::flush();

        // Dashboard + storefront blades read the global $settings blob.
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
        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect(),
        ]);

        $this->withoutMiddleware([
            PreventRequestForgery::class,
            ThrottleRequests::class,
            AbuseRateLimit::class,
            AbuseHoldWall::class,
            BranchRateLimit::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            Installed::class,
        ]);
    }

    /** @return array{owner: User, r: Store, menu: Menu} */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'allow_ordering' => '1'],
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

    private function baseUpdatePayload(Store $r): array
    {
        return [
            'color' => '#ff5500', 'title' => $r->title, 'description' => $r->description,
            'address' => $r->address, 'store_template' => 'aurora',
            'currency_code' => 'USD', 'currency_sign' => '$', 'currency_pos' => '1',
        ];
    }

    private function checkoutPayload(Menu $menu): array
    {
        return [
            'items' => json_encode([['id' => $menu->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'tip_amount' => '0', 'name' => 'Jane', 'ordering-type' => 'takeaway',
            'phone-number' => '555-1', 'pay_via' => 'cod',
        ];
    }

    public function test_owner_sees_the_board_tab_with_its_link(): void
    {
        ['owner' => $owner, 'r' => $r] = $this->store();

        $res = $this->actingAs($owner)->get(route('stores.edit', $r->id))->assertOk();

        $res->assertSee('Order board');
        $res->assertSeeHtml('order_board_enabled');
        $res->assertSeeHtml('order_board_type_takeaway');
        $res->assertSeeHtml(route('board.show', $r->slug));
    }

    public function test_the_tab_disappears_when_the_feature_is_killed_without_losing_the_settings(): void
    {
        ['owner' => $owner, 'r' => $r] = $this->store();
        StoreOption::updateStoreOption($r->id, 'order_board_enabled', '1');
        StoreOption::updateStoreOption($r->id, 'order_board_ready_minutes', '9');

        config(['settings.admin_allow_order_board' => false]);
        FeatureAccess::flush();

        $res = $this->actingAs($owner)->get(route('stores.edit', $r->id))->assertOk();
        $res->assertDontSeeHtml("tab === 'board'");       // the tab is gone…
        $res->assertSeeHtml('order_board_ready_minutes'); // …but a hidden input still carries the value

        // A save from that page must not blank what the owner configured.
        $this->actingAs($owner)
            ->put(route('stores.update', $r), array_merge($this->baseUpdatePayload($r), [
                'order_board_enabled' => '1', 'order_board_ready_minutes' => '9',
            ]))
            ->assertRedirect();

        $this->assertSame('1', (string) StoreOption::getStoreOption($r->id, 'order_board_enabled'));
        $this->assertSame('9', (string) StoreOption::getStoreOption($r->id, 'order_board_ready_minutes'));
    }

    public function test_saving_the_tab_persists_every_board_option(): void
    {
        ['owner' => $owner, 'r' => $r] = $this->store();

        $this->actingAs($owner)
            ->put(route('stores.update', $r), array_merge($this->baseUpdatePayload($r), [
                'order_board_enabled' => '1',
                'order_board_ready_minutes' => '12',
                'order_board_chime' => '0',
                'order_board_type_takeaway' => '1',
                'order_board_type_on_table' => '0',
                'order_board_type_delivery' => '1',
                'order_board_type_room_service' => '0',
            ]))
            ->assertRedirect();

        $this->assertSame('1', (string) StoreOption::getStoreOption($r->id, 'order_board_enabled'));
        $this->assertSame('12', (string) StoreOption::getStoreOption($r->id, 'order_board_ready_minutes'));
        $this->assertSame('0', (string) StoreOption::getStoreOption($r->id, 'order_board_chime'));
        $this->assertTrue(DailyTokenService::typeEnabled((int) $r->id, 'delivery'));
        $this->assertFalse(DailyTokenService::typeEnabled((int) $r->id, 'on-table'));
    }

    public function test_checkout_response_carries_the_number_and_the_board_link(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->store();
        StoreOption::updateStoreOption($r->id, 'order_board_enabled', '1');
        StoreOption::updateStoreOption($r->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($r->id, 'quickorder_enable', 0);

        $res = $this->post(route('store.submitOrder', $r->id), $this->checkoutPayload($menu))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNotEmpty($res->json('track_url'), 'existing keys must be untouched');
        $this->assertSame(1, $res->json('token_number'));
        $this->assertSame(route('board.show', $r->slug), $res->json('board_url'));
    }

    public function test_checkout_response_keeps_the_keys_null_without_a_board(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->store();
        StoreOption::updateStoreOption($r->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($r->id, 'quickorder_enable', 0);

        $res = $this->post(route('store.submitOrder', $r->id), $this->checkoutPayload($menu))
            ->assertOk();

        $this->assertTrue(array_key_exists('token_number', $res->json()));
        $this->assertNull($res->json('token_number'));
        $this->assertNull($res->json('board_url'));
    }

    public function test_tracking_page_and_json_show_the_customer_their_number(): void
    {
        ['r' => $r] = $this->store();
        StoreOption::updateStoreOption($r->id, 'order_board_enabled', '1');

        $order = Order::create([
            'store_id' => $r->id, 'status' => 'preparing', 'type' => 'takeaway',
            'customer_name' => 'Jane', 'track_token' => Str::random(32), 'created_at' => now(),
        ]);
        $token = (int) $order->fresh()->token_number;
        $this->assertSame(1, $token);

        $this->getJson(route('store.orderTrack', $order->track_token))
            ->assertOk()
            ->assertJsonPath('token_number', 1);

        $page = $this->get(route('store.orderTrack', $order->track_token))->assertOk();
        $page->assertSee('Your number');
        $page->assertSeeHtml(route('board.show', $r->slug));
    }

    public function test_tracking_page_says_nothing_when_there_is_no_number(): void
    {
        ['r' => $r] = $this->store();

        $order = Order::create([
            'store_id' => $r->id, 'status' => 'preparing', 'type' => 'takeaway',
            'customer_name' => 'Jane', 'track_token' => Str::random(32), 'created_at' => now(),
        ]);

        $this->get(route('store.orderTrack', $order->track_token))
            ->assertOk()
            ->assertDontSee('Your number');
    }

    public function test_kds_tickets_expose_the_token_number(): void
    {
        ['owner' => $owner, 'r' => $r] = $this->store();
        StoreOption::updateStoreOption($r->id, 'order_board_enabled', '1');
        $order = Order::create([
            'store_id' => $r->id, 'status' => 'preparing', 'type' => 'takeaway',
            'customer_name' => 'Jane', 'track_token' => Str::random(32), 'created_at' => now(),
        ]);

        $res = $this->actingAs($owner)->getJson(route('kds.poll').'?store_id='.$r->id)->assertOk();

        $ticket = collect($res->json())->firstWhere('id', $order->id);
        $this->assertNotNull($ticket, 'the order should be on the KDS');
        $this->assertSame((int) $order->fresh()->token_number, $ticket['token_number']);
    }
}
