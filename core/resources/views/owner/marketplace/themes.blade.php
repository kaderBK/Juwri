{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

{{-- ST3 (features-run-4): Theme Store browse inside the dashboard chrome. Data
     comes verbatim from App\Http\Controllers\MarketplaceController@index via
     User\StoreController — faceting logic is not duplicated here. --}}

@section('title', ___('Theme Store'))
@section('page_title', ___('Theme Store'))
@section('page_sub', ___('Storefront themes you can buy once and apply to any of your stores.'))

@section('content')
<div class="ui-scope ui-appstore ui-themestore">
{{-- WIRING-RUN A3: theme.updates.index/apply shipped as JSON endpoints with no UI,
     so owners were never told a newer published version of their theme existed.
     Surfaced here because this is where theme decisions already happen. --}}
<div x-data="themeUpdatesBanner({
        indexUrl: @js(route('theme.updates.index')),
        applyBase: @js(url('theme-updates')),
        token: @js(csrf_token()),
        failedText: @js(___('Could not update the theme. Try again.'))
     })" x-init="load()" x-cloak>
    <template x-if="updates.length">
        <section class="ui-card ui-themestore__updates">
            <div class="ui-card__head">
                <h2 class="ui-card__title">{{ ___('Theme updates available') }}</h2>
                <div class="ui-card__actions"><span class="ui-tag" data-tone="success" x-text="updates.length"></span></div>
            </div>
            <p class="ui-themestore__note">{{ ___('Your settings are kept when you update.') }}</p>
            <div class="ui-themestore__update-list">
                <template x-for="u in updates" :key="u.store_id">
                    <div class="ui-themestore__update">
                        <div class="ui-themestore__update-text">
                            <strong x-text="u.post_title"></strong>
                            <span class="ui-themestore__note ui-ltr">
                                <span x-text="u.slug"></span>
                                <span x-text="u.current"></span> &rarr; <span x-text="u.latest"></span>
                            </span>
                        </div>
                        <span class="ui-themestore__error" x-show="u.error" x-text="u.error" x-cloak></span>
                        <button type="button" class="ui-btn ui-btn--sm" :disabled="u.busy" @click="apply(u)">
                            <span x-text="u.busy ? @js(___('Updating…')) : @js(___('Update'))"></span>
                        </button>
                    </div>
                </template>
            </div>
        </section>
    </template>
</div>

