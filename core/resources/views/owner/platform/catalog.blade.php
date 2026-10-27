{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Menu library'))
@section('page_title', ___('Menu library'))
@section('page_sub', ___('Adopt ready-made categories and add-ons from the platform catalog into this store\'s menu.'))

@section('content')
<div class="ui-scope ui-pcat">
@if(session('success'))<x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>@endif
@if(session('error'))<x-ui.banner tone="danger">{{ session('error') }}</x-ui.banner>@endif

<div class="ui-pcat__grid">
    {{-- ============ Categories ============ --}}
    <x-ui.card :title="___('Category collections')" variant="flush">
        @if($categories->isEmpty())
            <x-ui.empty icon="list" :title="___('No platform categories are available yet.')" />
        @else
            <ul class="ui-pcat__list">
                @foreach($categories as $cat)
                    <li class="ui-pcat__row">
                        <div class="ui-pcat__text">
                            <span class="ui-pcat__name">{{ $cat->name }}</span>
                            @if($cat->children->count())
                                <span class="ui-pcat__sub">
                                    {{ ___('Includes') }}: {{ $cat->children->pluck('name')->take(6)->implode(', ') }}@if($cat->children->count() > 6) …@endif
                                </span>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('platform-catalog.adopt-category', $cat->id) }}"
                              data-confirm="{{ ___('Add this category (and its sub-categories) to your menu?') }}">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" size="sm" icon="plus">{{ ___('Adopt') }}</x-ui.button>
                        </form>
                    </li>
                @endforeach
            </ul>
            <p class="ui-pcat__sub ui-pcat__foot">
                {{ ___('Adopting copies the entries into your own menu — you can edit your copy freely. Re-adopting refreshes it from the catalog without creating duplicates.') }}
            </p>
        @endif
    </x-ui.card>

    {{-- ============ Add-ons ============ --}}
    <x-ui.card :title="___('Add-ons & extras')" variant="flush">
        @if($extras->isEmpty())
            <x-ui.empty icon="plus" :title="___('No platform add-ons are available yet.')" />
        @else
            <ul class="ui-pcat__list">
                @foreach($extras as $extra)
                    <li class="ui-pcat__row">
                        <div class="ui-pcat__text">
                            <span class="ui-pcat__name">{{ $extra->title }}</span>
                            <span class="ui-pcat__sub ui-ltr">{{ number_format((float) $extra->price, 2) }}</span>
                        </div>
                        <form method="POST" action="{{ route('platform-catalog.adopt-extra', $extra->id) }}">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" size="sm" icon="plus">{{ ___('Adopt') }}</x-ui.button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
</div>
@endsection
