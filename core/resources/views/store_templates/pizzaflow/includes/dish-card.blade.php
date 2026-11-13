{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
$img = $menu->image && $menu->image !== 'default.png'
    ? asset('storage/menu/'.$menu->image)
    : asset('storage/menu/default.png');
$isSoldOut = $menu->is_sold_out || ($menu->track_inventory && $menu->stock_qty !== null && $menu->stock_qty <= 0);
$rating = $menu->rating_avg ? round($menu->rating_avg, 1) : null;
@endphp

<div class="pf-card{{ $isSoldOut ? ' sold-out' : '' }}" data-id="{{ $menu->id }}" data-cat="{{ $menu->category_id }}">
    <div class="pf-card-media">
        @if((string) ($img) !== '')<img src="{{ $img }}" alt="{{ $menu->name }}" loading="lazy" data-csp-error="fallback" data-csp-fallback="{{ asset('storage/menu/default.png') }}">@endif
        <button class="pf-fav" data-id="{{ $menu->id }}" aria-label="{{ ___('Favorite') }}" type="button">
            <i class="ico ico-heart" aria-hidden="true"><x-ui.icon name="heart" /></i>
        </button>
        <div class="pf-card-badges">
            @if($isSoldOut)
                <span class="pf-badge sold-out-badge">{{ ___('Sold Out') }}</span>
            @endif
            @if($menu->type === 'veg')
                <span class="pf-badge diet-veg">{{ ___('Veg') }}</span>
            @elseif($menu->type === 'nonveg')
                <span class="pf-badge diet-nonveg">{{ ___('Non Veg') }}</span>
            @endif
            @if($menu->is_popular)
                <span class="pf-badge diet-custom">⭐ {{ ___('Popular') }}</span>
            @endif
        </div>
    </div>
    <div class="pf-card-body">
        <div class="pf-card-title">{{ $menu->name }}</div>
        @if($menu->description)
            <div class="pf-card-desc">{{ \Str::limit($menu->description, 60) }}</div>
        @endif
        @if($rating)
        <div class="pf-card-rating"><i class="ico ico-star-filled" style="color:var(--pf-star);font-size:12px" aria-hidden="true"><x-ui.icon name="star-filled" /></i> {{ $rating }}</div>
        @endif
        <div class="pf-card-foot">
            <span class="pf-card-price">{{ price_symbol_format($menu->price) }}</span>
            @if(!$isSoldOut)
            <button class="pf-add" data-id="{{ $menu->id }}" data-name="{{ e($menu->name) }}"
                    data-image="{{ $img }}" data-price="{{ $menu->price }}" type="button"
                    aria-label="{{ ___('Add to order') }}">
                <i class="ico ico-plus" aria-hidden="true"><x-ui.icon name="plus" /></i>
            </button>
            @endif
        </div>
    </div>
</div>
