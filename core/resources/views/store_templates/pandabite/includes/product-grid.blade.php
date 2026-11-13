{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<section class="pb-products" id="pb-products">
    <h3 class="pb-section-title">{{ ___('Menu') }}</h3>
    <div class="pb-product-grid" id="pb-product-grid">
        @foreach($items as $m)
            @php
                $soldOut = (bool) $m->is_sold_out || ((bool) ($m->track_inventory ?? false) && (int) ($m->stock_qty ?? 1) <= 0);
                $rating = $m->rating_avg && $m->rating_avg > 0 ? number_format($m->rating_avg, 1) : null;
                $itemImg = $m->image && $m->image !== 'default.png' ? media_url('storage/menu/', $m->image, $store->id ?? null) : null;
                $initial = strtoupper(mb_substr($m->name, 0, 1));
            @endphp
            <article class="pb-product-card{{ $soldOut ? ' is-sold-out' : '' }}" data-category="{{ $m->category_id }}" data-item-id="{{ $m->id }}" data-search="{{ Str::lower($m->name.' '.strip_tags($m->description)) }}">
                <div class="pb-product-card__img {{ $itemImg ? '' : 'is-fallback' }}">
                    @if($itemImg)
                        @if((string) ($itemImg) !== '')<img src="{{ $itemImg }}" alt="{{ $m->name }}" loading="lazy" data-csp-error="parent-class" data-csp-class="is-fallback" data-csp-remove="true">@endif
                    @else
                        <span class="pb-product-card__fallback-letter">{{ $initial }}</span>
                    @endif
                    @if($rating)
                        <span class="pb-product-card__rating">⭐ {{ $rating }}</span>
                    @endif
                    @if($soldOut)
                        <span class="pb-product-card__badge">{{ ___('Sold out') }}</span>
                    @endif
                </div>
                <div class="pb-product-card__body">
                    <h4 class="pb-product-card__name">{{ $m->name }}</h4>
                    <div class="pb-product-card__foot">
                        <span class="pb-product-card__price">{{ price_symbol_format($m->price) }}</span>
                        <button type="button" class="pb-product-card__add" data-item-id="{{ $m->id }}" aria-label="{{ ___('Add') }} {{ $m->name }}"{{ $soldOut ? ' disabled' : '' }}>+</button>
                    </div>
                </div>
            </article>
        @endforeach
    </div>
    <div class="pb-empty" id="pb-empty" style="display:none;">
        <div class="pb-empty__icon">🔍</div>
        <p>{{ ___('No items found.') }}</p>
    </div>
</section>
