{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__b = ($item['badges'] ?? [null])[0] ?? null;
    $__icons = ['popular' => 'flame', 'new' => 'sparkles', 'spicy' => 'flame', 'low_stock' => 'alert-circle', 'sold_out' => 'ban', 'vegan' => 'sparkles', 'sale' => 'tag', 'offer' => 'tag'];
@endphp
@if ($__b)<span class="c-badge{{ $__b['kind'] === 'sold_out' ? ' c-badge--muted' : '' }}">{{ $kit->icon($__icons[$__b['kind']] ?? 'tag') }}<span>{{ $__b['label'] }}</span></span>@endif
