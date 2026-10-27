<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Auth;

use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * Behaviour contract for the auth-related route middleware (WI-07): the
 * admin gate, demo-mode write block, banned-account sign-out, registration
 * switch, last-active stamp, the locale resolver and the guest redirect.
 * Each is exercised on a throwaway route so the contract does not depend on
 * any real screen.
 */
class AuthMiddlewareContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);

        Route::middleware(['web', 'admin'])->get('/_contract/admin', fn () => 'admin-ok');
        Route::middleware(['web', 'demo'])->match(['get', 'post', 'put', 'delete'], '/_contract/demo', fn () => 'demo-ok');
        Route::middleware(['web', 'checkUserIsBanned'])->get('/_contract/banned', fn () => 'banned-ok');
        Route::middleware(['web', 'checkRegistrationDisabled'])->get('/_contract/signup', fn () => 'signup-ok');
        Route::middleware(['web', 'guest'])->get('/_contract/guest', fn () => 'guest-ok');
        Route::middleware(['web', 'app.locale'])->get('/_contract/locale', fn () => App::getLocale());
        Route::middleware(['web', 'app.locale'])->get('/fusion/_contract', fn () => App::getLocale());
    }

    private function user(array $attributes = []): User
    {
        // demo_mode() always exempts user #1 (the install owner); keep test
        // users off that id so the gate is really exercised.
        if (User::query()->doesntExist()) {
            User::factory()->create(['status' => 1]);
        }

        return User::factory()->create($attributes + ['status' => 1]);
    }

    // ------------------------------------------------------------ admin gate

    public function test_admin_gate_sends_guests_to_login(): void
    {
        $this->get('/_contract/admin')->assertRedirect(route('login'));
    }

    public function test_admin_gate_sends_non_admins_to_login(): void
    {
        $this->actingAs($this->user(['user_type' => 'user']))
            ->get('/_contract/admin')->assertRedirect(route('login'));
    }

    public function test_admin_gate_lets_admins_through(): void
    {
        $this->actingAs($this->user(['user_type' => 'admin']))
            ->get('/_contract/admin')->assertOk()->assertSee('admin-ok');
    }

    public function test_admin_gate_can_require_two_factor_enrolment(): void
    {
        config(['auth.force_2fa_admin' => true]);

        $this->actingAs($this->user(['user_type' => 'admin']))
            ->get('/_contract/admin')
            ->assertRedirect(route('2fa.show'))
            ->assertSessionHas('warning');
    }

    // ------------------------------------------------------------- demo mode

    public function test_demo_mode_blocks_writes_but_not_reads(): void
    {
        config(['app.demo_mode' => true]);
        $this->actingAs($this->user());

        $this->get('/_contract/demo')->assertOk()->assertSee('demo-ok');

        foreach (['post', 'put', 'delete'] as $method) {
            $this->from('/somewhere')->{$method}('/_contract/demo')
                ->assertRedirect('/somewhere')
                ->assertSessionHas('app_alert_type', 'error');
        }
    }

    public function test_demo_mode_answers_json_writes_with_a_failure_payload(): void
    {
        config(['app.demo_mode' => true]);
        $this->actingAs($this->user());

        $this->postJson('/_contract/demo')
            ->assertOk()
            ->assertJson(['success' => false])
            ->assertJsonStructure(['success', 'message']);
    }

    public function test_demo_mode_off_lets_writes_through(): void
    {
        config(['app.demo_mode' => false]);

        $this->post('/_contract/demo')->assertOk()->assertSee('demo-ok');
    }

    // ---------------------------------------------------------- banned users

    public function test_banned_user_is_signed_out(): void
    {
        $this->actingAs($this->user(['status' => 0]))
            ->get('/_contract/banned')
            ->assertRedirect(route('login'))
            ->assertSessionHas('app_alert_type', 'error');

        $this->assertGuest('web');
    }

    public function test_active_user_and_guest_pass_the_ban_check(): void
    {
        $this->get('/_contract/banned')->assertOk();
        $this->actingAs($this->user())->get('/_contract/banned')->assertOk();
    }

    // -------------------------------------------------- registration switch

    public function test_registration_switch(): void
    {
        config(['settings.enable_user_registration' => 1]);
        $this->get('/_contract/signup')->assertOk();

        config(['settings.enable_user_registration' => 0]);
        $this->get('/_contract/signup')
            ->assertRedirect(route('login'))
            ->assertSessionHas('app_alert_type', 'error');
    }

    // ------------------------------------------------------------ last active

    public function test_web_requests_stamp_last_active(): void
    {
        Carbon::setTestNow('2026-09-27 10:00:00');
        $user = $this->user(['lastactive' => null]);

        $this->actingAs($user)->get('/_contract/banned')->assertOk();

        $this->assertSame('2026-09-27 10:00:00', $user->refresh()->lastactive?->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    // ------------------------------------------------------------ guest only

    public function test_guest_middleware_redirects_signed_in_users_to_the_dashboard(): void
    {
        $this->get('/_contract/guest')->assertOk();

        $response = $this->actingAs($this->user())->get('/_contract/guest');
        $response->assertRedirect();
        $this->assertStringEndsWith('/dashboard', $response->headers->get('Location'));
    }

    // ---------------------------------------------------------------- locale

    public function test_session_locale_wins(): void
    {
        $this->withSession(['locale' => 'fr'])->get('/_contract/locale')->assertSee('fr');
    }

    public function test_signed_in_users_fall_back_to_their_preferred_locale(): void
    {
        $this->actingAs($this->user(['preferred_locale' => 'de']))
            ->get('/_contract/locale')->assertSee('de');
    }

    public function test_storefront_keeps_its_own_language_memory(): void
    {
        $this->withSession(['locale' => 'fr', 'mk_locale' => 'es'])
            ->get('/fusion/_contract')->assertSee('es');

        // The preferred locale never applies on the storefront surfaces.
        $this->actingAs($this->user(['preferred_locale' => 'de']))
            ->withSession([])
            ->get('/fusion/_contract')->assertDontSee('de');
    }
}
