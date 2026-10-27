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
    <link href="https://fonts.googleapis.com/css2?family=Mulish:ital,wght@0,200..900;1,200..900&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
@endpush

@section('content')
<div class="soiree" id="soiree">
    <main class="views">
        <section class="view is-active" data-view="track">
            <div class="view-scroll">
                <header class="page-head sub">
                    <a class="fab back inline" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" aria-label="{{ ___('Back to Menu') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>
                    </a>
                    <h1>{{ ___('Track Order') }}</h1>
                </header>

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
                <div class="track-card stacked" id="cv-curbside-card">
                    <button class="cta-band" type="button" id="cv-imhere-btn"><span>{{ ___("I'm here") }}</span></button>
                    <div class="imhere-msg" id="cv-imhere-msg">{{ ___('Staff notified') }}</div>
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

                <div class="track-card stacked">
                    <p class="tk-label">{{ ___('Customer Details') }}</p>
                    <div class="order-lines">
                        <div class="tr"><span>{{ ___('Customer Name') }}</span><b>{{ $order->customer_name }}</b></div>
                        @if($order->phone_number)
                            <div class="tr"><span>{{ ___('Phone') }}</span><b>{{ $order->phone_number }}</b></div>
                        @endif
                        @if($order->type)
                            {{-- F21: the storefront calls a `takeaway` order "Pickup"; echoing
                                 the raw column here made the track page call the same order
                                 "Takeaway". One name per concept, across both screens. --}}
                            @php
                                $otLabel = match ($order->type) {
                                    'takeaway'     => ___('Pickup'),
                                    'on-table'     => ___('Dine-in'),
                                    'delivery'     => ___('Delivery'),
                                    'room_service' => ___('Room service'),
                                    default        => ___(ucfirst(str_replace('-', ' ', $order->type))),
                                };
                            @endphp
                            <div class="tr"><span>{{ ___('Order type') }}</span><b>{{ $otLabel }}</b></div>
                        @endif
                    </div>
                </div>

                <div class="track-card stacked">
                    <p class="tk-label">{{ ___('Order Summary') }}</p>
                    <div class="order-lines">
                        @php
                            /* TOTALS CONTRACT — this receipt has to itemise every line the
                               diner is actually charged for AND visibly add up. It used to
                               omit BOTH the subtotal and the tax row, so on a taxed store
                               the printed lines did not sum to the printed total in front
                               of the customer. Mirrors Order::getTotalAttribute(): choice-
                               group option prices count toward the line, tax is computed on
                               the raw subtotal, and the discount is subtracted last. */
                            $trackSubtotal = 0.0;
                            foreach ($order->items as $it) {
                                if (!$it->menu) { continue; }
                                $u = $it->variant ? (float) $it->variant->price : (float) $it->menu->price;
                                foreach ($it->itemExtras ?? [] as $ix) { $u += (float) ($ix->extra->price ?? 0); }
                                foreach ((array) $it->options as $grp) {
                                    foreach ((array) ($grp['selected'] ?? []) as $opt) { $u += (float) ($opt['price'] ?? 0); }
                                }
                                $trackSubtotal += $u * $it->quantity;
                            }
                            $trackTax      = \App\Models\Order::computeTax($trackSubtotal, $postOptions);
                            $trackTaxLabel = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('Tax');
                        @endphp
                        @foreach($order->items as $item)
                            @if($item->menu)
                                @php
                                    $lineUnit = $item->variant ? (float) $item->variant->price : (float) $item->menu->price;
                                    foreach ($item->itemExtras ?? [] as $itemExtra) {
                                        $lineUnit += (float) ($itemExtra->extra->price ?? 0);
                                    }
                                    foreach ((array) $item->options as $grp) {
                                        foreach ((array) ($grp['selected'] ?? []) as $opt) { $lineUnit += (float) ($opt['price'] ?? 0); }
                                    }
                                @endphp
                                <div class="tr">
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
                        <div class="tr"><span>{{ ___('Subtotal') }}</span><b>{{ $formatPrice($trackSubtotal) }}</b></div>
                        @if($order->deliveryCharge($postOptions) > 0)
                            <div class="tr"><span>{{ ___('Delivery Charge') }}</span><b>{{ $formatPrice($order->deliveryCharge($postOptions)) }}</b></div>
                        @endif
                        @if($trackTax > 0)
                            <div class="tr"><span>{{ $trackTaxLabel }}</span><b>{{ $formatPrice($trackTax) }}</b></div>
                        @endif
                        @if($order->tip_amount > 0)
                            <div class="tr"><span>{{ ___('Tip') }}</span><b>{{ $formatPrice($order->tip_amount) }}</b></div>
                        @endif
                        @if($order->discount_amount > 0)
                            <div class="tr disc"><span>{{ ___('Discount') }}</span><b>−{{ $formatPrice($order->discount_amount) }}</b></div>
                        @endif
                        <div class="tr grand">
                            <span>{{ ___('Total') }}</span>
                            {{-- the charged number, straight off the model accessor --}}
                            <b>{{ $formatPrice($order->total) }}</b>
                        </div>
                    </div>
                </div>

                @if($waNumber)
                <a class="wa-line stacked" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
                    <svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>
                    <span>{{ ___('Questions? Chat with us on WhatsApp') }}</span>
                    <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
                </a>
                @endif

                {{-- F20: the one post-order rating on the one page the diner actually
                     reaches. The item sheet asked you to rate a dish you had not eaten,
                     and the confirmation screen asked a question it navigated away from
                     3.4s later — both are gone. --}}
                <section class="review-card stacked" id="npsCard">
                    <p class="rev-q">{{ ___('How likely are you to recommend us?') }}</p>
                    <div class="nps-row" id="npsRow">
                        @for($n = 0; $n <= 10; $n++)<button type="button" data-nps="{{ $n }}">{{ $n }}</button>@endfor
                    </div>
                    <p class="rev-thanks" id="npsThanks" hidden>{{ ___('Thanks for the feedback!') }}</p>
                    @if(!empty($postOptions->google_review_url))
                    <a class="cta-band google" id="npsGoogle" href="{{ $postOptions->google_review_url }}" target="_blank" rel="noopener" hidden><span class="g-badge" aria-hidden="true">G</span><span>{{ ___('Rate us on Google') }}</span></a>
                    @endif
                </section>

                <div class="foot-center">
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
    /* NPS — one tap, posted straight to the shared endpoint. Idempotent per
       session server-side, so a double tap cannot double-record. */
    var npsUrl = @json(route('store.submitNps', $store->slug));
    var npsToken = @json(csrf_token());
    Array.prototype.forEach.call(document.querySelectorAll('#npsRow button'), function (b) {
        b.addEventListener('click', function () {
            Array.prototype.forEach.call(document.querySelectorAll('#npsRow button'), function (x) { x.classList.remove('is-on'); });
            b.classList.add('is-on');
            fetch(npsUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': npsToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ score: Number(b.getAttribute('data-nps')) }),
            }).catch(function () {});
            document.getElementById('npsThanks').hidden = false;
            var g = document.getElementById('npsGoogle');
            if (g) g.hidden = Number(b.getAttribute('data-nps')) < 9;
        });
    });

    updateUI(@json($order->status));
    // T2: register with the shared single poller (visibility-gated,
    // terminal-stop, live prep-eta + board token) instead of a per-theme 5s interval.
    window.mbTrackPoll(function (res) { if (res && res.status) updateUI(res.status); });
})();
</script>
@endpush
