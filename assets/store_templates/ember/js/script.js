// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ═══════════════════════════════════════════════════════════
   ember — glassmorphic dark burger app · theme runtime
   Reads window.EMBER (from index.blade.php): items, endpoints,
   flags, i18n, currency, csrf. UI mirrors rev/ember/demo.js:
   size variants · paid-extra TOGGLES · dietary tags · calories ·
   favorites · cart w/ notes · coupons · order types + table# +
   schedule · tips · cash/card/wallet · loyalty · offers ·
   reservations + waitlist · WhatsApp · ember-particle FX
   ═══════════════════════════════════════════════════════════ */
/* ── appearance (dark / light / system) ───────────────────────────────────
   Runs on EVERY ember page — index, track and account — so the diner's choice
   does not evaporate the moment they follow a tracking link. It therefore sits
   ABOVE the `window.EMBER` guard below, which only the index page satisfies.

   Dark is the DEFAULT for every store; `prefers-color-scheme` is consulted only
   under the explicit "System" choice. The preference is per store (keyed on the
   root element's slug) so a diner's choice in one restaurant does not restyle
   another. */
(function emberAppearance() {
  var root = document.getElementById("ember");
  if (!root) return;
  var key = "em-appearance-" + (root.dataset.slug || location.pathname.split("/")[1] || "");
  var mq = window.matchMedia ? window.matchMedia("(prefers-color-scheme: light)") : null;

  function apply(mode) {
    root.dataset.appearance = mode;
    root.classList.toggle("is-sys-light", mode === "system" && !!(mq && mq.matches));
  }
  function current() {
    var v;
    try { v = localStorage.getItem(key); } catch (e) {}
    return v === "light" || v === "system" ? v : "dark";
  }
  apply(current());
  if (mq && mq.addEventListener) {
    mq.addEventListener("change", function () { if (current() === "system") apply("system"); });
  }

  var row = document.getElementById("appearanceRow");
  if (!row) return;
  function paint(mode) {
    Array.prototype.forEach.call(row.querySelectorAll("[data-appearance]"), function (b) {
      var on = b.dataset.appearance === mode;
      b.classList.toggle("is-on", on);
      b.setAttribute("aria-pressed", on ? "true" : "false");
    });
  }
  paint(current());
  row.addEventListener("click", function (e) {
    var b = e.target.closest("[data-appearance]");
    if (!b) return;
    var mode = b.dataset.appearance;
    try { localStorage.setItem(key, mode); } catch (err) {}
    apply(mode); paint(mode);
  });
})();

