{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__s = $vm['store'];
    $__st = $__s['status'];
    $__title = ($vm['theme']['texts']['hero_title'] ?? null) ?: ($__s['tagline'] ?? null);
    if (! $__st['open']) {
        $__label = (string) ($__st['label'] ?? '');
        $__time = preg_match('/(?:opens|يفتح|تفتح)\s*(.+)$/u', $__label, $__m) === 1 ? trim($__m[1]) : $__label;
        $__title = $__time !== '' ? $kit->s('Order now for pickup at :time', ['time' => $__time]) : $__title;
    }
    $__prep = $vm['context']['prep_time_min'] ?? null;
@endphp
<div class="c-hero c-hero--pickup" data-section="hero">
    @if ($__title)<p class="c-hero__title">{{ $kit->flow($__title) }}</p>@endif
    <ul class="c-hero__chips">
        <li class="c-hchip">{{ $kit->icon('store') }}<span>{{ $vm['branch']['current']['name'] ?? $__s['name'] }}</span></li>
        @if ($__prep && $__st['open'])<li class="c-hchip">{{ $kit->icon('timer') }}<span>{{ $kit->s('Ready in ~:minutes min', ['minutes' => $__prep]) }}</span></li>@endif
    </ul>
</div>
