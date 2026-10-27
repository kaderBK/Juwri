<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Foundation\Application;
use Juwri\Core\Support\LocalizedRoutes;
use Tests\Browser\Support\Axe;
use Tests\Browser\Support\Fixtures;
use Tests\Support\AisleStore;

/*
 * P6-95 a11y — cart, checkout and the hosted payment page on the six most-used non-crave
 * storefront themes (aisle, basket, brew, feast, pizzaflow, plate), in English and Arabic (RTL).
 * Crave keeps its own journeys in A11yCheckoutTest / A11yCheckoutRtlTest. axe-core runs with the
 * same WCAG 2.1 A + AA filter as every other a11y test (Axe::assertClean).
 *
 * Each theme is its own markup, so a journey is a list of steps keyed by theme: the clicks that
 * add an item, open the cart, open the checkout, fill the contact fields and pick "pay online"
 * (the test-only Stub gateway) so the place-order click lands on /payment/{transaction}.
 *
 * The Arabic prefix needs `settings.include_language_code` set before routes register — see
 * A11yCheckoutRtlTest for why a trait overrides beforeBootstrap(); the English cases run under
 * the same boot and use the unprefixed URL.
 */
trait A11yCheckoutThemesBoot
{
    protected function beforeBootstrap(Application $app): void
    {
        LocalizedRoutes::forget();

        $app->booting(function (): void {
            config([
                'settings.include_language_code' => true,
                'laravellocalization.localeRoutes' => 'ar,en',
            ]);
        });
    }
}

uses(A11yCheckoutThemesBoot::class);

/** @return array<string, array{retail?: bool, payment?: bool, before_pay?: array<int, string>, add: array<int, string>, cart: string, checkout: array<int, string>, name: string, phone: string, online: string, place: string}> */
function a11yThemeJourneys(): array
{
    // aisle and basket are the retail (goods) themes: a retail store (tests/Support/AisleStore), a product opened from its
    // card (the wide-leg trousers have one variant, so nothing needs choosing), pick up in store so no address is needed.
    $retail = [
        'retail' => true,
        'add' => ['a[href*="item=019ed8ab-d380"]', '[data-kit-add]'],
        'cart' => '[data-juwri-open="cart"]',
        'checkout' => ['[data-juwri-open="checkout"]'],
        'name' => '#f-name',
        'phone' => '#f-mobile',
        'before_pay' => ['[data-kit-shipping-methods] label:last-child'],
        'online' => 'label[for="pay-online"]',
        'place' => '[data-juwri-place]',
    ];
    $brew = [
        'add' => ['[data-juwri-add]'],
        'cart' => '[data-juwri-open="cart"]',
        'checkout' => ['[data-juwri-open="checkout"]'],
        'name' => '#f-name',
        'phone' => '#f-mobile',
        'online' => 'label[for="pay-online"]',
        'place' => '[data-juwri-place]',
    ];

    return [
        'aisle' => $retail,
        'basket' => ['payment' => false] + $retail,
        'brew' => ['payment' => false] + $brew,
        'feast' => [
            'add' => ['.ff-quick-add-btn'],
            'cart' => '#ff-cart-toggle',
            'checkout' => [],
            'name' => '#ff-cust-name',
            'phone' => '#ff-phone-num',
            'online' => 'input[name="pay_via"][value="pay_online"]',
            'place' => '#ff-submit-order-btn',
        ],
        'pizzaflow' => [
            'add' => ['.pf-card-title', '#pf-detail-add'],
            'cart' => '#pf-cartbar',
            'checkout' => [],
            'name' => '#pf-checkout-name',
            'phone' => '#pf-checkout-phone',
            'online' => '[data-pay="pay_online"]',
            'place' => '[data-pf-act="submitOrder"]',
        ],
        'plate' => [
            'add' => ['[data-open]:not([data-open="0"])', '#addBtn'],
            'cart' => '#pCartJump',
            'checkout' => ['#checkoutBtn'],
            'name' => '#ckName',
            'phone' => '#ckPhone',
            'online' => '[data-pay="pay_online"]',
            'place' => '#placeBtn',
        ],
    ];
}

