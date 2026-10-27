{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Analytics'))
@section('page_title', ___('Scan & sales analytics'))
@section('page_sub', ($scopeAll ?? false)
    ? ___('Traffic, behavior, sales, and goal progress across all your stores.')
    : ___('Traffic, behavior, sales, and goal progress for the selected store.'))

@section('page_actions')
    {{-- P1-4: period lives in the shared period bar (below) — export follows it. --}}
    <x-ui.button :href="route('analytics.export', array_filter(['range' => $range, 'scope' => ($scopeAll ?? false) ? 'all' : null]))" variant="secondary" icon="download" data-busy-download data-busy-label="{{ ___('Preparing…') }}">
        {{ ___('Export CSV') }}
    </x-ui.button>
    {{-- K9 (dark): AI Insights entry point, only when the copilot is enabled. --}}
    @if(config('analytics.copilot_enabled') && Route::has('analytics.insights'))
        <x-ui.button :href="route('analytics.insights')" variant="secondary" icon="sparkle">
            {{ ___('Insights') }}
        </x-ui.button>
    @endif
    {{-- HPMS E14(f): dir-aware print view (window.print, nonce'd). --}}
    <x-ui.button variant="ghost" icon="print" data-print-report>{{ ___('Print') }}</x-ui.button>
@endsection

@section('content')

{{-- P1-4: shared period bar (same model as the dashboard) — scope chip included. --}}
@include('owner.partials.periodbar', ['showScope' => true])

{{-- H13: the orders/scan figures on this page are bounded by a MANDATORY window.
     Print the window that was actually applied so an operator can tell at a glance
     which slice they are reading — and so a bad ?range= can never quietly produce
     an all-time figure again. --}}
<p class="ui-scope ui-analytics__window" data-analytics-window>
    {{ ___('Showing data from') }}
    <strong>{{ $analyticsWindowFrom }}</strong>
    {{ ___('to') }}
    <strong>{{ $analyticsWindowTo }}</strong>
</p>

@php
    /* Money follows the store's currency (3 for OMR/KWD/JOD/BHD/LYD/TND). The CSV
       export already did; this screen did not, so the same feature printed
       KD 1,234.56 here and KD 1,234.567 in the export of the same period.
       Defaulted here because the no-stores render path passes no currency at all —
       before the closure, since use() on an unset variable throws (the in-closure
       ?? fallback would never run). */
    $currencySymbol = $currencySymbol ?? '$';
    $currencyDecimals = $currencyDecimals ?? 2;
    $money = function ($v) use ($currencySymbol, $currencyDecimals) {
        $sym = cur_isolate($currencySymbol);
        $n = number_format((float) $v, $currencyDecimals);

        return money_ltr(currency_pos_effective() === 1 ? $sym.$n : $n.$sym);
    };

    $goalDisplay = match($goalType ?? null) {
        'revenue'         => $money($goalValue ?? 0),
        'orders', 'scans' => number_format((int)($goalValue ?? 0)),
        default           => null,
    };
    $fmtDelta = function ($delta) {
        // Controller's trendDelta() returns ['pct' => abs %, 'dir' => up|down|flat]; null pct = no prior data.
        if (is_array($delta)) {
            if (($delta['pct'] ?? null) === null) return null;
            return ['value' => (float) $delta['pct'], 'sign' => $delta['dir'] ?? 'flat'];
        }
        if ($delta === null || $delta === '' || !is_numeric($delta)) return null;
        $val = (float) $delta;
        return ['value' => abs($val), 'sign' => $val > 0 ? 'up' : ($val < 0 ? 'down' : 'flat')];
    };

    $kpis = [
        [
            'label' => ___('Revenue'),
            'value' => $money($totalRevenue ?? 0),
            'delta' => $fmtDelta($revenueDelta ?? null),
            'icon'  => 'wallet',
            'goal'  => ($goalType ?? null) === 'revenue' ? $goalProgressPct : null,
        ],
        [
            'label' => ___('Orders'),
            'value' => number_format((int)($ordersCount ?? 0)),
            'delta' => $fmtDelta($ordersDelta ?? null),
            'icon'  => 'orders',
            'goal'  => ($goalType ?? null) === 'orders' ? $goalProgressPct : null,
        ],
        [
            'label' => ___('AOV'),
            'value' => $money($aov ?? 0),
            'delta' => $fmtDelta($aovDelta ?? null),
            'icon'  => 'chart',
            'goal'  => null,
        ],
        [
            'label' => ___('Conversion'),
            'value' => ($conversionRate ?? 0).'%',
            'delta' => $fmtDelta($convDelta ?? null),
            'icon'  => 'trend-up',
            'goal'  => null,
        ],
        [
            'label' => ___('Scans'),
            'value' => number_format((int)($totalScans ?? 0)),
            'delta' => $fmtDelta($scansDelta ?? null),
            'icon'  => 'qr',
            'goal'  => ($goalType ?? null) === 'scans' ? $goalProgressPct : null,
        ],
        [
            'label' => ___('Unique visitors'),
            'value' => number_format((int)($uniqueVisitors ?? 0)),
            'delta' => $fmtDelta($uniqueVisitorsDelta ?? null),
            'icon'  => 'users',
            'goal'  => null,
        ],
        [
            'label' => ___('Repeat scanners'),
            'value' => number_format((int)($repeatScanners ?? 0)),
            'delta' => $fmtDelta($repeatScannersDelta ?? null),
            'icon'  => 'refresh',
            'goal'  => null,
        ],
        [
            'label' => ___('Repeat rate'),
            'value' => ($repeatRate ?? 0).'%',
            'delta' => $fmtDelta($repeatRateDelta ?? null),
            'icon'  => 'chart',
            'goal'  => null,
        ],
    ];

    $funnel = [
        ['label' => ___('Browse'),   'value' => (int)($browseCount ?? 0)],
        ['label' => ___('Add to cart'), 'value' => (int)($cartCount ?? 0)],
        ['label' => ___('Order placed'), 'value' => (int)($placedCount ?? 0)],
        ['label' => ___('Order paid'),   'value' => (int)($paidCount ?? 0)],
    ];
    $funnelMax = max(array_column($funnel, 'value')) ?: 1;

    $topItems = collect($itemPerformance ?? [])->take(10);
    $heatmap = collect($categoryHeatmap ?? [])->take(12);
@endphp

<div class="ui-scope ui-analytics">
    @if (($totalScans ?? 0) === 0 && ($ordersCount ?? 0) === 0)
        <div class="ui-card">
            <x-ui.empty icon="chart"
                :title="___('No analytics yet')"
                :body="___('Once diners scan your QR or place orders, traffic, behavior, and revenue trends appear here.')"
            />
        </div>
    @else
        {{-- KPI grid --}}
        <section class="ui-analytics__kpis" aria-label="{{ ___('Analytics') }}">
            @foreach ($kpis as $kpi)
                @php $sign = $kpi['delta']['sign'] ?? 'flat'; $chip = ['up'=>'success','down'=>'danger','flat'=>'neutral'][$sign] ?? 'neutral'; @endphp
                <div class="ui-stat ui-analytics__kpi">
                    {{-- Icon + change on top, value, then the full label (it used to
                         truncate to one letter beside the change badge on phones). --}}
                    <div class="ui-analytics__kpi-top">
                        <span class="ui-stat__icon"><x-ui.icon :name="$kpi['icon']" /></span>
                        @if ($kpi['delta'])
                            <x-ui.tag :tone="$chip" class="ui-ltr">{{ $sign === 'up' ? '+' : ($sign === 'down' ? '-' : '') }}{{ number_format($kpi['delta']['value'], 1) }}%</x-ui.tag>
                        @else
                            <x-ui.tag>{{ ___('No prior data') }}</x-ui.tag>
                        @endif
                    </div>
                    <span class="ui-stat__value">{{ $kpi['value'] }}</span>
                    <span class="ui-stat__label">{{ $kpi['label'] }}</span>
                    @if ($kpi['goal'] !== null)
                        <span class="ui-ov-meter" role="progressbar" aria-valuenow="{{ $kpi['goal'] }}" aria-valuemin="0" aria-valuemax="100"><span style="inline-size: {{ min(100, max(0, $kpi['goal'])) }}%"></span></span>
                        <small class="ui-analytics__muted">{{ $kpi['goal'] }}% {{ ___('of') }} {{ $goalDisplay }} {{ ___('goal') }}</small>
                    @endif
                </div>
            @endforeach
        </section>

        {{-- MC-41: per-currency revenue split — shown only when this owner booked
             revenue in more than one currency, so the single-currency page is
             byte-identical. Mirrors the owner-dashboard fleet chips. --}}
        @if (count($revenueByCurrency ?? []) > 1)
            <div class="ui-card ui-analytics__currencies">
                <span class="ui-overline">{{ ___('Revenue by currency') }}</span>
                @foreach ($revenueByCurrency as $cur)
                    <x-ui.tag tone="accent">
                        <span class="ui-ltr">{{ $cur['code'] }} {{ $cur['symbol'] }}{{ number_format($cur['revenue'], $cur['decimals']) }}</span>
                        <span>· {{ number_format((int) $cur['orders']) }} {{ ___('orders') }}</span>
                    </x-ui.tag>
                @endforeach
            </div>
        @endif

        {{-- Charts grid --}}
        <div class="ui-analytics__charts">
            <x-ui.card :title="___('Scans over time')" class="ui-analytics__wide">
                <p class="ui-analytics__muted">{{ ___('Daily QR scans across your stores in the selected range.') }}</p>
                <div id="analytics-daily" class="ui-analytics__chart"></div>
            </x-ui.card>
            <x-ui.card :title="___('Hourly scans')">
                <p class="ui-analytics__muted">{{ ___('When diners scan, by hour.') }}</p>
                <div id="analytics-hourly" class="ui-analytics__chart"></div>
            </x-ui.card>
            <x-ui.card :title="___('Hourly orders')">
                <p class="ui-analytics__muted">{{ ___('When orders are placed, by hour.') }}</p>
                <div id="analytics-hourly-orders" class="ui-analytics__chart"></div>
            </x-ui.card>
            <x-ui.card :title="___('Devices')">
                <p class="ui-analytics__muted">{{ ___('Visitor device share.') }}</p>
                <div id="analytics-devices" class="ui-analytics__chart"></div>
            </x-ui.card>
            <x-ui.card :title="___('Languages')">
                <p class="ui-analytics__muted">{{ ___('Visitor language preference.') }}</p>
                <div id="analytics-langs" class="ui-analytics__chart"></div>
            </x-ui.card>
        </div>

        {{-- Conversion funnel --}}
        <x-ui.card :title="___('Conversion funnel')">
            <p class="ui-analytics__muted">{{ ___('From menu view to paid order.') }}</p>
            <div class="ui-analytics__funnel">
                @foreach ($funnel as $i => $step)
                    @php
                        $pct = $funnelMax > 0 ? round(($step['value'] / $funnelMax) * 100) : 0;
                        $prevValue = $i > 0 ? $funnel[$i - 1]['value'] : null;
                        $dropPct = ($prevValue && $prevValue > 0)
                            ? round(100 - (($step['value'] / $prevValue) * 100), 1)
                            : null;
                    @endphp
                    <div class="ui-analytics__step">
                        <div class="ui-analytics__step-head">
                            <strong>{{ $step['label'] }}</strong>
                            <span class="ui-ltr">{{ number_format($step['value']) }}</span>
                        </div>
                        <span class="ui-ov-meter" aria-hidden="true"><span style="inline-size: {{ $pct }}%"></span></span>
                        @if ($dropPct !== null && $dropPct > 0)
                            <small class="ui-analytics__drop"><span class="ui-ltr">{{ $dropPct }}%</span> {{ ___('drop-off') }}</small>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        {{-- Sources --}}
        @if (!empty($sourceData))
            <x-ui.card :title="___('Traffic sources')">
                <p class="ui-analytics__muted">{{ ___('Top scan sources for the period.') }}</p>
                <ul class="ui-analytics__pairs">
                    @foreach (array_slice($sourceData, 0, 8, true) as $src => $cnt)
                        <li><span>{{ $src }}</span><strong class="ui-ltr">{{ number_format($cnt) }}</strong></li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        {{-- D10: menu-behavior signals (dwell time, exit sections, language + source split) --}}
        @php
            $behavior = $behavior ?? [];
            $behaviorSources = $behaviorSources ?? [];
            $__avgDwell = $behavior['avg_time_on_menu_sec'] ?? null;
            $__exits = $behavior['exit_sections'] ?? collect();
            $__langs = $behavior['language_split'] ?? collect();
            $__hasBehavior = $__avgDwell !== null || (is_countable($__exits) ? count($__exits) : 0) || (is_countable($__langs) ? count($__langs) : 0) || count($behaviorSources);
        @endphp
        <x-ui.card :title="___('Menu behavior')">
            @if ($__hasBehavior)
                <dl class="ui-analytics__facts">
                    <div>
                        <dt class="ui-overline">{{ ___('Avg. time on menu') }}</dt>
                        <dd class="ui-analytics__big ui-ltr">{{ $__avgDwell !== null ? $__avgDwell.'s' : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="ui-overline">{{ ___('Top exit section') }}</dt>
                        <dd>{{ (is_countable($__exits) && count($__exits)) ? array_key_first($__exits instanceof \Illuminate\Support\Collection ? $__exits->toArray() : $__exits) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="ui-overline">{{ ___('Language split') }}</dt>
                        <dd class="ui-analytics__tags">
                            @forelse (($__langs instanceof \Illuminate\Support\Collection ? $__langs->toArray() : $__langs) as $lang => $cnt)
                                <x-ui.tag class="ui-ltr">{{ strtoupper($lang) }} · {{ number_format($cnt) }}</x-ui.tag>
                            @empty — @endforelse
                        </dd>
                    </div>
                    <div>
                        <dt class="ui-overline">{{ ___('Traffic sources') }}</dt>
                        <dd class="ui-analytics__tags">
                            @forelse (array_slice($behaviorSources, 0, 4, true) as $src => $cnt)
                                <x-ui.tag>{{ $src }} · <span class="ui-ltr">{{ number_format($cnt) }}</span></x-ui.tag>
                            @empty — @endforelse
                        </dd>
                    </div>
                </dl>
            @else
                <p class="ui-analytics__muted">{{ ___('No menu-behavior data yet.') }}</p>
            @endif
        </x-ui.card>

        {{-- Item performance --}}
        <section class="ui-card ui-card--flush">
            <div class="ui-card__head"><h2 class="ui-card__title">{{ ___('Top items') }}</h2></div>
            <p class="ui-analytics__muted ui-analytics__pad">{{ ___('Ranked by orders, then views. Browse → cart → orders shows behavior.') }}</p>
            @if ($topItems->isEmpty())
                <x-ui.empty icon="products" :title="___('No item activity in this range yet.')" />
            @else
                <table class="ui-table ui-analytics__compact">
                    <thead>
                        <tr>
                            <th>{{ ___('Item') }}</th>
                            <th class="ui-table__num">{{ ___('Price') }}</th>
                            <th class="ui-table__num">{{ ___('Views') }}</th>
                            <th class="ui-table__num">{{ ___('Carts') }}</th>
                            <th class="ui-table__num">{{ ___('Orders') }}</th>
                            <th class="ui-table__num">{{ ___('Cart conv') }}</th>
                            <th class="ui-table__num">{{ ___('Order conv') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($topItems as $item)
                            <tr>
                                <td data-primary>{{ $item['name'] }}</td>
                                <td class="ui-table__num" data-label="{{ ___('Price') }}">{{ $money($item['price']) }}</td>
                                <td class="ui-table__num" data-label="{{ ___('Views') }}"><span class="ui-ltr">{{ number_format($item['views']) }}</span></td>
                                <td class="ui-table__num" data-label="{{ ___('Carts') }}"><span class="ui-ltr">{{ number_format($item['addToCarts']) }}</span></td>
                                <td class="ui-table__num" data-label="{{ ___('Orders') }}"><span class="ui-ltr">{{ number_format($item['orders']) }}</span></td>
                                <td class="ui-table__num" data-label="{{ ___('Cart conv') }}"><span class="ui-ltr">{{ $item['cartConv'] }}%</span></td>
                                <td class="ui-table__num" data-label="{{ ___('Order conv') }}"><span class="ui-ltr">{{ $item['orderConv'] }}%</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        {{-- Category heatmap --}}
        @if ($heatmap->isNotEmpty())
            <x-ui.card :title="___('Category heatmap')">
                <p class="ui-analytics__muted">{{ ___('Relative views and cart intensity per category.') }}</p>
                <div class="ui-analytics__heat">
                    @foreach ($heatmap as $cat)
                        <div class="ui-analytics__heat-row">
                            <strong>{{ $cat['name'] }}</strong>
                            <div class="ui-analytics__heat-bars">
                                <div class="ui-analytics__bar" title="{{ ___('Views') }}: {{ number_format($cat['views']) }}">
                                    <small>{{ ___('Views') }}</small>
                                    <span class="ui-ov-meter" aria-hidden="true"><span style="inline-size: {{ $cat['view_intensity'] }}%"></span></span>
                                    <strong class="ui-ltr">{{ number_format($cat['views']) }}</strong>
                                </div>
                                <div class="ui-analytics__bar ui-analytics__bar--cart" title="{{ ___('Carts') }}: {{ number_format($cat['carts']) }}">
                                    <small>{{ ___('Carts') }}</small>
                                    <span class="ui-ov-meter" aria-hidden="true"><span style="inline-size: {{ $cat['cart_intensity'] }}%"></span></span>
                                    <strong class="ui-ltr">{{ number_format($cat['carts']) }}</strong>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endif
    @endif

    {{-- SA1: Menu-engineering matrix (popularity × margin) — always shown when restaurants exist --}}
    @if(!empty($menuRestaurants) && count($menuRestaurants))
        <section class="ui-card ui-card--flush" id="menu-eng" data-url="{{ url('analytics') }}">
            <div class="ui-card__head">
                <h2 class="ui-card__title">{{ ___('Menu engineering') }}</h2>
                <div class="ui-card__actions">
                    @if(count($menuRestaurants) > 1)
                    <select id="menu-eng-restaurant" class="ui-input ui-input--sm ui-analytics__select" aria-label="{{ ___('Menu engineering') }}">
                        @foreach($menuRestaurants as $mr)
                            <option value="{{ $mr->id }}">{{ $mr->title }}</option>
                        @endforeach
                    </select>
                    @else
                    <input type="hidden" id="menu-eng-restaurant" value="{{ $menuRestaurants[0]->id }}">
                    @endif
                </div>
            </div>
            <p class="ui-analytics__muted ui-analytics__pad">{{ ___('Popularity × profit margin. Stars sell well and earn well; fix or drop Dogs.') }}</p>
            <table class="ui-table ui-analytics__compact">
                <thead>
                    <tr>
                        <th>{{ ___('Item') }}</th>
                        <th>{{ ___('Class') }}</th>
                        <th class="ui-table__num">{{ ___('Ordered') }}</th>
                        <th class="ui-table__num">{{ ___('Views') }}</th>
                        <th class="ui-table__num">{{ ___('View→order') }}</th>
                        <th class="ui-table__num">{{ ___('Margin') }}</th>
                    </tr>
                </thead>
                <tbody id="menu-eng-rows">
                    <tr><td colspan="6" class="ui-analytics__muted">{{ ___('Loading…') }}</td></tr>
                </tbody>
            </table>
        </section>
    @endif
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    (function () {
        if (typeof ApexCharts === 'undefined') return;

        var daily        = @json($dailyData ?? []);
        var hourly       = @json($hourlyData ?? []);
        var hourlyOrders = @json($hourlyOrdersData ?? []);
        var devices      = @json($deviceData ?? []);
        var langs        = @json($langData ?? []);
        var L = { scans: @json(___('Scans')), orders: @json(___('Orders')) };

        var charts = [];

        function palette() {
            var css = getComputedStyle(document.documentElement);
            var g = function (n, f) { return (css.getPropertyValue(n) || f).trim(); };
            return {
                primary: g('--bs-primary', '#6366f1'),
                muted:   g('--bs-secondary-color', '#6b7280'),
                border:  g('--bs-border-color', '#e6e8ee'),
                accents: [
                    g('--bs-primary', '#6366f1'),
                    g('--bs-info', '#3b82f6'),
                    g('--bs-success', '#22c55e'),
                    g('--bs-warning', '#f59e0b'),
                    g('--bs-danger', '#ef4444'),
                    '#14b8a6', '#ec4899', '#84cc16'
                ],
            };
        }

        function mount(id, opts) {
            var el = document.getElementById(id);
            if (!el) return;
            var c = new ApexCharts(el, opts);
            c.render();
            charts.push(c);
        }

        function base(p) {
            var dark = document.documentElement.getAttribute('data-theme') === 'dark';
            return {
                chart: { nonce: '{{ csp_nonce() }}', height: 220, toolbar: { show: false }, fontFamily: 'inherit', foreColor: p.muted, background: 'transparent' },
                grid: { borderColor: p.border, strokeDashArray: 4 },
                dataLabels: { enabled: false },
                tooltip: { theme: dark ? 'dark' : 'light' },
            };
        }

        function render() {
            charts.forEach(function (c) { c.destroy(); });
            charts = [];
            var p = palette();

            // Daily scans — area line
            mount('analytics-daily', Object.assign(base(p), {
                chart: Object.assign(base(p).chart, { type: 'area' }),
                series: [{ name: L.scans, data: Object.values(daily) }],
                xaxis: { categories: Object.keys(daily), labels: { rotate: 0, hideOverlappingLabels: true }, axisBorder: { show: false }, axisTicks: { show: false } },
                yaxis: { labels: { formatter: function (v) { return Math.round(v); } } },
                colors: [p.primary],
                stroke: { curve: 'smooth', width: 2 },
                fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.02 } },
            }));

            // Hourly scans — bar
            mount('analytics-hourly', Object.assign(base(p), {
                chart: Object.assign(base(p).chart, { type: 'bar' }),
                series: [{ name: L.scans, data: Object.values(hourly) }],
                xaxis: { categories: Object.keys(hourly), axisBorder: { show: false }, axisTicks: { show: false } },
                yaxis: { labels: { formatter: function (v) { return Math.round(v); } } },
                colors: [p.accents[2]],
                plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
            }));

            // Hourly orders — bar
            mount('analytics-hourly-orders', Object.assign(base(p), {
                chart: Object.assign(base(p).chart, { type: 'bar' }),
                series: [{ name: L.orders, data: Object.values(hourlyOrders) }],
                xaxis: { categories: Object.keys(hourlyOrders), axisBorder: { show: false }, axisTicks: { show: false } },
                yaxis: { labels: { formatter: function (v) { return Math.round(v); } } },
                colors: [p.accents[1]],
                plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
            }));

            // Devices — donut
            mount('analytics-devices', Object.assign(base(p), {
                chart: Object.assign(base(p).chart, { type: 'donut' }),
                series: Object.values(devices).map(Number),
                labels: Object.keys(devices),
                colors: p.accents,
                legend: { position: 'bottom' },
                plotOptions: { pie: { donut: { size: '60%' } } },
                stroke: { width: 2, colors: ['transparent'] },
            }));

            // Languages — donut
            mount('analytics-langs', Object.assign(base(p), {
                chart: Object.assign(base(p).chart, { type: 'donut' }),
                series: Object.values(langs).map(Number),
                labels: Object.keys(langs),
                colors: p.accents,
                legend: { position: 'bottom' },
                plotOptions: { pie: { donut: { size: '60%' } } },
                stroke: { width: 2, colors: ['transparent'] },
            }));
        }

        render();
        window.addEventListener('ui:theme', render);
    })();
</script>
<script nonce="{{ csp_nonce() }}">
(function () {
    var sel = document.getElementById('menu-eng-restaurant');
    var body = document.getElementById('menu-eng-rows');
    var wrap = document.getElementById('menu-eng');
    if (!sel || !body || !wrap) return;

    // P1-6: theme-aware Bootstrap variants (text-bg-*) — no hardcoded hex, so the
    // pills stay legible in dark mode.
    var QCLASS = {
        star:      {label: '{{ ___('Star') }}',      cls: 'success', tone: 'success'},
        plowhorse: {label: '{{ ___('Plowhorse') }}', cls: 'warning', tone: 'warning'},
        puzzle:    {label: '{{ ___('Puzzle') }}',    cls: 'primary', tone: 'accent'},
        dog:       {label: '{{ ___('Dog') }}',       cls: 'danger', tone: 'danger'},
        popular:   {label: '{{ ___('Popular') }}',   cls: 'info', tone: 'accent'},
        unpopular: {label: '{{ ___('Unpopular') }}', cls: 'secondary', tone: 'neutral'}
    };

    // Column names for the phone card layout of .ui-table (data-label).
    var L = { cls: esc(@json(___('Class'))), ordered: esc(@json(___('Ordered'))), views: esc(@json(___('Views'))), vto: esc(@json(___('View→order'))), margin: esc(@json(___('Margin'))) };

    function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }

    function render(items) {
        if (!items.length) { body.innerHTML = '<tr><td colspan="6" class="ui-analytics__muted">{{ ___('No menu data yet.') }}</td></tr>'; return; }
        body.innerHTML = items.map(function (r) {
            var q = QCLASS[r.quadrant] || QCLASS.unpopular;
            var badge = '<span class="ui-pill" data-tone="' + q.tone + '">' + q.label + '</span>';
            var vto = r.view_to_order == null ? '—' : (r.view_to_order + '%');
            var flag = r.underperformer ? ' <span title="{{ ___('High views, low orders — fix photo/description/price') }}" class="ui-analytics__drop">⚠</span>' : '';
            var margin = r.margin == null ? '—' : r.margin;
            return '<tr><td data-primary>' + esc(r.name) + '</td><td data-label="' + L.cls + '">' + badge + '</td><td class="ui-table__num" data-label="' + L.ordered + '">' + r.ordered +
                   '</td><td class="ui-table__num" data-label="' + L.views + '">' + r.views + '</td><td class="ui-table__num" data-label="' + L.vto + '">' + vto + flag +
                   '</td><td class="ui-table__num" data-label="' + L.margin + '">' + margin + '</td></tr>';
        }).join('');
    }

    function load() {
        var id = sel.value;
        body.innerHTML = '<tr><td colspan="6" class="ui-analytics__muted">{{ ___('Loading…') }}</td></tr>';
        fetch(wrap.dataset.url + '/' + id + '/menu-engineering', {headers: {'Accept': 'application/json'}})
            .then(function (r) { return r.json(); })
            .then(function (d) { render(d.items || []); })
            .catch(function () { body.innerHTML = '<tr><td colspan="6" class="ui-analytics__drop">{{ ___('Failed to load.') }}</td></tr>'; });
    }

    sel.addEventListener('change', load);
    load();
})();
</script>
{{-- HPMS E14(f): print handler + print-only styling. --}}
<style nonce="{{ csp_nonce() }}">
    @media print { [data-print-report] { display: none !important; } body { background: #fff; } }
</style>
<script nonce="{{ csp_nonce() }}">
    document.querySelector('[data-print-report]')?.addEventListener('click', function () { window.print(); });
</script>
@endpush
