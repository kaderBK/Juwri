{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: section_features_tabbed — "One platform. Every tool your
     restaurant runs on.": the four capability areas as cards, each with its
     two highlight chips (the old timed tabs are shown all at once). --}}
@php
    $sbCaps = [
        ['book-open', ___('Menu & content'), ___('Build unlimited menus with categories, items, photos, variants, and prices. Add allergens and calories, feature your best dishes, and publish in 11 languages with full RTL support.'), [___('Unlimited items & categories'), ___('11 languages, RTL ready')]],
        ['orders', ___('Ordering & payments'), ___('Route orders straight to WhatsApp with cart, notes, and scheduling. Sell dine-in, takeaway, or delivery in any currency, with service charges and cash or card.'), [___('WhatsApp & table ordering'), ___('Any currency, zero commission')]],
        ['kitchen', ___('Operations & POS'), ___('A built-in cloud POS with kitchen tickets, multiple kitchen zones, table and reservation management, inventory alerts, and staff accounts with roles.'), [___('Kitchen tickets & KDS'), ___('Inventory, staff & roles')]],
        ['chart', ___('Analytics & growth'), ___('See orders, revenue, best-sellers, and scan heatmaps in real time. Export reports and grow with promotions, referrals, and 50 gamification tools.'), [___('Real-time dashboards'), ___('Reports, referrals & rewards')]],
    ];
@endphp
<section class="mk-sec mk-sec--tint sb-block" data-site-block="section_features_tabbed">
    <div class="mk-wrap">
        <div class="mk-sec__head">
            <h2 class="mk-h2">{{ ___('One platform. Every tool your restaurant runs on.') }}</h2>
        </div>
        <div class="mk-grid mk-grid--2">
            @foreach ($sbCaps as [$sbIcon, $sbTitle, $sbBody, $sbChips])
                <article class="mk-card">
                    <span class="mk-ico" aria-hidden="true"><x-ui.icon :name="$sbIcon" size="22" /></span>
                    <h3>{{ $sbTitle }}</h3>
                    <p>{{ $sbBody }}</p>
                    <ul class="mk-chips">
                        @foreach ($sbChips as $sbChip)
                            <li><x-ui.icon name="check" size="14" />{{ $sbChip }}</li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
        </div>
    </div>
</section>
