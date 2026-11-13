<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Tests\Browser\Support\Axe;
use Tests\Browser\Support\Fixtures;

/**
 * P1-18 a11y: the crave storefront is a single-page app, so its screens are views/sheets of the one Blade
 * page rather than separate URLs. These cover the menu list, an open product sheet and the cart sheet, each
 * reached by clicking the storefront's own controls (the splash lays over the home until dismissed).
 */
it('has no axe-core WCAG 2.1 AA violations on the storefront menu list', function () {
    $store = Fixtures::store(Fixtures::owner());
    Fixtures::item($store, 'Accessible Burger', 10.00);

    $page = visit('/'.$store->slug);
    $page->click('#cvSplashSkip')
        ->wait(1);

    Axe::assertClean($page, 'crave storefront menu list /'.$store->slug);
});

it('has no axe-core WCAG 2.1 AA violations on the storefront product sheet', function () {
    $store = Fixtures::store(Fixtures::owner());
    $item = Fixtures::item($store, 'Accessible Burger', 12.00);
    $item->update(['description' => 'Flame-grilled beef with house pickles and smoked cheddar.']);

    $page = visit('/'.$store->slug);
    $page->click('#cvSplashSkip')
        ->click('[data-open]:not([data-open="0"])')
        ->wait(1);

    Axe::assertClean($page, 'crave storefront product sheet /'.$store->slug);
});

it('has no axe-core WCAG 2.1 AA violations on the storefront cart sheet', function () {
    $store = Fixtures::store(Fixtures::owner());
    Fixtures::item($store, 'Accessible Burger', 12.00);

    $page = visit('/'.$store->slug);
    $page->click('#cvSplashSkip')
        ->click('[data-open]:not([data-open="0"])')
        ->click('#addToCart')
        ->click('#pViewCart')
        ->wait(1);

    Axe::assertClean($page, 'crave storefront cart sheet /'.$store->slug);
});
