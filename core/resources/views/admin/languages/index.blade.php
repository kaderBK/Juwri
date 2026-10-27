{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Languages'))
@section('header_buttons')
    <x-ui.button size="sm" icon="plus" :data-url="route('admin.languages.create')" data-toggle="slidePanel">{{ ___('Add New') }}</x-ui.button>
@endsection
@section('content')
<div class="ui-stack">
    {{-- LANG-PIPELINE: translation coverage dashboard. Per-locale progress bar +
         provenance counts + a link into the per-locale pipeline page. Source
         locale is excluded by coverage() by design. --}}
    <x-ui.card variant="flush" :title="___('Translation coverage')">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Language') }}</th>
                        <th>{{ ___('Coverage') }}</th>
                        <th>{{ ___('Provenance') }}</th>
                        <th class="ui-end">{{ ___('Pipeline') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (($coverage['locales'] ?? []) as $loc)
                        <tr>
                            <td data-primary data-label="{{ ___('Language') }}">{{ $languageNames[$loc['code']] ?? $loc['code'] }} <small class="ui-text-2">({{ strtoupper($loc['code']) }})</small></td>
                            <td data-label="{{ ___('Coverage') }}">
                                <div class="ui-cluster">
                                    <div class="ui-meter ui-grow" role="progressbar" aria-label="{{ ___('Translation coverage') }}" aria-valuenow="{{ $loc['pct_done'] }}" aria-valuemin="0" aria-valuemax="100">
                                        <div class="ui-meter__bar" style="inline-size: {{ $loc['pct_done'] }}%"></div>
                                    </div>
                                    <span class="ui-small ui-text-2">{{ $loc['pct_done'] }}%</span>
                                </div>
                            </td>
                            <td data-label="{{ ___('Provenance') }}">
                                <span class="ui-cell-tags">
                                    <x-ui.tag tone="success">{{ ___('Human') }}: {{ $loc['human'] }}</x-ui.tag>
                                    <x-ui.tag tone="accent">{{ ___('Machine') }}: {{ $loc['machine'] }}</x-ui.tag>
                                    <x-ui.tag tone="warning">{{ ___('Review') }}: {{ $loc['review'] }}</x-ui.tag>
                                    <x-ui.tag>{{ ___('Placeholder') }}: {{ $loc['placeholder'] }}</x-ui.tag>
                                    <x-ui.tag tone="danger">{{ ___('Missing') }}: {{ $loc['absent'] }}</x-ui.tag>
                                </span>
                            </td>
                            <td class="ui-end" data-label="">
                                <x-ui.button variant="secondary" size="sm" :href="route('admin.languages.pipeline', $loc['code'])">{{ ___('Open pipeline') }}</x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="ui-center ui-text-2">{{ ___('Add another language to start translating the platform UI.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <x-ui.data-table :source="route('admin.languages.index')" :reorder="route('admin.languages.reorder')" order-dir="asc" :columns="[
        ['label' => '', 'sort' => false], ['label' => ___('Code')], ['label' => ___('Name')], ['label' => ___('Direction')],
        ['label' => ___('Status')], ['label' => '', 'sort' => false],
    ]" />
</div>
@endsection
