<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\InvoiceHash;
use Juwri\Modules\Fiscal\Services\Zatca\UblBuilder;
use Juwri\Modules\Fiscal\Services\Zatca\UblInvoice;
use Juwri\Modules\Fiscal\Services\Zatca\Vatex;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Juwri\Modules\Ordering\Services\Tax\TaxEngine;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-175: the ZATCA UBL of a real order on the J32 tax engine — S, Z and E items, a pre-VAT levy and a discount —
 * reconciles to the minor unit with the order snapshot (what the customer paid), and every line and subtotal carries
 * its tax category, rate, VAT scheme and (Z/E/O) VATEX code. Representation only: no amount differs from the order.
 */
class ZatcaUblGoldenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_sa' => true]);
        $this->app->instance(ZatcaClient::class, new FakeZatcaClient);
        View::share(['settings' => (object) []]);
        Queue::fake();
    }

    /** @param array<string,mixed> $options */
    private function store(array $options): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $u->id, 'slug' => 'z-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Diner', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($options + ['fiscal_country' => 'SA', 'fiscal_legal_name' => 'Diner', 'fiscal_vat_number' => '399999999900003', 'currency_code' => 'SAR', 'restaurant_taxx_charge' => '15', 'tax_engine' => 'vat'] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    /**
     * @param  list<array{0:string,1:float,2:int,3:?string}>  $items  name, price, qty, tax category
     * @param  array<string,mixed>  $attrs
     */
    private function order(Store $store, array $items, array $attrs = []): Order
    {
        $order = Order::create($attrs + ['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new', 'payment_method' => 'card']);
        foreach ($items as [$name, $price, $qty, $cat]) {
            $menu = Menu::create(['store_id' => $store->id, 'name' => $name, 'price' => $price, 'active' => '1']);
            if ($cat !== null) {
                $menu->forceFill(['tax_category' => $cat])->save();
            }
            OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty]);
        }
        $order = $order->fresh();
        $order->snapshotTotals();

        return $order->fresh();
    }

    private function ubl(Store $store, Order $order, string $type = 'simplified'): \DOMXPath
    {
        $fiscal = FiscalInvoiceMath::compute(OrderFiscalContext::for($store, $order), 2, dropOutOfScope: true);
        $xml = (new UblBuilder)->build(new UblInvoice(
            type: $type, number: 'G-1', uuid: '3cf5ee18-ee25-44ea-a444-2c37ba7f28be', issuedAt: new \DateTimeImmutable('2026-10-14T12:00:00+03:00'),
            currency: 'SAR', sellerName: 'Diner', sellerVat: '399999999900003', sellerCr: null, icv: 1, previousHash: null,
            lines: [], total: (float) $order->computeMoney()['grand'], taxTotal: FiscalInvoiceMath::major($fiscal['tax_total'], 2), fiscal: $fiscal,
            sellerAddress: ZatcaUblTest::SELLER_ADDRESS, buyer: $type === 'standard' ? ZatcaUblTest::BUYER : null, // P6-176
        ));
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($xml));
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('cac', InvoiceHash::NS_CAC);
        $xp->registerNamespace('cbc', InvoiceHash::NS_CBC);

        return $xp;
    }

    private static function minor(\DOMXPath $xp, string $path): int
    {
        return (int) round((float) $xp->evaluate("string($path)") * 100);
    }

    private static function sum(\DOMXPath $xp, string $path): int
    {
        $t = 0;
        foreach ($xp->query($path) as $n) {
            $t += (int) round((float) $n->textContent * 100);
        }

        return $t;
    }

    /** Every EN 16931 / KSA total rule, and the order snapshot, to the minor unit. */
    private function assertReconciles(\DOMXPath $xp, Order $order, bool $orderVat = true): void
    {
        $lmt = '/*/cac:LegalMonetaryTotal';
        $lineExt = self::minor($xp, "$lmt/cbc:LineExtensionAmount");
        $taxExcl = self::minor($xp, "$lmt/cbc:TaxExclusiveAmount");
        $taxIncl = self::minor($xp, "$lmt/cbc:TaxInclusiveAmount");
        $allow = self::minor($xp, "$lmt/cbc:AllowanceTotalAmount");
        $rounding = self::minor($xp, "$lmt/cbc:PayableRoundingAmount");
        $payable = self::minor($xp, "$lmt/cbc:PayableAmount");
        $tax = self::minor($xp, '/*/cac:TaxTotal[1]/cbc:TaxAmount');

        $this->assertSame((int) $order->grand_total_minor, $payable, 'PayableAmount = what the customer paid');
        if ($orderVat) {
            $this->assertSame((int) $order->tax_total_minor, $tax, 'TaxAmount = the order VAT');
        }
        $this->assertSame($tax, self::minor($xp, '/*/cac:TaxTotal[2]/cbc:TaxAmount'), 'second TaxTotal (SAR)');
        $this->assertSame($lineExt, self::sum($xp, '/*/cac:InvoiceLine/cbc:LineExtensionAmount'), 'BR-CO-10');
        $this->assertSame($allow, self::sum($xp, '/*/cac:AllowanceCharge[cbc:ChargeIndicator="false"]/cbc:Amount'), 'BR-CO-11');
        $this->assertSame($lineExt - $allow, $taxExcl, 'BR-CO-13');
        $this->assertSame($tax, self::sum($xp, '/*/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxAmount'), 'BR-CO-14');
        $this->assertSame($taxExcl + $tax, $taxIncl, 'BR-CO-15');
        $this->assertSame($taxIncl + $rounding, $payable, 'BR-CO-16');
        $this->assertSame($taxExcl, self::sum($xp, '/*/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount'), 'Σ TaxableAmount');
        foreach ($xp->query('/*/cac:InvoiceLine') as $line) {
            $this->assertSame(
                (int) round((float) $xp->evaluate('string(cbc:LineExtensionAmount)', $line) * 100) + (int) round((float) $xp->evaluate('string(cac:TaxTotal/cbc:TaxAmount)', $line) * 100),
                (int) round((float) $xp->evaluate('string(cac:TaxTotal/cbc:RoundingAmount)', $line) * 100), 'KSA-12 line amount with VAT');
            $this->assertSame(1, $xp->query('cac:Item/cac:ClassifiedTaxCategory[cbc:ID and cbc:Percent and cac:TaxScheme/cbc:ID="VAT"]', $line)->length, 'BT-151/152 per line');
            $this->assertSame(0, $xp->query('cac:Item/cac:ClassifiedTaxCategory/cbc:TaxExemptionReasonCode', $line)->length, 'the VATEX code sits on the subtotal only');
        }
        foreach ($xp->query('/*/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory') as $cat) {
            $id = $xp->evaluate('string(cbc:ID)', $cat);
            $this->assertSame('VAT', $xp->evaluate('string(cac:TaxScheme/cbc:ID)', $cat));
            $this->assertSame($id === 'S' ? 0 : 1, $xp->query('cbc:TaxExemptionReasonCode', $cat)->length, "VATEX on {$id}");
            $this->assertSame($id === 'S' ? 0 : 1, $xp->query('cbc:TaxExemptionReason', $cat)->length, "reason text on {$id}");
        }
        $snap = TaxEngine::snapshot($order);
        if ($snap !== null) {
            $this->assertSame((int) $snap['tax'], $tax, 'snapshot VAT');
            $levies = array_sum(array_map(fn ($l) => $l['kind'] === 'levy' ? $l['amount'] : 0, $snap['lines']));
            $this->assertSame($levies, self::sum($xp, '/*/cac:InvoiceLine[cac:Item/cbc:Name="Municipality fee"]/cbc:LineExtensionAmount'), 'levy line = snapshot levy');
        }
    }

    public function test_golden_s_z_e_levy_and_discount_reconcile_with_the_order(): void
    {
        $store = $this->store(['tax_levies' => [['id' => 'muni', 'name' => 'Municipality fee', 'type' => 'percent', 'value' => '5', 'base' => 'items', 'enabled' => '1']]]);
        $order = $this->order($store, [['Burger', 40.0, 2, null], ['Medicine', 30.0, 1, 'Z'], ['Insurance', 20.0, 1, 'E']], ['discount_amount' => 13.0]);

        // what the customer was charged (unchanged by this task): items 130 − 13 = 117, levy 5.85, VAT 10.80 + 0.88
        $this->assertSame(13453, (int) $order->grand_total_minor);
        $this->assertSame(1168, (int) $order->tax_total_minor);

        $xp = $this->ubl($store, $order);
        $this->assertReconciles($xp, $order);

        $lmt = '/*/cac:LegalMonetaryTotal';
        $this->assertSame('122.85', $xp->evaluate("string($lmt/cbc:LineExtensionAmount)"));
        $this->assertSame('0.00', $xp->evaluate("string($lmt/cbc:AllowanceTotalAmount)"));
        $this->assertSame('122.85', $xp->evaluate("string($lmt/cbc:TaxExclusiveAmount)"));
        $this->assertSame('134.53', $xp->evaluate("string($lmt/cbc:TaxInclusiveAmount)"));
        $this->assertSame('134.53', $xp->evaluate("string($lmt/cbc:PayableAmount)"));
        $this->assertSame(0, $xp->query("$lmt/cbc:PayableRoundingAmount")->length);

        $lines = [];
        foreach ($xp->query('/*/cac:InvoiceLine') as $l) {
            $lines[] = implode('|', [
                $xp->evaluate('string(cac:Item/cbc:Name)', $l), $xp->evaluate('string(cac:Item/cac:ClassifiedTaxCategory/cbc:ID)', $l),
                $xp->evaluate('string(cac:Item/cac:ClassifiedTaxCategory/cbc:Percent)', $l), $xp->evaluate('string(cbc:LineExtensionAmount)', $l),
                // P6-186: an even per-unit discount sits on the price (per unit × quantity = the line's discount)
                $xp->evaluate('string(cac:AllowanceCharge/cbc:Amount)', $l) ?: ($xp->query('cac:Price/cac:AllowanceCharge', $l)->length
                    ? number_format((float) $xp->evaluate('string(cac:Price/cac:AllowanceCharge/cbc:Amount)', $l) * (float) $xp->evaluate('string(cbc:InvoicedQuantity)', $l), 2, '.', '') : ''),
                $xp->evaluate('string(cac:TaxTotal/cbc:TaxAmount)', $l),
            ]);
        }
        $this->assertSame([
            'Burger|S|15.00|72.00|8.00|10.80',
            'Medicine|Z|0.00|27.00|3.00|0.00',
            'Insurance|E|0.00|18.00|2.00|0.00',
            'Municipality fee|S|15.00|5.85||0.88', // J32 levy: its own standard-rated line, VAT on top
        ], $lines);

        $subs = [];
        foreach ($xp->query('/*/cac:TaxTotal[1]/cac:TaxSubtotal') as $s) {
            $subs[] = implode('|', [
                $xp->evaluate('string(cac:TaxCategory/cbc:ID)', $s), $xp->evaluate('string(cac:TaxCategory/cbc:Percent)', $s),
                $xp->evaluate('string(cbc:TaxableAmount)', $s), $xp->evaluate('string(cbc:TaxAmount)', $s),
                $xp->evaluate('string(cac:TaxCategory/cbc:TaxExemptionReasonCode)', $s),
            ]);
        }
        $this->assertSame(['S|15.00|77.85|11.68|', 'Z|0.00|27.00|0.00|VATEX-SA-35', 'E|0.00|18.00|0.00|VATEX-SA-29'], $subs);
    }

    public function test_the_store_picks_its_own_vatex_code_and_amounts_do_not_move(): void
    {
        $store = $this->store(['tax_zero_vatex' => 'VATEX-SA-32', 'tax_exempt_vatex' => 'VATEX-SA-30']);
        $order = $this->order($store, [['Dates for export', 50.0, 1, 'Z'], ['Room rent', 100.0, 1, 'E'], ['Tea', 10.0, 1, null]]);
        $before = [(int) $order->grand_total_minor, (int) $order->tax_total_minor];

        $xp = $this->ubl($store, $order, 'standard');
        $this->assertReconciles($xp, $order);
        $sub = fn (string $c, string $f) => $xp->evaluate("string(/*/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory[cbc:ID='$c']/cbc:$f)");
        $this->assertSame('VATEX-SA-32', $sub('Z', 'TaxExemptionReasonCode'));
        $this->assertSame('Export of goods', $sub('Z', 'TaxExemptionReason'));
        $this->assertSame('VATEX-SA-30', $sub('E', 'TaxExemptionReasonCode'));
        $this->assertSame([16150, 150], $before);

        // a code of the wrong category never reaches a document: the settings form refuses it, and the builder stops
        $this->assertFalse(validator(['c' => 'VATEX-SA-29'], ['c' => Rule::in(array_keys(Vatex::choices('Z')))])->passes());
        StoreOption::updateStoreOption($store->id, 'tax_zero_vatex', 'VATEX-SA-29');
        $this->expectException(\RuntimeException::class);
        $this->ubl($store, $order->fresh(), 'standard');
    }

    public function test_tax_inclusive_store_with_levy_and_discount_reconciles(): void
    {
        $store = $this->store(['tax_prices_include' => '1', 'tax_levies' => [['id' => 'muni', 'name' => 'Municipality fee', 'type' => 'percent', 'value' => '3', 'base' => 'items', 'enabled' => '1']]]);
        $order = $this->order($store, [['Kabsa', 42.55, 3, null], ['Juice', 9.99, 1, null], ['Medicine', 17.35, 1, 'Z']], ['discount_amount' => 7.77]);
        $this->assertReconciles($this->ubl($store, $order), $order);
    }

    public function test_legacy_store_with_discount_reconciles_and_invoice_vat_is_the_order_tax(): void
    {
        // P6-186 (J44): a KSA store is on the engine; a legacy order is one placed before (the backfill marks it)
        $store = $this->store(['tax_engine' => '', 'fiscal_country' => '']);
        $order = $this->order($store, [['Kabsa', 42.55, 3, null], ['Juice', 9.99, 1, null]], ['discount_amount' => 7.77]);
        StoreOption::updateStoreOption($store->id, 'fiscal_country', 'SA');
        TaxEngine::freezeLegacyOrders((int) $store->id);
        StoreSettings::flush();
        $order = $order->fresh();
        $this->assertNull(TaxEngine::snapshot($order));
        $this->assertFalse(TaxEngine::applies($order));
        $xp = $this->ubl($store, $order);
        // P6-271: off the engine the order now taxes the items AFTER the discount; the discount sits on the item lines,
        // so the invoice's VAT is exactly the order's tax (ZATCA-SPEC-CHECK d2 resolved): (137.64 − 7.77) × 15 % = 19.48
        $this->assertSame(1948, (int) $order->tax_total_minor);
        $this->assertReconciles($xp, $order);
        $this->assertSame(1948, self::minor($xp, '/*/cac:TaxTotal[1]/cbc:TaxAmount'));
        $this->assertSame(0, $xp->query('/*/cac:AllowanceCharge[cbc:ChargeIndicator="false"]')->length, 'no document allowance: the discount is on the lines');
    }

    public function test_legacy_order_placed_before_p6_271_keeps_its_document_discount(): void
    {
        // off the engine: since P6-186 (J44) a KSA store is on the per-line engine unless fiscal_country is unset
        $store = $this->store(['tax_engine' => '', 'fiscal_country' => '']);
        $order = $this->order($store, [['Kabsa', 42.55, 3, null], ['Juice', 9.99, 1, null]], ['discount_amount' => 7.77, 'tax_pre_discount' => true]);
        $this->assertSame(2065, (int) $order->tax_total_minor); // 137.64 × 15 %
        $xp = $this->ubl($store, $order);
        // the pre-P6-271 rule: tax on the items before the discount, the discount off the total (an allowance that
        // carves its VAT share out — the invoice VAT is lower than the order's tax line, the payable is the same)
        $this->assertReconciles($xp, $order, orderVat: false);
        $this->assertLessThan(2065, self::minor($xp, '/*/cac:TaxTotal[1]/cbc:TaxAmount'));
        $this->assertSame(1, $xp->query('/*/cac:AllowanceCharge[cbc:ChargeIndicator="false"]/cac:TaxCategory[cbc:ID="S" and cac:TaxScheme/cbc:ID="VAT"]')->length, 'document discount as an allowance with its category');
    }
}
