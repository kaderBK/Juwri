{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  MC-34 shopper guardrail — the display-currency estimate notice.

  Every theme's switcher reprices the menu client-side, but the CHARGE is always
  in the store's base currency (converted prices are display estimates only —
  never a charge, a stored total, or JSON-LD). This shared partial shows a quiet
  inline notice — "Prices in {CUR} are estimates. You will be charged in {BASE}."
  — whenever a non-base currency is active, and removes it on return to base.

  MOUNTING: the notice must live INSIDE the theme's app view, not on <body>
  (a body-mounted bar spans the full device width and sits outside the theme's
  token scope). Each theme marks its mount with `data-mb-cur-mount` on the
  container right at the top of its app shell; the notice is prepended there.
  A theme without the attribute falls back to body-first-child (legacy look).

  STYLING: baseline below is zero-specificity (:where) on purpose — each theme
  restyles `#qc-cur-estimate` in its OWN stylesheet as a native component
  (chip/banner/toast matching its design language). Never style via inline
  styles or !important. The element structure is:
    <div id="qc-cur-estimate" class="qc-cen"><svg class="qc-cen-ic"/><span class="qc-cen-tx"/></div>

  Theme-agnostic activation: keys off the switcher buttons every theme renders
  (`button[data-cur]`, active one carrying `.is-on`). `aurora` ships its own
  equivalent notice and is skipped by the layout include. Inert unless the store
  enabled multi-currency AND a switcher is present.
--}}
@php
    $__enBase = strtoupper((string) config('settings.currency_code', 'USD'));
    $__enShow = ! empty($postOptions->enable_multi_currency ?? null);
    try {
        $__enAsOf = \Juwri\Modules\Storefront\Storefront\DisplayRates::forStore($store->id ?? 0)->asOf;
    } catch (\Throwable $e) {
        $__enAsOf = null;
    }
@endphp

@if ($__enShow)
<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
{{-- static in-flow on purpose: a sticky notice fights the theme's own sticky
     header (it covered topbars). It shows at the top of the view and scrolls
     away with content. --}}
:where(#qc-cur-estimate){display:flex;align-items:center;justify-content:center;gap:7px;background:#f59e0b22;color:#b45309;text-align:center;font-size:12px;line-height:1.45;padding:6px 12px}
:where(#qc-cur-estimate .qc-cen-ic){width:14px;height:14px;flex:none;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
</style>
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    var BASE   = @json($__enBase);
    var ASOF   = @json($__enAsOf);
    // "Prices in __CUR__ are estimates[ (date)]. You will be charged in __BASE__."
    var NOTICE = @json(___('Prices in :cur are estimates. You will be charged in :base.', ['cur' => '__CUR__', 'base' => '__BASE__']));
    var ID = 'qc-cur-estimate';

    function render(code) {
        var el = document.getElementById(ID);
        if (! code || code === BASE) { if (el) el.remove(); return; }
        if (! el) {
            el = document.createElement('div');
            el.id = ID;
            el.className = 'qc-cen';
            el.setAttribute('role', 'status');
            // coins glyph + text span — themes style both via #qc-cur-estimate
            el.innerHTML = '<svg class="qc-cen-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="9" r="6"/><path d="M14.2 5.2a6 6 0 1 1-9 9"/><path d="M9 6.6v4.8M7.2 7.8h2.7a1.2 1.2 0 0 1 0 2.4H7.8"/></svg><span class="qc-cen-tx"></span>';
            // Prefer the theme's declared mount (inside its app view); body is the
            // legacy fallback for templates that never declared one.
            var mount = document.querySelector('[data-mb-cur-mount]');
            if (mount) { mount.insertBefore(el, mount.firstChild); }
            else { document.body.insertBefore(el, document.body.firstChild); }
        }
        var msg = NOTICE.replace('__CUR__', code).replace('__BASE__', BASE);
        if (ASOF) msg = msg.replace('.', ' (' + ASOF + ').');   // real FX-feed date only
        (el.querySelector('.qc-cen-tx') || el).textContent = msg;
    }

    // The active currency = the switcher button marked .is-on (every theme does this).
    function activeCode() {
        var on = document.querySelector('button[data-cur].is-on');
        return on ? (on.dataset.cur || BASE) : BASE;
    }

    // A switcher click: the theme updates .is-on in its own handler, so read the
    // clicked button's code directly rather than racing that toggle.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('button[data-cur]');
        if (btn) render(btn.dataset.cur);
    });

    // Restore-on-load: a theme may re-apply a stored non-base currency without a
    // click. Read the resulting .is-on after the theme's init has run.
    function initFromState() { render(activeCode()); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(initFromState, 250); });
    } else {
        setTimeout(initFromState, 250);
    }
})();
</script>
@endif
