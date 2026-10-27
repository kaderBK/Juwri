{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.torque.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalVocab;

    /* ---------------------------------------------------------------------
       torque — "Torque Garage" order tracking (goods pipeline).

       Same data contract as shelf/track.blade.php (this theme's own chassis
       — NOT the shared store_templates/layout.blade.php / food track view):
       $order, $store, $postOptions (plain stdClass of option_name =>
       option_value), $theme, $plan, $prepEta, $walletTx. No $vocab/$table —
       computed locally below, same as index.blade.php does.

       Status timeline: a goods order runs through RetailOrderBoardService's
       state machine (new → packing → ready → shipped|collected → done), NOT
       the food KDS pipeline (Juwri\Modules\Ordering\Enums\OrderStatus) — see that service's own
       docblock. Labels mirror owner/orders/board.blade.php
       (the dashboard board that already hardcodes them) so the customer and
       the staff board agree — byte-identical wording to shelf's own track
       page, only the skin changes here.
       --------------------------------------------------------------------- */
    $vocab = app(VerticalVocab::class)->map($store->store_type ?? null);

    // P1 currency fix — trackOrder() never sets config('settings.currency_sign')
    // to this STORE's own value (unlike the storefront's publicView, which
    // does), so — like shelf/aurora's own track.blade.php — this reads the
    // store's sign/decimals off $postOptions directly rather than
    // price_symbol_format() (which would print the platform default).
    // Store-scoped money: store sign + gap + decimals, position-aware, with USD/riyal
    // LTR-isolated so the sign never bidi-flips in RTL. Centralises the old inline
    // money_ltr() closure and adds the Saudi Riyal (U+20C1) isolation it missed.
    $formatPrice = new \Juwri\Core\Support\StoreMoneyFormatter($store->id);

    $fulfillment = app(FulfillmentRegistry::class);
    $modeLabel = $order->type ? $fulfillment->label((string) $order->type) : null;

    /* Same receipt contract as every other kept theme's track page
       (theme-totals-contract: match Order::getTotalAttribute(), tax
       PRE-discount) — see shelf/track.blade.php for the annotated original
       this mirrors. itemExtras/options are food "extras"/choice-groups a
       goods item never has, but the loop stays generic (empty for a goods
       order, so it is a silent no-op) rather than forking a goods-only copy.
       A part's core charge (attrs.core_charge) is disclosed as an
       informational note on its line — never added into the line/subtotal
       arithmetic, so this receipt's numbers keep agreeing with
       Order::getTotalAttribute() below. */
    $tqSubtotal = 0;
    $tqLines = [];
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
        $tqSubtotal += $line;
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
        $tqAttrs = (array) ($item->menu->attributes ?? []);
        $tqCore = isset($tqAttrs['core_charge']) && is_numeric($tqAttrs['core_charge']) ? (float) $tqAttrs['core_charge'] : 0.0;
        $tqNoteBits = array_values(array_filter(array_merge($varLabel, $bits)));
        $tqLines[] = [
            'name' => $item->menu->name,
            'pn' => $tqAttrs['part_number'] ?? ($item->menu->plu ?? $item->menu->external_sku ?? null),
            'qty' => $item->quantity,
            'note' => implode(' · ', $tqNoteBits),
            'total' => $line,
            'core' => $tqCore * $item->quantity,
        ];
    }
    $tqCoreTotal = array_sum(array_column($tqLines, 'core'));
    $tqTax = \App\Models\Order::computeTax($tqSubtotal, $postOptions);
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    :root{
        --tq-ground:#EEF1F3; --tq-card:#FFFFFF; --tq-card-2:#F3F6F8; --tq-ink:#0E1519; --tq-ink-2:#54626B;
        --tq-ink-3:#8A97A0; --tq-hair:#E4E9ED; --tq-hair-2:#D3DBE0;
        --tq-amber:{{ $store->color ?: '#E6910C' }}; --tq-amber-ink:#fff; --tq-cyan:#0B93AE;
        --tq-fit:#1F9D5E; --tq-fit-bg:rgba(31,157,94,.12); --tq-verify:#B9770B; --tq-nofit:#D23B24;
        --tq-radius:12px; --tq-radius-lg:18px;
        --tq-f-head:"Archivo",system-ui,-apple-system,"Segoe UI",sans-serif;
        --tq-f-sans:"Saudi Riyal","IBM Plex Sans",system-ui,-apple-system,"Segoe UI",sans-serif;
        --tq-f-mono:"IBM Plex Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --tq-ground:#0C0F12; --tq-card:#14181C; --tq-card-2:#1B2126; --tq-ink:#EAEEF1; --tq-ink-2:#96A2AA;
            --tq-ink-3:#5C676E; --tq-hair:#232A30; --tq-hair-2:#2E373E;
            --tq-amber:{{ $store->color ?: '#F5A623' }}; --tq-amber-ink:#3A2A08; --tq-cyan:#27D3EE;
            --tq-fit:#35D07E; --tq-fit-bg:rgba(53,208,126,.13); --tq-verify:#F5A623; --tq-nofit:#F0553D;
        }
    }
    :root[data-theme="dark"]{
        --tq-ground:#0C0F12; --tq-card:#14181C; --tq-card-2:#1B2126; --tq-ink:#EAEEF1; --tq-ink-2:#96A2AA;
        --tq-ink-3:#5C676E; --tq-hair:#232A30; --tq-hair-2:#2E373E;
        --tq-amber:{{ $store->color ?: '#F5A623' }}; --tq-amber-ink:#3A2A08; --tq-cyan:#27D3EE;
        --tq-fit:#35D07E; --tq-fit-bg:rgba(53,208,126,.13); --tq-verify:#F5A623; --tq-nofit:#F0553D;
    }
    *{box-sizing:border-box}
    .tq-body{margin:0;background:var(--tq-ground);color:var(--tq-ink);font-family:var(--tq-f-sans,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif);
        background-image:linear-gradient(rgba(127,127,127,.035) 1px,transparent 1px),linear-gradient(90deg,rgba(127,127,127,.035) 1px,transparent 1px);background-size:26px 26px;}
    .tq-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:20}
    .tq-skip:focus{inset-inline-start:0}
    .mono{font-family:var(--tq-f-mono)}
    .tq-trk{max-width:560px;margin:0 auto;padding:28px 16px 80px}
    .tq-trk-head{text-align:center;margin-block-end:20px}
    .tq-trk-head h1{font-family:var(--tq-f-head);font-size:1.2rem;font-weight:800;text-transform:uppercase;letter-spacing:.02em;margin:0 0 4px}
    .tq-trk-head p{color:var(--tq-ink-2);font-size:.82rem;margin:0;font-family:var(--tq-f-mono)}
    .tq-trk-card{background:var(--tq-card);border:1px solid var(--tq-hair);border-radius:var(--tq-radius-lg);padding:20px;margin-block-end:16px}
    .tq-trk-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;border-radius:999px;
        font-family:var(--tq-f-mono);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;margin-block-end:14px}
    .tq-trk-badge.is-pending{background:color-mix(in srgb, var(--tq-verify) 16%, transparent);color:var(--tq-verify)}
    .tq-trk-badge.is-processing{background:color-mix(in srgb, var(--tq-cyan) 16%, transparent);color:var(--tq-cyan)}
    .tq-trk-badge.is-ready{background:var(--tq-fit-bg);color:var(--tq-fit)}
    .tq-trk-badge.is-done{background:var(--tq-fit-bg);color:var(--tq-fit)}
    .tq-trk-badge.is-cancelled{background:color-mix(in srgb, var(--tq-nofit) 16%, transparent);color:var(--tq-nofit)}
    .tq-trk-dot{width:8px;height:8px;border-radius:50%;background:currentColor}
    .tq-trk-title{font-family:var(--tq-f-head);font-size:1.05rem;font-weight:800;text-transform:uppercase;letter-spacing:.01em;margin:0 0 6px}
    .tq-trk-desc{color:var(--tq-ink-2);font-size:.86rem;line-height:1.5;margin:0 0 18px}
    /* torque-instrument vertical timeline (mirrors the demo's .track2/.tstep confirm screen) */
    .tq-steps{position:relative;margin-block-start:4px}
    .tq-step{display:flex;gap:14px;position:relative;padding-block-end:22px}
    .tq-step:last-child{padding-block-end:2px}
    .tq-step::before{content:'';position:absolute;inset-inline-start:12px;top:26px;bottom:0;width:2px;background:var(--tq-hair-2)}
    .tq-step:last-child::before{display:none}
    .tq-step .dot{width:26px;height:26px;border-radius:8px;flex:none;display:flex;align-items:center;justify-content:center;
        border:2px solid var(--tq-hair-2);background:var(--tq-card);color:var(--tq-ink-3);z-index:1;font-family:var(--tq-f-mono);font-size:.72rem;font-weight:700}
    .tq-step .tt{font-family:var(--tq-f-head);font-weight:700;font-size:.86rem;text-transform:uppercase;letter-spacing:.01em}
    .tq-step .td{font-family:var(--tq-f-mono);font-size:.72rem;color:var(--tq-ink-2);margin-block-start:1px}
    .tq-step.is-done .dot{background:var(--tq-amber);border-color:var(--tq-amber);color:var(--tq-amber-ink)}
    .tq-step.is-done::before{background:var(--tq-amber)}
    .tq-step.is-active .dot{border-color:var(--tq-cyan);color:var(--tq-cyan);animation:tqPulse 1.6s infinite}
    @keyframes tqPulse{0%{box-shadow:0 0 0 0 rgba(39,211,238,.4)}70%{box-shadow:0 0 0 8px rgba(39,211,238,0)}100%{box-shadow:0 0 0 0 rgba(39,211,238,0)}}
    .tq-step.is-active .tt,.tq-step.is-done .tt{color:var(--tq-ink)}
    .tq-trk-row{display:flex;justify-content:space-between;gap:10px;font-size:.86rem;margin-block-end:10px}
    .tq-trk-row .tq-trk-note{display:block;color:var(--tq-ink-2);font-size:.74rem;margin-block-start:2px;font-family:var(--tq-f-mono)}
    .tq-trk-label{color:var(--tq-ink-2)}
    .tq-trk-pn{font-family:var(--tq-f-mono);font-size:.7rem;color:var(--tq-ink-3);margin-inline-end:6px}
    .tq-trk-totals{margin-block-start:14px;padding-block-start:14px;border-block-start:1px dashed var(--tq-hair)}
    .tq-trk-total{display:flex;justify-content:space-between;font-weight:800;font-size:1rem;font-family:var(--tq-f-head)}
    .tq-trk-core-note{font-size:.72rem;color:var(--tq-ink-3);margin-block-start:6px;font-family:var(--tq-f-mono)}
    .tq-trk-back{display:block;text-align:center;margin-block-start:24px;color:var(--tq-amber);
        font-weight:700;font-size:.86rem;text-decoration:none;font-family:var(--tq-f-head);text-transform:uppercase;letter-spacing:.03em}
    @media (prefers-reduced-motion: reduce){ .tq-step.is-active .dot{animation:none} }

    /* Desktop polish (additive only — nothing below 768px changes). A
       receipt/tracking screen is inherently a single reading column, so the
       desktop "win" here is a wider, better-proportioned centered card
       instead of a lone narrow phone column adrift in grey, not a fabricated
       sidebar. All colors reuse existing --tq-* tokens (dark mode already
       covered) and no physical left/right is introduced (RTL unaffected). */
    @media (min-width: 768px){
        .tq-trk{padding-inline:24px}
    }
    @media (min-width: 1024px){
        .tq-trk{max-width:680px;padding-block-start:48px}
        .tq-trk-card{padding:26px 28px}
        .tq-trk-head h1{font-size:1.4rem}
    }
