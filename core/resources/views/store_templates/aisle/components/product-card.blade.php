{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle: the product card (component/Product card) — image 4:5, the badge on the top inline-start corner, the wishlist heart on
     the top inline-end corner (its own 44 px button), brand, a 2-line name, the price row (+ was-price), up to three colour dots.
     One link per card (name + price + colours in its name); no add button: a variant is chosen on the product page. On the wishlist
     page ($wishItem) the card also carries "Move to bag" and "Remove". At a kiosk the link opens the item's sheet. --}}
@php
    $__hl = (int) ($headingLevel ?? 3);
    $__sold = ! empty($item['sold_out']);
    $__saved = in_array($item['id'], $vm['customer']['wishlist'] ?? [], true) || ! empty($wishItem);
    $__kinds = \Juwri\Core\Support\ViewHelpers::badgeLabels($item['badges'] ?? []);
    $__b = match (true) {
        $__sold => ['sold', $__kinds['sold_out'] ?? $kit->s('Sold out')],
        isset($__kinds['sale']) || isset($__kinds['offer']) => ['sale', $__kinds['sale'] ?? $__kinds['offer']],
        isset($__kinds['low_stock']) => ['low', $__kinds['low_stock']],
        isset($__kinds['new']) => ['new', $__kinds['new']],
        default => null,
    };
    $__sw = $item['swatches'] ?? [];
    $__price = $item['price'] ?? $item['from_price'] ?? null;
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
    $__wish = ! empty($vm['features']['wishlist']) && ! $__kiosk;
@endphp
<li class="c-pcard{{ $__sold ? ' is-soldout' : '' }}" data-item="{{ $item['id'] }}">
    <div class="c-pcard__media">
        @if (! empty($item['image']))<img class="c-pcard__img" src="{{ $item['image']['src'] }}" alt="" width="480" height="600" decoding="async"@if (empty($first)) loading="lazy"@endif>@endif
        @if ($__b)<span class="c-pbadge c-pbadge--{{ $__b[0] }}">{{ $__b[1] }}</span>@endif
        @if ($__wish)<button class="c-heart" type="button" data-kit-wish="{{ $item['id'] }}" aria-pressed="{{ $__saved ? 'true' : 'false' }}" aria-label="{{ $kit->s('Save :name', ['name' => $item['name']]) }}">{{ $kit->icon('heart') }}</button>@endif
    </div>
    <div class="c-pcard__body">
        @if (! empty($item['brand']))<p class="c-pcard__brand"><bdi>{{ $item['brand'] }}</bdi></p>@endif
        <h{{ $__hl }} class="c-pcard__name"><a class="c-pcard__link" href="{{ $item['url'] }}" @if ($__kiosk && ! $__sold) data-kit-open-item="{{ $item['id'] }}" @endif>{{ $item['name'] }}<span class="u-sr">@if ($__price), {{ $kit->money($__price) }}@endif @if ($__sw), {{ $kit->n('colour', count($__sw)) }}@endif @if ($__sold), {{ $kit->s('Sold out') }}@endif</span></a></h{{ $__hl }}>
        @if ($__price)
        <p class="c-pcard__price"><span class="c-pcard__now">@if (! empty($item['from_price'])){{ $kit->t('from :amount', ['amount' => $kit->money($item['from_price'])]) }}@else{{ $kit->money($item['price']) }}@endif</span>@if (! empty($item['compare_at']))<s class="c-pcard__was"><span class="u-sr">{{ $kit->t('Was :amount', ['amount' => $kit->money($item['compare_at'])]) }}</span><span aria-hidden="true">{{ $kit->money($item['compare_at']) }}</span></s>@endif</p>
        @endif
        @if ($__sw)
        <div class="c-pcard__swatches" aria-hidden="true">@foreach (array_slice($__sw, 0, 3) as $__s)<svg class="c-sw c-sw--sm" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><circle class="c-sw__c" cx="10" cy="10" r="9" fill="{{ $__s['hex'] }}"/></svg>@endforeach @if (count($__sw) > 3)<span class="c-pcard__more">{{ $kit->flow('+'.(count($__sw) - 3)) }}</span>@endif</div>
        @endif
    </div>
    @if (! empty($wishItem))
    @php($__wv = ($wishVariants ?? [])[$item['id']] ?? null)
    <div class="c-wish__actions">
        @if (! $__sold && ($__wv !== null || empty($item['has_required_choices'])))
        <button class="c-btn c-btn--secondary c-btn--block" type="button" data-kit-wish-move="{{ $item['id'] }}" data-variant="{{ $__wv }}" data-name="{{ $item['name'] }}">{{ $kit->s('Move to bag') }}</button>
        @elseif (! $__sold)
        <a class="c-btn c-btn--secondary c-btn--block" href="{{ $item['url'] }}">{{ $kit->s('Choose options') }}</a>
        @endif
        <button class="c-textbtn" type="button" data-kit-wish-remove="{{ $item['id'] }}" aria-label="{{ $kit->s('Remove :name', ['name' => $item['name']]) }}"><span>{{ $kit->s('Remove') }}</span></button>
    </div>
    @endif
</li>
