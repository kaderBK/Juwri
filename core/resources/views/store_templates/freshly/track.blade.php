{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.freshly.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalVocab;

    /* ---------------------------------------------------------------------
       freshly — service booking tracking. Cloned from bookla/track.blade.php
       (the SAME data contract: $order, $store, $postOptions, $theme, $plan,
       $prepEta, $walletTx — see ManagesOrders::trackOrder(), NOT edited
       here), restyled to the calm mint/teal palette with a vertical
       connected-dot timeline instead of bookla's horizontal one. The status
       vocabulary below is byte-identical to bookla/track.blade.php's own
       (RetailOrderBoardService's additive state machine, reused rather than
       forked — see that file's docblock) so this page costs zero new i18n
       keys and always agrees with index.blade.php's own confirm-screen
       timeline for the exact same order.
       --------------------------------------------------------------------- */
    $vocab = app(VerticalVocab::class)->map($store->store_type ?? null);

    // Store-scoped money: store sign + gap + decimals, position-aware, with USD/riyal
    // LTR-isolated so the sign never bidi-flips in RTL. Centralises the old inline
    // money_ltr() closure and adds the Saudi Riyal (U+20C1) isolation it missed.
    $formatPrice = new \Juwri\Core\Support\StoreMoneyFormatter($store->id);

    $formatDuration = \Juwri\Core\Support\ViewHelpers::formatDuration(...);

    $fulfillment = app(FulfillmentRegistry::class);
    $modeLabel = $order->type ? $fulfillment->label((string) $order->type) : null;

    // Same receipt contract every kept theme's track page matches
    // (theme-totals-contract: Order::getTotalAttribute(), tax PRE-discount).
    $bkSubtotal = 0;
    $bkLines = [];
    foreach ($order->items as $item) {
        if (! $item->menu) {
            continue;
        }
        $unit = (float) $item->menu->price;
        if ($item->variation && $item->variant) {
            $unit = (float) $item->variant->price;
        }
        $bits = [];
        foreach ($item->itemExtras as $ie) {
            if ($ie->extra) {
                $unit += (float) $ie->extra->price;
                $bits[] = $ie->extra->title;
            }
        }
        foreach ((array) $item->options as $grp) {
            foreach ((array) ($grp['selected'] ?? []) as $opt) {
                $unit += (float) ($opt['price'] ?? 0);
                if (! empty($opt['name'])) {
                    $bits[] = $opt['name'];
                }
            }
        }
        // A weighted ('kg') line's own unit_price is already price-per-kg ×
        // weight (ServiceBookingService::book() snapshots it, quantity
        // pinned to 1) — mirror that exactly, same as bookla's own copy.
        $qty = (float) $item->quantity;
        $isWeighted = isset($item->weight_kg) && $item->weight_kg !== null;
        $line = $isWeighted ? ((float) $item->unit_price * $qty) : ($unit * $qty);
        $bkSubtotal += $line;
        $varLabel = [];
        if ($item->variation && $item->variant) {
            foreach ((array) ($item->variant->options ?? []) as $optId => $idx) {
                $vo = optional($item->menu->variantOptions ?? collect())->firstWhere('id', (int) $optId);
                $choices = (array) ($vo->options ?? []);
                if (isset($choices[(int) $idx])) {
                    $varLabel[] = $choices[(int) $idx];
                }
            }
        }
        $bkNoteBits = array_values(array_filter(array_merge($varLabel, $bits)));
        $duration = $formatDuration(isset($item->menu->turnaround_hours) ? (int) $item->menu->turnaround_hours : null);
        if ($duration) {
            $bkNoteBits[] = $duration;
        }
        $bkLines[] = [
            'name' => $item->menu->name,
            'qty' => $isWeighted ? (round((float) $item->weight_kg, 3).' '.___('kg')) : $qty,
            'note' => implode(' · ', $bkNoteBits),
            'total' => $line,
        ];
    }
    $bkTax = \App\Models\Order::computeTax($bkSubtotal, $postOptions);
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    :root{
        --frs-ground:#ECF6F4; --frs-card:#FFFFFF; --frs-card-2:#EFF7F5;
        --frs-ink:#122320; --frs-ink-2:#536965; --frs-hair:#E1EFEC;
        --frs-accent:{{ $store->color ?: '#12B3A6' }}; --frs-accent-2:#0C8C82; --frs-accent-bg:#D8F2EF;
        --frs-danger:#E1653C; --frs-ok:#0C8C82; --frs-radius:16px;
        --frs-font:"Saudi Riyal","Manrope",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
        --frs-shadow-card:0 10px 30px -18px rgba(18,35,32,.28);
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --frs-ground:#0B1615; --frs-card:#122120; --frs-card-2:#1A2C2A;
            --frs-ink:#E9F5F3; --frs-ink-2:#9DB4AF; --frs-hair:#213230;
            --frs-shadow-card:0 14px 32px -18px rgba(0,0,0,.55);
        }
    }
    :root[data-theme="dark"]{
        --frs-ground:#0B1615; --frs-card:#122120; --frs-card-2:#1A2C2A;
        --frs-ink:#E9F5F3; --frs-ink-2:#9DB4AF; --frs-hair:#213230;
        --frs-shadow-card:0 14px 32px -18px rgba(0,0,0,.55);
    }
    *{box-sizing:border-box}
    .frs-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:20}
    .frs-skip:focus{inset-inline-start:0}
    .frs-body{margin:0;background:var(--frs-ground);color:var(--frs-ink);font-family:var(--frs-font);-webkit-font-smoothing:antialiased}
    .frs-trk{max-width:560px;margin:0 auto;padding:28px 16px 80px}
    .frs-trk-head{text-align:center;margin-block-end:20px}
    .frs-trk-head h1{font-family:var(--frs-font);font-size:1.3rem;font-weight:800;margin:0 0 4px}
    .frs-trk-head p{color:var(--frs-ink-2);font-size:.86rem;margin:0}
    .frs-trk-card{background:var(--frs-card);border:1px solid var(--frs-hair);border-radius:var(--frs-radius);
        padding:20px;margin-block-end:16px;box-shadow:var(--frs-shadow-card)}
    .frs-trk-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;border-radius:999px;
        font-weight:700;font-size:.76rem;text-transform:uppercase;letter-spacing:.03em;margin-block-end:14px}
    .frs-trk-badge.is-pending{background:color-mix(in srgb, #b45309 16%, transparent);color:#b45309}
    .frs-trk-badge.is-processing{background:var(--frs-accent-bg);color:var(--frs-accent-2)}
    .frs-trk-badge.is-ready{background:color-mix(in srgb, var(--frs-ok) 16%, transparent);color:var(--frs-ok)}
    .frs-trk-badge.is-done{background:color-mix(in srgb, var(--frs-ok) 22%, transparent);color:var(--frs-ok)}
    .frs-trk-badge.is-cancelled{background:color-mix(in srgb, var(--frs-danger) 16%, transparent);color:var(--frs-danger)}
    .frs-trk-dot{width:8px;height:8px;border-radius:50%;background:currentColor}
    .frs-trk-title{font-family:var(--frs-font);font-size:1.05rem;font-weight:800;margin:0 0 6px}
    .frs-trk-desc{color:var(--frs-ink-2);font-size:.86rem;line-height:1.5;margin:0 0 6px}
    .frs-trk-steps{margin-top:18px}
    .frs-trk-step{display:flex;gap:14px;position:relative;padding-bottom:22px}
    .frs-trk-step:last-child{padding-bottom:0}
    .frs-trk-dotwrap{width:26px;height:26px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;
        border:2px solid var(--frs-hair);background:var(--frs-card);color:var(--frs-ink-2);font-weight:700;font-size:.72rem;z-index:2}
    .frs-trk-step::before{content:'';position:absolute;inset-inline-start:12px;top:26px;bottom:0;width:2px;background:var(--frs-hair)}
    .frs-trk-step:last-child::before{display:none}
    .frs-trk-step.is-done .frs-trk-dotwrap{background:var(--frs-accent);border-color:var(--frs-accent);color:#fff}
    .frs-trk-step.is-done::before{background:var(--frs-accent)}
    .frs-trk-step.is-active .frs-trk-dotwrap{border-color:var(--frs-accent);color:var(--frs-accent-2)}
    .frs-trk-step-label{font-weight:700;font-size:.88rem;padding-top:3px}
    .frs-trk-row{display:flex;justify-content:space-between;gap:10px;font-size:.86rem;margin-block-end:10px}
    .frs-trk-row .frs-trk-note{display:block;color:var(--frs-ink-2);font-size:.76rem;margin-block-start:2px}
    .frs-trk-label{color:var(--frs-ink-2)}
    .frs-trk-totals{margin-block-start:14px;padding-block-start:14px;border-block-start:1px dashed var(--frs-hair)}
    .frs-trk-total{display:flex;justify-content:space-between;font-weight:800;font-size:1rem}
    .frs-trk-back{display:block;text-align:center;margin-block-start:24px;color:var(--frs-accent-2);
        font-weight:700;font-size:.86rem;text-decoration:none}

    /* Desktop layer — additive only, mobile (<768) is untouched. This page
       is a linear receipt/timeline, so desktop just gets a comfortable
       wider reading column instead of a phone-width card lost in grey. */
    @media (min-width:768px){
        .frs-trk{max-width:640px;padding-block:36px}
    }
    @media (min-width:1024px){
        .frs-trk{max-width:760px;padding-block:48px}
        .frs-trk-card{padding:26px}
        .frs-trk-head h1{font-size:1.6rem}
    }
    @media (min-width:1280px){
        .frs-trk{max-width:820px}
    }
</style>
@endpush

@section('content')
<div class="frs-trk">
    <header class="frs-trk-head">
        <h1>{{ $store->title }}</h1>
        <p>{{ $vocab['order'] ?? ___('Booking') }} #{{ $order->id }} &bull; {{ date_formating($order->created_at, 'h:i A') }}</p>
    </header>

    <div class="frs-trk-card">
        <div id="frsTrkBadge"></div>
        <div class="frs-trk-title" id="frsTrkTitle">&nbsp;</div>
        <p class="frs-trk-desc" id="frsTrkDesc"></p>

        <div class="frs-trk-steps" id="frsTrkSteps">
            <div class="frs-trk-step" id="frsTrkStep0"><div class="frs-trk-dotwrap">1</div><div class="frs-trk-step-label">{{ ___('Received') }}</div></div>
            <div class="frs-trk-step" id="frsTrkStep1"><div class="frs-trk-dotwrap">2</div><div class="frs-trk-step-label">{{ ___('Processing') }}</div></div>
            <div class="frs-trk-step" id="frsTrkStep2"><div class="frs-trk-dotwrap">3</div><div class="frs-trk-step-label" id="frsTrkStep2Label">{{ ___('Ready') }}</div></div>
            <div class="frs-trk-step" id="frsTrkStep3"><div class="frs-trk-dotwrap">4</div><div class="frs-trk-step-label">{{ ___('Done') }}</div></div>
        </div>
    </div>

    <div class="frs-trk-card">
        <div class="frs-trk-row"><span class="frs-trk-label">{{ ___('Name') }}</span><span>{{ $order->customer_name }}</span></div>
        @if($order->phone_number)
            <div class="frs-trk-row"><span class="frs-trk-label">{{ ___('Phone') }}</span><span dir="ltr">{{ $order->phone_number }}</span></div>
        @endif
        @if($modeLabel)
            <div class="frs-trk-row"><span class="frs-trk-label">{{ ___('Fulfillment') }}</span><span>{{ $modeLabel }}</span></div>
        @endif
        @if($order->address)
            <div class="frs-trk-row"><span class="frs-trk-label">{{ ___('Pickup / delivery address') }}</span><span>{{ $order->address }}</span></div>
        @endif
        @if($order->scheduled_at)
            <div class="frs-trk-row"><span class="frs-trk-label">{{ ___('Pick a time') }}</span><span>{{ date_formating($order->scheduled_at, 'D, M j · h:i A') }}</span></div>
        @endif
    </div>

    <div class="frs-trk-card">
        @foreach($bkLines as $line)
            <div class="frs-trk-row">
                <span>
                    {{ $line['name'] }} &times; {{ $line['qty'] }}
                    @if($line['note'])<span class="frs-trk-note">{{ $line['note'] }}</span>@endif
                </span>
                <span>{{ $formatPrice($line['total']) }}</span>
            </div>
        @endforeach

        <div class="frs-trk-totals">
            <div class="frs-trk-row"><span class="frs-trk-label">{{ ___('Subtotal') }}</span><span>{{ $formatPrice($bkSubtotal) }}</span></div>
            {{-- Order::getTotalAttribute() folds restaurant_delivery_charge in
                 for type === 'delivery' — a real match here too (laundry's
                 own fulfillment modes literally include 'delivery'). --}}
            @if($order->deliveryCharge($postOptions) > 0)
                <div class="frs-trk-row"><span class="frs-trk-label">{{ ___('Delivery Charge') }}</span><span>{{ $formatPrice($order->deliveryCharge($postOptions)) }}</span></div>
            @endif
            @if($bkTax > 0)
                <div class="frs-trk-row"><span class="frs-trk-label">{{ ___('Tax') }}</span><span>{{ $formatPrice($bkTax) }}</span></div>
            @endif
            @if($order->tip_amount > 0)
                <div class="frs-trk-row"><span class="frs-trk-label">{{ ___('Tip') }}</span><span>{{ $formatPrice($order->tip_amount) }}</span></div>
            @endif
            @if($order->discount_amount > 0)
                <div class="frs-trk-row"><span class="frs-trk-label">{{ ___('Discount') }}</span><span style="color:var(--frs-ok)">-{{ $formatPrice($order->discount_amount) }}</span></div>
            @endif
            {{-- `total` is the authoritative accessor the customer is charged
                 on (theme-totals-contract) — never the `price` column. --}}
            <div class="frs-trk-row frs-trk-total"><span>{{ ___('Total') }}</span><span>{{ $formatPrice($order->total) }}</span></div>
        </div>
    </div>

    <a href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" class="frs-trk-back">{{ ___('Back to store') }}</a>
</div>
@endsection

{{-- freshly's own layout.blade.php only defines @stack('style') +
     @stack('script') — self-contained, like bookla, not the shared
     store_templates/layout.blade.php. --}}
@push('script')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    function el(id) { return document.getElementById(id); }
    function setText(id, txt) { var n = el(id); if (n) { n.textContent = txt; } }

    // Booking states — BYTE-IDENTICAL to bookla/track.blade.php's own table
    // (RetailOrderBoardService's additive state machine: new → packing →
    // ready → shipped|collected → done), reused rather than forked so a
    // laundry order's status always reads the same way regardless of which
    // service theme is rendering it. 'shipped' and 'collected' both read
    // "Out for delivery" — a laundry order never truly "ships".
    var isDelivery = @json($order->type === 'delivery');
    var STATUSES = {
        unpaid: {
            title: @json(___('Awaiting Payment')), desc: @json(___('Please complete your online payment to submit this booking.')),
            badge: @json(___('Awaiting Payment')), badgeClass: 'is-cancelled', progress: 0, step: -1,
        },
        new: {
            title: @json(___('Booking received')), desc: @json(___('Your booking has been received and is awaiting processing.')),
            badge: @json(___('Received')), badgeClass: 'is-pending', progress: 12.5, step: 0,
        },
        pending: {
            title: @json(___('Booking received')), desc: @json(___('Your booking has been received and is awaiting processing.')),
            badge: @json(___('Received')), badgeClass: 'is-pending', progress: 12.5, step: 0,
        },
        pending_approval: {
            title: @json(___('Awaiting approval')), desc: @json(___('Your booking has been received and is awaiting confirmation from the store.')),
            badge: @json(___('Awaiting approval')), badgeClass: 'is-pending', progress: 12.5, step: 0,
        },
        packing: {
            title: @json(___('Preparing your order')), desc: @json(___('The store is working on your service.')),
            badge: @json(___('Processing')), badgeClass: 'is-processing', progress: 37.5, step: 1,
        },
        ready: {
            title: isDelivery ? @json(___('Ready — awaiting delivery')) : @json(___('Ready for pickup')),
            desc: isDelivery ? @json(___('Your order is ready and will be delivered shortly.')) : @json(___('Your order is ready for pickup.')),
            badge: @json(___('Ready')), badgeClass: 'is-ready', progress: 62.5, step: 2,
        },
        shipped: {
            title: @json(___('Out for delivery')), desc: @json(___('Your order has left the store and is on its way.')),
            badge: @json(___('Out for delivery')), badgeClass: 'is-ready', progress: 87.5, step: 2,
        },
        collected: {
            title: @json(___('Out for delivery')), desc: @json(___('Your order has left the store and is on its way.')),
            badge: @json(___('Out for delivery')), badgeClass: 'is-ready', progress: 87.5, step: 2,
        },
        done: {
            title: @json(___('Completed')), desc: @json(___('This booking is complete. Thank you!')),
            badge: @json(___('Done')), badgeClass: 'is-done', progress: 100, step: 3,
        },
        cancelled: {
            title: @json(___('Booking Cancelled')), desc: @json(___('This booking has been cancelled. Please contact the store for assistance.')),
            badge: @json(___('Cancelled')), badgeClass: 'is-cancelled', progress: 0, step: -1,
        },
    };

    function updateUI(status) {
        var cfg = STATUSES[status] || STATUSES.new;
        var host = el('frsTrkBadge');
        if (host) {
            var badge = document.createElement('span');
            badge.className = 'frs-trk-badge ' + cfg.badgeClass;
            var dot = document.createElement('span');
            dot.className = 'frs-trk-dot';
            badge.appendChild(dot);
            badge.appendChild(document.createTextNode(' ' + cfg.badge));
            host.innerHTML = '';
            host.appendChild(badge);
        }
        setText('frsTrkTitle', cfg.title);
        setText('frsTrkDesc', cfg.desc);
        if (status === 'shipped' || status === 'collected') { setText('frsTrkStep2Label', cfg.badge); }

        var steps = ['frsTrkStep0', 'frsTrkStep1', 'frsTrkStep2', 'frsTrkStep3'];
        steps.forEach(function (id, idx) {
            var n = el(id);
            if (!n) { return; }
            n.classList.remove('is-active', 'is-done');
            if (cfg.step < 0) { return; }
            if (idx < cfg.step) { n.classList.add('is-done'); }
            else if (idx === cfg.step) { n.classList.add('is-active'); }
        });
    }

    updateUI(@json((string) $order->status));

    // Same JSON contract every track page's poll uses (ManagesOrders::
    // trackOrder — `wantsJson()`/`ajax()` branch): { status, ... }.
    var TERMINAL = { done: 1, cancelled: 1 };
    var timer = null;
    var stopped = false;
    function fetchOnce() {
        fetch(window.location.href, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.status) { return; }
                updateUI(res.status);
                if (TERMINAL[res.status]) { stop(); }
            })
            .catch(function () { /* best-effort — the page still shows the last known status */ });
    }
    function stop() {
        stopped = true;
        if (timer) { clearInterval(timer); timer = null; }
    }
    function arm(ms) {
        if (timer) { clearInterval(timer); }
        if (stopped) { return; }
        timer = setInterval(fetchOnce, ms);
    }
    if (!TERMINAL[@json((string) $order->status)]) {
        document.addEventListener('visibilitychange', function () {
            if (stopped) { return; }
            if (document.hidden) { arm(30000); } else { fetchOnce(); arm(5000); }
        });
        arm(document.hidden ? 30000 : 5000);
    }
})();
</script>
@endpush
