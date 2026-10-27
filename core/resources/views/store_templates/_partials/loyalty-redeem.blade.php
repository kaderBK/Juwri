{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  K3: loyalty points redemption at CHECKOUT. A returning, logged-in diner can spend
  their accrued points for the program's reward discount on THIS order. The strip
  shows their live balance + tier and an "Apply <reward> off" toggle; toggling it
  sets a hidden `loyalty_redeem` input the order-field-forwarder forwards into
  /send-order, where StoreController re-derives the discount server-side, debits the
  ledger and folds it into discount_amount (Order::getTotalAttribute stays the total
  authority). No client amount is ever trusted.

  Self-contained (inline styles + nonce'd ES5), self-gating: renders NOTHING unless
  feature:loyalty is entitled for this store AND an active program with a real reward
  exists. The balance endpoint is auth:customer, so a guest's fetch 401s and the strip
  removes itself. Stands down if a native checkout redeem control already exists.
--}}
@php
    $__k3Program = null;
    $__k3Allowed = false;
    // Redeeming points is a checkout action; the strip has nowhere to attach on
    // the account/profile/track/info pages, which share this layout. (loyalty
    // BALANCE still shows on those pages — that is the theme's own block, not
    // this checkout redeem strip.)
    if (isset($store) && storefront_page_has_checkout()) {
        $__k3Allowed = \App\Services\FeatureAccess::storeAllows((int) $store->id, 'loyalty');
        if ($__k3Allowed) {
            $__k3Program = \App\Models\LoyaltyProgram::where('store_id', $store->id)
                ->where('active', true)->first();
        }
    }
    $__k3Show = $__k3Allowed && $__k3Program && (float) $__k3Program->reward_discount > 0;
@endphp
@if($__k3Show)
{{-- Colours are inherited, never painted: the strip is moved INTO the theme's
     checkout block, whose background it cannot know (dark on crave, cream on
     picnic). A neutral-grey border reads on both; text takes the block's own. --}}
<div id="mbLoyaltyRedeem" data-loyalty-redeem-strip dir="auto" hidden
     style="margin:12px auto;max-width:460px;padding:12px 14px;border:1px solid rgba(128,128,128,.35);border-radius:12px;color:inherit;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <div>
            <p style="margin:0;font-size:13.5px;font-weight:700;">
                <span id="mbLoyBalance">–</span>
                <span id="mbLoyTier" style="margin-inline-start:6px;font-weight:600;opacity:.7;"></span>
            </p>
            <p id="mbLoyOffer" style="margin:2px 0 0;font-size:12.5px;opacity:.75;"></p>
            <p id="mbLoyDebt" role="status" hidden style="margin:6px 0 0;font-size:12.5px;font-weight:600;"></p>
        </div>
        <label id="mbLoyApply" style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:700;">
            <input type="checkbox" id="mbLoyToggle" style="width:18px;height:18px;">
            <span>{{ ___('Apply points') }}</span>
        </label>
    </div>
    <input type="hidden" name="loyalty_redeem" id="mbLoyField" value="">
</div>
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    "use strict";
    var host = document.getElementById('mbLoyaltyRedeem');
    if (!host) { return; }
    /* Stand down if the theme already ships a checkout redeem control. Deferred:
       this partial is parsed above the content yield, so a parse-time check sees
       an empty page and never stands down (same trap that double-prompted the
       OTP gate and doubled aurora/vivid's tip control). */
    host.hidden = true;
    function decide() {
        if (!document.body.contains(host)) { return; }
        if (document.querySelector('[name="loyalty_redeem"]:not(#mbLoyField)')) { host.remove(); return; }
        /* Move into the theme's checkout, beside the other amount controls. The
           strip is server-rendered at the top of <main>, which no theme shows at
           checkout: the app-shell themes cover it with fixed panes and the rest
           put it a whole page above the order it discounts. Retried by the same
           settle cadence because several themes build their checkout from JS. */
        if (window.mbAnchor && window.mbAnchor.placeCheckout) { window.mbAnchor.placeCheckout(host); }
        /* Deliberately does NOT unhide — only the balance response knows whether
           this diner has anything to redeem, and it unhides itself below. While
           the strip was stranded off-screen an early unhide cost nothing; in the
           checkout it would flash an empty "–" card at every guest before the
           401 removed it. */
    }
    function settleStandDown() {
        decide();
        setTimeout(decide, 900);
        setTimeout(decide, 2500);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', settleStandDown); }
    else { settleStandDown(); }

    var BAL_URL = @json(route('publicView.loyalty.balance', $store->id));
    var T = {
        points: @json(___('points')),
        stamps: @json(___('stamps')),
        tier: @json(___('Tier')),
        apply: @json(___('Apply :amount off your order')),
    };

    var toggle = document.getElementById('mbLoyToggle');
    var field = document.getElementById('mbLoyField');
    var balEl = document.getElementById('mbLoyBalance');
    var tierEl = document.getElementById('mbLoyTier');
    var offerEl = document.getElementById('mbLoyOffer');

    toggle.addEventListener('change', function () {
        field.value = toggle.checked ? '1' : '';
    });

    // Same-origin credentials so the auth:customer session cookie rides along.
    fetch(BAL_URL, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
            // P6-253 (J46): a negative balance shows the plain explanation instead of the redeem toggle.
            if (d && d.active && d.negative && d.banner) {
                var unitN = (d.type === 'stamps') ? T.stamps : T.points;
                balEl.textContent = String(d.balance) + ' ' + unitN;
                document.getElementById('mbLoyApply').style.display = 'none';
                var debtEl = document.getElementById('mbLoyDebt');
                debtEl.textContent = d.banner;
                debtEl.hidden = false;
                host.hidden = false;
                return;
            }
            // Only a returning diner with enough points to redeem sees the strip.
            if (!d || !d.active || !d.ready_to_redeem || !d.reward_discount_display) {
                host.remove();
                return;
            }
            var unit = (d.type === 'stamps') ? T.stamps : T.points;
            balEl.textContent = String(d.balance) + ' ' + unit;
            if (d.tier) { tierEl.textContent = T.tier + ': ' + d.tier; }
            offerEl.textContent = T.apply.replace(':amount', d.reward_discount_display);
            host.hidden = false;
        })
        .catch(function () { host.remove(); });
})();
</script>
@endif
