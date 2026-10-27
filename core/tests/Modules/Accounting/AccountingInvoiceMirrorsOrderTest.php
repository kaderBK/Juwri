<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Accounting;

use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Accounting\Jobs\SyncInvoiceJob;
use Juwri\Modules\Accounting\Services\Accounting\QuickBooksProvider;
use Juwri\Modules\Accounting\Services\Accounting\XeroProvider;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Bug (2026-10-01, accounting exports ≠ order): the Xero / QuickBooks invoice payloads priced each line at the
 * menu's CURRENT price (`$it->menu->price`) and left out the sold unit price, options, modifiers, discount, tax,
 * delivery, service charge and tip — an exported invoice could differ from what the customer paid.
 *
 * Now both mirror the STORED order in the order's own currency (Juwri\Modules\Accounting\Services\Accounting\OrderInvoice): every price
 * is read from the order's snapshots, and the menu / store settings are changed AFTER the order in every test
 * below, so a re-price from live data would show. The invoice total equals the order's stored grand total
 * (QuickBooks: exactly; Xero: at Xero's 2-decimal precision, with a rounding line when its own line rounding would
 * land off it).
 */
class AccountingInvoiceMirrorsOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
        config(['settings.currency_code' => 'USD']);
        SsrfGuard::$resolver = static fn (string $h): array => ['93.184.216.34']; // P6-68b: sync pins the host
    }

    /** @param array<string, string> $settings */
    private function store(string $currency, array $settings = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'p-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'R', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        foreach ($settings as $key => $value) {
            StoreOption::updateStoreOption($store->id, $key, $value);
        }

        return $store;
    }

    private function menu(Store $store, string $name, float $price): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'C']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => $name, 'price' => $price, 'active' => 1]);
    }

    private function settle(Order $order): Order
    {
        $order->unsetRelation('items');
        $order->snapshotTotals();

        return $order->fresh();
    }

    /** What Xero computes for the invoice: Σ round2(Quantity × UnitAmount) + Σ TaxAmount (HALF_UP). */
    private function xeroTotal(array $payload): string
    {
        $sum = BigDecimal::zero();
        foreach ($payload['LineItems'] as $li) {
            $sum = $sum->plus(BigDecimal::of((string) $li['UnitAmount'])->multipliedBy($li['Quantity'])->toScale(2, RoundingMode::HalfUp))
                ->plus(BigDecimal::of((string) ($li['TaxAmount'] ?? 0))->toScale(2, RoundingMode::HalfUp));
        }

        return (string) $sum->toScale(2);
    }

    /** Σ QuickBooks lines.amount at the currency's decimals. */
    private function qbLinesTotal(array $payload, int $decimals): string
    {
        $sum = BigDecimal::zero();
        foreach ($payload['lines'] as $l) {
            $sum = $sum->plus(BigDecimal::of((string) $l['amount']));
        }

        return (string) $sum->toScale($decimals, RoundingMode::HalfUp);
    }

    /**
     * KWD delivery order: snapshot unit price (happy hour) + option + modifier, a legacy line, 5 % tax, delivery,
     * tip and a discount. Then the menu, the modifier, the delivery fee and the tax rate all change.
     */
    private function kwdOrder(): Order
    {
        $store = $this->store('KWD', ['restaurant_taxx_charge' => '5', 'restaurant_tax_type' => 'percentage', 'restaurant_delivery_charge' => '0.500']);
        $tea = $this->menu($store, 'Tea', 1.235);
        $cake = $this->menu($store, 'Cake', 2.0);
        $honey = MenuExtra::create(['store_id' => $store->id, 'menu_id' => $tea->id, 'title' => 'Honey', 'price' => 0.05]);

        // P6-271: an order placed before the tax-after-discount rule (tax on the pre-discount subtotal) — the mirror
        // must reproduce whatever the order stored
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'delivery', 'tip_amount' => 0.25, 'discount_amount' => 0.3, 'tax_pre_discount' => true]);
        $line = OrderItem::create([
            'order_id' => $order->id, 'item_id' => $tea->id, 'quantity' => 3, 'unit_price' => 1.1,
            'options' => [['group_id' => 1, 'title' => 'Size', 'selected' => [['option' => 'Large', 'price' => 0.125]]]],
        ]);
        OrderItemExtra::create(['order_item_id' => $line->id, 'extra_id' => $honey->id, 'price' => 0.05]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $cake->id, 'quantity' => 1]);
        $order = $this->settle($order);

        // stored: subtotal 3 × (1.100 + 0.125 + 0.050) + 2.000 = 5.825; tax 5 % = 0.291; grand 5.825 + 0.500 + 0.291 + 0.250 − 0.300
        $this->assertSame('KWD', $order->currency_code);
        $this->assertSame(5825, (int) $order->subtotal_minor);
        $this->assertSame(291, (int) $order->tax_total_minor);
        $this->assertSame(6566, (int) $order->grand_total_minor);

        // Everything live changes after the sale.
        $tea->update(['price' => 9.999]);
        $honey->update(['price' => 0.9]);
        StoreOption::updateStoreOption($store->id, 'restaurant_delivery_charge', '1.000');
        StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', '10');

        return $order->fresh('items.menu');
    }

    public function test_quickbooks_kwd_invoice_mirrors_the_stored_order(): void
    {
        $qb = (new QuickBooksProvider)->buildPayload($this->kwdOrder());

        // before: currency KWD, total 6.566, lines [Tea 3 × 9.999 (live menu) = 29.997, Cake 2.0] — no option,
        // modifier, tax, delivery, tip or discount; Σ lines 31.997 ≠ total
        $this->assertSame('KWD', $qb['currency']);
        $this->assertSame('exclusive', $qb['tax_mode']);
        $this->assertSame([
            ['type' => 'item', 'description' => 'Tea (Large, Honey)', 'quantity' => 3, 'unit_amount' => 1.275, 'amount' => 3.825, 'taxable' => true],
            ['type' => 'item', 'description' => 'Cake', 'quantity' => 1, 'unit_amount' => 2.0, 'amount' => 2.0, 'taxable' => true],
            ['type' => 'delivery', 'description' => 'Delivery fee', 'quantity' => 1, 'unit_amount' => 0.5, 'amount' => 0.5, 'taxable' => false],
            ['type' => 'tip', 'description' => 'Tip', 'quantity' => 1, 'unit_amount' => 0.25, 'amount' => 0.25, 'taxable' => false],
            ['type' => 'discount', 'description' => 'Discount', 'quantity' => 1, 'unit_amount' => -0.3, 'amount' => -0.3, 'taxable' => false],
            ['type' => 'tax', 'description' => 'Tax', 'quantity' => 1, 'unit_amount' => 0.291, 'amount' => 0.291, 'taxable' => false],
        ], $qb['lines']);
        $this->assertSame(5.825, $qb['subtotal']);
        $this->assertSame(0.3, $qb['discount_total']);
        $this->assertSame(0.291, $qb['tax_total']);
        $this->assertSame(6.566, $qb['total']);
        $this->assertSame('6.566', $this->qbLinesTotal($qb, 3));
    }

    public function test_xero_kwd_invoice_mirrors_the_stored_order_at_xeros_two_decimals(): void
    {
        $xero = (new XeroProvider)->buildPayload($this->kwdOrder());

        // before: LineItems [Tea 3 × 9.999 (live menu), Cake 1 × 2.0], no LineAmountTypes, no tax/delivery/tip/discount
        $this->assertSame('KWD', $xero['CurrencyCode']);
        $this->assertSame('Exclusive', $xero['LineAmountTypes']);
        $this->assertSame([
            // tax 0.291 → Xero's 0.29, spread over the item lines by amount (largest remainder)
            ['Description' => 'Tea (Large, Honey)', 'Quantity' => 3, 'UnitAmount' => 1.275, 'AccountCode' => '200', 'TaxType' => 'OUTPUT', 'TaxAmount' => 0.19],
            ['Description' => 'Cake', 'Quantity' => 1, 'UnitAmount' => 2.0, 'AccountCode' => '200', 'TaxType' => 'OUTPUT', 'TaxAmount' => 0.1],
            ['Description' => 'Delivery fee', 'Quantity' => 1, 'UnitAmount' => 0.5, 'AccountCode' => '200', 'TaxType' => 'NONE'],
            ['Description' => 'Tip', 'Quantity' => 1, 'UnitAmount' => 0.25, 'AccountCode' => '200', 'TaxType' => 'NONE'],
            ['Description' => 'Discount', 'Quantity' => 1, 'UnitAmount' => -0.3, 'AccountCode' => '200', 'TaxType' => 'NONE'],
        ], $xero['LineItems']);
        // Xero: 3.83 + 2.00 + 0.19 + 0.10 + 0.50 + 0.25 − 0.30 = 6.57 = the order's 6.566 at Xero's 2 decimals
        $this->assertSame('6.57', $this->xeroTotal($xero));
    }

    public function test_invoice_lines_follow_the_order_lines_whatever_order_the_database_returns_them_in(): void
    {
        // Bug (PostgreSQL): order_items came back in physical order after the settle UPDATE, so Cake could precede Tea.
        $order = $this->kwdOrder();
        $expected = (new XeroProvider)->buildPayload($order)['LineItems'];
        $order->setRelation('items', $order->items->reverse()->values());

        $this->assertSame($expected, (new XeroProvider)->buildPayload($order)['LineItems']);
        $this->assertSame('Tea (Large, Honey)', $expected[0]['Description']);
    }

    public function test_xero_adds_a_rounding_line_when_its_line_rounding_lands_off_the_order_total(): void
    {
        $store = $this->store('KWD');
        $tea = $this->menu($store, 'Tea', 1.235);
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $tea->id, 'quantity' => 1, 'unit_price' => 1.235]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $tea->id, 'quantity' => 1, 'unit_price' => 1.235]);
        $order = $this->settle($order);
        $this->assertSame(2470, (int) $order->grand_total_minor);

        $xero = (new XeroProvider)->buildPayload($order->fresh('items.menu'));

        // Xero rounds each line: 1.24 + 1.24 = 2.48; the order is 2.470 → 2.47 → a −0.01 rounding line
        $this->assertCount(3, $xero['LineItems']);
        $this->assertSame([
            'Description' => 'Rounding (Xero 2-decimal amounts; order total KWD 2.470)',
            'Quantity' => 1, 'UnitAmount' => -0.01, 'AccountCode' => '200', 'TaxType' => 'NONE',
        ], $xero['LineItems'][2]);
        $this->assertSame('2.47', $this->xeroTotal($xero));

        $qb = (new QuickBooksProvider)->buildPayload($order->fresh('items.menu'));
        $this->assertSame(2.47, $qb['total']);
        $this->assertSame('2.470', $this->qbLinesTotal($qb, 3));
    }

    public function test_usd_delivery_order_with_service_charge_matches_exactly(): void
    {
        $store = $this->store('USD', ['restaurant_taxx_charge' => '8.25', 'restaurant_tax_type' => 'percentage', 'restaurant_delivery_charge' => '2.99']);
        $burger = $this->menu($store, 'Burger', 8.5);
        $bacon = MenuExtra::create(['store_id' => $store->id, 'menu_id' => $burger->id, 'title' => 'Bacon', 'price' => 1.25]);
        $order = Order::create([
            'store_id' => $store->id, 'status' => 'new', 'type' => 'delivery',
            'tip_amount' => 3, 'service_charge_amount' => 1.5, 'discount_amount' => 2,
        ]);
        $line = OrderItem::create([
            'order_id' => $order->id, 'item_id' => $burger->id, 'quantity' => 2, 'unit_price' => 7.99,
            'options' => [['group_id' => 1, 'title' => 'Cheese', 'selected' => [['option' => 'Cheddar', 'price' => 0.75]]]],
        ]);
        OrderItemExtra::create(['order_item_id' => $line->id, 'extra_id' => $bacon->id, 'price' => 1.25]);
        $order = $this->settle($order);
        // 2 × (7.99 + 0.75 + 1.25) = 19.98; P6-271 tax 8.25 % of (19.98 − 2.00) = 1.48; 19.98 + 2.99 + 1.48 + 1.50 + 3.00 − 2.00
        $this->assertSame(2695, (int) $order->grand_total_minor);

        $burger->update(['price' => 12]);
        StoreOption::updateStoreOption($store->id, 'restaurant_delivery_charge', '0');
        $order = $order->fresh('items.menu');

        $qb = (new QuickBooksProvider)->buildPayload($order);
        // before: lines [Burger 2 × 12.0 (live menu) = 24.0], total 27.12
        $this->assertSame(
            [['item', 19.98], ['delivery', 2.99], ['service_charge', 1.5], ['tip', 3.0], ['discount', -2.0], ['tax', 1.48]],
            array_map(fn ($l) => [$l['type'], $l['amount']], $qb['lines'])
        );
        $this->assertSame('Burger (Cheddar, Bacon)', $qb['lines'][0]['description']);
        $this->assertSame(26.95, $qb['total']);
        $this->assertSame('26.95', $this->qbLinesTotal($qb, 2));

        $xero = (new XeroProvider)->buildPayload($order);
        // before: [Burger 2 × 12.0]
        $this->assertSame(9.99, $xero['LineItems'][0]['UnitAmount']);
        $this->assertSame(1.48, $xero['LineItems'][0]['TaxAmount']);
        $this->assertCount(5, $xero['LineItems']); // no rounding line on a 2-decimal currency
        $this->assertSame('26.95', $this->xeroTotal($xero));
    }

    public function test_jpy_order_matches_exactly(): void
    {
        $store = $this->store('JPY', ['restaurant_taxx_charge' => '10', 'restaurant_tax_type' => 'percentage']);
        $ramen = $this->menu($store, 'Ramen', 1200);
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway', 'discount_amount' => 300]);
        OrderItem::create([
            'order_id' => $order->id, 'item_id' => $ramen->id, 'quantity' => 2, 'unit_price' => 1000,
            'options' => [['group_id' => 1, 'title' => 'Topping', 'selected' => [['option' => 'Egg', 'price' => 150]]]],
        ]);
        $order = $this->settle($order);
        // 2 × 1150 = 2300; P6-271 tax 10 % of (2300 − 300) = 200; − 300
        $this->assertSame(2200, (int) $order->grand_total_minor);
        $ramen->update(['price' => 1500]);
        $order = $order->fresh('items.menu');

        $qb = (new QuickBooksProvider)->buildPayload($order);
        // before: lines [Ramen 2 × 1500 (live menu) = 3000.0], total 2230.0
        $this->assertSame(
            [['item', 1150.0, 2300.0], ['discount', -300.0, -300.0], ['tax', 200.0, 200.0]],
            array_map(fn ($l) => [$l['type'], $l['unit_amount'], $l['amount']], $qb['lines'])
        );
        $this->assertSame(2200.0, $qb['total']);
        $this->assertSame('2200', $this->qbLinesTotal($qb, 0));

        $xero = (new XeroProvider)->buildPayload($order);
        $this->assertSame(1150.0, $xero['LineItems'][0]['UnitAmount']);
        $this->assertSame(200.0, $xero['LineItems'][0]['TaxAmount']);
        $this->assertCount(2, $xero['LineItems']);
        $this->assertSame('2200.00', $this->xeroTotal($xero));
    }

    public function test_discount_larger_than_the_order_is_capped_at_the_floored_zero_total(): void
    {
        $store = $this->store('USD');
        $tea = $this->menu($store, 'Tea', 5);
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway', 'discount_amount' => 8]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $tea->id, 'quantity' => 1, 'unit_price' => 5]);
        $order = $this->settle($order);
        $this->assertSame(0, (int) $order->grand_total_minor);

        $qb = (new QuickBooksProvider)->buildPayload($order->fresh('items.menu'));
        $this->assertSame([['item', 5.0], ['discount', -5.0]], array_map(fn ($l) => [$l['type'], $l['amount']], $qb['lines']));
        $this->assertSame(5.0, $qb['discount_total']);
        $this->assertSame(0.0, $qb['total']);
        $this->assertSame('0.00', $this->xeroTotal((new XeroProvider)->buildPayload($order->fresh('items.menu'))));
    }

    public function test_legacy_line_whose_menu_price_moved_is_reconciled_to_the_stored_subtotal(): void
    {
        $store = $this->store('USD');
        $tea = $this->menu($store, 'Tea', 3);
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $tea->id, 'quantity' => 2]); // pre-T5 line: no unit_price
        $order = $this->settle($order);
        $this->assertSame(600, (int) $order->grand_total_minor);
        $tea->update(['price' => 4]);

        $qb = (new QuickBooksProvider)->buildPayload($order->fresh('items.menu'));
        // The line carries no frozen price (only the menu's); the stored subtotal still wins.
        $this->assertSame([['item', 8.0], ['item_adjustment', -2.0]], array_map(fn ($l) => [$l['type'], $l['amount']], $qb['lines']));
        $this->assertSame(6.0, $qb['total']);
        $this->assertSame('6.00', $this->xeroTotal((new XeroProvider)->buildPayload($order->fresh('items.menu'))));
    }

    public function test_sync_job_posts_the_mirrored_invoice(): void
    {
        Http::fake(['qb.example/*' => Http::response(['id' => 'INV-9'], 200)]);
        $order = $this->kwdOrder();
        Credential::create([
            'store_id' => $order->store_id, 'feature' => 'accounting', 'provider' => 'quickbooks', 'active' => true,
            'secrets' => ['provider' => 'quickbooks', 'access_token' => 'tok', 'base_url' => 'https://qb.example/api'],
        ]);

        (new SyncInvoiceJob($order->id))->handle();

        Http::assertSent(fn ($r) => $r['total'] === 6.566 && $r['currency'] === 'KWD'
            && count($r['lines']) === 6 && $r['lines'][0]['amount'] === 3.825);
    }
}
