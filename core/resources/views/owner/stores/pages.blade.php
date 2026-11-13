{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Storefront pages'))
@section('page_title', ___('Storefront pages'))
@section('page_sub', ___('FAQ, refund policy, and delivery policy — plain text shown on your storefront.'))

@section('content')
@if(session('success'))<x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>@endif

@if(!$storeId)
    <x-ui.empty :title="___('Pick a store first.')" icon="store" />
@else
    <form method="post" action="{{ route('storefront-pages.save') }}" class="ui-stack">
        @csrf
        <input type="hidden" name="store_id" value="{{ $storeId }}">
        @foreach($pages as $slug)
            <x-ui.card :title="$labels[$slug] ?? $slug">
                <textarea name="{{ $slug }}" class="ui-input" rows="6" maxlength="20000" placeholder="{{ ___('Leave empty to hide this page.') }}">{{ $values[$slug] }}</textarea>
            </x-ui.card>
        @endforeach
        <div class="ui-cluster ui-cluster--end">
            <x-ui.button type="submit" icon="check">{{ ___('Save pages') }}</x-ui.button>
        </div>
    </form>
@endif
@endsection
