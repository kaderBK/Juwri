<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Models\StoreOption;
use Tests\Browser\Support\Fixtures;

/**
 * P6-127: every shipped theme opens in a real browser under the enforced storefront CSP, for a store with no logo,
 * cover or item photo, with no JavaScript error and nothing in the console (a CSP violation logs there).
 * Empty <img src=""> slots made some themes re-request the page itself; none may be left.
 */
dataset('storefront_themes', function () {
    $themes = [];
    foreach (glob(dirname(__DIR__, 2).'/resources/views/store_templates/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $theme = basename($dir);
        if (is_file(dirname(__DIR__, 3).'/assets/store_templates/'.$theme.'/theme-info.txt')) {
            $themes[$theme] = $theme;
        }
    }

    return $themes;
});

it('opens the storefront without console errors and without an empty image src', function (string $theme) {
    $store = Fixtures::store(Fixtures::owner(), 'No Logo Grill');
    $store->update(['main_image' => '', 'cover_image' => '']);
    StoreOption::updateStoreOption($store->id, 'store_template', $theme);
    Fixtures::item($store, 'Plain Burger', 10.00, '');

    $page = visit('/'.$store->slug);
    $page->wait(1)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();

    $empty = $page->script("Array.from(document.querySelectorAll('img[src=\"\"]')).map(function (i) { return i.outerHTML.slice(0, 160); }).join(' | ')");
    expect($theme.': '.$empty)->toBe($theme.': ');
})->with('storefront_themes');
