{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Branch menu').' - '.$branch->name)
@section('page_title', ___('Branch menu & pricing'))
@section('page_sub', $branch->name)

@section('page_actions')
    <x-ui.button :href="route('branches.index', ['store_id' => $branch->store_id])" variant="secondary" icon="back">
        {{ ___('Back to branches') }}
    </x-ui.button>
@endsection

@section('content')
<div class="ui-stack">
    <div class="ui-card ui-card--inset">
        <div class="ui-card__body">
            <p class="ui-text-accent ui-small ui-caps">{{ ___('Branch') }}</p>
            <h2 class="ui-section-title">{{ $branch->name }}</h2>
            <span class="ui-text-2">{{ ___('Turn items off here, or override their price for this location. Blank price = base price.') }}</span>
        </div>
    </div>

    <x-ui.card :title="___('Items')">
        @if($menus->isEmpty())
            <div class="ui-text-2 ui-center">{{ ___('No menu items in this store.') }}</div>
        @else
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('Item') }}</th><th class="ui-end">{{ ___('Base price') }}</th>
                        <th class="ui-end">{{ ___('Branch price') }}</th><th>{{ ___('Available here') }}</th><th class="ui-end">{{ ___('Save') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($menus as $m)
                            @php $setting = $m->branchSettings->first(); @endphp
                            <tr>
                                <td data-label="{{ ___('Item') }}"><strong>{{ $m->getRawOriginal('name') }}</strong></td>
                                <td class="ui-end" data-label="{{ ___('Base price') }}">{{ price_symbol_format($m->price) }}</td>
                                <td colspan="3">
                                    <form method="post" action="{{ route('branches.menu.update', $branch->id) }}" class="ui-cluster ui-cluster--end">
                                        @csrf
                                        <input type="hidden" name="menu_id" value="{{ $m->id }}">
                                        <input class="ui-input" type="number" step="0.01" min="0" name="price"
                                            value="{{ $setting && $setting->price !== null ? rtrim(rtrim(number_format($setting->price,2),'0'),'.') : '' }}"
                                            placeholder="{{ ___('base') }}" style="inline-size:100px">
                                        <label class="ui-check"><input type="checkbox" name="available" value="1" @checked(!$setting || $setting->available)><span>{{ ___('Available') }}</span></label>
                                        <x-ui.button type="submit" variant="secondary" size="sm" icon="check">{{ ___('Save') }}</x-ui.button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
@endsection
