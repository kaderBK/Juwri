{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- T3: coarse delivery sub-status shown under the "On the Way" step. Delivery
     orders only. The label is a localized STRING keyed off the JSON's
     delivery_status — no driver name/phone/coordinates ever reach the diner
     (that PII is deliberately absent from the public track JSON). Painted live
     by the shared poller (window.mbTrackPoll) and seeded server-side so it is
     never blank on first paint. --}}
@if(isset($order) && $order->type === 'delivery')
@php
    $__dSub = [
        'assigned'         => ___('Driver assigned'),
        'picked_up'        => ___('Driver picked up your order'),
        'out_for_delivery' => ___('On the way to you'),
        'delivered'        => ___('Delivered — enjoy! 🎉'),
        'failed'           => ___('Delivery could not be completed — please contact the store'),
    ];
    $__dCur = ($order->delivery_status && isset($__dSub[$order->delivery_status])) ? $order->delivery_status : null;
@endphp
<p class="delivery-substatus-line" id="deliverySubStatus" dir="auto"
   @class(['is-failed' => $__dCur === 'failed']) @if(!$__dCur) hidden @endif
   style="margin:10px auto 0;font-size:13px;font-weight:600;opacity:.85;text-align:center;"
   aria-live="polite" aria-atomic="true">{{ $__dCur ? $__dSub[$__dCur] : '' }}</p>
<script nonce="{{ csp_nonce() }}">
(function () {
    "use strict";
    var el = document.getElementById('deliverySubStatus');
    if (!el || !window.mbTrackPoll) { return; }
    var LABELS = {
        assigned:         @json(___('Driver assigned')),
        picked_up:        @json(___('Driver picked up your order')),
        out_for_delivery: @json(___('On the way to you')),
        delivered:        @json(___('Delivered — enjoy! 🎉')),
        failed:           @json(___('Delivery could not be completed — please contact the store')),
    };
    window.mbTrackPoll(function (res) {
        var s = res && res.delivery_status;
        var txt = s && LABELS[s];
        el.classList.toggle('is-failed', s === 'failed');
        if (txt) { el.textContent = txt; el.hidden = false; }   // textContent only
        else { el.textContent = ''; el.hidden = true; }
    });
})();
</script>
@endif
