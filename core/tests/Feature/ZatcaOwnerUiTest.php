<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Http\Middleware\Authenticate;
use Juwri\Modules\Fiscal\Models\FiscalCertificate;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Services\FiscalChain;
use Juwri\Modules\Fiscal\Services\Tlv;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaDocumentService;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaOnboarding;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Juwri\Modules\Pos\Models\PosOfflineDevice;
use Juwri\Modules\Pos\Models\PosOfflineZatcaInvoice;
use Juwri\Modules\Pos\Models\PosOfflineZatcaUnit;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

class ZatcaOwnerUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_fiscalization' => true]);
        config(['settings.admin_allow_fiscal_sa' => true]);
        $this->app->instance(ZatcaClient::class, new FakeZatcaClient);
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
        View::share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
    }

    private function owner(string $country = 'SA'): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $u->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Diner', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'fiscal_country', $country);
        StoreOption::updateStoreOption($r->id, 'fiscal_vat_number', '300000000000003');
        StoreOption::updateStoreOption($r->id, 'fiscal_legal_name', 'Diner Co');
        // P6-176: the national address the standard compliance samples need
        foreach (['fiscal_street' => 'King Fahd Road', 'fiscal_building_number' => '1234', 'fiscal_district' => 'Al Olaya',
            'fiscal_city' => 'Riyadh', 'fiscal_postal_code' => '12211'] as $k => $v) {
            StoreOption::updateStoreOption($r->id, $k, $v);
        }

        return [$u, $r];
    }

    public function test_console_renders_scoped_to_owner(): void
    {
        [$u, $r] = $this->owner();
        $this->withoutMiddleware();
        $res = $this->actingAs($u)->get(route('fiscal.index').'?store_id='.$r->id)->assertOk();
        $res->assertSeeHtml(___('Fiscalization'));
        $res->assertSeeHtml(___('ZATCA device onboarding'));
        $res->assertSeeHtml(___('Document log'));
    }

    public function test_route_is_gated_by_fiscalization_switch(): void
    {
        [$u, $r] = $this->owner();
        config(['settings.admin_allow_fiscalization' => false]);
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            Authenticate::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            AbuseHoldWall::class,
        ]);
        $this->actingAs($u)->get(route('fiscal.index').'?store_id='.$r->id)->assertStatus(403);
    }

    public function test_offline_tills_card_shows_each_till_of_this_store_only(): void
    {
        [$u, $r] = $this->owner();
        [, $other] = $this->owner();
        $mk = function (Store $store, string $name, int $pending) {
            $acc = (int) DB::table('stores')->where('id', $store->id)->value('account_id');
            $dev = PosOfflineDevice::query()->withoutGlobalScopes()->create(['account_id' => $acc, 'store_id' => $store->id,
                'device_id' => 'till-'.Str::lower(Str::random(6)), 'name' => $name, 'last_seen_at' => now()->subHours(2)]);
            $unit = PosOfflineZatcaUnit::query()->withoutGlobalScopes()->create(['account_id' => $acc, 'store_id' => $store->id,
                'pos_offline_device_id' => $dev->id, 'device_id' => $dev->device_id, 'status' => 'production', 'public_key' => 'pk',
                'compliance_request_id' => 'r', 'compliance_certificate' => 'c', 'compliance_secret' => 's']);
            for ($i = 1; $i <= $pending; $i++) {
                PosOfflineZatcaInvoice::query()->withoutGlobalScopes()->create(['account_id' => $acc, 'store_id' => $store->id,
                    'unit_id' => $unit->id, 'device_id' => $dev->device_id, 'icv' => $i, 'uuid' => (string) Str::uuid(), 'invoice_hash' => Str::random(44),
                    'previous_hash' => Str::random(44), 'xml' => '<Invoice/>', 'signature' => 's', 'chain' => 'ok', 'status' => 'pending',
                    'created_at' => now()->subHours(5), 'updated_at' => now()]);
            }
        };
        $mk($r, 'Front till', 3);
        $mk($other, 'Rival till', 1);
        $this->withoutMiddleware();
        $res = $this->actingAs($u)->get(route('fiscal.index').'?store_id='.$r->id)->assertOk();
        $res->assertSeeText(___('Offline tills'));
        $res->assertSeeText('Front till');
        $res->assertSeeText(___(':hours h', ['hours' => 5]));
        $res->assertDontSeeText('Rival till');
    }

    public function test_cross_tenant_document_resubmit_is_404(): void
    {
        [$u, $r] = $this->owner();
        [, $other] = $this->owner();
        $doc = FiscalChain::issue($other->id, 'EGS-1', [
            'country' => 'SA', 'type' => 'simplified', 'total' => 1, 'tax_total' => 0, 'currency' => 'SAR',
        ]);
        $this->withoutMiddleware();
        $this->actingAs($u)->post(route('fiscal.documents.resubmit', $doc->id).'?store_id='.$r->id)
            ->assertStatus(404);
    }

    public function test_onboarding_wizard_advances_device_to_production(): void
    {
        [$u, $r] = $this->owner();
        $this->withoutMiddleware();
        $this->actingAs($u)->post(route('fiscal.zatca.onboard').'?store_id='.$r->id, [
            'device_key' => 'EGS-1', 'otp' => '123456',
        ])->assertRedirect();

        $device = FiscalDevice::where('store_id', $r->id)->where('device_key', 'EGS-1')->first();
        $this->assertNotNull($device);
        $this->assertSame('production', $device->status);
    }

    public function test_onboarding_stops_before_the_code_is_spent_when_the_national_address_is_missing(): void
    {
        // P6-176: the standard compliance samples need the seller address — refused before any CSID request
        [$u, $r] = $this->owner();
        StoreOption::updateStoreOption($r->id, 'fiscal_postal_code', '');
        $this->withoutMiddleware();
        $this->actingAs($u)->post(route('fiscal.zatca.onboard').'?store_id='.$r->id, ['device_key' => 'EGS-A', 'otp' => '123456'])
            ->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains((string) $m, 'Postal code'));
        $this->assertSame(0, FiscalDevice::where('device_key', 'EGS-A')->count());
    }

    public function test_onboarding_is_refused_when_production_env_runs_on_the_demo_client(): void
    {
        config(['services.zatca.environment' => 'production', 'services.zatca.live' => false]);
        [$u, $r] = $this->owner();
        $this->withoutMiddleware();
        $this->actingAs($u)->post(route('fiscal.zatca.onboard').'?store_id='.$r->id, ['device_key' => 'EGS-P', 'otp' => '123456'])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, FiscalDevice::where('device_key', 'EGS-P')->count());
    }

    public function test_owner_can_renew_the_production_certificate_and_cannot_renew_another_stores_device(): void
    {
        [$u, $r] = $this->owner();
        [, $other] = $this->owner();
        $this->withoutMiddleware();
        $this->actingAs($u)->post(route('fiscal.zatca.onboard').'?store_id='.$r->id, ['device_key' => 'EGS-R', 'otp' => '123456'])->assertRedirect();
        $old = FiscalCertificate::resolve($r->id, 'pcsid', 'EGS-R');

        $this->actingAs($u)->post(route('fiscal.zatca.renew').'?store_id='.$r->id, ['device_key' => 'EGS-R', 'otp' => '654321'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNotSame($old->id, FiscalCertificate::resolve($r->id, 'pcsid', 'EGS-R')->id);

        $this->actingAs($u)->post(route('fiscal.zatca.renew').'?store_id='.$r->id, ['device_key' => 'EGS-NOPE', 'otp' => '654321'])
            ->assertSessionHasErrors('device_key');
    }

    public function test_onboarding_refuses_another_stores_branch(): void
    {
        // P4-01 IDOR: before, any branch id was stored on the device (and the device of that branch then issued)
        [$u, $r] = $this->owner();
        [, $other] = $this->owner();
        $foreign = Branch::create(['store_id' => $other->id, 'name' => 'Theirs', 'active' => true]);
        $own = Branch::create(['store_id' => $r->id, 'name' => 'Ours', 'active' => true]);
        $this->withoutMiddleware();
        $this->actingAs($u)->post(route('fiscal.zatca.onboard').'?store_id='.$r->id, [
            'device_key' => 'EGS-X', 'otp' => '123456', 'branch_id' => $foreign->id,
        ])->assertSessionHasErrors('branch_id');
        $this->assertSame(0, FiscalDevice::where('device_key', 'EGS-X')->count());

        $this->flushSession();
        $this->actingAs($u)->post(route('fiscal.zatca.onboard').'?store_id='.$r->id, [
            'device_key' => 'EGS-Y', 'otp' => '123456', 'branch_id' => $own->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($own->id, (int) FiscalDevice::where('store_id', $r->id)->where('device_key', 'EGS-Y')->value('branch_id'));
    }

    public function test_receipt_qr_upgrades_from_five_to_nine_tags_on_production(): void
    {
        [$u, $r] = $this->owner();

        // Pre-onboarding: 5-tag.
        $order1 = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $svc = new ZatcaDocumentService(new ZatcaCrypto);
        // Device exists but not production yet.
        FiscalDevice::create(['store_id' => $r->id, 'device_key' => 'EGS-1', 'country' => 'SA', 'status' => 'new']);
        $doc1 = $svc->issue($r, OrderFiscalContext::for($r, $order1), 'EGS-1');
        $this->assertSame(5, $doc1->authority_response['tags']);
        $this->assertCount(5, Tlv::decode($doc1->authority_response['tlv']));

        // Onboard device to production.
        $onboard = new ZatcaOnboarding(new FakeZatcaClient, new ZatcaCrypto);
        $device = FiscalDevice::where('store_id', $r->id)->where('device_key', 'EGS-1')->first();
        $onboard->requestComplianceCsid($device, '123456', ['vat' => '300000000000003', 'country' => 'SA', 'common_name' => 'EGS-1']);
        $onboard->runComplianceChecks($device->fresh(), array_fill(0, 6, ['xml' => '<Invoice/>']));
        $onboard->requestProductionCsid($device->fresh());

        // Post-onboarding: 9-tag.
        $order2 = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $doc2 = $svc->issue($r->fresh(), OrderFiscalContext::for($r->fresh(), $order2), 'EGS-1');
        $this->assertSame(9, $doc2->authority_response['tags']);
        $this->assertCount(9, Tlv::decode($doc2->authority_response['tlv']));

        // Receipt block for order2 renders the 9-tag QR (reuses stored TLV).
        $html = View::make('fiscal.receipt-block', ['store' => $r->fresh(), 'order' => $order2])->render();
        $this->assertStringContainsString('<svg', $html);
    }

    public function test_nav_shows_fiscalization_when_enabled(): void
    {
        $this->assertTrue(FeatureRegistry::isEnabled('fiscalization'));
    }
}
