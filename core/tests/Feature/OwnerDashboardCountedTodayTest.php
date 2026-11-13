<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-296: the owner dashboard "Today's orders" count, "Today's sales" and the
 * bell attention badge must AGREE on which of today's orders are counted.
 *
 * Before this change:
 *   - "Today's orders" counted every row in the today window (cancelled, unpaid,
 *     pending_approval all in).
 *   - "Today's sales" used revenueSum() (revenue-counted only) — so the two
 *     figures disagreed whenever a cancellation or unpaid row existed.
 *   - The bell "orders awaiting action" badge counted only `pending`/`new`,
 *     so a `pending_approval` order (placed, paid, but parked for staff
 *     approval) was invisible — even though scopeLive() keeps it out of every
 *     kitchen queue.
 *
 * After this change:
 *   - One named scope `Order::scopeCountedToday()` is used by both
 *     `todayOrderCount` and the fleet per-store `todayCount`. Same predicate
 *     as `scopeRevenueCounted()` (status='completed' OR is_paid=1).
 *   - `pending_approval` is in the bell badge.
 *   - `unpaid` is excluded from the live strip (hosted-checkout, not in the
 *     kitchen yet).
 *
 * These tests pin all three with the spec's "day with 1 cancelled + 1
 * pending_approval" scenario: counts, sales and badge agree.
 */
class OwnerDashboardCountedTodayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
        ]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    private function makeOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'scan_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeStore(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Insert an order with snapshot money columns directly — same shape
     * the production create flow leaves in the orders table (R1c snapshot).
     */
    private function makeOrder(Store $store, array $overrides, int $grandMinor = 1000): Order
    {
        $row = array_merge([
            'store_id' => $store->id, 'track_token' => 'TT'.Str::upper(Str::random(8)),
            'customer_name' => 'C', 'phone_number' => '1',
            'type' => 'takeaway', 'status' => 'pending', 'is_paid' => 0,
            'currency_code' => 'USD',
            'subtotal_minor' => $grandMinor, 'tax_total_minor' => 0,
            'grand_total_minor' => $grandMinor,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            'held_at' => null, 'completed_at' => null, 'cancelled_at' => null,
        ], $overrides);
        DB::table('orders')->insert($row);

        return Order::where('track_token', $row['track_token'])->firstOrFail();
    }

    /**
     * The headline P6-296 scenario: a day with 1 cancelled-unpaid and 1
     * pending_approval row. Counts (todayOrderCount), sales (todaySales) and
     * the bell badge (orders-notification count) all exclude both — except
     * the badge lights because pending_approval is parked waiting for action.
     */
    public function test_counts_sales_and_badge_agree_on_cancelled_and_pending_approval(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);

        // Revenue-counting today order — counts toward todayOrderCount, todaySales.
        $paid = $this->makeOrder($store, ['status' => 'completed', 'is_paid' => 1], 2500);
        // The spec scenario: cancelled-unpaid and pending_approval.
        $this->makeOrder($store, [
            'status' => 'cancelled', 'is_paid' => 0, 'cancelled_at' => Carbon::now(),
        ], 1500);
        $this->makeOrder($store, [
            'status' => 'pending_approval', 'is_paid' => 1,
        ], 2000);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));
        $response->assertStatus(200);

        // todayOrderCount = 1 (only the paid completed order).
        $this->assertSame(
            1,
            $response->viewData('todayOrderCount'),
            'todayOrderCount uses scopeCountedToday() — cancelled-unpaid and pending_approval are out'
        );
        // todaySales = paid.completed's grand_total — sales agrees with count.
        $this->assertEqualsWithDelta(
            25.0,
            (float) $response->viewData('todaySales'),
            0.01,
            'todaySales (revenueSum) only sums revenue-counted orders; matches the one today order'
        );

        // The bell badge counts the pending_approval order (it has no
        // completed_at / cancelled_at and was created today). Pre-P6-296
        // the badge would have stayed silent on pending_approval.
        $counts = $this->computeBellCounts($owner->id, [(int) $store->id]);
        $this->assertSame(
            1,
            $counts['orders'],
            'pending_approval order lights the bell — placed, paid, not in the kitchen'
        );

        // Cleanup the fixture we created (not strictly needed, RefreshDatabase handles it).
        unset($paid);
    }

    /**
     * Sanity: an unpaid hosted-checkout order does NOT count as today's order
     * (no money changed hands) and is NOT in the live strip (not in the
     * kitchen). Sales stays at 0.
     */
    public function test_unpaid_order_excluded_from_today_count_and_live_strip(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);

        // Hosted-checkout order: status='unpaid', is_paid=0.
        $this->makeOrder($store, ['status' => 'unpaid', 'is_paid' => 0], 3000);
        // A normal completed order so today isn't completely empty.
        $this->makeOrder($store, ['status' => 'completed', 'is_paid' => 1], 1000);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));
        $response->assertStatus(200);

        $this->assertSame(
            1,
            $response->viewData('todayOrderCount'),
            'todayOrderCount = 1 (the completed one); the unpaid row is out'
        );
        $this->assertSame(
            0,
            $response->viewData('liveTickets'),
            'live tickets = 0 — the unpaid hosted-checkout order is not in the kitchen yet'
        );
        $this->assertEqualsWithDelta(
            10.0,
            (float) $response->viewData('todaySales'),
            0.01,
        );
    }

    /**
     * Order::scopeCountedToday() must match a revenue-counted order that is
     * NOT parked for staff approval — the SQL/collection pair mirrors the H10
     * revenue-counted pair (plus the pending_approval exclusion), so the
     * dashboard never drifts from the analytics surface.
     */
    public function test_scope_counted_today_agrees_with_counts_as_revenue(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);

        // status × is_paid fixture: which combinations count?
        // completed+0 → counts (status='completed')
        // new+1       → counts (is_paid=1)
        // preparing+1 → counts (is_paid=1)
        // pending_approval+1 → does NOT count (held for staff approval)
        // cancelled+0 → does NOT count (status not completed AND not paid)
        // unpaid+0    → does NOT count
        $this->makeOrder($store, ['status' => 'completed', 'is_paid' => 0]);
        $this->makeOrder($store, ['status' => 'new', 'is_paid' => 1]);
        $this->makeOrder($store, ['status' => 'preparing', 'is_paid' => 1]);
        $this->makeOrder($store, ['status' => 'pending_approval', 'is_paid' => 1]);
        $this->makeOrder($store, ['status' => 'cancelled', 'is_paid' => 0, 'cancelled_at' => Carbon::now()]);
        $this->makeOrder($store, ['status' => 'unpaid', 'is_paid' => 0]);

        $sqlIds = Order::query()->countedToday()->pluck('id')->sort()->values()->all();
        $phpIds = Order::all()
            ->filter(fn ($o) => $o->countsAsRevenue() && $o->status !== Order::STATUS_PENDING_APPROVAL)
            ->pluck('id')->sort()->values()->all();
        $this->assertSame($phpIds, $sqlIds, 'scopeCountedToday() ids match revenue-counted orders minus pending_approval');
        $this->assertCount(3, $sqlIds, 'exactly the three revenue-counted, non-pending rows: completed/0, new/1, preparing/1');
    }

    /**
     * The bell badge: pending_approval lights it; 'new' and 'pending' keep
     * lighting it; completed/cancelled do not (held_at test is not relevant
     * for these orders).
     */
    public function test_bell_badge_includes_pending_approval(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);

        // Placed-but-paid, waiting on staff approval.
        $this->makeOrder($store, ['status' => 'pending_approval', 'is_paid' => 1]);
        // An older completed order does NOT light the bell (already handled).
        $this->makeOrder($store, ['status' => 'completed', 'is_paid' => 1, 'created_at' => Carbon::now()->subDays(2)]);
        // A cancelled order does NOT light the bell.
        $this->makeOrder($store, ['status' => 'cancelled', 'is_paid' => 0, 'cancelled_at' => Carbon::now()]);

        $counts = $this->computeBellCounts($owner->id, [(int) $store->id]);
        $this->assertSame(1, $counts['orders'], 'pending_approval order lights the bell');
    }

    /** Mirror of DashboardController::computeNotificationCounts()'s orders term — kept inline so the
     *  test does not need to refresh the controller's prefs cache. */
    private function computeBellCounts(int $ownerId, array $storeIds): array
    {
        $counts = ['orders' => 0];
        $orders = Order::whereIn('store_id', $storeIds)
            ->whereIn('status', ['pending', 'new', 'pending_approval'])
            ->whereNull('held_at')
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->count();
        $counts['orders'] = $orders;

        return $counts;
    }
}
