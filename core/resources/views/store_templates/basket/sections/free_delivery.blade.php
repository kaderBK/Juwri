{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket §3: how far the basket is from free delivery. Shown only when the store has a threshold; the bar and the words are the
     server quote's (core JS fills them), hidden while the basket is empty. --}}
@if (! empty($vm['context']['free_delivery_threshold']))
<div class="c-fd" data-section="free_delivery" data-juwri-freeship hidden>
    <p class="c-fd__row">{{ $kit->icon('truck') }}<span class="c-fd__label" data-juwri-freeship-label></span></p>
    <progress class="c-fd__bar" data-juwri-freeship-bar max="100" value="0" aria-label="{{ $kit->s('Free delivery progress') }}"></progress>
</div>
@endif
