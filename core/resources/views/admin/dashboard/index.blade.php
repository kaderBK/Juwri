{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Dashboard'))

@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="users" :href="route('admin.users.index')">{{ ___('Manage users') }}</x-ui.button>
    @if(Route::has('admin.announcements.index')) {{-- P1-13 13e: platform announcements are the ee Platform module's --}}
        <x-ui.button variant="secondary" size="sm" icon="share" :href="route('admin.announcements.index')">{{ ___('New announcement') }}</x-ui.button>
    @endif
    <x-ui.button variant="secondary" size="sm" icon="gift" :href="route('admin.referrals.index')">
        {{ ___('Referrals') }}<x-ui.count :value="$pendingReferrals" tone="accent" />
    </x-ui.button>
    @if (Route::has('admin.feature-matrix.index'))
        <x-ui.button size="sm" icon="more" :href="route('admin.feature-matrix.index')">{{ ___('Feature matrix') }}</x-ui.button>
    @endif
@endsection

@section('content')
@php
    $pulseIcons = ['revenue' => 'wallet', 'subs' => 'orders', 'stores' => 'store', 'orders' => 'pickup', 'scans' => 'qr', 'signups' => 'users'];
@endphp
<div class="ui-stack">

    {{-- Pulse: the six numbers that matter --}}
    <section class="ui-kpis" aria-label="{{ ___('Overview') }}">
        @foreach ([
            'revenue' => ___('Revenue · 30 days'),
            'subs' => ___('Paid subscriptions'),
            'stores' => ___('Stores'),
            'orders' => ___('Orders · 30 days'),
            'scans' => ___('Menu scans · 30 days'),
            'signups' => ___('New users · 30 days'),
        ] as $key => $label)
            @php $cell = $pulse[$key]; $dir = $cell['delta']['dir']; @endphp
            <div class="ui-kpi">
                <span class="ui-kpi__label">{{ $label }}<span class="ui-kpi__icon"><x-ui.icon :name="$pulseIcons[$key]" size="18" /></span></span>
                <span class="ui-kpi__row">
                    <span class="ui-kpi__value">{{ $cell['value'] }}</span>
                    <x-ui.tag :tone="$dir === 'up' ? 'success' : ($dir === 'down' ? 'danger' : 'neutral')">
                        @if ($dir === 'up')▲@elseif ($dir === 'down')▼@else—@endif
                        @if ($cell['delta']['pct'] === null){{ ___('new') }}@else{{ $cell['delta']['pct'] }}%@endif
                    </x-ui.tag>
                </span>
                @if (! empty($cell['spark']))
                    @php [$cx, $cy] = explode(',', $cell['spark']['last']); @endphp
                    <svg class="ui-kpi__spark" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true">
                        <polyline points="{{ $cell['spark']['points'] }}" vector-effect="non-scaling-stroke"/>
                        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="2.5"/>
                    </svg>
                @endif
            </div>
        @endforeach
    </section>

    {{-- M9: merchant recurring-revenue metrics --}}
    @if (! empty($merchantRevenue))
        <section class="ui-kpis" aria-label="{{ ___('MRR') }}">
            @foreach ([
                ['label' => ___('MRR'), 'value' => price_symbol_format($merchantRevenue['mrr']), 'sub' => ___('incl. :a add-ons', ['a' => price_symbol_format($merchantRevenue['addon_mrr'])]).(! empty($merchantRevenue['mrr_other']) ? ' · '.\Juwri\Core\Money\CurrencyTotals::label($merchantRevenue['mrr_other']) : '')],
                ['label' => ___('ARPU'), 'value' => price_symbol_format($merchantRevenue['arpu']), 'sub' => ___n('paying merchant', (int) $merchantRevenue['paying_merchants'])],
                ['label' => ___('Churn · 30 days'), 'value' => $merchantRevenue['churn_pct'].'%', 'sub' => ___n('cancel/expire', (int) $merchantRevenue['churned_30d'])],
                ['label' => ___('Trial → paid'), 'value' => $merchantRevenue['trial_conversion'].'%', 'sub' => ___n('trial started', (int) $merchantRevenue['trials_started_30d'])],
            ] as $tile)
                <div class="ui-kpi">
                    <span class="ui-kpi__label">{{ $tile['label'] }}</span>
                    <span class="ui-kpi__value">{{ $tile['value'] }}</span>
                    <span class="ui-kpi__sub">{{ $tile['sub'] }}</span>
                </div>
            @endforeach
        </section>
    @endif

    {{-- Analytics teaser (links to the full analytics page) --}}
    @isset($analyticsTeaser)
        <x-ui.card>
            <div class="ui-cluster ui-cluster--between">
                <dl class="ui-facts">
                    <div><dt>{{ ___('NRR · 30 days') }}</dt><dd>{{ $analyticsTeaser['nrr_pct'] === null ? '—' : $analyticsTeaser['nrr_pct'].'%' }}</dd></div>
                    <div><dt>{{ ___('Net MRR movement · 30 days') }}</dt><dd>{{ $analyticsTeaser['net_mrr'] }}</dd></div>
                    <div><dt>{{ ___('Tenants at risk') }}</dt><dd>{{ format_number_count($analyticsTeaser['at_risk']) }}</dd></div>
                </dl>
                <x-ui.button size="sm" icon="chart" :href="route('admin.analytics')">{{ ___('Open analytics') }}</x-ui.button>
            </div>
        </x-ui.card>
    @endisset

    @isset($hostingHealth)
        @include('admin.dashboard._hosting-health', ['health' => $hostingHealth])
    @endisset

    {{-- Needs attention --}}
    @if (count($alerts))
        <x-ui.card>
            <ul class="ui-list-box">
                @foreach ($alerts as $alert)
                    @php $lvl = $alert['level'] === 'danger' ? 'danger' : 'warning'; @endphp
                    <li>
                        @if ($alert['route'])<a class="ui-list-box__item ui-cluster" href="{{ $alert['route'] }}">@else<div class="ui-list-box__item ui-cluster">@endif
                            <span class="ui-dot" data-tone="{{ $lvl }}"></span>
                            <span class="ui-grow">{{ $alert['label'] }}</span>
                            <x-ui.tag :tone="$lvl">{{ $alert['count'] }}</x-ui.tag>
                            @if ($alert['route'])<x-ui.icon name="chevron" size="16" flip />@endif
                        @if ($alert['route'])</a>@else</div>@endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    {{-- Revenue + side --}}
    <div class="ui-columns ui-columns--wide-first">
        <x-ui.card :title="___('Revenue')">
            <x-slot:actions>
                <div class="ui-chips" role="group" aria-label="{{ ___('Date range') }}" data-dash-range>
                    @foreach ([7, 30, 90] as $days)
                        <button type="button" class="ui-chip" data-days="{{ $days }}" aria-pressed="{{ $days === 30 ? 'true' : 'false' }}">{{ $days }}{{ ___('d') }}</button>
                    @endforeach
                </div>
            </x-slot:actions>
            <p class="ui-muted">{{ ___('Successful payments per day') }}</p>
            <div class="ui-bars" id="dash-revenue" aria-label="{{ ___('Daily revenue chart') }}">
                <div class="ui-bars__empty" id="dash-revenue-empty" hidden>{{ ___('No revenue in this period yet') }}</div>
            </div>
            <dl class="ui-facts">
                <div><dt><span class="ui-swatch" style="--_c: var(--ui-series-1)"></span> {{ ___('Subscriptions & plans') }}</dt><dd id="dash-total-subs"></dd></div>
                <div><dt><span class="ui-swatch" style="--_c: var(--ui-series-2)"></span> {{ ___('Add-ons & bundles') }}</dt><dd id="dash-total-addon"></dd></div>
                <div><dt><span class="ui-swatch" style="--_c: var(--ui-series-3)"></span> {{ ___('Wallet top-ups') }}</dt><dd id="dash-total-wallet"></dd></div>
            </dl>
            {{-- MC-42: billing revenue booked in a non-base currency. The chart is base-currency
                 only; these chips carry the foreign figures without a converted total (v1).
                 Hidden on single-currency installs. --}}
            <div class="ui-cluster" id="dash-foreign-cur" hidden>
                <span class="ui-small ui-text-2">{{ ___('Other currencies') }}</span>
            </div>
        </x-ui.card>

        <div class="ui-stack">
            <x-ui.card :title="___('Subscribers by plan')">
                @if (Route::has('admin.plans.index'))
                    <x-slot:actions><x-ui.button variant="link" size="sm" :href="route('admin.plans.index')">{{ ___('Plans') }}</x-ui.button></x-slot:actions>
                @endif
                <div class="ui-stack">
                    @forelse ($byPlan as $plan)
                        <div class="ui-bar-row">
                            <span class="ui-bar-row__head"><span>{{ $plan['name'] }}</span><strong>{{ $plan['count'] }}</strong></span>
                            <div class="ui-meter"><div class="ui-meter__bar" style="width: {{ $plan['pct'] }}%"></div></div>
                        </div>
                    @empty
                        <p class="ui-muted">{{ ___('No paid subscribers yet') }}<br>{{ ___('Subscribers appear here as soon as a plan is purchased') }}</p>
                    @endforelse
                </div>
            </x-ui.card>

            <x-ui.card :title="___('Top customers · 30 days')">
                <x-slot:actions><x-ui.button variant="link" size="sm" :href="route('admin.transactions.index')">{{ ___('View all') }}</x-ui.button></x-slot:actions>
                <ul class="ui-list-box">
                    @forelse ($topCustomers as $customer)
                        <li>
                            <a class="ui-list-box__item ui-cluster ui-cluster--between" href="{{ route('admin.users.edit', $customer['id']) }}">
                                <span><strong>{{ $customer['name'] }}</strong><small class="ui-cell-sub">{{ $customer['email'] }}</small></span>
                                <span class="ui-end"><strong>{{ $customer['amount'] }}</strong><small class="ui-cell-sub">{{ $customer['n'] }} {{ ___('payments') }}</small></span>
                            </a>
                        </li>
                    @empty
                        <li class="ui-list-box__item ui-muted">{{ ___('No payments in the last 30 days') }}</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>
    </div>

    {{-- Growth --}}
    <div class="ui-columns">
        <x-ui.card :title="___('Restaurant activation')">
            <x-slot:actions><x-ui.button variant="link" size="sm" :href="route('admin.stores.index')">{{ ___('Stores') }}</x-ui.button></x-slot:actions>
            <p class="ui-muted">{{ ___('How far restaurants get after being created') }}</p>
            <div class="ui-stack">
                @foreach ($funnel as $stage)
                    <div class="ui-bar-row">
                        <span class="ui-bar-row__head"><span>{{ $stage['label'] }}</span><span><strong>{{ format_number_count($stage['count']) }}</strong> · {{ $stage['pct'] }}%</span></span>
                        <div class="ui-meter"><div class="ui-meter__bar" style="width: {{ $stage['pct'] }}%"></div></div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card :title="___('Plan mix')">
            <x-slot:actions><x-ui.button variant="link" size="sm" :href="route('admin.users.index')">{{ ___('Users') }}</x-ui.button></x-slot:actions>
            <p class="ui-muted">{{ ___('Every registered user by current plan') }}</p>
            @if (count($planMix))
                <div class="ui-cluster">
                    <div class="ui-donut" id="dash-plan-donut" aria-label="{{ ___('Users per plan') }}"></div>
                    <ul class="ui-legend">
                        @foreach ($planMix as $i => $slice)
                            <li><span class="ui-swatch" style="--_c: var(--ui-series-{{ $i % 6 + 1 }})"></span>{{ $slice['label'] }}<strong>{{ format_number_count($slice['count']) }}</strong></li>
                        @endforeach
                    </ul>
                </div>
            @else
                <p class="ui-muted">{{ ___('No users yet') }}</p>
            @endif
        </x-ui.card>
    </div>

    {{-- Feature adoption --}}
    <x-ui.card :title="___('Feature adoption')">
        <p class="ui-muted">{{ ___('Restaurants actively using each part of the platform') }}</p>
        <div class="ui-columns" style="--ui-col-min: 280px">
            @foreach ($adoption as $feature)
                <div class="ui-bar-row">
                    <span class="ui-bar-row__head"><span>{{ $feature['label'] }}</span><span><strong>{{ format_number_count($feature['count']) }}</strong> · {{ $feature['pct'] }}%</span></span>
                    <div class="ui-meter"><div class="ui-meter__bar" style="width: {{ $feature['pct'] }}%"></div></div>
                </div>
            @endforeach
        </div>
    </x-ui.card>

    {{-- Platform operations --}}
    <section class="ui-kpis" aria-label="{{ ___('Operations') }}">
        @foreach ($ops as $op)
            @php $tag = $op['route'] ? 'a' : 'div'; @endphp
            <{{ $tag }} class="ui-kpi" @if ($op['route']) href="{{ $op['route'] }}" @endif>
                <span class="ui-kpi__label"><span class="ui-cluster" style="--ui-gap: 8px">@if ($op['state'])<span class="ui-dot" data-tone="{{ \Juwri\Core\Support\ViewHelpers::toneOf($op['state']) }}"></span>@endif{{ $op['label'] }}</span></span>
                <span class="ui-kpi__value">{{ $op['value'] }}</span>
                <span class="ui-kpi__sub">{{ $op['sub'] }}</span>
            </{{ $tag }}>
        @endforeach
    </section>

    {{-- Recent activity --}}
    <div class="ui-columns">
        <x-ui.card :title="___('Latest transactions')">
            <x-slot:actions><x-ui.button variant="link" size="sm" :href="route('admin.transactions.index')">{{ ___('View all') }}</x-ui.button></x-slot:actions>
            <ul class="ui-list-box">
                @forelse ($recentTransactions as $transaction)
                    @php $tone = $transaction->status === 'success' ? 'success' : ($transaction->status === 'pending' ? 'warning' : 'danger'); @endphp
                    <li class="ui-list-box__item ui-cluster ui-cluster--between">
                        <span><strong>{{ $transaction->user->name }}</strong><small class="ui-cell-sub">{{ $transaction->displayName() }}</small></span>
                        <span class="ui-end">
                            <strong>{{ price_symbol_format($transaction->amount) }}</strong>
                            <small class="ui-cell-sub"><x-ui.tag :tone="$tone">{{ ___($transaction->status) }}</x-ui.tag> {{ $transaction->created_at->diffForHumans() }}</small>
                        </span>
                    </li>
                @empty
                    <li class="ui-list-box__item ui-muted">{{ ___('No transactions yet') }}</li>
                @endforelse
            </ul>
        </x-ui.card>

        <x-ui.card :title="___('Newest restaurants')">
            <x-slot:actions><x-ui.button variant="link" size="sm" :href="route('admin.stores.index')">{{ ___('View all') }}</x-ui.button></x-slot:actions>
            <ul class="ui-list-box">
                @forelse ($recentRestaurants as $store)
                    <li class="ui-list-box__item ui-cluster ui-cluster--between">
                        <span><strong>{{ $store->title }}</strong><small class="ui-cell-sub">{{ $store->user->name ?? '—' }}</small></span>
                        <small class="ui-text-2">{{ $store->created_at?->diffForHumans() }}</small>
                    </li>
                @empty
                    <li class="ui-list-box__item ui-muted">{{ ___('No restaurants yet') }}</li>
                @endforelse
            </ul>
        </x-ui.card>
    </div>

    @if (! empty($topUsageTenants) && count($topUsageTenants))
        <x-ui.card :title="___('Top tenants by requests today')">
            <x-slot:actions><x-ui.button variant="link" size="sm" :href="route('admin.abuse-guard.index', ['tab' => 'resource-usage'])">{{ ___('Resource Usage') }} →</x-ui.button></x-slot:actions>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <tbody>
                        @foreach ($topUsageTenants as $t)
                            <tr>
                                <td>{{ ___('User') }} #{{ $t->user_id }}</td>
                                <td class="ui-table__num"><strong>{{ number_format($t->total) }}</strong> {{ ___('requests') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
@endsection

@push('scripts')
<script nonce="{{ csp_nonce() }}">
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var CURRENCY = @json($settings->currency_sign);
    var CURRENCY_POS = @json($settings->currency_pos);
    var CHART_URL = @json(route('admin.dashboard.chart-data'));
    var INITIAL = @json($charts);
    var PLAN_MIX = @json($planMix);
    var LABELS = @json([___('Subscriptions & plans'), ___('Add-ons & bundles'), ___('Wallet top-ups')]);

    function money(v) {
        var n = Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 });
        return CURRENCY_POS == 1 ? CURRENCY + n : n + CURRENCY;
    }

    var chart = document.getElementById('dash-revenue');
    var empty = document.getElementById('dash-revenue-empty');
    var fx = document.getElementById('dash-foreign-cur');

    function apply(p) {
        window.adminCharts.bars(chart, {
            labels: p.labels,
            series: [
                { label: LABELS[0], values: p.subscriptions },
                { label: LABELS[1], values: p.addon },
                { label: LABELS[2], values: p.wallet },
            ],
        }, { format: money });
        document.getElementById('dash-total-subs').textContent = money(p.totals.subscriptions);
        document.getElementById('dash-total-addon').textContent = money(p.totals.addon);
        document.getElementById('dash-total-wallet').textContent = money(p.totals.wallet);
        var none = p.totals.subscriptions === 0 && p.totals.addon === 0 && p.totals.wallet === 0;
        empty.hidden = !none;
        chart.classList.toggle('is-empty', none);

        // MC-42: non-base-currency billing totals as chips (no converted total).
        var byCur = p.totals_by_currency || {};
        var codes = Object.keys(byCur);
        fx.querySelectorAll('.ui-tag').forEach(function (el) { el.remove(); });
        codes.forEach(function (code) {
            var chip = document.createElement('span');
            chip.className = 'ui-tag';
            chip.setAttribute('data-tone', 'neutral');
            chip.textContent = code + ' ' + Number(byCur[code]).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 3 });
            fx.appendChild(chip);
        });
        fx.hidden = codes.length === 0;
    }

    apply(INITIAL);

    var range = document.querySelector('[data-dash-range]');
    range.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-days]');
        if (!btn || btn.getAttribute('aria-pressed') === 'true') { return; }
        var buttons = range.querySelectorAll('button');
        buttons.forEach(function (b) { b.disabled = true; });
        chart.classList.add('is-loading');
        fetch(CHART_URL + '?days=' + btn.getAttribute('data-days'), { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (res) { if (!res.ok) { throw new Error('HTTP ' + res.status); } return res.json(); })
            .then(function (p) {
                apply(p);
                buttons.forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
            })
            .catch(function () { window.adminUi && window.adminUi.toast(@json(___('Something went wrong. Please try again.')), 'danger'); })
            .then(function () { buttons.forEach(function (b) { b.disabled = false; }); chart.classList.remove('is-loading'); });
    });

    var donut = document.getElementById('dash-plan-donut');
    if (donut && PLAN_MIX.length) { window.adminCharts.donut(donut, PLAN_MIX); }
});
</script>
@endpush
