{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Product detail — rendered from the ITEMS JSON by lm-boot.js (same
     "sheet populated client-side from a shared item map" pattern shelf uses
     for its own product sheet), so there is exactly one markup path for
     every product regardless of which card opened it. A `?item={id}` query
     string still deep-links here (site-wide convention — see the
     `data-open-item-link` anchors on every card) via a boot-time check. --}}
<section class="lm-screen has-actionbar" data-screen="product" hidden>
    <div class="lm-hdr">
        <button type="button" class="lm-iconbtn" data-back aria-label="{{ ___('Back') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
        </button>
        <span class="lm-title">{{ ___('Product details') }}</span>
        <button type="button" class="lm-iconbtn lm-corner lm-heart" id="lmProductHeart" style="position:static" aria-pressed="false" aria-label="{{ ___('Add to favorites') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20s-7-4.5-9.5-9A5 5 0 0112 5a5 5 0 019.5 6c-2.5 4.5-9.5 9-9.5 9z"/></svg>
        </button>
    </div>

    <div class="lm-pd-img" id="lmProductImgWrap">
        <div class="lm-pd-img-ph" id="lmProductImgPh"><svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M12 2c4 5 8 9.5 8 14a8 8 0 01-16 0c0-4.5 4-9 8-14z"/></svg></div>
        <img id="lmProductImg" alt="" hidden data-csp-error="hide">
    </div>

    {{-- Gallery thumbnail strip — populated by openProduct() from the item's
         `gallery` array (primary first); shown only when ≥2 images. Tapping a
         thumb swaps the main image above. --}}
    <div class="lm-pd-thumbs" id="lmProductThumbs" role="group" aria-label="{{ ___('Product images') }}" hidden></div>

    {{-- Desktop-only grouping (see _styles.blade.php ≥1024 rules): wraps the
         brand/title/price/description/options/CTA into one flow column that
         sits beside the image. Adds no id/class removal — every existing
         hook below keeps working via getElementById/delegated click exactly
         as before; this div only changes grid placement at desktop. --}}
    <div class="lm-pd-body">
        <div class="lm-pd-brand" id="lmProductBrand" hidden></div>
        <h1 class="lm-pd-title" id="lmProductTitle"></h1>
        <div class="lm-pd-prices">
            <span class="lm-pd-price" id="lmProductPrice"></span>
            <span class="lm-rating" id="lmProductRating" hidden>
                <span id="lmProductRatingVal"></span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>
                <span id="lmProductRatingCount"></span>
            </span>
        </div>
        <p class="lm-pd-desc" id="lmProductDesc"></p>

        <div id="lmProductVariantsWrap" hidden>
            <div class="lm-label" id="lmProductVariantsLabel">{{ ___('Option') }}</div>
            <div class="lm-chipsrow" id="lmProductVariants"></div>
        </div>

        <div id="lmProductBenefitsWrap" hidden>
            <div class="lm-label">{{ ___('Benefits') }}</div>
            <div class="lm-chipsrow" id="lmProductBenefits"></div>
        </div>

        <div id="lmProductIngredientsWrap" hidden>
            <div class="lm-label">{{ ___('Key ingredients') }}</div>
            <div class="lm-chipsrow" id="lmProductIngredients"></div>
        </div>

        <span class="lm-stock" id="lmProductStock" hidden></span>

        {{-- F6 · reviews — average/count + 5→1 distribution + list + write
             form. Fetched per-item on openProduct() from
             GET <mbrBase>/<menuId>/reviews?filter=latest (see the
             $mbrBase = url('r/'.$store->id.'/item') contract) and posted back
             to the same URL. Ships hidden/empty so it never flashes stale
             data from a previously-open item before the fetch resolves. --}}
        <div class="lm-pd-reviews" id="lmReviewsSection">
            <div class="lm-label">{{ ___('Reviews') }}</div>

            <div id="lmReviewsSummary" hidden>
                <div class="lm-rv-summary">
                    <div class="lm-rv-avgblock">
                        <span class="lm-rv-avgnum" id="lmRvAvgNum">0.0</span>
                        <span class="lm-rv-avgstars" id="lmRvAvgStars" aria-hidden="true"></span>
                        <span class="lm-rv-avgcount" id="lmRvAvgCount"></span>
                    </div>
                    <div class="lm-rv-dist" id="lmRvDist"></div>
                </div>
            </div>

            <div class="lm-rv-empty" id="lmReviewsEmpty" hidden>
                <p>{{ ___('No reviews yet — be the first') }}</p>
            </div>

            <div class="lm-rv-list" id="lmReviewsList"></div>

            <button type="button" class="lm-btn lm-btn-ghost lm-btn-sm lm-rv-writebtn" id="lmReviewsWriteBtn">{{ ___('Write a review') }}</button>

            <form class="lm-rv-form" id="lmReviewForm" hidden>
                <div class="lm-label">{{ ___('Your rating') }}</div>
                <div class="lm-rv-starsinput" id="lmReviewStars" role="group" aria-label="{{ ___('Your rating') }}">
                    @for($lmS = 1; $lmS <= 5; $lmS++)
                        <button type="button" class="lm-rv-starbtn" data-star="{{ $lmS }}" aria-label="{{ $lmS }}">★</button>
                    @endfor
                </div>
                <div class="lm-field">
                    <label for="lmReviewName">{{ ___('Your name') }} <span class="lm-muted">({{ ___('Optional') }})</span></label>
                    <input type="text" id="lmReviewName" maxlength="80" autocomplete="name">
                </div>
                <div class="lm-field">
                    <label for="lmReviewText">{{ ___('Your review') }} <span class="lm-muted">({{ ___('Optional') }})</span></label>
                    <textarea id="lmReviewText" maxlength="2000" rows="3"></textarea>
                </div>
                <p class="lm-rv-msg" id="lmReviewMsg"></p>
                <div class="lm-rv-formrow">
                    <button type="button" class="lm-btn lm-btn-ghost" id="lmReviewCancelBtn">{{ ___('Cancel') }}</button>
                    <button type="submit" class="lm-btn lm-btn-dark" id="lmReviewSubmitBtn">{{ ___('Submit review') }}</button>
                </div>
            </form>
        </div>

        <div class="lm-actionbar"><div class="lm-actionbar__inner">
            <button type="button" class="lm-btn lm-btn-dark lm-btn-block" id="lmProductAddBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l1 12a1 1 0 01-1 1H6a1 1 0 01-1-1z"/><path d="M9 8V6a3 3 0 016 0v2"/></svg>
                <span id="lmProductAddLabel">{{ ___('Add to Basket') }}</span>
            </button>
        </div></div>
    </div>
</section>
