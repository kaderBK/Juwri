<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\StoreOption;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Scopes\AccountScope;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Models\Passkey;
use Juwri\Modules\Auth\Models\StaffPasskey;
use Juwri\Modules\Customers\Models\CustomerPasskey;
use Juwri\Modules\Customers\Support\CustomerSignIn;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;
use Juwri\Modules\Messaging\Models\SmsLog;
use Juwri\Modules\Messaging\Services\SmsOtpService;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Tests\Support\Passkeys\VirtualAuthenticator;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P1-19 follow-up: passkeys for storefront customers. A customer passkey belongs to ONE customer of ONE store
 * (`customer_passkeys`) and signs in to that store only, with the customer-guard session the password sign-in
 * opens. Real attestation / assertion vectors (VirtualAuthenticator) through webauthn-lib: register, sign in,
 * cross-store refusal, replay, counter regression, removal, relying party per host (J28), rate limits, the
 * password / phone-code sign-in unchanged, and the storefront partials.
 */
class CustomerPasskeyAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(string $title = 'Pizza Palace', string $template = 'crave'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#7c5cff',
            'title' => $title, 'sub_title' => 'Menu', 'description' => 'Demo menu', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return $store;
    }

    private function customer(Store $store, array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'store_id' => $store->id, 'name' => 'Alice Diner', 'email' => 'alice@example.test',
            'phone' => UniquePhone::next('5551'), 'password' => Hash::make('secret123'),
        ], $attrs));
    }

    private function signOut(): void
    {
        Auth::guard('customer')->logout();
        Auth::forgetGuards();
        $this->flushSession();
    }

    /** The customer (signed in) adds a passkey, re-proving the password. */
    private function register(Customer $customer, VirtualAuthenticator $key, string $base = 'https://localhost', string $name = 'My phone'): TestResponse
    {
        $this->actingAs($customer, 'customer');
        $options = $this->postJson($base.'/'.$customer->store_id.'/customer/passkeys/options', ['password' => 'secret123'])
            ->assertOk()->json('options');

        return $this->postJson($base.'/'.$customer->store_id.'/customer/passkeys', ['name' => $name, 'credential' => $key->create($options)]);
    }

    private function login(Store $store, VirtualAuthenticator $key, array $override = [], string $base = 'https://localhost'): TestResponse
    {
        $options = $this->postJson($base.'/'.$store->id.'/customer/passkeys/login/options')->assertOk()->json('options');

        return $this->postJson($base.'/'.$store->id.'/customer/passkeys/login', ['credential' => $key->get($options, $override)]);
    }

    private function sole(): CustomerPasskey
    {
        return CustomerPasskey::withoutGlobalScope(AccountScope::class)->sole();
    }

    public function test_a_customer_registers_a_passkey_bound_to_their_store(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $key = new VirtualAuthenticator;

        $this->actingAs($customer, 'customer');
        // A customer with a password re-proves it before adding a passkey.
        $this->postJson("/{$store->id}/customer/passkeys/options")->assertStatus(423)->assertJsonValidationErrors('password');
        $this->postJson("/{$store->id}/customer/passkeys/options", ['password' => 'wrong'])->assertStatus(423);
        $options = $this->postJson("/{$store->id}/customer/passkeys/options", ['password' => 'secret123'])->assertOk()->json('options');

        $this->assertSame('localhost', $options['rp']['id']);
        $this->assertSame('Pizza Palace', $options['rp']['name']);
        $this->assertSame('Alice Diner · Pizza Palace', $options['user']['displayName'], 'the device picker shows which store');
        $this->assertSame('alice@example.test', $options['user']['name']);
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);

        $this->postJson("/{$store->id}/customer/passkeys", ['name' => 'My phone', 'credential' => $key->create($options)])
            ->assertCreated()->assertJsonPath('passkey.name', 'My phone');

        $pk = $this->sole();
        $this->assertSame($customer->id, $pk->customer_id);
        $this->assertSame($store->id, $pk->store_id);
        $this->assertSame((int) $customer->account_id, (int) $pk->account_id);
        $this->assertSame('localhost', $pk->rp_id);
        $this->assertSame(['internal', 'hybrid'], $pk->transports);
        $this->assertSame(0, Passkey::count(), 'never an owner passkey');
        $this->assertSame(0, StaffPasskey::withoutGlobalScope(AccountScope::class)->count(), 'never a staff passkey');

        // The proof is remembered for a while: a second passkey needs no password again.
        $this->postJson("/{$store->id}/customer/passkeys/options")->assertOk();
    }

    /** A phone-code service that accepts only $good (and records the codes it was asked to send). */
    private function fakeOtp(string $good = '123456'): object
    {
        $fake = new class($good) implements SmsOtp
        {
            /** @var list<array{0: string, 1: ?int}> */
            public array $sent = [];

            /** @var list<string> scope keys verify() was asked about */
            public array $verifiedScopes = [];

            public function __construct(private string $good) {}

            public function request(string $phone, OtpScope $scope): array
            {
                $this->sent[] = [$phone, $scope->billingStoreId];

                return [true, 'We sent you a verification code.'];
            }

            public function verify(string $phone, string $code, OtpScope $scope): bool
            {
                $this->verifiedScopes[] = $scope->key;

                return $code === $this->good;
            }
        };
        $this->app->instance(SmsOtp::class, $fake);

        return $fake;
    }

    /**
     * Review fix: a customer without a password (phone code / social) adds a passkey only with a fresh proof — a
     * sign-in less than 10 minutes old, or a new phone code. A session alone (stolen cookie) is not enough.
     */
    public function test_a_customer_without_a_password_needs_a_fresh_sign_in_to_add_a_passkey(): void
    {
        $store = $this->store();
        $this->fakeOtp();

        // Phone-code sign-in (the real endpoint): fresh → adding works without anything else.
        $this->postJson("/{$store->id}/customer/otp/verify", ['phone' => '+15551234567', 'code' => '123456', 'store_id' => $store->id])
            ->assertOk()->assertJsonPath('success', true);
        $customer = Auth::guard('customer')->user();
        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertEmpty($customer->password);

        $options = $this->postJson("/{$store->id}/customer/passkeys/options")->assertOk()->json('options');
        $this->assertSame($customer->phone, $options['user']['name']);
        $this->postJson("/{$store->id}/customer/passkeys", ['name' => 'Phone', 'credential' => (new VirtualAuthenticator)->create($options)])->assertCreated();

        // 11 minutes later the same session is stale.
        $this->travel(11)->minutes();
        $this->postJson("/{$store->id}/customer/passkeys/options")
            ->assertStatus(423)->assertJsonPath('reauth', true)->assertJsonValidationErrors('code');
        $this->postJson("/{$store->id}/customer/passkeys/options", ['code' => '000000'])
            ->assertStatus(423)->assertJsonValidationErrors('code');
        $this->assertSame(1, CustomerPasskey::withoutGlobalScope(AccountScope::class)->count());

        // A new phone code is a fresh proof (for the next 10 minutes).
        $this->postJson("/{$store->id}/customer/passkeys/options", ['code' => '123456'])->assertOk();
        $this->postJson("/{$store->id}/customer/passkeys/options")->assertOk();
        $this->travel(11)->minutes();
        $this->postJson("/{$store->id}/customer/passkeys/options")->assertStatus(423);
    }

    public function test_a_session_without_a_sign_in_proof_cannot_add_a_passkey_for_a_passwordless_customer(): void
    {
        $store = $this->store();
        $this->fakeOtp();
        $social = $this->customer($store, ['password' => null, 'email' => 'bob@example.test', 'phone' => null, 'source' => 'google']);

        // A session that carries the customer but no sign-in mark (a stolen / replayed session).
        $this->actingAs($social, 'customer');
        $this->postJson("/{$store->id}/customer/passkeys/options")->assertStatus(423);
        $this->postJson("/{$store->id}/customer/passkeys/options", ['code' => '123456'])->assertStatus(423, 'no phone → no code proof');
        $this->postJson("/{$store->id}/customer/passkeys/code")->assertNotFound();
        $this->assertSame(0, CustomerPasskey::withoutGlobalScope(AccountScope::class)->count());
    }

    public function test_only_an_interactive_customer_sign_in_counts_as_fresh(): void
    {
        $store = $this->store();
        $customer = $this->customer($store, ['password' => null]);
        $other = $this->customer($store, ['password' => null, 'email' => 'other@example.test']);

        $request = Request::create('/');
        $request->setLaravelSession($this->app['session']->driver());
        $this->app->instance('request', $request);

        event(new Login('customer', $customer, true));
        $this->assertFalse(CustomerSignIn::isFresh($request, $customer), 'a remember-me recall is not a fresh proof');
        event(new Login('web', $customer, false));
        $this->assertFalse(CustomerSignIn::isFresh($request, $customer), 'another guard does not count');

        event(new Login('customer', $customer, false));
        $this->assertTrue(CustomerSignIn::isFresh($request, $customer));
        $this->assertFalse(CustomerSignIn::isFresh($request, $other), 'the mark belongs to one customer');
    }

    /** Security (OTP scoping): the fresh-proof code works with the real code service, and only at its own store. */
    public function test_the_fresh_proof_code_is_scoped_to_the_store(): void
    {
        $store = $this->store();
        $other = $this->store('Other Place');
        $customer = $this->customer($store, ['password' => null, 'phone' => '+15559876543']);
        $latest = function (): string {
            preg_match('/(\d{6})/', (string) SmsLog::where('feature', 'otp')->latest('id')->value('message_preview'), $m);

            return $m[1] ?? '';
        };

        // A code for the same phone sent by ANOTHER store (which can read its own SMS log) is no proof here.
        app(SmsOtpService::class)->request('+15559876543', OtpScope::store((int) $other->id));
        $foreign = $latest();
        $this->actingAs($customer, 'customer');
        $this->postJson("/{$store->id}/customer/passkeys/options", ['code' => $foreign])->assertStatus(423);

        $this->postJson("/{$store->id}/customer/passkeys/code")->assertOk()->assertJsonPath('success', true);
        $this->assertSame((int) $store->id, (int) SmsLog::where('feature', 'otp')->latest('id')->value('store_id'));
        $this->postJson("/{$store->id}/customer/passkeys/options", ['code' => $latest()])->assertOk();
    }

    public function test_a_passwordless_customer_can_text_themselves_a_code_and_the_account_page_offers_it(): void
    {
        $store = $this->store('Crave Cafe', 'crave');
        $otp = $this->fakeOtp();
        $customer = $this->customer($store, ['password' => null, 'phone' => '+15559876543']);

        $this->actingAs($customer, 'customer');
        $this->get("/{$store->slug}/customer/profile")->assertOk()
            ->assertSee('data-passkey-code', false)
            ->assertSee(route('customer.passkeys.code', $store->id), false)
            ->assertDontSee('data-passkey-password', false);
        $this->assertDoesNotMatchRegularExpression('/data-passkey-reauth\s+hidden/', $this->get("/{$store->slug}/customer/profile")->getContent());

        $this->postJson("/{$store->id}/customer/passkeys/code")->assertOk()->assertJsonPath('success', true);
        $this->assertSame([['+15559876543', $store->id]], $otp->sent, 'only to the signed-in customer\'s own phone');

        // Password customers re-prove the password instead; no code endpoint for them.
        $withPassword = $this->customer($store, ['email' => 'pw@example.test']);
        $this->actingAs($withPassword, 'customer');
        $this->postJson("/{$store->id}/customer/passkeys/code")->assertNotFound();
    }

    public function test_sign_in_opens_the_customer_session_of_that_store(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $key = new VirtualAuthenticator;
        $this->register($customer, $key)->assertCreated();
        $this->signOut();
        $this->assertGuest('customer');

        $options = $this->postJson("/{$store->id}/customer/passkeys/login/options")->assertOk()->json('options');
        $this->assertSame('localhost', $options['rpId']);
        $this->assertSame([], $options['allowCredentials'] ?? [], 'discoverable sign-in');
        $this->assertSame('required', $options['userVerification']);

        $this->postJson("/{$store->id}/customer/passkeys/login", ['credential' => $key->get($options)])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('reload', true);

        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertGuest('web');
        $pk = $this->sole();
        $this->assertSame(1, $pk->sign_count);
        $this->assertNotNull($pk->last_used_at);

        // The slug form of the store address works the same.
        $this->signOut();
        $options = $this->postJson("/{$store->slug}/customer/passkeys/login/options")->assertOk()->json('options');
        $this->postJson("/{$store->slug}/customer/passkeys/login", ['credential' => $key->get($options)])->assertOk();
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_a_passkey_of_store_a_never_signs_in_to_store_b(): void
    {
        $storeA = $this->store('Store A');
        $storeB = $this->store('Store B');
        $alice = $this->customer($storeA);
        // The same person also has an account at store B (same email): still no way in with A's passkey.
        $this->customer($storeB);
        $key = new VirtualAuthenticator;
        $this->register($alice, $key)->assertCreated();
        $this->signOut();

        $this->login($storeB, $key)->assertStatus(422)->assertJsonValidationErrors('credential');
        $this->assertGuest('customer');

        // A challenge made on store A's page is not accepted on store B's.
        $options = $this->postJson("/{$storeA->id}/customer/passkeys/login/options")->assertOk()->json('options');
        $this->postJson("/{$storeB->id}/customer/passkeys/login", ['credential' => $key->get($options)])->assertStatus(422);
        $this->assertGuest('customer');

        // A customer of store B cannot manage passkeys on store A's page.
        $bob = $this->customer($storeB, ['email' => 'bob@example.test']);
        $this->actingAs($bob, 'customer');
        $this->postJson("/{$storeA->id}/customer/passkeys/options", ['password' => 'secret123'])->assertForbidden();
        $this->delete("/{$storeA->id}/customer/passkeys/".$this->sole()->id)->assertForbidden();
        $this->delete("/{$storeB->id}/customer/passkeys/".$this->sole()->id)->assertNotFound();
        $this->assertSame(1, CustomerPasskey::withoutGlobalScope(AccountScope::class)->count());

        // And store A still signs in.
        $this->signOut();
        $this->login($storeA, $key)->assertOk();
        $this->assertAuthenticatedAs($alice, 'customer');
    }

    public function test_a_replayed_assertion_is_refused(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $key = new VirtualAuthenticator;
        $this->register($customer, $key)->assertCreated();
        $this->signOut();

        $options = $this->postJson("/{$store->id}/customer/passkeys/login/options")->assertOk()->json('options');
        $assertion = $key->get($options);
        $this->postJson("/{$store->id}/customer/passkeys/login", ['credential' => $assertion])->assertOk();
        $this->signOut();

        // The same assertion again: its challenge was used up.
        $this->postJson("/{$store->id}/customer/passkeys/login", ['credential' => $assertion])->assertStatus(422);
        // Against a fresh challenge: it signed the old one.
        $this->postJson("/{$store->id}/customer/passkeys/login/options")->assertOk();
        $this->postJson("/{$store->id}/customer/passkeys/login", ['credential' => $assertion])->assertStatus(422);
        $this->assertGuest('customer');

        // Wrong relying party / origin are refused too.
        $this->login($store, $key, ['rpId' => 'evil.test'])->assertStatus(422);
        $this->login($store, $key, ['origin' => 'https://evil.test'])->assertStatus(422);
        $this->assertGuest('customer');
    }

    public function test_a_sign_counter_regression_is_refused(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $key = new VirtualAuthenticator;
        $this->register($customer, $key)->assertCreated();
        $this->signOut();

        $this->login($store, $key, ['signCount' => 5])->assertOk();
        $this->assertSame(5, $this->sole()->sign_count);
        $this->signOut();

        // A cloned authenticator replays an older counter: refused, counter kept.
        $this->login($store, $key, ['signCount' => 3])->assertStatus(422);
        $this->login($store, $key, ['signCount' => 5])->assertStatus(422);
        $this->assertGuest('customer');
        $this->assertSame(5, $this->sole()->sign_count);

        $this->login($store, $key, ['signCount' => 6])->assertOk();
        $this->assertSame(6, $this->sole()->sign_count);
    }

    public function test_a_customer_removes_a_passkey_and_it_no_longer_signs_in(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $key = new VirtualAuthenticator;
        $this->register($customer, $key)->assertCreated();
        $id = $this->sole()->id;

        // Guests cannot remove anything.
        $this->signOut();
        $this->delete("/{$store->id}/customer/passkeys/{$id}")->assertForbidden();

        $this->actingAs($customer, 'customer');
        $this->delete("/{$store->id}/customer/passkeys/{$id}")
            ->assertRedirect(route('customer.profile', $store->id))->assertSessionHas('passkey_status');
        $this->assertSame(0, CustomerPasskey::withoutGlobalScope(AccountScope::class)->count());

        $this->signOut();
        $this->login($store, $key)->assertStatus(422);
        $this->assertGuest('customer');

        // Deleting the customer deletes their passkeys (FK cascade).
        $key2 = new VirtualAuthenticator;
        $this->register($customer, $key2)->assertCreated();
        $customer->delete();
        $this->assertSame(0, CustomerPasskey::withoutGlobalScope(AccountScope::class)->count());
    }

    public function test_relying_party_follows_the_host_serving_the_store(): void
    {
        $storeA = $this->store('Store A');
        $storeB = $this->store('Store B');
        $alice = $this->customer($storeA);
        StoreDomain::create(['store_id' => $storeA->id, 'type' => 'custom', 'host' => 'menu.store-a.test', 'verified_at' => now(), 'verification_token' => 't']);

        // On the store's own domain the RP is that domain — for that store only.
        $onDomain = new VirtualAuthenticator('menu.store-a.test', 'https://menu.store-a.test');
        $this->register($alice, $onDomain, 'https://menu.store-a.test')->assertCreated();
        $this->assertSame('menu.store-a.test', $this->sole()->rp_id);
        $this->signOut();
        $this->postJson("https://menu.store-a.test/{$storeB->id}/customer/passkeys/login/options")->assertNotFound();
        $this->login($storeA, $onDomain, [], 'https://menu.store-a.test')->assertOk();
        $this->assertAuthenticatedAs($alice, 'customer');
        $this->signOut();

        // A passkey made for the custom domain does not work on the shared host (another RP).
        $this->login($storeA, $onDomain)->assertStatus(422);

        // Hosts that are not storefront hosts offer none: an unknown host, and in split mode the dashboard host.
        $this->postJson("https://evil.test/{$storeA->id}/customer/passkeys/login/options")->assertNotFound();
        config([
            'hosts.mode' => 'split',
            'hosts.app_url' => 'https://app.juwri.test',
            'hosts.admin_url' => 'https://admin.juwri.test',
            'hosts.storefront_url' => 'https://shop.juwri.test',
            'hosts.marketing_url' => 'https://juwri.test',
        ]);
        $this->postJson("https://app.juwri.test/{$storeA->id}/customer/passkeys/login/options")->assertNotFound();
        $this->postJson("https://shop.juwri.test/{$storeA->id}/customer/passkeys/login/options")
            ->assertOk()->assertJsonPath('options.rpId', 'shop.juwri.test');
    }

    public function test_password_and_phone_code_sign_in_are_unchanged(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $this->register($customer, new VirtualAuthenticator)->assertCreated();
        $this->signOut();

        $this->postJson("/{$store->id}/customer/login", ['email' => 'alice@example.test', 'password' => 'secret123', 'store_id' => $store->id])
            ->assertOk()->assertJsonPath('success', true);
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_passkey_endpoints_are_rate_limited(): void
    {
        $store = $this->store();

        for ($i = 0; $i < 20; $i++) {
            $this->postJson("/{$store->id}/customer/passkeys/login/options")->assertOk();
        }
        $this->postJson("/{$store->id}/customer/passkeys/login/options")->assertStatus(429);
    }

    public function test_repeated_failed_sign_ins_lock_the_ip_out(): void
    {
        $store = $this->store();
        $stranger = new VirtualAuthenticator;
        config(['passkeys.max_failures' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->login($store, $stranger)->assertStatus(422);
        }
        $this->login($store, $stranger)->assertStatus(429);
    }

    public function test_storefront_login_panel_and_account_page_offer_passkeys(): void
    {
        $store = $this->store('Crave Cafe', 'crave');
        $customer = $this->customer($store);

        $page = $this->get("/{$store->slug}")->assertOk();
        $page->assertSee('data-passkey-login', false)
            ->assertSee('data-conditional="off"', false)
            ->assertSee(route('customer.passkey.login.options', $store->id), false)
            ->assertSee('assets/owner-ui/passkeys.js', false);
        $this->assertMatchesRegularExpression('/<script src="[^"]*passkeys\.js[^"]*" nonce="[^"]+"/', $page->getContent(), 'the script carries the CSP nonce');

        $key = new VirtualAuthenticator;
        $this->register($customer, $key)->assertCreated();
        $profile = $this->get("/{$store->slug}/customer/profile")->assertOk();
        $profile->assertSee('data-passkey-manage', false)
            ->assertSee('data-passkey-password', false)
            ->assertSee('My phone')
            ->assertSee(route('customer.passkeys.destroy', [$store->id, $this->sole()->id]), false);

        // aurora's own profile page too.
        StoreOption::updateStoreOption($store->id, 'store_template', 'aurora');
        $this->get("/{$store->slug}/customer/profile")->assertOk()->assertSee('data-passkey-manage', false);

        // Signed in to this store: the login panel offers no passkey sign-in.
        $this->get("/{$store->slug}")->assertOk()->assertDontSee('data-passkey-login', false);
    }

    public function test_arabic_storefront_shows_translated_passkey_strings(): void
    {
        $store = $this->store('Crave Cafe', 'crave');
        $customer = $this->customer($store);
        $this->actingAs($customer, 'customer');

        app()->setLocale('ar');
        $html = view('store_templates._partials.customer-passkeys', ['store' => $store, 'customer' => $customer])->render();
        $this->assertStringNotContainsString('Sign in to this store with your fingerprint', $html);
        $this->assertStringContainsString('بصمة', $html);
    }
}
