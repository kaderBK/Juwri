{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Theme Studio B0.7 — {% widget 'reservation' %}. Table booking request, posted
    to the SAME public endpoint the Blade themes use (publicView.reservation.book,
    ReservationController@publicBook), which redirects back with the
    `reservation_success` flash or to the deposit step. WidgetTag gates it on
    features.reservations (= postOptions.enable_reservations, as crave does).
--}}
@php
    $mbwId = 'mbw-reservation';
    $mbwErrors = isset($errors) && old('_mbw') === 'reservation' ? $errors : null;
    $mbwDone = session('reservation_success');
@endphp
@if($widgetBase)@include('store_templates._partials.widgets._base')@endif
<button type="button" class="mbw-btn mbw-reservation__trigger" data-mbw-open="reservation" aria-haspopup="dialog" aria-controls="{{ $mbwId }}-dialog">
    <svg class="mbw-ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
    <span>{{ $widgetLabel ?? ___('Reserve a Table') }}</span>
</button>
@if($widgetFirst)
<dialog class="mbw-dialog mbw-reservation" id="{{ $mbwId }}-dialog" aria-labelledby="{{ $mbwId }}-title" @if($mbwDone || ($mbwErrors && $mbwErrors->any())) data-mbw-autoopen @endif>
    @include('store_templates._partials.widgets._head', ['mbwTitle' => ___('Reserve a Table')])
    <div class="mbw-body">
        @if($mbwDone)
            <div class="mbw-done" role="status">
                <b>{{ ___('Request received!') }}</b>
                <p>{{ $mbwDone }}</p>
                <p>{{ ___('We\'ll confirm your booking shortly.') }}</p>
            </div>
        @else
            @if($mbwErrors && $mbwErrors->any())
                <p class="mbw-alert" role="alert">{{ $mbwErrors->first() }}</p>
            @endif
            <form class="mbw-form" action="{{ route('publicView.reservation.book', $store->slug) }}" method="POST">
                @csrf
                <input type="hidden" name="_mbw" value="reservation">
                <label class="mbw-field">{{ ___('Your Name') }}
                    <input class="mbw-input" name="name" required maxlength="80" autocomplete="name" value="{{ old('name', $customer->name ?? '') }}">
                </label>
                <label class="mbw-field">{{ ___('Phone number') }}
                    <input class="mbw-input" name="phone" maxlength="30" inputmode="tel" autocomplete="tel" dir="ltr" value="{{ old('phone', $customer->phone ?? '') }}">
                </label>
                <label class="mbw-field">{{ ___('Email') }}
                    <input class="mbw-input" name="email" type="email" maxlength="120" autocomplete="email" dir="ltr" value="{{ old('email', $customer->email ?? '') }}">
                </label>
                <label class="mbw-field">{{ ___('Date & Time') }}
                    <input class="mbw-input" name="reserved_at" type="datetime-local" required min="{{ now()->format('Y-m-d\TH:i') }}" value="{{ old('reserved_at') }}">
                </label>
                <label class="mbw-field">{{ ___('Party Size') }}
                    <input class="mbw-input" name="party_size" type="number" min="1" max="50" required inputmode="numeric" value="{{ old('party_size', 2) }}">
                </label>
                <label class="mbw-field">{{ ___('Note') }} ({{ ___('optional') }})
                    <textarea class="mbw-input" name="notes" rows="2" maxlength="500" placeholder="{{ ___('Any special requests?') }}">{{ old('notes') }}</textarea>
                </label>
                @include('store_templates._partials.reservation-deposit-notice', ['rid' => $store->id])
                <button class="mbw-btn mbw-submit" type="submit">{{ ___('Request Reservation') }}</button>
            </form>
        @endif
    </div>
</dialog>
@endif
