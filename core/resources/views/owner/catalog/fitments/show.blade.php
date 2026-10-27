{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    rev/multi-vertical Lane E3/E4/E5 — per-item vehicle fitments + OEM/
    cross-reference number manager. Own page, own controller
    (FitmentController), never edited into Lane A's retail item form.
--}}
@extends('owner.layout')

@section('title', ___('Fitments').' - '.$item->getRawOriginal('name'))
@section('page_title', $item->getRawOriginal('name'))
@section('page_sub', ___('Vehicle fitments'))

@section('page_actions')
    <x-ui.button :href="route('retail.fitments.index', $store->id)" variant="secondary" icon="back">
        {{ ___('Back to fitments') }}
    </x-ui.button>
    @if(Route::has('retail.products.edit'))
        <x-ui.button :href="route('retail.products.edit', [$store->id, $item->id])" variant="secondary" icon="pencil">
            {{ ___('Edit item') }}
        </x-ui.button>
    @endif
@endsection

@section('content')
<div class="ui-scope ui-fit">

<x-ui.card :title="___('Add a fitment')">
    <form method="POST" action="{{ route('retail.fitments.store', [$store->id, $item->id]) }}" class="ui-fit__form">
        @csrf
        <div class="ui-fit__field ui-fit__field--year">
            <label class="ui-label" for="fit-year">{{ ___('Year') }}</label>
            <input id="fit-year" type="number" name="year" class="ui-input ui-ltr" min="1900" max="2100" required inputmode="numeric">
        </div>
        <div class="ui-fit__field">
            <label class="ui-label" for="fit-make">{{ ___('Make') }}</label>
            <input id="fit-make" type="text" name="make" class="ui-input" required>
        </div>
        <div class="ui-fit__field">
            <label class="ui-label" for="fit-model">{{ ___('Model') }}</label>
            <input id="fit-model" type="text" name="model" class="ui-input" required>
        </div>
        <div class="ui-fit__field">
            <label class="ui-label" for="fit-sub">{{ ___('Submodel') }}</label>
            <input id="fit-sub" type="text" name="submodel" class="ui-input">
        </div>
        <div class="ui-fit__field">
            <label class="ui-label" for="fit-engine">{{ ___('Engine') }}</label>
            <input id="fit-engine" type="text" name="engine" class="ui-input">
        </div>
        <div class="ui-fit__field ui-fit__field--wide">
            <label class="ui-label" for="fit-notes">{{ ___('Notes') }}</label>
            <input id="fit-notes" type="text" name="notes" class="ui-input" maxlength="2000">
        </div>
        <div class="ui-fit__submit">
            <x-ui.button type="submit" icon="plus">{{ ___('Add') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

<x-ui.card :title="___('Bulk add by model-year range')">
    <p class="ui-fit__hint ui-fit__hint--in">{{ ___('Adds one row per year in the range (e.g. 2015 to 2018 = 4 rows). Already-covered years are skipped.') }}</p>

    <form method="POST" action="{{ route('retail.fitments.bulkStore', [$store->id, $item->id]) }}" class="ui-fit__form">
        @csrf
        <div class="ui-fit__field ui-fit__field--year">
            <label class="ui-label" for="fitb-from">{{ ___('From year') }}</label>
            <input id="fitb-from" type="number" name="year_from" class="ui-input ui-ltr" min="1900" max="2100" required inputmode="numeric">
        </div>
        <div class="ui-fit__field ui-fit__field--year">
            <label class="ui-label" for="fitb-to">{{ ___('To year') }}</label>
            <input id="fitb-to" type="number" name="year_to" class="ui-input ui-ltr" min="1900" max="2100" required inputmode="numeric">
        </div>
        <div class="ui-fit__field">
            <label class="ui-label" for="fitb-make">{{ ___('Make') }}</label>
            <input id="fitb-make" type="text" name="make" class="ui-input" required>
        </div>
        <div class="ui-fit__field">
            <label class="ui-label" for="fitb-model">{{ ___('Model') }}</label>
            <input id="fitb-model" type="text" name="model" class="ui-input" required>
        </div>
        <div class="ui-fit__field">
            <label class="ui-label" for="fitb-sub">{{ ___('Submodel') }}</label>
            <input id="fitb-sub" type="text" name="submodel" class="ui-input">
        </div>
        <div class="ui-fit__field">
            <label class="ui-label" for="fitb-engine">{{ ___('Engine') }}</label>
            <input id="fitb-engine" type="text" name="engine" class="ui-input">
        </div>
        <div class="ui-fit__field ui-fit__field--wide">
            <label class="ui-label" for="fitb-notes">{{ ___('Notes') }}</label>
            <input id="fitb-notes" type="text" name="notes" class="ui-input" maxlength="2000">
        </div>
        <div class="ui-fit__submit">
            <x-ui.button type="submit" variant="secondary" icon="plus">{{ ___('Bulk add') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

<x-ui.card :title="___('Import CSV')">
    <p class="ui-fit__hint ui-fit__hint--in">{{ ___('A flat fitment sheet for this part: columns year, make, model, and optionally submodel, engine, notes.') }}</p>
    <form method="POST" action="{{ route('retail.fitments.importCsv', [$store->id, $item->id]) }}" enctype="multipart/form-data" class="ui-fit__import">
        @csrf
        <input type="file" name="csv_file" accept=".csv,.txt" class="ui-input" required aria-label="{{ ___('Import CSV') }}">
        <x-ui.button type="submit" variant="secondary" icon="upload">{{ ___('Import CSV') }}</x-ui.button>
    </form>
</x-ui.card>

<x-ui.card :title="___('Fitted vehicles')" variant="flush">
    <x-slot:actions><x-ui.tag>{{ $fitments->count() }}</x-ui.tag></x-slot:actions>

    @if($fitments->isNotEmpty())
        <table class="ui-table">
            <thead>
                <tr>
                    <th>{{ ___('Year') }}</th>
                    <th>{{ ___('Make') }}</th>
                    <th>{{ ___('Model') }}</th>
                    <th>{{ ___('Submodel') }}</th>
                    <th>{{ ___('Engine') }}</th>
                    <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach($fitments as $fitment)
                    <tr>
                        <td data-primary><strong><span class="ui-ltr">{{ $fitment->year }}</span> {{ $fitment->make }} {{ $fitment->model }}</strong></td>
                        <td data-label="{{ ___('Make') }}">{{ $fitment->make }}</td>
                        <td data-label="{{ ___('Model') }}">{{ $fitment->model }}</td>
                        <td data-label="{{ ___('Submodel') }}">{{ $fitment->submodel ?? '—' }}</td>
                        <td data-label="{{ ___('Engine') }}">{{ $fitment->engine ?? '—' }}</td>
                        <td class="ui-catalog__row-actions">
                            {{-- A form="" submit, never an inline confirm handler: dead code under
                                 CSP_ENFORCE_DASHBOARD (script-src-attr 'none') — see
                                 DashboardInlineHandlerRatchetTest. --}}
                            <button type="submit" form="delete-fitment-{{ $fitment->id }}" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Remove') }}" title="{{ ___('Remove') }}"><x-ui.icon name="trash" /></button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @foreach($fitments as $fitment)
            <form id="delete-fitment-{{ $fitment->id }}" method="POST" action="{{ route('retail.fitments.destroy', [$store->id, $item->id, $fitment->id]) }}" class="ui-hidden" data-confirm="{{ ___('Remove this fitment?') }}" data-confirm-danger>
                @csrf @method('DELETE')
            </form>
        @endforeach
    @else
        <x-ui.empty :title="___('No records found.')" icon="list" />
    @endif
</x-ui.card>

<x-ui.card :title="___('OEM / cross-reference numbers')">
    <p class="ui-fit__hint ui-fit__hint--in">{{ ___('One number per line (or comma-separated). Shown on the storefront and searchable there.') }}</p>

    <form method="POST" action="{{ route('retail.fitments.updateOem', [$store->id, $item->id]) }}" class="ui-fit__oem">
        @csrf
        <div class="ui-fit__field">
            <label class="ui-label" for="fit-oem">{{ ___('OEM numbers') }}</label>
            <textarea id="fit-oem" name="oem_numbers" class="ui-input ui-ltr" rows="4">{{ implode("\n", $oemNumbers) }}</textarea>
        </div>
        <div class="ui-fit__field">
            <label class="ui-label" for="fit-xref">{{ ___('Cross-reference numbers') }}</label>
            <textarea id="fit-xref" name="cross_refs" class="ui-input ui-ltr" rows="4">{{ implode("\n", $crossRefs) }}</textarea>
        </div>
        <div class="ui-fit__submit">
            <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

</div>
@endsection
