{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('shop.bookings.layout')
@section('title', ___('Your details'))
@section('content')
<h1>{{ ___('Confirm your booking') }}</h1>
<div class="card">
    <h2>{{ ___('Appointment') }}</h2>
    <dl>
        <dt>{{ ___('Date') }}</dt><dd>{{ $summary['date'] }}</dd>
        <dt>{{ ___('Time') }}</dt><dd><span dir="ltr">{{ $summary['time'] }}</span> · {{ $summary['duration'] }}</dd>
        <dt>{{ ___('Services') }}</dt><dd>{{ $summary['services'] }}</dd>
        @if($summary['staff'] !== '')<dt>{{ ___('With') }}</dt><dd>{{ $summary['staff'] }}</dd>@endif
    </dl>
    <p class="muted small">{{ $tzNote }} {{ ___('Held for you until :time.', ['time' => $expiresAt]) }}</p>
</div>

<form method="post" action="{{ route('bookings.book', ['slug' => $store->slug]) }}" class="card">
    @csrf
    <input type="hidden" name="hold" value="{{ $hold->public_id }}">
    <input type="hidden" name="client_key" value="{{ $clientKey }}">
    <h2>{{ ___('Your details') }}</h2>
    <label for="bk-name">{{ ___('Name') }}</label>
    <input id="bk-name" type="text" name="name" value="{{ old('name') }}" required maxlength="80" autocomplete="name">
    <label for="bk-phone">{{ ___('Mobile number') }}</label>
    <input id="bk-phone" type="tel" name="phone" value="{{ old('phone') }}" required maxlength="30" dir="ltr" inputmode="tel" autocomplete="tel">
    <label for="bk-email">{{ ___('Email (optional)') }}</label>
    <input id="bk-email" type="email" name="email" value="{{ old('email') }}" maxlength="120" autocomplete="email">
    @if($atHome)
        <label for="bk-address">{{ ___('Address for the visit') }}</label>
        <input id="bk-address" type="text" name="address" value="{{ old('address') }}" required maxlength="255" autocomplete="street-address">
        <input type="hidden" name="lat" value="{{ old('lat', $hold->location === 'at_home' ? request('lat', $coords['lat']) : '') }}">
        <input type="hidden" name="lng" value="{{ old('lng', $hold->location === 'at_home' ? request('lng', $coords['lng']) : '') }}">
    @endif
    <label for="bk-note">{{ ___('Note for the business (optional)') }}</label>
    <textarea id="bk-note" name="note" maxlength="200" rows="2">{{ old('note') }}</textarea>

    @if($bk->reminderEmail || $bk->reminderSms || $bk->reminderWhatsApp)
        <fieldset>
            <legend>{{ ___('Send me a reminder the day before') }}</legend>
            @if($bk->reminderEmail)<label class="check"><input type="checkbox" name="remind_email" value="1"> {{ ___('By email') }}</label>@endif
            @if($bk->reminderSms)<label class="check"><input type="checkbox" name="remind_sms" value="1"> {{ ___('By SMS') }}</label>@endif
            @if($bk->reminderWhatsApp)<label class="check"><input type="checkbox" name="remind_whatsapp" value="1"> {{ ___('On WhatsApp') }}</label>@endif
        </fieldset>
    @endif

    <h2>{{ ___('Summary') }}</h2>
    <dl>
        <dt>{{ ___('Total') }}</dt><dd>{{ $total }}</dd>
        @if($deposit)
            <dt>{{ ___('Due now') }}</dt><dd>{{ $deposit }}</dd>
            <dt>{{ ___('Due at the appointment') }}</dt><dd>{{ $dueLater }}</dd>
        @endif
    </dl>
    <p class="card small" data-testid="policy">{{ $policy }}</p>
    @if($bk->policyNote !== '')<p class="muted small">{{ $bk->policyNote }}</p>@endif
    <button type="submit">{{ $deposit ? ___('Confirm and pay :amount', ['amount' => $deposit]) : ___('Confirm booking') }}</button>
</form>
<form method="post" action="{{ route('bookings.release', ['slug' => $store->slug, 'hold' => $hold->public_id]) }}">
    @csrf
    <button type="submit" class="btn-outline">{{ ___('Choose another time') }}</button>
</form>
@endsection
