<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;

/*
 * BL-053 — the shared .mba-* sheet skin (store_templates/_partials/mb-sheet-base)
 * styled .mba-scrim with display:flex. That author rule beat the user-agent
 * [hidden]{display:none} rule (same specificity, author origin wins), so the
 * CLOSED scrim stayed a full-viewport, max-z-index (2147483000) layer that
 * swallowed every click on the page behind it — on the customer account page
 * that meant the "Request refund" button never fired.
 *
 * Both sheets are opened/closed by toggling the `hidden` ATTRIBUTE (never
 * style.display), so the CSS has to honour [hidden] explicitly.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->setLocale('en');
    $this->shareLayoutGlobals();
    $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
});

/** A storefront whose account page ships the shared .mba-* sheets. */
function bl053Store(string $template = 'crave'): Store
{
    $plan = TestPlans::create([
        'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
        'lifetime_price' => 1, 'status' => 1,
        'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
    ]);
    $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    $store = Store::create([
        'user_id' => $owner->id, 'slug' => $template.'-demo-'.Str::lower(Str::random(6)), 'color' => '#7c5cff',
        'title' => ucfirst($template).' Demo', 'sub_title' => 'Menu', 'description' => 'Demo menu',
        'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
    ]);
    StoreOption::updateStoreOption($store->id, 'store_template', $template);

    return $store;
}

/** A signed-in customer of the given store. */
function bl053Customer(Store $store): Customer
{
    return Customer::create([
        'store_id' => $store->id,
        'name' => 'Ahmed Ali',
        'email' => 'ahmed@example.test',
        'phone' => '966501234567',
        'password' => Hash::make('SecurePass123'),
    ]);
}

it('hides the mba scrim when the hidden attribute is set', function () {
    $css = view('store_templates._partials.mb-sheet-base')->render();

    // The closed scrim must not paint and must not take clicks.
    expect($css)->toContain('.mba-scrim[hidden]{display:none !important}');

    // The base rule is exactly what the override protects against: it still
    // forces display:flex on the full-viewport, max-z-index scrim.
    expect($css)->toMatch('/\.mba-scrim\{[^}]*display:flex/');
});

it('hides the mba sheet form rows when the hidden attribute is set', function () {
    $css = view('store_templates._partials.mb-sheet-base')->render();

    // The auth sheet keeps the OTP and Register forms in the DOM with `hidden`;
    // .mba-form{display:flex} would otherwise paint all three forms at once.
    expect($css)->toContain('.mba-form[hidden]{display:none !important}');
    expect($css)->toMatch('/\.mba-form\{[^}]*display:flex/');
});

it('ships both scrims closed with the hidden attribute and toggles that attribute, not display', function () {
    $wallet = file_get_contents(__DIR__.'/../../../resources/views/store_templates/_partials/mb-wallet-sheet.blade.php');
    $auth = file_get_contents(__DIR__.'/../../../resources/views/store_templates/_partials/mb-auth-sheet.blade.php');

    expect($wallet)->toContain('<div class="mba-scrim" id="mbwScrim" hidden>');
    expect($auth)->toContain('<div class="mba-scrim" id="mbaScrim" hidden>');

    // Opening/closing flips the attribute only, so the CSS override above is what
    // makes the closed scrim inert — the JS must keep using the attribute.
    foreach ([$wallet, $auth] as $source) {
        expect($source)->toContain('scrim.hidden = false;');
        expect($source)->toContain('scrim.hidden = true;');
        expect($source)->not->toContain('style.display');
    }
});

it('ships the hidden scrim rule on the customer account page', function () {
    config(['settings.admin_allow_customer_wallet' => true]);

    $store = bl053Store();
    $customer = bl053Customer($store);

    $this->actingAs($customer, 'customer');

    $response = $this->get("/{$store->slug}/customer/account");

    $response->assertOk();
    $response->assertSee('.mba-scrim[hidden]{display:none !important}', false);
});
