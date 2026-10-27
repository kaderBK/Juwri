{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Import from GloriaFood'))
@section('page_title', ___('Import from GloriaFood'))
@section('page_sub', ___('Upload your GloriaFood menu export (CSV) to bring your menu across.'))

@section('page_actions')
    <x-ui.button :href="route('integrations.docs', 'gloriafood-import')" target="_blank" rel="noopener" variant="secondary" size="sm" icon="book-open">{{ ___('Setup guide') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-gfi">
<x-ui.card :title="___('Upload menu CSV')">
    <form method="post" action="{{ route('stores.import.gloriafood.preview') }}?store_id={{ $rid }}" enctype="multipart/form-data" class="ui-gfi__row">
        @csrf
        <label class="ui-visually-hidden" for="gfi-menu-file">{{ ___('Upload menu CSV') }}</label>
        <input id="gfi-menu-file" type="file" name="file" accept=".csv,.txt" class="ui-input" required>
        <x-busy-button type="submit" variant="primary" :busy-label="___('Reading…')">{{ ___('Preview') }}</x-busy-button>
    </form>
    <p class="ui-gfi__hint">{{ ___('In GloriaFood: Settings → Menu → Export, then save as CSV.') }}</p>
</x-ui.card>

@if($preview)
<x-ui.card :title="___('Preview')">
    @if(!empty($preview['errors']))
        <x-ui.banner tone="danger">{{ implode(' ', $preview['errors']) }}</x-ui.banner>
    @endif
    <div class="ui-gfi__stats">
        <x-ui.stat :value="$preview['categories_created']" :label="___('new categories')" icon="list" />
        <x-ui.stat :value="$preview['items_created']" :label="___('new items')" icon="products" tone="success" />
        <x-ui.stat :value="$preview['skipped']" :label="___('skipped (existing)')" icon="refresh" tone="warning" />
    </div>
    @if(!empty($preview['sample']))
    <div class="ui-gfi__table">
        <table class="ui-table">
            <thead><tr><th>{{ ___('Item') }}</th><th>{{ ___('Category') }}</th><th class="ui-table__num">{{ ___('Price') }}</th></tr></thead>
            <tbody>
            @foreach($preview['sample'] as $row)
                <tr><td data-primary>{{ $row['name'] }}</td><td data-label="{{ ___('Category') }}">{{ $row['category'] }}</td><td class="ui-table__num" data-label="{{ ___('Price') }}"><span class="ui-ltr">{{ number_format($row['price'], 2) }}</span></td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
    @if($preview['items_created'] > 0 || $preview['categories_created'] > 0)
    <form method="post" action="{{ route('stores.import.gloriafood.import') }}?store_id={{ $rid }}" class="ui-gfi__confirm">
        @csrf
        <input type="hidden" name="csv" value="{{ $csv }}">
        <x-busy-button type="submit" variant="success" :busy-label="___('Importing…')">{{ ___('Confirm import') }}</x-busy-button>
    </form>
    @endif
</x-ui.card>
@endif

<div class="ui-gfi__grid">
    <x-ui.card :title="___('Import customers')">
        <p class="ui-gfi__hint ui-gfi__hint--top">{{ ___('Customers are imported without marketing consent (compliance).') }}</p>
        <form method="post" action="{{ route('stores.import.gloriafood.customers') }}?store_id={{ $rid }}" enctype="multipart/form-data" class="ui-gfi__row">
            @csrf
            <label class="ui-visually-hidden" for="gfi-cust-file">{{ ___('Import customers') }}</label>
            <input id="gfi-cust-file" type="file" name="file" accept=".csv,.txt" class="ui-input" required>
            <x-busy-button type="submit" variant="secondary" :busy-label="___('Importing…')">{{ ___('Import') }}</x-busy-button>
        </form>
    </x-ui.card>
    <x-ui.card :title="___('Import menu from your live page')">
        <p class="ui-gfi__hint ui-gfi__hint--top">{{ ___('Paste your public GloriaFood ordering URL.') }}</p>
        <form method="post" action="{{ route('stores.import.gloriafood.widget') }}?store_id={{ $rid }}" class="ui-gfi__row">
            @csrf
            <label class="ui-visually-hidden" for="gfi-widget-url">{{ ___('Import menu from your live page') }}</label>
            <input id="gfi-widget-url" type="url" name="widget_url" class="ui-input ui-ltr" placeholder="https://..." required>
            <x-busy-button type="submit" variant="secondary" :busy-label="___('Fetching…')">{{ ___('Fetch') }}</x-busy-button>
        </form>
    </x-ui.card>
</div>

@if($batches->count())
<x-ui.card :title="___('Recent imports')" variant="flush">
    <x-ui.list class="ui-gfi__list">
        @foreach($batches as $b)
            <x-ui.row icon="upload" :title="$b->items_created.' '.___('items').', '.$b->categories_created.' '.___('categories')" :sub="$b->created_at->diffForHumans()" />
        @endforeach
    </x-ui.list>
</x-ui.card>
@endif
</div>
@endsection
