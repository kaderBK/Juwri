<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * google-suite Lane A (A6) — reseller/custom-domain OAuth bounce. The callback runs
 * on the fixed platform host; a custom-domain store gets a single-use, server-side
 * -burned login token bounced back to its own origin.
 */
class CustomerSocialBounceTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOM_DOMAIN = 'shop.example';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['app.url' => 'http://localhost']); // platform host = localhost
        $this->withoutMiddleware([Installed::class]);
    }

    private function createRestaurant(): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'resto-'.Str::lower(Str::random(6)),
            'color' => '#ff5500', 'title' => 'Resto', 'sub_title' => 'x',
            'description' => 'x', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);
    }

    private function withCustomDomain(Store $store, string $domain = self::CUSTOM_DOMAIN): void
    {
        StoreDomain::create([
            'store_id' => $store->id,
            'domain' => $domain,
            'verified' => true,
            'verification_token' => Str::random(40),
        ]);
    }

    private function platformKeys(string $provider, array $secrets): void
    {
        Credential::create([
            'store_id' => null, 'feature' => 'social',
            'provider' => $provider, 'secrets' => $secrets, 'active' => true,
        ]);
    }

    private function state(Store $store, string $provider): string
    {
        // Mirror redirect(): bind the login-CSRF nonce to the session (google/facebook).
        $nonce = Str::random(40);
        $this->withSession(['customer_social_nonce' => hash('sha256', $nonce)]);

        return Crypt::encryptString(json_encode(['r' => $store->id, 'p' => $provider, 't' => time(), 'n' => $nonce]));
    }

    private function mockGoogleUser(string $id, string $email): void
    {
        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn($id);
        $socialUser->shouldReceive('getEmail')->andReturn($email);
        $socialUser->shouldReceive('getName')->andReturn('Diner');
        $socialUser->shouldReceive('getNickname')->andReturn(null);
        $socialUser->shouldReceive('getAvatar')->andReturn(null);
        $socialUser->user = ['email_verified' => true];

        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('redirectUrl')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    private function bounceKey(string $token): string
    {
        return 'social_bounce:'.hash('sha256', $token);
    }

    #[Test]
    public function callback_bounces_custom_domain_store_to_its_origin_without_platform_login()
    {
        $store = $this->createRestaurant();
        $this->withCustomDomain($store);
        $this->platformKeys('google', ['client_id' => 'plat-id', 'client_secret' => 'plat-secret']);
        $this->mockGoogleUser('goog-cd', 'cd@example.test');

        $resp = $this->get(route('customer.social.callback', 'google').'?state='.urlencode($this->state($store, 'google')));
        $resp->assertRedirect();

        $location = $resp->headers->get('Location');
        $this->assertStringStartsWith('https://'.self::CUSTOM_DOMAIN.'/auth/social/customer/bounce?token=', $location);
        // Customer created, but NOT logged in on the platform host (bounce owns login).
        $this->assertDatabaseHas('customers', ['store_id' => $store->id, 'oauth_uid' => 'goog-cd']);
        $this->assertGuest('customer');
    }

    #[Test]
    public function plain_platform_store_does_not_bounce()
    {
        $store = $this->createRestaurant(); // no custom domain
        $this->platformKeys('google', ['client_id' => 'plat-id', 'client_secret' => 'plat-secret']);
        $this->mockGoogleUser('goog-plain', 'plain@example.test');

        $this->get(route('customer.social.callback', 'google').'?state='.urlencode($this->state($store, 'google')))
            ->assertRedirect();

        $this->assertAuthenticated('customer'); // logged in directly, no bounce
    }

    #[Test]
    public function bounce_consumes_token_logs_in_and_burns_it()
    {
        $store = $this->createRestaurant();
        $this->withCustomDomain($store);
        $customer = Customer::create([
            'store_id' => $store->id, 'name' => 'C',
            'oauth_provider' => 'google', 'oauth_uid' => 'g',
        ]);
        $token = Str::random(64);
        Cache::put($this->bounceKey($token), ['cid' => $customer->id, 'rid' => $store->id], now()->addSeconds(120));

        $this->get('https://'.self::CUSTOM_DOMAIN.'/auth/social/customer/bounce?token='.$token)
            ->assertRedirect();

        $this->assertAuthenticated('customer');
        $this->assertNull(Cache::get($this->bounceKey($token))); // burned
    }

    #[Test]
    public function bounce_second_use_is_refused()
    {
        $store = $this->createRestaurant();
        $this->withCustomDomain($store);
        $customer = Customer::create([
            'store_id' => $store->id, 'name' => 'C',
            'oauth_provider' => 'google', 'oauth_uid' => 'g',
        ]);
        $token = Str::random(64);
        Cache::put($this->bounceKey($token), ['cid' => $customer->id, 'rid' => $store->id], now()->addSeconds(120));

        // First use succeeds → redirect to the account page, token burned.
        $first = $this->get('https://'.self::CUSTOM_DOMAIN.'/auth/social/customer/bounce?token='.$token);
        $this->assertStringContainsString('/customer/account', (string) $first->headers->get('Location'));
        $this->assertNull(Cache::get($this->bounceKey($token)));

        // Second use of the burned token takes the failure path (not the account page).
        $second = $this->get('https://'.self::CUSTOM_DOMAIN.'/auth/social/customer/bounce?token='.$token);
        $this->assertStringNotContainsString('/customer/account', (string) $second->headers->get('Location'));
    }

    #[Test]
    public function bounce_expired_token_is_refused()
    {
        $store = $this->createRestaurant();
        $this->withCustomDomain($store);
        $customer = Customer::create([
            'store_id' => $store->id, 'name' => 'C',
            'oauth_provider' => 'google', 'oauth_uid' => 'g',
        ]);
        $token = Str::random(64);
        Cache::put($this->bounceKey($token), ['cid' => $customer->id, 'rid' => $store->id], now()->addSeconds(120));

        $this->travel(121)->seconds();

        $this->get('https://'.self::CUSTOM_DOMAIN.'/auth/social/customer/bounce?token='.$token)
            ->assertRedirect();
        $this->assertGuest('customer');
    }

    #[Test]
    public function bounce_from_foreign_origin_is_refused()
    {
        $store = $this->createRestaurant();
        $this->withCustomDomain($store);
        $customer = Customer::create([
            'store_id' => $store->id, 'name' => 'C',
            'oauth_provider' => 'google', 'oauth_uid' => 'g',
        ]);
        $token = Str::random(64);
        Cache::put($this->bounceKey($token), ['cid' => $customer->id, 'rid' => $store->id], now()->addSeconds(120));

        // Consume on the platform host (localhost) instead of the store's origin.
        $this->get('http://localhost/auth/social/customer/bounce?token='.$token)
            ->assertRedirect();
        $this->assertGuest('customer');
    }
}
