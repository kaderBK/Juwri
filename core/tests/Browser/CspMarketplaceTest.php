<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Models\App;
use App\Models\MarketplaceMedia;
use App\Models\Theme;
use Illuminate\Support\Str;
use Tests\Browser\Support\CspProbe;
use Tests\Browser\Support\Fixtures;

// P6-122: these routes carry the enforced policy themselves (csp.app / csp.dashboard); the tests run against the real routes.

/**
 * P6-115b: the public Theme Store (/marketplace) and App Store (/apps/store), the partner screenshot form, and the theme
 * broken-image fallback, with the Content-Security-Policy ENFORCED. Facet checkboxes, sort, search and the screenshot picker
 * were onchange= / onsearch= / onclick= before P6-115; they are data-change / data-search / data-act now.
 */
function storeTheme(string $slug, string $category, float $price, ?string $name = null): Theme
{
    $theme = Theme::create(['name' => $name ?? ucfirst($slug), 'slug' => $slug, 'status' => 'published', 'price' => $price, 'category' => $category, 'tagline' => 'Tagline '.$slug]);
    $theme->versions()->create(['semver' => '1.0.0', 'manifest' => ['t' => $slug], 'checksum' => hash('sha256', $slug), 'status' => 'published']);

    return $theme;
}

function storeApp(string $slug, string $category, string $billing = 'free', ?string $name = null): App
{
    return App::create([
        'name' => $name ?? ucfirst($slug), 'slug' => $slug, 'scopes' => ['menu:read'], 'status' => 'approved', 'category' => $category,
        'tagline' => 'Tagline '.$slug, 'billing_type' => $billing, 'price' => $billing === 'recurring' ? 9 : 0, 'billing_period_days' => 30,
    ]);
}

/** Slugs of the cards in the result grid, read from their detail links. */
function listedSlugs(object $page, string $prefix): array
{
    return $page->script("() => [...document.querySelectorAll('a[href*=\"{$prefix}\"]')].map(a => a.getAttribute('href').split('{$prefix}').pop().split('?')[0]).filter((s, i, all) => s && all.indexOf(s) === i)");
}

it('filters, sorts and searches the Theme Store with the enforced policy', function () {
    storeTheme('free-cafe', 'cafe', 0, 'Free Cafe');
    storeTheme('paid-pizza', 'pizza', 19, 'Paid Pizza');
    storeTheme('paid-bakery', 'bakery', 15, 'Paid Bakery');

    $page = visit('/marketplace');
    $page->wait(1);
    CspProbe::arm($page);
    CspProbe::assertClean($page);

    // facet: price = Paid (data-change="facetChanged" data-this -> el.form.submit())
    $page->click('input[name="price"][value="paid"]');
    CspProbe::until($page, fn () => str_contains($page->url(), 'price=paid'), 'the price facet submitted the form');
    CspProbe::until($page, fn () => str_contains($page->content(), 'Paid Pizza'), 'the filtered list rendered');
    expect($page->content())->not->toContain('Free Cafe');

    // facet: an industry checkbox
    CspProbe::arm($page);
    $page->click('input[name="industry[]"][value="pizza"]');
    CspProbe::until($page, fn () => str_contains(urldecode($page->url()), 'industry[]=pizza'), 'the industry facet submitted the form');
    CspProbe::until($page, fn () => str_contains($page->content(), 'Paid Pizza') && ! str_contains($page->content(), 'Paid Bakery'), 'the industry-filtered list rendered');

    // sort: data-change="submitBrowse" on the <select>
    $page->navigate('/marketplace');
    CspProbe::arm($page);
    $page->select('#sortsel', 'price_high');
    CspProbe::until($page, fn () => str_contains($page->url(), 'sort=price_high'), 'the sort select submitted the form');
    CspProbe::assertClean($page, checkHeader: false);
});

it('submits the Theme Store search through the search event (clearing the box reloads the full list)', function () {
    storeTheme('zephyr', 'cafe', 0, 'Zephyr');
    storeTheme('other', 'cafe', 0, 'Other');

    $page = visit('/marketplace?q=zeph');
    $page->wait(1);
    CspProbe::arm($page);
    expect($page->content())->toContain('Zephyr')->and($page->content())->not->toContain('>Other<');

    // Escape in a type=search input clears it and fires `search`, which data-search="submitBrowse" turns into a form submit.
    // (Nothing else navigates here: a native clear never submits a form.)
    $page->keys('input[name="q"]', 'Escape');
    CspProbe::until($page, fn () => ! str_contains($page->url(), 'q=zeph') && str_contains($page->content(), 'Other'), 'clearing the search box reloaded the unfiltered list');
    CspProbe::assertClean($page, checkHeader: false);
});

