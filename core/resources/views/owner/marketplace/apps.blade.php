{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

{{-- ST2 (features-run-4): App Store browse rendered inside the dashboard chrome.
     Data comes verbatim from Juwri\Modules\AppStore\Http\Controllers\AppStoreController@index via
     User\StoreController — no query/faceting logic is duplicated here. --}}

@section('title', ___('App Store'))
@section('page_title', ___('App Store'))
@section('page_sub', ___('Apps that power your store — install and manage without leaving the dashboard.'))

@section('content')
@php $hasFilters = $q !== '' || $pricing !== '' || !empty($category) || (int) $minRating > 0; @endphp
<div class="ui-scope ui-appstore">
<form method="get" action="{{ route('user.store.apps') }}" id="storeBrowse" class="ui-appstore__layout">
    {{-- Filters: chip rows on phones, a sidebar from 992px. Real radios and
         checkboxes (data-ui-do="submit") so the form still works without JS. --}}
    <aside class="ui-card ui-appstore__filters" aria-label="{{ ___('Filters') }}">
        <div class="ui-appstore__filters-head">
            <p class="ui-overline">{{ ___('Filters') }}</p>
            @if($hasFilters)<a class="ui-appstore__clear" href="{{ route('user.store.apps') }}">{{ ___('Clear all') }}</a>@endif
        </div>

        <fieldset class="ui-appstore__group">
            <legend class="ui-appstore__legend">{{ ___('Pricing') }}</legend>
            <div class="ui-appstore__opts">
                @foreach([['','Featured', $freeCount + $paidCount, false], ['free','Free', $freeCount, $freeCount === 0], ['paid','Paid', $paidCount, $paidCount === 0]] as [$val, $lbl, $cnt, $dis])
                    <label @class(['ui-appstore__opt', 'is-disabled' => $dis])>
                        <input class="ui-appstore__input" type="radio" name="pricing" value="{{ $val }}" @checked($pricing === $val) @disabled($dis) data-ui-do="submit">
                        <span>{{ ___($lbl) }}</span><span class="ui-chip__count ui-ltr">{{ $cnt }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <fieldset class="ui-appstore__group">
            <legend class="ui-appstore__legend">{{ ___('Category') }}</legend>
            <div class="ui-appstore__opts">
                @foreach($categories as $key)
                    @php $c = $categoryCounts[$key]; $checked = in_array($key, $category, true); @endphp
                    <label @class(['ui-appstore__opt', 'is-disabled' => $c === 0 && !$checked])>
                        <input class="ui-appstore__input" type="checkbox" name="category[]" value="{{ $key }}" @checked($checked) @disabled($c === 0 && !$checked) data-ui-do="submit">
                        <span>{{ ___(ucfirst(str_replace('_',' ',$key))) }}</span><span class="ui-chip__count ui-ltr">{{ $c }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <fieldset class="ui-appstore__group">
            <legend class="ui-appstore__legend">{{ ___('Rating') }}</legend>
            <div class="ui-appstore__opts">
                <label class="ui-appstore__opt">
                    <input class="ui-appstore__input" type="radio" name="min_rating" value="0" @checked((int) $minRating === 0) data-ui-do="submit">
                    <span>{{ ___('Any') }}</span>
                </label>
                @foreach([4, 3, 2] as $n)
                    <label class="ui-appstore__opt">
                        <input class="ui-appstore__input" type="radio" name="min_rating" value="{{ $n }}" @checked((int) $minRating === $n) data-ui-do="submit">
                        <span class="ui-appstore__stars" aria-hidden="true">{{ str_repeat('★', $n) }}</span><span class="ui-visually-hidden">{{ $n }}</span><span>{{ ___('& up') }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
    </aside>

    {{-- Results --}}
    <div class="ui-appstore__results">
        <div class="ui-appstore__bar">
            <label class="ui-appstore__search">
                <x-ui.icon name="search" />
                <input class="ui-input" type="search" name="q" value="{{ $q }}" placeholder="{{ ___('Search apps') }}"
                       aria-label="{{ ___('Search apps') }}" data-ui-do="submit-target" data-ui-target="storeBrowse">
            </label>
            <select class="ui-input ui-appstore__sort" name="sort" data-ui-do="submit-target" data-ui-target="storeBrowse" aria-label="{{ ___('Sort by') }}">
                <option value="featured" @selected($sort==='featured')>{{ ___('Featured') }}</option>
                <option value="name" @selected($sort==='name')>{{ ___('Name (A–Z)') }}</option>
                <option value="top_rated" @selected($sort==='top_rated')>{{ ___('Top rated') }}</option>
            </select>
        </div>
        <p class="ui-appstore__count">{{ ___(':count apps', ['count' => $total]) }}</p>

        @if($results->isEmpty())
            <div class="ui-card">
                <x-ui.empty icon="search" :title="___('No apps match your filters.')">
                    @if($hasFilters)<x-ui.button variant="secondary" :href="route('user.store.apps')">{{ ___('Clear all filters') }}</x-ui.button>@endif
                </x-ui.empty>
            </div>
        @else
            <div class="ui-appstore__grid">
                @foreach($results as $app)
                    @php $shot = $app->screenshots->first(); $devName = $app->developer?->name ?: ___('Platform'); @endphp
                    <a class="ui-card ui-card--flush ui-appstore__card" href="{{ route('user.store.apps.show', $app->slug) }}">
                        <span class="ui-appstore__shot">
                            @if($shot)<img src="{{ $shot->url() }}" alt="{{ $app->name }}" loading="lazy" decoding="async" width="320" height="180">
                            @else<span>{{ ___('No preview') }}</span>@endif
                        </span>
                        <span class="ui-appstore__body">
                            <span class="ui-appstore__name">{{ $app->name }}</span>
                            <span class="ui-appstore__dev">{{ ___('by :developer', ['developer' => $devName]) }}</span>
                            <span class="ui-appstore__tagline">{{ \Illuminate\Support\Str::limit($app->tagline, 80) }}</span>
                            <span class="ui-appstore__meta">
                                <x-ui.tag :tone="$app->isFreeBilling() ? 'success' : 'accent'">{{ $app->pricingSummary() }}</x-ui.tag>
                                @if((int) $app->rating_count > 0)
                                    <span class="ui-appstore__rating"><span class="ui-appstore__stars" aria-hidden="true">★</span> <span class="ui-ltr">{{ number_format($app->rating_avg, 1) }} ({{ $app->rating_count }})</span></span>
                                @else
                                    <x-ui.tag>{{ ___('New') }}</x-ui.tag>
                                @endif
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</form>
</div>
@endsection
