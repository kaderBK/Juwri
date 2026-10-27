{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__s = $vm['store'];
    $__mo = $vm['context']['min_order'] ?? null;
    $__hero = ! empty($hero);
@endphp
<ul class="c-meta{{ $__hero ? ' c-meta--hero' : '' }}">
    @if (! empty($__s['rating']))
    <li class="c-meta__item c-meta__item--rating">{{ $kit->icon('star') }}<span>{{ $__s['rating']['avg'] }} ({{ number_format($__s['rating']['count']) }})</span></li>
    @endif
    @if ($__mo)
    <li class="c-meta__item">{{ $kit->icon('shopping-bag') }}<span>{{ $kit->t('Min. order :amount', ['amount' => $kit->money($__mo)]) }}</span></li>
    @endif
    @if (! empty($__s['contact']['address']))
    <li class="c-meta__item c-meta__item--addr">{{ $kit->icon('map-pin') }}<span>{{ $__s['contact']['address'] }}</span></li>
    @endif
</ul>
