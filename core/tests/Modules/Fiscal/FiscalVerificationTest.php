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
use Juwri\Modules\Fiscal\Models\FiscalCertificate;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\FiscalChain;
use Juwri\Modules\Fiscal\Services\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\FiscalRegistry;
use Juwri\Modules\Fiscal\Services\FiscalSlaWatchdog;
use Juwri\Modules\Fiscal\Services\Tlv;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * TAX-RUN V-P1: cross-cutting verification pass — chain integrity torture, golden
 * decode, receipt compliance matrix, controls + scoping, cert no-leak, regression.
 */
class FiscalVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(111, 112, 113, 200, 300); // P1-04 5b: fixture store ids must be real stores
        app()->setLocale('en');
        config(['settings.admin_allow_fiscalization' => true]);
        foreach (['sa', 'jo', 'eg', 'ae', 'om', 'bh', 'tn', 'ma', 'qa', 'iq', 'kw', 'dz'] as $cc) {
            config(['settings.admin_allow_fiscal_'.$cc => true]);
        }
    }

    // ── 1. Chain-integrity torture ────────────────────────────────────────────

    public function test_sequential_issuance_has_no_gaps_or_dupes(): void
    {
        for ($i = 0; $i < 50; $i++) {
            FiscalChain::issue(111, 'DEV-1', ['country' => 'SA', 'type' => 'simplified', 'total' => 1, 'tax_total' => 0, 'currency' => 'SAR']);
        }
        $counters = FiscalDocument::where('store_id', 111)->where('device_key', 'DEV-1')->orderBy('counter')->pluck('counter')->all();
        $this->assertSame(range(1, 50), $counters);
        $this->assertCount(50, array_unique($counters));
    }

    public function test_rejection_midchain_continues_from_last_document(): void
    {
        $d1 = FiscalChain::issue(112, 'DEV-1', ['country' => 'SA', 'type' => 'simplified', 'total' => 1, 'tax_total' => 0, 'currency' => 'SAR']);
        $d1->transitionTo('queued');
        $d1->transitionTo('submitted');
        $d1->transitionTo('rejected');
        $corr = FiscalChain::correct($d1);
        $this->assertSame(2, $corr->counter);
        $this->assertSame('superseded', $d1->fresh()->status);
    }

    public function test_submitted_document_body_is_immutable(): void
    {
        $d = FiscalChain::issue(113, 'DEV-1', ['country' => 'SA', 'type' => 'simplified', 'total' => 10, 'tax_total' => 1, 'currency' => 'SAR']);
        $d->transitionTo('queued');
        $d->transitionTo('submitted');

        $this->expectException(\RuntimeException::class);
        $d->update(['total' => 999]); // sealed field → blocked
    }

    // ── 2. Golden decode ──────────────────────────────────────────────────────

    public function test_zatca_tlv_decodes_to_expected_tag_counts(): void
    {
        $five = FiscalRegistry::profile('SA')->qr(new FiscalReceiptContext(
            sellerName: 'S', vatNumber: '300000000000003', crNumber: null, total: 1, taxAmount: 0,
            issuedAt: new \DateTimeImmutable, currency: 'SAR',
        ));
        $this->assertCount(5, Tlv::decode($five));
    }

    // ── 4. Receipt compliance matrix ──────────────────────────────────────────

    public function test_qatar_receipt_unchanged_no_fiscal_block(): void
    {
        $r = $this->storeFixture('QA');
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $this->assertSame('', trim(View::make('fiscal.receipt-block', ['store' => $r, 'order' => $order])->render()));
    }

    public function test_no_country_receipt_has_no_fiscal_block(): void
    {
        $r = $this->storeFixture(null);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $this->assertSame('', trim(View::make('fiscal.receipt-block', ['store' => $r, 'order' => $order])->render()));
    }

    public function test_ksa_receipt_shows_required_fields(): void
    {
        $r = $this->storeFixture('SA');
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $html = View::make('fiscal.receipt-block', ['store' => $r, 'order' => $order])->render();
        $this->assertStringContainsString('VAT No.', $html);
        $this->assertStringContainsString('Simplified Tax Invoice', $html);
        $this->assertStringContainsString('<svg', $html);
    }

    // ── 5. Controls + scoping ─────────────────────────────────────────────────

    public function test_master_off_hides_all_blocks(): void
    {
        config(['settings.admin_allow_fiscalization' => false]);
        $r = $this->storeFixture('SA');
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $this->assertSame('', trim(View::make('fiscal.receipt-block', ['store' => $r, 'order' => $order])->render()));
    }

    public function test_cert_pem_never_leaks_into_payload_or_json(): void
    {
        $secret = '-----BEGIN EC PRIVATE KEY-----SUPERSECRETKEY-----END-----';
        FiscalCertificate::create(['store_id' => 200, 'device_key' => 'DEV', 'kind' => 'pcsid', 'pem' => $secret, 'active' => true]);
        $doc = FiscalChain::issue(200, 'DEV', ['country' => 'SA', 'type' => 'simplified', 'total' => 1, 'tax_total' => 0, 'currency' => 'SAR', 'payload' => '<Invoice/>']);

        // The secret must not appear anywhere in the document snapshot/serialization.
        $this->assertStringNotContainsString('SUPERSECRETKEY', json_encode($doc->toArray()));
        $this->assertStringNotContainsString('SUPERSECRETKEY', (string) $doc->payload);
        // Nor in the cert's own serialization (hidden).
        $cert = FiscalCertificate::first();
        $this->assertStringNotContainsString('SUPERSECRETKEY', json_encode($cert));
    }

    // ── 6. SLA + ops ──────────────────────────────────────────────────────────

    public function test_sla_watchdog_flags_overdue(): void
    {
        $d = FiscalChain::issue(300, 'DEV', ['country' => 'SA', 'type' => 'simplified', 'total' => 1, 'tax_total' => 0, 'currency' => 'SAR']);
        $d->transitionTo('queued', ['queued_at' => now()->subHours(23)]);
        $this->assertSame(1, app(FiscalSlaWatchdog::class)->run());
    }

    // ── 7. Regression coverage of the 12-country registry ─────────────────────

    public function test_all_twelve_profiles_resolve_and_declare_a_mode(): void
    {
        foreach (FiscalRegistry::countries() as $cc) {
            $p = FiscalRegistry::profile($cc);
            $this->assertContains($p->driverMode(), ['clear_before_print', 'report_after_print', 'none'], $cc);
        }
    }

    private function storeFixture(?string $country): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create(['user_id' => $u->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Diner Co', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        if ($country !== null) {
            StoreOption::updateStoreOption($r->id, 'fiscal_country', $country);
            StoreOption::updateStoreOption($r->id, 'fiscal_vat_number', '300000000000003');
            StoreOption::updateStoreOption($r->id, 'fiscal_legal_name', 'Diner Co');
        }

        return $r;
    }
}
