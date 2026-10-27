<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Editions\EditionPackages;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-7: the dashboard's revenue figures (KPI, today strip, branch comparison)
 * moved from hydrate-then-PHP-sum onto SQL aggregates over the snapshotted
 * grand_total column. This test pins PARITY: every number the controller
 * reports must equal the sum the live ->total accessor derives for the same
 * scope — written against the pre-swap controller and kept green through it.
 *
 * Accessor sums are computed via ->get()->map (never pluck — pluck bypasses
 * accessors).
 */
class DashboardAggregateParityTest extends TestCase
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
        $this->withoutMiddleware([Installed::class, Locale::class, PreventRequestForgery::class]);
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

    /** Create a real order (items + snapshot) and mark it revenue-counted. */
    private function paidOrder(Store $store, Menu $item, int $qty, ?int $branchId = null, float $discount = 0): Order
    {
        $order = OrderApiService::create((int) $store->user_id, $store->id, [
            'type' => 'takeaway',
            'branch_id' => $branchId,
            'items' => [['item_id' => $item->id, 'quantity' => $qty]],
        ]);
        $order->refresh();
        $order->is_paid = 1;
        $order->status = 'completed';
        if ($discount > 0) {
            $order->discount_amount = $discount; // updated-hook re-snapshots
        }
        $order->save();

        return $order->refresh();
    }

    /** The accessor-derived revenue sum for a scope — the parity oracle. */
    private function accessorRevenue($query): float
    {
        return round(
            $query->with(['items.menu', 'items.itemExtras.extra', 'items.variant'])->get()
                ->filter(fn ($o) => $o->countsAsRevenue())
                ->map(fn ($o) => (float) $o->total)   // never pluck: it bypasses the accessor
                ->sum(),
            2
        );
    }

    public function test_dashboard_revenue_numbers_match_the_accessor_derivation(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        // Tax on the PRE-discount subtotal (theme-totals contract) — 10%.
        StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', '10');
        StoreOption::updateStoreOption($store->id, 'restaurant_tax_type', 'percentage');

        $bx = Branch::create(['store_id' => $store->id, 'name' => 'X', 'active' => 1, 'accepting_orders' => 1, 'position' => 1]);
        $by = Branch::create(['store_id' => $store->id, 'name' => 'Y', 'active' => 1, 'accepting_orders' => 1, 'position' => 2]);

        $item = Menu::create(['store_id' => $store->id, 'name' => 'Dish', 'price' => 12.5, 'active' => 1]);

        $this->paidOrder($store, $item, 2, $bx->id);            // 25 + 2.5 tax
        $this->paidOrder($store, $item, 1, $bx->id, 3.0);       // 12.5 + 1.25 tax - 3 discount
        $this->paidOrder($store, $item, 4, $by->id);            // 50 + 5 tax
        // A non-revenue order (unpaid, pending) — must be excluded everywhere.
        OrderApiService::create((int) $store->user_id, $store->id, [
            'type' => 'takeaway', 'branch_id' => $by->id,
            'items' => [['item_id' => $item->id, 'quantity' => 9]],
        ]);

        $expected = $this->accessorRevenue(Order::where('store_id', $store->id));
        $this->assertGreaterThan(0, $expected);

        $response = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));
        $response->assertOk();

        // KPI window revenue (period includes today).
        $this->assertEqualsWithDelta($expected, (float) $response->viewData('salesRevenue'), 0.001);
        // Today / live strip.
        $this->assertEqualsWithDelta($expected, (float) $response->viewData('todaySales'), 0.001);
        // Branch comparison rows (a chain tool, ee since 2026-10-05: absent in Community, see CommunityEditionSplitModulesTest).
        if (EditionPackages::all() !== []) {
            $rows = collect($response->viewData('branchCard')['rows']);
            $this->assertGreaterThanOrEqual(2, $rows->count());
            $rowX = $rows->firstWhere('name', 'X');
            $rowY = $rows->firstWhere('name', 'Y');
            $this->assertSame(2, $rowX['orders']);
            $this->assertSame(2, $rowY['orders']);   // includes the unpaid order in the count
            $this->assertEqualsWithDelta(
                $this->accessorRevenue(Order::where('store_id', $store->id)->where('branch_id', $bx->id)),
                $rowX['sales'], 0.001
            );
            $this->assertEqualsWithDelta(
                $this->accessorRevenue(Order::where('store_id', $store->id)->where('branch_id', $by->id)),
                $rowY['sales'], 0.001
            );
        }
        // By-day sparkline decomposes salesRevenue.
        $this->assertEqualsWithDelta($expected, array_sum($response->viewData('salesSeries')), 0.001);
    }

    public function test_an_unsnapshotted_legacy_order_still_counts_via_the_accessor_fallback(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Dish', 'price' => 8, 'active' => 1]);

        $o = $this->paidOrder($store, $item, 3); // 24.0
        // Null the snapshot quietly — a legacy pre-backfill row.
        Order::withoutEvents(function () use ($o) {
            Order::where('id', $o->id)->update(['subtotal_minor' => null, 'tax_total_minor' => null, 'grand_total_minor' => null]);
        });

        $response = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));
        $response->assertOk();

        // The current-window KPI and today strip fall back to the accessor, so a
        // legacy row is never silently dropped from the numbers the owner sees.
        $this->assertEqualsWithDelta(24.0, (float) $response->viewData('salesRevenue'), 0.001);
        $this->assertEqualsWithDelta(24.0, (float) $response->viewData('todaySales'), 0.001);
        // And the gap counter tells the owner the backfill still owes a run.
        $this->assertGreaterThanOrEqual(1, (int) $response->viewData('revenueGap'));
    }
}
