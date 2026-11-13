<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use App\Models\StoreView;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Core\Settings\UserSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\StorePaymentGateway;
use Juwri\Modules\Payments\Services\StorePaymentGateways;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * R11 — onboarding checklist: a "next best move" highlight on the first
 * incomplete step, and auto-collapse of the step list once everything is done.
 */
class OwnerDashboardOnboardingTest extends TestCase
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
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
        ]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    private function makeOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'scan_limit' => 999]),
            'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function enableTwoFactor(User $owner): void
    {
        $owner->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
    }

    private function makeStore(User $owner, string $logo = 'default.png'): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => $logo, 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_fresh_owner_sees_next_best_move(): void
    {
        $owner = $this->makeOwner(); // no restaurant yet → step 2 is "next"

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('Get started');
        $response->assertSeeHtml(___('Add your first store')); // a step is visible
        $response->assertSeeHtml('>Next<');                    // "Next" highlight chip
    }

    public function test_completed_onboarding_collapses_steps(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'logo.png'); // logo done
        Menu::create(['store_id' => $store->id, 'name' => 'Item', 'price' => 5, 'description' => 'T', 'active' => 1, 'position' => 1]);
        StoreView::create(['store_id' => $store->id, 'ip' => '10.0.0.1', 'date' => Carbon::now(), 'device' => 'Mobile', 'lang' => 'en']);
        // P0-7: step 5 (QR) is now its own predicate — mark it explicitly.
        StoreOption::updateStoreOption($store->id, 'qr_builder_visited', '1');
        $this->enableTwoFactor($owner); // P6-47: "Secure your account" step
        // P6-297: the new real-check steps also need to be ticked for "all set".
        $store->update(['timing' => '24/7']); // hours / 24-7
        StorePaymentGateway::create([
            'store_id' => $store->id, 'gateway' => 'paypal', 'enabled' => 1,
        ]); // payment method
        Order::create([
            'store_id' => $store->id, 'track_token' => 'TT'.Str::upper(Str::random(6)),
            'customer_name' => 'Test', 'phone_number' => '1', 'type' => 'takeaway',
            'status' => 'cancelled', 'is_paid' => 0, 'cancelled_at' => Carbon::now(),
        ]); // first test order
        PrinterProfile::create([
            'store_id' => $store->id, 'name' => 'Front', 'transport' => 'browser',
            'events' => json_encode(['new_order']), 'station_ids' => json_encode([]),
            'active' => 1, 'position' => 1, 'width_mm' => 80, 'copies' => 1, 'auto_print' => 1,
        ]); // printer OR KDS

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('You are all set');
        $response->assertDontSeeHtml('Add your first store'); // step list collapsed
        $response->assertDontSeeHtml('>Next<');
    }

    public function test_qr_step_incomplete_until_qr_builder_used(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'logo.png');
        Menu::create(['store_id' => $store->id, 'name' => 'Item', 'price' => 5, 'description' => 'T', 'active' => 1, 'position' => 1]);
        // Menu previewed (StoreView present) but QR builder NEVER opened.
        StoreView::create(['store_id' => $store->id, 'ip' => '10.0.0.1', 'date' => Carbon::now(), 'device' => 'Mobile', 'lang' => 'en']);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        // Not all-set: the QR step is still open even though the menu was previewed.
        $response->assertDontSeeHtml('You are all set');
        $response->assertSeeHtml('QR Builder'); // the incomplete QR step's action button

        // Now open the QR builder (and secure the account, P6-47) and tick the
        // P6-297 real-check steps (hours, payment, test order, printer) → the
        // steps complete.
        $this->enableTwoFactor($owner);
        StoreOption::updateStoreOption($store->id, 'qr_builder_visited', '1');
        $store->update(['timing' => '24/7']);
        StorePaymentGateway::create([
            'store_id' => $store->id, 'gateway' => 'paypal', 'enabled' => 1,
        ]);
        StorePaymentGateways::forgetMemo(); // direct create bypasses put(); first dashboard hit memoised 'none'
        Order::create([
            'store_id' => $store->id, 'track_token' => 'TT'.Str::upper(Str::random(6)),
            'customer_name' => 'Test', 'phone_number' => '1', 'type' => 'takeaway',
            'status' => 'cancelled', 'is_paid' => 0, 'cancelled_at' => Carbon::now(),
        ]);
        PrinterProfile::create([
            'store_id' => $store->id, 'name' => 'Front', 'transport' => 'browser',
            'events' => json_encode(['new_order']), 'station_ids' => json_encode([]),
            'active' => 1, 'position' => 1, 'width_mm' => 80, 'copies' => 1, 'auto_print' => 1,
        ]);
        $this->actingAs($owner)->get(route('dashboard'))->assertSeeHtml('You are all set');
    }

    public function test_checklist_state_follows_context_store(): void
    {
        $owner = $this->makeOwner();
        // Store A fully set up.
        $a = $this->makeStore($owner, 'logo.png');
        Menu::create(['store_id' => $a->id, 'name' => 'Item', 'price' => 5, 'description' => 'T', 'active' => 1, 'position' => 1]);
        StoreView::create(['store_id' => $a->id, 'ip' => '10.0.0.1', 'date' => Carbon::now(), 'device' => 'Mobile', 'lang' => 'en']);
        StoreOption::updateStoreOption($a->id, 'qr_builder_visited', '1');
        // Store B is fresh (no logo/menu/QR/preview).
        $b = $this->makeStore($owner);

        // Context on the fresh store B → checklist shows open steps, not "all set".
        $response = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $b->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertDontSeeHtml('You are all set');
        $response->assertSeeHtml('>Next<');
    }

    public function test_restore_link_clears_dismissal(): void
    {
        $owner = $this->makeOwner();
        UserOption::updateUserOption($owner->id, 'onboarding_dismissed', '1');

        // Dismissed → checklist hidden.
        $this->actingAs($owner)->get(route('dashboard'))->assertDontSeeHtml('Get started');

        // Restore → dismissal cleared.
        $this->actingAs($owner)->post(route('onboarding.restore'))->assertRedirect();
        $this->assertNull(UserSettings::legacy((int) $owner->id, 'onboarding_dismissed'));
        $this->actingAs($owner)->get(route('dashboard'))->assertSeeHtml('Get started');
    }
}
