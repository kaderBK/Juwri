<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * H4 — web login endpoints are rate-limited. The `auth-login` limiter keys on
 * email|ip (6/min), so brute-forcing a staff or owner password is throttled
 * without letting an attacker lock a victim out by hammering their email alone
 * (the ip is part of the key). Password-reset email is throttled too.
 */
class AuthLoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
        RateLimiter::clear('');
    }

    public function test_staff_login_is_throttled_after_six_attempts(): void
    {
        $status = 0;
        for ($i = 0; $i < 7; $i++) {
            $status = $this->post('/staff/login', [
                'email'    => 'attacker@example.com',
                'password' => 'wrong-guess-' . $i,
            ])->getStatusCode();
        }

        // 6 allowed per minute → the 7th is blocked.
        $this->assertSame(429, $status, 'Staff login must return 429 after the auth-login limit.');
    }

    public function test_owner_login_route_carries_the_auth_login_limiter(): void
    {
        $status = 0;
        for ($i = 0; $i < 7; $i++) {
            $status = $this->post('/login', [
                'email'    => 'owner-brute@example.com',
                'password' => 'nope-' . $i,
            ])->getStatusCode();
        }

        $this->assertSame(429, $status, 'Owner login must return 429 after the auth-login limit.');
    }
}
