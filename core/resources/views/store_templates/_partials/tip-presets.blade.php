{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  K4: checkout tip presets. Renders configurable tip-percentage buttons and a
  custom field; injects a hidden `tip_amount` the order-field-forwarder forwards
  into /send-order. The percentage is computed off the DISPLAYED subtotal for UX
  only — the server re-validates and the total contract owns the real number.

  Self-contained (inline styles + nonce'd ES5), self-gating: renders nothing
  unless the store turned tips on (store_options.tip_enabled). Stands down if the
  theme already ships a native tip control.
--}}
@php
    // A tip only means something at checkout. account/profile/track/info extend
    // the same layout with no checkout, and the strip has no native tip control
    // to stand down against there, so it stranded itself at the top of <main>.
    $__tipEnabled = isset($store) && storefront_page_has_checkout()
        && (bool) store_options($store->id, 'allow_tips', false);
    $__tipPresets = [];
    if ($__tipEnabled) {
        $raw = store_options($store->id, 'tip_presets', '10,15,20');
        foreach (explode(',', (string) $raw) as $p) {
            $p = (int) trim($p);
            if ($p > 0 && $p <= 100) { $__tipPresets[] = $p; }
        }
        if (empty($__tipPresets)) { $__tipPresets = [10, 15, 20]; }
    }
@endphp
@if($__tipEnabled)
<div id="mbTipPresets" dir="auto" data-tip-presets
     style="margin:12px auto;max-width:460px;padding:12px 14px;border:1px solid rgba(0,0,0,.12);border-radius:12px;">
    <p style="margin:0 0 8px;font-size:13.5px;font-weight:700;">{{ ___('Add a tip?') }}</p>
    <div id="mbTipRow" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
        <button type="button" class="mb-tip-btn" data-tip-pct="0"
                style="min-height:40px;padding:0 14px;border:1px solid rgba(0,0,0,.2);border-radius:20px;background:#fff;cursor:pointer;font-size:13px;">{{ ___('No tip') }}</button>
        @foreach($__tipPresets as $pct)
            <button type="button" class="mb-tip-btn" data-tip-pct="{{ $pct }}"
                    style="min-height:40px;padding:0 14px;border:1px solid rgba(0,0,0,.2);border-radius:20px;background:#fff;cursor:pointer;font-size:13px;">{{ $pct }}%</button>
        @endforeach
        <input type="number" id="mbTipCustom" min="0" step="0.01" placeholder="{{ ___('Custom') }}"
               style="min-height:40px;width:90px;padding:0 10px;border:1px solid rgba(0,0,0,.2);border-radius:20px;font-size:13px;">
    </div>
    <input type="hidden" name="tip_amount" id="mbTipAmount" value="">
    <p id="mbTipShown" style="margin:8px 0 0;font-size:12.5px;opacity:.75;"></p>
</div>
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    "use strict";
    var host = document.getElementById('mbTipPresets');
    if (!host) { return; }
    /* Stand down if a native tip control already exists elsewhere on the page.
       This partial is parsed ABOVE the content yield, so checking here at parse
       time found nothing on every theme: aurora and vivid ship their own
       `tip_amount` and were rendering TWO tip UIs, with two same-named fields in
       one checkout. Hide until the page is built, then decide. */
    host.hidden = true;
    /* Native tip controls come in two idioms across the modern themes: a hidden
       `tip_amount` input (aurora, vivid) and `data-tip` / `data-tip-pct` buttons
       whose own bundle posts the amount (the other 15). Matching only the named
       input therefore stood down on TWO themes and left the other 15 rendering a
       second tip UI — stranded in body flow, behind the app shell on every theme
       that paints fixed panes, so the diner never saw either it or a duplicate.
       `.mb-tip-btn` is excluded so the strip never detects its own buttons. */
    function nativeTip() {
        return document.querySelector('[name="tip_amount"]:not(#mbTipAmount), [data-tip], [data-tip-pct]:not(.mb-tip-btn)');
    }
    function decide() {
        if (!document.body.contains(host)) { return; }
        if (nativeTip()) { host.remove(); return; }
        host.hidden = false;
    }
    function settle() {
        decide();
        setTimeout(decide, 900);
        setTimeout(decide, 2500);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', settle); }
    else { settle(); }

    var hidden = document.getElementById('mbTipAmount');
    var shown = document.getElementById('mbTipShown');
    var custom = document.getElementById('mbTipCustom');
    var btns = Array.prototype.slice.call(host.querySelectorAll('.mb-tip-btn'));
    var sym = @json(cur_isolate(config('settings.currency_sign', '')));
    var symAfter = @json(currency_pos_effective() !== 1); // RTL/after → symbol trails the number

    // Best-effort read of the displayed subtotal for the % preview (UX only).
    function subtotal() {
        var el = document.querySelector('[data-cart-subtotal], #cart-subtotal, .cart-subtotal, [data-subtotal]');
        if (el) {
            var v = parseFloat(String(el.getAttribute('data-cart-subtotal') || el.getAttribute('data-subtotal') || el.textContent).replace(/[^0-9.]/g, ''));
            if (!isNaN(v)) { return v; }
        }
        return 0;
    }
    function setTip(amount) {
        amount = Math.max(0, Math.round(amount * 100) / 100);
        hidden.value = amount ? String(amount) : '';
        shown.textContent = amount ? (@json(___('Tip')) + ': ' + (symAfter ? amount.toFixed(2) + sym : sym + amount.toFixed(2))) : '';
    }
    btns.forEach(function (b) {
        b.addEventListener('click', function () {
            btns.forEach(function (x) { x.style.background = '#fff'; x.style.borderColor = 'rgba(0,0,0,.2)'; });
            b.style.background = 'rgba(13,110,253,.12)'; b.style.borderColor = '#0d6efd';
            custom.value = '';
            var pct = parseFloat(b.getAttribute('data-tip-pct')) || 0;
            setTip(pct ? subtotal() * pct / 100 : 0);
        });
    });
    custom.addEventListener('input', function () {
        btns.forEach(function (x) { x.style.background = '#fff'; x.style.borderColor = 'rgba(0,0,0,.2)'; });
        setTip(parseFloat(custom.value) || 0);
    });
})();
</script>
@endif
