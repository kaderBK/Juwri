<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Auth;

use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * S8 sub-2 — password.confirm re-auth on sensitive actions. There was no
 * `password.confirm` route before; these prove the screen exists and that the
 * middleware gates API-token creation, 2FA-disable and reseller impersonation
 * until the password is re-confirmed.
 */
class RequirePasswordConfirmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');

        // The installer gate redirects everything to /install in the test env
        // (APP_INSTALLED unset); drop it so the auth routes are reachable, and
        // share the view vars the auth layout composes (APP_INSTALLED=false
        // otherwise kills the $settings composer — see memory).
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png']) {
            public function __construct(private array $data) {}
            public function __get(string $key): mixed { return $this->data[$key] ?? null; }
            public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function __isset(string $key): bool { return array_key_exists($key, $this->data); }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $user = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1,
            'email_verified_at' => now(), 'password' => bcrypt('secret-password'),
        ]);
        $title = 'R '.Str::random(6);
        Store::create([
            'user_id' => $user->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);

        return $user;
    }

    public function test_confirm_screen_renders_for_an_authenticated_user(): void
    {
        $this->actingAs($this->owner())->get(route('password.confirm'))->assertOk();
    }

    public function test_2fa_disable_requires_a_recent_password_confirmation(): void
    {
        $user = $this->owner();

        // No confirmation → bounced to the confirm screen.
        $this->actingAs($user)
            ->post(route('2fa.disable'))
            ->assertRedirect(route('password.confirm'));

        // With a recent confirmation in session → the middleware lets it through
        // (lands back on the previous page, not the confirm screen).
        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('2fa.show'))
            ->post(route('2fa.disable'))
            ->assertRedirect(route('2fa.show'));
    }

    public function test_confirm_store_stamps_the_session_on_correct_password(): void
    {
        $this->actingAs($this->owner())
            ->post(route('password.confirm.store'), ['password' => 'secret-password'])
            ->assertRedirect()
            ->assertSessionHas('auth.password_confirmed_at');
    }

    public function test_confirm_store_rejects_a_wrong_password(): void
    {
        $this->actingAs($this->owner())
            ->post(route('password.confirm.store'), ['password' => 'not-the-password'])
            ->assertSessionMissing('auth.password_confirmed_at');
    }

    public function test_api_token_create_is_gated_by_password_confirm(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureStaffDashboardPermission::class,
            \Juwri\Core\Http\Middleware\SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            \App\Http\Middleware\AbuseHoldWall::class,
        ]);

        $this->actingAs($this->owner())
            ->post(route('api.token.create'), ['name' => 'My token'])
            ->assertRedirect(route('password.confirm'));
    }
}
