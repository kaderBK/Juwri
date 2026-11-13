{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- one cart line. $line = a quote line (server-rendered prefill) or null = the blank row the cart JS clones --}}
@php($__l = $line ?? null)
<li class="c-pline">
    <div class="c-pline__text">
        <p class="c-pline__name" data-juwri-line-name>{{ $__l['name'] ?? '' }}</p>
        <p class="c-pline__choices" data-juwri-line-choices>{{ $__l['choices_label'] ?? '' }}</p>
        <div class="c-stepper c-stepper--sm" role="group" aria-label="{{ $kit->s('Quantity') }}">
            <button class="c-stepper__btn" type="button" data-juwri-dec="{{ $__l['id'] ?? '' }}" aria-label="{{ $kit->s('Decrease quantity') }}">{{ $kit->icon('minus') }}</button>
            <output class="c-stepper__val" data-juwri-line-qty>{{ $__l['qty'] ?? '' }}</output>
            <button class="c-stepper__btn" type="button" data-juwri-inc="{{ $__l['id'] ?? '' }}" aria-label="{{ $kit->s('Increase quantity') }}">{{ $kit->icon('plus') }}</button>
        </div>
        <button class="c-link c-line__edit" type="button" data-juwri-remove="{{ $__l['id'] ?? '' }}">{{ $kit->s('Remove') }}</button>
    </div>
    <p class="c-pline__price" data-juwri-line-total>{{ isset($__l['line_total']) ? $__l['line_total']['formatted'] : '' }}</p>
</li>
