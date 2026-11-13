{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: the states board (no free times, a time just taken, closed day, outside the area, deposit kept, rescheduled, cannot reschedule,
     payment failed / waiting / not completed, cancelled, offline). Every state says what happened and offers a way out. Reads $vm. --}}
@extends('store_templates._kit.layout', ['theme' => 'book', 'bodyClass' => 'p-states'])
@php
    $__name = $vm['store']['name'];
    $__next = $vm['context']['next_free_slot']['label'] ?? null;
    $__ref = $kit->ltr('PAY-48213');
    $__bk = $kit->ltr('SB-4821');
@endphp
@section('content')
@include('store_templates.book.sections.header')
<main class="c-main c-main--states" id="main"><h2 class="u-sr">{{ $kit->s('States') }}</h2><div class="c-states">
    @include('store_templates._kit.components.state-card', ['tone' => 'muted', 'icon' => 'calendar-x', 'title' => $kit->s('No times left on this day'), 'body' => $__next ? $kit->s('Everyone is fully booked on this day. The next free time is :time.', ['time' => $__next]) : $kit->s('Everyone is fully booked on this day. Try another day.'), 'primary' => $kit->s('Show next open day'), 'secondary' => $kit->s('Any staff')])
    @include('store_templates._kit.components.state-card', ['tone' => 'warning', 'icon' => 'clock', 'title' => $kit->s('That time was just booked'), 'body' => $kit->s('Someone booked this time a moment ago. Your service and staff are kept; pick another time.'), 'primary' => $kit->s('Choose another time')])
    @include('store_templates._kit.components.state-card', ['tone' => 'muted', 'icon' => 'calendar-x', 'title' => $kit->s('Closed on this day'), 'body' => $kit->t(':store is closed on this day. Pick another day.', ['store' => $__name]), 'primary' => $kit->s('Choose another day')])
    @include('store_templates._kit.components.state-card', ['tone' => 'danger', 'icon' => 'map-pin', 'title' => $kit->s('Outside our service area'), 'body' => $kit->s('At-home visits do not reach this address. Book at the venue instead, or change the address.'), 'primary' => $kit->s('Book at the venue'), 'secondary' => $kit->s('Change address')])
    @include('store_templates._kit.components.state-card', ['tone' => 'warning', 'icon' => 'alert-triangle', 'title' => $kit->s('Cancelling now keeps your deposit'), 'body' => $kit->s('Your visit is closer than the free-cancellation time. If you cancel, the deposit is kept. You can move it to another day instead.'), 'primary' => $kit->s('Reschedule instead'), 'secondary' => $kit->s('Cancel anyway')])
    @include('store_templates._kit.components.state-card', ['tone' => 'muted', 'icon' => 'calendar-clock', 'title' => $kit->s('Booking rescheduled'), 'body' => $kit->t(':number is moved to your new time. Same price, nothing to pay.', ['number' => $__bk]), 'primary' => $kit->s('Your booking')])
    @include('store_templates._kit.components.state-card', ['tone' => 'warning', 'icon' => 'alert-triangle', 'title' => $kit->s("Can't reschedule online"), 'body' => $kit->t('The time to reschedule online has passed. Call :store and we will see what we can do.', ['store' => $__name]), 'primary' => $kit->s('Call store'), 'secondary' => $kit->s('Keep current time')])
    @include('store_templates._kit.components.state-card', ['tone' => 'danger', 'icon' => 'credit-card', 'title' => $kit->s('Payment failed'), 'body' => $kit->t("You weren't charged and the time is still held. Try another card or pay at the venue. Reference: :reference", ['reference' => $__ref]), 'primary' => $kit->s('Try again'), 'secondary' => $kit->s('Pay at the venue')])
    @include('store_templates._kit.components.state-card', ['tone' => 'warning', 'icon' => 'hourglass', 'live' => true, 'title' => $kit->s('Waiting for payment'), 'body' => $kit->t("We've received booking :number and are holding your time, but your deposit isn't paid. Complete payment soon or the booking is cancelled.", ['number' => $__bk]), 'primary' => $kit->s('Complete payment')])
    @include('store_templates._kit.components.state-card', ['tone' => 'danger', 'icon' => 'x', 'title' => $kit->s('Payment not completed'), 'body' => $kit->t('Booking :number was cancelled because the deposit was not paid in time. Nothing was charged and your time is released.', ['number' => $__bk]), 'primary' => $kit->s('Try again')])
    @include('store_templates._kit.components.state-card', ['tone' => 'muted', 'icon' => 'calendar-x', 'title' => $kit->s('Booking cancelled'), 'body' => $kit->t(':number is cancelled.', ['number' => $__bk]), 'primary' => $kit->s('Book again')])
    @include('store_templates._kit.components.state-card', ['tone' => 'warning', 'icon' => 'wifi-off', 'title' => $kit->s("You're offline"), 'body' => $kit->s('You can look at services and your booking. Booking a new time needs a connection.')])
</div></main>
@include('store_templates._kit.sections.footer')
@endsection
