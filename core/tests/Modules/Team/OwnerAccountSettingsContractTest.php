<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Country;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Tests\TestCase;

/**
 * WI-09d characterisation of User\SettingsController account screen: the page
 * data, profile edits (avatar, locale, success flash) and billing details.
 * Email/password re-authentication is covered by ProfileEmailPasswordReauthTest
 * and UserProfileTest.
 */
class OwnerAccountSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    private function owner(): User
    {
        return User::factory()->create([
            'status' => 1, 'email_verified_at' => now(), 'username' => 'own'.Str::lower(Str::random(5)),
            'email' => Str::random(6).'@example.com', 'password' => Hash::make('oldpassword'), 'image' => 'default_user.png',
        ]);
    }

    public function test_account_page_passes_the_user_and_their_options(): void
    {
        $user = $this->owner();
        UserOption::updateUserOption($user->id, 'billing_name', 'Acme');

        $this->actingAs($user)->get(route('settings'))->assertOk()
            ->assertViewIs('owner.settings.account')
            ->assertViewHas('user', fn ($u) => $u->id === $user->id)
            ->assertViewHas('user_options', fn ($o) => $o->billing_name === 'Acme');
    }

    public function test_profile_saves_username_locale_and_flashes_success(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->from(route('settings'))->post(route('settings.editProfile'), [
            'username' => 'renamed1', 'email' => $user->email, 'preferred_locale' => 'en',
        ])->assertRedirect(route('settings'));

        $fresh = $user->fresh();
        $this->assertSame('renamed1', $fresh->username);
        $this->assertSame('en', $fresh->preferred_locale);
        $this->assertSame('default_user.png', $fresh->image);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertSame(___('User details updated successfully'), session('app_alert_message'));

        $this->actingAs($fresh)->post(route('settings.editProfile'), ['username' => 'renamed1', 'email' => $user->email, 'preferred_locale' => ''])->assertRedirect();
        $this->assertNull($fresh->fresh()->preferred_locale);

        $this->actingAs($fresh)->post(route('settings.editProfile'), ['username' => 'renamed1', 'email' => $user->email, 'preferred_locale' => 'xx'])
            ->assertSessionHasErrors('preferred_locale');
    }

    public function test_profile_avatar_upload_replaces_the_image(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->post(route('settings.editProfile'), [
            'username' => $user->username, 'email' => $user->email,
            'avatar' => UploadedFile::fake()->image('me.png', 300, 300),
        ])->assertRedirect();

        $image = $user->fresh()->image;
        $this->assertNotSame('default_user.png', $image);
        $this->assertNotEmpty($image);

        $this->actingAs($user->fresh())->post(route('settings.editProfile'), [
            'username' => $user->username, 'email' => $user->email,
            'avatar' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('avatar');
    }

    public function test_billing_details_are_saved_as_options_and_set_the_country(): void
    {
        $user = $this->owner();
        Country::query()->firstOrCreate(['code' => 'TR'], ['name' => 'Turkey']);
        $billing = [
            'billing_details_type' => 'business', 'billing_name' => 'Acme Ltd', 'billing_address' => '1 Main St',
            'billing_city' => 'Istanbul', 'billing_state' => 'IST', 'billing_zipcode' => '34000',
            'billing_country' => 'TR', 'billing_tax_id' => 'TR123',
        ];

        $this->actingAs($user)->from(route('settings'))->post(route('settings.editBilling'), $billing + ['submit' => 'Save'])
            ->assertRedirect(route('settings'));

        foreach ($billing as $key => $value) {
            $this->assertSame($value, UserOption::getUserOption($user->id, $key), $key);
        }
        $this->assertFalse(UserOption::getUserOption($user->id, 'submit'));
        $this->assertSame(['TR', 'Turkey'], [$user->fresh()->country_code, $user->fresh()->country]);
        $this->assertSame(___('Updated Successfully'), session('app_alert_message'));
    }

    public function test_billing_validation_uses_the_billing_error_bag(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->post(route('settings.editBilling'), ['billing_country' => 'ZZ'])
            ->assertSessionHasErrorsIn('billing', ['billing_details_type', 'billing_name', 'billing_address', 'billing_city', 'billing_state', 'billing_zipcode', 'billing_country']);
    }
}
