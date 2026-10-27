<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

class ImpersonationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';

        $settings = new class([])
        {
            public function __get(string $key): mixed
            {
                return null;
            }

            public function __isset(string $key): bool
            {
                return false;
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makePlan(): object
    {
        return TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => json_encode(['restaurant_limit' => 5]), 'status' => 1]);
    }

    private function makeUser(bool $admin = false): User
    {
        return User::factory()->create([
            'group_id' => (string) $this->makePlan()->id,
            'status' => 1,
            'email_verified_at' => now(),
            'user_type' => $admin ? 'admin' : 'user',
        ]);
    }

    /** Admin impersonating a user writes an audit log row */
    public function test_impersonation_logs_activity(): void
    {
        $admin = $this->makeUser(true);
        $target = $this->makeUser();

        $this->actingAs($admin)
            ->post(route('admin.users.login', $target), ['reason' => 'Support ticket 123'])
            ->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'admin.impersonate',
            'actor_user_id' => $admin->id,
        ]);
        $this->assertAuthenticatedAs($target);
    }

    /** Security (AUTH-8): no reason, no impersonation. */
    public function test_impersonation_needs_a_reason(): void
    {
        $admin = $this->makeUser(true);
        $target = $this->makeUser();

        $this->actingAs($admin)->post(route('admin.users.login', $target), ['reason' => 'x'])->assertRedirect();

        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'admin.impersonate']);
    }

    /** Security (AUTH-8): the session ends after an hour and sensitive pages are blocked. */
    public function test_impersonation_is_time_limited_and_fenced(): void
    {
        $admin = $this->makeUser(true);
        $target = $this->makeUser();
        $session = ['quick_admin_user_id' => $admin->id, 'quick_admin_impersonation' => ['started_at' => now()->timestamp, 'target_id' => $target->id]];

        $this->actingAs($target)->withSession($session)->get(route('2fa.show'))->assertForbidden();

        $session['quick_admin_impersonation']['started_at'] = now()->subMinutes(61)->timestamp;
        $this->actingAs($target)->withSession($session)->get(route('dashboard'))->assertRedirect(route('admin.users.index'));
        $this->assertAuthenticatedAs($admin);
    }

    /** Exiting impersonation writes an exit audit log row */
    public function test_impersonation_exit_logs_activity(): void
    {
        $admin = $this->makeUser(true);
        $target = $this->makeUser();

        // Simulate already impersonating: set session and auth as target
        $this->actingAs($target)
            ->withSession(['quick_admin_user_id' => $admin->id])
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'admin.impersonate_exit',
        ]);
    }

    /** Cannot impersonate yourself */
    public function test_cannot_impersonate_self(): void
    {
        $admin = $this->makeUser(true);

        $response = $this->actingAs($admin)
            ->post(route('admin.users.login', $admin));

        // Should redirect back (not throw; LoginController uses app_alert)
        $response->assertRedirect();
        $this->assertDatabaseMissing('activity_logs', ['action' => 'admin.impersonate']);
    }

    /** Port of upstream a5b53e0ad: an admin cannot take over another admin's session. */
    public function test_cannot_impersonate_another_admin(): void
    {
        $admin = $this->makeUser(true);
        $other = $this->makeUser(true);

        $this->actingAs($admin)->post(route('admin.users.login', $other))->assertRedirect();

        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'admin.impersonate']);
    }
}
