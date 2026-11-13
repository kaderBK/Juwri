{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('App Catalog'))
@section('header_buttons')
    @if (Route::has('admin.app-reviews.index'))
        <x-ui.button variant="secondary" size="sm" icon="shield" :href="route('admin.app-reviews.index')">{{ ___('App Reviews') }}</x-ui.button>
    @endif
    <x-ui.button variant="secondary" size="sm" icon="store" :href="route('apps.store.index')" target="_blank" rel="noopener">{{ ___('View store') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-stack">
    <x-ui.banner>{{ ___('Every App Store listing and its commercials. Apps charge their listed price live at renewal — repricing moves existing subscribers at their next renewal.') }}</x-ui.banner>

    <form method="get" class="ui-cluster">
        <input type="search" name="q" value="{{ $q }}" class="ui-input ui-input--sm ui-input--auto"
               placeholder="{{ ___('Name or slug') }}" aria-label="{{ ___('Search') }}">
        <select name="status" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Status') }}">
            <option value="">{{ ___('All statuses') }}</option>
            @foreach ($statuses as $s)
                <option value="{{ $s }}" @selected($status === $s)>{{ ___(ucfirst($s)) }}</option>
            @endforeach
        </select>
        <x-ui.button type="submit" size="sm" icon="filter">{{ ___('Filter') }}</x-ui.button>
    </form>

    <x-ui.card variant="flush">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th class="ui-dt__narrow"><span class="ui-visually-hidden">{{ ___('Icon') }}</span></th>
                        <th>{{ ___('App') }}</th>
                        <th>{{ ___('Category') }}</th>
                        <th>{{ ___('Pricing') }}</th>
                        <th>{{ ___('Trial') }}</th>
                        <th>{{ ___('Grants') }}</th>
                        <th class="ui-center">{{ ___('Installs') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th class="ui-end">{{ ___('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($apps as $app)
                    <tr>
                        <td data-label="">
                            @if ($app->icon)
                                <span class="ui-cell-img"><img src="{{ $app->icon->url() }}" alt="" width="36" height="36"></span>
                            @else
                                <span class="ui-tpl-thumb" style="inline-size:36px;block-size:36px;aspect-ratio:1"><x-ui.icon name="layout-grid" size="18" /></span>
                            @endif
                        </td>
                        <td data-primary data-label="{{ ___('App') }}">
                            <span class="ui-strong">{{ $app->name }}</span>
                            <small class="ui-cell-sub ui-mono">{{ $app->slug }}</small>
                            @if ($app->developer)
                                <small class="ui-cell-sub">{{ ___('by') }} {{ $app->developer->username ?? $app->developer->name }}</small>
                            @else
                                <small class="ui-cell-sub">{{ ___('First-party') }}</small>
                            @endif
                        </td>
                        <td data-label="{{ ___('Category') }}"><x-ui.tag>{{ $app->category ?: '—' }}</x-ui.tag></td>
                        <td data-label="{{ ___('Pricing') }}">{{ $app->pricingSummary() }}</td>
                        <td data-label="{{ ___('Trial') }}">{{ $app->trial_days ? $app->trial_days.' '.___('days') : '—' }}</td>
                        <td data-label="{{ ___('Grants') }}">
                            @foreach ((array) ($app->grants_features ?? []) as $key)
                                <code class="ui-mono ui-small ui-block">{{ $key }}</code>
                            @endforeach
                            @if (empty($app->grants_features))<span class="ui-text-2">—</span>@endif
                        </td>
                        <td class="ui-center" data-label="{{ ___('Installs') }}">{{ $installs[$app->id] ?? 0 }}</td>
                        <td data-label="{{ ___('Status') }}">
                            @php $tone = ['approved' => 'success', 'review' => 'warning', 'rejected' => 'danger', 'delisted' => 'neutral'][$app->status] ?? 'neutral'; @endphp
                            <x-ui.tag :tone="$tone">{{ ___(ucfirst($app->status)) }}</x-ui.tag>
                        </td>
                        <td class="ui-end" data-label="">
                            <x-ui.button variant="secondary" size="sm" icon="pencil" :href="route('admin.app-catalog.edit', $app->id)">{{ ___('Edit') }}</x-ui.button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="ui-center ui-text-2">{{ ___('No apps match this filter.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
@endsection