/** First visible match: several themes render a control twice (header + drawer, mobile + desktop). */
function a11ySel(string $selector): string
{
    return $selector.' >> visible=true >> nth=0';
}

/**
 * Clicks the first visible match, or nothing when none is shown: on a desktop viewport the cart of
 * brew/basket is an always-open panel (its "open cart" control is mobile-only), so that step is a no-op.
 */
function a11yClick(object $page, string $selector): void
{
    $sel = json_encode($selector, JSON_THROW_ON_ERROR);
    $page->script('() => { const all = [...document.querySelectorAll('.$sel.')]; '
        .'const el = all.find((e) => e.getClientRects().length > 0) ?? all.find((e) => e.tagName === "INPUT"); '
        .'if (!el) return false; el.click(); return true; }');
    $page->wait(0.5);
}

/** @param  array<string, mixed>  $j */
function a11yCartPage(string $theme, string $locale, array $j, bool $online): array
{
    $ar = $locale === 'ar';
    if ($online || ! empty($j['retail'])) {
        Fixtures::armStubGateway();
    }
    if (! empty($j['retail'])) {
        $store = AisleStore::make($theme)->store;
    } else {
        $store = Fixtures::store(Fixtures::owner(), 'Browser Bistro', $theme);
        Fixtures::item($store, $ar ? 'برجر عربي' : 'Browser Burger', 10.00, Fixtures::menuImage());
    }

    $page = visit('/'.$locale.'/'.$store->slug);
    if ($ar) {
        expect($page->script('() => document.documentElement.dir'))->toBe('rtl');
    }
    // The fixed cookie banner (bottom start, 380px) covers the cart bar of several themes; decline it like a diner would.
    $page->script('() => document.querySelector("[data-ckc=reject]")?.click()');
    // feast and plate open on a splash that fades out on its own; skip it so axe does not scan mid-fade colours.
    a11yClick($page, '#ff-splash-skip, #splashSkip');
    $page->wait(1);
    foreach ($j['add'] as $selector) {
        a11yClick($page, $selector);
    }

    return [$page, $store];
}

foreach (['en', 'ar'] as $locale) {
    foreach (a11yThemeJourneys() as $theme => $j) {
        $ar = $locale === 'ar';

        it("has no axe violations on the $theme cart in $locale", function () use ($theme, $locale, $j) {
            [$page] = a11yCartPage($theme, $locale, $j, false);
            a11yClick($page, $j['cart']);
            $page->wait(1);
            Axe::assertClean($page, "$theme cart [$locale]");
        });

        it("has no axe violations on the $theme checkout form in $locale", function () use ($theme, $locale, $j) {
            [$page] = a11yCartPage($theme, $locale, $j, true);
            a11yClick($page, $j['cart']);
            foreach ($j['checkout'] as $selector) {
                a11yClick($page, $selector);
            }
            $page->wait(1);
            Axe::assertClean($page, "$theme checkout [$locale]");
        });

        if (($j['payment'] ?? true) !== false) {
            // basket (a delivery time to pick) and brew (a restaurant order, tracked rather than sent to /payment) do not reach the
            // hosted payment page from the checkout; the page is the same payment.blade.php for every theme (covered by the others).
            it("has no axe violations on the $theme hosted payment page in $locale", function () use ($theme, $locale, $j, $ar) {
                [$page] = a11yCartPage($theme, $locale, $j, true);
                a11yClick($page, $j['cart']);
                foreach ($j['checkout'] as $selector) {
                    a11yClick($page, $selector);
                }
                $page->type(a11ySel($j['name']), $ar ? 'زائر المتصفح' : 'Browser Guest')
                    ->type(a11ySel($j['phone']), '5551234567');
                foreach ($j['before_pay'] ?? [] as $selector) {
                    a11yClick($page, $selector);
                }
                a11yClick($page, $j['online']);
                a11yClick($page, $j['place']);
                $page->assertPathContains('/payment/')
                    ->wait(1);
                Axe::assertClean($page, "$theme payment page [$locale]");
            });
        }
    }
}
