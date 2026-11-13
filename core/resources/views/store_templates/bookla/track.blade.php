{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.bookla.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalVocab;

    /* ---------------------------------------------------------------------
       bookla — service booking tracking (NEW).

       ManagesOrders::trackOrder() previously fell back to the FOOD 'aurora'
       theme's track view for a bookla-templated (service/booking) store — a
       "Preparing your Food" / "chef" / kitchen-vocab page for someone who
       booked a laundry service, not a meal. This is bookla's own vocab-aware
       equivalent, reusing bookla's own layout.blade.php + CSS vars (self-
       contained, like index.blade.php — no shared store_templates/layout.
       blade.php or script.js).

       Data contract (see ManagesOrders::trackOrder — controller, NOT edited
       here): $order, $store, $postOptions (plain stdClass of option_name =>
       option_value), $theme, $plan, $prepEta, $walletTx. No $vocab/$table —
       computed locally below, same as index.blade.php does.

       Status timeline: a booking runs through the SAME additive state
       machine as shelf's goods orders — RetailOrderBoardService (new →
       packing → ready → shipped|collected → done) — Lane D deliberately
       reuses it rather than forking a laundry-only one (see
       ServiceBoardVocab's own docblock), just with laundry wording:
       'shipped' and 'collected' BOTH read "Out for delivery" (the piece has
       left the shop either way), never "Shipped". --------------------------
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

    // P1 (service duration) — same defensive turnaround_hours read as
    // index.blade.php; a per-line "ready in N h" note when the booked
    // service's menu row set one.
    $formatDuration = \Juwri\Core\Support\ViewHelpers::formatDuration(...);

    $fulfillment = app(FulfillmentRegistry::class);
    $modeLabel = $order->type ? $fulfillment->label((string) $order->type) : null;

    /* Same receipt contract as every other kept theme's track page (theme-
       totals-contract: match Order::getTotalAttribute(), tax PRE-discount) —
       see aurora/track.blade.php for the annotated original this mirrors.
       itemExtras/options are food "extras"/choice-groups a service never
       has, but the loop stays generic (empty for a booking, so it is a
       silent no-op) rather than forking a booking-only copy. */
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
        // weight (ServiceBookingService::book() snapshots it, quantity pinned
        // to 1) — Order::computeMoney() sums `$itemPrice * $item->quantity`
        // for EVERY line the same way regardless, so this mirrors that exactly
        // rather than special-casing away the (already-1) multiplication.
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
        --bk-bg:#f7f7f8; --bk-surface:#ffffff; --bk-ink:#15181d; --bk-muted:#6b7280;
        --bk-line:#e6e7eb; --bk-accent:{{ $store->color ?: '#0f9d78' }}; --bk-accent-ink:#ffffff;
        --bk-danger:#c0322a; --bk-ok:#1a8f4c; --bk-radius:14px;
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --bk-bg:#0f1115; --bk-surface:#181b21; --bk-ink:#f3f4f6; --bk-muted:#9aa1ac; --bk-line:#2a2e37;
        }
    }
    :root[data-theme="dark"]{
        --bk-bg:#0f1115; --bk-surface:#181b21; --bk-ink:#f3f4f6; --bk-muted:#9aa1ac; --bk-line:#2a2e37;
    }
    *{box-sizing:border-box}
    .bkl-body{margin:0;background:var(--bk-bg);color:var(--bk-ink);
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif;}
    .bk-trk{max-width:560px;margin:0 auto;padding:28px 16px 80px}
    .bk-trk-head{text-align:center;margin-block-end:20px}
    .bk-trk-head h1{font-size:1.3rem;font-weight:800;margin:0 0 4px}
    .bk-trk-head p{color:var(--bk-muted);font-size:.86rem;margin:0}
    .bk-trk-card{background:var(--bk-surface);border:1px solid var(--bk-line);border-radius:var(--bk-radius);
        padding:20px;margin-block-end:16px}
    .bk-trk-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;border-radius:999px;
        font-weight:700;font-size:.76rem;text-transform:uppercase;letter-spacing:.03em;margin-block-end:14px}
    .bk-trk-badge.is-pending{background:color-mix(in srgb, #b45309 14%, transparent);color:#b45309}
    .bk-trk-badge.is-processing{background:color-mix(in srgb, #2563eb 14%, transparent);color:#2563eb}
    .bk-trk-badge.is-ready{background:color-mix(in srgb, var(--bk-ok) 14%, transparent);color:var(--bk-ok)}
    .bk-trk-badge.is-done{background:color-mix(in srgb, var(--bk-ok) 20%, transparent);color:var(--bk-ok)}
    .bk-trk-badge.is-cancelled{background:color-mix(in srgb, var(--bk-danger) 14%, transparent);color:var(--bk-danger)}
    .bk-trk-dot{width:8px;height:8px;border-radius:50%;background:currentColor}
    .bk-trk-title{font-size:1.05rem;font-weight:800;margin:0 0 6px}
    .bk-trk-desc{color:var(--bk-muted);font-size:.86rem;line-height:1.5;margin:0 0 18px}
    .bk-trk-steps{display:flex;justify-content:space-between;position:relative;margin:16px 0 4px}
    .bk-trk-steps::before{content:'';position:absolute;top:15px;inset-inline-start:0;inset-inline-end:0;height:3px;background:var(--bk-line)}
    .bk-trk-steps-progress{position:absolute;top:15px;inset-inline-start:0;height:3px;background:var(--bk-accent);width:0%;transition:width .5s ease}
    [dir="rtl"] .bk-trk-steps-progress{inset-inline-start:auto;inset-inline-end:0}
    .bk-trk-step{position:relative;text-align:center;flex:1;font-size:.68rem;color:var(--bk-muted);font-weight:600}
    .bk-trk-step-dot{width:32px;height:32px;border-radius:50%;background:var(--bk-surface);border:3px solid var(--bk-line);
        display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-weight:700;color:var(--bk-muted)}
    .bk-trk-step.is-active .bk-trk-step-dot{border-color:var(--bk-accent);background:var(--bk-accent);color:var(--bk-accent-ink)}
    .bk-trk-step.is-done .bk-trk-step-dot{border-color:var(--bk-accent);color:var(--bk-accent)}
    .bk-trk-step.is-active,.bk-trk-step.is-done{color:var(--bk-ink)}
    .bk-trk-row{display:flex;justify-content:space-between;gap:10px;font-size:.86rem;margin-block-end:10px}
    .bk-trk-row .bk-trk-note{display:block;color:var(--bk-muted);font-size:.76rem;margin-block-start:2px}
    .bk-trk-label{color:var(--bk-muted)}
    .bk-trk-totals{margin-block-start:14px;padding-block-start:14px;border-block-start:1px dashed var(--bk-line)}
    .bk-trk-total{display:flex;justify-content:space-between;font-weight:800;font-size:1rem}
    .bk-trk-back{display:block;text-align:center;margin-block-start:24px;color:var(--bk-accent);
        font-weight:700;font-size:.86rem;text-decoration:none}
</style>
@endpush

@section('content')
<div class="bk-trk">
    <header class="bk-trk-head">
        <h1>{{ $store->title }}</h1>
        <p>{{ $vocab['order'] ?? ___('Booking') }} #{{ $order->id }} &bull; {{ date_formating($order->created_at, 'h:i A') }}</p>
    </header>

    <div class="bk-trk-card">
        <div id="bkTrkBadge"></div>
        <div class="bk-trk-title" id="bkTrkTitle">&nbsp;</div>
        <p class="bk-trk-desc" id="bkTrkDesc"></p>

        <div class="bk-trk-steps" id="bkTrkSteps">
            <div class="bk-trk-steps-progress" id="bkTrkProgress"></div>
            <div class="bk-trk-step" id="bkTrkStep0"><div class="bk-trk-step-dot">1</div>{{ ___('Received') }}</div>
            <div class="bk-trk-step" id="bkTrkStep1"><div class="bk-trk-step-dot">2</div>{{ ___('Processing') }}</div>
            <div class="bk-trk-step" id="bkTrkStep2"><div class="bk-trk-step-dot">3</div><span id="bkTrkStep2Label">{{ ___('Ready') }}</span></div>
            <div class="bk-trk-step" id="bkTrkStep3"><div class="bk-trk-step-dot">4</div>{{ ___('Done') }}</div>
        </div>
    </div>

    <div class="bk-trk-card">
        <div class="bk-trk-row"><span class="bk-trk-label">{{ ___('Name') }}</span><span>{{ $order->customer_name }}</span></div>
        @if($order->phone_number)
            <div class="bk-trk-row"><span class="bk-trk-label">{{ ___('Phone') }}</span><span dir="ltr">{{ $order->phone_number }}</span></div>
        @endif
        @if($modeLabel)
            <div class="bk-trk-row"><span class="bk-trk-label">{{ ___('Fulfillment') }}</span><span>{{ $modeLabel }}</span></div>
        @endif
        @if($order->address)
            <div class="bk-trk-row"><span class="bk-trk-label">{{ ___('Pickup / delivery address') }}</span><span>{{ $order->address }}</span></div>
        @endif
        @if($order->scheduled_at)
            <div class="bk-trk-row"><span class="bk-trk-label">{{ ___('Pick a time') }}</span><span>{{ date_formating($order->scheduled_at, 'D, M j · h:i A') }}</span></div>
        @endif
    </div>

    <div class="bk-trk-card">
        @foreach($bkLines as $line)
            <div class="bk-trk-row">
                <span>
                    {{ $line['name'] }} &times; {{ $line['qty'] }}
                    @if($line['note'])<span class="bk-trk-note">{{ $line['note'] }}</span>@endif
                </span>
                <span>{{ $formatPrice($line['total']) }}</span>
            </div>
        @endforeach

        <div class="bk-trk-totals">
            <div class="bk-trk-row"><span class="bk-trk-label">{{ ___('Subtotal') }}</span><span>{{ $formatPrice($bkSubtotal) }}</span></div>
            {{-- Order::getTotalAttribute() folds restaurant_delivery_charge in for
                 type === 'delivery' — a REAL match for bookla, unlike shelf's
                 'ship' (config/verticals.php: laundry's own fulfillment modes
                 literally include 'delivery'), so this row IS safe to show and
                 stays consistent with $order->total below it. --}}
            @if($order->deliveryCharge($postOptions) > 0)
                <div class="bk-trk-row"><span class="bk-trk-label">{{ ___('Delivery Charge') }}</span><span>{{ $formatPrice($order->deliveryCharge($postOptions)) }}</span></div>
            @endif
            @if($bkTax > 0)
                <div class="bk-trk-row"><span class="bk-trk-label">{{ ___('Tax') }}</span><span>{{ $formatPrice($bkTax) }}</span></div>
            @endif
            @if($order->tip_amount > 0)
                <div class="bk-trk-row"><span class="bk-trk-label">{{ ___('Tip') }}</span><span>{{ $formatPrice($order->tip_amount) }}</span></div>
            @endif
            @if($order->discount_amount > 0)
                <div class="bk-trk-row"><span class="bk-trk-label">{{ ___('Discount') }}</span><span style="color:var(--bk-ok)">-{{ $formatPrice($order->discount_amount) }}</span></div>
            @endif
            {{-- `total` is the authoritative accessor the customer is charged on
                 (theme-totals-contract) — never the `price` column, which is
                 empty on some orders. --}}
            <div class="bk-trk-row bk-trk-total"><span>{{ ___('Total') }}</span><span>{{ $formatPrice($order->total) }}</span></div>
        </div>
    </div>

    <a href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" class="bk-trk-back">{{ ___('Back to store') }}</a>
</div>
@endsection

{{-- bookla's own layout.blade.php only defines @stack('style') + @stack('script')
     (no scripts_at_bottom/style_at_top — those are the SHARED food
     store_templates/layout.blade.php's stacks; bookla is self-contained). --}}
@push('script')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    function el(id) { return document.getElementById(id); }
    function setText(id, txt) { var n = el(id); if (n) { n.textContent = txt; } }

    // Booking states — the SAME RetailOrderBoardService::STATES shelf's goods
    // orders use (new → packing → ready → shipped|collected → done), Lane D
    // reusing it rather than forking a laundry-only machine (see
    // ServiceBoardVocab's own docblock). Badge wording matches that vocab
    // exactly: 'shipped' and 'collected' BOTH read "Out for delivery" — a
    // laundry order never truly "ships".
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
        // A laundry order never truly "ships" (config/verticals.php: its modes
        // are pickup_slot/delivery/appointment, never 'ship') — 'shipped' is
        // listed only for completeness against RetailOrderBoardService::STATES.
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
        var host = el('bkTrkBadge');
        if (host) {
            var badge = document.createElement('span');
            badge.className = 'bk-trk-badge ' + cfg.badgeClass;
            var dot = document.createElement('span');
            dot.className = 'bk-trk-dot';
            badge.appendChild(dot);
            badge.appendChild(document.createTextNode(' ' + cfg.badge));
            host.innerHTML = '';
            host.appendChild(badge);
        }
        setText('bkTrkTitle', cfg.title);
        setText('bkTrkDesc', cfg.desc);
        if (status === 'shipped' || status === 'collected') { setText('bkTrkStep2Label', cfg.badge); }

        var steps = ['bkTrkStep0', 'bkTrkStep1', 'bkTrkStep2', 'bkTrkStep3'];
        steps.forEach(function (id, idx) {
            var n = el(id);
            if (!n) { return; }
            n.classList.remove('is-active', 'is-done');
            if (cfg.step < 0) { return; }
            if (idx < cfg.step) { n.classList.add('is-done'); }
            else if (idx === cfg.step) { n.classList.add('is-active'); }
        });
        var bar = el('bkTrkProgress');
        if (bar) { bar.style.width = cfg.progress + '%'; }
    }

    updateUI(@json((string) $order->status));

    // Same JSON contract every track page's poll uses (ManagesOrders::
    // trackOrder — `wantsJson()`/`ajax()` branch): { status, ... }. Simplified
    // single-purpose poller (no shared window.mbTrackPoll here — bookla is
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
