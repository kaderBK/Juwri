<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Auth;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Events\CredentialsChanged;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-267: a reset link already sitting in a compromised mailbox must not outlive
 * the step the victim takes to lock an attacker out. Every outstanding reset
 * token is deleted on a password change or reset, an email change by any path and
 * a 2FA disable; a cosmetic profile edit leaves it in place.
 */
class ResetTokensPurgedOnCredentialChangeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-pass-1';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
        Mail::fake();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'own'.Str::lower(Str::random(6)),
            'email' => Str::lower(Str::random(8)).'@owner.test',
            'password' => Hash::make(self::PASSWORD), 'image' => 'default_user.png',
        ]);
    }

    /** A raw reset token, exactly as the forgot-password mail would carry it. */
    private function resetToken(User $user): string
    {
        return Password::broker()->createToken($user);
    }

    private function resetTokenCount(string $email): int
    {
        return DB::table('password_reset_tokens')->where('email', $email)->count();
    }

    /** @param array<string, mixed> $extra */
    private function changePasswordViaSettings(User $user, string $new = 'another-pass-3', array $extra = []): void
    {
        $this->actingAs($user)->post(route('settings.editProfile'), array_merge([
            'username' => $user->username,
            'email' => $user->email,
            'current_password' => self::PASSWORD,
            'new_password' => $new,
            'new_password_confirmation' => $new,
        ], $extra))->assertRedirect();
    }

    public function test_settings_password_change_purges_the_outstanding_reset_token(): void
    {
        $user = $this->owner();
        $raw = $this->resetToken($user);
        $this->assertTrue(Password::broker()->tokenExists($user->fresh(), $raw));

        $this->changePasswordViaSettings($user, 'settings-pass-3');

        $this->assertFalse(Password::broker()->tokenExists($user->fresh(), $raw));
        $this->assertSame(0, $this->resetTokenCount($user->email));

        // The purged link can no longer overwrite the password the victim just set.
        $this->post(route('password.update'), [
            'token' => $raw, 'email' => $user->email,
            'password' => 'reset-attempt-9', 'password_confirmation' => 'reset-attempt-9',
        ])->assertSessionHasErrors();
        $this->assertTrue(Hash::check('settings-pass-3', $user->fresh()->password));
    }

    public function test_settings_email_change_purges_the_token_for_the_old_address(): void
    {
        $user = $this->owner();
        $oldEmail = $user->email;
        $raw = $this->resetToken($user);
        $this->assertSame(1, $this->resetTokenCount($oldEmail));

        $this->actingAs($user)->post(route('settings.editProfile'), [
            'username' => $user->username,
            'email' => 'moved-'.Str::lower(Str::random(6)).'@owner.test',
            'current_password' => self::PASSWORD,
        ])->assertRedirect();

        $this->assertSame(0, $this->resetTokenCount($oldEmail));
        $this->assertFalse(Password::broker()->tokenExists($user->fresh(), $raw));
    }

    public function test_two_factor_disable_purges_the_outstanding_reset_token(): void
    {
        $user = $this->owner();
        $raw = $this->resetToken($user);

        event(new CredentialsChanged($user, CredentialsChanged::TWO_FACTOR_DISABLED));

        $this->assertSame(0, $this->resetTokenCount($user->email));
        $this->assertFalse(Password::broker()->tokenExists($user->fresh(), $raw));
    }

    public function test_any_password_write_purges_the_outstanding_reset_token(): void
    {
        $user = $this->owner();
        $raw = $this->resetToken($user);

        // An admin/import path that never dispatches CredentialsChanged: the
        // User saved hook is the backstop.
        $user->update(['password' => Hash::make('admin-set-pass-7')]);

        $this->assertSame(0, $this->resetTokenCount($user->email));
        $this->assertFalse(Password::broker()->tokenExists($user->fresh(), $raw));
    }

    public function test_a_cosmetic_profile_edit_keeps_the_reset_token(): void
    {
        $user = $this->owner();
        $raw = $this->resetToken($user);

        $this->actingAs($user)->post(route('settings.editProfile'), [
            'username' => 'renamed'.Str::lower(Str::random(4)),
            'email' => $user->email,
        ])->assertRedirect();

        $this->assertSame(1, $this->resetTokenCount($user->email));
        $this->assertTrue(Password::broker()->tokenExists($user->fresh(), $raw));
    }
}
