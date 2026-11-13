{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- rev/multi-vertical Lane C (C3) — bookla: service-booking storefront.

     Self-contained, like shelf/pageview/folio — does NOT extend
     store_templates/layout.blade.php (food-flow shared layout, 17 modern
     themes only). Keeps this theme free of hardcoded food/menu vocabulary
     (C5) and the food themes byte-identical. --}}
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
    {{-- P3: CSP-safe broken-image fallback (mature-theme `data-csp-error`
         pattern, see store_templates/layout.blade.php). A capture listener in
         <head> so an image that fails while the body is still parsing is
         still caught. Only the "hide" case is needed here. --}}
    <script nonce="{{ csp_nonce() }}">
        document.addEventListener('error', function (event) {
            var el = event.target;
            if (el && el.dataset && el.dataset.cspError === 'hide') { el.style.display = 'none'; }
        }, true);
    </script>
</head>
<body class="bkl-body">
    <a href="#bkl-main" class="bkl-skip">{{ ___('Skip to content') }}</a>
    @yield('content')
    @include('store_templates._partials.custom-blocks', ['mbcbAnchor' => true])
    @stack('script')
@include('store_templates._partials.client-order-key')
{{-- P1-23: "Cookie settings" + the consent banner on every storefront theme. --}}
@include('partials.pharmacy-licence')
@include('store_templates._partials.cookie-settings-link')
</body>
</html>
