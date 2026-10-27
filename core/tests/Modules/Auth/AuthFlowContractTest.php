<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Auth;

use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Juwri\Core\Mail\ResetPassword as ResetPasswordMail;
use Juwri\Core\Mail\VerifyEmail as VerifyEmailMail;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Behaviour contract for the owner sign-in, sign-up, password reset and
 * email verification flows (WI-07). Written against the flows as they
 * behaved before the rewrite; the rewritten controllers must keep every
 * assertion here green.
 */
class AuthFlowContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The installer gate (being removed in WI-02) would redirect every
        // route while APP_INSTALLED=false in phpunit.xml.
        $bypass = [PreventRequestForgery::class];
        if (class_exists('App\\Http\\Middleware\\Installed')) {
            $bypass[] = 'App\\Http\\Middleware\\Installed';
        }
        $this->withoutMiddleware($bypass);
    }

    private function owner(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'username' => 'owner'.random_int(1000, 9999),
            'status' => 1,
            'email_verified_at' => now(),
            'password' => Hash::make('secret-pass-1'),
        ]);
    }

    // ------------------------------------------------------------ sign-in

    public function test_login_form_renders(): void
    {
        $this->get(route('login'))->assertOk();
    }

    public function test_login_with_username_goes_to_the_dashboard(): void
    {
        $user = $this->owner();

        $response = $this->post(route('login'), ['username' => $user->username, 'password' => 'secret-pass-1']);

        $response->assertRedirect();
        $this->assertStringEndsWith('/dashboard', $response->headers->get('Location'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_accepts_the_email_address_in_the_username_field(): void
    {
        $user = $this->owner();

        $this->post(route('login'), ['username' => $user->email, 'password' => 'secret-pass-1'])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_regenerates_the_session_and_marks_the_password_confirmed(): void
    {
        $user = $this->owner();
        $this->startSession();
        $before = session()->getId();

        $this->post(route('login'), ['username' => $user->username, 'password' => 'secret-pass-1']);

        $this->assertNotSame($before, session()->getId(), 'session id must change on sign-in (fixation)');
        $this->assertIsInt(session('auth.password_confirmed_at'));
    }

    public function test_remember_me_sets_the_recaller_cookie(): void
    {
        $user = $this->owner();

        $response = $this->post(route('login'), [
            'username' => $user->username, 'password' => 'secret-pass-1', 'remember' => 'on',
        ]);

        $response->assertCookie(Auth::guard('web')->getRecallerName());
    }

    public function test_wrong_password_fails_on_the_username_field(): void
    {
        $user = $this->owner();

        $this->from(route('login'))
            ->post(route('login'), ['username' => $user->username, 'password' => 'wrong'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_login_requires_username_and_password(): void
    {
        $this->post(route('login'), [])->assertSessionHasErrors(['username', 'password']);
    }

    public function test_sixth_attempt_within_a_minute_is_locked_out(): void
    {
        $user = $this->owner();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('login'), ['username' => $user->username, 'password' => 'wrong'])
                ->assertStatus(422);
        }

        // Locked out by the per-account counter even with the right password.
        $this->postJson(route('login'), ['username' => $user->username, 'password' => 'secret-pass-1'])
            ->assertStatus(429)
            ->assertJsonValidationErrors('username');

        $this->assertGuest();
    }

    public function test_successful_json_login_returns_no_content(): void
    {
        $user = $this->owner();

        $this->postJson(route('login'), ['username' => $user->username, 'password' => 'secret-pass-1'])
            ->assertNoContent();
    }

    public function test_admin_lands_on_the_admin_dashboard(): void
    {
        $admin = $this->owner(['user_type' => 'admin']);

        $this->post(route('login'), ['username' => $admin->username, 'password' => 'secret-pass-1'])
            ->assertRedirect(url(admin_url().'/dashboard'));
    }

    public function test_blocked_account_is_signed_out_again(): void
    {
        $user = $this->owner(['status' => 0]);

        $this->post(route('login'), ['username' => $user->username, 'password' => 'secret-pass-1'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('app_alert_type', 'error');

        $this->assertGuest();
    }

    public function test_signed_in_user_is_sent_away_from_the_login_form(): void
    {
        $this->actingAs($this->owner())->get(route('login'))->assertRedirect();
    }

    public function test_logout_signs_out_and_rotates_the_csrf_token(): void
    {
        $this->actingAs($this->owner());
        $this->startSession();
        $token = session()->token();

        $this->post(route('logout'))->assertRedirect('/');

        $this->assertGuest();
        $this->assertNotSame($token, session()->token());
    }

    public function test_json_logout_returns_no_content(): void
    {
        $this->actingAs($this->owner())->postJson(route('logout'))->assertNoContent();
    }

    // --------------------------------------------------------- registration

    private function signupPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'New Owner',
            'username' => 'newowner',
            'email' => 'new-owner@example.com',
            'password' => 'secret-pass-1',
            'agree_for_term' => 'on',
        ];
    }

    public function test_registration_form_renders(): void
    {
        $this->get(route('register'))->assertOk();
    }

    private function defaultPlan(): void
    {
        // user_ip_info() reads the raw user-agent superglobal; CLI has none.
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';
        $plan = TestPlans::create([
            'name' => 'Trial', 'description' => 'T',
            'monthly_price' => 0, 'annual_price' => 0, 'lifetime_price' => 0,
            'settings' => (object) ['restaurant_limit' => 3], 'status' => 1,
        ]);
        config(['settings.default_user_plan' => (string) $plan->id]);
    }

    public function test_registration_creates_the_account_signs_in_and_sends_mail(): void
    {
        Mail::fake();
        $this->defaultPlan();

        $this->post(route('register'), $this->signupPayload())
            ->assertRedirect(route('dashboard'));

        $user = User::where('email', 'new-owner@example.com')->firstOrFail();
        $this->assertSame('newowner', $user->username);
        $this->assertTrue(Hash::check('secret-pass-1', $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        Mail::assertQueued(VerifyEmailMail::class);
    }

    public function test_registration_rejects_a_taken_username_and_email(): void
    {
        $this->owner(['username' => 'takenname', 'email' => 'taken@example.com']);

        $this->post(route('register'), $this->signupPayload(['username' => 'takenname', 'email' => 'taken@example.com']))
            ->assertSessionHasErrors(['username', 'email']);

        $this->assertGuest();
    }

    public function test_registration_can_be_switched_off(): void
    {
        config(['settings.enable_user_registration' => 0]);

        $this->get(route('register'))->assertRedirect(route('login'));
        $this->post(route('register'), $this->signupPayload())->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'new-owner@example.com']);
    }

    public function test_signed_in_user_is_sent_away_from_the_signup_form(): void
    {
        $this->actingAs($this->owner())->get(route('register'))->assertRedirect();
    }

    /** The endpoint's HTML fragment, whether it is echoed or returned. */
    private function availability(array $input): string
    {
        ob_start();
        $response = $this->post(route('register.check_availability'), $input);
        $echoed = (string) ob_get_clean();
        $response->assertOk();

        return $echoed.$response->getContent();
    }

    public function test_availability_check_reports_a_taken_email(): void
    {
        $this->owner(['email' => 'taken@example.com']);

        $this->assertStringContainsString('status-not-available', $this->availability(['email' => 'taken@example.com']));
        $this->assertStringContainsString("'status-available'", $this->availability(['email' => 'free@example.com']));
    }

    public function test_availability_check_reports_a_taken_username(): void
    {
        // Before WI-07 the name/username checks were discarded and always
        // answered "available"; the rewrite validates them.
        $this->owner(['username' => 'takenname']);

        $this->assertStringContainsString('status-not-available', $this->availability(['username' => 'takenname']));
        $this->assertStringContainsString("'status-available'", $this->availability(['username' => 'freename']));
        $this->assertStringContainsString('status-not-available', $this->availability(['name' => 'x']));
    }

    public function test_availability_check_reports_a_short_password(): void
    {
        $this->assertStringContainsString('status-not-available', $this->availability(['password' => 'abc']));
        $this->assertStringContainsString("'status-available'", $this->availability(['password' => 'long-enough']));
    }

    // ------------------------------------------------------- password reset

    public function test_forgot_password_form_renders(): void
    {
        $this->get(route('password.request'))->assertOk();
    }

    public function test_reset_link_request_is_neutral_for_unknown_addresses(): void
    {
        Mail::fake();
        $user = $this->owner();

        $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]);
        $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@example.com']);

        $known->assertRedirect(route('password.request'))->assertSessionHasNoErrors();
        $unknown->assertRedirect(route('password.request'))->assertSessionHasNoErrors();
        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));

        Mail::assertQueued(ResetPasswordMail::class, 1);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_reset_link_request_is_neutral_as_json(): void
    {
        $this->postJson(route('password.email'), ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonStructure(['message']);
    }

    public function test_reset_form_renders_with_token(): void
    {
        $this->get(route('password.reset', ['token' => 'abc', 'email' => 'a@example.com']))->assertOk();
    }

    public function test_valid_token_resets_the_password_and_signs_in(): void
    {
        Event::fake([PasswordReset::class]);
        $user = $this->owner();
        $oldRemember = $user->remember_token;
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-pass-2',
            'password_confirmation' => 'brand-new-pass-2',
        ])->assertRedirect()->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-pass-2', $user->password));
        $this->assertNotSame($oldRemember, $user->remember_token);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Event::assertDispatched(PasswordReset::class);
    }

    public function test_invalid_token_keeps_the_password(): void
    {
        $user = $this->owner();

        $this->from(route('password.request'))->post(route('password.update'), [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'brand-new-pass-2',
            'password_confirmation' => 'brand-new-pass-2',
        ])->assertRedirect(route('password.request'))->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('secret-pass-1', $user->refresh()->password));
        $this->assertGuest();
    }

    public function test_reset_token_is_single_use(): void
    {
        $user = $this->owner();
        $token = Password::broker()->createToken($user);
        $payload = [
            'token' => $token, 'email' => $user->email,
            'password' => 'brand-new-pass-2', 'password_confirmation' => 'brand-new-pass-2',
        ];

        $this->post(route('password.update'), $payload)->assertSessionHasNoErrors();
        Auth::logout();

        $payload['password'] = $payload['password_confirmation'] = 'third-pass-3';
        $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('brand-new-pass-2', $user->refresh()->password));
    }

    public function test_reset_requires_matching_confirmation(): void
    {
        $user = $this->owner();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'brand-new-pass-2', 'password_confirmation' => 'different-2',
        ])->assertSessionHasErrors('password');
    }

    public function test_reset_failure_as_json_is_a_validation_error(): void
    {
        $user = $this->owner();

        $this->postJson(route('password.update'), [
            'token' => 'nope', 'email' => $user->email,
            'password' => 'brand-new-pass-2', 'password_confirmation' => 'brand-new-pass-2',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    // --------------------------------------------------- email verification

    private function verifyUrl(User $user, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => $hash ?? sha1($user->getEmailForVerification()),
        ]);
    }

    public function test_signed_link_verifies_the_email(): void
    {
        Event::fake([Verified::class]);
        $user = $this->owner(['email_verified_at' => null]);

        $this->actingAs($user)->get($this->verifyUrl($user))
            ->assertRedirect()
            ->assertSessionHas('verified', true);

        $this->assertNotNull($user->refresh()->email_verified_at);
        Event::assertDispatched(Verified::class);
    }

    public function test_link_with_the_wrong_hash_is_forbidden(): void
    {
        $user = $this->owner(['email_verified_at' => null]);

        $this->actingAs($user)->get($this->verifyUrl($user, sha1('other@example.com')))->assertForbidden();
        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_link_for_another_user_is_forbidden(): void
    {
        $user = $this->owner(['email_verified_at' => null]);
        $other = $this->owner(['email_verified_at' => null]);

        $this->actingAs($user)->get($this->verifyUrl($other))->assertForbidden();
        $this->assertNull($other->refresh()->email_verified_at);
    }

    public function test_unsigned_link_is_rejected(): void
    {
        $user = $this->owner(['email_verified_at' => null]);

        $this->actingAs($user)
            ->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]))
            ->assertForbidden();
    }

    public function test_verify_link_needs_a_session(): void
    {
        $user = $this->owner(['email_verified_at' => null]);

        $this->get($this->verifyUrl($user))->assertRedirect();
        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_resend_queues_a_new_verification_mail(): void
    {
        Mail::fake();
        $user = $this->owner(['email_verified_at' => null]);

        $this->actingAs($user)->from('/dashboard')->post(route('verification.resend'))
            ->assertRedirect('/dashboard')
            ->assertSessionHas('app_alert_type', 'success');

        Mail::assertQueued(VerifyEmailMail::class);
    }

    public function test_resend_is_a_no_op_once_verified(): void
    {
        Mail::fake();
        $user = $this->owner();

        $this->actingAs($user)->from('/dashboard')->post(route('verification.resend'))->assertRedirect('/dashboard');

        Mail::assertNothingQueued();
    }

    public function test_resend_requires_a_session(): void
    {
        $this->post(route('verification.resend'))->assertRedirect();
        $this->assertSame(0, DB::table('users')->count());
    }
}