</style>
@endpush

@section('content')
<div class="tq-trk">
    <header class="tq-trk-head">
        <h1>{{ $store->title }}</h1>
        <p>{{ $vocab['order'] ?? ___('Order') }} #{{ $order->id }} &bull; {{ date_formating($order->created_at, 'h:i A') }}</p>
    </header>

    <div class="tq-trk-card">
        <div id="tqTrkBadge"></div>
        <div class="tq-trk-title" id="tqTrkTitle">&nbsp;</div>
        <p class="tq-trk-desc" id="tqTrkDesc"></p>

        <div class="tq-steps" id="tqTrkSteps">
            <div class="tq-step" id="tqTrkStep0"><div class="dot">1</div><div><div class="tt">{{ ___('Received') }}</div><div class="td" id="tqTrkStep0Time">&mdash;</div></div></div>
            <div class="tq-step" id="tqTrkStep1"><div class="dot">2</div><div><div class="tt">{{ ___('Processing') }}</div><div class="td" id="tqTrkStep1Time">&mdash;</div></div></div>
            <div class="tq-step" id="tqTrkStep2"><div class="dot">3</div><div><div class="tt"><span id="tqTrkStep2Label">{{ ___('Ready') }}</span></div><div class="td" id="tqTrkStep2Time">&mdash;</div></div></div>
            <div class="tq-step" id="tqTrkStep3"><div class="dot">4</div><div><div class="tt">{{ ___('Done') }}</div><div class="td" id="tqTrkStep3Time">&mdash;</div></div></div>
        </div>
    </div>

    <div class="tq-trk-card">
        <div class="tq-trk-row"><span class="tq-trk-label">{{ ___('Name') }}</span><span>{{ $order->customer_name }}</span></div>
        @if($order->phone_number)
            <div class="tq-trk-row"><span class="tq-trk-label">{{ ___('Phone') }}</span><span dir="ltr" class="mono">{{ $order->phone_number }}</span></div>
        @endif
        @if($modeLabel)
            <div class="tq-trk-row"><span class="tq-trk-label">{{ ___('Fulfillment') }}</span><span>{{ $modeLabel }}</span></div>
        @endif
        @if($order->address)
            <div class="tq-trk-row"><span class="tq-trk-label">{{ ___('Address') }}</span><span>{{ $order->address }}</span></div>
        @endif
    </div>

    <div class="tq-trk-card">
        @foreach($tqLines as $line)
            <div class="tq-trk-row">
                <span>
                    @if($line['pn'])<span class="tq-trk-pn">{{ $line['pn'] }}</span>@endif
                    {{ $line['name'] }} &times; {{ $line['qty'] }}
                    @if($line['note'])<span class="tq-trk-note">{{ $line['note'] }}</span>@endif
                </span>
                <span class="mono">{{ $formatPrice($line['total']) }}</span>
            </div>
        @endforeach

        <div class="tq-trk-totals">
            <div class="tq-trk-row"><span class="tq-trk-label">{{ ___('Subtotal') }}</span><span class="mono">{{ $formatPrice($tqSubtotal) }}</span></div>
            {{-- No separate "Shipping" row: Order::getTotalAttribute() (the
                 authoritative total this receipt must reconcile with — see
                 theme-totals-contract) only folds restaurant_delivery_charge
                 in for type === 'delivery', never for a goods 'ship' order. --}}
            @if($tqTax > 0)
                <div class="tq-trk-row"><span class="tq-trk-label">{{ ___('Tax') }}</span><span class="mono">{{ $formatPrice($tqTax) }}</span></div>
            @endif
            @if($order->tip_amount > 0)
                <div class="tq-trk-row"><span class="tq-trk-label">{{ ___('Tip') }}</span><span class="mono">{{ $formatPrice($order->tip_amount) }}</span></div>
            @endif
            @if($order->discount_amount > 0)
                <div class="tq-trk-row"><span class="tq-trk-label">{{ ___('Discount') }}</span><span class="mono" style="color:var(--tq-fit)">-{{ $formatPrice($order->discount_amount) }}</span></div>
            @endif
            {{-- `total` is the authoritative accessor the customer is charged
                 on (theme-totals-contract) — never the `price` column. --}}
            <div class="tq-trk-row tq-trk-total"><span>{{ ___('Total') }}</span><span>{{ $formatPrice($order->total) }}</span></div>
            @if($tqCoreTotal > 0)
                {{-- Disclosure only (see index.blade.php's cart summary for the
                     same note) — never added into the arithmetic above, so
                     this receipt always agrees with Order::getTotalAttribute(). --}}
                <p class="tq-trk-core-note">{{ ___('Includes :amount in refundable core charges, collected/returned in-store.', ['amount' => $formatPrice($tqCoreTotal)]) }}</p>
            @endif
        </div>
    </div>

    <a href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" class="tq-trk-back">{{ ___('Back to store') }}</a>
