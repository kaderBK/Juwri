<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Owner profile update — username, email, password change.
 * Route: POST settings/edit-profile → SettingsController@editProfile
 */
class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T',
            'social_share_image' => 'default.png', 'userlangsel' => 0,
            'include_language_code' => 0, 'site_favicon' => 'favicon.png']) {
            public function __construct(private array $data) {}
            public function __get(string $key): mixed { return $this->data[$key] ?? null; }
            public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function __isset(string $key): bool { return array_key_exists($key, $this->data); }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
            \App\Http\Middleware\EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeUser(?string $username = null, ?string $email = null): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5], 'status' => 1]);
        return User::factory()->create([
            'group_id'          => (string) $plan->id,
            'status'            => 1,
            'email_verified_at' => now(),
            'username'          => $username ?? 'user'.Str::random(6),
            'email'             => $email ?? Str::random(6).'@example.com',
            'password'          => Hash::make('oldpassword'),
            'image'             => 'default_user.png',
        ]);
    }

    /** Owner can update username */
    public function test_owner_can_update_username(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post(route('settings.editProfile'), [
                'username' => 'newname12',
                'email'    => $user->email,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'username' => 'newname12']);
    }

    /** Owner can update email — triggers email_verified_at → null */
    public function test_email_change_clears_verification(): void
    {
        Mail::fake(); // prevents verification email from rendering empty view
        $user = $this->makeUser();
        $newEmail = 'changed'.strtolower(Str::random(4)).'@example.com'; // emails are stored lowercase (P6-40)

        $this->actingAs($user)
            ->post(route('settings.editProfile'), [
                'username'         => $user->username,
                'email'            => $newEmail,
                'current_password' => 'oldpassword', // re-auth required to change email
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $newEmail]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email_verified_at' => null]);
    }

    /** Same email does NOT clear verification */
    public function test_same_email_keeps_verification(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post(route('settings.editProfile'), [
                'username' => $user->username,
                'email'    => $user->email,
            ])
            ->assertRedirect();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->email_verified_at);
    }

    /** Password change hashes and stores new password */
    public function test_owner_can_change_password(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post(route('settings.editProfile'), [
                'username'                  => $user->username,
                'email'                     => $user->email,
                'new_password'              => 'newsecret99',
                'new_password_confirmation' => 'newsecret99',
                'current_password'          => 'oldpassword', // re-auth required to change password
            ])
            ->assertRedirect();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('newsecret99', $fresh->password));
    }

    /** Validation: duplicate username rejected */
    public function test_duplicate_username_rejected(): void
    {
        $existing = $this->makeUser('takenname');
        $user     = $this->makeUser();

        $response = $this->actingAs($user)
            ->post(route('settings.editProfile'), [
                'username' => 'takenname',
                'email'    => $user->email,
            ]);

        // Validation fails → back with errors (422 or redirect with errors)
        $response->assertStatus(302);
        // Username NOT changed
        $this->assertDatabaseHas('users', ['id' => $user->id, 'username' => $user->username]);
    }

    /** Validation: short password rejected */
    public function test_short_password_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post(route('settings.editProfile'), [
                'username'                  => $user->username,
                'email'                     => $user->email,
                'new_password'              => 'abc',
                'new_password_confirmation' => 'abc',
            ])
            ->assertStatus(302);

        // Password unchanged
        $this->assertTrue(Hash::check('oldpassword', $user->fresh()->password));
    }
}
