{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

{{-- P3-06b (API-21, API-27, API-28, API-32): payments that arrived after an online order's payment window closed. --}}
@section('title', ___('Late payments'))
@section('page_title', ___('Late payments'))
@section('page_sub', ___('Payments that arrived after the order was cancelled. Accept them or refund them.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.latePayments.index') }}">
        <select name="store_id" class="ui-input ui-input--sm ui-mods__store" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@php
    $labels = [
        'received_late' => ___('Waiting for you'),
        'accepted' => ___('Accepted'),
        'refunded' => ___('Refunded'),
        'voided' => ___('Card hold released'),
    ];
@endphp
<div class="ui-scope ui-disp" data-late-payments>
@if($orders->isEmpty())
    <div class="ui-card">
        <x-ui.empty icon="check" :title="___('No late payments.')" />
    </div>
@else
    @foreach($orders as $order)
        @php
            $state = (string) $order->late_payment_status;
            $waiting = $state === 'received_late' && $order->status === 'cancelled';
            $pending = $state === 'accepted' && $order->status === \App\Models\Order::STATUS_PENDING_APPROVAL;
            $currency = strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id)));
            $amount = \Juwri\Core\Money\Money::majorFromMinor((int) $order->late_payment_minor, $currency);
            $lapsesSoon = $pending && $order->authorisation_expires_at && $order->authorisation_expires_at->isFuture()
                && $order->authorisation_expires_at->diffInHours(now(), true) <= 24;
        @endphp
        <article class="ui-card ui-disp__item" data-late-payment="{{ $order->id }}" @if($waiting || $pending) data-open @endif @if($lapsesSoon) data-overdue @endif>
            <header class="ui-disp__head">
                <div class="ui-disp__amount">
                    <strong class="ui-ltr">{{ $currency }} {{ number_format($amount, \Juwri\Core\Money\Currencies::exponent($currency)) }}</strong>
                    <span class="ui-disp__reason">{{ ___('Order') }} <span class="ui-ltr">#{{ $order->id }}</span> · {{ $order->name ?: ___('Guest') }}</span>
                </div>
                <x-ui.pill :tone="$waiting ? 'warning' : ($pending ? 'accent' : 'neutral')">{{ $pending ? ___('Accepted, needs a new time') : ($labels[$state] ?? $state) }}</x-ui.pill>
            </header>

            <dl class="ui-disp__facts">
                <div><dt>{{ ___('Paid') }}</dt><dd class="ui-ltr">{{ $order->late_payment_at ? date_formating($order->late_payment_at, 'd M Y, h:i A') : '—' }}</dd></div>
                <div><dt>{{ ___('Window closed') }}</dt><dd class="ui-ltr">{{ $order->payment_expires_at ? date_formating($order->payment_expires_at, 'd M Y, h:i A') : '—' }}</dd></div>
                @if($pending && $order->authorisation_expires_at)
                    <div>
                        <dt>{{ ___('Card hold lapses') }}</dt>
                        <dd><span @class(['ui-disp__due', 'is-soon' => $lapsesSoon])>{{ $order->authorisation_expires_at->diffForHumans() }}</span></dd>
                    </div>
                @endif
            </dl>

            @if($waiting || $pending)
                <div class="ui-disp__actions">
                    @if($waiting)
                        <form method="post" action="{{ route('stores.latePayments.accept', $order->id) }}">
                            @csrf
                            <x-ui.button type="submit" size="sm" icon="check" data-ui-busy>{{ ___('Accept payment') }}</x-ui.button>
                        </form>
                    @else
                        <x-ui.button :href="route('stores.orders.show', $order->id)" variant="secondary" size="sm">{{ ___('Open order') }}</x-ui.button>
                    @endif
                    <form method="post" action="{{ route('stores.latePayments.refund', $order->id) }}" data-confirm="{{ ___('Refund this payment?') }}" data-confirm-danger>
                        @csrf
                        <x-ui.button type="submit" size="sm" variant="secondary" data-ui-busy>{{ ___('Refund') }}</x-ui.button>
                    </form>
                </div>
            @endif
        </article>
    @endforeach
@endif
</div>
@endsection
