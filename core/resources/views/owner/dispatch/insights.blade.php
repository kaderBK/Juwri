{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Delivery insights'))
@section('page_title', ___('Delivery insights'))
@section('page_sub', ___('When and where your delivery demand happens.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.deliveryInsights.index') }}" class="ui-dinsights__store">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')

{{-- WI-A3: shown blurred to an unentitled owner, with their real heat grid and
     forecast behind it. The controller has already withheld the courier
     coordinates and the area tokens — blur hides nothing from the page source. --}}
@php $__preview = $preview ?? false; @endphp
<x-spotlight.preview :key="$previewKey ?? 'demand_forecast'" :active="$__preview">

<div class="ui-scope ui-dinsights">

<x-ui.card :title="___('Demand forecast')" variant="flush">
    <p class="ui-dinsights__sub">{{ ___('Next 7 days — projected orders, revenue and staffing.') }}</p>
    @if(($forecast['insufficient'] ?? true))
        <x-ui.empty :title="___('Not enough order history yet for a reliable forecast.')" icon="chart" />
    @else
        @php $staffByDate = collect($forecast['staffing'] ?? [])->keyBy('date'); @endphp
        <table class="ui-dinsights__fc">
            <thead><tr>
                <th scope="col">{{ ___('Day') }}</th><th scope="col">{{ ___('Orders') }}</th>
                <th scope="col">{{ ___('Revenue') }}</th><th scope="col">{{ ___('Staff') }}</th>
            </tr></thead>
            <tbody>
            @foreach($forecast['forecast'] as $f)
                <tr>
                    <th scope="row"><strong>{{ $f['weekday'] }}</strong> <span class="ui-dinsights__date ui-ltr">{{ $f['date'] }}</span></th>
                    <td><span class="ui-ltr">{{ $f['orders'] }}</span></td>
                    <td><strong class="ui-ltr">{{ number_format($f['revenue'], 2) }}</strong></td>
                    <td><span class="ui-ltr">{{ optional($staffByDate->get($f['date']))['staff'] ?? '—' }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if(!empty($forecast['prep']))
            <div class="ui-dinsights__prep">
                <p class="ui-overline">{{ ___('Tomorrow — suggested prep') }}</p>
                <div class="ui-dinsights__tags">
                    @foreach($forecast['prep'] as $p)
                        <x-ui.tag>{{ $p['name'] }}: <span class="ui-ltr">{{ $p['qty'] }}</span></x-ui.tag>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</x-ui.card>

@php
    $days = [___('Sun'), ___('Mon'), ___('Tue'), ___('Wed'), ___('Thu'), ___('Fri'), ___('Sat')];
    $max = max(1, $heat['max']);
@endphp

<x-ui.card :title="___('Demand heat grid')">
    <x-slot:actions><x-ui.tag tone="accent"><span class="ui-ltr">{{ $heat['total'] }}</span> {{ ___('orders') }}</x-ui.tag></x-slot:actions>
    <p class="ui-dinsights__note">{{ ___('Delivery orders by weekday and hour over the last 8 weeks.') }}</p>
    @if($heat['total'] === 0)
        <x-ui.empty :title="___('No delivery orders in this window yet.')" icon="clock" />
    @else
    <div class="ui-dinsights__heat-wrap">
        <table class="ui-dinsights__heat">
            <thead><tr>
                <th><span class="ui-visually-hidden">{{ ___('Day') }}</span></th>
                @for($h = 0; $h < 24; $h++)<th scope="col">{{ $h }}</th>@endfor
            </tr></thead>
            <tbody>
                @foreach($days as $w => $label)
                    <tr>
                        <th scope="row">{{ $label }}</th>
                        @for($h = 0; $h < 24; $h++)
                            @php
                                $v = $heat['grid'][$w][$h];
                                $alpha = $v > 0 ? round(0.12 + 0.88 * ($v / $max), 2) : 0;
                            @endphp
                            <td title="{{ $label }} {{ $h }}:00 — {{ $v }}" @class(['is-hot' => $alpha > 0.5])
                                style="--heat: {{ round($alpha * 100) }}%">{{ $v ?: '' }}</td>
                        @endfor
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</x-ui.card>

<div class="ui-dinsights__pair">
    <x-ui.card :title="___('Top delivery areas')">
        <p class="ui-dinsights__note">{{ ___('Most frequent address keywords (approximate — no geocoding).') }}</p>
        @if(empty($topAreas))
            <x-ui.empty :title="___('Not enough address data yet.')" icon="pin" />
        @else
            <ul class="ui-dinsights__areas">
                @foreach($topAreas as $a)
                    <li><span>{{ ucfirst($a['token']) }}</span><strong class="ui-ltr">{{ $a['count'] }}</strong></li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
    <x-ui.card :title="___('Courier GPS points')">
        <p class="ui-dinsights__note">{{ ___('Real coordinates captured from courier tracking.') }}</p>
        @if(empty($scatter))
            <x-ui.empty :title="___('No courier GPS captured yet.')" icon="delivery" />
        @else
            <p class="ui-dinsights__gps"><strong class="ui-ltr">{{ count($scatter) }}</strong> {{ ___('points available.') }}</p>
            <p class="ui-dinsights__note">{{ ___('A map layer renders these where courier tracking is active.') }}</p>
        @endif
    </x-ui.card>
</div>

</div>

</x-spotlight.preview>

@endsection
