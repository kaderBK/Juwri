{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Delivery drivers'))
@section('page_title', ___('Delivery drivers'))
@section('page_sub', ___('Add drivers and issue app tokens for the driver app.'))

@section('content')
<div class="ui-scope ui-account ui-drivers">
{{-- Hero --}}
<section class="ui-card ui-account__hero">
    <div class="ui-drivers__intro">
        <p class="ui-overline">{{ ___('Fleet') }}</p>
        <h2 class="ui-account__name">{{ ___('Your drivers') }}</h2>
        <p class="ui-account__row-sub">{{ ___('Create a driver, share their one-time app token, and track deliveries in real time.') }}</p>
        <x-ui.button :href="route('developers').'#driver-app'" variant="ghost" size="sm" icon="book-open" target="_blank" rel="noopener">{{ ___('Driver app API docs') }}</x-ui.button>
    </div>
    <dl class="ui-account__facts ui-drivers__facts">
        <div><dt>{{ ___('Drivers') }}</dt><dd>{{ $drivers->count() }}</dd></div>
        <div><dt>{{ ___('Active') }}</dt><dd>{{ $drivers->where('active', true)->count() }}</dd></div>
    </dl>
</section>

@if (! empty($newDriverToken))
    <x-ui.banner tone="success">
        <strong>{{ ___('Copy this driver token now - it will not be shown again.') }}</strong>
        <code class="ui-drivers__token ui-ltr">{{ $newDriverToken }}</code>
    </x-ui.banner>
@endif

<x-ui.card :title="___('Add driver')">
    <form action="{{ route('drivers.store') }}" method="post" class="ui-drivers__add">
        @csrf
        <div class="ui-drivers__field">
            <label class="ui-label" for="dv-name">{{ ___('Name') }}</label>
            <input id="dv-name" type="text" name="name" class="ui-input" required maxlength="120" placeholder="Alex">
        </div>
        <div class="ui-drivers__field">
            <label class="ui-label" for="dv-phone">{{ ___('Phone') }}</label>
            <input id="dv-phone" type="text" name="phone" class="ui-input ui-ltr" maxlength="40" placeholder="+1 555 000 1111" inputmode="tel">
        </div>
        <div class="ui-drivers__field">
            <label class="ui-label" for="dv-email">{{ ___('Email') }}</label>
            <input id="dv-email" type="email" name="email" class="ui-input ui-ltr" maxlength="160" placeholder="alex@example.com" inputmode="email">
        </div>
        <div class="ui-drivers__field">
            <label class="ui-label" for="dv-store">{{ ___('Store (optional)') }}</label>
            <select id="dv-store" name="store_id" class="ui-input">
                <option value="">{{ ___('All stores') }}</option>
                @foreach ($stores as $r)
                    <option value="{{ $r->id }}">{{ $r->title }}</option>
                @endforeach
            </select>
        </div>
        <div class="ui-drivers__submit">
            <x-ui.button type="submit" icon="plus">{{ ___('Add driver') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

<x-ui.card :title="___('Let drivers claim orders')">
    <p class="ui-account__row-sub">{{ ___('When on, a driver of this store can take an unassigned delivery order from the Available tab of the driver app. Off by default: you assign every order yourself.') }}</p>
    @foreach ($stores as $r)
        <form action="{{ route('drivers.selfClaim') }}" method="post" class="ui-drivers__add" data-self-claim="{{ $r->id }}">
            @csrf
            <input type="hidden" name="store_id" value="{{ $r->id }}">
            <x-ui.switch name="enabled" off="0" :checked="! empty($selfClaim[$r->id])" :label="$r->title" />
            <div class="ui-drivers__submit"><x-ui.button type="submit" size="sm">{{ ___('Save') }}</x-ui.button></div>
        </form>
    @endforeach
</x-ui.card>

<x-ui.card :title="___('Drivers')" variant="flush">
    <x-slot:actions><x-ui.tag>{{ $drivers->count() }}</x-ui.tag></x-slot:actions>
    @if ($drivers->isEmpty())
        <x-ui.empty :title="___('No drivers yet.')" icon="delivery" />
    @else
        <table class="ui-table">
            <thead><tr>
                <th>{{ ___('Name') }}</th><th>{{ ___('Phone') }}</th><th>{{ ___('Store') }}</th>
                <th>{{ ___('Last location') }}</th><th>{{ ___('Status') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
            </tr></thead>
            <tbody>
                @foreach ($drivers as $d)
                    <tr>
                        <td data-primary><strong>{{ $d->name }}</strong></td>
                        <td data-label="{{ ___('Phone') }}"><span class="ui-ltr">{{ $d->phone ?: '—' }}</span></td>
                        <td data-label="{{ ___('Store') }}">{{ optional($stores->firstWhere('id', $d->store_id))->title ?? ___('All') }}</td>
                        <td data-label="{{ ___('Last location') }}">{{ $d->last_location_at ? $d->last_location_at->diffForHumans() : ___('Never') }}</td>
                        <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$d->active ? 'success' : 'neutral'">{{ $d->active ? ___('Active') : ___('Inactive') }}</x-ui.pill></td>
                        <td class="ui-catalog__row-actions">
                            <form action="{{ route('drivers.rotate', $d->id) }}" method="post" data-confirm="{{ ___('Generate a new token? The current one stops working.') }}">
                                @csrf
                                <button type="submit" class="ui-icon-btn" aria-label="{{ ___('New token') }}" title="{{ ___('New token') }}"><x-ui.icon name="refresh" /></button>
                            </form>
                            <form action="{{ route('drivers.toggle', $d->id) }}" method="post">
                                @csrf
                                <button type="submit" class="ui-icon-btn" aria-label="{{ $d->active ? ___('Disable') : ___('Enable') }}" title="{{ $d->active ? ___('Disable') : ___('Enable') }}"><x-ui.icon :name="$d->active ? 'pause' : 'check'" /></button>
                            </form>
                            <form action="{{ route('drivers.destroy', $d->id) }}" method="post" data-confirm="{{ ___('Remove this driver?') }}" data-confirm-danger>
                                @csrf @method('DELETE')
                                <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Remove') }}" title="{{ ___('Remove') }}"><x-ui.icon name="trash" /></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-ui.card>
</div>
@endsection
