{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    rev/multi-vertical Lane F1/F2 — per-item OTC compliance form: the
    age-gate flag (F1) and batch/expiry (F2). Each field only renders when
    the store's vertical actually has that capability (VerticalRegistry),
    so a hypothetical future `expiry`-only vertical never sees the age
    checkbox and vice versa.
--}}
@extends('owner.layout')

@section('title', ___('Compliance').' - '.$item->getRawOriginal('name'))
@section('page_title', $item->getRawOriginal('name'))
@section('page_sub', $store->title)

@section('page_actions')
    <x-ui.button :href="route('pharmacy.compliance.index', $store->id)" variant="secondary" icon="back">
        {{ ___('Back to :label', ['label' => ___('Compliance')]) }}
    </x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-pharm">
<x-ui.card :title="___('Compliance')">
    <form method="POST" action="{{ route('pharmacy.compliance.update', [$store->id, $item->id]) }}" class="ui-pharm__form">
        @csrf
        @method('PUT')

        @if($ageGateOn)
            <div class="ui-check">
                <input type="checkbox" role="switch" name="age_restricted" id="age_restricted" value="1" @checked(old('age_restricted', $item->age_restricted))>
                <label for="age_restricted">{{ ___('Age-restricted item') }}</label>
                <div class="ui-field__help">{{ ___('A shopper must confirm their age before this item can be added to the cart. This is a self-declared confirmation, not identity verification.') }}</div>
            </div>
        @endif

        @if(($store->store_type ?? null) === 'pharmacy')
            <div class="ui-check">
                <input type="checkbox" role="switch" name="not_medicine" id="not_medicine" value="1" @checked(old('not_medicine', ! ($item->is_medicine ?? true)))>
                <label for="not_medicine">{{ ___('Not a medicine (cosmetics, baby care, devices)') }}</label>
                <div class="ui-field__help">{{ ___('Medicines have the per-order quantity limit of your market, and no coupons where your market forbids promotions.') }}</div>
            </div>
        @endif

        @if($expiryOn)
            <div class="ui-pharm__pair">
                <x-ui.field name="batch_no" :label="___('Batch number')" :value="old('batch_no', $item->batch_no)" />
                <x-ui.field name="expiry_date" type="date" :label="___('Expiry date')" :value="old('expiry_date', $item->expiry_date?->toDateString())" />
            </div>
        @endif

        <div><x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button></div>
    </form>
</x-ui.card>
</div>
@endsection
