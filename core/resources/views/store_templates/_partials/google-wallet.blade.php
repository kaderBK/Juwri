{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  google-suite Lane C — "Add to Google Wallet" (loyalty).

  Renders NOTHING unless the store has Google Wallet configured AND enabled
  (post_option google_wallet_enabled == '1'), a logged-in customer is viewing, and
  an active loyalty program exists. Body-level + self-anchoring: the badge is moved
  next to the theme's own loyalty balance block (each theme names it differently, so
  a selector list is tried; if none is found the badge removes itself — F0 refines
  per-theme anchors). Included ONCE from layout.blade.php — the only layout edit in
  this pack.

  The save-link JWT is minted on demand from the customer-guarded endpoint
  (publicView.googleWallet.loyaltyJwt), never embedded here — so it exists only for
  an authenticated customer and the guard/ownership checks apply. Clicking navigates
  the top window to the returned pay.google.com save URL (a navigation, not a fetch —
  CSP connect-src stays 'self').

  The badge below is an inline SVG (locally committed, CSP-safe: img-src never
  involved). An owner may swap it for Google's official downloadable badge asset.
--}}
@php
    $__gwShow = false;
    if (isset($store) && auth('customer')->check()
        && \App\Services\GoogleWallet\GoogleWalletService::enabledForStore($store->id)) {
        $__gwShow = \App\Models\LoyaltyProgram::where('store_id', $store->id)
            ->where('active', true)->exists();
    }
@endphp
@if($__gwShow)
<div id="mbGWallet" data-google-wallet dir="auto" hidden
     style="margin:12px auto 0;max-width:460px;display:flex;justify-content:center;">
    <button type="button" id="mbGWalletBtn" aria-label="{{ ___('Add to Google Wallet') }}"
            style="border:0;background:transparent;padding:0;cursor:pointer;line-height:0;">
        {{-- Inline "Add to Google Wallet" badge (local, CSP-safe) --}}
        <svg width="220" height="47" viewBox="0 0 220 47" role="img"
             aria-label="{{ ___('Add to Google Wallet') }}" xmlns="http://www.w3.org/2000/svg">
            <rect x="1" y="1" width="218" height="45" rx="23" fill="#000" stroke="#747775" stroke-width="1"/>
            <g transform="translate(20,12)">
                <rect x="0" y="2" width="30" height="20" rx="4" fill="#fff"/>
                <rect x="0" y="2" width="30" height="7" rx="4" fill="#4285F4"/>
                <rect x="0" y="6" width="30" height="3" fill="#4285F4"/>
                <circle cx="22" cy="15" r="4" fill="#EA4335"/>
                <circle cx="26" cy="15" r="4" fill="#FBBC05" fill-opacity="0.9"/>
            </g>
            <text x="62" y="21" fill="#e3e3e3" font-family="Roboto,Arial,Helvetica,sans-serif"
                  font-size="10" font-weight="400">{{ ___('Add to') }}</text>
            <text x="62" y="35" fill="#fff" font-family="Roboto,Arial,Helvetica,sans-serif"
                  font-size="14" font-weight="600">{{ ___('Google Wallet') }}</text>
        </svg>
    </button>
</div>
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    "use strict";
    var host = document.getElementById('mbGWallet');
    if (!host) { return; }
    host.hidden = true;

    var JWT_URL = @json(route('publicView.googleWallet.loyaltyJwt', $store->id));
    var btn = document.getElementById('mbGWalletBtn');

    // Theme loyalty-block containers (IDs/classes vary per theme). The badge is
    // injected into the first match, just below the balance.
    var SEL = '#cvLoyaltyBlock, #loyaltyBlock, #loyaltyBanner, #loyaltySheet, #loyaltyChip,'
        + '.loyalty, .v-loyalty, .ff-loyalty, .vd-loyalty-card, .loyalty-card,'
        + '.loyalty-chip, .loyalty-row';

    var placed = false;
    function place() {
        if (placed || !document.body.contains(host)) { return; }
        var target = document.querySelector(SEL);
        if (!target) { return; }
        target.appendChild(host);
        host.hidden = false;
        placed = true;
    }
    function settle() {
        place();
        setTimeout(place, 900);
        setTimeout(place, 2500);
        setTimeout(function () {
            // No loyalty block appeared at all — remove the orphaned badge.
            if (!placed && host && host.parentNode) { host.parentNode.removeChild(host); }
        }, 3000);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', settle);
    } else { settle(); }

    var busy = false;
    btn.addEventListener('click', function () {
        if (busy) { return; }
        busy = true;
        btn.style.opacity = '0.6';
        fetch(JWT_URL, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (d && d.save_url) {
                    window.location.href = d.save_url; // top-level nav to pay.google.com
                } else {
                    host.hidden = true; // disabled / no pass — stand down quietly
                }
            })
            .catch(function () { /* fail-soft: leave the button as-is */ })
            .then(function () { busy = false; btn.style.opacity = '1'; });
    });
})();
</script>
@endif
