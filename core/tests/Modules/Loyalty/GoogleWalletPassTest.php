<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use App\Services\GoogleWallet\GoogleWalletService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Loyalty\Services\GoogleWallet\GiftCardPassMapper;
use Juwri\Modules\Loyalty\Services\GoogleWallet\LoyaltyPassMapper;
use Tests\Support\FakeGoogleServiceAccount;
use Tests\TestCase;

/**
 * Lane C — C2. Pass payload shape (loyalty points + stamps, gift card) and the
 * RS256 save-link JWT (signs with the fake fixture key, decodes with the derived
 * public key). No network — pay.google.com is never hit.
 */
class GoogleWalletPassTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = FakeGoogleServiceAccount::path();
        config([
            'services.google_wallet.issuer_id' => '3388000000012345678',
            'services.google_wallet.service_account_json' => $this->fixture,
        ]);
    }

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#112233',
            'title' => 'Bistro', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'google_wallet_enabled', '1');

        return $store;
    }

    public function test_loyalty_points_payload_shape(): void
    {
        $store = $this->store();
        $program = LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => 'points', 'earn_rate' => 1,
            'reward_threshold' => 100, 'reward_description' => 'Free side', 'reward_discount' => 5.0, 'active' => true,
        ]);
        $customer = Customer::create(['name' => 'Ada', 'store_id' => $store->id, 'phone' => '55-1']);
        $account = LoyaltyAccount::forCustomer($customer->id, $store->id);
        $account->update(['balance' => 42]);

        $payload = LoyaltyPassMapper::payload($store, $program, $account->fresh(), $customer);

        $class = $payload['loyaltyClasses'][0];
        $object = $payload['loyaltyObjects'][0];

        $this->assertSame('3388000000012345678.loy'.$store->id, $class['id']);
        $this->assertSame('3388000000012345678.loy'.$store->id.'c'.$customer->id, $object['id']);
        $this->assertSame($class['id'], $object['classId']);
        $this->assertSame('ACTIVE', $object['state']);
        // Label is localized (renders in the store locale); assert it matches the
        // Points translation and is distinct from the Stamps one.
        $this->assertSame(___('Points'), $object['loyaltyPoints']['label']);
        $this->assertNotSame(___('Stamps'), $object['loyaltyPoints']['label']);
        $this->assertSame('42', $object['loyaltyPoints']['balance']['string']);
        $this->assertSame('Bistro', $class['issuerName']);
        $this->assertSame('#112233', $class['hexBackgroundColor']);
    }

    public function test_loyalty_stamps_payload_shows_progress(): void
    {
        $store = $this->store();
        $program = LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => 'stamps', 'earn_rate' => 1,
            'reward_threshold' => 10, 'reward_description' => 'Free coffee', 'reward_discount' => 0, 'active' => true,
        ]);
        $customer = Customer::create(['name' => 'Bo', 'store_id' => $store->id, 'phone' => '55-2']);
        $account = LoyaltyAccount::forCustomer($customer->id, $store->id);
        $account->update(['balance' => 3]);

        $object = LoyaltyPassMapper::payload($store, $program, $account->fresh(), $customer)['loyaltyObjects'][0];

        $this->assertSame(___('Stamps'), $object['loyaltyPoints']['label']);
        $this->assertSame('3 / 10', $object['loyaltyPoints']['balance']['string']);
    }

    public function test_gift_card_payload_shape(): void
    {
        $store = $this->store();
        $card = GiftCardService::issueManual($store->id, 50.0);

        $payload = GiftCardPassMapper::payload($store, $card->fresh());
        $class = $payload['giftCardClasses'][0];
        $object = $payload['giftCardObjects'][0];

        $this->assertSame('3388000000012345678.gc'.$store->id, $class['id']);
        $this->assertSame('3388000000012345678.gc'.$store->id.'c'.$card->id, $object['id']);
        $this->assertSame('ACTIVE', $object['state']);
        $this->assertSame($card->code, $object['cardNumber']);
        $this->assertSame(50000000, $object['balance']['micros']); // 50.00 * 1e6
        $this->assertSame(strtoupper($card->currency), $object['balance']['currencyCode']);
        $this->assertSame('QR_CODE', $object['barcode']['type']);
    }

    public function test_gift_card_state_maps_status(): void
    {
        $store = $this->store();
        $card = GiftCardService::issueManual($store->id, 10.0);
        $card->update(['status' => 'disabled']);
        $this->assertSame('INACTIVE', GiftCardPassMapper::object($store, $card->fresh())['state']);

        $card->update(['status' => 'expired']);
        $this->assertSame('EXPIRED', GiftCardPassMapper::object($store, $card->fresh())['state']);
    }

    public function test_jwt_signs_and_decodes_with_fixture_key(): void
    {
        $store = $this->store();
        $program = LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => 'points', 'earn_rate' => 1,
            'reward_threshold' => 100, 'reward_description' => 'Free side', 'reward_discount' => 5.0, 'active' => true,
        ]);
        $customer = Customer::create(['name' => 'Ada', 'store_id' => $store->id, 'phone' => '55-3']);
        $account = LoyaltyAccount::forCustomer($customer->id, $store->id);

        $url = GoogleWalletService::loyaltySaveUrl($store, $program, $account, $customer);
        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', $url);

        $jwt = substr($url, strlen('https://pay.google.com/gp/v/save/'));

        // Derive the public key from the fixture private key and verify the signature.
        $sa = json_decode((string) file_get_contents($this->fixture), true);
        $pub = openssl_pkey_get_details(openssl_pkey_get_private($sa['private_key']))['key'];

        $decoded = JWT::decode($jwt, new Key($pub, 'RS256'));
        $this->assertSame('savetowallet', $decoded->typ);
        $this->assertSame('google', $decoded->aud);
        $this->assertSame($sa['client_email'], $decoded->iss);
        $this->assertNotEmpty($decoded->origins);
        $this->assertObjectHasProperty('loyaltyObjects', $decoded->payload);
    }

    public function test_save_url_null_when_disabled(): void
    {
        config([
            'services.google_wallet.issuer_id' => '',
            'services.google_wallet.service_account_json' => '',
        ]);
        $store = $this->store();
        $program = LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => 'points', 'earn_rate' => 1,
            'reward_threshold' => 100, 'reward_description' => 'x', 'reward_discount' => 1, 'active' => true,
        ]);
        $customer = Customer::create(['name' => 'Z', 'store_id' => $store->id, 'phone' => '55-4']);
        $account = LoyaltyAccount::forCustomer($customer->id, $store->id);

        $this->assertNull(GoogleWalletService::loyaltySaveUrl($store, $program, $account, $customer));
    }
}
