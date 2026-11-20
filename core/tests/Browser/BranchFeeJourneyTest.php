<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Tests\Browser\Support\Fixtures;

/**
 * Every default storefront image resolves to `storage/<dir>/default.png` (the store's
 * logo/cover, plus the menu item/category, banner and allergy fallbacks). Those files
 * ship with a real install but not with a browser-test checkout, so the in-process test
 * server finds no static file, falls through to the router and Laravel throws
 * NotFoundHttpException. Materialise one 1x1 PNG per default path under the public
 * storage disk (the server's docroot is the repo root — see AppServiceProvider's
 * usePublicPath) so each image request is served as a real file. Idempotent, and LOCK_EX
 * so parallel browser workers cannot interleave writes.
 */
function ensureBranchFeeJourneyDefaultImages(): void
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );

    $paths = [
        'storage/store/logo/default.png',  // store main_image / brand mark
        'storage/store/cover/default.png', // store cover_image
        'storage/menu/default.png',             // item, category and sub-category images
        'storage/menu_banners/default.png',     // admin-managed banner image
        'storage/allergies/default.png',        // item allergy image
        'storage/profile/default.png',          // user avatar fallback
        'storage/logo/classic-theme_footer_logo.png', // crave footer logo
        'storage/logo/classic-theme_logo.png',
        'storage/logo/classic-theme_favicon.png',
    ];

    foreach ($paths as $rel) {
        $abs = public_path($rel);
        if (is_file($abs)) {
            continue;
        }

        File::ensureDirectoryExists(dirname($abs));
        file_put_contents($abs, $png, LOCK_EX);
    }
}

/**
 * P5-38a: the owner sets a branch's prep time, delivery fee (7.00), minimum order (20)
 * and Google place ID through the real branches screen, then a diner ordering at that
 * branch sees the branch fee on the storefront checkout and is refused below its
 * minimum. The branch overrides are charged on the placed order (minor units).
 */
