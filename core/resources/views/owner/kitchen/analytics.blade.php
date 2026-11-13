{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Kitchen analytics'))
@section('page_title', ___('Kitchen performance'))
@section('page_sub', ___('Prep times, on-time rate, and slowest items by station.'))

@section('page_actions')
    <x-ui.button :href="route('kds.index', ['store_id' => $storeId])" variant="secondary" icon="kitchen">{{ ___('Open KDS') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-kdsan">
    <form method="get" action="{{ route('kds.analytics') }}" class="ui-kdsan__context">
        <select name="store_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            <option value="">{{ ___('Select store…') }}</option>
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
        @if($storeId)
            <select name="days" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Period') }}">
                @foreach([1=>___('Today'),7=>___('Last 7 days'),30=>___('Last 30 days')] as $d => $label)
                    <option value="{{ $d }}" @selected($days == $d)>{{ $label }}</option>
                @endforeach
            </select>
        @endif
    </form>

    @if(!$report)
        <div class="ui-card">
            <x-ui.empty :title="___('Pick a store to see kitchen performance.')" icon="kitchen" />
        </div>
    @else
        <section class="ui-card ui-kdsan__hero">
            <div class="ui-kdsan__lead">
                <p class="ui-overline">{{ ___('Avg ticket time') }}</p>
                <p class="ui-kdsan__big"><span class="ui-ltr">{{ $report['avgTicket'] }}</span> <small>{{ ___('min') }}</small></p>
                <span class="ui-kdsan__sub">{{ ___(':n tickets completed in range', ['n' => number_format($report['orders'])]) }}</span>
            </div>
            <div class="ui-kdsan__stats">
                <x-ui.stat :value="$report['avgPrep'].' '.___('min')" :label="___('Avg item prep')" icon="clock" />
                <x-ui.stat :value="$report['onTimePct'].'%'" :label="___('On-time')" icon="check" tone="success" />
                <x-ui.stat :value="$report['last15']" :label="___('Orders 15m')" icon="orders" />
                <x-ui.stat :value="$report['last30']" :label="___('Orders 30m')" icon="orders" />
            </div>
        </section>

        {{-- On-time breakdown bar --}}
        @php $tot = max(1, $report['green'] + $report['yellow'] + $report['red']); @endphp
        <x-ui.card :title="___('Bumped on time')">
            <div class="ui-kdsan__bar" role="img" aria-label="{{ ___('Bumped on time') }}">
                <span class="ui-kdsan__seg" data-tone="success" style="inline-size: {{ round($report['green']/$tot*100) }}%" title="{{ ___('On time') }}: {{ $report['green'] }}"></span>
                <span class="ui-kdsan__seg" data-tone="warning" style="inline-size: {{ round($report['yellow']/$tot*100) }}%" title="{{ ___('Caution') }}: {{ $report['yellow'] }}"></span>
                <span class="ui-kdsan__seg" data-tone="danger" style="inline-size: {{ round($report['red']/$tot*100) }}%" title="{{ ___('Late') }}: {{ $report['red'] }}"></span>
            </div>
            <div class="ui-kdsan__legend">
                <x-ui.pill tone="success">{{ ___('On time') }}: <span class="ui-ltr">{{ $report['green'] }}</span></x-ui.pill>
                <x-ui.pill tone="warning">{{ ___('Caution') }}: <span class="ui-ltr">{{ $report['yellow'] }}</span></x-ui.pill>
                <x-ui.pill tone="danger">{{ ___('Late') }}: <span class="ui-ltr">{{ $report['red'] }}</span></x-ui.pill>
            </div>
        </x-ui.card>

        <div class="ui-kdsan__pair">
            <x-ui.card :title="___('Slowest items')" variant="flush">
                @if($report['byItem']->isEmpty())
                    <x-ui.empty :title="___('No completed items in this range yet.')" icon="products" />
                @else
                    <table class="ui-table">
                        <thead><tr><th>{{ ___('Item') }}</th><th class="ui-table__num">{{ ___('Avg prep') }}</th><th class="ui-table__num">{{ ___('Count') }}</th></tr></thead>
                        <tbody>
                            @foreach ($report['byItem'] as $row)
                                <tr>
                                    <td data-primary><strong>{{ $row['name'] }}</strong></td>
                                    <td class="ui-table__num" data-label="{{ ___('Avg prep') }}"><span><span class="ui-ltr">{{ $row['avg'] }}</span> {{ ___('min') }}</span></td>
                                    <td class="ui-table__num" data-label="{{ ___('Count') }}"><span class="ui-ltr">{{ $row['count'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-ui.card>

            <x-ui.card :title="___('By station')" variant="flush">
                @if($report['byStation']->isEmpty())
                    <x-ui.empty :title="___('No station data yet.')" icon="kitchen" />
                @else
                    <table class="ui-table">
                        <thead><tr><th>{{ ___('Station') }}</th><th class="ui-table__num">{{ ___('Avg prep') }}</th><th class="ui-table__num">{{ ___('Items') }}</th></tr></thead>
                        <tbody>
                            @foreach ($report['byStation'] as $row)
                                <tr>
                                    <td data-primary><strong>{{ $row['name'] }}</strong></td>
                                    <td class="ui-table__num" data-label="{{ ___('Avg prep') }}"><span><span class="ui-ltr">{{ $row['avg'] }}</span> {{ ___('min') }}</span></td>
                                    <td class="ui-table__num" data-label="{{ ___('Items') }}"><span class="ui-ltr">{{ $row['count'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-ui.card>
        </div>
    @endif
</div>
@endsection
