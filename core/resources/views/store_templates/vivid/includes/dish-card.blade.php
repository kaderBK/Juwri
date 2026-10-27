{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $img = media_url('storage/menu/', $menu->image, $store->id ?? null);
    $rating = $menu->rating_avg && $menu->rating_avg > 0
        ? number_format($menu->rating_avg, 1)
        : '';
    $itemAllergies = empty($menu->allergies)
        ? collect()
        : $allergies->whereIn('id', explode(',', $menu->allergies));
    $customizable = (isset($menu->variants) && $menu->variants->isNotEmpty())
        || (isset($menu->extras) && $menu->extras->isNotEmpty());
    $cardIndex = $cardIndex ?? 0;
    $isSoldOut = (bool) $menu->is_sold_out
        || (!empty($menu->track_inventory) && isset($menu->stock_qty) && (int) $menu->stock_qty <= 0);
    $isLowStock = !$isSoldOut && !empty($menu->track_inventory)
        && isset($menu->stock_qty) && $menu->stock_qty > 0
        && (int) $menu->stock_qty <= (int) ($menu->low_stock_threshold ?? 0);
@endphp
<article class="vd-card" data-item-card data-id="{{ $menu->id }}"
         data-name="{{ Illuminate\Support\Str::lower($menu->name) }}"
         data-desc="{{ Illuminate\Support\Str::lower(html_entity_decode(strip_tags((string) $menu->description), ENT_QUOTES)) }}"
         data-cat="{{ $menu->category_id }}"
         data-vegan="{{ $menu->is_vegan ? 1 : 0 }}"
         data-halal="{{ $menu->is_halal ? 1 : 0 }}"
         data-gf="{{ $menu->is_gluten_free ? 1 : 0 }}"
         data-popular="{{ $menu->is_popular ? 1 : 0 }}"
         data-spice="{{ $menu->spice_level ?? 0 }}"
         data-allergens="{{ $menu->allergies ?: '' }}"
         data-amount="{{ $menu->price }}"
         style="--vd-card-delay:{{ min($cardIndex * 0.05, 0.4) }}s;">
    <div class="vd-card-media vd-shimmer">
        <div class="vd-card-badges">
            @if(!empty($menu->schedule_label))
                {{-- Happy-hour offer badge — strike was already rendered (vd-was) but the
                     badge was missing, so the discount had no label. --}}
                <span class="vd-badge vd-badge-hh" style="background:linear-gradient(135deg,#f59e0b,#ef4444);color:#fff"><i class="ico ico-clock" aria-hidden="true"><x-ui.icon name="clock" /></i>{{ $menu->schedule_label }}</span>
            @endif
            <span class="vd-badge vd-badge-{{ $menu->type === 'nonveg' ? 'nonveg' : 'veg' }}">
                <i class="ico ico-circle-filled" style="font-size:7px" aria-hidden="true"><x-ui.icon name="circle-filled" /></i>{{ $menu->type === 'nonveg' ? ___('Non Veg') : ___('Veg') }}
            </span>
            @if($isSoldOut)
                <span class="vd-badge vd-badge-soldout"><i class="ico ico-ban" aria-hidden="true"><x-ui.icon name="ban" /></i>{{ ___('Sold Out') }}</span>
            @endif
            @if($menu->is_vegan)
                <span class="vd-badge vd-badge-vegan"><i class="ico ico-leaf" aria-hidden="true"><x-ui.icon name="leaf" /></i>{{ ___('Vegan') }}</span>
            @endif
            @if($menu->is_halal)
                <span class="vd-badge vd-badge-halal"><i class="ico ico-rosette-discount-check" aria-hidden="true"><x-ui.icon name="rosette-discount-check" /></i>{{ ___('Halal') }}</span>
            @endif
            @if($menu->is_gluten_free)
                <span class="vd-badge vd-badge-gf"><i class="ico ico-wheat" aria-hidden="true"><x-ui.icon name="wheat" /></i>GF</span>
            @endif
            @if($menu->is_popular)
                <span class="vd-badge vd-badge-popular"><i class="ico ico-flame" aria-hidden="true"><x-ui.icon name="flame" /></i>{{ ___('Popular') }}</span>
            @endif
            @if(($menu->spice_level ?? 0) > 0)
                <span class="vd-badge vd-badge-spicy">
                    @for($s = 0; $s < ($menu->spice_level ?? 0); $s++)🌶️@endfor
                </span>
            @endif
            @if($customizable)
                <span class="vd-badge vd-badge-custom"><i class="ico ico-adjustments-horizontal" aria-hidden="true"><x-ui.icon name="adjustments-horizontal" /></i>{{ ___('customizable') }}</span>
            @endif
        </div>
        <button class="vd-fav" data-fav="{{ $menu->id }}" type="button" aria-label="{{ ___('Add to favorites') }}">
            <i class="ico ico-heart-filled" aria-hidden="true"><x-ui.icon name="heart-filled" /></i>
        </button>
        @if((string) ($img) !== '')<img src="{{ $img }}" alt="{{ $menu->name }}" loading="lazy"
             data-csp-load="remove-parent-class" data-csp-load-class="vd-shimmer"
             data-csp-error="shimmer-error" data-csp-shimmer="vd-shimmer" data-csp-class="vd-noimg" data-csp-remove="true">@endif
    </div>
    <div class="vd-card-body">
        <div class="vd-card-top">
            <h3 class="vd-card-title"><a href="?item={{ $menu->id }}" style="color:inherit;text-decoration:none">{{ $menu->name }}</a></h3>
            @if($rating !== '')
            <div class="vd-card-rating"><i class="ico ico-star-filled" aria-hidden="true"><x-ui.icon name="star-filled" /></i> {{ $rating }}</div>
            @endif
        </div>
        @if($menu->description)
        <p class="vd-card-desc">{{ html_entity_decode(strip_tags((string) $menu->description), ENT_QUOTES) }}</p>
        @endif
        @if(!empty($menu->calories))
        <div class="vd-card-nutrition">
            <span><i class="ico ico-flame" aria-hidden="true"><x-ui.icon name="flame" /></i> {{ $menu->calories }} kcal</span>
            @if(!empty($menu->protein))<span>P {{ $menu->protein }}g</span>@endif
            @if(!empty($menu->carbs))<span>C {{ $menu->carbs }}g</span>@endif
            @if(!empty($menu->fat))<span>F {{ $menu->fat }}g</span>@endif
        </div>
        @endif
        @if($isLowStock)
        <div class="vd-card-lowstock"><i class="ico ico-error" aria-hidden="true"><x-ui.icon name="error" /></i> {{ ___('Low stock') }} — {{ ___(':count left', ['count' => $menu->stock_qty]) }}</div>
        @endif
        @if(count($itemAllergies))
        <div class="vd-card-allergies" title="{{ ___('Allergies') }}">
            @foreach($itemAllergies->take(4) as $al)
                @if((string) (Illuminate\Support\Str::of($al->image)->isUrl() ? $al->image : media_url('storage/allergies/', $al->image, null)) !== '')<img src="{{ Illuminate\Support\Str::of($al->image)->isUrl() ? $al->image : media_url('storage/allergies/', $al->image, null) }}"
                     alt="{{ $al->title }}" title="{{ $al->title }}">@endif
            @endforeach
        </div>
        @endif
        <div class="vd-card-foot">
            <div class="vd-card-price">
                @if(!is_null($menu->original_price) && (float) $menu->original_price > (float) $menu->price)
                    {{-- K8: day-part / happy-hour reference price (MenuScheduleService::decorate) --}}
                    <del class="vd-was" style="opacity:.8;font-weight:400;margin-inline-end:.35em">{{ price_symbol_format($menu->original_price) }}</del>
                @endif
                <span class="vd-amt" data-vd-base="{{ $menu->price }}">{{ price_symbol_format($menu->price) }}</span>
                @if($customizable)<small>{{ ___('from') }}</small>@endif
            </div>
            <div class="vd-card-actions">
                @if($isSoldOut)
                    <button class="vd-btn-add is-disabled" type="button" disabled>{{ ___('Sold Out') }}</button>
                @elseif($allow_order)
                    <button class="vd-btn-add" type="button" data-open-item="{{ $menu->id }}">
                        <i class="ico ico-plus" aria-hidden="true"><x-ui.icon name="plus" /></i> <span data-tc-text="texts.add_to_cart">{{ theme_setting($store->id, 'texts.add_to_cart', '') ?: ___('Add') }}</span>
                    </button>
                @else
                    <button class="vd-btn-add is-ghost" type="button" data-open-item="{{ $menu->id }}">
                        <i class="ico ico-eye" aria-hidden="true"><x-ui.icon name="eye" /></i> {{ ___('View') }}
                    </button>
                @endif
            </div>
        </div>
    </div>
</article>