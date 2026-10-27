{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.lumiere.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalVocab;

    /* ---------------------------------------------------------------------
       lumiere — goods order tracking. Same contract as shelf/track.blade.php
       (ManagesOrders::trackOrder — controller, NOT edited here): $order,
       $store, $postOptions, $theme, $plan, $prepEta, $walletTx. Status
       timeline: RetailOrderBoardService's state machine (new → packing →
       ready → shipped|collected → done), not the food KDS pipeline.
       --------------------------------------------------------------------- */
    $vocab = app(VerticalVocab::class)->map($store->store_type ?? null);

    // Store-scoped money: store sign + gap + decimals, position-aware, with USD/riyal
    // LTR-isolated so the sign never bidi-flips in RTL. Centralises the old inline
    // money_ltr() closure and adds the Saudi Riyal (U+20C1) isolation it missed.
    $formatPrice = new \Juwri\Core\Support\StoreMoneyFormatter($store->id);

    $fulfillment = app(FulfillmentRegistry::class);
    $modeLabel = $order->type ? $fulfillment->label((string) $order->type) : null;

    $lmSubtotal = 0;
    $lmLines = [];
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
        $lmSubtotal += $line;
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
        $lmNoteBits = array_values(array_filter(array_merge($varLabel, $bits)));
        $lmLines[] = [
            'name' => $item->menu->name,
            'qty' => $item->quantity,
            'note' => implode(' · ', $lmNoteBits),
            'total' => $line,
        ];
    }
    $lmTax = \App\Models\Order::computeTax($lmSubtotal, $postOptions);
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    :root{
        --lm-ground:#F6F1FB; --lm-card:#FFFFFF; --lm-ink:#2F2F30; --lm-muted:#6E6A75;
        --lm-hair:#EFEAF5; --lm-accent:#A377D2; --lm-accent-2:#8A5FB8; --lm-accent-ink:#ffffff;
        --lm-danger:#c0322a; --lm-ok:#2f9e63; --lm-radius:16px;
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --lm-ground:#171320; --lm-card:#211C2B; --lm-ink:#F3EFFA; --lm-muted:#B4ACC2; --lm-hair:#2C2637;
            --lm-accent:#BC93E6; --lm-accent-2:#9A6FCC;
        }
    }
    :root[data-theme="dark"]{
        --lm-ground:#171320; --lm-card:#211C2B; --lm-ink:#F3EFFA; --lm-muted:#B4ACC2; --lm-hair:#2C2637;
        --lm-accent:#BC93E6; --lm-accent-2:#9A6FCC;
    }
    *{box-sizing:border-box}
    .lumiere-body{margin:0;background:var(--lm-ground);color:var(--lm-ink);
        font-family:"Saudi Riyal","Poppins",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
    .lm-trk{max-width:560px;margin:0 auto;padding:28px 20px 80px}
    .lm-trk-head{text-align:center;margin-block-end:20px}
    .lm-trk-head h1{font-size:1.25rem;font-weight:600;margin:0 0 4px}
    .lm-trk-head p{color:var(--lm-muted);font-size:.86rem;margin:0}
    .lm-trk-card{background:var(--lm-card);border:1px solid var(--lm-hair);border-radius:20px;
        padding:20px;margin-block-end:16px;box-shadow:0 12px 30px -18px rgba(90,60,140,.22)}
    .lm-trk-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;border-radius:999px;
        font-weight:700;font-size:.76rem;text-transform:uppercase;letter-spacing:.03em;margin-block-end:14px}
    .lm-trk-badge.is-pending{background:color-mix(in srgb, #b45309 14%, transparent);color:#b45309}
    .lm-trk-badge.is-processing{background:color-mix(in srgb, var(--lm-accent-2) 16%, transparent);color:var(--lm-accent-2)}
    .lm-trk-badge.is-ready{background:color-mix(in srgb, var(--lm-ok) 14%, transparent);color:var(--lm-ok)}
    .lm-trk-badge.is-done{background:color-mix(in srgb, var(--lm-ok) 20%, transparent);color:var(--lm-ok)}
    .lm-trk-badge.is-cancelled{background:color-mix(in srgb, var(--lm-danger) 14%, transparent);color:var(--lm-danger)}
    .lm-trk-dot{width:8px;height:8px;border-radius:50%;background:currentColor}
    .lm-trk-title{font-size:1.05rem;font-weight:600;margin:0 0 6px;font-family:"Poppins",sans-serif}
    .lm-trk-desc{color:var(--lm-muted);font-size:.86rem;line-height:1.5;margin:0 0 4px}
    .lm-trk-steps{margin-top:18px;padding-top:4px}
    .lm-trk-step{display:flex;gap:14px;position:relative;padding-bottom:22px}
    .lm-trk-step:last-child{padding-bottom:0}
    .lm-trk-step .lm-dot{width:26px;height:26px;border-radius:50%;flex:none;display:flex;align-items:center;
        justify-content:center;border:2px solid var(--lm-hair);background:var(--lm-card);color:var(--lm-muted);z-index:2}
    .lm-trk-step .lm-dot svg{width:13px;height:13px}
    .lm-trk-step::before{content:"";position:absolute;inset-inline-start:12px;top:26px;bottom:0;width:2px;background:var(--lm-hair)}
    .lm-trk-step:last-child::before{display:none}
    .lm-trk-step.is-done .lm-dot{background:var(--lm-accent);border-color:var(--lm-accent);color:#fff}
    .lm-trk-step.is-done::before{background:var(--lm-accent)}
    .lm-trk-step.is-active .lm-dot{border-color:var(--lm-accent);color:var(--lm-accent-2)}
    .lm-trk-step .lm-tt{font-weight:600;font-size:14.5px}
    .lm-trk-step .lm-td{font-size:12.5px;color:var(--lm-muted);margin-top:1px}
    .lm-trk-row{display:flex;justify-content:space-between;gap:10px;font-size:.86rem;margin-block-end:10px}
    .lm-trk-row .lm-trk-note{display:block;color:var(--lm-muted);font-size:.76rem;margin-block-start:2px}
    .lm-trk-label{color:var(--lm-muted)}
    .lm-trk-totals{margin-block-start:14px;padding-block-start:14px;border-block-start:1px dashed var(--lm-hair)}
    .lm-trk-total{display:flex;justify-content:space-between;font-weight:700;font-size:1rem}
    .lm-trk-back{display:block;text-align:center;margin-block-start:24px;color:var(--lm-accent-2);
        font-weight:600;font-size:.86rem;text-decoration:none}
</style>
@endpush

@section('content')
<div class="lm-trk">
    <header class="lm-trk-head">
        <h1>{{ $store->title }}</h1>
        <p>{{ $vocab['order'] ?? ___('Order') }} #{{ $order->id }} · {{ date_formating($order->created_at, 'h:i A') }}</p>
    </header>

    <div class="lm-trk-card">
        <div id="lmTrkBadge"></div>
        <div class="lm-trk-title" id="lmTrkTitle">&nbsp;</div>
        <p class="lm-trk-desc" id="lmTrkDesc"></p>

        <div class="lm-trk-steps" id="lmTrkSteps">
            <div class="lm-trk-step" id="lmTrkStep0"><span class="lm-dot">1</span><div><div class="lm-tt">{{ ___('Received') }}</div><div class="lm-td"></div></div></div>
            <div class="lm-trk-step" id="lmTrkStep1"><span class="lm-dot">2</span><div><div class="lm-tt">{{ ___('Processing') }}</div><div class="lm-td"></div></div></div>
            <div class="lm-trk-step" id="lmTrkStep2"><span class="lm-dot">3</span><div><div class="lm-tt" id="lmTrkStep2Label">{{ ___('Ready') }}</div><div class="lm-td"></div></div></div>
            <div class="lm-trk-step" id="lmTrkStep3"><span class="lm-dot">4</span><div><div class="lm-tt">{{ ___('Done') }}</div><div class="lm-td"></div></div></div>
        </div>
    </div>

    <div class="lm-trk-card">
        <div class="lm-trk-row"><span class="lm-trk-label">{{ ___('Name') }}</span><span>{{ $order->customer_name }}</span></div>
        @if($order->phone_number)
            <div class="lm-trk-row"><span class="lm-trk-label">{{ ___('Phone') }}</span><span dir="ltr">{{ $order->phone_number }}</span></div>
        @endif
        @if($modeLabel)
            <div class="lm-trk-row"><span class="lm-trk-label">{{ ___('Fulfillment') }}</span><span>{{ $modeLabel }}</span></div>
        @endif
        @if($order->address)
            <div class="lm-trk-row"><span class="lm-trk-label">{{ ___('Address') }}</span><span>{{ $order->address }}</span></div>
        @endif
    </div>

    <div class="lm-trk-card">
        @foreach($lmLines as $line)
            <div class="lm-trk-row">
                <span>
                    {{ $line['name'] }} &times; {{ $line['qty'] }}
                    @if($line['note'])<span class="lm-trk-note">{{ $line['note'] }}</span>@endif
                </span>
                <span>{{ $formatPrice($line['total']) }}</span>
            </div>
        @endforeach

        <div class="lm-trk-totals">
            <div class="lm-trk-row"><span class="lm-trk-label">{{ ___('Subtotal') }}</span><span>{{ $formatPrice($lmSubtotal) }}</span></div>
            @if($lmTax > 0)
                <div class="lm-trk-row"><span class="lm-trk-label">{{ ___('Tax') }}</span><span>{{ $formatPrice($lmTax) }}</span></div>
            @endif
            @if($order->tip_amount > 0)
                <div class="lm-trk-row"><span class="lm-trk-label">{{ ___('Tip') }}</span><span>{{ $formatPrice($order->tip_amount) }}</span></div>
            @endif
            @if($order->discount_amount > 0)
                <div class="lm-trk-row"><span class="lm-trk-label">{{ ___('Discount') }}</span><span style="color:var(--lm-ok)">-{{ $formatPrice($order->discount_amount) }}</span></div>
            @endif
            <div class="lm-trk-row lm-trk-total"><span>{{ ___('Total') }}</span><span>{{ $formatPrice($order->total) }}</span></div>
        </div>
    </div>

    <a href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" class="lm-trk-back">{{ ___('Back to store') }}</a>
</div>
@endsection

@push('script')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    function el(id) { return document.getElementById(id); }
    function setText(id, txt) { var n = el(id); if (n) { n.textContent = txt; } }

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
    if (isShip) { setText('lmTrkStep2Label', @json(___('Shipped'))); }

    function updateUI(status) {
        var cfg = STATUSES[status] || STATUSES.new;
        var host = el('lmTrkBadge');
        if (host) {
            var badge = document.createElement('span');
            badge.className = 'lm-trk-badge ' + cfg.badgeClass;
            var dot = document.createElement('span');
            dot.className = 'lm-trk-dot';
            badge.appendChild(dot);
            badge.appendChild(document.createTextNode(' ' + cfg.badge));
            host.innerHTML = '';
            host.appendChild(badge);
        }
        setText('lmTrkTitle', cfg.title);
        setText('lmTrkDesc', cfg.desc);

        var steps = ['lmTrkStep0', 'lmTrkStep1', 'lmTrkStep2', 'lmTrkStep3'];
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
