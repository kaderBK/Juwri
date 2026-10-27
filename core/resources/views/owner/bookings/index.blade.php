{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Bookings'))
@section('page_title', ___('Bookings'))
@section('page_sub', ___('Appointments with your staff, deposits and the setup of your booking page.'))

@section('page_actions')
    @include('owner.bookings._store-switch')
@endsection

@section('content')
<div class="ui-scope ui-stack">
    @include('owner.bookings._nav')

    @if(! $open)
        <x-ui.card :title="___('Set up bookings')">
            <p class="ui-muted">{{ ___('Customers can book once these steps are done. Until then your booking page says "Bookings open soon".') }}</p>
            <ul class="ui-stack" data-testid="booking-checklist">
                <li>@if($checklist['hours'])<x-ui.tag tone="success">{{ ___('Done') }}</x-ui.tag>@else<x-ui.tag tone="warning">{{ ___('To do') }}</x-ui.tag>@endif
                    <a href="{{ route('bookings.owner.hours', ['store_id' => $store->id]) }}">{{ ___('Set your hours') }}</a></li>
                <li>@if($checklist['services'])<x-ui.tag tone="success">{{ ___('Done') }}</x-ui.tag>@else<x-ui.tag tone="warning">{{ ___('To do') }}</x-ui.tag>@endif
                    <a href="{{ route('bookings.owner.services', ['store_id' => $store->id]) }}">{{ ___('Add your services') }}</a></li>
                <li>@if($checklist['staff'])<x-ui.tag tone="success">{{ ___('Done') }}</x-ui.tag>@else<x-ui.tag tone="warning">{{ ___('To do') }}</x-ui.tag>@endif
                    <a href="{{ route('bookings.owner.staff', ['store_id' => $store->id]) }}">{{ ___('Add staff and the services they do') }}</a></li>
                @unless($checklist['plan'])
                    <li><x-ui.tag tone="danger">{{ ___('Not in your plan') }}</x-ui.tag> {{ ___('Online bookings are not included in your current plan.') }}</li>
                @endunless
            </ul>
        </x-ui.card>
    @else
        <x-ui.banner tone="success">{{ ___('Your booking page is open.') }} <a href="{{ $storefrontUrl }}">{{ $storefrontUrl }}</a></x-ui.banner>
    @endif

    @if($late->isNotEmpty())
        <x-ui.card :title="___('Late payments to resolve')" variant="flush">
            <p class="ui-muted">{{ ___('These deposits arrived after the payment time ended, so the booking was cancelled. Accept the payment or refund it.') }}</p>
            @include('owner.bookings._table', ['rows' => $late, 'actions' => ['accept_late' => ___('Accept'), 'refund_late' => ___('Refund')]])
        </x-ui.card>
    @endif

    @if($refunds->isNotEmpty())
        <x-ui.card :title="___('Deposit refunds to retry')" variant="flush" data-testid="deposit-refunds">
            <p class="ui-muted">{{ ___('These bookings were cancelled, but the payment provider refused the deposit refund, so the customer has not been paid back yet. Retry the refund, or refund it in your payment provider.') }}</p>
            @include('owner.bookings._table', ['rows' => $refunds, 'actions' => ['retry_refund' => ___('Retry refund')]])
        </x-ui.card>
    @endif

    <x-ui.card :title="___('Upcoming')" variant="flush">
        @if($upcoming->isEmpty())
            <x-ui.empty :title="___('No upcoming bookings.')" icon="calendar" />
        @else
            @include('owner.bookings._table', ['rows' => $upcoming, 'actions' => ['complete' => ___('Done'), 'no_show' => ___('No-show'), 'cancel' => ___('Cancel')]])
        @endif
    </x-ui.card>

    <x-ui.card :title="___('Past and cancelled')" variant="flush">
        @if($past->isEmpty())
            <x-ui.empty :title="___('Nothing here yet.')" icon="calendar" />
        @else
            @include('owner.bookings._table', ['rows' => $past, 'actions' => []])
        @endif
    </x-ui.card>
</div>
@endsection
