{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket §8: one basket line — thumbnail, name, pack size / weight, the estimate or weighed note, the out-of-stock choice, a quantity
     stepper and Remove, the line total ("~" for an estimate). The blank row the cart JS clones; every word is the server quote's. --}}
<li class="c-bkline">
    <img class="c-bkline__thumb" data-juwri-line-thumb hidden alt="" width="56" height="56" decoding="async">
    <div class="c-bkline__body">
        <p class="c-bkline__name" data-juwri-line-name></p>
        <p class="c-bkline__unit" data-juwri-line-unit hidden></p>
        <p class="c-bkline__unit" data-juwri-line-choices></p>
        <p class="c-bkline__note" data-juwri-line-note hidden></p>
        <p class="c-bkline__note c-bkline__note--plain" data-juwri-line-substitution hidden></p>
        <div class="c-bkline__ctl">
            <div class="c-qty c-qty--line" role="group" aria-label="{{ $kit->s('Quantity') }}">
                <button class="c-qty__btn" type="button" data-juwri-dec="" aria-label="{{ $kit->s('Decrease quantity') }}">{{ $kit->icon('minus') }}</button>
                <output class="c-qty__val" data-juwri-line-qty></output>
                <button class="c-qty__btn" type="button" data-juwri-inc="" aria-label="{{ $kit->s('Increase quantity') }}">{{ $kit->icon('plus') }}</button>
            </div>
            <button class="c-link c-line__edit" type="button" data-juwri-remove="">{{ $kit->s('Remove') }}</button>
        </div>
    </div>
    <p class="c-bkline__price"><span data-juwri-line-estimate hidden aria-hidden="true">~ </span><span data-juwri-line-total></span></p>
</li>
