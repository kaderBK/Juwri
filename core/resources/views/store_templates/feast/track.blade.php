{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    $curDecimals = currency_decimals($postOptions->currency_code ?? null);
    $formatPrice = new \Juwri\Core\Support\TrimmedPriceFormatter($curSign, $curPos, $curDecimals);
    $waNumber = storefront_whatsapp_number($store); /* WA-T2: branch first, then legacy option, then store phone */
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    {{-- FEAST-F5 · apply the stored appearance BEFORE first paint so a dark-face
         customer never gets a white flash. Kept inline and tiny on purpose. --}}
    <script nonce="{{ csp_nonce() }}">
        (function () {
            try {
                var f = localStorage.getItem('ff_face_' + @json($store->id));
                if (f === 'dark' || f === 'light') document.documentElement.setAttribute('data-ff-face', f);
            } catch (e) {}
            /* FEAST-F16 · marks that JS is alive, which is what arms the
               scroll-reveal. Without it the menu renders plainly instead of
               staying at opacity 0 forever. */
            document.documentElement.classList.add('ff-js');
        })();
    </script>
@endpush

@section('content')
<div id="feast-app" class="feast-container {{ current_language()->direction === 'rtl' ? 'rtl-dir' : 'ltr-dir' }}">
    <div class="ff-ambient-1"></div>
    <div class="ff-ambient-2"></div>

    <main class="ff-account-page">
        <header class="ff-account-page-head">
            <a class="ff-account-back" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" aria-label="{{ ___('Back to Menu') }}">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 5l-7 7 7 7"/></svg>
            </a>
            <h1 class="ff-account-page-title">{{ ___('Track Order') }}</h1>
        </header>

        <div class="ff-order-card">
            <div class="ff-order-top">
                <div><p class="ff-order-date">{{ ___('Order') }}</p><b class="ff-order-id">#{{ $order->id }}</b></div>
                @include("store_templates._partials.ordered-via")
                <div style="text-align:end"><p class="ff-order-date">{{ date_formating($order->created_at, 'h:i A') }}</p><b class="ff-order-status" id="vStatusWord">…</b></div>
            </div>

            <ol class="ff-timeline" id="vTimeline">
                <li class="ff-tl" data-step="0">
                    <span class="ff-tl-node">✓</span><span class="ff-tl-line"></span>
                    <span class="ff-tl-copy"><b>{{ ___('Order Received') }}</b><small>{{ ___('Your order has been received by the kitchen.') }}</small></span>
                </li>
                <li class="ff-tl" data-step="1">
                    <span class="ff-tl-node">✓</span><span class="ff-tl-line"></span>
                    <span class="ff-tl-copy"><b>{{ ___('Preparing your Food') }}</b><small>{{ ___('The chef is preparing your meal with care.') }}</small></span>
                </li>
                <li class="ff-tl" data-step="2">
                    <span class="ff-tl-node">✓</span><span class="ff-tl-line"></span>
                    <span class="ff-tl-copy">
                        <b>@if($order->type === 'delivery'){{ ___('On the Way') }}@elseif($order->type === 'takeaway'){{ ___('Ready for Pickup') }}@else{{ ___('Ready!') }}@endif</b>
                        <small>@if($order->type === 'delivery'){{ ___('Your order is out for delivery.') }}@elseif($order->type === 'takeaway'){{ ___('Your order is packed and ready at the counter.') }}@else{{ ___('Your order is on its way to the table.') }}@endif</small>
                    </span>
                </li>
                <li class="ff-tl" data-step="3">
                    <span class="ff-tl-node">✓</span>
                    <span class="ff-tl-copy"><b>{{ ___('Order Completed') }}</b><small>{{ ___('Enjoy your meal and thank you!') }}</small></span>
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
            <p class="ff-track-alert" id="vCancelled" style="display:none">{{ ___('This order has been cancelled. Please contact staff for assistance.') }}</p>
            <p class="ff-track-alert" id="vUnpaid" style="display:none">⚠ {{ ___('Please complete your online payment to submit this order to the kitchen.') }}</p>
        </div>

        {{-- T5: Curbside "I'm here" arrival ping --}}
        @if(!empty($order->is_curbside))
        <div class="ff-order-card" id="ff-curbside-card" style="margin-top:14px;text-align:center;">
            <button type="button" id="ff-imhere-btn" style="width:100%;padding:14px;border:none;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;background:#111;color:#fff;">
                {{ ___("I'm here") }}
            </button>
            <div id="ff-imhere-msg" style="display:none;margin-top:10px;font-size:14px;font-weight:600;">{{ ___('Staff notified') }}</div>
        </div>
        <script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
            (function(){
                var btn=document.getElementById('ff-imhere-btn');
                if(!btn) return;
                btn.addEventListener('click', function(){
                    btn.disabled=true;
                    fetch('{{ url('order/track') }}/{{ $order->track_token }}/arrived', {
                        method:'POST',
                        headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content,'Accept':'application/json'}
                    }).then(function(r){return r.json();}).then(function(d){
                        btn.style.display='none';
                        var m=document.getElementById('ff-imhere-msg'); if(m) m.style.display='block';
                    }).catch(function(){ btn.disabled=false; });
                });
            })();
        </script>
        @endif

        <div class="ff-order-card" style="margin-top:14px">
            <p class="ff-order-date" style="margin-bottom:10px">{{ ___('Customer Details') }}</p>
            <div class="ff-order-lines">
                <div class="ff-order-line" style="display:flex;justify-content:space-between"><span>{{ ___('Customer Name') }}</span><b>{{ $order->customer_name }}</b></div>
                @if($order->phone_number)
                    <div class="ff-order-line" style="display:flex;justify-content:space-between"><span>{{ ___('Phone') }}</span><b>{{ $order->phone_number }}</b></div>
                @endif
                @if($order->type)
                    <div class="ff-order-line" style="display:flex;justify-content:space-between"><span>{{ ___('Order type') }}</span><b>{{ ___(ucfirst(str_replace('-', ' ', $order->type))) }}</b></div>
                @endif
            </div>
        </div>

        <div class="ff-order-card" style="margin-top:14px">
            <p class="ff-order-date" style="margin-bottom:10px">{{ ___('Order Summary') }}</p>
            {{-- FEAST-F7 · the receipt printed lines, delivery, tip and discount but NO
                 subtotal and NO tax row, and took the grand total from $order->price —
                 which is empty on these orders — so its own arithmetic did not add up in
                 front of the customer. Every charged line is now printed, and they sum. --}}
            @php
                $rcpSubtotal = 0.0;
                foreach ($order->items as $it) {
                    if (!$it->menu) continue;
                    $u = $it->variant ? (float) $it->variant->price : (float) $it->menu->price;
                    foreach ($it->itemExtras ?? [] as $ie) { $u += (float) ($ie->extra->price ?? 0); }
                    foreach ((array) $it->options as $grp) {
                        foreach ((array) ($grp['selected'] ?? []) as $opt) { $u += (float) ($opt['price'] ?? 0); }
                    }
                    $rcpSubtotal += $u * (int) $it->quantity;
                }
                $rcpTax      = \App\Models\Order::computeTax($rcpSubtotal, $postOptions);
                $rcpTaxLabel = trim((string) (@$postOptions->restaurant_tax_label ?: '')) ?: ___('Tax');
            @endphp
            <div class="ff-order-lines">
                @foreach($order->items as $item)
                    @if($item->menu)
                        @php
                            $lineUnit = $item->variant ? (float) $item->variant->price : (float) $item->menu->price;
                            foreach ($item->itemExtras ?? [] as $itemExtra) {
                                $lineUnit += (float) ($itemExtra->extra->price ?? 0);
                            }
                            foreach ((array) $item->options as $grp) {
                                foreach ((array) ($grp['selected'] ?? []) as $opt) {
                                    $lineUnit += (float) ($opt['price'] ?? 0);
                                }
                            }
                        @endphp
                        <div class="ff-order-line" style="display:flex;justify-content:space-between">
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
                <div class="ff-order-line ff-order-subtotal-line" style="display:flex;justify-content:space-between"><span>{{ ___('Subtotal') }}</span><b>{{ $formatPrice($rcpSubtotal) }}</b></div>
                @if($order->deliveryCharge($postOptions) > 0)
                    <div class="ff-order-line" style="display:flex;justify-content:space-between"><span>{{ ___('Delivery Charge') }}</span><b>{{ $formatPrice($order->deliveryCharge($postOptions)) }}</b></div>
                @endif
                @if($rcpTax > 0)
                    <div class="ff-order-line" style="display:flex;justify-content:space-between"><span>{{ $rcpTaxLabel }}</span><b>{{ $formatPrice($rcpTax) }}</b></div>
                @endif
                {{-- C10 — room-service tray charge. Order::computeMoney folds it into the
                     grand total, so without this row the item lines under-sum the printed
                     Total by exactly the tray amount (mirrors crumb/orbit/plate/velora). --}}
                @php $rcpTray = \App\Models\Order::trayCharge($order->type, $rcpSubtotal, $order->store_id); @endphp
                @if($rcpTray > 0)
                    <div class="ff-order-line" style="display:flex;justify-content:space-between"><span>{{ ___('Tray charge') }}</span><b>{{ $formatPrice($rcpTray) }}</b></div>
                @endif
                @if($order->tip_amount > 0)
                    <div class="ff-order-line" style="display:flex;justify-content:space-between"><span>{{ ___('Tip') }}</span><b>{{ $formatPrice($order->tip_amount) }}</b></div>
                @endif
                @if($order->discount_amount > 0)
                    <div class="ff-order-line" style="display:flex;justify-content:space-between;color:var(--ff-accent)"><span>{{ ___('Discount') }}</span><b>−{{ $formatPrice($order->discount_amount) }}</b></div>
                @endif
                <div class="ff-order-line ff-order-total-line" style="display:flex;justify-content:space-between">
                    <span>{{ ___('Total') }}</span>
                    {{-- was $order->price, which is EMPTY on these orders — the receipt's
                         grand total rendered blank. $order->total is the charged number. --}}
                    <b>{{ $formatPrice($order->total) }}</b>
                </div>
            </div>
        </div>

        @if($waNumber)
        <a class="ff-ghostbtn" style="display:flex;margin-top:14px;justify-content:center" href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener">
            {{ ___('Questions? Chat with us on WhatsApp') }}
        </a>
        @endif

        <div style="display:grid;place-items:center;padding-top:18px">
            <a class="ff-ghostbtn" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}">{{ ___('Back to Menu') }}</a>
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
        document.getElementById('vStatusWord').textContent = word[status] || status;
        document.getElementById('vCancelled').style.display = status === 'cancelled' ? 'block' : 'none';
        document.getElementById('vUnpaid').style.display = status === 'unpaid' ? 'block' : 'none';
        var rows = document.querySelectorAll('#vTimeline .ff-tl');
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
