{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Translate').' '.$language->name)
@section('header_buttons')
    {{-- HPMS E13: missing-string view + pack export/import --}}
    <x-ui.button variant="secondary" size="sm" :href="route('admin.languages.missing', $language->code)">{{ ___('Missing strings') }}</x-ui.button>
    <x-ui.button type="submit" size="sm" form="translate-form" icon="check">{{ ___('Save Changes') }}</x-ui.button>
@endsection
@section('content')
    <form id="translate-form" action="{{ route('admin.languages.translates.update', $language->id) }}" method="POST" class="ui-stack">
        @csrf
        <label>
            <span class="ui-visually-hidden">{{ ___('Search') }}</span>
            <input class="ui-input" type="search" placeholder="{{ ___('Search') }}" data-ui-table-search="translations" autofocus>
        </label>
        <x-ui.card variant="flush">
            <div class="ui-table-wrap">
                <table class="ui-table ui-translate" id="translations">
                    <thead>
                        <tr><th>{{ ___('Key') }}</th><th>{{ ___('Value') }}</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($defaultLanguage as $file => $trans)
                            @foreach ($trans as $key1 => $value1)
                                @if (is_array($value1))
                                    @foreach ($value1 as $key2 => $value2)
                                        @continue(is_array($value2))
                                        @php $__cur = $translates[$file][$key1][$key2] ?? ''; @endphp
                                        <tr data-key="{{ $value2 }} {{ is_string($__cur) ? $__cur : '' }}">
                                            <td><textarea class="ui-input" rows="2" readonly tabindex="-1">{{ $value2 }}</textarea></td>
                                            <td><textarea class="ui-input" rows="2" name="translates[{{ $file }}][{{ $key1 }}][{{ $key2 }}]" placeholder="{{ $value2 }}">{{ $__cur }}</textarea></td>
                                        </tr>
                                    @endforeach
                                @else
                                    @php $__cur = $translates[$file][$key1] ?? ''; @endphp
                                    <tr data-key="{{ $value1 }} {{ is_string($__cur) ? $__cur : '' }}">
                                        <td><textarea class="ui-input" rows="2" readonly tabindex="-1">{{ $value1 }}</textarea></td>
                                        <td><textarea class="ui-input" rows="2" name="translates[{{ $file }}][{{ $key1 }}]" placeholder="{{ $value1 }}">{{ is_string($__cur) ? $__cur : '' }}</textarea></td>
                                    </tr>
                                @endif
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </form>
@endsection
