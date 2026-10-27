<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\StoreTransferContext;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\StoreDataEngine;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\SecretMask;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Payments\Models\StorePaymentGateway;
use Juwri\Modules\Payments\Services\GatewaySecretVault;
use Juwri\Modules\Payments\Services\StorePaymentGateways;
use Tests\TestCase;

/**
 * P1-07 slice 4: per-store payment gateway settings live in store_payment_gateways — one row per
 * store + gateway, config in jsonb, credentials encrypted — behind the unchanged legacy key names.
 */
class StorePaymentGatewaysTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'g-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'Gateway Cafe', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
    }

    public function test_every_store_gateway_is_covered_and_keys_parse(): void
    {
        foreach (array_merge(PaymentController::STORE_GATEWAYS, ['2checkout']) as $gateway) {
            $this->assertContains($gateway, StorePaymentGateways::GATEWAYS, $gateway);
        }
        $this->assertSame(['gateway' => 'paytabs', 'field' => 'profile_id'], StorePaymentGateways::parse('restaurant_paytabs_profile_id'));
        $this->assertSame(['gateway' => 'paytm', 'field' => 'merchant_mid'], StorePaymentGateways::parse('restaurant_paytm_merchant_mid'));
        $this->assertSame(['gateway' => 'wallet_transfer', 'field' => 'mtn_number'], StorePaymentGateways::parse('restaurant_wallet_transfer_mtn_number'));
        $this->assertNull(StorePaymentGateways::parse('store_template'));
        $this->assertNull(StorePaymentGateways::parse('restaurant_cash_install'));
        $this->assertNull(StorePaymentGateways::parse('meta_capi_token'));
    }

    public function test_one_row_per_gateway_with_encrypted_credentials_and_no_store_options(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_install', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_title', 'Card');
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_publishable_key', 'pk_live_PUBLIC');
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_secret_key', 'sk_live_TOP_SECRET_1234');

        $this->assertSame(1, StorePaymentGateway::where('store_id', $store->id)->count());
        $row = StorePaymentGateway::where('store_id', $store->id)->where('gateway', 'stripe')->firstOrFail();
        $this->assertTrue($row->enabled);
        $this->assertSame($store->account_id, $row->account_id);
        $this->assertEquals(['install' => '1', 'title' => 'Card', 'publishable_key' => 'pk_live_PUBLIC'], $row->config); // key order is the DB's (jsonb)

        $raw = (string) DB::table('store_payment_gateways')->where('id', $row->id)->value('credentials');
        $this->assertStringNotContainsString('sk_live_TOP_SECRET_1234', $raw, 'credentials are encrypted at rest');
        $this->assertStringNotContainsString('sk_live_TOP_SECRET_1234', (string) DB::table('store_payment_gateways')->where('id', $row->id)->value('config'));
        $this->assertSame([], array_values(array_filter(array_keys(StoreSettings::legacyMap((int) $store->id)), fn ($n) => str_starts_with($n, 'restaurant_stripe_'))));
        $this->assertSame(0, DB::table('credentials')->where('store_id', $store->id)->count());

        // Every legacy read shape still resolves.
        $this->assertSame('sk_live_TOP_SECRET_1234', StoreOption::getStoreOption($store->id, 'restaurant_stripe_secret_key'));
        $this->assertSame('sk_live_TOP_SECRET_1234', GatewaySecretVault::get($store->id, 'restaurant_stripe_secret_key'));
        $this->assertSame('sk_live_TOP_SECRET_1234', store_options($store->id)->restaurant_stripe_secret_key);
        $this->assertSame(1, store_options($store->id)->restaurant_stripe_install, 'config values decode like store_options did');
        $this->assertSame('pk_live_PUBLIC', store_options($store->id, 'restaurant_stripe_publishable_key'));
        $this->assertSame(['stripe'], StorePaymentGateways::enabledGateways($store->id));
    }

    public function test_credentials_are_masked_for_display_and_never_serialised(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'restaurant_paymob_hmac_secret', 'hmac-secret-value-9876');
        StoreOption::updateStoreOption($store->id, 'restaurant_paymob_title', 'Paymob');

        $masked = StorePaymentGateways::masked($store->id);
        $this->assertSame(SecretMask::mask('hmac-secret-value-9876'), $masked['restaurant_paymob_hmac_secret']);
        $this->assertSame('Paymob', $masked['restaurant_paymob_title']);

        $row = StorePaymentGateway::where('store_id', $store->id)->firstOrFail();
        $this->assertArrayNotHasKey('credentials', $row->toArray());
        $this->assertStringNotContainsString('hmac-secret-value-9876', $row->toJson());
        $this->assertStringNotContainsString('hmac-secret-value-9876', json_encode(StorePaymentGateways::legacyRows($store->id)));

        // A masked echo keeps the secret, a new value replaces it, a blank clears it.
        StoreOption::updateStoreOption($store->id, 'restaurant_paymob_hmac_secret', $masked['restaurant_paymob_hmac_secret']);
        $this->assertSame('hmac-secret-value-9876', StorePaymentGateways::get($store->id, 'restaurant_paymob_hmac_secret'));
        StoreOption::updateStoreOption($store->id, 'restaurant_paymob_hmac_secret', 'hmac-rotated');
        $this->assertSame('hmac-rotated', StorePaymentGateways::get($store->id, 'restaurant_paymob_hmac_secret'));
        StoreOption::updateStoreOption($store->id, 'restaurant_paymob_hmac_secret', '');
        $this->assertNull(StorePaymentGateways::get($store->id, 'restaurant_paymob_hmac_secret'));
        $this->assertFalse(StoreOption::getStoreOption($store->id, 'restaurant_paymob_hmac_secret'));
    }

    public function test_store_a_never_sees_store_b_gateway_row(): void
    {
        $a = $this->store();
        $b = $this->store();
        StoreOption::updateStoreOption($b->id, 'restaurant_tap_secret_key', 'sk_tap_owner_b');
        StoreOption::updateStoreOption($b->id, 'restaurant_tap_install', '1');

        $this->assertNull(StorePaymentGateways::get($a->id, 'restaurant_tap_secret_key'));
        $this->assertFalse(StoreOption::getStoreOption($a->id, 'restaurant_tap_secret_key'));
        $this->assertSame([], StorePaymentGateways::all($a->id));
        $this->assertSame([], StorePaymentGateways::enabledGateways($a->id));
        $this->assertObjectNotHasProperty('restaurant_tap_secret_key', store_options($a->id));

        // A write for store A creates A's own row and leaves B's untouched.
        StoreOption::updateStoreOption($a->id, 'restaurant_tap_secret_key', 'sk_tap_owner_a');
        $this->assertSame('sk_tap_owner_b', StorePaymentGateways::get($b->id, 'restaurant_tap_secret_key'));
        $this->assertSame(2, StorePaymentGateway::where('gateway', 'tap')->count());
    }

    public function test_ownership_transfer_resets_all_but_the_title(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'restaurant_noon_install', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_noon_title', 'Noon Pay');
        StoreOption::updateStoreOption($store->id, 'restaurant_noon_business_id', 'biz-1');
        StoreOption::updateStoreOption($store->id, 'restaurant_noon_api_key', 'noon-key');

        // P1-21: the registry's transfer reset (Payments declares store_payment_gateways).
        $owner = (int) $store->user_id;
        $done = app(StoreDataEngine::class)->transfer(DB::connection(), new StoreTransferContext((int) $store->id, $owner, $owner, null, null));

        $this->assertContains('restaurant_noon_api_key', $done['options']);
        $this->assertContains('restaurant_noon_install', $done['options']);
        $this->assertSame(['restaurant_noon_title' => 'Noon Pay'], StorePaymentGateways::all($store->id));
        $this->assertFalse(StorePaymentGateway::where('store_id', $store->id)->value('enabled'));
    }
}
