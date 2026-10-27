{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

{{-- P3-09: grocery settings — time slots, weighed items (cap + D7 switch), out-of-stock options, items by weight / pack. --}}
@php
    $q = ['store_id' => $store->id];
    $exp = \Juwri\Core\Money\Currencies::exponent($currency);
    $major = fn (?int $minor) => $minor === null ? '' : number_format(\Juwri\Core\Money\Money::majorFromMinor($minor, $currency), $exp, '.', '');
    $windowsText = $rule ? collect((array) $rule->windows)->map(fn ($w) => implode('-', array_values((array) $w)))->implode(', ') : '09:00-21:00';
    $actions = [
        'offer_replacement' => ___('Offer a replacement'),
        'call_customer' => ___('Call the customer'),
        'remove_item' => ___('Remove it'),
    ];
    $options = $group ? $group->modifiers->where('active', true)->sortBy('position')->values() : collect();
    $rows = $options->concat(array_fill(0, max(0, 4 - $options->count()), null))->take(6);
    $grocery = app(\Juwri\Modules\Menu\Contracts\GroceryCatalog::class);
@endphp

@section('title', ___('Grocery settings'))
@section('page_title', ___('Grocery settings'))
@section('page_sub', ___('Delivery times, weighed items and what happens when an item is out of stock.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.grocery.settings') }}">
        <select name="store_id" class="ui-input ui-input--sm ui-mods__store" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($store->id == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
    <x-ui.button :href="route('stores.picking.index', $q)" variant="secondary" icon="check">{{ ___('Picking') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-scope" data-grocery-settings>
    <section class="ui-card">
        <h3 class="ui-section-title">{{ ___('Delivery and collection times') }}</h3>
        <form method="post" action="{{ route('stores.grocery.slots', $q) }}" class="ui-form-grid" data-grocery-slots>
            @csrf
            <input type="hidden" name="active" value="0">
            <label class="ui-label ui-form-grid__full"><input type="checkbox" name="active" value="1" @checked($rule?->active ?? false)> {{ ___('Customers choose a time slot') }}</label>
            <x-ui.field name="windows" id="g-windows" :label="___('Opening times (e.g. 09:00-12:00, 16:00-21:00)')" :value="old('windows', $windowsText)" />
            <x-ui.field name="capacity_per_hour" id="g-capacity" type="number" :label="___('Orders per hour (0 = no limit)')" :value="$rule->capacity_per_hour ?? 10" />
            <x-ui.field name="lead_minutes" id="g-lead" type="number" :label="___('Earliest slot (minutes from now)')" :value="$rule->lead_minutes ?? 60" />
            <x-ui.field name="days_ahead" id="g-days" type="number" :label="___('Days customers can book ahead')" :value="$rule->days_ahead ?? 3" />
            <x-ui.field name="express_fee" id="g-express-fee" type="number" step="any" :label="___('Express fee (empty = no express)').' ('.$currency.')'" :value="$major($rule?->express_fee_minor)" />
            <x-ui.field name="express_eta_from" id="g-express-from" type="number" :label="___('Express time from (minutes)')" :value="$rule->express_eta_from ?? ''" />
            <x-ui.field name="express_eta_to" id="g-express-to" type="number" :label="___('Express time to (minutes)')" :value="$rule->express_eta_to ?? ''" />
            <x-ui.field name="radius_km" id="g-radius" type="number" step="any" :label="___('Delivery radius in km (empty = no limit)')" :value="$rule->radius_km ?? ''" />
            <div class="ui-form-grid__full">
                <x-ui.button type="submit" size="sm">{{ ___('Save') }}</x-ui.button>
            </div>
        </form>
    </section>

    <section class="ui-card">
        <h3 class="ui-section-title">{{ ___('Weighed items') }}</h3>
        <form method="post" action="{{ route('stores.grocery.weighed', $q) }}" class="ui-form-grid" data-grocery-weighed>
            @csrf
            <x-ui.field name="cap_percent" id="g-cap" type="number" :label="___('A weighed item may cost up to this % more than its estimate')" :value="$capPercent" />
            <div class="ui-form-grid__full">
                <label class="ui-label">{{ ___('Refund the weighed difference') }}</label>
                <select class="ui-input" name="refunds_weighed_difference">
                    <option value="0" @selected(! $refundsWeighed)>{{ ___('Off: only payment methods that can hold the amount are offered for weighed items') }}</option>
                    <option value="1" @selected($refundsWeighed)>{{ ___('On: other methods charge the estimate and the difference is refunded') }}</option>
                </select>
            </div>
            <div class="ui-form-grid__full">
                <x-ui.button type="submit" size="sm">{{ ___('Save') }}</x-ui.button>
            </div>
        </form>
    </section>

    <section class="ui-card">
        <h3 class="ui-section-title">{{ ___('If an item is out of stock') }}</h3>
        <form method="post" action="{{ route('stores.grocery.substitutions', $q) }}" class="ui-form-grid" data-grocery-substitutions>
            @csrf
            <x-ui.field name="title" id="g-sub-title" :label="___('Question shown to customers')" :value="$group->title ?? ___('If it\'s out of stock')" />
            @foreach($rows as $i => $option)
                @php($pa = $option ? ($pickerActions[(string) $option->public_id] ?? null) : null)
                <fieldset class="ui-form-grid__full" data-substitution-row="{{ $i }}">
                    <input type="hidden" name="options[{{ $i }}][id]" value="{{ $option->public_id ?? '' }}">
                    <input class="ui-input ui-input--sm" name="options[{{ $i }}][title]" value="{{ $option->title ?? '' }}" placeholder="{{ ___('Option name') }}" aria-label="{{ ___('Option name') }}">
                    <select class="ui-input ui-input--sm" name="options[{{ $i }}][action]" aria-label="{{ ___('What the picker does') }}">
                        @foreach($actions as $value => $label)
                            <option value="{{ $value }}" @selected(($pa['action'] ?? 'remove_item') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select class="ui-input ui-input--sm" name="options[{{ $i }}][fallback]" aria-label="{{ ___('If the customer does not answer') }}">
                        <option value="remove_item" @selected(($pa['fallback'] ?? 'remove_item') === 'remove_item')>{{ ___('No answer: remove it') }}</option>
                        <option value="offer_replacement" @selected(($pa['fallback'] ?? '') === 'offer_replacement')>{{ ___('No answer: offer a replacement') }}</option>
                    </select>
                    <label><input type="radio" name="default" value="{{ $i }}" @checked($option && $defaultOption && $defaultOption->id === $option->id)> {{ ___('Default') }}</label>
                </fieldset>
            @endforeach
            <div class="ui-form-grid__full">
                <x-ui.button type="submit" size="sm">{{ ___('Save') }}</x-ui.button>
            </div>
        </form>
    </section>

    <section class="ui-card">
        <h3 class="ui-section-title">{{ ___('How an item is sold') }}</h3>
        <form method="post" action="{{ route('stores.grocery.item', $q) }}" class="ui-form-grid" data-grocery-item>
            @csrf
            <div class="ui-form-grid__full">
                <label class="ui-label" for="g-item">{{ ___('Item') }}</label>
                <select class="ui-input" id="g-item" name="item">
                    @foreach($items as $item)
                        @php($rule2 = $grocery->sellRule($store, $item))
                        <option value="{{ $item->id }}">{{ $item->name }} · {{ ['weighed' => ___('By weight'), 'pack' => ___('Pack'), 'unit' => ___('Each')][$rule2['kind']] ?? '' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ui-label" for="g-sell">{{ ___('Sold') }}</label>
                <select class="ui-input" id="g-sell" name="sell">
                    <option value="unit">{{ ___('Each') }}</option>
                    <option value="pack">{{ ___('Pack (shows the unit price)') }}</option>
                    <option value="weighed">{{ ___('By weight (the price is per kg)') }}</option>
                </select>
            </div>
            <x-ui.field name="steps" id="g-steps" :label="___('Weights in grams (e.g. 250, 500, 1000)')" />
            <x-ui.field name="pack_size" id="g-pack-size" type="number" step="any" :label="___('Pack size')" />
            <div>
                <label class="ui-label" for="g-pack-unit">{{ ___('Pack unit') }}</label>
                <select class="ui-input" id="g-pack-unit" name="pack_unit">
                    @foreach(['g', 'kg', 'ml', 'l', 'pc'] as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach
                </select>
            </div>
            <div class="ui-form-grid__full">
                <x-ui.button type="submit" size="sm">{{ ___('Save') }}</x-ui.button>
            </div>
        </form>
    </section>
</div>
@endsection
