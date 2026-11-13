{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Purchase orders'))
@section('page_title', ___('Purchase orders'))
@section('page_sub', ___('Order stock from suppliers, then receive it into inventory.'))

@section('page_actions')
    <x-ui.button :href="route('inventory.suppliers.index', $storeId ? ['store_id' => $storeId] : [])" variant="secondary" size="sm" icon="users">
        {{ ___('Suppliers') }}
    </x-ui.button>
@endsection

@section('content')
@php
    $statusVariant = ['draft'=>'neutral','ordered'=>'accent','received'=>'success','cancelled'=>'danger'];
@endphp
<div class="ui-scope ui-po">
<form method="get" action="{{ route('inventory.purchaseOrders.index') }}" class="ui-po__context">
    <select name="store_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Store') }}">
        <option value="">{{ ___('Select store…') }}</option>
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

@if($storeId)
    <x-ui.card :title="___('New purchase order')">
        <form method="post" action="{{ route('inventory.purchaseOrders.store') }}" class="ui-po__new">
            @csrf
            <input type="hidden" name="store_id" value="{{ $storeId }}">
            <div class="ui-po__field">
                <label class="ui-label" for="po-supplier">{{ ___('Supplier') }}</label>
                <select id="po-supplier" class="ui-input" name="supplier_id">
                    <option value="">{{ ___('No supplier') }}</option>
                    @foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                </select>
            </div>
            <div class="ui-po__field ui-po__field--grow"><label class="ui-label" for="po-notes">{{ ___('Notes') }}</label><input id="po-notes" class="ui-input" name="notes"></div>
            @if($branches->isNotEmpty())
                <div class="ui-po__field">
                    <label class="ui-label" for="po-branch">{{ ___('Branch') }}</label>
                    <select id="po-branch" class="ui-input" name="branch_id">
                        <option value="">{{ ___('Whole store') }}</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) $branchId === (int) $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <x-ui.button type="submit" icon="plus">{{ ___('Create draft') }}</x-ui.button>
        </form>
    </x-ui.card>
@endif

<x-ui.card :title="___('Orders')" variant="flush">
    @if($orders->isEmpty())
        <x-ui.empty :title="$storeId ? ___('No purchase orders yet') : ___('Select a store')"
            :body="$storeId ? ___('Create your first draft above.') : ___('Pick a store to view its orders.')" icon="orders" />
    @else
        <table class="ui-table">
            <thead><tr>
                <th>{{ ___('PO #') }}</th><th>{{ ___('Supplier') }}</th><th>{{ ___('Status') }}</th>
                @if($branches->isNotEmpty())
                    <th>{{ ___('Branch') }}</th>
                @endif
                <th class="ui-table__num">{{ ___('Total') }}</th><th>{{ ___('Created') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
            </tr></thead>
            <tbody>
                @foreach ($orders as $o)
                    <tr>
                        <td data-primary><strong class="ui-ltr">#{{ $o->id }}</strong></td>
                        <td data-label="{{ ___('Supplier') }}">{{ $o->supplier->name ?? ___('—') }}</td>
                        <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$statusVariant[$o->status] ?? 'neutral'">{{ ___(ucfirst($o->status)) }}</x-ui.pill></td>
                        @if($branches->isNotEmpty())
                            <td data-label="{{ ___('Branch') }}">{{ optional($branches->firstWhere('id', $o->branch_id))->name ?? ___('Whole store') }}</td>
                        @endif
                        <td class="ui-table__num" data-label="{{ ___('Total') }}"><strong class="ui-ltr">{{ price_symbol_format($o->total) }}</strong></td>
                        <td data-label="{{ ___('Created') }}"><span class="ui-ltr">{{ optional($o->created_at)->format('Y-m-d') }}</span></td>
                        <td class="ui-catalog__row-actions">
                            <x-ui.button :href="route('inventory.purchaseOrders.show', $o->id)" variant="secondary" size="sm">{{ ___('Open') }}</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-ui.card>
</div>
@endsection
