<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-62: the owner dashboard's current-window numbers (orders, revenue, AOV, by-day series, top items) come from
 * SQL aggregates. GOLDEN: a deterministic 2,000-order fixture (mixed statuses incl. refunded / cancelled-but-paid,
 * extras, variants, option upcharges, scheduled unit prices, unsnapshotted legacy rows, a previous window) whose
 * view data was captured from the PRE-change hydrating controller and is pinned below. Plus query-count and
 * peak-memory caps, and the multi-currency rule (never one sum across currencies).
 *
 * Set P662_DUMP=1 to print the live view data as JSON instead of asserting (how the golden was captured).
 */
class DashboardPeriodAggregatesGoldenTest extends TestCase
{
    use RefreshDatabase;

    private const ORDERS = 2000;

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

    private function makeStore(User $owner, string $currency): Store
    {
        $title = 'R '.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    /**
     * Deterministic fixture. Orders / lines / extras go in with bulk inserts (no model events): the snapshot columns
     * are written directly, exactly as the R1c snapshot would have left them.
     *
     * @return array{menus: list<int>}
     */
    private function fixture(Store $store, string $currency, int $orders): array
    {
        $menus = [];
        $variants = [];
        $extras = [];
        for ($m = 0; $m < 12; $m++) {
            $menu = Menu::create(['store_id' => $store->id, 'name' => 'Dish '.$m, 'price' => 5 + $m * 1.5, 'active' => 1]);
            $menus[] = $menu->id;
            if ($m < 3) {
                $opt = MenuVariantOption::create(['menu_id' => $menu->id, 'title' => 'Size', 'options' => ['S', 'L'], 'position' => 1, 'active' => 1]);
                $variants[$m] = MenuVariant::create(['menu_id' => $menu->id, 'price' => 20 + $m, 'options' => [$opt->id => 1], 'active' => 1, 'position' => 1])->id;
            }
            if ($m % 3 === 0) {
                $extras[$m] = MenuExtra::create(['menu_id' => $menu->id, 'title' => 'Extra '.$m, 'price' => 1.75, 'position' => 1, 'active' => 1])->id;
            }
        }
        // one soft-deleted dish: its lines price as 0 and show as "Unknown item"
        Menu::find($menus[11])->delete();

        $statuses = [['completed', 1], ['pending', 0], ['cancelled', 0], ['pending', 1], ['refunded', 1], ['refunded', 0], ['completed', 0], ['cancelled', 1]];
        $today = now()->startOfDay();
        $orderRows = [];
        for ($i = 0; $i < $orders; $i++) {
            [$status, $paid] = $statuses[$i % 8];
            $daysAgo = $i % 5 === 4 ? 31 + ($i % 20) : 1 + ($i % 25); // 20% land in the previous window
            $created = $today->copy()->subDays($daysAgo)->addMinutes(($i * 37) % 1440)->format('Y-m-d H:i:s');
            $gap = ($i % 197 === 0); // legacy, never snapshotted
            $orderRows[] = [
                'store_id' => $store->id, 'track_token' => 'T'.$store->id.'-'.$i, 'customer_name' => 'C', 'phone_number' => '1',
                'type' => 'takeaway', 'status' => $status, 'is_paid' => $paid, 'currency_code' => $currency, 'created_at' => $created,
                'subtotal_minor' => $gap ? null : 1000 + ($i * 137) % 9000, 'tax_total_minor' => $gap ? null : 0,
                'grand_total_minor' => $gap ? null : 1000 + ($i * 137) % 9000,
            ];
        }
        foreach (array_chunk($orderRows, 250) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
        $ids = DB::table('orders')->where('store_id', $store->id)->orderBy('id')->pluck('id')->all();

        $lineRows = [];
        foreach ($ids as $i => $orderId) {
            $n = 1 + ($i % 3);
            for ($j = 0; $j < $n; $j++) {
                $m = ($i + $j * 5) % 12;
                $lineRows[] = [
                    'order_id' => $orderId, 'item_id' => $menus[$m], 'quantity' => 1 + (($i + $j) % 3),
                    'variation' => isset($variants[$m]) && $j === 0 ? $variants[$m] : null,
                    'unit_price_minor' => ($i + $j) % 11 === 0 ? 777 : null,
                    'options' => ($i + $j) % 5 === 0
                        ? json_encode([['group_id' => 1, 'title' => 'G', 'selected' => [['option' => 'x', 'price_minor' => 150], ['option' => 'y', 'price' => 0.5]]]])
                        : null,
                    'currency_code' => $currency,
                ];
            }
        }
        foreach (array_chunk($lineRows, 250) as $chunk) {
            DB::table('order_items')->insert($chunk);
        }
        $extraRows = [];
        foreach (DB::table('order_items')->whereIn('order_id', $ids)->orderBy('id')->get(['id', 'item_id']) as $k => $line) {
            $m = array_search((int) $line->item_id, $menus, true);
            if (isset($extras[$m]) && $k % 3 === 0) {
                $extraRows[] = ['order_item_id' => $line->id, 'extra_id' => $extras[$m], 'quantity' => 1, 'price_minor' => $k % 2 === 0 ? 225 : null];
            }
        }
        foreach (array_chunk($extraRows, 250) as $chunk) {
            DB::table('order_item_extras')->insert($chunk);
        }

        return ['menus' => $menus];
    }

    /** @return array<string, mixed> */
    private function dashData(User $owner, Store $store): array
    {
        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])->get(route('dashboard', ['period' => '30d']));
        $response->assertOk();

        $top = array_map(fn ($t) => ['name' => $t['name'], 'qty' => $t['qty'], 'revenue' => $t['revenue']], $response->viewData('topItems'));

        return [
            'thisMonthOrders' => $response->viewData('thisMonthOrders'),
            'lastMonthOrders' => $response->viewData('lastMonthOrders'),
            'salesRevenue' => (float) $response->viewData('salesRevenue'),
            'avgOrderValue' => (float) $response->viewData('avgOrderValue'),
            'ordersSeries' => array_values($response->viewData('ordersSeries')),
            'salesSeries' => array_map('floatval', array_values($response->viewData('salesSeries'))),
            'topItems' => $top,
            'trends' => $response->viewData('trends'),
            'revenueGap' => (int) $response->viewData('revenueGap'),
        ];
    }

