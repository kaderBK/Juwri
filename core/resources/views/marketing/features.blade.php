{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

{{-- /features: the full feature roster behind the home "See all features"
     grid (marketing/data/feature-defs.php), told as five chapters. Each row
     pairs the copy with a kit mock panel (no product screenshots). --}}

@section('title', ___('Every feature in one subscription').' - '.seo_brand())
@section('meta_description', ___('Explore every feature: QR menus, WhatsApp ordering, POS, kitchen screens, analytics, inventory, loyalty and more — one subscription, no add-ons.'))
@section('canonical', route('features'))

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => ___('Features')],
]) !!}
@endpush

@php
    $fgFeatures = require resource_path('views/marketing/data/feature-defs.php');
    $fx = collect($fgFeatures)->keyBy('id');

    /* Row data: 'ticks' (check list), 'trows'/'panel' (mock panel rows as
       [label, kind, value]; kind note|pill-ok|pill-warn|pill-brand), 'chips' /
       'chips_raw' (translated / proper-noun chips), 'bars' (mini chart caption),
       'widget' => 'kds' (kitchen tickets), 'link'. */
    $fxChapters = [
        [
            'anchor' => 'storefront',
            'tag' => ___('Storefront'),
            'title' => ___('What diners see'),
            'lead' => ___('40 themes wired to the real ordering engine, customized without code.'),
            'rows' => [
                [
                    'f' => 'f-qr-menu-builder',
                    'ticks' => [___('19 themes. Zero code.'), ___('Photograph a paper menu, AI builds the digital one')],
                    'trows' => [
                        [___('Theme: Crave'), 'pill-brand', ___('Published')],
                        [___('Arabic menu'), 'pill-ok', ___('Auto-translated')],
                        [___('Ramadan banner'), 'note', ___('Scheduled for iftar')],
                        [___('Sold-out items'), 'note', ___('Hide at zero stock')],
                    ],
                ],
                [
                    'f' => 'f-whatsapp-orders',
                    'ticks' => [___('QR & WhatsApp ordering straight from the table'), ___('Keep 100% of every order — flat subscription, no cut')],
                    'chips' => [___('Dine-in QR'), ___('Pickup'), ___('Delivery'), ___('Curbside'), ___('WhatsApp'), ___('Split bill')],
                    'link' => Route::has('whatsapp.landing') ? ['url' => route('whatsapp.landing'), 'label' => ___('Explore WhatsApp ordering')] : null,
                ],
                [
                    'f' => 'f-multi-language',
                    'trows' => [
                        [___('Arabic menu'), 'pill-ok', ___('Auto-translated')],
                    ],
                    // Language endonyms are proper nouns — never translated.
                    'chips_raw' => ['العربية', 'English', 'Türkçe', 'Français', 'हिन्दी', '中文', 'اردو'],
                ],
            ],
        ],
        [
            'anchor' => 'operations',
            'tag' => ___('Operations'),
            'title' => ___('The floor, the pass, the till'),
            'lead' => ___('Full POS with shifts and drawers, kitchen screens per station, live floor map.'),
            'rows' => [
                [
                    'f' => 'f-ordering-dashboard',
                    'widget' => 'kds',
                    'trows' => [
                        [___('New order — table 7'), 'note', ___('2× Classic Deluxe · just now')],
                    ],
                ],
                [
                    'f' => 'f-pos-kitchen',
                    'panel' => [
                        [___('Grill station'), 'pill-warn', ___('4 open tickets')],
                        [___('Fryer station'), 'pill-ok', ___('All clear')],
                        [___('Shift drawer'), 'note', ___('Opened 16:00 by Youssef')],
                        [___('Branch: Olaya'), 'pill-brand', ___('Active')],
                    ],
                    'ticks' => [___('Tickets route to the right station. Timers go amber before anything burns.')],
                ],
                [
                    'f' => 'f-inventory',
                    'panel' => [
                        [___('Food cost'), 'note', ___('28.4% of revenue this month')],
                        [___('PO #218 · Al Watania'), 'pill-warn', ___('Awaiting delivery')],
                        [___('Sold-out items'), 'note', ___('Hide at zero stock')],
                    ],
                    'ticks' => [___('Inventory to the gram')],
                ],
                [
                    'f' => 'f-reservations',
                    'panel' => [
                        [___('Table 7 · Zone A'), 'note', ___('Seated 24 min, bill requested')],
                        [___('Reservations + waitlist'), 'pill-ok', ___('On')],
                    ],
                    'ticks' => [___('Drag tables into zones, watch occupancy live, run reservations and a waitlist.')],
                ],
            ],
        ],
        [
            'anchor' => 'marketing',
            'tag' => ___('Marketing'),
            'title' => ___('Regulars, on autopilot'),
            'lead' => ___('Every order builds your list. Loyalty and reviews work it for you.'),
            'rows' => [
                [
                    'f' => 'f-sales-analytics',
                    'ticks' => [___('Menu engineering, scan heatmaps, RFM segments, weekly digest.')],
                    'bars' => ___('Mixed grill platter is this week\'s star item.'),
                ],
                [
                    'f' => 'f-loyalty',
                    'trows' => [
                        [___('Sleeping regulars'), 'note', ___('112 diners, 30+ days quiet')],
                        [___('WhatsApp blast'), 'pill-ok', ___('38% opened')],
                        [___('Points issued'), 'note', ___('18,240 this week')],
                    ],
                ],
                [
                    'f' => 'f-feedback',
                    'panel' => [
                        [___('Google reviews'), 'note', ___('4.7 average this month')],
                        [___('Bio page'), 'note', ___('1,204 link clicks this week')],
                        [___('WhatsApp blast'), 'pill-ok', ___('38% opened')],
                    ],
                    'ticks' => [___('Every order builds your list. Loyalty and reviews work it for you.')],
                ],
            ],
        ],
        [
            'anchor' => 'payments',
            'tag' => ___('Payments'),
            'title' => ___('Get paid your way. Stay legal without thinking.'),
            'rows' => [
                [
                    'f' => 'f-e-invoicing',
                    'label_override' => ___('27 payment gateways'),
                    'no_anchor' => true,
                    'blurb_override' => ___('Cards, wallets, cash on delivery and buy-now-pay-later — down to Syriatel Cash, MTN Cash and Sham Cash mobile wallets. Pick what your market trusts.'),
                    'icon_override' => 'M2 5h20v14H2zM2 9h20M6 15h4',
                    'ticks' => [___('No commission on your orders.').' '.___('Flat subscriptions, so busy nights stay yours.'), ___('Multi-currency display.').' '.___('Diners see prices in their own currency — pinned rates or a daily FX feed.')],
                    'chips_raw' => ['Stripe', 'Paylink', 'Paymob', 'Geidea', 'Fawry', 'Fatora', 'Noon Payments', 'T-Lync', 'Tamara', 'Tabby', 'GoCardless', 'Mollie', 'PayPal', 'Razorpay', 'Tap', 'Syriatel Cash'],
                ],
                [
                    'f' => 'f-e-invoicing',
                    'ticks' => [___('Invoices generated, signed and chained automatically. Live drivers for Saudi Arabia, Jordan and Egypt.'), ___('Phase-2 QR on every receipt, and an invoice chain your auditor will actually like.')],
                    'chips_raw' => ['ZATCA', 'JoFotara', 'ETA'],
                    'chips' => [___('UAE'), ___('Qatar'), ___('Kuwait'), ___('Bahrain'), ___('Oman'), ___('Morocco'), ___('Tunisia'), ___('Algeria')],
                ],
            ],
        ],
        [
            'anchor' => 'platform',
            'tag' => ___('Platform'),
            'title' => ___('Your brand. Your stack.'),
            'rows' => [
                [
                    'f' => 'f-branding',
                    'ticks' => [___('Your link-in-bio, but it takes orders.'), ___('42 designer presets')],
                    'panel' => [
                        [___('Theme: Crave'), 'pill-brand', ___('Published')],
                        [___('Bio page'), 'note', ___('1,204 link clicks this week')],
                        [___('Arabic menu'), 'pill-ok', ___('Auto-translated')],
                    ],
                ],
                [
                    'f' => 'f-branding',
                    'label_override' => ___('Integrations'),
                    'no_anchor' => true,
                    'blurb_override' => ___('One Integrations hub shows every connection with live status — plus signed webhooks and a scoped REST API for anything custom.'),
                    'icon_override' => 'M9 2v6M15 2v6M6 8h12v3a6 6 0 0 1-12 0zM12 17v5',
                    'trows' => [
                        [___('Zapier · Make · n8n'), 'pill-ok', ___('Official apps')],
                        [___('Webhooks'), 'pill-ok', ___('Signed + retried')],
                        [___('MCP servers'), 'note', ___('Admin, catalog, customer, dev, ordering')],
                    ],
                    'chips_raw' => ['Deliverect', 'Jahez', 'HungerStation', 'Keeta', 'Zomato', 'Swiggy', 'Foodics', 'Zapier', 'Make', 'n8n', 'REST API', 'Google Wallet', 'Reserve with Google'],
                    'link' => ['url' => url('/developers'), 'label' => ___('Read the developer docs')],
                ],
            ],
        ],
    ];

    $fxStats = [
        ['40', ___('Themes')],
        ['27', ___('Payment Gateways')],
        ['11', ___('Tax authorities')],
        ['7', ___('AI Providers')],
    ];

    $fxTone = ['pill-ok' => 'success', 'pill-warn' => 'warning', 'pill-brand' => 'accent'];

    $fxVerticals = [
        ['🏷️', ___('Products, variants & SKUs'), ___('Sizes, colours and options as real variants — each with its own SKU, price and stock.')],
        ['📦', ___('Live inventory'), ___('Track stock per product or per variant; a sold-out item removes its own buy button.')],
        ['💊', ___('OTC pharmacy'), ___('Over-the-counter catalogs with batch numbers, expiry dates and an age-check gate at checkout.')],
        ['🔧', ___('Auto-parts fitment'), ___('Shoppers filter parts by vehicle make, model and year, with OEM cross-references built in.')],
        ['🧺', ___('Services & bookings'), ___('Per-item or per-kg pricing with pickup, drop-off and time-slot booking for laundry and services.')],
        ['🏨', ___('Rooms & folio'), ___('Room types, availability and bookings, with orders charged straight to the room folio.')],
    ];

    $fxStatsHtml = '<ul class="mk-stats">';
    foreach ($fxStats as [$fxN, $fxL]) {
        $fxStatsHtml .= '<li class="mk-stat"><b>'.e($fxN).'</b><span>'.e($fxL).'</span></li>';
    }
    $fxStatsHtml .= '</ul>';

    $fxActions = [['url' => route('register'), 'label' => ___('Start free')]];
    if (Route::has('pricing')) {
        $fxActions[] = ['url' => route('pricing'), 'label' => ___('See pricing'), 'ghost' => true];
    }
