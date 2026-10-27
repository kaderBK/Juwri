{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket §6: the product card — the photo contained on its tile, the price (struck-through was-price on a sale), the name, the pack
     size with its price per kg / l, then Add. A product with a choice to make (weight, size) opens its sheet instead; a plain one adds
     and becomes a quantity stepper (the shared script keeps both in step with the basket). One link, one separate button. --}}
@php
    $__sold = ! empty($item['sold_out']);
    $__unit = (string) ($item['unit']['label'] ?? ($item['pack_label'] ?? ''));
    $__weighed = ! empty($item['unit']['sold_by_weight']);
    $__choices = ! empty($item['has_required_choices']);
    $__was = $item['compare_at'] ?? null;
    $__b = \Juwri\Core\Support\ViewHelpers::badgeOfKind($item['badges'] ?? [], $__sold ? 'sold_out' : 'sale') ?? \Juwri\Core\Support\ViewHelpers::badgeNotOfKind($item['badges'] ?? [], 'sold_out');
    $__label = $kit->s('Add :name', ['name' => $item['name']]).($__unit !== '' ? ', '.$__unit : '');
@endphp
<li class="c-pcard{{ $__sold ? ' is-soldout' : '' }}" data-item="{{ $item['id'] }}" data-name="{{ $item['name'] }}" data-price="{{ $item['price']['minor'] ?? 0 }}" @if (isset($pos)) data-pos="{{ $pos }}" @endif @if ($__weighed || $__choices) data-choices @endif>
    <div class="c-pcard__media">@if (! empty($item['image']))<img class="c-pcard__img" src="{{ $item['image']['src'] }}" alt="" width="140" height="140" decoding="async"@if (empty($first) && empty($rail)) loading="lazy"@endif>@endif
        @if ($__b)<span class="c-pcard__badge{{ ($__b['kind'] ?? '') === 'sold_out' ? ' c-pcard__badge--muted' : '' }}">{{ $__b['label'] }}</span>@endif</div>
    <p class="c-pcard__prices"><span class="c-pcard__price">@include('store_templates._kit.components.price')</span>@if ($__was)<s class="c-pcard__was"><span class="u-sr">{{ $kit->s('Was') }} {{ $kit->money($__was) }}</span><span aria-hidden="true">{{ $kit->money($__was) }}</span></s>@endif</p>
    <h3 class="c-pcard__name"><a class="c-pcard__link" href="{{ $item['url'] }}" @unless ($__sold) data-kit-open-item="{{ $item['id'] }}" @endunless>{{ $item['name'] }}@if ($__unit !== '')<span class="u-sr">, {{ $__unit }}</span>@endif @if ($__sold)<span class="u-sr">, {{ $kit->s('Sold out') }}</span>@endif</a></h3>
    <p class="c-pcard__unit">@if ($__unit !== '')<span class="c-pcard__size">{{ $kit->flow($__unit) }}</span>@endif</p>
    <div class="c-pcard__foot">
        @if ($__sold)
        <p class="c-pcard__notify">{{ $kit->icon('ban') }}<span>{{ $kit->s('Sold out') }}</span></p>
        @elseif ($__weighed || $__choices)
        <button class="c-pcard__add" type="button" data-kit-open-item="{{ $item['id'] }}" aria-haspopup="dialog" aria-label="{{ $__weighed ? $kit->s('Choose the weight of :name', ['name' => $item['name']]) : $__label }}">{{ $kit->icon($__weighed ? 'scale' : 'plus') }}<span>{{ $__weighed ? $kit->s('Choose weight') : $kit->s('Add') }}</span><span class="c-pcard__qty" data-bk-qty-badge hidden></span></button>
        @else
        <button class="c-pcard__add" type="button" data-juwri-add="{{ $item['id'] }}" data-juwri-name="{{ $item['name'] }}" data-bk-add aria-label="{{ $__label }}">{{ $kit->icon('plus') }}<span>{{ $kit->s('Add') }}</span></button>
        <div class="c-qty c-qty--card" role="group" aria-label="{{ $kit->s('Quantity of :name', ['name' => $item['name']]) }}" data-bk-stepper hidden>
            <button class="c-qty__btn" type="button" data-bk-dec aria-label="{{ $kit->s('Remove one :name', ['name' => $item['name']]) }}">{{ $kit->icon('minus') }}</button>
            <output class="c-qty__val" data-bk-qty>0</output>
            <button class="c-qty__btn" type="button" data-bk-inc aria-label="{{ $kit->s('Add one :name', ['name' => $item['name']]) }}">{{ $kit->icon('plus') }}</button>
        </div>
        @endif
    </div>
</li>