    public function test_golden_dashboard_numbers_for_a_2000_order_store_are_unchanged(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'USD');
        $this->fixture($store, 'USD', self::ORDERS);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $data = $this->dashData($owner, $store);
        $peak = memory_get_peak_usage() - $before;

        if (getenv('P662_DUMP')) {
            fwrite(STDERR, "\nP662_GOLDEN ".json_encode($data + ['_queries' => $queries, '_peakMB' => round($peak / 1048576, 1)], JSON_PRESERVE_ZERO_FRACTION)."\n");
            $this->markTestSkipped('dump mode');
        }

        $golden = json_decode(self::GOLDEN, true);
        $this->assertSame($golden['thisMonthOrders'], $data['thisMonthOrders']);
        $this->assertSame($golden['lastMonthOrders'], $data['lastMonthOrders']);
        $this->assertSame($golden['revenueGap'], $data['revenueGap']);
        $this->assertEqualsWithDelta($golden['salesRevenue'], $data['salesRevenue'], 0.0001);
        $this->assertEqualsWithDelta($golden['avgOrderValue'], $data['avgOrderValue'], 0.0001);
        $this->assertSame($golden['ordersSeries'], $data['ordersSeries']);
        $this->assertEqualsWithDelta($golden['salesSeries'], $data['salesSeries'], 0.0001);
        $this->assertSame($golden['trends'], $data['trends']);
        $this->assertCount(count($golden['topItems']), $data['topItems']);
        foreach ($golden['topItems'] as $k => $g) {
            $this->assertSame($g['name'], $data['topItems'][$k]['name'], "top item $k name");
            $this->assertSame($g['qty'], $data['topItems'][$k]['qty'], "top item $k qty");
            $this->assertEqualsWithDelta($g['revenue'], $data['topItems'][$k]['revenue'], 0.0001, "top item $k revenue");
        }

