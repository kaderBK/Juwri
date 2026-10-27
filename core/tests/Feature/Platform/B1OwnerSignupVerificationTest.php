<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Juwri\Core\Mail\VerifyEmail;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * B1 — owner signup → verified (the seam Lane B's `platform/b-signup.js` drives
 * end-to-end in a real browser: register on the public form, pull the
 * verification link out of the mail log, visit it, then create the first store).
 *
 * This locks the server-side contract that journey depends on,
 * HTTP-through-controller (a Blade-direct test would miss the controller wiring):
 *   1. POST /signup creates an UNVERIFIED owner, logs them in, and dispatches the
 *      verification mail to that address.
 *   2. The signed verification link marks `email_verified_at`.
 *   3. A verification link carrying a hash for a different address does NOT verify
 *      (the sha1(email) guard in EmailVerificationRequest still holds).
 *
 * Discriminating: it fails if registration ever starts creating pre-verified
 * users, stops sending the verification mail, or the verify route stops enforcing
 * the hash / marking verification.
 */
class B1OwnerSignupVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // APP_INSTALLED=false in the test env; signup + verify sit behind the
        // Installed gate and CSRF like every web route (same as the sibling
        // StoreCreateLimitTest).
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_signup_creates_an_unverified_owner_and_dispatches_verification_mail(): void
    {
        Mail::fake();

        $stamp = (string) time();
        $email = "e2e-b-owner-{$stamp}@local.invalid";

        // Registration calls user_ip_info() → user_os_info()/user_browser_info(),
        // which read the raw $_SERVER['HTTP_USER_AGENT'] superglobal (not the
        // request header) unguarded (Helper.php) and 500 on a request without one
        // — see ISS-B-004. Real browsers always send a UA; a CLI request has none,
        // so seed the superglobal the helper reads.
        $_SERVER['HTTP_USER_AGENT'] = 'e2e-b/phpunit';

        // Registration seeds the new owner's group_id from the default plan
        // setting. The live lane has this configured; the test env does not, so
        // stand up a default plan (group_id is NOT NULL) as the sibling
        // StoreCreateLimitTest does.
        $plan = TestPlans::create([
            'name' => 'Trial', 'description' => 'T',
            'monthly_price' => 0, 'annual_price' => 0, 'lifetime_price' => 0,
            'settings' => (object) ['restaurant_limit' => 3], 'status' => 1,
        ]);
        config(['settings.default_user_plan' => (string) $plan->id]);

        $response = $this->post(route('register'), [
            'username' => 'b'.substr($stamp, -12),
            'email' => $email,
            'password' => 'E2e-pass-1234',
            'password_confirmation' => 'E2e-pass-1234',
            'agree_for_term' => '1',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user, 'signup did not create the owner row');
        $this->assertNull($user->email_verified_at, 'a freshly registered owner must NOT be pre-verified');
        $this->assertFalse($user->hasVerifiedEmail());

        // The verification mail is actually dispatched to the new owner.
        Mail::assertQueued(VerifyEmail::class, fn ($mail) => $mail->hasTo($email));
    }

    public function test_signed_verification_link_marks_the_owner_verified(): void
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => null]);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        // Built exactly as Juwri\Core\Mail\VerifyEmail does.
        $url = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        );

        $this->actingAs($user)->get($url)->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at, 'the signed link did not verify the owner');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_verification_link_for_a_different_address_does_not_verify(): void
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => null]);

        // A validly-signed URL whose hash is for a DIFFERENT address must be
        // rejected by the sha1(email) guard, leaving the owner unverified.
        $url = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1('someone-else@example.com')],
        );

        $this->actingAs($user)->get($url)->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at, 'a mismatched hash must not verify the owner');
    }
}
