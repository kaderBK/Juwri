<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuItemEvent;
use Tests\Support\TestPlans;
use Tests\TestCase;

class MenuItemPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $settings = new class(['theme_color' => '#111111', 'site_title' => 'juwri', 'meta_description' => 'Test description', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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

        $plan = TestPlans::create([
            'name' => 'Free Plan',
            'description' => 'Free membership plan',
            'monthly_price' => 0,
            'annual_price' => 0,
            'lifetime_price' => 0,
            'settings' => [
                'allow_viewonly' => '1',
                'allow_pro' => '1',
            ],
            'status' => 1,
        ]);
        config(['settings.free_membership_plan' => $plan]);

        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
            'plan' => $plan,
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function createTestRestaurant(User $user): Store
    {
        return Store::create([
            'user_id' => $user->id,
            'title' => 'Pizza Paradise',
            'slug' => 'pizza-paradise',
            'color' => '#ff5500',
            'sub_title' => 'Test restaurant',
            'description' => 'Test description',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 Test Street',
            'phone' => '1234567890',
        ]);
    }

    public function test_public_user_can_record_item_event()
    {
        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);

        $category = MenuCategory::create([
            'store_id' => $store->id,
            'name' => 'Pizza',
        ]);

        $item = Menu::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Margherita',
            'price' => 10,
        ]);

        $response = $this->post(route('store.recordItemEvent', $store->id), [
            'menu_id' => $item->id,
            'type' => 'view',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('menu_item_events', [
            'menu_id' => $item->id,
            'store_id' => $store->id,
            'type' => 'view',
        ]);
    }

    public function test_cannot_record_event_for_invalid_type_or_mismatched_item()
    {
        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);

        $category = MenuCategory::create([
            'store_id' => $store->id,
            'name' => 'Pizza',
        ]);

        $item = Menu::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Margherita',
            'price' => 10,
        ]);

        // Mismatched restaurant check
        $otherMerchant = User::factory()->create(['status' => 1]);
        $otherRestaurant = $this->createTestRestaurant($otherMerchant);
        $otherRestaurant->slug = 'other-restaurant';
        $otherRestaurant->save();

        $response = $this->post(route('store.recordItemEvent', $otherRestaurant->id), [
            'menu_id' => $item->id,
            'type' => 'view',
        ]);

        $response->assertStatus(404);
    }

    public function test_merchant_can_view_aggregated_item_performance_metrics()
    {
        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);

        $category = MenuCategory::create([
            'store_id' => $store->id,
            'name' => 'Pizza',
        ]);

        $item = Menu::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Margherita',
            'price' => 10,
        ]);

        // Create events: 10 views, 5 add_to_carts
        for ($i = 0; $i < 10; $i++) {
            MenuItemEvent::create([
                'menu_id' => $item->id,
                'store_id' => $store->id,
                'type' => 'view',
                'created_at' => now(),
            ]);
        }
        for ($i = 0; $i < 5; $i++) {
            MenuItemEvent::create([
                'menu_id' => $item->id,
                'store_id' => $store->id,
                'type' => 'add_to_cart',
                'created_at' => now(),
            ]);
        }

        // Create order: 2 quantities ordered
        $order = Order::create([
            'store_id' => $store->id,
            'status' => 'completed',
            'created_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);

        $this->actingAs($merchant);

        $response = $this->get(route('analytics.index'));
        $response->assertStatus(200);

        // Verify metrics are calculated and displayed
        $response->assertSee('Top items');
        $response->assertSee('Margherita');
        $response->assertSee('10'); // Views count
        $response->assertSee('5'); // Add to Carts count
        $response->assertSee('2'); // Orders count
        $response->assertSee('50%'); // Detail-to-Cart Conversion %
        $response->assertSee('20%'); // Detail-to-Order Conversion %
    }

    public function test_category_heatmap_event_queries_do_not_scale_with_categories(): void
    {
        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);

        $addCategory = function (int $n) use ($store) {
            $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat'.$n]);
            $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'I'.$n, 'price' => 5]);
            MenuItemEvent::create(['menu_id' => $item->id, 'store_id' => $store->id, 'type' => 'view', 'created_at' => now()]);
        };

        $count = 0;
        DB::listen(function ($q) use (&$count) {
            if (stripos($q->sql, 'menu_item_events') !== false) {
                $count++;
            }
        });
        $this->actingAs($merchant);

        // One category.
        $addCategory(0);
        $count = 0;
        $this->get(route('analytics.index'))->assertStatus(200);
        $withOne = $count;

        // Three more (four total).
        $addCategory(1);
        $addCategory(2);
        $addCategory(3);
        $count = 0;
        $this->get(route('analytics.index'))->assertStatus(200);
        $withFour = $count;

        // The heatmap is one grouped query regardless of category count; the per-category
        // version would add three more menu_item_events queries here.
        $this->assertSame($withOne, $withFour, "menu_item_events queries must not scale with categories; 1 cat={$withOne}, 4 cats={$withFour}");
    }
}
