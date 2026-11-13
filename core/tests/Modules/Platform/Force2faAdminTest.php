<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\AdminMiddleware;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * S8 sub-3 — force 2FA for privileged roles (flag FORCE_2FA_ADMIN, dark). An
 * unenrolled superadmin is bounced to the 2FA enrolment screen only when the
 * flag is on; enrolled admins and the default-off flag pass straight through.
 */
class Force2faAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(bool $withTwoFactor = false): User
    {
        $attrs = ['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()];

        if ($withTwoFactor) {
            $attrs['two_factor_secret'] = 'SECRETSECRET';
            $attrs['two_factor_confirmed_at'] = now();
        }

        return User::factory()->create($attrs);
    }

    private function runMiddleware(User $admin): \Symfony\Component\HttpFoundation\Response
    {
        $this->actingAs($admin);

        return app(AdminMiddleware::class)->handle(
            Request::create('/admin/x', 'GET'),
            fn () => response('ok')
        );
    }

    public function test_flag_off_lets_an_unenrolled_admin_through(): void
    {
        config()->set('auth.force_2fa_admin', false);

        $this->assertSame('ok', $this->runMiddleware($this->admin(false))->getContent());
    }

    public function test_flag_on_bounces_an_unenrolled_admin_to_enrol(): void
    {
        config()->set('auth.force_2fa_admin', true);

        $res = $this->runMiddleware($this->admin(false));

        $this->assertTrue($res->isRedirect(), 'unenrolled admin must be redirected');
        $this->assertSame(route('2fa.show'), $res->getTargetUrl());
    }

    public function test_flag_on_lets_an_enrolled_admin_through(): void
    {
        config()->set('auth.force_2fa_admin', true);

        $this->assertSame('ok', $this->runMiddleware($this->admin(true))->getContent());
    }
}
