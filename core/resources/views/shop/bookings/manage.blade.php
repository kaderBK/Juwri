{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('shop.bookings.layout')
@section('title', ___('Your booking'))
@section('content')
@php
    $labels = [
        'confirmed' => ___('Confirmed'), 'pending_payment' => ___('Waiting for deposit'), 'pending_approval' => ___('Pending approval'),
        'cancelled' => ___('Cancelled'), 'completed' => ___('Done'), 'no_show' => ___('No-show'),
    ];
    $deposits = ['paid' => ___('Deposit paid'), 'refunded' => ___('Deposit refunded'), 'kept' => ___('Deposit kept'), 'pending' => ___('Deposit due'), 'received_late' => ___('Your payment arrived late. The business will contact you.')];
@endphp
<div role="status" data-testid="booking-status" data-status="{{ $booking->status }}">
    <p><strong>{{ $labels[$booking->status] ?? $booking->status }}</strong> · <bdi>{{ $booking->number }}</bdi></p>
    @if($booking->status === 'confirmed')<h1>{{ ___('You\'re booked, :name', ['name' => $booking->customer_name]) }}</h1>@endif
</div>
<div class="ticket">
    <div>{{ $summary['date'] }}</div>
    <div class="big" dir="ltr">{{ $summary['time'] }}</div>
    <div>{{ $summary['duration'] }} · {{ $summary['services'] }}@if($summary['staff'] !== '') · {{ ___('With') }} {{ $summary['staff'] }}@endif</div>
</div>
<p class="muted small">{{ $tzNote }}</p>

@if($booking->status === 'pending_payment')
    <p><a class="btn" href="{{ route('bookings.pay', ['slug' => $store->slug, 'token' => $token]) }}">{{ ___('Pay the deposit') }}</a></p>
@endif

@if(in_array($booking->status, ['confirmed', 'pending_payment'], true))
    <div class="row">
        <a class="btn btn-outline" href="{{ route('bookings.calendar', ['slug' => $store->slug, 'token' => $token]) }}">{{ ___('Add to calendar') }}</a>
        <a class="btn btn-outline" href="{{ $googleUrl }}" rel="noopener" target="_blank">{{ ___('Google Calendar') }}</a>
        <a class="btn btn-outline" href="{{ $mapsUrl }}" rel="noopener" target="_blank">{{ ___('Directions') }}</a>
    </div>
@endif

<div class="card">
    <dl>
        <dt>{{ ___('Location') }}</dt><dd>{{ $booking->location === 'at_home' ? $booking->address : $store->address }}</dd>
        <dt>{{ ___('Total') }}</dt><dd>{{ $total }}</dd>
        @if($deposit)<dt>{{ ___('Deposit') }}</dt><dd>{{ $deposit }}@if(! empty($refundPending)) · <span data-testid="refund-pending">{{ ___('Your deposit refund is being processed.') }}</span>@elseif(isset($deposits[$booking->deposit_status])) · {{ $deposits[$booking->deposit_status] }}@endif</dd>@endif
    </dl>
</div>

@if($canCancel)
    <div class="card">
        <h2>{{ ___('Manage') }}</h2>
        <p class="small" data-testid="policy">{{ $policy }}</p>
        <div class="row">
            @if($canChange)<a class="btn btn-outline" href="{{ route('bookings.rescheduleTimes', ['slug' => $store->slug, 'token' => $token]) }}">{{ ___('Reschedule') }}</a>@endif
            <form method="post" action="{{ route('bookings.cancel', ['slug' => $store->slug, 'token' => $token]) }}">
                @csrf
                <button type="submit" class="btn-danger">{{ ___('Cancel booking') }}</button>
            </form>
        </div>
    </div>
@endif
@endsection
