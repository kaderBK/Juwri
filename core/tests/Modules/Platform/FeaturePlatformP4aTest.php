<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Models\App;
use App\Models\AppInstallation;
use Juwri\Core\Models\User;
use Juwri\Modules\AppStore\Services\AppBillingService;
use App\Services\AppInstallService;
use App\Services\Entitlements;
use Juwri\Modules\Payments\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P4a (feature-platform): apps as entitlement sources — grants_features
 * (admin-only, D9), trials (trial end = first due date), one_time billing,
 * grace before suspend (Terms 6.4: 14 days overdue + 7 days' notice), suspend/uninstall revoke grants.
 */
class FeaturePlatformP4aTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        \App\Services\FeatureRegistry::clearCache();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
        $this->admin = User::factory()->create(['group_id' => '1', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
    }

    private function user(float $wallet = 0): User
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'group_id' => 'free', 'user_type' => 'user']);
        if ($wallet > 0) {
            WalletService::credit($user, $wallet, 'seed');
        }

        return $user;
    }

    private function app(array $overrides = []): App
    {
        $developer = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return App::create(array_merge([
            'name' => 'A'.Str::random(4), 'slug' => 'a-'.uniqid(),
            'developer_user_id' => $developer->id,
            'scopes' => ['menu:read'], 'status' => 'approved',
            'billing_type' => 'free', 'price' => 0,
        ], $overrides));
    }

    // ------------------------------------------------------------ P4-1/P4-2 grants

    public function test_install_grants_features_and_uninstall_revokes(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $app = $this->app(['grants_features' => ['hotel', 'kiosk']]);
        $user = $this->user();

        $installation = app(AppInstallService::class)->install($app, $user);
        $this->assertTrue(Entitlements::allows($user->fresh(), 'hotel'), 'install grants');
        $this->assertTrue(Entitlements::allows($user->fresh(), 'kiosk'));
        $this->assertDatabaseHas('entitlement_overrides', ['user_id' => $user->id, 'feature_key' => 'hotel', 'source_type' => 'app_installation', 'source_id' => $installation->id]);

        app(AppInstallService::class)->uninstall($installation->fresh());
        $this->assertFalse(Entitlements::allows($user->fresh(), 'hotel'), 'uninstall revokes');
        $this->assertDatabaseHas('entitlement_audit_logs', ['user_id' => $user->id, 'feature_key' => 'hotel', 'action' => 'revoke', 'source_type' => 'app_installation']);
    }

    public function test_admin_grants_surface_guards_platform_only(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $app = $this->app(['status' => 'approved']);

        $this->actingAs($this->admin)->post(route('admin.app-reviews.grants', $app->id), [
            'grants_features' => 'hotel,kiosk',
        ])->assertRedirect();
        $this->assertSame(['hotel', 'kiosk'], $app->fresh()->grants_features);

        \Juwri\Core\Models\Feature::where('key', 'hotel')->update(['platform_only' => true]);
        $this->actingAs($this->admin)->post(route('admin.app-reviews.grants', $app->id), [
            'grants_features' => 'hotel',
        ])->assertStatus(422);
    }

    public function test_partner_submission_cannot_set_grants(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $developer = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($developer)->post(route('partner.apps.store'), [
            'name' => 'Sneaky', 'category' => array_values(config('marketplace.app_categories'))[0] ?? 'operations',
            'billing_type' => 'free', 'grants_features' => ['hotel'],
        ]);

        $app = App::where('name', 'Sneaky')->first();
        if ($app) {
            $this->assertNull($app->grants_features, 'partner input can never set grants_features');
        } else {
            $this->assertTrue(true); // validation rejected the submission entirely — equally safe
        }
    }

    // ------------------------------------------------------------ P4-4 trial

    public function test_trial_install_skips_charge_and_converts_at_expiry(): void
    {
        $app = $this->app(['billing_type' => 'recurring', 'price' => 20, 'billing_period_days' => 30, 'trial_days' => 7, 'grants_features' => ['hotel']]);
        $user = $this->user(100);

        $installation = app(AppInstallService::class)->install($app, $user);
        $installation->refresh();

        $this->assertSame(100.0, WalletService::balanceFor($user->fresh()), 'trial charges nothing');
        $this->assertNotNull($installation->trial_ends_at);
        $this->assertTrue(Entitlements::allows($user->fresh(), 'hotel'), 'trial grants features');

        // Trial expires → renewDue converts (charges first period).
        $installation->update(['trial_ends_at' => now()->subMinute(), 'current_period_ends_at' => now()->subMinute()]);
        $out = app(AppBillingService::class)->renewDue();

        $this->assertSame(1, $out['renewed']);
        $this->assertSame(80.0, WalletService::balanceFor($user->fresh()), 'first period charged at conversion');
        $this->assertNull($installation->fresh()->trial_ends_at, 'converted');
    }

    public function test_reinstall_never_gets_second_trial(): void
    {
        $app = $this->app(['billing_type' => 'recurring', 'price' => 20, 'billing_period_days' => 30, 'trial_days' => 7]);
        $user = $this->user(100);
        $svc = app(AppInstallService::class);

        $installation = $svc->install($app, $user);
        $svc->uninstall($installation->fresh());

        $svc->install($app, $user); // reinstall
        $this->assertSame(80.0, WalletService::balanceFor($user->fresh()), 'reinstall charges immediately, no second trial');
    }

    // ------------------------------------------------------------ P4-5 one_time

    public function test_one_time_charges_once_and_never_renews(): void
    {
        $app = $this->app(['billing_type' => 'one_time', 'price' => 15]);
        $user = $this->user(50);
        $svc = app(AppInstallService::class);

        $installation = $svc->install($app, $user);
        $this->assertSame(35.0, WalletService::balanceFor($user->fresh()));
        $this->assertNull($installation->fresh()->current_period_ends_at, 'no renewal period');

        // Reinstall must not re-charge (idempotent per installation).
        $svc->uninstall($installation->fresh());
        $svc->install($app, $user);
        $this->assertSame(35.0, WalletService::balanceFor($user->fresh()), 'reinstall free — already paid');
    }

    // ------------------------------------------------------------ P4-4 grace

    public function test_failed_renewal_gets_grace_then_suspends_and_revokes(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $app = $this->app(['billing_type' => 'recurring', 'price' => 20, 'billing_period_days' => 30, 'grants_features' => ['hotel']]);
        $user = $this->user(20); // covers install only
        $svc = app(AppInstallService::class);

        $installation = $svc->install($app, $user)->fresh();
        $this->assertTrue(Entitlements::allows($user->fresh(), 'hotel'));

        // Due, wallet empty → past_due with grace, access retained.
        $installation->update(['current_period_ends_at' => now()->subMinute()]);
        app(AppBillingService::class)->renewDue();
        $installation->refresh();
        $this->assertSame('past_due', $installation->status);
        $this->assertNotNull($installation->grace_period_ends_at);
        $this->assertTrue(Entitlements::allows($user->fresh(), 'hotel'), 'grace keeps access');

        // Terms 6.4 (P1-23 part 7): 21 days overdue and 7+ days after the written notice → suspended, grants revoked.
        $installation->update(['current_period_ends_at' => now()->subDays(22), 'suspension_notice_at' => now()->subDays(8), 'grace_period_ends_at' => now()->subDay()]);
        app(AppBillingService::class)->renewDue();
        $this->assertSame('suspended', $installation->fresh()->status);
        $this->assertFalse(Entitlements::allows($user->fresh(), 'hotel'), 'suspension revokes grants');
    }
}
