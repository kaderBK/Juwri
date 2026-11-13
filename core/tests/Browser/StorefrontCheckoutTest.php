<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Fixtures;

/**
 * P1-18: a diner orders on the storefront and pays through the Stub gateway (armed only in the testing env, see
 * Fixtures::armStubGateway()). The storefront is the crave theme (single-page app: ids like #addToCart are its own; the product sheet hides the tab bar, so the diner opens the cart with the View-cart button beside Add to Cart).
 */
it('lets a diner add an item, check out and pay with the stub gateway', function () {
    Fixtures::armStubGateway();
    $store = Fixtures::store(Fixtures::owner());
    Fixtures::item($store, 'Browser Burger', 10.00);

    $page = visit('/'.$store->slug);
    $page->click('[data-open]:not([data-open="0"])')
        ->click('#addToCart')
        ->click('#pViewCart')
        ->click('#cvGoCheckout')
        ->type('#cvCustName', 'Browser Guest')
        ->type('#cvCustPhone', '5551234567')
        ->click('.pm[data-pm="pay_online"]')
        ->click('#cvPlaceOrder')
        ->assertPathBeginsWith('/payment/')
        ->assertNoJavaScriptErrors();

    $order = Order::where('store_id', $store->id)->latest('id')->first();
    expect($order)->not->toBeNull()->and((bool) $order->is_paid)->toBeFalse();

    $page->click('button[type=submit]');

    $settled = DB::table('transaction')->where('product_id', $order->id)->latest('id')->first();
    expect($settled->transaction_gatway)->toBe('stub')
        ->and($settled->status)->toBe('consumed')
        ->and((bool) $order->fresh()->is_paid)->toBeTrue();
});

it('places a pay-on-counter order without any gateway', function () {
    $store = Fixtures::store(Fixtures::owner());
    Fixtures::item($store, 'Counter Burger', 8.00);

    $page = visit('/'.$store->slug);
    $page->click('[data-open]:not([data-open="0"])')
        ->click('#addToCart')
        ->click('#pViewCart')
        ->click('#cvGoCheckout')
        ->type('#cvCustName', 'Counter Guest')
        ->type('#cvCustPhone', '5551234567')
        ->click('#cvPlaceOrder')
        ->wait(2);

    $order = Order::where('store_id', $store->id)->latest('id')->first();
    expect($order)->not->toBeNull()
        ->and((bool) $order->is_paid)->toBeFalse();
});
