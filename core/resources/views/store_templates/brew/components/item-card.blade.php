{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- brew: the grid card — image on top (1:1), the badge on the image's top inline-start corner, name, one sub-line, then
     the price and a round add button. One link to the item sheet, one separate add button. --}}
@php
    $__sold = ! empty($item['sold_out']);
    $__qty = ($inCart ?? [])[$item['id']] ?? 0;
    $__show = ! empty($item['short']) && ($vm['theme']['settings']['menu.show_descriptions'] ?? true);
    $__b = ($item['badges'] ?? [])[0] ?? null;
    if ($__sold) {
        $__b = \Juwri\Core\Support\ViewHelpers::badgeOfKind($item['badges'] ?? [], 'sold_out') ?? $__b;
    }
    $__icons = ['popular' => 'flame', 'new' => 'sparkles', 'spicy' => 'flame', 'low_stock' => 'alert-circle', 'sold_out' => 'ban', 'vegan' => 'sparkles', 'sale' => 'tag', 'offer' => 'tag'];
    $__label = $__qty ? $kit->s('Add :name again', ['name' => $item['name']]) : $kit->s('Add :name', ['name' => $item['name']]);
@endphp
<li class="c-gcard{{ $__sold ? ' is-soldout' : '' }}" data-item="{{ $item['id'] }}">
    <div class="c-gcard__media">@if (! empty($item['image']))<img class="c-gcard__img" src="{{ $item['image']['src'] }}" alt="" width="240" height="240" decoding="async"@if (empty($first)) loading="lazy"@endif>@endif
        @if ($__b)<span class="c-badge c-gcard__badge{{ ($__b['kind'] ?? '') === 'sold_out' ? ' c-badge--muted' : '' }}">{{ $kit->icon($__icons[$__b['kind']] ?? 'tag') }}<span>{{ $__b['label'] }}</span></span>@endif</div>
    <div class="c-gcard__body"><h3 class="c-gcard__name"><a class="c-gcard__link" href="{{ $item['url'] }}" data-kit-open-item="{{ $item['id'] }}">{{ $item['name'] }}@if ($__sold)<span class="u-sr">, {{ $kit->s('Sold out') }}</span>@endif</a></h3>@if ($__show)<p class="c-gcard__sub">{{ $item['short'] }}</p>@endif</div>
    <div class="c-gcard__foot"><span class="c-gcard__price">@include('store_templates._kit.components.price')</span>
        @unless ($__sold)
        @if (! empty($item['has_required_choices']))
        <button class="c-gcard__add" type="button" data-kit-open-item="{{ $item['id'] }}" aria-haspopup="dialog" aria-label="{{ $__label }}">{{ $kit->icon('plus') }}@if ($__qty)<span class="c-gcard__qty" aria-hidden="true">{{ $__qty }}</span>@endif</button>
        @else
        <button class="c-gcard__add" type="button" data-juwri-add="{{ $item['id'] }}" data-juwri-name="{{ $item['name'] }}" aria-label="{{ $__label }}">{{ $kit->icon('plus') }}@if ($__qty)<span class="c-gcard__qty" aria-hidden="true">{{ $__qty }}</span>@endif</button>
        @endif
        @endunless
    </div>
</li>
