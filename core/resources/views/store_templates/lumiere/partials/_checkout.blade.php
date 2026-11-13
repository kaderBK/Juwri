{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Checkout — real fields only: GoodsOrderService::checkout() accepts
     address/phone_number/customer_name/scheduled_at + the chosen fulfillment
     `type`. The design source's card/PayPal/Apple Pay picker has no backend
     counterpart at this layer (no payment-gateway selection exists on the
     goods checkout endpoint), so that visual slot is repurposed for the ONE
     choice that IS real here — fulfillment mode — styled with the same
     optcard component. See the build report for this deviation. --}}
<section class="lm-screen has-actionbar" data-screen="checkout" hidden>
    <div class="lm-hdr">
        <button type="button" class="lm-iconbtn" data-back aria-label="{{ ___('Back') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
        </button>
        <span class="lm-title">{{ ___('Checkout') }}</span>
        <span style="width:42px"></span>
    </div>

    {{-- Desktop-only grouping (see _styles.blade.php ≥1024 rules): the
         contact/fulfillment/address/slot fields become the left column and
         the summary+CTA become a sticky right column. No id/class removed —
         every field is still reachable via the same getElementById calls
         lm-boot.js already uses; these two divs only change grid placement
         at desktop. --}}
    <div class="lm-checkout-body">
        <div id="lmCheckoutMsg"></div>

        <div class="lm-sect">
            <div class="lm-label">{{ ___('Contact') }}</div>
            <div class="lm-field">
                <label for="lmName">{{ ___('Name') }}</label>
                <input type="text" id="lmName" maxlength="255" value="{{ $customer->name ?? '' }}" autocomplete="name">
            </div>
            <div class="lm-field">
                <label for="lmPhone">{{ ___('Phone') }}</label>
                <input type="tel" id="lmPhone" maxlength="25" value="{{ $customer->phone ?? '' }}" autocomplete="tel">
            </div>
        </div>

        @if($modes->count() > 1)
        <div class="lm-sect">
            <div class="lm-label">{{ ___('Fulfillment') }}</div>
            @foreach($modes as $i => $mode)
                <button type="button" class="lm-optcard" data-mode="{{ $mode['value'] }}"
                        data-needs-address="{{ $mode['needsAddress'] ? 1 : 0 }}" data-needs-slot="{{ $mode['needsSlot'] ? 1 : 0 }}"
                        aria-pressed="{{ $i === 0 ? 'true' : 'false' }}">
                    <span class="lm-oico" aria-hidden="true">
                        @if($mode['needsAddress'])
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg>
                        @elseif($mode['needsSlot'])
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
                        @else
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6 7-11a7 7 0 00-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                        @endif
                    </span>
                    <span class="lm-obody">
                        <span class="lm-ot">{{ $mode['label'] }}</span>
                    </span>
                    <span class="lm-radio" aria-hidden="true"></span>
                </button>
            @endforeach
        </div>
        @else
            @php $onlyMode = $modes->first(); @endphp
            <input type="hidden" id="lmModeSingle" value="{{ $onlyMode['value'] ?? 'in_store' }}"
                   data-needs-address="{{ ($onlyMode['needsAddress'] ?? false) ? 1 : 0 }}"
                   data-needs-slot="{{ ($onlyMode['needsSlot'] ?? false) ? 1 : 0 }}">
        @endif

        <div class="lm-sect" id="lmAddressField" hidden>
            <div class="lm-label">{{ ___('Deliver to') }}</div>
            <div class="lm-field">
                <label for="lmAddress" class="lm-sr-only">{{ ___('Address') }}</label>
                <div class="lm-addr">
                    <input type="text" id="lmAddress" maxlength="255" autocomplete="off" autocorrect="off" spellcheck="false" placeholder="{{ ___('Start typing your address…') }}">
                    <button type="button" class="lm-addr-loc" id="lmAddrLoc" aria-label="{{ ___('Use my location') }}" title="{{ ___('Use my location') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
                    </button>
                    <div class="lm-addr-suggest" id="lmAddrSuggest" hidden></div>
                </div>
                {{-- Drop-a-pin picker (shared window.mbMapPicker engine, lumiere-skinned
                     .lm-msheet bottom sheet). Fills the same #lmAddress the order submits. --}}
                <button type="button" class="lm-maplink" id="lmMapBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.4"/></svg>
                    {{ ___('Choose on map') }}
                </button>
            </div>
        </div>

        <div class="lm-sect" id="lmSlotField" hidden>
            <div class="lm-label">{{ ___('Pick a time') }}</div>
            <div class="lm-slots" id="lmSlots"></div>
        </div>
    </div>

    <div class="lm-checkout-side">
        {{-- K3 · loyalty redeem row. Teaser is public/no-auth (store_options
             loyalty_teaser); the live balance + "ready to redeem" toggle is
             auth:customer (see partials/_script.blade.php loadLoyaltyCheckout())
             and only appears once the balance response says ready_to_redeem —
             a guest (or a signed-in diner without enough points) just keeps
             seeing the teaser, with a sign-in hint for guests. --}}
        @if(! empty($loyTeaser))
            <div class="lm-sect" id="lmLoyaltySect">
                <div class="lm-loyalty-teaser" id="lmLoyaltyTeaser">
                    <div class="lm-lt-program">{{ $loyTeaser['program'] ?? ___('Rewards') }}</div>
                    @if(!empty($loyTeaser['earn']))
                        <p>{{ $loyTeaser['earn'] }}</p>
                    @endif
                    @if(!empty($loyTeaser['reward']))
                        <p>{{ $loyTeaser['reward'] }}</p>
                    @endif
                    @if(! $customer)
                        <p class="lm-loyalty-hint">{{ ___('Sign in to earn & redeem points') }}</p>
                    @endif
                </div>
                <div class="lm-loyalty-redeem" id="lmLoyaltyRedeemBox" hidden>
                    <div class="lm-lr-info">
                        <div class="lm-lr-bal"><span id="lmLoyBalanceVal"></span><span class="lm-lr-tier" id="lmLoyTierVal"></span></div>
                        <div class="lm-lr-offer" id="lmLoyOfferVal"></div>
                    </div>
                    <button type="button" class="lm-switch" id="lmLoyToggle" role="switch" aria-checked="false" aria-label="{{ ___('Apply points') }}"><i></i></button>
                </div>
                <input type="hidden" name="loyalty_redeem" id="lmLoyaltyRedeemField" value="0">
            </div>
        @endif

        <div class="lm-couponbox">
            <div class="lm-couponrow">
                <input type="text" class="lm-input lm-coupon-input" id="lmCouponInput" autocomplete="off" placeholder="{{ ___('Promo code') }}" aria-label="{{ ___('Promo code') }}">
                <button type="button" class="lm-btn lm-btn-sm lm-coupon-apply" id="lmCouponApply">{{ ___('Apply') }}</button>
            </div>
            <p class="lm-coupon-msg" id="lmCouponMsg" hidden></p>
            <input type="hidden" name="coupon_code" id="lmCouponField" value="">
        </div>

        <div class="lm-couponbox">
            <div class="lm-couponrow">
                <input type="text" class="lm-input lm-coupon-input" id="lmGiftCardInput" name="gift_card_code" autocomplete="off" placeholder="{{ ___('Gift card code') }}" aria-label="{{ ___('Gift card code') }}">
            </div>
            <p class="lm-coupon-msg" style="color:var(--lm-muted)">{{ ___('Applied at checkout — reduces your total.') }}</p>
        </div>

        <div class="lm-summary">
            <div class="lm-srow"><span>{{ ___('Subtotal') }}</span><span id="lmCheckoutSubtotal"></span></div>
            <div class="lm-srow" id="lmCheckoutTaxRow" hidden><span>{{ ___('Tax') }}</span><span id="lmCheckoutTax"></span></div>
            <div class="lm-srow" id="lmCheckoutDiscountRow" hidden><span>{{ ___('Discount') }}</span><span id="lmCheckoutDiscount"></span></div>
            <div class="lm-srow"><span>{{ ___('Delivery') }}</span><span class="lm-freeval">{{ ___('Free') }}</span></div>
            <div class="lm-srow lm-total"><span>{{ ___('Total') }}</span><span id="lmCheckoutTotal"></span></div>
        </div>

        <div class="lm-actionbar"><div class="lm-actionbar__inner">
            <button type="button" class="lm-btn lm-btn-dark lm-btn-block" id="lmPlaceOrderBtn">
                <span id="lmPlaceOrderLabel">{{ ___('Place order') }}</span>
            </button>
        </div></div>
    </div>
</section>
