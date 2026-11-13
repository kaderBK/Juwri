{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    P1-23 cookie banner (marketing site, legal pages, storefronts, dashboard).

    Strictly necessary cookies only until the visitor chooses. Every analytics / marketing script on the page is
    rendered inert — <script type="text/plain" data-cookie-consent="analytics|marketing" [data-src="…"]> — and this
    script starts the allowed ones after consent (the nonce is copied, so it stays valid under the CSP). "Essential
    only" is as easy as "Accept analytics" (same button, same step); "Cookie settings" opens the essential /
    analytics / marketing toggles. The choice is the plain cookie site_consent=v1.a<0|1>.m<0|1> for 6 months
    (Juwri\Core\Legal\CookieConsent reads it server-side). Any element with [data-cookie-settings] (footer link)
    re-opens the banner; turning a category off removes its first-party cookies and reloads. The toggles and the
    cookies removed per category come from the cookie registry (Juwri\Core\Legal\CookieRegistry), the same list
    the /cookies page prints (linked from the settings).

    Shown on its own when the page carries a consent-gated script, when the platform's "cookie consent box" setting
    is on, or when $cookieConsentForce is true (the marketing / legal pages of an instance publishing the legal pages).
    Cache-safe: nothing here depends on the request's cookie. Rendered once per request (a storefront theme's
    "Cookie settings" link partial includes it too).
--}}
@php
    $__ckForce = ! empty($cookieConsentForce) || (($__ckSetting = config('settings.cookie_consent')) && $__ckSetting != '0');
    $__ckLink = config('settings.cookie_link');
