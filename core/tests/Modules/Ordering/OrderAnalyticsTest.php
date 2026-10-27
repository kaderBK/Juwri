<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class OrderAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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

    public function test_merchant_can_view_order_analytics()
    {
        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);

        // Create scan log
        StoreView::create([
            'store_id' => $store->id,
            'ip' => '192.168.1.1',
            'date' => now()->format('Y-m-d H:i:s'),
        ]);
        StoreView::create([
            'store_id' => $store->id,
            'ip' => '192.168.1.2',
            'date' => now()->format('Y-m-d H:i:s'),
        ]);

        // Create menu item
        $menuItem = Menu::create([
            'name' => 'Pepperoni Pizza',
            'price' => 15.00,
        ]);

        // Create completed order
        $order = Order::create([
            'store_id' => $store->id,
            'customer_name' => 'John Doe',
            'status' => 'completed',
            'is_paid' => true,
            'created_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $menuItem->id,
            'quantity' => 2,
        ]);

        $this->actingAs($merchant);

        $response = $this->get(route('analytics.index'));
        $response->assertStatus(200);

        // Revenue should be 30.00 (15.00 * 2)
        // AOV should be 30.00
        // Conversion rate should be 50% (1 order / 2 scans)
        $response->assertSee('Revenue');
        $response->assertSee('AOV');
        $response->assertSee('Orders');
        $response->assertSee('Conversion');

        $response->assertSee('30'); // Revenue / AOV
        $response->assertSee('1'); // Order Count
        $response->assertSee('50%'); // Conversion
    }

    public function test_merchant_cannot_see_other_merchants_orders_analytics()
    {
        $merchant1 = User::factory()->create(['status' => 1]);
        $restaurant1 = $this->createTestRestaurant($merchant1);

        $merchant2 = User::factory()->create(['status' => 1]);
        $restaurant2 = $this->createTestRestaurant($merchant2);

        $menuItem = Menu::create([
            'name' => 'Burger',
            'price' => 10.00,
        ]);

        // Order for merchant 2
        $order = Order::create([
            'store_id' => $restaurant2->id,
            'customer_name' => 'Secret Buyer',
            'status' => 'completed',
            'is_paid' => true,
            'created_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $menuItem->id,
            'quantity' => 3,
        ]);

        // Request as Merchant 1
        $this->actingAs($merchant1);
        $response = $this->get(route('analytics.index'));
        $response->assertStatus(200);

        // Merchant 1 should see 0 revenue
        $response->assertSee('0');
    }

    public function test_order_analytics_shows_previous_period_trends_for_aov(): void
    {
        // Freeze to a mid-month, midday instant so period-window boundaries are
        // deterministic regardless of the CI timezone.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12, 0, 0));

        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);

        StoreView::create([
            'store_id' => $store->id,
            'ip' => '192.168.1.1',
            'date' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);
        StoreView::create([
            'store_id' => $store->id,
            'ip' => '192.168.1.2',
            'date' => now()->subDays(2)->format('Y-m-d H:i:s'),
        ]);
        StoreView::create([
            'store_id' => $store->id,
            'ip' => '192.168.1.3',
            'date' => now()->subDays(8)->format('Y-m-d H:i:s'),
        ]);

        $menuItem = Menu::create([
            'store_id' => $store->id,
            'name' => 'Pepperoni Pizza',
            'price' => 10.00,
            'active' => '1',
        ]);

        $previousOrder = Order::create([
            'store_id' => $store->id,
            'customer_name' => 'Old Buyer',
            'status' => 'completed',
            'is_paid' => true,
            'created_at' => now()->subDays(8),
        ]);
        OrderItem::create([
            'order_id' => $previousOrder->id,
            'item_id' => $menuItem->id,
            'quantity' => 1,
        ]);

        $currentOrderA = Order::create([
            'store_id' => $store->id,
            'customer_name' => 'New Buyer A',
            'status' => 'completed',
            'is_paid' => true,
            'created_at' => now()->subDay(),
        ]);
        OrderItem::create([
            'order_id' => $currentOrderA->id,
            'item_id' => $menuItem->id,
            'quantity' => 1,
        ]);

        $currentOrderB = Order::create([
            'store_id' => $store->id,
            'customer_name' => 'New Buyer B',
            'status' => 'completed',
            'is_paid' => true,
            'created_at' => now()->subDays(2),
        ]);
        OrderItem::create([
            'order_id' => $currentOrderB->id,
            'item_id' => $menuItem->id,
            'quantity' => 2,
        ]);

        $this->actingAs($merchant);

        $response = $this->get(route('analytics.index', ['period' => '7d']));
        $response->assertOk();
        $response->assertSee('AOV');
        $this->assertMatchesRegularExpression('/\+50(?:\.0)?%/', $response->getContent());
    }
}
