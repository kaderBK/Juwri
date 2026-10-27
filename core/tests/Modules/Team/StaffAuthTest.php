<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Services\TwoFactorService;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\TestPlans;
use Tests\TestCase;

class StaffAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111111', 'site_title' => 'juwri', 'meta_description' => 'Test description', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'facebook_login' => 0, 'google_login' => 0])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };

        $plan = TestPlans::create([
            'name' => 'Test Plan',
            'description' => 'Test plan',
            'monthly_price' => 0,
            'annual_price' => 0,
            'lifetime_price' => 0,
            'settings' => [
                'restaurant_limit' => 999,
            ],
            'status' => 1,
        ]);

        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
            'plan' => $plan,
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function createRestaurant(): array
    {
        $owner = User::factory()->create([
            'status' => 1,
            'email_verified_at' => now(),
        ]);

        $store = Store::create([
            'user_id' => $owner->id,
            'slug' => 'staff-auth-'.Str::lower(Str::random(5)),
            'color' => '#111111',
            'title' => 'Staff Test Restaurant',
            'sub_title' => 'Test restaurant',
            'description' => 'Test description',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 Test Street',
            'phone' => '1234567890',
        ]);

        StoreOption::updateStoreOption($store->id, 'store_template', 'aurora');

        return [$owner, $store];
    }

    public function test_staff_can_log_in_to_the_main_dashboard_with_role_limited_navigation(): void
    {
        [$owner, $store] = $this->createRestaurant();

        $staff = StaffMember::create([
            'owner_id' => $owner->id,
            'store_id' => $store->id,
            'name' => 'Kitchen Staff',
            'email' => 'kitchen@example.test',
            'password' => Hash::make('secret123'),
            'role' => 'kitchen',
            'active' => true,
        ]);

        $response = $this->post(route('staff.login.attempt'), [
            'email' => $staff->email,
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($staff, 'staff');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Kitchen Staff')
            ->assertSee('Staff Test Restaurant')
            ->assertSee('Kitchen Display')
            ->assertDontSee('Membership')
            ->assertDontSee('Add Restaurant');
    }

    /** Security (AUTH-6): web staff login enforces the staff member's TOTP like the API does. */
    public function test_enrolled_staff_need_their_two_factor_code_on_the_web_login(): void
    {
        [$owner, $store] = $this->createRestaurant();
        $secret = new TwoFactorService()->generateSecret();
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Guarded',
            'email' => 'guarded@example.test', 'password' => Hash::make('secret123'), 'role' => 'waiter', 'active' => true,
        ]);
        $staff->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $this->post(route('staff.login.attempt'), ['email' => $staff->email, 'password' => 'secret123'])
            ->assertSessionHasErrors('code');
        $this->assertGuest('staff');
        $this->assertGuest('web');
        $this->get(route('staff.login'))->assertOk()->assertSeeHtml('name="code"');

        $this->post(route('staff.login.attempt'), ['email' => $staff->email, 'password' => 'secret123', 'code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest('staff');

        $code = new Google2FA()->getCurrentOtp($secret);
        $this->post(route('staff.login.attempt'), ['email' => $staff->email, 'password' => 'secret123', 'code' => $code])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($staff, 'staff');
        $this->assertAuthenticatedAs($owner, 'web');
    }

    public function test_inactive_staff_cannot_log_in(): void
    {
        [$owner, $store] = $this->createRestaurant();

        StaffMember::create([
            'owner_id' => $owner->id,
            'store_id' => $store->id,
            'name' => 'Inactive Staff',
            'email' => 'inactive@example.test',
            'password' => Hash::make('secret123'),
            'role' => 'waiter',
            'active' => false,
        ]);

        $response = $this->from('/staff/login')->post(route('staff.login.attempt'), [
            'email' => 'inactive@example.test',
            'password' => 'secret123',
        ]);

        $response->assertRedirect('/staff/login');
        $this->assertGuest('staff');
    }

    public function test_staff_cannot_access_routes_outside_role_permissions(): void
    {
        [$owner, $store] = $this->createRestaurant();

        $staff = StaffMember::create([
            'owner_id' => $owner->id,
            'store_id' => $store->id,
            'name' => 'Waiter Staff',
            'email' => 'waiter@example.test',
            'password' => Hash::make('secret123'),
            'role' => 'waiter',
            'active' => true,
        ]);

        $this->post(route('staff.login.attempt'), [
            'email' => $staff->email,
            'password' => 'secret123',
        ])->assertRedirect(route('dashboard'));

        $this->get(route('analytics.index'))->assertForbidden();
        $this->get(route('stores.orders', $store->id))->assertOk();
    }
}
