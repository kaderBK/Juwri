{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Bookings'))
@section('page_title', ___('Bookings'))
@section('page_sub', $store->title.' - '.___('Reservations, arrivals and departures.'))

@php
    // Lane A's controller supplies these; defaults keep the blade renderable
    // pre-merge and defensive if a key is ever missing.
    $bookings      = $bookings ?? collect();
    $roomTypes     = $roomTypes ?? collect();
    $ratePlans     = $ratePlans ?? collect();
    $rooms         = $rooms ?? collect();
    $status        = $status ?? '';
    $filterCheckIn = $filterCheckIn ?? '';
    $filterTypeId  = $filterTypeId ?? '';
    $currency      = ($currency ?? '').' ';

    $tabs = [
        ''           => ___('All'),
        'pending'    => ___('Pending'),
        'confirmed'  => ___('Confirmed'),
        'checked_in' => ___('In-house'),
        'upcoming'   => ___('Upcoming'),
        'past'       => ___('Past'),
    ];
    $statusTones = [
        'pending' => 'warning', 'confirmed' => 'accent',
        'checked_in' => 'success', 'checked_out' => 'neutral',
        'cancelled' => 'neutral', 'no_show' => 'danger',
    ];
    $hasBackend = Route::has('hotel.pms.bookings');
@endphp

@if($hasBackend)
@section('page_actions')
    <x-ui.button :href="route('hotel.pms.calendar')" variant="secondary" icon="calendar">{{ ___('Calendar') }}</x-ui.button>
    <x-ui.button type="button" icon="plus" x-data @click="$dispatch('booking-new')">{{ ___('New booking') }}</x-ui.button>
@endsection
@endif

@section('content')
@unless($hasBackend)
    {{-- Pre-merge fallback: lane A's PMS routes are not registered yet. --}}
    <x-ui.empty :title="___('PMS backend pending')" :body="___('The booking engine is being set up. Reservations, availability and the calendar appear here once it is enabled.')" />
@else
<div class="ui-stack" x-data="{ showNew: false }" @booking-new.window="showNew = !showNew">

    {{-- New booking form (revealed on demand) --}}
    <div class="ui-card" x-show="showNew" x-cloak>
        <div class="ui-cluster ui-cluster--between ui-card__head">
            <h3 class="ui-section-title">{{ ___('New booking') }}</h3>
            <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="showNew = false" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
        </div>
        <div class="ui-card__body">
            @include('owner.hotel.booking-form', [
                'action' => route('hotel.pms.bookings.store'),
                'booking' => null,
                'roomTypes' => $roomTypes,
                'ratePlans' => $ratePlans,
                'rooms' => $rooms,
                'currency' => trim($currency),
                'availUrl' => Route::has('hotel.pms.availability') ? route('hotel.pms.availability') : null,
            ])
        </div>
    </div>

    {{-- Status tabs (plain GET links — CSP-clean, server-filtered) --}}
    <div class="ui-card">
        <div class="ui-card__head">
            <ul class="ui-chips nav-pills-sm">
                @foreach($tabs as $key => $label)
                    <li>
                        <a class="ui-chip {{ $status === $key ? 'active' : '' }}"
                           href="{{ route('hotel.pms.bookings', array_filter(['status' => $key, 'check_in' => $filterCheckIn, 'room_type_id' => $filterTypeId])) }}">{{ $label }}</a>
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Date + room-type filters --}}
        <div class="ui-card__body ui-rule-bottom">
            <form method="get" action="{{ route('hotel.pms.bookings') }}" class="ui-form-grid">
                <input type="hidden" name="status" value="{{ $status }}">
                <div>
                    <label class="ui-label ui-small">{{ ___('Arriving on') }}</label>
                    <input type="date" name="check_in" value="{{ $filterCheckIn }}" class="ui-input ui-input--sm">
                </div>
                <div>
                    <label class="ui-label ui-small">{{ ___('Room type') }}</label>
                    <select name="room_type_id" class="ui-input ui-input--sm">
                        <option value="">{{ ___('All types') }}</option>
                        @foreach($roomTypes as $t)
                            <option value="{{ $t->id }}" @selected((int) $filterTypeId === (int) $t->id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-ui.button type="submit" variant="secondary" size="sm" icon="search">{{ ___('Filter') }}</x-ui.button>
                </div>
            </form>
        </div>

        @if($bookings->isEmpty())
            <div class="ui-card__body">
                <x-ui.empty :title="___('No bookings')" :body="___('Create a booking or adjust your filters.')" />
            </div>
        @else
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('Ref') }}</th>
                        <th>{{ ___('Guest') }}</th>
                        <th>{{ ___('Type') }}</th>
                        <th>{{ ___('Dates') }}</th>
                        <th class="ui-end">{{ ___('Nights') }}</th>
                        <th>{{ ___('Room') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th class="ui-end">{{ ___('Total') }}</th>
                        <th class="ui-end">{{ ___('Actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach($bookings as $b)
                            @php
                                $ci = $b->check_in ? \Illuminate\Support\Carbon::parse($b->check_in) : null;
                                $co = $b->check_out ? \Illuminate\Support\Carbon::parse($b->check_out) : null;
                                $nights = ($ci && $co) ? max(0, $ci->diffInDays($co)) : 0;
                            @endphp
                            <tr>
                                <td data-label="{{ ___('Ref') }}"><strong>{{ $b->code }}</strong></td>
                                <td data-label="{{ ___('Guest') }}">{{ $b->guest_name }}<div class="ui-muted">{{ $b->guest_phone }}</div></td>
                                <td data-label="{{ ___('Type') }}">{{ $b->roomType->name ?? '—' }}</td>
                                <td class="ui-small" data-label="{{ ___('Dates') }}">{{ optional($ci)->format('d M') }} → {{ optional($co)->format('d M') }}</td>
                                <td class="ui-end" data-label="{{ ___('Nights') }}">{{ $nights }}</td>
                                <td data-label="{{ ___('Room') }}">{{ ($b->room->table_no ?? null) ? '#'.$b->room->table_no : '—' }}</td>
                                <td data-label="{{ ___('Status') }}"><span class="ui-tag ui-capitalize" data-tone="{{ $statusTones[$b->status] ?? 'neutral' }}">{{ ___(str_replace('_', ' ', $b->status)) }}</span></td>
                                <td class="ui-end" data-label="{{ ___('Total') }}">{{ $b->quoted_total !== null ? ($b->currency ? $b->currency.' ' : $currency).number_format((float) $b->quoted_total, 2) : '—' }}</td>
                                <td class="ui-end" data-label="{{ ___('Actions') }}">
                                    @if(Route::has('hotel.pms.bookings.show'))
                                        <x-ui.button :href="route('hotel.pms.bookings.show', $b->id)" variant="ghost" size="sm" icon="external">{{ ___('View') }}</x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if(method_exists($bookings, 'hasPages') && $bookings->hasPages())
                <div class="ui-card__body ui-rule-top">{{ $bookings->withQueryString()->links() }}</div>
            @endif
        @endif
    </div>
</div>
@endunless
@endsection
