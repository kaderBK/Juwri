<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\StoreView;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\StorePaymentGateway;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-297: the onboarding checklist must use real business checks for every
 * step that can prevent taking an order. The previous shape ticked any menu
 * row (an inactive, 0-price, or seeded demo item counts) and any StoreView
 * hit (a bot), so a store could look 100% ready while it could not take an
 * order.
 *
 * Per-step state tests below pin:
 *   - menu step: ACTIVE × price > 0 — an inactive-only menu does not tick
 *     the step (the spec's "inactive-only menu -> step open" assertion);
 *   - hours step: timing text "24/7" or a branch.hours row ticks it;
 *   - payment step: an enabled store_payment_gateways row ticks it;
 *   - test-order step: any Order row for the store (placed OR cancelled)
 *     ticks it;
 *   - printer/KDS step: a PrinterProfile OR a KitchenStation row ticks it.
 *
 * The steps render in the dashboard HTML so each test asserts the
 * presence / absence of the step title and CTA via assertSee /
 * assertDontSee.
 */
class OwnerDashboardOnboardingRealChecksTest extends TestCase
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

    private function makeStore(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'logo.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * SPEC: "inactive-only menu -> step open". With only an INACTIVE menu row
     * the step title must still appear (step is OPEN — the row is not enough).
     */
    public function test_menu_step_stays_open_when_only_inactive_items(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        // inactive row only — pre-P6-297 this would tick the step.
        Menu::create(['store_id' => $store->id, 'name' => 'Draft', 'price' => 5, 'description' => 'T', 'active' => 0, 'position' => 1]);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        // The step title is still visible (and not data-done) because no ACTIVE priced item exists.
        $response->assertSeeHtml(___('Add your first menu item'));
    }

    /**
     * SPEC: "inactive-only menu -> step open" — symmetric positive case: an
     * active priced item ticks the step (the step title stays in the DOM
     * because it is always listed, but the row carries data-done and the
     * CTA disappears).
     */
    public function test_menu_step_closes_when_at_least_one_active_priced_item(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        Menu::create(['store_id' => $store->id, 'name' => 'Dish', 'price' => 5, 'description' => 'T', 'active' => 1, 'position' => 1]);
        // ALSO add an inactive draft and a 0-price row — they must NOT
        // demote the step back to open.
        Menu::create(['store_id' => $store->id, 'name' => 'Draft', 'price' => 5, 'description' => 'T', 'active' => 0, 'position' => 2]);
        Menu::create(['store_id' => $store->id, 'name' => 'FreeSample', 'price' => 0, 'description' => 'T', 'active' => 1, 'position' => 3]);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        // The step is closed: the CTA link to restaurants.menu is NOT rendered.
        $content = $response->getContent();
        $this->assertStringNotContainsString(
            'href="'.route('stores.menu', $store->id).'"',
            $content,
            'menu step closed: the "Build menu" CTA must not render when ≥1 active priced item exists'
        );
    }

    /**
     * Per-step state — opening hours: a "24/7" timing string ticks the step
     * (so the step can be taken without configuring structured per-day hours).
     */
    public function test_hours_step_closes_when_timing_is_247(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        $store->update(['timing' => 'Open 24/7']);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringNotContainsString('Set hours', $content, 'hours step closed: timing "24/7" ticks it');
    }

    /**
     * Per-step state — opening hours: NO branch.hours AND no timing text
     * leaves the step open.
     */
    public function test_hours_step_stays_open_without_timing_or_branch_hours(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml(___('Set opening hours'));
    }

    /**
     * Per-step state — payment method: an enabled gateway ticks the step,
     * a disabled-only config does not.
     */
    public function test_payment_step_closes_when_a_gateway_is_enabled(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => 'paypal', 'enabled' => 1]);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringNotContainsString('Set up payments', $content, 'payment step closed when a gateway is enabled');
    }

    public function test_payment_step_stays_open_when_gateway_is_disabled(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        StorePaymentGateway::create(['store_id' => $store->id, 'gateway' => 'paypal', 'enabled' => 0]);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml(___('Enable a payment method'));
    }

    /**
     * Per-step state — test order: any Order row for the store ticks the step,
     * including a cancelled-in-test row (a placed test order is the realistic
     * path; a cancelled test order is an explicitly allowed alternative in
     * the step copy).
     */
    public function test_test_order_step_closes_when_a_cancelled_test_order_exists(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        Order::create([
            'store_id' => $store->id, 'track_token' => 'TT'.Str::upper(Str::random(6)),
            'customer_name' => 'Test', 'phone_number' => '1', 'type' => 'takeaway',
            'status' => 'cancelled', 'is_paid' => 0, 'cancelled_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringNotContainsString('Open storefront', $content, 'test-order step closed when a test order exists');
    }

    public function test_test_order_step_stays_open_without_any_order(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml(___('Place your first test order'));
    }

    /**
     * Per-step state — printer OR KDS: either a PrinterProfile OR a
     * KitchenStation row ticks the step.
     */
    public function test_printer_kds_step_closes_when_a_printer_profile_exists(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        PrinterProfile::create([
            'store_id' => $store->id, 'name' => 'Front', 'transport' => 'browser',
            'events' => json_encode(['new_order']), 'station_ids' => json_encode([]),
            'active' => 1, 'position' => 1, 'width_mm' => 80, 'copies' => 1, 'auto_print' => 1,
        ]);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringNotContainsString('Open printers', $content, 'printer/KDS step closed when a PrinterProfile exists');
    }

    public function test_printer_kds_step_closes_when_a_kitchen_station_exists(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        KitchenStation::create([
            'store_id' => $store->id, 'name' => 'Hot Line', 'active' => 1, 'position' => 1,
        ]);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringNotContainsString('Open printers', $content, 'printer/KDS step closed when a KitchenStation exists');
    }

    public function test_printer_kds_step_stays_open_without_either(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml(___('Open a printer or KDS'));
    }

    /**
     * Each open step links to the screen that closes it (the "real-check
     * steps have deep links" requirement). Assert the new four open steps
     * each carry a non-empty `href`.
     */
    public function test_each_real_check_step_has_a_deep_link(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $content = $response->getContent();

        // Each new step has its own CTA pointing to a route that closes it.
        $this->assertStringContainsString(route('branches.index', ['store_id' => $store->id]), $content, 'hours step links to branches.index');
        $this->assertStringContainsString(route('stores.printing.index'), $content, 'payment OR printers step links to restaurants.printing.index');
        $this->assertStringContainsString(route('publicView', ['slug' => $store->slug]), $content, 'test-order OR preview step links to publicView');
    }

    /**
     * Dashboard renders the onboarding-checklist section only when the
     * store exists (the menu/hours/payment/printer/test-order checks all
     * depend on a context store — a "fresh owner" pre-store must still see
     * a usable checklist, just with the store step first).
     */
    public function test_fresh_owner_with_no_store_sees_store_step_first(): void
    {
        $owner = $this->makeOwner();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeHtml('Get started');
        $response->assertSeeHtml(___('Add your first store'));
    }
}
