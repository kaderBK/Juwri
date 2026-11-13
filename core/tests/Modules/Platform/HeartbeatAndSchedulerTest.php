<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Tables\Models\WaiterCall;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * #74  Auto-print / order notification — heartbeat endpoint (server poll)
 * #5   Sold-out auto-reset — menu:reset-sold-out artisan command
 */
class HeartbeatAndSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'allow_ordering' => 1],
            'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id,
            'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::random(5),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234',
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeMenuItem(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Item', 'price' => 10, 'active' => 1]);
    }

    private function makeOrder(Store $store, string $status = 'pending', bool $seen = false): Order
    {
        return Order::create([
            'store_id' => $store->id,
            'customer_name' => 'Diner',
            'phone_number' => '555',
            'type' => 'takeaway',
            'status' => $status,
            'seen' => $seen ? 1 : 0,
        ]);
    }

    // ── #74 Heartbeat (auto-print / order notification) ──

    /** Unseen, non-unpaid order returns count=1 and marks it seen */
    public function test_heartbeat_returns_unseen_order_count(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $order = $this->makeOrder($store, 'pending', false);

        $response = $this->actingAs($owner)->getJson(route('stores.heartbeat'));

        $response->assertOk()->assertJson(['success' => true, 'orders' => 1]);

        // Marked seen after heartbeat
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'seen' => 1]);
    }

    /** Second heartbeat returns 0 (dedup — already marked seen) */
    public function test_heartbeat_dedup_marks_seen(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $this->makeOrder($store, 'pending', false);

        $this->actingAs($owner)->getJson(route('stores.heartbeat')); // first call

        $response = $this->actingAs($owner)->getJson(route('stores.heartbeat')); // second
        $response->assertOk()->assertJson(['success' => true, 'orders' => 0]);
    }

    /** Unpaid orders excluded from heartbeat count */
    public function test_heartbeat_excludes_unpaid_orders(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $this->makeOrder($store, 'unpaid', false);

        $response = $this->actingAs($owner)->getJson(route('stores.heartbeat'));
        $response->assertOk()->assertJson(['success' => true, 'orders' => 0]);
    }

    /** Waiter call appears in notifications and is cleared after heartbeat */
    public function test_heartbeat_returns_waiter_call_and_clears_it(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        WaiterCall::create([
            'store_id' => $store->id,
            'table_no' => '5',
            'type' => 'call_waiter',
            'seen' => 0,
        ]);

        $response = $this->actingAs($owner)->getJson(route('stores.heartbeat'));

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertNotEmpty($response->json('waiterCalls'));
        // Kit icons (inline SVG), no Font Awesome classes; store title is escaped.
        $line = $response->json('waiterCalls.0');
        $this->assertSame(2, substr_count($line, '<svg'));
        $this->assertStringNotContainsString('fa-', $line);
        $this->assertStringContainsString(e($store->title), $line);
        $this->assertStringContainsString('5', strip_tags($line));

        // WaiterCall deleted after heartbeat fetches it
        $this->assertDatabaseCount('waiter_calls', 0);
    }

    /** Tenant isolation: heartbeat only shows orders for own restaurants */
    public function test_heartbeat_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $this->makeOrder($store, 'pending', false);

        // other owner's heartbeat should not see owner's order
        $response = $this->actingAs($other)->getJson(route('stores.heartbeat'));
        $response->assertOk()->assertJson(['success' => true, 'orders' => 0]);
    }

    // ── #5 Sold-out auto-reset ──

    /** menu:reset-sold-out sets is_sold_out=0 on all sold-out items */
    public function test_reset_sold_out_command_clears_flag(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);
        $item->update(['is_sold_out' => 1]);

        $this->assertDatabaseHas('items', ['id' => $item->id, 'is_sold_out' => 1]);

        $this->artisan('menu:reset-sold-out')->assertExitCode(0);

        $this->assertDatabaseHas('items', ['id' => $item->id, 'is_sold_out' => 0]);
    }

    /** reset-sold-out leaves non-sold-out items untouched */
    public function test_reset_sold_out_ignores_available_items(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($store);
        $item->update(['is_sold_out' => 0]);

        $this->artisan('menu:reset-sold-out')->assertExitCode(0);

        $this->assertDatabaseHas('items', ['id' => $item->id, 'is_sold_out' => 0]);
    }

    /** The nightly reset must not re-enable genuinely out-of-stock tracked items. */
    public function test_reset_keeps_out_of_stock_tracked_items_sold_out(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        // Tracked + zero on hand — auto-86'd; only a restock should clear it.
        $tracked = $this->makeMenuItem($store);
        $tracked->update(['is_sold_out' => 1, 'track_inventory' => 1, 'stock_qty' => 0]);
        // Manually 86'd (not tracked) — should reset.
        $manual = $this->makeMenuItem($store);
        $manual->update(['is_sold_out' => 1, 'track_inventory' => 0]);

        $this->artisan('menu:reset-sold-out')->assertExitCode(0);

        $this->assertDatabaseHas('items', ['id' => $tracked->id, 'is_sold_out' => 1]);
        $this->assertDatabaseHas('items', ['id' => $manual->id, 'is_sold_out' => 0]);
    }

    /** --scheduled resets a store only during ITS OWN local midnight hour */
    public function test_scheduled_reset_honours_store_timezone(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        StoreOption::updateStoreOption($store->id, 'timezone', 'America/New_York');
        $item = $this->makeMenuItem($store);
        $item->update(['is_sold_out' => 1]);

        // 18:00 UTC = 14:00 in New York (EDT) — NOT the midnight hour → skipped.
        Carbon::setTestNow(Carbon::parse('2026-08-09 18:00:00', 'UTC'));
        $this->artisan('menu:reset-sold-out --scheduled')->assertExitCode(0);
        $this->assertDatabaseHas('items', ['id' => $item->id, 'is_sold_out' => 1]);

        // 04:30 UTC = 00:30 in New York → local midnight hour → reset.
        Carbon::setTestNow(Carbon::parse('2026-08-09 04:30:00', 'UTC'));
        $this->artisan('menu:reset-sold-out --scheduled')->assertExitCode(0);
        $this->assertDatabaseHas('items', ['id' => $item->id, 'is_sold_out' => 0]);

        Carbon::setTestNow();
    }

    public function test_heartbeat_touches_the_orders_seen_predicate_once(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $this->makeOrder($store, 'pending', false);

        DB::enableQueryLog();
        $this->actingAs($owner)->getJson(route('stores.heartbeat'))->assertOk();

        // Scope to the ORDERS table's seen predicate — waiter_calls also has a seen column.
        $seenQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn ($q) => (str_contains($q, '"seen"') || str_contains($q, '`seen`'))
                && preg_match('/["`]?orders["`]?/', $q)
                && ! str_contains($q, 'waiter'))
            ->values();

        // M11: exactly one statement against the orders seen predicate — an UPDATE,
        // not a COUNT followed by an identical UPDATE.
        $this->assertCount(1, $seenQueries, 'heartbeat must hit the orders seen predicate exactly once');
        $this->assertStringStartsWith('update', strtolower($seenQueries[0]));
    }
}
