{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    rev/multi-vertical Lane E (E3) — car-parts fitment landing page: every
    item in this store with its fitment count, linking into the per-item
    fitments + OEM manager. A brand-new surface, own page, own route file
    (routes/verticals/lane-e.php) — never edited into Lane A's retail item
    form/RetailItemController.
--}}
@extends('owner.layout')

@section('title', ___('Fitments').' - '.$store->title)
@section('page_title', ___('Vehicle fitments'))
@section('page_sub', $store->title)

@section('page_actions')
    @if(Route::has('retail.fitments.import'))
        <x-ui.button :href="route('retail.fitments.import', $store->id)" icon="upload">
            {{ ___('Import fitments') }}
        </x-ui.button>
    @endif
    @if(Route::has('retail.products.index'))
        <x-ui.button :href="route('retail.products.index', $store->id)" variant="secondary" icon="back">
            {{ ___('Back to :label', ['label' => $vocab['items']]) }}
        </x-ui.button>
    @endif
@endsection

@section('content')
<div class="ui-scope ui-fit">
    <x-ui.card :title="___(':label — fitments', ['label' => $vocab['items']])" variant="flush">
        <p class="ui-fit__hint">
            {{ ___('Pick a part to add or remove the vehicles it fits, bulk-add a model-year range, import a fitment CSV, or manage its OEM / cross-reference numbers.') }}
        </p>

        @if($items->isEmpty())
            <x-ui.empty :title="___('No records found.')" icon="products" />
        @else
            <div class="ui-list ui-fit__list">
                @foreach($items as $item)
                    <a class="ui-row" href="{{ route('retail.fitments.show', [$store->id, $item->id]) }}">
                        <span class="ui-row__body">
                            <span class="ui-row__title">{{ $item->getRawOriginal('name') }}</span>
                            <span class="ui-row__sub">{{ ___('Manage fitments') }}</span>
                        </span>
                        <span class="ui-row__trail">
                            <x-ui.tag :tone="$item->fitments_count ? 'accent' : 'neutral'">{{ $item->fitments_count }} {{ ___('Fitments') }}</x-ui.tag>
                            <x-ui.icon name="chevron" size="18" class="ui-row__chevron" />
                        </span>
                    </a>
                @endforeach
            </div>
        @endif

        @if($items->hasPages())
            <div class="ui-catalog__pager">{{ $items->links() }}</div>
        @endif
    </x-ui.card>
</div>
@endsection
