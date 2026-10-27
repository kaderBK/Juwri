{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    /* keep the owner's accent in sync with index — --lime falls back to the
       stock lime otherwise, so this page would ignore the store's brand. */
    $brandColor = theme_setting($store->id, 'colors.primary', '#CDF34B');
    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    $formatPrice = new \Juwri\Core\Support\RawPriceFormatter($curSign, $curPos, currency_decimals($postOptions->currency_code ?? null));
    $waNumber = storefront_whatsapp_number($store); /* WA-T2: branch first, then legacy option, then store phone */

    /* Receipt arithmetic — the same subtotal Order::getTotalAttribute() builds, so the
       printed lines sum to the printed Total. */
    $trackSubtotal = 0.0;
    foreach ($order->items as $trackItem) {
        if (!$trackItem->menu) { continue; }
        $unit = $trackItem->variant ? (float) $trackItem->variant->price : (float) $trackItem->menu->price;
        foreach ($trackItem->itemExtras ?? [] as $trackExtra) {
            $unit += (float) ($trackExtra->extra->price ?? 0);
        }
        foreach ((array) $trackItem->options as $trackGroup) {
            foreach ((array) ($trackGroup['selected'] ?? []) as $trackOpt) {
                $unit += (float) ($trackOpt['price'] ?? 0);
            }
        }
        $trackSubtotal += $unit * $trackItem->quantity;
    }
    $trackTax      = \App\Models\Order::computeTax($trackSubtotal, $postOptions);
    $trackTaxLabel = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('Tax');
    if ((string) (@$postOptions->restaurant_tax_type ?: 'percentage') === 'percentage' && (float) (@$postOptions->restaurant_taxx_charge ?: 0) > 0) {
        $trackTaxLabel .= ' ('.rtrim(rtrim(number_format((float) $postOptions->restaurant_taxx_charge, 2, '.', ''), '0'), '.').'%)';
    }
@endphp

@push('style_at_top')
    {{-- LIME-F7 · appearance: resolve the palette BEFORE first paint so there is no
         flash of the wrong theme. 'auto' follows the OS. --}}
    <script nonce="{{ csp_nonce() }}">
        (function () {
            try {
                var m = localStorage.getItem('lime-theme') || 'auto';
                var dark = m === 'dark' || (m === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.setAttribute('data-lime-theme', dark ? 'dark' : 'light');
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
@endpush

@section('content')
<div class="lime" id="lime" style="--lime-brand: {{ \Juwri\Core\Support\HexColor::safe($brandColor, '#CDF34B') }}">
    <main class="views">
        <section class="view is-active" data-view="track">
            <div class="view-scroll">
                <header class="page-head sub">
                    <a class="fab back" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" aria-label="{{ ___('Back to Menu') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>
                    </a>
                    <h1 class="ph-title">{{ ___('Track Order') }}</h1>
                    <span class="order-chip">#{{ $order->id }}</span>
                    @include("store_templates._partials.ordered-via")
                </header>

                {{-- lime signature: the route illustration + contact capsule. It is a
                     stylised progress graphic, not a GPS feed, so it only renders once the
                     order is ACTUALLY out for delivery — it used to draw a bike halfway
                     across town for an order the kitchen had not started. --}}
                @if($order->type === 'delivery' && in_array($order->status, ['ready', 'completed'], true))
                <div class="lm-mapcard" aria-hidden="true">
                    <svg viewBox="0 0 336 232" preserveAspectRatio="xMidYMid slice">
                        <rect class="lm-m-base" width="336" height="232"/>
                        <rect class="lm-m-block" x="12" y="8" width="76" height="48" rx="10"/>
                        <rect class="lm-m-block" x="112" y="8" width="98" height="48" rx="10"/>
                        <rect class="lm-m-block" x="234" y="8" width="56" height="48" rx="10"/>
                        <rect class="lm-m-park" x="112" y="80" width="98" height="48" rx="10"/>
                        <rect class="lm-m-block" x="12" y="80" width="76" height="48" rx="10"/>
                        <rect class="lm-m-block" x="236" y="84" width="88" height="44" rx="10"/>
                        <rect class="lm-m-block" x="12" y="152" width="76" height="42" rx="10"/>
                        <rect class="lm-m-block" x="114" y="152" width="96" height="42" rx="10"/>
                        <rect class="lm-m-block" x="236" y="152" width="88" height="42" rx="10"/>
                        <path class="lm-m-road thin" d="M-10 68H346"/>
                        <path class="lm-m-road" d="M-10 140H346"/>
                        <path class="lm-m-road thin" d="M-10 206H346"/>
                        <path class="lm-m-road thin" d="M100 -10V242"/>
                        <path class="lm-m-road" d="M222 -10V242"/>
                        <path class="lm-m-route" d="M30 206H86Q100 206 100 192V154Q100 140 114 140H208Q222 140 222 126V82Q222 68 236 68H302"/>
                        <g class="lm-m-poi" transform="translate(30 206)"><rect x="-13" y="-13" width="26" height="26" rx="9"/><path class="lm-poi-glyph" d="M-6.5 -2.5 -5 -6h10l1.5 3.5M-5 -2.5v7.5h10v-7.5M-1.6 5v-4h3.2v4"/></g>
                        <g class="lm-m-poi" transform="translate(302 68)"><rect x="-13" y="-13" width="26" height="26" rx="9"/><path class="lm-poi-glyph" d="M-5.5 4.5v-6.5L0 -6.5 5.5 -2v6.5ZM-1.2 4.5v-3.4h2.4v3.4"/></g>
                        <g class="lm-courier" transform="translate(30 206)"><circle class="lm-c-pulse" r="14"/><circle class="lm-c-body" r="12"/><g class="lm-c-bike"><circle cx="-4.2" cy="2.8" r="2.6"/><circle cx="4.6" cy="2.8" r="2.6"/><path d="M-4.2 2.8 -1.6 -2h3M1.4 -2 4.6 2.8M-1.6 -2l-1.6-2.4h-1.6"/></g></g>
                    </svg>
                </div>
                <div class="lm-courier-card">
                    <div class="lm-cc-top">
                        @if((string) (media_url('storage/store/logo/', $store->main_image, $store->id ?? null)) !== '')<img src="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}" alt="" data-csp-error="visibility">@endif
                        <div class="lm-cc-who"><b>{{ $store->title }}</b><small>{{ ___('Out for delivery') }}</small></div>
                        {{-- T3: the "Live" pill means the courier is genuinely in
                             flight — gate it on the real delivery_status
                             (out_for_delivery), not the coarse ready/completed
                             order status. Kept live by the poller below. --}}
                        <span class="lm-cc-live" id="lmCcLive" @if($order->delivery_status !== 'out_for_delivery') hidden @endif><i class="lm-godot"></i>{{ ___('Live') }}</span>
                    </div>
                </div>
                @endif
                @if($store->phone || $waNumber)
                <div class="lm-contact-capsule">
                    @if($store->phone)
                    <a href="tel:{{ $store->phone }}"><span class="lm-ccap-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg></span>{{ ___('Call') }}</a>
                    @endif
                    @if($waNumber)
                    @if($store->phone)<i class="lm-ccap-div"></i>@endif
                    <a href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener"><span class="lm-ccap-ic"><svg class="ic" viewBox="0 0 24 24"><path d="M21 12a8.5 8.5 0 0 1-8.5 8.5c-1.6 0-3-.4-4.3-1.1L3 21l1.6-5.2A8.5 8.5 0 1 1 21 12Z"/></svg></span>{{ ___('Chat') }}</a>
                    @endif
                </div>
                @endif

                <div class="track-card">
                    <div class="track-top">
                        <div><p class="tk-label">{{ ___('Order') }}</p><b>#{{ $order->id }}</b></div>
                        <div class="tk-eta"><p class="tk-label">{{ date_formating($order->created_at, 'h:i A') }}</p><b id="cvStatusWord">…</b></div>
                    </div>

                    <ol class="timeline" id="cvTimeline">
                        <li class="tl" data-step="0">
                            <span class="tl-node"><svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg></span><span class="tl-line"></span>
                            <span class="tl-copy"><b>{{ ___('Order Received') }}</b><small>{{ ___('Your order has been received by the kitchen.') }}</small></span>
                        </li>
                        <li class="tl" data-step="1">
                            <span class="tl-node"><svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg></span><span class="tl-line"></span>
                            <span class="tl-copy"><b>{{ ___('Preparing your Food') }}</b><small>{{ ___('The chef is preparing your meal with care.') }}</small></span>
                        </li>
                        <li class="tl" data-step="2">
                            <span class="tl-node"><svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg></span><span class="tl-line"></span>
                            <span class="tl-copy">
                                <b>@if($order->type === 'delivery'){{ ___('On the Way') }}@elseif($order->type === 'takeaway'){{ ___('Ready for Pickup') }}@else{{ ___('Ready!') }}@endif</b>
                                <small>@if($order->type === 'delivery'){{ ___('Your order is out for delivery.') }}@elseif($order->type === 'takeaway'){{ ___('Your order is packed and ready at the counter.') }}@else{{ ___('Your order is on its way to the table.') }}@endif</small>
                            </span>
                        </li>
                        <li class="tl" data-step="3">
                            <span class="tl-node"><svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg></span>
                            <span class="tl-copy"><b>{{ ___('Order Completed') }}</b><small>{{ ___('Enjoy your meal and thank you!') }}</small></span>
                        </li>
                    </ol>
                    @include('store_templates._partials.order-token')
                    @include('store_templates._partials.prep-eta')
                    @include('store_templates._partials.mk-rating-prompt')
@include('store_templates._partials.guest-account-prompt')
@include('store_templates.shared.payment-pending')
@include('store_templates._partials.track-poll')
@include('store_templates._partials.delivery-substatus')
@include('store_templates._partials.track-notify')
                    <p class="err-box" id="cvCancelled" hidden>{{ ___('This order has been cancelled. Please contact staff for assistance.') }}</p>
                    <p class="minorder" id="cvUnpaid" hidden>⚠ {{ ___('Please complete your online payment to submit this order to the kitchen.') }}</p>
                </div>

        {{-- T5: Curbside "I'm here" arrival ping --}}
        @if(!empty($order->is_curbside))
        <div class="track-card curbside" id="cv-curbside-card">
            <button class="lime-pill curbside-btn" type="button" id="cv-imhere-btn">
                {{ ___("I'm here") }}
            </button>
            <div class="curbside-msg" id="cv-imhere-msg">{{ ___('Staff notified') }}</div>
        </div>
        <script nonce="{{ csp_nonce() }}">
            (function(){
                var btn=document.getElementById('cv-imhere-btn');
                if(!btn) return;
                btn.addEventListener('click', function(){
                    btn.disabled=true;
                    fetch('{{ url('order/track') }}/{{ $order->track_token }}/arrived', {
                        method:'POST',
                        headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content,'Accept':'application/json'}
                    }).then(function(r){return r.json();}).then(function(d){
                        btn.style.display='none';
                        var m=document.getElementById('cv-imhere-msg'); if(m) m.style.display='block';
                    }).catch(function(){ btn.disabled=false; });
                });
            })();
        </script>
        @endif

                <div class="track-card">
                    <div class="track-top"><p class="tk-label">{{ ___('Customer Details') }}</p></div>
                    <div class="order-lines">
                        <div class="order-line"><span>{{ ___('Customer Name') }}</span><b>{{ $order->customer_name }}</b></div>
                        @if($order->phone_number)
                            <div class="order-line"><span>{{ ___('Phone') }}</span><b>{{ $order->phone_number }}</b></div>
                        @endif
                        @if($order->type)
                            <div class="order-line"><span>{{ ___('Order type') }}</span><b>{{ ___(ucfirst(str_replace('-', ' ', $order->type))) }}</b></div>
                        @endif
                    </div>
                </div>

                <div class="track-card">
                    <div class="track-top"><p class="tk-label">{{ ___('Order Summary') }}</p></div>
                    <div class="order-lines">
                        @foreach($order->items as $item)
                            @if($item->menu)
                                @php
                                    $lineUnit = $item->variant ? (float) $item->variant->price : (float) $item->menu->price;
                                    foreach ($item->itemExtras ?? [] as $itemExtra) {
                                        $lineUnit += (float) ($itemExtra->extra->price ?? 0);
                                    }
                                    foreach ((array) $item->options as $lineGroup) {
                                        foreach ((array) ($lineGroup['selected'] ?? []) as $lineOpt) {
                                            $lineUnit += (float) ($lineOpt['price'] ?? 0);
                                        }
                                    }
                                @endphp
                                <div class="order-line">
                                    <span>{{ $item->menu->name }} × {{ $item->quantity }}
                                        @if($item->variation_title)<small>{{ $item->variation_title }}</small>@endif
                                        @if($item->itemExtras && $item->itemExtras->count())
                                            <small>+ {{ \Juwri\Core\Support\ViewHelpers::extraTitles($item->itemExtras) }}</small>
                                        @endif
                                    </span>
                                    <b>{{ $formatPrice($lineUnit * $item->quantity) }}</b>
                                </div>
                            @endif
                        @endforeach
                        {{-- TOTALS CONTRACT: the receipt must itemise every line the customer is
                             charged for, and those lines must sum to Total in front of them.
                             Subtotal and Tax were both missing, so the printed arithmetic was
                             short by the tax on every order. --}}
                        <div class="order-line"><span>{{ ___('Subtotal') }}</span><b>{{ $formatPrice($trackSubtotal) }}</b></div>
                        @if($order->deliveryCharge($postOptions) > 0)
                            <div class="order-line"><span>{{ ___('Delivery Charge') }}</span><b>{{ $formatPrice($order->deliveryCharge($postOptions)) }}</b></div>
                        @endif
                        @if($trackTax > 0)
                            <div class="order-line"><span>{{ $trackTaxLabel }}</span><b>{{ $formatPrice($trackTax) }}</b></div>
                        @endif
                        @if($order->tip_amount > 0)
                            <div class="order-line"><span>{{ ___('Tip') }}</span><b>{{ $formatPrice($order->tip_amount) }}</b></div>
                        @endif
                        @if($order->discount_amount > 0)
                            <div class="order-line disc"><span>{{ ___('Discount') }}</span><b>−{{ $formatPrice($order->discount_amount) }}</b></div>
                        @endif
                        <div class="order-line total">
                            <span>{{ ___('Total') }}</span>
                            <b>{{ $formatPrice($order->price) }}</b>
                        </div>
                    </div>
                </div>

                @if($waNumber)
                <a class="wa-line" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
                    <span>{{ ___('Questions? Chat with us on WhatsApp') }}</span>
                    <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                </a>
                @endif

                <div class="trk-foot">
                    <a class="ghostbtn" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}">{{ ___('Back to Menu') }}</a>
                </div>
                <div class="tail-space tall"></div>
            </div>
        </section>
    </main>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
(function () {
    "use strict";
    var STEP = { unpaid: -1, new: 0, pending_approval: 0, pending: 0, preparing: 1, ready: 2, completed: 3, delivered: 3, call_bill: 2, cancelled: -2 };
    var word = {
        unpaid: @json(___('Awaiting Payment')),
        new: @json(___('Received')),
        pending: @json(___('Received')),
        preparing: @json(___('Preparing')),
        ready: @json($order->type === 'delivery' ? ___('On the Way') : ___('Ready')),
        completed: @json(___('Completed')),
        pending_approval: @json(___('Awaiting approval')),
        delivered: @json(___('Delivered')),
        call_bill: @json(___('Bill requested')),
        cancelled: @json(___('Cancelled')),
    };
    function updateUI(status) {
        var step = STEP[status]; if (step === undefined) step = 0;
        document.getElementById('cvStatusWord').textContent = word[status] || status;
        document.getElementById('cvCancelled').hidden = status !== 'cancelled';
        document.getElementById('cvUnpaid').hidden = status !== 'unpaid';
        var rows = document.querySelectorAll('#cvTimeline .tl');
        rows.forEach(function (row, i) {
            row.classList.remove('done', 'now', 'cancelled');
            if (status === 'cancelled') { if (i === 0) row.classList.add('cancelled'); return; }
            if (step < 0) return;
            if (i < step) row.classList.add('done');
            else if (i === step) row.classList.add(step === 3 ? 'done' : 'now');
        });
    }
    updateUI(@json($order->status));
    // T2: register with the shared single poller (visibility-gated,
    // terminal-stop, live prep-eta + board token) instead of a per-theme 5s interval.
    window.mbTrackPoll(function (res) {
        if (res && res.status) { updateUI(res.status); }
        // T3: the "Live" pill tracks the real courier state, not the order status.
        var live = document.getElementById('lmCcLive');
        if (live && res) { live.hidden = res.delivery_status !== 'out_for_delivery'; }
    });
})();
</script>
@endpush
