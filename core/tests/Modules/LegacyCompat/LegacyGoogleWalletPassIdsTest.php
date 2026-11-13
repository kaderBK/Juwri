<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\LegacyCompat\Support\LegacyGoogleWalletPassIds;
use Juwri\Modules\Loyalty\Contracts\GoogleWalletPassIds;
use Juwri\Modules\Loyalty\Jobs\SyncGoogleWalletPass;
use Juwri\Modules\Loyalty\Services\GoogleWallet\DefaultGoogleWalletPassIds;
use Juwri\Modules\Loyalty\Services\GoogleWallet\GiftCardPassMapper;
use Juwri\Modules\Loyalty\Services\GoogleWallet\LoyaltyPassMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeGoogleServiceAccount;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * J52b M3 (plan §5.1, R8): Google Wallet passes saved on phones before the migration keep their class/object ids, so
 * the JWT save link and the balance sync address the SAME pass. Only with the module on and the legacy issuer fully
 * configured, and only for imported stores (id <= max_store_id); anything else uses the platform ids.
 */
class LegacyGoogleWalletPassIdsTest extends TestCase
{
    use RefreshDatabase;

    private const PLATFORM_ISSUER = '3388000000012345678';

    private const LEGACY_ISSUER = '3388000000099999999';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google_wallet.issuer_id' => self::PLATFORM_ISSUER,
            'services.google_wallet.service_account_json' => FakeGoogleServiceAccount::path(),
        ]);
    }

    public function test_platform_ids_when_the_module_is_off_even_if_configured(): void
    {
        $store = $this->store();
        $this->configureLegacy((int) $store->id);

        $this->assertSame(DefaultGoogleWalletPassIds::class, app(GoogleWalletPassIds::class)::class);
        $this->assertSame(self::PLATFORM_ISSUER.'.loy'.$store->id, LoyaltyPassMapper::classId($store));
        $this->assertSame(self::PLATFORM_ISSUER.'.loy'.$store->id.'c5', LoyaltyPassMapper::objectId($store, 5));
        $this->assertSame(self::PLATFORM_ISSUER.'.gc'.$store->id.'c9', GiftCardPassMapper::objectId($store, 9));
    }

    public function test_platform_ids_when_on_but_not_configured(): void
    {
        $this->activate();
        $store = $this->store();

        $this->assertInstanceOf(LegacyGoogleWalletPassIds::class, app(GoogleWalletPassIds::class));
        $this->assertNull(LegacyGoogleWalletPassIds::settings());
        $this->assertSame(self::PLATFORM_ISSUER.'.loy'.$store->id.'c5', LoyaltyPassMapper::objectId($store, 5));
    }

    public function test_legacy_ids_for_an_imported_store_when_on_and_configured(): void
    {
        $this->activate();
        $store = $this->store();
        $this->configureLegacy((int) $store->id, ['loyalty_class_prefix' => 'lp', 'gift_card_class_prefix' => 'gift', 'object_separator' => '-']);

        $this->assertSame(self::LEGACY_ISSUER.'.lp'.$store->id, LoyaltyPassMapper::classId($store));
        $this->assertSame(self::LEGACY_ISSUER.'.lp'.$store->id.'-5', LoyaltyPassMapper::objectId($store, 5));
        $this->assertSame(self::LEGACY_ISSUER.'.gift'.$store->id, GiftCardPassMapper::classId($store));
        $this->assertSame(self::LEGACY_ISSUER.'.gift'.$store->id.'-9', GiftCardPassMapper::objectId($store, 9));
    }

    public function test_a_store_created_after_cut_over_keeps_the_platform_ids(): void
    {
        $this->activate();
        $imported = $this->store();
        $this->configureLegacy((int) $imported->id);
        $newStore = $this->store();

        $this->assertSame(self::LEGACY_ISSUER.'.loy'.$imported->id.'c5', LoyaltyPassMapper::objectId($imported, 5));
        $this->assertSame(self::PLATFORM_ISSUER.'.loy'.$newStore->id.'c5', LoyaltyPassMapper::objectId($newStore, 5));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badSettings(): array
    {
        return [
            'empty issuer' => [['issuer_id' => '']],
            'non-numeric issuer' => [['issuer_id' => 'abc.def']],
            'issuer with a dot' => [['issuer_id' => '33.88']],
            'max store id 0' => [['max_store_id' => 0]],
            'negative max store id' => [['max_store_id' => -3]],
            'prefix with a dot' => [['loyalty_class_prefix' => 'lo.y']],
            'empty gift prefix' => [['gift_card_class_prefix' => '']],
            'separator with a slash' => [['object_separator' => 'c/']],
            'array issuer' => [['issuer_id' => ['1']]],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('badSettings')]
    public function test_malformed_settings_fail_closed_to_the_platform_ids(array $override): void
    {
        $this->activate();
        $store = $this->store();
        $this->configureLegacy((int) $store->id, $override);

        $this->assertNull(LegacyGoogleWalletPassIds::settings());
        $this->assertSame(self::PLATFORM_ISSUER.'.loy'.$store->id.'c5', LoyaltyPassMapper::objectId($store, 5));
    }

    public function test_switching_the_module_off_returns_to_the_platform_ids_without_a_rebind(): void
    {
        $this->activate();
        $store = $this->store();
        $this->configureLegacy((int) $store->id);
        $this->assertStringStartsWith(self::LEGACY_ISSUER.'.', LoyaltyPassMapper::classId($store));

        config(['legacy_compat.enabled' => false]);
        $this->assertSame(self::PLATFORM_ISSUER.'.loy'.$store->id, LoyaltyPassMapper::classId($store));
    }

    public function test_the_save_link_jwt_carries_the_legacy_ids_only_when_configured(): void
    {
        $store = $this->store();
        $customer = Customer::create(['name' => 'C', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);
        $card = GiftCardService::issueManual($store->id, 30.0, ['purchaser_customer_id' => $customer->id]);

        // Off: platform ids.
        $payload = $this->jwtPayload($this->actingAs($customer, 'customer')->getJson(route('publicView.googleWallet.loyaltyJwt', $store->id))->assertOk()->json('save_url'));
        $this->assertSame(self::PLATFORM_ISSUER.'.loy'.$store->id.'c'.$customer->id, $payload['loyaltyObjects'][0]['id']);

        $this->activate();
        $this->configureLegacy((int) $store->id);

        $payload = $this->jwtPayload($this->actingAs($customer, 'customer')->getJson(route('publicView.googleWallet.loyaltyJwt', $store->id))->assertOk()->json('save_url'));
        $this->assertSame(self::LEGACY_ISSUER.'.loy'.$store->id.'c'.$customer->id, $payload['loyaltyObjects'][0]['id']);
        $this->assertSame(self::LEGACY_ISSUER.'.loy'.$store->id, $payload['loyaltyObjects'][0]['classId']);
        $this->assertSame(self::LEGACY_ISSUER.'.loy'.$store->id, $payload['loyaltyClasses'][0]['id']);

        $payload = $this->jwtPayload($this->actingAs($customer, 'customer')->getJson(route('publicView.googleWallet.giftCardJwt', ['storeId' => $store->id, 'giftCardId' => $card->id]))->assertOk()->json('save_url'));
        $this->assertSame(self::LEGACY_ISSUER.'.gc'.$store->id.'c'.$card->id, $payload['giftCardObjects'][0]['id']);
    }

    public function test_the_balance_sync_updates_the_pass_saved_under_the_legacy_id(): void
    {
        $this->activate();
        $store = $this->store();
        $this->configureLegacy((int) $store->id);
        $customer = Customer::create(['name' => 'C', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);
        LoyaltyAccount::forCustomer($customer->id, $store->id);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fake'], 200),
            'walletobjects.googleapis.com/*' => Http::response(['id' => 'x'], 200),
        ]);

        new SyncGoogleWalletPass('loyalty', (int) $store->id, (int) $customer->id)->handle();

        $legacyId = rawurlencode(self::LEGACY_ISSUER.'.loy'.$store->id.'c'.$customer->id);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/walletobjects/v1/loyaltyObject/'.$legacyId));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), self::PLATFORM_ISSUER));
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    private function activate(): void
    {
        config(['legacy_compat.enabled' => true]);
        LegacyCompatServiceProvider::activate($this->app);
    }

    /** @param array<string, mixed> $override */
    private function configureLegacy(int $maxStoreId, array $override = []): void
    {
        config(['legacy_compat.google_wallet' => array_merge([
            'issuer_id' => self::LEGACY_ISSUER,
            'max_store_id' => $maxStoreId,
            'loyalty_class_prefix' => 'loy',
            'gift_card_class_prefix' => 'gc',
            'object_separator' => 'c',
        ], $override)]);
    }

    /** @return array<string, mixed> */
    private function jwtPayload(string $saveUrl): array
    {
        $jwt = Str::after($saveUrl, '/gp/v/save/');
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $this->assertIsArray($claims);

        return $claims['payload'];
    }

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'gw-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'google_wallet_enabled', '1');
        LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => 'points', 'earn_rate' => 1,
            'reward_threshold' => 100, 'reward_description' => 'Free side', 'reward_discount' => 5.0, 'active' => true,
        ]);

        return $store;
    }
}
