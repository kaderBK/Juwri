{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  P1-G (locales) — one working language switch for every storefront theme.

  The site runs mcamara in PREFIX mode (hideDefaultLocaleInURL, so "/" is the
  default locale and "/en/slug" is explicit). RememberLocale resolves the UI
  locale from the URL segment on every request.

  Ten of the seventeen retained themes switched language by writing the
  `Quick_user_lang_code` cookie and calling location.reload(). Under prefix mode
  that cannot work: the reload keeps the existing prefix, RememberLocale reads
  the prefix, and the UI comes back in the SAME language the guest just tried to
  leave. Menu item names changed (the Menu accessors read the cookie) while every
  label around them did not — which is exactly the "language switch is not
  working" the storefront reports describe.

  This partial owns the behaviour centrally: it listens in the CAPTURE phase, so
  it runs before each theme's own delegated handler, sets the cookie (menu
  content still resolves through it) AND rewrites the URL locale segment (which
  is what actually moves the UI), then stops the event so the theme's
  cookie-and-reload path never runs.

  Themes that already rewrite the URL themselves (orbit, velora) are unaffected
  in behaviour — this simply gets there first and does the same thing.
--}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    var SUPPORTED = @json(array_keys(config('laravellocalization.supportedLocales', [])));
    /* Prefix mode (include_language_code ON): the URL segment is the authority,
       so a missing segment must be INSERTED. Session mode: there is no localized
       route to insert into — inserting 404s; the cookie + a plain reload is the
       whole switch (app.locale applies it). */
    var PREFIX_MODE = @json((bool) config('settings.include_language_code'));

    function setCookie(name, value) {
        try {
            document.cookie = name + '=' + encodeURIComponent(value) + ';path=/;max-age=31536000;samesite=lax';
        } catch (e) {}
    }

    /* Swap (or insert) the locale segment, preserving path, query and hash. A
       first segment only counts as a locale when the app actually supports it —
       otherwise "/menu/..." would have "menu" overwritten. */
    function localizedUrl(code) {
        var url = new URL(window.location.href);
        var parts = url.pathname.split('/');           // ['', 'en', 'slug', …]
        if (parts.length > 1 && SUPPORTED.indexOf((parts[1] || '').toLowerCase()) !== -1) {
            parts[1] = code;
            url.pathname = parts.join('/');
        } else if (PREFIX_MODE) {
            parts.splice(1, 0, code);
            url.pathname = parts.join('/');
        }
        /* else: session mode with no segment — reload same URL; cookie carries it. */
        url.searchParams.delete('lang');               // legacy query switch, now redundant
        return url.toString();
    }

    document.addEventListener('click', function (e) {
        var el = e.target && e.target.closest ? e.target.closest('[data-lang-code]') : null;
        if (!el) return;

        var code = (el.getAttribute('data-lang-code') || '').toLowerCase();
        if (!code) return;

        var name = el.getAttribute('data-lang-name') || '';
        // Menu item/category names are translated off these cookies, so they must
        // still be written — the URL alone only moves the interface locale.
        setCookie('Quick_user_lang_code', code);
        // AL-11: record the explicit choice so the auto-locale suggest banner
        // (locale-suggest.blade.php) never nags a visitor who has already picked —
        // covers both the theme picker and the banner CTA (both dispatch here).
        setCookie('qc_lang_choice', code);
        if (name) {
            setCookie('Quick_lang', name);
            setCookie('Quick_user_lang', name);
        }

        // Win over the theme's own handler (which would cookie-and-reload).
        e.preventDefault();
        e.stopPropagation();
        if (e.stopImmediatePropagation) e.stopImmediatePropagation();

        window.location.assign(localizedUrl(code));
    }, true); // capture
})();
</script>
