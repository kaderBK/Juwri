{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@php
    // Lane A supplies $booking (object) + option selects; defaults are defensive.
    $booking   = $booking ?? (object) [];
    $roomTypes = $roomTypes ?? collect();
    $ratePlans = $ratePlans ?? collect();
    $rooms     = $rooms ?? collect();
    $currency  = ($currency ?? '').' ';
    $hkWarning = $hkWarning ?? session('hk_warning');

    $status  = $booking->status ?? 'pending';
    $ci      = ($booking->check_in ?? null) ? \Illuminate\Support\Carbon::parse($booking->check_in) : null;
    $co      = ($booking->check_out ?? null) ? \Illuminate\Support\Carbon::parse($booking->check_out) : null;
    $nights  = ($ci && $co) ? max(0, $ci->diffInDays($co)) : 0;
    $stayId  = $booking->hotel_stay_id ?? null;

    // Lifecycle timeline (terminal states shown inline).
    $flow = ['pending' => ___('Pending'), 'confirmed' => ___('Confirmed'), 'checked_in' => ___('Checked in'), 'checked_out' => ___('Checked out')];
    $flowKeys = array_keys($flow);
    $curIdx = array_search($status, $flowKeys, true);
    $isTerminalBad = in_array($status, ['cancelled', 'no_show'], true);
    $preCheckIn = in_array($status, ['pending', 'confirmed'], true);

    // A money-adjacent action form: hidden uuid so a double-submit acts once.
@endphp

@section('title', ___('Booking').' '.($booking->code ?? ''))
@section('page_title', ___('Booking').' · '.($booking->code ?? ''))
@section('page_sub', ($booking->guest_name ?? '').' · '.___(str_replace('_', ' ', $status)))

@section('page_actions')
    @if(Route::has('hotel.pms.bookings'))
        <x-ui.button :href="route('hotel.pms.bookings')" variant="ghost" icon="back">{{ ___('All bookings') }}</x-ui.button>
    @endif
    @if($stayId && Route::has('hotel.stays.show'))
        <x-ui.button :href="route('hotel.stays.show', $stayId)" variant="secondary" icon="orders">{{ ___('Open folio') }}</x-ui.button>
    @endif
@endsection

@section('content')
<div class="ui-form-grid">
    <div class="ui-stack">
        @if($hkWarning)
            <div class="ui-cluster ui-note" data-tone="warning">
                <x-ui.icon name="alert" size="18" />
                <span>{{ $hkWarning }}</span>
            </div>
        @endif

        {{-- Status timeline --}}
        <div class="ui-card">
            <div class="ui-cluster ui-card__body">
                @if($isTerminalBad)
                    <span class="ui-tag ui-capitalize" data-tone="{{ $status === 'no_show' ? 'danger' : 'neutral' }}">{{ ___(str_replace('_', ' ', $status)) }}</span>
                @else
                    @foreach($flow as $key => $label)
                        @php $done = $curIdx !== false && array_search($key, $flowKeys, true) <= $curIdx; @endphp
                        <span class="ui-tag" data-tone="{{ $done ? 'accent' : 'neutral' }}">{{ $label }}</span>
                        @if(!$loop->last)<x-ui.icon name="chevron" size="14" class="ui-text-2" />@endif
                    @endforeach
                @endif
            </div>
        </div>

        {{-- Detail card --}}
        <div class="ui-card">
            <div class="ui-card__head"><h3 class="ui-section-title">{{ ___('Reservation') }}</h3></div>
            <div class="ui-card__body">
                <dl class="ui-form-grid">
                    <dt>{{ ___('Guest') }}</dt><dd>{{ $booking->guest_name ?? '—' }} <span class="ui-muted">{{ $booking->guest_phone ?? '' }}</span></dd>
                    <dt>{{ ___('Room type') }}</dt><dd>{{ $booking->roomType->name ?? '—' }}</dd>
                    <dt>{{ ___('Rate plan') }}</dt><dd>{{ $booking->ratePlan->name ?? ___('Standard') }}</dd>
                    <dt>{{ ___('Room') }}</dt><dd>{{ ($booking->room->table_no ?? null) ? '#'.$booking->room->table_no.(($booking->room->label ?? '') ? ' · '.$booking->room->label : '') : ___('Not assigned') }}</dd>
                    <dt>{{ ___('Dates') }}</dt><dd>{{ optional($ci)->format('d M Y') }} → {{ optional($co)->format('d M Y') }} <span class="ui-muted">({{ $nights }} {{ ___('nights') }})</span></dd>
                    <dt>{{ ___('Occupancy') }}</dt><dd>{{ $booking->adults ?? 0 }} {{ ___('adults') }}, {{ $booking->children ?? 0 }} {{ ___('children') }}</dd>
                    <dt>{{ ___('Quoted total') }}</dt><dd><strong>{{ ($booking->quoted_total ?? null) !== null ? (($booking->currency ?? '') ? $booking->currency.' ' : $currency).number_format((float) $booking->quoted_total, 2) : '—' }}</strong></dd>
                    @if($booking->notes ?? null)
                        <dt>{{ ___('Notes') }}</dt><dd>{{ $booking->notes }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        {{-- Edit / assign room (availability-aware) — pre-check-in only. --}}
        @if($preCheckIn && Route::has('hotel.pms.bookings.update'))
        <div class="ui-card" x-data="{ edit: false }">
            <div class="ui-cluster ui-cluster--between ui-card__head">
                <h3 class="ui-section-title">{{ ___('Edit / assign room') }}</h3>
                <x-ui.button type="button" variant="ghost" size="sm" icon="pencil" @click="edit = !edit">{{ ___('Edit') }}</x-ui.button>
            </div>
            <div class="ui-card__body" x-show="edit" x-cloak>
                @include('owner.hotel.booking-form', [
                    'action' => route('hotel.pms.bookings.update', $booking->id),
                    'booking' => $booking,
                    'roomTypes' => $roomTypes,
                    'ratePlans' => $ratePlans,
                    'rooms' => $rooms,
                    'currency' => trim($currency),
                    'availUrl' => Route::has('hotel.pms.availability') ? route('hotel.pms.availability') : null,
                ])
            </div>
        </div>
        @endif
    </div>

    {{-- Lifecycle actions --}}
    <div class="ui-stack">
        <div class="ui-card">
            <div class="ui-card__head"><h4 class="ui-section-title">{{ ___('Actions') }}</h4></div>
            <div class="ui-card__body ui-stack">
                @if($status === 'pending' && Route::has('hotel.pms.bookings.confirm'))
                    <form action="{{ route('hotel.pms.bookings.confirm', $booking->id) }}" method="post">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::bookingUuid() }}">
                        <x-ui.button type="submit" icon="check" class="ui-w-full">{{ ___('Confirm booking') }}</x-ui.button>
                    </form>
                @endif

                @if($status === 'confirmed' && Route::has('hotel.pms.bookings.checkin'))
                    <form action="{{ route('hotel.pms.bookings.checkin', $booking->id) }}" method="post">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::bookingUuid() }}">
                        <x-ui.button type="submit" variant="success" icon="back" class="ui-w-full">{{ ___('Check in') }}</x-ui.button>
                    </form>
                @endif

                @if($status === 'confirmed' && Route::has('hotel.pms.bookings.noshow'))
                    <form action="{{ route('hotel.pms.bookings.noshow', $booking->id) }}" method="post" data-confirm="{{ ___('Mark this booking as a no-show?') }}">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::bookingUuid() }}">
                        <x-ui.button type="submit" variant="secondary" icon="users" class="ui-w-full">{{ ___('Mark no-show') }}</x-ui.button>
                    </form>
                @endif

                @if($preCheckIn && Route::has('hotel.pms.bookings.cancel'))
                    <form action="{{ route('hotel.pms.bookings.cancel', $booking->id) }}" method="post" data-confirm="{{ ___('Cancel this booking?') }}" data-confirm-danger>
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::bookingUuid() }}">
                        <x-ui.button type="submit" variant="danger" icon="error" class="ui-w-full">{{ ___('Cancel booking') }}</x-ui.button>
                    </form>
                @endif

                @if(in_array($status, ['checked_in', 'checked_out'], true))
                    @if($stayId && Route::has('hotel.stays.show'))
                        <x-ui.button :href="route('hotel.stays.show', $stayId)" variant="secondary" icon="orders" class="ui-w-full">{{ ___('Open folio') }}</x-ui.button>
                    @else
                        <p class="ui-muted">{{ ___('This booking has been checked in.') }}</p>
                    @endif
                @endif

                @if($isTerminalBad)
                    <p class="ui-muted">{{ ___('This booking is closed and has no further actions.') }}</p>
                @endif
            </div>
        </div>

        <div class="ui-card">
            <div class="ui-muted ui-card__body">
                {{ ___('Source') }}: <span class="ui-capitalize">{{ ___($booking->source ?? 'desk') }}</span>
            </div>
        </div>
    </div>
</div>
@endsection
