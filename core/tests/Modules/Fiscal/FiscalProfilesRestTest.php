<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Export\FakeAnceSigner;
use Juwri\Modules\Fiscal\Services\Export\FiscalExport;
use Juwri\Modules\Fiscal\Services\Export\TeifExport;
use Juwri\Modules\Fiscal\Services\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\FiscalRegistry;
use Tests\Support\TestPlans;
use Tests\TestCase;

class FiscalProfilesRestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        $_SERVER += ['SERVER_SOFTWARE' => 'phpunit', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'SERVER_ADDR' => '127.0.0.1', 'SERVER_PORT' => '80', 'HTTP_HOST' => 'localhost'];
        config(['settings.currency_sign' => '$', 'settings.currency_pos' => 1]);
        View::share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        View::share(['admin_languages' => collect(), 'notifications' => collect(), 'totalUnreadNotifications' => 0, 'totalUnapprovedComments' => 0]);
    }

    private function ctx(): FiscalReceiptContext
    {
        return new FiscalReceiptContext(
            sellerName: 'Tunis Café', vatNumber: '1234567A', crNumber: null,
            total: 119.0, taxAmount: 19.0, issuedAt: new \DateTimeImmutable('2026-07-12T10:00:00+01:00'),
            currency: 'TND',
        );
    }

    public function test_tunisia_teif_export_and_ance_stub(): void
    {
        $xml = TeifExport::teif($this->ctx(), ['number' => 'TN-9']);
        $this->assertStringContainsString('<TEIF', $xml);
        $this->assertStringContainsString('TN-9', $xml);
        $this->assertStringContainsString('<CurrencyCode>TND</CurrencyCode>', $xml);
        $this->assertTrue(new \DOMDocument()->loadXML($xml));

        $signer = new FakeAnceSigner;
        $this->assertSame('ANCE', $signer->authority());
        $this->assertStringStartsWith('ANCE-SIG:', $signer->sign($xml));
    }

    public function test_morocco_uses_generic_ubl_seam(): void
    {
        $ctx = new FiscalReceiptContext(
            sellerName: 'Casa Grill', vatNumber: 'MA1', crNumber: null, total: 120, taxAmount: 20,
            issuedAt: new \DateTimeImmutable, currency: 'MAD',
        );
        $xml = FiscalExport::ubl($ctx, ['number' => 'MA-1']);
        $this->assertStringContainsString('MAD', $xml);
        $this->assertSame(20.0, FiscalRegistry::profile('MA')->defaultVatRate());
    }

    public function test_iraq_optional_preset_and_null_kuwait_algeria(): void
    {
        $this->assertSame(10.0, FiscalRegistry::profile('IQ')->suggestedTaxRate());
        $this->assertSame(0.0, FiscalRegistry::profile('IQ')->defaultVatRate());
        $this->assertSame([], FiscalRegistry::profile('KW')->requiredReceiptFields());
        $this->assertSame([], FiscalRegistry::profile('DZ')->requiredReceiptFields());
    }

    public function test_null_profile_receipt_unchanged_when_master_off(): void
    {
        config(['settings.admin_allow_fiscalization' => false]);
        $r = $this->storeFixture('SA');
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $html = trim(View::make('fiscal.receipt-block', ['store' => $r, 'order' => $order])->render());
        $this->assertSame('', $html, 'master OFF hides all fiscal blocks');
    }

    public function test_per_country_switch_off_hides_block(): void
    {
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_sa' => false]);
        $r = $this->storeFixture('SA');
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $html = trim(View::make('fiscal.receipt-block', ['store' => $r, 'order' => $order])->render());
        $this->assertSame('', $html);

        config(['settings.admin_allow_fiscal_sa' => true]);
        $html2 = View::make('fiscal.receipt-block', ['store' => $r, 'order' => $order])->render();
        $this->assertStringContainsString('Diner Co', $html2);
    }

    public function test_superadmin_compliance_page_aggregates(): void
    {
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_sa' => true]);
        $r = $this->storeFixture('SA');
        FiscalDocument::create(['store_id' => $r->id, 'country' => 'SA', 'type' => 'simplified', 'status' => 'accepted', 'total' => 1, 'tax_total' => 0]);
        FiscalDocument::create(['store_id' => $r->id, 'country' => 'SA', 'type' => 'simplified', 'status' => 'rejected', 'total' => 1, 'tax_total' => 0]);

        $admin = User::factory()->create(['group_id' => '1', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $res = $this->actingAs($admin)->get(route('admin.fiscal.compliance'))->assertOk();
        $res->assertSeeHtml(___('Per-restaurant compliance'));
        $res->assertSeeHtml('Diner Co');
    }

    private function storeFixture(string $country): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create(['user_id' => $u->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Diner Co', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($r->id, 'fiscal_country', $country);
        StoreOption::updateStoreOption($r->id, 'fiscal_vat_number', '300000000000003');
        StoreOption::updateStoreOption($r->id, 'fiscal_legal_name', 'Diner Co');

        return $r;
    }
}
