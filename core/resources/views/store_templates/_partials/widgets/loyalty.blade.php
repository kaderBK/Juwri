{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Theme Studio B0.7 — {% widget 'loyalty' %}. The store's loyalty program and,
    for a signed-in diner, their live balance / tier / progress with a Redeem
    button — read from and posted to the SAME customer endpoints the Blade themes
    use (publicView.loyalty.balance / publicView.loyalty.redeem, auth:customer,
    store-entitlement gated server-side). A guest gets a sign-in prompt wired to
    the commerce runtime's [data-mb-account] hook. WidgetTag gates it on
    features.loyalty (FeatureAccess 'loyalty' + an active program).
--}}
@php
    $mbwId = 'mbw-loyalty';
    $mbwProgram = storefront()->loyaltyProgram($store);
@endphp
@if($mbwProgram)
@if($widgetBase)@include('store_templates._partials.widgets._base')@endif
<button type="button" class="mbw-btn mbw-loyalty__trigger" data-mbw-open="loyalty" aria-haspopup="dialog" aria-controls="{{ $mbwId }}-dialog">
    <svg class="mbw-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12v10H4V12M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
    <span>{{ $widgetLabel ?? ___('Loyalty') }}</span>
</button>
@if($widgetFirst)
<dialog class="mbw-dialog mbw-loyalty" id="{{ $mbwId }}-dialog" aria-labelledby="{{ $mbwId }}-title"
        @if($customer) data-mbw-loy-balance-url="{{ route('publicView.loyalty.balance', $store->id) }}" data-mbw-loy-redeem-url="{{ route('publicView.loyalty.redeem', $store->id) }}" @endif>
    @include('store_templates._partials.widgets._head', ['mbwTitle' => ___('Loyalty')])
    <div class="mbw-body">
        <p class="mbw-lead">
            {{ $mbwProgram->reward_threshold }} {{ $mbwProgram->type === 'stamps' ? ___('stamps') : ___('points') }}
            · {{ ___('to earn:') }} {{ $mbwProgram->reward_description }}
        </p>
        @if($customer)
            <div class="mbw-loyalty__card" data-mbw-loy-card hidden>
                <p class="mbw-loyalty__balance"><b data-mbw-loy-balance>–</b> <span>{{ $mbwProgram->type === 'stamps' ? ___('stamps') : ___('points') }}</span></p>
                <p class="mbw-alert" role="status" data-mbw-loy-banner hidden></p>
                <p class="mbw-muted" data-mbw-loy-tier hidden></p>
                <div class="mbw-loyalty__bar" aria-hidden="true"><i data-mbw-loy-bar></i></div>
                <button class="mbw-btn mbw-submit" type="button" data-mbw-loy-redeem hidden>{{ ___('Redeem Reward') }}</button>
                <p class="mbw-done" role="status" data-mbw-loy-ok hidden>{{ ___('Reward redeemed! Enjoy your reward.') }}</p>
                <p class="mbw-alert" role="alert" data-mbw-loy-err hidden></p>
                <ul class="mbw-loyalty__history" data-mbw-loy-history hidden></ul>
            </div>
        @else
            <p class="mbw-muted">{{ ___('Sign in to see your points') }}</p>
            <button class="mbw-btn mbw-submit" type="button" data-mb-account data-mbw-close>{{ ___('Sign in') }}</button>
        @endif
    </div>
</dialog>
@if($customer)
<style nonce="{{ csp_nonce() }}">
.mbw-loyalty__balance{margin-block:0 4px;font-size:1.05rem}
.mbw-loyalty__balance b{font-size:1.8rem;font-family:var(--tf-heading,inherit)}
.mbw-loyalty__history{list-style:none;margin:10px 0 0;padding:0;font-size:.85rem}
.mbw-loyalty__history li{padding-block:4px;border-block-start:1px solid rgba(127,127,127,.2)}
.mbw-loyalty__bar{block-size:8px;margin-block:10px;border-radius:999px;background:rgba(127,127,127,.2);overflow:hidden}
.mbw-loyalty__bar i{display:block;block-size:100%;inline-size:0;background:var(--tc-primary,#111);transition:inline-size .4s ease}
</style>
<script nonce="{{ csp_nonce() }}">
(function () {
    "use strict";
    if (window.__mbwLoyalty) { return; }
    window.__mbwLoyalty = true;
    var dlg = document.getElementById('mbw-loyalty-dialog');
    if (!dlg) { return; }
    var T = { tier: @json(___('Tier')), failed: @json(___('Something went wrong. Please try again.')) };
    var q = function (s) { return dlg.querySelector(s); };
    var loaded = false;
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    function paint(d) {
        q('[data-mbw-loy-balance]').textContent = String(d.balance);
        var tier = q('[data-mbw-loy-tier]');
        if (d.tier) { tier.textContent = T.tier + ': ' + d.tier; tier.hidden = false; }
        q('[data-mbw-loy-bar]').style.inlineSize = Math.max(0, Math.min(100, Number(d.progress_pct) || 0)) + '%';
        q('[data-mbw-loy-redeem]').hidden = !d.ready_to_redeem;
        // P6-253 (J46): plain banner when a refund took back points that were already spent.
        var banner = q('[data-mbw-loy-banner]');
        banner.textContent = d.negative && d.banner ? d.banner : '';
        banner.hidden = !(d.negative && d.banner);
        // History lines for refund claw-backs ("Order #N refunded: -X points").
        var hist = q('[data-mbw-loy-history]');
        hist.textContent = '';
        (d.ledger || []).forEach(function (row) {
            if (!row.text) { return; }
            var li = document.createElement('li');
            li.textContent = row.text;
            hist.appendChild(li);
        });
        hist.hidden = !hist.firstChild;
        q('[data-mbw-loy-card]').hidden = false;
    }
    function load() {
        fetch(dlg.getAttribute('data-mbw-loy-balance-url'), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d && d.active) { loaded = true; paint(d); } })
            .catch(function () {});
    }
    dlg.addEventListener('mbw:open', function () { if (!loaded) { load(); } });
    q('[data-mbw-loy-redeem]').addEventListener('click', function () {
        var btn = this, err = q('[data-mbw-loy-err]');
        btn.disabled = true; err.hidden = true;
        fetch(dlg.getAttribute('data-mbw-loy-redeem-url'), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
          .then(function (res) {
              btn.disabled = false;
              if (res.ok && res.body && res.body.success) {
                  q('[data-mbw-loy-ok]').hidden = false;
                  btn.hidden = true;
                  q('[data-mbw-loy-balance]').textContent = String(res.body.new_balance);
                  return;
              }
              err.textContent = (res.body && res.body.error) || T.failed; err.hidden = false;
          })
          .catch(function () { btn.disabled = false; err.textContent = T.failed; err.hidden = false; });
    });
})();
</script>
@endif
@endif
@endif
