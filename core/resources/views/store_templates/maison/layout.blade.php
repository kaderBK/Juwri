{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- rev/multi-vertical — maison: editorial luxe clothing storefront.

     Mirrors store_templates/shelf/layout.blade.php: deliberately does NOT
     extend the shared store_templates/layout.blade.php (that layout + its
     _partials are written for the food ordering flow and shared by the 17
     "modern" restaurant themes). maison is a NEW, non-food theme — like
     shelf/folio/pageview before it — so it ships its own small
     self-contained layout. This keeps the food themes byte-identical
     (nothing here is ever @include'd by them) and keeps this theme free of
     hardcoded food vocabulary.

     The only addition over shelf/layout.blade.php is the Google Fonts link
     for the Fraunces (display/serif) + Hanken Grotesk (UI/body) pairing the
     design spec calls for — loaded the same way crave's own index.blade.php
     loads its font pairing (preconnect + one combined css2 stylesheet). --}}
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400&family=Hanken+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    {{-- PWA: installable "Add to Home Screen" via the shared per-store manifest. --}}
    <link rel="manifest" href="{{ route('publicView.manifest', $store->slug) }}">
    @if($store->main_image && $store->main_image !== 'default.png')<link rel="apple-touch-icon" href="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">@endif
    <meta name="theme-color" content="#0f0e0c">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $store->title }}">
    {{-- Skip-to-content base style belongs in the LAYOUT (the skip link below
         renders on every view), not only in index's @push('style') — otherwise
         secondary views like the order-track page render it as a plain visible
         link. Off-screen until keyboard-focused. --}}
    <style nonce="{{ csp_nonce() }}">
        .ms-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:100}
        .ms-skip:focus{inset-inline-start:0}
    </style>
    @stack('style')
    {{-- CSP-safe broken-image fallback (mature-theme `data-csp-error` pattern,
         see store_templates/layout.blade.php / shelf/layout.blade.php). A
         capture listener in <head> so an image that fails while the body is
         still parsing is still caught. Only the "hide" case is needed here —
         maison never shows a food-style default.png fallback image. --}}
    <script nonce="{{ csp_nonce() }}">
        document.addEventListener('error', function (event) {
            var el = event.target;
            if (el && el.dataset && el.dataset.cspError === 'hide') { el.style.display = 'none'; }
        }, true);
        // …and RE-SHOW one that later loads a good src (JS-populated images —
        // e.g. the item-sheet main photo — start with an empty src="" that fires
        // a spurious error and stamps display:none; clear it once a real src loads).
        document.addEventListener('load', function (event) {
            var el = event.target;
            if (el && el.tagName === 'IMG' && el.dataset && el.dataset.cspError === 'hide') { el.style.removeProperty('display'); }
        }, true);
    </script>
    {{-- Saudi Riyal (U+20C1) @font-face — 'Saudi Riyal' is prepended to --ms-font-sans so the ⃁ sign renders (else tofu). --}}
    @include('store_templates._partials.riyal-font')
</head>
<body class="ms-body">
    <a href="#ms-main" class="ms-skip">{{ ___('Skip to content') }}</a>
    @yield('content')
    @include('store_templates._partials.custom-blocks', ['mbcbAnchor' => true])
    @include('store_templates._partials.open-status-recompute')
    <script nonce="{{ csp_nonce() }}">window.MB_STORE = @json((string) ($store->id ?? $store->slug ?? 'default'));</script>
    <script src="{{ asset('assets/store_templates/_shared/js/mb-notify.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/mb-notify.js')) }}"></script>
    @stack('script')
@include('store_templates._partials.client-order-key')
{{-- P1-23: "Cookie settings" + the consent banner on every storefront theme. --}}
@include('partials.pharmacy-licence')
@include('store_templates._partials.cookie-settings-link')
</body>
</html>
