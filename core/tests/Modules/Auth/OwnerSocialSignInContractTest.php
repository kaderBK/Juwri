<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Mail\UserDetails;
use Juwri\Core\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Behaviour contract for the owner social sign-in / sign-up callback (WI-07),
 * complementing OwnerSocialLoginTest (connect/disconnect + the verified-email
 * account-takeover guard).
 */
class OwnerSocialSignInContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists('App\\Http\\Middleware\\Installed')) {
            $this->withoutMiddleware(['App\\Http\\Middleware\\Installed']);
        }
        config(['settings.google_login' => '1', 'settings.facebook_login' => '1']);
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';

        $plan = TestPlans::create([
            'name' => 'Trial', 'description' => 'T',
            'monthly_price' => 0, 'annual_price' => 0, 'lifetime_price' => 0,
            'settings' => (object) ['restaurant_limit' => 3], 'status' => 1,
        ]);
        config(['settings.default_user_plan' => (string) $plan->id]);
    }

    private function provider(string $provider, string $id, ?string $email, ?bool $verified = null, ?\Throwable $fails = null): void
    {
        $socialUser = Mockery::mock(\Laravel\Socialite\Contracts\User::class);
        $socialUser->shouldReceive('getId')->andReturn($id);
        $socialUser->shouldReceive('getEmail')->andReturn($email);
        $socialUser->shouldReceive('getName')->andReturn('Social Owner');
        $socialUser->user = $verified === null ? [] : ['email_verified' => $verified];

        $driver = Mockery::mock();
        if ($fails) {
            $driver->shouldReceive('user')->andThrow($fails);
        } else {
            $driver->shouldReceive('user')->andReturn($socialUser);
        }
        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    public function test_redirect_is_404_when_the_provider_is_off(): void
    {
        config(['settings.google_login' => '0']);

        $this->get(route('social.login', 'google'))->assertNotFound();
    }

    public function test_new_google_identity_creates_a_verified_owner_and_signs_in(): void
    {
        Mail::fake();
        $this->provider('google', 'g-100', 'jane.doe@example.com', true);

        $this->get(route('social.callback', 'google'))->assertRedirect(route('dashboard'));

        $user = User::where('email', 'jane.doe@example.com')->firstOrFail();
        $this->assertSame('google', $user->oauth_provider);
        $this->assertSame('g-100', $user->oauth_uid);
        $this->assertSame('jane_doe', $user->username);
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        Mail::assertQueued(UserDetails::class);
    }

    public function test_new_facebook_identity_is_not_pre_verified(): void
    {
        Mail::fake();
        $this->provider('facebook', 'fb-1', 'fb.owner@example.com');

        $this->get(route('social.callback', 'facebook'))->assertRedirect(route('dashboard'));

        $user = User::where('email', 'fb.owner@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_returning_identity_signs_in_by_provider_uid(): void
    {
        $owner = User::factory()->create([
            'status' => 1, 'email' => 'changed@example.com',
            'oauth_provider' => 'google', 'oauth_uid' => 'g-200',
        ]);
        $this->provider('google', 'g-200', 'other-address@example.com', false);

        $this->get(route('social.callback', 'google'))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($owner);
        $this->assertSame(1, User::count());
    }

    public function test_new_identity_is_refused_while_registration_is_off(): void
    {
        config(['settings.enable_user_registration' => 0]);
        $this->provider('google', 'g-300', 'someone@example.com', true);

        $this->get(route('social.callback', 'google'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('app_alert_type', 'error');

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_provider_failure_returns_to_login_with_an_error(): void
    {
        $this->provider('google', 'x', null, null, new \RuntimeException('Invalid state'));

        $this->get(route('social.callback', 'google'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('app_alert_type', 'error');

        $this->assertGuest();
    }

    public function test_callback_for_a_disabled_provider_returns_to_login(): void
    {
        config(['settings.google_login' => '0']);

        $this->get(route('social.callback', 'google'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
