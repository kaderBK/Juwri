<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Auth;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Modules\Integrations\Listeners\RevokeTokensOnCredentialChange;
use Juwri\Modules\Integrations\Mail\ApiTokensReviewMail;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Team\Models\StaffToken;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-75: a password reset, a settings password change and a 2FA disable revoke
 * the user's owner-app (kind=app) tokens; integration (kind=api) tokens are kept
 * and flagged for review (notice + email). Staff-app tokens go on an owner reset
 * of the staff password or 2FA.
 */
class CredentialChangeTokenRevocationTest extends TestCase
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

    /** @return array{0: string, 1: ApiToken} */
    private function appToken(User $u, string $device = 'device-1'): array
    {
        $raw = Str::random(64);
        $token = ApiToken::create([
            'user_id' => $u->id, 'name' => 'Mobile app', 'token' => hash('sha256', $raw),
            'abilities' => ['read'], 'kind' => 'app', 'device_id' => $device, 'platform' => 'ios',
            'expires_at' => now()->addDays(180),
        ]);

        return [$raw, $token];
    }

    private function integrationToken(User $u, string $name = 'zapier'): ApiToken
    {
        return ApiToken::create(['user_id' => $u->id, 'name' => $name, 'token' => hash('sha256', Str::random(40)), 'abilities' => ['read']]);
    }

    /** @return array<string, string> */
    private function bearer(string $raw): array
    {
        return ['Authorization' => 'Bearer '.$raw];
    }

    private function resetPassword(User $user): void
    {
        $this->post(route('password.update'), [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
            'password' => 'brand-new-pass-2',
            'password_confirmation' => 'brand-new-pass-2',
        ])->assertRedirect();
    }

    public function test_password_reset_revokes_app_tokens_and_the_old_token_gets_401(): void
    {
        $user = $this->owner();
        [$raw] = $this->appToken($user);
        $this->getJson('/api/v1/app/me', $this->bearer($raw))->assertOk();

        $other = $this->owner();
        [$otherRaw] = $this->appToken($other);

        $this->resetPassword($user);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/app/me', $this->bearer($raw))->assertUnauthorized();
        $this->assertSame(0, ApiToken::where('user_id', $user->id)->where('kind', 'app')->count());
        // Another user's device token is untouched.
        $this->getJson('/api/v1/app/me', $this->bearer($otherRaw))->assertOk();
    }

    public function test_integration_tokens_are_kept_flagged_and_emailed(): void
    {
        $user = $this->owner();
        $this->appToken($user);
        $integration = $this->integrationToken($user, 'Zapier sync');

        $this->resetPassword($user);

        $this->assertNotNull($integration->fresh());
        $this->assertNotEmpty(UserOption::getUserOption($user->id, RevokeTokensOnCredentialChange::REVIEW_OPTION));
        Mail::assertSent(ApiTokensReviewMail::class, fn (ApiTokensReviewMail $m) => $m->hasTo($user->email) && $m->tokenNames === ['Zapier sync']);

        $this->actingAs($user->fresh())->get(route('api.index'))->assertOk()->assertSee('Zapier sync')
            ->assertSee(route('api.token.review.revoke'), false);

        $this->actingAs($user->fresh())->post(route('api.token.review.revoke'))->assertRedirect();
        $this->assertNull($integration->fresh());
        $this->assertEmpty(UserOption::getUserOption($user->id, RevokeTokensOnCredentialChange::REVIEW_OPTION));
    }

    public function test_no_integration_tokens_means_no_notice_or_mail(): void
    {
        $user = $this->owner();
        $this->appToken($user);

        $this->resetPassword($user);

        Mail::assertNotSent(ApiTokensReviewMail::class);
        $this->assertEmpty(UserOption::getUserOption($user->id, RevokeTokensOnCredentialChange::REVIEW_OPTION));
    }

    public function test_review_notice_can_be_dismissed_keeping_tokens(): void
    {
        $user = $this->owner();
        $integration = $this->integrationToken($user);
        UserOption::updateUserOption($user->id, RevokeTokensOnCredentialChange::REVIEW_OPTION, now()->toIso8601String());

        $this->actingAs($user)->post(route('api.token.review.dismiss'))->assertRedirect();

        $this->assertNotNull($integration->fresh());
        $this->assertEmpty(UserOption::getUserOption($user->id, RevokeTokensOnCredentialChange::REVIEW_OPTION));
    }

    public function test_settings_password_change_revokes_all_app_tokens_by_default(): void
    {
        $user = $this->owner();
        [, $current] = $this->appToken($user, 'device-1');
        [, $second] = $this->appToken($user, 'device-2');

        $this->actingAs($user)->withSession(['owner_app_token_id' => $current->id])
            ->post(route('settings.editProfile'), [
                'username' => $user->username, 'email' => $user->email, 'current_password' => self::PASSWORD,
                'new_password' => 'another-pass-3', 'new_password_confirmation' => 'another-pass-3',
            ])->assertRedirect();

        $this->assertNull($current->fresh());
        $this->assertNull($second->fresh());
    }

    public function test_keep_this_device_keeps_only_the_current_app_token(): void
    {
        $user = $this->owner();
        [, $current] = $this->appToken($user, 'device-1');
        [, $second] = $this->appToken($user, 'device-2');

        $this->actingAs($user)->withSession(['owner_app_token_id' => $current->id])
            ->post(route('settings.editProfile'), [
                'username' => $user->username, 'email' => $user->email, 'current_password' => self::PASSWORD,
                'new_password' => 'another-pass-3', 'new_password_confirmation' => 'another-pass-3',
                'keep_this_device' => '1',
            ])->assertRedirect();

        $this->assertNotNull($current->fresh());
        $this->assertNull($second->fresh());
    }

    public function test_profile_edit_without_password_change_keeps_app_tokens(): void
    {
        $user = $this->owner();
        [, $token] = $this->appToken($user);

        $this->actingAs($user)->post(route('settings.editProfile'), [
            'username' => $user->username, 'email' => $user->email,
        ])->assertRedirect();

        $this->assertNotNull($token->fresh());
    }

    public function test_two_factor_disable_revokes_app_tokens(): void
    {
        $user = $this->owner();
        $user->forceFill(['two_factor_secret' => 'ABCDEFGHIJKLMNOP', 'two_factor_confirmed_at' => now()])->save();
        [, $token] = $this->appToken($user);
        $other = $this->owner();
        [, $otherToken] = $this->appToken($other);

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('2fa.disable'))->assertRedirect();

        $this->assertNull($token->fresh());
        $this->assertNotNull($otherToken->fresh());
    }

    public function test_handoff_records_the_device_token_in_the_session(): void
    {
        $user = $this->owner();
        [$raw, $token] = $this->appToken($user);

        $url = (string) $this->postJson('/api/v1/app/session', [], $this->bearer($raw))->assertOk()->json('url');
        $this->get($url)->assertRedirect()->assertSessionHas('owner_app_token_id', $token->id);
    }

    public function test_owner_staff_password_and_two_factor_reset_revoke_staff_tokens(): void
    {
        $owner = $this->owner();
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Diner', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Staff', 'role' => 'manager',
            'email' => 'staff'.Str::random(5).'@ex.com', 'password' => Hash::make('secret'), 'active' => 1,
        ]);
        $otherStaff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Other', 'role' => 'cashier',
            'email' => 'other'.Str::random(5).'@ex.com', 'password' => Hash::make('secret'), 'active' => 1,
        ]);

        StaffToken::issue($staff, 'tablet');
        StaffToken::issue($otherStaff, 'tablet');
        $this->actingAs($owner)->post(route('staff.reset2fa', $staff->id))->assertRedirect();
        $this->assertSame(0, StaffToken::where('staff_id', $staff->id)->count());
        $this->assertSame(1, StaffToken::where('staff_id', $otherStaff->id)->count());

        StaffToken::issue($staff, 'tablet');
        $this->actingAs($owner)->put(route('staff.update', $staff->id), [
            'name' => 'Staff', 'role' => 'manager', 'password' => 'new-staff-pass',
        ])->assertRedirect();
        $this->assertSame(0, StaffToken::where('staff_id', $staff->id)->count());
        $this->assertSame(1, StaffToken::where('staff_id', $otherStaff->id)->count());
    }
}
