{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  PARITY-RUN T6 — the storefront combo configurator.

  This lives in the SHARED layout, not in a theme, on purpose. Every theme
  compiles its own cart bundle (assets/store_templates/<theme>/js/script.js, not in
  this repo) and there are 40+ of them; the theme-parity run proved a shared
  partial reaches all of them at once and a per-theme copy drifts within a
  release. So the combo section, the configurator and the combo basket are one
  self-contained, theme-agnostic block, and the picks ride the existing
  send-order POST through the field forwarder (`combos` is in its FIELDS list).

  MONEY: nothing here is authoritative. The prices rendered are what the server
  WOULD charge; ComboService::resolve() re-derives every one of them from the
  owner's own rows before an order line exists. A tampered payload cannot buy a
  combo cheaply — it just fails validation.

  FEATURE GATE: checked here with FeatureAccess::storeAllows() (via
  ComboService::enabled) rather than with `feature:` middleware — this partial is
  rendered for anonymous diners, and middleware would 403 every one of them.
--}}
@php
    $__comboPost = $store ?? null;
    // The storefront branch switcher puts the choice in ?branch_id= (see
    // partials/branch-switcher.blade.php) — no session key exists for it.
    $__comboBranchId = (int) request()->get('branch_id') ?: null;

    $__combos = collect();
    try {
        if ($__comboPost) {
            $__combos = collect(\Juwri\Modules\Menu\Services\Combos\ComboService::payloads(
                \Juwri\Modules\Menu\Services\Combos\ComboService::sellable((int) $__comboPost->id, $__comboBranchId)->all()
            ));
        }
    } catch (\Throwable $e) {
        $__combos = collect(); // a combo must never take the menu down with it
    }

    // Base-currency symbol. Default to '$' when unset, MIRRORING the themes'
    // `$postOptions->currency_sign ?? '$'` — the old `?: ''` left the combo rail/
    // sheet symbol-less on a store that never set a sign while its menu prices
    // (theme default '$') showed one.
    $__comboCurrency = cur_isolate($__comboPost ? (string) (store_options($__comboPost->id, 'currency_sign') ?: '$') : '$');
    // Effective (locale-aware) position: 'right' = symbol AFTER the number (RTL/Arabic
    // or currency_pos=0). The old `?: 'left'` coerced a stored 0 back to 'left', so the
    // combos sheet always rendered symbol-before — money() only ever checks === 'right'.
    $__comboCurrencyPos = $__comboPost ? (currency_pos_effective($__comboPost->id) === 1 ? 'left' : 'right') : 'left';
    $__comboDecimals = $__comboPost
        ? currency_decimals(store_options($__comboPost->id, 'currency_code') ?: null)
        : 2;
    // MC display-currency support: combos must convert like the rest of the menu.
    // The base code + per-code decimals mirror the themes' CFG (baseCurrency /
    // decimalsByCode); the live rate+code come from the shared currency switcher
    // (`button[data-cur].is-on`, carrying data-rate) at render time — no rates are
    // embedded here, so a switch needs no re-fetch.
    $__comboBaseCode = config('settings.currency_code', 'USD');
    $__comboDecimalsByCode = currency_decimals_map();
    $__comboCurrencyGap = currency_symbol_gap($__comboPost ? (store_options($__comboPost->id, 'currency_sign') ?: '$') : '$');
@endphp

@if($__combos->isNotEmpty())
{{-- The forwarder reads this by name and injects it into the send-order body. --}}
<input type="hidden" name="combos" id="mb-combos-field" value="">

