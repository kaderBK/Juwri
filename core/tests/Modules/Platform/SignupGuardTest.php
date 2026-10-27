<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Models\AbuseEvent;
use App\Services\SignupGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Abuse Guard P4 (4.5): disposable email, honeypot, velocity, Turnstile seam,
 * warn-vs-enforce.
 */
class SignupGuardTest extends TestCase
{
    use RefreshDatabase;

    private function req(array $input = [], string $ip = '10.0.0.1'): Request
    {
        $r = Request::create('/signup', 'POST', $input);
        $r->server->set('REMOTE_ADDR', $ip);

        return $r;
    }

    public function test_disposable_email_denied_in_enforce(): void
    {
        config(['settings.abuse_signup_mode' => 'enforce']);

        $this->expectException(ValidationException::class);
        SignupGuard::assertAllowed($this->req(), 'foo@mailinator.com');
    }

    public function test_disposable_email_warn_logs_and_allows(): void
    {
        config(['settings.abuse_signup_mode' => 'warn']);

        SignupGuard::assertAllowed($this->req(), 'foo@mailinator.com'); // no throw
        $this->assertSame(1, AbuseEvent::where('action', 'disposable_email')->count());
    }

    public function test_real_email_passes(): void
    {
        config(['settings.abuse_signup_mode' => 'enforce']);
        SignupGuard::assertAllowed($this->req(), 'someone@gmail.com');
        $this->assertSame(0, AbuseEvent::count());
    }

    public function test_honeypot_blocks_in_enforce(): void
    {
        config(['settings.abuse_signup_mode' => 'enforce']);

        $this->expectException(ValidationException::class);
        SignupGuard::assertAllowed($this->req([SignupGuard::HONEYPOT => 'http://spam']), 'someone@gmail.com');
    }

    public function test_velocity_blocks_after_cap(): void
    {
        config(['settings.abuse_signup_mode' => 'enforce', 'settings.abuse_signup_velocity_per_day' => 2]);

        SignupGuard::assertAllowed($this->req(), 'a@gmail.com');
        SignupGuard::assertAllowed($this->req(), 'b@gmail.com');
        // 3rd from same IP → over cap
        $this->expectException(ValidationException::class);
        SignupGuard::assertAllowed($this->req(), 'c@gmail.com');
    }

    public function test_normalized_email_collapses_aliases(): void
    {
        $this->assertSame('johndoe@gmail.com', SignupGuard::normalizeEmail('John.Doe+promo@gmail.com'));
        $this->assertSame('johndoe@gmail.com', SignupGuard::normalizeEmail('JohnDoe+x@gmail.com'));
    }

    public function test_turnstile_seam_passes_without_keys(): void
    {
        $this->assertTrue(SignupGuard::verifyTurnstile($this->req()));
    }
}
