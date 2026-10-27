{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    #101 Meta Pixel (browser side). Include in the customer menu layout head:
        @include('partials.meta-pixel', ['metaPixelPost' => $store])
    On the order-success view, also pass the order to fire a deduped Purchase:
        @include('partials.meta-pixel', ['metaPixelPost' => $store, 'metaPurchase' => $order])
    The server-side CAPI Purchase (SendMetaCapiEventJob) uses the same event_id
    'order_{id}', so Meta dedupes the two.
--}}
@php($__pixelId = isset($metaPixelPost) ? store_options($metaPixelPost->id, 'meta_pixel_id') : null)
@if($__pixelId)
{{-- P1-23: marketing consent (partials/cookie-consent starts it); no no-JS fallback. --}}
<script type="text/plain" data-cookie-consent="marketing" nonce="{{ csp_nonce() }}">
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
    document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', @json((string) $__pixelId));
    fbq('track', 'PageView');
    @isset($metaPurchase)
    fbq('track', 'Purchase',
        { value: {{ (float) $metaPurchase->total }}, currency: @json((string) (store_options($metaPixelPost->id, 'currency_code') ?: 'USD')) },
        { eventID: 'order_{{ $metaPurchase->id }}' });
    @endisset
</script>
@endif
