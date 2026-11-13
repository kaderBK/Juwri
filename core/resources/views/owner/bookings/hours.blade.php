{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Hours'))
@section('page_title', ___('Booking hours'))
@section('page_sub', ___('When customers can book. Without hours nobody can book (your page says "Bookings open soon").'))

@section('page_actions')
    @include('owner.bookings._store-switch')
@endsection

@section('content')
<div class="ui-scope ui-stack">
    @include('owner.bookings._nav')

    <nav class="ui-chips" aria-label="{{ ___('Whose hours') }}">
        <x-ui.chip :href="route('bookings.owner.hours', ['store_id' => $store->id])" :active="$staffId === null">{{ ___('Business hours') }}</x-ui.chip>
        @foreach($staff as $member)
            <x-ui.chip :href="route('bookings.owner.hours', ['store_id' => $store->id, 'staff' => $member->id])" :active="$staffId === $member->id">{{ $member->name }}</x-ui.chip>
        @endforeach
    </nav>

    <x-ui.card :title="$staffId ? ___('Hours of :name', ['name' => (string) $staff->firstWhere('id', $staffId)?->name]) : ___('Business hours')">
        <form method="post" action="{{ route('bookings.owner.hours.save', ['store_id' => $store->id]) }}">
            @csrf
            @if($staffId)
                <input type="hidden" name="staff_id" value="{{ $staffId }}">
                <x-ui.check name="use_store_hours" value="1" :label="___('Same as the business hours')" :checked="! $ownHours" />
            @endif
            <table class="ui-table">
                <thead><tr><th>{{ ___('Day') }}</th><th>{{ ___('Open') }}</th><th>{{ ___('From') }}</th><th>{{ ___('To') }}</th><th>{{ ___('Break from') }}</th><th>{{ ___('Break to') }}</th></tr></thead>
                <tbody>
                @foreach($week as $d)
                    <tr>
                        <td data-primary data-label="{{ ___('Day') }}">{{ $d['label'] }}</td>
                        <td data-label="{{ ___('Open') }}"><x-ui.check :name="'days['.$d['day'].'][open]'" value="1" :label="___('Open')" :checked="$d['open']" /></td>
                        <td data-label="{{ ___('From') }}"><input class="ui-input" type="time" step="300" name="days[{{ $d['day'] }}][starts]" value="{{ $d['starts'] }}" aria-label="{{ ___('From') }} {{ $d['label'] }}"></td>
                        <td data-label="{{ ___('To') }}"><input class="ui-input" type="time" step="300" name="days[{{ $d['day'] }}][ends]" value="{{ $d['ends'] }}" aria-label="{{ ___('To') }} {{ $d['label'] }}"></td>
                        <td data-label="{{ ___('Break from') }}"><input class="ui-input" type="time" step="300" name="days[{{ $d['day'] }}][break_starts]" value="{{ $d['break_starts'] }}" aria-label="{{ ___('Break from') }} {{ $d['label'] }}"></td>
                        <td data-label="{{ ___('Break to') }}"><input class="ui-input" type="time" step="300" name="days[{{ $d['day'] }}][break_ends]" value="{{ $d['break_ends'] }}" aria-label="{{ ___('Break to') }} {{ $d['label'] }}"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <x-ui.button type="submit">{{ ___('Save hours') }}</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :title="___('Closed days')">
        @forelse($closed as $c)
            <div class="ui-cluster">
                <span class="ui-ltr">{{ $c['date'] }}</span> · {{ $c['who'] }}@if($c['note']) · {{ $c['note'] }}@endif
                <form method="post" action="{{ route('bookings.owner.closed.delete', ['day' => $c['id'], 'store_id' => $store->id]) }}">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" size="sm">{{ ___('Remove') }}</x-ui.button>
                </form>
            </div>
        @empty
            <x-ui.empty :title="___('No closed days ahead.')" icon="calendar" />
        @endforelse
        <form method="post" action="{{ route('bookings.owner.closed.store', ['store_id' => $store->id]) }}" class="ui-form-grid">
            @csrf
            <x-ui.field id="cd-date" name="date" type="date" :label="___('Date')" required />
            <x-ui.field id="cd-who" name="staff_id" type="select" :label="___('Who')" :options="[0 => ___('Everyone')] + $staff->pluck('name', 'id')->all()" />
            <x-ui.field id="cd-note" name="note" :label="___('Note')" maxlength="120" />
            <div class="ui-form-grid__full"><x-ui.button type="submit" icon="plus">{{ ___('Add closed day') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</div>
@endsection
