{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Missing translations').' '.$language->name)
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="download" :href="route('admin.languages.export', $language->code)">{{ ___('Export pack') }}</x-ui.button>
    <x-ui.button size="sm" :href="route('admin.languages.translates', $language->code)">{{ ___('Translate') }}</x-ui.button>
@endsection
@section('content')
<div class="ui-stack">
    {{-- Import a JSON language pack (key → string). Direct file write. --}}
    <x-ui.card :title="___('Import JSON pack')">
        <form action="{{ route('admin.languages.import', $language->code) }}" method="POST" enctype="multipart/form-data" class="ui-cluster">
            @csrf
            <input type="file" name="pack" accept=".json,application/json" class="ui-input ui-grow" required aria-label="{{ ___('Import JSON pack') }}">
            <x-ui.button type="submit" variant="success" icon="upload">{{ ___('Import') }}</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card>
        <x-slot:actions><x-ui.tag tone="warning">{{ count($missing) }}</x-ui.tag></x-slot:actions>
        @if (count($missing) === 0)
            <p class="ui-muted">{{ ___('Nothing missing — every key is translated.') }}</p>
        @else
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr><th>{{ ___('Key') }}</th><th>{{ ___('Default') }} ({{ $default }})</th></tr></thead>
                    <tbody>
                        @foreach ($missing as $key => $row)
                            <tr>
                                <td data-label="{{ ___('Key') }}"><code>{{ $key }}</code></td>
                                <td data-label="{{ ___('Default') }}">{{ is_array($row['en']) ? json_encode($row['en']) : $row['en'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
@endsection
