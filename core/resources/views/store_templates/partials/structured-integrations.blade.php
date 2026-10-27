{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P5-1: structured integrations — the SAFE replacement for arbitrary
     head/js custom code. IDs are strictly validated at save time and escaped
     here; the snippets are the official ones with only the ID interpolated,
     and scripts carry the CSP nonce (P0-4). Available to every owner — no
     custom-code grant needed, because nothing arbitrary can pass through. --}}
@php
    $si = store_options($store->id);
    $siGa = $si->integration_ga_id ?? null;
    $siPixel = $si->integration_meta_pixel_id ?? null;
    $siFont = $si->integration_font_family ?? null;
    $siNonce = function_exists('csp_nonce') ? csp_nonce() : null;
@endphp

@if($siFont)
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family={{ urlencode($siFont) }}:wght@400;500;600;700&display=swap">
@endif

@if($siGa)
    @php $siIsGtm = \Illuminate\Support\Str::startsWith(strtoupper((string) $siGa), 'GTM-'); @endphp
    @if($siIsGtm)
        {{-- Google Tag Manager container (GTM- ids). The gtag snippet in the @else
             branch is WRONG for GTM containers — a GTM id loads gtm.js (not
             gtag/js) and ships a no-JS iframe fallback. The controller RULES
             accept GTM- ids, so before this branch a GTM id silently did nothing.
             The inline loader carries the CSP nonce; the ns.html iframe uses
             frame-src (www.googletagmanager.com allowed in StorefrontPolicy). --}}
        {{-- P1-23: a GTM container can load any tag (ads included) → marketing consent. The no-JS iframe
             fallback is gone: it would load without consent. --}}
        <script type="text/plain" data-cookie-consent="marketing" @if($siNonce) nonce="{{ $siNonce }}" @endif>
            (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer',@json($siGa));
        </script>
    @else
        {{-- P1-23: Google Analytics → analytics consent. --}}
        <script type="text/plain" data-cookie-consent="analytics" data-src="https://www.googletagmanager.com/gtag/js?id={{ urlencode($siGa) }}" @if($siNonce) nonce="{{ $siNonce }}" @endif></script>
        <script type="text/plain" data-cookie-consent="analytics" @if($siNonce) nonce="{{ $siNonce }}" @endif>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', @json($siGa));
        </script>
    @endif
@endif

@if($siPixel)
    {{-- P1-23: Meta Pixel → marketing consent; the no-JS image fallback is gone (it would fire without consent). --}}
    <script type="text/plain" data-cookie-consent="marketing" @if($siNonce) nonce="{{ $siNonce }}" @endif>
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
        n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
        document,'script','https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', @json((string) $siPixel));
        fbq('track', 'PageView');
    </script>
@endif
