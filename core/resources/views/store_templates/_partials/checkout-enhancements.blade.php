{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    F12 — ship the newer checkout-enhancement inputs to every theme.

    The shared order-field-forwarder already carries scheduled_at / gift_card_code /
    curbside / vehicle_* into the send-order POST on all themes, but only ~half of
    the modern themes render the INPUTS. So an owner who enables order scheduling,
    gift cards or curbside gets no storefront UI on the themes that lack them.

    This shared, self-contained panel renders ONLY the inputs whose owner flag is
    on, then stands down per-field: if the active theme already ships a native input
    of that name, the JS removes our copy so there is never a double field. If
    nothing remains, the whole panel removes itself. Values ride checkout through the
    forwarder — no bundle change.

    PLACEMENT (this run): it used to float as a fixed "Order options" pill + bottom
    sheet, pinned to the viewport and sitting OUTSIDE the app frame in the desktop
    letterbox — a body-level overlay that was never part of the theme. It now renders
    as an inline card and is moved INTO the theme's own checkout (beside the tip /
    totals / order button) by mbAnchor.placeCheckout — the loyalty-redeem idiom.
    Colours are inherited, never painted, so it wears whatever the checkout block
    already uses (dark on crave, cream on picnic); a neutral-grey border reads on
    both. Revealed only once it is actually placed, so it never strands on a page
    with no checkout.
--}}
@php
    $__ceSched   = !empty($postOptions->allow_order_scheduling ?? null);
    $__ceGift    = !empty($postOptions->gift_cards_enabled ?? null);
    $__ceCurb    = !empty($postOptions->curbside_enabled ?? null);
    $__ceLead    = (int) ($postOptions->scheduling_lead_minutes ?? 30);
    $__ceMaxDays = (int) ($postOptions->scheduling_max_days ?? 7);
    /* These inputs only mean anything on a page that can place an order. The
       track-order page extends the same layout, so this gate keeps the card off
       every non-checkout page (account, profile, track, info). */
    $__ceOrderable = storefront_page_has_checkout();
@endphp
@if($__ceOrderable && ($__ceSched || $__ceGift || $__ceCurb))
<style nonce="{{ csp_nonce() }}">
    /* Inline card — moved into the theme's checkout. Inherits the block's colour;
       a neutral border reads on a light or a dark surface. */
    .mbce-card{margin:12px auto;max-width:460px;padding:14px 16px;color:inherit;
        border:1px solid rgba(128,128,128,.35);border-radius:14px;
        font-family:var(--menu-font,inherit);}
    .mbce-card h3{margin:0 0 2px;font-size:.98rem;font-weight:800;color:inherit;}
    .mbce-card > p.mbce-sub{margin:0 0 4px;font-size:.8rem;opacity:.7;}
    .mbce-field{margin-block-start:12px;}
    .mbce-field label{display:block;font-size:.85rem;font-weight:600;margin-block-end:6px;}
    .mbce-input{width:100%;padding:11px 13px;font:inherit;font-size:1rem;color:inherit;background:transparent;
        border-radius:11px;border:1px solid color-mix(in srgb, currentColor 24%, transparent);}
    .mbce-row{display:flex;align-items:center;gap:8px;font-weight:600;}
    .mbce-veh{margin-block-start:8px;}
    .mbce-hidden{display:none !important;}
</style>

<section class="mbce-card" id="mbCeCard" dir="auto" hidden aria-label="{{ ___('Order options') }}">
    <h3>{{ ___('Order options') }}</h3>
    @if($__ceSched)
    <div class="mbce-field" data-ce="scheduled_at">
        <label for="mbCeSched">{{ ___('Schedule your order') }}</label>
        <input type="datetime-local" class="mbce-input" id="mbCeSched" name="scheduled_at"
               min="{{ now()->addMinutes($__ceLead)->format('Y-m-d\TH:i') }}"
               max="{{ now()->addDays($__ceMaxDays)->format('Y-m-d\TH:i') }}">
    </div>
    @endif
    @if($__ceGift)
    <div class="mbce-field" data-ce="gift_card_code">
        <label for="mbCeGift">{{ ___('Gift card code') }}</label>
        <input type="text" class="mbce-input" id="mbCeGift" name="gift_card_code" autocomplete="off"
               placeholder="{{ ___('Gift card code') }}">
    </div>
    @endif
    @if($__ceCurb)
    <div class="mbce-field" data-ce="curbside">
        <label class="mbce-row" for="mbCeCurb">
            <input type="checkbox" id="mbCeCurb" name="curbside" value="1"> {{ ___('Curbside pickup') }}
        </label>
        <div class="mbce-veh mbce-hidden" id="mbCeVeh">
            <input type="text" class="mbce-input mbce-veh" name="vehicle_make" placeholder="{{ ___('Car make/model') }}">
            <input type="text" class="mbce-input mbce-veh" name="vehicle_color" placeholder="{{ ___('Colour') }}">
            <input type="text" class="mbce-input mbce-veh" name="vehicle_plate" placeholder="{{ ___('Plate') }}">
        </div>
    </div>
    @endif
</section>

<script nonce="{{ csp_nonce() }}">
(function () {
    "use strict";
    var card = document.getElementById('mbCeCard');
    if (!card) return;

    /* This partial is included ABOVE the content yield, so at parse time the
       theme's own inputs are not in the DOM yet and the stand-down below would
       find nothing. Keep the card hidden until the check has run against the full
       page AND it has actually been moved into the checkout; re-run on the settle
       cadence for themes that build their checkout from JS. */
    card.hidden = true;

    /* A theme's control counts as native by NAME or by its known id/class — eight
       themes render an un-named #whenTime/.when-time and append scheduled_at from
       JS, and the name-only check left this duplicate on all of them. */
    /* Selector built by concatenation on purpose: tests assert the page does not
       contain a disabled field's name-attribute markup verbatim, and an inline
       selector literal here would match that assertion. */
    var extraNatives = {
        scheduled_at: ', #whenTime, .when-time, #cvScheduledAt',
        gift_card_code: ', input[id*="gift" i]',
        curbside: ', input[id*="curb" i]',
    };
    var names = ['scheduled_at', 'gift_card_code', 'curbside'];
    function standDown() {
        if (!document.body.contains(card)) return;
        names.forEach(function (n) {
            var mine = card.querySelector('[data-ce="' + n + '"]');
            if (!mine) return;
            var others = document.querySelectorAll('[name="' + n + '"]' + (extraNatives[n] || ''));
            var hasNative = false;
            others.forEach(function (el) { if (!card.contains(el)) hasNative = true; });
            if (hasNative && mine.parentNode) mine.parentNode.removeChild(mine);
        });
        // Nothing left to offer → remove the whole card.
        if (!card.querySelector('[data-ce]') && card.parentNode) {
            card.parentNode.removeChild(card);
        }
    }

    function decide() {
        standDown();
        if (!document.body.contains(card)) return;
        /* Move the card into the theme's checkout, beside the amount controls
           (loyalty-redeem idiom). Reveal ONLY once it is placed there, so a page
           with no checkout never flashes it in body flow outside the app frame. */
        if (window.mbAnchor && window.mbAnchor.placeCheckout && window.mbAnchor.placeCheckout(card)) {
            card.hidden = false;
        }
    }
    function settle() {
        decide();
        setTimeout(decide, 900);
        setTimeout(decide, 2500);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', settle);
    else settle();

    var curb = document.getElementById('mbCeCurb');
    var veh = document.getElementById('mbCeVeh');
    if (curb && veh) curb.addEventListener('change', function () { veh.classList.toggle('mbce-hidden', !curb.checked); });
})();
</script>
@endif
