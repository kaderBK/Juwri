<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Api;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StaffMember;
use App\Services\PosService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P6-109 load retest: database queries per request on the six hot paths (storefront menu, cart quote, checkout, order
 * status, POS order create, dashboard orders list), with two guards each:
 *  - a budget (the count measured on 2026-10-13, so a new query on a hot path is a visible, reviewed change), and
 *  - N+1: the SELECT count must stay flat when the data grows (more menu items, more cart lines, more orders).
 * HOTPATH_PRINT=1 prints one JSON line per path to stderr (used to compare two commits for LOAD-RETEST-2026-10-13.md).
 */
class HotPathQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private const READ = 'sfx_demo_saffron_read_0001';

    private const WRITE = 'sfx_demo_saffron_write_0001';

    private Store $store;

    private ApiFixtures $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        $this->travelTo(Carbon::parse('2026-10-02 18:40:00', 'UTC'));
        $this->fx = new ApiFixtures;
        $owner = $this->fx->owner(['pos_enabled' => '1']);
        $this->store = $this->fx->saffron($owner);
    }

    /** @return array{0: int, 1: int} [total queries, SELECT queries] of one run of $fn after one warm-up run */
    private function measure(callable $fn): array
    {
        $fn(); // warm the per-process caches (Octane keeps them between requests)
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        $selects = count(array_filter($log, fn (array $q) => preg_match('/^\s*(select|with)\b/i', $q['query']) === 1));

        return [count($log), $selects];
    }

    private function record(string $path, int $total, int $selects): void
    {
        if (getenv('HOTPATH_PRINT')) {
            fwrite(STDERR, "\nHOTPATH ".json_encode(['path' => $path, 'queries' => $total, 'selects' => $selects])."\n");
        }
    }

    /** @return list<array<string, mixed>> */
    private function lines(int $n): array
    {
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $lines[] = $i % 2 === 0
                ? ['line_id' => 'l'.$i, 'item_id' => ApiFixtures::GRILL, 'qty' => 1 + $i, 'option_ids' => [ApiFixtures::OPT_FOR_ONE, ApiFixtures::OPT_GARLIC]]
                : ['line_id' => 'l'.$i, 'item_id' => ApiFixtures::TAWOOK, 'qty' => 1];
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    private function placeBody(int $lines): array
    {
        $body = ['order_type' => 'takeaway', 'lines' => $this->lines($lines)];
        $q = $this->postJson('/api/v1/storefront/quote', $body, ['X-Storefront-Token' => self::READ])->assertOk();

        return $body + ['quote_hash' => $q->json('quote_hash'), 'payment' => ['mode' => 'pay_later', 'method_id' => 'cash']];
    }

    private function place(int $lines): string
    {
        $r = $this->postJson('/api/v1/storefront/orders', $this->placeBody($lines), [
            'X-Storefront-Token' => self::WRITE, 'Origin' => 'https://saffron-house.example', 'Idempotency-Key' => (string) Str::uuid7(),
        ])->assertCreated();

        return (string) $r->json('order.track_token');
    }

    private function moreItems(int $n): void
    {
        $cat = MenuCategory::create(['store_id' => $this->store->id, 'name' => 'Extra '.Str::random(4), 'active' => 1]);
        for ($i = 0; $i < $n; $i++) {
            $this->fx->item($this->store, $cat, (string) Str::uuid7(), 'Extra dish '.$i, 10 + $i);
        }
    }

    public function test_storefront_menu_catalog_is_flat_as_the_menu_grows(): void
    {
        $call = fn () => $this->getJson('/api/v1/storefront/catalog', ['X-Storefront-Token' => self::READ])->assertOk();
        [$small, $smallSel] = $this->measure($call);
        $this->moreItems(25);
        [$big, $bigSel] = $this->measure($call);
        $this->record('storefront_menu_catalog', $big, $bigSel);
        $this->assertSame($smallSel, $bigSel, "catalog SELECTs grow with the menu (N+1): $smallSel -> $bigSel");
        $this->assertLessThanOrEqual(self::BUDGET['storefront_menu_catalog'], $big);
    }

    public function test_storefront_menu_home_page_is_flat_as_the_menu_grows(): void
    {
        $call = fn () => $this->get('/'.$this->store->slug)->assertOk();
        [, $smallSel] = $this->measure($call);
        $this->moreItems(25);
        [$big, $bigSel] = $this->measure($call);
        $this->record('storefront_menu_home', $big, $bigSel);
        $this->assertSame($smallSel, $bigSel, "home page SELECTs grow with the menu (N+1): $smallSel -> $bigSel");
        $this->assertLessThanOrEqual(self::BUDGET['storefront_menu_home'], $big);
    }

    public function test_cart_quote_is_flat_as_the_cart_grows(): void
    {
        $call = fn (int $n) => fn () => $this->postJson('/api/v1/storefront/quote', ['order_type' => 'takeaway', 'lines' => $this->lines($n)], ['X-Storefront-Token' => self::READ])->assertOk();
        [, $one] = $this->measure($call(1));
        [$six, $sixSel] = $this->measure($call(6));
        $this->record('cart_quote', $six, $sixSel);
        $this->assertSame($one, $sixSel, "quote SELECTs grow with the cart (N+1): $one -> $sixSel");
        $this->assertLessThanOrEqual(self::BUDGET['cart_quote'], $six);
    }

    public function test_checkout_order_create_selects_are_flat_as_the_cart_grows(): void
    {
        $call = fn (int $n) => function () use ($n) {
            $this->place($n);
        };
        [, $one] = $this->measure($call(1));
        [$four, $fourSel] = $this->measure($call(4));
        $this->record('checkout_order_create', $four, $fourSel);
        // P6-148: order placement reads the cart's items once per order (was 85 per extra line on 2026-10-07, 19 on 10-13, 0 now).
        $this->assertLessThanOrEqual(self::KNOWN_SELECTS_PER_EXTRA_LINE['checkout_order_create'], intdiv($fourSel - $one, 3), "order-create SELECTs per extra line grew: $one -> $fourSel");
        $this->assertLessThanOrEqual(self::BUDGET['checkout_order_create'], $four);
    }

    public function test_order_status_is_flat_as_the_order_grows(): void
    {
        $status = fn (string $track) => fn () => $this->getJson('/api/v1/storefront/orders/'.$track, ['X-Storefront-Token' => self::READ])->assertOk();
        [, $one] = $this->measure($status($this->place(1)));
        [$four, $fourSel] = $this->measure($status($this->place(4)));
        $this->record('order_status', $four, $fourSel);
        $this->assertSame($one, $fourSel, "order-status SELECTs grow with the lines (N+1): $one -> $fourSel");
        $this->assertLessThanOrEqual(self::BUDGET['order_status'], $four);
    }

    public function test_pos_order_create_selects_are_flat_as_the_cart_grows(): void
    {
        $staff = StaffMember::create([
            'owner_id' => $this->store->user_id, 'store_id' => $this->store->id, 'name' => 'Till', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        $ids = array_values($this->fx->legacyIds); // Hummus, Moutabal: no required choice group
        $call = fn (int $n) => function () use ($staff, $ids, $n) {
            $items = [];
            for ($i = 0; $i < $n; $i++) {
                $items[] = ['item_id' => $ids[$i % 2], 'quantity' => 1 + $i];
            }
            $this->actingAs($staff, 'staff')->postJson(route('pos.orders.store'), ['type' => 'takeaway', 'items' => $items])->assertSuccessful();
        };
        [, $one] = $this->measure($call(1));
        [$four, $fourSel] = $this->measure($call(4));
        $this->record('pos_order_create', $four, $fourSel);
        // P6-148: the POS pricing reads the order's items once (was 8 per extra line, 0 now).
        $this->assertLessThanOrEqual(self::KNOWN_SELECTS_PER_EXTRA_LINE['pos_order_create'], intdiv($fourSel - $one, 3), "POS order-create SELECTs per extra line grew: $one -> $fourSel");
        $this->assertLessThanOrEqual(self::BUDGET['pos_order_create'], $four);
    }

    /** P6-148: the same two order-write paths stay flat on the VAT engine with J32 levies on (per-line tax and levy lines). */
    public function test_order_writes_stay_flat_with_vat_engine_levies(): void
    {
        StoreSettings::setMany((int) $this->store->id, [
            'tax_engine' => 'vat', 'tax_delivery_taxable' => '1',
            'tax_levies' => [
                ['id' => 'muni', 'name' => 'Municipality fee', 'type' => 'percent', 'value' => 7.0, 'base' => 'items', 'over' => null, 'value_over' => null, 'enabled' => true],
                ['id' => 'stamp', 'name' => 'Stamp duty', 'type' => 'percent', 'value' => 0.5, 'base' => 'total', 'over' => 100.0, 'value_over' => 1.0, 'enabled' => true],
                ['id' => 'bag', 'name' => 'Bag fee', 'type' => 'fixed', 'value' => 1.25, 'base' => 'total', 'over' => null, 'value_over' => null, 'enabled' => true],
            ],
        ]);
        $this->test_checkout_order_create_selects_are_flat_as_the_cart_grows();
        $this->test_pos_order_create_selects_are_flat_as_the_cart_grows();
    }

    public function test_dashboard_orders_list_is_flat_as_the_order_count_grows(): void
    {
        $owner = User::query()->findOrFail($this->store->user_id);
        $staff = StaffMember::create([
            'owner_id' => $this->store->user_id, 'store_id' => $this->store->id, 'name' => 'Till', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        $ids = array_values($this->fx->legacyIds); // Hummus, Moutabal: no required choice group
        $make = function (int $n) use ($staff, $ids) {
            for ($i = 0; $i < $n; $i++) {
                PosService::createOrder($this->store, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $ids[0], 'quantity' => 1], ['item_id' => $ids[1], 'quantity' => 2]]]);
            }
        };
        $call = fn () => $this->actingAs($owner)->get(route('stores.orders', $this->store->id))->assertOk();
        $make(3);
        [, $fewSel] = $this->measure($call);
        $make(15);
        [$many, $manySel] = $this->measure($call);
        $this->record('dashboard_orders_list', $many, $manySel);
        $this->assertSame($fewSel, $manySel, "dashboard SELECTs grow with the orders (N+1): $fewSel -> $manySel");
        $this->assertLessThanOrEqual(self::BUDGET['dashboard_orders_list'], $many);
    }

    /**
     * Total queries per request, measured on 2026-10-13 on SQLite (23 / 24 / 24 / 171 / 15 / 89 / 52) plus 2 of slack for the MySQL and
     * PostgreSQL slices (savepoints); see LOAD-RETEST-2026-10-13.md. Raise one only with a reason in the commit message.
     * 10-07 for comparison: cart_quote 214 (6 lines), checkout_order_create 419 (4 lines), the rest the same.
     * P6-148 (2026-10-14): checkout_order_create 171 -> 112, pos_order_create 89 -> 65 (order-write lines batched).
     */
    private const BUDGET = [
        'storefront_menu_catalog' => 25,
        'storefront_menu_home' => 26,
        'cart_quote' => 26,
        'checkout_order_create' => 116, // p5r: +2 branch_hour_exceptions reads (P6-291 holiday / special hours in the open checks)
        'order_status' => 17,
        'pos_order_create' => 67,
        'dashboard_orders_list' => 55, // BL-043: +1 users.account_id read (the owner tenant scope now resolves on dashboard routes)
    ];

    /** SELECTs added per extra cart line on the two order-write paths (P6-148: none; was 19 and 8). */
    private const KNOWN_SELECTS_PER_EXTRA_LINE = ['checkout_order_create' => 0, 'pos_order_create' => 0];
}
