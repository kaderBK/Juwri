{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-03: the storefront core JS (assets/storefront/*.js) for the kit themes. One include per kit layout, at the end of
     <body>. Prints the page's boot data (a JSON block the browser never executes, so the CSP is untouched) and loads the
     core as an ES module: cart on the server quote, UUIDv7 submit, announcer, sheets, kiosk idle, per-store service
     worker, offline queue. Themes add only `js/theme.js` (<= 20 KB gzip) and the `data-juwri-*` hooks documented in
     assets/storefront/dom.js. The legacy themes do not include this and keep their own scripts. --}}
@php
    $__sfBoot = app(\Juwri\Modules\Storefront\Services\Storefront\StorefrontBoot::class)->forStore($store, request()->query('table'));
    $__sfVersion = @filemtime(public_path('assets/storefront/core.js'));
@endphp
<link rel="stylesheet" href="{{ asset('assets/storefront/core.css') }}?v={{ $__sfVersion }}">
<script type="application/json" data-juwri-boot nonce="{{ csp_nonce() }}">@json($__sfBoot)</script>
<script type="module" src="{{ asset('assets/storefront/core.js') }}?v={{ $__sfVersion }}" nonce="{{ csp_nonce() }}"></script>
