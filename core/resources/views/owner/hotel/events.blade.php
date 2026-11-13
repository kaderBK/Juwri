{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Events'))
@section('page_title', ___('Events & functions'))
@section('page_sub', $store->title.' - '.___('Banquets, meetings and functions — billed to a stay folio or their own account.'))

@php
    // Lane A supplies these; defaults keep the blade renderable pre-merge / defensive.
    //   $events list of objects: id, title, guest_name, venue, starts_at, ends_at,
    //           attendees, status, hotel_stay_id
    //   $stays  active stays for the optional "bill to stay folio" picker
    $events   = collect($events ?? []);
    $stays    = collect($stays ?? []);
    $currency = trim($currency ?? '');

    $hasBackend  = Route::has('hotel.pms.events');
    $canStore    = Route::has('hotel.pms.events.store');
    $servicesUrl = Route::has('hotel.pms.services') ? route('hotel.pms.services') : null;

    $statusTones = [
        'enquiry' => 'warning', 'confirmed' => 'accent',
        'completed' => 'success', 'cancelled' => 'neutral',
    ];
@endphp

@if($hasBackend)
@section('page_actions')
    @if($servicesUrl)
        <x-ui.button :href="$servicesUrl" variant="secondary" icon="dine-in">{{ ___('Services') }}</x-ui.button>
    @endif
    @if($canStore)
        <x-ui.button type="button" icon="plus" x-data @click="$dispatch('event-add')">{{ ___('New event') }}</x-ui.button>
    @endif
@endsection
@endif

@section('content')
@unless($hasBackend)
    <x-ui.empty :title="___('PMS backend pending')" :body="___('Events and functions appear here once the booking engine is enabled.')" />
@else
<div class="ui-stack" x-data="pmsEventsList()" @event-add.window="openAdd()">
    <div class="ui-card">
        <div class="ui-cluster ui-cluster--between ui-card__head">
            <h3 class="ui-section-title">{{ ___('All events') }}</h3>
            <span class="ui-tag" data-tone="neutral">{{ $events->count() }}</span>
        </div>
        @if($events->isEmpty())
            <div class="ui-card__body">
                <x-ui.empty :title="___('No events yet')" :body="___('Create an event to plan its guest, venue, timing and chargeable items.')" />
            </div>
        @else
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('Title') }}</th>
                        <th>{{ ___('Client') }}</th>
                        <th>{{ ___('Venue') }}</th>
                        <th>{{ ___('When') }}</th>
                        <th class="ui-end">{{ ___('Guests') }}</th>
                        <th>{{ ___('Billing') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th class="ui-end">{{ ___('Actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach($events as $e)
                            @php $starts = ($e->starts_at ?? null) ? \Illuminate\Support\Carbon::parse($e->starts_at) : null; @endphp
                            <tr>
                                <td data-label="{{ ___('Title') }}"><strong>{{ $e->title }}</strong></td>
                                <td data-label="{{ ___('Client') }}">{{ $e->guest_name ?: '—' }}</td>
                                <td data-label="{{ ___('Venue') }}">{{ $e->venue ?: '—' }}</td>
                                <td class="ui-small" data-label="{{ ___('When') }}">{{ optional($starts)->translatedFormat('d M, H:i') ?: '—' }}</td>
                                <td class="ui-end" data-label="{{ ___('Guests') }}">{{ ($e->attendees ?? null) !== null ? (int) $e->attendees : '—' }}</td>
                                <td data-label="{{ ___('Billing') }}">
                                    @if($e->hotel_stay_id ?? null)
                                        <span class="ui-tag" data-tone="accent">{{ ___('Stay folio') }}</span>
                                    @else
                                        <span class="ui-tag" data-tone="neutral">{{ ___('Own account') }}</span>
                                    @endif
                                </td>
                                <td data-label="{{ ___('Status') }}"><span class="ui-tag ui-capitalize" data-tone="{{ $statusTones[$e->status ?? 'enquiry'] ?? 'neutral' }}">{{ ___($e->status ?? 'enquiry') }}</span></td>
                                <td class="ui-end" data-label="{{ ___('Actions') }}">
                                    @if(Route::has('hotel.pms.events.show'))
                                        <x-ui.button :href="route('hotel.pms.events.show', $e->id)" variant="ghost" size="sm" icon="external">{{ ___('Open') }}</x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- New event dialog. Leaving "bill to stay" empty opens the event its own account. --}}
    @if($canStore)
    <dialog class="ui-dialog ui-dialog--flat" x-ref="evtDlg" style="max-width:520px;">
        <form action="{{ route('hotel.pms.events.store') }}" method="post">
            @csrf
            <div class="ui-cluster ui-cluster--between ui-rule-bottom">
                <h3 class="ui-section-title">{{ ___('New event') }}</h3>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.evtDlg.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
            </div>
            <div class="ui-stack">
                <div>
                    <label class="ui-label">{{ ___('Title') }}</label>
                    <input type="text" name="title" class="ui-input" maxlength="120" required>
                </div>
                <div class="ui-form-grid">
                    <div>
                        <label class="ui-label">{{ ___('Client name') }}</label>
                        <input type="text" name="guest_name" class="ui-input" maxlength="120">
                    </div>
                    <div>
                        <label class="ui-label">{{ ___('Client phone') }}</label>
                        <input type="text" name="guest_phone" class="ui-input" maxlength="40">
                    </div>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Venue') }}</label>
                    <input type="text" name="venue" class="ui-input" maxlength="120" placeholder="{{ ___('e.g. Rooftop terrace') }}">
                </div>
                <div class="ui-form-grid">
                    <div>
                        <label class="ui-label">{{ ___('Starts') }}</label>
                        <input type="datetime-local" name="starts_at" class="ui-input" required>
                    </div>
                    <div>
                        <label class="ui-label">{{ ___('Ends') }}</label>
                        <input type="datetime-local" name="ends_at" class="ui-input">
                    </div>
                </div>
                <div class="ui-form-grid">
                    <div>
                        <label class="ui-label">{{ ___('Attendees') }}</label>
                        <input type="number" name="attendees" class="ui-input" min="0">
                    </div>
                    <div>
                        <label class="ui-label">{{ ___('Bill to stay folio') }}</label>
                        <select name="hotel_stay_id" class="ui-input">
                            <option value="">{{ ___('Own account (standalone)') }}</option>
                            @foreach($stays as $st)
                                <option value="{{ $st->id }}">#{{ $st->room->table_no ?? $st->table_id }} · {{ $st->guest_name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Notes') }}</label>
                    <textarea name="notes" class="ui-input" rows="2" maxlength="1000"></textarea>
                </div>
            </div>
            <div class="ui-cluster ui-cluster--end ui-rule-top">
                <x-ui.button type="button" variant="ghost" @click="$refs.evtDlg.close()">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ ___('Create event') }}</x-ui.button>
            </div>
        </form>
    </dialog>
    @endif
</div>
@endunless
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    function pmsEventsList() {
        return { openAdd() { if (this.$refs.evtDlg) { this.$refs.evtDlg.showModal(); } } };
    }
</script>
@endpush
