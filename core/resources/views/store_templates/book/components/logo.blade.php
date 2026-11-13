{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: the store mark — the logo image (decorative: the store name is always next to it), else the service glyph on the primary colour --}}
@php($__cls = $cls ?? 'c-bklogo')
@if (! empty($vm['store']['logo']) && ! str_contains((string) ($vm['store']['logo']['src'] ?? ''), '/img/placeholder-'))
<span class="{{ $__cls }}"><img class="c-bklogo__img" src="{{ $vm['store']['logo']['src'] }}" alt="" width="76" height="76" decoding="async"></span>
@else
<span class="{{ $__cls }}" aria-hidden="true">{{ $kit->icon('scissors') }}</span>
@endif
