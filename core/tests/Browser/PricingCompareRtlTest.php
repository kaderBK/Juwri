<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Core\Models\Language;
use Tests\Browser\Support\Axe;
use Tests\Support\TestPlans;

/**
 * RB-10(d) RTL audit of the public money pages: /pricing (route('pricing')) and the Odoo comparison
 * (/compare/odoo, route('compare.show', 'odoo')), in English and Arabic at desktop (1280) and mobile
 * (390). For every locale/page/viewport it asserts the document direction/`lang`, that nothing overflows
 * the viewport horizontally, that prices/numbers stay LTR inside RTL text, and that axe-core finds no
 * WCAG 2.1 A/AA violation. The plan billing-cycle toggle on /pricing is also driven by keyboard only.
 *
 * Locale switching: this suite boots the app in-process before any beforeEach() can touch config, so the
 * pattern is the one the SoftPOS browser test uses — register the Language rows, register the same
 * supportedLocales list the app would (mcamara validates config('app.locale') against it), switch through
 * the UI language link (`lang_url()` stores session('locale')), then open the page. A plan is created
 * through Tests\Support\TestPlans so /pricing has one real card + compare column in the licensed build.
 *
 * Decisions (see .loop/report.md):
 * - The numeric-isolation check accepts a bidi isolate/embed/override control character in the element's
 *   text as well as computed `direction: ltr` / `unicode-bidi: isolate|embed|…` / a `<bdi>` or `[dir=ltr]`
 *   ancestor. The control-character case is the app's documented money technique (cur_isolate() wraps the
 *   sign, money_ltr() wraps the whole USD unit) — the plain-text equivalent of <bdi>. Without it the check
 *   could not pass on code that deliberately isolates with U+2066–U+2069.
 * - Scope of the price/number selectors is the pricing *amounts* (card amount + compare-table plan price)
 *   on /pricing and the value cells (.cmp-val) of the competitor comparison. The Odoo page ships no price
 *   rows, so its value cells are checked only when they actually contain a digit; /pricing must always
 *   expose at least one amount, so that check can never pass vacuously.
 */

dataset('localeViewports', [
    'en at 1280' => ['en', 1280],
    'en at 390' => ['en', 390],
    'ar at 1280' => ['ar', 1280],
    'ar at 390' => ['ar', 390],
]);

dataset('locales', [
    'en' => ['en'],
    'ar' => ['ar'],
]);

dataset('rtlLocaleViewports', [
    'ar at 1280' => ['ar', 1280],
    'ar at 390' => ['ar', 390],
]);

/** Registers en/ar, creates the plan, switches to $locale through lang_url() and opens $path at $width. */
$open = function (string $locale, int $width, string $path): mixed {
    Language::updateOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);

    app()->setLocale('en');

    TestPlans::create([
        'name' => 'Starter',
        'description' => 'Starter plan',
        'monthly_price' => 9,
        'annual_price' => 90,
        'lifetime_price' => 100,
        'recommended' => 'yes',
        'settings' => json_encode(['restaurant_limit' => 5]),
        'status' => 1,
    ]);

    // mcamara rejects any config('app.locale') absent from its supportedLocales registry; the app fills it
    // from the active languages table (AppServiceProvider::forRequest -> get_active_languages), which the
    // in-process test env skips, so register the same list by hand (as SoftPosCardTerminalsTest does).
    $supported = get_active_languages();
    config(['laravellocalization.supportedLocales' => $supported]);
    app()->make(\Mcamara\LaravelLocalization\LaravelLocalization::class)->setSupportedLocales($supported);

    $page = visit('/');

    // Switch language through the link the UI language picker uses (session mode: /lang/{code}).
    $page->navigate((string) parse_url(lang_url($locale), PHP_URL_PATH));

    // Resize after the switch: resize is a per-page viewport call, not a device profile.
    $page->resize($width, 900);

    return $page->navigate($path);
};

/** Asserts the document direction and lang attribute for the locale. */
$assertDocument = function ($page, string $locale, string $label): void {
    $dir = $page->script('() => document.documentElement.getAttribute("dir")');
    $lang = $page->script('() => document.documentElement.getAttribute("lang")');

    expect($dir)->toBe($locale === 'ar' ? 'rtl' : 'ltr', $label.' sets the right document direction');
    expect($lang)->toBe($locale, $label.' sets the right document lang');
};

/** Asserts the page does not overflow the viewport horizontally. */
$assertNoOverflow = function ($page, string $label): void {
    expect($page->script('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth'))
        ->toBeTrue($label.' has no horizontal overflow');
};

/**
 * Every numeric/price element selected below must render as one LTR unit inside RTL text: computed
 * direction:ltr, computed unicode-bidi isolate/embed/plaintext, a <bdi>/[dir=ltr] ancestor, or a bidi
 * isolate/embed/override control character in its text (the app's cur_isolate()/money_ltr() technique).
 * __SELECTORS__ is replaced with a JSON array before evaluation.
 */
$numericScript = <<<'JS'
function () {
    var selectors = __SELECTORS__;

    function isolated(el) {
        var style = window.getComputedStyle(el);
        if (style.direction === 'ltr') { return true; }

        var bidi = (style.unicodeBidi || '').toLowerCase();
        if (['isolate', 'isolate-override', 'embed', 'bidi-override', 'plaintext'].indexOf(bidi) !== -1) { return true; }

        var node = el;
        while (node && node.nodeType === 1) {
            if (node.tagName === 'BDI' || (node.getAttribute('dir') || '').toLowerCase() === 'ltr') { return true; }
            node = node.parentElement;
        }

        return /[\u2066-\u2069\u202a-\u202e]/.test(el.textContent || '');
    }

    var found = 0;
    var bad = [];

    selectors.forEach(function (selector) {
        Array.prototype.forEach.call(document.querySelectorAll(selector), function (el) {
            var text = (el.textContent || '').trim();
            if (!/[0-9]/.test(text)) { return; }

            found++;
            if (!isolated(el)) {
                var style = window.getComputedStyle(el);
                bad.push({ selector: selector, text: text.slice(0, 60), direction: style.direction, unicodeBidi: style.unicodeBidi });
            }
        });
    });

    return { count: found, bad: bad };
}
JS;

