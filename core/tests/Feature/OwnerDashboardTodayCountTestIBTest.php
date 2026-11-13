<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
 * P6-296 — owner dashboard "today" reconciliation.
 *
 * Three views onto the same day must agree:
 *
 *   1. `todayOrderCount` (and the per-store fleet `todayCount`) must match the
 *      set of orders that contribute to `todaySales` / `todayCount`'s revenue
 *      neighbour. The strip currently says "12 orders, 40 sales" when a
 *      cancellation moves them apart.
 *   2. `pending_approval` orders must light the "orders awaiting action"
 *      notification badge — they are held, they have not reached the kitchen,
 *      and the owner has to act on them.
 *   3. `liveTickets` must not count unpaid orders — they have not reached
 *      the kitchen yet, so they are not "live tickets".
 *
 * The fix is a single named scope `Order::scopeCountedToday()` for (1) +
 * additions in the badge + live-strip queries. This file pins the EXPECTED
 * behaviour so the builder's change lands green and a regression in any of
 * the three views fails loudly.
 */
class OwnerDashboardTodayCountTestIBTest extends TestCase
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
        Cache::flush();
    }

    /**
     * Owner + single store (USD) wired up with the dashboard's plan/menu
     * prerequisites.
     *
     * @return array{0: User, 1: Store}
     */
    private function ownerWithStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6),
            'description' => 'T',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'scan_limit' => 999]),
            'status' => 1,
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
        $title = 'R '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');

        return [$owner, $store];
    }

    /**
     * Insert one order in the requested state. The snapshot column is set
     * directly so revenueSum() picks it up exactly as a backfilled row would.
     * Pass `null` for grand_total_minor to leave it unsnapshotted.
     *
     * @return int the inserted order id
     */
    private function seedOrder(int $storeId, string $status, bool $paid, ?int $grandMinor = 1000, ?Carbon $at = null): int
    {
        $now = $at ?? Carbon::now();

        return DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'type' => 'takeaway',
            'customer_name' => 'C',
            'phone_number' => '1',
            'status' => $status,
            'is_paid' => $paid ? 1 : 0,
            'currency_code' => 'USD',
            'grand_total_minor' => $grandMinor,
            'created_at' => $now,
            'completed_at' => $status === 'completed' ? $now : null,
            'cancelled_at' => $status === 'cancelled' ? $now : null,
        ]);
    }

    // ── 1. todayOrderCount ↔ todaySales parity ────────────────────────────

    /**
     * The shipped-in acceptance: a cancelled + a pending_approval day leaves
     * the count, the sales, and the badge in agreement. Three of today's
     * orders are revenue-counted (1 completed paid, 1 completed unpaid, 1
     * pending paid), two are not (1 cancelled, 1 pending_approval).
     */
    public function test_today_count_matches_sales_after_a_cancelled_and_a_pending_approval_order(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        // Revenue-counted today.
        $this->seedOrder($store->id, 'completed', true, 2500);  // 25.00
        $this->seedOrder($store->id, 'completed', false, 1500); // 15.00 — completed = revenue-counted
        $this->seedOrder($store->id, 'pending', true, 1000); // 10.00 — is_paid = revenue-counted
        // Cancelled today — NOT counted.
        $this->seedOrder($store->id, 'cancelled', false, 0);
        // Pending approval today — held, NOT revenue-counted.
        $this->seedOrder($store->id, Order::STATUS_PENDING_APPROVAL, true, 9999);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $this->assertSame(3, $response->viewData('todayOrderCount'),
            'todayOrderCount counts only the 3 revenue-counted orders');
        $this->assertSame(50.0, (float) $response->viewData('todaySales'),
            'todaySales is the same 3 orders × their snapshotted total');
    }

    /**
     * Unpaid orders that are not revenue-counted (any non-completed, not paid)
     * must not inflate today's count. The acceptance for "12 orders, 40 sales
     * disagree after cancellations" lives here.
     */
    public function test_today_count_excludes_an_unpaid_non_completed_order(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        $this->seedOrder($store->id, 'completed', true, 4000); // 40.00 — counts
        $this->seedOrder($store->id, 'pending', false, 9999); // unpaid + not completed — NOT counted

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $this->assertSame(1, $response->viewData('todayOrderCount'));
        $this->assertSame(40.0, (float) $response->viewData('todaySales'));
    }

    // ── 2. fleet per-store "today" agrees too ─────────────────────────────

    /**
     * The multi-store fleet strip uses the same `todayCount`. A cancelled
     * order on a single store's row must NOT inflate that row's order count.
     */
    public function test_fleet_today_count_excludes_cancelled_and_pending_approval(): void
    {
        [$owner, $a] = $this->ownerWithStore();
        $bTitle = 'R '.Str::random(6);
        $b = Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($bTitle).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $bTitle, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($b->id, 'currency_code', 'USD');

        // Store A: 1 revenue-counted + 1 cancelled + 1 pending_approval.
        $this->seedOrder($a->id, 'completed', true, 4000);
        $this->seedOrder($a->id, 'cancelled', false, 0);
        $this->seedOrder($a->id, Order::STATUS_PENDING_APPROVAL, true, 9999);
        // Store B: 2 revenue-counted + 1 cancelled (must NOT inflate the count).
        $this->seedOrder($b->id, 'completed', true, 1000);
        $this->seedOrder($b->id, 'pending', true, 2000);
        $this->seedOrder($b->id, 'cancelled', false, 9999);

        $response = $this->actingAs($owner)
            ->get(route('dashboard', ['scope' => 'all']));

        $response->assertStatus(200);
        $fleet = collect($response->viewData('fleet'))->keyBy('id')->all();
        $this->assertSame(1, $fleet[$a->id]['orders'], 'A: only the completed paid order counts');
        $this->assertSame(40.0, (float) $fleet[$a->id]['sales'], 'A: today sales mirror the order count');
        $this->assertSame(2, $fleet[$b->id]['orders'], 'B: only the 2 revenue-counted orders count; the cancelled one is excluded');
        $this->assertSame(30.0, (float) $fleet[$b->id]['sales'], 'B: today sales mirror the order count');
    }

    // ── 3. live tickets exclude unpaid ────────────────────────────────────

    /**
     * An `unpaid` order (hosted-checkout, not yet paid) is held for the
     * kitchen — the kitchen has nothing to cook yet. It must NOT count as a
     * live ticket. A paid order in a live status DOES count.
     */
    public function test_live_tickets_exclude_unpaid_orders(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        $this->seedOrder($store->id, 'preparing', true, 1000); // live
        $this->seedOrder($store->id, 'unpaid', false, 1000); // held, not live (hosted checkout)
        $this->seedOrder($store->id, 'unpaid', false, 1000); // held, not live
        $this->seedOrder($store->id, 'completed', true, 1000); // not live (done)

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $this->assertSame(1, $response->viewData('liveTickets'),
            'only the paid preparing order is live; unpaid orders are held, not live');
    }

    // ── 4. awaiting-action badge includes pending_approval ───────────────

    /**
     * `pending_approval` is held, has not reached the kitchen, and needs the
     * owner's accept/reject decision — it must light the badge. Holding it
     * (`held_at` set) drops it the same way `pending`/`new` held orders are
     * dropped (mirrors the current `held_at` rule).
     */
    public function test_awaiting_action_badge_counts_a_pending_approval_order(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        // pending_approval + not held → counted.
        $this->seedOrder($store->id, Order::STATUS_PENDING_APPROVAL, false);
        // held pending_approval → not counted (existing rule).
        DB::table('orders')->insert([
            'store_id' => $store->id, 'type' => 'takeaway',
            'customer_name' => 'C', 'phone_number' => '1',
            'status' => Order::STATUS_PENDING_APPROVAL, 'is_paid' => 0,
            'currency_code' => 'USD', 'held_at' => now(), 'created_at' => now(),
        ]);
        // pending_approval on another store → not counted.
        $otherOwner = User::factory()->create(['group_id' => 'free', 'status' => 1]);
        $otherTitle = 'R '.Str::random(6);
        $otherStore = Store::create([
            'user_id' => $otherOwner->id,
            'slug' => Str::slug($otherTitle).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $otherTitle, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($otherStore->id, 'currency_code', 'USD');
        DB::table('orders')->insert([
            'store_id' => $otherStore->id, 'type' => 'takeaway',
            'customer_name' => 'C', 'phone_number' => '1',
            'status' => Order::STATUS_PENDING_APPROVAL, 'is_paid' => 0,
            'currency_code' => 'USD', 'created_at' => now(),
        ]);

        $json = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->getJson(route('dashboard.notifications'))
            ->assertOk()
            ->json();

        $this->assertSame(1, $json['orders'],
            'one pending_approval on the context store is counted; held + other-store rows are not');
    }

    /**
     * The same day, mixed-status rows: 1 cancelled, 1 pending_approval, 1
     * completed paid — only the completed paid one is revenue-counted. The
     * count matches sales (1) AND does not inflate the badge (the
     * pending_approval row IS the awaiting-action row, not revenue).
     */
    public function test_full_day_consistent_view_count_sales_and_badge(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        $this->seedOrder($store->id, 'completed', true, 4000); // revenue + counted
        $this->seedOrder($store->id, 'cancelled', false, 0);    // nothing
        $this->seedOrder($store->id, Order::STATUS_PENDING_APPROVAL, true, 9999); // badge only

        $response = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $this->assertSame(1, $response->viewData('todayOrderCount'),
            'todayOrderCount: only the completed paid order counts (1)');
        $this->assertSame(40.0, (float) $response->viewData('todaySales'),
            'todaySales: only the completed paid order contributes ($40)');

        Cache::flush();
        $json = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->getJson(route('dashboard.notifications'))
            ->assertOk()
            ->json();
        $this->assertSame(1, $json['orders'],
            'badge: the pending_approval row lights the awaiting-action badge');
    }

    // ── 5. the named scope exists ──────────────────────────────────────────

    /**
     * The fix is "one named scope for 'counted today'" — it must live on the
     * Order model so both the dashboard controller (and any future caller
     * measuring "today") agree. Asserts the scope is callable and trims
     * today's window down to revenue-counted, non-pending-approval orders.
     */
    public function test_order_has_a_named_counted_today_scope(): void
    {
        [, $store] = $this->ownerWithStore();

        // 2 revenue-counted today + 1 cancelled + 1 pending_approval → 2
        $this->seedOrder($store->id, 'completed', true, 4000);
        $this->seedOrder($store->id, 'pending', true, 2000);
        $this->seedOrder($store->id, 'cancelled', false, 0);
        $this->seedOrder($store->id, Order::STATUS_PENDING_APPROVAL, true, 9999);

        $this->assertTrue(
            method_exists(Order::query()->getModel(), 'scopeCountedToday'),
            'Order must expose a public scopeCountedToday so the dashboard can share the definition.'
        );

        // The scope itself trims a today window the same way the dashboard does.
        $count = Order::query()
            ->where('store_id', $store->id)
            ->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()])
            ->countedToday()
            ->count();

        $this->assertSame(2, $count,
            'countedToday(): the 2 revenue-counted, non-pending-approval orders');
    }
}