<style nonce="{{ csp_nonce() }}">
    .mb-combo-fab{position:fixed;inset-block-end:16px;inset-inline-start:16px;z-index:9997;border:0;border-radius:999px;
        padding:12px 18px;font-weight:700;font-size:14px;color:#fff;background:var(--theme-color-1,#111);
        box-shadow:0 8px 24px rgba(0,0,0,.25);display:inline-flex;align-items:center;gap:8px;cursor:pointer}
    .mb-combo-fab[hidden]{display:none} /* the class display beats the UA [hidden] rule */
    .mb-combo-fab__count{background:#fff;color:#111;border-radius:999px;min-width:20px;height:20px;line-height:20px;
        text-align:center;font-size:12px;padding:0 6px}
    /* The sheet is injected at <body> level, OUTSIDE the theme root, so it inherits
       none of the theme's scoped CSS (its palette, font and radius all live on
       .crave / #dough / … ). Rather than paint one grey modal for seventeen very
       different themes, the script (mbcStyle()) reads the theme's REAL ink /
       surface / font off the rendered page and sets the --mbc-* vars below; the
       literals here are only the pre-sample fallback and the safety net for a
       sampling miss. Brand stays --theme-color-1 (the store's colour, set in the
       layout head) so the primary button matches the storefront. */
    .mb-combo-sheet{position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.5);display:none;
        align-items:flex-end;justify-content:center}
    .mb-combo-sheet[data-open="1"]{display:flex}
    .mb-combo-sheet__panel{background:var(--mbc-surface,#fff);color:var(--mbc-ink,#111);
        font-family:var(--mbc-font,var(--menu-font,inherit));
        width:100%;max-width:640px;max-height:92vh;overflow:auto;
        border-start-start-radius:18px;border-start-end-radius:18px;padding:16px;
        box-shadow:0 -12px 40px rgba(0,0,0,.28)}
    @media (min-width:640px){.mb-combo-sheet{align-items:center}
        .mb-combo-sheet__panel{border-radius:18px;max-height:86vh;box-shadow:0 24px 60px rgba(0,0,0,.4)}}
    .mb-combo-sheet__head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
    .mb-combo-sheet__title{font-size:18px;font-weight:800;margin:0}
    .mb-combo-x{border:0;background:transparent;font-size:26px;line-height:1;cursor:pointer;color:var(--mbc-ink-2,#666)}
    .mb-combo-card{display:flex;gap:12px;align-items:center;border:1px solid var(--mbc-line,#e6e6e6);border-radius:14px;padding:10px;
        margin-bottom:10px;background:var(--mbc-surface-2,#fff)}
    .mb-combo-card__img{width:64px;height:64px;object-fit:cover;border-radius:10px;flex:0 0 auto}
    .mb-combo-card__body{flex:1 1 auto;min-width:0}
    .mb-combo-card__name{font-weight:700;display:block}
    .mb-combo-card__desc{color:var(--mbc-ink-2,#666);font-size:13px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .mb-combo-card__price{font-weight:800;white-space:nowrap}
    .mb-combo-save{display:inline-block;background:#e8f7ee;color:#146c43;border-radius:999px;padding:1px 8px;
        font-size:12px;font-weight:700;margin-inline-start:6px}
    .mb-combo-btn{border:0;border-radius:10px;padding:9px 14px;font-weight:700;cursor:pointer;
        background:var(--theme-color-1,#111);color:var(--mbc-on-brand,#fff)}
    .mb-combo-btn[disabled]{opacity:.5;cursor:not-allowed}
    .mb-combo-btn--ghost{background:var(--mbc-surface-2,#f1f1f1);color:var(--mbc-ink,#111)}
    .mb-combo-slot{border-top:1px solid var(--mbc-line,#eee);padding-top:10px;margin-top:10px}
    .mb-combo-slot__title{font-weight:700;display:flex;justify-content:space-between;gap:8px;align-items:baseline}
    .mb-combo-slot__rule{font-weight:500;font-size:12px;color:var(--mbc-ink-2,#777)}
    .mb-combo-opt{display:flex;align-items:center;gap:10px;padding:8px 4px;cursor:pointer}
    .mb-combo-opt input{inline-size:18px;block-size:18px;flex:0 0 auto;accent-color:var(--theme-color-1)}
    .mb-combo-opt__name{flex:1 1 auto}
    .mb-combo-opt__up{color:var(--mbc-ink-2,#555);font-size:13px;white-space:nowrap}
    .mb-combo-line{display:flex;gap:10px;align-items:flex-start;border-bottom:1px solid var(--mbc-line,#eee);padding:10px 0}
    .mb-combo-line__body{flex:1 1 auto;min-width:0}
    .mb-combo-line__parts{color:var(--mbc-ink-2,#666);font-size:13px;margin:2px 0 0;padding-inline-start:14px}
    .mb-combo-total{display:flex;justify-content:space-between;font-weight:800;padding-top:12px}
    .mb-combo-empty{color:var(--mbc-ink-2,#777);text-align:center;padding:18px 0}
    .mb-combo-err{background:#fdecea;color:#a3282c;border-radius:10px;padding:8px 10px;font-size:13px;margin-top:8px}
    .mb-combo-qty{display:inline-flex;align-items:center;gap:8px}
    .mb-combo-qty button{border:1px solid var(--mbc-line,#ddd);background:var(--mbc-surface-2,#fff);border-radius:8px;width:30px;height:30px;
        font-size:17px;line-height:1;cursor:pointer;color:var(--mbc-ink,#111)}
    /* COMBOS-V2 B1 — in-menu "Meal deals" rail. No own background (it sits on
       whatever surface the theme paints — ink() picks the text colour); cards use
       a translucent fill so they read on light and dark alike. */
    /* 16px inline padding mirrors the offers strip (.mbo-strip padding:14px 16px 6px),
       the sibling injected section — so the rail's heading and tiles line up with the
       theme's other section content instead of sitting flush to the column edge. */
    .mb-combo-rail{color:var(--mb-ink,inherit);padding:2px 16px 6px;margin:12px 0 18px;max-width:100%}
    .mb-combo-rail__head{font-weight:800;font-size:16px;letter-spacing:-.01em;margin:0 0 12px;
        display:flex;align-items:center;gap:8px}
    .mb-combo-rail__track{display:flex;gap:12px;overflow-x:auto;scrollbar-width:none;padding-bottom:6px;scroll-snap-type:x proximity}
    .mb-combo-rail__track > *{scroll-snap-align:start}
    .mb-combo-rail__track::-webkit-scrollbar{display:none}
    .mb-combo-tile{flex:0 0 auto;width:168px;border:1px solid rgba(128,128,128,.38);background:rgba(128,128,128,.14);
        border-radius:14px;padding:10px;cursor:pointer;text-align:start;color:inherit;font:inherit}
    .mb-combo-tile:hover{border-color:rgba(128,128,128,.6)}
    .mb-combo-tile__img{width:100%;height:84px;object-fit:cover;border-radius:10px;display:block;margin-bottom:8px}
    /* Imageless combos still get a card with visual weight, not a bare text box. */
    .mb-combo-tile__ph{width:100%;height:84px;border-radius:10px;display:flex;align-items:center;justify-content:center;
        font-size:30px;margin-bottom:8px;background:linear-gradient(135deg,rgba(128,128,128,.22),rgba(128,128,128,.06))}
    .mb-combo-tile__name{font-weight:700;font-size:13px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .mb-combo-tile__row{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-top:4px}
    .mb-combo-tile__price{font-weight:800;font-size:13px}
    .mb-combo-opt__img{width:36px;height:36px;object-fit:cover;border-radius:8px;flex:0 0 auto}
    .mb-combo-savings{color:#146c43;font-size:13px;font-weight:600;margin:2px 0 0}
    [data-theme="dark"] .mb-combo-savings{color:#5fd39a}
    .mb-combo-fab__total{font-weight:600;opacity:.85;font-size:12px}
</style>

{{-- COMBOS-V2 B1 — discovery: combos live IN the menu, not only behind the fab.
     mbAnchor.place() drops the rail above the grid (and above any
     [data-mb-grid-lead] category rows); ink() bakes a readable text colour and
     onSettled re-runs both after the theme's own boot reparents things. --}}
<div class="mb-combo-rail" id="mb-combo-rail" hidden>
    <p class="mb-combo-rail__head">🍱 {{ ___('Meal deals') }}</p>
    <div class="mb-combo-rail__track" id="mb-combo-rail-track"></div>
</div>

{{-- COMBOS-V2 B2 — the fab is a BASKET chip now: hidden while empty (the rail is
     the discovery surface), and it carries the running total once filled. --}}
<button type="button" class="mb-combo-fab" id="mb-combo-fab" hidden>
    <span>{{ ___('Combo meals') }}</span>
    <span class="mb-combo-fab__count" id="mb-combo-fab-count">0</span>
    <span class="mb-combo-fab__total" id="mb-combo-fab-total"></span>
</button>

<div class="mb-combo-sheet" id="mb-combo-sheet" role="dialog" aria-modal="true"
     aria-labelledby="mb-combo-sheet-title" data-open="0">
    <div class="mb-combo-sheet__panel">
        <div class="mb-combo-sheet__head">
            <h3 class="mb-combo-sheet__title" id="mb-combo-sheet-title">{{ ___('Combo meals') }}</h3>
            <button type="button" class="mb-combo-x" id="mb-combo-close" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <div id="mb-combo-body"></div>
    </div>
</div>

<script nonce="{{ csp_nonce() }}">
(function () {
    var COMBOS = @json($__combos->values());
    var CUR = @json($__comboCurrency);
    var CUR_POS = @json($__comboCurrencyPos);
    var DEC = {{ (int) $__comboDecimals }};
    var CUR_GAP = @json($__comboCurrencyGap);
    var BASE_CODE = @json($__comboBaseCode);
    var DEC_BY_CODE = @json($__comboDecimalsByCode);
    var T = {
        combos: @json(___('Combo meals')),
        addToOrder: @json(___('Add to order')),
        yourCombos: @json(___('Your combos')),
        noCombos: @json(___('No combos added yet.')),
        saves: @json(___('Saves')),
        included: @json(___('Included')),
        remove: @json(___('Remove')),
        back: @json(___('Back')),
        chooseAtLeast: @json(___('Choose at least :n')),
        chooseAtMost: @json(___('Choose at most :n')),
        pickExactly: @json(___('Pick :n')),
        optional: @json(___('Optional')),
        subtotal: @json(___('Combos subtotal')),
        done: @json(___('Done')),
        checkout: @json(___('Checkout')),
    };

    var fab = document.getElementById('mb-combo-fab');
    var sheet = document.getElementById('mb-combo-sheet');
    var body = document.getElementById('mb-combo-body');
    var field = document.getElementById('mb-combos-field');
    var closeBtn = document.getElementById('mb-combo-close');
    if (!fab || !sheet || !body || !field) return;

    /* basket: [{combo_id, quantity, picks:{slotId:[optionIds]}}] — ids only, no
       prices. The server prices it; these numbers are for the diner's eyes. */
    var basket = [];
    var view = 'list';       // list | configure | basket
    var current = null;      // combo being configured
    var draft = {};          // slotId -> [optionIds]
    var draftError = '';

    /* Active display currency = the shared switcher button marked .is-on (every
       theme renders `button[data-cur]` with a data-rate; the base button carries
       data-rate="1" and starts .is-on). Absent switcher / no selection = base. */
    function activeCur() {
        var on = document.querySelector('button[data-cur].is-on');
        if (!on) return { code: BASE_CODE, rate: 1 };
        return { code: on.dataset.cur || BASE_CODE, rate: Number(on.dataset.rate) || 1 };
    }
    /* Mirrors the themes' money(base): multiply by the live rate, and for a non-base
       display currency render the "≈ CODE n" estimate the menu items use, so a combo
       never shows the store's base currency while everything around it is converted.
       Decimals follow the DISPLAYED currency (base uses its own DEC; a converted code
       is 2 unless listed in DEC_BY_CODE). */
    function money(n) {
        var a = activeCur();
        var v = (Number(n) || 0) * a.rate;
        var dp = DEC_BY_CODE[a.code];
        if (dp == null) dp = (a.code === BASE_CODE) ? DEC : 2;
        var s = v.toFixed(dp);
        if (a.code !== BASE_CODE) return '≈ ' + a.code + ' ' + s;
        return CUR_POS === 'right' ? (s + CUR_GAP + CUR) : (CUR + CUR_GAP + s);
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    function comboById(id) {
        for (var i = 0; i < COMBOS.length; i++) { if (COMBOS[i].id === Number(id)) return COMBOS[i]; }
        return null;
    }
    function optById(combo, optId) {
        for (var i = 0; i < combo.slots.length; i++) {
            var s = combo.slots[i];
            for (var j = 0; j < s.options.length; j++) {
                if (s.options[j].id === Number(optId)) return { slot: s, opt: s.options[j] };
            }
        }
        return null;
    }
    function linePrice(entry) {
        var combo = comboById(entry.combo_id);
        if (!combo) return 0;
        var unit = Number(combo.price) || 0;
        Object.keys(entry.picks || {}).forEach(function (slotId) {
            (entry.picks[slotId] || []).forEach(function (optId) {
                var f = optById(combo, optId);
                if (f) unit += Number(f.opt.upcharge) || 0;
            });
        });
        return unit * (entry.quantity || 1);
    }
    function basketTotal() {
        return basket.reduce(function (a, e) { return a + linePrice(e); }, 0);
    }
    function slotRule(slot) {
        if (slot.min === slot.max && slot.min > 0) return T.pickExactly.replace(':n', slot.min);
        if (slot.min > 0) return T.chooseAtLeast.replace(':n', slot.min);
        return T.optional;
    }

    /* Mirror of ComboService::resolve()'s slot rules, so the diner is told BEFORE
       submitting rather than by a 422 at checkout. The server still enforces it —
       this is a courtesy, never the gate. */
    function draftValid() {
        if (!current) return false;
        for (var i = 0; i < current.slots.length; i++) {
            var s = current.slots[i];
            var n = (draft[s.id] || []).length;
            if (n < s.min) return false;
            if (s.max > 0 && n > s.max) return false;
        }
        return true;
    }

    /* Pin the FAB to the theme's centered content column, not the raw viewport.
       On desktop every modern theme is a centered frame (crave's 430px phone
       column … crumb's 1178), so a viewport-anchored FAB sat far out in the
       letterbox. Align its inline edge to the frame's; on mobile / a full-width
       theme the band spans the viewport and the guard restores the CSS default
       (inset-inline-start:16px). RTL uses the frame's opposite edge. */
    function positionFab() {
        var band = window.mbAnchor && window.mbAnchor.frameBand && window.mbAnchor.frameBand();
        var rtl = (document.documentElement.getAttribute('dir') || document.dir) === 'rtl';
        if (band && band.width < window.innerWidth - 40) {
            if (rtl) {
                fab.style.right = (window.innerWidth - (band.left + band.width) + 16) + 'px';
                fab.style.left = 'auto';
            } else {
                fab.style.left = (band.left + 16) + 'px';
                fab.style.right = 'auto';
            }
        } else {
            fab.style.left = '';
            fab.style.right = '';
        }
    }
    window.addEventListener('resize', positionFab);
    window.addEventListener('load', positionFab);

    function syncField() {
        field.value = basket.length ? JSON.stringify(basket.map(function (e) {
            return {
                combo_id: e.combo_id,
                quantity: e.quantity,
                picks: Object.keys(e.picks).map(function (slotId) {
                    return { slot_id: Number(slotId), option_ids: e.picks[slotId] };
                }),
            };
        })) : '';
        var count = basket.reduce(function (a, e) { return a + (e.quantity || 1); }, 0);
        document.getElementById('mb-combo-fab-count').textContent = String(count);
        /* B2 — basket chip: hidden while empty (the in-menu rail is the discovery
           surface), and carries the running total once filled. */
        fab.hidden = count === 0;
        if (count) positionFab();
        var fabTotal = document.getElementById('mb-combo-fab-total');
        if (fabTotal) fabTotal.textContent = count ? money(basketTotal()) : '';
        /* COMBOS-V2 A1 — authoritative DISPLAY state for the themes. Every theme
           folds `total` into its quoted subtotal pre-tax (the server taxes combo
           lines like any other line) and renders `lines` as a read-only "Combos"
           group in its cart/checkout summary; quantity edits stay in this sheet.
           Money here is still only what the server WOULD charge — resolve()
           re-prices everything. */
        window.mbCombos = window.mbCombos || {};
        window.mbCombos.count = count;
        window.mbCombos.total = basketTotal();
        window.mbCombos.money = money;
        window.mbCombos.openSheet = function (v) { open(v || (basket.length ? 'basket' : 'list')); };
        window.mbCombos.lines = basket.map(function (e) {
            var c = comboById(e.combo_id);
            var parts = [];
            Object.keys(e.picks || {}).forEach(function (slotId) {
                (e.picks[slotId] || []).forEach(function (optId) {
                    var f = c && optById(c, optId);
                    if (f) parts.push(f.opt.name);
                });
            });
            return {
                id: e.combo_id,
                name: c ? c.name : '',
                qty: e.quantity || 1,
                unit: c ? (linePrice(e) / (e.quantity || 1)) : 0,
                price: linePrice(e),
                parts: parts,
            };
        });
        try { window.dispatchEvent(new CustomEvent('mb:combos-changed', { detail: { count: count, total: basketTotal(), lines: window.mbCombos.lines } })); } catch (e) {}
    }

    function renderList() {
        var html = '';
        COMBOS.forEach(function (c) {
            html += '<div class="mb-combo-card">';
            if (c.image) {
                html += '<img class="mb-combo-card__img" loading="lazy" alt="" src="' + esc(c.image_url || c.image) + '">';
            }
            html += '<div class="mb-combo-card__body">'
                 +  '<span class="mb-combo-card__name">' + esc(c.name)
                 +  (c.saving > 0 ? '<span class="mb-combo-save">' + esc(T.saves) + ' ' + esc(money(c.saving)) + '</span>' : '')
                 +  '</span>'
                 +  (c.description ? '<span class="mb-combo-card__desc">' + esc(c.description) + '</span>' : '')
                 +  '</div>'
                 +  '<span class="mb-combo-card__price">' + esc(money(c.price)) + '</span>'
                 +  '<button type="button" class="mb-combo-btn" data-configure="' + c.id + '">' + esc(T.addToOrder) + '</button>'
                 +  '</div>';
        });

        html += '<div style="margin-top:14px"><button type="button" class="mb-combo-btn mb-combo-btn--ghost" data-view="basket">'
             +  esc(T.yourCombos) + ' (' + basket.length + ')</button></div>';
        body.innerHTML = html;
    }

    function renderConfigure() {
        var c = current;
        var html = '<div class="mb-combo-card"><div class="mb-combo-card__body">'
                 + '<span class="mb-combo-card__name">' + esc(c.name) + '</span>'
                 + (c.description ? '<span class="mb-combo-card__desc">' + esc(c.description) + '</span>' : '')
                 /* B4 — make the deal explicit: what this bundle saves vs à la carte. */
                 + (c.saving > 0 ? '<span class="mb-combo-savings">' + esc(T.saves) + ' ' + esc(money(c.saving)) + '</span>' : '')
                 + '</div><span class="mb-combo-card__price">' + esc(money(c.price)) + '</span></div>';

        c.slots.forEach(function (slot) {
            html += '<div class="mb-combo-slot"><div class="mb-combo-slot__title"><span>' + esc(slot.title) + '</span>'
                 +  '<span class="mb-combo-slot__rule">' + esc(slotRule(slot)) + '</span></div>';
            var multi = slot.max === 0 || slot.max > 1;
            slot.options.forEach(function (o) {
                var checked = (draft[slot.id] || []).indexOf(o.id) !== -1;
                html += '<label class="mb-combo-opt">'
                     +  '<input type="' + (multi ? 'checkbox' : 'radio') + '" name="mb-slot-' + slot.id + '"'
                     +  ' data-slot="' + slot.id + '" data-opt="' + o.id + '"' + (checked ? ' checked' : '') + '>'
                     +  (o.image_url ? '<img class="mb-combo-opt__img" loading="lazy" alt="" src="' + esc(o.image_url) + '">' : '')
                     +  '<span class="mb-combo-opt__name">' + esc(o.name) + '</span>'
                     +  '<span class="mb-combo-opt__up">' + (Number(o.upcharge) > 0 ? '+' + esc(money(o.upcharge)) : esc(T.included)) + '</span>'
                     +  '</label>';
            });
            html += '</div>';
        });

        if (draftError) html += '<div class="mb-combo-err">' + esc(draftError) + '</div>';

        html += '<div style="display:flex;gap:8px;margin-top:14px;justify-content:space-between;align-items:center;flex-wrap:wrap">'
             +  '<button type="button" class="mb-combo-btn mb-combo-btn--ghost" data-view="list">' + esc(T.back) + '</button>'
             +  '<button type="button" class="mb-combo-btn" data-add="1"' + (draftValid() ? '' : ' disabled') + '>'
             +  esc(T.addToOrder) + ' · ' + esc(money(draftUnitPrice())) + '</button></div>';
        body.innerHTML = html;
    }

    function draftUnitPrice() {
        if (!current) return 0;
        var unit = Number(current.price) || 0;
        Object.keys(draft).forEach(function (slotId) {
            (draft[slotId] || []).forEach(function (optId) {
                var f = optById(current, optId);
                if (f) unit += Number(f.opt.upcharge) || 0;
            });
        });
        return unit;
    }

    function renderBasket() {
        var html = '';
        if (!basket.length) {
            html += '<div class="mb-combo-empty">' + esc(T.noCombos) + '</div>';
        } else {
            basket.forEach(function (e, idx) {
                var c = comboById(e.combo_id);
                if (!c) return;
                var parts = '';
                Object.keys(e.picks).forEach(function (slotId) {
                    (e.picks[slotId] || []).forEach(function (optId) {
                        var f = optById(c, optId);
                        if (f) parts += '<li>' + esc(f.opt.name) + '</li>';
                    });
                });
                html += '<div class="mb-combo-line"><div class="mb-combo-line__body">'
                     +  '<strong>' + esc(c.name) + '</strong>'
                     +  '<ul class="mb-combo-line__parts">' + parts + '</ul>'
                     +  '<span class="mb-combo-qty">'
                     +  '<button type="button" data-dec="' + idx + '" aria-label="-">&minus;</button>'
                     +  '<span>' + e.quantity + '</span>'
                     +  '<button type="button" data-inc="' + idx + '" aria-label="+">+</button>'
                     +  '</span></div>'
                     +  '<div style="text-align:end"><div class="mb-combo-card__price">' + esc(money(linePrice(e))) + '</div>'
                     +  '<button type="button" class="mb-combo-btn mb-combo-btn--ghost" style="margin-top:6px;padding:4px 10px;font-size:12px" data-del="' + idx + '">'
                     +  esc(T.remove) + '</button></div></div>';
            });
            html += '<div class="mb-combo-total"><span>' + esc(T.subtotal) + '</span><span>' + esc(money(basketTotal())) + '</span></div>';
        }
        /* B4 — when the theme registered a cart-opener (A2 integrations may set
           window.mbCombos.openCart), the primary action walks the diner straight
           to checkout; otherwise it stays a plain Done. */
        var canCheckout = basket.length && window.mbCombos && typeof window.mbCombos.openCart === 'function';
        html += '<div style="display:flex;gap:8px;margin-top:14px;justify-content:space-between;flex-wrap:wrap">'
             +  '<button type="button" class="mb-combo-btn mb-combo-btn--ghost" data-view="list">' + esc(T.back) + '</button>'
             +  (canCheckout
                    ? '<button type="button" class="mb-combo-btn" data-checkout="1">' + esc(T.checkout) + ' · ' + esc(money(basketTotal())) + '</button>'
                    : '<button type="button" class="mb-combo-btn" data-close="1">' + esc(T.done) + '</button>');
        html += '</div>';
        body.innerHTML = html;
    }

    function render() {
        if (view === 'configure' && current) renderConfigure();
        else if (view === 'basket') renderBasket();
        else renderList();
    }

    /* COMBOS-V2 — one entry point for "start building this combo", shared by the
       sheet's list buttons and the in-menu rail tiles. */
    function startConfigure(id) {
        current = comboById(id);
        draft = {};
        draftError = '';
        // Pre-select the first option of every single-pick required slot, so the
        // common case is one tap.
        if (current) {
            current.slots.forEach(function (s) {
                if (s.min === 1 && s.max === 1 && s.options.length) draft[s.id] = [s.options[0].id];
            });
        }
        view = 'configure';
        render();
        /* Skin + frame-align before revealing: the rail-tile open path must run the
           same setScheme()/positionSheet() the FAB's open() does. Without them the
           panel paints the fallback white-on-#111 instead of the theme's sampled
           ink/surface (wrong on a dark theme like crave) and, on a phone-frame theme,
           spans the whole viewport instead of sitting inside the centered column. */
        setScheme();
        positionSheet();
        sheet.setAttribute('data-open', '1');
    }

    /* COMBOS-V2 B1 — the "Meal deals" rail. Rendered once (the combo list is
       server-fixed per page) and anchored into the theme's menu by mbAnchor;
       onSettled re-places + re-inks after the theme's own boot reparents things. */
    /* Rail tile HTML — extracted so a currency switch can re-price it in place. */
    function renderRailTrack() {
        var track = document.getElementById('mb-combo-rail-track');
        if (!track) return;
        track.innerHTML = COMBOS.map(function (c) {
            return '<button type="button" class="mb-combo-tile" data-rail-configure="' + c.id + '">' +
                (c.image_url
                    ? '<img class="mb-combo-tile__img" loading="lazy" alt="" src="' + esc(c.image_url) + '">'
                    : '<span class="mb-combo-tile__ph" aria-hidden="true">🍱</span>') +
                '<span class="mb-combo-tile__name">' + esc(c.name) + '</span>' +
                '<span class="mb-combo-tile__row"><span class="mb-combo-tile__price">' + esc(money(c.price)) + '</span>' +
                (c.saving > 0 ? '<span class="mb-combo-save">' + esc(T.saves) + ' ' + esc(money(c.saving)) + '</span>' : '') +
                '</span></button>';
        }).join('');
    }
    (function mountRail() {
        var rail = document.getElementById('mb-combo-rail');
        var track = document.getElementById('mb-combo-rail-track');
        if (!rail || !track) return;
        renderRailTrack();
        track.addEventListener('click', function (e) {
            var t = e.target.closest('[data-rail-configure]');
            if (t) startConfigure(t.getAttribute('data-rail-configure'));
        });
        /* theme-anchor is @included AFTER this partial in the layout, so
           window.mbAnchor does not exist yet while this script runs — anchor on
           DOMContentLoaded (or immediately if some future reorder puts it first).
           Without the retry the rail silently strands at the top of <body>,
           invisible behind every app-shell theme. */
        function anchorRail() {
            if (!window.mbAnchor) return false;
            window.mbAnchor.onSettled(function () {
                /* Reveal ONLY once it actually sits next to the menu (offers-strip
                   pattern). A page with no menu grid — order tracking, account,
                   info — never places it, so it stays hidden instead of stranding
                   full-width at the top of <body>, outside the theme frame. */
                if (window.mbAnchor.place(rail)) rail.hidden = false;
                window.mbAnchor.ink(rail);
            }, rail);
            return true;
        }
        if (!anchorRail()) {
            document.addEventListener('DOMContentLoaded', anchorRail);
            window.addEventListener('load', anchorRail);
        }
    })();

    /* Re-price the combo UI when the visitor flips the shared currency switcher.
       Every theme flips .is-on inside its own [data-cur] click handler; defer a
       tick so we read the settled selection, then re-render the rail, the open
       sheet and the FAB total through money() (which now reads the active button).
       Without this the combo prices would keep the currency they were first
       rendered in while the menu around them converts. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('button[data-cur]');
        if (!btn) return;
        setTimeout(function () {
            renderRailTrack();
            if (sheet.getAttribute('data-open') === '1') render();
            var ft = document.getElementById('mb-combo-fab-total');
            if (ft && basket.length) ft.textContent = money(basketTotal());
        }, 0);
    });

    /* ── theme-matched sheet skin (B5) ──────────────────────────────────────
       The sheet is injected at <body> level, outside the theme root, so it
       inherits none of the theme's palette/font. Read the theme's REAL ink and
       surface off the rendered page and publish them as --mbc-* vars the panel
       CSS consumes, so the modal wears the theme's own colours instead of one
       hardcoded grey. Robust to the desktop letterbox: sampling background alone
       is fooled by the dark backdrop around the phone-frame column, so INK (the
       theme's text colour, set regardless of surround or of whether it stamped
       data-theme — soiree is dark by default and stamps nothing) drives the
       scheme, and the surface is derived by nudging the theme's own base fill a
       few percent toward that ink for a modal-appropriate lift. */
    function rgbOf(s) { var m = String(s).match(/(\d+(?:\.\d+)?)/g); return (m && m.length >= 3) ? { r: +m[0], g: +m[1], b: +m[2], a: m.length > 3 ? +m[3] : 1 } : null; }
    function mix(a, b, t) { return { r: Math.round(a.r + (b.r - a.r) * t), g: Math.round(a.g + (b.g - a.g) * t), b: Math.round(a.b + (b.b - a.b) * t) }; }
    function rgbStr(c) { return 'rgb(' + c.r + ',' + c.g + ',' + c.b + ')'; }
    function rgbaStr(c, a) { return 'rgba(' + c.r + ',' + c.g + ',' + c.b + ',' + a + ')'; }
    function lum(c) { return (0.299 * c.r + 0.587 * c.g + 0.114 * c.b) / 255; }
    /* The theme's base surface: the first opaque ancestor fill that the ink will
       actually read on. The contrast test matters — dough paints a cream `.view`
       pane OVER its dark #dough root while keeping cream ink, so the naive "first
       opaque bg" gave cream-on-cream (invisible). Skipping a fill whose luminance
       matches the ink climbs past that pane to the dark root. A readable theme
       always pairs its text with a contrasting surface, so this never skips the
       real one; the first-opaque fallback covers the impossible all-low-contrast
       case rather than returning null. */
    function opaqueBg(el, ink) {
        var inkL = ink ? lum(ink) : 0;
        var firstOpaque = null;
        for (var e = el; e && e !== document.documentElement; e = e.parentElement) {
            var c = rgbOf(window.getComputedStyle(e).backgroundColor);
            if (c && c.a >= 0.9) {
                if (!firstOpaque) firstOpaque = c;
                if (!ink || Math.abs(lum(c) - inkL) > 0.28) return c;
            }
        }
        return firstOpaque;
    }
    function setScheme() {
        var probe = document.querySelector('.view.is-active')
                 || document.querySelector('.view-scroll')
                 || document.querySelector('[class*="views"]')
                 || document.body.firstElementChild
                 || document.body;
        var cs = window.getComputedStyle(probe);
        var ink = rgbOf(cs.color);
        var base = opaqueBg(probe, ink);
        var st = sheet.style;
        if (ink && base) {
            sheet.setAttribute('data-scheme', lum(ink) > 0.6 ? 'dark' : 'light');
            st.setProperty('--mbc-ink', rgbStr(ink));
            st.setProperty('--mbc-ink-2', rgbaStr(ink, 0.62));
            st.setProperty('--mbc-line', rgbaStr(ink, 0.16));
            st.setProperty('--mbc-surface', rgbStr(mix(base, ink, 0.05)));
            st.setProperty('--mbc-surface-2', rgbStr(mix(base, ink, 0.11)));
        } else {
            /* Sampling miss: fall back to a scheme guess and let the CSS literals
               cover the rest. */
            var dark = document.documentElement.getAttribute('data-theme') === 'dark'
                    || (ink && lum(ink) > 0.6);
            sheet.setAttribute('data-scheme', dark ? 'dark' : 'light');
            ['--mbc-ink', '--mbc-ink-2', '--mbc-line', '--mbc-surface', '--mbc-surface-2'].forEach(function (v) { st.removeProperty(v); });
            if (dark) {
                st.setProperty('--mbc-ink', '#f2f3f5'); st.setProperty('--mbc-ink-2', '#9aa1ab');
                st.setProperty('--mbc-line', '#2a2e36'); st.setProperty('--mbc-surface', '#16181d');
                st.setProperty('--mbc-surface-2', '#1d2026');
            }
        }
        // Typography: adopt the theme's own family (keep Saudi Riyal first so the
        // riyal price glyph still resolves — but only prepend it when the sampled
        // stack does not already lead with it, which the body stack does).
        if (cs.fontFamily) {
            var ff = cs.fontFamily;
            if (ff.indexOf('Saudi Riyal') === -1) ff = "'Saudi Riyal', " + ff;
            st.setProperty('--mbc-font', ff);
        }
    }

    /* Constrain the modal to the theme's centered content column on desktop, so
       the scrim dims only the storefront frame and the panel cannot be wider than
       (and spill past) a phone-frame theme's 430px column. Mobile / full-width
       themes (band ≈ viewport) keep the CSS inset:0 default. */
    function positionSheet() {
        var band = window.mbAnchor && window.mbAnchor.frameBand && window.mbAnchor.frameBand();
        var st = sheet.style;
        if (band && band.width < window.innerWidth - 40) {
            st.left = band.left + 'px'; st.right = 'auto'; st.width = band.width + 'px';
        } else {
            st.left = ''; st.right = ''; st.width = '';
        }
    }
    window.addEventListener('resize', function () { if (sheet.getAttribute('data-open') === '1') { setScheme(); positionSheet(); } });

    /* Re-apply frame alignment once mbAnchor has measured the theme's frame.
       positionFab()/positionSheet() both read mbAnchor.frameBand(); the FAB is
       revealed (syncField) and the sheet can be opened BEFORE mbAnchor loads —
       it is @included AFTER this partial — so frameBand() is null at that moment
       and both fall back to their CSS default: the FAB parks at the viewport edge
       (inset-inline-start:16px), OUTSIDE the centered phone-frame column, and the
       sheet spans the whole viewport. They are otherwise only bound to load/resize,
       so nothing corrects them after mbAnchor settles. Register a re-sync on
       onSettled (retrying until mbAnchor exists, like anchorRail) so they snap into
       the frame the moment it is known. */
    function syncFramePositions() {
        positionFab();
        if (sheet.getAttribute('data-open') === '1') positionSheet();
    }
    function registerFrameSync() {
        if (!window.mbAnchor || !window.mbAnchor.onSettled) return false;
        window.mbAnchor.onSettled(syncFramePositions);
        return true;
    }
    if (!registerFrameSync()) {
        document.addEventListener('DOMContentLoaded', registerFrameSync);
        window.addEventListener('load', registerFrameSync);
    }

    function open(v) { view = v || 'list'; render(); setScheme(); positionSheet(); sheet.setAttribute('data-open', '1'); }
    function close() { sheet.setAttribute('data-open', '0'); }

    fab.addEventListener('click', function () { open(basket.length ? 'basket' : 'list'); });
    closeBtn.addEventListener('click', close);
    sheet.addEventListener('click', function (e) { if (e.target === sheet) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

    body.addEventListener('change', function (e) {
        var input = e.target;
        if (!input || !input.getAttribute || !input.getAttribute('data-slot')) return;
        var slotId = Number(input.getAttribute('data-slot'));
        var optId = Number(input.getAttribute('data-opt'));
        var slot = null;
        current.slots.forEach(function (s) { if (s.id === slotId) slot = s; });
        if (!slot) return;

        var list = draft[slotId] || [];
        var multi = slot.max === 0 || slot.max > 1;
        if (multi) {
            if (input.checked) {
                if (list.indexOf(optId) === -1) list.push(optId);
            } else {
                list = list.filter(function (x) { return x !== optId; });
            }
            // Client-side courtesy clamp; the server refuses an over-max payload anyway.
            if (slot.max > 0 && list.length > slot.max) list = list.slice(list.length - slot.max);
        } else {
            list = input.checked ? [optId] : [];
        }
        draft[slotId] = list;
        draftError = '';
        renderConfigure();
    });

    body.addEventListener('click', function (e) {
        var el = e.target.closest('[data-configure],[data-view],[data-add],[data-del],[data-inc],[data-dec],[data-close],[data-checkout]');
        if (!el) return;

        if (el.hasAttribute('data-checkout')) {
            close();
            try { window.mbCombos.openCart(); } catch (err) {}
            return;
        }

        if (el.hasAttribute('data-configure')) {
            startConfigure(el.getAttribute('data-configure'));
            return;
        }
        if (el.hasAttribute('data-view')) { view = el.getAttribute('data-view'); render(); return; }
        if (el.hasAttribute('data-close')) { close(); return; }
        if (el.hasAttribute('data-add')) {
            if (!draftValid()) { draftError = T.chooseAtLeast.replace(':n', 1); renderConfigure(); return; }
            var picks = {};
            Object.keys(draft).forEach(function (k) { if ((draft[k] || []).length) picks[k] = draft[k].slice(); });
            basket.push({ combo_id: current.id, quantity: 1, picks: picks });
            syncField();
            view = 'basket';
            render();
            return;
        }
        var idx;
        if (el.hasAttribute('data-del')) {
            idx = Number(el.getAttribute('data-del'));
            basket.splice(idx, 1);
        } else if (el.hasAttribute('data-inc')) {
            idx = Number(el.getAttribute('data-inc'));
            basket[idx].quantity = Math.min(99, (basket[idx].quantity || 1) + 1);
        } else if (el.hasAttribute('data-dec')) {
            idx = Number(el.getAttribute('data-dec'));
            basket[idx].quantity = (basket[idx].quantity || 1) - 1;
            if (basket[idx].quantity < 1) basket.splice(idx, 1);
        } else {
            return;
        }
        syncField();
        renderBasket();
    });

    /* A successful order clears the combo basket. Most themes navigate away after
       checkout, but a kiosk or a shared tablet stays on the page — and a basket
       that survived would silently ride along on the NEXT diner's order. Themes
       may also fire the event by hand. */
    function clearBasket() { basket = []; view = 'list'; syncField(); if (sheet.getAttribute('data-open') === '1') render(); }
    window.addEventListener('mb:order-placed', clearBasket);

    function watchResponse(text) {
        try { if (JSON.parse(text).success === true) clearBasket(); } catch (e) {}
    }
    function isSendOrder(u) { return typeof u === 'string' && u.indexOf('/send-order') !== -1; }

    var origFetch = window.fetch;
    if (origFetch) {
        window.fetch = function (input, init) {
            var url = (typeof input === 'string') ? input : (input && input.url);
            var p = origFetch.apply(this, arguments);
            if (isSendOrder(url)) {
                p.then(function (res) { res.clone().text().then(watchResponse).catch(function () {}); })
                 .catch(function () {});
            }
            return p;
        };
    }
    var xhrOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url) {
        if (isSendOrder(url)) {
            this.addEventListener('load', function () { watchResponse(this.responseText); });
        }
        return xhrOpen.apply(this, arguments);
    };

    fab.hidden = false;
    syncField();
})();
</script>
@endif
