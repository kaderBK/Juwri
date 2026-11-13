{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Delivery report'))
@section('page_title', ___('Delivery report'))
@section('page_sub', ___('Per-driver performance.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.dispatch.report') }}" class="ui-dreport__store">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@php
    $totalDeliveries = collect($rows)->sum('deliveries');
    $totalCash = collect($rows)->sum('cash_collected');
@endphp
<div class="ui-scope ui-dreport">
    <form method="get" action="{{ route('stores.dispatch.report') }}" class="ui-card ui-dreport__filters">
        <input type="hidden" name="store_id" value="{{ $rid }}">
        <div class="ui-dreport__field"><label class="ui-label" for="dr-from">{{ ___('From') }}</label><input id="dr-from" type="date" name="from" value="{{ $from }}" class="ui-input"></div>
        <div class="ui-dreport__field"><label class="ui-label" for="dr-to">{{ ___('To') }}</label><input id="dr-to" type="date" name="to" value="{{ $to }}" class="ui-input"></div>
        <x-ui.button type="submit" icon="refresh">{{ ___('Run') }}</x-ui.button>
    </form>

    <section class="ui-dreport__stats" aria-label="{{ ___('Delivery report') }}">
        <x-ui.stat :value="number_format(count($rows))" :label="___('Drivers')" icon="users" />
        <x-ui.stat :value="number_format($totalDeliveries)" :label="___('Deliveries')" icon="delivery" tone="success" />
        <x-ui.stat :value="number_format($totalCash, 2)" :label="___('Cash collected')" icon="wallet" />
    </section>

    <section class="ui-card ui-card--flush">
        @if(count($rows))
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Driver') }}</th>
                    <th class="ui-table__num">{{ ___('Deliveries') }}</th>
                    <th class="ui-table__num">{{ ___('Avg minutes') }}</th>
                    <th class="ui-table__num">{{ ___('Cash collected') }}</th>
                </tr></thead>
                <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td data-primary><strong>{{ $row['driver']->name }}</strong></td>
                        <td class="ui-table__num" data-label="{{ ___('Deliveries') }}"><span class="ui-ltr">{{ $row['deliveries'] }}</span></td>
                        <td class="ui-table__num" data-label="{{ ___('Avg minutes') }}"><span class="ui-ltr">{{ $row['avg_minutes'] ?? '—' }}</span></td>
                        <td class="ui-table__num" data-label="{{ ___('Cash collected') }}"><strong class="ui-ltr">{{ number_format($row['cash_collected'], 2) }}</strong></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @else
            <x-ui.empty :title="___('No drivers.')" icon="users" />
        @endif
    </section>
</div>
@endsection
