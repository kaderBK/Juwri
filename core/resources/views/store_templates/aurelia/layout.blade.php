{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- store_templates/aurelia — a premium hotel super-app storefront (room-stay
     booking, in-room dining, spa & amenities, experiences, charge-to-room folio).

     Self-contained, like shelf/freshly/bookla/pageview/folio — does NOT extend
     store_templates/layout.blade.php (the shared food-flow layout used by the 17
     "modern" restaurant themes). This keeps those themes byte-identical and
     keeps aurelia free of any dependency on their shared script.js/_partials.

     VIEWS ONLY: this theme renders against the platform's existing hotel engine
     (the Hotel module's booking service, folio service and room-type/booking/stay
     models, reached through the Core RoomCharge contract) and the generic goods commerce endpoints (storefront.goods.cart.*,
     storefront.checkout.place) already used by store_templates/shelf. Any endpoint
     that does not exist yet (hotel.rooms.availability / hotel.rooms.book) is
     probed with Route::has() and degrades to a clearly-labelled sample/demo
     experience — see index.blade.php's data-prep block. --}}
<!DOCTYPE html>
<html lang="{{ get_lang() }}" dir="{{ current_language()->direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, viewport-fit=cover">
    <meta name="theme-color" content="#141210">
    <meta name="robots" content="index,follow">
    <title>{{ $store->title }}</title>
    @if($store->main_image && $store->main_image !== 'default.png')
        <link rel="icon" href="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">
    @endif
    {{-- Signature luxury pairing: Cormorant Garamond (display serif, headings)
         + Jost (geometric sans, UI/body). Storefront CSP already allows
         fonts.googleapis.com (STYLE) + fonts.gstatic.com (FONT) — see
         App\Support\Csp\StorefrontPolicy — same pattern freshly's own layout
         uses for its Google Font. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,500&family=Jost:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    {{-- PWA: installable "Add to Home Screen" via the shared per-store manifest. --}}
    <link rel="manifest" href="{{ route('publicView.manifest', $store->slug) }}">
    @if($store->main_image && $store->main_image !== 'default.png')<link rel="apple-touch-icon" href="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">@endif
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $store->title }}">
    {{-- Skip-to-content base style belongs in the LAYOUT (the skip link below
         renders on every view), not only in index's @push('style') — otherwise
         secondary views like the order-track page render it as a plain visible
         link. Off-screen until keyboard-focused. --}}
    <style nonce="{{ csp_nonce() }}">
        .au-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:40}
        .au-skip:focus{inset-inline-start:0}
    </style>
    @stack('style')
    {{-- P3: CSP-safe broken-image fallback (mature-theme `data-csp-error`
         pattern, see store_templates/layout.blade.php). A capture listener in
         <head> so an image that fails while the body is still parsing is
         still caught. Only the "hide" case is needed here. --}}
    <script nonce="{{ csp_nonce() }}">
        document.addEventListener('error', function (event) {
            var el = event.target;
            if (el && el.dataset && el.dataset.cspError === 'hide') { el.style.display = 'none'; }
        }, true);
        // …and RE-SHOW one that later loads a good src. A JS-populated image (the
        // item-sheet main photo) starts with an empty src="" that fires a spurious
        // error and stamps display:none; clear it once a real src actually loads,
        // or the main image stays blank. (Same fix as maison/torque/carely.)
        document.addEventListener('load', function (event) {
            var el = event.target;
            if (el && el.tagName === 'IMG' && el.dataset && el.dataset.cspError === 'hide') { el.style.removeProperty('display'); }
        }, true);
    </script>
    {{-- Saudi Riyal (U+20C1) @font-face — 'Saudi Riyal' is prepended to --au-sans so the ⃁ sign renders (else tofu). --}}
    @include('store_templates._partials.riyal-font')
</head>
<body class="au-body">
    <a href="#aurelia-main" class="au-skip">{{ ___('Skip to content') }}</a>
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
