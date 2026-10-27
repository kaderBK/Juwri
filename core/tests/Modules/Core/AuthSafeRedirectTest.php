<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\User;
use Juwri\Core\Support\SafeRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The auth flow honors a caller-supplied `?redirect` (e.g. the Studio "Save my
 * work" CTA) so a guest lands back where they came from after signing in/up —
 * but ONLY a same-site target (open-redirect guard). Login already honors
 * `redirect()->intended()` (see DirectoryClaimLoginRedirectTest); the gap was
 * that nothing turned the `?redirect` query param into `url.intended`.
 */
class AuthSafeRedirectTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------- SafeRedirect (the guard)

    public function test_resolve_allows_same_site_targets_only(): void
    {
        // Relative same-site paths.
        $this->assertSame('/en/studio/app', SafeRedirect::resolve('/en/studio/app'));
        $this->assertSame('/studio/app?x=1', SafeRedirect::resolve('/studio/app?x=1'));

        // Absolute URL on the app host is allowed (host = localhost in tests).
        $same = url('/studio/app');
        $this->assertSame($same, SafeRedirect::resolve($same));

        // Cross-origin + junk are rejected.
        $this->assertNull(SafeRedirect::resolve('https://evil.com/x'));
        $this->assertNull(SafeRedirect::resolve('//evil.com'));
        $this->assertNull(SafeRedirect::resolve('/\\evil.com'));
        // P6-90b: backslash / userinfo authority confusion (parse_url sees the app host, browsers see evil.com).
        $this->assertNull(SafeRedirect::resolve('https://evil.com\\@'.parse_url((string) config('app.url'), PHP_URL_HOST).'/x'));
        $this->assertNull(SafeRedirect::resolve('https://evil.com%5C@'.parse_url((string) config('app.url'), PHP_URL_HOST).'/x'));
        $this->assertNull(SafeRedirect::resolve('https://user@'.parse_url((string) config('app.url'), PHP_URL_HOST).'/x'));
        $this->assertNull(SafeRedirect::resolve('javascript:alert(1)'));
        $this->assertNull(SafeRedirect::resolve('data:text/html,x'));
        $this->assertNull(SafeRedirect::resolve("/a\nb")); // control char
        $this->assertNull(SafeRedirect::resolve(''));
        $this->assertNull(SafeRedirect::resolve(null));
        $this->assertNull(SafeRedirect::resolve('/'.str_repeat('a', 3000))); // over-long
    }

    public function test_stash_intended_only_stashes_safe_targets(): void
    {
        SafeRedirect::stashIntended('https://evil.com/x');
        $this->assertNull(session('url.intended'), 'unsafe target must not be stashed');

        SafeRedirect::stashIntended('/en/studio/app');
        $this->assertSame('/en/studio/app', session('url.intended'));
    }

    // ------------------------------------------------------------------- login

    public function test_login_form_stashes_a_safe_redirect_and_login_honors_it(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $user = User::factory()->create([
            'status' => 1, 'email_verified_at' => now(), 'password' => bcrypt('secret-pass'),
        ]);
        $target = url('/studio/app');

        // GET the form with ?redirect → stashed as the intended URL.
        $this->get(route('login').'?redirect='.urlencode($target));
        $this->assertSame($target, session('url.intended'));

        // POST login → sent to the target, not the dashboard.
        $this->post(route('login'), ['username' => $user->email, 'password' => 'secret-pass'])
            ->assertRedirect($target);
    }

    public function test_login_form_ignores_a_cross_site_redirect(): void
    {
        $this->get(route('login').'?redirect='.urlencode('https://evil.com/steal'));
        $this->assertNull(session('url.intended'), 'a cross-site ?redirect must never be stashed');
    }

    // ---------------------------------------------------------------- register

    public function test_register_form_stashes_a_safe_redirect(): void
    {
        $this->withoutMiddleware([Installed::class]);

        $target = url('/studio/app');
        $this->get(route('register').'?redirect='.urlencode($target))->assertOk();
        $this->assertSame($target, session('url.intended'));
    }
}
