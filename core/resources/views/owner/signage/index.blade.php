{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Signage'))
@section('page_title', ___('Signage'))
@section('page_sub', ___('Open a menu or pickup board on any TV — just the URL.'))

@section('page_actions')
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
<div class="ui-scope ui-signage">
@if(session('success'))
    <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
@endif

<x-ui.card :title="___('Add a screen')">
    <form method="post" action="{{ route('stores.signage.store') }}?store_id={{ $rid }}" class="ui-signage__add">
        @csrf
        <div>
            <label class="ui-label" for="signage-kind">{{ ___('Kind') }}</label>
            <select id="signage-kind" name="kind" class="ui-input" required>
                <option value="menu">{{ ___('Menu board') }}</option>
                <option value="pickup">{{ ___('Pickup / status') }}</option>
            </select>
        </div>
        <div><label class="ui-label" for="signage-name">{{ ___('Name') }}</label><input id="signage-name" name="name" class="ui-input" placeholder="{{ ___('e.g. Front counter TV') }}"></div>
        <div><x-ui.button type="submit" icon="plus" block data-ui-busy>{{ ___('Create') }}</x-ui.button></div>
    </form>
</x-ui.card>

@forelse($screens as $s)
    <section class="ui-card ui-signage__screen">
        <div class="ui-signage__qr">{!! $s['qr'] !!}</div>
        <div class="ui-signage__body">
            <div class="ui-signage__title">
                <h2 class="ui-card__title">{{ $s['model']->name ?: ucfirst($s['model']->kind) }}</h2>
                <x-ui.pill :tone="$s['model']->active ? 'success' : 'neutral'">{{ $s['model']->active ? ___('active') : ___('off') }}</x-ui.pill>
            </div>
            <p class="ui-signage__kind">{{ ucfirst($s['model']->kind) }} {{ ___('board') }}</p>
            <div class="ui-signage__url">
                <input class="ui-input ui-ltr" dir="ltr" value="{{ $s['url'] }}" readonly data-ui-do="select" aria-label="{{ $s['model']->name ?: ucfirst($s['model']->kind) }}">
                <x-ui.button :href="$s['url']" variant="secondary" size="sm" target="_blank" rel="noopener">{{ ___('Open') }}</x-ui.button>
            </div>
            <div class="ui-signage__actions">
                <form method="post" action="{{ route('stores.signage.toggle', $s['model']->id) }}?store_id={{ $rid }}">@csrf
                    <x-ui.button type="submit" variant="secondary" size="sm" icon="pause">{{ $s['model']->active ? ___('Turn off') : ___('Turn on') }}</x-ui.button>
                </form>
                <form method="post" action="{{ route('stores.signage.regenerate', $s['model']->id) }}?store_id={{ $rid }}" data-confirm="{{ ___('Regenerate token? The old URL stops working.') }}">@csrf
                    <x-ui.button type="submit" variant="secondary" size="sm" icon="refresh">{{ ___('New token') }}</x-ui.button>
                </form>
                <form method="post" action="{{ route('stores.signage.destroy', $s['model']->id) }}?store_id={{ $rid }}" data-confirm="{{ ___('Delete this screen?') }}" data-confirm-danger>@csrf @method('DELETE')
                    <x-ui.button type="submit" variant="danger" size="sm" icon="trash">{{ ___('Delete') }}</x-ui.button>
                </form>
            </div>
        </div>
    </section>
@empty
    <div class="ui-card">
        <x-ui.empty icon="qr" :title="___('No screens yet — add one above.')" />
    </div>
@endforelse
</div>
@endsection
