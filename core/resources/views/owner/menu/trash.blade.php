{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Trash').' - '.$store->title)
@section('page_title', ___('Trash'))
@section('page_sub', $store->title)

@section('page_actions')
    <x-ui.button :href="route('stores.menu', $store->id)" variant="secondary">
        <span class="ui-btn__icon"><x-ui.icon name="back" :flip="true" /></span>
        {{ ___('Back to menu') }}
    </x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-trash">
    <x-ui.banner>{{ ___('Deleted items are kept for 30 days, then permanently removed. Restore any item to bring it back.') }}</x-ui.banner>

    @if($trashed->isEmpty())
        <div class="ui-card">
            <x-ui.empty icon="trash" :title="___('Trash is empty.')" />
        </div>
    @else
        <section class="ui-card ui-card--flush">
            @if(Route::has('stores.menu.restore'))
                <div class="ui-card__head">
                    <h2 class="ui-card__title"><span class="ui-ltr">{{ $trashed->count() }}</span> · {{ ___('Deleted') }}</h2>
                    <div class="ui-card__actions">
                        <form method="POST" action="{{ route('stores.menu.restore', $store->id) }}">
                            @csrf
                            @foreach($trashed as $m)
                                <input type="hidden" name="ids[]" value="{{ $m->id }}">
                            @endforeach
                            <x-ui.button type="submit" size="sm" icon="refresh">{{ ___('Restore all') }}</x-ui.button>
                        </form>
                    </div>
                </div>
            @endif

            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Item') }}</th>
                        <th class="ui-table__num">{{ ___('Price') }}</th>
                        <th>{{ ___('Deleted') }}</th>
                        <th><span class="ui-visually-hidden">{{ ___('Action') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($trashed as $m)
                        <tr>
                            <td data-primary>{{ $m->name }}</td>
                            <td class="ui-table__num" data-label="{{ ___('Price') }}">{{ price_symbol_format($m->price) }}</td>
                            <td data-label="{{ ___('Deleted') }}">{{ optional($m->deleted_at)->diffForHumans() }}</td>
                            <td class="ui-catalog__row-actions">
                                @if(Route::has('stores.menu.restore'))
                                    <form method="POST" action="{{ route('stores.menu.restore', $store->id) }}">
                                        @csrf
                                        <input type="hidden" name="ids[]" value="{{ $m->id }}">
                                        <x-ui.button type="submit" variant="secondary" size="sm">{{ ___('Restore') }}</x-ui.button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
@endsection
