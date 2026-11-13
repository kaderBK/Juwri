{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Accounting'))
@section('page_title', ___('Accounting'))
@section('page_sub', ___('Your books at a glance.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.books.index') }}">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('accounting')])

@php
    // Bar heights are relative to the busiest month so the chart reads at a glance.
    $trendMax = max(1, collect($trend)->flatMap(fn ($m) => [(float) $m['revenue'], (float) $m['expense']])->max());
@endphp

<div class="ui-scope ui-books">
    <section class="ui-books__kpis" aria-label="{{ ___('Accounting') }}">
        <x-ui.stat :value="number_format($cash, 2)" :label="___('Cash position')" icon="wallet" />
        <x-ui.stat :value="number_format($pl['total_revenue'], 2)" :label="___('Revenue (MTD)')" icon="trend-up" tone="success" />
        <x-ui.stat :value="number_format($pl['net_income'], 2)" :label="___('Net income (MTD)')" icon="chart" />
        <x-ui.stat :value="number_format($vat['net_vat_due'], 2)" :label="___('VAT due (MTD)')" icon="alert" tone="danger" />
    </section>

    <x-ui.card :title="___('Revenue vs expense (6 months)')" variant="flush">
        <div class="ui-books__trend" aria-hidden="true">
            @foreach($trend as $m)
                <div class="ui-books__trend-col">
                    <div class="ui-books__trend-bars">
                        <span class="ui-books__bar ui-books__bar--in" style="block-size: {{ round(((float) $m['revenue'] / $trendMax) * 100) }}%"></span>
                        <span class="ui-books__bar ui-books__bar--out" style="block-size: {{ round(((float) $m['expense'] / $trendMax) * 100) }}%"></span>
                    </div>
                    <span class="ui-books__trend-label">{{ $m['label'] }}</span>
                </div>
            @endforeach
        </div>
        <div class="ui-books__legend" aria-hidden="true">
            <span class="ui-books__key ui-books__key--in">{{ ___('Revenue') }}</span>
            <span class="ui-books__key ui-books__key--out">{{ ___('Expense') }}</span>
        </div>
        <table class="ui-books__ledger">
            <thead><tr>
                <th>{{ ___('Month') }}</th>
                <th class="ui-books__ledger-num">{{ ___('Revenue') }}</th>
                <th class="ui-books__ledger-num">{{ ___('Expense') }}</th>
                <th class="ui-books__ledger-num">{{ ___('Net') }}</th>
            </tr></thead>
            <tbody>
            @foreach($trend as $m)
                @php $net = $m['revenue'] - $m['expense']; @endphp
                <tr>
                    <td><strong>{{ $m['label'] }}</strong></td>
                    <td class="ui-books__ledger-num ui-books__in"><span class="ui-ltr">{{ number_format($m['revenue'], 2) }}</span></td>
                    <td class="ui-books__ledger-num ui-books__out"><span class="ui-ltr">{{ number_format($m['expense'], 2) }}</span></td>
                    <td class="ui-books__ledger-num"><strong class="ui-ltr">{{ number_format($net, 2) }}</strong></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </x-ui.card>

    <x-ui.card :title="___('Quick links')" variant="flush">
        <nav class="ui-books__links" aria-label="{{ ___('Quick links') }}">
            <x-ui.row :href="route('stores.books.journals').'?store_id='.$rid" icon="book-open" :title="___('Journals')" />
            <x-ui.row :href="route('stores.books.expenses').'?store_id='.$rid" icon="wallet" :title="___('Expenses')" />
            <x-ui.row :href="route('stores.books.reports').'?store_id='.$rid" icon="chart" :title="___('Financial reports')" />
            @if($syncEnabled && Route::has('stores.accounting.index'))
                <x-ui.row :href="route('stores.accounting.index').'?store_id='.$rid" icon="refresh" :title="___('QuickBooks / Xero sync')" />
            @endif
        </nav>
    </x-ui.card>
</div>
@endsection
