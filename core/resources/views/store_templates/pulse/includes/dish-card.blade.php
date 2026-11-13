{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $img = media_url('storage/menu/', $menu->image, $store->id ?? null);
    $rating = $menu->rating_avg && $menu->rating_avg > 0
        ? number_format($menu->rating_avg, 1)
        : number_format(4.2 + (($menu->id % 8) / 10), 1);
    $itemAllergies = empty($menu->allergies)
        ? collect()
        : $allergies->whereIn('id', explode(',', $menu->allergies));
    $customizable = (isset($menu->variants) && $menu->variants->isNotEmpty())
        || (isset($menu->extras) && $menu->extras->isNotEmpty());
    $cardIndex = $cardIndex ?? 0;
@endphp
<article class="ps-card" data-item-card data-id="{{ $menu->id }}"
         data-name="{{ \Illuminate\Support\Str::lower($menu->name) }}"
         data-desc="{{ \Illuminate\Support\Str::lower(strip_tags((string) $menu->description)) }}"
         data-cat="{{ $menu->category_id }}"
         data-vegan="{{ $menu->is_vegan ? 1 : 0 }}"
         data-halal="{{ $menu->is_halal ? 1 : 0 }}"
         data-gf="{{ $menu->is_gluten_free ? 1 : 0 }}"
         data-popular="{{ $menu->is_popular ? 1 : 0 }}"
         data-spice="{{ $menu->spice_level }}"
         data-allergens="{{ $menu->allergies ?: '' }}"
         data-amount="{{ $menu->price }}">
    <div class="ps-card-media ps-shimmer">
        <div class="ps-card-badges">
            <span class="ps-badge diet-{{ $menu->type === 'nonveg' ? 'nonveg' : 'veg' }}">
                <i class="ico ico-circle-filled" style="font-size:7px" aria-hidden="true"><x-ui.icon name="circle-filled" /></i> {{ $menu->type === 'nonveg' ? ___('Non Veg') : ___('Veg') }}
            </span>
            @if($menu->is_sold_out)
                <span class="ps-badge" style="background:var(--ps-danger);color:#fff"><i class="ico ico-ban" aria-hidden="true"><x-ui.icon name="ban" /></i> {{ ___('Sold Out') }}</span>
            @endif
            @if($menu->is_vegan)
                <span class="ps-badge diet-vegan"><i class="ico ico-leaf" aria-hidden="true"><x-ui.icon name="leaf" /></i> {{ ___('Vegan') }}</span>
            @endif
            @if($menu->is_halal)
                <span class="ps-badge diet-halal"><i class="ico ico-rosette-discount-check" aria-hidden="true"><x-ui.icon name="rosette-discount-check" /></i> {{ ___('Halal') }}</span>
            @endif
            @if($menu->is_gluten_free)
                <span class="ps-badge diet-gf"><i class="ico ico-wheat" aria-hidden="true"><x-ui.icon name="wheat" /></i> {{ ___('GF') }}</span>
            @endif
            @if($menu->is_popular)
                <span class="ps-badge diet-popular"><i class="ico ico-flame" aria-hidden="true"><x-ui.icon name="flame" /></i> {{ ___('Popular') }}</span>
            @endif
            @if($menu->spice_level > 0)
                <span class="ps-badge diet-spicy">
                    @for($s = 0; $s < $menu->spice_level; $s++)🌶️@endfor
                </span>
            @endif
            @if($customizable)
                <span class="ps-badge diet-custom"><i class="ico ico-adjustments-horizontal" aria-hidden="true"><x-ui.icon name="adjustments-horizontal" /></i> {{ ___('customizable') }}</span>
            @endif
        </div>
        <button class="ps-fav" data-fav="{{ $menu->id }}" type="button" aria-label="{{ ___('Add to favorites') }}">
            <i class="ico ico-heart-filled" aria-hidden="true"><x-ui.icon name="heart-filled" /></i>
        </button>
        @if((string) ($img) !== '')<img src="{{ $img }}" alt="{{ $menu->name }}" loading="lazy"
             data-csp-load="remove-parent-class" data-csp-load-class="ps-shimmer"
             data-csp-error="shimmer-error" data-csp-shimmer="ps-shimmer" data-csp-opacity="true">@endif
    </div>
    <div class="ps-card-body">
        <div class="ps-card-top">
            <h3 class="ps-card-title">{{ $menu->name }}</h3>
            <div class="ps-card-rating"><i class="ico ico-star-filled" aria-hidden="true"><x-ui.icon name="star-filled" /></i> {{ $rating }}</div>
        </div>
        @if($menu->description)
            <p class="ps-card-desc">{{ strip_tags((string) $menu->description) }}</p>
        @endif
        @if(!empty($menu->calories))
        <div class="ps-card-nutrition">
            <span><i class="ico ico-flame" style="color:var(--ps-orange)" aria-hidden="true"><x-ui.icon name="flame" /></i> {{ $menu->calories }} kcal</span>
            @if(!empty($menu->protein))<span>P: {{ $menu->protein }}g</span>@endif
            @if(!empty($menu->carbs))<span>C: {{ $menu->carbs }}g</span>@endif
            @if(!empty($menu->fat))<span>F: {{ $menu->fat }}g</span>@endif
        </div>
        @endif
        @if(!empty($menu->available_from) && !empty($menu->available_to))
        <div class="ps-card-avail" data-from="{{ $menu->available_from }}" data-to="{{ $menu->available_to }}" data-days="{{ $menu->available_days ?? '' }}">
            <i class="ico ico-clock" aria-hidden="true"><x-ui.icon name="clock" /></i> {{ $menu->available_from }} – {{ $menu->available_to }}
        </div>
        @endif
        @if(count($itemAllergies))
            <div class="ps-card-allergies" title="{{ ___('Allergies') }}">
                @foreach($itemAllergies->take(4) as $al)
                    @if((string) (\Illuminate\Support\Str::of($al->image)->isUrl() ? $al->image : media_url('storage/allergies/', $al->image, null)) !== '')<img src="{{ \Illuminate\Support\Str::of($al->image)->isUrl() ? $al->image : media_url('storage/allergies/', $al->image, null) }}"
                         alt="{{ $al->title }}" title="{{ $al->title }}">@endif
                @endforeach
            </div>
        @endif
        <div class="ps-card-foot">
            <div class="ps-card-price">
                {{ price_symbol_format($menu->price) }}
                @if($customizable)<small>{{ ___('starting from') }}</small>@endif
            </div>
            @if(!$menu->is_sold_out)
                @if($customizable)
                    <button class="ps-card-btn" data-open-item="{{ $menu->id }}" type="button">
                        <i class="ico ico-plus" aria-hidden="true"><x-ui.icon name="plus" /></i>
                    </button>
                @else
                    <button class="ps-card-btn ps-quick-add" data-add="{{ $menu->id }}" type="button" aria-label="{{ ___('Add to cart') }}">
                        <i class="ico ico-plus" aria-hidden="true"><x-ui.icon name="plus" /></i>
                    </button>
                @endif
            @else
                <span class="ps-card-btn ps-sold-out-btn"><i class="ico ico-ban" aria-hidden="true"><x-ui.icon name="ban" /></i></span>
            @endif
        </div>
    </div>
</article>
