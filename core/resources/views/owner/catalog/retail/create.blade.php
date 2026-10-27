{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', $vocab['create_item'].' - '.$store->title)
@section('page_title', $vocab['create_item'])
@section('page_sub', $store->title)

@section('page_actions')
    <x-ui.button :href="route('retail.products.index', $store->id)" variant="secondary" icon="back">
        {{ ___('Back to :label', ['label' => $vocab['items']]) }}
    </x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-itemform">
<x-ui.card>
    <form method="POST" action="{{ route('retail.products.store', $store->id) }}" enctype="multipart/form-data">
        @csrf
        @include('owner.catalog.retail.partials.form', ['item' => null])

        <div class="sticky-savebar ui-itemform__save" data-bridge-hide="form">
            <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>
</div>
@endsection
