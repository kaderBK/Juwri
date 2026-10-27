{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.zevoa.layout')

@php
    use App\Services\Verticals\FulfillmentRegistry;
    use App\Services\Verticals\VerticalVocab;

    /* ---------------------------------------------------------------------
       zevoa — REAL goods order tracking (adapted from shelf/track.blade.php,
       same data contract, same self-contained posture — no shared
       store_templates/layout.blade.php or script.js).

       Data contract (see ManagesOrders::trackOrder — controller, NOT edited
       here): $order, $store, $postOptions (plain stdClass), $theme, $plan,
       $prepEta (Juwri\Modules\Ordering\Services\Orders\EtaRange|null), $walletTx.

       Status timeline: a goods order runs through RetailOrderBoardService's
       state machine (new → packing → ready → shipped|collected → done), NOT
       the food KDS pipeline. Labels are relabeled to the zevoa "Purchased /
       Packed / On the way / Delivered" vocabulary used on the confirm
       screen's own (demo) timeline, so a shopper sees the SAME four words in
       both places — only this page's timeline is driven by the real order
       status (polled below), never a timer.

       Courier card stays intentionally GENERIC: trackOrder()'s own JSON poll
       (see its docblock) never exposes a driver name/phone/photo to this
       public token-authed page, so this page never fabricates one either —
       unlike the confirm screen's celebratory demo card, which is clearly
       inside the same "demo timer is OK" allowance the design spec grants
       that screen only. */
    $vocab = app(VerticalVocab::class)->map($store->store_type ?? null);

    // Store-scoped money: store sign + gap + decimals, position-aware, with USD/riyal
    // LTR-isolated so the sign never bidi-flips in RTL. Centralises the old inline
    // money_ltr() closure and adds the Saudi Riyal (U+20C1) isolation it missed.
    $formatPrice = new \Juwri\Core\Support\StoreMoneyFormatter($store->id);

    $fulfillment = app(FulfillmentRegistry::class);
    $modeLabel = null;
    if ($order->type) {
        $modeLabel = $order->type === 'ship' ? ($vocab['delivery'] ?? ___('Delivery')) : $fulfillment->label((string) $order->type);
    }

    // Same receipt contract as every other kept theme's track page
    // (theme-totals-contract: match Order::getTotalAttribute(), tax
    // PRE-discount). itemExtras/options are food "extras"/choice-groups a
    // goods item never has, but the loop stays generic (empty for a goods
    // order — a silent no-op) rather than forking a goods-only copy.
    $zvSubtotal = 0;
    $zvLines = [];
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
        $zvSubtotal += $line;
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
        $zvNoteBits = array_values(array_filter(array_merge($varLabel, $bits)));
        $zvLines[] = [
            'name' => $item->menu->name,
            'qty' => $item->quantity,
            'note' => implode(' · ', $zvNoteBits),
            'total' => $line,
        ];
    }
    $zvTax = \App\Models\Order::computeTax($zvSubtotal, $postOptions);

    // Real ETA banner text — EtaRange::label() gives a rounded "20–30"
    // range; abstains (null) below MIN_SAMPLES, in which case this falls
    // back to the same generic "~30 min" flavor text the confirm screen's
    // presentational banner already shows, so the two never contradict.
    $zvEtaText = $prepEta ? ___('Estimated delivery in :range min', ['range' => $prepEta->label()]) : ___('Delivery in about 30 min');
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    :root{
        --zv-ground:#F3F5F2; --zv-card:#FFFFFF; --zv-ink:#151A17; --zv-ink2:#5F6B62; --zv-ink3:#93A096;
        --zv-hair:#E6EAE4; --zv-hair2:#D7DED3; --zv-brand:{{ $store->color ?: '#17A44B' }}; --zv-brand2:#0E7D38;
        --zv-brand-ink:#ffffff; --zv-brand-bg:#E2F5E9; --zv-amber:#F59E0B; --zv-amber-bg:#FCF1DC; --zv-sale:#E1483C;
        --zv-radius:14px; --zv-radius-lg:22px; --zv-shadow-card:0 10px 26px -16px rgba(10,20,14,.28);
        --zv-font:"Saudi Riyal","Plus Jakarta Sans",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    }
    @media (prefers-color-scheme: dark){
        :root:not([data-theme="light"]){
            --zv-ground:#0E120F; --zv-card:#161B17; --zv-ink:#EDF1EC; --zv-ink2:#9AA79E; --zv-ink3:#67736A;
            --zv-hair:#262C26; --zv-hair2:#313931; --zv-brand2:#28A25A; --zv-brand-bg:#132A1D;
            --zv-amber:#F5A623; --zv-amber-bg:#2C2412; --zv-sale:#FF6B5E; --zv-shadow-card:0 14px 30px -18px rgba(0,0,0,.55);
        }
    }
    :root[data-theme="dark"]{
        --zv-ground:#0E120F; --zv-card:#161B17; --zv-ink:#EDF1EC; --zv-ink2:#9AA79E; --zv-ink3:#67736A;
        --zv-hair:#262C26; --zv-hair2:#313931; --zv-brand2:#28A25A; --zv-brand-bg:#132A1D;
        --zv-amber:#F5A623; --zv-amber-bg:#2C2412; --zv-sale:#FF6B5E; --zv-shadow-card:0 14px 30px -18px rgba(0,0,0,.55);
    }
    *{box-sizing:border-box}
    .zv-body{margin:0;background:var(--zv-ground);color:var(--zv-ink);font-family:var(--zv-font);-webkit-font-smoothing:antialiased}
    [hidden]{display:none !important}
    .zv-skip{position:absolute;inset-inline-start:-999px;top:0;background:#000;color:#fff;padding:8px 16px;z-index:20}
    .zv-skip:focus{inset-inline-start:0}
    .zv-trk{max-width:560px;margin:0 auto;padding:28px 16px 80px}
    .zv-trk-head{text-align:center;margin-bottom:18px}
    .zv-trk-head h1{font-size:1.25rem;font-weight:800;margin:0 0 4px;letter-spacing:-.01em}
    .zv-trk-head p{color:var(--zv-ink2);font-size:.86rem;margin:0}
    .zv-trk-card{background:var(--zv-card);border:1px solid var(--zv-hair);border-radius:var(--zv-radius-lg);
        padding:20px;margin-bottom:16px;box-shadow:var(--zv-shadow-card)}
    .zv-trk-eta{display:flex;align-items:center;justify-content:center;gap:9px;background:var(--zv-amber-bg);color:var(--zv-amber);
        border-radius:100px;padding:11px 16px;font-size:.82rem;font-weight:700;margin:0 0 16px}
    .zv-trk-eta svg{width:16px;height:16px;flex:0 0 auto}
    .zv-trk-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;border-radius:999px;
        font-weight:700;font-size:.76rem;text-transform:uppercase;letter-spacing:.03em;margin-bottom:14px}
    .zv-trk-badge.is-pending{background:color-mix(in srgb, #b45309 14%, transparent);color:#b45309}
    .zv-trk-badge.is-processing{background:color-mix(in srgb, #2563eb 14%, transparent);color:#2563eb}
    .zv-trk-badge.is-ready{background:color-mix(in srgb, var(--zv-brand2) 14%, transparent);color:var(--zv-brand2)}
    .zv-trk-badge.is-done{background:color-mix(in srgb, var(--zv-brand2) 20%, transparent);color:var(--zv-brand2)}
    .zv-trk-badge.is-cancelled{background:color-mix(in srgb, var(--zv-sale) 14%, transparent);color:var(--zv-sale)}
    .zv-trk-dot{width:8px;height:8px;border-radius:50%;background:currentColor}
    .zv-trk-title{font-size:1.05rem;font-weight:800;margin:0 0 6px}
    .zv-trk-desc{color:var(--zv-ink2);font-size:.86rem;line-height:1.5;margin:0 0 4px}
    .zv-trk-steps{display:flex;justify-content:space-between;position:relative;margin:20px 0 4px}
    .zv-trk-steps::before{content:'';position:absolute;top:15px;inset-inline-start:0;inset-inline-end:0;height:3px;background:var(--zv-hair)}
    .zv-trk-steps-progress{position:absolute;top:15px;inset-inline-start:0;height:3px;background:var(--zv-brand);width:0%;transition:width .5s ease}
    [dir="rtl"] .zv-trk-steps-progress{inset-inline-start:auto;inset-inline-end:0}
    .zv-trk-step{position:relative;text-align:center;flex:1;font-size:.68rem;color:var(--zv-ink2);font-weight:600}
    .zv-trk-step-dot{width:32px;height:32px;border-radius:50%;background:var(--zv-card);border:3px solid var(--zv-hair);
        display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-weight:700;color:var(--zv-ink2)}
    .zv-trk-step-dot svg{width:14px;height:14px}
    .zv-trk-step.is-active .zv-trk-step-dot{border-color:var(--zv-brand);background:var(--zv-brand);color:var(--zv-brand-ink)}
    .zv-trk-step.is-done .zv-trk-step-dot{border-color:var(--zv-brand);color:var(--zv-brand)}
    .zv-trk-step.is-active,.zv-trk-step.is-done{color:var(--zv-ink)}
    .zv-trk-row{display:flex;justify-content:space-between;gap:10px;font-size:.86rem;margin-bottom:10px}
    .zv-trk-row .zv-trk-note{display:block;color:var(--zv-ink2);font-size:.76rem;margin-top:2px}
    .zv-trk-label{color:var(--zv-ink2)}
    .zv-trk-totals{margin-top:14px;padding-top:14px;border-top:1px dashed var(--zv-hair)}
    .zv-trk-total{display:flex;justify-content:space-between;font-weight:800;font-size:1rem}
    .zv-trk-back{display:block;text-align:center;margin-top:24px;color:var(--zv-brand2);font-weight:700;font-size:.86rem;text-decoration:none}

    .zv-trk-driver{display:flex;align-items:center;gap:12px}
    .zv-trk-davatar{width:48px;height:48px;border-radius:50%;flex:0 0 auto;border:1px solid var(--zv-hair);background:color-mix(in srgb, var(--zv-ink) 6%, transparent);
        display:flex;align-items:center;justify-content:center;color:var(--zv-ink2)}
    .zv-trk-davatar svg{width:22px;height:22px}
    .zv-trk-dinfo{flex:1;min-width:0}
    .zv-trk-dname{font-weight:700;font-size:.92rem}
    .zv-trk-dsub{font-size:.76rem;color:var(--zv-ink2);margin-top:1px}
    .zv-trk-dcall{width:38px;height:38px;border-radius:50%;background:var(--zv-brand-bg);color:var(--zv-brand2);
        display:flex;align-items:center;justify-content:center;text-decoration:none;flex:0 0 auto}
    .zv-trk-dcall svg{width:17px;height:17px}

    /* Desktop (ADD-ONLY, see scratchpad/loop/RESPONSIVE-SPEC.md): this page
       is already a single centered column (.zv-trk, hard-capped at 560px
       above — the ONE case the shared contract says to widen only via a
       media-query override, never by editing that base rule). On a wide
       monitor a plain, slightly wider centered column reads better than
       either the narrow 560px card or a full-bleed stretch of the timeline,
       so this only nudges the cap and adds breathing room — no new columns,
       nothing to keep in sync with dark mode/RTL beyond the existing
       logical properties already used above. */
    @media (min-width:1024px){
        .zv-trk{max-width:640px;padding-top:48px}
    }
</style>
@endpush

@section('content')
<div class="zv-trk">
    <header class="zv-trk-head">
        <h1>{{ $store->title }}</h1>
        <p>{{ $vocab['order'] ?? ___('Order') }} #{{ $order->id }} &bull; {{ date_formating($order->created_at, 'h:i A') }}</p>
    </header>

    <div class="zv-trk-eta">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
        <span>{{ $zvEtaText }}</span>
    </div>

    <div class="zv-trk-card">
        <div id="zvTrkBadge"></div>
        <div class="zv-trk-title" id="zvTrkTitle">&nbsp;</div>
        <p class="zv-trk-desc" id="zvTrkDesc"></p>

        <div class="zv-trk-steps" id="zvTrkSteps">
            <div class="zv-trk-steps-progress" id="zvTrkProgress"></div>
            <div class="zv-trk-step" id="zvTrkStep0">
                <div class="zv-trk-step-dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l4 4 10-10"/></svg></div>
                {{ ___('Purchased') }}
            </div>
            <div class="zv-trk-step" id="zvTrkStep1">
                <div class="zv-trk-step-dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M3 8l9-5 9 5-9 5z"/><path d="M3 8v8l9 5 9-5V8"/></svg></div>
                {{ ___('Packed') }}
            </div>
            <div class="zv-trk-step" id="zvTrkStep2">
                <div class="zv-trk-step-dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg></div>
                <span id="zvTrkStep2Label">{{ ___('On the way') }}</span>
            </div>
            <div class="zv-trk-step" id="zvTrkStep3">
                <div class="zv-trk-step-dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M4 11l8-7 8 7M6 10v9a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-9"/></svg></div>
                {{ ___('Delivered') }}
            </div>
        </div>
    </div>

    {{-- Courier card — GENERIC on purpose (see docblock above): no driver
         name/phone/photo is ever invented. Call only shows when the STORE
         itself published a real phone number. Only shown once the order is
         actually on its way, so it doesn't imply a courier before one would
         realistically be involved. --}}
    <div class="zv-trk-card zv-trk-driver" id="zvTrkDriverCard" hidden>
        <span class="zv-trk-davatar" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 12l1.3-4.2A2 2 0 0 1 6.2 6.5h11.6a2 2 0 0 1 1.9 1.3L21 12"/><rect x="1" y="12" width="22" height="6" rx="2"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/></svg>
        </span>
        <div class="zv-trk-dinfo">
            <div class="zv-trk-dname">{{ ___('Your delivery courier') }}</div>
            <div class="zv-trk-dsub">{{ ___('On the way to you now.') }}</div>
        </div>
        @if($store->phone)
        <a class="zv-trk-dcall" href="tel:{{ $store->phone }}" aria-label="{{ ___('Call') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M5 4h3l2 5-2.2 1.3a12 12 0 0 0 6 6L15 14l5 2v3a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg>
        </a>
        @endif
    </div>

    <div class="zv-trk-card">
        <div class="zv-trk-row"><span class="zv-trk-label">{{ ___('Name') }}</span><span>{{ $order->customer_name }}</span></div>
        @if($order->phone_number)
            <div class="zv-trk-row"><span class="zv-trk-label">{{ ___('Phone') }}</span><span dir="ltr">{{ $order->phone_number }}</span></div>
        @endif
        @if($modeLabel)
            <div class="zv-trk-row"><span class="zv-trk-label">{{ ___('Fulfillment') }}</span><span>{{ $modeLabel }}</span></div>
        @endif
        @if($order->address)
            <div class="zv-trk-row"><span class="zv-trk-label">{{ ___('Address') }}</span><span>{{ $order->address }}</span></div>
        @endif
    </div>

    <div class="zv-trk-card">
        @foreach($zvLines as $line)
            <div class="zv-trk-row">
                <span>
                    {{ $line['name'] }} &times; {{ $line['qty'] }}
                    @if($line['note'])<span class="zv-trk-note">{{ $line['note'] }}</span>@endif
                </span>
                <span>{{ $formatPrice($line['total']) }}</span>
            </div>
        @endforeach

        <div class="zv-trk-totals">
            <div class="zv-trk-row"><span class="zv-trk-label">{{ ___('Subtotal') }}</span><span>{{ $formatPrice($zvSubtotal) }}</span></div>
            {{-- No separate "Delivery"/"Handling" row here: Order::getTotalAttribute()
                 (the authoritative total this receipt must reconcile with —
                 theme-totals-contract) only folds restaurant_delivery_charge
                 in for type === 'delivery', never for a goods 'ship' order —
                 showing a presentational fee line here (like the cart/checkout
                 screens do) would make this REAL receipt's arithmetic disagree
                 with $order->total below it. --}}
            @if($zvTax > 0)
                <div class="zv-trk-row"><span class="zv-trk-label">{{ ___('Tax') }}</span><span>{{ $formatPrice($zvTax) }}</span></div>
            @endif
            @if($order->tip_amount > 0)
                <div class="zv-trk-row"><span class="zv-trk-label">{{ ___('Tip') }}</span><span>{{ $formatPrice($order->tip_amount) }}</span></div>
            @endif
            @if($order->discount_amount > 0)
                <div class="zv-trk-row"><span class="zv-trk-label">{{ ___('Discount') }}</span><span style="color:var(--zv-brand2)">-{{ $formatPrice($order->discount_amount) }}</span></div>
            @endif
            <div class="zv-trk-row zv-trk-total"><span>{{ ___('Total') }}</span><span>{{ $formatPrice($order->total) }}</span></div>
        </div>
    </div>

    <a href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" class="zv-trk-back">{{ ___('Back to store') }}</a>
</div>
@endsection

{{-- zevoa's own layout.blade.php only defines @stack('style') + @stack('script')
     — self-contained, like shelf's track page (no shared script.js). --}}
@push('script')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    function el(id) { return document.getElementById(id); }
    function setText(id, txt) { var n = el(id); if (n) { n.textContent = txt; } }

    // Goods order states — RetailOrderBoardService::STATES (new → packing →
    // ready → shipped|collected → done), NOT the food KDS pipeline. Relabeled
    // to zevoa's "Purchased/Packed/On the way/Delivered" vocabulary so this
    // page and the confirm screen's demo timeline read identically.
    var isShip = @json($order->type === 'ship');
    var driverCard = el('zvTrkDriverCard');
    var STATUSES = {
        unpaid: {
            title: @json(___('Awaiting Payment')), desc: @json(___('Please complete your online payment to submit this order.')),
            badge: @json(___('Awaiting Payment')), badgeClass: 'is-cancelled', progress: 0, step: -1, driver: false,
        },
        new: {
            title: @json(___('Order received')), desc: @json(___('Your order has been received and is awaiting processing.')),
            badge: @json(___('Received')), badgeClass: 'is-pending', progress: 12.5, step: 0, driver: false,
        },
        pending: {
            title: @json(___('Order received')), desc: @json(___('Your order has been received and is awaiting processing.')),
            badge: @json(___('Received')), badgeClass: 'is-pending', progress: 12.5, step: 0, driver: false,
        },
        pending_approval: {
            title: @json(___('Awaiting approval')), desc: @json(___('Your order has been received and is awaiting confirmation from the store.')),
            badge: @json(___('Awaiting approval')), badgeClass: 'is-pending', progress: 12.5, step: 0, driver: false,
        },
        packing: {
            title: @json(___('Packed')), desc: @json(___('The store is packing your order.')),
            badge: @json(___('Processing')), badgeClass: 'is-processing', progress: 37.5, step: 1, driver: false,
        },
        ready: {
            title: isShip ? @json(___('Ready to ship')) : @json(___('Ready for pickup')),
            desc: isShip ? @json(___('Your order is packed and will ship shortly.')) : @json(___('Your order is packed and ready for pickup.')),
            badge: @json(___('Ready')), badgeClass: 'is-ready', progress: 62.5, step: 2, driver: false,
        },
        shipped: {
            title: @json(___('On the way')), desc: @json(___('Your order is on its way to you.')),
            badge: @json(___('On the way')), badgeClass: 'is-ready', progress: 87.5, step: 2, driver: true,
        },
        collected: {
            title: @json(___('Collected')), desc: @json(___('Your order has been handed over. Thank you!')),
            badge: @json(___('Collected')), badgeClass: 'is-ready', progress: 87.5, step: 2, driver: false,
        },
        done: {
            title: @json(___('Delivered')), desc: @json(___('This order is complete. Thank you for shopping with us!')),
            badge: @json(___('Delivered')), badgeClass: 'is-done', progress: 100, step: 3, driver: false,
        },
        cancelled: {
            title: @json(___('Order Cancelled')), desc: @json(___('This order has been cancelled. Please contact the store for assistance.')),
            badge: @json(___('Cancelled')), badgeClass: 'is-cancelled', progress: 0, step: -1, driver: false,
        },
    };
    if (isShip) { setText('zvTrkStep2Label', @json(___('Shipped'))); }

    function updateUI(status) {
        var cfg = STATUSES[status] || STATUSES.new;
        var host = el('zvTrkBadge');
        if (host) {
            var badge = document.createElement('span');
            badge.className = 'zv-trk-badge ' + cfg.badgeClass;
            var dot = document.createElement('span');
            dot.className = 'zv-trk-dot';
            badge.appendChild(dot);
            badge.appendChild(document.createTextNode(' ' + cfg.badge));
            host.innerHTML = '';
            host.appendChild(badge);
        }
        setText('zvTrkTitle', cfg.title);
        setText('zvTrkDesc', cfg.desc);
        if (driverCard) { driverCard.hidden = !cfg.driver; }

        var steps = ['zvTrkStep0', 'zvTrkStep1', 'zvTrkStep2', 'zvTrkStep3'];
        steps.forEach(function (id, idx) {
            var n = el(id);
            if (!n) { return; }
            n.classList.remove('is-active', 'is-done');
            if (cfg.step < 0) { return; }
            if (idx < cfg.step) { n.classList.add('is-done'); }
            else if (idx === cfg.step) { n.classList.add('is-active'); }
        });
        var bar = el('zvTrkProgress');
        if (bar) { bar.style.width = cfg.progress + '%'; }
    }

    updateUI(@json((string) $order->status));

    // Same JSON contract every track page's poll uses (ManagesOrders::
    // trackOrder — wantsJson()/ajax() branch): { status, ... }. Same
    // visibility-aware backoff + terminal-stop shape as shelf's own poller.
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
