{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Suppliers'))
@section('page_title', ___('Suppliers'))
@section('page_sub', ___('Vendors you buy ingredients from.'))

@section('page_actions')
    <x-ui.button :href="route('inventory.purchaseOrders.index', $storeId ? ['store_id' => $storeId] : [])" variant="secondary" size="sm" icon="orders">
        {{ ___('Purchase orders') }}
    </x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-suppliers" x-data="{ editId: null, edit: {} }">
    @if($stores->count() > 1)
        <form method="get" action="{{ route('inventory.suppliers.index') }}" class="ui-suppliers__context">
            <select name="store_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Store') }}">
                <option value="">{{ ___('All stores') }}</option>
                @foreach ($stores as $r)
                    <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
                @endforeach
            </select>
        </form>
    @endif

    <x-ui.card :title="___('Add supplier')">
        <form method="post" action="{{ route('inventory.suppliers.store') }}" class="ui-suppliers__add">
            @csrf
            @if($storeId)
                <input type="hidden" name="store_id" value="{{ $storeId }}">
            @else
                <div class="ui-suppliers__field">
                    <label class="ui-label" for="sp-store">{{ ___('Store') }}</label>
                    <select id="sp-store" name="store_id" class="ui-input" required>
                        <option value="">{{ ___('Select…') }}</option>
                        @foreach ($stores as $r)<option value="{{ $r->id }}">{{ $r->title }}</option>@endforeach
                    </select>
                </div>
            @endif
            <div class="ui-suppliers__field"><label class="ui-label" for="sp-name">{{ ___('Name') }}</label><input id="sp-name" class="ui-input" name="name" required></div>
            <div class="ui-suppliers__field"><label class="ui-label" for="sp-contact">{{ ___('Contact') }}</label><input id="sp-contact" class="ui-input" name="contact_name"></div>
            <div class="ui-suppliers__field"><label class="ui-label" for="sp-email">{{ ___('Email') }}</label><input id="sp-email" class="ui-input ui-ltr" name="email" type="email" inputmode="email"></div>
            <div class="ui-suppliers__field"><label class="ui-label" for="sp-phone">{{ ___('Phone') }}</label><input id="sp-phone" class="ui-input ui-ltr" name="phone" inputmode="tel"></div>
            <div class="ui-suppliers__field"><label class="ui-label" for="sp-lead">{{ ___('Lead time (days)') }}</label><input id="sp-lead" class="ui-input" name="lead_time_days" type="number" min="0" value="0" inputmode="numeric"></div>
            <div class="ui-suppliers__submit"><x-ui.button type="submit" icon="plus">{{ ___('Add supplier') }}</x-ui.button></div>
        </form>
    </x-ui.card>

    <x-ui.card :title="___('Suppliers')" variant="flush">
        <x-slot:actions><x-ui.tag>{{ $suppliers->count() }}</x-ui.tag></x-slot:actions>
        @if($suppliers->isEmpty())
            <x-ui.empty :title="___('No suppliers yet')" :body="___('Add your first vendor above.')" icon="users" />
        @else
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Name') }}</th><th>{{ ___('Contact') }}</th><th>{{ ___('Phone') }}</th>
                    <th class="ui-table__num">{{ ___('Lead time') }}</th><th>{{ ___('Status') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                </tr></thead>
                <tbody>
                    @foreach ($suppliers as $s)
                        <tr>
                            <td data-primary><strong>{{ $s->name }}</strong></td>
                            <td data-label="{{ ___('Contact') }}">
                                <span class="ui-suppliers__contact">{{ $s->contact_name ?: '—' }}@if($s->email)<small class="ui-ltr">{{ $s->email }}</small>@endif</span>
                            </td>
                            <td data-label="{{ ___('Phone') }}"><span class="ui-ltr">{{ $s->phone ?: '—' }}</span></td>
                            <td class="ui-table__num" data-label="{{ ___('Lead time') }}"><span><span class="ui-ltr">{{ $s->lead_time_days }}</span> {{ ___('d') }}</span></td>
                            <td data-label="{{ ___('Status') }}">
                                @if($s->active)<x-ui.pill tone="success">{{ ___('Active') }}</x-ui.pill>
                                @else<x-ui.pill>{{ ___('Inactive') }}</x-ui.pill>@endif
                            </td>
                            <td class="ui-catalog__row-actions">
                                <a class="ui-icon-btn" href="{{ route('inventory.suppliers.show', $s->id) }}" aria-label="{{ ___('Balance') }}" title="{{ ___('Balance') }}"><x-ui.icon name="wallet" /></a>
                                <button type="button" class="ui-icon-btn" aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}"
                                    @click="editId = {{ $s->id }}; edit = @js(['name'=>$s->name,'contact_name'=>$s->contact_name,'email'=>$s->email,'phone'=>$s->phone,'lead_time_days'=>$s->lead_time_days,'active'=>(bool)$s->active]); $refs.editDialog.showModal()">
                                    <x-ui.icon name="pencil" />
                                </button>
                                <form method="post" action="{{ route('inventory.suppliers.destroy', $s->id) }}" data-confirm="{{ ___('Delete this supplier?') }}" data-confirm-danger>
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

    <x-ui.sheet id="supplier-edit" :title="___('Edit supplier')" x-ref="editDialog">
        <form method="post" :action="@js(url('inventory/suppliers')) + '/' + editId" id="supplier-edit-form" class="ui-suppliers__sheet-form">
            @csrf @method('PUT')
            <div><label class="ui-label" for="se-sname">{{ ___('Name') }}</label><input id="se-sname" class="ui-input" name="name" x-model="edit.name" required></div>
            <div><label class="ui-label" for="se-scontact">{{ ___('Contact') }}</label><input id="se-scontact" class="ui-input" name="contact_name" x-model="edit.contact_name"></div>
            <div><label class="ui-label" for="se-semail">{{ ___('Email') }}</label><input id="se-semail" class="ui-input ui-ltr" name="email" type="email" x-model="edit.email" inputmode="email"></div>
            <div><label class="ui-label" for="se-sphone">{{ ___('Phone') }}</label><input id="se-sphone" class="ui-input ui-ltr" name="phone" x-model="edit.phone" inputmode="tel"></div>
            <div><label class="ui-label" for="se-slead">{{ ___('Lead time (days)') }}</label><input id="se-slead" class="ui-input" name="lead_time_days" type="number" min="0" x-model="edit.lead_time_days" inputmode="numeric"></div>
            <label class="ui-check"><input type="checkbox" id="sup-active" name="active" value="1" :checked="edit.active" @change="edit.active = $event.target.checked"><span>{{ ___('Active') }}</span></label>
        </form>
        <x-slot:footer>
            <x-ui.button type="button" variant="secondary" @click="$refs.editDialog.close()">{{ ___('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" form="supplier-edit-form" icon="check">{{ ___('Save') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.sheet>
</div>
@endsection
