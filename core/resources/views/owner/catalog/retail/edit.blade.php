{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Edit').' '.$item->getRawOriginal('name').' - '.$store->title)
@section('page_title', $item->getRawOriginal('name'))
@section('page_sub', $store->title)

@section('page_actions')
    <x-ui.button :href="route('retail.products.index', $store->id)" variant="secondary" icon="back">
        {{ ___('Back to :label', ['label' => $vocab['items']]) }}
    </x-ui.button>
    <x-ui.button :href="route('stores.retail.variants', ['menu' => $item->id, 'store_id' => $store->id])" variant="secondary">{{ ___('Variants') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-itemform">
<x-ui.card>
    <form method="POST" action="{{ route('retail.products.update', [$store->id, $item->id]) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')
        @include('owner.catalog.retail.partials.form', ['item' => $item])

        <div class="sticky-savebar ui-itemform__save" data-bridge-hide="form">
            <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

@include('owner.catalog.retail.partials.variant-grid', ['item' => $item, 'variants' => $variants])
</div>
@endsection
