{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: section_product_fields — "Front of house to back office":
     three panels (ordering channels, kitchen screens, analytics) plus four
     supporting cards. --}}
@php
    $sbOrderChips = [
        [___('Dine-in QR'), true], [___('Pickup'), false], [___('Delivery'), false],
        [___('Curbside'), false], [___('WhatsApp'), false], [___('Split bill'), false],
    ];
    $sbWide = [
        [___('Inventory to the gram'), ___('Recipes deduct stock as orders land. Purchase orders, suppliers, wastage and low-stock alerts.')],
        [___('Floor map and reservations'), ___('Drag tables into zones, watch occupancy live, run reservations and a waitlist.')],
        [___('Apps for the whole team'), ___('Waiter, driver and staff apps with clock-in, tableside ordering and tips. Works offline.')],
        [___('Loyalty and wallets'), ___('Points, store credit, gift cards and campaigns over SMS and WhatsApp. One ledger.')],
    ];
@endphp
<section class="mk-sec sb-block" data-site-block="section_product_fields">
    <div class="mk-wrap">
        <div class="mk-sec__head">
            <h2 class="mk-h2">{{ ___('Front of house to back office. Nothing bolted on.') }}</h2>
            <p class="mk-lead">{{ ___('One subscription covers the storefront, the kitchen, the floor and the back office.') }}</p>
        </div>
        <div class="mk-grid mk-grid--3">
            <article class="mk-card">
                <h3>{{ ___('Take orders everywhere') }}</h3>
                <p>{{ ___('Dine-in QR, pickup, delivery with courier tracking, curbside and WhatsApp. Split bills, tips, coupons, scheduled orders.') }}</p>
                <ul class="mk-chips">
                    @foreach ($sbOrderChips as [$sbLabel, $sbOn])
                        <li @class(['sb-chip--on' => $sbOn])>{{ $sbLabel }}</li>
                    @endforeach
                </ul>
            </article>
            <article class="mk-card">
                <h3>{{ ___('Kitchen screens that keep up') }}</h3>
                <p>{{ ___('Tickets route to the right station. Timers go amber before anything burns.') }}</p>
                <div class="sb-rows" aria-hidden="true">
                    <div class="mk-panel__row">
                        <span>#1042 · {{ ___('Table') }} 12<small>{{ ___('Mixed grill, 2x kofta') }}</small></span>
                        <x-ui.pill tone="warning">2:34</x-ui.pill>
                    </div>
                    <div class="mk-panel__row">
                        <span>#1043 · {{ ___('Pickup') }}<small>{{ ___('Shish tawook wrap') }}</small></span>
                        <x-ui.pill tone="success">0:41</x-ui.pill>
                    </div>
                </div>
            </article>
            <article class="mk-card">
                <h3>{{ ___('Numbers that tell you what to do') }}</h3>
                <p>{{ ___('Menu engineering, scan heatmaps, RFM segments, weekly digest.') }}</p>
                <div class="sb-bars" aria-hidden="true"><i></i><i></i><i></i><i class="is-top"></i><i></i><i></i><i></i></div>
                <p class="mk-small">{{ ___('Mixed grill platter is this week\'s star item.') }}</p>
            </article>
        </div>
        <div class="mk-grid mk-grid--4 sb-gap-top">
            @foreach ($sbWide as [$sbTitle, $sbBody])
                <article class="mk-card">
                    <h3>{{ $sbTitle }}</h3>
                    <p>{{ $sbBody }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>
