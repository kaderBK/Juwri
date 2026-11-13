{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $img = media_url('storage/menu/', $menu->image, $store->id ?? null);
    /* Ratings are shown ONLY when the item actually has them. This used to fall
       back to `4.2 + (id % 8)/10`, i.e. every unrated dish on the menu carried an
       invented 4.2–4.9 star score derived from its primary key. That is a
       fabricated review presented to a paying customer as data. */
    $rating = $menu->rating_avg && $menu->rating_avg > 0
        ? number_format($menu->rating_avg, 1)
        : null;
    $itemAllergies = empty($menu->allergies)
        ? collect()
        : $allergies->whereIn('id', explode(',', $menu->allergies));
    $customizable = (isset($menu->variants) && $menu->variants->isNotEmpty())
        || (isset($menu->extras) && $menu->extras->isNotEmpty());
    $cardIndex = $cardIndex ?? 0;
@endphp
<article class="au-card" data-item-card data-id="{{ $menu->id }}"
         data-name="{{ \Illuminate\Support\Str::lower($menu->name) }}"
         data-desc="{{ \Illuminate\Support\Str::lower(strip_tags((string) $menu->description)) }}"
         data-cat="{{ $menu->category_id }}"
         data-vegan="{{ $menu->is_vegan ? 1 : 0 }}"
         data-halal="{{ $menu->is_halal ? 1 : 0 }}"
         data-gf="{{ $menu->is_gluten_free ? 1 : 0 }}"
         data-popular="{{ $menu->is_popular ? 1 : 0 }}"
         data-spice="{{ $menu->spice_level }}"
         data-allergens="{{ $menu->allergies ?: '' }}"
         data-amount="{{ $menu->price }}"
         style="animation-delay: {{ min($cardIndex * 0.04, 0.4) }}s;">
    <div class="au-card-media au-shimmer">
        <div class="au-card-badges">
            @if(!empty($menu->schedule_label))
                {{-- Happy-hour / day-part offer (MenuScheduleService::decorate). Was absent
                     while the price also dropped the strike — the offer was fully invisible. --}}
                <span class="au-badge au-badge-hh" style="background:linear-gradient(135deg,#f59e0b,#ef4444);color:#fff"><i class="ico ico-clock" aria-hidden="true"><x-ui.icon name="clock" /></i>{{ $menu->schedule_label }}</span>
            @endif
            <span class="au-badge diet-{{ $menu->type === 'nonveg' ? 'nonveg' : 'veg' }}">
                <i class="ico ico-circle-filled" style="font-size:8px" aria-hidden="true"><x-ui.icon name="circle-filled" /></i>{{ $menu->type === 'nonveg' ? ___('Non Veg') : ___('Veg') }}
            </span>
            @if($menu->is_sold_out)
                <span class="au-badge" style="background:#ff4757;color:#fff"><i class="ico ico-ban" aria-hidden="true"><x-ui.icon name="ban" /></i>{{ ___('Sold Out') }}</span>
            @endif
            @if($menu->is_vegan)
                <span class="au-badge diet-vegan"><i class="ico ico-leaf" aria-hidden="true"><x-ui.icon name="leaf" /></i>{{ ___('Vegan') }}</span>
            @endif
            @if($menu->is_halal)
                <span class="au-badge diet-halal"><i class="ico ico-rosette-discount-check" aria-hidden="true"><x-ui.icon name="rosette-discount-check" /></i>{{ ___('Halal') }}</span>
            @endif
            @if($menu->is_gluten_free)
                <span class="au-badge diet-gf"><i class="ico ico-wheat" aria-hidden="true"><x-ui.icon name="wheat" /></i>{{ ___('GF') }}</span>
            @endif
            @if($menu->is_popular)
                <span class="au-badge diet-popular"><i class="ico ico-flame" aria-hidden="true"><x-ui.icon name="flame" /></i>{{ ___('Popular') }}</span>
            @endif
            @if($menu->spice_level > 0)
                <span class="au-badge diet-spicy">
                    @for($s = 0; $s < $menu->spice_level; $s++)🌶️@endfor
                </span>
            @endif
            @if($customizable)
                <span class="au-badge diet-custom"><i class="ico ico-adjustments-horizontal" aria-hidden="true"><x-ui.icon name="adjustments-horizontal" /></i>{{ ___('customizable') }}</span>
            @endif
        </div>
        <button class="au-fav" data-fav="{{ $menu->id }}" type="button" aria-label="{{ ___('Add to favorites') }}">
            <i class="ico ico-heart-filled" aria-hidden="true"><x-ui.icon name="heart-filled" /></i>
        </button>
        @if((string) ($img) !== '')<img src="{{ $img }}" alt="{{ $menu->name }}" loading="lazy"
             data-csp-load="remove-parent-class" data-csp-load-class="au-shimmer"
             data-csp-error="shimmer-error" data-csp-shimmer="au-shimmer" data-csp-opacity="true">@endif
    </div>
    <div class="au-card-body">
        <div class="au-card-top">
            <div>
                <h3 class="au-card-title"><a href="?item={{ $menu->id }}" style="color:inherit;text-decoration:none">{{ $menu->name }}</a></h3>
                @if($rating)
                    <div class="au-card-rating"><i class="ico ico-star-filled" aria-hidden="true"><x-ui.icon name="star-filled" /></i> {{ $rating }}</div>
                @endif
            </div>
        </div>
        @if($menu->description)
            <p class="au-card-desc">{{ strip_tags((string) $menu->description) }}</p>
        @endif
        {{-- #7: Nutrition info --}}
        @if(!empty($menu->calories))
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px;font-size:11px;color:var(--au-muted);">
            <span><i class="ico ico-flame" style="color:#f97316" aria-hidden="true"><x-ui.icon name="flame" /></i> {{ $menu->calories }} kcal</span>
            @if(!empty($menu->protein))<span>P: {{ $menu->protein }}g</span>@endif
            @if(!empty($menu->carbs))<span>C: {{ $menu->carbs }}g</span>@endif
            @if(!empty($menu->fat))<span>F: {{ $menu->fat }}g</span>@endif
        </div>
        @endif
        {{-- #4: Availability window --}}
        @if(!empty($menu->available_from) && !empty($menu->available_to))
        <div style="font-size:11px;color:var(--au-muted);margin-bottom:6px;" class="au-avail-badge"
             data-from="{{ $menu->available_from }}" data-to="{{ $menu->available_to }}"
             data-days="{{ $menu->available_days ?? '' }}">
            <i class="ico ico-clock" aria-hidden="true"><x-ui.icon name="clock" /></i> {{ $menu->available_from }} – {{ $menu->available_to }}
        </div>
        @endif
        @if(count($itemAllergies))
            <div class="au-card-allergies" title="{{ ___('Allergies') }}">
                @foreach($itemAllergies->take(4) as $al)
                    @if((string) (\Illuminate\Support\Str::of($al->image)->isUrl() ? $al->image : media_url('storage/allergies/', $al->image, null)) !== '')<img src="{{ \Illuminate\Support\Str::of($al->image)->isUrl() ? $al->image : media_url('storage/allergies/', $al->image, null) }}"
                         alt="{{ $al->title }}" title="{{ $al->title }}">@endif
                @endforeach
            </div>
        @endif
        <div class="au-card-foot">
            <div class="au-card-price">
                @if(!is_null($menu->original_price) && (float) $menu->original_price > (float) $menu->price)
                    <del class="au-was" style="opacity:.55;font-weight:400;margin-inline-end:.35em">{{ price_symbol_format($menu->original_price) }}</del>
                @endif
                {{ price_symbol_format($menu->price) }}
                @if($customizable)<small>{{ ___('starting from') }}</small>@endif
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
                {{-- #61: Share button --}}
                <button class="share-dish-btn" type="button"
                        data-name="{{ $menu->name }}"
                        data-price="{{ price_symbol_format($menu->price) }}"
                        data-url="{{ url()->current() }}#item-{{ $menu->id }}"
                        style="background:transparent;border:1px solid var(--au-border-hi);border-radius:8px;width:34px;height:34px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:inherit;opacity:.7;flex-shrink:0;"
                        aria-label="{{ ___('Share') }} {{ $menu->name }}">
                    <i class="ico ico-share" style="font-size:12px;" aria-hidden="true"><x-ui.icon name="share" /></i>
                </button>
                @if($menu->is_sold_out)
                    <button class="au-add is-ghost" type="button" disabled style="opacity:0.5;cursor:not-allowed;">
                        {{ ___('Sold Out') }}
                    </button>
                @elseif($allow_order)
                    <button class="au-add" type="button" data-open-item="{{ $menu->id }}">
                        <i class="ico ico-plus" aria-hidden="true"><x-ui.icon name="plus" /></i> <span data-tc-text="texts.add_to_cart">{{ theme_setting($store->id, 'texts.add_to_cart', '') ?: ___('Add') }}</span>
                    </button>
                @else
                    <button class="au-add is-ghost" type="button" data-open-item="{{ $menu->id }}">
                        <i class="ico ico-eye" aria-hidden="true"><x-ui.icon name="eye" /></i> {{ ___('View') }}
                    </button>
                @endif
            </div>
        </div>
    </div>
</article>
