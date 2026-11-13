<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\Security\SecretsAtRest;
use App\Support\PlatformSecrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AbuseRateLimit;
use Juwri\Core\Http\Middleware\BranchRateLimit;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\PlatformSetting;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Providers\SettingsServiceProvider;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\SecretMask;
use Juwri\Modules\Payments\Services\GatewaySecretVault;
use Juwri\Modules\Payments\Services\StorePaymentGateways;
use Juwri\Modules\Pos\Services\Printing\PrintSettings;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Stored credentials are encrypted at rest: platform secrets in `platform_settings`
 * (PlatformSecrets) and store secrets in the Credential vault
 * (GatewaySecretVault). Screens show a mask; saving the mask back keeps the
 * secret; runtime reads get the plaintext.
 */
class SecretsAtRestTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET = 'sk_live_51TestSecretValueABCD1234';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        PlatformSettings::flush();
        GatewaySecretVault::forgetMemo();
    }

    // ---- platform secrets (options table) ---------------------------------

    public function test_platform_secret_is_encrypted_on_write_and_decrypted_on_read(): void
    {
        PlatformSettings::set('smtp_password', 'mail-pass-123');
        PlatformSettings::set('site_title', 'Plain Title');

        $raw = PlatformSettings::stored('smtp_password');
        $this->assertNotSame('mail-pass-123', $raw);
        $this->assertSame('mail-pass-123', Crypt::decryptString($raw));
        $this->assertSame('mail-pass-123', PlatformSettings::get('smtp_password', false));

        // Non-secret settings are untouched.
        $this->assertSame('Plain Title', PlatformSettings::stored('site_title'));
    }

    public function test_runtime_config_gets_plaintext_while_the_cache_holds_ciphertext(): void
    {
        PlatformSettings::set('site_title', 'Test');
        PlatformSettings::set('stripe_webhook_secret', 'whsec_live_abc');
        PlatformSettings::set('stripe_secret_key', self::STRIPE_SECRET);

        config(['app.installed' => true]);
        new SettingsServiceProvider($this->app)->boot(request());

        // What the platform Stripe gateway code reads.
        $this->assertSame('whsec_live_abc', config('settings.stripe_webhook_secret'));
        $this->assertSame(self::STRIPE_SECRET, config('settings.stripe_secret_key'));

        $cached = Cache::get(PlatformSettings::CACHE_KEY);
        $this->assertStringNotContainsString(self::STRIPE_SECRET, $cached['stripe_secret_key']);
        $this->assertSame(self::STRIPE_SECRET, PlatformSecrets::decrypt(json_decode($cached['stripe_secret_key'])));
    }

    public function test_config_caching_never_bakes_settings_into_the_cache_file(): void
    {
        PlatformSettings::set('site_title', 'Test');
        PlatformSettings::set('smtp_password', 'never-in-config-cache');
        config(['app.installed' => true, 'settings' => []]);

        $argv = $_SERVER['argv'] ?? null;
        try {
            foreach (['config:cache', 'optimize'] as $command) {
                $_SERVER['argv'] = ['artisan', $command];
                new SettingsServiceProvider($this->app)->boot(request());
                $this->assertNull(config('settings.smtp_password'), $command);
            }
        } finally {
            $_SERVER['argv'] = $argv;
        }

        // Any other boot still loads settings.
        new SettingsServiceProvider($this->app)->boot(request());
        $this->assertSame('never-in-config-cache', config('settings.smtp_password'));
    }

    public function test_masked_platform_secret_is_kept_new_value_replaces_blank_clears(): void
    {
        PlatformSettings::set('facebook_app_secret', 'fb-secret-original-9876');
        $mask = SecretMask::mask('fb-secret-original-9876');
        $this->assertSame(SecretMask::PREFIX.'9876', $mask);

        PlatformSettings::set('facebook_app_secret', $mask);
        $this->assertSame('fb-secret-original-9876', PlatformSettings::get('facebook_app_secret', false));

        PlatformSettings::set('facebook_app_secret', 'fb-secret-new');
        $this->assertSame('fb-secret-new', PlatformSettings::get('facebook_app_secret', false));

        PlatformSettings::set('facebook_app_secret', '');
        $this->assertSame('', PlatformSettings::get('facebook_app_secret', false));
    }

    public function test_views_receive_a_mask_never_the_platform_secret(): void
    {
        PlatformSettings::set('site_title', 'Test');
        PlatformSettings::set('google_app_secret', 'google-oauth-secret-5555');

        $html = (string) $this->blade('[{{ $settings->google_app_secret }}]');

        $this->assertStringNotContainsString('google-oauth-secret-5555', $html);
        $this->assertStringContainsString('['.SecretMask::PREFIX.'5555]', $html);
    }

    // ---- store secrets (Credential vault) ----------------------------------

    public function test_newly_listed_store_secrets_are_vaulted_and_read_transparently(): void
    {
        $rid = $this->makeStore()->id;
        $values = [
            'meta_capi_token' => 'EAAB-capi-token-value',
            'print_bridge_token' => Str::random(40),
            'restaurant_stripe_webhook_secret' => 'whsec_store_legacy',
            'restaurant_payumoney_merchant_salt' => 'salt-legacy',
        ];

        foreach ($values as $key => $value) {
            $this->assertTrue(GatewaySecretVault::isSecret($key), $key);
            StoreOption::updateStoreOption($rid, $key, $value);
            $this->assertNull(StoreSettings::legacy((int) $rid, $key));
        }

        $raw = (string) DB::table('credentials')->where('feature', 'gateway_secrets')->where('store_id', $rid)->value('secrets');
        foreach ($values as $key => $value) {
            $this->assertStringNotContainsString($value, $raw, $key);
            $this->assertSame($value, store_options($rid, $key), $key);
            $this->assertSame($value, store_options($rid)->{$key}, $key);
        }
    }

    public function test_print_bridge_pairing_token_still_reads_back_in_full(): void
    {
        $rid = $this->makeStore()->id;

        $token = PrintSettings::rotatePairingToken($rid);

        $this->assertSame(40, strlen($token));
        $this->assertSame($token, PrintSettings::pairingToken($rid));
        $this->assertNull(StoreSettings::legacy((int) $rid, 'print_bridge_token'));
    }

    public function test_masked_store_secret_is_kept(): void
    {
        $rid = $this->makeStore()->id;
        StoreOption::updateStoreOption($rid, 'restaurant_stripe_secret_key', self::STRIPE_SECRET);

        StoreOption::updateStoreOption($rid, 'restaurant_stripe_secret_key', SecretMask::mask(self::STRIPE_SECRET));

        $this->assertSame(self::STRIPE_SECRET, GatewaySecretVault::get($rid, 'restaurant_stripe_secret_key'));
    }

    public function test_owner_settings_page_masks_gateway_secrets_and_an_untouched_save_keeps_them(): void
    {
        $this->prepareDashboard();
        [$owner, $store] = $this->makeOwnerWithStore();
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_secret_key', self::STRIPE_SECRET);
        GatewaySecretVault::forgetMemo();

        $page = $this->actingAs($owner)->get(route('stores.edit', $store->id))->assertOk();
        $page->assertDontSee(self::STRIPE_SECRET);
        $page->assertSee(SecretMask::mask(self::STRIPE_SECRET));

        $payload = [
            'color' => '#ff5500', 'title' => $store->title, 'description' => $store->description,
            'address' => $store->address, 'store_template' => 'aurora',
            'currency_code' => 'USD', 'currency_sign' => '$', 'currency_pos' => '1',
        ];

        // Saved with the mask untouched: the stored key survives.
        $this->actingAs($owner)
            ->put(route('stores.update', $store), $payload + ['restaurant_stripe_secret_key' => SecretMask::mask(self::STRIPE_SECRET)])
            ->assertRedirect();
        GatewaySecretVault::forgetMemo();
        $this->assertSame(self::STRIPE_SECRET, GatewaySecretVault::get($store->id, 'restaurant_stripe_secret_key'));

        // Saved with a new key: it replaces the old one, still encrypted.
        $this->actingAs($owner)
            ->put(route('stores.update', $store), $payload + ['restaurant_stripe_secret_key' => 'sk_live_replacement_key_0000'])
            ->assertRedirect();
        GatewaySecretVault::forgetMemo();
        $this->assertSame('sk_live_replacement_key_0000', GatewaySecretVault::get($store->id, 'restaurant_stripe_secret_key'));
    }

    // ---- the encrypt command ------------------------------------------------

    public function test_encrypt_command_dry_run_then_real_run_then_rerun_is_a_no_op(): void
    {
        $rid = $this->makeStore()->id;
        // Plaintext values still sitting in the settings tree (as written before encryption at rest).
        StoreSettings::set($rid, 'meta_capi_token', 'EAAB-plaintext');
        StoreSettings::set($rid, 'restaurant_tabby_secret_key', '');
        $service = app(SecretsAtRest::class);
        // The options table ships with default rows; measure from them.
        $base = $service->run(true);

        // A plaintext row as written before encryption at rest (bypasses PlatformSettings::set).
        PlatformSetting::query()->updateOrCreate(['name' => 'smtp_password'], ['value' => json_encode('plain-smtp')]);
        PlatformSettings::set('paypal_api_secret', PlatformSecrets::encrypt('already-enc'));
        PlatformSettings::flush();
        $alreadyBefore = $service->run(true)['platform_already_encrypted'];

        $dry = $service->run(true);
        $this->assertSame(1, $dry['store_moved']);
        $this->assertSame(1, $dry['store_empty_removed']);
        $this->assertSame(0, $dry['store_orphans_left']);
        $this->assertSame($base['platform_encrypted'] + 1, $dry['platform_encrypted']);
        $this->assertSame($alreadyBefore, $dry['platform_already_encrypted']);
        $this->assertSame(0, $dry['orphan_store_options']);
        $this->assertSame('EAAB-plaintext', StoreSettings::legacy((int) $rid, 'meta_capi_token'));
        $this->assertSame('plain-smtp', PlatformSettings::stored('smtp_password'));

        $this->artisan('secrets:encrypt')->assertSuccessful();
        PlatformSettings::flush();

        $this->assertNull(StoreSettings::legacy((int) $rid, 'meta_capi_token'));
        $this->assertNull(StoreSettings::legacy((int) $rid, 'restaurant_tabby_secret_key'));
        GatewaySecretVault::forgetMemo();
        $this->assertSame('EAAB-plaintext', GatewaySecretVault::get($rid, 'meta_capi_token'));
        $this->assertSame('plain-smtp', Crypt::decryptString(PlatformSettings::stored('smtp_password')));
        $this->assertSame('already-enc', PlatformSettings::get('paypal_api_secret', false));

        $again = $service->run(false);
        $this->assertSame(0, $again['store_moved']);
        $this->assertSame(0, $again['store_empty_removed']);
        $this->assertSame(0, $again['platform_encrypted']);
        $this->assertSame($alreadyBefore + $dry['platform_encrypted'], $again['platform_already_encrypted']);
        $this->assertSame(0, $again['store_orphans_left']);
    }

    public function test_an_owner_can_neither_read_nor_overwrite_another_owners_gateway_credentials(): void
    {
        $this->prepareDashboard();
        [$ownerA, $storeA] = $this->makeOwnerWithStore();
        [, $storeB] = $this->makeOwnerWithStore();
        StoreOption::updateStoreOption($storeB->id, 'restaurant_stripe_secret_key', 'sk_live_owner_b_secret_4242');
        StoreOption::updateStoreOption($storeB->id, 'restaurant_stripe_install', '1');

        // P1-07: the row belongs to store B only.
        $this->assertNull(StorePaymentGateways::get($storeA->id, 'restaurant_stripe_secret_key'));
        $this->assertArrayNotHasKey('restaurant_stripe_secret_key', (array) store_options($storeA->id));

        $page = $this->actingAs($ownerA)->get(route('stores.edit', $storeB->id));
        $this->assertNotSame(200, $page->status(), 'owner A must not open store B settings');
        $this->assertStringNotContainsString('sk_live_owner_b_secret_4242', (string) $page->getContent());

        $this->actingAs($ownerA)->put(route('stores.update', $storeB), [
            'color' => '#000000', 'title' => 'Hijack', 'description' => 'x', 'address' => 'x',
            'store_template' => 'aurora', 'currency_code' => 'USD', 'currency_sign' => '$', 'currency_pos' => '1',
            'restaurant_stripe_secret_key' => 'sk_live_attacker_0000',
        ]);
        StorePaymentGateways::forgetMemo();
        $this->assertSame('sk_live_owner_b_secret_4242', StorePaymentGateways::get($storeB->id, 'restaurant_stripe_secret_key'));
    }

    // ---- helpers ----------------------------------------------------------------

    private function makeStore(?User $owner = null): Store
    {
        $owner ??= User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Secret Cafe', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function makeOwnerWithStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return [$owner, $this->makeStore($owner)];
    }

    private function prepareDashboard(): void
    {
        FeatureAccess::flush();
        $this->shareLayoutGlobals(['store_order_whatsapp_message' => 'msg']);
        $this->withoutMiddleware([
            PreventRequestForgery::class,
            ThrottleRequests::class,
            AbuseRateLimit::class,
            AbuseHoldWall::class,
            BranchRateLimit::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            Installed::class,
        ]);
    }
}
