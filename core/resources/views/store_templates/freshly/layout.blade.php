{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- rev/multi-vertical — freshly: calm mint service-booking storefront (laundry
     & dry cleaning). Self-contained, like bookla/shelf/pageview/folio — does
     NOT extend store_templates/layout.blade.php (food-flow shared layout, 17
     modern themes only). Cloned from bookla/layout.blade.php (the SERVICE
     family's own layout), keeping this theme free of hardcoded food/menu
     vocabulary and the food themes byte-identical. --}}
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
    {{-- Manrope — the design's signature font. Storefront CSP already allows
         fonts.googleapis.com (STYLE) + fonts.gstatic.com (FONT), see
         App\Support\Csp\StorefrontPolicy — same pattern the shared modern-
         theme layout.blade.php uses for its own Google Font. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    {{-- PWA: installable "Add to Home Screen" via the shared per-store manifest. --}}
    <link rel="manifest" href="{{ route('publicView.manifest', $store->slug) }}">
    @if($store->main_image && $store->main_image !== 'default.png')<link rel="apple-touch-icon" href="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">@endif
    <meta name="theme-color" content="#0f9e8e">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $store->title }}">
    {{-- Skip-to-content base style belongs in the LAYOUT (the skip link below
         renders on every view), not only in index's @push('style') — otherwise
         secondary views like the order-track page render it as a plain visible
         link. Off-screen until keyboard-focused. --}}
    <style nonce="{{ csp_nonce() }}">
        .frs-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:50}
        .frs-skip:focus{inset-inline-start:0}
    </style>
    @stack('style')
    {{-- Saudi Riyal (U+20C1): always-on @font-face for the riyal glyph, scoped by
         unicode-range to that one codepoint. 'Saudi Riyal' is prepended to
         --frs-font so the ⃁ sign draws wherever a price renders (no effect on
         any other text); without it U+20C1 shows as a tofu box. --}}
    @include('store_templates._partials.riyal-font')
    {{-- P3: CSP-safe broken-image fallback (mature-theme `data-csp-error`
         pattern, see store_templates/layout.blade.php). A capture listener in
         <head> so an image that fails while the body is still parsing is
         still caught. The paired `load` handler is REQUIRED, not optional: the
         story viewer and item images ship with src="" and get a real src at
         runtime — the empty src fires `error` and hides the img, so without a
         `load` handler to un-hide it once the real image arrives, it stays
         display:none (a black story screen). --}}
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
</head>
<body class="frs-body">
    <a href="#frs-main" class="frs-skip">{{ ___('Skip to content') }}</a>
    @yield('content')
    @include('store_templates._partials.custom-blocks', ['mbcbAnchor' => true])
    @include('store_templates._partials.open-status-recompute')
    <script nonce="{{ csp_nonce() }}">window.MB_STORE = @json((string) ($store->id ?? $store->slug ?? 'default'));</script>
    <script src="{{ asset('assets/store_templates/_shared/js/mb-notify.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/mb-notify.js')) }}"></script>
    {{-- P3-13: totals from the server quote (THEME-CONTRACT §5.2). --}}
    <script src="{{ asset('assets/store_templates/_shared/js/mb-quote.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/mb-quote.js')) }}"></script>
    @stack('script')
@include('store_templates._partials.client-order-key')
{{-- P1-23: "Cookie settings" + the consent banner on every storefront theme. --}}
@include('partials.pharmacy-licence')
@include('store_templates._partials.cookie-settings-link')
</body>
</html>
