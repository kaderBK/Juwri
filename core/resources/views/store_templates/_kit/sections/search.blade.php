{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@if ($vm['theme']['settings']['search.enabled'] ?? true)
@php($__ph = ($vm['theme']['texts']['search_placeholder'] ?? null) ?: $kit->s('Search the menu'))
<form class="c-search" role="search" method="get" action="{{ ($vm['endpoints']['home'] ?? '/') }}" data-kit-search>
    <label class="u-sr" for="q">{{ $kit->s('Search the menu') }}</label>{{ $kit->icon('search') }}
    <input class="c-search__input" id="q" type="search" name="q" placeholder="{{ $__ph }}" autocomplete="off" enterkeyhint="search">
</form>
@endif
