<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Fiscal\Services\FiscalProfile;
use Juwri\Modules\Fiscal\Services\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\FiscalRegistry;
use Juwri\Modules\Fiscal\Services\Profiles\SaProfile;
use Juwri\Modules\Fiscal\Services\Tlv;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * TAX-RUN F0-P1: profile registry, identity, receipt fiscal blocks + QR.
 */
class FiscalProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Master + per-country fiscal switches ON for receipt-block rendering.
        config(['settings.admin_allow_fiscalization' => true]);
        foreach (['sa', 'jo', 'eg', 'ae', 'om', 'bh', 'tn', 'ma', 'qa', 'iq', 'kw', 'dz'] as $cc) {
            config(['settings.admin_allow_fiscal_'.$cc => true]);
        }
    }

    private function storeFixture(array $options = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'My Diner', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($options as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    public function test_registry_resolves_all_thirteen_countries(): void
    {
        $this->assertCount(13, FiscalRegistry::countries());
        foreach (FiscalRegistry::countries() as $cc) {
            $this->assertInstanceOf(FiscalProfile::class, FiscalRegistry::profile($cc));
        }
        // P6-108: Libya (a launch country) can be chosen as a store's country: no VAT, no authority driver, LYD with 3 decimals.
        $ly = FiscalRegistry::profile('LY');
        $this->assertSame('LYD', $ly->currency());
        $this->assertSame(0.0, $ly->defaultVatRate());
        $this->assertSame(3, $ly->roundingScale());
        $this->assertSame(FiscalProfile::MODE_NONE, $ly->driverMode());
        $this->assertNull(FiscalRegistry::profile('ZZ'));
        $this->assertNull(FiscalRegistry::profile(''));
    }

    public function test_live_driver_countries_are_exactly_sa_jo_eg(): void
    {
        $live = FiscalRegistry::liveDriverCountries();
        sort($live);
        $this->assertSame(['EG', 'JO', 'SA'], $live);
    }

    public function test_driver_modes_per_country(): void
    {
        $this->assertSame(FiscalProfile::MODE_REPORT, FiscalRegistry::profile('SA')->driverMode());
        $this->assertSame(FiscalProfile::MODE_CLEAR, FiscalRegistry::profile('JO')->driverMode());
        $this->assertSame(FiscalProfile::MODE_REPORT, FiscalRegistry::profile('EG')->driverMode());
        $this->assertSame(FiscalProfile::MODE_NONE, FiscalRegistry::profile('QA')->driverMode());
    }

    public function test_ksa_vat_number_validation(): void
    {
        $sa = new SaProfile;
        $this->assertTrue($sa->validateVatNumber('300000000000003'));
        $this->assertFalse($sa->validateVatNumber('123456789012345'));
        $this->assertFalse($sa->validateVatNumber('3001'));
        $this->assertTrue($sa->validateVatNumber('')); // optional
    }

    public function test_uae_threshold_and_simplified_title(): void
    {
        $ae = FiscalRegistry::profile('AE');
        $this->assertSame(10000.0, $ae->simplifiedThreshold());
        $this->assertTrue($ae->requiresSimplifiedTitle());
        $this->assertSame(5.0, $ae->defaultVatRate());
    }

    public function test_null_profiles_have_no_receipt_fields(): void
    {
        foreach (['QA', 'KW', 'DZ', 'IQ'] as $cc) {
            $this->assertSame([], FiscalRegistry::profile($cc)->requiredReceiptFields(), $cc);
        }
    }

    public function test_zatca_five_tag_tlv_decodes_to_expected_tags(): void
    {
        $sa = new SaProfile;
        $ctx = new FiscalReceiptContext(
            sellerName: 'My Diner',
            vatNumber: '300000000000003',
            crNumber: null,
            total: 115.00,
            taxAmount: 15.00,
            issuedAt: new \DateTimeImmutable('2026-07-12T10:00:00+03:00'),
            currency: 'SAR',
        );
        $tlv = $sa->qr($ctx);
        $decoded = Tlv::decode($tlv);

        $this->assertCount(5, $decoded);
        $this->assertSame('My Diner', $decoded[1]);
        $this->assertSame('300000000000003', $decoded[2]);
        $this->assertSame('115.00', $decoded[4]);
        $this->assertSame('15.00', $decoded[5]);
    }

    public function test_driver_supplied_tlv_overrides_five_tag(): void
    {
        $sa = new SaProfile;
        $ctx = new FiscalReceiptContext(
            sellerName: 'X', vatNumber: '300000000000003', crNumber: null,
            total: 1, taxAmount: 0, issuedAt: new \DateTimeImmutable, currency: 'SAR',
            extra: ['tlv' => 'PRODUCTION_9_TAG'],
        );
        $this->assertSame('PRODUCTION_9_TAG', $sa->qr($ctx));
    }

    public function test_receipt_block_renders_for_ksa_restaurant(): void
    {
        app()->setLocale('en');
        $r = $this->storeFixture([
            'fiscal_country' => 'SA',
            'fiscal_vat_number' => '300000000000003',
            'fiscal_legal_name' => 'Al Baik Co',
            'restaurant_taxx_charge' => 15,
        ]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);

        $html = View::make('fiscal.receipt-block', ['store' => $r, 'order' => $order])->render();

        $this->assertStringContainsString('Al Baik Co', $html);
        $this->assertStringContainsString('300000000000003', $html);
        $this->assertStringContainsString('Simplified Tax Invoice', $html);
        $this->assertStringContainsString('<svg', $html); // QR rendered
    }

    public function test_receipt_block_is_empty_for_null_profile_country(): void
    {
        $r = $this->storeFixture(['fiscal_country' => 'QA']);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);

        $html = trim(View::make('fiscal.receipt-block', ['store' => $r, 'order' => $order])->render());
        $this->assertSame('', $html);
    }

    public function test_receipt_block_is_empty_when_no_country_set(): void
    {
        $r = $this->storeFixture([]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);

        $html = trim(View::make('fiscal.receipt-block', ['store' => $r, 'order' => $order])->render());
        $this->assertSame('', $html);
    }

    public function test_receipt_block_renders_arabic_title(): void
    {
        app()->setLocale('ar');
        $r = $this->storeFixture([
            'fiscal_country' => 'SA', 'fiscal_vat_number' => '300000000000003',
            'fiscal_legal_name' => 'مطعم', 'restaurant_taxx_charge' => 15,
        ]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);

        $html = View::make('fiscal.receipt-block', ['store' => $r, 'order' => $order])->render();
        $this->assertStringContainsString('فاتورة ضريبية مبسطة', $html);
        app()->setLocale('en');
    }

    public function test_context_reads_identity_and_totals(): void
    {
        $r = $this->storeFixture([
            'fiscal_country' => 'SA', 'fiscal_vat_number' => '300000000000003',
            'fiscal_cr_number' => 'CR-1', 'fiscal_legal_name' => 'Legal LLC',
        ]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);

        $ctx = OrderFiscalContext::for($r, $order);
        $this->assertSame('Legal LLC', $ctx->sellerName);
        $this->assertSame('300000000000003', $ctx->vatNumber);
        $this->assertSame('CR-1', $ctx->crNumber);
        $this->assertSame('SAR', $ctx->currency);
    }
}
