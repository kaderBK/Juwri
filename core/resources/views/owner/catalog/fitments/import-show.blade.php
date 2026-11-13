{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    P3-08b (D12) — a fitment import: the preview (step 2 of 2: counts, sample rows, the rows that failed, ignored
    columns, confirm), then its progress and report.
--}}
@extends('owner.layout')

@section('title', ___('Import fitments').' - '.$store->title)
@section('page_title', ___('Import fitments'))
@section('page_sub', $import->original_name)

@section('page_actions')
    <x-ui.button :href="route('retail.fitments.import', $store->id)" variant="secondary" icon="back">
        {{ ___('Back to imports') }}
    </x-ui.button>
@endsection

@php
    $errors_ = (array) $import->errors;
    $ignored = (array) ($import->meta['ignored'] ?? []);
    $new = $import->status === 'done' ? $import->imported_rows : $import->valid_rows;
@endphp

@section('content')
<div class="ui-scope ui-fit">
    @if($import->status === 'failed')
        <x-ui.banner tone="danger">{{ $import->message ?: ___('The import failed.') }}</x-ui.banner>
    @elseif($import->status === 'done')
        <x-ui.banner tone="success">{{ ___(':count fitment row(s) imported.', ['count' => $import->imported_rows]) }}</x-ui.banner>
    @elseif($import->isOpen())
        <x-ui.banner tone="info">
            {{ $import->status === 'queued' ? ___('The import is queued. It can take a few minutes.') : ___('The import is running.') }}
            <a href="{{ route('retail.fitments.import.show', [$store->id, $import->public_id]) }}">{{ ___('Refresh') }}</a>
        </x-ui.banner>
    @else
        <x-ui.banner tone="info">{{ ___('Nothing has been added yet. Check the numbers below, then confirm.') }}</x-ui.banner>
    @endif

    <div class="ui-fit__stats">
        <x-ui.stat :value="number_format($import->total_rows)" :label="___('Rows in the file')" icon="list" />
        <x-ui.stat :value="number_format($new)" :label="$import->status === 'done' ? ___('Fitments added') : ___('New fitments')" icon="check" tone="success" />
        <x-ui.stat :value="number_format($import->duplicate_rows)" :label="___('Already in your store')" icon="info" />
        <x-ui.stat :value="number_format($import->error_rows)" :label="___('Rows with a problem')" icon="alert" :tone="$import->error_rows ? 'warning' : null" />
    </div>

    @if($ignored !== [])
        <x-ui.banner tone="warning">{{ ___('These columns are ignored: :columns. The file only ever changes this store.', ['columns' => implode(', ', $ignored)]) }}</x-ui.banner>
    @endif

    @if($import->status === 'previewed')
        <x-ui.card :title="___('Confirm')">
            @if($import->valid_rows > 0)
                <p class="ui-fit__hint ui-fit__hint--in">
                    {{ $import->total_rows > $inlineRows
                        ? ___(':count new fitment(s) will be added in the background.', ['count' => number_format($import->valid_rows)])
                        : ___(':count new fitment(s) will be added now.', ['count' => number_format($import->valid_rows)]) }}
                    @if($import->error_rows) {{ ___('The rows with a problem are skipped.') }} @endif
                </p>
                <form method="POST" action="{{ route('retail.fitments.import.confirm', [$store->id, $import->public_id]) }}">
                    @csrf
                    <x-ui.button type="submit" icon="check" data-ui-busy>{{ ___('Add :count fitment(s)', ['count' => number_format($import->valid_rows)]) }}</x-ui.button>
                </form>
            @else
                <x-ui.banner tone="warning">{{ ___('There is nothing new to add from this file. Fix the rows below and upload it again.') }}</x-ui.banner>
            @endif
            <form method="POST" action="{{ route('retail.fitments.import.destroy', [$store->id, $import->public_id]) }}" class="ui-fit__import">
                @csrf @method('DELETE')
                <x-ui.button type="submit" variant="secondary">{{ ___('Discard this file') }}</x-ui.button>
            </form>
        </x-ui.card>
    @endif

    @if(! empty($import->sample))
        <x-ui.card :title="___('First rows to add')" variant="flush">
            <table class="ui-table">
                <thead><tr><th>{{ ___('Row') }}</th><th>{{ ___('Part') }}</th><th>{{ ___('Vehicle') }}</th><th>{{ ___('New fitments') }}</th></tr></thead>
                <tbody>
                @foreach($import->sample as $row)
                    <tr>
                        <td data-primary data-label="{{ ___('Row') }}"><span class="ui-ltr">{{ $row['row'] }}</span></td>
                        <td data-label="{{ ___('Part') }}"><span class="ui-ltr">{{ $row['product'] }}</span></td>
                        <td data-label="{{ ___('Vehicle') }}">{{ $row['vehicle'] }}</td>
                        <td data-label="{{ ___('New fitments') }}"><span class="ui-ltr">{{ $row['new'] }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </x-ui.card>
    @endif

    @if($errors_ !== [])
        <x-ui.card :title="___('Rows with a problem')" variant="flush">
            <x-slot:actions>
                <x-ui.button :href="route('retail.fitments.import.errors', [$store->id, $import->public_id])" variant="secondary" size="sm" icon="download">{{ ___('Download the problems (CSV)') }}</x-ui.button>
            </x-slot:actions>
            @if($import->error_rows > count($errors_))
                <p class="ui-fit__hint ui-fit__hint--in">{{ ___('Showing the first :shown of :total.', ['shown' => count($errors_), 'total' => number_format($import->error_rows)]) }}</p>
            @endif
            <table class="ui-table">
                <thead><tr><th>{{ ___('Row') }}</th><th>{{ ___('Problem') }}</th><th>{{ ___('Values') }}</th></tr></thead>
                <tbody>
                @foreach($errors_ as $error)
                    <tr>
                        <td data-primary data-label="{{ ___('Row') }}"><span class="ui-ltr">{{ $error['row'] }}</span></td>
                        <td data-label="{{ ___('Problem') }}">{{ $error['message'] }}</td>
                        <td data-label="{{ ___('Values') }}"><span class="ui-ltr">{{ $error['values'] ?? '' }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </x-ui.card>
    @endif
</div>
@endsection
