{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    P3-08b (D12) — store-wide fitment CSV import: upload a file for the whole catalogue (the per-part import stays on
    each part's page). Step 1 of 2: the file is checked and previewed on the next page; nothing is added until confirmed.
--}}
@extends('owner.layout')

@section('title', ___('Import fitments').' - '.$store->title)
@section('page_title', ___('Import fitments'))
@section('page_sub', $store->title)

@section('page_actions')
    <x-ui.button :href="route('retail.fitments.index', $store->id)" variant="secondary" icon="back">
        {{ ___('Back to fitments') }}
    </x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-fit">
    <x-ui.card :title="___('Upload a fitment file')">
        <p class="ui-fit__hint ui-fit__hint--in">{{ ___('One file for all your parts. Each row says which part (its sku), and the vehicle it fits: year (or a range such as 2015-2018), make, model, and optionally submodel, engine, vin and notes. You see what will happen before anything is added.') }}</p>
        <p class="ui-fit__hint ui-fit__hint--in">{{ ___('Limits: :mb MB and :rows rows per file. Files over :inline rows are imported in the background. A row with a problem is reported and skipped; the other rows still import. Columns such as store_id are ignored: the file only ever changes this store.', ['mb' => $maxMb, 'rows' => number_format($maxRows), 'inline' => number_format($inlineRows)]) }}</p>
        <form method="POST" action="{{ route('retail.fitments.import.upload', $store->id) }}" enctype="multipart/form-data" class="ui-fit__import">
            @csrf
            <input type="file" name="csv_file" accept=".csv,.txt" class="ui-input" required aria-label="{{ ___('Fitment file (CSV)') }}">
            <x-ui.button type="submit" icon="upload" data-ui-busy>{{ ___('Check the file') }}</x-ui.button>
            <x-ui.button :href="route('retail.fitments.import.template', $store->id)" variant="secondary" icon="download">{{ ___('Download a sample file') }}</x-ui.button>
        </form>
        @error('csv_file')<p class="ui-field__error" role="alert">{{ $message }}</p>@enderror
    </x-ui.card>

    @if($recent->isNotEmpty())
        <x-ui.card :title="___('Recent imports')" variant="flush">
            <div class="ui-list ui-fit__list">
                @foreach($recent as $row)
                    <a class="ui-row" href="{{ route('retail.fitments.import.show', [$store->id, $row->public_id]) }}">
                        <span class="ui-row__body">
                            <span class="ui-row__title">{{ $row->original_name }}</span>
                            <span class="ui-row__sub">{{ $row->created_at?->format('Y-m-d H:i') }} · {{ ___(':count new fitment(s)', ['count' => $row->status === 'done' ? $row->imported_rows : $row->valid_rows]) }}</span>
                        </span>
                        <span class="ui-row__trail">
                            <x-ui.tag :tone="['done' => 'success', 'failed' => 'danger', 'previewed' => 'neutral'][$row->status] ?? 'accent'">{{ ['done' => ___('Imported'), 'failed' => ___('Failed'), 'previewed' => ___('Waiting for you'), 'queued' => ___('Queued'), 'running' => ___('Running')][$row->status] ?? $row->status }}</x-ui.tag>
                            <x-ui.icon name="chevron" size="18" class="ui-row__chevron" />
                        </span>
                    </a>
                @endforeach
            </div>
        </x-ui.card>
    @endif
</div>
@endsection
