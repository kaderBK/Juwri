<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuItemEvent;
use Tests\Support\TestPlans;
use Tests\TestCase;

class TapHeatmapTest extends TestCase
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

    public function test_merchant_can_view_category_tap_heatmap()
    {
        $merchant = User::factory()->create(['status' => 1]);
        $store = $this->createTestRestaurant($merchant);

        $category1 = MenuCategory::create([
            'store_id' => $store->id,
            'name' => 'Pizza',
        ]);

        $category2 = MenuCategory::create([
            'store_id' => $store->id,
            'name' => 'Desserts',
        ]);

        $item1 = Menu::create([
            'store_id' => $store->id,
            'category_id' => $category1->id,
            'name' => 'Margherita',
            'price' => 10,
        ]);

        $item2 = Menu::create([
            'store_id' => $store->id,
            'category_id' => $category2->id,
            'name' => 'Tiramisu',
            'price' => 5,
        ]);

        // Traffic so the analytics page isn't in its empty state.
        StoreView::create([
            'store_id' => $store->id,
            'ip' => '192.168.1.1',
            'date' => now()->format('Y-m-d H:i:s'),
            'device' => 'Mobile',
            'lang' => 'en',
        ]);

        // Create 10 views for Pizza category
        for ($i = 0; $i < 10; $i++) {
            MenuItemEvent::create([
                'menu_id' => $item1->id,
                'store_id' => $store->id,
                'type' => 'view',
                'created_at' => now(),
            ]);
        }

        // Create 5 views for Desserts category
        for ($i = 0; $i < 5; $i++) {
            MenuItemEvent::create([
                'menu_id' => $item2->id,
                'store_id' => $store->id,
                'type' => 'view',
                'created_at' => now(),
            ]);
        }

        $this->actingAs($merchant);

        $response = $this->get(route('analytics.index'));
        $response->assertStatus(200);

        // Verify category heatmap sections and percentages are in the view
        $response->assertSee('Category heatmap');
        $response->assertSee('Pizza');
        $response->assertSee('Desserts');

        // Pizza views = 10 (top category: 100% intensity)
        $response->assertSee('100%');

        // Desserts views = 5 (50% intensity relative to Pizza)
        $response->assertSee('50%');
    }
}
