{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Owner mobile usage'))
@section('page_sub', ___('Which dashboard screens owners open, ranked by distinct owners. Use it to decide which screens to redesign for phones first.'))
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="download" :href="route('admin.ownerMobileUsage.export', ['device' => $device, 'days' => $days])">CSV</x-ui.button>
@endsection

@section('content')
<div class="ui-stack">
    <div class="ui-cluster">
        <nav class="ui-chips" aria-label="{{ ___('Device') }}">
            @foreach($devices as $d)
                <x-ui.chip :href="route('admin.ownerMobileUsage', ['device' => $d, 'days' => $days])" :active="$device === $d">{{ ___(ucfirst($d)) }}</x-ui.chip>
            @endforeach
        </nav>
        <nav class="ui-chips" aria-label="{{ ___('Period') }}">
            @foreach([7, 30, 90] as $p)
                <x-ui.chip :href="route('admin.ownerMobileUsage', ['device' => $device, 'days' => $p])" :active="$days === $p">{{ ___(':n days', ['n' => $p]) }}</x-ui.chip>
            @endforeach
        </nav>
    </div>

    <section class="ui-kpis" aria-label="{{ ___('Owner mobile usage') }}">
        @foreach([
            [___('Screen views'), $totals['views'] ?? 0, 'eye'],
            [___('Owners'), $totals['users'] ?? 0, 'users'],
            [___('Owners using the installed app (PWA)'), $totals['pwa_users'] ?? 0, 'device-mobile'],
            [___('Owners using the native app'), $totals['app_users'] ?? 0, 'device-tablet'],
        ] as [$label, $val, $icon])
            <x-ui.stat :value="number_format($val)" :label="$label" :icon="$icon" />
        @endforeach
    </section>

    <x-ui.card variant="flush">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ ___('Screen (route)') }}</th>
                        <th class="ui-end">{{ ___('Owners') }}</th>
                        <th class="ui-end">{{ ___('Views') }}</th>
                        <th class="ui-end">{{ ___('Browser') }}</th>
                        <th class="ui-end">PWA</th>
                        <th class="ui-end">{{ ___('App') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $i => $r)
                        <tr>
                            <td class="ui-text-2" data-label="#">{{ $i + 1 }}</td>
                            <td data-primary data-label="{{ ___('Screen (route)') }}"><code class="ui-mono ui-break">{{ $r->route_name }}</code></td>
                            <td class="ui-end" data-label="{{ ___('Owners') }}"><strong>{{ number_format($r->users) }}</strong></td>
                            <td class="ui-end" data-label="{{ ___('Views') }}">{{ number_format($r->views) }}</td>
                            <td class="ui-end ui-text-2" data-label="{{ ___('Browser') }}">{{ number_format($r->browser_views) }}</td>
                            <td class="ui-end ui-text-2" data-label="PWA">{{ number_format($r->pwa_views) }}</td>
                            <td class="ui-end ui-text-2" data-label="{{ ___('App') }}">{{ number_format($r->app_views) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ui-center ui-text-2">{{ ___('No data yet. Screen views are recorded as owners use the dashboard.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
@endsection
