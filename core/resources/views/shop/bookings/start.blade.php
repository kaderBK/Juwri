{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('shop.bookings.layout')
@section('title', ___('Book an appointment'))
@section('content')
<h1>{{ ___('Book an appointment') }}</h1>
@if(! $open)
    <div class="card" data-testid="bookings-open-soon">
        <h2>{{ ___('Bookings open soon') }}</h2>
        <p class="muted">{{ ___('This business is not taking online bookings yet. Please check back later.') }}</p>
    </div>
@elseif($services->isEmpty())
    <p class="muted">{{ ___('Nothing here yet.') }}</p>
@else
    <form method="get" action="{{ route('bookings.times', ['slug' => $store->slug]) }}">
        @if($bk->atHome)
            <fieldset class="card">
                <legend>{{ ___('Location') }}</legend>
                <label class="check"><input type="radio" name="location" value="venue" checked> {{ ___('At our place') }}</label>
                <label class="check"><input type="radio" name="location" value="at_home"> {{ ___('At home') }}</label>
                <p class="muted small">{{ ___('For a visit at home, add your location:') }}</p>
                <div class="row">
                    <label for="bk-lat" class="sr">{{ ___('Latitude') }}</label><input id="bk-lat" type="number" step="any" name="lat" placeholder="{{ ___('Latitude') }}">
                    <label for="bk-lng" class="sr">{{ ___('Longitude') }}</label><input id="bk-lng" type="number" step="any" name="lng" placeholder="{{ ___('Longitude') }}">
                </div>
            </fieldset>
        @endif
        <fieldset class="card">
            <legend>{{ ___('Choose up to 4 services') }}</legend>
            @foreach($services as $s)
                <label class="check">
                    <input type="checkbox" name="services[]" value="{{ $s['id'] }}">
                    <span><strong>{{ $s['name'] }}</strong><br><span class="muted small">{{ $s['meta'] }}@if($s['at_home'] && ! $s['venue']) · {{ ___('At home only') }}@endif</span>
                    @if($s['description'])<br><span class="small">{{ \Illuminate\Support\Str::limit($s['description'], 160) }}</span>@endif</span>
                </label>
            @endforeach
        </fieldset>
        @if($bk->policyNote !== '')<p class="muted small">{{ $bk->policyNote }}</p>@endif
        <button type="submit">{{ ___('Choose a time') }}</button>
    </form>
@endif
@endsection
