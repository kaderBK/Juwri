<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Tests\Browser\Support\Axe;
use Tests\Browser\Support\Fixtures;

/*
 * P1-18 a11y — the crave storefront checkout journey, in the English locale (the
 * app default: config('app.locale') === 'en').
 *
 * The storefront is a single-page app (core/resources/views/store_templates/crave/index.blade.php):
 * the cart, checkout and confirmation are views toggled by clicks, so each step clicks
 * through the flow, waits for the view to settle, then runs Axe::assertClean (axe-core,
 * WCAG 2.1 level A + AA). Fixtures::armStubGateway() arms the test-only Stub gateway so
 * the "Pay Online" tender is offered; a pay-on-counter order needs no gateway.
 */

it('has no axe-core WCAG 2.1 AA violations on the checkout form', function () {
    $store = Fixtures::store(Fixtures::owner());
    Fixtures::item($store, 'Browser Burger', 10.00);

    $page = visit('/'.$store->slug);
    $page->click('[data-open]:not([data-open="0"])')
        ->click('#addToCart')
        ->click('#pViewCart')
        ->click('#cvGoCheckout')
        ->wait(1);

    Axe::assertClean($page, 'crave checkout form /'.$store->slug);
});

it('has no axe-core WCAG 2.1 AA violations with the online payment method selected', function () {
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
        ->wait(1);

    Axe::assertClean($page, 'crave checkout, Pay Online selected /'.$store->slug);
});

it('has no axe-core WCAG 2.1 AA violations after placing a pay-on-counter order', function () {
    $store = Fixtures::store(Fixtures::owner());
    Fixtures::item($store, 'Browser Burger', 10.00);

    $page = visit('/'.$store->slug);
    $page->click('[data-open]:not([data-open="0"])')
        ->click('#addToCart')
        ->click('#pViewCart')
        ->click('#cvGoCheckout')
        ->type('#cvCustName', 'Counter Guest')
        ->type('#cvCustPhone', '5551234567')
        ->click('#cvPlaceOrder');

    // The SPA shows its confirmation view, then auto-redirects to the order's tracking
    // page (~3s for crave). assertPathBeginsWith is retried by pest-plugin-browser, so it
    // waits that redirect out; axe then runs on whatever page the diner landed on.
    $page->assertPathBeginsWith('/order/track/')
        ->wait(1);

    Axe::assertClean($page, 'crave order confirmation/track page /'.$store->slug);
});

it('has no axe-core WCAG 2.1 AA violations on the stub payment page', function () {
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
        ->wait(1);

    Axe::assertClean($page, 'stub payment page /payment/{transaction}');
});
