{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Kitchen stations'))
@section('page_title', ___('Kitchen stations'))
@section('page_sub', ___('Route menu items to stations. Each station sees only its items; set target times and an Expo.'))

@section('page_actions')
    <x-ui.button :href="route('kds.index', ['store_id' => $storeId])" variant="secondary" icon="kitchen">
        {{ ___('Open KDS') }}
    </x-ui.button>
@endsection

@section('content')
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('kds')])

<div class="ui-scope ui-stations" x-data="{ editId: null, edit: {} }">
    <form method="get" action="{{ route('stations.index') }}" class="ui-stations__context">
        <select name="store_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            <option value="">{{ ___('Select store…') }}</option>
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
        @if($branches->count())
            <select name="branch_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Branch') }}">
                <option value="">{{ ___('Store-wide assignment') }}</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}" @selected($branchId == $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        @endif
    </form>

    @if(!$storeId)
        <div class="ui-card"><x-ui.empty :title="___('Pick a store to manage its kitchen stations.')" icon="kitchen" /></div>
    @else
        <x-ui.card :title="___('Add station')">
            <form method="post" action="{{ route('stations.store') }}" class="ui-stations__add">
                @csrf
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                <div class="ui-stations__field ui-stations__field--grow">
                    <label class="ui-label" for="st-name">{{ ___('Name') }}</label>
                    <input id="st-name" class="ui-input" name="name" placeholder="{{ ___('Grill') }}" required>
                </div>
                <div class="ui-stations__field">
                    <label class="ui-label" for="st-color">{{ ___('Colour') }}</label>
                    <input id="st-color" class="ui-input" name="display_color" type="color" value="#6366f1">
                </div>
                <div class="ui-stations__field">
                    <label class="ui-label" for="st-target">{{ ___('Target (min)') }}</label>
                    <input id="st-target" class="ui-input" name="target_minutes" type="number" min="1" max="240" value="10" inputmode="numeric">
                </div>
                @if($branches->count())
                    <div class="ui-stations__field ui-stations__field--grow">
                        <label class="ui-label" for="st-branch">{{ ___('Branch') }}</label>
                        <select id="st-branch" class="ui-input" name="branch_id">
                            <option value="">{{ ___('All branches') }}</option>
                            @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                        </select>
                    </div>
                @endif
                <label class="ui-check ui-stations__check"><input type="checkbox" name="is_expo" value="1"><span>{{ ___('Expo') }}</span></label>
                <x-ui.button type="submit" icon="plus">{{ ___('Add station') }}</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card :title="___('Stations')" variant="flush">
            <x-slot:actions><x-ui.tag>{{ $stations->count() }}</x-ui.tag></x-slot:actions>
            @if($stations->isEmpty())
                <x-ui.empty :title="___('No stations yet. All items go to one default kitchen view.')" icon="kitchen" />
            @else
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('Station') }}</th>
                        <th class="ui-table__num">{{ ___('Target') }}</th>
                        <th>{{ ___('Type') }}</th>
                        <th>{{ ___('Branch') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th class="ui-table__num">{{ ___('Items') }}</th>
                        <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($stations as $s)
                            <tr>
                                <td data-primary>
                                    <span class="ui-stations__name">
                                        <span class="ui-stations__swatch" style="background: {{ $s->display_color }}" aria-hidden="true"></span>
                                        <strong>{{ $s->name }}</strong>
                                    </span>
                                </td>
                                <td class="ui-table__num" data-label="{{ ___('Target') }}"><span class="ui-ltr">{{ $s->target_minutes }}</span> {{ ___('min') }}</td>
                                <td data-label="{{ ___('Type') }}">@if($s->is_expo)<x-ui.tag tone="accent">{{ ___('Expo') }}</x-ui.tag>@else<x-ui.tag>{{ ___('Station') }}</x-ui.tag>@endif</td>
                                <td data-label="{{ ___('Branch') }}">{{ $s->branch_id ? ($branches->firstWhere('id', $s->branch_id)->name ?? '—') : ___('All') }}</td>
                                <td data-label="{{ ___('Status') }}">
                                    @if($s->active)<x-ui.pill tone="success">{{ ___('Active') }}</x-ui.pill>
                                    @else<x-ui.pill>{{ ___('Inactive') }}</x-ui.pill>@endif
                                </td>
                                <td class="ui-table__num" data-label="{{ ___('Items') }}">{{ $menus->where('station_id', $s->id)->count() }}</td>
                                <td class="ui-catalog__row-actions">
                                    <button type="button" class="ui-icon-btn" aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}"
                                        @click="editId = {{ $s->id }}; edit = @js(['name'=>$s->name,'display_color'=>$s->display_color,'active'=>(bool)$s->active,'target_minutes'=>$s->target_minutes,'is_expo'=>(bool)$s->is_expo,'branch_id'=>$s->branch_id]); $refs.editDialog.showModal()">
                                        <x-ui.icon name="pencil" />
                                    </button>
                                    <form method="post" action="{{ route('stations.destroy', $s->id) }}" data-confirm="{{ ___('Delete this station? Its items become unassigned.') }}" data-confirm-danger>
                                        @csrf @method('DELETE')
                                        <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-ui.card>

        {{-- Assign items to stations --}}
        <x-ui.card :title="___('Assign items to stations')" variant="flush">
            @if($branchId)
                <x-slot:actions><x-ui.tag tone="accent">{{ ___('Per-branch') }}: {{ $branches->firstWhere('id', $branchId)->name ?? '' }}</x-ui.tag></x-slot:actions>
            @endif
            @if($branchId)
                <p class="ui-stations__hint">{{ ___('Overrides for this branch. Leave “Default” to use the store-wide assignment.') }}</p>
            @endif
            @if($menus->isEmpty())
                <x-ui.empty :title="___('No menu items in this store.')" icon="products" />
            @else
                <ul class="ui-list ui-stations__assign">
                    @foreach ($menus as $m)
                        @php $current = $branchId ? ($pivot->get($m->id)->station_id ?? null) : $m->station_id; @endphp
                        <li class="ui-stations__assign-row">
                            <strong class="ui-stations__item">{{ $m->getRawOriginal('name') }}</strong>
                            <form method="post" action="{{ route('stations.assign') }}">
                                @csrf
                                <input type="hidden" name="menu_id" value="{{ $m->id }}">
                                @if($branchId)<input type="hidden" name="branch_id" value="{{ $branchId }}">@endif
                                <select name="station_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Station') }}">
                                    <option value="">{{ $branchId ? ___('— Default —') : ___('— None —') }}</option>
                                    @foreach($stations as $s)
                                        <option value="{{ $s->id }}" @selected($current == $s->id)>{{ $s->name }}</option>
                                    @endforeach
                                </select>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.sheet id="station-edit" :title="___('Edit station')" x-ref="editDialog">
            <form method="post" :action="@js(url('kds/stations')) + '/' + editId" id="station-edit-form" class="ui-stations__sheet-form">
                @csrf @method('PUT')
                <div><label class="ui-label" for="se-name">{{ ___('Name') }}</label><input id="se-name" class="ui-input" name="name" x-model="edit.name" required></div>
                <div><label class="ui-label" for="se-color">{{ ___('Colour') }}</label><input id="se-color" class="ui-input" name="display_color" type="color" x-model="edit.display_color"></div>
                <div><label class="ui-label" for="se-target">{{ ___('Target (min)') }}</label><input id="se-target" class="ui-input" name="target_minutes" type="number" min="1" max="240" x-model="edit.target_minutes" inputmode="numeric"></div>
                @if($branches->count())
                    <div>
                        <label class="ui-label" for="se-branch">{{ ___('Branch') }}</label>
                        <select id="se-branch" class="ui-input" name="branch_id" x-model="edit.branch_id">
                            <option value="">{{ ___('All branches') }}</option>
                            @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                        </select>
                    </div>
                @endif
                <label class="ui-check"><input type="checkbox" name="is_expo" value="1" :checked="edit.is_expo" @change="edit.is_expo = $event.target.checked"><span>{{ ___('Expo') }}</span></label>
                <label class="ui-check"><input type="checkbox" name="active" value="1" :checked="edit.active" @change="edit.active = $event.target.checked"><span>{{ ___('Active') }}</span></label>
            </form>
            <x-slot:footer>
                <x-ui.button type="button" variant="secondary" @click="$refs.editDialog.close()">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" form="station-edit-form" icon="check">{{ ___('Save') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.sheet>
    @endif
</div>
@endsection
