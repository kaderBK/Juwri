{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Branch reports'))
{{-- P1-13 13h: one branch at a time in Community; the side-by-side comparison of all branches is ee ($compare). --}}
@section('page_title', ($compare ?? false) ? ___('Branch comparison') : ___('Branch reports'))
@section('page_sub', ___('Sales and performance by location.'))

@section('content')
<div class="ui-scope ui-breports">
    <form method="get" action="{{ route('branches.reports') }}" class="ui-breports__context">
        <select name="store_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            <option value="">{{ ___('Select store…') }}</option>
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
        @if($storeId && ! ($compare ?? false) && $branches->count() > 1)
            <select name="branch_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Branch') }}">
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}" @selected($branchId == $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        @endif
        @if($storeId)
            <select name="days" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Period') }}">
                @foreach([7=>___('Last 7 days'),30=>___('Last 30 days'),90=>___('Last 90 days')] as $d => $label)
                    <option value="{{ $d }}" @selected($days == $d)>{{ $label }}</option>
                @endforeach
            </select>
        @endif
    </form>

    @if($compareWithheld ?? false)
        <x-plan-gate-notice feature="branch_reports" />
    @endif

    @if(!$storeId)
        <div class="ui-card"><x-ui.empty :title="___('Pick a store to compare its branches.')" icon="store" /></div>
    @else
        <section class="ui-card ui-account__hero">
            <div>
                <p class="ui-overline">{{ ($compare ?? false) ? ___('All branches') : ($rows->first()?->branch->name ?? ___('Branch')) }}</p>
                <h2 class="ui-account__name ui-ltr">{{ price_symbol_format($totals['revenue']) }}</h2>
                <p class="ui-account__row-sub">{{ ___(':n orders in the last :d days', ['n' => number_format($totals['orders']), 'd' => $days]) }}</p>
            </div>
            <dl class="ui-account__facts ui-breports__facts">
                @if($compare ?? false)
                    <div><dt>{{ ___('Branches') }}</dt><dd>{{ $rows->count() }}</dd></div>
                @endif
                <div><dt>{{ ___('Avg / order') }}</dt><dd class="ui-ltr">{{ price_symbol_format($totals['orders'] ? round($totals['revenue']/$totals['orders'],2) : 0) }}</dd></div>
            </dl>
        </section>

        @php $maxRevenue = max(1, (float) $rows->max('revenue')); @endphp
        <x-ui.card :title="___('By branch')" variant="flush">
            @if($rows->isEmpty())
                <x-ui.empty :title="___('No branches.')" icon="store" />
            @else
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('Branch') }}</th><th>{{ ___('Status') }}</th>
                        <th class="ui-table__num">{{ ___('Orders') }}</th><th class="ui-table__num">{{ ___('Revenue') }}</th>
                        <th class="ui-table__num">{{ ___('Avg / order') }}</th><th>{{ ___('Top item') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $sv = ['open'=>'success','closed'=>'warning','paused'=>'accent','inactive'=>'neutral'][$row->status] ?? 'neutral';
                            @endphp
                            <tr>
                                <td data-primary><strong>{{ $row->branch->name }}</strong></td>
                                <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$sv">{{ ___(ucfirst($row->status)) }}</x-ui.pill></td>
                                <td class="ui-table__num" data-label="{{ ___('Orders') }}"><span class="ui-ltr">{{ number_format($row->orders) }}</span></td>
                                <td class="ui-table__num" data-label="{{ ___('Revenue') }}">
                                    <span class="ui-breports__rev">
                                        <strong class="ui-ltr">{{ price_symbol_format($row->revenue) }}</strong>
                                        <span class="ui-ov-meter" aria-hidden="true"><span style="inline-size: {{ round(((float) $row->revenue / $maxRevenue) * 100) }}%"></span></span>
                                    </span>
                                </td>
                                <td class="ui-table__num" data-label="{{ ___('Avg / order') }}"><span class="ui-ltr">{{ price_symbol_format($row->avg) }}</span></td>
                                <td data-label="{{ ___('Top item') }}">{{ $row->top_item ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-ui.card>
    @endif
</div>
@endsection
