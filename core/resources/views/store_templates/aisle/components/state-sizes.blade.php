{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle states: a row of size pills (the "XS is out of stock" and "Choose a size" states) --}}
<div class="c-opt__row c-state__sizes @if (! empty($error)) c-state__sizes--error @endif" role="group" aria-label="{{ $kit->s('Size') }}">
@foreach (['XS', 'S', 'M', 'L', 'XL'] as $__z)
    @if (! empty($error))<span class="c-size"><span class="c-size__face">{{ $__z }}</span></span>
    @else<label class="c-size{{ $__z === 'XS' ? ' is-soldout' : '' }}" for="st-size-{{ $__z }}"><input class="c-size__input u-vh" type="radio" id="st-size-{{ $__z }}" name="st-size" value="{{ $__z }}" @if ($__z === 'XS') aria-disabled="true" @endif><span class="c-size__face">{{ $__z }}@if ($__z === 'XS')<span class="u-sr">, {{ $kit->s('Sold out') }}</span>@endif</span></label>@endif
@endforeach
</div>
