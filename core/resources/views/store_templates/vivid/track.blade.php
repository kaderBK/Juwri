{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    $formatPrice = new \Juwri\Core\Support\RawPriceFormatter($curSign, $curPos, currency_decimals($postOptions->currency_code ?? null));
    $waNumber = storefront_whatsapp_number($store); /* WA-T2: branch first, then legacy option, then store phone */
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
@endpush

@section('content')
<div class="vd" id="vd">
    <div class="vd-mesh" aria-hidden="true">
        <span class="vd-blob vd-blob-1"></span>
        <span class="vd-blob vd-blob-2"></span>
        <span class="vd-blob vd-blob-3"></span>
    </div>

    <main class="vd-account">
        <header class="vd-account-head">
            <a class="vd-account-back" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" aria-label="{{ ___('Back to Menu') }}">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg>
            </a>
            <h1 class="vd-account-title">{{ ___('Track Order') }}</h1>
        </header>

        <div class="vd-order-card">
            <div class="vd-order-top">
                <div><small class="vd-order-date">{{ ___('Order') }}</small><b class="vd-order-id">#{{ $order->id }}</b></div>
                @include("store_templates._partials.ordered-via")
                <div style="text-align:end"><small class="vd-order-date">{{ date_formating($order->created_at, 'h:i A') }}</small><b class="vd-order-status" id="vdStatusWord">…</b></div>
            </div>

            <ol class="vd-timeline" id="vdTimeline">
                <li class="vd-tl" data-step="0">
                    <span class="vd-tl-node"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 12 5 5 9-10"/></svg></span><span class="vd-tl-line"></span>
                    <span class="vd-tl-copy"><b>{{ ___('Order Received') }}</b><small>{{ ___('Your order has been received by the kitchen.') }}</small></span>
                </li>
                <li class="vd-tl" data-step="1">
                    <span class="vd-tl-node"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 12 5 5 9-10"/></svg></span><span class="vd-tl-line"></span>
                    <span class="vd-tl-copy"><b>{{ ___('Preparing your Food') }}</b><small>{{ ___('The chef is preparing your meal with care.') }}</small></span>
                </li>
                <li class="vd-tl" data-step="2">
                    <span class="vd-tl-node"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 12 5 5 9-10"/></svg></span><span class="vd-tl-line"></span>
                    <span class="vd-tl-copy">
                        <b>@if($order->type === 'delivery'){{ ___('On the Way') }}@elseif($order->type === 'takeaway'){{ ___('Ready for Pickup') }}@else{{ ___('Ready!') }}@endif</b>
                        <small>@if($order->type === 'delivery'){{ ___('Your order is out for delivery.') }}@elseif($order->type === 'takeaway'){{ ___('Your order is packed and ready at the counter.') }}@else{{ ___('Your order is on its way to the table.') }}@endif</small>
                    </span>
                </li>
                <li class="vd-tl" data-step="3">
                    <span class="vd-tl-node"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 12 5 5 9-10"/></svg></span>
                    <span class="vd-tl-copy"><b>{{ ___('Order Completed') }}</b><small>{{ ___('Enjoy your meal and thank you!') }}</small></span>
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
            <p class="vd-track-alert" id="vdCancelled" style="display:none">{{ ___('This order has been cancelled. Please contact staff for assistance.') }}</p>
            <p class="vd-track-alert" id="vdUnpaid" style="display:none">⚠ {{ ___('Please complete your online payment to submit this order to the kitchen.') }}</p>
        </div>

        {{-- T5: Curbside "I'm here" arrival ping --}}
        @if(!empty($order->is_curbside))
        <div class="vd-order-card" id="vd-curbside-card" style="margin-top:14px;text-align:center;">
            <button type="button" id="vd-imhere-btn" style="width:100%;padding:14px;border:none;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;background:#111;color:#fff;">
                {{ ___("I'm here") }}
            </button>
            <div id="vd-imhere-msg" style="display:none;margin-top:10px;font-size:14px;font-weight:600;">{{ ___('Staff notified') }}</div>
        </div>
        <script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
            (function(){
                var btn=document.getElementById('vd-imhere-btn');
                if(!btn) return;
                btn.addEventListener('click', function(){
                    btn.disabled=true;
                    fetch('{{ url('order/track') }}/{{ $order->track_token }}/arrived', {
                        method:'POST',
                        headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content,'Accept':'application/json'}
                    }).then(function(r){return r.json();}).then(function(d){
                        btn.style.display='none';
                        var m=document.getElementById('vd-imhere-msg'); if(m) m.style.display='block';
                    }).catch(function(){ btn.disabled=false; });
                });
            })();
        </script>
        @endif

        <div class="vd-order-card" style="margin-top:14px">
            <small class="vd-order-date" style="display:block;margin-bottom:10px">{{ ___('Customer Details') }}</small>
            <div class="vd-order-lines">
                <div class="vd-order-line" style="display:flex;justify-content:space-between"><span>{{ ___('Customer Name') }}</span><b>{{ $order->customer_name }}</b></div>
                @if($order->phone_number)
                    <div class="vd-order-line" style="display:flex;justify-content:space-between"><span>{{ ___('Phone') }}</span><b>{{ $order->phone_number }}</b></div>
                @endif
                @if($order->type)
                    <div class="vd-order-line" style="display:flex;justify-content:space-between"><span>{{ ___('Order type') }}</span><b>{{ ___(ucfirst(str_replace('-', ' ', $order->type))) }}</b></div>
                @endif
            </div>
        </div>

        <div class="vd-order-card" style="margin-top:14px">
            <small class="vd-order-date" style="display:block;margin-bottom:10px">{{ ___('Order Summary') }}</small>
            <div class="vd-order-lines">
                @foreach($order->items as $item)
                    @if($item->menu)
                        @php
                            $lineUnit = $item->variant ? (float) $item->variant->price : (float) $item->menu->price;
                            foreach ($item->itemExtras ?? [] as $itemExtra) {
                                $lineUnit += (float) ($itemExtra->extra->price ?? 0);
                            }
                        @endphp
                        <div class="vd-order-line" style="display:flex;justify-content:space-between">
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
                @if($order->deliveryCharge($postOptions) > 0)
                    <div class="vd-order-line" style="display:flex;justify-content:space-between"><span>{{ ___('Delivery Charge') }}</span><b>{{ $formatPrice($order->deliveryCharge($postOptions)) }}</b></div>
                @endif
                {{-- TOTALS CONTRACT: the receipt has to itemise every line the customer
                     is charged for. The tax row was missing, so the printed summary's
                     own arithmetic did not add up in front of the diner — the same
                     defect dough and matcha shipped. Computed through the SERVER's
                     Order::computeTax() on the order's own subtotal, so it can never
                     drift from what was billed. --}}
                @php
                    $vdSubtotal = 0.0;
                    foreach ($order->items as $vdIt) {
                        if (!$vdIt->menu) continue;
                        $vdUnit = $vdIt->variant ? (float) $vdIt->variant->price : (float) $vdIt->menu->price;
                        foreach ($vdIt->itemExtras ?? [] as $vdEx) { $vdUnit += (float) ($vdEx->extra->price ?? 0); }
                        $vdSubtotal += $vdUnit * $vdIt->quantity;
                    }
                    $vdTax = \App\Models\Order::computeTax($vdSubtotal, $postOptions);
                @endphp
                @if($vdTax > 0)
                    <div class="vd-order-line" style="display:flex;justify-content:space-between"><span>{{ ___('Tax') }}</span><b>{{ $formatPrice($vdTax) }}</b></div>
                @endif
                @if($order->tip_amount > 0)
                    <div class="vd-order-line" style="display:flex;justify-content:space-between"><span>{{ ___('Tip') }}</span><b>{{ $formatPrice($order->tip_amount) }}</b></div>
                @endif
                @if($order->discount_amount > 0)
                    <div class="vd-order-line" style="display:flex;justify-content:space-between;color:var(--vd-accent)"><span>{{ ___('Discount') }}</span><b>−{{ $formatPrice($order->discount_amount) }}</b></div>
                @endif
                <div class="vd-order-line vd-order-total-line" style="display:flex;justify-content:space-between">
                    <span>{{ ___('Total') }}</span>
                    {{-- `price` is a nullable column that submitOrder does not populate:
                         on order #99605 it is the empty string, so the receipt's Total
                         rendered as a bare currency sign. `total` is the computed
                         attribute the customer is actually charged. --}}
                    <b>{{ $formatPrice($order->total) }}</b>
                </div>
            </div>
        </div>

        @if($waNumber)
        <a class="vd-ghostbtn" style="display:flex;margin-top:14px;justify-content:center" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
            {{ ___('Questions? Chat with us on WhatsApp') }}
        </a>
        @endif

        <div style="display:grid;place-items:center;padding-top:18px">
            <a class="vd-ghostbtn" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}">{{ ___('Back to Menu') }}</a>
        </div>
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
        document.getElementById('vdStatusWord').textContent = word[status] || status;
        document.getElementById('vdCancelled').style.display = status === 'cancelled' ? 'block' : 'none';
        document.getElementById('vdUnpaid').style.display = status === 'unpaid' ? 'block' : 'none';
        var rows = document.querySelectorAll('#vdTimeline .vd-tl');
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
