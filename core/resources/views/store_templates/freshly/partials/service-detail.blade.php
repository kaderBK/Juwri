{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- freshly — one service's detail/quantity-builder screen (a top-level menu
     category + the real Menu rows inside it). One of these sections is
     server-rendered per category up front (categories are few for a service
     store), so every item is a real, present DOM node — no client-side HTML
     templating for the structure, only the qty/subtotal numbers update live.
     Expects: $cat (MenuCategory), $its (array rows from $categoryItems),
     $T (string table), $catImg, $catFromFormatted, $catUnitLabel. --}}
<section class="frs-screen" id="frs-service-{{ $cat->id }}" data-screen="service:{{ $cat->id }}" hidden>
    <header class="frs-hdr">
        <button type="button" class="frs-iconbtn" data-back aria-label="{{ $T['back'] }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
        </button>
        {{-- No .frs-hdr-title here: the media column below carries the service
             name as a hero heading, so a header title would duplicate it. --}}
        <span class="frs-hdr-spacer" aria-hidden="true"></span>
        <div class="frs-hdr-tools">
            <button type="button" class="frs-iconbtn" data-nav="saved" aria-label="{{ $T['savedTitle'] }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
                <span class="frs-badge frs-saved-badge" hidden>0</span>
            </button>
            <button type="button" class="frs-iconbtn" data-nav="basket" aria-label="{{ $T['basket'] }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l1 12a1 1 0 01-1 1H6a1 1 0 01-1-1z"/><path d="M9 8V6a3 3 0 016 0v2"/></svg>
                <span class="frs-badge frs-cart-badge" hidden>0</span>
            </button>
        </div>
    </header>
    <div class="frs-scroll" style="padding-bottom:132px">
        {{-- .frs-detail-media / .frs-detail-options are new wrapper elements
             (desktop 2-col layout only — see index.blade.php's ≥1024 media
             query). They only regroup the SAME existing nodes below; every
             class/id/data-* here is untouched, so the JS (which selects by
             those attributes, never by DOM position) keeps working as-is. --}}
        @php $frsGallery = $catGallery ?? ($catImg ? [$catImg] : []); @endphp
        <div class="frs-detail-media">
            {{-- Title + "from" price lead the media column so the service is named
                 above its image/gallery (not stranded below the thumbnail strip). --}}
            <div class="frs-detail-title">{{ $cat->name }}</div>
            <div class="frs-detail-from">{{ $T['from'] }} {{ $catFromFormatted }}/{{ $catUnitLabel }}</div>
            <div class="frs-detail-banner">
                @if(count($frsGallery))
                    @if((string) ($frsGallery[0]) !== '')<img src="{{ $frsGallery[0] }}" alt="{{ $cat->name }}" loading="lazy" data-csp-error="hide" data-detail-main>@endif
                @else
                    <span class="frs-detail-banner-ph" role="img" aria-label="{{ $cat->name }}">
                        <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M12 3s7 8 7 13a7 7 0 01-14 0c0-5 7-13 7-13z"/></svg>
                    </span>
                @endif
            </div>
            @if(count($frsGallery) > 1)
                <div class="frs-thumbs">
                    @foreach($frsGallery as $gi => $gsrc)
                        <button type="button" class="frs-thumb" data-detail-thumb="{{ $gsrc }}" aria-current="{{ $gi === 0 ? 'true' : 'false' }}" aria-label="{{ $cat->name }} {{ $gi + 1 }}">
                            @if((string) ($gsrc) !== '')<img src="{{ $gsrc }}" alt="" loading="lazy" data-csp-error="hide">@endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="frs-detail-options">
            <div class="frs-label">{{ $T['servicesLabel'] }}</div>
            <div class="frs-qtylist">
                @foreach($its as $it)
                    <div class="frs-qtyrow" data-item="{{ $it['id'] }}" data-price="{{ $it['price'] }}"
                         data-unit="{{ $it['unit'] }}" data-add-route="{{ $it['addRoute'] }}"
                         data-service="{{ $cat->id }}">
                        <div class="frs-qty-info">
                            <div class="frs-qty-name">{{ $it['name'] }}</div>
                            <div class="frs-qty-price">{{ $it['priceFormatted'] }} / {{ $it['unitLabel'] }}@if($it['duration']) &middot; {{ $it['duration'] }}@endif</div>
                        </div>
                        <div class="frs-stepper">
                            <button type="button" class="frs-step" data-item-dec="{{ $it['id'] }}" disabled aria-label="{{ $T['decrease'] }}">&minus;</button>
                            <span class="frs-qty" data-qty-for="{{ $it['id'] }}">0</span>
                            <button type="button" class="frs-step frs-step-on" data-item-inc="{{ $it['id'] }}" @if($it['soldOut']) disabled @endif aria-label="{{ $T['increase'] }}">&plus;</button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Curated core feature: reviews. This is a SERVICE, so reviews
                 review the service as a whole — the thread is anchored to the
                 same representative item ($serviceReviewItem in index.blade.
                 php) used for this service's own "from $X" price. Fetched
                 fresh whenever this service screen becomes active. --}}
            <div class="frs-detail-reviews" data-review-service="{{ $cat->id }}">
                <div class="frs-label">{{ $T['reviewsLabel'] }}</div>
                <div class="frs-rv-head" hidden>
                    <div class="frs-rv-avg">
                        <b class="frs-rv-avg-num">0.0</b>
                        <span class="frs-rv-stars" aria-hidden="true"></span>
                        <span class="frs-rv-count"></span>
                    </div>
                    <button type="button" class="frs-rv-write-btn">{{ $T['writeReview'] }}</button>
                </div>
                <div class="frs-rv-dist" hidden></div>
                <div class="frs-rv-list"></div>
                <p class="frs-rv-empty" hidden>{{ $T['noReviewsYet'] }}</p>
                <form class="frs-rv-form">
                    <div class="frs-rv-starpicker" role="group" aria-label="{{ $T['yourRating'] }}">
                        <button type="button" data-rv-star="1" aria-label="1">★</button>
                        <button type="button" data-rv-star="2" aria-label="2">★</button>
                        <button type="button" data-rv-star="3" aria-label="3">★</button>
                        <button type="button" data-rv-star="4" aria-label="4">★</button>
                        <button type="button" data-rv-star="5" aria-label="5">★</button>
                    </div>
                    <div class="frs-field frs-field--tight">
                        <label>{{ $T['yourName'] }} ({{ $T['optional'] }})</label>
                        <input type="text" class="frs-input frs-rv-name" maxlength="80" autocomplete="name">
                    </div>
                    <div class="frs-field frs-field--tight">
                        <label>{{ $T['yourReview'] }} ({{ $T['optional'] }})</label>
                        <input type="text" class="frs-input frs-rv-body" maxlength="2000">
                    </div>
                    <div class="frs-rv-msg"></div>
                    <button type="submit" class="frs-btn frs-btn-dark frs-btn-block frs-rv-submit">{{ $T['submitReview'] }}</button>
                </form>
            </div>
        </div>
    </div>
    <div class="frs-stickybar">
        <div class="frs-subrow"><span>{{ $T['subtotal'] }}</span><b class="frs-svc-subtotal" data-svc-subtotal="{{ $cat->id }}">{{ price_symbol_format(0) }}</b></div>
        <button type="button" class="frs-btn frs-btn-accent frs-btn-block" data-add-to-basket="{{ $cat->id }}" disabled>
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8h12l1 12a1 1 0 01-1 1H6a1 1 0 01-1-1z"/><path d="M9 8V6a3 3 0 016 0v2"/></svg>
            {{ $T['bookCta'] }}
        </button>
    </div>
</section>
