{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- zevoa grocery product card ("Grocery card" signature component).

     Product-on-white tile: name (2-line clamp), unit sub (`/kg`, `/1L`…,
     defensive — see $unitOf in index.blade.php), bold price (+ compare-at
     strike/discount ribbon when present), green "+" quick-add that posts to
     the item's REAL addRoute (rev/multi-vertical Lane V goods cart).

     Shared by the home "Popular near you" row, the ZeMart store grid, the
     favorites screen and every JS-rendered upsell row (cardHTML() in the
     script below keeps the SAME markup shape so all four contexts render
     byte-identical cards). Included with `$jd` = one $jsItems[...] entry;
     $vocab/$store are inherited from the including view (Blade @include
     shares the parent's data scope). --}}
@php
    use Illuminate\Support\Str;
@endphp
<article class="zv-pcard" data-id="{{ $jd['id'] }}" data-root="{{ $jd['root'] }}"
         data-brand="{{ Str::lower($jd['brand'] ?? '') }}" data-price="{{ $jd['price'] ?? '' }}"
         data-name="{{ Str::lower($jd['name']) }}" data-desc="{{ Str::lower($jd['desc']) }}">
    <div class="zv-imgwrap">
        @if(($jd['discountPct'] ?? 0) > 0)
            <span class="zv-disc">-{{ $jd['discountPct'] }}%</span>
        @endif
        @if($jd['soldOut'])
            <span class="zv-soldout">{{ ___('Sold out') }}</span>
        @endif
        {{-- P3-style real, shareable/crawlable ?item= URL — same convention
             as shelf/menu-item-anchor-links: the script intercepts this
             click and opens the product screen in place. --}}
        <a href="?item={{ $jd['id'] }}" class="zv-card-media-link" data-zv-open-link="{{ $jd['id'] }}" aria-label="{{ $jd['name'] }}">
            @if($jd['img'])
                @if((string) ($jd['img']) !== '')<img src="{{ $jd['img'] }}" alt="" loading="lazy" data-csp-error="hide">@endif
            @else
                <div class="zv-img-ph" role="img" aria-label="{{ $jd['name'] }}">
                    <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 8h16l-1.6 11a2 2 0 0 1-2 1.8H7.6a2 2 0 0 1-2-1.8L4 8z"/><path d="M8 8V6a4 4 0 0 1 8 0v2"/></svg>
                </div>
            @endif
        </a>
        <button type="button" class="zv-heart" data-zv-heart="{{ $jd['id'] }}" aria-pressed="false" aria-label="{{ ___('Add to favorites') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>
        </button>
        <button type="button" class="zv-addbag" data-zv-add="{{ $jd['id'] }}" aria-label="{{ $vocab['add_to_cart'] ?? ___('Add to cart') }}" @if($jd['soldOut']) disabled @endif>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        </button>
    </div>
    <div class="zv-pbody">
        @if($jd['brand'])<span class="zv-pbrand">{{ $jd['brand'] }}</span>@endif
        <div class="zv-pname">{{ $jd['name'] }}</div>
        @if($jd['unit'])<div class="zv-punit">{{ $jd['unit'] }}</div>@endif
        @if($jd['stockBadge'] && ! $jd['soldOut'])
            <span class="zv-stock zv-stock--{{ $jd['stockBadge']['type'] }}">{{ $jd['stockBadge']['text'] }}</span>
        @endif
        <div class="zv-prow">
            <span class="zv-price">{{ $jd['priceFormatted'] }}</span>
            @if($jd['compareAtFormatted'])<span class="zv-price zv-price--was">{{ $jd['compareAtFormatted'] }}</span>@endif
        </div>
    </div>
</article>
