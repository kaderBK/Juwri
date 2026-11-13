{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket §8, phone: the bar once the basket has a line — count, "View basket", the total; it opens the basket sheet. --}}
<div class="c-cartbar c-cartbar--basket" data-section="cart_bar" data-juwri-filled hidden role="complementary" aria-label="{{ $kit->s('Basket') }}">
    <button class="c-cartbar__btn" type="button" data-juwri-open="cart" aria-controls="cart" aria-haspopup="dialog" aria-expanded="false"><span class="c-cartbar__count" aria-hidden="true" data-juwri-count>0</span><span class="u-sr">{{ $kit->s('View basket') }}, <bdi data-juwri-count>0</bdi> {{ $kit->s('Items') }}</span><span class="c-cartbar__text" aria-hidden="true"><span class="c-cartbar__label">{{ $kit->s('View basket') }}</span></span><span class="c-cartbar__total c-money" aria-hidden="true" data-juwri-total></span></button>
</div>