</div>
@endsection

{{-- torque's own layout.blade.php only defines @stack('style') + @stack('script')
     (no scripts_at_bottom/style_at_top — those are the SHARED food
     store_templates/layout.blade.php's stacks; torque is self-contained). --}}
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
    // for the 3rd step, same as shelf keys "Ready"/"Shipped" off type.
    var isShip = @json($order->type === 'ship');
    var STATUSES = {
        unpaid: {
            title: @json(___('Awaiting Payment')), desc: @json(___('Please complete your online payment to submit this order.')),
            badge: @json(___('Awaiting Payment')), badgeClass: 'is-cancelled', step: -1,
        },
        new: {
            title: @json(___('Order received')), desc: @json(___('Your order has been received and is awaiting processing.')),
            badge: @json(___('Received')), badgeClass: 'is-pending', step: 0,
        },
        pending: {
            title: @json(___('Order received')), desc: @json(___('Your order has been received and is awaiting processing.')),
            badge: @json(___('Received')), badgeClass: 'is-pending', step: 0,
        },
        pending_approval: {
            title: @json(___('Awaiting approval')), desc: @json(___('Your order has been received and is awaiting confirmation from the store.')),
            badge: @json(___('Awaiting approval')), badgeClass: 'is-pending', step: 0,
        },
        packing: {
            title: @json(___('Preparing your order')), desc: @json(___('The store is packing your parts.')),
            badge: @json(___('Processing')), badgeClass: 'is-processing', step: 1,
        },
        ready: {
            title: isShip ? @json(___('Ready to ship')) : @json(___('Ready for pickup')),
            desc: isShip ? @json(___('Your order is packed and will ship shortly.')) : @json(___('Your order is packed and ready for pickup.')),
            badge: @json(___('Ready')), badgeClass: 'is-ready', step: 2,
        },
        shipped: {
            title: @json(___('On the way')), desc: @json(___('Your order has shipped and is on its way.')),
            badge: @json(___('Shipped')), badgeClass: 'is-ready', step: 2,
        },
        collected: {
            title: @json(___('Collected')), desc: @json(___('Your order has been handed over. Thank you!')),
            badge: @json(___('Collected')), badgeClass: 'is-ready', step: 2,
        },
        done: {
            title: @json(___('Completed')), desc: @json(___('This order is complete. Thank you for shopping with us!')),
            badge: @json(___('Done')), badgeClass: 'is-done', step: 3,
        },
        cancelled: {
            title: @json(___('Order Cancelled')), desc: @json(___('This order has been cancelled. Please contact the store for assistance.')),
            badge: @json(___('Cancelled')), badgeClass: 'is-cancelled', step: -1,
        },
    };
    if (isShip) { setText('tqTrkStep2Label', @json(___('Shipped'))); }

    function updateUI(status) {
        var cfg = STATUSES[status] || STATUSES.new;
        var host = el('tqTrkBadge');
        if (host) {
            var badge = document.createElement('span');
            badge.className = 'tq-trk-badge ' + cfg.badgeClass;
            var dot = document.createElement('span');
            dot.className = 'tq-trk-dot';
            badge.appendChild(dot);
            badge.appendChild(document.createTextNode(' ' + cfg.badge));
            host.innerHTML = '';
            host.appendChild(badge);
        }
        setText('tqTrkTitle', cfg.title);
        setText('tqTrkDesc', cfg.desc);

        var steps = ['tqTrkStep0', 'tqTrkStep1', 'tqTrkStep2', 'tqTrkStep3'];
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
    // trackOrder — `wantsJson()`/`ajax()` branch): { status, ... }. Simplified
    // single-purpose poller (no shared window.mbTrackPoll here — torque is
    // self-contained and does not load the shared script.js) with the same
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

    /* dark/light toggle persistence (no toggle control on the track page
       itself — the store owner's own color/theme choice governs it — but
       honour a saved preference from the storefront so the receipt matches
       what the customer was just looking at). */
    try {
        var saved = window.localStorage.getItem('torque-theme-' + @json((string) ($store->id ?? '')));
        if (saved) { document.documentElement.setAttribute('data-theme', saved); }
    } catch (e) { /* storage may be unavailable — default (system) stands */ }
})();
</script>
@endpush
