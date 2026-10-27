{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- maison — editorial product card (signature component).

     Single source of truth for the card markup: included by both the Home
     "New in" teaser grid and the Shop (full catalog) grid, and mirrored by
     the JS `cardHTML()` template (index.blade.php) for the three lists that
     can only ever be composed client-side — Wishlist (localStorage
     membership), AI stylist picks, and the Visual search match. Keep this
     partial's classes/structure in lock-step with that JS function.

     Expects: $jd (one entry of $jsItems — see index.blade.php @php block)
     and $vocab (VerticalVocab::map() output). --}}
<article class="ms-card" data-id="{{ $jd['id'] }}" data-root="{{ $jd['root'] }}"
         data-brand="{{ \Illuminate\Support\Str::lower($jd['brand'] ?? '') }}"
         data-name="{{ \Illuminate\Support\Str::lower($jd['name']) }}"
         data-desc="{{ \Illuminate\Support\Str::lower($jd['desc']) }}"
         data-price="{{ $jd['price'] }}" data-created="{{ $jd['createdAt'] }}">
    <div class="ms-card__media">
        @if($jd['discountPct'])
            <span class="ms-pill ms-pill--sale">&minus;{{ $jd['discountPct'] }}%</span>
        @elseif($jd['soldOut'])
            <span class="ms-pill ms-pill--soldout">{{ ___('Sold out') }}</span>
        @endif
        <button type="button" class="ms-heart" data-fav-toggle="{{ $jd['id'] }}" aria-pressed="false" aria-label="{{ ___('Add to favorites') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
        </button>
        <a href="?item={{ $jd['id'] }}" class="ms-card__link" data-open-item-link="{{ $jd['id'] }}" aria-label="{{ $jd['name'] }}">
            @if($jd['img'])
                @if((string) ($jd['img']) !== '')<img class="ms-card__img" src="{{ $jd['img'] }}" alt="" loading="lazy" data-csp-error="hide">@endif
            @else
                <span class="ms-card__img ms-card__img--ph" role="img" aria-label="{{ $jd['name'] }}">
                    <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M8 3l4 3 4-3 3 3-2 3v12H5V9L3 6z"/></svg>
                </span>
            @endif
            <span class="ms-card__scrim" aria-hidden="true"></span>
            <span class="ms-card__meta">
                @if($jd['brand'])<span class="ms-card__brand">{{ $jd['brand'] }}</span>@endif
                <span class="ms-card__name">{{ $jd['name'] }}</span>
                <span class="ms-card__row">
                    <span class="ms-price">{{ $jd['priceFormatted'] }}</span>
                    @if($jd['compareAtFormatted'])<span class="ms-price ms-price--was">{{ $jd['compareAtFormatted'] }}</span>@endif
                </span>
            </span>
        </a>
        <button type="button" class="ms-bagbtn" data-open-item="{{ $jd['id'] }}" @if($jd['soldOut']) disabled @endif aria-label="{{ $vocab['add_to_cart'] ?? ___('Add to cart') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l-1 12H7L6 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
        </button>
    </div>
</article>
