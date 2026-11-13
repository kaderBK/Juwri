{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- D8 (integrations pack): for an aggregator order, one localized "Ordered via
     <channel>" line under the order number. Renders nothing for a native order
     (channelLabel() is null for pos/online/customer_app/api). Localized through the
     app locale trackOrder() already resolved — the viewer's current language (the
     qc_lang_choice cookie) when set, else the order's placed-in locale for a cold
     SMS/email link. --}}
@php $orderedViaChannel = $order->channelLabel(); @endphp
@if ($orderedViaChannel)
    <p class="ov-ordered-via" data-testid="ordered-via">{{ ___('Ordered via') }} {{ $orderedViaChannel }}</p>
@endif
