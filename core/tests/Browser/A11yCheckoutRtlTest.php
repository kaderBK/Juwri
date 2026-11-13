<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Foundation\Application;
use Juwri\Core\Support\LocalizedRoutes;
use Tests\Browser\Support\Axe;
use Tests\Browser\Support\Fixtures;

/**
 * RTL a11y: the same storefront+checkout journey as StorefrontCheckoutTest, but reached through the Arabic locale
 * prefix (`/ar/{slug}`), asserting the document is RTL and running axe-core WCAG 2.1 A/AA on each view. The crave
 * theme is a single-page app, so home, product sheet, cart, checkout and confirmation are views/sheets (clicks, not
 * navigations); one it() replays the journey up to one view.
 *
 * Why the trait below: the `/ar/{slug}` URL only exists when `settings.include_language_code` is set while
 * routes/web.php registers (it selects the `localized` route groups and the storefront's prefixed group). The Pest
 * browser suite boots its application in setUp(), before any beforeEach() can touch config, so the only seam that
 * runs first is CreatesApplication::beforeBootstrap(). This trait overrides it for this file's tests, pins the
 * setting on before the kernel boots, and asks {@see LocalizedRoutes} to register the Arabic prefix up front
 * (`laravellocalization.localeRoutes` = 'ar', the worker/test path — php-fpm would otherwise read the prefix from the
 * current request, which the in-process test server never has while routes are being registered).
 */
trait A11yCheckoutRtlBoot
{
    protected function beforeBootstrap(Application $app): void
    {
        LocalizedRoutes::forget();

        $app->booting(function (): void {
            config([
                'settings.include_language_code' => true,
                'laravellocalization.localeRoutes' => 'ar',
            ]);
        });
    }
}

uses(A11yCheckoutRtlBoot::class);

it('has no axe-core WCAG 2.1 AA violations on the Arabic storefront home', function () {
    $store = Fixtures::store(Fixtures::owner());
    Fixtures::item($store, 'برجر عربي');

    $page = visit('/ar/'.$store->slug);
    expect($page->script('() => document.documentElement.dir'))->toBe('rtl');

    $page->wait(1);
    Axe::assertClean($page, 'Arabic storefront home /ar/'.$store->slug);
});

it('has no axe-core WCAG 2.1 AA violations on the Arabic product sheet', function () {
    $store = Fixtures::store(Fixtures::owner());
    Fixtures::item($store, 'برجر عربي');

    $page = visit('/ar/'.$store->slug);
    expect($page->script('() => document.documentElement.dir'))->toBe('rtl');

    $page->click('[data-open]:not([data-open="0"])')->wait(1);
    Axe::assertClean($page, 'Arabic product sheet');
});

it('has no axe-core WCAG 2.1 AA violations on the Arabic cart', function () {
    $store = Fixtures::store(Fixtures::owner());
    Fixtures::item($store, 'برجر عربي');

    $page = visit('/ar/'.$store->slug);
    expect($page->script('() => document.documentElement.dir'))->toBe('rtl');

    $page->click('[data-open]:not([data-open="0"])')
        ->click('#addToCart')
        ->click('#pViewCart')
        ->wait(1);
    Axe::assertClean($page, 'Arabic cart');
});

it('has no axe-core WCAG 2.1 AA violations on the Arabic checkout form', function () {
    $store = Fixtures::store(Fixtures::owner());
    Fixtures::item($store, 'برجر عربي');

    $page = visit('/ar/'.$store->slug);
    expect($page->script('() => document.documentElement.dir'))->toBe('rtl');

    $page->click('[data-open]:not([data-open="0"])')
        ->click('#addToCart')
        ->click('#pViewCart')
        ->click('#cvGoCheckout')
        ->wait(1);
    Axe::assertClean($page, 'Arabic checkout form');
});

it('has no axe-core WCAG 2.1 AA violations on the Arabic order confirmation', function () {
    $store = Fixtures::store(Fixtures::owner());
    Fixtures::item($store, 'برجر عربي');

    $page = visit('/ar/'.$store->slug);
    expect($page->script('() => document.documentElement.dir'))->toBe('rtl');

    // Pay on counter keeps the diner on the SPA's confirmation sheet (pay online would leave for /payment/).
    // The sheet redirects itself to live tracking after ~3.2s, so scan it right after the order settles.
    $page->click('[data-open]:not([data-open="0"])')
        ->click('#addToCart')
        ->click('#pViewCart')
        ->click('#cvGoCheckout')
        ->type('#cvCustName', 'زائر المتصفح')
        ->type('#cvCustPhone', '5551234567')
        ->click('.pm[data-pm="pay_on_counter"]')
        ->click('#cvPlaceOrder')
        ->wait(2);
    Axe::assertClean($page, 'Arabic order confirmation');
});
