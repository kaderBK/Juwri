{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- brew: "Order again". Only a signed-in customer has recent_items (the server filtered them: this store's, still orderable).
     A row whose last choices all still exist adds straight to the cart; otherwise it opens the item sheet. --}}
@php
    $__rows = $vm['customer']['recent_items'] ?? [];
    $__title = ($vm['theme']['texts']['reorder_title'] ?? null) ?: $kit->s('Order again');
@endphp
@if ($__rows)
<section class="c-reorder" data-section="reorder" aria-labelledby="reorder-title">
    <h2 class="c-reorder__title" id="reorder-title">{{ $__title }}</h2>
    <ul class="c-rail c-reorder__rail">
    @foreach ($__rows as $__r)
        @php
            $__card = $vm['catalog']['items'][$__r['item_id']] ?? null;
            $__full = ! empty($__r['choices_label']) ? $__r['label'].', '.$__r['choices_label'] : $__r['label'];
            $__complete = $__r['complete'] ?? true; // absent = the server did not check the choices: add as listed
        @endphp
        @continue(! $__card)
        <li class="c-rpill" data-item="{{ $__r['item_id'] }}">
            @if (! empty($__card['image']))<img class="c-rpill__img" src="{{ $__card['image']['src'] }}" alt="" width="48" height="48" decoding="async" loading="lazy">@endif
            <span class="c-rpill__text"><span class="c-rpill__name">{{ $__r['label'] }}</span>@if ($__complete && ! empty($__r['choices_label']))<span class="c-rpill__sub">{{ $kit->flow($__r['choices_label']) }}</span>@elseif (! $__complete)<span class="c-rpill__sub">{{ $kit->s('Some choices changed') }}</span>@endif</span>
            @if ($__complete)
            <button class="c-rpill__btn" type="button" data-juwri-add="{{ $__r['item_id'] }}" @if (! empty($__r['variant'])) data-juwri-variant="{{ $__r['variant'] }}" @endif @if (! empty($__r['options'])) data-juwri-options="{{ implode(' ', $__r['options']) }}" @endif data-juwri-name="{{ $__r['label'] }}" aria-label="{{ $kit->s('Add :name again', ['name' => $__full]) }}">{{ $kit->icon('rotate-ccw') }}<span class="c-rpill__label">{{ $kit->s('Add') }}</span></button>
            @else
            <button class="c-rpill__btn" type="button" data-kit-open-item="{{ $__r['item_id'] }}" aria-haspopup="dialog" aria-label="{{ $kit->s('Add :name again', ['name' => $__full]) }}">{{ $kit->icon('rotate-ccw') }}<span class="c-rpill__label">{{ $kit->s('Add') }}</span></button>
            @endif
        </li>
    @endforeach
    </ul>
</section>
@endif
