<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-H13 — AnalyticsController must not N+1 the previous window (it had NO eager
 * load and summed the `total` accessor), must keep the revenue figure identical
 * to Order::getTotalAttribute(), and must render the mandatory applied window.
 */
class AnalyticsQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $settings = new class(['theme_color' => '#111', 'site_title' => 'juwri', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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
        $plan = TestPlans::create(['name' => 'Free', 'description' => 'T', 'monthly_price' => 0, 'annual_price' => 0, 'lifetime_price' => 0, 'settings' => ['allow_viewonly' => '1', 'allow_pro' => '1'], 'status' => 1]);
        config(['settings.free_membership_plan' => $plan]);
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'plan' => $plan]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(User $user): Store
    {
        return Store::create([
            'user_id' => $user->id, 'title' => 'R'.Str::random(4), 'slug' => 'r-'.Str::lower(Str::random(6)),
            'color' => '#111', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
    }

    private function item(Store $r): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);

        return Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 10, 'active' => '1']);
    }

    private function paidOrder(Store $r, Menu $item, string $createdAt, int $lines = 2): Order
    {
        $order = Order::create([
            'store_id' => $r->id, 'customer_name' => 'D', 'type' => 'delivery',
            'status' => 'pending', 'is_paid' => 1, 'track_token' => Str::random(20),
            'created_at' => $createdAt,
        ]);
        for ($i = 0; $i < $lines; $i++) {
            OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1]);
        }

        return $order;
    }

    public function test_previous_window_revenue_does_not_n_plus_one(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        $item = $this->item($r);

        // 6 paid orders in the PREVIOUS 7-day window (8-13 days ago), 2 in the current.
        foreach (range(8, 13) as $d) {
            $this->paidOrder($r, $item, now()->subDays($d)->toDateTimeString());
        }
        $this->paidOrder($r, $item, now()->subDays(1)->toDateTimeString());
        $this->paidOrder($r, $item, now()->subDays(2)->toDateTimeString());

        $count = 0;
        DB::listen(function ($q) use (&$count) {
            if (preg_match('/from\s+["`]?[a-z_]*order_items["`]?/i', $q->sql)) {
                $count++;
            }
        });

        $this->actingAs($owner)->get(route('analytics.index', ['period' => '7d']))->assertOk();

        $this->assertLessThanOrEqual(4, $count, "previous-window revenue N+1'd order_items ({$count} queries)");
    }

    public function test_revenue_still_matches_the_order_total_accessor(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        $item = $this->item($r);

        $current = collect([
            $this->paidOrder($r, $item, now()->subDays(1)->toDateTimeString()),
            $this->paidOrder($r, $item, now()->subDays(2)->toDateTimeString()),
        ]);

        $response = $this->actingAs($owner)->get(route('analytics.index', ['period' => '7d']))->assertOk();

        $expected = round($current->sum(fn ($o) => (float) $o->fresh()->total), 2);
        $this->assertSame($expected, round((float) $response->viewData('totalRevenue'), 2));
    }

    public function test_placed_and_paid_counts_are_unchanged(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        $item = $this->item($r);

        // 8 orders in the current window, 5 paid.
        for ($i = 0; $i < 5; $i++) {
            $this->paidOrder($r, $item, now()->subDays(1)->toDateTimeString(), 1);
        }
        for ($i = 0; $i < 3; $i++) {
            Order::create(['store_id' => $r->id, 'customer_name' => 'D', 'type' => 'delivery', 'status' => 'pending', 'is_paid' => 0, 'track_token' => Str::random(20), 'created_at' => now()->subDays(1)]);
        }

        $response = $this->actingAs($owner)->get(route('analytics.index', ['period' => '7d']))->assertOk();

        $this->assertSame(8, (int) $response->viewData('placedCount'));
        $this->assertSame(5, (int) $response->viewData('paidCount'));
    }

    public function test_applied_window_is_rendered_on_the_page(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $this->storeFixture($owner);

        $this->actingAs($owner)->get(route('analytics.index', ['period' => '30d']))->assertOk()->assertSeeHtml('data-analytics-window')
            ->assertSee(now()->subDays(30)->toDateString());
    }
}
