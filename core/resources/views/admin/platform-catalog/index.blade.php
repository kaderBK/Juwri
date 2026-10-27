{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Platform catalog'))
@section('page_sub', ___('Curate global categories and add-ons that any store can adopt into its own menu.'))
@section('content')
<div class="ui-stack">
    <h2 class="ui-section-title">{{ ___('Platform menu catalog') }}</h2>

    <div class="ui-columns">
        <x-ui.card :title="___('Global categories')">
            <div class="ui-stack">
                <form method="POST" action="{{ route('admin.platform-catalog.category.store') }}" class="ui-cluster">
                    @csrf
                    <input class="ui-input ui-input--sm ui-grow" style="inline-size:auto" name="name" placeholder="{{ ___('Name') }}" aria-label="{{ ___('Name') }}" required>
                    <select class="ui-input ui-input--sm ui-input--auto" name="parent_id" style="max-inline-size:180px" aria-label="{{ ___('Top level') }}">
                        <option value="">{{ ___('Top level') }}</option>
                        @foreach($categories->whereNull('parent_id') as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select>
                    <x-ui.button type="submit" size="sm" icon="plus">{{ ___('Add') }}</x-ui.button>
                </form>
                <ul class="ui-list-box">
                    @foreach($categories as $c)
                    <li class="ui-list-box__item ui-cluster ui-cluster--between">
                        <span>@if($c->parent_id)&nbsp;&nbsp;↳ @endif{{ $c->name }}</span>
                        <form method="POST" action="{{ route('admin.platform-catalog.category.destroy', $c->id) }}">@csrf @method('DELETE')
                            <x-ui.button type="submit" variant="link" size="sm" class="ui-text-danger">{{ ___('Delete') }}</x-ui.button></form>
                    </li>
                    @endforeach
                </ul>
            </div>
        </x-ui.card>
        <x-ui.card :title="___('Global add-ons')">
            <div class="ui-stack">
                <form method="POST" action="{{ route('admin.platform-catalog.extra.store') }}" class="ui-cluster">
                    @csrf
                    <input class="ui-input ui-input--sm ui-grow" style="inline-size:auto" name="title" placeholder="{{ ___('Title') }}" aria-label="{{ ___('Title') }}" required>
                    <input class="ui-input ui-input--sm" name="price" type="number" step="0.001" placeholder="{{ ___('Price') }}" aria-label="{{ ___('Price') }}" style="max-inline-size:120px">
                    <x-ui.button type="submit" size="sm" icon="plus">{{ ___('Add') }}</x-ui.button>
                </form>
                <ul class="ui-list-box">
                    @foreach($extras as $e)
                    <li class="ui-list-box__item ui-cluster ui-cluster--between">
                        <span>{{ $e->title }} · {{ $e->price }}</span>
                        <form method="POST" action="{{ route('admin.platform-catalog.extra.destroy', $e->id) }}">@csrf @method('DELETE')
                            <x-ui.button type="submit" variant="link" size="sm" class="ui-text-danger">{{ ___('Delete') }}</x-ui.button></form>
                    </li>
                    @endforeach
                </ul>
            </div>
        </x-ui.card>
    </div>
</div>
@endsection
