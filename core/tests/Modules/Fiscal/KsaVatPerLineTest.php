<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Store\StoreSettingsService;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Accounting\Services\Accounting\Gl\OrderJournalPoster;
use Juwri\Modules\Fiscal\Http\Controllers\Admin\FiscalComplianceController;
use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\InvoiceHash;
use Juwri\Modules\Fiscal\Services\Zatca\UblBuilder;
use Juwri\Modules\Fiscal\Services\Zatca\UblInvoice;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Juwri\Modules\Ordering\Services\Tax\TaxEngine;
use Juwri\Modules\Ordering\Services\Tax\TaxSnapshot;
use Juwri\Modules\Pos\Services\Printing\EscPosRenderer;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-186 (J44): KSA "VAT per line" — default ON, whole-order VAT when the owner turns it off, platform force, new
 * orders only. In BOTH modes, with a discount: printed receipt VAT == UBL invoice VAT == order VAT == journal VAT.
 *
 * Basket: three items of 1.03 SAR, 0.03 discount, 15%. Per line: 102 × 15% = 15.3 → 15, ×3 = 0.45.
 * Whole order: 306 × 15% = 45.9 → 0.46. The two modes differ, so each test proves which one ran.
 */
class KsaVatPerLineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_sa' => true, 'settings.admin_allow_accounting_gl' => true]);
        $this->app->instance(ZatcaClient::class, new FakeZatcaClient);
        View::share(['settings' => (object) []]);
        Queue::fake();
        PlatformSettings::set(TaxEngine::FORCE_KSA_LINE, false); // J44a: forced by default — these tests run unforced unless they say so
    }

    /** @param array<string,mixed> $options */
    private function store(array $options = []): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $u->id, 'slug' => 'k-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Diner', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($options + ['fiscal_country' => 'SA', 'fiscal_legal_name' => 'Diner', 'fiscal_vat_number' => '399999999900003', 'currency_code' => 'SAR', 'restaurant_taxx_charge' => '15'] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    private function order(Store $store): Order
    {
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new', 'payment_method' => 'card', 'discount_amount' => 0.03]);
        foreach (['A', 'B', 'C'] as $name) {
            $menu = Menu::create(['store_id' => $store->id, 'name' => $name, 'price' => 1.03, 'active' => '1']);
            OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1]);
        }
        $order = $order->fresh();
        $order->snapshotTotals();

        return $order->fresh();
    }

    private function invoiceVatMinor(Store $store, Order $order): int
    {
        $fiscal = FiscalInvoiceMath::compute(OrderFiscalContext::for($store, $order), 2, dropOutOfScope: true);
        $xml = (new UblBuilder)->build(new UblInvoice(
            type: 'simplified', number: 'K-1', uuid: '3cf5ee18-ee25-44ea-a444-2c37ba7f28be', issuedAt: new \DateTimeImmutable('2026-10-18T12:00:00+03:00'),
            currency: 'SAR', sellerName: 'Diner', sellerVat: '399999999900003', sellerCr: null, icv: 1, previousHash: null,
            lines: [], total: (float) $order->computeMoney()['grand'], taxTotal: FiscalInvoiceMath::major($fiscal['tax_total'], 2), fiscal: $fiscal,
            sellerAddress: ZatcaUblTest::SELLER_ADDRESS,
        ));
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($xml));
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('cac', InvoiceHash::NS_CAC);
        $xp->registerNamespace('cbc', InvoiceHash::NS_CBC);
        $this->assertSame((int) $order->grand_total_minor, (int) round((float) $xp->evaluate('string(/*/cac:LegalMonetaryTotal/cbc:PayableAmount)') * 100), 'payable = what the customer paid');
        $sumLines = 0;
        foreach ($xp->query('/*/cac:InvoiceLine/cac:TaxTotal/cbc:TaxAmount') as $n) {
            $sumLines += (int) round((float) $n->textContent * 100);
        }
        $doc = (int) round((float) $xp->evaluate('string(/*/cac:TaxTotal[1]/cbc:TaxAmount)') * 100);
        $this->assertSame($doc, $sumLines, 'Σ line VAT = document VAT');

        return $doc;
    }

    private function receiptVatMinor(Order $order): int
    {
        $out = (new EscPosRenderer)->render($order);
        // the KSA receipt prints RTL (amount first): the tax row is the one right after the 3.09 subtotal row
        $this->assertMatchesRegularExpression('/\n3\.09 SAR[^\n]*\n([0-9.,]+) SAR/u', $out);
        preg_match('/\n3\.09 SAR[^\n]*\n([0-9.,]+) SAR/u', $out, $m);

        return (int) round((float) str_replace(',', '', $m[1]) * 100);
    }

    private function journalVatMinor(Order $order): int
    {
        $j = OrderJournalPoster::postOrderPaid($order);
        $this->assertNotNull($j);
        $j->load('lines.account');

        return (int) round((float) $j->lines->first(fn ($l) => $l->account->code === '2200')->credit * 100);
    }

    /** @return array{order: int, receipt: int, invoice: int, journal: int, snapshot: int, fiscal: int} */
    private function vats(Store $store, Order $order): array
    {
        return [
            'order' => (int) $order->tax_total_minor,
            'receipt' => $this->receiptVatMinor($order),
            'snapshot' => (int) round(TaxSnapshot::total($order) * 100),
            'fiscal' => (int) round(OrderFiscalContext::sale($store, $order)->taxAmount * 100),
            'invoice' => $this->invoiceVatMinor($store, $order),
            'journal' => $this->journalVatMinor($order),
        ];
    }

    private function assertOneVat(int $expected, array $vats): void
    {
        foreach ($vats as $where => $v) {
            $this->assertSame($expected, $v, "$where VAT");
        }
    }

    public function test_ksa_default_is_vat_per_line_and_every_surface_agrees_with_a_discount(): void
    {
        $store = $this->store();
        $this->assertSame(TaxEngine::MODE_LINE, TaxEngine::storeMode(store_options($store->id)));
        $order = $this->order($store);

        $this->assertSame('line', TaxEngine::snapshot($order)['mode']);
        $this->assertOneVat(45, $this->vats($store, $order));
        $this->assertSame(309 - 3 + 45, (int) $order->grand_total_minor);
    }

    public function test_whole_order_vat_when_the_owner_turns_it_off_every_surface_agrees(): void
    {
        $store = $this->store(['tax_engine' => 'order']);
        $this->assertSame(TaxEngine::MODE_ORDER, TaxEngine::storeMode(store_options($store->id)));
        $order = $this->order($store);

        $this->assertSame('order', TaxEngine::snapshot($order)['mode']);
        $this->assertOneVat(46, $this->vats($store, $order));
        $this->assertSame(309 - 3 + 46, (int) $order->grand_total_minor);
    }

    public function test_whole_order_vat_with_prices_including_vat(): void
    {
        $store = $this->store(['tax_engine' => 'order', 'tax_prices_include' => '1']);
        $order = $this->order($store);
        // 306 gross × 15/115 = 39.9 → 40, shared over the lines; payable = 3.06 (VAT inside)
        $this->assertSame(306, (int) $order->grand_total_minor);
        $this->assertSame(40, (int) $order->tax_total_minor);
        $this->assertSame(40, $this->invoiceVatMinor($store, $order));
        $this->assertSame(40, $this->journalVatMinor($order));
    }

    public function test_toggling_never_changes_a_placed_order(): void
    {
        $store = $this->store();
        $line = $this->order($store);
        $svc = app(StoreSettingsService::class);

        $svc->applyOptions($store, ['tax_engine' => 'order']);
        StoreSettings::flush();
        $this->assertSame(TaxEngine::MODE_ORDER, TaxEngine::storeMode(store_options($store->id)));
        $line = $line->fresh();
        $line->snapshotTotals();
        $line = $line->fresh();
        $this->assertSame(45, (int) $line->tax_total_minor, 'the line-mode order keeps its VAT');
        $this->assertOneVat(45, $this->vats($store, $line));

        $whole = $this->order($store);
        $this->assertSame(46, (int) $whole->tax_total_minor, 'a new order takes the new mode');

        $svc->applyOptions($store, ['tax_engine' => 'vat']);
        StoreSettings::flush();
        $whole = $whole->fresh();
        $whole->snapshotTotals();
        $this->assertSame(46, (int) $whole->fresh()->tax_total_minor, 'the whole-order order keeps its VAT');
    }

    public function test_a_store_moving_onto_ksa_keeps_its_legacy_orders(): void
    {
        $store = $this->store(['fiscal_country' => '']);
        $this->assertNull(TaxEngine::storeMode(store_options($store->id)));
        $legacy = $this->order($store);
        $this->assertSame(46, (int) $legacy->tax_total_minor, 'legacy: 15% of the pre-discount 3.09');

        app(StoreSettingsService::class)->applyOptions($store, ['fiscal_country' => 'SA']);
        StoreSettings::flush();
        $legacy = $legacy->fresh();
        $this->assertTrue(TaxEngine::legacyMarked($legacy));
        $this->assertFalse(TaxEngine::applies($legacy));
        $legacy->snapshotTotals();
        $this->assertSame(46, (int) $legacy->fresh()->tax_total_minor, 'issued order not recalculated');

        $this->assertSame(45, (int) $this->order($store)->tax_total_minor, 'new order: VAT per line');
    }

    public function test_platform_force_overrides_the_owner_and_locks_the_toggle(): void
    {
        $store = $this->store(['tax_engine' => 'order']);
        $placed = $this->order($store);
        $this->assertFalse(TaxEngine::ownerToggleLocked(store_options($store->id)));

        PlatformSettings::set(TaxEngine::FORCE_KSA_LINE, true);
        $this->assertTrue(TaxEngine::ownerToggleLocked(store_options($store->id)));
        $this->assertSame(TaxEngine::MODE_LINE, TaxEngine::storeMode(store_options($store->id)));
        $this->assertSame(45, (int) $this->order($store)->tax_total_minor);

        // the owner cannot change the stored choice while locked
        app(StoreSettingsService::class)->applyOptions($store, ['tax_engine' => 'vat']);
        StoreSettings::flush();
        $this->assertSame('order', (string) store_options($store->id, 'tax_engine'));

        // a placed order keeps its method
        $placed = $placed->fresh();
        $placed->snapshotTotals();
        $this->assertSame(46, (int) $placed->fresh()->tax_total_minor);

        // a non-KSA store is not touched by the switch
        $other = $this->store(['fiscal_country' => 'AE', 'tax_engine' => 'order']);
        $this->assertSame(TaxEngine::MODE_ORDER, TaxEngine::storeMode(store_options($other->id)));

        PlatformSettings::set(TaxEngine::FORCE_KSA_LINE, false);
        $this->assertSame(TaxEngine::MODE_ORDER, TaxEngine::storeMode(store_options($store->id)));
    }

    public function test_backfill_migration_turns_vat_per_line_on_for_existing_ksa_stores(): void
    {
        $ksa = $this->store(['fiscal_country' => '']);
        $legacy = $this->order($ksa);
        $chosen = $this->store(['fiscal_country' => '', 'tax_engine' => 'order']);
        $other = $this->store(['fiscal_country' => 'AE']);
        // the stores become KSA behind the settings service (as an old install would have them)
        foreach ([$ksa, $chosen] as $s) {
            $row = \DB::table('stores')->where('id', $s->id)->value('settings');
            $j = json_decode((string) $row, true);
            $j['fiscal']['country'] = 'SA';
            \DB::table('stores')->where('id', $s->id)->update(['settings' => json_encode($j)]);
        }

        (require base_path('app/Modules/Ordering/Database/Migrations/2026_10_18_200000_ksa_vat_per_line_default.php'))->up();
        StoreSettings::flush();

        $this->assertSame('vat', (string) store_options($ksa->id, 'tax_engine'));
        $this->assertSame('order', (string) store_options($chosen->id, 'tax_engine'), 'an explicit choice is kept');
        $this->assertSame('', (string) store_options($other->id, 'tax_engine'));
        $legacy = $legacy->fresh();
        $this->assertTrue(TaxEngine::legacyMarked($legacy));
        $legacy->snapshotTotals();
        $this->assertSame(46, (int) $legacy->fresh()->tax_total_minor);
        $this->assertSame(45, (int) $this->order($ksa)->tax_total_minor);
    }

    public function test_admin_switch_saves_the_platform_setting(): void
    {
        PlatformSettings::forget(TaxEngine::FORCE_KSA_LINE);
        $this->assertTrue(TaxEngine::ksaForced(), 'J44a: default ON');
        $this->assertSame(TaxEngine::FORCE_KSA_LINE, FiscalComplianceController::FORCE_KSA_LINE);
        $c = new FiscalComplianceController;
        $c->ksaVatPerLine(Request::create('/', 'POST', ['force' => '1']));
        $this->assertTrue(PlatformSettings::bool(TaxEngine::FORCE_KSA_LINE));
        $c->ksaVatPerLine(Request::create('/', 'POST', []));
        $this->assertFalse(PlatformSettings::bool(TaxEngine::FORCE_KSA_LINE));
    }
}
