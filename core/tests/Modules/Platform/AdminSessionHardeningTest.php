<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Mail\NewDeviceLogin;
use Juwri\Modules\Auth\Models\LoginAudit;
use Tests\Support\ThrowingMailTransport;
use Tests\TestCase;

/**
 * Security A1 (upstream superadmin hardening, 185d3adb0): superadmin session
 * hardening — step-up password on sensitive sections, idle timeout,
 * privileged sign-in audit with new-device email, account-security page.
 */
class AdminSessionHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        config(['auth.admin_step_up' => true, 'auth.admin_idle_timeout' => 1800]);
        $this->admin = User::factory()->create(['group_id' => '1', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        $this->shareLayoutGlobals([], true);
    }

    public function test_sensitive_sections_need_a_recent_password(): void
    {
        $this->actingAs($this->admin)->get(route('admin.gateways.index'))->assertRedirect(route('password.confirm'));
        $this->actingAs($this->admin)->postJson(route('admin.settings.update'), [])->assertStatus(423);

        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get(route('admin.security.accounts'))->assertOk()->assertSee('Superadmins');

        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => now()->subHour()->timestamp])
            ->get(route('admin.security.accounts'))->assertRedirect(route('password.confirm'));
    }

    public function test_idle_admin_session_is_signed_out(): void
    {
        $this->actingAs($this->admin)->withSession(['admin_last_seen_at' => now()->subHour()->timestamp])
            ->get(route('admin.coupons.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_privileged_sign_ins_are_audited_and_a_new_device_is_emailed(): void
    {
        Mail::fake();
        $owner = User::factory()->create(['status' => 1]);

        $this->withHeader('User-Agent', 'Firefox/1')->get('/');
        event(new Login('web', $this->admin, false));
        session()->forget('login_audited_for');
        $this->withHeader('User-Agent', 'Firefox/1')->get('/');
        event(new Login('web', $this->admin, false));
        session()->forget('login_audited_for');
        app('request')->headers->set('User-Agent', 'Evil/9');
        event(new Login('web', $this->admin, false));
        event(new Login('web', $owner, false));

        $this->assertSame(3, LoginAudit::where('user_id', $this->admin->id)->count());
        $this->assertSame(1, LoginAudit::where('user_id', $this->admin->id)->where('new_device', true)->where('user_agent', 'Evil/9')->count());
        $this->assertSame(0, LoginAudit::where('user_id', $owner->id)->count(), 'plain owners are not audited');
        Mail::assertQueued(NewDeviceLogin::class, 1);
        Mail::assertQueued(NewDeviceLogin::class, fn ($m) => $m->afterCommit === true);
        Mail::assertNothingSent();
    }

    /** P6-193: a dead mail server never breaks or delays a new-device sign-in. */
    public function test_new_device_login_succeeds_when_mail_transport_throws(): void
    {
        ThrowingMailTransport::install();
        $this->admin->forceFill(['password' => bcrypt('secret-pass')])->save();
        LoginAudit::create(['user_id' => $this->admin->id, 'ip' => '1.1.1.1', 'user_agent' => 'Old/1', 'device_hash' => hash('sha256', 'old/1'), 'new_device' => false, 'created_at' => now()->subDay()]);

        $this->withHeader('User-Agent', 'Brand-New/2')
            ->post(route('login'), ['username' => $this->admin->email, 'password' => 'secret-pass'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($this->admin);
        $this->assertSame(1, LoginAudit::where('user_id', $this->admin->id)->where('new_device', true)->count());
    }
}