@endphp
@unless (\Juwri\Core\Support\OwnerApp::inApp() || request()->attributes->get('juwri.cookie_consent_rendered'))
@php(request()->attributes->set('juwri.cookie_consent_rendered', true))
<style nonce="{{ csp_nonce() }}">
    .ckc{box-sizing:border-box;z-index:1080;position:fixed;inset-block-end:16px;inset-inline-start:16px;inline-size:380px;max-inline-size:calc(100% - 32px);padding:18px 20px;border-radius:12px;background:#fff;color:#111;border:1px solid #e4e4e4;box-shadow:0 6px 24px rgba(0,0,0,.14);font-size:.9rem;line-height:1.5}
    .ckc[hidden]{display:none}
    .ckc h2{margin:0 0 6px;font-size:1.05rem;color:inherit}
    .ckc p{margin:0 0 12px}
    .ckc a{color:inherit;text-decoration:underline}
    .ckc__opts{display:grid;gap:8px;margin:0 0 12px;padding:0;border:0}
    .ckc__opts[hidden]{display:none}
    .ckc__sr{position:absolute;inline-size:1px;block-size:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
    .ckc__opts label{display:flex;gap:8px;align-items:flex-start}
    .ckc__opts a{font-size:.85rem}
    .ckc__btns{display:flex;flex-wrap:wrap;gap:8px}
    .ckc__btn{flex:1 1 auto;min-block-size:40px;padding:8px 14px;border-radius:8px;border:1px solid #111;background:#111;color:#fff;font:inherit;font-weight:600;cursor:pointer}
    .ckc__btn--link{flex:1 1 100%;border-color:transparent;background:transparent;color:inherit;text-decoration:underline;font-weight:500}
    .ckc__btn[hidden]{display:none}
    .ckc__btn:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
    :root[data-theme="dark"] .ckc{background:#1e1826;color:#f2eefa;border-color:rgba(255,255,255,.12)}
    :root[data-theme="dark"] .ckc__btn:not(.ckc__btn--link){background:#f2eefa;color:#1e1826;border-color:#f2eefa}
    @media (prefers-color-scheme:dark){
        :root:not([data-theme="light"]) .ckc{background:#1e1826;color:#f2eefa;border-color:rgba(255,255,255,.12)}
        :root:not([data-theme="light"]) .ckc__btn:not(.ckc__btn--link){background:#f2eefa;color:#1e1826;border-color:#f2eefa}
    }
    @media (max-width:600px){.ckc{inset-block-end:0;inset-inline-start:0;inline-size:100%;max-inline-size:100%;border-radius:12px 12px 0 0}}
</style>
<div class="ckc" id="siteCookieConsent" role="dialog" aria-labelledby="ckc-title" data-force="{{ $__ckForce ? '1' : '0' }}" hidden>
    <h2 id="ckc-title">{{ __('site.cookie.title') }}</h2>
    <p>{{ \Juwri\Core\Legal\LegalText::inline('site.cookie.body', ['brand' => brand_title()]) }}@if (!empty($__ckLink)) <a href="{{ $__ckLink }}">{{ ___('Cookie Policy') }}</a>@endif</p>
    <fieldset class="ckc__opts" data-ckc-opts hidden>
        <legend class="ckc__sr">{{ __('site.cookie.settings') }}</legend>
        <label><input type="checkbox" checked disabled> <span>{{ __('site.cookie.settings.essential') }}</span></label>
        @foreach (\Juwri\Core\Legal\CookieRegistry::optionalCategories() as $__ckCat)
        <label><input type="checkbox" data-ckc-cat="{{ $__ckCat }}"> <span>{{ __('site.cookie.settings.'.$__ckCat) }}</span></label>
        @endforeach
        @if (Route::has('legal.cookies'))
        <a href="{{ route('legal.cookies') }}" data-ckc-list>{{ __('cookies.list_link') }}</a>
        @endif
    </fieldset>
    <div class="ckc__btns">
        <button type="button" class="ckc__btn" data-ckc="reject">{{ __('site.cookie.reject') }}</button>
        <button type="button" class="ckc__btn" data-ckc="accept">{{ __('site.cookie.accept') }}</button>
        <button type="button" class="ckc__btn" data-ckc="save" hidden>{{ __('site.cookie.save') }}</button>
        <button type="button" class="ckc__btn ckc__btn--link" data-ckc="settings">{{ __('site.cookie.settings') }}</button>
    </div>
</div>
<script nonce="{{ csp_nonce() }}">
(function () {
    if (window.siteConsent) { return; }
    var NAME = @json(\Juwri\Core\Legal\CookieConsent::COOKIE), DAYS = {{ \Juwri\Core\Legal\CookieConsent::DAYS }};
    var DROP = {}, PATTERNS = @json(\Juwri\Core\Legal\CookieRegistry::dropPatterns());
    Object.keys(PATTERNS).forEach(function (cat) { DROP[cat] = new RegExp(PATTERNS[cat]); });
    var box = document.getElementById('siteCookieConsent');
    function read() {
        var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + NAME + '=v1\\.a([01])\\.m([01])'));
        return m ? { analytics: m[1] === '1', marketing: m[2] === '1' } : null;
    }
    function write(c) {
        document.cookie = NAME + '=v1.a' + (c.analytics ? 1 : 0) + '.m' + (c.marketing ? 1 : 0)
            + '; Max-Age=' + (DAYS * 86400) + '; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    }
    function activate(c) {
        var list = document.querySelectorAll('script[type="text/plain"][data-cookie-consent]');
        Array.prototype.forEach.call(list, function (old) {
            if (!c[old.getAttribute('data-cookie-consent')]) { return; }
            var s = document.createElement('script');
            if (old.nonce) { s.nonce = old.nonce; s.setAttribute('nonce', old.nonce); }
            var src = old.getAttribute('data-src');
            if (src) { s.src = src; s.async = true; } else { s.text = old.text; }
            old.parentNode.replaceChild(s, old);
        });
    }
    function drop(re) {
        var parts = location.hostname.split('.'), domains = [''];
        for (var i = 0; i < parts.length - 1; i++) { domains.push('; Domain=' + parts.slice(i).join('.')); }
        document.cookie.split(';').forEach(function (part) {
            var name = part.split('=')[0].trim();
            if (!name || !re.test(name)) { return; }
            domains.forEach(function (d) { document.cookie = name + '=; Max-Age=0; Path=/' + d; });
        });
    }
    function settings(show) {
        if (!box) { return; }
        var c = read() || { analytics: false, marketing: false };
        box.querySelector('[data-ckc-opts]').hidden = !show;
        box.querySelector('[data-ckc="save"]').hidden = !show;
        box.querySelector('[data-ckc="settings"]').hidden = show;
        Array.prototype.forEach.call(box.querySelectorAll('[data-ckc-cat]'), function (el) { el.checked = !!c[el.getAttribute('data-ckc-cat')]; });
    }
    function open(withSettings) {
        if (!box) { return; }
        settings(!!withSettings);
        box.hidden = false;
        var first = box.querySelector('[data-ckc="reject"]');
        if (first) { first.focus(); }
    }
    function decide(c) {
        var before = read();
        write(c);
        if (box) { box.hidden = true; }
        var revoked = before && ((before.analytics && !c.analytics) || (before.marketing && !c.marketing));
        Object.keys(DROP).forEach(function (cat) { if (!c[cat]) { drop(DROP[cat]); } });
        if (revoked) { location.reload(); return; }
        activate(c);
    }
    if (box) {
        box.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-ckc]') : null;
            if (!b) { return; }
            var act = b.getAttribute('data-ckc');
            if (act === 'reject') { decide({ analytics: false, marketing: false }); }
            else if (act === 'accept') { decide({ analytics: true, marketing: false }); }
            else if (act === 'settings') { settings(true); }
            else if (act === 'save') {
                var c = { analytics: false, marketing: false };
                Array.prototype.forEach.call(box.querySelectorAll('[data-ckc-cat]'), function (el) { c[el.getAttribute('data-ckc-cat')] = el.checked; });
                decide(c);
            }
        });
    }
    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('[data-cookie-settings]') : null;
        if (!link) { return; }
        e.preventDefault();
        open(true);
    });
    window.siteConsent = { open: open, get: read };
    function boot() {
        var c = read();
        if (c) {
            // A withdrawn category's cookies must not outlive the choice: a running tag (GA writes its session cookie
            // again on page hide) can set one right after decide() removed it, so every load removes them again.
            Object.keys(DROP).forEach(function (cat) { if (!c[cat]) { drop(DROP[cat]); } });
            activate(c);
            return;
        }
        if (box && (box.getAttribute('data-force') === '1' || document.querySelector('script[type="text/plain"][data-cookie-consent]'))) { open(false); }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
</script>
@endunless
