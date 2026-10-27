{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §6: the filter groups (category, the catalogue's facets, price, in stock only). Filtering is server-side: this is a
     plain GET form, so the URL is the filter (shareable, the back button works); the theme script only submits it on change.
     $idp makes the ids unique (the sidebar and the phone sheet are two forms of the same fields); $sheet = the phone sheet. --}}
@php
    $__l = $listing ?? [];
    $__facets = $vm['catalog']['facets'] ?? [];
    $__cats = \Juwri\Core\Support\ViewHelpers::populatedCategories($vm['catalog']['categories'] ?? []);
    $__home = $vm['endpoints']['home'] ?? '/';
    $__cur = $vm['money']['currency']['code'] ?? '';
    $__fid = $idp === 'a' ? 'filters-form' : 'filters-form-sheet';
@endphp
<form class="c-filters{{ $sheet ? ' c-filters--sheet' : '' }}" id="{{ $__fid }}" method="get" action="{{ $__home }}" data-kit-filters role="search" aria-label="{{ $kit->s('Filters') }}">
    <input type="hidden" name="q" value="{{ $__l['q'] ?? '' }}">
    @if ($sheet)<input type="hidden" name="sort" value="{{ $__l['sort'] ?? 'position' }}">@endif
    <div class="c-fhead">
        <h2 class="c-fhead__title" id="filters-title-{{ $idp }}"@if ($sheet) tabindex="-1"@endif>{{ $kit->s('Filters') }}</h2>
        @if (! empty($__l['filtered']))<a class="c-fhead__clear" href="{{ $__home }}">{{ $kit->s('Clear all') }}</a>@endif
        @if ($sheet)<button class="c-iconbtn c-fhead__close" type="button" data-juwri-close aria-label="{{ $kit->s('Close filters') }}">{{ $kit->icon('x') }}</button>@endif
    </div>
    <div class="c-filters__groups">
        @if (count($__cats) > 1)
        <fieldset class="c-fgroup"><legend class="c-fgroup__title">{{ $kit->s('Category') }}</legend>
            <div class="c-fgroup__list">
                <label class="c-check" for="f-{{ $idp }}-category-all"><input class="c-check__input u-vh" type="radio" id="f-{{ $idp }}-category-all" name="category" value="" @checked(empty($__l['category']))><span class="c-check__box" aria-hidden="true">{{ $kit->icon('check') }}</span><span class="c-check__label">{{ $kit->s('All products') }}</span></label>
                @foreach ($__cats as $__c)
                <label class="c-check" for="f-{{ $idp }}-category-{{ $__c['id'] }}"><input class="c-check__input u-vh" type="radio" id="f-{{ $idp }}-category-{{ $__c['id'] }}" name="category" value="{{ $__c['id'] }}" @checked(($__l['category'] ?? null) === $__c['id'])><span class="c-check__box" aria-hidden="true">{{ $kit->icon('check') }}</span><span class="c-check__label">{{ $__c['name'] }}</span><span class="c-check__count">{{ $__c['count'] }}</span></label>
                @endforeach
            </div>
        </fieldset>
        @endif
        @foreach ($__facets as $__f)
        <fieldset class="c-fgroup"><legend class="c-fgroup__title">{{ $__f['label'] }}</legend>
            @if ($__f['kind'] === 'size')
            <div class="c-fgroup__pills">
                @foreach ($__f['options'] as $__o)
                <label class="c-pill-opt{{ ($__o['available'] ?? true) ? '' : ' is-unavailable' }}" for="f-{{ $idp }}-{{ $__f['id'] }}-{{ $__o['value'] }}"><input class="c-pill-opt__input u-vh" type="checkbox" id="f-{{ $idp }}-{{ $__f['id'] }}-{{ $__o['value'] }}" name="filter[{{ $__f['id'] }}][]" value="{{ $__o['value'] }}" @checked(! empty($__o['selected'])) @if (! ($__o['available'] ?? true)) aria-disabled="true" @endif><span class="c-pill-opt__face">{{ $__o['label'] }}@unless ($__o['available'] ?? true)<span class="u-sr">, {{ $kit->s('Sold out') }}</span>@endunless</span></label>
                @endforeach
            </div>
            @elseif ($__f['kind'] === 'swatch')
            <div class="c-fgroup__swatches">
                @foreach ($__f['options'] as $__o)
                <label class="c-swopt{{ ($__o['available'] ?? true) ? '' : ' is-unavailable' }}" for="f-{{ $idp }}-{{ $__f['id'] }}-{{ $__o['value'] }}"><input class="c-swopt__input u-vh" type="checkbox" id="f-{{ $idp }}-{{ $__f['id'] }}-{{ $__o['value'] }}" name="filter[{{ $__f['id'] }}][]" value="{{ $__o['value'] }}" @checked(! empty($__o['selected']))><span class="c-swopt__face"><svg class="c-sw c-sw--lg" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><circle class="c-sw__c" cx="10" cy="10" r="9" fill="{{ $__o['swatch'] ?? 'currentColor' }}"/></svg></span><span class="u-sr">{{ $__o['label'] }}</span></label>
                @endforeach
            </div>
            @else
            <div class="c-fgroup__list">
                @foreach ($__f['options'] as $__o)
                <label class="c-check" for="f-{{ $idp }}-{{ $__f['id'] }}-{{ $__o['value'] }}"><input class="c-check__input u-vh" type="checkbox" id="f-{{ $idp }}-{{ $__f['id'] }}-{{ $__o['value'] }}" name="filter[{{ $__f['id'] }}][]" value="{{ $__o['value'] }}" @checked(! empty($__o['selected']))><span class="c-check__box" aria-hidden="true">{{ $kit->icon('check') }}</span><span class="c-check__label">{{ $__o['label'] }}</span><span class="c-check__count">{{ $__o['count'] ?? 0 }}</span></label>
                @endforeach
            </div>
            @endif
        </fieldset>
        @endforeach
        <fieldset class="c-fgroup"><legend class="c-fgroup__title">{{ $kit->s('Price') }}</legend>
            <div class="c-range">
                <div class="c-range__field"><label class="u-sr" for="f-{{ $idp }}-price-min">{{ $kit->s('Minimum price') }}</label><input class="c-input c-range__input" id="f-{{ $idp }}-price-min" name="price_min" type="text" inputmode="decimal" dir="ltr" placeholder="{{ $kit->s('Min') }}" value="{{ $__l['price_min'] ?? '' }}" autocomplete="off"></div>
                <span class="c-range__cur" dir="ltr">{{ $__cur }}</span>
                <div class="c-range__field"><label class="u-sr" for="f-{{ $idp }}-price-max">{{ $kit->s('Maximum price') }}</label><input class="c-input c-range__input" id="f-{{ $idp }}-price-max" name="price_max" type="text" inputmode="decimal" dir="ltr" placeholder="{{ $kit->s('Max') }}" value="{{ $__l['price_max'] ?? '' }}" autocomplete="off"></div>
            </div>
        </fieldset>
        <div class="c-fgroup c-fgroup--toggles">
            <label class="c-check" for="f-{{ $idp }}-in_stock"><input class="c-check__input u-vh" type="checkbox" id="f-{{ $idp }}-in_stock" name="in_stock" value="1" @checked(! empty($__l['in_stock']))><span class="c-check__box" aria-hidden="true">{{ $kit->icon('check') }}</span><span class="c-check__label">{{ $kit->s('In stock only') }}</span></label>
        </div>
    </div>
    <div class="c-ffoot"><button class="c-btn c-btn--primary c-btn--lg c-btn--block" type="submit">{{ $sheet ? $kit->s('Show :count products', ['count' => (string) ($vm['catalog']['total'] ?? 0)]) : $kit->s('Apply filters') }}</button></div>
</form>
