{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Booking settings'))
@section('page_title', ___('Booking settings'))
@section('page_sub', ___('Time steps, cancellation policy, deposits, visits at home and reminders.'))

@section('page_actions')
    @include('owner.bookings._store-switch')
@endsection

@section('content')
<div class="ui-scope ui-stack">
    @include('owner.bookings._nav')

    <form method="post" action="{{ route('bookings.owner.settings.save', ['store_id' => $store->id]) }}" class="ui-stack">
        @csrf
        <x-ui.card :title="___('Times')">
            <div class="ui-form-grid">
                <x-ui.field id="bs-interval" name="slot_interval" type="select" :label="___('Show a start time every')" :value="$s->slotInterval"
                    :options="\Juwri\Core\Support\ViewHelpers::minuteOptions(\Juwri\Modules\Bookings\Services\BookingSettings::INTERVALS)" />
                <x-ui.field id="bs-lead" name="lead_minutes" type="number" min="0" :label="___('Earliest booking (minutes from now)')" :value="$s->leadMinutes" />
                <x-ui.field id="bs-max" name="max_days" type="number" min="1" max="365" :label="___('Book up to (days ahead)')" :value="$s->maxDays" />
                <div class="ui-form-grid__full"><x-ui.check name="staff_choice" value="1" :label="___('Customers choose the staff member')" :checked="$s->staffChoice" /></div>
            </div>
        </x-ui.card>

        <x-ui.card :title="___('Cancellations and deposits')">
            <div class="ui-form-grid">
                <x-ui.field id="bs-window" name="cancel_window_hours" type="number" min="0" max="720" :label="___('Free cancellation until (hours before)')" :value="$s->cancelWindowHours" />
                <div class="ui-form-grid__full">
                    <x-ui.check name="deposit_refunds" value="1" :label="___('Refund deposits when a customer cancels in time')" :checked="$s->depositRefunds" />
                    <span class="ui-field__help">{{ ___('Off: deposits are not refunded, and customers can still reschedule until the deadline.') }}</span>
                </div>
                <div class="ui-form-grid__full">
                    <x-ui.check name="no_show_fee" value="1" :label="___('Charge a no-show fee')" :checked="$s->noShowFee" />
                    <span class="ui-field__help">{{ ___('Recorded on the booking for you to collect. Charging a saved card is not available yet.') }}</span>
                </div>
                <x-ui.field id="bs-fee" name="no_show_fee_amount" type="number" step="any" min="0" :label="___('No-show fee')" :prefix="$currency" :value="$noShowFee" />
                <x-ui.field id="bs-policy" name="policy_note" type="textarea" :label="___('Note for customers')" :value="$s->policyNote" class="ui-form-grid__full" :help="___('Shown before booking, for example: please arrive 10 minutes early.')" />
            </div>
        </x-ui.card>

        <x-ui.card :title="___('Visits at home')">
            <div class="ui-form-grid">
                <div class="ui-form-grid__full"><x-ui.check name="at_home" value="1" :label="___('Offer visits at the customer\'s home')" :checked="$s->atHome" /></div>
                <x-ui.field id="bs-radius" name="at_home_radius_km" type="number" min="1" max="500" :label="___('Service area (km)')" :value="$s->atHomeRadiusKm" />
                <x-ui.field id="bs-travel" name="travel_buffer_min" type="number" step="5" min="0" max="240" :label="___('Travel time before and after (minutes)')" :value="$s->travelBufferMin" />
                <x-ui.field id="bs-lat" name="base_lat" type="number" step="any" :label="___('Visits start from (latitude)')" :value="$s->baseLat" />
                <x-ui.field id="bs-lng" name="base_lng" type="number" step="any" :label="___('Visits start from (longitude)')" :value="$s->baseLng" />
            </div>
        </x-ui.card>

        <x-ui.card :title="___('Reminders 24 hours before')">
            <p class="ui-muted">{{ ___('Sent only on the channels the customer ticks when booking.') }}</p>
            <x-ui.check name="reminder_email" value="1" :label="___('Email')" :checked="$s->reminderEmail" />
            <x-ui.check name="reminder_sms" value="1" :label="___('SMS')" :checked="$s->reminderSms" />
            <x-ui.check name="reminder_whatsapp" value="1" :label="___('WhatsApp')" :checked="$s->reminderWhatsApp" />
        </x-ui.card>

        <div><x-ui.button type="submit">{{ ___('Save settings') }}</x-ui.button></div>
    </form>
</div>
@endsection
