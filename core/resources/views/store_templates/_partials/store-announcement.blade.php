{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    D3 — shared storefront announcement banner.

    The fleet bulk op (Reseller\FleetController) — and the per-store option editor —
    write `store_announcement` + `store_announcement_active`, but until now NO theme
    read them, so the setting was inert. This renders them once in the shared
    storefront layout, so every template gets the banner at once (no per-theme copy
    to drift, per [[theme-parity-fanout]]).

    SERVER-GATED. The @if below is a data check on the store's OWN options — valid at
    parse time, unlike the DOM stand-downs the other layout partials use (those sit
    above @yield and would see an empty page, see
    [[shared-storefront-partial-placement]]). Nothing at all reaches the page when
    the announcement is inactive or the text is empty, so a store with no
    announcement renders byte-identically to before.

    Placement: this is a FIXED overlay notification — the same family as
    checkout-feedback / order-result / the WhatsApp fallback bar, NOT an inline
    section injected into the theme's menu flow. The memo's "never own a background"
    rule targets inline sections that must inherit the theme's ink; a fixed overlay
    instead carries its OWN self-contained dark ground + light ink, so its contrast
    is guaranteed on a light OR dark theme and it never becomes the offsetParent of
    any theme content. It frame-aligns through the shared
    window.mbAnchor.frameBand() so a centered desktop store gets a banner over its
    content column rather than an edge-to-edge band.
--}}
@php
    $__annActive = (string) store_options($store->id, 'store_announcement_active');
    $__annText = trim((string) store_options($store->id, 'store_announcement'));
@endphp
@if($__annActive === '1' && $__annText !== '')
@php
    // A short signature of the current message: dismissal is remembered per message,
    // so editing the announcement re-shows it even on a device that dismissed the
    // previous one.
    $__annSig = substr(hash('crc32b', $__annText), 0, 8);
    $__annKey = 'mb_ann_'.$store->id;
@endphp
<style nonce="{{ csp_nonce() }}">
    .mb-ann{
        position:fixed;left:0;right:0;top:0;z-index:2147483100;box-sizing:border-box;
        display:flex;align-items:flex-start;gap:.6rem;
        padding:calc(11px + env(safe-area-inset-top,0px)) 44px 11px 16px;
        background:#1f2937;color:#fff;font-family:var(--menu-font, inherit);
        font-size:.93rem;line-height:1.45;box-shadow:0 6px 20px rgba(0,0,0,.18);
    }
    .mb-ann__msg{margin:0;font-weight:600;flex:1 1 auto;overflow-wrap:anywhere;}
    .mb-ann__x{
        position:absolute;inset-inline-end:10px;top:calc(9px + env(safe-area-inset-top,0px));
        background:transparent;border:0;color:#fff;opacity:.9;font-size:1.3rem;line-height:1;cursor:pointer;
    }
</style>

<div class="mb-ann" id="mbAnnounce" role="status" aria-live="polite"
     data-key="{{ $__annKey }}" data-sig="{{ $__annSig }}" hidden>
    <p class="mb-ann__msg">{{ $__annText }}</p>
    <button type="button" class="mb-ann__x" id="mbAnnClose" aria-label="{{ ___('Close') }}">&times;</button>
</div>

<script nonce="{{ csp_nonce() }}">
(function () {
    var el = document.getElementById('mbAnnounce');
    if (!el) return;
    var key = el.getAttribute('data-key') || 'mb_ann';
    var sig = el.getAttribute('data-sig') || '';

    // Already dismissed THIS message on this device → leave it hidden, no flash.
    try { if (localStorage.getItem(key) === sig) return; } catch (e) {}

    // Pin to the theme's centered content column when the store renders as a
    // centered desktop frame; a full-width / mobile theme keeps the full-bleed bar.
    // Guarded: mbAnchor may not exist yet (this partial parses above <main>), in
    // which case the banner simply stays full-bleed.
    function alignToFrame() {
        var band = window.mbAnchor && window.mbAnchor.frameBand && window.mbAnchor.frameBand();
        if (band && band.width < window.innerWidth - 40) {
            el.style.left = band.left + 'px';
            el.style.right = 'auto';
            el.style.width = band.width + 'px';
            el.style.borderRadius = '0 0 14px 14px';
        } else {
            el.style.left = ''; el.style.right = ''; el.style.width = ''; el.style.borderRadius = '';
        }
    }

    var xBtn = document.getElementById('mbAnnClose');
    if (xBtn) xBtn.addEventListener('click', function () {
        el.hidden = true;
        try { localStorage.setItem(key, sig); } catch (e) {}
    });

    window.addEventListener('resize', function () { if (!el.hidden) alignToFrame(); });

    el.hidden = false;
    alignToFrame();
    // One late pass: app-shell themes build their grid in JS, so the frame band
    // (and mbAnchor itself) may only exist a beat after first paint.
    window.setTimeout(function () { if (!el.hidden) alignToFrame(); }, 700);
})();
</script>
@endif
