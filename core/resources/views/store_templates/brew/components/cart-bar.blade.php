{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- brew, phone: the inverted bar that appears once the cart has a line — "2 items · Ready ~8 min", the total, and a View cart pill.
     The count and total are the cart's own (core JS); the ready time is the kitchen's (view model), hidden when unknown. --}}
@php
    $__q = $cart ?? null;
    $__prep = $vm['context']['prep_time_min'] ?? null;
@endphp
<div class="c-cartbar" data-section="cart_bar" data-juwri-filled @unless ($__q) hidden @endunless>
    <div class="c-cartbar__bar">
        <p class="c-cartbar__text"><span class="c-cartbar__meta">{{ $kit->s('Items') }}: <span data-juwri-count>{{ collect($__q['lines'] ?? [])->sum('qty') }}</span>@if ($__prep) · {{ $kit->s('Ready ~:minutes min', ['minutes' => $__prep]) }}@endif</span><span class="c-cartbar__total c-money" data-juwri-total>{{ $__q['total']['formatted'] ?? '' }}</span></p>
        <button class="c-cartbar__btn" type="button" data-juwri-open="cart" aria-controls="cart" aria-expanded="false"><span>{{ $kit->s('View cart') }}</span>{{ $kit->icon('arrow-right') }}<span class="c-cartbar__queue" data-juwri-queue-badge hidden></span></button>
    </div>
</div>
