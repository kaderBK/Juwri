{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Waste log'))
@section('page_title', ___('Waste log'))
@section('page_sub', ___('Record spoilage, expiry and prep loss. Stock is deducted automatically.'))

@section('page_actions')
    <x-ui.button :href="route('inventory.reports.index', $storeId ? ['store_id' => $storeId] : [])" variant="secondary" size="sm" icon="chart">
        {{ ___('Reports') }}
    </x-ui.button>
@endsection

@section('content')
@php
    $reasonLabels = ['spoilage'=>___('Spoilage'),'expiry'=>___('Expiry'),'prep_error'=>___('Prep error'),'theft'=>___('Theft'),'overproduction'=>___('Overproduction'),'other'=>___('Other')];
@endphp
<div class="ui-scope ui-waste">
<form method="get" action="{{ route('inventory.waste.index') }}" class="ui-waste__context">
    <select name="store_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Store') }}">
        <option value="">{{ ___('All stores') }}</option>
        @foreach ($stores as $r)
            <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
        @endforeach
    </select>
    @if($branches->isNotEmpty())
        <select name="branch_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Branch') }}">
            <option value="">{{ ___('All branches') }}</option>
            @foreach ($branches as $b)
                <option value="{{ $b->id }}" @selected((int) $branchId === (int) $b->id)>{{ $b->name }}</option>
            @endforeach
        </select>
    @endif
</form>

<section class="ui-card ui-account__hero ui-waste__hero">
    <div>
        <p class="ui-overline">{{ ___('Waste') }}</p>
        <h2 class="ui-account__name ui-ltr">{{ price_symbol_format($wasteCost30) }}</h2>
        <p class="ui-account__row-sub">{{ ___('Costed waste in the last 30 days') }}</p>
    </div>
    @if(count($byReason))
        <dl class="ui-account__facts ui-waste__facts">
            @foreach($byReason as $row)
                <div><dt>{{ $reasonLabels[$row->reason] ?? $row->reason }}</dt><dd class="ui-ltr">{{ price_symbol_format($row->total_cost) }}</dd></div>
            @endforeach
        </dl>
    @endif
</section>

@if($storeId)
    <x-ui.card :title="___('Log waste')">
        @if($ingredients->isEmpty())
            <x-ui.empty :title="___('Add ingredients to this store first.')" icon="products" />
        @else
            <form method="post" action="{{ route('inventory.waste.store') }}" class="ui-waste__form">
                @csrf
                <div class="ui-waste__field ui-waste__field--wide">
                    <label class="ui-label" for="wl-ing">{{ ___('Ingredient') }}</label>
                    <select id="wl-ing" class="ui-input" name="ingredient_id" required>
                        @foreach($ingredients as $ing)<option value="{{ $ing->id }}">{{ $ing->name }} ({{ $ing->unit }})</option>@endforeach
                    </select>
                </div>
                <div class="ui-waste__field"><label class="ui-label" for="wl-qty">{{ ___('Quantity') }}</label><input id="wl-qty" class="ui-input" name="quantity" type="number" step="0.001" min="0.001" required inputmode="decimal"></div>
                <div class="ui-waste__field">
                    <label class="ui-label" for="wl-reason">{{ ___('Reason') }}</label>
                    <select id="wl-reason" class="ui-input" name="reason">
                        @foreach($reasonLabels as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                @if($branches->isNotEmpty())
                    <div class="ui-waste__field">
                        <label class="ui-label" for="wl-branch">{{ ___('Branch') }}</label>
                        <select id="wl-branch" class="ui-input" name="branch_id">
                            <option value="">{{ ___('Whole store') }}</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}" @selected((int) $branchId === (int) $b->id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="ui-waste__field ui-waste__field--wide"><label class="ui-label" for="wl-note">{{ ___('Note') }}</label><input id="wl-note" class="ui-input" name="note" maxlength="255"></div>
                <div class="ui-waste__submit"><x-ui.button type="submit" icon="plus">{{ ___('Log waste') }}</x-ui.button></div>
            </form>
        @endif
    </x-ui.card>
@endif

<x-ui.card :title="___('Recent waste')" variant="flush">
    @if($logs->isEmpty())
        <x-ui.empty :title="___('No waste recorded')" :body="___('Logged spoilage and loss will appear here.')" icon="trash" />
    @else
        <table class="ui-table">
            <thead><tr>
                <th>{{ ___('Ingredient') }}</th>
                @if($branches->isNotEmpty())
                    <th>{{ ___('Branch') }}</th>
                @endif
                <th>{{ ___('When') }}</th><th>{{ ___('Reason') }}</th>
                <th class="ui-table__num">{{ ___('Quantity') }}</th><th class="ui-table__num">{{ ___('Cost') }}</th>
            </tr></thead>
            <tbody>
                @foreach ($logs as $log)
                    <tr>
                        <td data-primary><strong>{{ $log->ingredient->name ?? '—' }}</strong></td>
                        @if($branches->isNotEmpty())
                            <td data-label="{{ ___('Branch') }}">{{ optional($branches->firstWhere('id', $log->branch_id))->name ?? ___('Whole store') }}</td>
                        @endif
                        <td data-label="{{ ___('When') }}"><span class="ui-ltr">{{ optional($log->created_at)->format('Y-m-d H:i') }}</span></td>
                        <td data-label="{{ ___('Reason') }}"><x-ui.tag tone="danger">{{ $reasonLabels[$log->reason] ?? $log->reason }}</x-ui.tag></td>
                        <td class="ui-table__num" data-label="{{ ___('Quantity') }}"><span class="ui-ltr">{{ rtrim(rtrim(number_format($log->quantity, 3), '0'), '.') }}</span></td>
                        <td class="ui-table__num" data-label="{{ ___('Cost') }}"><strong class="ui-ltr">{{ price_symbol_format($log->cost) }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-ui.card>
</div>
@endsection
