{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-13: one carely icon from the sprite (partials.icon-sprite). `n` is one of the sprite's names (anything else
     shows the pill), `class` an optional class list. Everything is escaped. --}}
@php($__clIcon = in_array($n ?? '', ['pill', 'leaf', 'flu', 'cross', 'drop', 'baby', 'device', 'shield', 'truck', 'search', 'bag', 'moon', 'box', 'user', 'gift', 'heart', 'close', 'chevron'], true) ? $n : 'pill')
<svg @if(! empty($class)) class="{{ $class }}" @endif viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="#cl-i-{{ $__clIcon }}"></use></svg>
