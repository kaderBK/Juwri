{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- the shared item card (list | grid | compact): one link to the item sheet, one separate add button --}}
@php
    $__v = $variant ?? ($vm['theme']['settings']['layout.card'] ?? 'list');
    $__sold = ! empty($item['sold_out']);
    $__qty = ($inCart ?? [])[$item['id']] ?? 0;
    $__show = ! empty($item['short']) && ($__v === 'compact' || ($vm['theme']['settings']['menu.show_descriptions'] ?? true));
@endphp
<li class="c-card c-card--{{ $__v }}{{ $__sold ? ' is-soldout' : '' }}" data-item="{{ $item['id'] }}">
    <div class="c-card__body">
        <h3 class="c-card__name"><a class="c-card__link" href="{{ $item['url'] }}" data-kit-open-item="{{ $item['id'] }}">{{ $item['name'] }}@if ($__sold)<span class="u-sr">, {{ $kit->s('Sold out') }}</span>@endif</a></h3>
        @if ($__show)<p class="c-card__desc">{{ $item['short'] }}</p>@endif
        <p class="c-card__meta"><span class="c-card__price">@include('store_templates._kit.components.price')</span>@include('store_templates._kit.components.badge')</p>
    </div>
    <div class="c-card__media">
        @if ($__v !== 'compact' && ! empty($item['image']))<img class="c-card__img" src="{{ $item['image']['src'] }}" alt="" width="96" height="96" decoding="async"@if (empty($first)) loading="lazy"@endif>@endif
        @unless ($__sold)
        @if (! empty($item['has_required_choices']))
        <button class="c-card__add" type="button" data-kit-open-item="{{ $item['id'] }}" aria-haspopup="dialog" aria-label="{{ $__qty ? $kit->s('Add :name again', ['name' => $item['name']]) : $kit->s('Add :name', ['name' => $item['name']]) }}">{{ $kit->icon('plus') }}<span class="c-card__add-label">{{ $kit->s('Add') }}</span>@if ($__qty)<span class="c-card__qty" aria-hidden="true">{{ $__qty }}</span>@endif</button>
        @else
        <button class="c-card__add" type="button" data-juwri-add="{{ $item['id'] }}" data-juwri-name="{{ $item['name'] }}" aria-label="{{ $__qty ? $kit->s('Add :name again', ['name' => $item['name']]) : $kit->s('Add :name', ['name' => $item['name']]) }}">{{ $kit->icon('plus') }}<span class="c-card__add-label">{{ $kit->s('Add') }}</span>@if ($__qty)<span class="c-card__qty" aria-hidden="true">{{ $__qty }}</span>@endif</button>
        @endif
        @endunless
    </div>
</li>
