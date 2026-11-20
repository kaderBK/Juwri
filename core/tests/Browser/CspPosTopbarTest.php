<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Tests\Browser\Support\CspProbe;
use Tests\Browser\Support\Fixtures;

// P6-122: these routes carry the enforced policy themselves (csp.app / csp.dashboard); the tests run against the real routes.

/**
 * P6-115b: the POS register topbar under an enforced Content-Security-Policy. P6-115 turned its inline onclick= /
 * onchange= / onfocus= into Alpine @click / @change / @focus. These journeys use the real controls.
 */
function posSignIn(int $stores = 1): array
{
    $owner = Fixtures::owner(['username' => 'pos_owner', 'email' => 'pos-owner@example.test'], ['pos_enabled' => '1']);
    $made = [];
    foreach (range(1, $stores) as $n) {
        $made[] = $store = Fixtures::store($owner, 'Register Store '.$n);
        Fixtures::item($store, 'POS Burger '.$n, 9.0, Fixtures::menuImage());
    }

    $page = visit('/login');
    $page->type('username', 'pos-owner@example.test')
        ->type('password', Fixtures::PASSWORD)
        ->click('form button[type=submit]')
        ->assertPathIsNot('/login')
        ->navigate('/pos')
        ->assertPathIs('/pos')
        ->wait(2);
    CspProbe::arm($page);

    return [$page, $made];
}

it('toggles the POS theme from the topbar button and remembers it', function () {
    [$page] = posSignIn();
    $before = $page->script('() => document.documentElement.dataset.posTheme || ""');

    $page->click('.pos-theme-toggle');
    CspProbe::until($page, fn () => $page->script('() => document.documentElement.dataset.posTheme || ""') !== $before, 'the theme attribute flipped');
    $after = $page->script('() => document.documentElement.dataset.posTheme');
    expect($page->script("() => localStorage.getItem('pos-theme')"))->toBe($after);

    $page->click('.pos-theme-toggle');
    CspProbe::until($page, fn () => $page->script('() => document.documentElement.dataset.posTheme') !== $after, 'the second click flipped it back');
    CspProbe::assertClean($page);
});

it('unlocks the search field on focus (readonly is removed)', function () {
    [$page] = posSignIn();
    expect($page->script("() => document.getElementById('pos-search').hasAttribute('readonly')"))->toBeTrue();

    $page->click('#pos-search');
    CspProbe::until($page, fn () => $page->script("() => document.getElementById('pos-search').hasAttribute('readonly')") === false, 'focus removed readonly');
    $page->type('#pos-search', 'POS Burger');
    expect($page->script("() => document.getElementById('pos-search').value"))->toBe('POS Burger');
    CspProbe::assertClean($page, checkHeader: false);
});

it('switches register store from the store selector', function () {
    [$page, $stores] = posSignIn(2);
    $second = $stores[1];

    // the store <select> is the one whose Alpine @change sends the browser to /pos?store=
    $page->script("() => { document.querySelectorAll('.topbar select').forEach(s => { if ((s.getAttribute('@change') || '').includes('location.href')) s.id = 'pos-store-switch'; }); return true; }");
    $page->select('#pos-store-switch', (string) $second->id);
    CspProbe::until($page, fn () => str_contains($page->url(), 'store='.$second->id), 'the register reloaded for the other store');
    CspProbe::assertClean($page, checkHeader: false);
});

it('opens and closes the keyboard help, and opens the Tables panel from the Select Table button', function () {
    [$page] = posSignIn();
    $visible = fn (string $css) => $page->script("() => { const e = document.querySelector('{$css}'); return !!e && getComputedStyle(e).display !== 'none'; }");

    $page->click('.pos-help-toggle');
    CspProbe::until($page, fn () => $visible('.pos-help') === true, 'the help overlay opened');
    $page->click('.pos-help-x');
    CspProbe::until($page, fn () => $visible('.pos-help') === false, 'the help overlay closed');

    expect($visible('[x-init="initMerge()"]'))->toBeFalse('the Tables panel starts closed');
    $page->click('.pos-cta-btn');
    CspProbe::until($page, fn () => $visible('[x-init="initMerge()"]') === true, 'Select Table opened the Tables panel');
    $page->assertNoJavaScriptErrors();
    CspProbe::assertClean($page, checkHeader: false);
});
