{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Staff'))
@section('page_title', ___('Booking staff'))
@section('page_sub', ___('The people customers can book, and the services each one does.'))

@section('page_actions')
    @include('owner.bookings._store-switch')
@endsection

@section('content')
<div class="ui-scope ui-stack">
    @include('owner.bookings._nav')

    @if($services->isEmpty())
        <x-ui.banner tone="warning">{{ ___('Add your services first, then choose which ones each staff member does.') }}</x-ui.banner>
    @endif

    @foreach($staff as $member)
        @php $mine = $member->services->pluck('id')->all(); @endphp
        <x-ui.card :title="$member->name">
            <x-slot:actions>
                @unless($member->active)<x-ui.tag>{{ ___('Off') }}</x-ui.tag>@endunless
                <form method="post" action="{{ route('bookings.owner.staff.delete', ['staff' => $member->id, 'store_id' => $store->id]) }}">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" size="sm" icon="trash">{{ ___('Remove') }}</x-ui.button>
                </form>
            </x-slot:actions>
            <form method="post" action="{{ route('bookings.owner.staff.update', ['staff' => $member->id, 'store_id' => $store->id]) }}" class="ui-form-grid">
                @csrf
                <x-ui.field :id="'st-name-'.$member->id" name="name" :label="___('Name')" :value="$member->name" required maxlength="80" />
                <x-ui.field :id="'st-role-'.$member->id" name="role" :label="___('Role')" :value="$member->role" maxlength="60" />
                <x-ui.field :id="'st-photo-'.$member->id" name="photo_url" type="url" :label="___('Photo link')" :value="$member->photo_url" />
                <x-ui.field :id="'st-pos-'.$member->id" name="position" type="number" :label="___('Order')" :value="$member->position" min="0" />
                <fieldset class="ui-form-grid__full">
                    <legend class="ui-label">{{ ___('Services they do') }}</legend>
                    @foreach($services as $service)
                        <x-ui.check name="services[]" :value="$service->id" :label="$service->name" :checked="in_array($service->id, $mine, true)" />
                    @endforeach
                </fieldset>
                <div class="ui-form-grid__full">
                    <x-ui.check name="active" value="1" :label="___('Can be booked')" :checked="$member->active" />
                </div>
                <div class="ui-form-grid__full"><x-ui.button type="submit">{{ ___('Save') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    @endforeach

    <x-ui.card :title="___('Add staff')">
        <form method="post" action="{{ route('bookings.owner.staff.store', ['store_id' => $store->id]) }}" class="ui-form-grid">
            @csrf
            <x-ui.field id="st-name-new" name="name" :label="___('Name')" required maxlength="80" />
            <x-ui.field id="st-role-new" name="role" :label="___('Role')" maxlength="60" :help="___('For example: Senior stylist')" />
            <x-ui.field id="st-photo-new" name="photo_url" type="url" :label="___('Photo link')" />
            <fieldset class="ui-form-grid__full">
                <legend class="ui-label">{{ ___('Services they do') }}</legend>
                @foreach($services as $service)
                    <x-ui.check name="services[]" :value="$service->id" :label="$service->name" :checked="true" />
                @endforeach
            </fieldset>
            <div class="ui-form-grid__full"><x-ui.button type="submit" icon="plus">{{ ___('Add staff') }}</x-ui.button></div>
        </form>
    </x-ui.card>
</div>
@endsection