it('sets branch delivery overrides in the owner UI and the storefront honours them', function () {
    app()->setLocale('en'); // the suite pins DEFAULT_LANGUAGE=ar; this journey reads English chrome/text.
    ensureBranchFeeJourneyDefaultImages(); // serve every storefront image default as a real file.

    $owner = Fixtures::owner(['email' => 'branch-owner-'.Str::random(4).'@example.test']);
    $store = Fixtures::store($owner, 'Branch Bistro');

    // Delivery must exist at the store level so the craving storefront shows the
    // delivery option and a fee on the checkout totals (the branch overrides it too).
    StoreOption::updateStoreOption($store->id, 'restaurant_delivery_order', '1');
    StoreOption::updateStoreOption($store->id, 'restaurant_delivery_charge', '7');

    // A real on-disk image: the crave item sheet renders the item image, and the schema's
    // default (storage/menu/default.png) has no file for the in-process browser server to
    // serve, so opening the sheet would 404 through the router (see Fixtures::menuImage()).
    Fixtures::item($store, 'Branch Burger', 12.00, Fixtures::menuImage()); // 1× = 12 (< 20), 2× = 24 (≥ 20)

    // ── owner journey: sign in and open the branches screen ────────────────────
    $page = visit('/login');
    $page->type('username', $owner->email)
        ->type('password', Fixtures::PASSWORD)
        ->click('form button[type=submit]')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();

    $parts = (array) parse_url(route('branches.index', ['store_id' => $store->id]));
    $branchesPath = ((string) ($parts['path'] ?? '/'))
        .((string) ($parts['query'] ?? '') !== '' ? '?'.(string) $parts['query'] : '');

    $page = $page->navigate($branchesPath);
    $page->assertSee('Add branch')->assertNoJavaScriptErrors();

    // Create the branch through the add form.
    $page->type('#br-name', 'Downtown')
        ->type('#br-address', '1 Main St')
        ->type('#br-phone', '5551234567')
        ->click('.ui-branches__add button[type=submit]')
        ->wait(1);

    $branch = Branch::where('store_id', $store->id)->latest('id')->first();
    expect($branch)->not->toBeNull();

    // The store ships with a default "Main" branch, so the list holds two items.
    // Scope the Edit button to the branch we just created (by its name).
    $editBranchButton = '.ui-branches__item:has-text("Downtown") button[aria-label="Edit"]';

    // Edit the branch through the real dialog: place ID + fee / minimum / prep time.
    $page->click($editBranchButton)
        ->wait(1)
        ->type('#edit-google-place-id', 'ChIJN1t_tDeuEmsRUsoyG83frY4')
        ->type('input[name="prep_time_minutes"]', '25')
        ->type('input[name="delivery_fee"]', '7')
        ->type('input[name="min_order"]', '20')
        ->click('dialog[open] button[type=submit]')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    // The saved values come back on the branch card and stayed on the model.
    $page->assertSee('Place ID set')
        ->assertSee('ChIJN1t_tDeuEmsRUsoyG83frY4');

    $branch->refresh();
    expect((int) $branch->prep_time_minutes)->toBe(25)
        ->and((int) $branch->delivery_fee_minor)->toBe(700)
        ->and((int) $branch->min_order_minor)->toBe(2000)
        ->and($branch->google_place_id)->toBe('ChIJN1t_tDeuEmsRUsoyG83frY4');

    // Reopening the edit form shows the same values back.
    $page->click($editBranchButton)
        ->wait(1)
        ->assertValue('#edit-google-place-id', 'ChIJN1t_tDeuEmsRUsoyG83frY4')
        ->assertValue('input[name="prep_time_minutes"]', '25')
        ->assertValue('input[name="delivery_fee"]', '7')
        ->assertValue('input[name="min_order"]', '20');

    // ── storefront journey: a diner orders at that branch ───────────────────────
    // The form seeds 09:00-22:00 hours; open all day so the run does not depend on the clock.
    $branch->forceFill(['hours' => [], 'accepting_orders' => true, 'active' => true])->save();
    $page = $page->navigate('/'.$store->slug.'?branch_id='.$branch->id);
    $page->wait(1)->assertNoJavaScriptErrors();

    $page->click('[data-open]:not([data-open="0"])') // open the item sheet
        ->click('#addToCart') // 1 × Branch Burger (12 < branch minimum 20)
        ->click('#pViewCart')
        ->wait(1)
        // The order-type segment only exists inside the CART view (#cvCartTools in
        // crave/index.blade.php); the checkout view hides it. Select delivery here,
        // while the cart is on screen, before #cvGoCheckout swaps views.
        ->click('#cvOrderTypeSeg button[data-ot="delivery"]')
        ->click('#cvCartList .qty-btn[data-cq="1"]') // 2 x Branch Burger = 24 (>= branch minimum 20)
        ->click('#cvGoCheckout')
        ->wait(1)
        // #cvGoCheckout runs show('checkout') (crave script.js); confirm the checkout
        // view is the active one before typing into its fields.
        ->assertScript('document.querySelector(\'[data-view="checkout"]\').classList.contains(\'is-active\')', true);

    $page->type('#cvCustName', 'Browser Guest')
        ->type('#cvCustPhone', '5551234567')
        ->type('#cvCustAddr', '1 Main St');

    // The delivery totals show the branch's 7.00 fee.
    $page->assertSee('Delivery')
        ->assertSee('7.00');

    // (The below-minimum refusal is covered by the feature tests; here the basket is raised above it.)
    $page->click('#cvPlaceOrder')
        ->assertPathBeginsWith('/order/track/')
        ->assertNoJavaScriptErrors();

    $order = Order::where('store_id', $store->id)->latest('id')->first();
    expect($order)->not->toBeNull()
        ->and((int) $order->branch_id)->toBe((int) $branch->id)
        ->and($order->type)->toBe('delivery')
        ->and($order->deliveryCharge())->toBe(7.0);
});
