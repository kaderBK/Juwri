{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Charge history'))
@section('page_sub', ___('Durable record of every settled charge, kept even after the transaction is consumed.'))
@section('header_buttons')
    <form method="GET" action="{{ route('admin.receipts') }}">
        <select name="currency" class="ui-input ui-input--sm ui-input--auto" data-ui-do="submit" aria-label="{{ ___('All currencies') }}">
            <option value="">{{ ___('All currencies') }}</option>
            @foreach ($currencies as $code)
                <option value="{{ $code }}" @selected(request('currency') === $code)>{{ $code }}</option>
            @endforeach
        </select>
    </form>
    <x-ui.button variant="secondary" size="sm" :href="route('admin.transactions.index')">{{ ___('Transactions') }}</x-ui.button>
@endsection
@section('content')
    <x-ui.card variant="flush">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Receipt') }}</th>
                        <th>{{ ___('Product') }}</th>
                        <th>{{ ___('Method') }}</th>
                        <th>{{ ___('Amount') }}</th>
                        <th>{{ ___('Gateway') }}</th>
                        <th>{{ ___('Reference') }}</th>
                        <th>{{ ___('Date') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($receipts as $receipt)
                        <tr>
                            <td data-primary data-label="{{ ___('Receipt') }}">#{{ $receipt->id }}</td>
                            <td data-label="{{ ___('Product') }}">{{ $receipt->product_name ?: '—' }}</td>
                            <td data-label="{{ ___('Method') }}"><x-ui.tag>{{ $receipt->transaction_method ?: '—' }}</x-ui.tag></td>
                            <td data-label="{{ ___('Amount') }}">{{ number_format((float) $receipt->amount, currency_decimals($receipt->currency_code)) }} {{ $receipt->currency_code }}</td>
                            <td data-label="{{ ___('Gateway') }}">{{ $receipt->gateway ? ucfirst($receipt->gateway) : '—' }}</td>
                            <td data-label="{{ ___('Reference') }}"><small class="ui-text-2 ui-break">{{ $receipt->gateway_ref ?: '—' }}</small></td>
                            <td data-label="{{ ___('Date') }}"><span title="{{ $receipt->created_at?->format('Y-m-d H:i:s') }}">{{ $receipt->created_at?->diffForHumans() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="ui-center ui-text-2">{{ ___('No charges recorded yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($receipts->hasPages())
            <div class="ui-card__foot">{{ $receipts->links() }}</div>
        @endif
    </x-ui.card>
@endsection
