<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Core\Models\Language;
use Tests\Browser\Support\Fixtures;

/**
 * P5-37b: the owner "Card terminals" (SoftPOS) screen — route('softpos.index'),
 * view owner.payments.card-terminals — in a real browser, once per locale (en, ar, ur).
 *
 * The browser talks to this same PHP process (pest-plugin-browser's in-process HTTP server),
 * so the request state below is what the rendered page sees. The app picks the owner's language
 * from session('locale'), then the user's saved preferred_locale (Locale middleware), and the
 * Language rows give current_language() the direction the owner layout prints as <html dir="…">.
 * mcamara validates config('app.locale') against its supportedLocales registry, which the app
 * fills from the active languages table (AppServiceProvider::forRequest -> get_active_languages);
 * the test registers the same list so switching to 'ur' is legal. The two SoftPOS
 * providers ship dark, so they are switched on in config for the test. Assertions: the page
 * loads with no JavaScript errors, the RTL locales flip the document direction, nothing
 * overflows horizontally, every visible form control is labelled, the first control reached by
 * keyboard Tab shows a focus ring, and the h1/label text keeps an AA (>= 4.5:1) contrast ratio.
 */

/** Every visible input/select/textarea must have a label[for], a wrapping label or an aria-label. Returns the offenders. */
$labelCheck = <<<'JS'
function () {
    var bad = [];
    Array.prototype.forEach.call(document.querySelectorAll('input, select, textarea'), function (el) {
        if (el.type === 'hidden') {
            return;
        }
        var s = getComputedStyle(el);
        var r = el.getBoundingClientRect();
        if (s.display === 'none' || s.visibility === 'hidden' || (!r.width && !r.height)) {
            return;
        }
        var id = el.getAttribute('id');
        var byFor = id ? document.querySelector('label[for="' + CSS.escape(id) + '"]') : null;
        var wrapping = el.closest('label');
        var aria = el.getAttribute('aria-label') || el.getAttribute('aria-labelledby');
        if (!byFor && !wrapping && !aria) {
            bad.push(el.tagName + (el.name ? '[name=' + el.name + ']' : ''));
        }
    });
    return bad;
}
JS;

/** The element the last keyboard Tab landed on: is it a form control, and does it show an outline or box-shadow? */
$focusCheck = <<<'JS'
function () {
    var el = document.activeElement;
    if (!el || !el.matches('input:not([type=hidden]), select, textarea')) {
        return { isControl: false, hasRing: false };
    }
    var s = getComputedStyle(el);
    var outline = s.outlineStyle !== 'none' && s.outlineWidth !== '0px';
    return { isControl: true, hasRing: outline || s.boxShadow !== 'none' };
}
JS;

