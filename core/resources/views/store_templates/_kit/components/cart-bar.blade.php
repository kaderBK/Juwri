{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- phone: the sticky bar that appears once the cart has a line and opens the cart dialog. The count and total are the
     cart's own; the accessible name is set by the cart JS-free text below plus the live region (core). --}}
@php($__q = $cart ?? null)
<div class="c-cartbar" data-section="cart_bar" data-juwri-filled @unless ($__q) hidden @endunless>
    <button class="c-cartbar__btn" type="button" data-juwri-open="cart" aria-controls="cart" aria-expanded="false"><span class="c-cartbar__count" data-juwri-count>{{ collect($__q['lines'] ?? [])->sum('qty') }}</span><span class="c-cartbar__label">{{ $kit->s('View cart') }}</span><span class="c-cartbar__total c-money" data-juwri-total>{{ $__q['total']['formatted'] ?? '' }}</span><span class="c-cartbar__queue" data-juwri-queue-badge hidden></span></button>
</div>
