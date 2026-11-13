{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Order history screen. Fetches the signed-in shopper's past orders at THIS
     store from publicView.myOrders.index (auth:customer, store-scoped) and lists
     them with status, total and a track link; "Reorder" re-adds the order's
     items to the cart client-side. A guest sees a sign-in prompt (the endpoint
     401s for guests). Rendered by renderOrders() in _script.blade.php. --}}
<section class="lm-screen has-tabbar" data-screen="orders" hidden>
    <div class="lm-hdr">
        <button type="button" class="lm-iconbtn" data-nav="profile" aria-label="{{ ___('Back') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
        <span class="lm-title">{{ ___('My Orders') }}</span>
        <span class="lm-iconbtn" aria-hidden="true" style="visibility:hidden"></span>
    </div>

    <div id="lmOrdersList"></div>

    <div class="lm-empty" id="lmOrdersEmpty" hidden>
        <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--lm-ink-3)"><path d="M6 8h12l1 12a1 1 0 01-1 1H6a1 1 0 01-1-1z"/><path d="M9 8V6a3 3 0 016 0v2"/></svg>
        <p style="margin-top:10px;font-weight:600">{{ ___('No orders yet') }}</p>
        <p style="font-size:13px">{{ ___('Your past orders will appear here.') }}</p>
    </div>

    <div class="lm-empty" id="lmOrdersGuest" hidden>
        <p style="font-weight:600">{{ ___('Sign in to see your orders') }}</p>
        <button type="button" class="lm-btn lm-btn-dark lm-btn-block" style="max-width:260px;margin:12px auto 0" id="lmOrdersSignin">{{ ___('Sign in') }}</button>
    </div>

    <div class="lm-empty" id="lmOrdersLoading">
        <p style="font-size:13px">{{ ___('Loading…') }}</p>
    </div>
</section>
