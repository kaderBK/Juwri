{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('shop.bookings.layout')
@section('title', ___('Choose a time'))
@section('content')
@php
    $keep = ['services' => $serviceIds, 'location' => $location, 'staff' => $staffId, 'lat' => $coords['lat'], 'lng' => $coords['lng']];
@endphp
<h1>{{ $reschedule ? ___('Choose a new time') : ___('Choose a time') }}</h1>
<p class="card">{{ $serviceLine }}@if($reschedule) · {{ ___('Same price, nothing to pay.') }}@endif</p>

@if($staffChoice)
    <h2 id="staff-h">{{ ___('Staff') }}</h2>
    <nav class="row" aria-labelledby="staff-h">
        <a class="day" href="{{ \Juwri\Core\Support\Views\PublicSiteViewHelpers::bookingsTimesUrl(['staff' => null, 'date' => $date], $keep, $reschedule, $store->slug, request()->route('token')) }}" @if($staffId === null) aria-current="date" @endif>{{ ___('Any staff · Earliest') }}</a>
        @foreach($staffList as $member)
            <a class="day" href="{{ \Juwri\Core\Support\Views\PublicSiteViewHelpers::bookingsTimesUrl(['staff' => $member->id, 'date' => $date], $keep, $reschedule, $store->slug, request()->route('token')) }}" @if($staffId === $member->id) aria-current="date" @endif>{{ $member->name }}@if($member->role)<span class="small">{{ $member->role }}</span>@endif</a>
        @endforeach
    </nav>
@endif

<h2 id="days-h">{{ ___('Day') }}</h2>
<nav class="row" aria-labelledby="days-h">
    @foreach($days as $d)
        @if($d['closed'])
            <span class="day" aria-disabled="true">{{ $d['label'] }} <span class="sr">{{ ___('closed') }}</span></span>
        @else
            <a class="day" href="{{ \Juwri\Core\Support\Views\PublicSiteViewHelpers::bookingsTimesUrl(['date' => $d['date']], $keep, $reschedule, $store->slug, request()->route('token')) }}" @if($d['date'] === $date) aria-current="date" @endif>{{ $d['label'] }}</a>
        @endif
    @endforeach
</nav>

<h2>{{ ___('Times') }}</h2>
<p class="muted small">{{ $tzNote }}</p>
@if($freeCount === 0)
    <div class="card" data-testid="no-times">{{ ___('No free times on this day. Try another day or any staff member.') }}</div>
@else
    @if($freeCount <= 3)<p class="warn small">{{ ___('Only :count times left on this day.', ['count' => $freeCount]) }}</p>@endif
    @foreach($groups as $part => $slots)
        <h3>{{ $part }}</h3>
        <div class="row">
            @foreach($slots as $slot)
                @if($slot['free'])
                    <form method="post" action="{{ $action }}">
                        @csrf
                        @unless($reschedule)
                            @foreach($serviceIds as $sid)<input type="hidden" name="services[]" value="{{ $sid }}">@endforeach
                            <input type="hidden" name="location" value="{{ $location }}">
                            @if($coords['lat'] !== null)<input type="hidden" name="lat" value="{{ $coords['lat'] }}"><input type="hidden" name="lng" value="{{ $coords['lng'] }}">@endif
                        @endunless
                        @if($staffId)<input type="hidden" name="staff_id" value="{{ $staffId }}">@endif
                        <input type="hidden" name="starts_at" value="{{ $slot['ts'] }}">
                        <button type="submit" class="slot" aria-label="{{ $slot['range'] }}">{{ $slot['label'] }}</button>
                    </form>
                @else
                    <span class="btn slot" aria-disabled="true">{{ $slot['label'] }} <span class="sr">{{ ___('taken') }}</span></span>
                @endif
            @endforeach
        </div>
    @endforeach
    <p class="muted small">{{ ___('The time you pick is held for 10 minutes while you add your details.') }}</p>
@endif
@endsection
