{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.shelf.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalVocab;

    /* ---------------------------------------------------------------------
       shelf — goods order tracking (NEW).

       ManagesOrders::trackOrder() previously fell back to the FOOD 'aurora'
       theme's track view for a shelf-templated (retail/goods) store — a
       "Preparing your Food" / "chef" / kitchen-vocab page for someone who
       never ordered food. This is shelf's own vocab-aware equivalent, reusing
       shelf's own layout.blade.php + CSS vars (self-contained, like index.
       blade.php — no shared store_templates/layout.blade.php or script.js).

       Data contract (see ManagesOrders::trackOrder — controller, NOT edited
       here): $order, $store, $postOptions (plain stdClass of option_name =>
       option_value), $theme, $plan, $prepEta, $walletTx. No $vocab/$table —
       computed locally below, same as index.blade.php does.

       Status timeline: a goods order runs through RetailOrderBoardService's
       state machine (new → packing → ready → shipped|collected → done),
       NOT the food KDS pipeline (Juwri\Modules\Ordering\Enums\OrderStatus) aurora's track view
       renders — see that service's own docblock. Labels mirror
       owner/orders/board.blade.php (the dashboard board that
       already hardcodes them) so the customer and the staff board agree.
       --------------------------------------------------------------------- */
    $vocab = app(VerticalVocab::class)->map($store->store_type ?? null);

    // P1 currency fix — trackOrder() never sets config('settings.currency_sign')
    // to this STORE's own value (unlike the storefront's publicView, which
    // does), so — like aurora's own track.blade.php — this reads the store's
    // sign/decimals off $postOptions directly rather than price_symbol_format()
    // (which would print the platform default, not this store's currency).
    // Store-scoped money: store sign + gap + decimals, position-aware, with USD/riyal
    // LTR-isolated so the sign never bidi-flips in RTL. Centralises the old inline
    // money_ltr() closure and adds the Saudi Riyal (U+20C1) isolation it missed.
    $formatPrice = new \Juwri\Core\Support\StoreMoneyFormatter($store->id);

    $fulfillment = app(FulfillmentRegistry::class);
    $modeLabel = $order->type ? $fulfillment->label((string) $order->type) : null;

    /* Same receipt contract as every other kept theme's track page (theme-
       totals-contract: match Order::getTotalAttribute(), tax PRE-discount) —
       see aurora/track.blade.php for the annotated original this mirrors.
       itemExtras/options are food "extras"/choice-groups a goods item never
       has, but the loop stays generic (empty for a goods order, so it is a
       silent no-op) rather than forking a goods-only copy. */
    $shSubtotal = 0;
    $shLines = [];
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
        $shSubtotal += $line;
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
        $shNoteBits = array_values(array_filter(array_merge($varLabel, $bits)));
        $shLines[] = [
            'name' => $item->menu->name,
            'qty' => $item->quantity,
            'note' => implode(' · ', $shNoteBits),
            'total' => $line,
        ];
    }
    $shTax = \App\Models\Order::computeTax($shSubtotal, $postOptions);
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    :root{
        --sh-bg:#f7f7f8; --sh-surface:#ffffff; --sh-ink:#15181d; --sh-muted:#6b7280;
        --sh-line:#e6e7eb; --sh-accent:{{ $store->color ?: '#1f6feb' }}; --sh-accent-ink:#ffffff;
        --sh-danger:#c0322a; --sh-ok:#1a8f4c; --sh-radius:14px;
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --sh-bg:#0f1115; --sh-surface:#181b21; --sh-ink:#f3f4f6; --sh-muted:#9aa1ac; --sh-line:#2a2e37;
        }
    }
    :root[data-theme="dark"]{
        --sh-bg:#0f1115; --sh-surface:#181b21; --sh-ink:#f3f4f6; --sh-muted:#9aa1ac; --sh-line:#2a2e37;
    }
    *{box-sizing:border-box}
    .shelf-body{margin:0;background:var(--sh-bg);color:var(--sh-ink);
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif;}
    .sh-trk{max-width:560px;margin:0 auto;padding:28px 16px 80px}
    .sh-trk-head{text-align:center;margin-block-end:20px}
    .sh-trk-head h1{font-size:1.3rem;font-weight:800;margin:0 0 4px}
    .sh-trk-head p{color:var(--sh-muted);font-size:.86rem;margin:0}
    .sh-trk-card{background:var(--sh-surface);border:1px solid var(--sh-line);border-radius:var(--sh-radius);
        padding:20px;margin-block-end:16px}
    .sh-trk-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;border-radius:999px;
        font-weight:700;font-size:.76rem;text-transform:uppercase;letter-spacing:.03em;margin-block-end:14px}
    .sh-trk-badge.is-pending{background:color-mix(in srgb, #b45309 14%, transparent);color:#b45309}
    .sh-trk-badge.is-processing{background:color-mix(in srgb, #2563eb 14%, transparent);color:#2563eb}
    .sh-trk-badge.is-ready{background:color-mix(in srgb, var(--sh-ok) 14%, transparent);color:var(--sh-ok)}
    .sh-trk-badge.is-done{background:color-mix(in srgb, var(--sh-ok) 20%, transparent);color:var(--sh-ok)}
    .sh-trk-badge.is-cancelled{background:color-mix(in srgb, var(--sh-danger) 14%, transparent);color:var(--sh-danger)}
    .sh-trk-dot{width:8px;height:8px;border-radius:50%;background:currentColor}
    .sh-trk-title{font-size:1.05rem;font-weight:800;margin:0 0 6px}
    .sh-trk-desc{color:var(--sh-muted);font-size:.86rem;line-height:1.5;margin:0 0 18px}
    .sh-trk-steps{display:flex;justify-content:space-between;position:relative;margin:16px 0 4px}
    .sh-trk-steps::before{content:'';position:absolute;top:15px;inset-inline-start:0;inset-inline-end:0;height:3px;background:var(--sh-line)}
    .sh-trk-steps-progress{position:absolute;top:15px;inset-inline-start:0;height:3px;background:var(--sh-accent);width:0%;transition:width .5s ease}
    [dir="rtl"] .sh-trk-steps-progress{inset-inline-start:auto;inset-inline-end:0}
    .sh-trk-step{position:relative;text-align:center;flex:1;font-size:.68rem;color:var(--sh-muted);font-weight:600}
    .sh-trk-step-dot{width:32px;height:32px;border-radius:50%;background:var(--sh-surface);border:3px solid var(--sh-line);
        display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-weight:700;color:var(--sh-muted)}
    .sh-trk-step.is-active .sh-trk-step-dot{border-color:var(--sh-accent);background:var(--sh-accent);color:var(--sh-accent-ink)}
    .sh-trk-step.is-done .sh-trk-step-dot{border-color:var(--sh-accent);color:var(--sh-accent)}
    .sh-trk-step.is-active,.sh-trk-step.is-done{color:var(--sh-ink)}
    .sh-trk-row{display:flex;justify-content:space-between;gap:10px;font-size:.86rem;margin-block-end:10px}
    .sh-trk-row .sh-trk-note{display:block;color:var(--sh-muted);font-size:.76rem;margin-block-start:2px}
    .sh-trk-label{color:var(--sh-muted)}
    .sh-trk-totals{margin-block-start:14px;padding-block-start:14px;border-block-start:1px dashed var(--sh-line)}
    .sh-trk-total{display:flex;justify-content:space-between;font-weight:800;font-size:1rem}
    .sh-trk-back{display:block;text-align:center;margin-block-start:24px;color:var(--sh-accent);
        font-weight:700;font-size:.86rem;text-decoration:none}
</style>
@endpush

@section('content')
<div class="sh-trk">
    <header class="sh-trk-head">
        <h1>{{ $store->title }}</h1>
        <p>{{ $vocab['order'] ?? ___('Order') }} #{{ $order->id }} &bull; {{ date_formating($order->created_at, 'h:i A') }}</p>
    </header>

    <div class="sh-trk-card">
        <div id="shTrkBadge"></div>
        <div class="sh-trk-title" id="shTrkTitle">&nbsp;</div>
        <p class="sh-trk-desc" id="shTrkDesc"></p>

        <div class="sh-trk-steps" id="shTrkSteps">
            <div class="sh-trk-steps-progress" id="shTrkProgress"></div>
            <div class="sh-trk-step" id="shTrkStep0"><div class="sh-trk-step-dot">1</div>{{ ___('Received') }}</div>
            <div class="sh-trk-step" id="shTrkStep1"><div class="sh-trk-step-dot">2</div>{{ ___('Processing') }}</div>
            <div class="sh-trk-step" id="shTrkStep2"><div class="sh-trk-step-dot">3</div><span id="shTrkStep2Label">{{ ___('Ready') }}</span></div>
            <div class="sh-trk-step" id="shTrkStep3"><div class="sh-trk-step-dot">4</div>{{ ___('Done') }}</div>
        </div>
    </div>

    <div class="sh-trk-card">
        <div class="sh-trk-row"><span class="sh-trk-label">{{ ___('Name') }}</span><span>{{ $order->customer_name }}</span></div>
        @if($order->phone_number)
            <div class="sh-trk-row"><span class="sh-trk-label">{{ ___('Phone') }}</span><span dir="ltr">{{ $order->phone_number }}</span></div>
        @endif
        @if($modeLabel)
            <div class="sh-trk-row"><span class="sh-trk-label">{{ ___('Fulfillment') }}</span><span>{{ $modeLabel }}</span></div>
        @endif
        @if($order->address)
            <div class="sh-trk-row"><span class="sh-trk-label">{{ ___('Address') }}</span><span>{{ $order->address }}</span></div>
        @endif
    </div>

    <div class="sh-trk-card">
        @foreach($shLines as $line)
            <div class="sh-trk-row">
                <span>
                    {{ $line['name'] }} &times; {{ $line['qty'] }}
                    @if($line['note'])<span class="sh-trk-note">{{ $line['note'] }}</span>@endif
                </span>
                <span>{{ $formatPrice($line['total']) }}</span>
            </div>
        @endforeach

        <div class="sh-trk-totals">
            <div class="sh-trk-row"><span class="sh-trk-label">{{ ___('Subtotal') }}</span><span>{{ $formatPrice($shSubtotal) }}</span></div>
            {{-- No separate "Shipping" row: Order::getTotalAttribute() (the
                 authoritative total this receipt must reconcile with — see
                 theme-totals-contract) only folds restaurant_delivery_charge
                 in for type === 'delivery', never for a goods 'ship' order —
                 showing one here would make this receipt's own arithmetic
                 disagree with $order->total below it. --}}
            @if($shTax > 0)
                <div class="sh-trk-row"><span class="sh-trk-label">{{ ___('Tax') }}</span><span>{{ $formatPrice($shTax) }}</span></div>
            @endif
            @if($order->tip_amount > 0)
                <div class="sh-trk-row"><span class="sh-trk-label">{{ ___('Tip') }}</span><span>{{ $formatPrice($order->tip_amount) }}</span></div>
            @endif
            @if($order->discount_amount > 0)
                <div class="sh-trk-row"><span class="sh-trk-label">{{ ___('Discount') }}</span><span style="color:var(--sh-ok)">-{{ $formatPrice($order->discount_amount) }}</span></div>
            @endif
            {{-- `total` is the authoritative accessor the customer is charged on
                 (theme-totals-contract) — never the `price` column, which is
                 empty on some orders. --}}
            <div class="sh-trk-row sh-trk-total"><span>{{ ___('Total') }}</span><span>{{ $formatPrice($order->total) }}</span></div>
        </div>
    </div>

    <a href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" class="sh-trk-back">{{ ___('Back to store') }}</a>
</div>
@endsection

{{-- shelf's own layout.blade.php only defines @stack('style') + @stack('script')
     (no scripts_at_bottom/style_at_top — those are the SHARED food
     store_templates/layout.blade.php's stacks; shelf is self-contained). --}}
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
    // for the 3rd step, same as aurora keys "Ready"/"On the Way" off type.
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
    if (isShip) { setText('shTrkStep2Label', @json(___('Shipped'))); }

    function updateUI(status) {
        var cfg = STATUSES[status] || STATUSES.new;
        var host = el('shTrkBadge');
        if (host) {
            var badge = document.createElement('span');
            badge.className = 'sh-trk-badge ' + cfg.badgeClass;
            var dot = document.createElement('span');
            dot.className = 'sh-trk-dot';
            badge.appendChild(dot);
            badge.appendChild(document.createTextNode(' ' + cfg.badge));
            host.innerHTML = '';
            host.appendChild(badge);
        }
        setText('shTrkTitle', cfg.title);
        setText('shTrkDesc', cfg.desc);

        var steps = ['shTrkStep0', 'shTrkStep1', 'shTrkStep2', 'shTrkStep3'];
        steps.forEach(function (id, idx) {
            var n = el(id);
            if (!n) { return; }
            n.classList.remove('is-active', 'is-done');
            if (cfg.step < 0) { return; }
            if (idx < cfg.step) { n.classList.add('is-done'); }
            else if (idx === cfg.step) { n.classList.add('is-active'); }
        });
        var bar = el('shTrkProgress');
        if (bar) { bar.style.width = cfg.progress + '%'; }
    }

    updateUI(@json((string) $order->status));

    // Same JSON contract every track page's poll uses (ManagesOrders::
    // trackOrder — `wantsJson()`/`ajax()` branch): { status, ... }. Simplified
    // single-purpose poller (no shared window.mbTrackPoll here — shelf is
    // self-contained and does not load the shared script.js) with the same
    // visibility-aware backoff + terminal-stop shape as track-poll.blade.php.
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
