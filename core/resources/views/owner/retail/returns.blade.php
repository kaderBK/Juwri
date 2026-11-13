{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

{{-- P3-08 (D7): goods returns — approve or reject, mark the goods received (back on stock), complete as exchange, store credit or refund. --}}
@section('title', ___('Returns'))
@section('page_title', ___('Returns'))
@section('page_sub', ___('Return requests from your customers.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.retail.returns') }}">
        <select name="store_id" class="ui-input ui-input--sm ui-mods__store" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@php
    $q = ['store_id' => $rid];
    $statusLabels = [
        'requested' => ___('Waiting for you'),
        'approved' => ___('Approved, waiting for the goods'),
        'rejected' => ___('Rejected'),
        'received' => ___('Goods received'),
        'completed' => ___('Completed'),
        'cancelled' => ___('Cancelled'),
    ];
    $resolutionLabels = ['exchange' => ___('Exchange'), 'store_credit' => ___('Store credit'), 'refund' => ___('Refund')];
@endphp
<div class="ui-scope ui-disp" data-retail-returns>
@if($returns->isEmpty())
    <div class="ui-card">
        <x-ui.empty icon="check" :title="___('No return requests.')" />
    </div>
@else
    @foreach($returns as $return)
        @php
            $currency = strtoupper((string) ($return->currency_code ?: store_currency((int) $return->store_id)));
            $amount = \Juwri\Core\Money\Money::majorFromMinor((int) $return->amount_minor, $currency);
        @endphp
        <article class="ui-card ui-disp__item" data-return="{{ $return->id }}" @if(in_array($return->status, ['requested', 'approved', 'received'], true)) data-open @endif>
            <header class="ui-disp__head">
                <div class="ui-disp__amount">
                    <strong class="ui-ltr">{{ $currency }} {{ number_format($amount, \Juwri\Core\Money\Currencies::exponent($currency)) }}</strong>
                    <span class="ui-disp__reason">{{ ___('Order') }} <span class="ui-ltr">#{{ $return->order_id }}</span> · {{ $resolutionLabels[$return->resolution] ?? $return->resolution }}</span>
                </div>
                <x-ui.pill :tone="$return->status === 'requested' ? 'warning' : ($return->status === 'completed' ? 'neutral' : 'accent')">{{ $statusLabels[$return->status] ?? $return->status }}</x-ui.pill>
            </header>
            <ul>
                @foreach((array) $return->lines as $line)
                    @php($item = $items->get((int) $line['order_item_id']))
                    <li>{{ (int) $line['qty'] }} × {{ $item ? $item->displayName() : ___('Item') }}</li>
                @endforeach
            </ul>
            @if($return->reason)
                <p class="ui-text-2">{{ $return->reason }}</p>
            @endif
            <div class="ui-disp__actions">
                <x-ui.button :href="route('stores.orders.show', $return->order_id)" variant="secondary" size="sm">{{ ___('Open order') }}</x-ui.button>
                @if($return->status === 'requested')
                    <form method="post" action="{{ route('stores.retail.returns.decide', ['return' => $return->id] + $q) }}">
                        @csrf
                        <input type="hidden" name="decision" value="approve">
                        <x-ui.button type="submit" size="sm" icon="check" data-ui-busy>{{ ___('Approve') }}</x-ui.button>
                    </form>
                    <form method="post" action="{{ route('stores.retail.returns.decide', ['return' => $return->id] + $q) }}" data-confirm="{{ ___('Reject this return?') }}" data-confirm-danger>
                        @csrf
                        <input type="hidden" name="decision" value="reject">
                        <x-ui.button type="submit" size="sm" variant="secondary" data-ui-busy>{{ ___('Reject') }}</x-ui.button>
                    </form>
                @elseif($return->status === 'approved')
                    <form method="post" action="{{ route('stores.retail.returns.receive', ['return' => $return->id] + $q) }}">
                        @csrf
                        <input type="hidden" name="restock" value="0">
                        <label><input type="checkbox" name="restock" value="1" checked> {{ ___('Put back on stock') }}</label>
                        <x-ui.button type="submit" size="sm" data-ui-busy>{{ ___('Goods received') }}</x-ui.button>
                    </form>
                @elseif($return->status === 'received')
                    <form method="post" action="{{ route('stores.retail.returns.complete', ['return' => $return->id] + $q) }}" data-confirm="{{ ___('Complete this return?') }}">
                        @csrf
                        <x-ui.button type="submit" size="sm" icon="check" data-ui-busy>{{ $return->resolution === 'exchange' ? ___('Complete exchange') : ($return->resolution === 'refund' ? ___('Refund and complete') : ___('Add store credit and complete')) }}</x-ui.button>
                    </form>
                @endif
            </div>
        </article>
    @endforeach
@endif
</div>
@endsection
