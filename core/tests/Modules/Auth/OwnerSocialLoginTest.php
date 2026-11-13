<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Auth;

use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

/**
 * google-suite Lane A — owner (web guard) Google/Facebook sign-in.
 *  - A3: connect/disconnect a provider to the CURRENT authenticated owner.
 *  - A4: (added below) verified-email gate on the login/register callback.
 */
class OwnerSocialLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
        ]);
        config(['settings.google_login' => '1']);
        config(['settings.facebook_login' => '1']);
    }

    /**
     * Socialite user carrying a raw claim array (Google `email_verified`).
     */
    private function mockSocialite(string $provider, string $id, ?string $email, ?string $name, ?bool $emailVerified = null): void
    {
        $raw = [];
        if ($emailVerified !== null) {
            $raw['email_verified'] = $emailVerified;
        }

        $socialUser = Mockery::mock(\Laravel\Socialite\Contracts\User::class);
        $socialUser->shouldReceive('getId')->andReturn($id);
        $socialUser->shouldReceive('getEmail')->andReturn($email);
        $socialUser->shouldReceive('getName')->andReturn($name);
        $socialUser->shouldReceive('getNickname')->andReturn(null);
        $socialUser->shouldReceive('getAvatar')->andReturn(null);
        $socialUser->user = $raw;

        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function connect_redirect_404s_when_provider_disabled()
    {
        config(['settings.google_login' => '0']);
        $owner = User::factory()->create(['status' => 1]);

        $this->actingAs($owner)
            ->get(route('social.connect', 'google'))
            ->assertNotFound();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function connect_redirect_404s_for_unlinkable_provider()
    {
        $owner = User::factory()->create(['status' => 1]);

        $this->actingAs($owner)
            ->get(route('social.connect', 'apple'))
            ->assertNotFound();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function connect_flow_links_provider_to_current_owner()
    {
        $owner = User::factory()->create(['status' => 1, 'oauth_provider' => null, 'oauth_uid' => null]);
        $this->mockSocialite('google', 'g-owner-1', 'owner@example.test', 'Owner', true);

        $this->actingAs($owner)
            ->withSession(['social_connect_provider' => 'google'])
            ->get(route('social.callback', 'google'))
            ->assertRedirect(route('settings'));

        $owner->refresh();
        $this->assertSame('g-owner-1', $owner->oauth_uid);
        $this->assertSame('google', $owner->oauth_provider);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function connect_refuses_identity_already_linked_to_another_owner()
    {
        User::factory()->create(['status' => 1, 'oauth_provider' => 'google', 'oauth_uid' => 'g-taken']);
        $owner = User::factory()->create(['status' => 1, 'oauth_provider' => null, 'oauth_uid' => null]);
        $this->mockSocialite('google', 'g-taken', 'owner@example.test', 'Owner', true);

        $this->actingAs($owner)
            ->withSession(['social_connect_provider' => 'google'])
            ->get(route('social.callback', 'google'))
            ->assertRedirect(route('settings'));

        $owner->refresh();
        $this->assertNull($owner->oauth_uid);
        $this->assertNull($owner->oauth_provider);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function disconnect_clears_owner_oauth_fields()
    {
        $owner = User::factory()->create(['status' => 1, 'oauth_provider' => 'google', 'oauth_uid' => 'g-owner-1']);

        $this->actingAs($owner)
            ->post(route('social.disconnect', 'google'))
            ->assertRedirect(route('settings'));

        $owner->refresh();
        $this->assertNull($owner->oauth_uid);
        $this->assertNull($owner->oauth_provider);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function owner_verified_email_links_existing_account_and_logs_in()
    {
        $owner = User::factory()->create([
            'status' => 1, 'email' => 'owner@example.test',
            'oauth_provider' => null, 'oauth_uid' => null,
        ]);
        $this->mockSocialite('google', 'g-new-uid', 'owner@example.test', 'Owner', true);

        $this->get(route('social.callback', 'google'))->assertRedirect();

        $owner->refresh();
        $this->assertSame('g-new-uid', $owner->oauth_uid);
        $this->assertSame('google', $owner->oauth_provider);
        $this->assertAuthenticatedAs($owner);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function owner_unverified_email_does_not_login_existing_account()
    {
        config(['settings.enable_user_registration' => 0]);
        $owner = User::factory()->create([
            'status' => 1, 'email' => 'owner@example.test',
            'oauth_provider' => null, 'oauth_uid' => null,
        ]);
        $this->mockSocialite('google', 'g-evil', 'owner@example.test', 'Evil', false);

        $this->get(route('social.callback', 'google'))->assertRedirect(route('login'));

        $owner->refresh();
        $this->assertNull($owner->oauth_uid);
        $this->assertGuest();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function owner_facebook_email_does_not_login_existing_account()
    {
        config(['settings.enable_user_registration' => 0]);
        $owner = User::factory()->create([
            'status' => 1, 'email' => 'owner@example.test',
            'oauth_provider' => null, 'oauth_uid' => null,
        ]);
        $this->mockSocialite('facebook', 'fb-evil', 'owner@example.test', 'Evil', null);

        $this->get(route('social.callback', 'facebook'))->assertRedirect(route('login'));

        $owner->refresh();
        $this->assertNull($owner->oauth_uid);
        $this->assertGuest();
    }
}
