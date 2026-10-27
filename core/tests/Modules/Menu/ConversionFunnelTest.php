<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuItemEvent;
use Tests\Support\TestPlans;
use Tests\TestCase;

class ConversionFunnelTest extends TestCase
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
        $this->withoutExceptionHandling();
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

    public function test_merchant_can_view_diner_conversion_funnel()
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

        // Stage 1: 10 Scans
        for ($i = 0; $i < 10; $i++) {
            StoreView::create([
                'store_id' => $store->id,
                'ip' => '192.168.1.'.$i,
                'date' => now()->format('Y-m-d H:i:s'),
                'device' => 'Mobile',
                'lang' => 'en',
            ]);
        }

        // Stage 2: 6 Browse events
        for ($i = 0; $i < 6; $i++) {
            MenuItemEvent::create([
                'menu_id' => $item->id,
                'store_id' => $store->id,
                'type' => 'view',
                'created_at' => now(),
            ]);
        }

        // Stage 3: 4 Cart additions
        for ($i = 0; $i < 4; $i++) {
            MenuItemEvent::create([
                'menu_id' => $item->id,
                'store_id' => $store->id,
                'type' => 'add_to_cart',
                'created_at' => now(),
            ]);
        }

        // Stage 4 & 5: 2 Orders placed, 1 order paid/completed
        $order1 = Order::create([
            'store_id' => $store->id,
            'status' => 'completed',
            'created_at' => now(),
        ]);
        $order2 = Order::create([
            'store_id' => $store->id,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        $this->actingAs($merchant);

        $response = $this->get(route('analytics.index'));
        $response->assertStatus(200);

        // Linen funnel stages: Browse → Add to cart → Order placed → Order paid, with drop-off %.
        $response->assertSee('Conversion funnel');
        $response->assertSee('Browse');
        $response->assertSee('Add to cart');
        $response->assertSee('Order placed');
        $response->assertSee('Order paid');
    }
}