it('swaps the Theme Store gallery image with the screenshot picker', function () {
    $theme = storeTheme('gallery-theme', 'cafe', 0, 'Gallery Theme');
    $theme->media()->create(['disk_path' => 'storage/marketplace/theme/1/shot-1.png', 'position' => 1, 'kind' => 'screenshot']);
    $theme->media()->create(['disk_path' => 'storage/marketplace/theme/1/shot-2.png', 'position' => 2, 'kind' => 'screenshot']);

    $page = visit('/marketplace/gallery-theme');
    $page->wait(1);
    CspProbe::arm($page);
    $page->click('.thumbs button:nth-child(2)');
    CspProbe::until($page, fn () => $page->script("() => document.querySelector('.thumbs button:nth-child(2)').classList.contains('active')") === true, 'the picked thumbnail is active');
    expect($page->script("() => document.getElementById('galleryImg').getAttribute('src')"))->toContain('shot-2.png');
    CspProbe::assertClean($page);
});

it('filters, sorts and searches the App Store with the enforced policy', function () {
    storeApp('order-free', 'ordering', 'free', 'Order Free');
    storeApp('mkt-paid', 'marketing', 'recurring', 'Mkt Paid');
    storeApp('deliver-paid', 'delivery', 'recurring', 'Deliver Paid');

    $page = visit('/apps/store');
    $page->wait(1);
    CspProbe::arm($page);
    CspProbe::assertClean($page);

    $page->click('input[name="pricing"][value="paid"]');
    CspProbe::until($page, fn () => str_contains($page->url(), 'pricing=paid'), 'the pricing facet submitted the form');
    CspProbe::until($page, fn () => str_contains($page->content(), 'Mkt Paid'), 'the filtered list rendered');
    expect($page->content())->not->toContain('Order Free');

    CspProbe::arm($page);
    $page->click('input[name="category[]"][value="marketing"]');
    CspProbe::until($page, fn () => str_contains(urldecode($page->url()), 'category[]=marketing'), 'the category facet submitted the form');
    CspProbe::until($page, fn () => str_contains($page->content(), 'Mkt Paid') && ! str_contains($page->content(), 'Deliver Paid'), 'the category-filtered list rendered');

    $page->navigate('/apps/store');
    CspProbe::arm($page);
    $page->select('select[name="sort"]', 'name');
    CspProbe::until($page, fn () => str_contains($page->url(), 'sort=name'), 'the sort select submitted the form');
    CspProbe::assertClean($page, checkHeader: false);
});

it('submits the App Store search through the search event', function () {
    storeApp('zephyr-app', 'ordering', 'free', 'Zephyr App');
    storeApp('other-app', 'ordering', 'free', 'Other App');

    $page = visit('/apps/store?q=zeph');
    $page->wait(1);
    CspProbe::arm($page);
    $page->keys('input[name="q"]', 'Escape');
    CspProbe::until($page, fn () => ! str_contains($page->url(), 'q=zeph') && str_contains($page->content(), 'Other App'), 'clearing the search box reloaded the unfiltered list');
    CspProbe::assertClean($page, checkHeader: false);
});

it('swaps the App Store gallery image with the screenshot picker', function () {
    $app = storeApp('gallery-app', 'ordering', 'free', 'Gallery App');
    foreach ([1, 2] as $n) {
        $app->media()->create(['disk_path' => "storage/marketplace/app/1/shot-{$n}.png", 'position' => $n, 'kind' => 'screenshot']);
    }

    $page = visit('/apps/store/gallery-app');
    $page->wait(1);
    CspProbe::arm($page);
    $page->click('.thumbs button:nth-child(2)');
    CspProbe::until($page, fn () => $page->script("() => document.querySelector('.thumbs button:nth-child(2)').classList.contains('active')") === true, 'the picked thumbnail is active');
    expect($page->script("() => document.getElementById('galleryImg').getAttribute('src')"))->toContain('shot-2.png');
    CspProbe::assertClean($page);
});

