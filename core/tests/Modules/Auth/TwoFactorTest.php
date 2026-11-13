<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Auth;

use Juwri\Modules\Auth\Http\Controllers\TwoFactorController;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** H8 — TOTP two-factor auth: enrollment, login challenge, recovery, remember-device. */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function user(array $attrs = []): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(array_merge([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'u'.Str::lower(Str::random(6)),
            'password' => Hash::make('secret123'),
        ], $attrs));
    }

    private function otp(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    /** Enrolls a user and returns [user, secret] with 2FA confirmed. */
    private function enrolled(): array
    {
        $svc = app(TwoFactorService::class);
        $secret = $svc->generateSecret();
        $user = $this->user();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $svc->generateRecoveryCodes(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$user->fresh(), $secret];
    }

    public function test_enable_mints_secret_and_recovery_codes_unconfirmed(): void
    {
        $user = $this->user();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post(route('2fa.enable'))->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertCount(10, $user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at, 'enrollment is not confirmed until a code is entered');
        $this->assertFalse($user->hasTwoFactorEnabled());
    }

    public function test_confirm_with_valid_code_enables_two_factor(): void
    {
        $user = $this->user();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post(route('2fa.enable'));
        $secret = $user->fresh()->two_factor_secret;

        $this->actingAs($user->fresh())
            ->post(route('2fa.confirm'), ['code' => $this->otp($secret)])
            ->assertRedirect();

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_confirm_with_bad_code_does_not_enable(): void
    {
        $user = $this->user();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post(route('2fa.enable'));

        $this->actingAs($user->fresh())
            ->post(route('2fa.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_login_with_two_factor_diverts_to_challenge(): void
    {
        [$user] = $this->enrolled();

        $res = $this->post('/login', ['username' => $user->username, 'password' => 'secret123']);

        $res->assertRedirect(route('2fa.challenge'));
        $this->assertGuest();               // not fully logged in yet
    }

    public function test_challenge_with_valid_code_completes_login(): void
    {
        [$user, $secret] = $this->enrolled();
        $this->post('/login', ['username' => $user->username, 'password' => 'secret123']);

        $this->post(route('2fa.challenge.verify'), ['code' => $this->otp($secret)])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_a_code_signs_in_once_and_a_replay_is_refused(): void
    {
        // P4-06 (RFC 6238 §5.2): before, the same 6 digits signed in again for the whole ±1-step window.
        [$user, $secret] = $this->enrolled();
        $code = $this->otp($secret);
        $this->post('/login', ['username' => $user->username, 'password' => 'secret123']);
        $this->post(route('2fa.challenge.verify'), ['code' => $code])->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());

        auth()->guard('web')->logout();
        $this->flushSession();
        $this->post('/login', ['username' => $user->username, 'password' => 'secret123'])->assertRedirect(route('2fa.challenge'));
        $this->post(route('2fa.challenge.verify'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();

        // an older code than the last one used is refused too; the next step's code is accepted
        $this->assertFalse(app(TwoFactorService::class)->verifyCode($user->fresh(), (new Google2FA)->oathTotp($secret, (int) floor(time() / 30) - 1)));
        $this->assertTrue(app(TwoFactorService::class)->verifyCode($user->fresh(), (new Google2FA)->oathTotp($secret, (int) floor(time() / 30) + 1)));
    }

    public function test_recovery_code_completes_login_and_is_consumed(): void
    {
        [$user] = $this->enrolled();
        $recovery = $user->two_factor_recovery_codes[0];

        $this->post('/login', ['username' => $user->username, 'password' => 'secret123']);
        $this->post(route('2fa.challenge.verify'), ['code' => $recovery])->assertRedirect();

        $this->assertAuthenticated();
        $this->assertNotContains($recovery, $user->fresh()->two_factor_recovery_codes, 'recovery code is single-use');
    }

    public function test_remembered_device_skips_the_challenge(): void
    {
        [$user] = $this->enrolled();
        $cookie = TwoFactorController::deviceToken($user);

        $res = $this->withCookie('2fa_remember', $cookie)
            ->post('/login', ['username' => $user->username, 'password' => 'secret123']);

        $res->assertRedirect();
        $this->assertFalse($res->isRedirect(route('2fa.challenge')), 'remembered device must not be challenged');
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_disable_requires_password(): void
    {
        // S8: re-auth is now enforced by the password.confirm middleware on the
        // route rather than a manual check in the controller.
        [$user] = $this->enrolled();

        // Without a recent password confirmation → bounced to the confirm screen.
        $this->actingAs($user)->post(route('2fa.disable'))
            ->assertRedirect(route('password.confirm'));
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        // With a recent confirmation → 2FA is disabled.
        $this->actingAs($user->fresh())
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('2fa.disable'))
            ->assertRedirect()
            ->assertCookieExpired('2fa_remember');   // stale remember-device cookie is dropped
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }
}
