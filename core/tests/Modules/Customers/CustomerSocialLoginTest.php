<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Services\Social\AppleClientSecret;
use Juwri\Modules\Auth\Services\Social\SocialCredentialResolver;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerSocialLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
        ]);
    }

    private function createRestaurant(): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'resto-'.Str::lower(Str::random(6)),
            'color' => '#ff5500',
            'title' => 'Resto',
            'sub_title' => 'x',
            'description' => 'x',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '123',
        ]);
    }

    private function platformKeys(string $provider, array $secrets, bool $active = true): void
    {
        Credential::create([
            'store_id' => null,
            'feature' => 'social',
            'provider' => $provider,
            'secrets' => $secrets,
            'active' => $active,
        ]);
    }

    private function state(Store $store, string $provider): string
    {
        // Bind a login-CSRF nonce to the session for GET providers (google/facebook),
        // mirroring redirect(). Apple (form_post) is exempt in the controller, so the
        // embedded nonce is simply ignored there.
        $nonce = Str::random(40);
        $this->withSession(['customer_social_nonce' => hash('sha256', $nonce)]);

        return Crypt::encryptString(json_encode(['r' => $store->id, 'p' => $provider, 't' => time(), 'n' => $nonce]));
    }

    /**
     * @param  bool|null  $emailVerified  Provider's raw `email_verified` claim.
     *                                    true (default) → Google-style verified; false → present-but-false;
     *                                    null → claim absent entirely (e.g. Facebook payload).
     */
    private function fakeSocialUser(string $id, ?string $email, ?string $name, ?bool $emailVerified = true): SocialiteUser
    {
        $user = Mockery::mock(SocialiteUser::class);
        $user->shouldReceive('getId')->andReturn($id);
        $user->shouldReceive('getEmail')->andReturn($email);
        $user->shouldReceive('getName')->andReturn($name);
        $user->shouldReceive('getNickname')->andReturn(null);
        $user->shouldReceive('getAvatar')->andReturn('https://cdn.test/a.png');
        $user->user = $emailVerified === null ? [] : ['email_verified' => $emailVerified];

        return $user;
    }

    #[Test]
    public function disabled_provider_returns_404_on_redirect()
    {
        $store = $this->createRestaurant();
        // No platform keys → not enabled.
        $this->get(route('customer.social.redirect', [$store->id, 'google']))
            ->assertNotFound();
    }

    #[Test]
    public function unknown_provider_returns_404()
    {
        $store = $this->createRestaurant();
        $this->get(route('customer.social.redirect', [$store->id, 'twitter']))
            ->assertNotFound();
    }

    #[Test]
    public function platform_keys_enable_the_redirect()
    {
        $store = $this->createRestaurant();
        $this->platformKeys('google', ['client_id' => 'plat-id', 'client_secret' => 'plat-secret']);

        $this->get(route('customer.social.redirect', [$store->id, 'google']))
            ->assertRedirect();
    }

    #[Test]
    public function owner_byok_overrides_platform_keys()
    {
        $store = $this->createRestaurant();
        $this->platformKeys('google', ['client_id' => 'plat-id', 'client_secret' => 'plat-secret']);
        Credential::create([
            'store_id' => $store->id,
            'feature' => 'social',
            'provider' => 'google',
            'secrets' => ['client_id' => 'owner-id', 'client_secret' => 'owner-secret'],
            'active' => true,
        ]);

        $resolver = app(SocialCredentialResolver::class);
        $secrets = $resolver->secretsFor('google', $store->id);
        $this->assertSame('owner-id', $secrets['client_id']);
    }

    #[Test]
    public function owner_can_disable_provider_despite_platform_keys()
    {
        $store = $this->createRestaurant();
        $this->platformKeys('google', ['client_id' => 'plat-id', 'client_secret' => 'plat-secret']);
        Credential::create([
            'store_id' => $store->id,
            'feature' => 'social',
            'provider' => 'google',
            'secrets' => ['enabled' => false],
            'active' => true,
        ]);

        $this->assertFalse(app(SocialCredentialResolver::class)->isEnabled('google', $store->id));
        $this->get(route('customer.social.redirect', [$store->id, 'google']))->assertNotFound();
    }

    #[Test]
    public function callback_creates_a_new_customer_and_logs_in()
    {
        $store = $this->createRestaurant();
        $this->platformKeys('google', ['client_id' => 'plat-id', 'client_secret' => 'plat-secret']);

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($this->fakeSocialUser('goog-1', 'new@example.test', 'New Diner'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('customer.social.callback', 'google').'?state='.urlencode($this->state($store, 'google')))
            ->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'store_id' => $store->id,
            'oauth_provider' => 'google',
            'oauth_uid' => 'goog-1',
            'email' => 'new@example.test',
        ]);
        $this->assertTrue(auth('customer')->check());
    }

    #[Test]
    public function callback_links_existing_email_customer()
    {
        $store = $this->createRestaurant();
        $this->platformKeys('google', ['client_id' => 'plat-id', 'client_secret' => 'plat-secret']);
        $existing = Customer::create([
            'store_id' => $store->id,
            'name' => 'Existing',
            'email' => 'link@example.test',
        ]);

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($this->fakeSocialUser('goog-9', 'link@example.test', 'Existing'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('customer.social.callback', 'google').'?state='.urlencode($this->state($store, 'google')))
            ->assertRedirect();

        $existing->refresh();
        $this->assertSame('goog-9', $existing->oauth_uid);
        $this->assertSame('google', $existing->oauth_provider);
        $this->assertSame(1, Customer::where('store_id', $store->id)->count());
    }

    #[Test]
    public function unverified_email_does_not_link_and_creates_separate_customer()
    {
        $store = $this->createRestaurant();
        $this->platformKeys('google', ['client_id' => 'plat-id', 'client_secret' => 'plat-secret']);
        $existing = Customer::create([
            'store_id' => $store->id,
            'name' => 'Victim',
            'email' => 'victim@example.test',
        ]);

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        // Same email, but Google says it is NOT verified.
        $provider->shouldReceive('user')->andReturn($this->fakeSocialUser('goog-evil', 'victim@example.test', 'Evil', false));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(route('customer.social.callback', 'google').'?state='.urlencode($this->state($store, 'google')))
            ->assertRedirect();

        $existing->refresh();
        // Victim account untouched (no oauth stamped), and a NEW row was created.
        $this->assertNull($existing->oauth_uid);
        $this->assertSame(2, Customer::where('store_id', $store->id)->count());
        $new = Customer::where('store_id', $store->id)->where('oauth_uid', 'goog-evil')->first();
        $this->assertNotNull($new);
        $this->assertNull($new->email_verified_at); // unverified → not stamped
        $this->assertNull($new->email);             // disputed email not claimed
    }

    #[Test]
    public function facebook_email_never_links_by_bare_email()
    {
        $store = $this->createRestaurant();
        $this->platformKeys('facebook', ['client_id' => 'fb-id', 'client_secret' => 'fb-secret']);
        $existing = Customer::create([
            'store_id' => $store->id,
            'name' => 'Victim',
            'email' => 'victim@example.test',
        ]);

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($this->fakeSocialUser('fb-evil', 'victim@example.test', 'Evil', null));
        Socialite::shouldReceive('driver')->with('facebook')->andReturn($provider);

        $this->get(route('customer.social.callback', 'facebook').'?state='.urlencode($this->state($store, 'facebook')))
            ->assertRedirect();

        $existing->refresh();
        $this->assertNull($existing->oauth_uid);
        $this->assertSame(2, Customer::where('store_id', $store->id)->count());
    }

    #[Test]
    public function callback_with_tampered_state_fails_gracefully()
    {
        $store = $this->createRestaurant();
        $this->platformKeys('google', ['client_id' => 'plat-id', 'client_secret' => 'plat-secret']);

        $this->get(route('customer.social.callback', 'google').'?state=garbage')
            ->assertRedirect();
        $this->assertFalse(auth('customer')->check());
    }

    #[Test]
    public function callback_without_session_nonce_is_rejected()
    {
        $store = $this->createRestaurant();
        $this->platformKeys('google', ['client_id' => 'plat-id', 'client_secret' => 'plat-secret']);

        // Valid, app-key-encrypted state carrying a nonce, but NO matching session
        // nonce — i.e. an attacker-initiated flow fed to a victim. Login-CSRF rejected
        // before any code exchange.
        $state = Crypt::encryptString(json_encode(['r' => $store->id, 'p' => 'google', 't' => time(), 'n' => Str::random(40)]));
        $this->get(route('customer.social.callback', 'google').'?state='.urlencode($state))
            ->assertRedirect();
        $this->assertFalse(auth('customer')->check());
    }

    #[Test]
    public function callback_with_mismatched_nonce_is_rejected()
    {
        $store = $this->createRestaurant();
        $this->platformKeys('google', ['client_id' => 'plat-id', 'client_secret' => 'plat-secret']);

        // Session holds one nonce; the state carries a different one.
        $this->withSession(['customer_social_nonce' => hash('sha256', Str::random(40))]);
        $state = Crypt::encryptString(json_encode(['r' => $store->id, 'p' => 'google', 't' => time(), 'n' => Str::random(40)]));
        $this->get(route('customer.social.callback', 'google').'?state='.urlencode($state))
            ->assertRedirect();
        $this->assertFalse(auth('customer')->check());
    }

    #[Test]
    public function apple_form_post_callback_captures_first_auth_name()
    {
        $store = $this->createRestaurant();
        $this->platformKeys('apple', [
            'client_id' => 'com.test.svc',
            'team_id' => 'TEAM123',
            'key_id' => 'KEY123',
            'private_key' => $this->fakeP8(),
        ]);

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($this->fakeSocialUser('apple-1', 'apple@example.test', null));
        Socialite::shouldReceive('driver')->with('apple')->andReturn($provider);

        $this->post(route('customer.social.callback', 'apple'), [
            'state' => $this->state($store, 'apple'),
            'user' => json_encode(['name' => ['firstName' => 'Tim', 'lastName' => 'Cook']]),
        ])->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'store_id' => $store->id,
            'oauth_provider' => 'apple',
            'oauth_uid' => 'apple-1',
            'name' => 'Tim Cook',
        ]);
    }

    #[Test]
    public function apple_client_secret_is_generated_from_p8()
    {
        $jwt = AppleClientSecret::generate([
            'client_id' => 'com.test.svc',
            'team_id' => 'TEAM123',
            'key_id' => 'KEY123',
            'private_key' => $this->fakeP8(),
        ]);

        $this->assertIsString($jwt);
        $this->assertCount(3, explode('.', $jwt)); // header.payload.signature
    }

    #[Test]
    public function apple_client_secret_null_when_incomplete()
    {
        $this->assertNull(AppleClientSecret::generate(['client_id' => 'x']));
    }

    #[Test]
    public function partial_renders_enabled_provider_buttons()
    {
        $store = $this->createRestaurant();
        $this->platformKeys('google', ['client_id' => 'plat-id', 'client_secret' => 'plat-secret']);
        $this->platformKeys('facebook', ['client_id' => 'fb-id', 'client_secret' => 'fb-secret'], false); // inactive

        $html = view('store_templates.partials.social-login-buttons', ['store' => $store])->render();

        $this->assertStringContainsString(route('customer.social.redirect', [$store->id, 'google']), $html);
        // Facebook platform row inactive → not shown.
        $this->assertStringNotContainsString(route('customer.social.redirect', [$store->id, 'facebook']), $html);
        $this->assertStringContainsString('social-login__btn--google', $html);
    }

    #[Test]
    public function partial_renders_empty_when_no_provider_enabled()
    {
        $store = $this->createRestaurant();
        $html = view('store_templates.partials.social-login-buttons', ['store' => $store])->render();
        // A1: renders NOTHING when unconfigured — not even the shared <style> block.
        $this->assertStringNotContainsString('<a class="social-login__btn', $html);
        $this->assertStringNotContainsString('role="group"', $html);
        $this->assertStringNotContainsString('social-login', $html);
        $this->assertSame('', trim($html));
    }

    #[Test]
    public function all_kept_themes_include_the_social_login_partial()
    {
        $base = resource_path('views/store_templates');
        foreach (KeptTemplates::MODERN as $theme) {
            $index = $base.'/'.$theme.'/index.blade.php';
            $this->assertFileExists($index, "Missing index for theme {$theme}");
            $this->assertStringContainsString(
                'store_templates.partials.social-login-buttons',
                file_get_contents($index),
                "Theme {$theme} does not include the social-login-buttons partial"
            );
        }
    }

    /** A throwaway EC P-256 private key for signing test tokens. */
    private function fakeP8(): string
    {
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        openssl_pkey_export($res, $pem);

        return $pem;
    }
}
