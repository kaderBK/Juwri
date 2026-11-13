{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket: the search field in the page (the phone's; desktop has it in the header). Filters the loaded products as you type. --}}
@if ($vm['theme']['settings']['search.enabled'] ?? true)
@php($__ph = ($vm['theme']['texts']['search_placeholder'] ?? null) ?: $kit->s('Search products'))
<form class="c-search c-search--main" role="search" method="get" action="{{ $vm['endpoints']['home'] ?? '/' }}" @unless (! empty($listing['paged'])) data-kit-search @endunless>
    <label class="u-sr" for="q">{{ $kit->s('Search products') }}</label>{{ $kit->icon('search') }}
    <input class="c-search__input" id="q" type="search" name="q" @if (! empty($listing['q'])) value="{{ $listing['q'] }}" @endif placeholder="{{ $__ph }}" autocomplete="off" enterkeyhint="search">
</form>
@endif
