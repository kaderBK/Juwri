{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    $formatPrice = new \Juwri\Core\Support\RawPriceFormatter($curSign, $curPos, currency_decimals($postOptions->currency_code ?? null));
    $waNumber = storefront_whatsapp_number($store); /* WA-T2: branch first, then legacy option, then store phone */
    /* ORBIT-F13 — the storefront tints itself with the restaurant's brand colour;
       this page omitted the custom property, so 50 rules keyed off var(--orange)
       silently reverted to stock orange the moment a customer left the menu. */
    $brandColor = theme_setting($store->id, 'colors.primary', '#f4633c');
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
@endpush

@section('content')
<div class="orbit" id="orbit" style="--theme-color-1: {{ \Juwri\Core\Support\HexColor::safe($brandColor, '#f4633c') }}">
<script nonce="{{ csp_nonce() }}">try{var m=localStorage.getItem("orbit-theme-"+@json($store->slug));if(m==="dark"||m==="light")document.currentScript.parentNode.setAttribute("data-theme",m);}catch(e){}</script>
    <main class="views">
        <section class="view is-active" data-view="track">
            <div class="view-scroll">
                <header class="page-head sub">
                    <a class="fab back inline" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" aria-label="{{ ___('Back to Menu') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>
                    </a>
                    <h1>{{ ___('Track Order') }}</h1>
                </header>

                <div class="track-flow">
                <div class="track-card">
                    <div class="track-top">
                        <div><p class="tk-label">{{ ___('Order') }}</p><b>#{{ $order->id }}</b></div>
                        @include("store_templates._partials.ordered-via")
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
                <div class="track-card" id="cv-curbside-card">
                    <button type="button" id="cv-imhere-btn" class="cta wide">{{ ___("I'm here") }}</button>
                    <div id="cv-imhere-msg" class="imhere-msg">{{ ___('Staff notified') }}</div>
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
                    <div><p class="tk-label">{{ ___('Customer Details') }}</p></div>
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
                    <div><p class="tk-label">{{ ___('Order Summary') }}</p></div>
                    @php $summarySubtotal = 0; @endphp
                    <div class="order-lines">
                        @foreach($order->items as $item)
                            @if($item->menu)
                                @php
                                    $lineUnit = $item->variant ? (float) $item->variant->price : (float) $item->menu->price;
                                    foreach ($item->itemExtras ?? [] as $itemExtra) {
                                        $lineUnit += (float) ($itemExtra->extra->price ?? 0);
                                    }
                                    /* snapshotted choice-group prices ride the line too (Order::getTotalAttribute) */
                                    foreach ((array) $item->options as $optGroup) {
                                        foreach ((array) ($optGroup['selected'] ?? []) as $opt) {
                                            $lineUnit += (float) ($opt['price'] ?? 0);
                                        }
                                    }
                                    $summarySubtotal += $lineUnit * $item->quantity;
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
                        @php
                            /* ORBIT-F11 — the summary listed items + delivery + tip − discount
                               and then printed a Total that was 5% bigger, because the TAX line
                               was simply missing: the receipt visibly did not add up. Same
                               authority the charge used (Order::computeTax on the UNDISCOUNTED
                               subtotal), so the arithmetic on screen closes exactly. */
                            $summaryTax  = \App\Models\Order::computeTax((float) $summarySubtotal, $postOptions);
                            $summaryTray = \App\Models\Order::trayCharge($order->type, (float) $summarySubtotal, $order->store_id);
                            $summaryTaxLabel = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('Tax');
                        @endphp
                        @if($order->discount_amount > 0)
                            <div class="order-line disc"><span>{{ ___('Discount') }}</span><b>−{{ $formatPrice($order->discount_amount) }}</b></div>
                        @endif
                        @if($summaryTax > 0)
                            <div class="order-line"><span>{{ $summaryTaxLabel }}</span><b>{{ $formatPrice($summaryTax) }}</b></div>
                        @endif
                        @if($summaryTray > 0)
                            <div class="order-line"><span>{{ ___('Service charge') }}</span><b>{{ $formatPrice($summaryTray) }}</b></div>
                        @endif
                        @if($order->deliveryCharge($postOptions) > 0)
                            <div class="order-line"><span>{{ ___('Delivery Charge') }}</span><b>{{ $formatPrice($order->deliveryCharge($postOptions)) }}</b></div>
                        @endif
                        @if($order->tip_amount > 0)
                            <div class="order-line"><span>{{ ___('Tip') }}</span><b>{{ $formatPrice($order->tip_amount) }}</b></div>
                        @endif
                        <div class="order-line grand">
                            <span>{{ ___('Total') }}</span>
                            <b>{{ $formatPrice($order->price ?? $order->total) }}</b>
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

                <div class="track-foot">
                    <a class="ghostbtn" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}">{{ ___('Back to Menu') }}</a>
                </div>
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
    window.mbTrackPoll(function (res) { if (res && res.status) updateUI(res.status); });
})();
</script>
@endpush
