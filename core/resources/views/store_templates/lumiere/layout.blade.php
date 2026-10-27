{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- rev/multi-vertical Lane C — lumiere: soft AI-skincare beauty storefront (cosmetic vertical).

     Deliberately does NOT extend store_templates/layout.blade.php — that shared
     layout (and its _partials) is written for the food ordering flow and is
     shared by the 17 "modern" restaurant themes. lumiere is a NEW, non-food
     theme, so — like shelf/folio/pageview before it — it ships its own small
     self-contained layout. This keeps the food themes byte-identical and keeps
     this theme free of hardcoded food vocabulary. --}}
<!DOCTYPE html>
<html lang="{{ get_lang() }}" dir="{{ current_language()->direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, viewport-fit=cover">
    <meta name="robots" content="index,follow">
    <title>{{ $store->title }}</title>
    @if($store->main_image && $store->main_image !== 'default.png')
        <link rel="icon" href="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">
        <link rel="apple-touch-icon" href="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">
    @endif
    {{-- PWA: installable "Add to Home Screen" via the shared per-store manifest. --}}
    <link rel="manifest" href="{{ route('publicView.manifest', $store->slug) }}">
    <meta name="theme-color" content="#A377D2">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $store->title }}">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    {{-- Design tokens call for Poppins (300–700), rounded/soft — see
         handoff/03-cosmetic-lumiere.md. Self-hosted-CDN Google Fonts link,
         same approach the design source used; degrades to the system-ui
         fallback stack (baked into --lm-font below) if the request is
         blocked/offline. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap">
    {{-- Skip-to-content base style belongs in the LAYOUT (the skip link below
         renders on every view), not only in the _styles partial — otherwise
         secondary views like the order-track page render it as a plain visible
         link. Off-screen until keyboard-focused. --}}
    <style nonce="{{ csp_nonce() }}">
        .lm-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:99}
        .lm-skip:focus{inset-inline-start:0}
    </style>
    @stack('style')
    {{-- P3-style CSP-safe broken-image fallback (mature-theme `data-csp-error`
         pattern, see store_templates/layout.blade.php / shelf/layout.blade.php).
         A capture listener in <head> so an image that fails while the body is
         still parsing is still caught. Only the "hide" case is needed —
         lumiere never shows a food-style default.png fallback image. --}}
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
    {{-- Saudi Riyal (U+20C1) @font-face — 'Saudi Riyal' is prepended to --lm-font so the ⃁ sign renders (else tofu). --}}
    @include('store_templates._partials.riyal-font')
</head>
<body class="lumiere-body">
    <a href="#lm-main" class="lm-skip">{{ ___('Skip to content') }}</a>
    @yield('content')
    @include('store_templates._partials.custom-blocks', ['mbcbAnchor' => true])
    @include('store_templates._partials.open-status-recompute')
    {{-- Shared notification centre (rev/notif-center-spec.md): store id for the
         store-scoped keys + the panel/fetch engine. Included here so the theme's
         own bells can drive the same backend the food themes use. --}}
    <script nonce="{{ csp_nonce() }}">window.MB_STORE = @json((string) ($store->id ?? $store->slug ?? 'default'));</script>
    <script src="{{ asset('assets/store_templates/_shared/js/mb-notify.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/mb-notify.js')) }}"></script>
    @stack('script')
@include('store_templates._partials.client-order-key')
{{-- P1-23: "Cookie settings" + the consent banner on every storefront theme. --}}
@include('partials.pharmacy-licence')
@include('store_templates._partials.cookie-settings-link')
</body>
</html>
