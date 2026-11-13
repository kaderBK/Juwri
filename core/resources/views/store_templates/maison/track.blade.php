{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.maison.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalVocab;

    /* ---------------------------------------------------------------------
       maison — goods order tracking. Clones store_templates/shelf/track.blade.php
       byte-for-byte in DATA CONTRACT and behaviour (same controller feeds
       both — see ManagesOrders::trackOrder — NOT edited here), reskinned in
       the editorial-luxe tokens/typography this template's index.blade.php
       uses. Self-contained (own layout, no shared store_templates/layout or
       script.js), same as shelf's.

       Data contract: $order, $store, $postOptions (plain stdClass of
       option_name => option_value), $theme, $plan, $prepEta, $walletTx.
       No $vocab/$table passed in — computed locally below, same as
       index.blade.php does.

       Status timeline: a goods order runs through RetailOrderBoardService's
       state machine (new → packing → ready → shipped|collected → done), NOT
       the food KDS pipeline (Juwri\Modules\Ordering\Enums\OrderStatus). Labels mirror
       owner/orders/board.blade.php so the customer and the
       staff board agree — see shelf/track.blade.php for the annotated
       original this mirrors line for line.
       --------------------------------------------------------------------- */
    $vocab = app(VerticalVocab::class)->map($store->store_type ?? null);

    // Same P1 currency fix shelf/track.blade.php applies — trackOrder() never
    // sets config('settings.currency_sign') to this STORE's own value (unlike
    // the storefront's publicView), so this reads the store's sign/decimals
    // off $postOptions directly rather than price_symbol_format().
    // Store-scoped money: store sign + gap + decimals, position-aware, with USD/riyal
    // LTR-isolated so the sign never bidi-flips in RTL. Centralises the old inline
    // money_ltr() closure and adds the Saudi Riyal (U+20C1) isolation it missed.
    $formatPrice = new \Juwri\Core\Support\StoreMoneyFormatter($store->id);

    $fulfillment = app(FulfillmentRegistry::class);
    $modeLabel = $order->type ? $fulfillment->label((string) $order->type) : null;

    /* Same receipt contract as every other kept theme's track page
       (theme-totals-contract: match Order::getTotalAttribute(), tax
       PRE-discount) — see shelf/track.blade.php for the annotated original.
       itemExtras/options are food "extras"/choice-groups a goods item never
       has, but the loop stays generic (empty for a goods order, so it is a
       silent no-op) rather than forking a goods-only copy. */
    $msSubtotal = 0;
    $msLines = [];
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
        $line = $unit * $item->quantity;
        $msSubtotal += $line;
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
        $msNoteBits = array_values(array_filter(array_merge($varLabel, $bits)));
        $msLines[] = [
            'name' => $item->menu->name,
            'qty' => $item->quantity,
            'note' => implode(' · ', $msNoteBits),
            'total' => $line,
        ];
    }
    $msTax = \App\Models\Order::computeTax($msSubtotal, $postOptions);
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    :root{
        --ms-ground:#F2F1EE; --ms-card:#FFFFFF; --ms-ink:#0F0E0C; --ms-muted:#6B6762;
        --ms-hair:#E6E2DC; --ms-camel:#A9784E; --ms-sale:#A93B2C; --ms-ok:#1F7A4D;
        --ms-radius:20px; --ms-font-serif:"Fraunces",Georgia,serif;
        --ms-font-sans:"Saudi Riyal","Hanken Grotesk",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --ms-ground:#121110; --ms-card:#1C1A17; --ms-ink:#F2F1EE; --ms-muted:#ADA79E;
            --ms-hair:#2A2620; --ms-camel:#C79C6E;
        }
    }
    :root[data-theme="dark"]{
        --ms-ground:#121110; --ms-card:#1C1A17; --ms-ink:#F2F1EE; --ms-muted:#ADA79E;
        --ms-hair:#2A2620; --ms-camel:#C79C6E;
    }
    *{box-sizing:border-box}
    .ms-body{margin:0;background:var(--ms-ground);color:var(--ms-ink);font-family:var(--ms-font-sans)}
    .ms-trk{max-width:600px;margin:0 auto;padding:32px 18px 80px}
    .ms-trk-head{text-align:center;margin-block-end:22px}
    .ms-trk-head h1{font-family:var(--ms-font-serif);font-weight:500;font-size:1.5rem;margin:0 0 6px;letter-spacing:-.01em}
    .ms-trk-head p{color:var(--ms-muted);font-size:.86rem;margin:0}
    .ms-trk-card{background:var(--ms-card);border:1px solid var(--ms-hair);border-radius:var(--ms-radius);
        padding:22px;margin-block-end:16px;box-shadow:0 10px 30px -20px rgba(20,16,12,.25)}
    .ms-trk-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;border-radius:999px;
        font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;margin-block-end:14px}
    .ms-trk-badge.is-pending{background:color-mix(in srgb, #b45309 14%, transparent);color:#b45309}
    .ms-trk-badge.is-processing{background:color-mix(in srgb, #2563eb 14%, transparent);color:#2563eb}
    .ms-trk-badge.is-ready{background:color-mix(in srgb, var(--ms-ok) 14%, transparent);color:var(--ms-ok)}
    .ms-trk-badge.is-done{background:color-mix(in srgb, var(--ms-ok) 20%, transparent);color:var(--ms-ok)}
    .ms-trk-badge.is-cancelled{background:color-mix(in srgb, var(--ms-sale) 14%, transparent);color:var(--ms-sale)}
    .ms-trk-dot{width:8px;height:8px;border-radius:50%;background:currentColor}
    .ms-trk-title{font-family:var(--ms-font-serif);font-weight:500;font-size:1.2rem;margin:0 0 6px}
    .ms-trk-desc{color:var(--ms-muted);font-size:.86rem;line-height:1.5;margin:0 0 20px}
    .ms-trk-steps{display:flex;justify-content:space-between;position:relative;margin:16px 0 4px}
    .ms-trk-steps::before{content:'';position:absolute;top:15px;inset-inline-start:0;inset-inline-end:0;height:3px;background:var(--ms-hair)}
    .ms-trk-steps-progress{position:absolute;top:15px;inset-inline-start:0;height:3px;background:var(--ms-camel);width:0%;transition:width .5s ease}
    [dir="rtl"] .ms-trk-steps-progress{inset-inline-start:auto;inset-inline-end:0}
    .ms-trk-step{position:relative;text-align:center;flex:1;font-size:.66rem;color:var(--ms-muted);font-weight:600}
    .ms-trk-step-dot{width:32px;height:32px;border-radius:50%;background:var(--ms-card);border:3px solid var(--ms-hair);
        display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-weight:700;color:var(--ms-muted)}
    .ms-trk-step.is-active .ms-trk-step-dot{border-color:var(--ms-camel);background:var(--ms-camel);color:#fff}
    .ms-trk-step.is-done .ms-trk-step-dot{border-color:var(--ms-camel);color:var(--ms-camel)}
    .ms-trk-step.is-active,.ms-trk-step.is-done{color:var(--ms-ink)}
    .ms-trk-row{display:flex;justify-content:space-between;gap:10px;font-size:.86rem;margin-block-end:10px}
    .ms-trk-row .ms-trk-note{display:block;color:var(--ms-muted);font-size:.76rem;margin-block-start:2px}
    .ms-trk-label{color:var(--ms-muted)}
    .ms-trk-totals{margin-block-start:14px;padding-block-start:14px;border-block-start:1px dashed var(--ms-hair)}
    .ms-trk-total{display:flex;justify-content:space-between;font-weight:800;font-size:1.05rem;font-variant-numeric:tabular-nums}
    .ms-trk-back{display:block;text-align:center;margin-block-start:24px;color:var(--ms-camel);
        font-weight:700;font-size:.86rem;text-decoration:none;letter-spacing:.02em}

    /* ---------------- desktop (ADD-ONLY): a little more breathing room on a
       standalone order-status page — kept centered/narrow like a receipt on
       every viewport (mirrors how the rest of the theme treats single-column
       content), never touching anything below 768px. ---------------- */
    @media (min-width:768px){
        .ms-trk{ max-width:640px; padding-top:40px }
        .ms-trk-card{ padding:24px }
    }
    @media (min-width:1024px){
        .ms-trk{ max-width:680px; padding-top:52px }
        .ms-trk-card{ padding:28px }
        .ms-trk-head h1{ font-size:1.7rem }
    }
</style>
@endpush

@section('content')
<div class="ms-trk" id="ms-main">
    <header class="ms-trk-head">
        <h1>{{ $store->title }}</h1>
        <p>{{ $vocab['order'] ?? ___('Order') }} #{{ $order->id }} &middot; {{ date_formating($order->created_at, 'h:i A') }}</p>
    </header>

    <div class="ms-trk-card">
        <div id="msTrkBadge"></div>
        <div class="ms-trk-title" id="msTrkTitle">&nbsp;</div>
        <p class="ms-trk-desc" id="msTrkDesc"></p>

        <div class="ms-trk-steps" id="msTrkSteps">
            <div class="ms-trk-steps-progress" id="msTrkProgress"></div>
            <div class="ms-trk-step" id="msTrkStep0"><div class="ms-trk-step-dot">1</div>{{ ___('Received') }}</div>
            <div class="ms-trk-step" id="msTrkStep1"><div class="ms-trk-step-dot">2</div>{{ ___('Processing') }}</div>
            <div class="ms-trk-step" id="msTrkStep2"><div class="ms-trk-step-dot">3</div><span id="msTrkStep2Label">{{ ___('Ready') }}</span></div>
            <div class="ms-trk-step" id="msTrkStep3"><div class="ms-trk-step-dot">4</div>{{ ___('Done') }}</div>
        </div>
    </div>

    <div class="ms-trk-card">
        <div class="ms-trk-row"><span class="ms-trk-label">{{ ___('Name') }}</span><span>{{ $order->customer_name }}</span></div>
        @if($order->phone_number)
            <div class="ms-trk-row"><span class="ms-trk-label">{{ ___('Phone') }}</span><span dir="ltr">{{ $order->phone_number }}</span></div>
        @endif
        @if($modeLabel)
            <div class="ms-trk-row"><span class="ms-trk-label">{{ ___('Fulfillment') }}</span><span>{{ $modeLabel }}</span></div>
        @endif
        @if($order->address)
            <div class="ms-trk-row"><span class="ms-trk-label">{{ ___('Address') }}</span><span>{{ $order->address }}</span></div>
        @endif
    </div>

    <div class="ms-trk-card">
        @foreach($msLines as $line)
            <div class="ms-trk-row">
                <span>
                    {{ $line['name'] }} &times; {{ $line['qty'] }}
                    @if($line['note'])<span class="ms-trk-note">{{ $line['note'] }}</span>@endif
                </span>
                <span>{{ $formatPrice($line['total']) }}</span>
            </div>
        @endforeach

        <div class="ms-trk-totals">
            <div class="ms-trk-row"><span class="ms-trk-label">{{ ___('Subtotal') }}</span><span>{{ $formatPrice($msSubtotal) }}</span></div>
            {{-- No separate "Shipping" row: Order::getTotalAttribute() (the
                 authoritative total this receipt must reconcile with) only
                 folds restaurant_delivery_charge in for type === 'delivery',
                 never for a goods 'ship' order — see shelf/track.blade.php. --}}
            @if($msTax > 0)
                <div class="ms-trk-row"><span class="ms-trk-label">{{ ___('Tax') }}</span><span>{{ $formatPrice($msTax) }}</span></div>
            @endif
            @if($order->tip_amount > 0)
                <div class="ms-trk-row"><span class="ms-trk-label">{{ ___('Tip') }}</span><span>{{ $formatPrice($order->tip_amount) }}</span></div>
            @endif
            @if($order->discount_amount > 0)
                <div class="ms-trk-row"><span class="ms-trk-label">{{ ___('Discount') }}</span><span style="color:var(--ms-ok)">-{{ $formatPrice($order->discount_amount) }}</span></div>
            @endif
            <div class="ms-trk-row ms-trk-total"><span>{{ ___('Total') }}</span><span>{{ $formatPrice($order->total) }}</span></div>
        </div>
    </div>

    <a href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" class="ms-trk-back">{{ ___('Back to store') }}</a>
</div>
@endsection

{{-- maison's own layout.blade.php only defines @stack('style') + @stack('script')
     (self-contained, same as shelf's — no shared store_templates/layout.blade.php
     scripts_at_bottom/style_at_top stacks here). --}}
@push('script')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    function el(id) { return document.getElementById(id); }
    function setText(id, txt) { var n = el(id); if (n) { n.textContent = txt; } }

    // Goods order states — RetailOrderBoardService::STATES (new → packing →
    // ready → shipped|collected → done), NOT the food KDS pipeline. Labels
    // mirror owner/orders/board.blade.php so the customer and
    // the staff board agree. `ship` vs. pickup/in-store gets its own wording
    // for the 3rd step — same convention as shelf/track.blade.php.
    var isShip = @json($order->type === 'ship');
    var STATUSES = {
        unpaid: {
            title: @json(___('Awaiting Payment')), desc: @json(___('Please complete your online payment to submit this order.')),
            badge: @json(___('Awaiting Payment')), badgeClass: 'is-cancelled', progress: 0, step: -1,
        },
        new: {
            title: @json(___('Order received')), desc: @json(___('Your order has been received and is awaiting processing.')),
            badge: @json(___('Received')), badgeClass: 'is-pending', progress: 12.5, step: 0,
        },
        pending: {
            title: @json(___('Order received')), desc: @json(___('Your order has been received and is awaiting processing.')),
            badge: @json(___('Received')), badgeClass: 'is-pending', progress: 12.5, step: 0,
        },
        pending_approval: {
            title: @json(___('Awaiting approval')), desc: @json(___('Your order has been received and is awaiting confirmation from the store.')),
            badge: @json(___('Awaiting approval')), badgeClass: 'is-pending', progress: 12.5, step: 0,
        },
        packing: {
            title: @json(___('Preparing your order')), desc: @json(___('The store is packing your items.')),
            badge: @json(___('Processing')), badgeClass: 'is-processing', progress: 37.5, step: 1,
        },
        ready: {
            title: isShip ? @json(___('Ready to ship')) : @json(___('Ready for pickup')),
            desc: isShip ? @json(___('Your order is packed and will ship shortly.')) : @json(___('Your order is packed and ready for pickup.')),
            badge: @json(___('Ready')), badgeClass: 'is-ready', progress: 62.5, step: 2,
        },
        shipped: {
            title: @json(___('On the way')), desc: @json(___('Your order has shipped and is on its way.')),
            badge: @json(___('Shipped')), badgeClass: 'is-ready', progress: 87.5, step: 2,
        },
        collected: {
            title: @json(___('Collected')), desc: @json(___('Your order has been handed over. Thank you!')),
            badge: @json(___('Collected')), badgeClass: 'is-ready', progress: 87.5, step: 2,
        },
        done: {
            title: @json(___('Completed')), desc: @json(___('This order is complete. Thank you for shopping with us!')),
            badge: @json(___('Done')), badgeClass: 'is-done', progress: 100, step: 3,
        },
        cancelled: {
            title: @json(___('Order Cancelled')), desc: @json(___('This order has been cancelled. Please contact the store for assistance.')),
            badge: @json(___('Cancelled')), badgeClass: 'is-cancelled', progress: 0, step: -1,
        },
    };
    if (isShip) { setText('msTrkStep2Label', @json(___('Shipped'))); }

    function updateUI(status) {
        var cfg = STATUSES[status] || STATUSES.new;
        var host = el('msTrkBadge');
        if (host) {
            var badge = document.createElement('span');
            badge.className = 'ms-trk-badge ' + cfg.badgeClass;
            var dot = document.createElement('span');
            dot.className = 'ms-trk-dot';
            badge.appendChild(dot);
            badge.appendChild(document.createTextNode(' ' + cfg.badge));
            host.innerHTML = '';
            host.appendChild(badge);
        }
        setText('msTrkTitle', cfg.title);
        setText('msTrkDesc', cfg.desc);

        var steps = ['msTrkStep0', 'msTrkStep1', 'msTrkStep2', 'msTrkStep3'];
        steps.forEach(function (id, idx) {
            var n = el(id);
            if (!n) { return; }
            n.classList.remove('is-active', 'is-done');
            if (cfg.step < 0) { return; }
            if (idx < cfg.step) { n.classList.add('is-done'); }
            else if (idx === cfg.step) { n.classList.add('is-active'); }
        });
        var bar = el('msTrkProgress');
        if (bar) { bar.style.width = cfg.progress + '%'; }
    }

    updateUI(@json((string) $order->status));

    // Same JSON contract every track page's poll uses (ManagesOrders::
    // trackOrder — `wantsJson()`/`ajax()` branch): { status, ... }. Simplified
    // single-purpose poller (no shared window.mbTrackPoll — maison is
    // self-contained and does not load the shared script.js), same
    // visibility-aware backoff + terminal-stop shape as shelf/track.blade.php.
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
