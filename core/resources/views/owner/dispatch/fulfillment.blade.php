{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Fulfillment'))
@section('page_title', ___('Fulfillment'))
@section('page_sub', $store->title)

@section('content')
<div class="ui-scope ui-fulfil">

@if(session('success'))
    <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
@endif
@if(in_array('delivery', $availableModes, true))
    <x-plan-gate-notice feature="delivery" :owner="$store->user" />
@endif

<x-ui.card :title="___('How do customers get their order?')">
    <p class="ui-fulfil__lead">
        {{ ___('Turn on the fulfillment methods you offer. Customers only see the methods you enable here.') }}
    </p>

    <form method="POST" action="{{ route('stores.fulfillment.update') }}?store_id={{ $store->id }}" class="ui-fulfil__form">
        @csrf
        @method('PUT')

        @if(empty($availableModes))
            <x-ui.empty :title="___('This store type has no fulfillment methods to configure.')" icon="delivery" />
        @endif

        @if(!empty($availableModes))
            <div class="ui-fulfil__modes">
                @foreach($availableModes as $mode)
                    <x-ui.switch
                        name="modes[]"
                        :value="$mode"
                        :id="'fulfillment-mode-'.$mode"
                        :checked="in_array($mode, $enabledModes, true)"
                        :label="$registry->label($mode)"
                        :description="$registry->needsAddress($mode) && $registry->needsSlot($mode) ? ___('Collects an address and a time slot at checkout.') : ($registry->needsAddress($mode) ? ___('Collects a shipping address at checkout.') : ($registry->needsSlot($mode) ? ___('Customer picks a time slot at checkout.') : null))" />
                @endforeach
            </div>

            <div class="ui-fulfil__actions">
                <x-ui.button type="submit" icon="check" data-ui-busy>{{ ___('Save') }}</x-ui.button>
            </div>
        @endif
    </form>
</x-ui.card>

</div>
@endsection
