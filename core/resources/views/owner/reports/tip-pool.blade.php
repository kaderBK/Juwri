{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Tip pooling'))
@section('page_title', ___('Tip pooling'))
@section('page_sub', ___('Tips collected per staff member, with pooled shares.'))

@section('page_actions')
    <x-ui.button :href="route('reports.tips', array_merge(request()->only('from','to','rule'), ['export' => 'csv']))" variant="secondary" icon="download">{{ ___('Export CSV') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-tips">
    <x-ui.card>
        <form method="GET" action="{{ route('reports.tips') }}" class="ui-tips__filters">
            <div class="ui-tips__field">
                <label class="ui-label" for="tips-from">{{ ___('From') }}</label>
                <input type="date" id="tips-from" name="from" value="{{ $from }}" class="ui-input">
            </div>
            <div class="ui-tips__field">
                <label class="ui-label" for="tips-to">{{ ___('To') }}</label>
                <input type="date" id="tips-to" name="to" value="{{ $to }}" class="ui-input">
            </div>
            <div class="ui-tips__field">
                <label class="ui-label" for="tips-rule">{{ ___('Pooling rule') }}</label>
                <select id="tips-rule" name="rule" class="ui-input">
                    <option value="by_orders" @selected($rule==='by_orders')>{{ ___('By their own orders') }}</option>
                    <option value="even" @selected($rule==='even')>{{ ___('Split evenly') }}</option>
                </select>
            </div>
            <div class="ui-tips__apply">
                <x-ui.button type="submit" icon="filter" block>{{ ___('Apply') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <section class="ui-tips__stats" aria-label="{{ ___('Tip pooling') }}">
        <x-ui.stat :label="___('Total tips')" :value="price_symbol_format($totalTips)" icon="wallet" tone="success" />
        <x-ui.stat :label="___('Staff')" :value="number_format($report->count())" icon="users" />
    </section>

    <section class="ui-card ui-card--flush">
        @if($report->isEmpty())
            <x-ui.empty icon="wallet" :title="___('No tips in this range.')" />
        @else
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Staff') }}</th>
                        <th class="ui-table__num">{{ ___('Orders') }}</th>
                        <th class="ui-table__num">{{ ___('Tips') }}</th>
                        <th class="ui-table__num">{{ ___('Pooled share') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($report as $r)
                        <tr>
                            <td data-primary>{{ $r['staff'] }}</td>
                            <td class="ui-table__num" data-label="{{ ___('Orders') }}"><span class="ui-ltr">{{ number_format($r['orders']) }}</span></td>
                            <td class="ui-table__num" data-label="{{ ___('Tips') }}"><span class="ui-ltr">{{ price_symbol_format($r['tips']) }}</span></td>
                            <td class="ui-table__num" data-label="{{ ___('Pooled share') }}"><strong class="ui-ltr">{{ price_symbol_format($r['share']) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
@endsection