@php $hasFilters = $q !== '' || $price !== '' || !empty($industry) || (int) $minRating > 0; @endphp
<form method="get" action="{{ route('user.store.themes') }}" id="themeBrowse" class="ui-appstore__layout">
    <aside class="ui-card ui-appstore__filters" aria-label="{{ ___('Filters') }}">
        <div class="ui-appstore__filters-head">
            <p class="ui-overline">{{ ___('Filters') }}</p>
            @if($hasFilters)<a class="ui-appstore__clear" href="{{ route('user.store.themes') }}">{{ ___('Clear all') }}</a>@endif
        </div>

        <fieldset class="ui-appstore__group">
            <legend class="ui-appstore__legend">{{ ___('Price') }}</legend>
            <div class="ui-appstore__opts">
                @foreach([['','Featured', $freeCount + $paidCount, false], ['free','Free', $freeCount, $freeCount === 0], ['paid','Paid', $paidCount, $paidCount === 0]] as [$val, $lbl, $cnt, $dis])
                    <label @class(['ui-appstore__opt', 'is-disabled' => $dis])>
                        <input class="ui-appstore__input" type="radio" name="price" value="{{ $val }}" @checked($price === $val) @disabled($dis) data-ui-do="submit">
                        <span>{{ ___($lbl) }}</span><span class="ui-chip__count ui-ltr">{{ $cnt }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <fieldset class="ui-appstore__group">
            <legend class="ui-appstore__legend">{{ ___('Industry') }}</legend>
            <div class="ui-appstore__opts">
                @foreach($industries as $key)
                    @php $c = $industryCounts[$key]; $checked = in_array($key, $industry, true); @endphp
                    <label @class(['ui-appstore__opt', 'is-disabled' => $c === 0 && !$checked])>
                        <input class="ui-appstore__input" type="checkbox" name="industry[]" value="{{ $key }}" @checked($checked) @disabled($c === 0 && !$checked) data-ui-do="submit">
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

    <div class="ui-appstore__results">
        <div class="ui-appstore__bar">
            <label class="ui-appstore__search">
                <x-ui.icon name="search" />
                <input class="ui-input" type="search" name="q" value="{{ $q }}" placeholder="{{ ___('Search themes') }}"
                       aria-label="{{ ___('Search themes') }}" data-ui-do="submit-target" data-ui-target="themeBrowse">
            </label>
            <select class="ui-input ui-appstore__sort" name="sort" data-ui-do="submit-target" data-ui-target="themeBrowse" aria-label="{{ ___('Sort by') }}">
                <option value="featured" @selected($sort==='featured')>{{ ___('Featured') }}</option>
                <option value="price_low" @selected($sort==='price_low')>{{ ___('Price (low → high)') }}</option>
                <option value="price_high" @selected($sort==='price_high')>{{ ___('Price (high → low)') }}</option>
                <option value="top_rated" @selected($sort==='top_rated')>{{ ___('Top rated') }}</option>
            </select>
        </div>
        <p class="ui-appstore__count">{{ ___(':count themes', ['count' => $total]) }}</p>

        @if($results->isEmpty())
            <div class="ui-card">
                <x-ui.empty icon="search" :title="___('No themes match your filters.')">
                    @if($hasFilters)<x-ui.button variant="secondary" :href="route('user.store.themes')">{{ ___('Clear all filters') }}</x-ui.button>@endif
                </x-ui.empty>
            </div>
        @else
            <div class="ui-appstore__grid">
                @foreach($results as $theme)
                    @php $shot = $theme->screenshots->first(); $isFree = (float) $theme->price <= 0; $sellerName = $theme->seller?->name ?: ___('Platform'); @endphp
                    <a class="ui-card ui-card--flush ui-appstore__card" href="{{ route('user.store.themes.show', $theme->slug) }}">
                        <span class="ui-appstore__shot">
                            @if($shot)<img src="{{ $shot->url() }}" alt="{{ $theme->name }}" loading="lazy" decoding="async" width="320" height="180">
                            @else<span>{{ ___('No preview') }}</span>@endif
                        </span>
                        <span class="ui-appstore__body">
                            <span class="ui-appstore__name">{{ $theme->name }}</span>
                            <span class="ui-appstore__dev">{{ ___('by :developer', ['developer' => $sellerName]) }}</span>
                            <span class="ui-appstore__tagline">{{ \Illuminate\Support\Str::limit($theme->tagline, 80) }}</span>
                            <span class="ui-appstore__meta">
                                <x-ui.tag :tone="$isFree ? 'success' : 'accent'"><span class="ui-ltr">{{ $isFree ? ___('Free') : price_symbol_format($theme->price) }}</span></x-ui.tag>
                                @if((int) $theme->rating_count > 0)
                                    <span class="ui-appstore__rating"><span class="ui-appstore__stars" aria-hidden="true">★</span> <span class="ui-ltr">{{ number_format($theme->rating_avg, 1) }} ({{ $theme->rating_count }})</span></span>
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

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    // WIRING-RUN A3: drives the "theme updates available" banner above the browse grid.
    window.themeUpdatesBanner = function (config) {
        return {
            updates: [],
            async load() {
                try {
                    const res = await fetch(config.indexUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!res.ok) return;
                    const body = await res.json();
                    this.updates = (body.updates || []).map(u => ({ ...u, busy: false, error: '' }));
                } catch (e) { /* banner is additive — stay silent if it cannot load */ }
            },
            async apply(u) {
                u.busy = true; u.error = '';
                try {
                    const res = await fetch(config.applyBase + '/' + u.store_id, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': config.token, 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    const body = await res.json().catch(() => ({}));
                    if (res.ok && body.ok) {
                        this.updates = this.updates.filter(x => x.store_id !== u.store_id);
                        return;
                    }
                    u.error = body.message || config.failedText;
                } catch (e) {
                    u.error = config.failedText;
                }
                u.busy = false;
            },
        };
    };
</script>
@endpush
@endsection
