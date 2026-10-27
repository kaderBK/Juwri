{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- rev/multi-vertical Lane C (C1) — zevoa: grocery quick-commerce super-app shell.

     Deliberately does NOT extend store_templates/layout.blade.php — that shared
     layout (and its _partials) is written for the food ordering flow (see
     KeptTemplates docblock) and is shared by the 17 "modern" restaurant themes.
     zevoa is a NEW, non-food theme (retail/grocery), so — like shelf/folio/
     pageview before it — it ships its own small self-contained layout. This
     keeps the food themes byte-identical (nothing here is ever `@include`d by
     them) and keeps this theme free of hardcoded food vocabulary (C5). --}}
<!DOCTYPE html>
<html lang="{{ get_lang() }}" dir="{{ current_language()->direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, viewport-fit=cover">
    <meta name="robots" content="index,follow">
    <title>{{ $store->title }}</title>
    @if($store->main_image && $store->main_image !== 'default.png')
        <link rel="icon" href="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">
    @endif
    {{-- Design ref: Plus Jakarta Sans everywhere (no serif). Preconnect first,
         per Google's own recommended pattern, then a single weight-range request. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
    {{-- PWA: installable "Add to Home Screen" via the shared per-store manifest. --}}
    <link rel="manifest" href="{{ route('publicView.manifest', $store->slug) }}">
    @if($store->main_image && $store->main_image !== 'default.png')<link rel="apple-touch-icon" href="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">@endif
    <meta name="theme-color" content="#12855a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $store->title }}">
    {{-- Skip-to-content base style belongs in the LAYOUT (the skip link below
         renders on every view), not only in index's @push('style') — otherwise
         secondary views like the order-track page render it as a plain visible
         link. Off-screen until keyboard-focused. --}}
    <style nonce="{{ csp_nonce() }}">
        .zv-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:99}
        .zv-skip:focus{inset-inline-start:0}
    </style>
    @stack('style')
    {{-- P3: CSP-safe broken-image fallback (mature-theme `data-csp-error` pattern,
         see store_templates/layout.blade.php / shelf/layout.blade.php). A capture
         listener in <head> so an image that fails while the body is still
         parsing is still caught — registering it at the bottom (in
         @push('script')) would miss a fast 404. Only the "hide" case is
         needed here (zevoa never shows a food-style default.png fallback
         image — grocery cards render a neutral placeholder glyph instead). --}}
    <script nonce="{{ csp_nonce() }}">
        document.addEventListener('error', function (event) {
            var el = event.target;
            if (el && el.dataset && el.dataset.cspError === 'hide') { el.style.display = 'none'; }
        }, true);
        document.addEventListener('load', function (event) {
            var el = event.target;
            if (el && el.tagName === 'IMG' && el.dataset && el.dataset.cspError === 'hide') { el.style.removeProperty('display'); }
        }, true);
    </script>
    {{-- Saudi Riyal (U+20C1) @font-face — 'Saudi Riyal' is prepended to --zv-font so the ⃁ sign renders (else tofu). --}}
    @include('store_templates._partials.riyal-font')
</head>
<body class="zv-body">
    @php
        /* Content translations (MenuCategory/Menu::getNameAttribute) key off the
           `Quick_user_lang_code` cookie, but a store can render in a locale
           (get_lang()) with no cookie yet set — so the UI is Arabic while the
           category/product names stay in their base language. Align the two:
           seed the cookie from the effective locale for THIS request (so the
           accessors translate on first render) and persist it for the next. */
        if (empty($_COOKIE['Quick_user_lang_code'])) {
            if (! headers_sent()) {
                setcookie('Quick_user_lang_code', get_lang(), [
                    'expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax',
                ]);
            }
            // Seed THIS request's cookie so getNameAttribute translates on first
            // render — but never under PHPUnit: the suite shares one process, so
            // mutating the $_COOKIE superglobal leaks the locale into later tests
            // (a translatable model then writes to translations, not its base
            // column). Real requests are isolated per php-fpm worker, so this is
            // safe there; the response cookie above covers the next request.
            if (! app()->runningUnitTests()) {
                $_COOKIE['Quick_user_lang_code'] = get_lang();
            }
        }
    @endphp
    <a href="#zv-main" class="zv-skip">{{ ___('Skip to content') }}</a>
    @yield('content')
    @include('store_templates._partials.custom-blocks', ['mbcbAnchor' => true])
    @include('store_templates._partials.open-status-recompute')
    {{-- Shared notification centre engine (rev/notif-center-spec.md). --}}
    <script nonce="{{ csp_nonce() }}">window.MB_STORE = @json((string) ($store->id ?? $store->slug ?? 'default'));</script>
    <script src="{{ asset('assets/store_templates/_shared/js/mb-notify.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/mb-notify.js')) }}"></script>
    @stack('script')
@include('store_templates._partials.client-order-key')
{{-- P1-23: "Cookie settings" + the consent banner on every storefront theme. --}}
@include('partials.pharmacy-licence')
@include('store_templates._partials.cookie-settings-link')
</body>
</html>
