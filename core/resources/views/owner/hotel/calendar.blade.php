{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Calendar'))
@section('page_title', ___('Occupancy calendar'))
@section('page_sub', $store->title.' - '.___('Room availability across the next two weeks.'))

@php
    // Lane A's controller supplies these; defaults keep the blade renderable
    // pre-merge / defensive.
    //   $days       list<string Y-m-d>  the window columns (14 nights)
    //   $rooms      iterable of plain objects: id, table_no, label, room_type_name
    //   $occupancy  array[room_id][Y-m-d] => null (free)
    //                 | ['type'=>'booked','status'=>..,'booking_id'=>..,'code'=>..,'guest'=>..]
    //                 | ['type'=>'ooo']
    //   $windowStart string Y-m-d — first column (drives prev/next nav)
    $days        = $days ?? [];
    $rooms       = collect($rooms ?? []);
    $occupancy   = $occupancy ?? [];
    $windowStart = $windowStart ?? ($days[0] ?? now()->toDateString());

    $start   = \Illuminate\Support\Carbon::parse($windowStart);
    $span    = max(1, count($days)) ?: 14;
    $prev    = $start->copy()->subDays($span)->toDateString();
    $next    = $start->copy()->addDays($span)->toDateString();

    // Booking status → owner UI kit tone (colours live in assets/owner-ui/screens.css).
    $statusTone = ['pending' => 'warning', 'confirmed' => 'accent', 'checked_in' => 'success', 'checked_out' => 'neutral'];
    $byType = \Juwri\Core\Support\Views\OwnerOpsViewHelpers::groupByRoomTypeName($rooms);
    $hasBackend = Route::has('hotel.pms.calendar');
@endphp

@if($hasBackend)
@section('page_actions')
    <div class="ui-cal-nav">
        <x-ui.icon-button :href="route('hotel.pms.calendar', ['start' => $prev])" icon="back" :label="___('Previous')" class="ui-cal-nav__arrow" />
        <x-ui.button :href="route('hotel.pms.calendar', ['start' => now()->toDateString()])" variant="secondary">{{ ___('Today') }}</x-ui.button>
        <x-ui.icon-button :href="route('hotel.pms.calendar', ['start' => $next])" icon="chevron" :label="___('Next')" class="ui-cal-nav__arrow" />
    </div>
@endsection
@endif

@section('content')
<div class="ui-scope ui-cal">
@unless($hasBackend)
    <div class="ui-card">
        <x-ui.empty :title="___('PMS backend pending')" :body="___('The occupancy calendar appears here once the booking engine is enabled.')" icon="calendar" />
    </div>
@else
    <section class="ui-card ui-card--flush">
        <div class="ui-card__head ui-cal__head">
            <h2 class="ui-card__title">{{ $start->translatedFormat('d M') }} – {{ \Illuminate\Support\Carbon::parse(end($days) ?: $windowStart)->translatedFormat('d M Y') }}</h2>
            <div class="ui-cal__legend">
                <span data-tone="accent">{{ ___('Confirmed') }}</span>
                <span data-tone="success">{{ ___('In-house') }}</span>
                <span data-tone="warning">{{ ___('Pending') }}</span>
                <span data-tone="ooo">{{ ___('Out of order') }}</span>
            </div>
        </div>
        @if($rooms->isEmpty())
            <x-ui.empty :title="___('No rooms')" :body="___('Add rooms and assign room types to see them on the calendar.')" icon="bed" />
        @else
            <div class="ui-cal__scroll">
                <table class="ui-cal__grid">
                    <thead>
                        <tr>
                            <th class="ui-cal__room" scope="col">{{ ___('Room') }}</th>
                            @foreach($days as $d)
                                @php $dc = \Illuminate\Support\Carbon::parse($d); @endphp
                                <th scope="col" @if($dc->isWeekend()) data-weekend @endif @if($dc->isToday()) data-today @endif>
                                    <span>{{ $dc->translatedFormat('D') }}</span>
                                    <span class="ui-ltr">{{ $dc->format('d/m') }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($byType as $typeName => $typeRooms)
                            <tr class="ui-cal__type">
                                <th class="ui-cal__room" colspan="{{ count($days) + 1 }}" scope="rowgroup">{{ $typeName }}</th>
                            </tr>
                            @foreach($typeRooms as $room)
                                <tr>
                                    <th class="ui-cal__room" scope="row">
                                        <span class="ui-ltr">#{{ $room->table_no }}</span>{{ ($room->label ?? '') ? ' · '.$room->label : '' }}
                                    </th>
                                    @foreach($days as $d)
                                        @php $cell = $occupancy[$room->id][$d] ?? null; @endphp
                                        @if($cell && ($cell['type'] ?? '') === 'ooo')
                                            <td data-tone="ooo" title="{{ ___('Out of order') }}">×</td>
                                        @elseif($cell && ($cell['type'] ?? '') === 'booked')
                                            <td data-tone="{{ $statusTone[$cell['status'] ?? ''] ?? 'accent' }}" @if(!empty($cell['booking_id'])) data-booking-id="{{ $cell['booking_id'] }}" @endif>
                                                @if(!empty($cell['booking_id']) && Route::has('hotel.pms.bookings.show'))
                                                    <a href="{{ route('hotel.pms.bookings.show', $cell['booking_id']) }}"
                                                       title="{{ ($cell['code'] ?? '').' · '.($cell['guest'] ?? '') }}">{{ \Illuminate\Support\Str::limit($cell['guest'] ?? ($cell['code'] ?? ''), 8, '') }}</a>
                                                @else
                                                    <span title="{{ $cell['code'] ?? '' }}">{{ \Illuminate\Support\Str::limit($cell['guest'] ?? ($cell['code'] ?? ''), 8, '') }}</span>
                                                @endif
                                            </td>
                                        @else
                                            <td data-free><span aria-hidden="true">·</span></td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endunless
</div>
@endsection
