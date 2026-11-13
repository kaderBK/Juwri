{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('shop.bookings.layout')
@section('title', ___('Pay the deposit'))
@section('content')
<h1>{{ ___('Pay the deposit') }}</h1>
<div class="card">
    <p><bdi>{{ $booking->number }}</bdi> · {{ ___('Deposit') }}: <strong>{{ $deposit }}</strong></p>
    @if($expiresAt)<p class="muted small">{{ ___('Your time is kept until :time. After that the booking is cancelled.', ['time' => $expiresAt]) }}</p>@endif
</div>
@if($gateways->isEmpty())
    <div class="alert alert-bad">{{ ___('Online payment is not available at this business right now.') }}</div>
@else
    <form method="post" action="{{ route('bookings.charge', ['slug' => $store->slug, 'token' => $token]) }}" class="card">
        @csrf
        <fieldset>
            <legend>{{ ___('Payment method') }}</legend>
            @foreach($gateways as $i => $g)
                <label class="check"><input type="radio" name="payment_method" value="{{ $g->id }}" @checked($i === 0)> {{ $g->payment_title }}</label>
            @endforeach
        </fieldset>
        <button type="submit">{{ ___('Pay :amount', ['amount' => $deposit]) }}</button>
    </form>
@endif
<p><a href="{{ route('bookings.manage', ['slug' => $store->slug, 'token' => $token]) }}">{{ ___('View booking') }}</a></p>
@endsection
