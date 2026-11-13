{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §1: the promo strip — one line, an icon, never a carousel and not dismissible (it never moves: WCAG 2.2.2). --}}
@php($__a = ($vm['features']['announcement'] ?? null) ?: (($vm['theme']['texts']['announcement'] ?? null) ? ['text' => $vm['theme']['texts']['announcement']] : null))
@if ($__a)
<div class="c-announce" role="region" aria-label="{{ $kit->s('Offers') }}">{{ $kit->icon($__a['icon'] ?? 'truck') }}<p class="c-announce__text">{{ $kit->flow($__a['text']) }}</p></div>
@endif
