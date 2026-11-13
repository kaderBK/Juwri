{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Dashboard'))
@section('page_title', ___('Dashboard'))
@section('page_sub')
    @php $__greetActor = $dashboardActor ?? dashboard_actor(); $__greetPlan = $user_plan_name ?? ___('Free'); @endphp
    {{ ___('Welcome back') }}, {{ $__greetActor?->name ?? ___('there') }} · {{ $__greetPlan }}
@endsection

@section('page_actions')
    @if(($aiAssistant ?? false) && Route::has('dashboard.assistant'))
        {{-- P2-6: grounded assistant drawer trigger (feature-flagged OFF by default). --}}
        <button type="button" class="ui-btn ui-btn--secondary" data-ui-do="drawer-open" data-ui-target="ai-assistant-drawer" aria-controls="ai-assistant-drawer">
            <span class="ui-btn__icon"><x-ui.icon name="sparkle" /></span>{{ ___('Ask assistant') }}
        </button>
    @endif
    @if(!$isStaffDashboard && Route::has('stores.create'))
        <x-ui.button :href="route('stores.create')" icon="plus">{{ ___('Add store') }}</x-ui.button>
    @endif
    @if(Route::has('stores.index'))
        <x-ui.button :href="route('stores.index')" variant="secondary" icon="store">{{ ___('Stores') }}</x-ui.button>
    @endif
@endsection

@section('content')
@php
    $actor = $dashboardActor ?? dashboard_actor();
    $stores = $stores ?? collect();
    $transactions = $transactions ?? collect();
    $days = $days ?? [];
    $scans = $scans ?? [];
    $totalScans = array_sum($scans);
    $peak = max($scans ?: [0]);
    $planName = $user_plan_name ?? ___('Free');
    $primaryRestaurant = $stores->first();
    $linkRestaurant = ($activeStore ?? null) ?: $primaryRestaurant;
    // P0-9: empty-state CTAs point at the CONTEXT store's real next action.
    $qrCtaUrl = ($linkRestaurant && Route::has('stores.qrDesigner')) ? route('stores.qrDesigner', $linkRestaurant->id) : null;
    // P6-297: hide the "Build your menu" CTA the moment the store has at
    // least one ACTIVE priced item — the menu step is closed, so a second
    // "Build your menu" link in the empty-state card is noise that misleads
    // the dashboard acceptance tests (OnboardingChecklistAccuracyIBTest,
    // OwnerDashboardOnboardingRealChecksTest) and the owner.
    $menuActivePricedEmpty = $linkRestaurant
        ? \Juwri\Modules\Menu\Models\Menu::where('store_id', $linkRestaurant->id)
            ->where('active', 1)
            ->where('price_minor', '>', 0)
            ->exists()
        : false;
    $menuCtaUrl = ($linkRestaurant && Route::has('stores.menu') && !$menuActivePricedEmpty) ? route('stores.menu', $linkRestaurant->id) : null;
    $visitorTotal = ($uniqueVisitors ?? 0) + ($repeatVisitors ?? 0);
    $usageItems = [
        ['label' => ___('Stores'), 'used' => data_get($usageLimits ?? [], 'store.used', $total_stores ?? $stores->count()), 'limit' => data_get($usageLimits ?? [], 'store.limit')],
        ['label' => ___('Scans this month'), 'used' => data_get($usageLimits ?? [], 'scan.used', $totalScans), 'limit' => data_get($usageLimits ?? [], 'scan.limit', $scan_goal ?? null)],
        ['label' => ___('Menu items'), 'used' => data_get($usageLimits ?? [], 'menu.used', $total_items ?? 0), 'limit' => data_get($usageLimits ?? [], 'menu.limit')],
    ];
    $quickActions = [];
    if (!$isStaffDashboard && Route::has('stores.create')) $quickActions[] = ['href' => route('stores.create'), 'icon' => 'plus', 'label' => ___('Add store'), 'desc' => ___('Create a new QR menu')];
    if ((!$isStaffDashboard || dashboard_allows('orders')) && Route::has('stores.orders')) $quickActions[] = ['href' => route('stores.orders', $linkRestaurant?->id), 'icon' => 'orders', 'label' => ___('Orders'), 'desc' => ___('Review recent orders')];
    if ((!$isStaffDashboard || dashboard_allows('analytics')) && Route::has('analytics.index')) $quickActions[] = ['href' => route('analytics.index'), 'icon' => 'chart', 'label' => ___('Analytics'), 'desc' => ___('Open scan reports')];
    if ((!$isStaffDashboard || dashboard_allows('kds')) && Route::has('kds.index')) $quickActions[] = ['href' => route('kds.index'), 'icon' => 'kitchen', 'label' => ___('Kitchen display'), 'desc' => ___('Monitor live tickets')];
    if ((!$isStaffDashboard || dashboard_allows('customers')) && Route::has('customers.index')) $quickActions[] = ['href' => route('customers.index'), 'icon' => 'users', 'label' => ___('Customers'), 'desc' => ___('View diner profiles')];
    if (!$isStaffDashboard && Route::has('settings')) $quickActions[] = ['href' => route('settings'), 'icon' => 'settings', 'label' => ___('Settings'), 'desc' => ___('Account and billing')];

    $cmp = $compare ?? true;
        $periodName = $periodLabel ?? ___('This month');

    // Owners with activity see the business first and the setup lists after it;
    // a brand-new account still sees setup first.
    $hasActivity = ($thisMonthOrders ?? 0) > 0 || ($todayOrderCount ?? 0) > 0 || $totalScans > 0;

    // Hero bars: one bar per day of the selected period (sales, or orders for staff).
    $heroSeries = array_values(array_map('floatval', $isStaffDashboard ? ($ordersSeries ?? []) : ($salesSeries ?? [])));
    $heroMax = max($heroSeries ?: [0]) ?: 1;
    $heroFirst = $days[0] ?? '';
    $heroLast = !empty($days) ? $days[array_key_last($days)] : '';
    $heroKey = $isStaffDashboard ? 'orders' : 'sales';
    // Month-to-date periods include days that have not happened yet: mark today,
    // and draw the days after it as empty placeholders instead of zero bars.
    $todayIdx = array_search(date_formating(now(), 'd M'), $days, true);
    $currentIdx = $todayIdx === false ? array_key_last($heroSeries) : $todayIdx;
@endphp

<div class="ui-scope ui-ov">

{{-- P1-13 13e: platform announcements are the ee Platform module's — it names its banner partial in this slot. --}}
@php
    $announcementBanners = \Juwri\Core\Support\Hooks::first('owner.dashboard.announcement-banners');
@endphp
@if(is_string($announcementBanners))
    @include($announcementBanners)
@endif
@includeIf('owner.dashboard.partials.monetization-flip-notice')
@includeIf('owner.dashboard.partials.whatsapp-extra-numbers')

@unless($isStaffDashboard ?? false)
    {{-- rev/external-redirect B2: alert the owner that a store's public menu is
         redirecting away, with a one-click disable. --}}
    @includeIf('owner.dashboard.partials.external-redirect-banner')
    {{-- P6-47: nudge the owner to turn on 2FA or add a passkey. --}}
    @include('owner.dashboard.partials.security-nudge')
@endunless

@if(!($isStaffDashboard ?? false) && !$hasActivity)
    @include('owner.dashboard.partials.onboarding-checklist')
    {{-- WI-F4: what the owner already OWNS — after onboarding, never before it. --}}
    @include('owner.dashboard.partials.spotlight-owned')
@endif

@if(!empty($fleet ?? []))
    {{-- P2-4: multi-store fleet landing (scope=all, >1 store). Row → set context. --}}
    <section class="ui-card ui-card--flush ui-ov-fleet">
        <div class="ui-card__head">
            <div>
                <h2 class="ui-card__title">{{ ___('All stores') }}</h2>
                <p class="ui-ov-muted">{{ ___('Today across your stores. Select a store to focus.') }}</p>
            </div>
            @if(($activeStore->title ?? null) === null && ($stores->count() ?? 0) > 1)
                <x-ui.tag tone="accent">{{ number_format(count($fleet)) }} {{ ___('stores') }}</x-ui.tag>
            @endif
        </div>
        {{-- MC-40: fleet sales split by currency (only when the fleet spans >1
             currency; a single-currency fleet renders exactly as before). --}}
        @if(count($fleetCurrencyTotals ?? []) > 1)
            <div class="ui-cluster ui-ov-fleet__currencies">
                <span class="ui-ov-muted">{{ ___('Today by currency') }}:</span>
                @foreach($fleetCurrencyTotals as $cur_code => $cur_sum)
                    <x-ui.tag><span class="ui-ltr">{{ $cur_code }} {{ number_format($cur_sum, currency_decimals($cur_code)) }}</span></x-ui.tag>
                @endforeach
            </div>
        @endif
        <table class="ui-table">
            <thead>
                <tr>
                    <th>{{ ___('Store') }}</th>
                    <th class="ui-table__num">{{ ___('Today’s sales') }}</th>
                    <th class="ui-table__num">{{ ___('Today’s orders') }}</th>
                    <th class="ui-table__num">{{ ___('vs last week') }}</th>
                    <th class="ui-table__num">{{ ___('Live tickets') }}</th>
                    <th class="ui-table__num">{{ ___('Needs attention') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($fleet as $row)
                    <tr class="fleet-row ui-ov-fleet__row" data-ui-do="submit-closest">
                        <td data-primary>
                            {{ $row['title'] }}
                            @if(Route::has('dashboard.context'))
                                <form method="POST" action="{{ route('dashboard.context') }}" class="ui-hidden">
                                    @csrf
                                    <input type="hidden" name="store_id" value="{{ $row['id'] }}">
                                </form>
                            @endif
                        </td>
                        <td class="ui-table__num" data-label="{{ ___('Today’s sales') }}">{{ dashboard_money($row['sales'], null, (int) $row['id']) }}</td>
                        <td class="ui-table__num" data-label="{{ ___('Today’s orders') }}">{{ number_format($row['orders']) }}</td>
                        <td class="ui-table__num" data-label="{{ ___('vs last week') }}">
                            @if($row['delta'] === null)<span class="ui-ov-muted">—</span>
                            @else<span class="ui-ltr ui-ov-trend" data-trend="{{ $row['delta'] > 0 ? 'up' : ($row['delta'] < 0 ? 'down' : 'flat') }}">{{ ($row['delta'] > 0 ? '+' : '').$row['delta'] }}%</span>@endif
                        </td>
                        <td class="ui-table__num" data-label="{{ ___('Live tickets') }}">{{ number_format($row['live']) }}</td>
                        <td class="ui-table__num" data-label="{{ ___('Needs attention') }}">
                            @if($row['attention'] > 0)<x-ui.count :value="$row['attention']" tone="warning" />
                            @else<span class="ui-ov-ok" aria-label="{{ ___('All clear') }}"><x-ui.icon name="check" size="18" /></span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
    @unless($isStaffDashboard ?? false)
        @include('owner.pwa.install-card')
    @endunless
@else

@php
    $ordersUrl = Route::has('stores.orders') ? route('stores.orders', $linkRestaurant?->id) : null;
    $kdsUrl = Route::has('kds.index') ? route('kds.index') : null;
@endphp

{{-- Period bar (shared partial — P1-4) --}}
@include('owner.partials.periodbar', ['showScope' => !($isStaffDashboard ?? false)])

<div class="ui-ov-top">
    {{-- Hero: the period's headline number with one bar per day. --}}
    <section class="ui-card ui-card--hero ui-ov-hero" aria-label="{{ $isStaffDashboard ? ___('Orders') : ___('Sales') }}">
        <x-ui.hero-figure
            :label="($isStaffDashboard ? ___('Orders') : ___('Sales')) . ' · ' . $periodName"
            :value="$isStaffDashboard ? number_format($thisMonthOrders ?? 0) : dashboard_money($salesRevenue ?? 0, $moneyCurrency ?? null, $moneyStoreId ?? null)"
            :delta="$cmp ? \Juwri\Core\Support\ViewHelpers::trendText($trends[$heroKey] ?? 0) : null"
            :trend="($cmp ? \Juwri\Core\Support\ViewHelpers::trendState($trends[$heroKey] ?? 0) : 'flat') === 'down' ? 'down' : 'up'"
            :note="$cmp ? ___('vs last week') : null" />

        @if(!$isStaffDashboard && !empty($otherCurrencies))
            <p class="ui-ov-hero__other" data-other-currencies>{{ ___('Other currencies') }}:
                @foreach($otherCurrencies as $code => $amt){{ $loop->first ? '' : ' · ' }}{{ number_format($amt, money_decimals($code)) }} {{ $code }}@endforeach</p>
        @endif

        @if(count($heroSeries) > 1 && array_sum($heroSeries) > 0)
            <div class="ui-ov-bars" role="img" aria-label="{{ ($isStaffDashboard ? ___('Orders') : ___('Sales')) . ' · ' . $heroFirst . ' – ' . $heroLast }}">
                @foreach($heroSeries as $i => $v)
                    @if($todayIdx !== false && $i > $todayIdx)
                        <span class="ui-ov-bars__bar" data-future title="{{ $days[$i] ?? '' }}"></span>
                    @else
                        <span class="ui-ov-bars__bar" @if($i === $currentIdx) data-current @endif
                              style="block-size: {{ max(3, round(($v / $heroMax) * 100)) }}%"
                              title="{{ ($days[$i] ?? '') }} — {{ $isStaffDashboard ? number_format($v) : dashboard_money($v, $moneyCurrency ?? null, $moneyStoreId ?? null) }}"></span>
                    @endif
                @endforeach
            </div>
            <div class="ui-ov-bars__axis" aria-hidden="true"><span>{{ $heroFirst }}</span><span>{{ $heroLast }}</span></div>
        @endif

        <dl class="ui-ov-hero__stats">
            @if(!$isStaffDashboard)
                <div>
                    <dt>{{ ___('Orders') }}</dt>
                    <dd>{{ number_format($thisMonthOrders ?? 0) }}@if($cmp)<span class="ui-ov-hero__delta ui-ltr" data-trend="{{ ($cmp ? \Juwri\Core\Support\ViewHelpers::trendState($trends['orders'] ?? 0) : 'flat') }}">{{ \Juwri\Core\Support\ViewHelpers::trendText($trends['orders'] ?? 0) }}</span>@endif</dd>
                </div>
                <div>
                    <dt>{{ ___('Avg order value') }}</dt>
                    <dd>{{ dashboard_money($avgOrderValue ?? 0, $moneyCurrency ?? null, $moneyStoreId ?? null) }}@if($cmp)<span class="ui-ov-hero__delta ui-ltr" data-trend="{{ ($cmp ? \Juwri\Core\Support\ViewHelpers::trendState($trends['aov'] ?? 0) : 'flat') }}">{{ \Juwri\Core\Support\ViewHelpers::trendText($trends['aov'] ?? 0) }}</span>@endif</dd>
                </div>
                <div>
                    <dt>{{ ___('Scans') }}</dt>
                    <dd>{{ number_format($totalScans) }}@if($cmp)<span class="ui-ov-hero__delta ui-ltr" data-trend="{{ ($cmp ? \Juwri\Core\Support\ViewHelpers::trendState($trends['scans'] ?? 0) : 'flat') }}">{{ \Juwri\Core\Support\ViewHelpers::trendText($trends['scans'] ?? 0) }}</span>@endif</dd>
                </div>
            @else
                <div><dt>{{ ___('Scans') }}</dt><dd>{{ number_format($totalScans) }}</dd></div>
                <div><dt>{{ ___('Peak day') }}</dt><dd>{{ number_format($peak) }}</dd></div>
                <div><dt>{{ ___('Stores') }}</dt><dd>{{ number_format($total_stores ?? $stores->count()) }}</dd></div>
            @endif
        </dl>
    </section>

    <div class="ui-ov-side">
        @if(!in_array('livestrip', $hiddenCards ?? []))
            {{-- Live strip: today's numbers, each a shortcut to the screen behind it. --}}
            <section class="ui-ov-live" aria-labelledby="ui-ov-live-title">
                <h2 class="ui-section-title" id="ui-ov-live-title">{{ ___('Today') }}</h2>
                <div class="ui-ov-live__grid">
                    @unless($isStaffDashboard)
                        <a class="ui-stat ui-ov-tile" @if($ordersUrl) href="{{ $ordersUrl }}" @endif>
                            <span class="ui-stat__icon"><x-ui.icon name="trend-up" /></span>
                            <span class="ui-stat__value">{{ dashboard_money($todaySales ?? 0, $moneyCurrency ?? null, $moneyStoreId ?? null) }}</span>
                            <span class="ui-stat__label">{{ ___('Today’s sales') }}</span>
                        </a>
                    @endunless
                    <a class="ui-stat ui-ov-tile" @if($ordersUrl) href="{{ $ordersUrl }}" @endif>
                        <span class="ui-stat__icon"><x-ui.icon name="orders" /></span>
                        <span class="ui-stat__value">{{ number_format($todayOrderCount ?? 0) }}</span>
                        <span class="ui-stat__label">{{ ___('Today’s orders') }}</span>
                    </a>
                    <div class="ui-stat ui-ov-tile">
                        <span class="ui-stat__icon"><x-ui.icon name="clock" /></span>
                        <span class="ui-stat__value">{{ isset($avgPrepMin) ? $avgPrepMin . ' ' . ___('min') : '—' }}</span>
                        <span class="ui-stat__label">{{ ___('Avg prep time') }}</span>
                    </div>
                    <a class="ui-stat ui-ov-tile dashboard-livestrip" @if($kdsUrl) href="{{ $kdsUrl }}" @endif @if(($liveTickets ?? 0) > 0) data-live @endif>
                        <span class="ui-stat__icon"><x-ui.icon name="kitchen" /></span>
                        <span class="ui-stat__value">
                            @if(($liveTickets ?? 0) > 0)<span class="ui-ov-live__dot" aria-hidden="true"></span>@endif
                            {{ number_format($liveTickets ?? 0) }}
                        </span>
                        <span class="ui-stat__label">{{ ___('Live tickets') }}</span>
                    </a>
                </div>
            </section>
        @endif

        {{-- P2-2: needs-attention exceptions — hidden when all clear. --}}
        @if(!empty($attention))
            <section class="ui-ov-attention" aria-labelledby="ui-ov-attention-title">
                <h2 class="ui-section-title" id="ui-ov-attention-title">
                    {{ ___('Needs attention') }}
                    <x-ui.count :value="count($attention)" tone="warning" />
                </h2>
                <div class="ui-list">
                    @foreach($attention as $ex)
                        @php $tone = in_array($ex['severity'] ?? '', ['danger', 'warning', 'success'], true) ? $ex['severity'] : 'warning'; @endphp
                        <div class="ui-row attention-row" data-type="{{ $ex['type'] }}">
                            <span class="ui-row__icon" data-tone="{{ $tone }}"><x-ui.icon name="alert" size="22" /></span>
                            <span class="ui-row__body">
                                <span class="ui-row__title">{{ $ex['label'] }}</span>
                                <span class="ui-row__sub"><span class="ui-ltr">{{ $ex['count'] > 99 ? '99+' : $ex['count'] }}</span></span>
                            </span>
                            <span class="ui-row__trail ui-ov-attention__actions">
                                @if(!empty($ex['action_url']))
                                    {{-- D7: one-click un-86 for sold-out items --}}
                                    <form action="{{ $ex['action_url'] }}" method="post">
                                        @csrf
                                        <button type="submit" class="ui-btn ui-btn--success ui-btn--sm">{{ ___('Un-86 all') }}</button>
                                    </form>
                                @endif
                                <a href="{{ $ex['url'] }}" class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('Fix') }}</a>
                            </span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</div>

{{-- Owner PWA install / alerts card: after the business, not above it (tlf-d R2). --}}
@unless($isStaffDashboard ?? false)
    @include('owner.pwa.install-card')
@endunless

@if(!empty($quickActions))
    <nav class="ui-ov-actions" aria-label="{{ ___('Quick access') }}">
        @foreach($quickActions as $action)
            <a class="ui-ov-action" href="{{ $action['href'] }}">
                <span class="ui-ov-action__icon"><x-ui.icon :name="$action['icon']" /></span>
                <span class="ui-ov-action__text">
                    <span class="ui-ov-action__label">{{ $action['label'] }}</span>
                    <span class="ui-ov-action__desc">{{ $action['desc'] }}</span>
                </span>
            </a>
        @endforeach
    </nav>
@endif

{{-- H10: an un-backfilled order contributes 0 to the snapshot-based revenue sums, so a
     window with legacy orders would read quietly low. Surface it instead of hiding it. --}}
@if(!$isStaffDashboard && ($revenueGap ?? 0) > 0)
    <x-ui.banner tone="warning">
        {{ ___('Sales figures are still being backfilled') }} —
        {{ number_format($revenueGap) }} {{ ___('older orders are not counted yet.') }}
        <span class="ui-ov-muted">{{ ___('Ask your provider to run the order-totals backfill.') }}</span>
    </x-ui.banner>
@endif

@if(!empty($peerBenchmark))
    {{-- P2-6: anonymized "vs similar stores" benchmark (opted-in, cohort n≥10). --}}
    <x-ui.banner>
        {{ ___('vs similar stores') }}:
        {{ ___('median AOV') }} {{ price_symbol_format($peerBenchmark->median_aov) }} ·
        {{ ___('median orders (30d)') }} {{ number_format($peerBenchmark->median_orders) }}
        <span class="ui-ov-muted">({{ number_format($peerBenchmark->cohort_n) }} {{ ___('stores') }})</span>
    </x-ui.banner>
@endif

<div class="ui-ov-pair">
    {{-- Top items --}}
    @if(!$isStaffDashboard && !in_array('topitems', $hiddenCards ?? []))
        <x-ui.card :title="___('Top items') . ' · ' . $periodName" class="ui-ov-topitems topitems">
            @if(Route::has('analytics.index'))
                <x-slot:actions><a class="ui-btn ui-btn--link ui-btn--sm" href="{{ route('analytics.index') }}">{{ ___('View report') }}</a></x-slot:actions>
            @endif
            @if(!empty($topItems))
                @php $maxQty = \Juwri\Core\Support\ViewHelpers::maxQty($topItems); @endphp
                <ol class="ui-ov-rank">
                    @foreach($topItems as $item)
                        <li class="ui-ov-rank__row">
                            <span class="ui-ov-rank__thumb">
                                @if(!empty($item['image']) && $item['image'] !== 'default.png')
                                    <img src="{{ asset('storage/menu/' . $item['image']) }}" alt="" loading="lazy">
                                @else{{ mb_strtoupper(mb_substr($item['name'], 0, 1)) }}@endif
                            </span>
                            <span class="ui-ov-rank__body">
                                <span class="ui-ov-rank__name">{{ $item['name'] }}</span>
                                <span class="ui-ov-meter" aria-hidden="true"><span style="inline-size: {{ round(($item['qty'] / $maxQty) * 100) }}%"></span></span>
                            </span>
                            <span class="ui-ov-rank__nums">
                                <strong>{{ dashboard_money($item['revenue'], $moneyCurrency ?? null, $moneyStoreId ?? null) }}</strong>
                                <span class="ui-ltr">{{ number_format($item['qty']) }}×</span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            @else
                <x-ui.empty :title="___('No orders yet')" :body="___('Your best-selling items appear here once you take orders.')" icon="orders">
                    @if($menuCtaUrl)<x-ui.button :href="$menuCtaUrl" size="sm">{{ ___('Build your menu') }}</x-ui.button>@endif
                </x-ui.empty>
            @endif
        </x-ui.card>
    @endif

    {{-- Your stores --}}
    <x-ui.card :title="___('Your stores')" class="ui-ov-stores">
        @if(Route::has('stores.index'))
            <x-slot:actions><a class="ui-btn ui-btn--link ui-btn--sm" href="{{ route('stores.index') }}">{{ ___('View all') }}</a></x-slot:actions>
        @endif
        @forelse($stores->take(5) as $store)
            <div class="ui-ov-store">
                <span class="ui-ov-store__logo">
                    @if(!empty($store->main_image) && $store->main_image !== 'default.png')
                        <img src="{{ asset('storage/store/logo/'.$store->main_image) }}" alt="{{ $store->title }}">
                    @else{{ \Illuminate\Support\Str::of($store->title)->substr(0, 1)->upper() }}@endif
                </span>
                <span class="ui-ov-store__body">
                    <span class="ui-ov-store__name">{{ $store->title }}</span>
                    <span class="ui-ov-store__slug ui-ltr">/{{ $store->slug }}</span>
                </span>
                <span class="ui-ov-store__actions">
                    @if(Route::has('publicView'))
                        <x-ui.icon-button :href="route('publicView', $store->slug)" icon="external" :label="___('View store')" target="_blank" rel="noopener" />
                    @endif
                    @if(Route::has('stores.orders') && (!$isStaffDashboard || dashboard_allows('orders')))
                        <x-ui.icon-button :href="route('stores.orders', $store->id)" icon="orders" :label="___('View orders')" />
                    @endif
                </span>
            </div>
        @empty
            <x-ui.empty :title="___('No stores yet')" :body="___('Create your first store to publish a QR menu and start tracking scans.')" icon="store">
                @if(!$isStaffDashboard && Route::has('stores.create'))
                    <x-ui.button :href="route('stores.create')" size="sm" icon="plus">{{ ___('Add your first store') }}</x-ui.button>
                @endif
            </x-ui.empty>
        @endforelse
    </x-ui.card>
</div>

{{-- Sales & orders trend --}}
@if(!$isStaffDashboard && !in_array('trend', $hiddenCards ?? []))
    @php
        $salesSeries = $salesSeries ?? [];
        $ordersSeries = $ordersSeries ?? [];
        $lastDay = !empty($days) ? $days[array_key_last($days)] : '';
        $hasTrend = array_sum($salesSeries) > 0 || array_sum($ordersSeries) > 0;
    @endphp
    <x-ui.card :title="___('Sales & orders') . ' · ' . $periodName" class="ui-ov-trend">
        @if($hasTrend)
            <x-ui.trendchart :labels="$days ?? []" :series="[['name' => ___('Sales'), 'data' => $salesSeries, 'fill' => true], ['name' => ___('Orders'), 'data' => $ordersSeries]]" />
            <div class="ui-ov-legend trend-legend">
                <span data-series="sales">{{ ___('Sales') }} · {{ dashboard_money($salesRevenue ?? 0, $moneyCurrency ?? null, $moneyStoreId ?? null) }}</span>
                <span data-series="orders">{{ ___('Orders') }} · {{ number_format($thisMonthOrders ?? 0) }}</span>
                <span class="ui-ov-legend__range">{{ $days[0] ?? '' }} – {{ $lastDay }}</span>
            </div>
        @else
            <x-ui.empty :title="___('No sales yet')" :body="___('Sales and orders over time appear here once you start taking orders.')" icon="chart">
                @if($menuCtaUrl)<x-ui.button :href="$menuCtaUrl" size="sm">{{ ___('Build your menu') }}</x-ui.button>@endif
            </x-ui.empty>
        @endif
    </x-ui.card>
@endif

{{-- Branch comparison: a chain tool (ee) — the card comes from the Analytics ee half; no card in Community. --}}
@unless($isStaffDashboard)
    @if(is_array($branchCard ?? null))
        @include($branchCard['view'], ['rows' => $branchCard['rows'], 'periodName' => $periodName])
    @endif
@endunless

{{-- Scans: chart + breakdowns --}}
@php $showBreakdowns = !($isStaffDashboard && !dashboard_allows('analytics')) && !in_array('breakdowns', $hiddenCards ?? []); @endphp
<x-ui.card :title="___('Scans overview') . ' · ' . $periodName" class="ui-ov-scans">
    @if($totalScans > 0)
        {{-- P1-7: shimmer skeleton matching the final chart layout — visible until
             ApexCharts mounts, so the card never flashes an empty hole. --}}
        <div id="dash-scans-skel" class="ui-ov-chart-skel" aria-hidden="true">
            <span class="ui-ov-chart-skel__line"></span>
            <span class="ui-ov-chart-skel__area"></span>
        </div>
        <div id="dash-scans" class="ui-ov-chart" data-days='@json($days)' data-scans='@json($scans)' data-label="{{ ___('Scans') }}"></div>
    @else
        <x-ui.empty :title="___('No scans in this period')" :body="___('Share your QR menu — scans over time will chart here.')" icon="qr">
            @if($qrCtaUrl)<x-ui.button :href="$qrCtaUrl" size="sm">{{ ___('Open QR builder') }}</x-ui.button>@endif
        </x-ui.empty>
    @endif
</x-ui.card>

@if($showBreakdowns)
    @php
        $hours = $scansByHour ?? array_fill(0, 24, 0);
        $hourMax = max($hours ?: [0]) ?: 1;
        $hourTotal = array_sum($hours);
        $devices = $scansByDevice ?? [];
        $deviceTotal = array_sum($devices);
        $langs = $topLanguages ?? collect();
        $langMax = \Juwri\Core\Support\ViewHelpers::maxLanguageCount($langs);
    @endphp
    <div class="ui-ov-trio">
        <x-ui.card :title="___('Scans by hour')">
            @if($hourTotal > 0)
                <div class="ui-ov-hours breakdown-hours" role="img" aria-label="{{ ___('Scans by hour of day') }}">
                    @foreach($hours as $h => $count)
                        <span style="block-size: {{ max(2, round(($count / $hourMax) * 100)) }}%" title="{{ sprintf('%02d:00', $h) }} — {{ number_format($count) }}"></span>
                    @endforeach
                </div>
                <div class="ui-ov-bars__axis ui-ov-hours__axis ui-ltr" aria-hidden="true"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>
            @else
                <x-ui.empty :title="___('No scans yet')" :body="___('Hourly activity appears here once your menus are scanned.')" icon="clock" />
            @endif
        </x-ui.card>
        <x-ui.card :title="___('By device')">
            @if($deviceTotal > 0)
                <div class="ui-ov-meters">
                    @foreach($devices as $label => $count)
                        <div class="ui-ov-meters__row ui-ov-meters__row--inline">
                            <span class="ui-ov-meters__name">{{ ___($label) }}</span>
                            <span class="ui-ov-meter" aria-hidden="true"><span style="inline-size: {{ round(($count / $deviceTotal) * 100) }}%"></span></span>
                            <strong class="ui-ov-meters__value">{{ number_format($count) }}</strong>
                        </div>
                    @endforeach
                </div>
            @else
                <x-ui.empty :title="___('No device data')" :body="___('Desktop, mobile and tablet split shows here.')" icon="phone" />
            @endif
        </x-ui.card>
        <x-ui.card :title="___('Top languages')">
            @if(is_countable($langs) && count($langs) > 0)
                <div class="ui-ov-meters">
                    @foreach($langs as $lang)
                        <div class="ui-ov-meters__row ui-ov-meters__row--inline">
                            <span class="ui-ov-meters__name ui-ltr">{{ strtoupper($lang->lang ?? '—') }}</span>
                            <span class="ui-ov-meter" aria-hidden="true"><span style="inline-size: {{ round((((int) $lang->count) / $langMax) * 100) }}%"></span></span>
                            <strong class="ui-ov-meters__value">{{ number_format((int) $lang->count) }}</strong>
                        </div>
                    @endforeach
                </div>
            @else
                <x-ui.empty :title="___('No language data')" :body="___('The languages diners view your menu in show here.')" icon="chat" />
            @endif
        </x-ui.card>
    </div>
@endif

@unless($isStaffDashboard)
    <div class="ui-ov-pair">
        {{-- Plan usage --}}
        <x-ui.card :title="___('Plan usage') . ' · ' . $planName" class="ui-ov-usage">
            <div class="ui-ov-meters">
                @foreach($usageItems as $item)
                    @php
                        $used = (int) ($item['used'] ?? 0);
                        $limit = $item['limit'];
                        /* F7-1 (feature-platform gap-fix): ONLY the 999 sentinel (or a limit we
                           could not read at all) is unlimited. A limit of 0 — or a legacy -1 row
                           written before plan validation enforced min:0 (F7-2) — is EXHAUSTED:
                           enforcement blocks every create, so the meter must say so instead of
                           cheerfully reading "Unlimited". Sentinel test goes through the one
                           canonical helper, never a raw comparison. */
                        $limitInt = is_numeric($limit) ? (int) $limit : null;
                        $isUnlimited = $limitInt === null || \App\Services\Entitlements::isUnlimitedSentinel($limitInt);
                        $hasLimit = !$isUnlimited;
                        // Legacy -1 is displayed as the 0 it behaves like.
                        $shownLimit = $hasLimit ? max(0, $limitInt) : null;
                        $isExhausted = $hasLimit && ($shownLimit === 0 || $used >= $shownLimit);
                        $percent = $hasLimit ? ($shownLimit > 0 ? min(100, round(($used / $shownLimit) * 100)) : 100) : 0;
                    @endphp
                    <div class="ui-ov-meters__row">
                        <div class="ui-ov-meters__head">
                            <span class="ui-ov-meters__name">{{ $item['label'] }}</span>
                            <strong class="usage-pill ui-ltr {{ $isUnlimited ? 'is-unlimited' : '' }} {{ $isExhausted ? 'is-exhausted' : '' }}">{{ number_format($used) }}@if($hasLimit) / {{ number_format($shownLimit) }}@else <x-ui.tag tone="success">{{ ___('Unlimited') }}</x-ui.tag>@endif</strong>
                        </div>
                        <span class="ui-ov-meter {{ $isUnlimited ? 'is-unlimited' : '' }} {{ $isExhausted ? 'is-exhausted' : '' }}" role="progressbar" aria-label="{{ $item['label'] }}" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"><span style="inline-size: {{ $isUnlimited ? 100 : $percent }}%"></span></span>
                    </div>
                @endforeach
            </div>
            <div class="ui-ov-usage__billing">
                <span class="ui-ov-muted">{{ ___('Plan billing to date') }}</span>
                <strong>{{ platform_price_symbol_format($total_earnings ?? 0) }}</strong>{{-- platform money: never the working store's currency --}}
            </div>
        </x-ui.card>

        {{-- Recent billing --}}
        <x-ui.card :title="___('Recent billing')" class="ui-ov-billing">
            @forelse($transactions->take(4) as $transaction)
                <div class="ui-ov-store">
                    <span class="ui-ov-store__logo" data-tone="accent"><x-ui.icon name="orders" /></span>
                    <span class="ui-ov-store__body">
                        <span class="ui-ov-store__name">{{ $transaction->displayDescription() ?: $transaction->displayName() }}</span>
                        <span class="ui-ov-store__slug">{{ date_formating($transaction->created_at) }}</span>
                    </span>
                    <strong>{{ platform_price_symbol_format($transaction->amount, $transaction->currency_code) }}</strong>
                </div>
            @empty
                <x-ui.empty :title="___('No recent transactions')" :body="___('Billing activity will appear here after your next plan or addon payment.')" icon="orders" />
            @endforelse
        </x-ui.card>
    </div>
@else
    <x-ui.card :title="___('Audience snapshot')">
        <div class="ui-ov-live__grid ui-ov-live__grid--three">
            <div class="ui-stat"><span class="ui-stat__value">{{ number_format($uniqueVisitors ?? 0) }}</span><span class="ui-stat__label">{{ ___('Unique visitors') }}</span></div>
            <div class="ui-stat"><span class="ui-stat__value">{{ number_format($repeatVisitors ?? 0) }}</span><span class="ui-stat__label">{{ ___('Repeat visitors') }}</span></div>
            <div class="ui-stat"><span class="ui-stat__value">{{ number_format($visitorTotal) }}</span><span class="ui-stat__label">{{ ___('Total audience') }}</span></div>
        </div>
    </x-ui.card>
@endunless

@if(!($isStaffDashboard ?? false) && $hasActivity)
    <div class="ui-ov-setup">
        @include('owner.dashboard.partials.onboarding-checklist')
        {{-- WI-F4: what the owner already OWNS — after onboarding, never before it. --}}
        @include('owner.dashboard.partials.spotlight-owned')
    </div>
@endif

{{-- P0-9: resource usage is a hideable card (dismiss persists via localStorage). --}}
@isset($abuseUsage)
    <div x-data="{ show: (localStorage.getItem('dash.card.usage') || localStorage.getItem('lamar.card.usage')) !== 'dismissed' }" x-show="show" x-cloak>
        <x-ui.card :title="___('Account health')">
            <x-slot:actions>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost"
                        @click="localStorage.setItem('dash.card.usage','dismissed'); show=false"
                        aria-label="{{ ___('Dismiss') }}"><x-ui.icon name="x" /></button>
            </x-slot:actions>
            <div class="ui-ov-health">
                @foreach(['requests_public' => ___('Menu requests'), 'orders' => ___('Orders'), 'orders_failed' => ___('Failed checkouts'), 'ai_credits' => ___('AI credits'), 'storage_mb' => ___('Storage (MB)')] as $k => $label)
                    <div class="ui-stat"><span class="ui-stat__value">{{ number_format($abuseUsage[$k] ?? 0) }}</span><span class="ui-stat__label">{{ $label }}</span></div>
                @endforeach
            </div>
        </x-ui.card>
    </div>
@endisset

{{-- Promo (P0-9: dismissible — survives reload via localStorage, like banners) --}}
@unless($isStaffDashboard)
    <div x-data="{ show: (localStorage.getItem('dash.promo.reseller') || localStorage.getItem('lamar.promo.reseller')) !== 'dismissed' }" x-show="show" x-cloak>
        <section class="ui-card ui-card--inset ui-ov-promo">
            <span class="ui-ov-promo__icon"><x-ui.icon name="gift" /></span>
            <div class="ui-ov-promo__text">
                <p class="ui-overline">{{ ___('Reseller packages') }}</p>
                <p class="ui-ov-promo__title">{{ ___('Activate premium accounts with annual coupons.') }}</p>
            </div>
            <div class="ui-ov-promo__actions">
                <x-ui.button :href="url('/pricing')" size="sm" variant="secondary">{{ ___('More info') }}</x-ui.button>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost"
                        @click="localStorage.setItem('dash.promo.reseller','dismissed'); show=false"
                        aria-label="{{ ___('Dismiss') }}"><x-ui.icon name="x" /></button>
            </div>
        </section>
    </div>
@endunless

@unless($isStaffDashboard)
    @if(Route::has('dashboard.layout'))
        <details class="ui-ov-customize">
            <summary><x-ui.icon name="settings" size="16" /> {{ ___('Customize dashboard') }}</summary>
            <form method="POST" action="{{ route('dashboard.layout') }}" class="ui-ov-customize__form">
                @csrf
                @foreach(['livestrip' => ___('Live strip'), 'trend' => ___('Sales trend'), 'topitems' => ___('Top items'), 'breakdowns' => ___('Scan breakdowns')] as $key => $label)
                    <x-ui.check :label="$label" name="cards[]" :value="$key" :checked="!in_array($key, $hiddenCards ?? [])" />
                @endforeach
                <x-ui.button type="submit" size="sm">{{ ___('Save') }}</x-ui.button>
            </form>
        </details>
    @endif
@endunless
@endif {{-- P2-4 fleet vs single-store home --}}

</div>

@if(($aiAssistant ?? false) && Route::has('dashboard.assistant'))
    {{-- P2-6: grounded assistant drawer. Answers cite scoped dashboard numbers. --}}
    <div class="ui-drawer-backdrop" data-ui-drawer-for="ai-assistant-drawer" hidden></div>
    <div class="ui-drawer" tabindex="-1" id="ai-assistant-drawer" aria-labelledby="ai-assistant-title" aria-hidden="true"
         x-data="dashAssistant(@js(route('dashboard.assistant')))">
        <div class="ui-drawer__head ui-rule-bottom">
            <h2 class="ui-insights__title" id="ai-assistant-title"><x-ui.icon name="sparkle" size="18" />{{ ___('Dashboard assistant') }}</h2>
            <button type="button" class="ui-icon-btn" data-ui-do="drawer-close" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" /></button>
        </div>
        <div class="ui-drawer__body ui-scope ui-insights ui-assist">
            <div class="ui-assist__prompts">
                @foreach([___('What sold best this week?'), ___('How are sales today?'), ___('Draft a Tuesday coupon')] as $sp)
                    <button type="button" class="ui-chip" @click="q = @js($sp); ask()">{{ $sp }}</button>
                @endforeach
            </div>
            <div class="ui-insights__thread ui-assist__thread" aria-live="polite">
                <template x-for="(m, i) in messages" :key="i">
                    <div class="ui-insights__msg" :data-role="m.role === 'user' ? 'you' : 'assistant'">
                        <span class="ui-insights__text" x-text="m.text"></span>
                    </div>
                </template>
                <p x-show="busy" class="ui-insights__hint">{{ ___('Thinking…') }}</p>
            </div>
            <form class="ui-insights__ask" @submit.prevent="ask()">
                <input type="text" class="ui-input" x-model="q" placeholder="{{ ___('Ask about your numbers…') }}" aria-label="{{ ___('Ask about your numbers…') }}">
                <button type="submit" class="ui-btn" :disabled="busy">{{ ___('Send') }}</button>
            </form>
        </div>
    </div>
    @push('scripts_at_bottom')
    <script nonce="{{ csp_nonce() }}" data-turbo-eval="false">
        window.dashAssistant = function (url) {
            return {
                url: url, q: '', busy: false, messages: [],
                ask() {
                    var text = (this.q || '').trim();
                    if (!text || this.busy) return;
                    this.messages.push({ role: 'user', text: text });
                    this.q = ''; this.busy = true;
                    var token = document.querySelector('meta[name="csrf-token"]')?.content || '';
                    fetch(this.url, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
                        credentials: 'same-origin', body: JSON.stringify({ message: text }),
                    }).then(function (r) { return r.ok ? r.json() : null; }).then((d) => {
                        this.messages.push({ role: 'assistant', text: d && d.answer ? d.answer : '—' });
                    }).catch(() => { this.messages.push({ role: 'assistant', text: '—' }); })
                      .finally(() => { this.busy = false; });
                },
            };
        };
    </script>
    @endpush
@endif
@endsection

@push('scripts_at_bottom')
    <script nonce="{{ csp_nonce() }}">
        (function () {
            var el = document.getElementById('dash-scans');
            if (!el || typeof ApexCharts === 'undefined') {
                // No chart is coming — don't leave the skeleton shimmering forever.
                var deadSkel = document.getElementById('dash-scans-skel');
                if (deadSkel) { deadSkel.remove(); }
                return;
            }
            var days = JSON.parse(el.dataset.days || '[]');
            var scans = JSON.parse(el.dataset.scans || '[]');
            var chart;
            function render() {
                // Owner UI tokens (assets/owner-ui/tokens.css), read at render time so
                // a theme flip re-inks the chart.
                var css = getComputedStyle(document.documentElement);
                var accent = (css.getPropertyValue('--ui-accent') || css.getPropertyValue('--bs-primary') || '#4F46E5').trim();
                var muted = (css.getPropertyValue('--ui-ink-3') || '#6b7280').trim();
                var line = (css.getPropertyValue('--ui-hair') || '#e6e8ee').trim();
                var dark = document.documentElement.getAttribute('data-theme') === 'dark';
                var opts = {
                    chart: { nonce: '{{ csp_nonce() }}', type: 'area', height: 220, toolbar: { show: false }, fontFamily: 'inherit', foreColor: muted, background: 'transparent' },
                    series: [{ name: el.dataset.label || 'Scans', data: scans }],
                    xaxis: { categories: days, labels: { rotate: 0, hideOverlappingLabels: true }, axisBorder: { show: false }, axisTicks: { show: false } },
                    yaxis: { labels: { formatter: function (v) { return Math.round(v); } } },
                    colors: [accent],
                    stroke: { curve: 'smooth', width: 2 },
                    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.02 } },
                    dataLabels: { enabled: false },
                    grid: { borderColor: line, strokeDashArray: 4 },
                    tooltip: { theme: dark ? 'dark' : 'light' },
                };
                if (chart) { chart.destroy(); }
                chart = new ApexCharts(el, opts);
                chart.render();
                // P1-7: the shimmer skeleton has done its job once the chart mounts.
                var skel = document.getElementById('dash-scans-skel');
                if (skel) { skel.remove(); }
            }
            render();
            window.addEventListener('ui:theme', render);
        })();
    </script>
@endpush
