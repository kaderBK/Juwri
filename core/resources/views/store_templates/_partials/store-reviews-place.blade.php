{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Relocates the inline store-review block to the BOTTOM of the menu — just above
  the info footer — for themes that render reviews in the page flow (tagged with
  data-mb-reviews-end on their review wrapper). Uses mbAnchor.placeBottom, the
  same relocation the info footer uses.

  The 7 "About sheet/drawer" themes (crave/matcha/glow/aurora/vivid/feast/velvet)
  render reviews inside their About popup, styled by sheet-scoped CSS; they are
  NOT tagged, so this partial no-ops for them.

  Order-independent: this partial may be parsed before theme-anchor defines
  window.mbAnchor, so it waits for DOMContentLoaded and polls for mbAnchor rather
  than assuming it exists at parse time. It re-runs after the delayed ticks too,
  because some themes rebuild their content body asynchronously and would drop
  the block back at its original spot.
--}}
@if(isset($store) && ! empty($store->id)
    && \Juwri\Modules\Storefront\Services\StoreReviewsDisplay::enabledFor($store->id)
    && \App\Models\StoreReview::approved()->forStore($store->id)->exists())
{{-- Emit the relocation script ONLY when the store actually has approved reviews
     to place — otherwise a page with no reviews (and no info footer) would carry
     the literal "mbInfoFoot" string this script references, which
     StorefrontInfoLinksTest asserts absent, and it would needlessly inject into
     legacy templates it knows nothing about (same rule as info-links/offers). --}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    function run() {
        if (!window.mbAnchor || typeof mbAnchor.placeBottom !== 'function') return false;
        var blocks = [].slice.call(document.querySelectorAll('[data-mb-reviews-end]'));
        if (!blocks.length) return true; // nothing to do (sheet/drawer themes)
        var foot = document.getElementById('mbInfoFoot');
        blocks.forEach(function (b) {
            // placeBottom drops the block at the end of the menu; passing the info
            // footer as `before` keeps reviews just ABOVE it, and is a no-op once
            // the block is already there.
            mbAnchor.placeBottom(b, foot || undefined);
            if (mbAnchor.ink) mbAnchor.ink(b);
        });
        return true;
    }
    function boot() {
        if (run()) {
            // Re-assert after late layout / async body rebuilds.
            window.setTimeout(run, 900);
            window.setTimeout(run, 2500);
            return;
        }
        // mbAnchor not defined yet (include-order) — poll briefly for it.
        var tries = 0;
        var iv = window.setInterval(function () {
            if (run() || ++tries > 40) window.clearInterval(iv);
        }, 100);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
</script>
@endif
