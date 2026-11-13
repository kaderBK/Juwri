<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Payments\Services\GatewaySecretVault;
use Tests\TestCase;

/**
 * BYOK gateway secrets are stored in the encrypted Credential vault, never in the
 * plaintext store_options table — reads/writes stay transparent through the
 * existing StoreOption + store_options() funnels.
 */
class GatewaySecretVaultTest extends TestCase
{
    use RefreshDatabase;

    private int $rid;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $user->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->rid = $r->id;
    }

    public function test_secret_write_goes_to_vault_not_plaintext_options(): void
    {
        StoreOption::updateStoreOption($this->rid, 'restaurant_stripe_secret_key', 'sk_live_ABC');

        // No plaintext row.
        $this->assertNull(StoreSettings::legacy((int) $this->rid, 'restaurant_stripe_secret_key'));
        // P1-07: present in store_payment_gateways.credentials, encrypted at rest.
        $raw = DB::table('store_payment_gateways')->where('store_id', $this->rid)->where('gateway', 'stripe')->value('credentials');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('sk_live_ABC', (string) $raw);

        // Both read shapes resolve the secret.
        $this->assertSame('sk_live_ABC', StoreOption::getStoreOption($this->rid, 'restaurant_stripe_secret_key'));
        $this->assertSame('sk_live_ABC', store_options($this->rid)->restaurant_stripe_secret_key);
        $this->assertSame('sk_live_ABC', GatewaySecretVault::get($this->rid, 'restaurant_stripe_secret_key'));
    }

    public function test_non_secret_gateway_option_is_stored_as_plain_config(): void
    {
        StoreOption::updateStoreOption($this->rid, 'restaurant_stripe_publishable_key', 'pk_live_XYZ');
        StoreOption::updateStoreOption($this->rid, 'restaurant_stripe_install', '1');

        // P1-07: gateway config lives in store_payment_gateways.config (not encrypted, not a secret).
        $config = json_decode((string) DB::table('store_payment_gateways')->where('store_id', $this->rid)->value('config'), true);
        $this->assertSame('pk_live_XYZ', $config['publishable_key']);
        $this->assertSame('pk_live_XYZ', store_options($this->rid)->restaurant_stripe_publishable_key);
    }

    public function test_clearing_a_secret_removes_it(): void
    {
        StoreOption::updateStoreOption($this->rid, 'restaurant_paymob_hmac_secret', 'h1');
        $this->assertSame('h1', GatewaySecretVault::get($this->rid, 'restaurant_paymob_hmac_secret'));

        StoreOption::updateStoreOption($this->rid, 'restaurant_paymob_hmac_secret', '');
        $this->assertNull(GatewaySecretVault::get($this->rid, 'restaurant_paymob_hmac_secret'));
    }
}
