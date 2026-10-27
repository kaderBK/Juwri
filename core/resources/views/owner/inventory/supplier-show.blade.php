{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', $supplier->name)
@section('page_title', $supplier->name)
@section('page_sub', ___('Supplier balance, payments, and statement.'))

@section('page_actions')
    <x-ui.button :href="route('inventory.suppliers.index', ['store_id' => $supplier->store_id])" variant="ghost" icon="back">{{ ___('Back') }}</x-ui.button>
@endsection

@section('content')
@if(session('success'))<x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>@endif
@if(session('error'))<x-ui.banner tone="danger">{{ session('error') }}</x-ui.banner>@endif

<div class="ui-form-grid">
    <div>
        <x-ui.card>
            <p class="ui-muted ui-caps">{{ ___('Goods received') }}</p>
            <h3 class="ui-section-title">{{ price_symbol_format($charges) }}</h3>
        </x-ui.card>
    </div>
    <div>
        <x-ui.card>
            <p class="ui-muted ui-caps">{{ ___('Paid') }}</p>
            <h3 class="ui-section-title">{{ price_symbol_format($paid) }}</h3>
        </x-ui.card>
    </div>
    <div>
        <x-ui.card class="{{ $balance > 0 ? 'ui-bg-warn' : '' }}">
            <p class="ui-muted ui-caps">{{ ___('Outstanding balance') }}</p>
            <h3 class="ui-section-title">{{ price_symbol_format($balance) }}</h3>
        </x-ui.card>
    </div>
</div>

<x-ui.card :title="___('Record a payment')">
    <form method="post" action="{{ route('inventory.suppliers.payment', $supplier->id) }}" class="ui-form-grid">
        @csrf
        <div>
            <label class="ui-label">{{ ___('Amount') }}</label>
            <input type="number" step="0.001" min="0.001" name="amount" class="ui-input" required>
            @if(isset($errors) && $errors->has('amount'))<small class="ui-text-danger ui-block">{{ $errors->first('amount') }}</small>@endif
        </div>
        <div>
            <label class="ui-label">{{ ___('Method') }}</label>
            <input type="text" name="method" class="ui-input" maxlength="32" placeholder="{{ ___('Cash, bank transfer…') }}">
        </div>
        <div>
            <label class="ui-label">{{ ___('Note') }}</label>
            <input type="text" name="note" class="ui-input" maxlength="255">
        </div>
        <div class="ui-form-grid__full">
            <x-ui.button type="submit" icon="check">{{ ___('Record payment') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

<x-ui.card :title="___('Statement')">
    @if(empty($statement))
        <x-ui.empty :title="___('No received orders or payments yet.')" />
    @else
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>{{ ___('Date') }}</th><th>{{ ___('Detail') }}</th><th>{{ ___('Type') }}</th><th class="ui-end">{{ ___('Amount') }}</th></tr></thead>
                <tbody>
                    @foreach($statement as $row)
                        <tr>
                            <td class="ui-text-2" data-label="{{ ___('Date') }}">{{ $row['date'] }}</td>
                            <td data-label="{{ ___('Detail') }}">{{ $row['label'] }}</td>
                            <td data-label="{{ ___('Type') }}">
                                @if($row['type'] === 'payment')
                                    <x-ui.tag tone="success">{{ ___('Payment') }}</x-ui.tag>
                                @else
                                    <x-ui.tag tone="neutral">{{ ___('Goods received') }}</x-ui.tag>
                                @endif
                            </td>
                            <td class="ui-end" data-label="{{ ___('Amount') }}">{{ price_symbol_format($row['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.card>
@endsection