it('asks before deleting a partner screenshot: cancel keeps it, OK deletes it', function () {
    $owner = Fixtures::owner(['username' => 'partner_owner', 'email' => 'partner-owner@example.test']);
    $theme = storeTheme('partner-theme', 'cafe', 0, 'Partner Theme');
    $theme->update(['seller_id' => $owner->id]);
    $shot = $theme->media()->create(['disk_path' => 'storage/marketplace/theme/'.$theme->id.'/p1.png', 'position' => 1, 'kind' => 'screenshot']);

    $page = visit('/login');
    $page->type('username', 'partner-owner@example.test')
        ->type('password', Fixtures::PASSWORD)
        ->click('form button[type=submit]')
        ->assertPathIsNot('/login')
        ->navigate('/partner/themes/'.$theme->id.'/edit')
        ->wait(1);
    CspProbe::arm($page);
    expect($page->script("() => document.querySelectorAll('form[data-confirm]').length"))->toBeGreaterThan(0);

    // Cancel: the confirm appears, the submit is prevented, the row is still there.
    CspProbe::answers($page, false);
    $page->click('form[data-confirm] button[type=submit]')->wait(2);
    $dialogs = CspProbe::dialogs($page);
    expect($dialogs[0][0] ?? null)->toBe('confirm');
    expect($dialogs[0][1] ?? '')->not->toBe('', 'the confirm carried the form message');
    expect(MarketplaceMedia::whereKey($shot->id)->exists())->toBeTrue('Cancel kept the screenshot');
    expect($page->url())->toContain('/edit');

    // OK: the form posts and the row is gone.
    CspProbe::answers($page, true);
    $page->click('form[data-confirm] button[type=submit]');
    CspProbe::until($page, fn () => ! MarketplaceMedia::whereKey($shot->id)->exists(), 'OK deleted the screenshot');
    CspProbe::assertClean($page, checkHeader: false);
});

it('falls back cleanly for broken dish images on the retained storefront themes', function (string $template) {
    $store = Fixtures::store(Fixtures::owner(), 'Fallback '.$template, $template);
    Fixtures::item($store, 'Missing Photo Dish', 9.0, 'definitely-missing-'.Str::random(4).'.png');

    $page = visit('/'.$store->slug);
    $page->wait(2);
    CspProbe::arm($page);
    // lazy images below the fold have not been fetched yet: fetch them all so every broken one raises its error event
    $page->script("() => { document.querySelectorAll('img[loading=lazy]').forEach(i => { i.loading = 'eager'; }); return true; }");
    $page->wait(2);

    // Each data-csp-error mode has one visible outcome; a broken image whose outcome is missing means no handler ran.
    $unhandled = $page->script(<<<'JS'
() => [...document.querySelectorAll('img[data-csp-error]')].filter(function (i) {
    if (!i.complete || i.naturalWidth > 0) return false; // loaded fine, or still loading
    var d = i.dataset, mode = d.cspError, parent = i.parentElement;
    switch (mode) {
        case 'hide': return getComputedStyle(i).display !== 'none';
        case 'visibility': return getComputedStyle(i).visibility !== 'hidden';
        case 'opacity': return parseFloat(i.style.opacity) !== parseFloat(d.cspValue || '0');
        case 'parent-hide': return !parent || getComputedStyle(parent).display !== 'none';
        case 'closest-hide': return !i.closest(d.cspClosest) || getComputedStyle(i.closest(d.cspClosest)).display !== 'none';
        case 'closest-class': return !i.closest(d.cspClosest) || !i.closest(d.cspClosest).classList.contains(d.cspClass);
        case 'parent-class': return !parent || !parent.classList.contains(d.cspClass);
        case 'fallback': return !i.getAttribute('src').endsWith(d.cspFallback);
        case 'fallback-class': return !i.classList.contains(d.cspClass);
        case 'shimmer-error': return !!parent && parent.classList.contains(d.cspShimmer);
        case 'remove': case 'replace-ph': return true; // the node is gone when the handler ran: still being here = not handled
        default: return false; // a mode with a dedicated theme handler (carousel-fail, scoop-image-fail, ...) is not judged here
    }
}).map(function (i) { return i.dataset.cspError + ' ' + i.getAttribute('src'); })
JS);
    // The shimmer placeholder must stop once the photo is known to be missing (pulse, aurora, vivid): a card whose image is
    // broken or gone but whose container still shimmers would animate forever.
    $stuck = $page->script(<<<'JS'
() => [...document.querySelectorAll('.ps-shimmer, .au-shimmer, .vd-shimmer')].filter(function (el) {
    var img = el.querySelector('img');
    return !img || (img.complete && img.naturalWidth === 0);
}).map(function (el) { return 'stuck-shimmer ' + el.className; })
JS);
    expect(array_merge($unhandled, $stuck))->toBe([], 'every broken image with a data-csp-error mode was handled');
    CspProbe::assertClean($page, checkHeader: false);
})->with(['pizzaflow', 'feast', 'pulse', 'aurora', 'vivid', 'plate', 'nebula', 'pandabite']);
