<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Modules\Auth\Http\Middleware\Authenticate;
use Juwri\Modules\Payments\Models\PaylinkOnboarding;
use Juwri\Modules\Payments\Services\PaylinkPartner;
use Tests\Support\EditionTestHooks;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * G10 — Paylink Partner API: provisioning state machine + superadmin gating.
 */
class PaylinkPartnerTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        config([
            'settings.paylink_partner_api_id' => 'PARTNER_ID',
            'settings.paylink_partner_secret' => 'PARTNER_SECRET',
            'settings.paylink_partner_sandbox' => true,
        ]);
    }

    private function fakePartnerAuth(): array
    {
        return ['restpilot.paylink.sa/api/partner/auth' => Http::response(['id_token' => 'PTKN'], 200)];
    }

    public function test_submit_registration_creates_pending_onboarding(): void
    {
        [$store] = $this->makeOrderTransaction('paylink', 100.0, []);
        Http::fake(array_merge($this->fakePartnerAuth(), [
            'restpilot.paylink.sa/api/partner/merchant/register' => Http::response(['merchantId' => 'M123', 'status' => 'pending'], 200),
        ]));

        $ob = PaylinkPartner::submitRegistration($store->id, [
            'cr_number' => '1010', 'owner_id_number' => '1099', 'iban' => 'SA00',
        ]);

        $this->assertSame(PaylinkOnboarding::STATUS_PENDING_NAFATH, $ob->status);
        $this->assertSame('M123', $ob->provider_merchant_id);
    }

    public function test_activation_provisions_keys_into_store_options_single_read_path(): void
    {
        [$store] = $this->makeOrderTransaction('paylink', 100.0, []);
        PaylinkOnboarding::create([
            'store_id' => $store->id, 'status' => 'pending_nafath', 'provider_merchant_id' => 'M123',
        ]);
        Http::fake(array_merge($this->fakePartnerAuth(), [
            'restpilot.paylink.sa/api/partner/merchant/M123/keys' => Http::response([
                'status' => 'active', 'apiId' => 'PROV_API', 'secretKey' => 'PROV_SECRET',
            ], 200),
        ]));

        $provisioned = PaylinkPartner::handleActivation('M123');

        $this->assertTrue($provisioned);
        // Written to the SAME store_options the manual BYOK form / driver reads.
        $this->assertSame('PROV_API', (string) StoreOption::getStoreOption($store->id, 'restaurant_paylink_api_id'));
        $this->assertSame('PROV_SECRET', (string) StoreOption::getStoreOption($store->id, 'restaurant_paylink_secret_key'));
        $this->assertSame('1', (string) StoreOption::getStoreOption($store->id, 'restaurant_paylink_install'));
        $this->assertSame('active', PaylinkOnboarding::where('provider_merchant_id', 'M123')->first()->status);
    }

    public function test_activation_webhook_is_idempotent_on_replay(): void
    {
        [$store] = $this->makeOrderTransaction('paylink', 100.0, []);
        PaylinkOnboarding::create([
            'store_id' => $store->id, 'status' => 'pending_nafath', 'provider_merchant_id' => 'M123',
        ]);
        Http::fake(array_merge($this->fakePartnerAuth(), [
            'restpilot.paylink.sa/api/partner/merchant/M123/keys' => Http::response([
                'status' => 'active', 'apiId' => 'PROV_API', 'secretKey' => 'PROV_SECRET',
            ], 200),
        ]));

        $this->post('/webhook/paylink-partner', ['merchantId' => 'M123'])->assertOk();
        $first = $provisioned = PaylinkPartner::handleActivation('M123'); // replay via service
        $this->assertFalse($first); // already active -> no re-provision

        $this->assertSame(1, PaylinkOnboarding::where('provider_merchant_id', 'M123')->where('status', 'active')->count());
    }

    public function test_rejection_path(): void
    {
        [$store] = $this->makeOrderTransaction('paylink', 100.0, []);
        PaylinkOnboarding::create([
            'store_id' => $store->id, 'status' => 'pending_nafath', 'provider_merchant_id' => 'M9',
        ]);
        Http::fake(array_merge($this->fakePartnerAuth(), [
            'restpilot.paylink.sa/api/partner/merchant/M9/keys' => Http::response(['status' => 'rejected', 'reason' => 'CR invalid'], 200),
        ]));

        $this->assertFalse(PaylinkPartner::handleActivation('M9'));
        $ob = PaylinkOnboarding::where('provider_merchant_id', 'M9')->first();
        $this->assertSame('rejected', $ob->status);
        // No keys provisioned on rejection.
        $this->assertEmpty(StoreOption::getStoreOption($store->id, 'restaurant_paylink_api_id'));
    }

    public function test_unknown_merchant_id_is_noop_cross_tenant_safe(): void
    {
        Http::fake($this->fakePartnerAuth());
        // No onboarding row for this merchant id -> resolved from OUR store, never payload.
        $this->assertFalse(PaylinkPartner::handleActivation('GHOST'));
        Http::assertNothingSent(); // never even authenticates for an unknown merchant
    }

    public function test_feature_gate_off_returns_403(): void
    {
        config(['settings.admin_allow_paylink_partner' => false]);
        $this->withoutMiddleware([
            PreventRequestForgery::class,
            Authenticate::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            AbuseHoldWall::class,
        ]);
        // Route exists but the feature middleware refuses (platform OFF always wins).
        $this->get(route('stores.paylink.partner.index'))->assertStatus(403);
    }

    public function test_registry_and_admin_wiring_present(): void
    {
        $keys = array_map(fn ($e) => $e['key'], FeatureRegistry::all());
        $this->assertContains('paylink_partner', $keys);
        // Default OFF.
        $this->assertFalse(FeatureRegistry::isEnabled('paylink_partner'));
        $ctrl = file_get_contents(app_path('Modules/Payments/Support/GatewaySettings.php')); // WI-08e: the per-gateway settings keys live here
        $this->assertStringContainsString('paylink_partner_api_id', $ctrl);
    }
}
