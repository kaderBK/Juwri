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
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
@endpush

@section('content')
<div class="ember" id="ember" data-slug="{{ $store->slug }}">
    <div class="app">
        <div class="bg" aria-hidden="true"><div class="bg-tint"></div></div>
        <section class="view-scroll">
                <header class="page-head sub">
                    <a class="fab gl inline" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" aria-label="{{ ___('Back to Menu') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>
                    </a>
                    <h1>{{ ___('Track Order') }}</h1>
                </header>

                <div class="track-card panel gl">
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
                    <p class="minorder" id="cvUnpaid" hidden><svg class="ic ic-xs" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 21 19H3Z"/><path d="M12 10v4M12 16.5v.5"/></svg> {{ ___('Please complete your online payment to submit this order to the kitchen.') }}</p>
                </div>

        {{-- T5: Curbside "I'm here" arrival ping --}}
        @if(!empty($order->is_curbside))
        <div class="track-card panel gl" id="cv-curbside-card">
            <button type="button" class="cta grad wide" id="cv-imhere-btn">
                {{ ___("I'm here") }}
            </button>
            <div class="imhere-msg" id="cv-imhere-msg" style="display:none">{{ ___('Staff notified') }}</div>
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

                <div class="track-card panel gl">
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

                <div class="track-card panel gl">
                    <div><p class="tk-label">{{ ___('Order Summary') }}</p></div>
                    <div class="order-lines">
                        @foreach($order->items as $item)
                            @if($item->menu)
                                @php
                                    $lineUnit = $item->variant ? (float) $item->variant->price : (float) $item->menu->price;
                                    foreach ($item->itemExtras ?? [] as $itemExtra) {
                                        $lineUnit += (float) ($itemExtra->extra->price ?? 0);
                                    }
                                    /* Choice-group option prices are part of what the customer is
                                       charged (Order::getTotalAttribute folds them into the subtotal)
                                       but were missing from the printed line, so the receipt's own
                                       arithmetic did not add up in front of the customer. */
                                    foreach ((array) $item->options as $__grp) {
                                        foreach ((array) ($__grp['selected'] ?? []) as $__opt) {
                                            $lineUnit += (float) ($__opt['price'] ?? 0);
                                        }
                                    }
                                    $receiptSubtotal = ($receiptSubtotal ?? 0) + $lineUnit * $item->quantity;
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
                        {{-- The receipt must itemise EVERY line the customer is charged for and
                             those lines must sum to the printed total. It used to omit both the
                             subtotal and the tax, and printed `$order->price` — a column that is
                             empty on these orders — as the grand total. --}}
                        @php
                            $receiptSubtotal = $receiptSubtotal ?? 0;
                            $receiptTax = \App\Models\Order::computeTax((float) $receiptSubtotal, $postOptions);
                            $taxRowLabel = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('Tax');
                        @endphp
                        <div class="order-line sub"><span>{{ ___('Subtotal') }}</span><b>{{ $formatPrice($receiptSubtotal) }}</b></div>
                        @if($order->deliveryCharge($postOptions) > 0)
                            <div class="order-line"><span>{{ ___('Delivery Charge') }}</span><b>{{ $formatPrice($order->deliveryCharge($postOptions)) }}</b></div>
                        @endif
                        @if($receiptTax > 0)
                            <div class="order-line"><span>{{ $taxRowLabel }}</span><b>{{ $formatPrice($receiptTax) }}</b></div>
                        @endif
                        @if($order->tip_amount > 0)
                            <div class="order-line"><span>{{ ___('Tip') }}</span><b>{{ $formatPrice($order->tip_amount) }}</b></div>
                        @endif
                        @if($order->discount_amount > 0)
                            <div class="order-line disc"><span>{{ ___('Discount') }}</span><b>−{{ $formatPrice($order->discount_amount) }}</b></div>
                        @endif
                        <div class="order-line grand">
                            <span>{{ ___('Total') }}</span>
                            {{-- The CHARGED number, from the model accessor. --}}
                            <b>{{ $formatPrice($order->total) }}</b>
                        </div>
                    </div>
                </div>

                @if($waNumber)
                <a class="wa-line gl" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
                    <span>{{ ___('Questions? Chat with us on WhatsApp') }}</span>
                    <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                </a>
                @endif

                <div class="track-foot">
                    <a class="minipill gl" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}">{{ ___('Back to Menu') }}</a>
                </div>
                <div class="tail-space tall"></div>
        </section>
    </div>
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
