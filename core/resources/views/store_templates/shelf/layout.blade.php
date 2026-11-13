{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- rev/multi-vertical Lane C (C1) — shelf: grid retail storefront.

     Deliberately does NOT extend store_templates/layout.blade.php — that shared
     layout (and its _partials) is written for the food ordering flow (see
     KeptTemplates docblock) and is shared by the 17 "modern" restaurant themes.
     shelf is a NEW, non-food theme, so — like folio/pageview before it — it
     ships its own small self-contained layout. This keeps the food themes
     byte-identical (nothing here is ever `@include`d by them) and keeps this
     theme free of hardcoded food vocabulary (C5). --}}
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
    @stack('style')
    {{-- P3: CSP-safe broken-image fallback (mature-theme `data-csp-error` pattern,
         see store_templates/layout.blade.php). A capture listener in <head> so an
         image that fails while the body is still parsing is still caught —
         registering it at the bottom (in @push('script')) would miss a fast 404.
         Only the "hide" case is needed here (shelf never shows a food-style
         default.png fallback image). --}}
    <script nonce="{{ csp_nonce() }}">
        document.addEventListener('error', function (event) {
            var el = event.target;
            if (el && el.dataset && el.dataset.cspError === 'hide') { el.style.display = 'none'; }
        }, true);
    </script>
</head>
<body class="shelf-body">
    <a href="#shelf-main" class="shelf-skip">{{ ___('Skip to content') }}</a>
    @yield('content')
    @include('store_templates._partials.custom-blocks', ['mbcbAnchor' => true])
    @stack('script')
@include('store_templates._partials.client-order-key')
{{-- P1-23: "Cookie settings" + the consent banner on every storefront theme. --}}
@include('partials.pharmacy-licence')
@include('store_templates._partials.cookie-settings-link')
</body>
</html>
