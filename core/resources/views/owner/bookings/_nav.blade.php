{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-10: Bookings sub-navigation + store switcher (shared by the five owner screens). --}}
@php $q = ['store_id' => $store->id]; @endphp
<nav class="ui-chips" aria-label="{{ ___('Bookings') }}">
    <x-ui.chip :href="route('bookings.owner.index', $q)" :active="request()->routeIs('bookings.owner.index')">{{ ___('Bookings') }}</x-ui.chip>
    <x-ui.chip :href="route('bookings.owner.services', $q)" :active="request()->routeIs('bookings.owner.services')">{{ ___('Services') }}</x-ui.chip>
    <x-ui.chip :href="route('bookings.owner.staff', $q)" :active="request()->routeIs('bookings.owner.staff')">{{ ___('Staff') }}</x-ui.chip>
    <x-ui.chip :href="route('bookings.owner.hours', $q)" :active="request()->routeIs('bookings.owner.hours')">{{ ___('Hours') }}</x-ui.chip>
    <x-ui.chip :href="route('bookings.owner.settings', $q)" :active="request()->routeIs('bookings.owner.settings')">{{ ___('Settings') }}</x-ui.chip>
</nav>
@if(isset($errors) && $errors->any())
    <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
@endif
