// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ═══════════════════════════════════════════════════════════
   orbit — theme runtime (faithful rebuild of rev/orbit/demo.js)
   Ultra-clean iOS ordering app · arc categories · floating pill nav
   Reads window.ORBIT (set by index.blade.php) and wires the demo UI
   to the REAL backend: submitOrder, coupon, review, nps, waiter, auth,
   loyalty, order lookup, language & multi-currency.
   ═══════════════════════════════════════════════════════════ */
(function () {
  "use strict";
  var CFG = window.ORBIT;
  if (!CFG) return;

  var root = document.getElementById("orbit");
  if (!root) return;
  function $(s, c) { return (c || root).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || root).querySelectorAll(s)); }
  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }
  /* Crawlable + new-tab-able per-item URL. Keeps existing query (table, lang…)
     and just sets ?item=ID — the exact deep-link the R7 intercept and the boot
     loader already understand, so middle-click/⌘-click opens the product in a
     fresh tab and a plain click still opens it in place (no reload). */
  function itemHref(id) {
    try { var u = new URL(window.location.href); u.searchParams.set("item", id); u.hash = ""; return u.pathname + u.search; }
    catch (e) { return "?item=" + encodeURIComponent(id); }
  }
  var REDUCED = matchMedia("(prefers-reduced-motion:reduce)").matches;

  var ICONS = {
    plus: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg>',
    minus: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg>',
    heart: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>',
    star: '<svg class="ic" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>',
    clock: '<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
    fire: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 22c4.4 0 7-2.8 7-6.6 0-4.8-4.2-6.6-4.9-11.4-2.5 1.6-3.4 4-3.2 6.6-1.2-.7-2-1.7-2.4-3.4C6.6 9 5 11.6 5 15.4 5 19.2 7.6 22 12 22Z"/></svg>',
    check: '<svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>',
    trash: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M10 7V5h4v2M8 7l.8 13h6.4L16 7"/></svg>',
    pen: '<svg class="ic" viewBox="0 0 24 24"><path d="M4 20l1-4L16 5l3 3L8 19l-4 1Z"/></svg>',
    leaf: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 19C5 9 13 5 20 5c0 8-4 14-12 14-1 0-2-.1-3 0Z"/><path d="M5 19c2-5 6-9 10-11"/></svg>',
    moon: '<svg class="ic" viewBox="0 0 24 24"><path d="M20 13A8 8 0 1 1 11 4a6.5 6.5 0 0 0 9 9Z"/></svg>',
    gf: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 4v16M8 8c0 2 2 3 4 3s4-1 4-3M8 14c0 2 2 3 4 3s4-1 4-3"/><path d="M4 20 20 4"/></svg>',
    pin: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>',
    cash: '<svg class="ic" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="11" rx="2"/><circle cx="12" cy="12.5" r="2.6"/></svg>',
    card: '<svg class="ic" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M7 15h4"/></svg>',
  };

  /* ── state ────────────────────────────────────────────── */
  var slug = CFG.restaurantSlug;
  var cartKey = "mb-cart-" + slug;          /* shared key across themes */
  var favKey = "cv-favs-" + slug;
  var cart = [];
  try { cart = JSON.parse(localStorage.getItem(cartKey)) || []; } catch (e) {}
  if (!Array.isArray(cart)) cart = [];
  var favs = new Set();
  try { (JSON.parse(localStorage.getItem(favKey)) || []).forEach(function (id) { favs.add(String(id)); }); } catch (e) {}

  var S = {
    cat: "all", sub: "root", search: "", sort: "featured",
    filters: { diets: {}, allergens: {}, spice: 3 },
    orderType: null, coupon: null, tipPct: 0, tipCustom: null, pay: "pay_on_counter",
    current: null, pQty: 1, pVariant: 0, pMods: {}, pExtras: {},
    curCode: CFG.baseCurrency,
    curRate: 1,
  };

  /* ── money ────────────────────────────────────────────── */
  /* Decimals follow the DISPLAYED currency, not the store's: an OMR store viewed in its own
     currency needs 3 (the server-rendered cards already print 3 via price_format()), but the
     same store viewed in USD needs 2. decimalsByCode lists every non-2 currency, so a code
     that is absent from it is 2 by definition — never fall back to this store's decimals. */
  function moneyDp(code) {
    var dp = code === CFG.baseCurrency ? CFG.currencyDecimals : (CFG.decimalsByCode || {})[code];
    dp = Number(dp);
    return isFinite(dp) ? dp : 2;
  }
  /* Base-currency decimals for the cart maths below (totals are computed pre-conversion). */
  var BASE_DP = moneyDp(CFG.baseCurrency);
  /* bug batch 10 · 4: order arithmetic (coupon / tax / tip) at the store currency's minor unit — what the order stores (CFG.moneyDecimals); display keeps currencyDecimals */
  var MONEY_DP = CFG.moneyDecimals != null ? CFG.moneyDecimals : BASE_DP;
  /* 1 on a 2-decimal store, so the percentage maths stays bit-identical to what it was. */
  var PCT_SCALE = Math.pow(10, BASE_DP - 2);
  /* M16: delegates to the shared money core so tax/percentage math rounds exactly
     like PHP's round(). */
  function pctOf(base, percentage) { return mbMoney.pct(base, percentage, MONEY_DP); }

  function money(base) {
    var v = (Number(base) || 0) * S.curRate;
    var str = v.toFixed(moneyDp(S.curCode));
    if (S.curCode !== CFG.baseCurrency) return "≈ " + S.curCode + " " + str;
    var gap = CFG.currencyGap || "";   // U+20C3 UAE Dirham gets a space; every other sign is gap-less
    return CFG.currencyPos === "1" ? CFG.currencySign + gap + str : str + gap + CFG.currencySign;
  }
  var t = CFG.i18n;

  /* ── items ────────────────────────────────────────────── */
  var ITEMS = Object.keys(CFG.items).map(function (k) { return CFG.items[k]; });
  function item(id) { return CFG.items[id]; }
  var FALLBACK_IMG = CFG.defaultImg || "";
  var IMG_ERR = FALLBACK_IMG ? ' data-csp-error="fallback" data-csp-fallback="' + esc(FALLBACK_IMG) + '"' : "";
  function hasOptions(i) { return (i.variants || []).length || (i.optionGroups || []).length || (i.extras || []).length; }
  function itemImgs(i) {
    var g = (i.gallery && i.gallery.length) ? i.gallery.slice() : [];
    if (i.img && g.indexOf(i.img) === -1) g.unshift(i.img);
    return g.length ? g : [i.img];
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
  function isSold(i) { return !!i.is_sold_out || !isAvailableNow(i); }
  function subOf(i) { return (i.desc || "").split(/[.،!\n]/)[0].slice(0, 42); }

  /* pricing: variant price ABSOLUTE, groups/extras additive */
  function unitPrice(i, variantId, mods, extras) {
    var p = Number(i.price) || 0;
    if (variantId) { var v = (i.variants || []).filter(function (x) { return x.id === variantId; })[0]; if (v) p = Number(v.price) || 0; }
    (mods || []).forEach(function (m) { p += Number(m.price) || 0; });
    (extras || []).forEach(function (e) { p += Number(e.price) || 0; });
    return p;
  }
  function lineLabel(l) {
    var i = item(l.id); if (!i) return ""; var parts = [];
    if (l.variantId) { var v = (i.variants || []).filter(function (x) { return x.id === l.variantId; })[0]; if (v) parts.push(v.label); }
    (l.mods || []).forEach(function (m) { parts.push(m.label); });
    (l.extras || []).forEach(function (e) { parts.push(e.title); });
    return parts.join(" · ");
  }
  function subtotal() {
    /* Combos ride in the subtotal: the server taxes them, min-order counts them,
       and the coupon endpoint is quoted the same base the order will carry. */
    return cart.reduce(function (s, l) { var i = item(l.id); return i ? s + unitPrice(i, l.variantId, l.mods, l.extras) * l.qty : s; }, 0) + comboTotal();
  }
  /* The cart as submitOrder's `items` — also posted to the coupon check (bug batch 7 · 4). */
  function orderLines() {
    return cart.map(function (l) {
      return { id: l.id, quantity: l.qty, variants: l.variantId || 0, extras: (l.extras || []).map(function (e) { return { id: e.id }; }), mods: (l.mods || []).map(function (m) { return { groupId: m.groupId, optIndex: m.optIndex }; }) };
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
        renderTotals();
      }).catch(function () {});
    }
    return Number(c.discount) || 0;
  }
  function cartCount() { return cart.reduce(function (n, l) { return n + l.qty; }, 0); }
  function qtyOf(id) { return cart.filter(function (l) { return String(l.id) === String(id); }).reduce(function (n, l) { return n + l.qty; }, 0); }
  function writeCart() { localStorage.setItem(cartKey, JSON.stringify(cart)); }
  function writeFavs() { localStorage.setItem(favKey, JSON.stringify(Array.from(favs))); }

  /* COMBOS-V2 — the shared combos partial (_partials/combos.blade.php) keeps its
     own basket and publishes display state on window.mbCombos {count,total,lines}.
     The server prices combo lines into the ORDER subtotal (and taxes them like any
     other line), so the quote must fold them in too — QUOTED == CHARGED. Quantity
     edits stay in the combo sheet; here they render read-only. */
  function comboCount() { return (window.mbCombos && window.mbCombos.count) || 0; }
  function comboTotal() { return (window.mbCombos && window.mbCombos.total) || 0; }
  function comboLines() { return (window.mbCombos && window.mbCombos.lines) || []; }

  function totals() {
    var sub = subtotal(), disc = 0;
    if (S.coupon) {
      disc = S.coupon.coupon_type === "percentage" ? pctOf(sub, S.coupon.percentage) : S.coupon.coupon_type === "bogo" ? bxgyDiscount() : Number(S.coupon.percentage) || 0;
      if (disc > sub) disc = sub;
    }
    /* QUOTED == CHARGED. The server (Order::computeTax + ManagesOrders) taxes the
       UNDISCOUNTED subtotal and honours restaurant_tax_type; taxing (sub − disc)
       here quoted a lower total than the customer was actually charged the moment
       a coupon was applied. */
    var tax = 0;
    if (CFG.taxCharge > 0 && sub > 0) {
      tax = CFG.taxType === "fixed" ? mbMoney.round(Number(CFG.taxCharge), MONEY_DP) : pctOf(sub, CFG.taxCharge);
    }
    var del = S.orderType === "delivery" && (cart.length || comboCount()) ? CFG.deliveryCharge : 0;
    var tip = S.tipPct ? pctOf(sub, S.tipPct) : (S.tipCustom || 0);
    return { sub: sub, disc: disc, tax: tax, del: del, tip: tip, total: sub - disc + tax + del + tip };
  }

  /* ── router ───────────────────────────────────────────── */
  var stack = [], ROOTS = ["home", "cart", "profile", "favs"], active = "home", rootTab = "home";

  /* Orders / Offers / Profile are one view (data-view="profile") with a segmented
     sub-switcher (#acctSeg). acctTab() toggles the sub-panels; the choice is
     remembered so re-opening Profile lands where you left it. */
  var acctActive = "orders";
  function acctTab(name) {
    acctActive = name;
    $$("#acctSeg [data-acct]").forEach(function (b) { b.classList.toggle("is-on", b.dataset.acct === name); });
    $$(".acct-panel").forEach(function (p) { p.hidden = p.getAttribute("data-acct-panel") !== name; });
    if (name === "offers") renderOffers();
    var host = $('.view[data-view="profile"] .view-scroll'); if (host) host.scrollTop = 0;
  }
  function show(name, opts) {
    opts = opts || {};
    if (name === active) return;
    var from = $('.view[data-view="' + active + '"]'), to = $('.view[data-view="' + name + '"]');
    if (!to) return;
    var isRoot = ROOTS.indexOf(name) !== -1;
    if (opts.push !== false && !isRoot) stack.push(active);
    if (isRoot) stack.length = 0;
    $$(".view").forEach(function (v) { v.classList.remove("is-under"); });
    if (!isRoot && from) from.classList.add("is-under");
    if (from) from.classList.remove("is-active");
    to.classList.add("is-active");
    var sc = to.querySelector(".view-scroll"); if (sc) sc.scrollTop = 0;
    active = name;
    syncInert();
    var nav = $("#navbar"); if (nav) nav.classList.toggle("is-hidden", !isRoot);
    if (isRoot) { rootTab = name; navSync(); }
    if (name === "cart") renderCart();
    if (name === "favs") renderFavs();
    if (name === "profile") acctTab(acctActive);
    if (name === "checkout") renderCheckout();
  }
  function back() { show(stack.pop() || "home", { push: false }); }

  /* ORBIT-F34 — an off-screen view kept every card, heart and add button in the
     accessibility tree and in the tab order, so a keyboard/AT user on the
     product page was still walking the home grid behind it. */
  function syncInert() {
    $$(".view").forEach(function (v) {
      var on = v.classList.contains("is-active");
      if (on) v.removeAttribute("inert"); else v.setAttribute("inert", "");
    });
  }

  function navSync() {
    var btn = $('.nav-btn[data-tab="' + rootTab + '"]'); if (!btn) return;
    $$(".nav-btn").forEach(function (b) { b.classList.toggle("is-on", b.dataset.tab === rootTab); });
    var dot = $("#navDot"); if (dot) dot.style.transform = "translateX(" + (btn.offsetLeft + btn.offsetWidth / 2 - 24) + "px)";
  }

  /* ── sheets / toast / fly ─────────────────────────────── */
  var openSheetId = null, sheetTimers = {}, mbSheetNav = false, mbSheetPopping = false;
  /* Close a sheet WITHOUT touching history — used by sheet-switching and by the
     popstate handler (see the Back-button block above). */
  function rawCloseSheet(id) {
    var s = document.getElementById(id), scrim = $("#scrim"); if (!s) return;
    s.classList.remove("is-open"); scrim.classList.remove("is-open");
    clearTimeout(sheetTimers[id]);
    sheetTimers[id] = setTimeout(function () { s.hidden = true; if (!openSheetId || openSheetId === id) scrim.hidden = true; }, 420);
    if (openSheetId === id) openSheetId = null;
  }
  function sheet(id, open) {
    var s = document.getElementById(id), scrim = $("#scrim"); if (!s) return;
    if (open !== false) {
      if (openSheetId && openSheetId !== id) rawCloseSheet(openSheetId);   /* switch: no history */
      clearTimeout(sheetTimers[id]);
      s.hidden = false; scrim.hidden = false;
      setTimeout(function () { s.classList.add("is-open"); scrim.classList.add("is-open"); }, 20);
      openSheetId = id;
      /* One guard history entry per sheet session so the device Back button
         closes the sheet instead of leaving the page. */
      if (!mbSheetNav) { mbSheetNav = true; try { history.pushState({ mbSheet: 1 }, ""); } catch (e) {} }
    } else {
      /* Route the close through Back so the guard entry is consumed and the
         popstate handler performs the actual close (single code path). */
      if (mbSheetNav && !mbSheetPopping) { history.back(); }
      else { rawCloseSheet(id); }
    }
  }
  function closeAnySheet() { if (openSheetId) sheet(openSheetId, false); }
  $("#scrim").addEventListener("click", closeAnySheet);
  $$(".sheet-grab").forEach(function (g) { g.addEventListener("click", function () { var s = g.closest(".sheet"); if (s) sheet(s.id, false); }); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") closeAnySheet(); });

  /* ── browser / OS Back-button navigation ──────────────────
     Orbit is a single-page app. Without wiring the History API, the device Back
     button (and edge-swipe on mobile) LEFT the site instead of returning to the
     previous view or closing an open sheet. We push a history entry per view
     change; Back then pops through the view stack, closes an open sheet first,
     and restores each root view's scroll position. Sheets are intentionally not
     pushed — an open sheet just consumes one Back press and re-balances history,
     which keeps this simple and avoids double-close races. */
  (function () {
    try { if ("scrollRestoration" in history) history.scrollRestoration = "manual"; } catch (e) {}
    var scrollMem = {};
    try { history.replaceState({ mbView: active }, ""); } catch (e) {}
    var rawShow = show;
    show = function (name, opts) {
      opts = opts || {};
      var prev = active;
      var fsc = $('.view[data-view="' + prev + '"] .view-scroll'); if (fsc) scrollMem[prev] = fsc.scrollTop;
      rawShow(name, opts);
      if (active === name && prev !== name) {
        if (opts.pop) {
          if (opts.restore != null) { var tsc = $('.view[data-view="' + name + '"] .view-scroll'); if (tsc) tsc.scrollTop = opts.restore; }
        } else {
          try { history.pushState({ mbView: name }, ""); } catch (e) {}
        }
      }
    };
    window.addEventListener("popstate", function (e) {
      /* An open sheet consumes this Back press (its guard entry): close it. */
      if (openSheetId) { mbSheetPopping = true; rawCloseSheet(openSheetId); mbSheetNav = false; mbSheetPopping = false; return; }
      if (document.getElementById("searchLayer") && $("#searchLayer").classList.contains("is-open")) {
        closeSearch(); try { history.pushState({ mbView: active }, ""); } catch (e3) {} return;
      }
      var st = e.state || {};
      if (st && st.mbView && st.mbView !== active) {
        show(st.mbView, { push: false, pop: true, restore: scrollMem[st.mbView] });
      }
    });
  })();

  var toastTimer;
  function toast(msg) {
    var el = $("#toast"); if (!el) return;
    el.innerHTML = ICONS.check.replace("<svg", '<svg class="ic"') + "<span>" + esc(msg) + "</span>";
    el.classList.add("is-on");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.classList.remove("is-on"); }, 2200);
  }

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

  function flyToCart(fromEl) {
    if (REDUCED || !fromEl) return;
    var host = fromEl.closest(".card, .rail-card, .sl-row");
    var img = host ? host.querySelector("img") : $("#pGallery img");
    var badge = $("#cartBadge"), target = badge ? badge.parentElement : $('.nav-btn[data-tab="cart"]');
    if (!img || !target) return;
    var a = img.getBoundingClientRect(), b = target.getBoundingClientRect();
    var lay = $("#flyLayer"), lr = lay.getBoundingClientRect();
    var ghost = document.createElement("img");
    ghost.src = img.src; ghost.className = "fly-img";
    ghost.style.left = a.left - lr.left + a.width / 2 - 30 + "px";
    ghost.style.top = a.top - lr.top + a.height / 2 - 30 + "px";
    lay.appendChild(ghost);
    requestAnimationFrame(function () {
      ghost.style.left = b.left - lr.left + b.width / 2 - 30 + "px";
      ghost.style.top = b.top - lr.top + b.height / 2 - 30 + "px";
      ghost.style.transform = "scale(.15)"; ghost.style.opacity = ".25";
    });
    setTimeout(function () { ghost.remove(); }, 800);
  }

  /* ── analytics ────────────────────────────────────────── */
  function itemEvent(id, type) {
    if (!CFG.endpoints.itemEvent) return;
    try {
      fetch(CFG.endpoints.itemEvent, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ menu_id: id, type: type }) }).catch(function () {});
    } catch (e) {}
  }

  /* ── ARC category selector (signature) ────────────────── */
  var CATS = (CFG.cats || []).slice();
  CATS.splice(Math.floor(CATS.length / 2), 0, { id: "all", name: t.all, img: null });
  var ALL_IDX = CATS.map(function (c) { return c.id; }).indexOf("all");
  /* chip id -> its subcategory ids: items filed under a child must answer to the
     parent's chip, otherwise every such chip renders an empty grid. */
  var CATKIDS = {};
  CATS.forEach(function (c) { CATKIDS[String(c.id)] = (c.kids || []).map(String); });
  function catMatch(item, cat) {
    var ic = String(item.cat), k = String(cat);
    if (ic === k) return true;
    var kids = CATKIDS[k];
    return !!kids && kids.indexOf(ic) !== -1;
  }
  var ARC = { R: 205, stepDeg: 24, apexY: 8 };
  var arcS = { pos: ALL_IDX, vel: 0, target: ALL_IDX, dragging: false, raf: null, moved: false, startX: 0, startPos: 0 };
  var arcChips = [];

  function buildArc() {
    var arc = $("#arc"); if (!arc) return;
    arc.innerHTML = CATS.map(function (c, i) {
      /* the ☆ fallback must carry .arc-star too — without it the glyph keeps
         --ink while the ACTIVE bubble fills with --ink: black on black. */
      var bub = c.id === "all" ? '<i class="arc-star">✦</i>' : (c.img ? '<img src="' + esc(c.img) + '" alt="" loading="lazy" data-csp-error="remove">' : '<i class="arc-star">☆</i>');
      return '<button class="arc-chip' + (c.img ? " has-img" : "") + '" data-ci="' + i + '" data-cat="' + esc(c.id) + '" type="button" tabindex="-1" aria-label="' + esc(c.name) + '"><span class="arc-bub">' + bub + '</span><span class="arc-label">' + esc(c.name) + '</span></button>';
    }).join("");
    arcChips = $$(".arc-chip", arc);
    arcRender();
  }
  /* ORBIT-F8 — the chips used to ride a true circle, so cos() flattened past
     ±60° and the 1st/last categories piled up 9px apart at the rim and clipped
     out of the track: pick the first category and two of five vanish. A
     parabola matches the circle to within a pixel for the chips you can
     actually see (|off| ≤ 2 — the same arc silhouette) but keeps a constant
     horizontal step forever, so no two chips can ever collide. */
  var ARC_STEP_X = ARC.R * Math.sin(ARC.stepDeg * Math.PI / 180);   /* ≈ 83px */
  var ARC_DROP   = ARC.R * (1 - Math.cos(ARC.stepDeg * Math.PI / 180)); /* ≈ 18px */
  function arcRender() {
    var arc = $("#arc"); if (!arc) return;
    var cx = arc.clientWidth / 2, mir = document.documentElement.dir === "rtl" ? -1 : 1;
    arcChips.forEach(function (chip, i) {
      var off = i - arcS.pos;
      var x = cx + mir * off * ARC_STEP_X, y = ARC.apexY + ARC_DROP * off * off;
      var d = Math.abs(off), scale = 1 + Math.max(0, 1 - d) * 0.16, o = Math.max(0, Math.min(1, (2.45 - d) / 0.55));
      chip.style.transform = "translate(" + x + "px," + y + "px) translate(-50%,0) scale(" + scale + ")";
      chip.style.opacity = o;
      chip.style.zIndex = 10 - Math.round(d);
      chip.style.pointerEvents = o < 0.35 ? "none" : "";
      /* fully faded chips must leave the a11y tree, not linger as invisible targets */
      chip.style.visibility = o <= 0.02 ? "hidden" : "";
      chip.classList.toggle("is-on", i === Math.round(arcS.target));
    });
  }
  function arcTick() {
    if (!arcS.dragging) {
      arcS.vel = (arcS.vel + (arcS.target - arcS.pos) * 0.085) * 0.84;
      arcS.pos += arcS.vel;
      if (Math.abs(arcS.target - arcS.pos) < 0.0015 && Math.abs(arcS.vel) < 0.0015) { arcS.pos = arcS.target; arcRender(); arcS.raf = null; return; }
    }
    arcRender();
    arcS.raf = requestAnimationFrame(arcTick);
  }
  function arcKick() { if (REDUCED) { arcS.pos = arcS.target; arcS.vel = 0; arcRender(); return; } if (!arcS.raf) arcS.raf = requestAnimationFrame(arcTick); }
  function arcSelect(idx, spin) {
    idx = Math.max(0, Math.min(CATS.length - 1, Math.round(idx)));
    arcS.target = idx;
    if (spin !== false) arcKick(); else { arcS.pos = idx; arcRender(); }
    var cat = CATS[idx].id;
    if (S.cat !== cat) {
      S.cat = cat; S.sub = "root"; syncSubChips(); skeletons();
      clearTimeout(arcSelect._t);
      arcSelect._t = setTimeout(renderGrid, REDUCED ? 0 : 360);
    }
    arcRender();
  }
  (function () {
    var arc = $("#arc"); if (!arc) return;
    var pxPer = ARC.R * Math.sin(ARC.stepDeg * Math.PI / 180);
    arc.addEventListener("pointerdown", function (e) {
      arcS.dragging = true; arcS.moved = false; arcS.startX = e.clientX; arcS.startPos = arcS.pos; arcS.vel = 0;
      /* Remember the chip under the finger BEFORE setPointerCapture: capture
         retargets every later pointer event to #arc, so on pointerup
         e.target is #arc and closest(".arc-chip") is null — a plain tap then
         re-selected the centre chip instead of the tapped one. Store it now. */
      arcS.downChip = e.target.closest(".arc-chip");
      try { arc.setPointerCapture(e.pointerId); } catch (x) {}
      if (!arcS.raf && !REDUCED) arcS.raf = requestAnimationFrame(arcTick);
    });
    arc.addEventListener("pointermove", function (e) {
      if (!arcS.dragging) return;
      var dx = e.clientX - arcS.startX;
      if (Math.abs(dx) > 6) arcS.moved = true;
      var mir = document.documentElement.dir === "rtl" ? -1 : 1;
      var raw = arcS.startPos - mir * dx / pxPer, max = CATS.length - 1;
      if (raw < 0) raw *= 0.35; else if (raw > max) raw = max + (raw - max) * 0.35;
      arcS.pos = raw;
      if (REDUCED) arcRender();
    });
    function release(e) {
      if (!arcS.dragging) return; arcS.dragging = false;
      if (!arcS.moved) { var chip = arcS.downChip || e.target.closest(".arc-chip"); arcSelect(chip ? +chip.dataset.ci : arcS.pos); }
      else arcSelect(arcS.pos);
      arcS.downChip = null;
    }
    arc.addEventListener("pointerup", release);
    arc.addEventListener("pointercancel", release);
  })();

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

  /* ── home render ──────────────────────────────────────── */
  function buyHTML(i) {
    if (!CFG.allowOrder || isSold(i)) return "";
    if (hasOptions(i)) {
      return '<span class="buy" data-openbuy="' + i.id + '" role="button" aria-label="+"><span class="b-inc">' + ICONS.plus + "</span></span>";
    }
    var q = qtyOf(i.id);
    return '<span class="buy ' + (q ? "is-in" : "") + '" data-buy="' + i.id + '">' +
      '<span class="b-dec" data-bdec="' + i.id + '" role="button" aria-label="−">' + ICONS.minus + "</span>" +
      '<i class="b-q">' + q + "</i>" +
      '<span class="b-inc" data-binc="' + i.id + '" role="button" aria-label="+">' + ICONS.plus + "</span></span>";
  }
  /* PARITY-RUN T5 — happy-hour price display. `i.price` is ALREADY the price the
     server will charge; `i.original_price` is what it was before the schedule,
     present only while a schedule is live. Purely presentational: the cart and
     the order path both take their number from the server, never from here. */
  function hhWasHTML(i) {
    return i.original_price ? '<s style="opacity:.55;font-weight:400;margin-inline-end:.35em">' + money(i.original_price) + "</s>" : "";
  }
  // Happy-hour label. Rendered as an overlay pill anchored to the BOTTOM of the
  // card thumbnail (see .hh-badge in style.css) — NOT in the price row: sat inline
  // it stole width from .card-price and clipped/covered the live price on the grid.
  function hhBadgeHTML(i) {
    return i.schedule_label
      ? '<span class="hh-badge">' + esc(i.schedule_label) + "</span>"
      : "";
  }
  function cardHTML(i, idx) {
    var fav = favs.has(String(i.id)), sub = subOf(i), sold = !!i.is_sold_out, unavail = !isAvailableNow(i);
    // F11: serve a 200w thumb candidate on small viewports when one exists, and
    // declare intrinsic width/height so the list reserves space (no CLS).
    var imgSet = i.thumb ? ' srcset="' + esc(i.thumb) + ' 200w, ' + esc(i.img) + ' 500w" sizes="(max-width:600px) 45vw, 200px"' : "";
    return '<a class="card' + (sold ? " is-sold" : unavail ? " is-unavailable" : "") + '" href="' + esc(itemHref(i.id)) + '" data-open="' + i.id + '" style="animation-delay:' + Math.min(idx * 45, 380) + 'ms">' +
      '<span class="card-imgwrap"><img src="' + esc(i.img) + '"' + imgSet + ' width="200" height="200" alt="" loading="lazy"' + IMG_ERR + ">" +
        (sold ? '<span class="card-soldout">' + esc(t.sold_out) + "</span>" : unavail ? '<span class="avail-badge">' + esc(t.not_available) + "</span>" : "") +
        '<span class="card-badges">' +
          (i.is_daily_special ? '<span class="sp-badge"><span>✦</span> ' + esc(t.popular) + "</span>" : (i.is_popular ? '<span class="sp-badge"><span>★</span> ' + esc(t.popular) + "</span>" : "")) +
        "</span>" +
        '<span class="card-fav' + (fav ? " is-on" : "") + '" data-fav="' + i.id + '" role="button" aria-label="' + esc(t.favorite || "Favorite") + '">' + ICONS.heart + "</span>" +
        hhBadgeHTML(i) +
      "</span>" +
      '<span class="card-name">' + esc(i.name) + "</span>" +
      (sub ? '<span class="card-sub">' + esc(sub) + "</span>" : "") +
      '<span class="card-foot"><span class="card-price">' + (hasOptions(i) ? '<span class="from">' + esc(t.starting_from) + "</span>" : "") + hhWasHTML(i) + money(i.price) + "</span> " + buyHTML(i) + "</span>" +
      "</a>";
  }
  function visibleItems() {
    var q = S.search.trim().toLowerCase();
    var list = ITEMS.filter(function (i) {
      // Two-tier: the arc parent chip matches by parent OR any child (catMatch/kids);
      // the sub-chip then narrows to one real category (i.cat).
      if (S.cat !== "all") {
        if (!catMatch(i, S.cat)) return false;
        if (S.sub !== "root" && String(i.cat) !== String(S.sub)) return false;
      }
      if (q && (String(i.name) + " " + String(i.desc)).toLowerCase().indexOf(q) === -1) return false;
      var f = S.filters;
      if (f.diets.vegan && !i.is_vegan) return false;
      if (f.diets.halal && !i.is_halal) return false;
      if (f.diets.gluten_free && !i.is_gluten_free) return false;
      if ((i.spice_level || 0) > f.spice) return false;
      for (var aId in f.allergens) { if (f.allergens[aId] && (i.allergies || []).some(function (a) { return String(a.id) === String(aId); })) return false; }
      return true;
    });
    if (S.sort === "price_asc") list.sort(function (a, b) { return a.price - b.price; });
    if (S.sort === "price_desc") list.sort(function (a, b) { return b.price - a.price; });
    if (S.sort === "pop") list.sort(function (a, b) { return (b.is_popular ? 1 : 0) - (a.is_popular ? 1 : 0) || (b.rating || 0) - (a.rating || 0); });
    else if (S.cat === "all") list.sort(function (a, b) { return (b.is_popular ? 1 : 0) - (a.is_popular ? 1 : 0); });
    return list;
  }
  function renderGrid() {
    var list = visibleItems();
    $("#grid").innerHTML = list.map(cardHTML).join("");
    $("#gridEmpty").hidden = list.length > 0;
    var catName = "all";
    for (var i = 0; i < CATS.length; i++) if (CATS[i].id === S.cat) catName = CATS[i].name;
    var title = S.cat === "all" ? t.popular : catName;
    if (S.sub && S.sub !== "root") {
      var scEl = $('#subChips .subchip[data-sub="' + S.sub + '"]');
      if (scEl) title = scEl.textContent.trim();
    }
    $("#gridTitle").textContent = title;
    var f = S.filters;
    var activeF = f.diets.vegan || f.diets.halal || f.diets.gluten_free || f.spice < 3 || Object.keys(f.allergens).some(function (k) { return f.allergens[k]; });
    var dot = $("#filterDot"); if (dot) dot.hidden = !activeF;
    var cnt = $("#filterCount"); if (cnt) cnt.textContent = list.length;
  }
  function skeletons() {
    $("#grid").innerHTML = new Array(4).fill('<div class="card sk"><span class="card-imgwrap"></span><div class="sk-line"></div><div class="sk-line short"></div></div>').join("");
    $("#gridEmpty").hidden = true;
  }
  function buySync(id) {
    var q = qtyOf(id);
    $$('[data-buy="' + id + '"]').forEach(function (b) { b.classList.toggle("is-in", q > 0); var bq = b.querySelector(".b-q"); if (bq) bq.textContent = q; });
  }

  /* ── promos ───────────────────────────────────────────── */
  var promoTimer;
  function renderPromos() {
    var track = $("#promoTrack"), dots = $("#promoDots"), wrap = $("#promos");
    var list = (CFG.banners || []).slice();
    if (!list.length) { if (wrap) wrap.classList.add("is-empty"); return; }
    if (wrap) wrap.classList.remove("is-empty");
    track.innerHTML = list.map(function (p, i) {
      var img = p.img ? '<img class="promo-img" src="' + esc(p.img) + '" alt="" data-csp-error="remove">' : "";
      var inner = '<span class="promo-copy">' +
        (p.subtitle ? '<span class="promo-kicker">' + esc(p.subtitle) + "</span>" : "") +
        '<span class="promo-title">' + esc(p.title) + '</span><span class="promo-btn">' + esc(t.order_now || "Order now") + '</span></span>' + img;
      if (p.link) return '<a class="promo ' + (i % 2 ? "blush" : "") + '" href="' + esc(p.link) + '" target="_blank" rel="noopener">' + inner + "</a>";
      return '<div class="promo ' + (i % 2 ? "blush" : "") + '">' + inner + "</div>";
    }).join("");
    dots.innerHTML = list.map(function (_, i) { return '<i class="' + (i === 0 ? "is-on" : "") + '"></i>'; }).join("");
    clearInterval(promoTimer);
    if (list.length > 1) {
      var idx = 0;
      promoTimer = setInterval(function () {
        idx = (idx + 1) % list.length;
        track.scrollTo({ left: (track.clientWidth + 12) * idx * (document.documentElement.dir === "rtl" ? -1 : 1), behavior: REDUCED ? "instant" : "smooth" });
      }, 4200);
    }
    track.addEventListener("scroll", function () {
      var i = Math.round(Math.abs(track.scrollLeft) / (track.clientWidth + 12));
      $$("#promoDots i").forEach(function (d, di) { d.classList.toggle("is-on", di === Math.min(i, list.length - 1)); });
    }, { passive: true });
  }

  /* ── product view ─────────────────────────────────────── */
  function openProduct(id) { setProduct(id, true); show("product"); itemEvent(id, "view"); }
  function setProduct(id, fresh) {
    var i = item(id); if (!i) return;
    var same = S.current && String(S.current.id) === String(i.id);
    S.current = i;
    if (fresh || !same) { S.pQty = 1; S.pVariant = (i.variants || []).length ? i.variants[0].id : 0; S.pMods = {}; S.pExtras = {}; }

    var imgs = itemImgs(i);
    $("#pGallery").innerHTML = imgs.map(function (g) { return '<img src="' + esc(g) + '" alt=""' + IMG_ERR + ">"; }).join("");
    var multiImg = imgs.length > 1;
    $("#pGalleryDots").innerHTML = multiImg ? imgs.map(function (_, gi) { return '<i class="' + (gi === 0 ? "is-on" : "") + '"></i>'; }).join("") : "";
    /* R8 — prev/next arrows for pointer/desktop users who can't swipe the gallery */
    var gp = $("#pGalPrev"), gn = $("#pGalNext");
    if (gp) gp.hidden = !multiImg; if (gn) gn.hidden = !multiImg;
    $("#pGallery").scrollTo({ left: 0 });
    $("#pFav").classList.toggle("is-on", favs.has(String(id)));
    var st = $("#pStock"); st.hidden = !i.is_sold_out; if (i.is_sold_out) st.textContent = t.sold_out;

    var chips = [
      i.rating ? '<span class="meta-chip star">' + ICONS.star + i.rating + (i.reviews ? " (" + i.reviews + ")" : "") + "</span>" : "",
      i.calories ? '<span class="meta-chip">' + ICONS.fire + i.calories + " kcal</span>" : "",
    ].join("");
    var tags = [
      i.is_vegan && '<span class="diet-tag">' + ICONS.leaf + esc(t.veg) + "</span>",
      i.is_halal && '<span class="diet-tag">' + ICONS.moon + "Halal</span>",
      i.is_gluten_free && '<span class="diet-tag">' + ICONS.gf + "GF</span>",
      (i.spice_level > 0) && '<span class="diet-tag spicy">' + "🌶".repeat(Math.min(i.spice_level, 3)) + "</span>",
    ].filter(Boolean).join("");
    var macros = (i.protein || i.carbs || i.fat) ? '<p class="p-desc" style="padding-top:4px;font-size:12px;color:var(--ink-3)">P ' + (i.protein || 0) + "g · C " + (i.carbs || 0) + "g · F " + (i.fat || 0) + "g</p>" : "";

    /* ORBIT-F19 — these rows were <div>s with no role, no tabindex and no
        keyboard path, so a keyboard/screen-reader customer could not satisfy a
        REQUIRED option group and therefore could not order at all. */
    var groupsHTML = "";
    if ((i.variants || []).length) {
      groupsHTML += '<div class="opt-group" data-kind="variant" role="radiogroup" aria-label="' + esc(t.choose) + '"><div class="opt-head"><b>' + esc(t.choose) + '</b><span class="opt-req">' + esc(t.required) + '</span></div><div class="opt-list">' +
        i.variants.map(function (v) { var on = S.pVariant === v.id; return '<button type="button" class="opt' + (on ? " is-on" : "") + '" role="radio" aria-checked="' + on + '" data-variant="' + v.id + '"><span class="opt-ctl">' + ICONS.check + '</span><span class="opt-name">' + esc(v.label) + '</span><span class="opt-price">' + money(v.price) + "</span></button>"; }).join("") + "</div></div>";
    }
    (i.optionGroups || []).forEach(function (g) {
      var multi = g.max === null || g.max > 1;
      groupsHTML += '<div class="opt-group" data-group="' + g.id + '" data-multi="' + (multi ? 1 : 0) + '" data-min="' + (g.min || 0) + '" data-max="' + (g.max === null ? "" : g.max) + '">' +
        '<div class="opt-head"><b>' + esc(g.title) + '</b><span class="opt-req' + (g.required ? "" : " optional") + '">' + esc(g.required ? t.required : t.optional) + "</span></div><div class=\"opt-list\">" +
        g.options.map(function (o) { return '<button type="button" class="opt" role="' + (multi ? "checkbox" : "radio") + '" aria-checked="false" data-opt="' + o.i + '" data-price="' + o.price + '" data-label="' + esc(o.label) + '"><span class="opt-ctl' + (multi ? " box" : "") + '">' + ICONS.check + '</span><span class="opt-name">' + esc(o.label) + '</span><span class="opt-price">' + (o.price ? "+" + money(o.price) : "") + "</span></button>"; }).join("") + "</div></div>";
    });
    if ((i.extras || []).length) {
      groupsHTML += '<div class="opt-group" data-kind="extras"><div class="opt-head"><b>' + esc(t.extras) + '</b><span class="opt-req optional">' + esc(t.optional) + '</span></div><div class="opt-list">' +
        i.extras.map(function (e) { return '<button type="button" class="opt' + (e.img ? " opt--img" : "") + '" role="checkbox" aria-checked="false" data-extra="' + e.id + '"><span class="opt-ctl box">' + ICONS.check + "</span>" + (e.img ? '<img class="opt-thumb" src="' + esc(e.img) + '" alt="" loading="lazy"' + IMG_ERR + ">" : "") + '<span class="opt-name">' + esc(e.title) + '</span><span class="opt-price">+' + money(e.price) + "</span></button>"; }).join("") + "</div></div>";
    }
    var allergensHTML = (i.allergies || []).length ? '<div class="p-allergens">' + i.allergies.map(function (a) { return '<span class="allergen">' + (a.img ? '<img src="' + esc(a.img) + '" alt="">' : "⚠ ") + esc(a.title) + "</span>"; }).join("") + "</div>" : "";

    /* ORBIT-F17 — .p-sub was the first 42 chars of the very description printed
       three lines below it, so every product page said the same thing twice.
       Keep the teaser ONLY when there is no full description to show. */
    var teaser = i.desc ? "" : subOf(i);
    var reviewsHTML = window.__mbItemReviewsOn === false ? "" : (i.reviewList || []).length
      ? '<section class="product-reviews"><div class="section-head"><h2>' + esc(t.reviews || "Reviews") + '</h2><span>' + esc(i.reviewTotal || i.reviewList.length) + '</span></div>' +
        i.reviewList.map(function (review) {
          return '<article><b>' + esc(review.name) + '</b><span class="review-stars">' +
            "★".repeat(Math.max(1, Math.min(5, Number(review.stars) || 0))) +
            '</span><p>' + esc(review.body || "") + "</p></article>";
        }).join("") + "</section>"
      : '<section class="product-reviews empty-reviews"><div class="section-head"><h2>' + esc(t.reviews || "Reviews") + '</h2></div><p>' + esc(t.no_reviews || "No reviews yet.") + '</p></section>';
    $("#pBody").innerHTML =
      '<button class="ghostbtn product-home-link" data-go="home" type="button">← ' + esc(t.back_menu || "Back to menu") + '</button>' +
      '<div class="p-title-row"><span class="p-name"><h1 class="p-title">' + esc(i.name) + "</h1>" + (teaser ? '<span class="p-sub">' + esc(teaser) + "</span>" : "") + "</span>" +
      '<div class="p-price"><b id="pHeadPrice">' + money(i.price) + "</b></div></div>" +
      (chips ? '<div class="p-meta">' + chips + "</div>" : "") +
      (tags ? '<div class="diet-tags">' + tags + "</div>" : "") +
      (i.desc ? '<p class="p-desc">' + esc(i.desc) + "</p>" : "") + macros + allergensHTML +
      '<div class="p-groups">' + groupsHTML + "</div>" +
      (window.__mbItemReviewsOn === false ? "" : '<div class="rate-row"><span>' + esc(t.rate_dish) + '</span><span class="rate-stars" id="rateStars">' + [1, 2, 3, 4, 5].map(function (n) { return '<button data-rate="' + n + '" type="button" aria-label="' + n + '">' + ICONS.star + "</button>"; }).join("") + '</span><span class="rate-thanks" id="rateThanks" hidden>' + esc(t.thanks) + "</span></div>") +
      reviewsHTML;

    var rel = ITEMS.filter(function (x) { return String(x.cat) === String(i.cat) && x.id !== i.id; }).slice(0, 6);
    $("#relatedRail").innerHTML = rel.map(function (r) { return '<button class="rail-card" data-open="' + r.id + '" type="button"><img src="' + esc(r.img) + '" alt=""' + IMG_ERR + "><b>" + esc(r.name) + '</b><span class="price">' + money(r.price) + "</span></button>"; }).join("");
    var relBlock = $("#relatedBlock"); if (relBlock) relBlock.hidden = rel.length === 0;
    updateProductCta();
  }
  function selectedMods() { var out = []; for (var gid in S.pMods) (S.pMods[gid] || []).forEach(function (m) { out.push(m); }); return out; }
  function selectedExtras() { var i = S.current, out = []; if (!i) return out; (i.extras || []).forEach(function (e) { if (S.pExtras[e.id]) out.push(e); }); return out; }
  function updateProductCta() {
    var i = S.current; if (!i) return;
    var qv = $("#pQtyVal"); if (qv) qv.textContent = S.pQty;
    var unit = unitPrice(i, S.pVariant, selectedMods(), selectedExtras());
    var tot = $("#pTotal"); if (tot) tot.textContent = money(unit * S.pQty);
    /* ORBIT-F18 — the biggest price on the screen used to stay on the base price
       while the CTA showed the real one: the wrong number was the prominent one. */
    var head = $("#pHeadPrice"); if (head) head.textContent = money(unit);
    var btn = $("#addToCart"); if (btn) btn.disabled = !!i.is_sold_out;
  }

  /* ── cart ─────────────────────────────────────────────── */
  function addToCart(id, variantId, mods, extras, qty, fromEl) {
    var i = item(id); if (!i || isSold(i)) { toast(t.sold_out); return; }
    var missing = (i.optionGroups || []).filter(function (g) {
      var need = g.required ? Math.max(1, g.min || 0) : (g.min || 0);
      var have = (mods || []).filter(function (m) { return String(m.groupId) === String(g.id); }).length;
      return have < need;
    });
    if (missing.length) { openProduct(id); toast(t.choose + ": " + missing[0].title); return; }
    var key = id + "|" + (variantId || 0) + "|" + (mods || []).map(function (m) { return m.groupId + ":" + m.optIndex; }).sort().join(",") + "|" + (extras || []).map(function (e) { return e.id; }).sort().join(",");
    var line = cart.filter(function (l) { return l.key === key; })[0];
    if (line) line.qty += qty; else cart.push({ key: key, id: id, qty: qty, variantId: variantId || 0, mods: mods || [], extras: extras || [], note: "" });
    writeCart(); syncBadge(true); buySync(id);
    toast(i.name + " — " + t.added);
    if (fromEl) flyToCart(fromEl);
    itemEvent(id, "add_to_cart");
    if (active === "cart") renderCart();
  }
  function removeOne(id) {
    for (var li = cart.length - 1; li >= 0; li--) { if (String(cart[li].id) === String(id)) { cart[li].qty--; if (cart[li].qty <= 0) cart.splice(li, 1); break; } }
    writeCart(); syncBadge(false); buySync(id);
    if (active === "cart") renderCart();
  }
  /* ORBIT-F10 — coupon and tip are cart-scoped, not session-scoped. They used to
     survive "Clear cart" (and a completed order) in memory AND on screen, so the
     next cart silently carried a discount/tip the customer never entered in it. */
  function resetCartExtras() {
    cart = []; writeCart(); syncBadge(false);
    S.coupon = null; S.tipPct = 0; S.tipCustom = null;
    var ci = $("#couponInput"); if (ci) ci.value = "";
    var cm = $("#couponMsg"); if (cm) { cm.hidden = true; cm.textContent = ""; }
    var cf = $(".coupon-field"); if (cf) cf.classList.remove("is-applied");
    $$("#tipRow button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.tip === "0"); });
    var ct = $("#customTip"); if (ct) { ct.value = ""; ct.hidden = true; }
  }
  function syncBadge(bump) {
    var n = cartCount() + comboCount(), b = $("#cartBadge"), bag = $("#bagDot");
    if (b) { b.hidden = n === 0; b.textContent = n; if (bump && n) { b.classList.remove("bump"); void b.offsetWidth; b.classList.add("bump"); } }
    if (bag) bag.hidden = n === 0;
    /* product footer: a direct jump to the cart, shown once anything is in it */
    var pj = $("#pCartJump"); if (pj) { pj.hidden = n === 0; var pjc = $("#pCartJumpCount"); if (pjc) pjc.textContent = n; }
  }

  /* COMBOS-V2 — read-only "Combos" group appended to the cart list and the
     checkout summary. Tapping a row reopens the combo sheet's basket view,
     which is where quantities are edited. */
  function comboGroupHTML(compact) {
    var lines = comboLines();
    if (!lines.length) return "";
    var h = '<div class="ck-combo-group" data-combo-open style="border-top:1px dashed var(--line,#8883);padding-top:8px;margin-top:8px;cursor:pointer">';
    lines.forEach(function (L) {
      h += '<div class="ck-row ck-row--combo">' +
        '<div class="ck-mid"><b>' + esc(L.name) + "</b>" +
        (L.parts.length ? "<small>" + esc(L.parts.join(" · ")) + "</small>" : "") + "</div>" +
        '<span class="ck-qty">×' + L.qty + "</span>" +
        '<span class="ck-price">' + money(L.price) + "</span></div>";
    });
    if (!compact) h += '<small style="opacity:.65">' + esc(t.edit || "Edit") + " ›</small>";
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
      syncBadge(true);
      renderTotals();
      if (active === "cart") renderCart();
      if (active === "checkout") renderCheckout();
    } catch (e) {}
  });

  function renderCart() {
    var has = cart.length > 0 || comboCount() > 0;
    $("#cartEmpty").hidden = has;
    var tools = $("#cartTools"); if (tools) tools.style.display = has ? "" : "none";
    var cta = $("#cartCta"); if (cta) cta.hidden = !has;
    var clr = $("#clearCart"); if (clr) clr.style.visibility = cart.length ? "" : "hidden";
    $("#cartList").innerHTML = comboGroupHTML(false) + cart.map(function (l, li) {
      var i = item(l.id); if (!i) return "";
      var label = lineLabel(l) || subOf(i);
      var unit = unitPrice(i, l.variantId, l.mods, l.extras);
      /* Happy-hour strike-through: original_price is the pre-schedule BASE
         price, so the pre-discount unit is unit + (original − current base). */
      var wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
        ? unit + (Number(i.original_price) - Number(i.price)) : 0;
      return '<div class="cart-item" data-key="' + esc(l.key) + '" style="animation-delay:' + li * 45 + 'ms">' +
        '<button class="ci-im" data-open="' + i.id + '" type="button" aria-label="' + esc(i.name) + '"><img src="' + esc(i.img) + '" alt=""' + IMG_ERR + ">" +
          (i.schedule_label ? '<span class="ci-hh">' + esc(i.schedule_label) + "</span>" : "") + "</button>" +
        '<div class="ci-main"><div class="ci-top">' +
          '<button class="ci-name" data-open="' + i.id + '" type="button">' + esc(i.name) + "</button>" +
          '<button class="ci-remove" data-rm aria-label="' + esc(t.removed || "Remove") + '" type="button">' + ICONS.trash + "</button></div>" +
        (label ? '<small class="ci-opts">' + esc(label) + "</small>" : "") +
        '<div class="ci-row"><span class="ci-priceline"><span class="ci-price">' + money(unit * l.qty) + "</span>" +
          (wasUnit ? '<del class="ci-was">' + money(wasUnit * l.qty) + "</del>" : "") +
          (l.qty > 1 ? '<small class="ci-unit">' + l.qty + " × " + money(unit) + "</small>" : "") + "</span>" +
        '<span class="ci-stepper"><button class="step-btn" data-cq="-1" aria-label="−" type="button">' + ICONS.minus + "</button><output>" + l.qty + '</output><button class="step-btn" data-cq="1" aria-label="+" type="button">' + ICONS.plus + "</button></span></div>" +
        '<button class="ci-note-btn ' + (l.note ? "has-note" : "") + '" data-note="' + esc(l.key) + '" type="button">' + ICONS.pen + "<span>" + (l.note ? esc(l.note) : esc(t.note_add)) + "</span></button></div>" +
        '<div class="ci-note" hidden><input data-notein="' + esc(l.key) + '" value="' + esc(l.note || "") + '" placeholder="' + esc(t.note_ph) + '"></div>' +
        "</div>";
    }).join("");
    renderOtFields(); renderTotals();
  }
  function renderOtFields() {
    var wr = $("#waiterRow"); if (wr) wr.hidden = S.orderType !== "on-table" || !CFG.allowCallWaiter;
    $$("[data-ot]").forEach(function (b) { b.classList.toggle("is-on", b.dataset.ot === S.orderType); });
    var f = $("#otFields"); if (!f) return;
    /* ORBIT-F30 — the three order types used to render in three different
       treatments (a bare tick row, a filled card, a bare label). One card. */
    if (S.orderType === "on-table") {
      f.innerHTML = '<div class="ot-addr">' + ICONS.pin + "<b>" + esc(t.mode_ontable) + (CFG.defaultTable ? " · " + esc(t.table) + " " + esc(CFG.defaultTable) : "") + "</b></div>";
    } else if (S.orderType === "delivery") {
      /* Same autocomplete + use-my-location widget as checkout. The field is
         rebuilt on every order-type switch, so it is re-wired right here; the
         value and coords live in checkout's #coAddr/#coLat/#coLng, which is
         what the order payload reads. */
      var coVal = ($("#coAddr") || {}).value || "";
      f.innerHTML = '<div class="ot-addr">' + ICONS.pin + "<b>" + esc(t.mode_delivery) + "</b></div>" +
        '<div class="addr-field" id="cartAddrField">' +
        '<input id="cartAddr" value="' + esc(coVal) + '" placeholder="' + esc(t.addr_ph || "Delivery address") + '" autocomplete="off" autocorrect="off" spellcheck="false">' +
        '<button class="uselocbtn" id="cartUseLocation" type="button" title="' + esc(t.use_my_location || "Use my location") + '" aria-label="' + esc(t.use_my_location || "Use my location") + '">' +
          '<svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg></button>' +
        '<div class="addr-suggest" id="cartAddrSuggest" hidden></div></div>';
      wireAddressField($("#cartAddr"), $("#cartAddrSuggest"), $("#cartUseLocation"), null, function (v) {
        var co = $("#coAddr"); if (co) co.value = v;
      });
    } else if (S.orderType === "takeaway") {
      f.innerHTML = '<div class="ot-addr">' + ICONS.pin + "<b>" + esc(t.branch) + "</b></div>";
    } else if (S.orderType === "room_service") {
      /* Room service is fulfilled in the guest's room, so like on-table it needs
         neither an address nor a phone number -- the room rides along on the
         hidden `table` field the room QR pre-fills. */
      f.innerHTML = '<div class="ot-addr">' + ICONS.pin + "<b>" + esc(t.mode_room) + (CFG.defaultTable ? " · " + esc(t.room) + " " + esc(CFG.defaultTable) : "") + "</b></div>";
    } else { f.innerHTML = ""; }
  }
  function totalsHTML() {
    var T = totals();
    var h = '<div class="tr"><span>' + esc(t.subtotal) + "</span><b>" + money(T.sub) + "</b></div>";
    if (T.disc) h += '<div class="tr disc"><span>' + esc(t.discount) + (S.coupon && S.coupon.code ? " (" + esc(S.coupon.code) + ")" : "") + "</span><b>−" + money(T.disc) + "</b></div>";
    if (T.tax) h += '<div class="tr"><span>' + esc(CFG.taxLabel) + "</span><b>" + money(T.tax) + "</b></div>";
    if (T.del) h += '<div class="tr"><span>' + esc(t.delivery) + "</span><b>" + money(T.del) + "</b></div>";
    if (T.tip) h += '<div class="tr"><span>' + esc(t.tip) + "</span><b>" + money(T.tip) + "</b></div>";
    h += '<div class="tr grand"><span>' + esc(t.total) + "</span><b>" + money(T.total) + "</b></div>";
    return h;
  }
  function renderTotals() {
    var box = $("#totalsBox"); if (box) box.innerHTML = totalsHTML();
    var T = totals();
    var ct = $("#cartCtaTotal"); if (ct) ct.textContent = money(T.total);
    var below = T.sub > 0 && CFG.minOrderValue > 0 && T.sub < CFG.minOrderValue;
    var msg = $("#minOrderMsg"); if (msg) { msg.hidden = !below; if (below) msg.textContent = "⚠ " + t.min_order + " " + money(CFG.minOrderValue); }
    var go = $("#goCheckout"); if (go) go.disabled = below;
  }

  /* ── checkout ─────────────────────────────────────────── */
  function paymentMethods() {
    var list = [{ id: "pay_on_counter", label: t.pay_counter, sub: "", icon: "cash" }];
    if (CFG.allowPayment) list.push({ id: "pay_online", label: t.pay_online, sub: t.pay_online_sub, icon: "card" });
    return list;
  }
  function renderCheckout() {
    var addr = $("#addrBlock"); if (addr) addr.style.display = S.orderType === "delivery" ? "" : "none";
    var tbl = $("#tableField"); if (tbl) tbl.style.display = S.orderType === "on-table" ? "" : "none";
    var ph = $("#phoneField"); if (ph) ph.style.display = (S.orderType !== "on-table" && S.orderType !== "room_service") ? "" : "none";
    var pm = $("#pmList");
    if (pm) pm.innerHTML = paymentMethods().map(function (m) {
      return '<button class="pm ' + (S.pay === m.id ? "is-on" : "") + '" data-pm="' + m.id + '" type="button"><span class="pm-ic">' + ICONS[m.icon] + "</span><b>" + esc(m.label) + (m.sub ? "<small>" + esc(m.sub) + "</small>" : "") + '</b><span class="pm-radio"></span></button>';
    }).join("");
    /* Order summary — what the customer is about to pay for, tap Edit → cart. */
    var ck = $("#ckItems");
    if (ck) {
      ck.hidden = cart.length === 0 && comboCount() === 0;
      ck.innerHTML = '<div class="ck-head"><h3>' + esc(t.your_order) + '</h3><button class="linkbtn" id="ckEdit" type="button">' + esc(t.edit) + "</button></div>" +
        cart.map(function (l) {
          var i = item(l.id); if (!i) return "";
          var unit = unitPrice(i, l.variantId, l.mods, l.extras);
          /* Happy-hour strike-through: original_price is the pre-schedule BASE
             price, so the pre-discount unit is unit + (original − current base). */
          var wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
            ? unit + (Number(i.original_price) - Number(i.price)) : 0;
          var label = lineLabel(l);
          return '<div class="ck-row">' +
            '<img src="' + esc(i.img) + '" alt=""' + IMG_ERR + ">" +
            '<div class="ck-mid"><b>' + esc(i.name) + "</b>" + (label ? "<small>" + esc(label) + "</small>" : "") + "</div>" +
            '<span class="ck-qty">×' + l.qty + "</span>" +
            '<span class="ck-price">' + money(unit * l.qty) + (wasUnit ? "<del>" + money(wasUnit * l.qty) + "</del>" : "") + "</span></div>";
        }).join("") + comboGroupHTML(true);
      var ed = $("#ckEdit"); if (ed) ed.onclick = function () { show("cart"); };
    }
    $("#checkoutTotals").innerHTML = totalsHTML();
    $("#checkoutCtaTotal").textContent = money(totals().total);
    /* ORBIT-F32 — a stale error from a previous attempt used to sit on screen
       through the next (successful) submit. */
    var err = $("#formError"); if (err) { err.hidden = true; err.textContent = ""; }
  }
  /* ORBIT-F2 — the server's `message` was piped straight into innerHTML, so a
     PHP exception ("foreach() argument must be of type array|object, string
     given") was rendered verbatim, as markup, into the customer's face. Server
     copy is now escaped text AND filtered: anything that smells like a stack
     trace / SQL error / file path is replaced by a human sentence. */
  function safeServerMsg(raw) {
    var m = String(raw == null ? "" : raw).trim();
    if (!m || m.length > 180) return "";
    if (/[<>]/.test(m)) return "";
    if (/(exception|stack trace|SQLSTATE|\.php|on line \d|foreach\(|argument must be|Undefined |Call to |->|::)/i.test(m)) return "";
    return m;
  }
  function showFormError(msg) {
    var err = $("#formError"); if (!err) return;
    err.textContent = msg;
    err.hidden = false;
    err.scrollIntoView({ block: "center", behavior: REDUCED ? "auto" : "smooth" });
  }
  function placeOrder() {
    var err = $("#formError"); err.hidden = true; err.textContent = "";
    if (!S.orderType) { err.textContent = t.select_type; err.hidden = false; return; }
    if (!cart.length && !comboCount()) return; /* combos-only orders are legal */
    var T = totals();
    if (CFG.minOrderValue > 0 && T.sub < CFG.minOrderValue) { err.textContent = t.min_order + " " + money(CFG.minOrderValue); err.hidden = false; return; }
    var nameEl = $("#coName"), name = nameEl ? nameEl.value.trim() : (CFG.customerName || "");
    if (!name) { err.textContent = t.fill_contact; err.hidden = false; return; }
    if (S.orderType === "delivery" && !$("#coAddr").value.trim()) { err.textContent = t.fill_addr; err.hidden = false; return; }
    if (CFG.authMode === "required" && !CFG.isLoggedIn) { openAuth(); return; }

    var payload = orderLines();
    var fd = new FormData();
    fd.append("_token", CFG.csrf);
    fd.append("items", JSON.stringify(payload));
    fd.append("name", name);
    fd.append("ordering-type", S.orderType);
    if (S.orderType === "on-table" || S.orderType === "room_service") fd.append("table", ($("#tableInput") || {}).value || CFG.defaultTable || "");
    else fd.append("phone-number", ($("#coPhone") || {}).value || "");
    if (S.orderType === "delivery") fd.append("address", $("#coAddr").value.trim());
    fd.append("pay_via", S.pay || "pay_on_counter");
    fd.append("tip_amount", String(T.tip || 0));
    fd.append("message", ($("#orderNote") || {}).value || "");
    var sched = $("#scheduledAt"); if (sched && sched.value) fd.append("scheduled_at", sched.value);
    if (S.coupon) fd.append("coupon_code", S.coupon.code);
    var uw = $("#mbUseWallet"); if (uw && uw.checked) fd.append("use_wallet", "1");
    var bid = new URLSearchParams(window.location.search).get("branch_id"); if (bid) fd.append("branch_id", bid);
    var cap = $("#captureEmail"), consent = $("#consentCb");
    if (consent && consent.checked) { fd.append("marketing_consent", "1"); if (cap && cap.value) fd.append("capture_email", cap.value); }
    var wa = $("#waOptin"); if (wa && wa.checked) fd.append("whatsapp_optin", "1");

    var btn = $("#placeOrder"), lbl = btn.querySelector(".po-label"), origLabel = lbl.textContent;
    function restore() { btn.disabled = false; btn.querySelector(".spinner").hidden = true; lbl.textContent = origLabel; }
    btn.disabled = true; btn.querySelector(".spinner").hidden = false; lbl.textContent = "…";
    var httpOk = true;
    fetch(CFG.endpoints.submitOrder, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" }, body: fd })
      .then(function (r) { httpOk = r.ok; return r.json().catch(function () { return null; }); })
      .then(function (res) {
        restore();
        if (res && res.success) {
          rememberRecentOrder(res);
          /* R6 — remember a guest's delivery address on this device so it comes
             back as a chip next time (no-op for signed-in customers). */
          if (S.orderType === "delivery") rememberAddress(($("#coAddr") || {}).value, ($("#coLat") || {}).value, ($("#coLng") || {}).value);
          resetCartExtras();
          ITEMS.forEach(function (i) { buySync(i.id); });
          if (res.message) { window.location.href = res.message; return; }  /* online payment redirect */
          $("#confirmTrack").href = res.track_url || "#";
          var ono = $("#confirmOrderNo");
          if (ono && res.order_id) { ono.hidden = false; ono.textContent = (t.order_label || "Order") + " #" + res.order_id; }
          var pts = $("#confirmPts");
          if (pts && res.points_earned) { pts.hidden = false; pts.textContent = "+" + res.points_earned + " " + (CFG.loyaltyType === "stamps" ? (t.stamps || "stamps") : (t.points || "points")); }
          resetReview();
          show("confirmed"); stack.length = 0; burstConfetti();
          if (res.whatsapp_url) mbWhatsAppHandoff(res.whatsapp_url, res.whatsapp_handoff);
          /* ORBIT-F12 — the screen used to bounce to /track after 3.4s, which
             made the whole review + Google-review + NPS block unreachable UI.
             The customer now leaves via the Track Order CTA when they choose. */
          return;
        }
        /* ORBIT-F3 — a 5xx can leave a PENDING order row behind (the crash
           happens after the row is written, in shared code). Never invite a
           blind retry that would double-order: point at My Orders instead. */
        if (!httpOk) { showFormError(t.order_unsure); return; }
        showFormError(safeServerMsg(res && res.message) || t.order_failed);
      })
      .catch(function () { restore(); showFormError(t.order_network); });
  }
  function burstConfetti() {
    if (REDUCED) return;
    var colors = ["var(--orange)", "#ffd0bf", "#ffffff", "#22b573", "#ff6259"];
    var box = $("#confetti"); if (!box) return;
    box.innerHTML = new Array(30).fill(0).map(function (_, i) {
      var tx = (Math.random() * 2 - 1) * 150, ty = -40 - Math.random() * 200;
      return '<i class="' + (Math.random() > 0.5 ? "round" : "") + '" style="--tx:' + tx + "px;--ty:" + ty + "px;--c:" + colors[i % colors.length] + ";--d:" + (Math.random() * 0.35) + "s;--rz:" + (Math.random() * 360) + "deg;--spin:" + (180 + Math.random() * 400) + 'deg"></i>';
    }).join("");
  }
  function resetReview() {
    $$("#starRow button").forEach(function (b) { b.classList.remove("is-on"); });
    var p = $("#reviewPos"); if (p) p.hidden = true;
    var d = $("#reviewDone"); if (d) d.hidden = true;
    var nt = $("#npsThanks"); if (nt) nt.hidden = true;
    $$("#npsRow button").forEach(function (b) { b.classList.remove("is-on"); });
  }

  /* ── favorites ────────────────────────────────────────── */
  function renderFavs() {
    var list = ITEMS.filter(function (i) { return favs.has(String(i.id)); });
    $("#favGrid").innerHTML = list.map(cardHTML).join("");
    $("#favEmpty").hidden = list.length > 0;
  }

  /* ── offers (loyalty + specials rail) ─────────────────── */
  function renderOffers() {
    var rail = $("#offersRail"); if (!rail) return;
    var sp = ITEMS.filter(function (i) { return i.is_daily_special && !isSold(i); });
    /* ORBIT-F35 — the rail is headed "Daily special"; when the restaurant has
       flagged none it falls back to popular dishes, so the HEADING has to fall
       back too instead of mislabelling 8 arbitrary items as today's special. */
    var head = $("#offersRailTitle");
    if (!sp.length) {
      sp = ITEMS.filter(function (i) { return i.is_popular && !isSold(i); }).slice(0, 8);
      if (head) head.textContent = t.popular;
    } else if (head && head.dataset.label) {
      head.textContent = head.dataset.label;
    }
    var block = $("#offersRailBlock"); if (block) block.hidden = !sp.length;
    rail.innerHTML = sp.map(function (i) {
      return '<button class="rail-card" data-open="' + i.id + '" type="button">' +
        '<span class="rail-fav' + (favs.has(String(i.id)) ? " is-on" : "") + '" data-fav="' + i.id + '">' + ICONS.heart + "</span>" +
        '<img src="' + esc(i.img) + '" alt=""' + IMG_ERR + "><b>" + esc(i.name) + '</b><span class="price">' + money(i.price) + "</span>" +
        (CFG.allowOrder && !isSold(i) ? '<span class="rail-add" data-add="' + i.id + '">' + ICONS.plus + "</span>" : "") + "</button>";
    }).join("");
  }

  /* ── search overlay ───────────────────────────────────── */
  function openSearch() {
    var l = $("#searchLayer"); l.hidden = false;
    setTimeout(function () { l.classList.add("is-open"); var si = $("#searchInput"); if (si) si.focus(); }, 20);
    renderSearch();
  }
  function closeSearch() {
    var l = $("#searchLayer"); l.classList.remove("is-open");
    setTimeout(function () { l.hidden = true; }, 300);
    $("#searchInput").value = ""; S.search = ""; renderGrid();
  }
  function renderSearch() {
    var q = $("#searchInput").value.trim().toLowerCase();
    var pool = q ? ITEMS.filter(function (i) { return (String(i.name) + " " + String(i.desc)).toLowerCase().indexOf(q) !== -1; }) : ITEMS.filter(function (i) { return i.is_popular; });
    /* ORBIT-F28 — the grid's "nothing found" is a designed empty state; search's
       used to be one grey line in a void. Same language on both surfaces now. */
    $("#searchResults").innerHTML = pool.length ? pool.map(function (i, ix) {
      return '<button class="sl-row" data-open="' + i.id + '" type="button" style="animation-delay:' + ix * 30 + 'ms"><img src="' + esc(i.img) + '" alt=""' + IMG_ERR + "><div><b>" + esc(i.name) + "</b><small>" + esc(subOf(i)) + '</small></div><span class="price">' + money(i.price) + "</span></button>";
    }).join("") : '<div class="sl-none"><svg viewBox="0 0 64 64" class="empty-art"><circle cx="28" cy="28" r="17"/><path d="m41 41 12 12"/></svg><p>' + esc(t.search_none) + "</p></div>";
  }

  /* ── order mode ───────────────────────────────────────── */
  var OT_ORDER = ["delivery", "takeaway", "on-table", "room_service"];
  function enabledTypes() { return OT_ORDER.filter(function (x) { return (x === "delivery" && CFG.allowDelivery) || (x === "takeaway" && CFG.allowTakeaway) || (x === "on-table" && CFG.allowOnTable) || (x === "room_service" && CFG.allowRoomService); }); }
  function applyOrderMode() {
    $$(".ot-ic").forEach(function (icn) { icn.hidden = icn.dataset.oti !== S.orderType; });
    var mode = $("#addrMode"), txt = $("#addrText");
    if (!mode || !txt) return;
    if (S.orderType === "delivery") { mode.textContent = t.mode_delivery; txt.textContent = CFG.restaurantName; }
    else if (S.orderType === "takeaway") { mode.textContent = t.mode_takeaway; txt.textContent = CFG.restaurantName; }
    else if (S.orderType === "on-table") { mode.textContent = t.mode_ontable; txt.textContent = CFG.defaultTable ? (t.table + " " + CFG.defaultTable) : CFG.restaurantName; }
    else if (S.orderType === "room_service") { mode.textContent = t.mode_room; txt.textContent = CFG.defaultTable ? (t.room + " " + CFG.defaultTable) : CFG.restaurantName; }
  }

  /* ── delegated clicks ─────────────────────────────────── */
  root.addEventListener("click", function (e) {
    var T = e.target; function closest(s) { return T.closest ? T.closest(s) : null; }

    var binc = closest("[data-binc]");
    if (binc) { e.stopPropagation(); e.preventDefault(); addToCart(binc.dataset.binc, 0, [], [], 1, binc); return; }
    var bdec = closest("[data-bdec]");
    if (bdec) { e.stopPropagation(); e.preventDefault(); removeOne(bdec.dataset.bdec); return; }
    var openbuy = closest("[data-openbuy]");
    if (openbuy) { e.stopPropagation(); e.preventDefault(); openProduct(openbuy.dataset.openbuy); return; }
    var add = closest("[data-add]");
    if (add) { e.stopPropagation(); e.preventDefault(); var ai = item(add.dataset.add); if (!ai) return; if (hasOptions(ai)) { openProduct(ai.id); return; } addToCart(ai.id, 0, [], [], 1, add); return; }

    var fav = closest("[data-fav]");
    if (fav) { e.stopPropagation(); e.preventDefault(); var fid = String(fav.dataset.fav); favs.has(fid) ? favs.delete(fid) : favs.add(fid); writeFavs(); fav.classList.toggle("is-on"); if (active === "favs") renderFavs(); return; }

    /* R7 — same-page ?item= links: grid card anchors + promo/banner/story CTAs.
       Checked AFTER the inner controls above on purpose: a card's ＋/heart sits
       inside the card anchor, so its own handler (each preventDefaults the anchor)
       must win. Same-pathname item links open in place; real external links
       (target set / other page) full-load. The boot half (~1670) handles a cold
       ?item= load, which is also what a new-tab / ⌘-click open lands on. */
    var itemLink = closest('a[href*="item="]');
    if (itemLink && !itemLink.target) {
      try {
        var u = new URL(itemLink.getAttribute("href") || "", window.location.href);
        var iid = u.searchParams.get("item");
        if (iid && u.pathname === window.location.pathname && item(iid)) {
          e.preventDefault(); openProduct(iid); return;
        }
      } catch (err) {}
    }

    var open = closest("[data-open]");
    if (open) { if ($("#searchLayer").classList.contains("is-open")) closeSearch(); openProduct(open.dataset.open); return; }

    /* Profile sub-tabs (Orders / Offers / Profile) and deep-links into them. */
    var accSeg = closest("#acctSeg [data-acct]");
    if (accSeg) { acctTab(accSeg.dataset.acct); return; }
    var accGo = closest("[data-acct-go]");
    if (accGo) { show("profile"); acctTab(accGo.dataset.acctGo); return; }

    var go = closest("[data-go]");
    if (go) { show(go.dataset.go); return; }
    if (closest("[data-back]")) { history.back(); return; }
    var tab = closest(".nav-btn");
    if (tab) {
      var _nt = tab.dataset.tab;
      // Menu jumps to the grid on home; search opens the overlay. Neither is a view.
      if (_nt === "search") { openSearch(); return; }
      if (_nt === "menu") { if (active !== "home") show("home"); var _g = document.querySelector('[data-mb-grid-lead]') || document.getElementById("grid"); if (_g) _g.scrollIntoView({ behavior: "smooth", block: "start" }); return; }
      show(_nt, { push: false }); return;
    }

    var subchip = closest("[data-sub]");
    if (subchip) {
      S.sub = subchip.dataset.sub;
      var grp = subchip.closest(".subgroup");
      if (grp) $$(".subchip", grp).forEach(function (c) { c.classList.toggle("is-on", c === subchip); });
      renderGrid(); return;
    }

    /* product option rows */
    var vOpt = closest("[data-variant]");
    if (vOpt && S.current) { S.pVariant = Number(vOpt.dataset.variant); $$("[data-variant]", vOpt.closest(".opt-list")).forEach(function (o) { var on = Number(o.dataset.variant) === S.pVariant; o.classList.toggle("is-on", on); o.setAttribute("aria-checked", on); }); updateProductCta(); return; }
    var gOpt = closest(".opt-group[data-group] .opt");
    if (gOpt && S.current) {
      var grp = gOpt.closest("[data-group]"), gid = grp.dataset.group, multi = grp.dataset.multi === "1", max = grp.dataset.max === "" ? Infinity : Number(grp.dataset.max);
      var mod = { groupId: gid, optIndex: Number(gOpt.dataset.opt), label: gOpt.dataset.label, price: Number(gOpt.dataset.price) };
      var cur = S.pMods[gid] || [];
      if (multi) { var ix = cur.findIndex(function (m) { return m.optIndex === mod.optIndex; }); if (ix !== -1) cur.splice(ix, 1); else if (cur.length < max) cur.push(mod); else { toast((t.max_pick || "Max") + " " + max); return; } }
      else { cur = cur.length && cur[0].optIndex === mod.optIndex ? [] : [mod]; }
      S.pMods[gid] = cur;
      $$(".opt", grp).forEach(function (o) { var on = cur.some(function (m) { return m.optIndex === Number(o.dataset.opt); }); o.classList.toggle("is-on", on); o.setAttribute("aria-checked", on); });
      updateProductCta(); return;
    }
    var xOpt = closest("[data-extra]");
    if (xOpt && S.current) { var xid = xOpt.dataset.extra; S.pExtras[xid] = !S.pExtras[xid]; xOpt.classList.toggle("is-on", !!S.pExtras[xid]); xOpt.setAttribute("aria-checked", !!S.pExtras[xid]); updateProductCta(); return; }
    var q = closest("#pQty [data-q]");
    if (q) { S.pQty = Math.max(1, S.pQty + Number(q.dataset.q)); updateProductCta(); return; }

    /* product per-dish rating — the inline stars now OPEN the shared review
       sheet with the tapped rating preselected, so a rating and a written
       review are one flow through one endpoint. The old immediate POST to
       CFG.endpoints.review was a second, text-less path that created its own
       pending review; it stays only as a fallback if the shared partial is off. */
    var rate = closest("#rateStars [data-rate]");
    if (rate && S.current) {
      var n = Number(rate.dataset.rate);
      $$("#rateStars button").forEach(function (b, bi) { b.classList.toggle("is-on", bi < n); });
      if (window.mbItemReviews) { window.mbItemReviews.open(n); return; }
      fetch(CFG.endpoints.review, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ menu_id: S.current.id, stars: n }) })
        .then(function (r) { return r.json(); }).then(function (d) { var th = $("#rateThanks"); if (th) { if (d && d.message) th.textContent = d.message; th.hidden = false; } }).catch(function () {});
      return;
    }

    /* cart line stepper */
    var cq = closest("[data-cq]");
    if (cq) {
      var row = cq.closest(".cart-item"), key = row.dataset.key, line = cart.filter(function (l) { return l.key === key; })[0];
      if (!line) return;
      line.qty += Number(cq.dataset.cq);
      if (line.qty <= 0) { row.classList.add("removing"); setTimeout(function () { cart = cart.filter(function (l) { return l.key !== key; }); writeCart(); syncBadge(false); buySync(line.id); renderCart(); toast(t.removed); }, REDUCED ? 0 : 300); }
      else { writeCart(); syncBadge(false); buySync(line.id); renderCart(); }
      return;
    }
    var rm = closest("[data-rm]");
    if (rm) {
      var rmRow = rm.closest(".cart-item"), rmKey = rmRow.dataset.key;
      var rmLine = cart.filter(function (l) { return l.key === rmKey; })[0];
      rmRow.classList.add("removing");
      setTimeout(function () {
        cart = cart.filter(function (l) { return l.key !== rmKey; });
        writeCart(); syncBadge(false); if (rmLine) buySync(rmLine.id); renderCart(); toast(t.removed);
      }, REDUCED ? 0 : 300);
      return;
    }
    var noteBtn = closest("[data-note]");
    if (noteBtn) { var box = noteBtn.closest(".cart-item").querySelector(".ci-note"); box.hidden = !box.hidden; if (!box.hidden) box.querySelector("input").focus(); return; }

    /* order type / payment / tip */
    var ot = closest("[data-ot]");
    if (ot) { S.orderType = ot.dataset.ot; applyOrderMode(); renderOtFields(); renderCheckout(); renderTotals(); return; }
    var pm = closest("[data-pm]");
    if (pm) { S.pay = pm.dataset.pm; $$(".pm").forEach(function (b) { b.classList.toggle("is-on", b === pm); }); return; }
    var tip = closest("#tipRow [data-tip]");
    if (tip) {
      $$("#tipRow button").forEach(function (b) { b.classList.toggle("is-on", b === tip); });
      var custom = $("#customTip");
      if (tip.dataset.tip === "custom") { S.tipPct = 0; S.tipCustom = Number(custom.value) || 0; custom.hidden = false; }
      else { S.tipPct = Number(tip.dataset.tip); S.tipCustom = null; if (custom) custom.hidden = true; }
      renderCheckout(); return;
    }

    /* filters */
    var diet = closest("#dietRow [data-diet]");
    if (diet) { S.filters.diets[diet.dataset.diet] = !S.filters.diets[diet.dataset.diet]; diet.classList.toggle("is-on"); updateFilterCount(); return; }
    var al = closest("#allergenRow [data-al]");
    if (al) { S.filters.allergens[al.dataset.al] = !S.filters.allergens[al.dataset.al]; al.classList.toggle("is-on"); updateFilterCount(); return; }
    var sp = closest("#spiceScale [data-spice]");
    if (sp) { S.filters.spice = Number(sp.dataset.spice); $$("#spiceScale button").forEach(function (b) { b.classList.toggle("is-on", b === sp); }); updateFilterCount(); return; }
    var so = closest("#sortRow [data-sort]");
    if (so) { S.sort = so.dataset.sort; $$("#sortRow button").forEach(function (b) { b.classList.toggle("is-on", b === so); }); updateFilterCount(); return; }

    /* profile rows */
    var prow = closest("[data-prow]");
    if (prow) { var k = prow.dataset.prow; if (k === "settings") sheet("settingsSheet"); else if (k === "branch") sheet("branchSheet"); return; }

    /* review stars on confirmed */
    var st = closest("#starRow [data-star]");
    if (st) {
      var sv = Number(st.dataset.star);
      $$("#starRow button").forEach(function (b, bi) { b.classList.toggle("is-on", bi < sv); });
      var pos = $("#reviewPos"), done = $("#reviewDone");
      if (sv >= 4 && CFG.googleReviewUrl && pos) { pos.hidden = false; if (done) done.hidden = true; }
      else { if (pos) pos.hidden = true; if (done) done.hidden = false; }
      return;
    }
    /* NPS */
    var nps = closest("#npsRow [data-nps]");
    if (nps) {
      $$("#npsRow button").forEach(function (b) { b.classList.remove("is-on"); }); nps.classList.add("is-on");
      fetch(CFG.endpoints.nps, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ score: Number(nps.dataset.nps) }) }).catch(function () {});
      $("#npsThanks").hidden = false;
      return;
    }

    /* language / currency */
    var lg = closest("[data-lang-code]");
    if (lg) {
      setCookie("Quick_lang", lg.dataset.langName);
      setCookie("Quick_user_lang", lg.dataset.langName);
      setCookie("Quick_user_lang_code", lg.dataset.langCode);
      navigateLanguage(lg.dataset.langCode);
      return;
    }
    var cu = closest("[data-cur]");
    if (cu) {
      S.curCode = cu.dataset.cur; S.curRate = parseFloat(cu.dataset.rate) || 1;
      $$("[data-cur]").forEach(function (b) { b.classList.toggle("is-on", b === cu); });
      rerenderMoney(); if (window.mbCurrency) mbCurrency.save(cu.dataset.cur, cu.dataset.rate); return;
    }
  });

  function rerenderMoney() { renderGrid(); renderOffers(); renderCart(); if (S.current) setProduct(S.current.id, false); renderCheckout(); }
  function setCookie(k, v) { document.cookie = k + "=" + encodeURIComponent(v) + ";path=/;max-age=31536000"; }
  function navigateLanguage(code) {
    var url = new URL(window.location.href);
    var parts = url.pathname.split("/");
    /* Prefix mode (/en/slug): swap the code in place. Session mode (no code in
       the path): inserting one 404s — the cookies already set by the caller
       carry the choice, so a plain reload is the whole switch. */
    if (parts.length > 2 && /^[a-z]{2,3}(?:-[a-z]{2})?$/i.test(parts[1])) parts[1] = code;
    url.pathname = parts.join("/");
    url.searchParams.delete("lang");
    window.location.assign(url.toString());
  }
  function updateFilterCount() { var c = $("#filterCount"); if (c) c.textContent = visibleItems().length; }

  /* live note typing */
  var cl = $("#cartList");
  if (cl) {
    cl.addEventListener("input", function (e) { var inp = e.target.closest("[data-notein]"); if (!inp) return; var line = cart.filter(function (l) { return l.key === inp.dataset.notein; })[0]; if (line) { line.note = inp.value; writeCart(); } });
    cl.addEventListener("change", function (e) { if (e.target.closest("[data-notein]")) renderCart(); });
  }

  /* ── single-purpose bindings ──────────────────────────── */
  function on(id, ev, fn) { var el = document.getElementById(id); if (el) el.addEventListener(ev, fn); }
  on("splashSkip", "click", function () { $("#splash").classList.add("is-done"); });
  on("otToggle", "click", function () {
    var types = enabledTypes(); if (!types.length) return;
    var i = types.indexOf(S.orderType); S.orderType = types[(i + 1) % types.length];
    applyOrderMode(); renderOtFields(); renderTotals();
    toast(S.orderType === "delivery" ? t.mode_delivery : S.orderType === "takeaway" ? t.mode_takeaway : S.orderType === "room_service" ? t.mode_room : t.mode_ontable);
  });
  on("addrBtn", "click", function () {
    // crave cvBranchBtn parity: on a multi-branch store this is the branch button,
    // so it opens the branch switcher. Single-branch keeps the cart/address shortcut.
    if (document.getElementById("branchSheet")) { sheet("branchSheet"); return; }
    if (CFG.allowOrder) show("cart");
  });
  on("bagBtn", "click", function () { show("cart"); });
  on("langBtn", "click", function () { sheet("settingsSheet"); });
  on("cvCateringBtn", "click", function () { sheet("cvCateringSheet"); });
  on("searchOpen", "click", function (e) { if (e.target.closest("#filterBtn")) { updateFilterCount(); sheet("filterSheet"); return; } openSearch(); });
  on("searchClose", "click", closeSearch);
  on("searchInput", "input", renderSearch);
  on("favsLink", "click", function () { show("favs"); });
  on("reserveRow", "click", function () { if (document.getElementById("resSheet")) sheet("resSheet"); else if (document.getElementById("wlSheet")) sheet("wlSheet"); });
  /* Reservations + waitlist both on: switch from the reservation sheet to the
     waitlist one (sheet() auto-closes the open sheet when it opens another). */
  on("resToWl", "click", function () { sheet("wlSheet"); });
  on("clearFilters", "click", function () { resetFilters(); S.search = ""; var si = $("#searchInput"); if (si) si.value = ""; renderGrid(); });
  on("filterDone", "click", function () { sheet("filterSheet", false); renderGrid(); });
  on("filterReset", "click", function () { resetFilters(); updateFilterCount(); });
  function resetFilters() {
    S.filters = { diets: {}, allergens: {}, spice: 3 }; S.sort = "featured";
    $$("#dietRow button,#allergenRow button").forEach(function (b) { b.classList.remove("is-on"); });
    $$("#spiceScale button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.spice === "3"); });
    $$("#sortRow button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.sort === "featured"); });
  }
  /* ORBIT-F31 — "Clear" used to wipe a whole cart on a single stray tap.
     Two-step confirm in the theme's own language (no browser confirm()). */
  var clearArmed = null;
  on("clearCart", "click", function (e) {
    var b = e.currentTarget;
    if (!clearArmed) {
      b.dataset.label = b.textContent;
      b.textContent = t.clear_sure;
      b.classList.add("is-armed");
      clearArmed = setTimeout(function () { clearArmed = null; b.textContent = b.dataset.label; b.classList.remove("is-armed"); }, 3500);
      return;
    }
    clearTimeout(clearArmed); clearArmed = null;
    b.textContent = b.dataset.label || b.textContent; b.classList.remove("is-armed");
    resetCartExtras();
    ITEMS.forEach(function (i) { buySync(i.id); });
    renderCart();
    toast(t.cart_cleared);
  });
  on("goCheckout", "click", function () { show("checkout"); });
  on("placeOrder", "click", placeOrder);
  on("customTip", "input", function (e) { S.tipCustom = Number(e.target.value) || 0; renderCheckout(); });
  on("addToCart", "click", function (e) { if (!S.current) return; addToCart(S.current.id, S.pVariant, selectedMods(), selectedExtras(), S.pQty, null); flyToCart(e.currentTarget); });
  on("pFav", "click", function () { if (!S.current) return; var fid = String(S.current.id); favs.has(fid) ? favs.delete(fid) : favs.add(fid); writeFavs(); $("#pFav").classList.toggle("is-on"); renderGrid(); });

  /* coupon (real endpoint) */
  on("couponApply", "click", function () {
    var input = $("#couponInput"), msg = $("#couponMsg"), code = input.value.trim();
    if (!code) return; msg.hidden = true;
    fetch(CFG.endpoints.coupon, { method: "POST", headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf }, body: couponBody(code) })
      .then(function (r) { return r.json(); }).then(function (res) {
        msg.hidden = false;
        /* ORBIT-F27 — "✕" (U+2715) renders as a blue colour-emoji glyph on iOS,
           off-palette in a black/orange theme. "×" stays a text glyph. */
        if (res && res.success) { S.coupon = { code: res.label, coupon_type: res.coupon_type, percentage: res.percentage, discount: Number(res.discount) || 0, sig: couponSig() }; msg.className = "coupon-msg"; msg.textContent = "✓ " + (safeServerMsg(res.message) || code); $(".coupon-field").classList.add("is-applied"); }
        else { S.coupon = null; msg.className = "coupon-msg err"; msg.textContent = "× " + (safeServerMsg(res && res.message) || t.coupon_bad); $(".coupon-field").classList.remove("is-applied"); }
        renderTotals();
      }).catch(function () { msg.hidden = false; msg.className = "coupon-msg err"; msg.textContent = "× " + t.coupon_bad; });
  });

  /* waiter / bill */
  function waiterCall(type) {
    var tbl = ($("#tableInput") || {}).value || CFG.defaultTable || "";
    fetch(CFG.endpoints.waiter, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ table: tbl, type: type, _token: CFG.csrf }) })
      .then(function (r) { return r.json(); }).then(function () { toast(type === "request_bill" ? t.bill_ok : t.waiter_ok); }).catch(function () {});
  }
  on("callWaiter", "click", function () { waiterCall("call_waiter"); });
  on("requestBill", "click", function () { waiterCall("request_bill"); });

  /* guest order lookup */
  /* ORBIT-F21 — the guest Orders tab was a lookup field over ~500px of white
       void. It now opens on a designed empty state and keeps that language for
       "no match" and for a failed request. */
  function lookupEmpty(msg) {
    return '<div class="empty sm"><svg viewBox="0 0 64 64" class="empty-art"><path d="M18 8h28v46l-5-3.4-5 3.4-5-3.4-5 3.4-5-3.4L18 54V8Z"/><path d="M26 20h12M26 30h12"/></svg><p>' + esc(msg) + "</p></div>";
  }
  var recentOrdersKey = "orbit_recent_orders_" + slug;
  function readRecentOrders() {
    try { return JSON.parse(localStorage.getItem(recentOrdersKey) || "[]").filter(function (o) { return o && o.track_url; }); }
    catch (e) { return []; }
  }
  function rememberRecentOrder(res) {
    if (!res || !res.track_url) return;
    var recent = readRecentOrders().filter(function (o) { return o.track_url !== res.track_url; });
    recent.unshift({ id: res.order_id || "", track_url: res.track_url, placed_at: Date.now() });
    try { localStorage.setItem(recentOrdersKey, JSON.stringify(recent.slice(0, 10))); } catch (e) {}
    renderRecentOrders();
  }
  function renderRecentOrders() {
    var box = $("#lookupResults"), recent = readRecentOrders();
    if (!box || !recent.length) return;
    box.innerHTML = recent.map(function (o) {
      return '<a class="order-row" href="' + esc(o.track_url) + '"><span class="or-ic">' +
        ICONS.card + "</span><div><b>" + esc(o.id ? "#" + o.id : (t.track_order || "Track order")) +
        "</b><small>" + esc(new Date(o.placed_at || Date.now()).toLocaleString()) +
        '</small></div><span class="order-status">' + esc(t.track_order || "Track order") + '</span></a>';
    }).join("");
  }
  on("lookupBtn", "click", function () {
    var val = $("#lookupInput").value.trim(); if (!val) return;
    var boxEl = $("#lookupResults"); boxEl.innerHTML = '<div class="empty sm"><p>…</p></div>';
    fetch(CFG.endpoints.orderLookup, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ identifier: val }) })
      .then(function (r) { return r.json(); }).then(function (data) {
        if (!data.success) {
          if (data.otp_required) {
            // S4: the store requires a verified phone. Prompt the diner to verify
            // via the existing phone-OTP auth flow, then re-run the lookup.
            boxEl.innerHTML = lookupEmpty(safeServerMsg(data.message) || t.verify_phone) +
              '<button class="cta wide" id="lookupOtpBtn" type="button" style="margin-top:10px"><span>' + esc(t.verify_phone) + '</span></button>';
            var ob = $("#lookupOtpBtn");
            if (ob) ob.addEventListener("click", function () {
              openAuth();
              var ot = document.querySelector('[data-auth-tab="otp"]');
              if (ot) ot.click();
              var op = $("#otpPhone"); if (op) op.value = val;
            });
            return;
          }
          boxEl.innerHTML = lookupEmpty(safeServerMsg(data.message) || t.no_orders); return;
        }
        boxEl.innerHTML = data.orders.map(function (o) { return '<a class="order-row" href="' + esc(o.track_url) + '"><span class="or-ic">' + ICONS.card + "</span><div><b>#" + esc(o.id) + " · " + esc(o.total) + "</b><small>" + esc(o.date) + '</small></div><span class="order-status">' + esc(o.status) + "</span></a>"; }).join("");
      }).catch(function () { boxEl.innerHTML = lookupEmpty(t.order_network); });
  });

  /* ── auth ─────────────────────────────────────────────── */
  function openAuth() { sheet("authSheet"); }
  on("loginLink", "click", openAuth);
  on("meAuthBtn", "click", function () { if (!CFG.isLoggedIn) openAuth(); });

  /* ── F2 · follow / brand row (crave parity) ─────────────
     Guest → open the login sheet (the follow API needs a customer token).
     Signed-in → optimistic follow/unfollow via the token-auth endpoint. */
  (function () {
    var btn = $("#cvFollowBtn"); if (!btn) return;
    var ep = CFG.endpoints && CFG.endpoints.follow;
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
      if (!CFG.customerToken) { openAuth(); return; }   // guest → login first
      if (!ep) return;
      var unfollow = btn.dataset.following === "1", prev = unfollow;
      btn.dataset.following = unfollow ? "0" : "1";
      btn.classList.toggle("is-on", !unfollow);
      bump(unfollow ? -1 : 1);
      if (!unfollow && !REDUCED) { btn.classList.add("followbtn--pop"); btn.addEventListener("animationend", function h() { btn.classList.remove("followbtn--pop"); btn.removeEventListener("animationend", h); }); }
      fetch(ep, { method: unfollow ? "DELETE" : "POST", headers: headers(), body: "{}" })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!apply(d)) throw new Error("bad"); })
        .catch(function () { btn.dataset.following = prev ? "1" : "0"; btn.classList.toggle("is-on", prev); bump(unfollow ? 1 : -1); toast((t && t.action_failed) || "Failed"); });
    });
  })();
  $$("#authTabs button").forEach(function (b) { b.addEventListener("click", function () { $$("#authTabs button").forEach(function (x) { x.classList.toggle("is-on", x === b); }); $$(".auth-form[data-auth]").forEach(function (f) { f.hidden = f.dataset.auth !== b.dataset.authTab; }); }); });
  function authPost(url, body, errEl) {
    return fetch(url, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf, "X-Requested-With": "XMLHttpRequest" }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); }).then(function (res) { if (res && res.success) { window.location.reload(); return res; } if (errEl) { errEl.textContent = (res && res.message) || (t.failed || "Failed."); errEl.hidden = false; } return res; });
  }
  on("loginBtn", "click", function () { authPost(CFG.endpoints.login, { store_id: CFG.storeId, email: $("#loginEmail").value.trim(), password: $("#loginPass").value }, $("#authErr")); });
  on("registerBtn", "click", function () { authPost(CFG.endpoints.register, { store_id: CFG.storeId, name: $("#regName").value.trim(), email: $("#regEmail").value.trim(), phone: $("#regPhone").value.trim(), password: $("#regPass").value, password_confirmation: ($("#regPass2") || {}).value || "" }, $("#authErr")); });
  on("otpSend", "click", function () {
    var phone = $("#otpPhone").value.trim(); if (!phone) return;
    fetch(CFG.endpoints.otpRequest, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ store_id: CFG.storeId, phone: phone }) })
      .then(function (r) { return r.json(); }).then(function (res) { if (res && res.success) { $("#otpStep1").hidden = true; $("#otpStep2").hidden = false; } else { var e2 = $("#authErr"); e2.textContent = (res && res.message) || (t.failed || "Failed."); e2.hidden = false; } }).catch(function () {});
  });
  on("otpVerify", "click", function () { var code = $$("#otpBoxes input").map(function (i) { return i.value; }).join(""); authPost(CFG.endpoints.otpVerify, { store_id: CFG.storeId, phone: $("#otpPhone").value.trim(), code: code }, $("#authErr")); });
  $$("#otpBoxes input").forEach(function (inp, i, arr) {
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
  on("logoutBtn", "click", function () { fetch(CFG.endpoints.logout, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf } }).then(function () { window.location.reload(); }); });

  /* ── loyalty ──────────────────────────────────────────── */
  (function () {
    if (!CFG.endpoints.loyaltyBalance) return;
    function paint(d) {
      var bal = d.balance || 0;
      var el = $("#ofPts"); if (el) el.textContent = bal;
      var bar = $("#ofBar"); if (bar) bar.style.width = Math.min(100, d.progress_pct || 0) + "%";
      var rb = $("#ofRedeem"); if (rb) rb.hidden = !d.ready_to_redeem;
    }
    fetch(CFG.endpoints.loyaltyBalance).then(function (r) { return r.json(); }).then(function (d) { if (d && d.active) paint(d); }).catch(function () {});
    on("ofRedeem", "click", function () {
      var rb = $("#ofRedeem"); rb.disabled = true;
      fetch(CFG.endpoints.loyaltyRedeem, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: "{}" })
        .then(function (r) { return r.json(); }).then(function (d) {
          if (d && d.success) { rb.hidden = true; var ok = $("#ofOk"); if (ok) ok.hidden = false; paint({ balance: d.new_balance, progress_pct: 0, ready_to_redeem: false }); }
          else { rb.disabled = false; toast((d && d.error) || t.coupon_bad); }
        }).catch(function () { rb.disabled = false; });
    });
  })();

  /* ── nearest-branch map (self-hosted Leaflet) ─────────── */
  (function () {
    var openBtn = $("#orbitNearestBranchBtn");
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
      $("#orbitBranchGeoList").innerHTML = branches.map(function (b) {
        var dist = b.distance_km != null ? '<span class="branch-dist" dir="ltr">' + b.distance_km.toFixed(1) + " km</span>" : "";
        return '<button class="branch-row" data-bid="' + b.id + '" type="button">' +
          '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
          '<b>' + esc(b.name) + (b.address ? '<small>' + esc(b.address) + "</small>" : "") + "</b>" + dist +
          '<span class="branch-check"></span></button>';
      }).join("") || '<p class="sl-none">' + esc(t.no_orders) + "</p>";
    }
    function drawMap(branches) {
      var withCoords = branches.filter(function (b) { return b.latitude != null && b.longitude != null; });
      if (!map) {
        map = L.map("orbitBranchMap", { attributionControl: false });
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 }).addTo(map);
        L.control.attribution({ prefix: false }).addAttribution("© OpenStreetMap").addTo(map);
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
        var attr = $("#orbitMapAttrib"); if (attr) attr.textContent = (d && d.attribution) || "© OpenStreetMap contributors";
        renderList(branches);
        setTimeout(function () { drawMap(branches); if (map) map.invalidateSize(); }, 60);
      }).catch(function () {});
    }
    openBtn.addEventListener("click", function () {
      sheet("orbitBranchMapSheet");
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
          function (p) { load(p.coords.latitude, p.coords.longitude); },
          function () { load(); }, { timeout: 6000 });
      } else { load(); }
    });
    $("#orbitBranchGeoList").addEventListener("click", function (e) {
      var b = e.target.closest("[data-bid]"); if (b) selectBranch(b.dataset.bid);
    });
  })();

  /* ── delivery address autocomplete + use-my-location ────
     Shared by the checkout field (#coAddr, wired once below) and the cart's
     delivery field (#cartAddr, re-wired by renderOtFields each time it is
     rebuilt). Coordinates always land in checkout's hidden #coLat/#coLng pair —
     the order payload reads only those. `onValue` lets the cart field mirror
     its text into #coAddr so the two stay in sync. */
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
      input.value = opt.dataset.label || "";
      sync();
      setCoords(opt.dataset.lat, opt.dataset.lng);
      hideBox();
    });
    input.addEventListener("blur", function () { setTimeout(hideBox, 200); });
    if (locBtn) locBtn.addEventListener("click", function () {
      if (!navigator.geolocation) { toast(t.loc_unavailable || "Location unavailable"); return; }
      locBtn.classList.add("is-loading");
      navigator.geolocation.getCurrentPosition(function (pos) {
        var lat = pos.coords.latitude, lng = pos.coords.longitude;
        fetch((CFG.endpoints.geoReverse || "") + "?lat=" + lat + "&lng=" + lng)
          .then(function (r) { return r.json(); })
          .then(function (d) {
            locBtn.classList.remove("is-loading");
            setAttrib(d && d.attribution);
            if (d && d.result) { input.value = d.result.label || ""; sync(); setCoords(d.result.lat || lat, d.result.lng || lng); }
            else { toast(t.no_address || "No address found"); }
          }).catch(function () { locBtn.classList.remove("is-loading"); });
      }, function () { locBtn.classList.remove("is-loading"); toast(t.loc_denied || "Location denied"); }, { enableHighAccuracy: true, timeout: 8000 });
    });
  }
  (function () {
    var input = $("#coAddr");
    if (!input) return;
    wireAddressField(input, $("#coAddrSuggest"), $("#coUseLocation"), $("#coGeoAttrib"), null);
    /* Saved-address chips: for a signed-in customer these are their server address
       book; for a guest they are addresses used before on this device (see
       renderRecentAddrs below). Tapping either fills the field + hidden coords. */
    var saved = $("#savedAddrs");
    if (saved) saved.addEventListener("click", function (e) {
      var b = e.target.closest(".saved-addr"); if (!b) return;
      input.value = b.dataset.addr || "";
      var la = $("#coLat"), ln = $("#coLng");
      if (la) la.value = b.dataset.lat || ""; if (ln) ln.value = b.dataset.lng || "";
      $$(".saved-addr", saved).forEach(function (x) { x.classList.remove("is-on"); });
      b.classList.add("is-on");
      var box = $("#coAddrSuggest"); if (box) { box.hidden = true; box.innerHTML = ""; }
    });
    renderRecentAddrs();
  })();

  /* ── R6 · guest multi-address (remember addresses used on this device) ─────
     The storage key is computed INLINE via addrKey() — a hoisted function — not a
     top-level `var KEY = ...`: the address IIFE above calls renderRecentAddrs()
     during load, and a var declared here would still be undefined at that point
     (var-hoist gap → 0 chips). Gated on !CFG.isLoggedIn so guest chips never
     fight the server address book. */
  function addrKey() { return "orbit_recent_addrs_" + slug; }
  function readRecentAddrs() {
    try { return (JSON.parse(localStorage.getItem(addrKey()) || "[]") || []).filter(function (a) { return a && a.addr; }); }
    catch (e) { return []; }
  }
  function rememberAddress(addr, lat, lng) {
    addr = (addr || "").trim(); if (!addr || CFG.isLoggedIn) return;
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
    saved.innerHTML = list.map(function (a, i) {
      return '<button class="saved-addr' + (i === 0 ? " is-on" : "") + '" type="button" data-addr="' + esc(a.addr) + '" data-lat="' + esc(a.lat) + '" data-lng="' + esc(a.lng) + '">' +
        '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
        '<span class="sa-text"><b>' + esc(t.recent_address || "Recent address") + '</b><small>' + esc(a.addr.length > 42 ? a.addr.slice(0, 42) + "…" : a.addr) + "</small></span></button>";
    }).join("");
  }

  /* ── stories (rail + full-screen viewer) ──────────────── */
  (function () {
    var rail = $("#storyRail");
    if (!rail || !CFG.endpoints.stories) return;
    var list = [], idx = 0, timer = null, T = 0;
    var DUR = 5; /* seconds per story */

    (window.mbStoriesGet?window.mbStoriesGet(CFG.endpoints.stories):fetch(CFG.endpoints.stories).then(function(r){return r.json();})).then(function (d) {
      list = (d && d.data) || [];
      if (!list.length) { rail.hidden = true; return; }
      rail.innerHTML = list.map(function (s, i) {
        return '<button class="story" data-story="' + i + '" type="button">' +
          '<span class="story-ring"><img src="' + esc(s.media_url) + '" alt=""' + IMG_ERR + "></span>" +
          '<small>' + esc(s.caption ? String(s.caption).slice(0, 14) : (t.popular || "")) + "</small></button>";
      }).join("");
      rail.hidden = false;
    }).catch(function () { rail.hidden = true; });

    var viewer = $("#storyViewer"), shot = $("#svShot"), cap = $("#svCap"), cta = $("#svCta"), bars = $("#svBars");
    if (!viewer) return;

    function buildBars() { bars.innerHTML = list.map(function () { return '<span class="sv-bar"><i></i></span>'; }).join(""); }
    function paintBars() {
      $$(".sv-bar i", bars).forEach(function (b, i) {
        b.style.width = i < idx ? "100%" : (i === idx ? (T / DUR * 100) + "%" : "0%");
      });
    }
    function render() {
      var s = list[idx]; if (!s) return;
      shot.src = s.media_url;
      cap.textContent = s.caption || "";
      if (s.cta_url && !(window.mbStoryCta && window.mbStoryCta.isCurrentPage(s.cta_url))) {
        cta.hidden = false;
        cta.href = s.cta_url;
        if (window.mbStoryCta) window.mbStoryCta.apply(cta, s.cta_url);
        cta.querySelector("span").textContent = s.cta_label || t.checkout;
      } else { cta.hidden = true; }
      T = 0; paintBars();
    }
    function tick() { T += 0.05; if (T >= DUR) { next(); return; } paintBars(); }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function play() { stop(); timer = setInterval(tick, 50); }
    function open(i) {
      idx = i; buildBars();
      viewer.hidden = false;
      void viewer.offsetWidth;
      viewer.classList.add("is-open");
      render(); play();
    }
    function close() {
      stop();
      viewer.classList.remove("is-open");
      setTimeout(function () { viewer.hidden = true; shot.removeAttribute("src"); }, REDUCED ? 0 : 300);
    }
    function next() { if (idx < list.length - 1) { idx++; render(); } else { close(); } }
    function prev() { if (idx > 0) { idx--; render(); } else { T = 0; paintBars(); } }

    rail.addEventListener("click", function (e) {
      var b = e.target.closest("[data-story]"); if (b) open(Number(b.dataset.story));
    });
    on("svClose", "click", close);
    on("svNext", "click", next);
    on("svPrev", "click", prev);
    /* The CTA opens its target via the delegated ?item= intercept (openProduct,
       in place) or, for an off-menu link, a real navigation. Either way this
       full-screen viewer (z-index:75) has to step aside first — otherwise the
       product view renders behind the still-open overlay and the CTA reads as
       dead. Don't preventDefault: the click must still reach the intercept. */
    on("svCta", "click", close);
    var stage = $("#svStage");
    if (stage) {
      stage.addEventListener("pointerdown", stop);
      stage.addEventListener("pointerup", function () { if (!viewer.hidden && !timer) play(); });
    }
  })();

  /* gallery dots + R8 arrows */
  var gal = $("#pGallery");
  function galIndex() { return gal ? Math.round(Math.abs(gal.scrollLeft) / gal.clientWidth) : 0; }
  function galGo(i) {
    if (!gal) return;
    var n = gal.querySelectorAll("img").length;
    i = Math.max(0, Math.min(n - 1, i));
    /* RTL scrolls in the negative direction — mirror the offset so next/prev track visually */
    var dir = getComputedStyle(gal).direction === "rtl" ? -1 : 1;
    gal.scrollTo({ left: dir * i * gal.clientWidth, behavior: REDUCED ? "auto" : "smooth" });
  }
  if (gal) gal.addEventListener("scroll", function () { var i = galIndex(); $$("#pGalleryDots i").forEach(function (d, di) { d.classList.toggle("is-on", di === i); }); }, { passive: true });
  on("pGalPrev", "click", function () { galGo(galIndex() - 1); });
  on("pGalNext", "click", function () { galGo(galIndex() + 1); });

  /* navbar hide on scroll down (root views) */
  var lastY = 0;
  $$(".view-scroll").forEach(function (sc) {
    sc.addEventListener("scroll", function () {
      if (ROOTS.indexOf(active) === -1) return;
      var y = sc.scrollTop, nav = $("#navbar");
      nav.classList.toggle("is-hidden", y > lastY + 4 && y > 140);
      if (y < lastY - 4 || y < 140) nav.classList.remove("is-hidden");
      lastY = y;
    }, { passive: true });
  });
  window.addEventListener("resize", function () { arcRender(); navSync(); });

  /* reorder handoff from account page (P6-301) — delegated to the SHARED
     engine (assets/store_templates/_shared/js/reorder-pickup.js, loaded by
     index.blade.php after this script). The earlier inline pickupReorder
     pushed every line with `mods: []` and `note: ""`, so the choice-group
     selections + per-line note the server-side BuildsReorderPayload forwarded
     were silently dropped. We now translate them onto orbit's
     `mods: [{groupId, optIndex, label, price}]` shape so the badge, the
     lineLabel() summary and the server `orderLines()` payload all carry them. */
  window.mbToast = toast;
  window.__orbitPickup = function (line) {
    var i = item(line.item_id);
    if (!i) return false;
    var R = window.JuwriReorder;
    var mods = R.modsFromOptions(i, line.options);
    if (R.requiredMissing(i, mods)) return false;
    var variantLabel = line.variant ? R.variantLabel(i, line.variant) : "";
    var extras = (line.extras || []).map(function (e) { return { id: Number(e.id), title: e.title, price: Number(e.price) || 0 }; });
    var key = line.item_id + "|" + (line.variant || 0) + "|" + mods.map(function (m) { return m.groupId + ":" + m.optIndex; }).sort().join(",") + "|" + extras.map(function (e) { return e.id; }).sort().join(",");
    var existing = cart.filter(function (l) { return l.key === key; })[0];
    if (existing) { existing.qty += line.qty; }
    else { cart.push({ key: key, id: line.item_id, qty: line.qty, variantId: line.variant || 0, variantLabel: variantLabel, mods: mods, extras: extras, note: line.note || "" }); }
    writeCart(); syncBadge(true); buySync(line.item_id);
    toast(i.name + " \u2014 " + t.added);
    return true;
  };
  function pickupReorder() {
    if (!window.JuwriReorder || !window.JuwriReorder.pickup) { return; }
    window.JuwriReorder.pickup(slug, window.__orbitPickup || function () { return false; }, {
      toast: toast,
    });
  }

  /* ── appearance (ORBIT-F6) ────────────────────────────────────────────────
     Persisted per store in localStorage and applied before first paint by the
     inline bootstrap in index.blade.php, so there is no white flash for a
     dark-mode customer. "auto" simply defers to prefers-color-scheme. */
  /* Persistence + the data-theme attribute are delegated to the SHARED engine
     (_shared/js/mb-theme.js, key mb_theme_<store-id>); the legacy per-slug key
     below is passed as a read-only fallback so existing diners keep their
     choice, and it migrates to the new key on their next explicit change. */
  var themeKey = "orbit-theme-" + slug; /* legacy, read-only */
  var mbT = window.mbTheme || null;
  function applyTheme(mode) {
    if (mbT) mbT.set(mode);
    else {
      /* engine missing (script blocked) — degrade to the old local behaviour */
      if (mode === "auto") root.removeAttribute("data-theme");
      else root.setAttribute("data-theme", mode);
      try { localStorage.setItem(themeKey, mode); } catch (e) {}
    }
    $$("#appearanceSeg button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.theme === mode); });
  }
  (function () {
    if (mbT) mbT.init({ rootSel: "#orbit", legacyKeys: [themeKey] });
    var seg = $("#appearanceSeg"); if (!seg) return;
    var saved = "auto";
    if (mbT) saved = mbT.get();
    else { try { saved = localStorage.getItem(themeKey) || "auto"; } catch (e) {} }
    applyTheme(saved);
    seg.addEventListener("click", function (e) {
      var b = e.target.closest("[data-theme]"); if (b) applyTheme(b.dataset.theme);
    });
  })();
  /* topbar dark/light toggle: flip the EFFECTIVE mode (resolve "auto" via the OS
     preference), then persist the explicit choice through applyTheme (which also
     keeps the profile Auto/Light/Dark segment in sync). */
  on("themeToggle", "click", function () {
    var isDark;
    if (mbT) isDark = mbT.effective() === "dark";
    else {
      var explicit = root.getAttribute("data-theme");
      isDark = explicit ? explicit === "dark"
        : !!(window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches);
    }
    applyTheme(isDark ? "light" : "dark");
  });

  /* ── boot ─────────────────────────────────────────────── */
  function boot() {
    pickupReorder();
    renderRecentOrders();
    syncInert();
    /* default order type: room service > forced table > first enabled.
       allowRoomService is only true when the scanned QR resolved to a hotel
       room, so it is the signal that this guest is in a room. */
    var types = enabledTypes();
    if (types.indexOf("room_service") !== -1) S.orderType = "room_service";
    else if (CFG.forceTable && CFG.allowOnTable) S.orderType = "on-table";
    else if (types.length) S.orderType = types[0];

    buildArc();
    syncSubChips();
    renderPromos();
    skeletons();
    syncBadge(false);
    resetReview();
    applyOrderMode();
    renderOtFields();
    setTimeout(renderGrid, 650);
    setTimeout(navSync, 120);

    /* Deep-link straight to a product from ?item=ID (or #item-ID). A story or
       banner CTA that targets a dish stores a URL like /{slug}?item=ID; the CTA
       is a real link, so it full-loads the menu — without this it would land on
       the home grid and look like "the CTA just reopens the home page". Open the
       item once the grid has rendered. */
    (function () {
      var q = new URLSearchParams(window.location.search).get("item");
      if (!q) { var h = (window.location.hash || "").match(/item-(\d+)/); if (h) q = h[1]; }
      if (q && ITEMS.some(function (i) { return String(i.id) === String(q); })) {
        setTimeout(function () { openProduct(q); }, 700);
      }
    })();

    if (CFG.openResSheet && document.getElementById("resSheet")) { show("home"); setTimeout(function () { sheet("resSheet"); }, 300); }
    if (CFG.openWaitlistSheet && document.getElementById("wlSheet")) { show("home"); setTimeout(function () { sheet("wlSheet"); }, 300); }

    var splash = $("#splash");
    if (splash) {
      /* A1: a ?item=/?table= deep link opens straight on the product — dismiss the
         splash up front so it never overlays it. Not persisted: a later plain visit
         this session still gets the intro. */
      var _dl = null; try { _dl = new URLSearchParams(location.search); } catch (e) {}
      var deepLinked = !!(_dl && (_dl.get("item") || _dl.get("table")));
      if (deepLinked) splash.classList.add("is-done");
      else if (sessionStorage.getItem("mt_splash_" + slug)) splash.classList.add("is-done");
      else { sessionStorage.setItem("mt_splash_" + slug, "1"); setTimeout(function () { splash.classList.add("is-done"); }, 2100); }
    }

    /* replay the saved store-scoped currency choice; runs after #curSeg (in the
       settings bottom-sheet) is populated from the blade, so the click lands. */
    if (window.mbCurrency) mbCurrency.restore("#curSeg");
  }
  boot();

  /* Collapsing header — condensed bar in once the retro cover header leaves the
     home scroll container. orbit's category chips are not top-sticky, so no
     overlap guard. Condensed buttons forward their click to the real controls. */
  (function mountCondensedHeader() {
    var homeView = document.querySelector('.view[data-view="home"]');
    if (!homeView) return;
    var anchor = homeView.querySelector(".orbit-cover") || homeView.querySelector(".topbar");
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
