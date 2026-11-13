{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

{{-- P3-08: shipping zones (where the store ships) and their methods (fee, free threshold, transit days, cut-off, carrier, tracking link), and pickup in store. --}}
@section('title', ___('Shipping'))
@section('page_title', ___('Shipping'))
@section('page_sub', ___('Where you ship, how much it costs and when it arrives.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.retail.shipping') }}">
        <select name="store_id" class="ui-input ui-input--sm ui-mods__store" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@php
    $q = ['store_id' => $rid];
    $weekdays = [0 => ___('Sun'), 1 => ___('Mon'), 2 => ___('Tue'), 3 => ___('Wed'), 4 => ___('Thu'), 5 => ___('Fri'), 6 => ___('Sat')];
    $kinds = ['standard' => ___('Standard'), 'express' => ___('Express'), 'pickup_in_store' => ___('Pickup in store')];
    $exp = \Juwri\Core\Money\Currencies::exponent($currency);
    $major = fn ($minor) => $minor === null ? '' : number_format(\Juwri\Core\Money\Money::majorFromMinor((int) $minor, $currency), $exp, '.', '');
@endphp
<div class="ui-scope" data-retail-shipping>
    @if($errors->any())
        <div class="ui-card"><p class="ui-text-danger">{{ $errors->first() }}</p></div>
    @endif

    <section class="ui-card">
        <h3 class="ui-section-title">{{ ___('Zones') }}</h3>
        @forelse($zones as $zone)
            @php($fp = 'z'.$zone->id.'-')
            <form method="post" action="{{ route('stores.retail.zones.update', ['zone' => $zone->id] + $q) }}" class="ui-form-grid" data-zone="{{ $zone->id }}">
                @csrf
                <x-ui.field name="code" :id="$fp.'code'" :label="___('Code')" :value="$zone->code" />
                <x-ui.field name="name" :id="$fp.'name'" :label="___('Name')" :value="$zone->name" />
                <x-ui.field name="countries" :id="$fp.'countries'" :label="___('Countries (comma separated, e.g. SA, AE)')" :value="implode(', ', (array) $zone->countries)" />
                <x-ui.field name="regions" :id="$fp.'regions'" :label="___('Regions (optional)')" :value="implode(', ', (array) $zone->regions)" />
                <x-ui.field name="cities" :id="$fp.'cities'" :label="___('Cities (optional)')" :value="implode(', ', (array) $zone->cities)" />
                <x-ui.field name="postal_prefixes" :id="$fp.'postal_prefixes'" :label="___('Postcode prefixes (optional)')" :value="implode(', ', (array) $zone->postal_prefixes)" />
                <input type="hidden" name="active" value="0">
                <label class="ui-label"><input type="checkbox" name="active" value="1" @checked($zone->active)> {{ ___('Active') }}</label>
                <div class="ui-form-grid__full">
                    <x-ui.button type="submit" size="sm">{{ ___('Save') }}</x-ui.button>
                </div>
            </form>
            <form method="post" action="{{ route('stores.retail.zones.delete', ['zone' => $zone->id] + $q) }}" data-confirm="{{ ___('Delete this zone and its methods?') }}" data-confirm-danger>
                @csrf
                <x-ui.button type="submit" size="sm" variant="secondary">{{ ___('Delete') }}</x-ui.button>
            </form>
            <hr>
        @empty
            <x-ui.empty icon="delivery" :title="___('No shipping zones yet.')" />
        @endforelse

        <h4 class="ui-section-title">{{ ___('Add a zone') }}</h4>
        @php($fp = 'zn-')
        <form method="post" action="{{ route('stores.retail.zones.store', $q) }}" class="ui-form-grid" data-zone-new>
            @csrf
            <x-ui.field name="code" :id="$fp.'code'" :label="___('Code')" placeholder="riyadh-north" />
            <x-ui.field name="name" :id="$fp.'name'" :label="___('Name')" />
            <x-ui.field name="countries" :id="$fp.'countries'" :label="___('Countries (comma separated, e.g. SA, AE)')" />
            <x-ui.field name="regions" :id="$fp.'regions'" :label="___('Regions (optional)')" />
            <x-ui.field name="cities" :id="$fp.'cities'" :label="___('Cities (optional)')" />
            <x-ui.field name="postal_prefixes" :id="$fp.'postal_prefixes'" :label="___('Postcode prefixes (optional)')" />
            <div class="ui-form-grid__full">
                <x-ui.button type="submit" size="sm" icon="plus">{{ ___('Add zone') }}</x-ui.button>
            </div>
        </form>
    </section>

    <section class="ui-card">
        <h3 class="ui-section-title">{{ ___('Methods') }}</h3>
        @foreach($methods->concat([null]) as $method)
            @php($fp = $method ? 'm'.$method->id.'-' : 'mn-')
            <form method="post" action="{{ $method ? route('stores.retail.methods.update', ['method' => $method->id] + $q) : route('stores.retail.methods.store', $q) }}" class="ui-form-grid" @if($method) data-method="{{ $method->id }}" @else data-method-new @endif>
                @csrf
                @unless($method)
                    <h4 class="ui-section-title ui-form-grid__full">{{ ___('Add a method') }}</h4>
                @endunless
                <div>
                    <label class="ui-label">{{ ___('Type') }}</label>
                    <select class="ui-input" name="kind" aria-label="{{ ___('Type') }}">
                        @foreach($kinds as $value => $label)
                            <option value="{{ $value }}" @selected(($method->kind ?? 'standard') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Zone') }}</label>
                    <select class="ui-input" name="shipping_zone_id" aria-label="{{ ___('Zone') }}">
                        <option value="">—</option>
                        @foreach($zones as $zone)
                            <option value="{{ $zone->id }}" @selected(($method->shipping_zone_id ?? null) == $zone->id)>{{ $zone->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Pickup branch') }}</label>
                    <select class="ui-input" name="pickup_branch_id" aria-label="{{ ___('Pickup branch') }}">
                        <option value="">—</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(($method->pickup_branch_id ?? null) == $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-ui.field name="name" :id="$fp.'name'" :label="___('Name')" :value="$method->name ?? ''" />
                <x-ui.field name="carrier" :id="$fp.'carrier'" :label="___('Carrier (optional)')" :value="$method->carrier ?? ''" />
                <x-ui.field name="tracking_url_template" :id="$fp.'tracking_url_template'" :label="___('Tracking link (use {tracking})')" :value="$method->tracking_url_template ?? ''" placeholder="https://carrier.example/track/{tracking}" />
                <x-ui.field name="fee" :id="$fp.'fee'" type="number" step="any" :label="___('Fee').' ('.$currency.')'" :value="$method ? $major($method->fee_minor) : '0'" />
                <x-ui.field name="free_over" :id="$fp.'free_over'" type="number" step="any" :label="___('Free from order subtotal (optional)')" :value="$method ? $major($method->free_over_minor) : ''" />
                <x-ui.field name="min_days" :id="$fp.'min_days'" type="number" :label="___('Fastest delivery (days)')" :value="$method->min_days ?? 1" />
                <x-ui.field name="max_days" :id="$fp.'max_days'" type="number" :label="___('Slowest delivery (days)')" :value="$method->max_days ?? 3" />
                <x-ui.field name="cutoff_time" :id="$fp.'cutoff_time'" type="time" :label="___('Order cut-off (store time, optional)')" :value="$method->cutoff_time ?? ''" />
                <fieldset class="ui-form-grid__full">
                    <legend class="ui-label">{{ ___('Delivers on (none ticked: every day)') }}</legend>
                    @foreach($weekdays as $d => $label)
                        <label><input type="checkbox" name="delivery_days[]" value="{{ $d }}" @checked(in_array($d, (array) ($method->delivery_days ?? []), true))> {{ $label }}</label>
                    @endforeach
                </fieldset>
                <input type="hidden" name="active" value="0">
                <label class="ui-label"><input type="checkbox" name="active" value="1" @checked($method->active ?? true)> {{ ___('Active') }}</label>
                <div class="ui-form-grid__full">
                    <x-ui.button type="submit" size="sm">{{ $method ? ___('Save') : ___('Add method') }}</x-ui.button>
                </div>
            </form>
            @if($method)
                <form method="post" action="{{ route('stores.retail.methods.delete', ['method' => $method->id] + $q) }}" data-confirm="{{ ___('Delete this method?') }}" data-confirm-danger>
                    @csrf
                    <x-ui.button type="submit" size="sm" variant="secondary">{{ ___('Delete') }}</x-ui.button>
                </form>
                <hr>
            @endif
        @endforeach
    </section>
</div>
@endsection
