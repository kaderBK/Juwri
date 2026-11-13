// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ═══════════════════════════════════════════════════════════
   lime — theme runtime (faithful rebuild of rev/lime/demo.js
   wired to real menu data + the juwri ordering contract).
   Reads window.LIME set by index.blade.php.
   ═══════════════════════════════════════════════════════════ */
(function () {
  "use strict";
  var CFG = window.LIME;
  if (!CFG) return;

  var root = document.getElementById("lime");
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
  var REDUCED = matchMedia("(prefers-reduced-motion: reduce)").matches;

  var ICONS = {
    plus: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg>',
    minus: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg>',
    heart: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>',
    star: '<svg class="ic" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>',
    clock: '<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
    flame: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 22c4.4 0 7-2.8 7-6.6 0-4.8-4.2-6.6-4.9-11.4-2.5 1.6-3.4 4-3.2 6.6-1.2-.7-2-1.7-2.4-3.4C6.6 9 5 11.6 5 15.4 5 19.2 7.6 22 12 22Z"/></svg>',
    check: '<svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>',
    check2: '<svg class="ic" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>',
    leaf: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 19C5 9 13 5 20 5c0 8-4 14-12 14-1 0-2-.1-3 0Z"/><path d="M5 19c2-5 6-9 10-11"/></svg>',
    bag: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12v11.6a2.4 2.4 0 0 1-2.4 2.4H8.4A2.4 2.4 0 0 1 6 19.6Z"/><path d="M9.4 8V6.6a2.6 2.6 0 0 1 5.2 0V8"/></svg>',
    pencil: '<svg class="ic" viewBox="0 0 24 24"><path d="M4 20l1-4L16 5l3 3L8 19l-4 1Z"/><path d="M14 7l3 3"/></svg>',
    trash: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M10 7V5h4v2M8 7l.8 13h6.4L16 7"/></svg>',
    wa: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z"/></svg>',
    share: '<svg class="ic" viewBox="0 0 24 24"><circle cx="6" cy="12" r="2.6"/><circle cx="17.5" cy="5.5" r="2.6"/><circle cx="17.5" cy="18.5" r="2.6"/><path d="m8.3 10.7 6.9-4M8.3 13.3l6.9 4"/></svg>',
    storeIc: '<svg class="ic" viewBox="0 0 24 24"><path d="M4 9l1.5-5h13L20 9M5 9v11h14V9"/><path d="M10 20v-6h4v6"/></svg>',
    bike: '<svg class="ic" viewBox="0 0 24 24"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h6V8h5l3 5v4h-2M12 8h-2M9 17l3-6"/></svg>',
    homeIc: '<svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5"/><path d="M6 10v9h12v-9"/></svg>',
    tableIc: '<svg class="ic" viewBox="0 0 24 24"><path d="M4 10h16M12 10v9M5 19h14M7 10 5.5 5h13L17 10"/></svg>',
  };
  var STEP_ICONS = { st_received: "storeIc", st_prep: "flame", st_onway: "bike", st_delivered: "homeIc", st_ready: "bag", st_served: "tableIc", st_done: "check2" };

  var I = CFG.i18n || {};
  function t(k) { return I[k] != null ? I[k] : k; }

  /* ── state / storage ──────────────────────────────────── */
  var slug = CFG.restaurantSlug;
  var cartKey = "mb-cart-" + slug;
  var favKey = "lime-favs-" + slug;
  var ordKey = "lime-orders-" + slug;
  var cart = [];
  try { cart = JSON.parse(localStorage.getItem(cartKey)) || []; } catch (e) {}
  if (!Array.isArray(cart)) cart = [];
  var favs = new Set();
  try { (JSON.parse(localStorage.getItem(favKey)) || []).forEach(function (id) { favs.add(String(id)); }); } catch (e) {}
  var orders = [];
  try { orders = JSON.parse(localStorage.getItem(ordKey)) || []; } catch (e) {}
  if (!Array.isArray(orders)) orders = [];

  var S = {
    cat: "all", sub: "root", search: "", diet: {},
    orderType: null, coupon: null, tipPct: 0,
    current: null, pQty: 1, pVariant: 0, pMods: {}, pExtras: {},
    noteKey: null, moreCtx: "cart", activeOrder: null,
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
  var PCT_SCALE = Math.pow(10, MONEY_DP - 2);
  function pctOf(base, percentage) {
    return Math.round(base * percentage * PCT_SCALE) / (100 * PCT_SCALE);
  }

  function money(base) {
    var v = (Number(base) || 0) * S.curRate;
    var str = v.toFixed(moneyDp(S.curCode));
    if (S.curCode !== CFG.baseCurrency) return "≈ " + S.curCode + " " + str;
    return CFG.currencyPos === "1" ? CFG.currencySign + (CFG.currencyGap || "") + str : str + (CFG.currencyGap || "") + CFG.currencySign;
  }

  /* ── items ────────────────────────────────────────────── */
  var ITEMS = Object.keys(CFG.items).map(function (k) { return CFG.items[k]; });
  function item(id) { return CFG.items[id]; }
  /* Theme-owned placeholder. The platform default is a pale-blue plate disc, which is
     off-brand on a black + neon-lime skin and appears on every card of an image-less
     menu. Inline data: URI — nothing to 404, nothing to fetch. */
  var PLACEHOLDER_IMG = "data:image/svg+xml;utf8," + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120">' +
    '<rect width="120" height="120" rx="18" fill="#101010"/>' +
    '<circle cx="60" cy="60" r="30" fill="none" stroke="#CDF34B" stroke-width="3"/>' +
    '<path d="M46 46v16a6 6 0 0 0 6 6v18M52 46v14M74 46c-3 4-4 9-4 14 0 3 2 5 4 5v21" ' +
    'fill="none" stroke="#CDF34B" stroke-width="3" stroke-linecap="round"/></svg>');
  var FALLBACK_IMG = PLACEHOLDER_IMG;
  var PLATFORM_IMG = CFG.defaultImg || "";
  /* the seeded/placeholder platform image never renders — it is swapped for ours */
  function imgOf(src) { return (!src || (PLATFORM_IMG && src === PLATFORM_IMG) || /\/default\.png(\?|$)/.test(src)) ? PLACEHOLDER_IMG : src; }
  /* default grid heading (shown when no category is filtered) captured before render */
  var GRID_DEFAULT_TITLE = (function () { var el = document.getElementById("gridTitle"); return el ? el.textContent.trim() : ""; })();
  /* one shared handler instead of inlining the whole data: URI into every <img> */
  window.__limeImgFallback = function (el) { el.onerror = null; el.src = PLACEHOLDER_IMG; };
  var IMG_ERR = ' data-csp-error="lime-image-fail"';
  function firstImg(i) { return imgOf((i.gallery && i.gallery.length) ? i.gallery[0] : i.img); }
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
  function soldOut(i) { return !!i.is_sold_out; }

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
      return { id: l.id, quantity: l.qty, variants: l.variantId || 0,
        /* the per-item note sheet toasted "Saved" and then the note was dropped on the
           floor — `order_note` is exactly what OrderItem::create() reads (StoreController
           ~:2143), so "no onions" now actually reaches the kitchen */
        order_note: l.note || null,
        extras: (l.extras || []).map(function (e) { return { id: e.id }; }),
        mods: (l.mods || []).map(function (m) { return { groupId: m.groupId, optIndex: m.optIndex }; }) };
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
  function writeCart() { try { localStorage.setItem(cartKey, JSON.stringify(cart)); } catch (e) {} }
  function writeFavs() { try { localStorage.setItem(favKey, JSON.stringify(Array.from(favs))); } catch (e) {} }
  function writeOrders() { try { localStorage.setItem(ordKey, JSON.stringify(orders.slice(0, 8))); } catch (e) {} }

  /* COMBOS-V2 — the shared combos partial (_partials/combos.blade.php) keeps its
     own basket and publishes display state on window.mbCombos {count,total,lines}.
     The server prices combo lines into the ORDER subtotal (and taxes them like any
     other line), so the quote must fold them in too — QUOTED == CHARGED. Quantity
     edits stay in the combo sheet; here they render read-only. */
  function comboCount() { return (window.mbCombos && window.mbCombos.count) || 0; }
  function comboTotal() { return (window.mbCombos && window.mbCombos.total) || 0; }
  function comboLines() { return (window.mbCombos && window.mbCombos.lines) || []; }

  /* ── TOTALS CONTRACT ──────────────────────────────────────────────────────
     A byte-for-byte reproduction of Order::computeTax() + getTotalAttribute():

        tax   = 'fixed' ? round(charge, dp) : round(subtotal * charge/100, dp)
                — on the RAW subtotal, guarded at charge<=0 || subtotal<=0
        TOTAL = subtotal + delivery + tax + tip − discount   (discount LAST)

     The previous implementation taxed `subtotal − discount` and ignored the
     'fixed' tax type, so the theme quoted less than the server charged on every
     discounted basket (proven: quoted 17.68 / charged 17.75 on order #99579). */
  /* M16: delegates to the shared money core (drops the epsilon nudge for PHP-exact rounding). */
  function roundDp(v) { return mbMoney.round(v, MONEY_DP); }
  function taxOf(sub) {
    var charge = Number(CFG.taxCharge) || 0;
    if (charge <= 0 || sub <= 0) return 0;
    if (CFG.taxType === "fixed") return roundDp(charge);
    return roundDp(sub * (charge / 100));
  }
  function totals() {
    var sub = subtotal(), disc = 0;
    if (S.coupon) {
      disc = S.coupon.coupon_type === "percentage" ? pctOf(sub, S.coupon.percentage) : S.coupon.coupon_type === "bogo" ? bxgyDiscount() : Number(S.coupon.percentage) || 0;
      if (disc > sub) disc = sub;
    }
    var tax = taxOf(sub);                                   /* PRE-discount base */
    var del = S.orderType === "delivery" && (cart.length || comboCount()) ? Number(CFG.deliveryCharge) || 0 : 0;
    var tip = S.tipPct ? pctOf(sub, S.tipPct) : 0;
    return { sub: sub, disc: disc, tax: tax, del: del, tip: tip, total: roundDp(sub + del + tax + tip - disc) };
  }

  /* ── router (roots + under-transition, tabbar) ────────── */
  var stack = [];
  var ROOTS = ["home", "orders", "search", "cart"];
  var active = "home";
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
      if (!opts.fromPop) { try { history.pushState({ lm: 1 }, ""); } catch (er) {} }
    }
    if (isRoot) stack.length = 0;
    $$(".view").forEach(function (v) { v.classList.remove("is-under"); });
    if (!isRoot && from) from.classList.add("is-under");
    if (from) from.classList.remove("is-active");
    to.classList.add("is-active");
    var sc = to.querySelector(".view-scroll"); if (sc) sc.scrollTop = 0;
    active = name;
    var tb = $("#tabbar"); if (tb) tb.classList.toggle("is-hidden", !isRoot);
    $$(".tab").forEach(function (b) { b.classList.toggle("is-on", b.dataset.tab === name); });
    if (name === "cart") renderCart();
    if (name === "orders") renderActivity();
    if (name === "search") { S.search = ""; var si0 = $("#searchInput"); if (si0) si0.value = ""; renderSearch(); setTimeout(function () { var si = $("#searchInput"); if (si) { try { si.focus({ preventScroll: true }); } catch (e) { si.focus(); } } }, 380); }
    if (name === "checkout") renderCheckout();
    if (name === "track") prepTrack();
  }
  function internalBack() { show(stack.pop() || "home", { push: false, fromPop: true }); }
  function back() {
    /* UI back buttons go through history when we pushed a state, so the
       browser's own back stack stays in step with what is on screen. */
    if (stack.length && history.state && history.state.lm) { history.back(); return; }
    internalBack();
  }
  window.addEventListener("popstate", function () {
    if (openSheetId) { sheet(openSheetId, false); try { history.pushState({ lm: 1 }, ""); } catch (er) {} return; }
    if (stack.length) internalBack();
    else if (active !== "home") show("home", { push: false, fromPop: true });
  });

  /* ── sheets / toast ───────────────────────────────────── */
  var openSheetId = null, sheetTimers = {};
  function sheet(id, open) {
    var s = document.getElementById(id), scrim = $("#scrim");
    if (!s) return;
    if (open !== false) {
      if (openSheetId && openSheetId !== id) sheet(openSheetId, false);
      clearTimeout(sheetTimers[id]);
      s.hidden = false; scrim.hidden = false;
      requestAnimationFrame(function () { s.classList.add("is-open"); scrim.classList.add("is-open"); });
      openSheetId = id;
    } else {
      s.classList.remove("is-open"); scrim.classList.remove("is-open");
      clearTimeout(sheetTimers[id]);
      sheetTimers[id] = setTimeout(function () { s.hidden = true; if (!openSheetId || openSheetId === id) scrim.hidden = true; }, 420);
      if (openSheetId === id) openSheetId = null;
    }
  }
  function closeAnySheet() { if (openSheetId) sheet(openSheetId, false); }
  $("#scrim").addEventListener("click", closeAnySheet);
  $$(".sheet-grab").forEach(function (g) { g.addEventListener("click", function () { var s = g.closest(".sheet"); if (s) sheet(s.id, false); }); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") closeAnySheet(); });

  var toastTimer;
  function toast(msg) {
    var el = $("#toast");
    el.innerHTML = ICONS.check2 + "<span>" + esc(msg) + "</span>";
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

  function itemEvent(id, type) {
    if (!CFG.endpoints.itemEvent) return;
    try {
      fetch(CFG.endpoints.itemEvent, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ menu_id: id, type: type }) }).catch(function () {});
    } catch (e) {}
  }

  /* ── cards / grids ────────────────────────────────────── */
  /* PARITY-RUN T5 — happy-hour price display. `i.price` is ALREADY the price the
     server will charge; `i.original_price` is what it was before the schedule,
     present only while a schedule is live. Purely presentational: the cart and
     the order path both take their number from the server, never from here. */
  function hhWasHTML(i) {
    return i.original_price ? '<s style="opacity:.55;font-weight:400;margin-inline-end:.35em">' + money(i.original_price) + "</s>" : "";
  }
  // Happy-hour label — rendered as a ribbon on the thumbnail bottom (see
  // .hh-badge in style.css), not in the price row where it crowded the price.
  function hhBadgeHTML(i) {
    return i.schedule_label
      ? '<span class="hh-badge">' + esc(i.schedule_label) + "</span>"
      : "";
  }
  function cardHTML(i, idx) {
    var fav = favs.has(String(i.id));
    var unavailable = !isAvailableNow(i);
    var hasOpts = (i.variants || []).length || (i.optionGroups || []).length || (i.extras || []).length;
    return '' +
      '<a class="card' + (unavailable ? " is-unavailable" : "") + '" href="' + esc(itemHref(i.id)) + '" data-open="' + i.id + '" style="animation-delay:' + Math.min(idx * 45, 400) + 'ms;color:inherit;text-decoration:none">' +
        '<span class="card-imgwrap">' +
          '<img src="' + esc(firstImg(i)) + '" alt="" loading="lazy"' + IMG_ERR + ">" +
          (soldOut(i) ? '<span class="card-soldout">' + esc(t("sold_out")) + "</span>" : "") +
          (!soldOut(i) && unavailable ? '<span class="avail-badge">⏰ ' + esc(t("not_available")) + "</span>" : "") +
          '<span class="card-badges">' + (i.is_popular ? '<span class="badge-pop">★</span>' : "") + '<span class="badge-veg ' + (i.type === "nonveg" ? "nv" : "") + '"><i></i></span></span>' +
          '<span class="card-fav' + (fav ? " is-on" : "") + '" data-fav="' + i.id + '">' + ICONS.heart + "</span>" +
          hhBadgeHTML(i) +
        "</span>" +
        '<span class="card-name">' + esc(i.name) + "</span>" +
        (i.desc ? '<span class="card-desc">' + esc(i.desc) + "</span>" : "") +
        /* the meta row used to render an empty span for every item without a rating
           or calories — i.e. the whole seeded menu — leaving a blank band in the card */
        ((i.rating || i.calories) ? '<span class="card-meta">' +
          (i.rating ? '<span class="pill-rate">' + ICONS.star + i.rating + "</span>" : "") +
          (i.calories ? "<span>" + i.calories + " " + esc(t("kcal")) + "</span>" : "") +
        "</span>" : "") +
        (hasOpts ? '<span class="card-from">' + esc(t("starting_from")) + "</span>" : "") +
        '<span class="card-price">' + hhWasHTML(i) + money(i.price) + "</span>" +
        (CFG.allowOrder && !soldOut(i) ? '<span class="card-add" data-add="' + i.id + '">' + ICONS.plus + "</span>" : "") +
      "</a>";
  }

  /* the top-level parent object for a chip id (drives the sub-chip row + title) */
  function catObj(id) {
    var cats = CFG.categories || [];
    for (var n = 0; n < cats.length; n++) {
      if (String(cats[n].id) === String(id)) return cats[n];
    }
    return null;
  }
  /* the category ids a parent chip covers — itself plus every descendant, since
     items are filed against the deepest category they belong to */
  function catIds(id) {
    var c = catObj(id);
    return c ? (c.ids || [c.id]).map(String) : [String(id)];
  }
  /* ── dietary filters ──────────────────────────────────────────────────────
     The store has dietary filters enabled and the theme shipped no filter UI at
     all. Only the flags that actually occur in this menu get a chip, so a store
     with no vegan dishes never sees a dead "Vegan" filter. */
  var DIET = [
    { k: "veg", label: "veg", test: function (i) { return i.type !== "nonveg"; } },
    { k: "vegan", label: "vegan", test: function (i) { return !!i.is_vegan; } },
    { k: "halal", label: "halal", test: function (i) { return !!i.is_halal; } },
    { k: "gf", label: "gf", test: function (i) { return !!i.is_gluten_free; } },
  ];
  function dietAvailable() {
    return DIET.filter(function (d) {
      var n = ITEMS.filter(d.test).length;
      return n > 0 && n < ITEMS.length;
    });
  }
  function renderDiet() {
    var row = $("#dietRow"); if (!row) return;
    var list = dietAvailable();
    if (!list.length) { row.hidden = true; row.innerHTML = ""; return; }
    row.hidden = false;
    row.innerHTML = list.map(function (d) {
      return '<button class="dietchip ' + (S.diet[d.k] ? "is-on" : "") + '" data-diet="' + d.k + '" type="button">' +
        ICONS.leaf + esc(t(d.label)) + "</button>";
    }).join("") + (Object.keys(S.diet).length
      ? '<button class="dietchip reset" data-diet-reset type="button">' + esc(t("reset")) + "</button>" : "");
  }

  function homeItems() {
    var q = S.search.trim().toLowerCase();
    var active = DIET.filter(function (d) { return S.diet[d.k]; });
    return ITEMS.filter(function (i) {
      // Two-tier: parent chip matches by descendant set (shows every descendant);
      // the sub-chip then narrows to one real category (i.cat).
      if (S.cat !== "all") {
        if (catIds(S.cat).indexOf(String(i.cat)) === -1) return false;
        if (S.sub !== "root" && String(i.cat) !== String(S.sub)) return false;
      }
      for (var n = 0; n < active.length; n++) { if (!active[n].test(i)) return false; }
      if (q && (String(i.name) + " " + String(i.desc)).toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
  }
  function renderGrid() {
    var list = homeItems();
    $("#grid").innerHTML = list.map(cardHTML).join("");
    $("#gridEmpty").hidden = list.length > 0;
    var titleEl = $("#gridTitle");
    if (titleEl) {
      var title = GRID_DEFAULT_TITLE;
      if (S.cat !== "all") {
        var c = catObj(S.cat);
        title = c ? c.name : title;
        if (S.sub !== "root" && c) {
          var s = (c.subs || []).filter(function (x) { return String(x.id) === String(S.sub); })[0];
          if (s) title = s.name;
        }
      }
      titleEl.textContent = title;
    }
  }
  function renderChips() {
    var cats = [{ id: "all", name: t("all"), image: "", subs: [] }].concat(CFG.categories || []);
    $("#chips").innerHTML = cats.map(function (c) {
      var em = c.id === "all" ? "✦" : "🍽";
      var hasSub = (c.subs && c.subs.length) ? ' data-has-sub="1"' : "";
      /* Show the category IMAGE in a round ring; the emoji sits underneath so a
         category with no image (or one that 404s → data-csp-error="remove") still
         reads. Mirrors the cat-ring pattern the other grid themes use. */
      var media = '<span class="chip-media"><span class="emoji">' + em + "</span>" +
        (c.image ? '<img class="chip-img" src="' + esc(c.image) + '" alt="" loading="lazy" data-csp-error="remove">' : "") +
        "</span>";
      return '<button class="chip ' + (String(c.id) === String(S.cat) ? "is-on" : "") + '" data-cat="' + c.id + '"' + hasSub + '>' + media + esc(c.name) + "</button>";
    }).join("");
    renderSubChips();
  }
  /* Reveal the active parent's subcategory chip row (if it has one). "All"
     (data-sub="root") shows every descendant; a sub-chip narrows to one category. */
  function renderSubChips() {
    var wrap = $("#subchips");
    if (!wrap) return;
    var c = S.cat === "all" ? null : catObj(S.cat);
    if (!c || !c.subs || !c.subs.length) { wrap.hidden = true; wrap.innerHTML = ""; return; }
    wrap.hidden = false;
    wrap.innerHTML = '<button class="subchip ' + (S.sub === "root" ? "is-on" : "") + '" data-sub="root" type="button">' + esc(t("all")) + "</button>" +
      c.subs.map(function (s) {
        return '<button class="subchip ' + (String(s.id) === String(S.sub) ? "is-on" : "") + '" data-sub="' + s.id + '" type="button">' + esc(s.name) + "</button>";
      }).join("");
  }
  function bestSellers() {
    var bs = ITEMS.filter(function (i) { return i.is_popular; });
    if (!bs.length) bs = ITEMS.filter(function (i) { return i.rating; }).slice(0, 8);
    if (!bs.length) bs = ITEMS.slice(0, 8);
    return bs.slice(0, 10);
  }
  function renderBS() {
    $("#bsRail").innerHTML = bestSellers().map(function (i) {
      return '<button class="rail-card" data-open="' + i.id + '">' +
        '<span class="rail-fav' + (favs.has(String(i.id)) ? " is-on" : "") + '" data-fav="' + i.id + '">' + ICONS.heart + "</span>" +
        '<img class="rc-img" src="' + esc(firstImg(i)) + '" alt="" loading="lazy"' + IMG_ERR + "><b>" + esc(i.name) + '</b><span class="price">' + money(i.price) + "</span>" +
        (CFG.allowOrder && !soldOut(i) ? '<span class="rc-add" data-add="' + i.id + '">' + ICONS.plus + "</span>" : "") +
        "</button>";
    }).join("");
  }
  function skeletons() {
    $("#grid").innerHTML = new Array(6).fill('<div class="card sk"><span class="card-imgwrap"></span><div class="sk-line"></div><div class="sk-line short"></div></div>').join("");
  }
  function renderHome() { renderChips(); renderDiet(); renderBS(); renderGrid(); }

  /* daily special badge on hero */
  function initDaily() {
    var sp = ITEMS.filter(function (i) { return i.is_daily_special && !soldOut(i); })[0];
    var badge = $("#dailyBadge");
    if (sp && badge) { badge.hidden = false; badge.dataset.open = sp.id; }
  }

  /* ── search ───────────────────────────────────────────── */
  function renderSearch() {
    /* the whole search view is optional (`search.enabled`), and the currency/boot
       repaints call this blind — bail out when the view was never rendered */
    var chips = $("#popChips"), grid = $("#searchGrid"); if (!chips || !grid) return;
    chips.innerHTML = bestSellers().slice(0, 6).map(function (i) { return '<button data-pop="' + i.id + '">' + esc(i.name) + "</button>"; }).join("");
    var q = S.search.trim().toLowerCase();
    var list = q ? ITEMS.filter(function (i) { return (String(i.name) + " " + String(i.desc)).toLowerCase().indexOf(q) !== -1; }) : ITEMS;
    grid.innerHTML = list.map(cardHTML).join("");
    $("#searchEmpty").hidden = list.length > 0;
  }

  /* ── product / giant counter ──────────────────────────── */
  function openProduct(id) { setProduct(id, true); show("product"); itemEvent(id, "view"); }
  function setProduct(id, fresh) {
    var i = item(id); if (!i) return;
    var same = S.current && S.current.id === i.id;
    S.current = i;
    if (fresh || !same) { S.pQty = 1; S.pVariant = (i.variants || []).length ? i.variants[0].id : 0; S.pMods = {}; S.pExtras = {}; }

    var photo = $("#pPhoto");
    if (FALLBACK_IMG) photo.onerror = function () { this.onerror = null; this.src = FALLBACK_IMG; };
    photo.src = firstImg(i);
    $("#pFav").classList.toggle("is-on", favs.has(String(id)));
    var off = $("#pOff");
    off.hidden = !soldOut(i);
    if (soldOut(i)) off.textContent = t("sold_out");

    $("#pName").textContent = i.name;
    $("#pDesc").innerHTML = i.desc
      ? '<span class="clamp" id="descClamp">' + esc(i.desc) + '</span> <button class="readmore" data-readmore>' + esc(t("read_more")) + "</button>"
      : "";

    var macros = (i.protein || i.carbs || i.fat)
      ? '<span class="meta-chip">P ' + (i.protein || 0) + "g · C " + (i.carbs || 0) + "g · F " + (i.fat || 0) + "g</span>" : "";
    $("#pTags").innerHTML = [
      (i.rating ? '<span class="meta-chip star">' + ICONS.star + i.rating + "</span>" : ""),
      (CFG.prepEta ? '<span class="meta-chip">' + ICONS.clock + esc(CFG.prepEta) + " " + esc(t("min_word")) + "</span>" : ""),
      (i.calories ? '<span class="meta-chip">' + ICONS.flame + i.calories + " " + esc(t("kcal")) + "</span>" : ""),
      i.is_vegan ? '<span class="meta-chip lime">' + ICONS.leaf + esc(t("vegan")) + "</span>" : "",
      i.is_halal ? '<span class="meta-chip lime">' + esc(t("halal")) + "</span>" : "",
      i.is_gluten_free ? '<span class="meta-chip lime">' + esc(t("gf")) + "</span>" : "",
      i.spice_level > 0 ? '<span class="meta-chip">' + "🌶".repeat(Math.min(i.spice_level, 3)) + "</span>" : "",
      macros,
    ].filter(Boolean).join("");

    $("#pGroups").innerHTML = groupsHTML(i);
    $("#giantNum").textContent = S.pQty;
    updateProductCta();
  }

  function groupsHTML(i) {
    var html = "";
    if ((i.variants || []).length) {
      html += '<div class="opt-group" data-kind="variant"><div class="opt-head"><b>' + esc(t("choose")) + '</b><span class="opt-req">' + esc(t("required")) + '</span></div><div class="opt-list">' +
        i.variants.map(function (v) {
          return '<div class="opt' + (S.pVariant === v.id ? " is-on" : "") + '" data-variant="' + v.id + '"><span class="opt-ctl">' + ICONS.check + '</span><span class="opt-name">' + esc(v.label) + '</span><span class="opt-price">' + money(v.price) + "</span></div>";
        }).join("") + "</div></div>";
    }
    (i.optionGroups || []).forEach(function (g) {
      var multi = g.max === null || g.max > 1;
      html += '<div class="opt-group" data-group="' + g.id + '" data-multi="' + (multi ? 1 : 0) + '" data-min="' + (g.min || 0) + '" data-max="' + (g.max === null ? "" : g.max) + '">' +
        '<div class="opt-head"><b>' + esc(g.title) + '</b><span class="opt-req' + (g.required ? "" : " optional") + '">' + esc(g.required ? t("required") : t("optional")) + "</span></div>" +
        '<div class="opt-list">' + g.options.map(function (o) {
          return '<div class="opt" data-opt="' + o.i + '" data-price="' + o.price + '" data-label="' + esc(o.label) + '"><span class="opt-ctl' + (multi ? " box" : "") + '">' + ICONS.check + '</span><span class="opt-name">' + esc(o.label) + '</span><span class="opt-price">' + (o.price ? "+" + money(o.price) : "") + "</span></div>";
        }).join("") + "</div></div>";
    });
    if ((i.extras || []).length) {
      html += '<div class="opt-group" data-kind="extras"><div class="opt-head"><b>' + esc(t("extras")) + '</b><span class="opt-req optional">' + esc(t("optional")) + '</span></div><div class="opt-list">' +
        i.extras.map(function (e) {
          return '<div class="opt' + (e.img ? " opt--img" : "") + '" data-extra="' + e.id + '"><span class="opt-ctl box">' + ICONS.check + "</span>" +
            (e.img ? '<img class="opt-thumb" src="' + esc(e.img) + '" alt=""' + IMG_ERR + ">" : "") +
            '<span class="opt-name">' + esc(e.title) + '</span><span class="opt-price">+' + money(e.price) + "</span></div>";
        }).join("") + "</div></div>";
    }
    if ((i.allergies || []).length) {
      html += '<div class="opt-group"><div class="opt-head"><b>⚠ ' + esc(t("allergens")) + '</b></div><div class="p-allergens">' +
        i.allergies.map(function (a) { return '<span class="allergen">' + (a.img ? '<img src="' + esc(a.img) + '" alt="">' : "") + esc(a.title) + "</span>"; }).join("") + "</div></div>";
    }
    if (window.__mbItemReviewsOn !== false) {
      html += rateRowHTML();
      html += reviewsHTML(i);
    }
    return html;
  }

  /* inline per-item quick-rate — the tapped star opens the shared review
     write-sheet (window.mbItemReviews) pre-selected, so a rating and a written
     review are one flow through one endpoint. Reuses the theme's .rate-row CSS. */
  function rateRowHTML() {
    return '<div class="rate-row"><span>' + esc(t("rate_dish")) + '</span>' +
      '<span class="rate-stars" id="rateStars">' +
      [1, 2, 3, 4, 5].map(function (n) { return '<button data-rate="' + n + '" type="button" aria-label="' + n + '">' + ICONS.star + "</button>"; }).join("") +
      '</span><span class="rate-thanks" id="rateThanks" hidden>' + esc(t("thanks")) + "</span></div>";
  }

  /* approved product reviews — the shared storefront layer hooks on the
     .product-reviews class for the histogram / write-review affordances. */
  function reviewsHTML(i) {
    var list = i.reviewList || [];
    var head = '<div class="opt-head"><b>' + esc(t("reviews_title")) + "</b>" +
      (list.length ? '<span class="opt-req optional">' + (i.reviewTotal || list.length) + "</span>" : "") + "</div>";
    if (!list.length) {
      return '<div class="opt-group product-reviews empty-reviews">' + head +
        '<p class="pr-empty">' + esc(t("reviews_empty")) + "</p></div>";
    }
    return '<div class="opt-group product-reviews">' + head + list.map(function (review) {
      var stars = Math.max(1, Math.min(5, Number(review.stars) || 0));
      return '<article class="pr-card"><b>' + esc(review.name) + '</b><span class="review-stars">' +
        "★".repeat(stars) + '<span class="review-stars-dim">' + "★".repeat(5 - stars) + "</span></span>" +
        (review.body ? "<p>" + esc(review.body) + "</p>" : "") + "</article>";
    }).join("") + "</div>";
  }

  function selectedMods() { var m = []; for (var g in S.pMods) (S.pMods[g] || []).forEach(function (x) { m.push(x); }); return m; }
  function selectedExtras() { var i = S.current, o = []; if (!i) return o; (i.extras || []).forEach(function (e) { if (S.pExtras[e.id]) o.push(e); }); return o; }
  function updateProductCta() {
    var i = S.current; if (!i) return;
    var unit = unitPrice(i, S.pVariant, selectedMods(), selectedExtras());
    var pill = $("#pPricePill"); if (pill) pill.textContent = money(unit);
    var line = $("#tbLine"); if (line) line.textContent = S.pQty + " × " + i.name;
    var price = $("#tbPrice"); if (price) price.textContent = money(unit * S.pQty);
    var btn = $("#addBtn"); if (btn) btn.disabled = soldOut(i);
  }
  function bumpGiant(dir) {
    var g = $("#giantNum");
    g.classList.remove("up", "down"); void g.offsetWidth;
    g.textContent = S.pQty;
    if (!REDUCED) g.classList.add(dir > 0 ? "up" : "down");
  }

  /* ── cart ─────────────────────────────────────────────── */
  function addToCart(id, variantId, mods, extras, qty, fromEl) {
    var i = item(id);
    if (!i || soldOut(i)) { toast(t("sold_out")); return; }
    var missing = (i.optionGroups || []).filter(function (g) {
      var need = g.required ? Math.max(1, g.min || 0) : (g.min || 0);
      var have = (mods || []).filter(function (m) { return String(m.groupId) === String(g.id); }).length;
      return have < need;
    });
    if (missing.length) { toast(t("choose") + ": " + missing[0].title); return; }
    if ((i.variants || []).length && !variantId) { openProduct(id); return; }

    var key = id + "|" + (variantId || 0) + "|" +
      (mods || []).map(function (m) { return m.groupId + ":" + m.optIndex; }).sort().join(",") + "|" +
      (extras || []).map(function (e) { return e.id; }).sort().join(",");
    var line = cart.filter(function (l) { return l.key === key; })[0];
    if (line) line.qty += qty;
    else cart.push({ key: key, id: id, qty: qty, variantId: variantId || 0, mods: mods || [], extras: extras || [], note: "" });
    writeCart(); syncBadge(true);
    toast(i.name + " — " + t("added"));
    if (fromEl) flyToBag(fromEl);
    itemEvent(id, "add_to_cart");
  }

  function flyToBag(fromEl) {
    var img = (fromEl.closest(".card, .rail-card") || {}).querySelector ? fromEl.closest(".card, .rail-card").querySelector("img") : $("#pPhoto");
    if (!img) img = $("#pPhoto");
    var target = $('.tab[data-tab="cart"] .tab-pill');
    if (!img || !target) return;
    var a = img.getBoundingClientRect(), b = target.getBoundingClientRect();
    var lay = $("#flyLayer"), lr = lay.getBoundingClientRect();
    var ghost = document.createElement("img");
    ghost.src = img.src; ghost.className = "fly-img";
    ghost.style.left = a.left - lr.left + a.width / 2 - 32 + "px";
    ghost.style.top = a.top - lr.top + a.height / 2 - 32 + "px";
    lay.appendChild(ghost);
    requestAnimationFrame(function () {
      ghost.style.left = b.left - lr.left + b.width / 2 - 32 + "px";
      ghost.style.top = b.top - lr.top + b.height / 2 - 32 + "px";
      ghost.style.transform = "scale(.14)"; ghost.style.opacity = ".3";
    });
    setTimeout(function () {
      ghost.remove();
      target.classList.remove("ring"); void target.offsetWidth; target.classList.add("ring");
      setTimeout(function () { target.classList.remove("ring"); }, 900);
    }, 720);
  }

  function syncBadge(bump) {
    var n = cartCount() + comboCount(), b = $("#bagBadge");
    if (b) {
      b.hidden = n === 0; b.textContent = n;
      if (bump && n) { b.classList.remove("bump"); void b.offsetWidth; b.classList.add("bump"); }
    }
    /* product footer: a direct jump to the cart, shown once anything is in it
       (mirrors orbit #pCartJump / aurora #au-cart-jump) */
    var pj = $("#pCartJump"); if (pj) { pj.hidden = n === 0; var pjc = $("#pCartJumpCount"); if (pjc) pjc.textContent = n; }
  }

  /* COMBOS-V2 — read-only "Combos" group appended to the cart list and the
     checkout summary. Tapping a row reopens the combo sheet's basket view,
     which is where quantities are edited. (grid override: lime's .ck-row
     reserves a 40px image column the combo rows don't carry) */
  function comboGroupHTML(compact) {
    var lines = comboLines();
    if (!lines.length) return "";
    var h = '<div class="lm-combo-group" data-combo-open style="border-top:1px dashed var(--line,#8883);padding-top:8px;margin-top:8px;cursor:pointer">';
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
    /* the "more" sheet (WhatsApp order / clear bag) acts on real items only */
    var more = $("#cartMore"); if (more) more.style.visibility = cart.length ? "" : "hidden";
    $("#cartList").innerHTML = comboGroupHTML(false) + cart.map(function (l, li) {
      var i = item(l.id); if (!i) return "";
      var opts = lineLabel(l);
      var unit = unitPrice(i, l.variantId, l.mods, l.extras);
      /* Happy-hour strike-through: original_price is the pre-schedule BASE
         price, so the pre-discount unit is unit + (original − current base). */
      var wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
        ? unit + (Number(i.original_price) - Number(i.price)) : 0;
      return '<div class="cart-item" data-key="' + esc(l.key) + '" data-idx="' + li + '" style="animation-delay:' + li * 50 + 'ms">' +
        '<img src="' + esc(firstImg(i)) + '" alt=""' + IMG_ERR + ' data-open="' + i.id + '" role="button" tabindex="0">' +
        '<div><div class="ci-name" data-open="' + i.id + '" role="button" tabindex="0">' + esc(i.name) + "</div>" +
        (opts ? '<div class="ci-opts">' + esc(opts) + "</div>" : "") +
        '<div class="ci-priceline"><span class="ci-price">' + money(unit * l.qty) + "</span>" +
          (wasUnit ? '<del class="ci-was">' + money(wasUnit * l.qty) + "</del>" : "") +
          (l.qty > 1 ? '<small class="ci-unit">' + l.qty + " × " + money(unit) + "</small>" : "") +
        "</div>" +
        (l.note ? '<div class="ci-note">' + ICONS.pencil + esc(l.note) + "</div>" : "") + "</div>" +
        '<div class="ci-side">' +
        '<button class="ci-remove" data-rm aria-label="' + esc(t("removed")) + '">' + ICONS.trash + "</button>" +
        '<div class="qty-min">' +
          '<button data-cq="-1" aria-label="−">' + ICONS.minus + "</button>" +
          "<output>" + l.qty + "</output>" +
          '<button data-cq="1" aria-label="+">' + ICONS.plus + "</button></div>" +
        '<button class="ci-note-btn" data-note="' + li + '">' + ICONS.pencil + "<span>" + esc(t("note")) + "</span></button></div></div>";
    }).join("");
    renderOtFields(); renderTotals();
  }

  function renderOtFields() {
    var f = $("#otFields"); if (!f) return;
    var wr = $("#waiterRow"); if (wr) wr.hidden = S.orderType !== "on-table" || !CFG.allowCallWaiter;
    $$("#otSeg button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.ot === S.orderType); });
    if (S.orderType === "on-table") {
      if (CFG.forceTable) {
        f.innerHTML = '<p class="ot-hint">' + ICONS.check2 + "<span>" + esc(t("table")) + " " + esc(CFG.defaultTable || "") + "</span></p>";
      } else {
        f.innerHTML = '<input id="tableSel" type="number" inputmode="numeric" placeholder="' + esc(t("choose_table")) + '"><p class="ot-hint">' + ICONS.check2 + "<span>" + esc(t("choose_table")) + "</span></p>";
      }
    } else if (S.orderType === "room_service") {
      /* Room service is fulfilled in the guest's room, so like on-table it needs
         neither an address nor a phone; the room rides along on the `table`
         field (the scanned room QR key). */
      f.innerHTML = '<p class="ot-hint">' + ICONS.check2 + "<span>" + esc(t("room_hint")) + "</span></p>";
    } else if (S.orderType === "delivery") {
      /* Full address widget in the cart — same autocomplete + use-my-location as
         checkout. The field is rebuilt on every order-type switch, so it is
         re-wired right here; the value syncs into checkout's #custAddr and the
         picked coordinates land in the hidden #custLat/#custLng pair. */
      var ca = $("#custAddr");
      f.innerHTML = '<div class="addr-field" id="cartAddrField">' +
        '<input id="cartAddrInput" value="' + esc(ca ? ca.value : "") + '" placeholder="' + esc(t("addr_ph")) + '" autocomplete="off" autocorrect="off" spellcheck="false">' +
        '<button class="uselocbtn" id="cartUseLocation" type="button" aria-label="' + esc(t("use_my_location")) + '" title="' + esc(t("use_my_location")) + '">' +
          '<svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg></button>' +
        '<div class="addr-suggest" id="cartAddrSuggest" hidden></div></div>' +
        '<p class="ot-hint">' + ICONS.check2 + "<span>" + esc(t("delivery_hint")) + "</span></p>";
      wireAddressField($("#cartAddrInput"), $("#cartAddrSuggest"), $("#cartUseLocation"), null, function (v) {
        var c = $("#custAddr"); if (c) c.value = v;
      });
    } else if (S.orderType) {
      f.innerHTML = '<p class="ot-hint">' + ICONS.check2 + "<span>" + esc(t("pickup_hint")) + "</span></p>";
    } else { f.innerHTML = ""; }
  }

  function totalsHTML() {
    var T = totals();
    var h = '<div class="tr"><span>' + esc(t("subtotal")) + "</span><b>" + money(T.sub) + "</b></div>";
    if (T.disc) h += '<div class="tr disc"><span>' + esc(t("discount")) + (S.coupon ? " (" + esc(S.coupon.code) + ")" : "") + "</span><b>−" + money(T.disc) + "</b></div>";
    if (T.tax) h += '<div class="tr"><span>' + esc(CFG.taxLabel) + "</span><b>" + money(T.tax) + "</b></div>";
    if (T.del) h += '<div class="tr"><span>' + esc(t("delivery")) + "</span><b>" + money(T.del) + "</b></div>";
    if (T.tip) h += '<div class="tr"><span>' + esc(t("tip")) + "</span><b>" + money(T.tip) + "</b></div>";
    h += '<div class="tr grand"><span>' + esc(t("total")) + "</span><b>" + money(T.total) + "</b></div>";
    return h;
  }
  function renderTotals() {
    var T = totals();
    var box = $("#totalsBox"); if (box) box.innerHTML = totalsHTML();
    var ct = $("#cartCtaTotal"); if (ct) ct.textContent = money(T.total);
    var below = T.sub > 0 && CFG.minOrderValue > 0 && T.sub < CFG.minOrderValue;
    var msg = $("#minOrderMsg");
    if (msg) { msg.hidden = !below; if (below) msg.textContent = "⚠ " + t("min_order") + " " + money(CFG.minOrderValue); }
    var go = $("#goCheckout"); if (go) go.disabled = below;
  }

  /* ── checkout ─────────────────────────────────────────── */
  function renderCheckout() {
    var addr = $("#addrBlock"); if (addr) addr.style.display = S.orderType === "delivery" ? "" : "none";
    var tbl = $("#tableField"); if (tbl) tbl.style.display = S.orderType === "on-table" ? "" : "none";
    var T = totals();
    /* Order summary — what the customer is about to pay for, tap Edit → cart. */
    var ck = $("#ckItems");
    if (ck) {
      ck.hidden = cart.length === 0 && comboCount() === 0;
      ck.innerHTML = '<div class="ck-head"><h3>' + esc(t("your_order")) + '</h3><button class="linkbtn" id="ckEdit" type="button">' + esc(t("edit")) + "</button></div>" +
        cart.map(function (l) {
          var i = item(l.id); if (!i) return "";
          var unit = unitPrice(i, l.variantId, l.mods, l.extras);
          var wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
            ? unit + (Number(i.original_price) - Number(i.price)) : 0;
          var sum = lineLabel(l);
          return '<div class="ck-row">' +
            '<img src="' + esc(firstImg(i)) + '" alt=""' + IMG_ERR + ">" +
            '<div class="ck-mid"><b>' + esc(i.name) + "</b>" + (sum ? "<small>" + esc(sum) + "</small>" : "") + "</div>" +
            '<span class="ck-qty">×' + l.qty + "</span>" +
            '<span class="ck-price">' + money(unit * l.qty) + (wasUnit ? "<del>" + money(wasUnit * l.qty) + "</del>" : "") + "</span></div>";
        }).join("") + comboGroupHTML(true);
      var ed = $("#ckEdit"); if (ed) ed.onclick = function () { show("cart"); };
    }
    var box = $("#checkoutTotals"); if (box) box.innerHTML = totalsHTML();
    var pt = $("#placeTotal"); if (pt) pt.textContent = money(T.total);
  }

  /* the locale-correct "Place Order" wording, read once from the rendered button */
  var PLACE_LABEL = (function () { var l = $(".po-label"); return l ? l.textContent.trim() : t("total"); })();

  /* A server message is only shown verbatim when it is plainly customer-facing.
     `err.innerHTML = res.message` used to put raw PHP exceptions (and any markup
     they carried) in front of the diner. */
  function safeServerMessage(msg) {
    msg = String(msg == null ? "" : msg).trim();
    if (!msg || msg.length > 160) return t("order_failed");
    if (/Exception|SQLSTATE|Stack trace|::|\bfunction\b|\$this|\.php\b|#\d+ \//i.test(msg)) return t("order_failed");
    return msg;
  }

  function placeOrder() {
    var err = $("#formError"); err.hidden = true;
    if (!$("#termsChk").checked) { err.textContent = t("accept_terms"); err.hidden = false; return; }
    if (!S.orderType) { err.textContent = t("select_type"); err.hidden = false; return; }
    if (!cart.length && !comboCount()) return; /* combos-only orders are legal */
    var T = totals();
    if (CFG.minOrderValue > 0 && T.sub < CFG.minOrderValue) { err.textContent = t("min_order") + " " + money(CFG.minOrderValue); err.hidden = false; return; }
    var name = ($("#custName") || {}).value ? $("#custName").value.trim() : (CFG.customerName || "");
    if (!name) { err.textContent = t("fill_contact"); err.hidden = false; return; }
    if (S.orderType === "delivery" && !$("#custAddr").value.trim()) { err.textContent = t("fill_addr"); err.hidden = false; return; }
    if (CFG.authMode === "required" && !CFG.isLoggedIn) { openAuth(); return; }

    var payload = orderLines();

    var fd = new FormData();
    fd.append("_token", CFG.csrf);
    fd.append("items", JSON.stringify(payload));
    fd.append("name", name);
    fd.append("ordering-type", S.orderType);
    if (S.orderType === "on-table" || S.orderType === "room_service") fd.append("table", ($("#tableInput") || $("#tableSel") || {}).value || CFG.defaultTable || "");
    else fd.append("phone-number", ($("#custPhone") || {}).value || "");
    if (S.orderType === "delivery") fd.append("address", $("#custAddr").value.trim());
    fd.append("pay_via", (root.querySelector(".pm.is-on") || { dataset: {} }).dataset.pm || "pay_on_counter");
    fd.append("tip_amount", String(T.tip || 0));
    fd.append("message", ($("#orderNote") || {}).value || "");
    var sched = $("#scheduledAt"); if (sched && sched.value) fd.append("scheduled_at", sched.value);
    if (S.coupon) fd.append("coupon_code", S.coupon.code);
    var uw = $("#useWallet"); if (uw && uw.checked) fd.append("use_wallet", "1");
    var bid = new URLSearchParams(window.location.search).get("branch_id"); if (bid) fd.append("branch_id", bid);
    var consent = $("#consentCb"), capEmail = $("#captureEmail");
    if (consent && consent.checked) { fd.append("marketing_consent", "1"); if (capEmail && capEmail.value) fd.append("capture_email", capEmail.value); }

    var btn = $("#placeOrder");
    btn.disabled = true;
    btn.querySelector(".spinner").hidden = false;
    btn.querySelector(".po-label").textContent = "…";
    /* the label is captured from the server-rendered markup, so it is already in the
       diner's locale — the old code hardcoded an Arabic/English pair and gave every
       other locale English */
    var restore = function () {
      btn.disabled = false; btn.querySelector(".spinner").hidden = true;
      btn.querySelector(".po-label").textContent = PLACE_LABEL;
    };

    fetch(CFG.endpoints.submitOrder, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        restore();
        if (res && res.success) {
          var type = S.orderType, tot = T.total;
          /* R6: remember a guest's delivery address on this device (no-op when
             logged-in or non-delivery) so it returns as a .saved-addr chip. */
          if (type === "delivery") {
            var _ca = $("#custAddr"), _la = $("#custLat"), _ln = $("#custLng");
            rememberAddress(_ca ? _ca.value : "", _la ? _la.value : "", _ln ? _ln.value : "");
          }
          /* snapshot the basket so Reorder can rebuild it exactly */
          var lines = cart.map(function (l) {
            return { id: l.id, qty: l.qty, variantId: l.variantId || 0, mods: l.mods || [], extras: l.extras || [] };
          });
          cart = []; writeCart(); syncBadge(false); S.coupon = null; S.tipPct = 0;
          if (res.message) { window.location.href = res.message; return; } /* online-pay redirect */
          /* NEVER invent a reference. The server does not return an order id yet
             (BLOCKED-SHARED), so the chip stays hidden rather than showing the diner
             a random 4-digit number that matches nothing the restaurant can look up. */
          var ref = res.order_number || res.order_id || res.order || null;
          var order = {
            code: ref ? "#" + ref : "",
            type: type, total: tot, status: "pending", stage: 0,
            placedAt: Date.now(),
            track_url: res.track_url || null,
            eta: etaText(res.prep_eta),
            lines: lines,
          };
          orders.unshift(order); writeOrders();
          S.activeOrder = order;
          var codeEl = $("#confirmCode");
          codeEl.textContent = order.code;
          codeEl.hidden = !order.code;
          $("#confirmEta").textContent = order.eta || "";
          var ctot = $("#confirmTotal"); if (ctot) ctot.textContent = money(tot);
          var full = $("#confirmTrackFull");
          if (full) { full.hidden = !res.track_url; if (res.track_url) full.href = res.track_url; }
          show("confirmed"); stack.length = 0;
          burstConfetti();
          /* WA-T3: lime never used res.whatsapp_url at all — the server sent it and
             the theme ignored it, so a merchant on lime with WhatsApp ordering on
             got orders in the dashboard but never on WhatsApp. */
          if (res.whatsapp_url && typeof mbWhatsAppHandoff === "function") {
            mbWhatsAppHandoff(res.whatsapp_url, res.whatsapp_handoff);
          }
          startTracking(order);
        } else {
          err.textContent = safeServerMessage(res && res.message);
          err.hidden = false;
        }
      })
      .catch(function () { restore(); err.textContent = t("network_error"); err.hidden = false; });
  }

  /* The real ETA, from the server's EtaEstimator payload (submitOrder / track JSON) or
     the store's configured static window — never a hardcoded 25 minutes. */
  function etaText(prepEta) {
    if (prepEta && prepEta.line) return String(prepEta.line);
    var win = (prepEta && prepEta.text) || CFG.prepEta;
    if (!win) return "";
    return t("prep_eta_prefix") + " " + win + " " + t("min_word");
  }

  function burstConfetti() {
    var colors = ["#CDF34B", "#101010", "#2fb56b", "#D6FF4B", "#c8cdd2"];
    $("#confetti").innerHTML = new Array(30).fill(0).map(function (_, i) {
      var tx = (Math.random() * 2 - 1) * 150, ty = -40 - Math.random() * 200;
      return '<i class="' + (Math.random() > 0.5 ? "round" : "") + '" style="--tx:' + tx + "px;--ty:" + ty + "px;--c:" + colors[i % colors.length] + ";--d:" + (Math.random() * 0.35) + "s;--rz:" + (Math.random() * 360) + "deg;--spin:" + (180 + Math.random() * 400) + 'deg"></i>';
    }).join("");
  }

  /* ── in-app order progress (driven by the REAL order status) ──────────────
     The four steps mirror the server's own status ladder 1:1
     (new/pending → preparing → ready → completed), so a stage can only advance
     because the restaurant advanced the order. The previous implementation ran
     a setInterval that walked the diner to "Delivered — enjoy!" 24 seconds after
     checkout while the order was still `pending`. */
  var STAGES = {
    delivery: ["st_received", "st_prep", "st_onway", "st_delivered"],
    takeaway: ["st_received", "st_prep", "st_ready", "st_done"],
    "on-table": ["st_received", "st_prep", "st_served", "st_done"],
    room_service: ["st_received", "st_prep", "st_served", "st_done"],
  };
  var STATUS_STAGE = { unpaid: 0, new: 0, pending: 0, preparing: 1, ready: 2, "on the way": 2, completed: 3, delivered: 3 };
  var COURIER_T = [0.05, 0.35, 0.72, 0.955];
  var routePath = $("#routePath"), courierG = $("#courierG");
  var routeLen = 0, courierT = 0, courierRaf, drawRaf, routeDrawn = false, trackTimer;

  function placeCourier(tt) { if (!routeLen) return; var p = routePath.getPointAtLength(routeLen * tt); courierG.setAttribute("transform", "translate(" + p.x.toFixed(1) + " " + p.y.toFixed(1) + ")"); courierT = tt; }
  function moveCourier(target) {
    if (!routeLen) return;
    if (REDUCED) { placeCourier(target); return; }
    cancelAnimationFrame(courierRaf);
    var from = courierT, delta = target - from, t0 = performance.now(), dur = 1400;
    var step = function (now) { var k = Math.min(1, (now - t0) / dur); placeCourier(from + delta * (1 - Math.pow(1 - k, 3))); if (k < 1) courierRaf = requestAnimationFrame(step); };
    courierRaf = requestAnimationFrame(step);
  }
  function drawRoute() {
    if (!routeLen) { try { routeLen = routePath.getTotalLength(); } catch (e) { return; } }
    cancelAnimationFrame(drawRaf);
    routePath.setAttribute("stroke-dasharray", routeLen);
    routeDrawn = true;
    if (REDUCED) { routePath.setAttribute("stroke-dashoffset", 0); return; }
    var t0 = performance.now(), dur = 1500;
    routePath.setAttribute("stroke-dashoffset", routeLen);
    var step = function (now) { var k = Math.min(1, (now - t0) / dur); routePath.setAttribute("stroke-dashoffset", (routeLen * Math.pow(1 - k, 3)).toFixed(1)); if (k < 1) drawRaf = requestAnimationFrame(step); };
    drawRaf = requestAnimationFrame(step);
  }

  function renderTrack(o) {
    if (!o) return;
    var isDel = o.type === "delivery";
    var chip = $("#trackCode");
    chip.textContent = o.code || "";
    chip.hidden = !o.code;
    $("#mapCard").hidden = !isDel;
    var cap = $("#contactCapsule"); if (cap) cap.hidden = !isDel;
    var full = $("#trackFullLink"); if (full) { full.hidden = !o.track_url; if (o.track_url) full.href = o.track_url; }

    var stages = STAGES[o.type] || STAGES.delivery;
    var cancelled = o.status === "cancelled";
    $("#stepsRow").innerHTML = '<i class="steps-line"></i>' + stages.map(function (sk, si) {
      var st = cancelled ? "" : si < o.stage ? "done" : si === o.stage ? "now" : "";
      var ic = st === "done" ? ICONS.check2 : ICONS[STEP_ICONS[sk]];
      return '<div class="step ' + st + '"><span class="step-ic">' + ic + "</span><b>" + esc(t(sk)) + "</b></div>";
    }).join("");

    /* The banner states the REAL status and the real ETA — no invented progress. */
    var arrived = !cancelled && o.stage >= stages.length - 1;
    var head = cancelled ? t("cancelled") : t(stages[o.stage]);
    $("#arriveMsg").innerHTML = "<b>" + esc(head) + "</b>" +
      (!arrived && !cancelled && o.eta ? "<span> · " + esc(o.eta) + "</span>" : "");

    var live = $("#ccStatus"); if (live) live.textContent = head;
    var dot = $("#ccLive"); if (dot) dot.hidden = arrived || cancelled;

    var rb = $("#reviewBlock"); if (rb) rb.hidden = !arrived;
    if (isDel) {
      if (!routeDrawn) { drawRoute(); placeCourier(COURIER_T[0]); }
      moveCourier(COURIER_T[Math.min(o.stage, COURIER_T.length - 1)]);
    }
  }
  function prepTrack() {
    if (!S.activeOrder) S.activeOrder = orders[0] || null;
    if (!S.activeOrder) { show("orders", { push: false }); return; }
    routeDrawn = false;
    renderTrack(S.activeOrder);
    pollTrack(S.activeOrder);
  }

  /* Ask the server what the status actually is. The track URL answers JSON
     ({status, prep_eta}) to an Accept: application/json request — the same feed the
     server-rendered track page polls. */
  function applyStatus(order, res) {
    if (!res || !res.status) return false;
    var prev = order.stage, prevStatus = order.status;
    order.status = res.status;
    order.stage = STATUS_STAGE[res.status] != null ? STATUS_STAGE[res.status] : order.stage;
    var e = etaText(res.prep_eta); if (e) order.eta = e;
    writeOrders();
    return prev !== order.stage || prevStatus !== order.status;
  }
  function pollTrack(order) {
    if (!order || !order.track_url) return;
    fetch(order.track_url, { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var changed = applyStatus(order, res);
        if (changed || active === "track") {
          if (active === "track" && S.activeOrder === order) renderTrack(order);
          renderActivity();
        }
      })
      .catch(function () {});
  }
  function isFinalOrder(o) {
    var stages = STAGES[o.type] || STAGES.delivery;
    return o.status === "cancelled" || o.stage >= stages.length - 1;
  }
  function startTracking(order) {
    clearInterval(trackTimer);
    if (!order || !order.track_url || isFinalOrder(order)) return;
    pollTrack(order);
    trackTimer = setInterval(function () {
      if (isFinalOrder(order)) { clearInterval(trackTimer); return; }
      pollTrack(order);
    }, 15000);
  }

  /* ── activity (favs + orders + loyalty) ───────────────── */
  function renderActivity() {
    var fl = ITEMS.filter(function (i) { return favs.has(String(i.id)); });
    $("#favGrid").innerHTML = fl.map(cardHTML).join("");
    $("#favEmpty").hidden = fl.length > 0;

    $("#ordersEmpty").hidden = orders.length > 0;
    $("#ordersList").innerHTML = orders.slice(0, 6).map(function (o, oi) {
      var stages = STAGES[o.type] || STAGES.delivery;
      var done = o.status === "cancelled" || o.stage >= stages.length - 1;
      var head = o.code ? esc(o.code) + " · " + money(o.total) : money(o.total);
      var when = o.placedAt ? new Date(o.placedAt).toLocaleDateString([], { month: "short", day: "numeric" }) : "";
      var inner = '<span class="or-ic">' + ICONS.bag + "</span>" +
        "<div><b>" + head + "</b><small>" + esc(o.status === "cancelled" ? t("cancelled") : t(stages[o.stage])) + (when ? " · " + esc(when) : "") + "</small></div>" +
        '<span class="order-status ' + (done ? "done" : "") + '">' + (done ? "✓" : "•") + "</span>";
      var row = '<button class="order-row" data-track="' + oi + '" type="button">' + inner + "</button>";
      /* Reorder rebuilds the exact basket (variant + extras + qty) — a guest has no
         account page to reorder from, so the path has to live here. */
      var re = (o.lines && o.lines.length)
        ? '<button class="or-again" data-reorder="' + oi + '" type="button">' + ICONS.plus + "<span>" + esc(t("reorder")) + "</span></button>"
        : "";
      return '<div class="order-wrap">' + row + re + "</div>";
    }).join("");
  }

  /* ── sheets content ───────────────────────────────────── */
  function openMore(ctx) {
    S.moreCtx = ctx;
    var rows = [];
    if (ctx === "product" && S.current) {
      var fav = favs.has(String(S.current.id));
      rows.push('<button class="sheet-row" data-more="fav">' + ICONS.heart + "<span>" + esc(fav ? t("rm_fav") : t("add_fav")) + "</span></button>");
      rows.push('<button class="sheet-row" data-more="share">' + ICONS.share + "<span>" + esc(t("share")) + "</span></button>");
      if (CFG.waNumber) rows.push('<button class="sheet-row" data-more="wa">' + ICONS.wa + "<span>" + esc(t("wa_order")) + "</span></button>");
    } else {
      if (CFG.waNumber) rows.push('<button class="sheet-row" data-more="wa">' + ICONS.wa + "<span>" + esc(t("wa_order")) + "</span></button>");
      rows.push('<button class="sheet-row danger" data-more="clear">' + ICONS.trash + "<span>" + esc(t("clear_bag")) + "</span></button>");
    }
    $("#moreBody").innerHTML = rows.join("");
    sheet("moreSheet");
  }

  function openWa() {
    if (!CFG.waNumber) return;
    var lines = cart.length
      ? cart.map(function (l) { var i = item(l.id); var o = lineLabel(l); return l.qty + "× " + i.name + (o ? " (" + o + ")" : "") + " — " + money(unitPrice(i, l.variantId, l.mods, l.extras) * l.qty); })
      : (S.current ? [S.pQty + "× " + S.current.name + " — " + money(unitPrice(S.current, S.pVariant, selectedMods(), selectedExtras()) * S.pQty)] : []);
    var total = cart.length ? money(totals().total) : (S.current ? money(unitPrice(S.current, S.pVariant, selectedMods(), selectedExtras()) * S.pQty) : money(0));
    var text = "🛍 " + CFG.restaurantTitle + "\n" + lines.join("\n") + "\n" + t("total") + ": " + total;
    var pre = $("#waPreview"); if (pre) pre.textContent = text;
    var open = $("#waOpen"); if (open) open.href = "https://wa.me/" + CFG.waNumber + "?text=" + encodeURIComponent(text);
    sheet("waSheet");
  }

  /* ── events (delegated) ───────────────────────────────── */
  root.addEventListener("click", function (e) {
    var T = e.target;
    function closest(s) { return T.closest ? T.closest(s) : null; }

    var fav = closest("[data-fav]");
    if (fav) {
      e.stopPropagation();
      e.preventDefault();
      var fid = String(fav.dataset.fav);
      favs.has(fid) ? favs.delete(fid) : favs.add(fid);
      writeFavs(); fav.classList.toggle("is-on");
      if (active === "orders") renderActivity();
      return;
    }
    var add = closest("[data-add]");
    if (add) {
      e.stopPropagation();
      e.preventDefault();
      var ai = item(add.dataset.add); if (!ai) return;
      if ((ai.variants || []).length || (ai.optionGroups || []).length || (ai.extras || []).length) { openProduct(ai.id); return; }
      addToCart(ai.id, 0, [], [], 1, add);
      return;
    }
    /* R7 — same-page ?item= links: grid card anchors + share/CTA links. Checked
       AFTER the controls above so a click on a card's ＋/heart (inside the card
       anchor, each preventDefaults it) wins. Same-pathname item links open in place;
       real external links full-load. Boot handles a cold ?item= / new-tab load. */
    var ilink = closest('a[href*="item="]');
    if (ilink) {
      try {
        var iu = new URL(ilink.href, location.href);
        if (iu.pathname === location.pathname) {
          var iid = iu.searchParams.get("item");
          if (iid && item(iid)) { e.preventDefault(); openProduct(iid); return; }
        }
      } catch (er) {}
    }

    var open = closest("[data-open]");
    if (open) { openProduct(open.dataset.open); return; }
    var go = closest("[data-go]");
    if (go) { show(go.dataset.go); return; }
    if (closest("[data-back]")) { back(); return; }

    var tab = closest(".tab");
    if (tab) {
      var _nt = tab.dataset.tab;
      // Menu jumps to the grid on home; favourites live inside the activity view;
      // profile opens its sheet. The rest are real views.
      if (_nt === "menu") { if (active !== "home") show("home"); var _g = document.querySelector('[data-mb-grid-lead]') || document.getElementById("grid"); if (_g) _g.scrollIntoView({ behavior: "smooth", block: "start" }); return; }
      /* Favourites and Activity live in ONE view (favs + orders + loyalty). Each tab
         lands on the same view but scrolls to ITS OWN section — and works even when
         the view is already open (show() no-ops on the current view, so the scroll
         must run regardless; previously the "other" tab looked dead). */
      if (_nt === "favs" || _nt === "orders") {
        var wasThere = active === "orders";
        if (!wasThere) show("orders");
        var _tgt = document.getElementById(_nt === "favs" ? "favsLabel" : "ordersLabel");
        if (_tgt) setTimeout(function () { _tgt.scrollIntoView({ behavior: "smooth", block: "start" }); }, wasThere ? 0 : 200);
        $$(".tab").forEach(function (b) { b.classList.toggle("is-on", b.dataset.tab === _nt); });
        return;
      }
      if (_nt === "profile") { sheet("profileSheet"); return; }
      show(_nt, { push: false }); return;
    }

    var chip = closest("[data-cat]");
    if (chip) { S.cat = chip.dataset.cat; S.sub = "root"; renderChips(); renderGrid(); return; }

    var subchip = closest("[data-sub]");
    if (subchip) { S.sub = subchip.dataset.sub; renderSubChips(); renderGrid(); return; }

    var diet = closest("[data-diet]");
    if (diet) {
      var dk = diet.dataset.diet;
      if (S.diet[dk]) delete S.diet[dk]; else S.diet[dk] = 1;
      renderDiet(); renderGrid(); return;
    }
    if (closest("[data-diet-reset]")) { S.diet = {}; renderDiet(); renderGrid(); return; }

    var pop = closest("[data-pop]");
    if (pop) { var pi = item(pop.dataset.pop); if (pi) { S.search = pi.name; var si = $("#searchInput"); if (si) si.value = S.search; renderSearch(); } return; }

    if (closest("[data-readmore]")) {
      var c = $("#descClamp"), b = closest("[data-readmore]");
      c.classList.toggle("open");
      b.textContent = c.classList.contains("open") ? t("read_less") : t("read_more");
      return;
    }

    /* product option groups */
    var vOpt = closest("[data-variant]");
    if (vOpt && S.current) {
      S.pVariant = Number(vOpt.dataset.variant);
      $$("[data-variant]", vOpt.closest(".opt-list")).forEach(function (o) { o.classList.toggle("is-on", Number(o.dataset.variant) === S.pVariant); });
      updateProductCta(); return;
    }
    var gOpt = closest(".opt-group[data-group] .opt");
    if (gOpt && S.current) {
      var grp = gOpt.closest("[data-group]");
      var gid = grp.dataset.group, multi = grp.dataset.multi === "1";
      var max = grp.dataset.max === "" ? Infinity : Number(grp.dataset.max);
      var mod = { groupId: gid, optIndex: Number(gOpt.dataset.opt), label: gOpt.dataset.label, price: Number(gOpt.dataset.price) };
      var cur = S.pMods[gid] || [];
      if (multi) {
        var idx = cur.findIndex(function (m) { return m.optIndex === mod.optIndex; });
        if (idx !== -1) cur.splice(idx, 1); else if (cur.length < max) cur.push(mod); else { toast(t("max_word") + " " + max); return; }
      } else { cur = cur.length && cur[0].optIndex === mod.optIndex ? [] : [mod]; }
      S.pMods[gid] = cur;
      $$(".opt", grp).forEach(function (o) { o.classList.toggle("is-on", cur.some(function (m) { return m.optIndex === Number(o.dataset.opt); })); });
      updateProductCta(); return;
    }
    var xOpt = closest("[data-extra]");
    if (xOpt && S.current) { var xid = xOpt.dataset.extra; S.pExtras[xid] = !S.pExtras[xid]; xOpt.classList.toggle("is-on", S.pExtras[xid]); updateProductCta(); return; }

    /* inline per-dish rating — light the tapped run of stars, then open the
       shared review write-sheet pre-selected. Scoped to #rateStars so the
       currency switcher's own data-rate never matches. */
    var rate = closest("#rateStars [data-rate]");
    if (rate) {
      var rn = +rate.dataset.rate;
      $$("#rateStars button").forEach(function (b, bi) { b.classList.toggle("is-on", bi < rn); });
      if (window.mbItemReviews) window.mbItemReviews.open(rn);
      return;
    }

    var gq = closest(".giant [data-g]");
    if (gq) { var d = Number(gq.dataset.g); S.pQty = Math.max(1, S.pQty + d); bumpGiant(d); updateProductCta(); return; }

    /* cart qty */
    var rm = closest("[data-rm]");
    if (rm) {
      var rmRow = rm.closest(".cart-item");
      var rmLine = cart.filter(function (l) { return l.key === rmRow.dataset.key; })[0];
      if (!rmLine) return;
      rmRow.classList.add("removing");
      setTimeout(function () { cart = cart.filter(function (l) { return l !== rmLine; }); writeCart(); syncBadge(false); renderCart(); toast(t("removed")); }, 300);
      return;
    }

    var cq = closest("[data-cq]");
    if (cq) {
      var row = cq.closest(".cart-item");
      var line = cart.filter(function (l) { return l.key === row.dataset.key; })[0];
      if (!line) return;
      line.qty += Number(cq.dataset.cq);
      if (line.qty <= 0) {
        row.classList.add("removing");
        setTimeout(function () { cart = cart.filter(function (l) { return l !== line; }); writeCart(); syncBadge(false); renderCart(); toast(t("removed")); }, 300);
        return;
      }
      writeCart(); syncBadge(false); clearCouponMsg();
      var out = row.querySelector("output"); out.textContent = line.qty;
      out.classList.remove("pop"); void out.offsetWidth; out.classList.add("pop");
      renderTotals(); return;
    }

    var nb = closest("[data-note]");
    if (nb) { S.noteKey = Number(nb.dataset.note); var ni = $("#noteInput"); if (ni) ni.value = (cart[S.noteKey] || {}).note || ""; sheet("noteSheet"); return; }

    var ot = closest("#otSeg [data-ot]");
    if (ot) { S.orderType = ot.dataset.ot; clearCouponMsg(); renderOtFields(); renderCheckout(); renderTotals(); return; }

    var pm = closest("[data-pm]");
    if (pm) { $$(".pm").forEach(function (b) { b.classList.toggle("is-on", b === pm); }); return; }

    var tip = closest("#tipRow [data-tip]");
    if (tip) { S.tipPct = Number(tip.dataset.tip) || 0; $$("#tipRow button").forEach(function (b) { b.classList.toggle("is-on", b === tip); }); clearCouponMsg(); renderCheckout(); return; }

    /* more sheet */
    var mo = closest("[data-more]");
    if (mo) {
      var act = mo.dataset.more;
      if (act === "fav" && S.current) {
        var id = String(S.current.id);
        favs.has(id) ? favs.delete(id) : favs.add(id); writeFavs();
        $("#pFav").classList.toggle("is-on", favs.has(id));
        sheet("moreSheet", false); toast(favs.has(id) ? t("add_fav") : t("rm_fav"));
      }
      if (act === "share") {
        sheet("moreSheet", false);
        var url = window.location.href;
        if (navigator.share) { navigator.share({ url: url }).catch(function () {}); }
        else if (navigator.clipboard) { navigator.clipboard.writeText(url); toast(t("share_ok")); }
        else toast(t("share_ok"));
      }
      if (act === "wa") { sheet("moreSheet", false); setTimeout(openWa, 430); }
      if (act === "clear") { cart = []; writeCart(); syncBadge(false); renderCart(); sheet("moreSheet", false); toast(t("bag_cleared")); }
      return;
    }

    /* orders → in-app track (polls the real server status) */
    var tr = closest("[data-track]");
    if (tr) {
      var o = orders[Number(tr.dataset.track)];
      if (o) { S.activeOrder = o; startTracking(o); show("track"); }
      return;
    }

    /* reorder — rebuild the exact basket from a past order on this device */
    var ro = closest("[data-reorder]");
    if (ro) {
      e.stopPropagation();
      var src = orders[Number(ro.dataset.reorder)];
      if (!src || !src.lines || !src.lines.length) return;
      var n = 0;
      src.lines.forEach(function (l) {
        var it = item(l.id); if (!it) return;
        var key = l.id + "|" + (l.variantId || 0) + "|" +
          (l.mods || []).map(function (m) { return m.groupId + ":" + m.optIndex; }).sort().join(",") + "|" +
          (l.extras || []).map(function (x) { return x.id; }).sort().join(",");
        var ex = cart.filter(function (c) { return c.key === key; })[0];
        if (ex) ex.qty += l.qty; else cart.push({ key: key, id: l.id, qty: l.qty, variantId: l.variantId || 0, mods: l.mods || [], extras: l.extras || [], note: "" });
        n += l.qty;
      });
      if (!n) return;
      writeCart(); syncBadge(true); toast(t("added"));
      show("cart", { push: false });
      return;
    }

    /* language / currency */
    var lg = closest("[data-lang-code]");
    if (lg) { setCookie("Quick_lang", lg.dataset.langName); setCookie("Quick_user_lang", lg.dataset.langName); setCookie("Quick_user_lang_code", lg.dataset.langCode); window.location.reload(); return; }
    var cu = closest("[data-cur]");
    if (cu) {
      S.curCode = cu.dataset.cur; S.curRate = parseFloat(cu.dataset.rate) || 1;
      if (window.mbCurrency) mbCurrency.save(S.curCode, S.curRate);   // persist store-scoped (shared helper)
      $$("[data-cur]").forEach(function (b) { b.classList.toggle("is-on", b === cu); });
      rerenderMoney(); return;
    }

    /* appearance (auto / light / dark) */
    var tm = closest("[data-theme-mode]");
    if (tm) { setThemeMode(tm.dataset.themeMode); return; }

    /* auth tabs */
    var atb = closest("#authTabs button");
    if (atb) {
      $$("#authTabs button").forEach(function (x) { x.classList.toggle("is-on", x === atb); });
      $$(".auth-form[data-auth]").forEach(function (f) { f.hidden = f.dataset.auth !== atb.dataset.authTab; });
      return;
    }
  });

  function rerenderMoney() {
    renderHome(); renderSearch(); renderCart(); renderCheckout();
    if (S.current) setProduct(S.current.id, false);
    renderActivity();
  }
  /* ── appearance ───────────────────────────────────────────────────────────
     Three-way Auto/Light/Dark. The palette itself is resolved by an inline
     script in <head> so there is no flash of the wrong theme on load; this only
     handles changes made while the page is open. */
  var themeMq = window.matchMedia("(prefers-color-scheme: dark)");
  function applyThemeMode(mode) {
    var dark = mode === "dark" || (mode === "auto" && themeMq.matches);
    document.documentElement.setAttribute("data-lime-theme", dark ? "dark" : "light");
    $$("#themeSeg [data-theme-mode]").forEach(function (b) { b.classList.toggle("is-on", b.dataset.themeMode === mode); });
  }
  function themeMode() { try { return localStorage.getItem("lime-theme") || "auto"; } catch (e) { return "auto"; } }
  function setThemeMode(mode) {
    try { localStorage.setItem("lime-theme", mode); } catch (e) {}
    applyThemeMode(mode);
  }
  if (themeMq.addEventListener) themeMq.addEventListener("change", function () { if (themeMode() === "auto") applyThemeMode("auto"); });

  function setCookie(k, v) { document.cookie = k + "=" + encodeURIComponent(v) + ";path=/;max-age=31536000"; }
  function on(id, ev, fn) { var el = document.getElementById(id); if (el) el.addEventListener(ev, fn); }

  /* ── single-purpose bindings ──────────────────────────── */
  on("splashSkip", "click", function () { $("#splash").classList.add("is-done"); });
  on("avatarBtn", "click", function () { sheet("profileSheet"); });
  on("locBtn", "click", function () { if ($("#branchSheet")) sheet("branchSheet"); });
  on("langBtn", "click", function () { if ($("#langSheet")) sheet("langSheet"); });
  on("cvCateringBtn", "click", function () { sheet("cvCateringSheet"); });
  on("branchLine", "click", function () { sheet("profileSheet", false); setTimeout(function () { sheet("branchSheet"); }, 430); });
  on("searchGo", "click", function () { show("search", { push: false }); });
  /* when the hero advertises a real dish the delegated [data-open] handler opens it;
     without one the CTA falls back to scrolling to the grid */
  on("heroCta", "click", function (e) {
    if (e.currentTarget.hasAttribute("data-open")) return;
    var g = root.querySelector(".grid"); if (g) g.scrollIntoView({ behavior: REDUCED ? "auto" : "smooth", block: "start" });
  });
  on("homeReset", "click", function () { S.cat = "all"; S.sub = "root"; S.search = ""; S.diet = {}; renderChips(); renderDiet(); renderGrid(); });
  on("searchInput", "input", function (e) { S.search = e.target.value; renderSearch(); });
  on("pMore", "click", function () { openMore("product"); });
  on("cartMore", "click", function () { openMore("cart"); });
  on("pFav", "click", function () { if (!S.current) return; var id = String(S.current.id); favs.has(id) ? favs.delete(id) : favs.add(id); writeFavs(); $("#pFav").classList.toggle("is-on"); });
  on("addBtn", "click", function () { if (!S.current) return; addToCart(S.current.id, S.pVariant, selectedMods(), selectedExtras(), S.pQty, $("#addBtn")); });
  on("goCheckout", "click", function () { show("checkout"); });
  on("placeOrder", "click", placeOrder);
  on("backToMenu", "click", function () { show("home", { push: false }); });
  on("dailyBadge", "click", function () { var id = $("#dailyBadge").dataset.open; if (id) openProduct(id); });
  on("consentCb", "change", function (e) { var w = $("#captureEmailWrap"); if (w) w.hidden = !e.target.checked; });

  on("callWaiter", "click", function () { waiterCall("call_waiter"); });
  on("requestBill", "click", function () { waiterCall("request_bill"); });
  on("reserveBtn", "click", function () { sheet("reserveSheet"); });
  on("waitlistBtn", "click", function () { sheet("waitlistSheet"); });
  on("redeemBtn", "click", function () { sheet("loyaltySheet"); });

  on("noteSave", "click", function () {
    var line = cart[S.noteKey];
    if (line) { line.note = ($("#noteInput") || {}).value.trim(); writeCart(); }
    sheet("noteSheet", false); renderCart(); toast(t("note_saved"));
  });

  /* coupon — the "✓ applied" / "✕ invalid" line described a total that has since
     moved, so anything that changes the basket clears it */
  function clearCouponMsg() {
    var m = $("#couponMsg"); if (m && !m.hidden) m.hidden = true;
  }
  on("couponApply", "click", function () {
    var input = $("#couponInput"), msg = $("#couponMsg");
    var code = input.value.trim(); if (!code) return;
    msg.hidden = true;
    fetch(CFG.endpoints.coupon, { method: "POST", headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf }, body: couponBody(code) })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        msg.hidden = false;
        if (res && res.success) {
          S.coupon = { code: res.label || code, coupon_type: res.coupon_type, percentage: res.percentage, discount: Number(res.discount) || 0, sig: couponSig() };
          msg.className = "coupon-msg"; msg.textContent = "✓ " + (res.message || t("coupon_ok"));
          $(".coupon-field").classList.add("is-applied");
        } else {
          S.coupon = null; msg.className = "coupon-msg err"; msg.textContent = "✕ " + ((res && res.message) || t("coupon_bad"));
          $(".coupon-field").classList.remove("is-applied");
        }
        renderTotals(); renderCheckout();
      })
      .catch(function () { msg.hidden = false; msg.className = "coupon-msg err"; msg.textContent = "✕ " + t("coupon_bad"); });
  });

  /* waiter / bill */
  function waiterCall(type) {
    var tbl = ($("#tableInput") || $("#tableSel") || {}).value || CFG.defaultTable || "";
    fetch(CFG.endpoints.waiter, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ table: tbl, type: type, _token: CFG.csrf }) })
      .then(function (r) { return r.json(); })
      /* only claim the waiter is coming when the SERVER says so — the endpoint already
         answers a localized message, so prefer it over our own copy */
      .then(function (res) {
        if (!res || res.success === false) { toast(t("order_failed")); return; }
        toast(safeServerMessage(res.message || (type === "request_bill" ? t("bill_ok") : t("waiter_ok"))));
      })
      .catch(function () { toast(t("network_error")); });
  }

  /* ── auth ─────────────────────────────────────────────── */
  function openAuth() { sheet("authSheet"); }
  on("loginLink", "click", openAuth);
  on("meAuthBtn", "click", function () { if (!CFG.isLoggedIn) openAuth(); });
  function authPost(url, body, errEl) {
    return fetch(url, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf, "X-Requested-With": "XMLHttpRequest" }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); })
      .then(function (res) { if (res && res.success) { window.location.reload(); return res; } if (errEl) { errEl.textContent = (res && res.message) || t("order_failed"); errEl.hidden = false; } return res; });
  }
  on("loginBtn", "click", function () { authPost(CFG.endpoints.login, { store_id: CFG.storeId, email: $("#loginEmail").value.trim(), password: $("#loginPass").value }, $("#authErr")); });
  on("registerBtn", "click", function () { authPost(CFG.endpoints.register, { store_id: CFG.storeId, name: $("#regName").value.trim(), email: $("#regEmail").value.trim(), phone: $("#regPhone").value.trim(), password: $("#regPass").value, password_confirmation: ($("#regPass2") || {}).value || "" }, $("#authErr")); });
  on("otpSend", "click", function () {
    var phone = $("#otpPhone").value.trim(); if (!phone) return;
    fetch(CFG.endpoints.otpRequest, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ store_id: CFG.storeId, phone: phone }) })
      .then(function (r) { return r.json(); })
      .then(function (res) { if (res && res.success) { $("#otpStep1").hidden = true; $("#otpStep2").hidden = false; } else { var e2 = $("#authErr"); e2.textContent = (res && res.message) || t("order_failed"); e2.hidden = false; } })
      .catch(function () {});
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

  /* ── F2 · follow / verified brand (ported from crave) ────
     Optimistic toggle, reconciled against the server's {following,
     follower_count} response; reverted on failure. Guest tap records an
     intent and opens the auth sheet; the intent is replayed once signed in. */
  (function () {
    var btn = $("#followBtn");
    if (!btn || !CFG.endpoints.follow) return;
    var FOLLOW_INTENT = "lm_follow_intent";
    var REDUCED_FB = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    function followHeaders() {
      var h = { "Content-Type": "application/json", "Accept": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf };
      if (CFG.customerToken) h["Authorization"] = "Bearer " + CFG.customerToken;
      return h;
    }
    function hasCustomerToken() { return !!CFG.customerToken; }

    function applyFollowState(d) {
      if (!d || typeof d.following === "undefined") return false;
      btn.dataset.following = d.following ? "1" : "0";
      btn.classList.toggle("is-on", d.following);
      var c = $("#followerCount"); if (c && typeof d.follower_count !== "undefined") c.textContent = d.follower_count;
      return true;
    }
    function bumpFollowerCount(delta) {
      var c = $("#followerCount"); if (!c) return;
      var n = parseInt(String(c.textContent || "").replace(/[^0-9]/g, ""), 10);
      if (!isNaN(n)) c.textContent = String(Math.max(0, n + delta));
    }
    function popBeat() {
      if (REDUCED_FB) return;
      btn.classList.add("followbtn--pop");
      btn.addEventListener("animationend", function h() { btn.classList.remove("followbtn--pop"); btn.removeEventListener("animationend", h); });
    }
    function doFollow(unfollow) {
      var prevFollowing = btn.dataset.following === "1";
      btn.dataset.following = unfollow ? "0" : "1";
      btn.classList.toggle("is-on", !unfollow);
      bumpFollowerCount(unfollow ? -1 : 1);
      if (!unfollow) popBeat();
      fetch(CFG.endpoints.follow, { method: unfollow ? "DELETE" : "POST", headers: followHeaders(), body: "{}" })
        .then(function (r) { return r.json(); }).then(function (d) {
          if (!d || typeof d.following === "undefined") throw new Error("bad");
          applyFollowState(d);
        }).catch(function () {
          btn.dataset.following = prevFollowing ? "1" : "0";
          btn.classList.toggle("is-on", prevFollowing);
          bumpFollowerCount(unfollow ? 1 : -1);
          toast(t("order_failed"));
        });
    }
    btn.addEventListener("click", function () {
      if (!hasCustomerToken()) {
        try { sessionStorage.setItem(FOLLOW_INTENT, "1"); } catch (e) {}
        openAuth();
        return;
      }
      doFollow(btn.dataset.following === "1");
    });
    /* replay a pending follow intent once the visitor is signed in (post-reload) */
    (function () {
      var pending = false;
      try { pending = sessionStorage.getItem(FOLLOW_INTENT) === "1"; } catch (e) {}
      if (!pending || !hasCustomerToken()) return;
      try { sessionStorage.removeItem(FOLLOW_INTENT); } catch (e) {}
      if (btn.dataset.following === "1") return;
      fetch(CFG.endpoints.follow, { method: "POST", headers: followHeaders(), body: "{}" })
        .then(function (r) { return r.json(); }).then(function (d) {
          if (applyFollowState(d) && d.following) toast(t("now_following"));
        }).catch(function () {});
    })();
  })();

  /* ── delivery address autocomplete + use-my-location ────
     Shared by the checkout field (#custAddr, wired once below) and the cart's
     delivery field (#cartAddrInput, re-wired by renderOtFields each time it is
     rebuilt). Coordinates always land in the checkout's hidden #custLat/#custLng
     pair — the order payload reads only those. */
  function wireAddressField(input, box, locBtn, attrib, onValue) {
    if (!input || !CFG.endpoints || !CFG.endpoints.geoAutocomplete) return;
    var timer = null, lastAttrib = "";
    function setAttrib(a) { if (a && attrib && a !== lastAttrib) { attrib.textContent = a; attrib.hidden = false; lastAttrib = a; } }
    function hideBox() { if (box) { box.hidden = true; box.innerHTML = ""; } }
    function setCoords(lat, lng) { var la = $("#custLat"), ln = $("#custLng"); if (la) la.value = lat || ""; if (ln) ln.value = lng || ""; }
    function setVal(v) { input.value = v; if (onValue) onValue(v); }
    input.addEventListener("input", function () {
      if (onValue) onValue(input.value);   /* keep cart/checkout address in sync */
      var q = input.value.trim();
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
      setVal(opt.dataset.label || "");
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
            if (d && d.result) { setVal(d.result.label || ""); setCoords(d.result.lat || lat, d.result.lng || lng); }
            else { toast(t("no_address")); }
          }).catch(function () { locBtn.classList.remove("is-loading"); });
      }, function () { locBtn.classList.remove("is-loading"); toast(t("loc_denied")); }, { enableHighAccuracy: true, timeout: 8000 });
    });
  }
  /* ── R6: guest multi-address (localStorage) ───────────────────────────────
     Guests have no server address book, so the last few delivery addresses they
     typed on this device are offered as .saved-addr chips at checkout — rendered
     into the SAME #savedAddrs slot the server uses for a logged-in customer's
     addresses (blade renders an empty #savedAddrs for guests). Gated on
     !isLoggedIn so it never fights the server book. The storage key is computed
     INLINE via addrKey() — never a hoisted `var KEY =` (var-hoist gap → 0 chips). */
  function addrKey() { return "lime_recent_addrs_" + slug; }
  function readRecentAddrs() {
    try { return (JSON.parse(localStorage.getItem(addrKey()) || "[]") || []).filter(function (a) { return a && a.addr; }); }
    catch (e) { return []; }
  }
  function rememberAddress(addr, lat, lng) {
    addr = (addr || "").trim();
    if (!addr || CFG.isLoggedIn) return;
    var l = readRecentAddrs().filter(function (a) { return a.addr !== addr; });
    l.unshift({ addr: addr, lat: lat || "", lng: lng || "" });
    try { localStorage.setItem(addrKey(), JSON.stringify(l.slice(0, 5))); } catch (e) {}
    renderRecentAddrs();
  }
  function renderRecentAddrs() {
    var saved = $("#savedAddrs");
    if (!saved || CFG.isLoggedIn) return;      /* logged-in slot holds server chips — leave it */
    var l = readRecentAddrs();
    if (!l.length) { saved.hidden = true; saved.innerHTML = ""; return; }
    saved.hidden = false;
    saved.innerHTML = l.map(function (a, i) {
      return '<button class="saved-addr' + (i === 0 ? " is-on" : "") + '" type="button" ' +
        'data-addr="' + esc(a.addr) + '" data-lat="' + esc(a.lat) + '" data-lng="' + esc(a.lng) + '">' +
        '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
        '<span class="sa-text"><b>' + esc(a.addr) + "</b></span></button>";
    }).join("");
  }

  (function () {
    var input = $("#custAddr");
    if (!input) return;
    wireAddressField(input, $("#custAddrSuggest"), $("#custUseLocation"), $("#custGeoAttrib"), null);
    var saved = $("#savedAddrs");
    if (saved) saved.addEventListener("click", function (e) {
      var b = e.target.closest(".saved-addr"); if (!b) return;
      input.value = b.dataset.addr || "";
      var la = $("#custLat"), ln = $("#custLng");
      if (la) la.value = b.dataset.lat || ""; if (ln) ln.value = b.dataset.lng || "";
      $$(".saved-addr", saved).forEach(function (x) { x.classList.remove("is-on"); });
      b.classList.add("is-on");
      var box = $("#custAddrSuggest"); if (box) { box.hidden = true; box.innerHTML = ""; }
    });
  })();

  /* ── loyalty ──────────────────────────────────────────── */
  (function () {
    if (!CFG.endpoints.loyaltyBalance) return;
    function paint(d) {
      var bal = d.balance || 0;
      var pn = $("#ptsNum"); if (pn) pn.textContent = bal;
      var lp = $("#loyPts"); if (lp) lp.textContent = bal;
      var bar = $("#loyBar"); if (bar) bar.style.width = Math.min(100, d.progress_pct || (CFG.loyaltyThreshold ? (bal / CFG.loyaltyThreshold * 100) : 0)) + "%";
      var rb = $("#loyRedeem"); if (rb) rb.hidden = !d.ready_to_redeem;
    }
    fetch(CFG.endpoints.loyaltyBalance).then(function (r) { return r.json(); }).then(function (d) { if (d && d.active) paint(d); }).catch(function () {});
    on("loyRedeem", "click", function () {
      var rb = $("#loyRedeem"); rb.disabled = true;
      fetch(CFG.endpoints.loyaltyRedeem, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: "{}" })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.success) { rb.hidden = true; $("#loyMsg").hidden = false; paint({ balance: d.new_balance, progress_pct: 0, ready_to_redeem: false }); } else { rb.disabled = false; toast((d && d.error) || t("coupon_bad")); } })
        .catch(function () { rb.disabled = false; });
    });
  })();

  /* ── reorder handoff from account page ────────────────── */
  /* Shared engine (_shared/js/reorder-pickup.js, loaded by store_templates.layout):
     resolves the server's choice-group selections + note onto this theme's
     cart-line shape so a reordered item keeps its modifiers and note. */
  window.__limePickup = function (line) {
    var i = item(line.item_id);
    if (!i || soldOut(i)) return false;
    var R = window.JuwriReorder;
    var mods = R.modsFromOptions(i, line.options);
    if (R.requiredMissing(i, mods)) return false;
    var extras = (line.extras || []).map(function (e) { return { id: e.id, title: e.title, price: e.price }; });
    var key = line.item_id + "|" + (line.variant || 0) + "|" + mods.map(function (m) { return m.groupId + ":" + m.optIndex; }).sort().join(",") + "|" + extras.map(function (e) { return e.id; }).sort().join(",");
    var existing = cart.filter(function (l) { return l.key === key; })[0];
    if (existing) { existing.qty += line.qty; }
    else { cart.push({ key: key, id: line.item_id, qty: line.qty, variantId: line.variant || 0, mods: mods, extras: extras, note: line.note || "" }); }
    return true;
  };
  function pickupReorder() {
    if (!window.JuwriReorder || !window.JuwriReorder.pickup) return;
    var r = window.JuwriReorder.pickup(slug, window.__limePickup, { toast: toast });
    if (r.added) { writeCart(); syncBadge(true); if (!r.unavailable.length) toast(t("added")); }
  }

  /* ── banners autoplay + dots ──────────────────────────── */
  (function () {
    var track = $("#bannerTrack"); if (!track || !track.children.length) return;
    var n = track.children.length, bi = 0;
    $("#bannerDots").innerHTML = new Array(n).fill(0).map(function (_, i) { return '<i class="' + (i === 0 ? "is-on" : "") + '"></i>'; }).join("");
    if (n > 1) {
      setInterval(function () { bi = (bi + 1) % n; track.scrollTo({ left: track.clientWidth * 0.92 * bi, behavior: "smooth" }); }, 4000);
      track.addEventListener("scroll", function () { var i = Math.round(Math.abs(track.scrollLeft) / (track.clientWidth * 0.92)); $$("#bannerDots i").forEach(function (d, di) { d.classList.toggle("is-on", di === Math.min(i, n - 1)); }); }, { passive: true });
    }
  })();

  /* ── stories (rail + full-screen viewer) ──────────────── */
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
        var label = s.caption ? String(s.caption).slice(0, 14) : t("popular");
        return '<button class="story" data-si="' + i + '" type="button">' +
          '<span class="story-ring"><img src="' + esc(s.media_url) + '" alt="" loading="lazy"' + IMG_ERR + "></span>" +
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
      /* the fade-out is collapsed under prefers-reduced-motion — don't sit on a
         fully transparent overlay waiting for a transition that never runs */
      setTimeout(function () { viewer.hidden = true; }, REDUCED ? 0 : 300);
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

  /* ── tabbar autohide on scroll ────────────────────────── */
  var lastY = 0;
  $$(".view-scroll").forEach(function (sc) {
    sc.addEventListener("scroll", function () {
      if (ROOTS.indexOf(active) === -1) return;
      var y = sc.scrollTop, tb = $("#tabbar");
      tb.classList.toggle("is-hidden", y > lastY + 4 && y > 130);
      if (y < lastY - 4 || y < 130) tb.classList.remove("is-hidden");
      lastY = y;
    }, { passive: true });
  });

  /* ── nearest-branch map (self-hosted Leaflet geo picker) ── */
  (function () {
    var openBtn = $("#nearestBranchBtn");
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
      $("#branchGeoList").innerHTML = branches.map(function (b) {
        var dist = b.distance_km != null ? '<span class="branch-dist" dir="ltr">' + b.distance_km.toFixed(1) + " km</span>" : "";
        return '<button class="branch-row" data-bid="' + b.id + '" type="button">' +
          '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
          '<b>' + esc(b.name) + (b.address ? '<small>' + esc(b.address) + "</small>" : "") + "</b>" + dist +
          '<span class="branch-check"></span></button>';
      }).join("") || '<p class="orders-empty">' + esc(t("no_branches")) + "</p>";
    }
    function drawMap(branches) {
      var withCoords = branches.filter(function (b) { return b.latitude != null && b.longitude != null; });
      if (!map) {
        map = L.map("branchMap", { attributionControl: false });
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 }).addTo(map);
        L.control.attribution({ prefix: false }).addAttribution("© OpenStreetMap").addTo(map);
      }
      markers.forEach(function (m) { map.removeLayer(m); }); markers = [];
      if (!withCoords.length) { map.setView([24.7136, 46.6753], 11); return; } // Riyadh fallback
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
        $("#mapAttrib").textContent = (d && d.attribution) || "© OpenStreetMap contributors";
        renderList(branches);
        setTimeout(function () { drawMap(branches); if (map) map.invalidateSize(); }, 60);
      }).catch(function () {});
    }
    openBtn.addEventListener("click", function () {
      sheet("branchMapSheet");
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
          function (p) { load(p.coords.latitude, p.coords.longitude); },
          function () { load(); }, { timeout: 6000 });
      } else { load(); }
    });
    $("#branchGeoList").addEventListener("click", function (e) {
      var b = e.target.closest("[data-bid]"); if (b) selectBranch(b.dataset.bid);
    });
  })();

  /* ── boot ─────────────────────────────────────────────── */
  function boot() {
    applyThemeMode(themeMode());
    pickupReorder();
    /* default order type: room service > forced table > first enabled.
       The room-service button only renders when the scanned QR resolved to a
       hotel room, so its presence is the signal that this guest is in a room. */
    var otBtns = $$("#otSeg [data-ot]");
    if (otBtns.some(function (b) { return b.dataset.ot === "room_service"; })) S.orderType = "room_service";
    else if (CFG.forceTable && otBtns.some(function (b) { return b.dataset.ot === "on-table"; })) S.orderType = "on-table";
    else if (otBtns.length) S.orderType = otBtns[0].dataset.ot;

    renderChips(); renderDiet(); renderBS();
    skeletons();
    setTimeout(renderGrid, 650);
    initDaily();
    syncBadge(false);
    renderActivity();
    renderOtFields();
    renderRecentAddrs();   /* R6: paint guest saved-address chips into #savedAddrs */

    /* splash once per session */
    var splash = $("#splash");
    if (splash) {
      /* A1: a ?item=/?table= deep link opens straight on the product — dismiss the
         splash up front so it never overlays it. Not persisted: a later plain visit
         this session still gets the intro. */
      var _dl = null; try { _dl = new URLSearchParams(location.search); } catch (e) {}
      var deepLinked = !!(_dl && (_dl.get("item") || _dl.get("table")));
      if (deepLinked) splash.classList.add("is-done");
      else if (sessionStorage.getItem("lime_splash_" + slug)) splash.classList.add("is-done");
      else { sessionStorage.setItem("lime_splash_" + slug, "1"); setTimeout(function () { splash.classList.add("is-done"); }, 2300); }
    }

    /* restore saved currency choice (store-scoped shared helper; replays the handler) */
    if (window.mbCurrency) mbCurrency.restore("#curSeg");

    if (CFG.openResSheet && $("#reserveSheet")) { show("orders", { push: false }); sheet("reserveSheet"); }
    if (CFG.openWaitlistSheet && $("#waitlistSheet")) { show("orders", { push: false }); sheet("waitlistSheet"); }

    /* resume tracking of a recent order — the server, not a timer, decides the stage */
    var last = orders[0];
    if (last && !isFinalOrder(last)) { S.activeOrder = last; startTracking(last); }

    /* R7: open a ?item=<id> deeplink on load, in place (no server round-trip). */
    var deepItem = new URLSearchParams(location.search).get("item");
    if (deepItem && item(deepItem)) openProduct(deepItem);

    /* topbar quick toggle (Lane B): rides lime's NATIVE appearance system
       (setThemeMode/data-lime-theme, key `lime-theme`) so the Profile
       Auto/Light/Dark segment stays in sync — the shared engine is NOT used
       here because lime already owns palette + persistence (LIME-F7). */
    on("limeThemeToggle", "click", function () {
      var isDark = document.documentElement.getAttribute("data-lime-theme") === "dark";
      setThemeMode(isDark ? "light" : "dark");
      var b = document.getElementById("limeThemeToggle");
      if (b) b.setAttribute("aria-pressed", String(!isDark));
    });
  }
  boot();

  /* Collapsing header — in once the cover card / header leaves the home scroll
     container, out again as the sticky .chips bar reaches the top. Proxies the
     condensed buttons to the real top-chrome controls. */
  (function mountCondensedHeader() {
    var homeView = document.querySelector('.view[data-view="home"]');
    if (!homeView) return;
    var anchor = homeView.querySelector(".lime-cover") || homeView.querySelector(".home-top");
    var bar = homeView.querySelector(".cv-condensed");
    var scroller = homeView.querySelector(".view-scroll");
    if (!anchor || !bar || !scroller || !("IntersectionObserver" in window)) return;
    var coverGone = false, menuReached = false;
    function apply() { bar.classList.toggle("is-on", coverGone && !menuReached); }
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.target === anchor) coverGone = e.intersectionRatio < 0.05; });
      apply();
    }, { root: scroller, threshold: [0, 0.05, 1] });
    io.observe(anchor);
    var sentinel = homeView.querySelector(".cv-menu-sentinel");
    if (sentinel) {
      var io2 = new IntersectionObserver(function (es) {
        es.forEach(function (e) {
          var top = e.rootBounds ? e.rootBounds.top : 0;
          menuReached = !e.isIntersecting && e.boundingClientRect.top <= top + 4;
        });
        apply();
      }, { root: scroller, threshold: [0, 1] });
      io2.observe(sentinel);
    }
    var T = { lang: "#langBtn", catering: "#cvCateringBtn", notif: "#mbNotifBell" };
    Array.prototype.forEach.call(bar.querySelectorAll("[data-cv-proxy]"), function (b) {
      b.addEventListener("click", function () {
        var t = homeView.querySelector(T[b.getAttribute("data-cv-proxy")]);
        if (t) t.click();
      });
    });
  })();
})();
