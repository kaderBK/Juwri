<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

class KdsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Test', 'meta_description' => 'Test', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
        ]);

        $this->withoutMiddleware();
    }

    private function makePlan(): object
    {
        return TestPlans::create([
            'name' => 'Plan '.Str::random(6),
            'description' => 'Test',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);
    }

    private function makeUser(object $plan): User
    {
        return User::factory()->create([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'Restaurant '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111',
            'title' => $title,
            'sub_title' => 'Test',
            'description' => 'Test',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 Test St',
            'phone' => '1234567890',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeOrder(Store $store, string $status = 'new'): Order
    {
        return Order::create([
            'store_id' => $store->id,
            'status' => $status,
            'type' => 'on-table',
            'customer_name' => 'Test Customer',
            'table_number' => 1,
            'created_at' => now(),
        ]);
    }

    /** Index renders the KDS view */
    public function test_index_renders(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $store = $this->makeRestaurant($owner);

        $this->actingAs($owner)
            ->get(route('kds.index'))
            ->assertOk();
    }

    /** Poll returns only new/preparing orders for owner's restaurant */
    public function test_poll_returns_open_orders(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $store = $this->makeRestaurant($owner);

        $newOrder = $this->makeOrder($store, 'new');
        $preparingOrder = $this->makeOrder($store, 'preparing');
        $completedOrder = $this->makeOrder($store, 'completed');

        $response = $this->actingAs($owner)
            ->getJson(route('kds.poll', ['store_id' => $store->id]))
            ->assertOk();

        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($newOrder->id, $ids);
        $this->assertContains($preparingOrder->id, $ids);
        $this->assertNotContains($completedOrder->id, $ids);
    }

    /** Poll tenant isolation: other owner's orders excluded */
    public function test_poll_tenant_isolation(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $store = $this->makeRestaurant($owner);
        $order = $this->makeOrder($store, 'new');

        $other = $this->makeUser($this->makePlan());

        // Other owner requests with specific store_id they don't own
        $this->actingAs($other)
            ->getJson(route('kds.poll', ['store_id' => $store->id]))
            ->assertJson(['error' => 'Forbidden'])
            ->assertStatus(403);
    }

    /** Bump advances new → preparing */
    public function test_bump_new_to_preparing(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $store = $this->makeRestaurant($owner);
        $order = $this->makeOrder($store, 'new');

        $this->actingAs($owner)
            ->postJson(route('kds.bump', $order->id))
            ->assertOk()
            ->assertJson(['status' => 'preparing']);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'preparing']);
    }

    /** Bump advances preparing → ready */
    public function test_bump_preparing_to_ready(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $store = $this->makeRestaurant($owner);
        $order = $this->makeOrder($store, 'preparing');

        $this->actingAs($owner)
            ->postJson(route('kds.bump', $order->id))
            ->assertOk()
            ->assertJson(['status' => 'ready']);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'ready']);
    }

    /** Bump tenant isolation: other owner gets 404 */
    public function test_bump_tenant_isolation(): void
    {
        $owner = $this->makeUser($this->makePlan());
        $store = $this->makeRestaurant($owner);
        $order = $this->makeOrder($store, 'new');

        $other = $this->makeUser($this->makePlan());

        $this->actingAs($other)
            ->postJson(route('kds.bump', $order->id))
            ->assertNotFound();
    }
}
