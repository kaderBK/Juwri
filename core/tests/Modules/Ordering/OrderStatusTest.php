<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

class OrderStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $settings = new class(['theme_color' => '#111111', 'site_title' => 'juwri', 'meta_description' => 'Test description', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'store_order_whatsapp_message' => 'Test whatsapp msg'])
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

        // Create a default free plan and configure settings fallback
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

        $this->withoutMiddleware();
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

    public function test_diner_can_track_order_with_token()
    {
        $user = User::factory()->create();
        $store = $this->createTestRestaurant($user);

        $order = Order::create([
            'store_id' => $store->id,
            'customer_name' => 'John Doe',
            'status' => 'pending',
            'track_token' => \Str::random(32),
        ]);

        // Track page view
        $response = $this->get(route('store.orderTrack', $order->track_token));
        $response->assertStatus(200);
        $response->assertSee('John Doe');
        $response->assertSee('Pizza Paradise');

        // Track AJAX JSON status check
        $ajaxResponse = $this->getJson(route('store.orderTrack', $order->track_token));
        $ajaxResponse->assertStatus(200);
        $ajaxResponse->assertJsonFragment([
            'status' => 'pending',
        ]);
    }

    public function test_merchant_can_update_order_status()
    {
        $user = User::factory()->create();
        $store = $this->createTestRestaurant($user);

        $order = Order::create([
            'store_id' => $store->id,
            'customer_name' => 'John Doe',
            'status' => 'pending',
            'track_token' => \Str::random(32),
        ]);

        $this->actingAs($user);
        auth()->login($user);

        // Advance to preparing
        $response = $this->postJson(route('stores.updateOrderStatus', $order->id), [
            'status' => 'preparing',
        ]);

        $response->assertStatus(200);
        $order->refresh();
        $this->assertEquals('preparing', $order->status);
        $this->assertNotNull($order->preparing_at);
    }
}
