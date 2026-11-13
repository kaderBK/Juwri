{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.carely.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalVocab;

    /* ---------------------------------------------------------------------
       carely — goods order tracking. Cloned from shelf/track.blade.php
       (the pattern this pack was told to clone) with carely's own layout.
       blade.php + CSS vars — no shared store_templates/layout.blade.php or
       script.js, same as index.blade.php.

       Data contract (see ManagesOrders::trackOrder — controller, NOT edited
       here): $order, $store, $postOptions (plain stdClass of option_name =>
       option_value), $theme, $plan, $prepEta, $walletTx. No $vocab/$table —
       computed locally below, same as index.blade.php does.

       Status timeline: a goods order runs through RetailOrderBoardService's
       state machine (new -> packing -> ready -> shipped|collected -> done),
       NOT a pharmacist-review step. D3 (OTC-only, no Rx, ever) — this store
       never holds an order for prescription approval, so the timeline below
       is the exact same STANDARD goods states shelf's own track page shows:
       Confirmed -> Packing -> Out for delivery/Ready -> Delivered/Done.
       --------------------------------------------------------------------- */
    $vocab = app(VerticalVocab::class)->map($store->store_type ?? null);

    // P1 currency fix — trackOrder() never sets config('settings.currency_sign')
    // to this STORE's own value, so this reads the store's sign/decimals off
    // $postOptions directly rather than price_symbol_format() (which would
    // print the platform default, not this store's currency).
    // Store-scoped money: store sign + gap + decimals, position-aware, with USD/riyal
    // LTR-isolated so the sign never bidi-flips in RTL. Centralises the old inline
    // money_ltr() closure and adds the Saudi Riyal (U+20C1) isolation it missed.
    $formatPrice = new \Juwri\Core\Support\StoreMoneyFormatter($store->id);

    $fulfillment = app(FulfillmentRegistry::class);
    $modeLabel = $order->type ? $fulfillment->label((string) $order->type) : null;

    /* Same receipt contract as every other kept theme's track page (theme-
       totals-contract: match Order::getTotalAttribute(), tax PRE-discount).
       itemExtras/options are food "extras"/choice-groups a goods item never
       has, but the loop stays generic (empty for a goods order, so it is a
       silent no-op) rather than forking a goods-only copy. */
    $clSubtotal = 0;
    $clLines = [];
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
        $clSubtotal += $line;
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
        $clNoteBits = array_values(array_filter(array_merge($varLabel, $bits)));
        $clLines[] = [
            'name' => $item->menu->name,
            'qty' => $item->quantity,
            'note' => implode(' · ', $clNoteBits),
            'total' => $line,
        ];
    }
    $clTax = \App\Models\Order::computeTax($clSubtotal, $postOptions);
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    :root{
        --cl-bg:#EDF6F1; --cl-surface:#FFFFFF; --cl-ink:#16211C; --cl-muted:#566A60;
        --cl-line:#E4EFE9; --cl-line-2:#D5E5DC; --cl-accent:{{ $store->color ?: '#1F9D74' }}; --cl-accent-2:#14805E;
        --cl-accent-ink:#FFFFFF; --cl-danger:#C0322A; --cl-ok:#14805E; --cl-radius:16px;
        --cl-font:"Saudi Riyal","Poppins",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --cl-bg:#0C1512; --cl-surface:#141E19; --cl-ink:#EAF5EF; --cl-muted:#9DB2A8; --cl-line:#242E29; --cl-line-2:#2F3B34;
            --cl-accent-2:#2FAE82;
        }
    }
    :root[data-theme="dark"]{
        --cl-bg:#0C1512; --cl-surface:#141E19; --cl-ink:#EAF5EF; --cl-muted:#9DB2A8; --cl-line:#242E29; --cl-line-2:#2F3B34;
        --cl-accent-2:#2FAE82;
    }
    *{box-sizing:border-box}
    .carely-body{margin:0;background:var(--cl-bg);color:var(--cl-ink);font-family:var(--cl-font);-webkit-font-smoothing:antialiased}
    .cl-trk{max-width:560px;margin:0 auto;padding:28px 16px 80px}
    .cl-trk-head{text-align:center;margin-block-end:20px}
    .cl-trk-head h1{font-size:1.3rem;font-weight:700;margin:0 0 4px}
    .cl-trk-head p{color:var(--cl-muted);font-size:.86rem;margin:0}
    .cl-trk-card{background:var(--cl-surface);border:1px solid var(--cl-line);border-radius:var(--cl-radius);
        padding:20px;margin-block-end:16px}
    .cl-trk-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;border-radius:999px;
        font-weight:700;font-size:.76rem;text-transform:uppercase;letter-spacing:.03em;margin-block-end:14px}
    .cl-trk-badge.is-pending{background:color-mix(in srgb, #b45309 14%, transparent);color:#b45309}
    .cl-trk-badge.is-processing{background:color-mix(in srgb, #2563eb 14%, transparent);color:#2563eb}
    .cl-trk-badge.is-ready{background:color-mix(in srgb, var(--cl-ok) 14%, transparent);color:var(--cl-ok)}
    .cl-trk-badge.is-done{background:color-mix(in srgb, var(--cl-ok) 20%, transparent);color:var(--cl-ok)}
    .cl-trk-badge.is-cancelled{background:color-mix(in srgb, var(--cl-danger) 14%, transparent);color:var(--cl-danger)}
    .cl-trk-dot{width:8px;height:8px;border-radius:50%;background:currentColor}
    .cl-trk-title{font-size:1.05rem;font-weight:700;margin:0 0 6px}
    .cl-trk-desc{color:var(--cl-muted);font-size:.86rem;line-height:1.5;margin:0 0 18px}
    .cl-trk-steps{display:flex;justify-content:space-between;position:relative;margin:16px 0 4px}
    .cl-trk-steps::before{content:'';position:absolute;top:15px;inset-inline-start:0;inset-inline-end:0;height:3px;background:var(--cl-line)}
    .cl-trk-steps-progress{position:absolute;top:15px;inset-inline-start:0;height:3px;background:var(--cl-accent);width:0%;transition:width .5s ease}
    [dir="rtl"] .cl-trk-steps-progress{inset-inline-start:auto;inset-inline-end:0}
    .cl-trk-step{position:relative;text-align:center;flex:1;font-size:.68rem;color:var(--cl-muted);font-weight:600}
    .cl-trk-step-dot{width:32px;height:32px;border-radius:50%;background:var(--cl-surface);border:3px solid var(--cl-line);
        display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-weight:700;color:var(--cl-muted)}
    .cl-trk-step.is-active .cl-trk-step-dot{border-color:var(--cl-accent);background:var(--cl-accent);color:var(--cl-accent-ink)}
    .cl-trk-step.is-done .cl-trk-step-dot{border-color:var(--cl-accent);color:var(--cl-accent-2)}
    .cl-trk-step.is-active,.cl-trk-step.is-done{color:var(--cl-ink)}
    .cl-trk-row{display:flex;justify-content:space-between;gap:10px;font-size:.86rem;margin-block-end:10px}
    .cl-trk-row .cl-trk-note{display:block;color:var(--cl-muted);font-size:.76rem;margin-block-start:2px}
    .cl-trk-label{color:var(--cl-muted)}
    .cl-trk-totals{margin-block-start:14px;padding-block-start:14px;border-block-start:1px dashed var(--cl-line)}
    .cl-trk-total{display:flex;justify-content:space-between;font-weight:700;font-size:1rem}
    .cl-trk-back{display:block;text-align:center;margin-block-start:24px;color:var(--cl-accent-2);
        font-weight:700;font-size:.86rem;text-decoration:none}

    /* ============================================================
       DESKTOP COMPOSITION (tablet >=768 / desktop >=1024 / wide >=1280)
       Additive only — mobile (<768) is byte-for-byte unchanged. Every
       color below reuses an existing --cl-* token, already covered by
       both dark-mode blocks above. RTL: CSS Grid is direction-aware (the
       theme sets `dir` on <html>), so the totals column mirrors to the
       reading-start side automatically for Arabic — no [dir="rtl"]
       override needed. See RESPONSIVE-SPEC.md.
       ============================================================ */
    @media (min-width:768px){
        .cl-trk{max-width:640px}
    }
    @media (min-width:1024px){
        /* status/customer-info left, sticky order-totals card right —
           mirrors the storefront cart's own sticky-summary pattern */
        .cl-trk{max-width:960px;padding-inline:32px;box-sizing:border-box;display:grid;
            grid-template-columns:1fr 380px;column-gap:28px;align-items:start}
        .cl-trk-head{grid-column:1/-1}
        .cl-trk-card{grid-column:1}
        /* Span the two left-column cards. NOT `/-1`: grid-template-rows is
           never declared, so `-1` resolves to explicit row line 1 and
           `grid-row:2/-1` becomes `2/1`, which the browser reverses to `1/2`,
           dropping the totals card at the top-left above the header. `span 2`
           is row-count-relative and immune. */
        .cl-trk-card--totals{grid-column:2;grid-row:2/span 2;position:sticky;top:28px}
        .cl-trk-back{grid-column:1/-1}
    }
    @media (min-width:1280px){
        .cl-trk{max-width:1040px}
    }
</style>
@endpush

@section('content')
<div class="cl-trk">
    <header class="cl-trk-head">
        <h1>{{ $store->title }}</h1>
        <p>{{ $vocab['order'] ?? ___('Order') }} #{{ $order->id }} &bull; {{ date_formating($order->created_at, 'h:i A') }}</p>
    </header>

    <div class="cl-trk-card">
        <div id="clTrkBadge"></div>
        <div class="cl-trk-title" id="clTrkTitle">&nbsp;</div>
        <p class="cl-trk-desc" id="clTrkDesc"></p>

        <div class="cl-trk-steps" id="clTrkSteps">
            <div class="cl-trk-steps-progress" id="clTrkProgress"></div>
            <div class="cl-trk-step" id="clTrkStep0"><div class="cl-trk-step-dot">1</div>{{ ___('Received') }}</div>
            <div class="cl-trk-step" id="clTrkStep1"><div class="cl-trk-step-dot">2</div>{{ ___('Processing') }}</div>
            <div class="cl-trk-step" id="clTrkStep2"><div class="cl-trk-step-dot">3</div><span id="clTrkStep2Label">{{ ___('Ready') }}</span></div>
            <div class="cl-trk-step" id="clTrkStep3"><div class="cl-trk-step-dot">4</div>{{ ___('Done') }}</div>
        </div>
    </div>

    <div class="cl-trk-card">
        <div class="cl-trk-row"><span class="cl-trk-label">{{ ___('Name') }}</span><span>{{ $order->customer_name }}</span></div>
        @if($order->phone_number)
            <div class="cl-trk-row"><span class="cl-trk-label">{{ ___('Phone') }}</span><span dir="ltr">{{ $order->phone_number }}</span></div>
        @endif
        @if($modeLabel)
            <div class="cl-trk-row"><span class="cl-trk-label">{{ ___('Fulfillment') }}</span><span>{{ $modeLabel }}</span></div>
        @endif
        @if($order->address)
            <div class="cl-trk-row"><span class="cl-trk-label">{{ ___('Address') }}</span><span>{{ $order->address }}</span></div>
        @endif
    </div>

    <div class="cl-trk-card cl-trk-card--totals">
        @foreach($clLines as $line)
            <div class="cl-trk-row">
                <span>
                    {{ $line['name'] }} &times; {{ $line['qty'] }}
                    @if($line['note'])<span class="cl-trk-note">{{ $line['note'] }}</span>@endif
                </span>
                <span>{{ $formatPrice($line['total']) }}</span>
            </div>
        @endforeach

        <div class="cl-trk-totals">
            <div class="cl-trk-row"><span class="cl-trk-label">{{ ___('Subtotal') }}</span><span>{{ $formatPrice($clSubtotal) }}</span></div>
            {{-- No separate "Shipping"/"Delivery" row: Order::getTotalAttribute()
                 (the authoritative total this receipt must reconcile with —
                 theme-totals-contract) only folds restaurant_delivery_charge
                 in for type === 'delivery', never for a goods 'ship'/
                 'pickup_slot'/'in_store' order — showing one here would make
                 this receipt's own arithmetic disagree with $order->total
                 below it. See index.blade.php's BUILD NOTES for the full
                 rationale (same reason the cart preview never shows a
                 "Delivery"/"Service fee" line either). --}}
            @if($clTax > 0)
                <div class="cl-trk-row"><span class="cl-trk-label">{{ ___('Tax') }}</span><span>{{ $formatPrice($clTax) }}</span></div>
            @endif
            @if($order->tip_amount > 0)
                <div class="cl-trk-row"><span class="cl-trk-label">{{ ___('Tip') }}</span><span>{{ $formatPrice($order->tip_amount) }}</span></div>
            @endif
            @if($order->discount_amount > 0)
                <div class="cl-trk-row"><span class="cl-trk-label">{{ ___('Discount') }}</span><span style="color:var(--cl-ok)">-{{ $formatPrice($order->discount_amount) }}</span></div>
            @endif
            {{-- `total` is the authoritative accessor the customer is charged on
                 (theme-totals-contract) — never the `price` column. --}}
            <div class="cl-trk-row cl-trk-total"><span>{{ ___('Total') }}</span><span>{{ $formatPrice($order->total) }}</span></div>
        </div>
    </div>

    <a href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" class="cl-trk-back">{{ ___('Back to store') }}</a>
</div>
@endsection

{{-- carely's own layout.blade.php only defines @stack('style') + @stack('script')
     (no scripts_at_bottom/style_at_top — those are the SHARED food
     store_templates/layout.blade.php's stacks; carely is self-contained,
     like shelf). --}}
@push('script')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    function el(id) { return document.getElementById(id); }
    function setText(id, txt) { var n = el(id); if (n) { n.textContent = txt; } }

    // Goods order states — RetailOrderBoardService::STATES (new -> packing ->
    // ready -> shipped|collected -> done), the SAME state machine every goods
    // vertical's track page reads (no pharmacist-review state — D3, OTC-only,
    // no Rx, ever). `ship` vs. pickup/in-store gets its own wording for the
    // 3rd step, same as shelf's own track.blade.php keys off type.
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
    if (isShip) { setText('clTrkStep2Label', @json(___('Shipped'))); }

    function updateUI(status) {
        var cfg = STATUSES[status] || STATUSES.new;
        var host = el('clTrkBadge');
        if (host) {
            var badge = document.createElement('span');
            badge.className = 'cl-trk-badge ' + cfg.badgeClass;
            var dot = document.createElement('span');
            dot.className = 'cl-trk-dot';
            badge.appendChild(dot);
            badge.appendChild(document.createTextNode(' ' + cfg.badge));
            host.innerHTML = '';
            host.appendChild(badge);
        }
        setText('clTrkTitle', cfg.title);
        setText('clTrkDesc', cfg.desc);

        var steps = ['clTrkStep0', 'clTrkStep1', 'clTrkStep2', 'clTrkStep3'];
        steps.forEach(function (id, idx) {
            var n = el(id);
            if (!n) { return; }
            n.classList.remove('is-active', 'is-done');
            if (cfg.step < 0) { return; }
            if (idx < cfg.step) { n.classList.add('is-done'); }
            else if (idx === cfg.step) { n.classList.add('is-active'); }
        });
        var bar = el('clTrkProgress');
        if (bar) { bar.style.width = cfg.progress + '%'; }
    }

    updateUI(@json((string) $order->status));

    // Same JSON contract every track page's poll uses (ManagesOrders::
    // trackOrder — `wantsJson()`/`ajax()` branch): { status, ... }. Simplified
    // single-purpose poller (no shared window.mbTrackPoll — carely is
    // self-contained) with the same visibility-aware backoff + terminal-stop
    // shape as track-poll.blade.php.
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