@endphp

@section('content')
    @include('marketing.partials.page-hero', [
        'eyebrow' => ___('Features'),
        'title' => ___('Everything you need for your restaurant in one platform'),
        'sub' => ___('No modules to bolt on and no per-feature pricing. Every plan runs the same engine — pick what you switch on.'),
        'actions' => $fxActions,
        'after' => $fxStatsHtml,
    ])

    {{-- Sticky chapter nav --}}
    <nav class="mk-subnav" aria-label="{{ ___('Jump to a feature') }}">
        <div class="mk-wrap mk-subnav__in">
            @foreach ($fxChapters as $ch)
                <a href="#{{ $ch['anchor'] }}">{{ $ch['tag'] }}</a>
            @endforeach
        </div>
    </nav>

    {{-- Tile index: every tile jumps to its chapter row --}}
    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('Jump to a feature') }}</span>
            </div>
            <ul class="mk-tiles">
                @foreach ($fgFeatures as $f)
                    <li>
                        <a class="mk-tile" href="#{{ $f['id'] }}">
                            <span class="mk-tile__ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $f['icon'] }}"/></svg></span>
                            <span>{{ $f['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Chapters --}}
    @foreach ($fxChapters as $ci => $ch)
        <section class="mk-sec{{ $ci % 2 === 0 ? ' mk-sec--tint' : '' }}" id="{{ $ch['anchor'] }}">
            <div class="mk-wrap">
                <div class="mk-sec__head">
                    <span class="mk-eyebrow">{{ $ch['tag'] }}</span>
                    <h2 class="mk-h2">{{ $ch['title'] }}</h2>
                    @if (! empty($ch['lead']))
                        <p class="mk-lead">{{ $ch['lead'] }}</p>
                    @endif
                </div>

                @foreach ($ch['rows'] as $ri => $row)
                    @php
                        $f = $fx[$row['f']];
                        $label = $row['label_override'] ?? $f['label'];
                        $blurb = $row['blurb_override'] ?? $f['blurb'];
                        $icon = $row['icon_override'] ?? $f['icon'];
                        $rowId = empty($row['no_anchor']) ? $f['id'] : null;
                        $rowLines = array_merge($row['panel'] ?? [], $row['trows'] ?? []);
                    @endphp
                    <div class="mk-split{{ $ri % 2 === 1 ? ' mk-split--rev' : '' }}" @if ($rowId) id="{{ $rowId }}" @endif>
                        <div class="mk-panel">
                            <div class="mk-panel__head">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                                <span>{{ $label }}</span>
                            </div>

                            @if (($row['widget'] ?? null) === 'kds')
                                <div class="mk-panel__row">
                                    <span>#1042 · {{ ___('Table') }} 12<small class="fp-sub">{{ ___('Mixed grill, 2x kofta') }}</small></span>
                                    <x-ui.pill tone="warning">2:34</x-ui.pill>
                                </div>
                                <div class="mk-panel__row">
                                    <span>#1043 · {{ ___('Pickup') }}<small class="fp-sub">{{ ___('Shish tawook wrap') }}</small></span>
                                    <x-ui.pill tone="success">0:41</x-ui.pill>
                                </div>
                            @endif

                            @foreach ($rowLines as [$b, $kind, $val])
                                <div class="mk-panel__row">
                                    <span>{{ $b }}</span>
                                    @if ($kind === 'note')
                                        <span class="mk-panel__note">{{ $val }}</span>
                                    @else
                                        <x-ui.pill :tone="$fxTone[$kind] ?? 'neutral'">{{ $val }}</x-ui.pill>
                                    @endif
                                </div>
                            @endforeach

                            @if (isset($row['bars']))
                                <div class="fp-bars" aria-hidden="true">
                                    @foreach ([34, 52, 41, 92, 60, 47, 73] as $bi => $bh)
                                        <i class="fp-bar fp-bar--{{ $bh }}{{ $bi === 3 ? ' is-top' : '' }}"></i>
                                    @endforeach
                                </div>
                                <p class="mk-panel__foot">{{ $row['bars'] }}</p>
                            @endif

                            @if (! empty($row['chips_raw']) || ! empty($row['chips']))
                                <ul class="mk-chips fp-panel-chips">
                                    @foreach (($row['chips_raw'] ?? []) as $chip)<li>{{ $chip }}</li>@endforeach
                                    @foreach (($row['chips'] ?? []) as $chip)<li>{{ $chip }}</li>@endforeach
                                </ul>
                            @endif
                        </div>

                        <div class="fp-copy">
                            <span class="mk-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}"/></svg></span>
                            <h3 class="mk-h2 fp-copy__title">{{ $label }}</h3>
                            <p class="mk-muted">{{ $blurb }}</p>
                            @if (! empty($row['ticks']))
                                <ul class="mk-checks">
                                    @foreach ($row['ticks'] as $tick)
                                        <li>{{ $tick }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if (! empty($row['link']))
                                <p class="fp-link"><a href="{{ $row['link']['url'] }}">{{ $row['link']['label'] }}</a></p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    {{-- Verticals: the same engine, tuned for non-restaurant trades. --}}
    <section class="mk-sec" id="verticals">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('More than restaurants') }}</span>
                <h2 class="mk-h2">{{ ___('The same engine, tuned for your trade') }}</h2>
                <p class="mk-lead">{{ ___('Pick a store type and the catalog, fields and checkout follow it — retail, cosmetics, clothing, car parts, laundry, pharmacy or a hotel.') }}</p>
            </div>
            <div class="mk-cards">
                @foreach ($fxVerticals as [$vIcon, $vTitle, $vBlurb])
                    <article class="mk-card">
                        <span class="mk-ico" aria-hidden="true">@include('marketing.partials.vt-icon', ['icon' => $vIcon, 'size' => 22])</span>
                        <h3>{{ $vTitle }}</h3>
                        <p>{{ $vBlurb }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Closing: pricing hand-off --}}
    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-cta">
                <h2 class="mk-h2">{{ ___('Every feature in one subscription') }}</h2>
                <div class="mk-actions">
                    <a href="{{ route('register') }}" class="ui-btn">{{ ___('Start free') }}</a>
                    @if (Route::has('pricing'))
                        <a href="{{ route('pricing') }}#compare" class="ui-btn ui-btn--ghost">{{ ___('Compare every plan') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endsection
