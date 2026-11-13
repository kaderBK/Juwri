<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\StoreOption;
use App\Services\GoogleWallet\GoogleWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * Lane C — C1 gate. GoogleWalletService is DARK unless the platform issuer id AND
 * the service-account key path are configured; per store, the owner toggle
 * (`google_wallet_enabled`, STRING '1'/'0') ANDs on top.
 */
class GoogleWalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function configureCreds(): void
    {
        config([
            'services.google_wallet.issuer_id' => '3388000000012345678',
            'services.google_wallet.service_account_json' => '/tmp/fake-sa-key.json',
        ]);
    }

    public function test_disabled_when_no_creds(): void
    {
        config([
            'services.google_wallet.issuer_id' => '',
            'services.google_wallet.service_account_json' => '',
        ]);

        $this->assertFalse(GoogleWalletService::enabled());
    }

    public function test_disabled_when_only_issuer_set(): void
    {
        config([
            'services.google_wallet.issuer_id' => '3388000000012345678',
            'services.google_wallet.service_account_json' => '',
        ]);

        $this->assertFalse(GoogleWalletService::enabled());
    }

    public function test_enabled_when_both_creds_present(): void
    {
        $this->configureCreds();

        $this->assertTrue(GoogleWalletService::enabled());
        $this->assertSame('3388000000012345678', GoogleWalletService::issuerId());
    }

    public function test_store_gate_false_when_platform_disabled_even_if_toggle_on(): void
    {
        config([
            'services.google_wallet.issuer_id' => '',
            'services.google_wallet.service_account_json' => '',
        ]);
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'google_wallet_enabled', '1');

        $this->assertFalse(GoogleWalletService::enabledForStore($store));
    }

    public function test_store_gate_tracks_toggle_when_creds_present(): void
    {
        $this->configureCreds();
        $store = $this->store();

        // Toggle off (absent) → disabled for store.
        $this->assertFalse(GoogleWalletService::enabledForStore($store));

        StoreOption::updateStoreOption($store->id, 'google_wallet_enabled', '1');
        $this->assertTrue(GoogleWalletService::enabledForStore($store->fresh()));

        StoreOption::updateStoreOption($store->id, 'google_wallet_enabled', '0');
        $this->assertFalse(GoogleWalletService::enabledForStore($store->fresh()));
    }

    public function test_origins_for_store_includes_platform_origin(): void
    {
        $this->configureCreds();
        $store = $this->store();

        $origins = GoogleWalletService::originsForStore($store);

        $this->assertNotEmpty($origins);
        // A plain store resolves to the platform host; every origin is a bare scheme://host.
        foreach ($origins as $o) {
            $this->assertMatchesRegularExpression('#^https?://[^/]+$#', $o);
        }
    }
}