/** Runs the numeric script for a page and asserts the isolation invariant; when $requireSome, a match is required. */
$assertNumbersIsolated = function ($page, array $selectors, string $label, bool $requireSome) use ($numericScript): void {
    $script = str_replace('__SELECTORS__', json_encode(array_values($selectors)), $numericScript);
    $result = $page->script($script);

    expect($result)->toBeArray();

    if ($requireSome) {
        expect($result['count'])->toBeGreaterThan(0);
    }

    expect($result['bad'])->toBe(
        [],
        $label.' price/number offenders: '.json_encode($result['bad'], JSON_UNESCAPED_UNICODE),
    );
};

it('sets the document language and direction on the pricing page', function (string $locale, int $width) use ($open, $assertDocument) {
    $page = $open($locale, $width, (string) parse_url(route('pricing'), PHP_URL_PATH));

    $assertDocument($page, $locale, 'pricing '.$locale.' @'.$width);
})->with('localeViewports');

it('has no horizontal overflow on the pricing page', function (string $locale, int $width) use ($open, $assertNoOverflow) {
    $page = $open($locale, $width, (string) parse_url(route('pricing'), PHP_URL_PATH));

    $assertNoOverflow($page, 'pricing '.$locale.' @'.$width);
})->with('localeViewports');

it('keeps prices and numbers LTR-isolated on the Arabic pricing page', function (string $locale, int $width) use ($open, $assertNumbersIsolated) {
    $page = $open($locale, $width, (string) parse_url(route('pricing'), PHP_URL_PATH));

    $assertNumbersIsolated(
        $page,
        ['.mk-pricing .pricing-card-b__amount', '.mk-pricing .pc-plan-price'],
        'Arabic pricing page @'.$width,
        true,
    );
})->with('rtlLocaleViewports');

it('has no axe-core WCAG 2.1 AA violations on the pricing page', function (string $locale, int $width) use ($open) {
    $page = $open($locale, $width, (string) parse_url(route('pricing'), PHP_URL_PATH));

    $page->wait(1);
    Axe::assertClean($page, 'pricing '.$locale.' @'.$width);
})->with('localeViewports');

it('sets the document language and direction on the Odoo comparison page', function (string $locale, int $width) use ($open, $assertDocument) {
    $page = $open($locale, $width, (string) parse_url(route('compare.show', 'odoo'), PHP_URL_PATH));

    $assertDocument($page, $locale, 'compare/odoo '.$locale.' @'.$width);
})->with('localeViewports');

it('has no horizontal overflow on the Odoo comparison page', function (string $locale, int $width) use ($open, $assertNoOverflow) {
    $page = $open($locale, $width, (string) parse_url(route('compare.show', 'odoo'), PHP_URL_PATH));

    $assertNoOverflow($page, 'compare/odoo '.$locale.' @'.$width);
})->with('localeViewports');

it('keeps prices and numbers LTR-isolated on the Arabic Odoo comparison page', function (string $locale, int $width) use ($open, $assertNumbersIsolated) {
    $page = $open($locale, $width, (string) parse_url(route('compare.show', 'odoo'), PHP_URL_PATH));

    // The Odoo comparison ships no price rows, so its value cells are checked only when they hold a digit.
    $assertNumbersIsolated($page, ['.cmp-val'], 'Arabic compare/odoo @'.$width, false);
})->with('rtlLocaleViewports');

it('has no axe-core WCAG 2.1 AA violations on the Odoo comparison page', function (string $locale, int $width) use ($open) {
    $page = $open($locale, $width, (string) parse_url(route('compare.show', 'odoo'), PHP_URL_PATH));

    $page->wait(1);
    Axe::assertClean($page, 'compare/odoo '.$locale.' @'.$width);
})->with('localeViewports');

it('reaches the billing-cycle toggle by keyboard and switches the pricing page to yearly', function (string $locale) use ($open) {
    $page = $open($locale, 1280, (string) parse_url(route('pricing'), PHP_URL_PATH));

    expect($page->script('() => document.querySelectorAll(".mk-cycle__btn").length'))->toBeGreaterThan(0);

    $onCycleButton = '() => !!(document.activeElement && document.activeElement.matches(".mk-cycle__btn"))';
    $yearlyPressed = '() => document.querySelector("[data-cycle=yearly]").getAttribute("aria-pressed")';

    // Drive real Tab key events on the page (the plugin's page-level keyboard), not a locator focus.
    $keyboard = $page->page();
    $reached = false;

    for ($i = 0; $i < 40; $i++) {
        $keyboard->keyDown('Tab');
        $keyboard->keyUp('Tab');

        if ($page->script($onCycleButton) === true) {
            $reached = true;
            break;
        }
    }

    expect($reached)->toBeTrue('keyboard Tab reaches the .mk-cycle__btn billing toggle');

    expect($page->script($yearlyPressed))->toBe('false');

    // Enter on the yearly button fires the delegated click handler that rewrites aria-pressed.
    $page->keys('[data-cycle="yearly"]', 'Enter');

    expect($page->script($yearlyPressed))->toBe('true');
})->with('locales');
