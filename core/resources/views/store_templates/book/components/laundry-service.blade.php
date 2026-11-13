{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: one laundry service row — what it is, what it is priced by, and its quantity (pieces) or weight (kg) --}}
@php
    $__thumb = $card['image']['src'] ?? null;
    $__kg = ! empty($card['unit']['sold_by_weight']);
    $__step = $__kg ? '0.5' : '1';
@endphp
<li class="c-svc c-lsvc" data-item="{{ $card['id'] }}" data-unit="{{ $__kg ? 'kg' : 'each' }}" data-name="{{ $card['name'] }}">
    @if ($__thumb)<img class="c-svc__thumb" src="{{ $__thumb }}" alt="" width="64" height="64" decoding="async" loading="lazy">@endif
    <div class="c-svc__body">
        <h3 class="c-svc__name">{{ $card['name'] }}</h3>
        @if (! empty($card['unit']['label']))<p class="c-svc__meta">{{ $kit->flow($card['unit']['label']) }}</p>@endif
        @if (! empty($card['short']))<p class="c-svc__desc">{{ $card['short'] }}</p>@endif
    </div>
    <div class="c-qty" role="group" aria-label="{{ $__kg ? $kit->s('Weight in kg') : $kit->s('Number of pieces') }}">
        <button class="c-iconbtn c-qty__btn" type="button" data-book-step="-{{ $__step }}" aria-label="{{ $kit->s('Decrease') }}" hidden>{{ $kit->icon('minus') }}</button>
        <input class="c-input c-qty__input" type="number" inputmode="decimal" min="0" max="999" step="{{ $__step }}" value="0" name="lines[{{ $card['id'] }}]" form="bk-form" aria-label="{{ $card['name'] }}: {{ $__kg ? $kit->s('Weight in kg') : $kit->s('Number of pieces') }}" dir="ltr" data-book-qty>
        <button class="c-iconbtn c-qty__btn" type="button" data-book-step="{{ $__step }}" aria-label="{{ $kit->s('Increase') }}" hidden>{{ $kit->icon('plus') }}</button>
    </div>
</li>
