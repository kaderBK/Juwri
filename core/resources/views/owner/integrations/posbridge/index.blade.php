{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('POS bridge'))
@section('page_title', ___('POS bridge'))
@section('page_sub', ___('Push your paid orders into Square or Clover.'))

@section('page_actions')
    <x-ui.button :href="route('integrations.docs', 'pos-bridge-square-clover')" target="_blank" rel="noopener" variant="secondary" size="sm" icon="book-open">{{ ___('Setup guide') }}</x-ui.button>
    <form method="get" action="{{ route('stores.posbridge.index') }}">
        <label class="ui-visually-hidden" for="pb-store">{{ ___('Store') }}</label>
        <select id="pb-store" name="store_id" class="ui-input ui-input--sm" data-ui-do="submit">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
<div class="ui-scope ui-pb">

@if(session('success'))
    <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
@endif

<x-ui.card :title="___('Connect a POS')">
    <form method="post" action="{{ route('stores.posbridge.connect') }}?store_id={{ $rid }}" class="ui-pb__form">
        @csrf
        <div class="ui-pb__fields">
            <div>
                <label class="ui-label" for="pb-provider">{{ ___('Provider') }}</label>
                <select id="pb-provider" name="provider" class="ui-input" required>
                    @foreach($providers as $p)
                        <option value="{{ $p }}">{{ ucfirst($p) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ui-label" for="pb-location">{{ ___('Location / Merchant id') }}</label>
                <input id="pb-location" name="external_location_id" class="ui-input" required>
            </div>
            <div class="ui-pb__wide">
                <label class="ui-label" for="pb-token">{{ ___('Access token (BYOK)') }}</label>
                <input id="pb-token" name="access_token" class="ui-input" required autocomplete="off">
            </div>
        </div>
        <div class="ui-pb__foot">
            <small class="ui-pb__hint">{{ ___('Tokens are stored encrypted. Toast is coming soon (partner approval required).') }}</small>
            <x-ui.button type="submit" icon="plus">{{ ___('Connect') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

<x-ui.card :title="___('Connections')" variant="flush">
    @if($connections->isEmpty())
        <x-ui.empty icon="kitchen" :title="___('No POS connected yet.')" />
    @else
        <table class="ui-table">
            <thead><tr>
                <th>{{ ___('Provider') }}</th><th>{{ ___('Location') }}</th>
                <th>{{ ___('Status') }}</th><th>{{ ___('Last push') }}</th><th>{{ ___('Last error') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
            </tr></thead>
            <tbody>
            @foreach($connections as $c)
                <tr>
                    <td data-primary>{{ ucfirst($c->provider) }}</td>
                    <td data-label="{{ ___('Location') }}"><code>{{ $c->external_location_id }}</code></td>
                    <td data-label="{{ ___('Status') }}"><x-ui.tag>{{ ___($c->status) }}</x-ui.tag></td>
                    <td data-label="{{ ___('Last push') }}">{{ $c->last_push_at ? $c->last_push_at->diffForHumans() : '—' }}</td>
                    <td data-label="{{ ___('Last error') }}"><span class="ui-pb__error">{{ $c->push_error ?: '—' }}</span></td>
                    <td class="ui-catalog__row-actions">
                        <form method="post" action="{{ route('stores.posbridge.disconnect', $c->provider) }}?store_id={{ $rid }}" data-confirm="{{ ___('Remove this connection?') }}" data-confirm-danger>
                            @csrf
                            <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Remove') }}" title="{{ ___('Remove') }}"><x-ui.icon name="trash" /></button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</x-ui.card>

@if(!$connections->isEmpty())
<x-ui.card :title="___('Item mapping')" variant="flush">
    <p class="ui-pb__hint ui-pb__pad">{{ ___('Map each menu item to its POS id so orders reference real POS items (unmapped items push as ad-hoc lines and may not kitchen-print).') }}</p>
    @foreach($connections as $c)
        <h3 class="ui-pb__provider">{{ ucfirst($c->provider) }}</h3>
        <ul class="ui-pb__maps">
            @foreach($menus as $m)
                @php $mapped = optional($maps->get($m->id))->firstWhere('provider', $c->provider); @endphp
                <li class="ui-pb__map">
                    <label class="ui-pb__map-name" for="pb-map-{{ $c->id }}-{{ $m->id }}">{{ $m->name }}</label>
                    <form method="post" action="{{ route('stores.posbridge.mapping') }}?store_id={{ $rid }}" class="ui-pb__map-form">
                        @csrf
                        <input type="hidden" name="provider" value="{{ $c->provider }}">
                        <input type="hidden" name="menu_id" value="{{ $m->id }}">
                        <input id="pb-map-{{ $c->id }}-{{ $m->id }}" name="external_id" class="ui-input ui-input--sm ui-ltr" value="{{ $mapped->external_id ?? '' }}" placeholder="{{ ___('POS id') }}">
                        <x-ui.button type="submit" variant="secondary" size="sm">{{ ___('Save') }}</x-ui.button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endforeach
</x-ui.card>
@endif

<x-ui.card :title="___('Push log')" variant="flush">
    @if($log->isEmpty())
        <x-ui.empty icon="list" :title="___('No pushes yet.')" />
    @else
        <table class="ui-table">
            <thead><tr>
                <th>{{ ___('Order') }}</th><th>{{ ___('Provider') }}</th>
                <th>{{ ___('Status') }}</th><th>{{ ___('POS id') }}</th><th>{{ ___('Error') }}</th><th>{{ ___('When') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
            </tr></thead>
            <tbody>
            @foreach($log as $row)
                <tr>
                    <td data-primary><strong class="ui-ltr">#{{ $row->order_id }}</strong></td>
                    <td data-label="{{ ___('Provider') }}">{{ ucfirst($row->provider) }}</td>
                    <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$row->status === 'pushed' ? 'success' : ($row->status === 'failed' ? 'danger' : 'neutral')"
                              :title="$row->status === 'pending' ? ___('Retrying automatically') : ''">{{ ___($row->status) }}</x-ui.pill></td>
                    <td data-label="{{ ___('POS id') }}"><code>{{ $row->external_id ?: '—' }}</code></td>
                    <td data-label="{{ ___('Error') }}"><span class="ui-pb__error">{{ $row->error ?: '—' }}</span></td>
                    <td data-label="{{ ___('When') }}">{{ $row->created_at?->diffForHumans() }}</td>
                    <td class="ui-catalog__row-actions">
                        @if($row->status !== 'pushed')
                            <form method="post" action="{{ route('stores.posbridge.retry', $row->id) }}?store_id={{ $rid }}">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" size="sm" icon="refresh">{{ ___('Retry') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</x-ui.card>

</div>
@endsection
