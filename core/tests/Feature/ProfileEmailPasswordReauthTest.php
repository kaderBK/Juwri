<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\AbuseHoldWall;
use Juwri\Core\Http\Middleware\AddDashboardCspHeaders;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use Juwri\Core\Http\Middleware\IsDemoMode;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Changing the owner account's email or password is an ATO-grade action, so editProfile
 * must re-authenticate with the current password (the same bar the app sets for minting
 * an API token / disabling 2FA). Cosmetic edits stay frictionless.
 */
class ProfileEmailPasswordReauthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Peripheral dashboard-group middleware (store sync, abuse/suspension walls, CSP,
        // demo) would redirect a bare test owner before editProfile runs. The route fence
        // itself is not under test here — only the controller's re-auth logic is.
        $this->withoutMiddleware([
            Installed::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            AbuseHoldWall::class,
            AddDashboardCspHeaders::class,
            EnsureStaffDashboardPermission::class,
            IsDemoMode::class,
        ]);
    }

    private function owner(string $password = 'secret123'): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'x',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(5)),
            'email' => 'owner_'.Str::lower(Str::random(5)).'@old.test',
            'password' => Hash::make($password),
            'image' => 'default_user.png',
        ]);
    }

    public function test_email_change_without_current_password_is_rejected(): void
    {
        $owner = $this->owner();
        $original = $owner->email;

        $res = $this->actingAs($owner)->from('/settings')->post(route('settings.editProfile'), [
            'username' => $owner->username,
            'email' => 'attacker_'.Str::random(4).'@evil.test',
        ]);

        $res->assertSessionHasErrors('current_password');
        $this->assertSame($original, $owner->fresh()->email, 'email must not change without re-auth');
    }

    public function test_email_change_with_wrong_current_password_is_rejected(): void
    {
        $owner = $this->owner();
        $original = $owner->email;

        $res = $this->actingAs($owner)->from('/settings')->post(route('settings.editProfile'), [
            'username' => $owner->username,
            'email' => 'attacker_'.Str::random(4).'@evil.test',
            'current_password' => 'not-the-password',
        ]);

        $res->assertSessionHasErrors('current_password');
        $this->assertSame($original, $owner->fresh()->email);
    }

    public function test_email_change_with_correct_current_password_succeeds(): void
    {
        Notification::fake();
        Mail::fake(); // swallow the old-address change notice + verification mail

        $owner = $this->owner();
        $newEmail = 'new_'.Str::lower(Str::random(5)).'@fresh.test';

        $res = $this->actingAs($owner)->from('/settings')->post(route('settings.editProfile'), [
            'username' => $owner->username,
            'email' => $newEmail,
            'current_password' => 'secret123',
        ]);

        $res->assertSessionHasNoErrors();
        $fresh = $owner->fresh();
        $this->assertSame($newEmail, $fresh->email);
        $this->assertNull($fresh->email_verified_at, 'email change must drop verification');
    }

    public function test_password_change_without_current_password_is_rejected(): void
    {
        $owner = $this->owner();

        $res = $this->actingAs($owner)->from('/settings')->post(route('settings.editProfile'), [
            'username' => $owner->username,
            'email' => $owner->email, // unchanged
            'new_password' => 'brandnew123',
            'new_password_confirmation' => 'brandnew123',
        ]);

        $res->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('secret123', $owner->fresh()->password), 'password must not change without re-auth');
    }

    public function test_cosmetic_update_needs_no_current_password(): void
    {
        $owner = $this->owner();
        $newName = 'owner'.Str::lower(Str::random(5));

        $res = $this->actingAs($owner)->from('/settings')->post(route('settings.editProfile'), [
            'username' => $newName,
            'email' => $owner->email, // unchanged
        ]);

        $res->assertSessionHasNoErrors();
        $this->assertSame($newName, $owner->fresh()->username);
    }
}
