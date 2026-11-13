{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  google-suite Lane C follow-up — "Add to Google Wallet" (gift cards).

  Renders NOTHING unless ALL hold: we are on the customer account page
  (route customer.account), a customer is logged in, Google Wallet is configured
  AND enabled for the store (post_option google_wallet_enabled == '1'), and the
  signed-in customer has PURCHASED at least one gift card at this store. So the
  whole section is dark until an owner turns Wallet on — matching the loyalty badge.

  Body-level + self-anchoring: included ONCE from layout.blade.php. A JS shim moves
  the pre-rendered block into the theme's account content column (a selector list is
  tried across the 17 account themes; if none matches the block removes itself).

  Per card, the save-link JWT is minted on demand from the customer-guarded endpoint
  (publicView.googleWallet.giftCardJwt) — never embedded here — so it exists only for
  the authenticated PURCHASER and the guard/ownership checks apply. Clicking navigates
  the top window to the returned pay.google.com save URL (a navigation, not a fetch —
  CSP connect-src stays 'self').
--}}
@php
    $__gcShow = false;
    $__gcCards = [];
    if (isset($store) && request()->routeIs('customer.account')
        && auth('customer')->check()
        && \App\Services\GoogleWallet\GoogleWalletService::enabledForStore($store->id)) {

        $__gcCustomerId = (int) auth('customer')->id();
        $__gcSign = store_options($store->id)->currency_sign ?? '$';
        $__gcPos  = (string) currency_pos_effective($store->id);

        $__gcRows = \App\Models\GiftCard::where('store_id', $store->id)
            ->where('purchaser_customer_id', $__gcCustomerId)
            ->orderByDesc('id')
            ->get();

        foreach ($__gcRows as $__c) {
            $__bal = (float) $__c->balance();
            $__balStr = number_format($__bal, 2, '.', '');
            $__gcCards[] = [
                'id'          => (int) $__c->id,
                'code'        => (string) $__c->code,
                'balance'     => $__gcPos === '1' ? ($__gcSign.$__balStr) : ($__balStr.$__gcSign),
                'status'      => (string) $__c->status,
                'expired'     => $__c->isExpired(),
                'redeemable'  => $__c->isRedeemable(),
                'expires_at'  => $__c->expires_at ? $__c->expires_at->format('d M Y') : null,
                'jwt_url'     => route('publicView.googleWallet.giftCardJwt', [$store->id, $__c->id]),
            ];
        }
        $__gcShow = count($__gcCards) > 0;
    }
@endphp
@if($__gcShow)
<div id="mbGWGiftCards" data-google-wallet-giftcards dir="auto" hidden
     style="margin:16px auto 0;max-width:640px;border:1px solid rgba(128,128,128,.28);
            border-radius:16px;padding:16px;background:rgba(128,128,128,.06);color:inherit;">
    <div style="font-size:15px;font-weight:700;margin-bottom:12px;letter-spacing:.01em;">
        {{ ___('Your gift cards') }}
    </div>
    @foreach($__gcCards as $__gc)
    <div class="mbGWGCRow"
         style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;
                gap:10px;padding:12px 0;border-top:1px solid rgba(128,128,128,.18);">
        <div style="min-width:0;">
            <div style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
                        font-size:14px;font-weight:600;word-break:break-all;">{{ $__gc['code'] }}</div>
            <div style="font-size:12px;opacity:.75;margin-top:2px;">
                <span>{{ ___('Balance') }}: {{ $__gc['balance'] }}</span>
                @if($__gc['expired'])
                    <span style="margin-inline-start:8px;">· {{ ___('Expired') }}</span>
                @elseif($__gc['status'] !== 'active')
                    <span style="margin-inline-start:8px;">· {{ ucfirst($__gc['status']) }}</span>
                @elseif($__gc['expires_at'])
                    <span style="margin-inline-start:8px;">· {{ ___('Expires') }} {{ $__gc['expires_at'] }}</span>
                @endif
            </div>
        </div>
        @if($__gc['redeemable'])
        <button type="button" class="mbGWGCBtn" data-jwt-url="{{ $__gc['jwt_url'] }}"
                aria-label="{{ ___('Add to Google Wallet') }}"
                style="border:0;background:transparent;padding:0;cursor:pointer;line-height:0;flex:none;">
            {{-- Inline "Add to Google Wallet" badge (local, CSP-safe) --}}
            <svg width="200" height="43" viewBox="0 0 220 47" role="img"
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
        @endif
    </div>
    @endforeach
</div>
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    "use strict";
    var host = document.getElementById('mbGWGiftCards');
    if (!host) { return; }
    host.hidden = true;

    // Account content columns across the 17 themes (crave-family share .view-scroll).
    var SEL = 'section[data-view="account"] .view-scroll, .view-scroll,'
        + '.au-account-container, .ff-account-page, .account-page,'
        + '.v-account-page, .vd-account';

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
            if (!placed && host && host.parentNode) { host.parentNode.removeChild(host); }
        }, 3000);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', settle);
    } else { settle(); }

    var busy = false;
    host.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('.mbGWGCBtn') : null;
        if (!btn || busy) { return; }
        var url = btn.getAttribute('data-jwt-url');
        if (!url) { return; }
        busy = true;
        btn.style.opacity = '0.6';
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (d && d.save_url) {
                    window.location.href = d.save_url; // top-level nav to pay.google.com
                } else {
                    btn.style.display = 'none'; // disabled / no pass — stand down quietly
                }
            })
            .catch(function () { /* fail-soft: leave the button as-is */ })
            .then(function () { busy = false; btn.style.opacity = '1'; });
    });
})();
</script>
@endif
