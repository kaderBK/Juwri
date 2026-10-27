{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Story CTA destination policy — shared by every storefront theme.

  Reported against orbit, velora, crumb and soiree as "the stories CTA opens a
  new page of the same home page instead of navigating to the item or custom
  URL". The themes were not inventing that: every seeded demo story carries a
  cta_url equal to the store's own home page, and each theme's viewer rendered
  it faithfully — with target="_blank" — producing a second tab showing the page
  the visitor was already on. A control that goes nowhere reads as broken.

  All seventeen retained themes ship their own story viewer with their own copy
  of the same three lines, so the decision lives here once and each viewer calls
  in. This partial is included unconditionally by the layout so every theme's
  viewer can reach window.mbStoryCta.

  Themes call it defensively (`if (window.mbStoryCta) …`), so an older cached
  bundle simply keeps its previous behaviour rather than throwing.
--}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    if (window.mbStoryCta) return;

    /* Supported locale codes, from the app's own list. In URL-prefix locale mode
       (hideDefaultLocaleInURL) a non-default locale serves the store under a
       prefix (/ar/slug) while cta_url is stored prefix-less (/slug). Stripping a
       leading locale segment before comparing lets the same-page check see
       through that difference — otherwise a home-page cta_url on a prefixed
       locale reads as a real destination and the dead CTA "opens the same
       home page in a new page". */
    var LOCALES = @json(\Mcamara\LaravelLocalization\Facades\LaravelLocalization::getSupportedLanguagesKeys());
    function stripLocale(path) {
        var m = path.match(/^\/([^\/]+)(\/.*|)$/);
        if (m && LOCALES.indexOf(m[1]) !== -1) return m[2] || '/';
        return path;
    }

    function resolvedDest(url) {
        try { return new URL(url, window.location.href); } catch (e) { return null; }
    }

    /* "Same page" means the visitor would end up exactly where they already are.
       A differing hash still counts as a real destination — an anchor to a
       category or a section is a legitimate story CTA. */
    function isCurrentPage(url) {
        var u = resolvedDest(url);
        if (!u) return false;
        return u.origin === window.location.origin
            && stripLocale(u.pathname).replace(/\/$/, '') === stripLocale(window.location.pathname).replace(/\/$/, '')
            && u.search === window.location.search
            && (u.hash || '') === (window.location.hash || '');
    }

    function isExternal(url) {
        var u = resolvedDest(url);
        return !!u && u.origin !== window.location.origin;
    }

    /* Keep a same-origin destination on the locale the visitor is reading.
       Story CTAs and banner links are stored exactly as the owner typed them,
       which is nearly always prefix-less, and in URL-prefix mode a prefix-less
       path IS the default-locale page. Following one from an English page threw
       the diner into the store's default language, which is what "clicking an
       item changed the language" turned out to be. The server-rendered links go
       through a PHP helper now; this covers the ones themes build in JS. */
    function currentLocalePrefix() {
        var m = window.location.pathname.match(/^\/([^\/]+)(?:\/|$)/);
        return (m && LOCALES.indexOf(m[1]) !== -1) ? m[1] : null;
    }

    function localizeSameOrigin(url) {
        var prefix = currentLocalePrefix();
        if (!prefix) return url;                 // default locale: no prefix to carry
        var u = resolvedDest(url);
        if (!u || u.origin !== window.location.origin) return url;
        var first = u.pathname.match(/^\/([^\/]+)(?:\/|$)/);
        if (first && LOCALES.indexOf(first[1]) !== -1) return url;   // already localized
        return u.origin + '/' + prefix + (u.pathname === '/' ? '' : u.pathname) + u.search + u.hash;
    }

    window.mbStoryCta = {
        isCurrentPage: isCurrentPage,
        isExternal: isExternal,
        localizeSameOrigin: localizeSameOrigin,
        /* Apply the whole policy to an anchor: hide it when it leads nowhere, and
           open a new tab only for a genuinely external destination — spawning a
           tab onto the store's own menu is what made the dead CTA look like a
           bug rather than a no-op. Returns whether the CTA ended up usable. */
        apply: function (anchor, url) {
            if (!anchor) return false;
            if (!url || isCurrentPage(url)) { anchor.hidden = true; return false; }
            anchor.href = localizeSameOrigin(url);
            if (isExternal(url)) {
                anchor.target = '_blank';
                anchor.rel = 'noopener';
            } else {
                anchor.removeAttribute('target');
                anchor.removeAttribute('rel');
            }
            anchor.hidden = false;
            return true;
        },
    };

    /* Promo banners have exactly the same problem, from the same cause. Themes
       render the banner anchor from the stored link_url, falling back to a bare
       "#", and add target="_blank". The demo banners carry either the store's
       own home page (orbit, velora - "the banner CTA opens a new page of the
       same home page") or nothing at all (crave, soiree - "the banner has no
       call to action"). Both produce an element that looks clickable and does
       nothing.

       Rather than edit seventeen blade files, neutralise it here: a banner whose
       destination is missing, "#", or the current page stops pretending to be a
       link. The image stays as content, it just no longer promises navigation.
       A real destination keeps working, and only an external one opens a tab.

       Keep Blade's echo and directive syntax out of this file's comments: Blade
       compiles interpolation inside a script block and inside JS comments
       alike, so a comment quoting theme markup verbatim is evaluated as a real
       expression and 500s every storefront page. And do not write a literal
       script tag here either — CspEnforceTest scans these files with a regex
       and reads one in a comment as an un-nonced inline script. */

    /* Themes name this element differently (`banner` on crave/dough/glow/lime/
       matcha, `promo` on orbit, and so on), and several build it from JS after
       the config loads, so a single pass at DOMContentLoaded would miss them. */
    var BANNER_SELECTOR = 'a.banner, a.promo, a[class*="banner"], a[class*="promo"], [data-banner] a[href]';

    function sanitizeBanners() {
        var anchors = document.querySelectorAll(BANNER_SELECTOR);
        for (var i = 0; i < anchors.length; i++) {
            var a = anchors[i];
            if (a.getAttribute('data-cta-inert')) continue;
            var href = a.getAttribute('href');
            var dead = !href || href === '#' || isCurrentPage(href);
            if (dead) {
                a.removeAttribute('href');
                a.removeAttribute('target');
                a.removeAttribute('rel');
                a.style.cursor = 'default';
                a.setAttribute('data-cta-inert', '1'); // for tests and for styling
            } else if (!isExternal(href)) {
                // A same-origin destination navigates in place.
                a.removeAttribute('target');
                a.removeAttribute('rel');
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sanitizeBanners);
    } else {
        sanitizeBanners();
    }

    /* Catch banners injected later (orbit renders its promo rail from CFG after
       load). Debounced, and the pass itself skips anything already handled. */
    if (window.MutationObserver) {
        var pending = null;
        new MutationObserver(function () {
            if (pending) return;
            pending = setTimeout(function () { pending = null; sanitizeBanners(); }, 120);
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    window.mbStoryCta.sanitizeBanners = sanitizeBanners;
})();
</script>
