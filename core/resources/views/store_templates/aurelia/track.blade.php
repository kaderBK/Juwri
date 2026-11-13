{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.aurelia.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalVocab;

    /* ---------------------------------------------------------------------
       aurelia — in-room dining / spa order tracking.

       Reuses the exact data contract store_templates/shelf/track.blade.php
       established for the goods-commerce pipeline (ManagesOrders::
       trackOrder() — controller, NOT edited here): $order, $store,
       $postOptions (plain stdClass of option_name => option_value), $theme,
       $plan, $prepEta, $walletTx. No $vocab/$table — computed locally below,
       same as index.blade.php does. GoodsCheckoutController::place() redirects
       here (route('store.orderTrack', $order->track_token)) on a
       successful in-room dining / spa checkout, including one placed with
       payment_method=charge_to_room from the cart sheet.

       Status timeline: a goods order runs through RetailOrderBoardService's
       state machine (new → packing → ready → shipped|collected → done), NOT
       the food KDS pipeline — see shelf/track.blade.php for the annotated
       original this mirrors, restyled to aurelia's own tokens.
       --------------------------------------------------------------------- */
    $vocab = app(VerticalVocab::class)->map($store->store_type ?? null);

    // P1 currency fix — trackOrder() never sets config('settings.currency_sign')
    // to this STORE's own value, so — like shelf/aurora's own track pages —
    // this reads the store's sign/decimals off $postOptions directly rather
    // than price_symbol_format() (which would print the platform default).
    // Store-scoped money: store sign + gap + decimals, position-aware, with USD/riyal
    // LTR-isolated so the sign never bidi-flips in RTL. Centralises the old inline
    // money_ltr() closure and adds the Saudi Riyal (U+20C1) isolation it missed.
    $formatPrice = new \Juwri\Core\Support\StoreMoneyFormatter($store->id);

    $fulfillment = app(FulfillmentRegistry::class);
    $modeLabel = $order->type ? $fulfillment->label((string) $order->type) : null;
    $isChargedToRoom = (string) ($order->payment_method ?? '') === 'charge_to_room';

    /* Same receipt contract as every other kept theme's track page (theme-
       totals-contract: match Order::getTotalAttribute(), tax PRE-discount) —
       see shelf/track.blade.php for the annotated original this mirrors. */
    $auSubtotal = 0;
    $auLines = [];
    foreach ($order->items as $item) {
        if (! $item->menu) {
            continue;
        }
        $unit = (float) $item->menu->price;
        if ($item->variation && $item->variant) {
            $unit = (float) $item->variant->price;
        }
        // Snapshotted per-line price authority (scheduled / happy-hour price
        // resolved at order time) — mirrors Order::computeMoney(), which reads
        // it back so a historical order re-totals to what the guest was charged.
        if ($item->unit_price !== null) {
            $unit = (float) $item->unit_price;
        }
        $bits = [];
        foreach ((array) ($item->options ?? []) as $grp) {
            foreach ((array) ($grp['selected'] ?? []) as $opt) {
                $unit += (float) ($opt['price'] ?? 0);
                if (! empty($opt['name'])) {
                    $bits[] = $opt['name'];
                }
            }
        }
        // Add-on extras (MenuExtra → OrderItemExtra seam). Order::computeMoney()
        // — the authoritative total this receipt must reconcile with — charges
        // these, so omitting them made the subtotal (and the tax recomputed on
        // it) fall short of $order->total. Same snapshot-then-fallback price.
        foreach ($item->itemExtras as $itemExtra) {
            $extraPrice = $itemExtra->price ?? optional($itemExtra->extra)->price;
            if ($extraPrice !== null) {
                $unit += (float) $extraPrice;
            }
            $extraName = optional($itemExtra->extra)->title;
            if (! empty($extraName)) {
                $bits[] = $extraName;
            }
        }
        $line = $unit * $item->quantity;
        $auSubtotal += $line;
        $varLabel = [];
        if ($item->variation && $item->variant) {
            $axesLabel = trim((string) ($item->variant->axes_label ?? ''));
            if ($axesLabel !== '') {
                $varLabel[] = $axesLabel;
            }
        }
        $auNoteBits = array_values(array_filter(array_merge($varLabel, $bits)));
        $auLines[] = [
            'name' => $item->menu->name,
            'qty' => $item->quantity,
            'note' => implode(' · ', $auNoteBits),
            'total' => $line,
        ];
    }
    $auTax = \App\Models\Order::computeTax($auSubtotal, $postOptions);
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    :root{
        --au-bg:#faf7f2; --au-surface:#ffffff; --au-ink:#221c14; --au-muted:#8a7f6c;
        --au-line:#e8ddc9; --au-accent:{{ $store->color ?: '#b08d57' }}; --au-accent-ink:#231a08;
        --au-danger:#a3342c; --au-ok:#2f6d4f; --au-radius:18px;
        --au-serif:'Cormorant Garamond', Georgia, 'Times New Roman', serif;
        --au-sans:'Saudi Riyal', 'Jost', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --au-bg:#100e09; --au-surface:#1a160e; --au-ink:#f4ecdc; --au-muted:#b6a98e; --au-line:#332a19; --au-accent-ink:#180f02;
        }
    }
    :root[data-theme="dark"]{
        --au-bg:#100e09; --au-surface:#1a160e; --au-ink:#f4ecdc; --au-muted:#b6a98e; --au-line:#332a19; --au-accent-ink:#180f02;
    }
    *{box-sizing:border-box}
    .au-body{margin:0;background:var(--au-bg);color:var(--au-ink);font-family:var(--au-sans);-webkit-font-smoothing:antialiased}
    .au-trk{max-width:560px;margin:0 auto;padding:30px 18px 84px}
    .au-trk-head{text-align:center;margin-bottom:22px}
    .au-trk-head h1{font-family:var(--au-serif);font-size:1.5rem;font-weight:600;margin:0 0 4px}
    .au-trk-head p{color:var(--au-muted);font-size:.86rem;margin:0}
    .au-trk-card{background:var(--au-surface);border:1px solid var(--au-line);border-radius:var(--au-radius);
        padding:20px;margin-bottom:16px}
    .au-trk-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;border-radius:999px;
        font-weight:700;font-size:.76rem;text-transform:uppercase;letter-spacing:.03em;margin-bottom:14px}
    .au-trk-badge.is-pending{background:color-mix(in srgb, #b45309 14%, transparent);color:#b45309}
    .au-trk-badge.is-processing{background:color-mix(in srgb, #2563eb 14%, transparent);color:#2563eb}
    .au-trk-badge.is-ready{background:color-mix(in srgb, var(--au-ok) 14%, transparent);color:var(--au-ok)}
    .au-trk-badge.is-done{background:color-mix(in srgb, var(--au-ok) 20%, transparent);color:var(--au-ok)}
    .au-trk-badge.is-cancelled{background:color-mix(in srgb, var(--au-danger) 14%, transparent);color:var(--au-danger)}
    .au-trk-dot{width:8px;height:8px;border-radius:50%;background:currentColor}
    .au-trk-title{font-family:var(--au-serif);font-size:1.2rem;font-weight:600;margin:0 0 6px}
    .au-trk-desc{color:var(--au-muted);font-size:.86rem;line-height:1.5;margin:0 0 18px}
    .au-trk-steps{display:flex;justify-content:space-between;position:relative;margin:16px 0 4px}
    .au-trk-steps::before{content:'';position:absolute;top:15px;inset-inline-start:0;inset-inline-end:0;height:3px;background:var(--au-line)}
    .au-trk-steps-progress{position:absolute;top:15px;inset-inline-start:0;height:3px;background:var(--au-accent);width:0%;transition:width .5s ease}
    [dir="rtl"] .au-trk-steps-progress{inset-inline-start:auto;inset-inline-end:0}
    .au-trk-step{position:relative;text-align:center;flex:1;font-size:.68rem;color:var(--au-muted);font-weight:600}
    .au-trk-step-dot{width:32px;height:32px;border-radius:50%;background:var(--au-surface);border:3px solid var(--au-line);
        display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-weight:700;color:var(--au-muted)}
    .au-trk-step.is-active .au-trk-step-dot{border-color:var(--au-accent);background:var(--au-accent);color:var(--au-accent-ink)}
    .au-trk-step.is-done .au-trk-step-dot{border-color:var(--au-accent);color:var(--au-accent)}
    .au-trk-step.is-active,.au-trk-step.is-done{color:var(--au-ink)}
    .au-trk-row{display:flex;justify-content:space-between;gap:10px;font-size:.86rem;margin-bottom:10px}
    .au-trk-row .au-trk-note{display:block;color:var(--au-muted);font-size:.76rem;margin-top:2px}
    .au-trk-label{color:var(--au-muted)}
    .au-trk-charge{display:flex;align-items:center;gap:8px;background:color-mix(in srgb, var(--au-accent) 12%, transparent);
        border-radius:12px;padding:10px 12px;font-size:.82rem;font-weight:600;margin-bottom:16px}
    .au-trk-charge svg{width:17px;height:17px;color:var(--au-accent);flex:0 0 auto}
    .au-trk-totals{margin-top:14px;padding-top:14px;border-top:1px dashed var(--au-line)}
    .au-trk-total{display:flex;justify-content:space-between;font-weight:700;font-size:1rem}
    .au-trk-back{display:block;text-align:center;margin-top:24px;color:var(--au-accent);
        font-family:var(--au-serif);font-weight:600;font-size:1rem;text-decoration:none}

    /* Desktop layer (additive-only, mirrors the storefront's own
       responsive contract) — status/customer details left, a sticky
       order-totals card right, same pattern as the other kept themes'
       track pages. Nothing below 768px changes. */
    @media (min-width: 1024px){
        .au-trk{max-width:920px;padding-inline:32px;box-sizing:border-box;display:grid;
            grid-template-columns:1fr 380px;column-gap:28px;align-items:start}
        .au-trk-head{grid-column:1 / -1}
        .au-trk-card{grid-column:1}
        /* Span the two left-column cards (status + guest). Do NOT end at
           `-1`: grid-template-rows is never declared, so the explicit row
           grid has a single line and `-1` resolves to line 1 — `grid-row:2/-1`
           becomes `2/1`, which the browser reverses to `1/2` and drops the
           totals card at the very top-left, above the header. `span 2` is
           row-count-relative and immune to that. */
        .au-trk-card--totals{grid-column:2;grid-row:2 / span 2;position:sticky;top:28px}
        .au-trk-charge{grid-column:1 / -1}
        /* the charge banner (when present) is the first thing after the
           header, so it pushes the two left-column cards down one row —
           shift the totals card's own start to match, or it would
           overlap the full-width banner at row 2 */
        .au-trk:has(.au-trk-charge) .au-trk-card--totals{grid-row:3 / span 2}
        .au-trk-back{grid-column:1 / -1}
    }
    @media (min-width: 1280px){
        .au-trk{max-width:1000px}
    }
</style>
@endpush

@section('content')
<div class="au-trk">
    <header class="au-trk-head">
        <h1>{{ $store->title }}</h1>
        <p>{{ $vocab['order'] ?? ___('Order') }} #{{ $order->id }} &bull; {{ date_formating($order->created_at, 'h:i A') }}</p>
    </header>

    @if($isChargedToRoom)
        <div class="au-trk-charge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
            <span>{{ ___('Charged to your room folio') }}</span>
        </div>
    @endif

    <div class="au-trk-card">
        <div id="auTrkBadge"></div>
        <div class="au-trk-title" id="auTrkTitle">&nbsp;</div>
        <p class="au-trk-desc" id="auTrkDesc"></p>

        <div class="au-trk-steps" id="auTrkSteps">
            <div class="au-trk-steps-progress" id="auTrkProgress"></div>
            <div class="au-trk-step" id="auTrkStep0"><div class="au-trk-step-dot">1</div>{{ ___('Received') }}</div>
            <div class="au-trk-step" id="auTrkStep1"><div class="au-trk-step-dot">2</div>{{ ___('Processing') }}</div>
            <div class="au-trk-step" id="auTrkStep2"><div class="au-trk-step-dot">3</div><span id="auTrkStep2Label">{{ ___('Ready') }}</span></div>
            <div class="au-trk-step" id="auTrkStep3"><div class="au-trk-step-dot">4</div>{{ ___('Done') }}</div>
        </div>
    </div>

    <div class="au-trk-card">
        <div class="au-trk-row"><span class="au-trk-label">{{ ___('Name') }}</span><span>{{ $order->customer_name }}</span></div>
        @if($order->phone_number)
            <div class="au-trk-row"><span class="au-trk-label">{{ ___('Phone') }}</span><span dir="ltr">{{ $order->phone_number }}</span></div>
        @endif
        @if($modeLabel)
            <div class="au-trk-row"><span class="au-trk-label">{{ ___('Fulfillment') }}</span><span>{{ $modeLabel }}</span></div>
        @endif
        @if($order->address)
            <div class="au-trk-row"><span class="au-trk-label">{{ ___('Address') }}</span><span>{{ $order->address }}</span></div>
        @endif
    </div>

    <div class="au-trk-card au-trk-card--totals">
        @foreach($auLines as $line)
            <div class="au-trk-row">
                <span>
                    {{ $line['name'] }} &times; {{ $line['qty'] }}
                    @if($line['note'])<span class="au-trk-note">{{ $line['note'] }}</span>@endif
                </span>
                <span>{{ $formatPrice($line['total']) }}</span>
            </div>
        @endforeach

        <div class="au-trk-totals">
            <div class="au-trk-row"><span class="au-trk-label">{{ ___('Subtotal') }}</span><span>{{ $formatPrice($auSubtotal) }}</span></div>
            {{-- No separate "Delivery"/"Shipping" row: Order::getTotalAttribute()
                 (the authoritative total this receipt must reconcile with — see
                 theme-totals-contract) only folds a charge in for the matching
                 fulfillment type — showing one here unconditionally would make
                 this receipt's own arithmetic disagree with $order->total below. --}}
            @if($auTax > 0)
                <div class="au-trk-row"><span class="au-trk-label">{{ ___('Tax') }}</span><span>{{ $formatPrice($auTax) }}</span></div>
            @endif
            @if($order->tip_amount > 0)
                <div class="au-trk-row"><span class="au-trk-label">{{ ___('Tip') }}</span><span>{{ $formatPrice($order->tip_amount) }}</span></div>
            @endif
            @if($order->discount_amount > 0)
                <div class="au-trk-row"><span class="au-trk-label">{{ ___('Discount') }}</span><span style="color:var(--au-ok)">-{{ $formatPrice($order->discount_amount) }}</span></div>
            @endif
            {{-- `total` is the authoritative accessor the guest is charged on
                 (theme-totals-contract) — never the `price` column. --}}
            <div class="au-trk-row au-trk-total"><span>{{ ___('Total') }}</span><span>{{ $formatPrice($order->total) }}</span></div>
        </div>
    </div>

    <a href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" class="au-trk-back">{{ ___('Back to store') }}</a>
</div>
@endsection

{{-- aurelia's own layout.blade.php only defines @stack('style') + @stack('script')
     (no scripts_at_bottom/style_at_top — those are the SHARED food
     store_templates/layout.blade.php's stacks; aurelia is self-contained). --}}
@push('script')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    function el(id) { return document.getElementById(id); }
    function setText(id, txt) { var n = el(id); if (n) { n.textContent = txt; } }

    // Carry the guest's dark/light choice from the main storefront (index.blade.php's
    // #auDarkToggle, saved under this same key) onto the receipt page — this page has
    // no toggle of its own, so it otherwise only follows the system preference.
    try {
        var savedTheme = window.localStorage.getItem('aurelia-theme-' + @json((string) $store->id));
        if (savedTheme) { document.documentElement.setAttribute('data-theme', savedTheme); }
    } catch (e) { /* storage may be unavailable — system preference stands */ }

    // Goods order states — RetailOrderBoardService::STATES (new → packing →
    // ready → shipped|collected → done), NOT the food KDS pipeline. Labels
    // mirror owner/orders/board.blade.php so the guest and the
    // staff board agree. `ship` vs. pickup/in-room gets its own wording for
    // the 3rd step, same as shelf/aurora key "Ready"/"On the Way" off type.
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
            title: @json(___('Preparing your order')), desc: @json(___('The kitchen is preparing your order.')),
            badge: @json(___('Processing')), badgeClass: 'is-processing', progress: 37.5, step: 1,
        },
        ready: {
            title: isShip ? @json(___('Ready to ship')) : @json(___('On its way to your room')),
            desc: isShip ? @json(___('Your order is packed and will ship shortly.')) : @json(___('Your order is on its way to your room.')),
            badge: @json(___('Ready')), badgeClass: 'is-ready', progress: 62.5, step: 2,
        },
        shipped: {
            title: @json(___('On the way')), desc: @json(___('Your order has shipped and is on its way.')),
            badge: @json(___('Shipped')), badgeClass: 'is-ready', progress: 87.5, step: 2,
        },
        collected: {
            title: @json(___('Delivered')), desc: @json(___('Your order has been delivered. Enjoy!')),
            badge: @json(___('Delivered')), badgeClass: 'is-ready', progress: 87.5, step: 2,
        },
        done: {
            title: @json(___('Completed')), desc: @json(___('This order is complete. Thank you for staying with us!')),
            badge: @json(___('Done')), badgeClass: 'is-done', progress: 100, step: 3,
        },
        cancelled: {
            title: @json(___('Order Cancelled')), desc: @json(___('This order has been cancelled. Please contact reception for assistance.')),
            badge: @json(___('Cancelled')), badgeClass: 'is-cancelled', progress: 0, step: -1,
        },
    };
    if (isShip) { setText('auTrkStep2Label', @json(___('Shipped'))); }
    else { setText('auTrkStep2Label', @json(___('On the way'))); }

    function updateUI(status) {
        var cfg = STATUSES[status] || STATUSES.new;
        var host = el('auTrkBadge');
        if (host) {
            var badge = document.createElement('span');
            badge.className = 'au-trk-badge ' + cfg.badgeClass;
            var dot = document.createElement('span');
            dot.className = 'au-trk-dot';
            badge.appendChild(dot);
            badge.appendChild(document.createTextNode(' ' + cfg.badge));
            host.innerHTML = '';
            host.appendChild(badge);
        }
        setText('auTrkTitle', cfg.title);
        setText('auTrkDesc', cfg.desc);

        var steps = ['auTrkStep0', 'auTrkStep1', 'auTrkStep2', 'auTrkStep3'];
        steps.forEach(function (id, idx) {
            var n = el(id);
            if (!n) { return; }
            n.classList.remove('is-active', 'is-done');
            if (cfg.step < 0) { return; }
            if (idx < cfg.step) { n.classList.add('is-done'); }
            else if (idx === cfg.step) { n.classList.add('is-active'); }
        });
        var bar = el('auTrkProgress');
        if (bar) { bar.style.width = cfg.progress + '%'; }
    }

    updateUI(@json((string) $order->status));

    // Same JSON contract every track page's poll uses (ManagesOrders::
    // trackOrder — `wantsJson()`/`ajax()` branch): { status, ... }. A
    // self-contained poller (aurelia loads no shared script.js) with the
    // same visibility-aware backoff + terminal-stop shape as shelf's.
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
