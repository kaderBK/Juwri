{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Expiry labels'))
@section('page_title', ___('Expiry labels'))

@section('content')
<div class="ui-scope ui-fslabels">

<x-ui.card class="ui-no-print">
    <form method="get" action="{{ route('stores.foodsafety.labels') }}" class="ui-fslabels__form">
        <input type="hidden" name="store_id" value="{{ request('store_id') }}">
        <div class="ui-fslabels__field ui-fslabels__field--items">
            <label class="ui-label" for="fl-items">{{ ___('Items (one per line)') }}</label>
            <textarea id="fl-items" name="items" rows="4" class="ui-input" placeholder="{{ ___('Chicken stock') }}&#10;{{ ___('Diced onions') }}">{{ request('items') }}</textarea>
        </div>
        <div class="ui-fslabels__field"><label class="ui-label" for="fl-prep">{{ ___('Prep date') }}</label><input id="fl-prep" type="date" name="prep" class="ui-input" value="{{ $prep }}"></div>
        <div class="ui-fslabels__field"><label class="ui-label" for="fl-days">{{ ___('Shelf-life (days)') }}</label><input id="fl-days" type="number" name="days" min="0" class="ui-input" value="{{ $shelfLife }}" inputmode="numeric"></div>
        <div class="ui-fslabels__actions">
            <x-ui.button type="submit" icon="refresh">{{ ___('Preview') }}</x-ui.button>
            <x-ui.button type="button" variant="secondary" icon="print" data-ui-do="print">{{ ___('Print') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

<div class="labels-sheet ui-fslabels__sheet">
    @forelse($items as $item)
        <div class="label ui-fslabels__label">
            <div class="ui-fslabels__name">{{ $item }}</div>
            <div class="ui-fslabels__prep">{{ ___('Prep') }}: <span class="ui-ltr">{{ $prep }}</span></div>
            <div class="ui-fslabels__use">{{ ___('Use by') }}: <span class="ui-ltr">{{ $expiry }}</span></div>
        </div>
    @empty
        <div class="ui-card ui-no-print ui-fslabels__empty"><x-ui.empty :title="___('Enter items above, then Preview.')" icon="print" /></div>
    @endforelse
</div>

</div>
@endsection