/** WCAG contrast (>= 4.5:1) for the h1 and label text inside the main region, backgrounds resolved up the tree. */
$contrastCheck = <<<'JS'
function () {
    function parse(c) {
        var m = /rgba?\(([^)]+)\)/.exec(c);
        if (!m) {
            return null;
        }
        var p = m[1].split(',').map(function (v) { return parseFloat(v); });
        return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    }
    function lum(c) {
        var v = [c.r, c.g, c.b].map(function (x) {
            x = x / 255;
            return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
    }
    function over(fg, bg) {
        return {
            r: fg.r * fg.a + bg.r * (1 - fg.a),
            g: fg.g * fg.a + bg.g * (1 - fg.a),
            b: fg.b * fg.a + bg.b * (1 - fg.a),
        };
    }
    function background(el) {
        var layers = [];
        var node = el;
        while (node && node.nodeType === 1) {
            var c = parse(getComputedStyle(node).backgroundColor);
            if (c && c.a > 0) {
                layers.push(c);
                if (c.a >= 1) {
                    break;
                }
            }
            node = node.parentElement;
        }
        var bg = { r: 255, g: 255, b: 255, a: 1 };
        for (var i = layers.length - 1; i >= 0; i--) {
            bg = over(layers[i], bg);
        }
        return bg;
    }
    function ratio(a, b) {
        var l1 = lum(a), l2 = lum(b);
        var hi = Math.max(l1, l2), lo = Math.min(l1, l2);
        return (hi + 0.05) / (lo + 0.05);
    }
    var min = Infinity, bad = [];
    Array.prototype.forEach.call(document.querySelectorAll('#main h1, #main label'), function (el) {
        var r = el.getBoundingClientRect();
        var s = getComputedStyle(el);
        if (s.display === 'none' || s.visibility === 'hidden' || (!r.width && !r.height)) {
            return;
        }
        var fg = parse(s.color);
        if (!fg) {
            return;
        }
        var bg = background(el);
        if (fg.a < 1) {
            fg = over(fg, bg);
        }
        var cr = ratio(fg, bg);
        min = Math.min(min, cr);
        if (cr < 4.5) {
            bad.push(el.tagName + ' "' + (el.textContent || '').trim().slice(0, 20) + '" ' + cr.toFixed(2));
        }
    });
    return { min: min === Infinity ? 99 : Math.round(min * 100) / 100, bad: bad };
}
JS;

/** Signs an owner in as en, registers the active locales, switches to $locale and opens route('softpos.index'). */
$open = function (string $locale): mixed {
    // The locale rows current_language() reads for lang/dir (en + the two RTL locales under test).
    Language::updateOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
    Language::updateOrCreate(['code' => 'ur'], ['name' => 'Urdu', 'direction' => 'rtl', 'active' => 1]);

    // Providers are dark by default, so switch them on for the form to render.
    config(['softpos.geidea.enabled' => true, 'softpos.edfapay.enabled' => true]);

    // Sign in under a locale the registry already supports (en); never app()->setLocale() the target.
    app()->setLocale('en');

    $owner = Fixtures::owner([
        'username' => 'softpos_'.$locale,
        'email' => 'softpos-'.$locale.'@example.test',
        'preferred_locale' => 'en',
    ]);
    Fixtures::store($owner, 'SoftPOS Bistro');

    $page = visit('/login');
    $page->type('username', 'softpos-'.$locale.'@example.test')
        ->type('password', Fixtures::PASSWORD)
        ->click('form button[type=submit]')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();

    // Register ar/ur only now: with them in the registry before sign-in, login redirects to /en/dashboard (404).
    // mcamara rejects any config('app.locale') absent from its supportedLocales registry. The app
    // builds that registry from the active languages table (AppServiceProvider::forRequest ->
    // get_active_languages), but the DB-backed pass is skipped in the test env, leaving only the
    // config defaults (en, ar) — so setting 'ur' throws UnsupportedLocaleException. Register the
    // same list the app would, then the in-process request accepts ar/ur the way a real install does.
    $supported = get_active_languages();
    config(['laravellocalization.supportedLocales' => $supported]);
    app()->make(\Mcamara\LaravelLocalization\LaravelLocalization::class)->setSupportedLocales($supported);

    // Switch language AFTER login, through the link the UI language picker uses: in session mode
    // lang_url() is the /lang/{code} route, which stores session('locale') for the app.locale
    // middleware to read on the softpos request.
    $page->navigate(parse_url(lang_url($locale), PHP_URL_PATH));

    return $page->navigate(parse_url(route('softpos.index'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();
};

/** The shared accessibility/RTL assertions, run once per locale. */
$assertScreen = function ($page, string $direction) use ($labelCheck, $focusCheck, $contrastCheck): void {
    $page->assertPresent('#main');

    expect($page->script('document.documentElement.getAttribute("dir")'))->toBe($direction);
    expect($page->script('(function () { var h = document.querySelector("#main h1"); return h ? h.textContent.trim() : ""; })()'))->not->toBe('', 'the page title rendered');

    // No horizontal overflow.
    $page->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true);

    // Every visible form control is labelled.
    expect($page->script($labelCheck))->toBe([], 'every visible input/select/textarea has a label');

    // The first form control reached by keyboard Tab shows a focus ring. Tabbing out of #main
    // (tabindex="-1") starts sequential focus at the first control inside the main region.
    $page->keys('#main', 'Tab');
    $focus = $page->script($focusCheck);
    expect($focus['isControl'])->toBeTrue('keyboard Tab reaches the first form control in #main');
    expect($focus['hasRing'])->toBeTrue('the first focused control shows a non-none outline or box-shadow');

    // h1/label text contrast is at least 4.5:1.
    $contrast = $page->script($contrastCheck);
    expect($contrast['bad'])->toBe([], 'h1/label text contrast is at least 4.5:1');
    expect($contrast['min'])->toBeGreaterThanOrEqual(4.5);
};

it('loads the card terminals screen in English (LTR)', function () use ($open, $assertScreen) {
    $assertScreen($open('en'), 'ltr');
});

it('loads the card terminals screen in Arabic (RTL)', function () use ($open, $assertScreen) {
    $assertScreen($open('ar'), 'rtl');
});

it('loads the card terminals screen in Urdu (RTL)', function () use ($open, $assertScreen) {
    $assertScreen($open('ur'), 'rtl');
});
