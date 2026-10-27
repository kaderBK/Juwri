<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\StoreOption;
use App\Models\StoreView;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\StorePaymentGateway;
use Juwri\Modules\Payments\Services\StorePaymentGateways;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-297 — the owner "Get started" checklist must only tick a step from a REAL
 * signal and must cover the steps that stop a store from taking an order.
 *
 * Weak signals it must stop trusting:
 *   - "first menu item" used to be `Menu::where(store)->exists()` — an inactive,
 *     zero-price or demo-seeded row counted;
 *   - the preview step ticked on any StoreView row (a bot hit) and the QR step on
 *     a page visit.
 *
 * Missing steps the fix adds (each with a deep link): >= 1 active priced item,
 * opening hours set (or 24/7 confirmed), a payment method enabled, a first test
 * order placed/cancelled, and a printer or KDS opened.
 *
 * These tests read the rendered checklist rather than any private API: a step is
 * "done" when its <li> carries `data-done`, and a store is complete when the card
 * renders "You are all set". That keeps them honest about what the owner sees.
 */
class OnboardingChecklistAccuracyIBTest extends TestCase
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

        // The gateway lookup is memoised per (app, store); a recycled store id in a
        // later test must not read a previous test's rows.
        StorePaymentGateways::forgetMemo();
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

    private function addActivePricedItem(Store $store, bool $active = true, int|float $price = 5): Menu
    {
        return Menu::create([
            'store_id' => $store->id, 'name' => 'Item '.Str::random(4), 'price' => $price,
            'description' => 'T', 'active' => $active ? 1 : 0, 'position' => 1,
        ]);
    }

    private function setOpeningHours(Store $store): void
    {
        $hours = [];
        foreach (Branch::DAYS as $day) {
            $hours[$day] = ['open' => '00:00', 'close' => '23:59', 'closed' => false];
        }

        Branch::where('store_id', $store->id)->first()?->update(['hours' => $hours, 'timezone' => 'Asia/Riyadh']);
    }

    private function enablePaymentMethod(Store $store): void
    {
        StorePaymentGateway::create([
            'account_id' => $store->account_id,
            'store_id' => $store->id,
            'gateway' => 'stripe',
            'enabled' => true,
            'config' => ['install' => '1'],
        ]);
        StorePaymentGateways::forgetMemo();
    }

    private function placeTestOrder(Store $store): void
    {
        Order::factory()->create(['store_id' => $store->id, 'status' => 'cancelled', 'is_paid' => 0]);
        Order::factory()->create(['store_id' => $store->id, 'status' => 'completed', 'is_paid' => 1]);
    }

    private function addPrinterOrStation(Store $store): void
    {
        PrinterProfile::create([
            'store_id' => $store->id, 'name' => 'Front', 'transport' => 'webserial',
            'width_mm' => 80, 'events' => ['new_order'], 'station_ids' => [],
            'auto_print' => true, 'active' => true, 'copies' => 1,
        ]);
        KitchenStation::create(['store_id' => $store->id, 'name' => 'Kitchen', 'active' => true, 'position' => 0]);
    }

    /**
     * A store that satisfies every signal the checklist is supposed to check.
     * $skip takes any of: logo, menu, preview, qr, twofactor, hours, payment,
     * order, printer — so a test can drop exactly one and see the step stay open.
     *
     * @param  list<string>  $skip
     */
    private function makeStoreReady(User $owner, array $skip = []): Store
    {
        $store = $this->makeStore($owner, in_array('logo', $skip, true) ? 'default.png' : 'logo.png');

        if (! in_array('menu', $skip, true)) {
            $this->addActivePricedItem($store);
        }
        if (! in_array('preview', $skip, true)) {
            StoreView::create(['store_id' => $store->id, 'ip' => '10.0.0.1', 'date' => Carbon::now(), 'device' => 'Mobile', 'lang' => 'en']);
        }
        if (! in_array('qr', $skip, true)) {
            StoreOption::updateStoreOption($store->id, 'qr_builder_visited', '1');
        }
        if (! in_array('twofactor', $skip, true)) {
            $this->enableTwoFactor($owner);
        }
        if (! in_array('hours', $skip, true)) {
            $this->setOpeningHours($store);
        }
        if (! in_array('payment', $skip, true)) {
            $this->enablePaymentMethod($store);
        }
        if (! in_array('order', $skip, true)) {
            $this->placeTestOrder($store);
        }
        if (! in_array('printer', $skip, true)) {
            $this->addPrinterOrStation($store);
        }

        return $store;
    }

    private function renderDashboard(User $owner): TestResponse
    {
        return $this->actingAs($owner)->get(route('dashboard'));
    }

    /**
     * The checklist <li> rows as rendered, each with its done/next state and text.
     *
     * @return list<array{done: bool, next: bool, html: string, text: string}>
     */
    private function checklistSteps(TestResponse $response): array
    {
        $body = (string) $response->getContent();

        $section = $body;
        if (preg_match('/<section\b[^>]*id="ob-checklist".*?<\/section>/s', $body, $m)) {
            $section = $m[0];
        } elseif (preg_match('/<section\b[^>]*spotlight-checklist.*?<\/section>/s', $body, $m)) {
            $section = $m[0];
        }

        preg_match_all('/<li\b[^>]*spotlight-checklist__step[^>]*>.*?<\/li>/s', $section, $matches);

        $steps = [];
        foreach ($matches[0] as $li) {
            $steps[] = [
                'done' => (bool) preg_match('/\bdata-done\b/', $li),
                'next' => (bool) preg_match('/\bdata-next\b/', $li),
                'html' => $li,
                'text' => trim(preg_replace('/\s+/', ' ', strip_tags($li))),
            ];
        }

        return $steps;
    }

    /**
     * @param  list<array{done: bool, next: bool, html: string, text: string}>  $steps
     * @param  list<string>  $keywords
     * @return array{done: bool, next: bool, html: string, text: string}|null
     */
    private function findStep(array $steps, array $keywords): ?array
    {
        foreach ($steps as $step) {
            $hay = mb_strtolower($step['text'].' '.$step['html']);
            foreach ($keywords as $keyword) {
                if (str_contains($hay, mb_strtolower($keyword))) {
                    return $step;
                }
            }
        }

        return null;
    }

    /** @param list<array{done: bool, next: bool, html: string, text: string}> $steps */
    private function assertMenuStepOpen(array $steps): void
    {
        $menu = $this->findStep($steps, ['menu item', 'menu', 'dish', 'item']);
        $this->assertNotNull($menu, 'the checklist still has a menu step');
        $this->assertFalse($menu['done'], 'the menu step stays open until there is a real, sellable item');
    }

    // ── the weak "first menu item" signal ────────────────────────────────────

    public function test_inactive_only_menu_keeps_the_menu_step_open(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'logo.png');
        // The only item is switched OFF — a store that cannot sell it is not ready.
        $this->addActivePricedItem($store, active: false, price: 5);
        $this->enableTwoFactor($owner);

        $response = $this->renderDashboard($owner);

        $response->assertStatus(200);
        $response->assertDontSeeHtml('You are all set');
        $this->assertMenuStepOpen($this->checklistSteps($response));
    }

    public function test_zero_price_only_menu_keeps_the_menu_step_open(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'logo.png');
        // Active but free: `Menu::exists()` used to be enough.
        $this->addActivePricedItem($store, active: true, price: 0);
        $this->enableTwoFactor($owner);

        $response = $this->renderDashboard($owner);

        $response->assertStatus(200);
        $response->assertDontSeeHtml('You are all set');
        $this->assertMenuStepOpen($this->checklistSteps($response));
    }

    public function test_an_active_priced_item_ticks_the_menu_step(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'logo.png');
        $this->addActivePricedItem($store, active: true, price: 12.5);
        $this->enableTwoFactor($owner);

        $steps = $this->checklistSteps($this->renderDashboard($owner));

        $menu = $this->findStep($steps, ['menu item', 'menu', 'dish', 'item']);
        $this->assertNotNull($menu, 'the checklist still has a menu step');
        $this->assertTrue($menu['done'], 'a real sellable item ticks the menu step');
    }

    // ── the headline bug: old weak signals no longer look like "ready" ────────

    public function test_a_store_with_only_the_old_weak_signals_is_not_all_set(): void
    {
        $owner = $this->makeOwner();
        // Exactly what the pre-P6-297 checklist needed to declare "all set".
        $store = $this->makeStore($owner, 'logo.png');
        $this->addActivePricedItem($store);
        StoreView::create(['store_id' => $store->id, 'ip' => '10.0.0.1', 'date' => Carbon::now(), 'device' => 'Mobile', 'lang' => 'en']);
        StoreOption::updateStoreOption($store->id, 'qr_builder_visited', '1');
        $this->enableTwoFactor($owner);

        $response = $this->renderDashboard($owner);

        $response->assertStatus(200);
        $response->assertDontSeeHtml('You are all set');
        $response->assertSeeHtml('>Next<');
    }

    // ── opening hours ────────────────────────────────────────────────────────

    public function test_the_hours_step_is_present_and_open_until_hours_are_set(): void
    {
        $owner = $this->makeOwner();
        $this->makeStore($owner, 'logo.png');
        $this->enableTwoFactor($owner);

        $steps = $this->checklistSteps($this->renderDashboard($owner));

        $hours = $this->findStep($steps, ['opening hours', 'business hours', 'hours', 'open 24', '24/7', 'always open']);
        $this->assertNotNull($hours, 'the checklist has an opening-hours step');
        $this->assertFalse($hours['done'], 'the hours step is open with no hours and no 24/7 confirmation');
    }

    public function test_setting_opening_hours_ticks_the_hours_step(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'logo.png');
        $this->addActivePricedItem($store);
        $this->setOpeningHours($store);
        $this->enableTwoFactor($owner);

        $steps = $this->checklistSteps($this->renderDashboard($owner));

        $hours = $this->findStep($steps, ['opening hours', 'business hours', 'hours', 'open 24', '24/7', 'always open']);
        $this->assertNotNull($hours, 'the checklist has an opening-hours step');
        $this->assertTrue($hours['done'], 'a saved weekly hours grid ticks the hours step');
    }

    public function test_missing_opening_hours_keeps_the_checklist_from_completing(): void
    {
        $owner = $this->makeOwner();
        $this->makeStoreReady($owner, ['hours']);

        $response = $this->renderDashboard($owner);

        $response->assertDontSeeHtml('You are all set');
        $response->assertSeeHtml('>Next<');
    }

    // ── payment method ───────────────────────────────────────────────────────

    public function test_the_payment_step_is_present_and_open_until_a_method_is_enabled(): void
    {
        $owner = $this->makeOwner();
        $this->makeStore($owner, 'logo.png');
        $this->enableTwoFactor($owner);

        $steps = $this->checklistSteps($this->renderDashboard($owner));

        $payment = $this->findStep($steps, ['payment', 'payment method', 'pay online', 'accept payment', 'gateway', 'card']);
        $this->assertNotNull($payment, 'the checklist has a payment-method step');
        $this->assertFalse($payment['done'], 'the payment step is open when no method is enabled');
    }

    public function test_enabling_a_payment_method_ticks_the_payment_step(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'logo.png');
        $this->addActivePricedItem($store);
        $this->enablePaymentMethod($store);
        $this->enableTwoFactor($owner);

        $steps = $this->checklistSteps($this->renderDashboard($owner));

        $payment = $this->findStep($steps, ['payment', 'payment method', 'pay online', 'accept payment', 'gateway', 'card']);
        $this->assertNotNull($payment, 'the checklist has a payment-method step');
        $this->assertTrue($payment['done'], 'an enabled gateway ticks the payment step');
    }

    public function test_missing_payment_method_keeps_the_checklist_from_completing(): void
    {
        $owner = $this->makeOwner();
        $this->makeStoreReady($owner, ['payment']);

        $response = $this->renderDashboard($owner);

        $response->assertDontSeeHtml('You are all set');
        $response->assertSeeHtml('>Next<');
    }

    // ── first test order ─────────────────────────────────────────────────────

    public function test_the_test_order_step_is_present_and_open_until_an_order_exists(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'logo.png');
        $this->addActivePricedItem($store);
        $this->enableTwoFactor($owner);

        $steps = $this->checklistSteps($this->renderDashboard($owner));

        $order = $this->findStep($steps, ['test order', 'test-order', 'first order', 'place an order']);
        $this->assertNotNull($order, 'the checklist has a first-test-order step');
        $this->assertFalse($order['done'], 'the order step is open before any test order');
    }

    public function test_placing_a_test_order_ticks_the_order_step(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'logo.png');
        $this->addActivePricedItem($store);
        $this->placeTestOrder($store);
        $this->enableTwoFactor($owner);

        $steps = $this->checklistSteps($this->renderDashboard($owner));

        $order = $this->findStep($steps, ['test order', 'test-order', 'first order', 'place an order']);
        $this->assertNotNull($order, 'the checklist has a first-test-order step');
        $this->assertTrue($order['done'], 'a placed/cancelled test order ticks the step');
    }

    public function test_missing_test_order_keeps_the_checklist_from_completing(): void
    {
        $owner = $this->makeOwner();
        $this->makeStoreReady($owner, ['order']);

        $response = $this->renderDashboard($owner);

        $response->assertDontSeeHtml('You are all set');
        $response->assertSeeHtml('>Next<');
    }

    // ── printer / KDS ────────────────────────────────────────────────────────

    public function test_the_printer_or_kds_step_is_present_and_open_until_one_is_configured(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'logo.png');
        $this->addActivePricedItem($store);
        $this->enableTwoFactor($owner);

        $steps = $this->checklistSteps($this->renderDashboard($owner));

        $printer = $this->findStep($steps, ['printer', 'print', 'kds', 'kitchen screen', 'kitchen display', 'kitchen']);
        $this->assertNotNull($printer, 'the checklist has a printer/KDS step');
        $this->assertFalse($printer['done'], 'the printer/KDS step is open with no printer or station');
    }

    public function test_opening_a_printer_or_kds_ticks_that_step(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner, 'logo.png');
        $this->addActivePricedItem($store);
        $this->addPrinterOrStation($store);
        $this->enableTwoFactor($owner);

        $steps = $this->checklistSteps($this->renderDashboard($owner));

        $printer = $this->findStep($steps, ['printer', 'print', 'kds', 'kitchen screen', 'kitchen display', 'kitchen']);
        $this->assertNotNull($printer, 'the checklist has a printer/KDS step');
        $this->assertTrue($printer['done'], 'a configured printer or kitchen station ticks the step');
    }

    public function test_missing_printer_or_kds_keeps_the_checklist_from_completing(): void
    {
        $owner = $this->makeOwner();
        $this->makeStoreReady($owner, ['printer']);

        $response = $this->renderDashboard($owner);

        $response->assertDontSeeHtml('You are all set');
        $response->assertSeeHtml('>Next<');
    }

    // ── the completion path still exists ─────────────────────────────────────

    public function test_a_store_that_can_actually_take_an_order_reaches_all_set(): void
    {
        $owner = $this->makeOwner();
        $this->makeStoreReady($owner);

        $response = $this->renderDashboard($owner);

        $response->assertStatus(200);
        $response->assertSeeHtml('You are all set');
    }
}