(function () {
  "use strict";
  var CFG = window.EMBER;
  if (!CFG) return;
  var I = CFG.i18n || {};
  function t(k) { return I[k] != null ? I[k] : k; }

  var root = document.getElementById("ember");
  if (!root) return;
  function $(s, c) { return (c || root).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || root).querySelectorAll(s)); }
  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }
  /* Crawlable + new-tab-able per-item URL: keeps existing query (table, lang…)
     and sets ?item=ID — the deep-link the R7 intercept + boot loader understand. */
  function itemHref(id) {
    try { var u = new URL(window.location.href); u.searchParams.set("item", id); u.hash = ""; return u.pathname + u.search; }
    catch (e) { return "?item=" + encodeURIComponent(id); }
  }
  function setCookie(name, value) {
    document.cookie = name + "=" + encodeURIComponent(value) + ";path=/;max-age=" + (60 * 60 * 24 * 365);
  }
  function navigateLanguage(code) {
    var url = new URL(window.location.href), parts = url.pathname.split("/");
    /* Prefix mode (/en/slug): swap the code in place. Session mode (no code in
       the path): inserting one 404s — the cookies set by the caller already
       carry the choice, so a plain reload is the whole switch. */
    if (parts.length > 2 && /^[a-z]{2,3}(?:-[a-z]{2})?$/i.test(parts[1])) parts[1] = code;
    url.pathname = parts.join("/");
    url.searchParams.delete("lang");
    window.location.assign(url.toString());
  }

  var ICONS = {
    plus: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg>',
    minus: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg>',
    heart: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>',
    check: '<svg class="ic" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>',
    trash: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M10 7V5h4v2M8 7l.8 13h6.4L16 7"/></svg>',
    note: '<svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M4 20l1-4L16.5 4.5a2.1 2.1 0 0 1 3 3L8 19l-4 1Z"/></svg>',
    flame: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 22c4.4 0 7-2.8 7-6.6 0-4.8-4.2-6.6-4.9-11.4-2.5 1.6-3.4 4-3.2 6.6-1.2-.7-2-1.7-2.4-3.4C6.6 9 5 11.6 5 15.4 5 19.2 7.6 22 12 22Z"/></svg>',
    /* Badge/tag glyphs. These were full-colour emoji (⚡🌶🔥🌱☪🌾⏱⚠) dropped into
       a monochrome dark-glass surface — two icon languages on one card. Redrawn in
       the theme's own 1.7px stroke so they inherit currentColor like every other icon. */
    bolt: '<svg class="ic ic-xs" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 5 13h6l-1 9 8-11h-6Z"/></svg>',
    chilli: '<svg class="ic ic-xs" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 4c2 0 3 1 3 2 3 0 5 2 5 5 0 5-5 9-10 9-4 0-7-2-7-5 0-4 5-6 8-6 0-3 0-5 1-5Z"/></svg>',
    flame2: '<svg class="ic ic-xs" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3c3 3.5 5 6 5 9a5 5 0 0 1-10 0c0-1.6.7-3 2-4.4.4 1.4 1.2 2.2 2 2.4-.4-2.6.3-5 1-7Z"/></svg>',
    leaf: '<svg class="ic ic-xs" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21c0-6 3-10 8-11 0 6-3 10-8 11Z"/><path d="M12 21c0-4-2-7-6-8"/></svg>',
    moon: '<svg class="ic ic-xs" viewBox="0 0 24 24" aria-hidden="true"><path d="M16.5 4.5a8 8 0 1 0 0 15 6.5 6.5 0 0 1 0-15Z"/></svg>',
    wheat: '<svg class="ic ic-xs" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21V8"/><path d="M12 8c0-2.5 1.8-4.5 4-5 0 2.8-1.6 4.6-4 5Z"/><path d="M12 13c0-2.5-1.8-4.5-4-5 0 2.8 1.6 4.6 4 5Z"/></svg>',
    clock: '<svg class="ic ic-xs" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 1.8"/></svg>',
    warn: '<svg class="ic ic-xs" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 21 19H3Z"/><path d="M12 10v4M12 16.5v.5"/></svg>',
    loc: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>',
  };

  /* ── state ────────────────────────────────────────────── */
  var slug = CFG.restaurantSlug;
  var cartKey = "mb-cart-" + slug;      /* shared with other themes */
  var favKey = "em-favs-" + slug;
  var cart = [];
  try { cart = JSON.parse(localStorage.getItem(cartKey)) || []; } catch (e) {}
  if (!Array.isArray(cart)) cart = [];
  var favs = new Set();
  try { (JSON.parse(localStorage.getItem(favKey)) || []).forEach(function (id) { favs.add(String(id)); }); } catch (e) {}

  var S = {
    cat: "all", sub: "root", search: "", diets: {},
    orderType: null, coupon: null, tipPct: 0,
    current: null, pQty: 1, pVariant: 0, pMods: {}, pExtras: {},
    noteKey: null,
    /* currency choice is restored at boot via the store-scoped mbCurrency helper
       (shared, per-store); the old em_cur/em_cur_rate sessionStorage keys are gone. */
    curCode: CFG.baseCurrency,
    curRate: 1,
  };

  /* ── money ────────────────────────────────────────────── */
  /* per-currency decimals: 3-decimal currencies (OMR/KWD/BHD/JOD/LYD/TND) must keep their fils */
  var CUR_DP_BASE = Number.isInteger(CFG.currencyDecimals) ? CFG.currencyDecimals : 2;
  /* bug batch 10 · 4: order arithmetic (coupon / tax / tip) at the store currency's minor unit — what the order stores (CFG.moneyDecimals); display keeps currencyDecimals */
  var MONEY_DP = Number.isInteger(CFG.moneyDecimals) ? CFG.moneyDecimals : CUR_DP_BASE;
  var CUR_DP_MAP = CFG.decimalsByCode || {};
  /* decimals follow the DISPLAYED currency: the store's own from the server, any
     other from the map (2 unless it is a 3-decimal currency). */
  function curDp(code) {
    if (code === CFG.baseCurrency) return CUR_DP_BASE;
    var dp = CUR_DP_MAP[code];
    return dp == null ? 2 : dp;
  }

  function money(base) {
    var v = (Number(base) || 0) * S.curRate;
    var str = v.toFixed(curDp(S.curCode));
    if (S.curCode !== CFG.baseCurrency) return "≈ " + S.curCode + " " + str;
    return CFG.currencyPos === "1" ? CFG.currencySign + (CFG.currencyGap || "") + str : str + (CFG.currencyGap || "") + CFG.currencySign;
  }

  /* ── items ────────────────────────────────────────────── */
  var ITEMS = Object.keys(CFG.items).map(function (k) { return CFG.items[k]; });
  function item(id) { return CFG.items[id]; }
  var FALLBACK = CFG.defaultImg || "";
  function imgOf(i) { return (i && i.img) ? i.img : FALLBACK; }
  var IMG_ERR = FALLBACK ? ' data-csp-error="fallback" data-csp-fallback="' + esc(FALLBACK) + '"' : "";
  /* main image + any gallery images, deduped — drives the product gallery */
  function itemImgs(i) {
    var g = (i.gallery && i.gallery.length) ? i.gallery.slice() : [];
    if (i.img && g.indexOf(i.img) === -1) g.unshift(i.img);
    return g.length ? g : [imgOf(i)];
  }

  function isSoldOut(i) {
    if (!i) return false;
    if (i.is_sold_out) return true;
    if (i.track_inventory && i.stock_qty != null && Number(i.stock_qty) <= 0) return true;
    return false;
  }
  /* PARITY-RUN T5: availability is resolved by the SERVER and shipped as
     `available_now`. The old body here re-derived it in the browser and got it
     wrong twice: it parsed `available_days` with .map(Number) while the server
     has always stored 3-letter codes ("mon,wed"), so ANY item with days set was
     permanently hidden; and it used the visitor's clock, so a tourist's phone
     closed a Riyadh kitchen on Berlin time. The server answer also folds in the
     item's CATEGORY window and the order-type scope, which a card payload
     cannot see. Falls back to visible when the flag is absent. */
  function isAvailableNow(i) {
    return i.available_now !== false;
  }
  function hasChoices(i) {
    return (i.variants && i.variants.length) || (i.optionGroups && i.optionGroups.length);
  }
  function reqGroups(i) { return (i.optionGroups || []).filter(function (g) { return g.required || (g.max === 1); }); }

  /* pricing: variant absolute; mods + extras additive */
  function unitPrice(i, variantId, mods, extras) {
    var p = Number(i.price) || 0;
    if (variantId) {
      var v = (i.variants || []).filter(function (x) { return x.id === variantId; })[0];
      if (v) p = Number(v.price) || 0;
    }
    (mods || []).forEach(function (m) { p += Number(m.price) || 0; });
    (extras || []).forEach(function (e) { p += Number(e.price) || 0; });
    return p;
  }
  function lineLabel(l) {
    var i = item(l.id); if (!i) return "";
    var parts = [];
    if (l.variantLabel) parts.push(l.variantLabel);
    (l.mods || []).forEach(function (m) { parts.push(m.label); });
    (l.extras || []).forEach(function (e) { parts.push("+ " + e.title); });
    return parts.join(" · ");
  }
  function subtotal() {
    /* Combos ride in the subtotal: the server taxes them, min-order counts them,
       and the coupon endpoint is quoted the same base the order will carry. */
    return cart.reduce(function (s, l) {
      var i = item(l.id); if (!i) return s;
      return s + unitPrice(i, l.variantId, l.mods, l.extras) * l.qty;
    }, 0) + comboTotal();
  }
  /* The cart as submitOrder's `items` — also posted to the coupon check (bug batch 7 · 4). */
  function orderLines() {
    return cart.map(function (l) {
      return {
        id: l.id, quantity: l.qty, variants: l.variantId || 0,
        /* The per-line kitchen note. This was MISSING: the note sheet wrote
           `line.note`, toasted "Note saved" and the note then died in localStorage
           — the customer was told the kitchen had it and the kitchen never did.
           The server has always read it (`order_note` at StoreController ~2144). */
        order_note: l.note || "",
        extras: (l.extras || []).map(function (e) { return { id: e.id }; }),
        mods: (l.mods || []).map(function (m) { return { groupId: m.groupId, optIndex: m.optIndex }; }),
      };
    });
  }
  /* Bug batch 7 · 4: buy-X-get-Y is priced by the server on the cart LINES (the discount the order will get);
     the quote is refreshed whenever the cart or the order type changes. */
  function couponBody(code) {
    return JSON.stringify({ code: code, subtotal: subtotal(), items: orderLines(), "ordering-type": S.orderType || "" });
  }
  function couponSig() { return JSON.stringify([orderLines(), S.orderType || ""]); }
  function bxgyDiscount() {
    var c = S.coupon, sig = couponSig();
    if (c.sig !== sig) {
      c.sig = sig;
      fetch(CFG.endpoints.coupon, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf },
        body: couponBody(c.code),
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (S.coupon !== c || c.sig !== sig) return;
        c.discount = res && res.success ? Number(res.discount) || 0 : 0;
        renderTotals(); renderCheckout();
      }).catch(function () {});
    }
    return Number(c.discount) || 0;
  }
  function cartCount() { return cart.reduce(function (n, l) { return n + l.qty; }, 0); }
  function writeCart() { localStorage.setItem(cartKey, JSON.stringify(cart)); }
  function writeFavs() { localStorage.setItem(favKey, JSON.stringify(Array.prototype.slice.call(favs))); }

  /* COMBOS-V2 — the shared combos partial (_partials/combos.blade.php) keeps its
     own basket and publishes display state on window.mbCombos {count,total,lines}.
     The server prices combo lines into the ORDER subtotal (and taxes them like any
     other line), so the quote must fold them in too — QUOTED == CHARGED. Quantity
     edits stay in the combo sheet; here they render read-only. */
  function comboCount() { return (window.mbCombos && window.mbCombos.count) || 0; }
  function comboTotal() { return (window.mbCombos && window.mbCombos.total) || 0; }
  function comboLines() { return (window.mbCombos && window.mbCombos.lines) || []; }

  /* percent-of-money rounded to the store currency's decimals.
     PCT_F is 1 on 2-decimal stores, so this stays byte-identical there; on a
     3-decimal store it rounds to fils instead of flattening them to cents. */
  var PCT_F = Math.pow(10, MONEY_DP - 2);
  var PCT_D = 100 * PCT_F;
  /* M16: delegates to the shared money core (PHP-exact rounding). */
  function pctOf(amount, pct) { return mbMoney.pct(amount, pct, MONEY_DP); }

  /* TOTALS CONTRACT — mirrors Order::computeTax() in core/app/Models/Order.php.
     Two modes: 'fixed' is a FLAT amount, 'percentage' is a rate on the subtotal.
     Same guard as the server: a non-positive charge or subtotal is exactly 0. */
  function computeTax(sub) {
    var charge = Number(CFG.taxCharge) || 0;
    if (charge <= 0 || sub <= 0) return 0;
    if (CFG.taxType === "fixed") return mbMoney.round(charge, MONEY_DP);
    return pctOf(sub, charge);
  }

  /* TOTALS CONTRACT — mirrors Order::getTotalAttribute().
     The two things that must not drift:
       1. TAX IS COMPUTED ON THE RAW SUBTOTAL, and the discount is subtracted LAST.
          Taxing (subtotal - discount) under-quotes every coupon order — on this
          lane's basket it quoted $21.85 against a charged $21.95.
       2. The tip that is QUOTED here is the tip that is POSTed (see placeOrder),
          rounded once, so the customer's number and the server's agree. */
  function totals() {
    var sub = subtotal();
    var disc = 0;
    if (S.coupon) {
      disc = S.coupon.coupon_type === "percentage"
        ? pctOf(sub, S.coupon.percentage)
        : S.coupon.coupon_type === "bogo" ? bxgyDiscount() : Number(S.coupon.percentage) || 0;
      if (disc > sub) disc = sub;
    }
    var tax = computeTax(sub);
    var del = S.orderType === "delivery" && (cart.length || comboCount()) ? CFG.deliveryCharge : 0;
    var tip = S.tipPct ? pctOf(sub, S.tipPct) : 0;
    return { sub: sub, disc: disc, tax: tax, del: del, tip: tip, total: sub + del + tax + tip - disc };
  }

  /* ── ember particles ──────────────────────────────────── */
  function spawnEmbers(elId, n) {
    var box = document.getElementById(elId);
    if (!box) return;
    var out = [];
    for (var k = 0; k < n; k++) {
      var x = (6 + Math.random() * 88).toFixed(1);
      var d = (2.8 + Math.random() * 2.6).toFixed(2);
      var dl = (Math.random() * 3.4).toFixed(2);
      var s = (3 + Math.random() * 4).toFixed(1);
      var dx = ((Math.random() * 2 - 1) * 34).toFixed(0);
      out.push('<i style="--x:' + x + '%;--d:' + d + 's;--dl:' + dl + 's;--s:' + s + 'px;--dx:' + dx + 'px"></i>');
    }
    box.innerHTML = out.join("");
  }

  /* ── router ───────────────────────────────────────────── */
  var stack = [];
  var ROOTS = ["home", "profile", "favs"];
  var active = "home";

  /* Orders / Offers / Profile are one view (data-view="profile") with a segmented
     sub-switcher (#acctSeg). acctTab() toggles the sub-panels; the sub-tab is
     remembered so re-opening Profile lands where you left it. */
  var acctActive = "orders";
  function acctTab(name) {
    acctActive = name;
    $$("#acctSeg [data-acct]").forEach(function (b) { b.classList.toggle("is-on", b.dataset.acct === name); });
    $$('.acct-panel').forEach(function (p) { p.hidden = p.getAttribute("data-acct-panel") !== name; });
    if (name === "offers") renderOffers();
    var host = $('.view[data-view="profile"] .view-scroll'); if (host) host.scrollTop = 0;
  }
  function show(name, opts) {
    opts = opts || {};
    if (name === active) return;
    var from = $('.view[data-view="' + active + '"]'), to = $('.view[data-view="' + name + '"]');
    if (!to) return;
    var isRoot = ROOTS.indexOf(name) !== -1;
    if (opts.push !== false && !isRoot) {
      stack.push(active);
      /* Mirror the internal stack into browser history so the phone's back
         button walks product → home instead of leaving the site. */
      if (!opts.fromPop) { try { history.pushState({ em: 1 }, ""); } catch (e) {} }
    }
    if (isRoot) stack.length = 0;
    $$(".view").forEach(function (v) { v.classList.remove("is-under"); });
    if (!isRoot && from) from.classList.add("is-under");
    if (from) from.classList.remove("is-active");
    to.classList.add("is-active");
    var sc = to.querySelector(".view-scroll");
    if (sc) sc.scrollTop = 0;
    active = name;
    $("#dock").classList.toggle("is-hidden", !isRoot);
    $$(".tab").forEach(function (b) { b.classList.toggle("is-on", b.dataset.tab === name); });
    if (name === "cart") renderCart();
    if (name === "favs") renderFavs();
    if (name === "profile") acctTab(acctActive);
    if (name === "checkout") renderCheckout();
  }
  function internalBack() { show(stack.pop() || "home", { push: false, fromPop: true }); }
  function back() {
    /* UI back buttons go through history when we pushed a state, so the
       browser's own back stack stays in step with what is on screen. */
    if (stack.length && history.state && history.state.em) { history.back(); return; }
    internalBack();
  }
  window.addEventListener("popstate", function () {
    var s = root.querySelector(".sheet.is-open");
    if (s) { sheet(s.id, false); try { history.pushState({ em: 1 }, ""); } catch (e) {} return; }
    var ov = $("#searchOverlay");
    if (ov && !ov.hidden) { closeSearch(); try { history.pushState({ em: 1 }, ""); } catch (e) {} return; }
    if (stack.length) internalBack();
    else if (active !== "home") show("home", { push: false, fromPop: true });
  });

  /* ── sheets / toast ───────────────────────────────────── */
  var openSheetId = null;
  var hideTimers = {};
  function sheet(id, open) {
    var s = document.getElementById(id), scrim = $("#scrim");
    if (!s) return;
    if (open !== false) {
      if (openSheetId && openSheetId !== id) sheet(openSheetId, false);
      clearTimeout(hideTimers[id]);
      s.hidden = false; scrim.hidden = false;
      requestAnimationFrame(function () { s.classList.add("is-open"); scrim.classList.add("is-open"); });
      openSheetId = id;
    } else {
      s.classList.remove("is-open"); scrim.classList.remove("is-open");
      clearTimeout(hideTimers[id]);
      hideTimers[id] = setTimeout(function () { s.hidden = true; if (!openSheetId || openSheetId === id) scrim.hidden = true; }, 420);
      if (openSheetId === id) openSheetId = null;
    }
  }
  function closeAnySheet() {
    var id = openSheetId || (root.querySelector(".sheet.is-open") || {}).id;
    if (id) sheet(id, false);
  }
  $("#scrim").addEventListener("click", closeAnySheet);
  $$(".sheet-grab").forEach(function (g) {
    g.addEventListener("click", function () { var s = g.closest(".sheet"); if (s) sheet(s.id, false); });
  });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") { closeAnySheet(); if (!$("#searchOverlay").hidden) closeSearch(); } });

  var toastTimer;
  function toast(msg) {
    var el = $("#toast");
    el.innerHTML = ICONS.flame + "<span>" + esc(msg) + "</span>";
    el.classList.add("is-on");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.classList.remove("is-on"); }, 2200);
  }
  /* Exposed so the shared reorder-pickup helper (loaded by index.blade.php
     after script.js) can route its "skipped these items" message through
     ember's toast — same component, same animation. */
  window.mbToast = toast;

  /* Share this menu (profile row) — native row, not the floating pill.
     Web Share where supported, else copy the link + toast. */
  (function () {
    var sb = document.getElementById("mbShareRow");
    if (!sb) return;
    function copied() { toast(sb.getAttribute("data-copied") || "Link copied"); }
    function fallbackCopy() {
      try {
        var ta = document.createElement("textarea");
        ta.value = location.href; ta.setAttribute("readonly", "");
        ta.style.position = "absolute"; ta.style.left = "-9999px";
        document.body.appendChild(ta); ta.select();
        document.execCommand("copy"); document.body.removeChild(ta); copied();
      } catch (e) {}
    }
    sb.addEventListener("click", function () {
      var data = { title: document.title, url: location.href };
      if (navigator.share) { navigator.share(data).catch(function () {}); return; }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(location.href).then(copied).catch(fallbackCopy);
      } else { fallbackCopy(); }
    });
  })();

  function itemEvent(id, type) {
    if (!CFG.endpoints.itemEvent) return;
    try {
      fetch(CFG.endpoints.itemEvent, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf },
        body: JSON.stringify({ menu_id: id, type: type }),
      }).catch(function () {});
    } catch (e) {}
  }

  /* ── card templates ───────────────────────────────────── */
  function chip(i) {
    if (isSoldOut(i)) return '<span class="chip-soldout">' + esc(t("sold_out")) + "</span>";
    if (i.is_daily_special) return '<span class="chip-daily">'+ICONS.bolt+" " + esc(t("daily")) + "</span>";
    if (i.spice_level >= 2) return '<span class="chip-spicy">'+ICONS.chilli+" " + esc(t("spicy")) + "</span>";
    return "";
  }
  function metaBits(i) {
    var bits = [];
    if (i.rating) bits.push("★ " + i.rating);
    if (i.prep_time) bits.push(i.prep_time + " " + t("min"));
    if (i.calories) bits.push(i.calories + " " + t("kcal"));
    return bits;
  }
  /* PARITY-RUN T5 — happy-hour price display. `i.price` is ALREADY the price the
     server will charge; `i.original_price` is what it was before the schedule,
     present only while a schedule is live. Purely presentational: the cart and
     the order path both take their number from the server, never from here. */
  function hhWasHTML(i) {
    return i.original_price ? '<s style="opacity:.55;font-weight:400;margin-inline-end:.35em">' + money(i.original_price) + "</s>" : "";
  }
  function hhBadgeHTML(i) {
    /* styling lives in CSS (.hh-badge) so it inherits the flame palette and can be
       tuned per theme, instead of the old hard-coded amber inline block. */
    return i.schedule_label
      ? '<span class="hh-badge">' + esc(i.schedule_label) + "</span>"
      : "";
  }
  function pcardHTML(i, idx) {
    var fav = favs.has(String(i.id)), so = isSoldOut(i);
    var meta = [];
    if (i.rating) meta.push("★ " + i.rating);
    if (i.prep_time) meta.push(i.prep_time + " " + t("min"));
    return '' +
      '<a class="pcard' + (so ? " is-soldout" : "") + '" href="' + esc(itemHref(i.id)) + '" data-open="' + i.id + '" style="animation-delay:' + Math.min(idx * 50, 350) + 'ms;color:inherit;text-decoration:none">' +
        '<span class="pcard-imgwrap"><img src="' + esc(imgOf(i)) + '" alt=""' + IMG_ERR + ' loading="lazy">' +
          '<span class="card-fav ' + (fav ? "is-on" : "") + '" data-fav="' + i.id + '">' + ICONS.heart + "</span>" + hhBadgeHTML(i) + "</span>" +
        chip(i) +
        "<b>" + esc(i.name) + "</b>" +
        '<span class="pcard-meta">' + esc(meta.join(" · ")) + "</span>" +
        '<span class="pcard-foot"><span class="pcard-price">' + hhWasHTML(i) + money(i.price) + "</span>" +
          (so ? "" : '<span class="card-add" data-add="' + i.id + '">' + ICONS.plus + "</span>") +
        "</span>" +
      "</a>";
  }
  function mrowHTML(i, idx) {
    var fav = favs.has(String(i.id)), so = isSoldOut(i);
    return '' +
      '<a class="mrow' + (so ? " is-soldout" : "") + '" href="' + esc(itemHref(i.id)) + '" data-open="' + i.id + '" style="animation-delay:' + Math.min(idx * 45, 320) + 'ms;color:inherit;text-decoration:none">' +
        '<span class="mrow-imgwrap"><img src="' + esc(imgOf(i)) + '" alt=""' + IMG_ERR + ' loading="lazy"></span>' +
        chip(i) +
        '<span class="mrow-body"><b>' + esc(i.name) + "</b>" +
          '<span class="mrow-meta">' + metaBits(i).map(function (b, bi) { return '<span' + (bi === 0 && i.rating ? ' class="star"' : "") + ">" + esc(b) + "</span>"; }).join("") + "</span>" +
          '<span class="mrow-price">' + hhWasHTML(i) + money(i.price) + "</span> " + hhBadgeHTML(i) + "</span>" +
        '<span class="mrow-side"><span class="card-fav ' + (fav ? "is-on" : "") + '" data-fav="' + i.id + '">' + ICONS.heart + "</span>" +
          (so ? "" : '<span class="card-add" data-add="' + i.id + '">' + ICONS.plus + "</span>") + "</span>" +
      "</a>";
  }

  /* ── home render ──────────────────────────────────────── */
  function renderPopular() {
    var rail = $("#popRail"); if (!rail) return;
    var pop = ITEMS.filter(function (i) { return i.is_popular; });
    if (!pop.length) pop = ITEMS.slice(0, 8);
    rail.innerHTML = pop.map(pcardHTML).join("");
  }
  /* a chip covers its own id plus any subcategory ids beneath it — a parent whose
     items all live in children would otherwise filter to nothing */
  function inCat(i) {
    var c = String(i.cat), kids = (CFG.catKids && CFG.catKids[String(S.cat)]) || [];
    // parent chip matches by parent OR any child; sub-chip then narrows to one real category
    if (c !== String(S.cat) && kids.indexOf(c) === -1) return false;
    if (S.sub !== "root" && c !== String(S.sub)) return false;
    return true;
  }
  /* Reveal the active parent's subcategory chip row (if it has one), reset its
     selection to "All", and hide every other group. Called on each parent change. */
  function syncSubChips() {
    var wrap = $("#subChips");
    if (!wrap) return;
    var shown = null;
    $$(".subgroup", wrap).forEach(function (g) {
      var match = S.cat !== "all" && g.dataset.parent === String(S.cat);
      g.hidden = !match;
      if (match) {
        shown = g;
        $$(".subchip", g).forEach(function (c) { c.classList.toggle("is-on", c.dataset.sub === "root"); });
      }
    });
    wrap.hidden = !shown;
  }
  function renderMenu() {
    var list = $("#menuList"); if (!list) return;
    var arr = S.cat === "all" ? ITEMS : ITEMS.filter(inCat);
    list.innerHTML = arr.map(mrowHTML).join("");
    $("#menuEmpty").hidden = arr.length > 0;
    var title = $("#menuTitle");
    if (title) {
      if (S.cat === "all") { title.textContent = t("menu"); }
      else {
        var btn = $('.cat[data-cat="' + S.cat + '"] small');
        var lbl = btn ? btn.textContent : t("menu");
        if (S.sub && S.sub !== "root") {
          var scEl = $('#subChips .subchip[data-sub="' + S.sub + '"]');
          if (scEl) lbl = scEl.textContent.trim();
        }
        title.textContent = lbl;
      }
    }
  }
  function renderFavs() {
    var el = $("#favList"); if (!el) return;
    var arr = ITEMS.filter(function (i) { return favs.has(String(i.id)); });
    el.innerHTML = arr.map(mrowHTML).join("");
    $("#favEmpty").hidden = arr.length > 0;
  }

  /* ── search overlay ───────────────────────────────────── */
  function matchesDiets(i) {
    if (S.diets.vegan && !i.is_vegan) return false;
    if (S.diets.halal && !i.is_halal) return false;
    if (S.diets.gf && !i.is_gluten_free) return false;
    if (S.diets.spicy && !(i.spice_level >= 1)) return false;
    return true;
  }
  function renderSearch() {
    var q = S.search.trim().toLowerCase();
    var arr = ITEMS.filter(function (i) {
      var hay = (i.name + " " + (i.desc || "")).toLowerCase();
      return (!q || hay.indexOf(q) !== -1) && matchesDiets(i);
    });
    $("#searchResults").innerHTML = arr.map(mrowHTML).join("");
    $("#searchEmpty").hidden = arr.length > 0;
    var anyDiet = !!(S.diets.vegan || S.diets.halal || S.diets.gf || S.diets.spicy);
    /* Say what actually failed, and offer the escape that matches it. */
    var msg = $("#searchEmptyMsg"), clr = $("#clearFilters");
    if (msg) msg.textContent = anyDiet ? t("no_filter_results") : t("no_search_results");
    if (clr) clr.hidden = !anyDiet;
    var dot = $("#filterDot");
    if (dot) dot.hidden = !anyDiet;
  }
  /* Header globe: language sheet (rendered only for multi-language stores). */
  on("langBtn", "click", function () { if (document.getElementById("langSheet")) sheet("langSheet"); });
  /* Header catering button: opens the dedicated catering & events sheet. */
  on("cvCateringBtn", "click", function () { sheet("cvCateringSheet"); });
  on("clearFilters", "click", function () {
    S.diets = {};
    $$("#searchOverlay [data-diet]").forEach(function (b) {
      b.classList.remove("is-on");
      b.setAttribute("aria-pressed", "false");
    });
    renderSearch();
  });
  function openSearch() {
    var ov = $("#searchOverlay");
    ov.hidden = false;
    requestAnimationFrame(function () { ov.classList.add("is-open"); });
    renderSearch();
    setTimeout(function () { var inp = $("#searchInput"); if (inp) inp.focus(); }, 120);
  }
  function closeSearch() {
    var ov = $("#searchOverlay");
    ov.classList.remove("is-open");
    setTimeout(function () { ov.hidden = true; }, 300);
  }

  /* ── item detail ──────────────────────────────────────── */
  function openProduct(id) {
    var i = item(id);
    if (!i) return;
    if (!S.current || S.current.id !== i.id) {
      S.pQty = 1;
      S.pVariant = (i.variants && i.variants.length) ? i.variants[0].id : 0;
      S.pMods = {};
      (i.optionGroups || []).forEach(function (g) {
        if ((g.required || g.max === 1) && g.options && g.options.length) {
          S.pMods[g.id] = { groupId: g.id, optIndex: g.options[0].i, label: g.options[0].label, price: g.options[0].price };
        }
      });
      S.pExtras = {};
    }
    S.current = i;
    var img = $("#pImg");
    img.src = imgOf(i);
    img.onerror = function () { this.onerror = null; if (FALLBACK) this.src = FALLBACK; };
    /* per-item gallery: thumbnail strip that swaps the hero image */
    var imgs = itemImgs(i), gal = $("#pGallery");
    if (gal) {
      if (imgs.length > 1) {
        img.src = imgs[0];
        gal.hidden = false;
        gal.innerHTML = imgs.map(function (g, gi) {
          return '<button class="p-thumb ' + (gi === 0 ? "is-on" : "") + '" data-galthumb="' + esc(g) + '" type="button"><img src="' + esc(g) + '" alt=""' + IMG_ERR + "></button>";
        }).join("");
      } else { gal.hidden = true; gal.innerHTML = ""; }
    }
    $("#pFav").classList.toggle("is-on", favs.has(String(id)));
    var so = isSoldOut(i);
    $("#pSpice").hidden = !(i.spice_level >= 1) || so;
    if (i.spice_level >= 1) $("#pSpice").innerHTML = new Array(Math.min(3, Math.max(1, i.spice_level)) + 1).join(ICONS.chilli) + " <span>" + esc(t("spicy")) + "</span>";
    $("#pSoldout").hidden = !so;
    $("#pTitle").textContent = i.name;
    $("#pRate").innerHTML = i.rating ? "★ " + i.rating + (i.rating_count ? " <small>(" + i.rating_count + ")</small>" : "") : "";
    $("#pDesc").textContent = i.desc || "";

    var tags = [];
    if (i.prep_time) tags.push('<span class="tag">'+ICONS.clock+" " + i.prep_time + " " + esc(t("min")) + "</span>");
    if (i.calories) tags.push('<span class="tag">'+ICONS.flame2+" " + i.calories + " " + esc(t("kcal")) + "</span>");
    if (i.is_vegan) tags.push('<span class="tag">'+ICONS.leaf+" " + esc(t("d_vegan")) + "</span>");
    if (i.is_halal) tags.push('<span class="tag">'+ICONS.moon+" " + esc(t("d_halal")) + "</span>");
    if (i.is_gluten_free) tags.push('<span class="tag">'+ICONS.wheat+" " + esc(t("d_gf")) + "</span>");
    (i.allergies || []).forEach(function (a) { tags.push('<span class="tag">'+ICONS.warn+" " + esc(a.title) + "</span>"); });
    $("#pTags").innerHTML = tags.join("");

    var nutri = [];
    if (i.calories) nutri.push('<span class="nutri"><b>' + i.calories + "</b><small>" + esc(t("kcal")) + "</small></span>");
    if (i.protein != null) nutri.push('<span class="nutri"><b>' + i.protein + "g</b><small>" + esc(t("protein")) + "</small></span>");
    if (i.carbs != null) nutri.push('<span class="nutri"><b>' + i.carbs + "g</b><small>" + esc(t("carbs")) + "</small></span>");
    if (i.fat != null) nutri.push('<span class="nutri"><b>' + i.fat + "g</b><small>" + esc(t("fat")) + "</small></span>");
    var nu = $("#pNutri");
    nu.innerHTML = nutri.join("");
    // Nutrition facts open BY DEFAULT when the item has them (button starts is-on);
    // the pGridBtn toggle below still collapses/expands. No nutrition → panel and
    // button both stay hidden.
    nu.hidden = !nutri.length;
    $("#pGridBtn").classList.toggle("is-on", nutri.length > 0);
    $("#pGridBtn").style.display = nutri.length ? "" : "none";

    renderSizes(); renderGroups(); renderTogs();

    /* product reviews — approved MenuReview rows shipped in the item payload */
    var pr = $("#pReviews");
    if (pr) {
      pr.innerHTML = window.__mbItemReviewsOn === false ? "" : (i.reviewList || []).length
        ? '<h3 class="p-sec">' + esc(t("reviews_title")) + "</h3>" +
          i.reviewList.map(function (review) {
            var stars = Math.max(1, Math.min(5, Number(review.stars) || 0));
            return '<article class="review-card gl"><b>' + esc(review.name) + '</b><span class="review-stars">' +
              "★".repeat(stars) + '<span class="review-stars-dim">' + "★".repeat(5 - stars) + "</span>" +
              "</span>" + (review.body ? "<p>" + esc(review.body) + "</p>" : "") + "</article>";
          }).join("")
        : '<h3 class="p-sec">' + esc(t("reviews_title")) + '</h3><p class="reviews-empty">' + esc(t("reviews_empty")) + "</p>";
    }

    /* inline "Rate this dish" quick-rate — rebuilt each open (stars reset), sits
       just above the reviews section. Tapping a star opens the shared review
       write-sheet preselected to that many stars (window.mbItemReviews.open). */
    if (window.__mbItemReviewsOn !== false && pr && pr.parentNode) {
      var rr = document.getElementById("rateRow");
      if (!rr) { rr = document.createElement("div"); rr.className = "rate-row"; rr.id = "rateRow"; pr.parentNode.insertBefore(rr, pr); }
      rr.innerHTML = '<span class="rate-label">' + esc(t("rate_dish")) + '</span><span class="rate-stars" id="rateStars">' +
        [1, 2, 3, 4, 5].map(function (n) { return '<button data-rate="' + n + '" type="button" aria-label="' + n + '"><svg class="ic" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg></button>'; }).join("") + "</span>";
    }

    var hasCustom = (i.variants && i.variants.length) || (i.optionGroups && i.optionGroups.length) || (i.extras && i.extras.length);
    $("#pCustomize").hidden = !hasCustom;

    updatePCta(false);
    var add = $("#addToCart");
    if (add) { add.disabled = so; }
    spawnEmbers("heroEmbers", 14);
    itemEvent(id, "view");
    show("product");
  }
  function renderSizes() {
    var i = S.current, wrap = $("#pSizeWrap"), row = $("#sizeRow");
    if (!i.variants || !i.variants.length) { wrap.hidden = true; row.innerHTML = ""; return; }
    /* The owner's own group name ("Style"), not a hardcoded "SIZE". */
    var lbl = $("#pSizeLabel");
    if (lbl) lbl.textContent = i.variantTitle || t("options");
    wrap.hidden = false;
    row.innerHTML = i.variants.map(function (v) {
      var extra = v.price - i.price;
      return '<button class="szchip ' + (v.id === S.pVariant ? "is-on" : "") + '" data-size="' + v.id + '">' + esc(v.label) +
        (extra > 0 ? '<small>+' + money(extra) + "</small>" : "") + "</button>";
    }).join("");
  }
  function renderGroups() {
    var i = S.current, wrap = $("#pGroups");
    var groups = (i.optionGroups || []).filter(function (g) { return g.options && g.options.length; });
    if (!groups.length) { wrap.innerHTML = ""; return; }
    wrap.innerHTML = groups.map(function (g) {
      var single = g.required || g.max === 1;
      if (single) {
        var chosen = S.pMods[g.id] ? S.pMods[g.id].optIndex : null;
        return '<p class="p-lbl">' + esc(g.title) + '<span class="opt-req">' + esc(t("required")) + '</span></p>' +
          '<div class="sizes" data-group="' + g.id + '">' + g.options.map(function (o) {
            return '<button class="szchip ' + (o.i === chosen ? "is-on" : "") + '" data-opt="' + o.i + '" data-gid="' + g.id + '">' + esc(o.label) +
              (o.price > 0 ? '<small>+' + money(o.price) + "</small>" : "") + "</button>";
          }).join("") + "</div>";
      }
      return '<p class="p-lbl">' + esc(g.title) + "</p><div class='togs' style='padding-top:0'>" + g.options.map(function (o) {
        var on = !!(S.pMods["m" + g.id + "_" + o.i]);
        return '<button class="tgl-row ' + (on ? "is-on" : "") + '" data-mtog="' + g.id + "_" + o.i + '" data-gid="' + g.id + '" data-oi="' + o.i + '">' +
          '<span class="tgl-info"><b>' + esc(o.label) + "</b>" + (o.price > 0 ? '<small>+' + money(o.price) + "</small>" : "") + "</span>" +
          '<span class="tgl"><i></i></span></button>';
      }).join("") + "</div>";
    }).join("");
  }
  function renderTogs() {
    var i = S.current, list = $("#togList");
    if (!i.extras || !i.extras.length) { list.innerHTML = ""; return; }
    list.innerHTML = i.extras.map(function (x, xi) {
      var on = !!S.pExtras[xi];
      return '<button class="tgl-row ' + (on ? "is-on" : "") + '" data-tog="' + xi + '">' +
        (x.img ? '<img class="tgl-img" src="' + esc(x.img) + '" alt=""' + IMG_ERR + ' loading="lazy">' : "") +
        '<span class="tgl-info"><b>' + esc(x.title) + "</b><small>+" + money(x.price) + "</small></span>" +
        '<span class="tgl"><i></i></span></button>';
    }).join("");
  }
  function currentMods() {
    var mods = [];
    Object.keys(S.pMods).forEach(function (k) {
      var m = S.pMods[k];
      if (m && typeof m === "object") mods.push(m);
    });
    return mods;
  }
  function currentExtras() {
    var i = S.current, ex = [];
    Object.keys(S.pExtras).forEach(function (xi) {
      if (S.pExtras[xi]) { var x = i.extras[xi]; if (x) ex.push({ id: x.id, title: x.title, price: x.price }); }
    });
    return ex;
  }
  function currentUnit() { return unitPrice(S.current, S.pVariant, currentMods(), currentExtras()); }
  function morph(el) { if (!el) return; el.classList.remove("morph"); void el.offsetWidth; el.classList.add("morph"); }
  function updatePCta(animate) {
    var unit = currentUnit();
    $("#pQtyVal").textContent = S.pQty;
    $("#pPrice").textContent = money(unit);
    var total = $("#pTotal");
    if (total) total.textContent = money(unit * S.pQty);
    if (animate) { morph(total); morph($("#pPrice")); }
  }

  /* ── cart ─────────────────────────────────────────────── */
  function lineKey(id, variantId, mods, extras) {
    var m = (mods || []).map(function (x) { return x.groupId + ":" + x.optIndex; }).sort().join(",");
    var e = (extras || []).map(function (x) { return x.id; }).sort().join(",");
    return id + "|" + (variantId || 0) + "|" + m + "|" + e;
  }
  function addToCart(id, variantId, variantLabel, mods, extras, qty, fromEl, opts) {
    opts = opts || {};
    var i = item(id);
    if (isSoldOut(i)) { toast(t("sold_out")); return; }
    var key = lineKey(id, variantId, mods, extras);
    var line = cart.filter(function (l) { return l.key === key; })[0];
    if (line) line.qty += qty;
    else cart.push({ key: key, id: id, qty: qty, variantId: variantId || 0, variantLabel: variantLabel || "", mods: mods || [], extras: extras || [], note: "" });
    writeCart();
    syncPill(true);
    if (!opts.silent) toast(i.name + " — " + t("added"));
    itemEvent(id, "add_to_cart");
    if (fromEl) flyToPill(fromEl);
  }
  /* ── account-page reorder handoff (P6-301) ────────────────
     The account page (account.blade.php) writes the reorder payload to
     cv_reorder_<slug> and redirects here. The shared helper reads it back and
     calls __emberPickup for each line. We translate the choice-group selections
     (which carry labels) onto ember's flat `[{groupId, optIndex}]` mods shape
     and push the line ourselves (so the per-line note survives — addToCart
     resets it to ""). */
  window.__emberPickup = function (line) {
    var i = item(line.item_id);
    if (!i || isSoldOut(i)) return false;
    var R = window.JuwriReorder;
    var mods = R.modsFromOptions(i, line.options);
    if (R.requiredMissing(i, mods)) return false;
    var variantLabel = line.variant ? R.variantLabel(i, line.variant) : "";
    /* payload.extras is [{id,title,price}]; ember stores extras the same way */
    var extras = (line.extras || []).map(function (e) { return { id: Number(e.id), title: e.title, price: Number(e.price) || 0 }; });
    var key = lineKey(line.item_id, line.variant || 0, mods, extras);
    var existing = cart.filter(function (l) { return l.key === key; })[0];
    if (existing) { existing.qty += line.qty; }
    else { cart.push({ key: key, id: line.item_id, qty: line.qty, variantId: line.variant || 0, variantLabel: variantLabel, mods: mods, extras: extras, note: line.note || "" }); }
    writeCart();
    syncPill(true);
    toast(i.name + " \u2014 " + t("added"));
    return true;
  };

  function flyToPill(fromEl) {
    var src = fromEl.closest(".pcard, .mrow");
    var img = (src && src.querySelector("img")) || $("#pImg");
    var target = $("#cartPill");
    if (!img || !target || target.hidden) return;
    var a = img.getBoundingClientRect(), b = target.getBoundingClientRect();
    var lay = $("#flyLayer"), lr = lay.getBoundingClientRect();
    var ghost = document.createElement("img");
    ghost.src = img.src; ghost.className = "fly-img";
    ghost.style.left = (a.left - lr.left + a.width / 2 - 32) + "px";
    ghost.style.top = (a.top - lr.top + a.height / 2 - 32) + "px";
    lay.appendChild(ghost);
    requestAnimationFrame(function () {
      ghost.style.left = (b.left - lr.left + 24) + "px";
      ghost.style.top = (b.top - lr.top - 12) + "px";
      ghost.style.transform = "scale(.16)";
      ghost.style.opacity = ".25";
    });
    setTimeout(function () { ghost.remove(); }, 800);
  }
  function syncPill(bump) {
    var n = cartCount() + comboCount();
    /* product footer: a direct jump to the cart, shown once anything is in it */
    var pj = $("#pCartJump"); if (pj) { pj.hidden = n === 0; var pjc = $("#pCartJumpCount"); if (pjc) pjc.textContent = n; }
    var pill = $("#cartPill");
    if (!pill) return;
    pill.hidden = n === 0;
    $("#cpCount").textContent = n;
    $("#cpTotal").textContent = money(totals().total);
    if (bump && n) { pill.classList.remove("morph"); void pill.offsetWidth; pill.classList.add("morph"); }
  }

  /* COMBOS-V2 — read-only "Combos" group appended to the cart list and the
     checkout summary. Tapping a row reopens the combo sheet's basket view,
     which is where quantities are edited. */
  function comboGroupHTML(compact) {
    var lines = comboLines();
    if (!lines.length) return "";
    var h = '<div class="em-combo-group" data-combo-open style="border-top:1px dashed var(--line,#8883);padding-top:8px;margin-top:8px;cursor:pointer">';
    lines.forEach(function (L) {
      h += '<div class="ck-row ck-row--combo">' +
        '<div class="ck-mid"><b>' + esc(L.name) + "</b>" +
        (L.parts.length ? "<small>" + esc(L.parts.join(" · ")) + "</small>" : "") + "</div>" +
        '<span class="ck-qty">×' + L.qty + "</span>" +
        '<span class="ck-price">' + money(L.price) + "</span></div>";
    });
    if (!compact) h += '<small style="opacity:.65">' + esc(t("edit")) + " ›</small>";
    h += "</div>";
    return h;
  }
  document.addEventListener("click", function (e) {
    var g = e.target.closest && e.target.closest("[data-combo-open]");
    if (g && window.mbCombos && window.mbCombos.openSheet) window.mbCombos.openSheet("basket");
  });
  /* Re-quote whenever the combo basket changes (add/remove/qty in the sheet). */
  window.addEventListener("mb:combos-changed", function () {
    try {
      syncPill(true);
      renderTotals();
      if (active === "cart") renderCart();
      if (active === "checkout") renderCheckout();
    } catch (e) {}
  });

  function renderCart() {
    var has = cart.length > 0 || comboCount() > 0;
    $("#cartEmpty").hidden = has;
    var tools = $("#cartTools");
    if (tools) tools.style.display = has ? "" : "none";
    var cta = $("#cartCta");
    if (cta) cta.hidden = !has;
    /* "clear cart" only clears real item lines, so it hides on a combos-only bag */
    var clr = $("#clearCart"); if (clr) clr.style.visibility = cart.length ? "" : "hidden";
    $("#cartList").innerHTML = comboGroupHTML(false) + cart.map(function (l, li) {
      var i = item(l.id); if (!i) return "";
      var lbl = lineLabel(l);
      var unit = unitPrice(i, l.variantId, l.mods, l.extras);
      /* Happy-hour strike-through: original_price is the pre-schedule BASE
         price, so the pre-discount unit is unit + (original − current base). */
      var wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
        ? unit + (Number(i.original_price) - Number(i.price)) : 0;
      return '' +
        '<div class="cart-item" data-key="' + esc(l.key) + '" style="animation-delay:' + (li * 50) + 'ms">' +
          '<button class="ci-im" data-open="' + i.id + '" type="button" aria-label="' + esc(i.name) + '"><img src="' + esc(imgOf(i)) + '" alt=""' + IMG_ERR + "></button>" +
          '<div><button class="ci-name" data-open="' + i.id + '" type="button">' + esc(i.name) + "</button>" +
            (lbl ? '<div class="ci-opts">' + esc(lbl) + "</div>" : "") +
            (l.note ? '<div class="ci-note">✎ ' + esc(l.note) + "</div>" : "") +
            '<div class="ci-row"><span class="ci-price">' + money(unit * l.qty) + "</span>" +
              (wasUnit ? '<del class="ci-was">' + money(wasUnit * l.qty) + "</del>" : "") +
              (l.qty > 1 ? '<small class="ci-unit">' + l.qty + " × " + money(unit) + "</small>" : "") +
              '<button class="ci-notebtn" data-note="' + esc(l.key) + '" type="button">' + ICONS.note + "<span>" + (l.note ? "✓" : "+") + "</span></button></div>" +
          "</div>" +
          '<div class="ci-side"><div class="ci-qty">' +
            '<button class="qty-btn" data-cq="-1" type="button" aria-label="−">' + ICONS.minus + "</button>" +
            "<output>" + l.qty + "</output>" +
            '<button class="qty-btn" data-cq="1" type="button" aria-label="+">' + ICONS.plus + "</button></div>" +
            '<button class="ci-remove" data-rm type="button" aria-label="' + esc(I.remove || "Remove") + '">' + ICONS.trash + "</button>" +
          "</div>" +
        "</div>";
    }).join("");
    renderOtFields(); renderTotals();
  }

  /* The owner's real prep window, appended to the order-type hint. Returns "" when
     the owner has not configured one — the hints used to promise "25–35 min" and
     "~15 min" unconditionally, which is a made-up commitment on most stores. */
  function etaSuffix() {
    if (!CFG.prepEta) return "";
    /* Tolerate either placeholder style: the shared lang entry uses {n}, but a
       locale that was hand-edited may carry :n. */
    return " · " + t("eta_suffix").replace(/\{n\}|:n/, CFG.prepEta);
  }
  function renderOtFields() {
    var f = $("#otFields");
    if (!f) return;
    var waiterRow = $("#waiterRow");
    if (waiterRow) waiterRow.hidden = S.orderType !== "on-table";
    var hint = "";
    if (S.orderType === "on-table") hint = t("choose_table") + etaSuffix();
    else if (S.orderType === "delivery") hint = t("delivery_hint") + etaSuffix();
    else if (S.orderType === "takeaway") hint = t("pickup_hint") + etaSuffix();
    else if (S.orderType === "room_service") hint = t("room_hint") + etaSuffix();
    if (S.orderType === "delivery") {
      /* Same autocomplete + use-my-location affordance as checkout — the field
         is rebuilt on every order-type switch, so it is re-wired right here. */
      var co = $("#coAddr");
      f.innerHTML = '<div class="addr-field" id="cartAddrField">' +
        '<input id="cartAddr" value="' + esc(co ? co.value : "") + '" placeholder="' + esc(t("addr_ph")) + '" autocomplete="off" autocorrect="off" spellcheck="false">' +
        '<button class="uselocbtn" id="cartUseLocation" type="button" aria-label="' + esc(t("use_location")) + '" title="' + esc(t("use_location")) + '">' + ICONS.loc + "</button>" +
        '<div class="addr-suggest" id="cartAddrSuggest" hidden></div></div>' +
        (hint ? '<p class="ot-hint">' + ICONS.check + "<span>" + esc(hint) + "</span></p>" : "");
      wireAddressField($("#cartAddr"), $("#cartAddrSuggest"), $("#cartUseLocation"), null, function (v) {
        var ci = $("#coAddr"); if (ci) ci.value = v;
      });
      return;
    }
    f.innerHTML = hint ? '<p class="ot-hint">' + ICONS.check + "<span>" + esc(hint) + "</span></p>" : "";
  }

  function totalsHTML() {
    var T = totals();
    var h = '<div class="tr"><span>' + esc(t("subtotal")) + "</span><b>" + money(T.sub) + "</b></div>";
    if (T.disc) h += '<div class="tr disc"><span>' + esc(t("discount")) + (S.coupon ? " (" + esc(S.coupon.code) + ")" : "") + "</span><b>−" + money(T.disc) + "</b></div>";
    if (T.tax) h += '<div class="tr"><span>' + esc(CFG.taxLabel || t("tax")) + "</span><b>" + money(T.tax) + "</b></div>";
    if (S.orderType === "delivery" && (cart.length || comboCount())) h += '<div class="tr"><span>' + esc(t("delivery_fee")) + "</span><b>" + (T.del ? money(T.del) : esc(t("freelbl"))) + "</b></div>";
    if (T.tip) h += '<div class="tr"><span>' + esc(t("tip_label")) + " (" + S.tipPct + "%)</span><b>" + money(T.tip) + "</b></div>";
    h += '<div class="tr grand"><span>' + esc(t("total")) + "</span><b>" + money(T.total) + "</b></div>";
    return h;
  }
  /* P3-13 (THEME-CONTRACT §5.2): the totals rows and the total are the server quote of the cart this checkout
     posts; totalsHTML() above is only the estimate shown until it answers. */
  function serverQuote() {
    if (!window.mbQuote || (!cart.length && !comboCount())) return;
    var boxes = [$("#cartTotals"), $("#coTotals")].filter(Boolean);
    window.mbQuote.update("cart", window.mbQuote.urlFrom(CFG.endpoints.submitOrder), {
      items: orderLines(),
      "ordering-type": S.orderType || "",
      coupon_code: S.coupon ? S.coupon.code : "",
      tip_amount: totals().tip || 0,
      branch_id: new URLSearchParams(window.location.search).get("branch_id") || ""
    }, function (q) {
      var h = window.mbQuote.rows(q).map(function (r) {
        return '<div class="tr' + (r.key === "discount" ? " disc" : "") + '"><span>' + esc(r.label) + "</span><b>" + esc(r.formatted) + "</b></div>";
      }).join("") + '<div class="tr grand"><span>' + esc(q.total_label || t("total")) + "</span><b>" + esc(q.total.formatted) + "</b></div>";
      boxes.forEach(function (b) { b.innerHTML = h; });
      [$("#cartCtaTotal"), $("#placeTotal")].forEach(function (el) { if (el) el.textContent = q.total.formatted; });
    }, boxes);
  }
  function renderTotals() {
    var box = $("#cartTotals");
    if (box) box.innerHTML = totalsHTML();
    var ct = $("#cartCtaTotal");
    if (ct) ct.textContent = money(totals().total);
    var mo = $("#minOrderMsg");
    if (mo) {
      if (CFG.minOrderValue > 0 && subtotal() < CFG.minOrderValue) {
        mo.hidden = false;
        mo.textContent = t("min_order") + " " + money(CFG.minOrderValue);
      } else mo.hidden = true;
    }
    syncPill(false);
    serverQuote();
  }

  /* ── checkout ─────────────────────────────────────────── */
  function renderCheckout() {
    var addr = $("#addrBlock");
    /* `hidden`, not an inline display style: the markup now ships these blocks
       hidden via the attribute (a `style=` attribute cannot be covered by the CSP
       nonce), and `.ember [hidden]{display:none!important}` would beat any inline
       `display:""` we set here — the delivery address would never appear again. */
    if (addr) addr.hidden = S.orderType !== "delivery";
    var tbl = $("#tableField");
    if (tbl) tbl.hidden = S.orderType !== "on-table";
    var ph = $("#phoneField");
    /* Room service is fulfilled in the guest's room, so like on-table it needs
       neither a phone number nor an address -- the room rides along on the hidden
       `room` field the shared hotel partial renders. */
    if (ph) ph.hidden = (S.orderType === "on-table" || S.orderType === "room_service");
    /* Order summary — what the customer is about to pay for, tap Edit → cart. */
    var ck = $("#ckItems");
    if (ck) {
      ck.hidden = cart.length === 0 && comboCount() === 0;
      ck.innerHTML = '<div class="ck-head"><h3>' + esc(t("your_order")) + '</h3><button class="linkbtn" id="ckEdit" type="button">' + esc(t("edit")) + "</button></div>" +
        cart.map(function (l) {
          var i = item(l.id); if (!i) return "";
          var unit = unitPrice(i, l.variantId, l.mods, l.extras);
          /* Happy-hour strike-through: original_price is the pre-schedule BASE
             price, so the pre-discount unit is unit + (original − current base). */
          var wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
            ? unit + (Number(i.original_price) - Number(i.price)) : 0;
          var lbl = lineLabel(l);
          return '<div class="ck-row">' +
            '<img src="' + esc(imgOf(i)) + '" alt=""' + IMG_ERR + ">" +
            '<div class="ck-mid"><b>' + esc(i.name) + "</b>" + (lbl ? "<small>" + esc(lbl) + "</small>" : "") + "</div>" +
            '<span class="ck-qty">×' + l.qty + "</span>" +
            '<span class="ck-price">' + money(unit * l.qty) + (wasUnit ? "<del>" + money(wasUnit * l.qty) + "</del>" : "") + "</span></div>";
        }).join("") + comboGroupHTML(true);
      var ed = $("#ckEdit"); if (ed) ed.onclick = function () { show("cart"); };
    }
    var T = totals();
    var co = $("#coTotals");
    if (co) co.innerHTML = totalsHTML();
    var pt = $("#placeTotal");
    if (pt) pt.textContent = money(T.total);
    serverQuote();
  }

  /* Show a checkout error safely.
     - ALWAYS textContent, never innerHTML: `res.message` is server text and was
       being injected as markup straight into the page.
     - Server text is additionally screened: if it looks like an internal error
       (a PHP exception, a SQL state, a file path, an undefined-index notice, or
       just an implausibly long string) the customer gets the translated generic
       instead of the stack-trace fragment. */
  function showFormError(el, msg, fromServer) {
    var text = String(msg == null ? "" : msg).trim();
    if (fromServer) {
      var internal = !text
        || text.length > 180
        || /Exception|SQLSTATE|\.php\b|must be of type|Undefined |Stack trace|<[a-z/]/i.test(text);
      if (internal) text = t("something_wrong");
    }
    el.textContent = text || t("something_wrong");
    el.hidden = false;
    /* Focus without scrolling the shell — the band is already in view. */
    if (el.focus) { try { el.focus({ preventScroll: true }); } catch (e) {} }
  }

  function placeOrder() {
    var err = $("#formError");
    err.hidden = true;
    if (!S.orderType) { err.textContent = t("select_type"); err.hidden = false; show("cart"); return; }
    if (!cart.length && !comboCount()) return; /* combos-only orders are legal */
    var T = totals();
    if (CFG.minOrderValue > 0 && T.sub < CFG.minOrderValue) { err.textContent = t("min_order") + " " + money(CFG.minOrderValue); err.hidden = false; return; }
    var nameEl = $("#coName");
    var name = nameEl ? nameEl.value.trim() : (CFG.customerName || "");
    if (!name) { err.textContent = t("fill_contact"); err.hidden = false; return; }
    if (S.orderType === "delivery" && !$("#coAddr").value.trim()) { err.textContent = t("fill_addr"); err.hidden = false; return; }
    if (CFG.authMode === "required" && !CFG.isLoggedIn) { sheet("authSheet"); return; }

    var payload = orderLines();

    var fd = new FormData();
    fd.append("_token", CFG.csrf);
    fd.append("items", JSON.stringify(payload));
    fd.append("name", name);
    fd.append("ordering-type", S.orderType);
    if (S.orderType === "on-table" || S.orderType === "room_service") fd.append("table", ($("#coTable") || {}).value || CFG.defaultTable || "");
    else fd.append("phone-number", ($("#coPhone") || {}).value || "");
    if (S.orderType === "delivery") fd.append("address", $("#coAddr").value.trim());
    var pmOn = root.querySelector(".pm.is-on");
    fd.append("pay_via", pmOn ? pmOn.dataset.pm : "pay_on_counter");
    fd.append("tip_amount", String(T.tip || 0));
    fd.append("message", ($("#orderNote") || {}).value || "");
    var sched = $("#scheduledAt");
    if (sched && sched.value) fd.append("scheduled_at", sched.value);
    if (S.coupon) fd.append("coupon_code", S.coupon.code);
    var uw = $("#mbUseWallet"); if (uw && uw.checked) fd.append("use_wallet", "1");
    var bid = new URLSearchParams(window.location.search).get("branch_id");
    if (bid) fd.append("branch_id", bid);
    var consent = $("#consentCb"), capEmail = $("#captureEmail");
    if (consent && consent.checked) { fd.append("marketing_consent", "1"); if (capEmail && capEmail.value) fd.append("capture_email", capEmail.value); }
    var waOpt = $("#waOptin");
    if (waOpt && waOpt.checked) fd.append("whatsapp_optin", "1");

    var btn = $("#placeOrder");
    var lbl = btn.querySelector(".po-label");
    var lblText = lbl.textContent;
    btn.disabled = true;
    btn.querySelector(".spinner").hidden = false;
    lbl.textContent = "…";

    fetch(CFG.endpoints.submitOrder, {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: fd,
    }).then(function (r) { return r.json(); }).then(function (res) {
      btn.disabled = false;
      btn.querySelector(".spinner").hidden = true;
      lbl.textContent = lblText;
      if (res && res.success) {
        if (S.orderType === "delivery") rememberAddress(($("#coAddr") || {}).value, ($("#coLat") || {}).value, ($("#coLng") || {}).value);
        cart = []; writeCart(); S.coupon = null; S.tipPct = 0; syncPill(false);
        if (res.message) { window.location.href = res.message; return; } /* online payment redirect */
        $("#confirmTrack").href = res.track_url || "#";
        var ono = $("#confirmOrderNo");
        if (ono && res.order_id) { ono.hidden = false; ono.textContent = t("order_label") + " #" + res.order_id; }
        spawnEmbers("confirmEmbers", 18);
        rememberOrder(res);   /* so the Orders tab lists it even before any lookup */
        show("confirmed");
        stack.length = 0;
        if (res.whatsapp_url) mbWhatsAppHandoff(res.whatsapp_url, res.whatsapp_handoff);
        if (res.track_url) setTimeout(function () { window.location.href = res.track_url; }, 3400);
      } else {
        showFormError(err, res && res.message, true);
      }
    }).catch(function () {
      btn.disabled = false;
      btn.querySelector(".spinner").hidden = true;
      lbl.textContent = lblText;
      err.textContent = t("network_err");
      err.hidden = false;
    });
  }

  /* ── offers / loyalty ─────────────────────────────────── */
  function renderOffers() { /* static; loyalty pts painted by loyalty fetch */ }

  (function loyalty() {
    if (!CFG.endpoints.loyaltyBalance) return;
    function paint(d) {
      var bal = d.balance || 0;
      var pv = $("#ptsVal"); if (pv) pv.textContent = bal;
      var lp = $("#loyPts"); if (lp) lp.textContent = bal + " " + (CFG.loyaltyType === "stamps" ? t("stamps") : t("pts"));
      var mp = $("#menuPts"); if (mp) mp.textContent = bal + " " + (CFG.loyaltyType === "stamps" ? t("stamps") : t("pts"));
      var bar = $("#ptsBar"); if (bar) bar.style.width = Math.min(100, d.progress_pct || 0) + "%";
      var rb = $("#loyaltyRedeem"); if (rb) rb.hidden = !d.ready_to_redeem;
    }
    fetch(CFG.endpoints.loyaltyBalance).then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.active) paint(d); }).catch(function () {});
    on("loyaltyRedeem", "click", function () {
      var rb = $("#loyaltyRedeem"); rb.disabled = true;
      fetch(CFG.endpoints.loyaltyRedeem, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: "{}" })
        .then(function (r) { return r.json(); }).then(function (d) {
          if (d && d.success) { rb.hidden = true; $("#loyaltyOk").hidden = false; paint({ balance: d.new_balance, progress_pct: 0, ready_to_redeem: false }); }
          else { rb.disabled = false; toast((d && d.error) || t("coupon_bad")); }
        }).catch(function () { rb.disabled = false; });
    });
  })();

  /* ── coupon / waiter ──────────────────────────────────── */
  function on(id, ev, fn) { var el = document.getElementById(id); if (el) el.addEventListener(ev, fn); }

  /* The coupon field's OWN element. `document.querySelector('.coupon-field')` used
     to be used here, which matched the account view's order-lookup box instead
     (it shares the shape and sits earlier in the DOM), so the "applied" state was
     painted on the wrong control every time. */
  function couponBox() { var i = $("#couponInput"); return i && i.closest(".coupon-field"); }

  function paintCoupon(applied) {
    var box = couponBox(), clear = $("#couponClear"), apply = $("#couponApply"), input = $("#couponInput");
    if (box) box.classList.toggle("is-applied", !!applied);
    if (clear) clear.hidden = !applied;
    if (apply) apply.hidden = !!applied;
    if (input) input.readOnly = !!applied;
  }

  function applyCoupon() {
    var input = $("#couponInput"), msg = $("#couponMsg");
    var code = input.value.trim(); if (!code) return;
    msg.hidden = true;
    fetch(CFG.endpoints.coupon, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf },
      body: couponBody(code),
    }).then(function (r) { return r.json(); }).then(function (res) {
      msg.hidden = false;
      if (res && res.success) {
        S.coupon = { code: res.label || code, coupon_type: res.coupon_type, percentage: res.percentage, discount: Number(res.discount) || 0, sig: couponSig() };
        msg.className = "coupon-msg"; msg.textContent = "✓ " + (res.message || code);
        paintCoupon(true);
      } else {
        S.coupon = null;
        msg.className = "coupon-msg err"; msg.textContent = "✕ " + ((res && res.message) || t("coupon_bad"));
        paintCoupon(false);
      }
      renderTotals(); renderCheckout();
    }).catch(function () { msg.hidden = false; msg.className = "coupon-msg err"; msg.textContent = "✕ " + t("coupon_bad"); });
  }

  on("couponApply", "click", applyCoupon);
  on("couponInput", "keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); applyCoupon(); } });
  on("couponClear", "click", function () {
    S.coupon = null;
    var input = $("#couponInput"), msg = $("#couponMsg");
    if (input) { input.readOnly = false; input.value = ""; }
    if (msg) { msg.hidden = true; msg.textContent = ""; }
    paintCoupon(false);
    renderTotals(); renderCheckout();
  });

  function waiterCall(type) {
    var tbl = ($("#coTable") || {}).value || CFG.defaultTable || "";
    fetch(CFG.endpoints.waiter, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf },
      body: JSON.stringify({ table: tbl, type: type, _token: CFG.csrf }),
    }).then(function (r) { return r.json(); }).then(function (res) {
      /* Branch on the server's answer. This toast used to fire unconditionally,
         so a rejected call still told the diner a waiter was on the way. */
      if (res && res.success === false) { toast((res && res.message) || t("something_wrong")); return; }
      toast(type === "request_bill" ? t("bill_ok") : t("waiter_ok"));
    }).catch(function () { toast(t("something_wrong")); });
  }
  on("callWaiter", "click", function () { waiterCall("call_waiter"); });
  on("requestBill", "click", function () { waiterCall("request_bill"); });

  /* ── auth / order lookup ──────────────────────────────── */
  $$("#authTabs button").forEach(function (b) {
    b.addEventListener("click", function () {
      $$("#authTabs button").forEach(function (x) { x.classList.toggle("is-on", x === b); });
      $$('[data-auth]', $("#authSheet")).forEach(function (f) { f.hidden = f.dataset.auth !== b.dataset.authTab; });
    });
  });
  function authPost(url, body) {
    var errEl = $("#authErr");
    return fetch(url, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf, "X-Requested-With": "XMLHttpRequest" }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.success) { window.location.reload(); return res; }
        if (errEl) { errEl.textContent = (res && res.message) || "Failed."; errEl.hidden = false; }
        return res;
      });
  }
  on("loginBtn", "click", function () { authPost(CFG.endpoints.login, { store_id: CFG.storeId, email: $("#loginEmail").value.trim(), password: $("#loginPass").value }); });
  on("registerBtn", "click", function () { authPost(CFG.endpoints.register, { store_id: CFG.storeId, name: $("#regName").value.trim(), email: $("#regEmail").value.trim(), phone: $("#regPhone").value.trim(), password: $("#regPass").value, password_confirmation: $("#regPass2").value }); });
  /* A3 · phone-OTP login — send code, then verify the 6-box code under `code` */
  on("emberOtpSend", "click", function () {
    var phone = $("#emberOtpPhone").value.trim(); if (!phone) return;
    var errEl = $("#authErr"); if (errEl) errEl.hidden = true;
    fetch(CFG.endpoints.otpRequest, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf, "X-Requested-With": "XMLHttpRequest" }, body: JSON.stringify({ store_id: CFG.storeId, phone: phone }) })
      .then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.success) { $("#emberOtpStep1").hidden = true; $("#emberOtpStep2").hidden = false; var f = $("#emberOtpBoxes input"); if (f) f.focus(); }
        else if (errEl) { errEl.textContent = (res && res.message) || "Failed."; errEl.hidden = false; }
      }).catch(function () { if (errEl) { errEl.textContent = "Failed."; errEl.hidden = false; } });
  });
  on("emberOtpVerify", "click", function () {
    var code = $$("#emberOtpBoxes input").map(function (i) { return i.value; }).join("");
    authPost(CFG.endpoints.otpVerifyLogin, { store_id: CFG.storeId, phone: $("#emberOtpPhone").value.trim(), code: code });
  });
  (function () {
    var arr = $$("#emberOtpBoxes input");
    arr.forEach(function (inp, i) {
      inp.addEventListener("input", function () { inp.value = inp.value.replace(/\D/g, "").slice(0, 1); if (inp.value && arr[i + 1]) arr[i + 1].focus(); });
      inp.addEventListener("keydown", function (e) { if (e.key === "Backspace" && !inp.value && arr[i - 1]) arr[i - 1].focus(); });
      inp.addEventListener("paste", function (e) {
        e.preventDefault();
        var d = ((e.clipboardData || window.clipboardData).getData("text") || "").replace(/\D/g, "").slice(0, arr.length);
        if (!d) return;
        for (var j = 0; j < arr.length; j++) arr[j].value = d.charAt(j) || "";
        (arr[Math.min(d.length, arr.length) - 1] || arr[0]).focus();
      });
    });
  })();
  on("loginLink", "click", function () { sheet("authSheet"); });
  on("meAuthBtn", "click", function () { sheet("authSheet"); });
  on("logoutBtn", "click", function () { fetch(CFG.endpoints.logout, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf } }).then(function () { window.location.reload(); }); });

  /* ── F2 · follow / brand row (crave/orbit parity) ────────
     Guest → open the login sheet (the follow API needs a customer token).
     Signed-in → optimistic follow/unfollow via the token-auth endpoint. */
  (function () {
    var btn = $("#cvFollowBtn"); if (!btn) return;
    var ep = CFG.endpoints && CFG.endpoints.follow;
    var REDUCED = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    function headers() {
      var h = { "Content-Type": "application/json", "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" };
      if (CFG.csrf) h["X-CSRF-TOKEN"] = CFG.csrf;
      if (CFG.customerToken) h["Authorization"] = "Bearer " + CFG.customerToken;
      return h;
    }
    function bump(delta) {
      var c = $("#cvFollowerCount"); if (!c) return;
      var n = parseInt(String(c.textContent || "").replace(/[^0-9]/g, ""), 10);
      if (!isNaN(n)) c.textContent = String(Math.max(0, n + delta));
    }
    function apply(d) {
      if (!d || typeof d.following === "undefined") return false;
      btn.dataset.following = d.following ? "1" : "0";
      btn.classList.toggle("is-on", d.following);
      var c = $("#cvFollowerCount"); if (c && typeof d.follower_count !== "undefined") c.textContent = d.follower_count;
      return true;
    }
    btn.addEventListener("click", function () {
      if (!CFG.customerToken) { sheet("authSheet"); return; }   // guest → login first
      if (!ep) return;
      var unfollow = btn.dataset.following === "1", prev = unfollow;
      btn.dataset.following = unfollow ? "0" : "1";
      btn.classList.toggle("is-on", !unfollow);
      bump(unfollow ? -1 : 1);
      if (!unfollow && !REDUCED) { btn.classList.add("followbtn--pop"); btn.addEventListener("animationend", function h() { btn.classList.remove("followbtn--pop"); btn.removeEventListener("animationend", h); }); }
      fetch(ep, { method: unfollow ? "DELETE" : "POST", headers: headers(), body: "{}" })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!apply(d)) throw new Error("bad"); })
        .catch(function () { btn.dataset.following = prev ? "1" : "0"; btn.classList.toggle("is-on", prev); bump(unfollow ? 1 : -1); toast(t("something_wrong")); });
    });
  })();

  /* Remember guest orders in this browser so the Orders tab LISTS what was placed
     here. The server only hands orders back to a phone/email lookup, so a guest who
     just ordered otherwise saw an empty tab. Mirrors velora's recent-orders idiom. */
  var RECENT_KEY = "em_recent_orders_" + (CFG.storeId || CFG.slug || "x");
  function readRecentOrders() {
    try { return (JSON.parse(localStorage.getItem(RECENT_KEY) || "[]") || []).filter(function (o) { return o && o.track_url; }); }
    catch (e) { return []; }
  }
  function rememberOrder(res) {
    if (!res || !res.track_url) return;
    var list = readRecentOrders().filter(function (o) { return o.track_url !== res.track_url; });
    list.unshift({ id: res.order_id || "", track_url: res.track_url, total: res.total || "", placed_at: Date.now() });
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 10))); } catch (e) {}
    renderRecentOrders();
  }
  function renderRecentOrders() {
    var box = $("#lookupResults"); if (!box) return;   /* only the guest orders view has it */
    var list = readRecentOrders();
    if (!list.length) return;                            /* leave lookup results / empty state as-is */
    var empty = $("#ordersEmpty"); if (empty) empty.hidden = true;
    box.innerHTML = list.map(function (o) {
      var when = new Date(o.placed_at || Date.now()).toLocaleDateString(document.documentElement.lang || "en", { day: "2-digit", month: "short" });
      var head = o.id ? "#" + esc(o.id) + (o.total ? " · " + esc(o.total) : "") : esc(when);
      return '<a class="order-row gl" href="' + esc(o.track_url) + '"><span class="order-ic">' + ICONS.flame + '</span><div><b>' + head + "</b><small>" + esc(when) + '</small></div><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg></a>';
    }).join("");
  }

  on("lookupBtn", "click", function () {
    var val = $("#lookupInput").value.trim(); if (!val) return;
    var box = $("#lookupResults"); box.innerHTML = "…";
    fetch(CFG.endpoints.orderLookup, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ identifier: val }) })
      .then(function (r) { return r.json(); }).then(function (data) {
        if (!data.success) { box.innerHTML = '<p class="orders-empty">' + esc(data.message || "") + "</p>"; return; }
        box.innerHTML = data.orders.map(function (o) {
          return '<a class="order-row gl" href="' + esc(o.track_url) + '"><span class="order-ic">' + ICONS.flame + '</span><div><b>#' + esc(o.id) + " · " + esc(o.total) + "</b><small>" + esc(o.date) + '</small></div><span class="order-status">' + esc(o.status) + "</span></a>";
        }).join("");
      }).catch(function () { box.innerHTML = ""; });
  });

  /* ── delegated clicks ─────────────────────────────────── */
  root.addEventListener("click", function (e) {
    var T = e.target;
    function C(s) { return T.closest ? T.closest(s) : null; }

    var fav = C("[data-fav]");
    if (fav) {
      e.stopPropagation();
      e.preventDefault();
      var fid = String(fav.dataset.fav);
      favs.has(fid) ? favs.delete(fid) : favs.add(fid);
      writeFavs();
      fav.classList.toggle("is-on");
      if (active === "favs") renderFavs();
      return;
    }
    var add = C("[data-add]");
    if (add) {
      e.stopPropagation();
      e.preventDefault();
      var ai = item(add.dataset.add);
      if (hasChoices(ai)) { openProduct(ai.id); return; }
      addToCart(ai.id, 0, "", [], [], 1, add);
      return;
    }
    /* product quick-rate — inline stars open the shared review write-sheet
       preselected to the tapped rating (one flow, one endpoint). */
    var qr = C("#rateStars [data-rate]");
    if (qr) {
      var qn = Number(qr.dataset.rate);
      $$("#rateStars button").forEach(function (b, bi) { b.classList.toggle("is-on", bi < qn); });
      if (window.mbItemReviews) window.mbItemReviews.open(qn);
      return;
    }

    /* R7 — same-page ?item= links: grid card anchors (pcard/mrow) + promo/banner/CTA
       links. Checked AFTER the controls above so a click on a card's ＋/heart (inside
       the card anchor, each preventDefaults it) wins. Same-pathname item links open in
       place; real external links full-load. Boot handles a cold ?item= / new-tab load. */
    var itemLink = C('a[href*="item="]');
    if (itemLink && !itemLink.target) {
      try {
        var u = new URL(itemLink.getAttribute("href") || "", window.location.href);
        var iid = u.searchParams.get("item");
        if (iid && u.pathname === window.location.pathname && item(iid)) {
          e.preventDefault();
          openProduct(iid);
          return;
        }
      } catch (err) {}
    }

    var open = C("[data-open]");
    if (open) { if (!$("#searchOverlay").hidden) closeSearch(); openProduct(open.dataset.open); return; }
    var go = C("[data-go]");
    if (go) { show(go.dataset.go); return; }
    if (C("[data-back]")) { back(); return; }

    /* Profile sub-tabs (Orders / Offers / Profile) and deep-links into them. */
    var accSeg = C("#acctSeg [data-acct]");
    if (accSeg) { acctTab(accSeg.dataset.acct); return; }
    var accGo = C("[data-acct-go]");
    if (accGo) {
      var msh = $("#menuSheet"); if (msh && !msh.hidden) sheet("menuSheet", false);
      show("profile"); acctTab(accGo.dataset.acctGo); return;
    }

    /* A tab can open a sheet instead of switching a view (Profile lives in
       #profileSheet): tabs carrying data-msheet fall through to the shared
       [data-msheet] handler below, which maps the name to the real sheet id. */
    var tab = C(".tab");
    if (tab && !tab.dataset.msheet) {
      var _nt = tab.dataset.tab;
      // Menu jumps to the grid on home; search opens the overlay. Neither is a view.
      if (_nt === "search") { openSearch(); return; }
      if (_nt === "menu") { if (active !== "home") show("home"); var _g = document.querySelector('[data-mb-grid-lead]') || document.getElementById("menuList"); if (_g) _g.scrollIntoView({ behavior: "smooth", block: "start" }); return; }
      show(_nt, { push: false }); return;
    }

    var cat = C("[data-cat]");
    if (cat) {
      S.cat = cat.dataset.cat;
      S.sub = "root";
      $$(".cat").forEach(function (b) { b.classList.toggle("is-on", b === cat); });
      syncSubChips();
      renderMenu();
      return;
    }

    /* secondary sub-category chip narrows within the active parent */
    var subchip = C("[data-sub]");
    if (subchip) {
      S.sub = subchip.dataset.sub;
      var grp = subchip.closest(".subgroup");
      if (grp) $$(".subchip", grp).forEach(function (c) { c.classList.toggle("is-on", c === subchip); });
      renderMenu();
      return;
    }

    /* gallery thumbnail → swap the hero image */
    var gt = C("[data-galthumb]");
    if (gt) {
      var pimg = $("#pImg"); if (pimg) pimg.src = gt.dataset.galthumb;
      $$("#pGallery .p-thumb").forEach(function (b) { b.classList.toggle("is-on", b === gt); });
      return;
    }

    /* item page: variant size */
    var sz = C("[data-size]");
    if (sz && S.current) {
      S.pVariant = +sz.dataset.size;
      $$("#sizeRow .szchip").forEach(function (b) { b.classList.toggle("is-on", b === sz); });
      updatePCta(true);
      return;
    }
    /* required option-group single-select */
    var opt = C("[data-opt]");
    if (opt && S.current) {
      var gid = opt.dataset.gid, oi = +opt.dataset.opt;
      var grp = (S.current.optionGroups || []).filter(function (g) { return String(g.id) === String(gid); })[0];
      var o = grp && grp.options[oi];
      if (o) S.pMods[gid] = { groupId: grp.id, optIndex: o.i, label: o.label, price: o.price };
      $$('.sizes[data-group="' + gid + '"] .szchip').forEach(function (b) { b.classList.toggle("is-on", b === opt); });
      updatePCta(true);
      return;
    }
    /* multi option-group toggle */
    var mtog = C("[data-mtog]");
    if (mtog && S.current) {
      var mkey = "m" + mtog.dataset.mtog, mgid = mtog.dataset.gid, moi = +mtog.dataset.oi;
      var mgrp = (S.current.optionGroups || []).filter(function (g) { return String(g.id) === String(mgid); })[0];
      var mo = mgrp && mgrp.options[moi];
      if (S.pMods[mkey]) delete S.pMods[mkey];
      else if (mo) S.pMods[mkey] = { groupId: mgrp.id, optIndex: mo.i, label: mo.label, price: mo.price };
      mtog.classList.toggle("is-on", !!S.pMods[mkey]);
      updatePCta(true);
      return;
    }
    /* extra toggle */
    var tg = C("[data-tog]");
    if (tg && S.current) {
      var xi = +tg.dataset.tog;
      S.pExtras[xi] = !S.pExtras[xi];
      tg.classList.toggle("is-on", !!S.pExtras[xi]);
      updatePCta(true);
      return;
    }
    var q = C("#pQty [data-q]");
    if (q) { S.pQty = Math.max(1, S.pQty + (+q.dataset.q)); updatePCta(true); return; }

    /* cart line qty / remove / note */
    var cq = C("[data-cq]");
    if (cq) {
      var ck = cq.closest(".cart-item").dataset.key;
      var line = cart.filter(function (l) { return l.key === ck; })[0];
      if (line) { line.qty += (+cq.dataset.cq); if (line.qty <= 0) cart = cart.filter(function (l) { return l !== line; }); }
      writeCart(); renderCart();
      return;
    }
    var rm = C("[data-rm]");
    if (rm) {
      var row = rm.closest(".cart-item");
      row.classList.add("removing");
      var rk = row.dataset.key;
      setTimeout(function () { cart = cart.filter(function (l) { return l.key !== rk; }); writeCart(); renderCart(); toast(t("removed")); }, 300);
      return;
    }
    var nb = C("[data-note]");
    if (nb) {
      S.noteKey = nb.dataset.note;
      var nl = cart.filter(function (l) { return l.key === S.noteKey; })[0];
      $("#noteItemName").textContent = nl ? item(nl.id).name : "";
      $("#noteText").value = nl ? (nl.note || "") : "";
      sheet("noteSheet");
      return;
    }

    /* order type */
    var ot = C("#otSeg [data-ot]");
    if (ot) {
      S.orderType = ot.dataset.ot;
      $$("#otSeg button").forEach(function (b) { b.classList.toggle("is-on", b === ot); });
      renderOtFields(); renderTotals();
      return;
    }

    /* payment / tip */
    var pm = C("[data-pm]");
    if (pm) {
      $$(".pm").forEach(function (b) { b.classList.toggle("is-on", b === pm); });
      return;
    }
    var tip = C("#tipRow [data-tip]");
    if (tip) {
      S.tipPct = +tip.dataset.tip;
      $$("#tipRow button").forEach(function (b) { b.classList.toggle("is-on", b === tip); });
      renderCheckout();
      return;
    }

    /* menu-sheet links */
    var ms = C("[data-msheet]");
    if (ms) {
      var target = ms.dataset.msheet;
      sheet("menuSheet", false);
      if (target === "loyalty") sheet("loyaltySheet");
      else if (target === "reserve") sheet("reserveSheet");
      else if (target === "waitlist") sheet("waitlistSheet");
      return;
    }

    /* diet chips */
    var dt = C("#dietRow [data-diet]");
    if (dt) {
      var d = dt.dataset.diet;
      S.diets[d] = !S.diets[d];
      dt.classList.toggle("is-on", !!S.diets[d]);
      dt.setAttribute("aria-pressed", S.diets[d] ? "true" : "false");
      renderSearch();
      return;
    }

    /* language / currency */
    var lg = C("#langSeg [data-lang-code]");
    if (lg) {
      setCookie("Quick_user_lang", lg.dataset.langName || lg.dataset.langCode);
      setCookie("Quick_user_lang_code", lg.dataset.langCode);
      navigateLanguage(lg.dataset.langCode);
      return;
    }
    var cu = C("#curSeg [data-cur]");
    if (cu) {
      S.curCode = cu.dataset.cur;
      S.curRate = parseFloat(cu.dataset.rate) || 1;
      if (window.mbCurrency) mbCurrency.save(S.curCode, S.curRate);   // persist store-scoped (shared helper)
      $$("#curSeg button").forEach(function (b) { b.classList.toggle("is-on", b === cu); });
      renderPopular(); renderMenu(); renderFavs(); renderCart(); renderCheckout();
      if (S.current) { updatePCta(false); renderSizes(); renderGroups(); renderTogs(); }
      syncPill(false);
      return;
    }
  });

  /* ── single-purpose buttons ───────────────────────────── */
  on("splashSkip", "click", function () { var s = $("#splash"); if (s) s.classList.add("is-done"); try { sessionStorage.setItem("em_splash_seen", "1"); } catch (e) {} });
  on("menuBtn", "click", function () { sheet("menuSheet"); });
  on("avatarBtn", "click", function () { show("profile"); acctTab("settings"); });
  on("searchOpen", "click", openSearch);
  on("filterOpen", "click", openSearch);
  on("searchClose", "click", closeSearch);
  on("searchInput", "input", function (e) { S.search = e.target.value; renderSearch(); });
  on("viewAll", "click", function () { S.cat = "all"; S.sub = "root"; $$(".cat").forEach(function (b) { b.classList.toggle("is-on", b.dataset.cat === "all"); }); syncSubChips(); renderMenu(); var mt = $("#menuTitle"); if (mt) mt.scrollIntoView({ behavior: "smooth", block: "start" }); });
  on("clearCart", "click", function () { cart = []; writeCart(); renderCart(); });
  on("goCheckout", "click", function () {
    if (!S.orderType) { toast(t("select_type")); return; }
    show("checkout");
  });
  on("placeOrder", "click", placeOrder);
  on("addToCart", "click", function (e) {
    if (isSoldOut(S.current)) { toast(t("sold_out")); return; }
    var vLabel = "";
    if (S.pVariant) { var v = (S.current.variants || []).filter(function (x) { return x.id === S.pVariant; })[0]; if (v) vLabel = v.label; }
    addToCart(S.current.id, S.pVariant, vLabel, currentMods(), currentExtras(), S.pQty, e.currentTarget);
  });
  on("pFav", "click", function () {
    if (!S.current) return;
    var fid = String(S.current.id);
    favs.has(fid) ? favs.delete(fid) : favs.add(fid);
    writeFavs();
    $("#pFav").classList.toggle("is-on");
  });
  on("pGridBtn", "click", function () {
    var n = $("#pNutri"); n.hidden = !n.hidden;
    $("#pGridBtn").classList.toggle("is-on", !n.hidden);
  });
  on("noteSave", "click", function () {
    var line = cart.filter(function (l) { return l.key === S.noteKey; })[0];
    sheet("noteSheet", false);
    /* Only claim the note was saved if a line actually took it. The note now
       rides to the kitchen on the order payload (`order_note`); before that it
       only ever reached localStorage, so this toast was a lie either way. */
    if (!line) return;
    line.note = $("#noteText").value.trim();
    writeCart(); renderCart();
    toast(t("note_saved"));
  });
  on("loyaltyBanner", "click", function () { sheet("loyaltySheet"); });
  on("offersReserve", "click", function () { sheet("reserveSheet"); });
  on("offersWaitlist", "click", function () { sheet("waitlistSheet"); });

  /* dock autohide on scroll (root views only) */
  var lastY = 0;
  $$(".view-scroll").forEach(function (sc) {
    sc.addEventListener("scroll", function () {
      if (ROOTS.indexOf(active) === -1) return;
      var y = sc.scrollTop;
      if (y > lastY + 4 && y > 130) $("#dock").classList.add("is-hidden");
      else if (y < lastY - 4 || y < 130) $("#dock").classList.remove("is-hidden");
      lastY = y;
    }, { passive: true });
  });

  /* ── nearest-branch map (self-hosted Leaflet) ─────────── */
  (function () {
    var openBtn = document.getElementById("emberNearestBranchBtn");
    if (!openBtn || !CFG.endpoints.branchesGeo || typeof L === "undefined") { if (openBtn) openBtn.style.display = "none"; return; }
    var map = null, markers = [];
    var icon = L.icon({
      iconUrl: CFG.leafletImgPath + "/marker-icon.png",
      iconRetinaUrl: CFG.leafletImgPath + "/marker-icon-2x.png",
      shadowUrl: CFG.leafletImgPath + "/marker-shadow.png",
      iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41],
    });
    function selectBranch(id) {
      var url = new URL(window.location.href);
      url.searchParams.set("branch_id", id);
      window.location.href = url.toString();
    }
    function renderList(branches) {
      $("#emberBranchGeoList").innerHTML = branches.map(function (b) {
        var dist = b.distance_km != null ? '<span class="branch-dist" dir="ltr">' + b.distance_km.toFixed(1) + " km</span>" : "";
        return '<button class="branch-row gl" data-bid="' + b.id + '" type="button">' +
          '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
          '<b>' + esc(b.name) + (b.address ? '<small>' + esc(b.address) + "</small>" : "") + "</b>" + dist +
          "</button>";
      }).join("");
    }
    function drawMap(branches) {
      var withCoords = branches.filter(function (b) { return b.latitude != null && b.longitude != null; });
      if (!map) {
        map = L.map("emberBranchMap", { attributionControl: false });
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 }).addTo(map);
      }
      markers.forEach(function (m) { map.removeLayer(m); }); markers = [];
      if (!withCoords.length) { map.setView([24.7136, 46.6753], 11); return; } /* Riyadh fallback */
      var group = [];
      withCoords.forEach(function (b) {
        var mk = L.marker([b.latitude, b.longitude], { icon: icon }).addTo(map);
        mk.bindPopup("<b>" + esc(b.name) + "</b>");
        mk.on("click", function () { selectBranch(b.id); });
        markers.push(mk); group.push([b.latitude, b.longitude]);
      });
      map.fitBounds(group, { padding: [40, 40], maxZoom: 14 });
    }
    function load(lat, lng) {
      var url = CFG.endpoints.branchesGeo + (lat != null ? "?lat=" + lat + "&lng=" + lng : "");
      fetch(url).then(function (r) { return r.json(); }).then(function (d) {
        var branches = (d && d.branches) || [];
        var attr = $("#emberMapAttrib"); if (attr) attr.textContent = (d && d.attribution) || "© OpenStreetMap contributors";
        renderList(branches);
        setTimeout(function () { drawMap(branches); if (map) map.invalidateSize(); }, 60);
      }).catch(function () {});
    }
    openBtn.addEventListener("click", function () {
      sheet("menuSheet", false);
      sheet("branchMapSheet");
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
          function (p) { load(p.coords.latitude, p.coords.longitude); },
          function () { load(); }, { timeout: 6000 });
      } else { load(); }
    });
    var geoList = $("#emberBranchGeoList");
    if (geoList) geoList.addEventListener("click", function (e) {
      var b = e.target.closest("[data-bid]"); if (b) selectBranch(b.dataset.bid);
    });
  })();

  /* ── delivery address autocomplete + use-my-location ────
     Shared by the checkout field (#coAddr, wired once below) and the cart's
     delivery field (#cartAddr, re-wired by renderOtFields each time it is
     rebuilt). Coordinates always land in the checkout's hidden #coLat/#coLng
     pair — the order payload reads only those. */
  function wireAddressField(input, box, locBtn, attrib, onValue) {
    if (!input || !CFG.endpoints || !CFG.endpoints.geoAutocomplete) return;
    var timer = null, lastAttrib = "";
    function setAttrib(a) { if (a && attrib && a !== lastAttrib) { attrib.textContent = a; attrib.hidden = false; lastAttrib = a; } }
    function hideBox() { if (box) { box.hidden = true; box.innerHTML = ""; } }
    function setCoords(lat, lng) { var la = $("#coLat"), ln = $("#coLng"); if (la) la.value = lat || ""; if (ln) ln.value = lng || ""; }
    function sync() { if (onValue) onValue(input.value); }
    input.addEventListener("input", function () {
      var q = input.value.trim();
      sync();
      setCoords("", "");                 /* typing invalidates a picked pin */
      if (timer) clearTimeout(timer);
      if (q.length < 3) { hideBox(); return; }
      timer = setTimeout(function () {
        fetch(CFG.endpoints.geoAutocomplete + "?q=" + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(function (d) {
            setAttrib(d && d.attribution);
            var res = (d && d.results) || [];
            if (!res.length || !box) { hideBox(); return; }
            box.innerHTML = res.map(function (r) {
              return '<button class="addr-opt" data-lat="' + (r.lat || "") + '" data-lng="' + (r.lng || "") + '" data-label="' + esc(r.label) + '" type="button">' + esc(r.label) + "</button>";
            }).join("");
            box.hidden = false;
          }).catch(hideBox);
      }, 350);
    });
    if (box) box.addEventListener("click", function (e) {
      var opt = e.target.closest(".addr-opt"); if (!opt) return;
      input.value = opt.dataset.label || ""; sync();
      setCoords(opt.dataset.lat, opt.dataset.lng);
      hideBox();
    });
    input.addEventListener("blur", function () { setTimeout(hideBox, 200); });
    if (locBtn) locBtn.addEventListener("click", function () {
      if (!navigator.geolocation) { toast(t("loc_unavailable")); return; }
      locBtn.classList.add("is-loading");
      navigator.geolocation.getCurrentPosition(function (pos) {
        var lat = pos.coords.latitude, lng = pos.coords.longitude;
        fetch((CFG.endpoints.geoReverse || "") + "?lat=" + lat + "&lng=" + lng)
          .then(function (r) { return r.json(); })
          .then(function (d) {
            locBtn.classList.remove("is-loading");
            setAttrib(d && d.attribution);
            if (d && d.result) { input.value = d.result.label || ""; sync(); setCoords(d.result.lat || lat, d.result.lng || lng); }
            else { toast(t("no_address")); }
          }).catch(function () { locBtn.classList.remove("is-loading"); });
      }, function () { locBtn.classList.remove("is-loading"); toast(t("loc_denied")); }, { enableHighAccuracy: true, timeout: 8000 });
    });
  }
  (function () {
    var input = $("#coAddr");
    if (!input) return;
    wireAddressField(input, $("#coAddrSuggest"), $("#coUseLocation"), $("#coGeoAttrib"), null);
    function setCoords(lat, lng) { var la = $("#coLat"), ln = $("#coLng"); if (la) la.value = lat || ""; if (ln) ln.value = lng || ""; }
    var saved = $("#savedAddrs");
    if (saved) saved.addEventListener("click", function (e) {
      var b = e.target.closest(".saved-addr"); if (!b) return;
      input.value = b.dataset.addr || "";
      setCoords(b.dataset.lat, b.dataset.lng);
      $$(".saved-addr", saved).forEach(function (x) { x.classList.remove("is-on"); });
      b.classList.add("is-on");
    });
    renderRecentAddrs();
  })();

  /* ── guest multi-address (remember addresses used on this device) ─────── */
  function addrKey() { return "em_recent_addrs_" + (CFG.storeId || CFG.slug || "x"); }
  function readRecentAddrs() {
    try { return (JSON.parse(localStorage.getItem(addrKey()) || "[]") || []).filter(function (a) { return a && a.addr; }); }
    catch (e) { return []; }
  }
  function rememberAddress(addr, lat, lng) {
    addr = (addr || "").trim(); if (!addr || CFG.isLoggedIn) return;   /* signed-in have a real book */
    var list = readRecentAddrs().filter(function (a) { return a.addr !== addr; });
    list.unshift({ addr: addr, lat: lat || "", lng: lng || "" });
    try { localStorage.setItem(addrKey(), JSON.stringify(list.slice(0, 5))); } catch (e) {}
  }
  function renderRecentAddrs() {
    var saved = $("#savedAddrs");
    if (!saved || CFG.isLoggedIn) return;
    var list = readRecentAddrs();
    if (!list.length) { saved.hidden = true; return; }
    saved.hidden = false;
    var lbl = t("recent_address"); if (!lbl || lbl === "recent_address") lbl = "Recent address";
    saved.innerHTML = list.map(function (a, i) {
      return '<button class="saved-addr' + (i === 0 ? " is-on" : "") + '" type="button" data-addr="' + esc(a.addr) + '" data-lat="' + esc(a.lat) + '" data-lng="' + esc(a.lng) + '">' +
        '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
        '<span class="sa-text"><b>' + esc(lbl) + '</b><small>' + esc(a.addr.length > 42 ? a.addr.slice(0, 42) + "…" : a.addr) + "</small></span></button>";
    }).join("");
  }

  /* ── stories ──────────────────────────────────────────── */
  (function () {
    var rail = $("#storiesRail");
    if (!rail || !CFG.endpoints.stories) return;
    var stories = [], idx = 0, timer = null, T = 0;
    var DUR = 5; /* seconds per story */

    (window.mbStoriesGet?window.mbStoriesGet(CFG.endpoints.stories):fetch(CFG.endpoints.stories).then(function(r){return r.json();})).then(function (d) {
      stories = (d && d.data) || [];
      if (!stories.length) { rail.hidden = true; return; }
      rail.hidden = false;
      rail.innerHTML = stories.map(function (s, i) {
        var label = s.caption ? String(s.caption).slice(0, 14) : t("popular_now");
        return '<button class="story" data-si="' + i + '" type="button">' +
          '<span class="story-ring"><img src="' + esc(s.media_url) + '" alt=""' + IMG_ERR + "></span>" +
          "<small>" + esc(label) + "</small></button>";
      }).join("");
    }).catch(function () { rail.hidden = true; });

    var viewer = $("#storyViewer"), media = $("#svMedia"), cap = $("#svCaption"),
        cta = $("#svCta"), prog = $("#svProgress");
    if (!viewer) return;
    var ctaSpan = cta.querySelector("span");
    var CTA_DEFAULT = ctaSpan.textContent;

    function buildProgress() {
      prog.innerHTML = stories.map(function () { return '<span class="sv-seg"><i></i></span>'; }).join("");
    }
    function paintSeg() {
      $$(".sv-seg i", prog).forEach(function (bar, i) {
        bar.style.width = i < idx ? "100%" : (i === idx ? (T / DUR * 100) + "%" : "0%");
      });
    }
    function render() {
      var s = stories[idx]; if (!s) return;
      media.src = s.media_url;
      cap.textContent = s.caption || "";
      if (s.cta_url && !(window.mbStoryCta && window.mbStoryCta.isCurrentPage(s.cta_url))) {
        cta.hidden = false;
        cta.href = s.cta_url;
        if (window.mbStoryCta) window.mbStoryCta.apply(cta, s.cta_url);
        ctaSpan.textContent = s.cta_label || CTA_DEFAULT;
      } else { cta.hidden = true; }
      T = 0; paintSeg();
    }
    function tick() { T += 0.05; if (T >= DUR) { next(); return; } paintSeg(); }
    function start() { stop(); render(); timer = setInterval(tick, 50); }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function open(i) {
      idx = i; buildProgress();
      viewer.hidden = false;
      void viewer.offsetWidth;
      viewer.classList.add("is-open");
      start();
    }
    function close() {
      stop();
      viewer.classList.remove("is-open");
      setTimeout(function () { viewer.hidden = true; }, 300);
    }
    function next() { if (idx < stories.length - 1) { idx++; render(); } else { close(); } }
    function prev() { if (idx > 0) { idx--; render(); } else { T = 0; paintSeg(); } }

    rail.addEventListener("click", function (e) {
      var b = e.target.closest("[data-si]"); if (b) open(Number(b.dataset.si));
    });
    on("svClose", "click", close);
    /* CTA opens the item in-page beneath this viewer; close the viewer so the item sheet is the top layer. */
    if (cta) cta.addEventListener("click", close);
    on("svNext", "click", next);
    on("svPrev", "click", prev);
    var stage = $("#svStage");
    if (stage) {
      stage.addEventListener("pointerdown", stop);
      stage.addEventListener("pointerup", function () { if (!viewer.hidden && !timer) timer = setInterval(tick, 50); });
    }
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && !viewer.hidden) close();
    });
  })();

  /* ── default order type ───────────────────────────────── */
  (function initOrderType() {
    var first = root.querySelector("#otSeg [data-ot]");
    /* Room service wins: the control only renders when the scanned QR resolved to
       a hotel room, so its presence IS the "this guest is in a room" signal. A room
       QR also sets forceTable, so without this the guest would boot on dine-in and
       the order would reach the kitchen as a table order. */
    var roomSvc = root.querySelector('#otSeg [data-ot="room_service"]');
    if (roomSvc) { S.orderType = "room_service"; roomSvc.classList.add("is-on"); return; }
    if (CFG.forceTable) {
      var onTable = root.querySelector('#otSeg [data-ot="on-table"]');
      if (onTable) { S.orderType = "on-table"; onTable.classList.add("is-on"); return; }
    }
    if (first) { S.orderType = first.dataset.ot; first.classList.add("is-on"); }
  })();

  /* ── boot ─────────────────────────────────────────────── */
  function boot() {
    renderPopular();
    renderMenu();
    if (window.mbCurrency) mbCurrency.restore("#curSeg");   // replay saved currency choice (store-scoped)
    renderRecentOrders();   /* list this browser's past orders on the guest Orders tab */
    spawnEmbers("splashEmbers", 16);
    syncPill(false);
    var splash = $("#splash");
    if (splash) {
      var seen = false;
      try { seen = sessionStorage.getItem("em_splash_seen") === "1"; } catch (e) {}
      /* already dismissed / hidden pre-paint this session → don't replay the intro */
      var markSeen = function () { try { sessionStorage.setItem("em_splash_seen", "1"); } catch (e) {} };
      /* A1: a ?item=/?table= deep link opens straight on the product — dismiss the
         splash up front so it never overlays it (splash sits above the sheet). Not
         marked seen: a later plain visit this session still gets the intro. */
      var _dl = null; try { _dl = new URLSearchParams(location.search); } catch (e) {}
      var deepLinked = !!(_dl && (_dl.get("item") || _dl.get("table")));
      if (deepLinked) splash.classList.add("is-done");
      else if (seen || splash.classList.contains("is-done")) { splash.classList.add("is-done"); markSeen(); }
      else setTimeout(function () { splash.classList.add("is-done"); markSeen(); }, 2400);
    }
    if (CFG.openResSheet && $("#reserveSheet")) { show("profile"); acctTab("offers"); sheet("reserveSheet"); }
    if (CFG.openWaitlistSheet && $("#waitlistSheet")) { show("profile"); acctTab("offers"); sheet("waitlistSheet"); }

    /* Open a shared / deep-linked product (?item=<id>) in place: a link that lands
       here goes straight to the item instead of the bare home view. Also makes any
       legacy full-navigation CTA graceful even if the click interceptor is bypassed. */
    try {
      var deepItem = new URLSearchParams(window.location.search).get("item");
      if (deepItem && item(deepItem)) openProduct(deepItem);
    } catch (e) {}
  }
  boot();

  /* Collapsing header — condensed bar in once the cover header leaves the home
     scroll container. ember's category chips are not top-sticky, so no overlap
     guard. Condensed buttons forward their click to the real top-chrome controls. */
  (function mountCondensedHeader() {
    var homeView = document.querySelector('.view[data-view="home"]');
    if (!homeView) return;
    var anchor = homeView.querySelector(".ember-cover") || homeView.querySelector(".home-top");
    var bar = homeView.querySelector(".cv-condensed");
    var scroller = homeView.querySelector(".view-scroll");
    if (!anchor || !bar || !scroller || !("IntersectionObserver" in window)) return;
    var io = new IntersectionObserver(function (es) {
      var e = es[0];
      if (e) bar.classList.toggle("is-on", e.intersectionRatio < 0.05);
    }, { root: scroller, threshold: [0, 0.05, 1] });
    io.observe(anchor);
    var T = { lang: "#langBtn", catering: "#cvCateringBtn", notif: "#mbNotifBell" };
    Array.prototype.forEach.call(bar.querySelectorAll("[data-cv-proxy]"), function (b) {
      b.addEventListener("click", function () {
        var t = homeView.querySelector(T[b.getAttribute("data-cv-proxy")]);
        if (t) t.click();
      });
    });
  })();
})();
