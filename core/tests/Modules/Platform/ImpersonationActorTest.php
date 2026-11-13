<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\User;
use Tests\TestCase;

/** P6-229: writes made while "logged in as" name the real admin; leaving needs a still-active admin. */
class ImpersonationActorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function makeUser(bool $admin = false): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'user_type' => $admin ? 'admin' : 'user']);
    }

    public function test_a_write_during_impersonation_logs_both_ids(): void
    {
        $admin = $this->makeUser(true);
        $merchant = $this->makeUser();
        $this->actingAs($merchant);
        $this->app['request']->setLaravelSession($this->app['session.store']);
        session(['quick_admin_user_id' => $admin->id]);

        log_activity('menu.update', null, ['x' => 1]);

        $row = ActivityLog::where('action', 'menu.update')->firstOrFail();
        $this->assertSame($merchant->id, (int) $row->actor_user_id);
        $this->assertSame($admin->id, (int) $row->meta['impersonator_id']);
        $this->assertSame($merchant->id, (int) $row->meta['impersonated_user_id']);
        $this->assertSame(1, (int) $row->meta['x']);
    }

    public function test_a_normal_write_has_no_impersonator(): void
    {
        $merchant = $this->makeUser();
        $this->actingAs($merchant);

        log_activity('menu.update');

        $this->assertArrayNotHasKey('impersonator_id', (array) ActivityLog::where('action', 'menu.update')->firstOrFail()->meta);
    }

    public function test_a_demoted_admin_is_not_logged_back_in_on_exit(): void
    {
        $admin = $this->makeUser(true);
        $merchant = $this->makeUser();
        $admin->forceFill(['user_type' => 'user'])->save();

        $res = $this->actingAs($merchant)->withSession(['quick_admin_user_id' => $admin->id])->post(route('logout'));

        $this->assertNotSame(route('admin.users.index'), $res->headers->get('Location'));
        $this->assertGuest();
        $this->assertDatabaseMissing('activity_logs', ['action' => 'admin.impersonate_exit']);
    }

    public function test_a_disabled_admin_is_not_logged_back_in_on_exit(): void
    {
        $admin = $this->makeUser(true);
        $merchant = $this->makeUser();
        $admin->forceFill(['status' => 0])->save();

        $this->actingAs($merchant)->withSession(['quick_admin_user_id' => $admin->id])->post(route('logout'));

        $this->assertGuest();
    }

    public function test_an_active_admin_returns_to_the_admin_users_page(): void
    {
        $admin = $this->makeUser(true);
        $merchant = $this->makeUser();

        $this->actingAs($merchant)->withSession(['quick_admin_user_id' => $admin->id])->post(route('logout'))
            ->assertRedirect(route('admin.users.index'));
        $this->assertAuthenticatedAs($admin);
    }
}
