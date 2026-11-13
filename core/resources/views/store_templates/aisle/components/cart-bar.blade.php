{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle: the phone's sticky bar once the bag has a line; it opens the bag. Count and total are the cart's own. --}}
<div class="c-cartbar" data-section="cart_bar" data-juwri-filled hidden>
    <button class="c-cartbar__btn c-cartbar__btn--aisle" type="button" data-juwri-open="cart" aria-controls="cart" aria-haspopup="dialog" aria-expanded="false"><span class="u-sr">{{ $kit->s('Bag') }}, </span><span class="c-cartbar__label"><bdi data-juwri-count>0</bdi> · {{ $kit->s('Your bag') }}</span><span class="c-cartbar__total c-money" data-juwri-total></span>{{ $kit->icon('arrow-right') }}</button>
</div>
