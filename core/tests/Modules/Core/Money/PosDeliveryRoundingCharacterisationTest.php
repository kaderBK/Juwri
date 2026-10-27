<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Driver;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Laundry\Services\Laundry\ServiceBookingService;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\ComboSlot;
use Juwri\Modules\Menu\Models\ComboSlotOption;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Combos\ComboService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P1-05 slice 10a-3 (characterisation): the money rounding of the POS (cash change, refunds, shift expected cash and
 * over/short, the sales report, the register's order-cart unit prices, the customer display subtotal), combos
 * (configurator payload + resolved unit price), laundry per-kg lines and the driver earnings API, for USD, KWD and
 * JPY stores, with .5 edges exact in binary so PHP 8.3 and 8.4 round() agree. Written BEFORE the bare round() calls
 * moved to the Money layer; values that changed for 3-decimal currencies are marked "before:".
 */
class PosDeliveryRoundingCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.currency_code' => 'USD', 'settings.admin_allow_combos' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function store(string $currency, array $attrs = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(array_merge([
            'user_id' => $owner->id, 'slug' => 'p-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'R', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ], $attrs));
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    private function item(Store $store, float $price, array $attrs = []): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'C']);

        return Menu::create(array_merge(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'I'.Str::random(4), 'price' => $price, 'active' => 1], $attrs));
    }

    private function manager(Store $store): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id, 'name' => 'M', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
        ]);
    }

    public function test_pos_cash_change_refund_and_shift_close(): void
    {
        // [currency, price, tendered, change, refund in, refund out, opening float, counted, expected, over/short]
        foreach ([
            ['USD', 10.125, 20, 9.87, 2.125, 2.13, 50, 57.875, 58.0, -0.13],
            ['KWD', 1.235, 2, 0.765, 0.0625, 0.063, 5, 6.0, 6.172, -0.172],
            ['JPY', 1001, 2000, 999.0, 100.5, 101.0, 5000, 5899.5, 5900.0, -1.0],
        ] as [$cur, $price, $tendered, $change, $refundIn, $refundOut, $float, $counted, $expected, $overShort]) {
            $store = $this->store($cur);
            $manager = $this->manager($store);
            $shift = PosService::openShift($store, $manager, null, $float);
            $order = PosService::createOrder($store, $manager, ['type' => 'takeaway', 'items' => [['item_id' => $this->item($store, $price)->id, 'quantity' => 1]]]);
            $order = PosService::settle($order, 'cash', $tendered);
            $this->assertSame($change, (float) $order->fresh()->change_due, $cur.' change');
            $adj = PosService::refund($order->fresh(), $manager, $refundIn, 'cash', 'Spilled');
            $this->assertSame($refundOut, (float) $adj->fresh()->amount, $cur.' refund');
            PosService::closeShift($shift->fresh(), $counted);
            $this->assertSame([$expected, $overShort], [(float) $shift->fresh()->expected_cash, (float) $shift->fresh()->over_short], $cur.' shift');
        }
    }

    /** @return array<string, array{0: string, 1: float, 2: float, 3: float}> */
    public static function registerCases(): array
    {
        // KWD before: unit price / cash / card / cart / display 1.24 (a hard-coded 2 decimals); the 2.47 total kept.
        return ['USD' => ['USD', 10.125, 10.13, 20.26], 'KWD' => ['KWD', 1.235, 1.235, 2.47]];
    }

    #[DataProvider('registerCases')]
    public function test_pos_sales_report_order_cart_and_display(string $cur, float $price, float $unit, float $total): void
    {
        $store = $this->store($cur);
        $manager = $this->manager($store);
        $item = $this->item($store, $price);
        PosService::settle(PosService::createOrder($store, $manager, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]), 'cash', 50);
        PosService::settle(PosService::createOrder($store, $manager, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]), 'card');
        $res = $this->actingAs($manager, 'staff')->getJson(route('pos.sales'))->assertOk();
        $this->assertSame([$total, $unit, $unit], [(float) $res->json('total'), (float) $res->json('sales_by_method.cash'), (float) $res->json('sales_by_method.card')], $cur.' report');

        $open = PosService::createOrder($store, $manager, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        $cart = $this->actingAs($manager, 'staff')->getJson(route('pos.orders.cart', $open->id))->assertOk();
        $this->assertSame($unit, (float) $cart->json('lines.0.unitPrice'), $cur.' cart unit price');
        $display = $this->actingAs($manager, 'staff')->getJson(route('pos.display.state'))->assertOk();
        $this->assertSame($unit, (float) $display->json('subtotal'), $cur.' display subtotal');
    }

    public function test_combo_payload_and_resolved_price(): void
    {
        foreach ([['USD', 15.125, 0.375, 15.13, 0.38, 15.51], ['KWD', 4.125, 0.0625, 4.125, 0.063, 4.188]] as [$cur, $price, $up, $payPrice, $payUp, $unit]) {
            $store = $this->store($cur);
            StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');
            $main = $this->item($store, 10);
            $combo = Combo::create(['store_id' => $store->id, 'name' => 'Meal', 'price' => $price, 'active' => true, 'position' => 0]);
            $slot = ComboSlot::create(['combo_id' => $combo->id, 'store_id' => $store->id, 'title' => 'Main', 'min_select' => 1, 'max_select' => 1, 'position' => 0]);
            $opt = ComboSlotOption::create(['combo_slot_id' => $slot->id, 'combo_id' => $combo->id, 'store_id' => $store->id, 'menu_id' => $main->id, 'upcharge' => $up, 'active' => true, 'position' => 0]);
            $payload = ComboService::payload($combo->fresh());
            $this->assertSame([$payPrice, $payUp], [$payload['price'], $payload['slots'][0]['options'][0]['upcharge']], $cur);
            $resolved = ComboService::resolve((int) $store->id, [['combo_id' => $combo->id, 'quantity' => 1, 'picks' => [['slot_id' => $slot->id, 'option_ids' => [$opt->id]]]]]);
            $this->assertSame($unit, $resolved[0]['unit_price'], $cur);
        }
    }

    public function test_laundry_per_kg_lines(): void
    {
        // Bug 6: the goods cart no longer cuts the per-kg price to 2 decimals (GoodsCartService): KWD 1.125 stays 1.125.
        // KWD before slice 10a: 2.82; after 10a-3: 2.825 (1.13 × 2.5 at 3 decimals); after bug 6: 2.813
        // (1.125 × 2.5 = 2.8125, HALF_UP at 3 decimals).
        foreach ([['USD', 5.25, 13.13], ['KWD', 1.125, 2.813]] as [$cur, $perKg, $lineTotal]) {
            $store = $this->store($cur, ['store_type' => 'laundry']);
            $wash = $this->item($store, $perKg, ['pricing_unit' => 'kg']);
            $svc = app(ServiceBookingService::class);
            $token = Str::random(32);
            $svc->addLine($store, $token, $wash->id, null, 2.5);
            $this->assertSame($perKg, $svc->lines($store, $token)->first()['unit_price'], $cur.' per-kg unit price (KWD before bug 6: 1.13)');
            $this->assertSame($lineTotal, $svc->lines($store, $token)->first()['line_total'], $cur.' 2.5 kg line');
            $this->assertSame($lineTotal, $svc->total($store, $token), $cur.' cart total');
        }
    }

    public function test_driver_earnings(): void
    {
        foreach ([['USD', 10.125, 1.125, 11.26, 1.13], ['KWD', 1.235, 0.0625, 1.298, 0.063]] as [$cur, $price, $tip, $total, $tips]) {
            $store = $this->store($cur);
            $raw = Str::random(40);
            $driver = Driver::create([
                'owner_id' => null, 'store_id' => null, 'name' => 'D'.Str::random(4), 'phone' => UniquePhone::next('05'),
                'token' => hash('sha256', $raw), 'active' => true, 'is_fleet' => true, 'city_id' => 1, 'shift_status' => 'online',
                'capacity' => 2, 'active_load' => 0,
            ]);
            $order = Order::create([
                'store_id' => $store->id, 'type' => 'delivery', 'status' => 'new', 'address' => '1', 'driver_id' => $driver->id,
                'delivery_status' => 'delivered', 'delivered_at' => now(), 'tip_amount' => $tip, 'created_at' => now(),
            ]);
            OrderItem::create(['order_id' => $order->id, 'item_id' => $this->item($store, $price)->id, 'quantity' => 1]);
            // KWD before: total 1.3, tips 0.06 (a hard-coded 2 decimals on 1.235 + 0.063)
            $res = $this->getJson('/api/v1/driver/earnings', ['Authorization' => 'Bearer '.$raw, 'Accept' => 'application/json'])->assertOk();
            $this->assertSame([$total, $tips], [(float) $res->json('summary.total'), (float) $res->json('summary.tips')], $cur.' summary');
            $this->assertSame([$total, $tips], [(float) $res->json('data.0.total'), (float) $res->json('data.0.tips')], $cur.' day');
            $this->assertSame([$total, $tips], [(float) $res->json('summary.by_currency.0.total'), (float) $res->json('summary.by_currency.0.tips')], $cur.' by currency');
        }
    }
}
