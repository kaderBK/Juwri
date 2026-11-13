<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D2 — server-side orders search/filter + permalink. The search box was a
 * client-side filter over the 50 loaded rows; these params narrow the DB query
 * so a historical order is findable, tenant-scoped, and survives pagination.
 */
class OrdersSearchFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $settings = new class(['currency_sign' => '$', 'currency_pos' => 1, 'currency_code' => 'USD', 'theme_color' => '#111', 'site_title' => 'X'])
        {
            public function __construct(private array $d) {}

            public function __get($k)
            {
                return $this->d[$k] ?? null;
            }

            public function __isset($k)
            {
                return isset($this->d[$k]);
            }
        };
        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'admin_languages' => collect(),
        ]);
        $this->withoutMiddleware();
    }

    private function restaurantWithMenu(User $owner): array
    {
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);

        return [$r, $menu];
    }

    private function order(Store $r, Menu $menu, string $name, string $phone, string $status, Carbon $at): Order
    {
        $o = Order::create([
            'store_id' => $r->id, 'type' => 'takeaway', 'customer_name' => $name,
            'phone_number' => $phone, 'status' => $status, 'seen' => 0, 'created_at' => $at,
        ]);
        OrderItem::create(['order_id' => $o->id, 'item_id' => $menu->id, 'quantity' => 1]);

        return $o;
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999, 'allow_ordering' => '1'],
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    public function test_search_finds_a_historical_order_by_name_phone_or_id(): void
    {
        $owner = $this->owner();
        [$r, $menu] = $this->restaurantWithMenu($owner);
        $alice = $this->order($r, $menu, 'Alice', '555-1111', 'pending', now());
        $bob = $this->order($r, $menu, 'Bob', '555-2222', 'completed', now()->subDays(7));

        // By name.
        $ids = $this->actingAs($owner)->get(route('stores.orders', [$r->id, 'q' => 'Alice']))
            ->assertOk()->viewData('orders')->pluck('id')->all();
        $this->assertContains($alice->id, $ids);
        $this->assertNotContains($bob->id, $ids);

        // By phone.
        $ids = $this->actingAs($owner)->get(route('stores.orders', [$r->id, 'q' => '555-2222']))
            ->viewData('orders')->pluck('id')->all();
        $this->assertSame([$bob->id], $ids);

        // By id.
        $ids = $this->actingAs($owner)->get(route('stores.orders', [$r->id, 'q' => (string) $alice->id]))
            ->viewData('orders')->pluck('id')->all();
        $this->assertContains($alice->id, $ids);
    }

    public function test_status_and_date_filters_narrow_results(): void
    {
        $owner = $this->owner();
        [$r, $menu] = $this->restaurantWithMenu($owner);
        $this->order($r, $menu, 'Alice', '555-1', 'pending', now());
        $bob = $this->order($r, $menu, 'Bob', '555-2', 'completed', Carbon::parse('2026-07-03'));

        $ids = $this->actingAs($owner)->get(route('stores.orders', [$r->id, 'status' => 'completed']))
            ->viewData('orders')->pluck('id')->all();
        $this->assertSame([$bob->id], $ids);

        $ids = $this->actingAs($owner)->get(route('stores.orders', [$r->id, 'from' => '2026-07-01', 'to' => '2026-07-05']))
            ->viewData('orders')->pluck('id')->all();
        $this->assertSame([$bob->id], $ids);
    }

    public function test_focus_order_id_is_passed_and_filters_are_tenant_scoped(): void
    {
        $owner = $this->owner();
        [$r, $menu] = $this->restaurantWithMenu($owner);
        $mine = $this->order($r, $menu, 'Mine', '555-9', 'pending', now());

        // Another owner's order must never leak into my search.
        $other = $this->owner();
        [$r2, $menu2] = $this->restaurantWithMenu($other);
        $this->order($r2, $menu2, 'Mine', '555-9', 'pending', now());

        $resp = $this->actingAs($owner)->get(route('stores.orders', [$r->id, 'q' => 'Mine', 'order' => $mine->id]))->assertOk();
        $ids = $resp->viewData('orders')->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
        $this->assertSame($mine->id, $resp->viewData('focusOrderId'));
    }
}
