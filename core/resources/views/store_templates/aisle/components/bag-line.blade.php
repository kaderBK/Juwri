{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §8: one bag line — thumb 4:5, brand, name (link to the product), the variant ("Sand · M"), price + was-price, a quantity
     select (0 = Remove), "Move to wishlist" and "Remove". $line = a quote line (server-rendered prefill) or null = the blank row the
     cart script clones; the core fills every [data-juwri-line-*] hook from the server quote. --}}
@php($__l = $line ?? null)
<li class="c-bagline">
    <img class="c-bagline__thumb" data-juwri-line-thumb @if (empty($__l['thumb'])) hidden @else src="{{ $__l['thumb']['src'] }}" @endif alt="" width="96" height="120" decoding="async">
    <div class="c-bagline__body">
        <p class="c-bagline__brand"><bdi data-juwri-line-brand>{{ $__l['brand'] ?? '' }}</bdi></p>
        <p class="c-bagline__name"><a data-juwri-line-link href="{{ ! empty($__l['item_id']) ? ($vm['endpoints']['home'] ?? '/').'?item='.$__l['item_id'] : '#' }}">{{ $__l['name'] ?? '' }}</a></p>
        <p class="c-bagline__variant" data-juwri-line-choices>{{ $__l['choices_label'] ?? '' }}</p>
        <p class="c-bagline__price"><span class="c-bagline__now" data-juwri-line-total>{{ isset($__l['line_total']) ? $__l['line_total']['formatted'] : '' }}</span><s class="c-bagline__was" data-juwri-line-was @if (empty($__l['compare_at'])) hidden @endif>{{ $__l['compare_at']['formatted'] ?? '' }}</s></p>
        <div class="c-bagline__row">
            <div class="c-qty"><span class="c-select-wrap c-select-wrap--sm"><select class="c-select" data-juwri-line-select data-juwri-qty="{{ $__l['id'] ?? '' }}" data-juwri-label-template="{{ $kit->s('Quantity of :name', ['name' => ':name']) }}" aria-label="{{ $kit->s('Quantity') }}">
                <option value="0">{{ $kit->s('Remove') }}</option>@foreach (range(1, 10) as $__n)<option value="{{ $__n }}" @selected(($__l['qty'] ?? 1) === $__n)>{{ $__n }}</option>@endforeach
            </select>{{ $kit->icon('chevron-down') }}</span></div>
            @if (! empty($vm['features']['wishlist']))<button class="c-textbtn" type="button" data-kit-move-to-wishlist>{{ $kit->icon('heart') }}<span>{{ $kit->s('Move to wishlist') }}</span></button>@endif
            <button class="c-textbtn" type="button" data-juwri-remove="{{ $__l['id'] ?? '' }}">{{ $kit->icon('trash-2') }}<span>{{ $kit->s('Remove') }}</span></button>
        </div>
    </div>
</li>
