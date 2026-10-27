{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- rev/multi-vertical Lane E (torque) — "Torque Garage": flagship car-parts
     storefront. Deliberately does NOT extend store_templates/layout.blade.php —
     that shared layout (and its _partials) is written for the food ordering
     flow and is shared by the 17 "modern" restaurant themes. torque is a NEW,
     non-food theme, so — like shelf/folio/pageview before it — it ships its
     own small self-contained layout. This keeps the food themes byte-identical
     and keeps this theme free of hardcoded food vocabulary.

     Dark-first "carbon instrument" aesthetic: the palette is defined as CSS
     custom properties on :root in index.blade.php/track.blade.php's own
     @push('style') blocks (mirrors shelf's pattern — this shared layout only
     owns the document chrome, never the theme tokens, so a future 2nd torque
     page can redefine/extend the same vars without editing this file). --}}
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
    {{-- Signature fonts: Archivo (display/headings), IBM Plex Sans (body/UI),
         IBM Plex Mono (VIN readout, part numbers, spec sheet) — loaded the
         SAME way crave already loads its own Google Fonts under this
         storefront's CSP (preconnect + a plain stylesheet <link>, no nonce
         needed on a <link>). A robust system-font fallback stack is still
         named first in every font-family declaration below (see index/track
         @push('style')) so text never falls back to a mismatched font if the
         stylesheet is blocked or slow. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800;900&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    {{-- PWA: installable "Add to Home Screen" via the shared per-store manifest. --}}
    <link rel="manifest" href="{{ route('publicView.manifest', $store->slug) }}">
    @if($store->main_image && $store->main_image !== 'default.png')<link rel="apple-touch-icon" href="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">@endif
    <meta name="theme-color" content="#0e0f13">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ $store->title }}">
    {{-- Skip-to-content base style belongs in the LAYOUT (the skip link below
         renders on every view), not only in index's @push('style') — otherwise
         secondary views like the order-track page render it as a plain visible
         link. Off-screen until keyboard-focused. --}}
    <style nonce="{{ csp_nonce() }}">
        .tq-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:50}
        .tq-skip:focus{inset-inline-start:0}
    </style>
    @stack('style')
    {{-- CSP-safe broken-image fallback (mature-theme `data-csp-error` pattern,
         see store_templates/layout.blade.php / shelf/layout.blade.php). A
         capture listener in <head> so an image that fails while the body is
         still parsing is still caught. Only the "hide" case is needed here —
         torque never shows a food-style default.png fallback image. --}}
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
    {{-- Saudi Riyal (U+20C1) @font-face — 'Saudi Riyal' is prepended to --tq-f-sans so the ⃁ sign renders (else tofu). --}}
    @include('store_templates._partials.riyal-font')
</head>
<body class="tq-body">
    <a href="#tq-main" class="tq-skip">{{ ___('Skip to content') }}</a>
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
