<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\MenuCategory;
use App\Models\StorefrontToken;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreTransfer;
use Juwri\Core\Models\User;
use Juwri\Core\Services\OwnershipTransferService;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Payments\Providers\PaymentsStoreData;
use Juwri\Modules\Payments\Services\GatewaySecretVault;
use Juwri\Modules\Payments\Services\StorePaymentGateways;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-06f: an ownership transfer must not hand the new owner the previous owner's
 * payment accounts, integration tokens, messaging keys or domain verification
 * (they used to follow the store because they key on store_id), and a
 * duplicated store must not copy them. Everything else still moves as before.
 */
class StoreTransferResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $u, string $slug): Store
    {
        return Store::create([
            'user_id' => $u->id, 'title' => 'Store '.$slug, 'slug' => $slug,
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
    }

    private function option(Store $store, string $name, string $value): void
    {
        StoreOption::updateStoreOption($store->id, $name, $value); // P1-07: settings live in stores.settings / store_payment_gateways
    }

    /** Alice's store, connected to her own accounts, plus data that must stay. */
    private function connectedStore(User $alice, string $slug): Store
    {
        $store = $this->store($alice, $slug);

        // Payment gateways: install flags, public ids, vaulted + legacy plaintext secrets.
        $this->option($store, 'restaurant_online_payment', '1');
        $this->option($store, 'restaurant_stripe_install', '1');
        $this->option($store, 'restaurant_stripe_title', 'Card');
        $this->option($store, 'restaurant_stripe_publishable_key', 'pk_test_alice');
        $this->option($store, 'restaurant_paystack_install', '1');
        $this->option($store, 'restaurant_paystack_secret_key', 'sk_test_legacy_plaintext');
        $this->option($store, 'restaurant_2checkout_install', '1');
        $this->option($store, 'restaurant_wallet_transfer_install', '1');
        $this->option($store, 'restaurant_wallet_transfer_syriatel_number', '0999000111');
        GatewaySecretVault::put($store->id, 'restaurant_stripe_secret_key', 'sk_test_alice');
        GatewaySecretVault::put($store->id, 'meta_capi_token', 'capi_test_alice');
        $this->option($store, 'meta_pixel_id', '123456');
        // Ordinary settings stay.
        $this->option($store, 'currency_code', 'SAR');
        $this->option($store, 'store_template', 'classic');

        // Integration + messaging credentials (one row per feature).
        foreach ([
            ['accounting', 'xero', ['refresh_token' => 'rt_alice']],
            ['gbp', 'google', ['refresh_token' => 'g_alice']],
            ['foodics', 'foodics', ['access_token' => 'f_alice']],
            ['foodics_webhook', 'foodics', ['webhook_token' => 'wh_alice']],
            ['deliverect', 'deliverect', ['webhook_secret' => 'd_alice']],
            ['whatsapp', 'meta', ['access_token' => 'wa_alice']],
            ['sms', 'twilio', ['auth_token' => 'sms_alice']],
        ] as [$feature, $provider, $secrets]) {
            Credential::create(['store_id' => $store->id, 'feature' => $feature, 'provider' => $provider, 'secrets' => $secrets, 'active' => true]);
        }

        StoreDomain::create(['store_id' => $store->id, 'domain' => $slug.'.example.com', 'verified' => true, 'verification_token' => 'mb-verify-alice', 'ssl_status' => 'active']);
        StorefrontToken::create(['store_id' => $store->id, 'label' => 'alice', 'token' => hash('sha256', 'alice-'.$slug), 'scopes' => ['menu:read'], 'active' => true]);
        DB::table('whatsapp_routes')->insert(['provider' => 'meta', 'sender_id' => 'alice-phone-'.$slug, 'store_id' => $store->id, 'account_id' => $store->account_id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('aggregator_connections')->insert(['provider' => 'deliverect', 'external_store_id' => 'ext-'.$slug, 'store_id' => $store->id, 'account_id' => $store->account_id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        // Business data that must move untouched.
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Kabsa', 'price' => 30, 'active' => 1]);

        return $store;
    }

    private function transfer(User $from, User $to, Store $store, bool $force = false): StoreTransfer
    {
        return StoreTransfer::create([
            'kind' => 'store', 'token' => StoreTransfer::newToken(), 'from_user_id' => $from->id,
            'to_user_id' => $to->id, 'to_email' => $to->email, 'store_id' => $store->id,
            'status' => 'pending', 'expires_at' => now()->addDay(), 'by_superadmin' => $force,
        ]);
    }

    private function gatewaysOffered(Store $store): array
    {
        StoreOption::flushOptionMemo();
        GatewaySecretVault::forgetMemo();
        $m = new \ReflectionMethod(PaymentController::class, 'storeGateways');
        $m->setAccessible(true);

        return array_column($m->invoke(new PaymentController, $store, store_options($store->id), 50.0), 'payment_folder');
    }

    public function test_accepted_transfer_resets_the_previous_owners_connections(): void
    {
        $alice = $this->owner();
        $bob = $this->owner();
        $store = $this->connectedStore($alice, 'alice-grill');
        $other = $this->connectedStore($alice, 'alice-other');
        $platform = Credential::create(['store_id' => null, 'feature' => 'sms', 'provider' => 'twilio', 'secrets' => ['auth_token' => 'platform'], 'active' => true]);

        $this->assertSame(['paystack', 'stripe', 'two_checkout', 'wallet_transfer'], collect($this->gatewaysOffered($store))->sort()->values()->all(), 'fixture: gateways offered before');

        // Offer/accept, as the owners do it.
        $this->actingAs($alice);
        session(['dashboard_store_id' => $store->id]);
        $this->post(route('transfer.store.offer'), ['to_email' => $bob->email])->assertRedirect();
        $transfer = StoreTransfer::where('store_id', $store->id)->firstOrFail();
        $this->actingAs($bob)->post(route('transfer.accept', $transfer->token))->assertRedirect();

        $this->assertSame($bob->id, $store->fresh()->user_id);
        $this->assertSame('accepted', $transfer->fresh()->status);

        // No gateway credentials or install flags; checkout offers nothing of Alice's.
        $this->assertSame([], $this->gatewaysOffered($store));
        $this->assertNull(GatewaySecretVault::get($store->id, 'restaurant_stripe_secret_key'));
        $this->assertEmpty(StoreOption::getStoreOption($store->id, 'restaurant_paystack_secret_key'));
        foreach (['restaurant_stripe_install', 'restaurant_stripe_publishable_key', 'restaurant_paystack_install', 'restaurant_2checkout_install',
            'restaurant_wallet_transfer_install', 'restaurant_wallet_transfer_syriatel_number', 'meta_pixel_id'] as $name) {
            StoreOption::flushOptionMemo();
            $this->assertEmpty(StoreOption::getStoreOption($store->id, $name), $name.' reset');
        }

        // No integration / messaging credentials at all.
        $this->assertSame(0, Credential::where('store_id', $store->id)->count());
        $this->assertNull(Credential::for('accounting', $store->id));
        $this->assertNull(Credential::for('whatsapp', $store->id));

        // Domain kept but unverified, with a token Alice's DNS record does not match.
        $domain = StoreDomain::where('store_id', $store->id)->firstOrFail();
        $this->assertFalse($domain->verified);
        $this->assertNotSame('mb-verify-alice', $domain->verification_token);
        $this->assertNotEmpty($domain->verification_token);
        $this->assertNull(StoreDomain::resolve('alice-grill.example.com'));

        // Storefront API token and aggregator link switched off; sender routing gone.
        $this->assertFalse(StorefrontToken::where('store_id', $store->id)->firstOrFail()->active);
        $this->assertSame('paused', DB::table('aggregator_connections')->where('store_id', $store->id)->value('status'));
        $this->assertFalse(DB::table('whatsapp_routes')->where('store_id', $store->id)->exists());

        // What stays: title, ordinary settings, menu.
        StoreOption::flushOptionMemo();
        $this->assertSame('Card', StoreOption::getStoreOption($store->id, 'restaurant_stripe_title'));
        $this->assertSame('SAR', StoreOption::getStoreOption($store->id, 'currency_code'));
        $this->assertSame(1, Menu::where('store_id', $store->id)->count());

        // Alice's other store and the platform default are untouched.
        $this->assertSame('sk_test_alice', GatewaySecretVault::get($other->id, 'restaurant_stripe_secret_key'));
        $this->assertSame(8, Credential::where('store_id', $other->id)->count());
        $this->assertContains('stripe', $this->gatewaysOffered($other));
        $this->assertTrue(StoreDomain::where('store_id', $other->id)->firstOrFail()->verified);
        $this->assertNotNull($platform->fresh());

        // The audit entry lists what was reset.
        $log = ActivityLog::where('action', 'store.transfer')->where('store_id', $store->id)->firstOrFail();
        $reset = $log->meta['reset'];
        $this->assertEqualsCanonicalizing(
            ['accounting', 'deliverect', 'foodics', 'foodics_webhook', 'gateway_secrets', 'gbp', 'sms', 'whatsapp'],
            $reset['credentials']
        );
        $this->assertContains('restaurant_stripe_install', $reset['options']);
        $this->assertContains('restaurant_paystack_secret_key', $reset['options']);
        $this->assertNotContains('restaurant_stripe_title', $reset['options']);
        $this->assertSame(['alice-grill.example.com'], $reset['domains']);
        $this->assertSame(1, $reset['storefront_tokens']);
        $this->assertSame(1, $reset['aggregator_connections']);
        $this->assertSame(1, $reset['whatsapp_routes']);
    }

    public function test_superadmin_forced_transfer_resets_too(): void
    {
        $alice = $this->owner();
        $bob = $this->owner();
        $store = $this->connectedStore($alice, 'forced-grill');
        $transfer = $this->transfer($alice, $bob, $store, true);

        app(OwnershipTransferService::class)->applyStore($transfer);

        $this->assertSame('forced', $transfer->fresh()->status);
        $this->assertSame([], $this->gatewaysOffered($store));
        $this->assertSame(0, Credential::where('store_id', $store->id)->count());
    }

    public function test_reset_catalogue_covers_every_gateway_option_but_the_title(): void
    {
        // P1-21: Payments declares the owner gateways; every gateway offered on the pay page (+ 2Checkout) is one.
        $this->assertSame([], array_values(array_diff([...PaymentController::STORE_GATEWAYS, '2checkout'], PaymentsStoreData::ownerGateways())));
        foreach (PaymentsStoreData::ownerGateways() as $gw) {
            $this->assertTrue(StoreDataRegistry::isOwnerSetting('restaurant_'.$gw.'_install'), $gw);
            $this->assertFalse(StoreDataRegistry::isOwnerSetting('restaurant_'.$gw.'_title'), $gw);
        }
        foreach (GatewaySecretVault::secretKeys() as $key) {
            $this->assertTrue(StoreDataRegistry::isOwnerSetting($key), $key);
        }
        foreach (['currency_code', 'store_template', 'restaurant_online_payment', 'restaurant_bnpl_enabled', 'transfer_cooldown_days'] as $kept) {
            $this->assertFalse(StoreDataRegistry::isOwnerSetting($kept), $kept);
        }
    }

    public function test_duplicating_a_store_copies_no_connections(): void
    {
        $alice = $this->owner();
        $source = $this->connectedStore($alice, 'dup-grill');

        $this->actingAs($alice)->postJson(route('stores.clone', $source))->assertOk()->assertJson(['success' => true]);
        $clone = Store::where('user_id', $alice->id)->where('id', '!=', $source->id)->firstOrFail();

        $names = array_merge(array_keys(StoreSettings::legacyMap($clone->id)), array_keys(StorePaymentGateways::all($clone->id)));
        foreach ($names as $name) {
            $this->assertFalse(StoreDataRegistry::isOwnerSetting($name), $name.' copied to the clone');
        }
        $this->assertContains('currency_code', $names);
        $this->assertContains('restaurant_stripe_title', $names);
        $this->assertSame([], $this->gatewaysOffered($clone));
        $this->assertSame(0, Credential::where('store_id', $clone->id)->count());
        $this->assertSame(0, StoreDomain::where('store_id', $clone->id)->count());
        $this->assertSame(0, StorefrontToken::where('store_id', $clone->id)->count());
        $this->assertSame(0, DB::table('aggregator_connections')->where('store_id', $clone->id)->count());
        $this->assertSame(1, Menu::where('store_id', $clone->id)->count());

        // The source keeps everything.
        $this->assertContains('stripe', $this->gatewaysOffered($source));
        $this->assertSame('sk_test_alice', GatewaySecretVault::get($source->id, 'restaurant_stripe_secret_key'));
    }
}