        // Caps: the aggregates must not hydrate the 2,000 orders (and their ~4,000 lines).
        $this->assertLessThanOrEqual(self::MAX_QUERIES, $queries, "dashboard ran $queries queries");
        $this->assertLessThanOrEqual(self::MAX_PEAK_MB, $peak / 1048576, 'dashboard peak memory (MB)');
    }

    public function test_a_three_decimal_currency_store_sums_exactly_in_minor_units(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'OMR');
        $this->fixture($store, 'OMR', 400);

        $expectedMinor = (int) DB::table('orders')->where('store_id', $store->id)->whereNotNull('grand_total_minor')
            ->whereBetween('created_at', [now()->subDays(29)->startOfDay(), now()->endOfDay()])
            ->where(fn ($q) => $q->where('status', 'completed')->orWhere('is_paid', 1))->sum('grand_total_minor');
        $gapIds = Order::where('store_id', $store->id)->whereNull('grand_total_minor')->whereBetween('created_at', [now()->subDays(29)->startOfDay(), now()->endOfDay()])
            ->where(fn ($q) => $q->where('status', 'completed')->orWhere('is_paid', 1))->get();
        $gapMinor = $gapIds->sum(fn ($o) => Money::minorFromMajor((float) $o->total, 'OMR'));

        $data = $this->dashData($owner, $store);
        $this->assertEqualsWithDelta(($expectedMinor + $gapMinor) / 1000, $data['salesRevenue'], 0.00001);
        $this->assertEqualsWithDelta($data['salesRevenue'], array_sum($data['salesSeries']), 0.0005);
    }

    public function test_a_two_currency_fleet_never_adds_currencies_into_one_figure(): void
    {
        $owner = $this->makeOwner();
        $usd = $this->makeStore($owner, 'USD');
        $omr = $this->makeStore($owner, 'OMR');
        $this->fixture($usd, 'USD', 120);
        $this->fixture($omr, 'OMR', 120);

        $response = $this->actingAs($owner)->get(route('dashboard', ['period' => '30d', 'scope' => 'all']));
        $response->assertOk();
        $sales = (float) $response->viewData('salesRevenue');

        $window = [now()->subDays(29)->startOfDay(), now()->endOfDay()];
        $rev = fn (Store $s) => (int) DB::table('orders')->where('store_id', $s->id)->whereNotNull('grand_total_minor')->whereBetween('created_at', $window)
            ->where(fn ($q) => $q->where('status', 'completed')->orWhere('is_paid', 1))->sum('grand_total_minor');
        $usdMajor = $rev($usd) / 100;
        $omrMajor = $rev($omr) / 1000;
        $blended = $usdMajor + $omrMajor;

        $this->assertNotEqualsWithDelta($blended, $sales, 0.01, 'USD and OMR were summed into one number');
        $this->assertTrue(
            abs($sales - $usdMajor) < 80 || abs($sales - $omrMajor) < 80,
            'the single figure must be ONE currency\'s total (plus its unsnapshotted rows), got '.$sales
        );
    }

    private function revenueMinor(array $storeIds, $from, $to, ?string $currency = null)
    {
        return (int) DB::table('orders')->whereIn('store_id', $storeIds)->whereNotNull('grand_total_minor')->whereBetween('created_at', [$from, $to])
            ->when($currency, fn ($q) => $q->where('currency_code', $currency))
            ->where(fn ($q) => $q->where('status', 'completed')->orWhere('is_paid', 1))->sum('grand_total_minor');
    }

    public function test_money_is_formatted_in_the_figures_currency_not_the_site_default(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'OMR');
        StoreOption::updateStoreOption($store->id, 'currency_sign', 'OMRSIGN');
        $this->fixture($store, 'OMR', 60);
        config(['settings.currency_code' => 'USD', 'settings.currency_sign' => '$']);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])->get(route('dashboard', ['period' => '30d']));
        $response->assertOk();
        $this->assertSame('OMR', $response->viewData('moneyCurrency'));
        $this->assertSame($store->id, $response->viewData('moneyStoreId'));
        $response->assertSee('OMRSIGN', false);
        $this->assertStringNotContainsString('$'.number_format((float) $response->viewData('salesRevenue'), 2), strip_tags($response->getContent()));
    }

    public function test_the_trend_compares_the_previous_window_in_the_same_currency(): void
    {
        $owner = $this->makeOwner();
        $usd = $this->makeStore($owner, 'USD');
        $omr = $this->makeStore($owner, 'OMR');
        $this->fixture($usd, 'USD', 200);
        $this->fixture($omr, 'OMR', 200);

        $response = $this->actingAs($owner)->get(route('dashboard', ['period' => '30d', 'scope' => 'all']));
        $response->assertOk();
        $cur = $response->viewData('moneyCurrency');
        $this->assertContains($cur, ['USD', 'OMR']);
        $ids = [$usd->id, $omr->id];
        $prevMinor = $this->revenueMinor($ids, now()->subDays(29)->startOfDay()->subWeek(), now()->endOfDay()->subWeek(), $cur);
        $prev = Money::majorFromMinor($prevMinor, $cur);
        $sales = (float) $response->viewData('salesRevenue');
        $this->assertGreaterThan(0, $prev);
        $this->assertSame((float) round((($sales - $prev) / $prev) * 100), (float) $response->viewData('trends')['sales']);
        $this->assertNotEmpty($response->viewData('otherCurrencies'));
    }

    public function test_the_store_own_currency_wins_over_a_bigger_foreign_amount(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'OMR');
        $this->fixture($store, 'OMR', 120);
        // a few huge JPY orders: bigger raw number, far fewer orders
        $jpy = DB::table('orders')->where('store_id', $store->id)->where('is_paid', 1)->limit(3)->pluck('id')->all();
        DB::table('orders')->whereIn('id', $jpy)->update(['currency_code' => 'JPY', 'grand_total_minor' => 90000000]);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])->get(route('dashboard', ['period' => '30d']));
        $this->assertSame('OMR', $response->viewData('moneyCurrency'));
        $this->assertArrayHasKey('JPY', $response->viewData('otherCurrencies'));
        $response->assertSee('data-other-currencies', false);
    }

    public function test_orders_without_a_currency_stamp_use_their_stores_currency(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'OMR');
        $this->fixture($store, 'OMR', 120);
        DB::table('orders')->where('store_id', $store->id)->update(['currency_code' => null]);
        config(['settings.currency_code' => 'USD']);

        $data = $this->dashData($owner, $store);
        $expected = $this->revenueMinor([$store->id], now()->subDays(29)->startOfDay(), now()->endOfDay());
        $gap = Order::where('store_id', $store->id)->whereNull('grand_total_minor')->whereBetween('created_at', [now()->subDays(29)->startOfDay(), now()->endOfDay()])
            ->where(fn ($q) => $q->where('status', 'completed')->orWhere('is_paid', 1))->get()
            ->sum(fn ($o) => Money::minorFromMajor((float) $o->total, 'OMR'));
        $this->assertEqualsWithDelta(($expected + $gap) / 1000, $data['salesRevenue'], 0.00001);
        $this->assertNotEmpty($data['topItems']);
    }

    private const MAX_QUERIES = 220;

    private const MAX_PEAK_MB = 25;

    private const GOLDEN = '{"thisMonthOrders":1600,"lastMonthOrders":1300,"salesRevenue":54279.33,"avgOrderValue":54.28,"ordersSeries":[0,0,0,0,0,80,80,80,80,0,80,80,80,80,0,80,80,80,80,0,80,80,80,80,0,80,80,80,80,0],"salesSeries":[0.0,0.0,0.0,0.0,0.0,2498.0,2842.0,2776.0,2400.0,0.0,2768.0,2522.0,2868.08,2670.0,0.0,2937.94,2572.0,2736.0,2850.0,0.0,2858.0,2668.91,2556.0,2850.0,0.0,2638.0,2932.0,2664.38,2672.02,0.0],"topItems":[{"name":"Dish 0","qty":665,"revenue":5746.47},{"name":"Dish 9","qty":665,"revenue":11790.7},{"name":"Dish 8","qty":399,"revenue":6648.72},{"name":"Unknown Item","qty":399,"revenue":501.03},{"name":"Dish 4","qty":399,"revenue":4404.72}],"trends":{"scans":0,"orders":23.0,"sales":18.0,"aov":0.0},"revenueGap":8}';

    /** Captured from the pre-change controller (2026-10-13): 819 queries, 56.4 MB peak. */
}
