{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Split bill').' - #'.$order->id)
@section('page_title', ___('Split bill'))
@section('page_sub', ___('Order #:id', ['id' => $order->id]))

@section('content')
@php
    $total = round((float) $order->total, 2);
    $canRemove = $check && $check->payments->where('status', 'paid')->isEmpty();
@endphp
<div class="ui-scope ui-stack ui-split">
    <x-ui.card>
        <div class="ui-split__summary">
            <div>
                <p class="ui-overline">{{ ___('Order total') }}</p>
                <p class="ui-split__total ui-num">{{ price_symbol_format($total) }}</p>
                <p class="ui-split__muted">{{ $order->customer_name }} · {{ ucfirst($order->type) }}</p>
            </div>
            @if($check)
                <dl class="ui-split__facts">
                    <div><dt>{{ ___('Paid') }}</dt><dd class="ui-num">{{ price_symbol_format($check->paidAmount()) }}</dd></div>
                    <div><dt>{{ ___('Remaining') }}</dt><dd class="ui-num">{{ price_symbol_format($check->remaining()) }}</dd></div>
                </dl>
            @endif
        </div>
    </x-ui.card>

    {{-- Create / change split --}}
    <x-ui.card :title="$check ? ___('Re-split') : ___('Split evenly')">
        <div class="ui-cluster ui-split__form">
            <form method="post" action="{{ route('orders.split.store', $order->id) }}" class="ui-cluster ui-split__form">
                @csrf
                <x-ui.field name="parts" type="number" :label="___('Number of people')" :value="$check->parts ?? 2" min="1" max="50" required class="ui-split__parts" />
                <x-ui.button type="submit" icon="refresh" data-ui-busy>{{ ___('Split bill') }}</x-ui.button>
            </form>
            @if($canRemove)
                <form method="post" action="{{ route('orders.split.destroy', $order->id) }}">
                    @csrf @method('DELETE')
                    <x-ui.button type="submit" variant="ghost" icon="x">{{ ___('Remove split') }}</x-ui.button>
                </form>
            @endif
        </div>
        <p class="ui-split__muted">{{ ___('Shares are equal; the last share absorbs any rounding so they sum exactly to the total.') }}</p>
    </x-ui.card>

    {{-- Shares --}}
    @if($check)
        <x-ui.card :title="___('Shares')" variant="flush">
            <x-slot:actions>
                @if($check->status === 'paid')
                    <x-ui.pill tone="success">{{ ___('Fully paid') }}</x-ui.pill>
                @elseif($check->status === 'partial')
                    <x-ui.pill tone="warning">{{ ___('Partially paid') }}</x-ui.pill>
                @else
                    <x-ui.pill>{{ ___('Open') }}</x-ui.pill>
                @endif
            </x-slot:actions>
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Share') }}</th><th class="ui-table__num">{{ ___('Amount') }}</th>
                    <th>{{ ___('Payer') }}</th><th>{{ ___('Status') }}</th><th class="ui-table__num">{{ ___('Action') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($check->payments as $share)
                        <tr>
                            <td data-label="{{ ___('Share') }}"><strong>{{ ___('Person :n', ['n' => $share->share_no]) }}</strong></td>
                            <td data-label="{{ ___('Amount') }}" class="ui-table__num">{{ price_symbol_format($share->amount) }}</td>
                            <td data-label="{{ ___('Payer') }}">{{ $share->payer_name ?: '—' }}</td>
                            <td data-label="{{ ___('Status') }}">
                                @if($share->status === 'paid')
                                    <x-ui.tag tone="success">{{ ___('Paid') }} · {{ ucfirst($share->method) }}</x-ui.tag>
                                @else
                                    <x-ui.tag>{{ ___('Pending') }}</x-ui.tag>
                                @endif
                            </td>
                            <td data-label="{{ ___('Action') }}" class="ui-table__num">
                                @if($share->status !== 'paid')
                                    <form method="post" action="{{ route('orders.split.pay', $share->id) }}" class="ui-cluster ui-split__pay">
                                        @csrf
                                        <span class="ui-field__control ui-split__payer">
                                            <input name="payer_name" placeholder="{{ ___('Name') }}" aria-label="{{ ___('Payer') }}">
                                        </span>
                                        <span class="ui-field__control ui-split__method">
                                            <select name="method" aria-label="{{ ___('Payment method') }}">
                                                <option value="cash">{{ ___('Cash') }}</option>
                                                <option value="card">{{ ___('Card') }}</option>
                                                <option value="online">{{ ___('Online') }}</option>
                                            </select>
                                        </span>
                                        <x-ui.button type="submit" variant="secondary" size="sm" icon="check">{{ ___('Mark paid') }}</x-ui.button>
                                    </form>
                                @else
                                    <span class="ui-split__muted">{{ optional($share->paid_at)->format('H:i') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
    @endif
</div>
@endsection
