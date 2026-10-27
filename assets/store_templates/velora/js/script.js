// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ═══════════════════════════════════════════════════════════
   velora — theme runtime (faithful rebuild of rev/velora/demo.js)
   Renders the demo's DOM/motions but is driven entirely by
   window.VELORA (real menu, categories, banners, currency,
   endpoints, flags, i18n). Real ordering → restaurant.submitOrder.
   ═══════════════════════════════════════════════════════════ */
(function () {
  "use strict";
  var CFG = window.VELORA;
  if (!CFG) return;

  var root = document.getElementById("velora");
  function $(s, c) { return (c || root).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || root).querySelectorAll(s)); }
  function esc(s) {
    return String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }
  /* Crawlable + new-tab-able per-item URL: keeps existing query (table, lang…)
     and sets ?item=ID — the deep-link the R7 intercept + boot loader understand. */
  function itemHref(id) {
    try { var u = new URL(window.location.href); u.searchParams.set("item", id); u.hash = ""; return u.pathname + u.search; }
    catch (e) { return "?item=" + encodeURIComponent(id); }
  }
  function T(k, fb) { return (CFG.i18n && CFG.i18n[k]) || fb || k; }

  var ICONS = {
    plus: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg>',
    minus: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg>',
    heart: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>',
    star: '<svg class="ic" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>',
    clock: '<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
    fire: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 22c4.4 0 7-2.8 7-6.6 0-4.8-4.2-6.6-4.9-11.4-2.5 1.6-3.4 4-3.2 6.6-1.2-.7-2-1.7-2.4-3.4C6.6 9 5 11.6 5 15.4 5 19.2 7.6 22 12 22Z"/></svg>',
    check: '<svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>',
    trash: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M10 7V5h4v2M8 7l.8 13h6.4L16 7"/></svg>',
    note: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 4h11l3 3v13H5V4Z"/><path d="M15 4v4h4M8.5 12h7M8.5 16h5"/></svg>',
    leaf: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 19C5 9 13 5 20 5c0 8-4 14-12 14-1 0-2-.1-3 0Z"/><path d="M5 19c2-5 6-9 10-11"/></svg>',
    bag: '<svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-1 11.6a2.1 2.1 0 0 1-2.1 1.9H8.7a2.1 2.1 0 0 1-2.1-1.9L5.6 8.4Z"/><path d="M9 10.4V6.9a3 3 0 0 1 6 0v3.5"/></svg>',
    arr: '<svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>',
    loc: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>',
  };
  var SQUIGGLE = '<svg class="banner-squiggle" viewBox="0 0 340 160" preserveAspectRatio="none" aria-hidden="true">' +
    '<path d="M-10 26 q 16 -13 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0"/>' +
    '<path d="M-26 66 q 16 -13 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0"/>' +
    '<path d="M-10 106 q 16 -13 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0"/>' +
    '<path d="M-26 146 q 16 -13 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0 t 32 0"/></svg>';

  /* ── state ────────────────────────────────────────────── */
  var slug = CFG.restaurantSlug;
  var cartKey = "mb-cart-" + slug;
  var favKey = "cv-favs-" + slug;
  var cart = [];
  try { cart = JSON.parse(localStorage.getItem(cartKey)) || []; } catch (e) {}
  if (!Array.isArray(cart)) cart = [];
  var favs = new Set();
  try { (JSON.parse(localStorage.getItem(favKey)) || []).forEach(function (id) { favs.add(String(id)); }); } catch (e) {}

  var S = {
    cat: "all", sub: "root", search: "", sort: "featured", soPreset: null,
    filters: { diets: {}, allergens: {}, spice: 3 },
    orderType: null, coupon: null, tipPct: 0, tipCustom: null, when: "asap",
    current: null, pQty: 1, pVariant: 0, pMods: {}, pExtras: {}, pImgIdx: 0,
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
    return CFG.currencyPos === "1" ? CFG.currencySign + (CFG.currencyGap || "") + str : str + (CFG.currencyGap || "") + CFG.currencySign;
  }

  /* ── items / categories ───────────────────────────────── */
  var ITEMS = Object.keys(CFG.items).map(function (k) { return CFG.items[k]; });
  function item(id) { return CFG.items[id]; }
  var CATS = (CFG.categories || []).slice();          // top-level parents (parent chips)
  var SUBCATS = CFG.subCats || {};                     // { parentId: [{id,name,img}] }
  function itemRoot(i) { return i.root != null ? i.root : i.cat; }
  var FALLBACK_IMG = CFG.defaultImg || "";
  var IMG_ERR = FALLBACK_IMG ? ' data-csp-error="fallback" data-csp-fallback="' + esc(FALLBACK_IMG) + '"' : "";
  function itemImgs(i) {
    var g = (i.gallery && i.gallery.length) ? i.gallery.slice() : [];
    if (i.img && g.indexOf(i.img) === -1) g.unshift(i.img);
    return g.length ? g : [i.img];
  }
  // Name lookup spans parents AND every item-bearing subcategory, so cards/search
  // rows filed under a subcategory still show their real category label.
  var CAT_NAME = {};
  CATS.forEach(function (c) { CAT_NAME[String(c.id)] = c.name; });
  Object.keys(SUBCATS).forEach(function (p) { (SUBCATS[p] || []).forEach(function (s) { CAT_NAME[String(s.id)] = s.name; }); });
  function catName(id) { return CAT_NAME[String(id)] || ""; }
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

  /* ── pricing ──────────────────────────────────────────── */
  function unitPrice(i, variantId, mods, extras) {
    var p = Number(i.price) || 0;
    if (variantId) { var v = (i.variants || []).filter(function (x) { return x.id === variantId; })[0]; if (v) p = Number(v.price) || 0; }
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
        renderTotals(); renderCheckout();
      }).catch(function () {});
    }
    return Number(c.discount) || 0;
  }
  function cartCount() { return cart.reduce(function (n, l) { return n + l.qty; }, 0); }
  function writeCart() { localStorage.setItem(cartKey, JSON.stringify(cart)); }

  /* COMBOS-V2 — the shared combos partial (_partials/combos.blade.php) keeps its
     own basket and publishes display state on window.mbCombos {count,total,lines}.
     The server prices combo lines into the ORDER subtotal (and taxes them like any
     other line), so the quote must fold them in too — QUOTED == CHARGED. Quantity
     edits stay in the combo sheet; here they render read-only. */
  function comboCount() { return (window.mbCombos && window.mbCombos.count) || 0; }
  function comboTotal() { return (window.mbCombos && window.mbCombos.total) || 0; }
  function comboLines() { return (window.mbCombos && window.mbCombos.lines) || []; }
  function writeFavs() { localStorage.setItem(favKey, JSON.stringify(Array.from(favs))); }

  /* Tax, exactly as the server computes it — see Order::computeTax().
     Two things this MUST NOT do, both of which have shipped in other themes and
     silently under-quoted real customers:
       1. tax the DISCOUNTED subtotal. The server taxes the raw subtotal and
          subtracts the discount last, so `SAVE10` on $16.00 at 5% is $0.80 of
          tax, never $0.72.
       2. treat `restaurant_tax_type = 'fixed'` as a percentage. Fixed means a
          flat amount, not a rate.
     The `charge <= 0 || subtotal <= 0 => 0` guard mirrors the server's. */
  function taxOf(sub) {
    var charge = Number(CFG.taxCharge) || 0;
    if (charge <= 0 || sub <= 0) return 0;
    if (String(CFG.taxType || "percentage") === "fixed") {
      var f = Math.pow(10, MONEY_DP);
      return Math.round(charge * f) / f;
    }
    return pctOf(sub, charge);
  }

  function totals() {
    var sub = subtotal(), disc = 0;
    if (S.coupon) {
      disc = S.coupon.coupon_type === "percentage" ? pctOf(sub, S.coupon.percentage) : S.coupon.coupon_type === "bogo" ? bxgyDiscount() : Number(S.coupon.percentage) || 0;
      if (disc > sub) disc = sub;
    }
    var tax = taxOf(sub);                                   /* RAW subtotal — never sub - disc */
    var del = S.orderType === "delivery" && (cart.length || comboCount()) ? CFG.deliveryCharge : 0;
    var tip = S.tipPct ? pctOf(sub, S.tipPct) : (S.tipCustom || 0);
    return { sub: sub, disc: disc, tax: tax, del: del, tip: tip, total: sub - disc + tax + del + tip };
  }

  /* ── router + nav glider ──────────────────────────────── */
  var stack = [];
  var ROOTS = ["home", "cart", "favs", "profile"];
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
      if (!opts.fromPop) { try { history.pushState({ vl: 1 }, ""); } catch (e) {} }
    }
    if (isRoot) stack.length = 0;
    $$(".view").forEach(function (v) { v.classList.remove("is-under"); });
    if (!isRoot && from) from.classList.add("is-under");
    if (from) from.classList.remove("is-active");
    to.classList.add("is-active");
    var sc = to.querySelector(".view-scroll"); if (sc) sc.scrollTop = 0;
    active = name;
    var nb = $("#navbar"); if (nb) nb.classList.toggle("is-hidden", !isRoot);
    $$(".nav-tab").forEach(function (b) { b.classList.toggle("is-on", b.dataset.tab === name); });
    if (isRoot) layNav();
    if (name === "cart") renderCart();
    if (name === "favs") renderFavs();
    if (name === "checkout") renderCheckout();
    if (name === "profile") loadLoyalty();
    syncInert();
  }
  /* Off-screen views stay in the DOM, so without this every card, heart and form
     field of the other 8 views is still focusable and still announced by a screen
     reader while the customer is looking at one of them. */
  function syncInert() {
    $$(".view").forEach(function (v) {
      var on = v.classList.contains("is-active");
      if (on) v.removeAttribute("inert"); else v.setAttribute("inert", "");
    });
  }
  syncInert();
  function internalBack() { show(stack.pop() || "home", { push: false, fromPop: true }); }
  function back() {
    /* UI back buttons go through history when we pushed a state, so the
       browser's own back stack stays in step with what is on screen. */
    if (stack.length && history.state && history.state.vl) { history.back(); return; }
    internalBack();
  }
  window.addEventListener("popstate", function () {
    var s = root.querySelector(".sheet.is-open");
    if (s) { sheet(s.id, false); try { history.pushState({ vl: 1 }, ""); } catch (e) {} return; }
    if (stack.length) internalBack();
    else if (active !== "home") show("home", { push: false, fromPop: true });
  });

  function layNav() {
    var on = $(".nav-tab.is-on"), g = $("#navGlider");
    if (!on || !g) return;
    var label = on.querySelector(".nt-label"), lw = 0;
    if (label) {
      var c = label.cloneNode(true);
      c.style.cssText = "position:absolute;visibility:hidden;max-width:none;opacity:1;transition:none";
      label.parentElement.appendChild(c); lw = c.offsetWidth; c.remove();
    }
    var w = Math.min(on.offsetWidth, 32 + 22 + (lw ? 7 + lw : 0));
    var x = on.offsetLeft + on.offsetWidth / 2 - w / 2;
    g.style.width = w + "px";
    g.style.transform = "translateX(" + x + "px)";
    g.style.opacity = "1";
  }
  window.addEventListener("resize", layNav);

  /* ── sheets / toast ───────────────────────────────────── */
  var openSheetId = null, sheetHideTimers = {};
  function sheet(id, open) {
    var s = document.getElementById(id), scrim = $("#scrim");
    if (!s) return;
    if (open !== false) {
      if (openSheetId && openSheetId !== id) sheet(openSheetId, false);
      clearTimeout(sheetHideTimers[id]);
      s.hidden = false; scrim.hidden = false;
      requestAnimationFrame(function () { s.classList.add("is-open"); scrim.classList.add("is-open"); });
      openSheetId = id;
    } else {
      s.classList.remove("is-open"); scrim.classList.remove("is-open");
      clearTimeout(sheetHideTimers[id]);
      sheetHideTimers[id] = setTimeout(function () { s.hidden = true; if (!openSheetId || openSheetId === id) scrim.hidden = true; }, 420);
      if (openSheetId === id) openSheetId = null;
    }
  }
  function closeAnySheet() { var id = openSheetId || (root.querySelector(".sheet.is-open") || {}).id; if (id) sheet(id, false); }
  $("#scrim").addEventListener("click", closeAnySheet);
  $$(".sheet-grab").forEach(function (g) { g.addEventListener("click", function () { var s = g.closest(".sheet"); if (s) sheet(s.id, false); }); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") closeAnySheet(); });

  var toastTimer;
  function toast(msg) {
    var t = $("#toast");
    t.innerHTML = ICONS.check.replace("<svg", '<svg class="ic"') + "<span>" + esc(msg) + "</span>";
    t.classList.add("is-on");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.classList.remove("is-on"); }, 2200);
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
    try { fetch(CFG.endpoints.itemEvent, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ menu_id: id, type: type }) }).catch(function () {}); } catch (e) {}
  }

  /* ── home render ──────────────────────────────────────── */
  function badgeHTML(i) {
    if (i.is_daily_special) return '<span class="g-badge">★ ' + esc(T("daily_special", "Daily Special")) + "</span>";
    if (i.is_popular) return '<span class="g-badge dark">' + esc(T("popular", "Popular")) + "</span>";
    return "";
  }
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
    var cn = catName(i.cat);
    var sub = cn + (i.calories ? " · " + i.calories + " kcal" : "");
    return '<a class="gcard' + (unavailable ? " is-unavailable" : "") + '" href="' + esc(itemHref(i.id)) + '" data-open="' + i.id + '" style="animation-delay:' + Math.min(idx * 45, 400) + 'ms;color:inherit;text-decoration:none">' +
      '<span class="g-photo">' +
        '<img src="' + esc(i.img) + '" alt="" loading="lazy"' + IMG_ERR + ">" +
        badgeHTML(i) +
        '<span class="g-fav ' + (fav ? "is-on" : "") + '" data-fav="' + i.id + '">' + ICONS.heart + "</span>" +
        (soldOut(i) ? '<span class="g-soldout">' + esc(T("sold_out", "Sold out")) + "</span>" : "") +
        hhBadgeHTML(i) +
      "</span>" +
      '<span class="g-name">' + esc(i.name) + "</span>" +
      (sub ? '<span class="g-sub">' + esc(sub) + "</span>" : "") +
      '<span class="g-price">' +
        '<span class="g-price-v">' + ((i.variants || []).length ? '<small>' + esc(T("starting_from", "From")) + "</small> " : "") + hhWasHTML(i) + money(i.price) + "</span>" +
        (CFG.allowOrder && !soldOut(i) ? '<button class="g-add" type="button" data-add="' + i.id + '" aria-label="' + esc(T("add_to_cart", "Add to cart") + " — " + i.name) + '">' + ICONS.plus + "</button>" : "") +
      "</span>" +
      "</a>";
  }
  // A quick-add can't answer a required option group, so those items open the
  // detail sheet instead; a bare variant choice keeps its default (base price).
  function needsChoice(i) {
    return (i.optionGroups || []).some(function (g) { return g.required || (g.min || 0) > 0; });
  }

  function visibleItems() {
    var q = S.search.trim().toLowerCase();
    var list = ITEMS.filter(function (i) {
      // Two-tier: a parent chip matches by top-level ancestor (i.root, so it shows
      // every descendant); the sub-chip then narrows to one real category (i.cat).
      if (S.cat !== "all") {
        if (String(itemRoot(i)) !== String(S.cat)) return false;
        if (S.sub !== "root" && String(i.cat) !== String(S.sub)) return false;
      }
      if (q && (String(i.name) + " " + String(i.desc)).toLowerCase().indexOf(q) === -1) return false;
      var f = S.filters;
      if (f.diets.vegan && !i.is_vegan) return false;
      if (f.diets.halal && !i.is_halal) return false;
      if (f.diets.gluten_free && !i.is_gluten_free) return false;
      if ((i.spice_level || 0) > f.spice) return false;
      for (var aId in f.allergens) if (f.allergens[aId] && (i.allergies || []).some(function (a) { return String(a.id) === String(aId); })) return false;
      return true;
    });
    if (S.sort === "price_asc") list.sort(function (a, b) { return a.price - b.price; });
    if (S.sort === "price_desc") list.sort(function (a, b) { return b.price - a.price; });
    if (S.sort === "pop") list.sort(function (a, b) { return (b.is_popular ? 1 : 0) - (a.is_popular ? 1 : 0) || (b.rating || 0) - (a.rating || 0); });
    return list;
  }
  function renderGrid() {
    var list = visibleItems();
    $("#grid").innerHTML = list.map(cardHTML).join("");
    $("#gridEmpty").hidden = list.length > 0;
    $("#gridTitle").textContent = S.cat === "all"
      ? T("new_arrivals", "New Arrivals")
      : (S.sub !== "root" ? catName(S.sub) : catName(S.cat));
    var f = S.filters;
    var activeF = f.diets.vegan || f.diets.halal || f.diets.gluten_free || f.spice < 3 || Object.keys(f.allergens).some(function (k) { return f.allergens[k]; });
    var dot = $("#filterDot"); if (dot) dot.hidden = !activeF;
    var cnt = $("#filterCount"); if (cnt) cnt.textContent = list.length;
  }
  function renderCats() {
    var html = '<button class="catpill ' + (S.cat === "all" ? "is-on" : "") + '" data-cat="all" type="button"><span>' + esc(T("all", "All")) + "</span>" +
      (CFG.logo ? '<img src="' + esc(CFG.logo) + '" alt=""' + IMG_ERR + ">" : '<span class="cat-emoji">✦</span>') + "</button>";
    // parent pills match items by top-level ancestor (i.root), so a parent still shows
    // when all its items live under subcategories.
    html += CATS.filter(function (c) { return ITEMS.some(function (i) { return String(itemRoot(i)) === String(c.id); }); }).map(function (c) {
      var hasSub = SUBCATS[c.id] && SUBCATS[c.id].length;
      return '<button class="catpill ' + (String(c.id) === String(S.cat) ? "is-on" : "") + '" data-cat="' + c.id + '"' + (hasSub ? ' data-has-sub="1"' : "") + ' type="button"><span>' + esc(c.name) + "</span>" +
        (c.img ? '<img src="' + esc(c.img) + '" alt="" loading="lazy"' + IMG_ERR + ">" : '<span class="cat-emoji">🍽</span>') + "</button>";
    }).join("");
    $("#catRail").innerHTML = html;
    renderSubCats();
  }
  // Reveal the active parent's sub-category chip row (only when it has item-bearing
  // subcategories). "All" (data-sub="root") shows every descendant of the parent.
  function renderSubCats() {
    var wrap = $("#catSubRail");
    if (!wrap) return;
    var subs = (S.cat !== "all" && SUBCATS[S.cat]) ? SUBCATS[S.cat] : null;
    if (!subs || !subs.length) { wrap.hidden = true; wrap.innerHTML = ""; return; }
    var html = '<button class="' + (S.sub === "root" ? "is-on" : "") + '" data-sub="root" type="button">' + esc(T("all", "All")) + "</button>";
    html += subs.map(function (s) {
      return '<button class="' + (String(s.id) === String(S.sub) ? "is-on" : "") + '" data-sub="' + s.id + '" type="button">' + esc(s.name) + "</button>";
    }).join("");
    wrap.innerHTML = html;
    wrap.hidden = false;
  }
  function skeletons() {
    $("#grid").innerHTML = new Array(6).fill('<div class="gcard sk"><span class="g-photo"></span><div class="sk-line"></div><div class="sk-line short"></div></div>').join("");
  }

  /* ── banners ──────────────────────────────────────────── */
  var bannerIdx = 0, bannerTimer;
  function bannerData() {
    if (CFG.banners && CFG.banners.length) return CFG.banners;
    /* synthesize from real specials/popular so the signature lime banner always shows */
    var picks = ITEMS.filter(function (i) { return i.is_daily_special; });
    if (!picks.length) picks = ITEMS.filter(function (i) { return i.is_popular; });
    if (!picks.length) picks = ITEMS.slice();
    return picks.slice(0, 3).map(function (i) {
      return { chip: i.is_daily_special ? T("daily_special", "Daily Special") : T("popular", "Popular"), title: i.name, note: "", btn: T("starting_from", "From") + " " + money(i.price), img: i.img, open: i.id };
    });
  }
  function bannerHTML(b, alt) {
    var actAttr = b.open ? 'data-bopen="' + b.open + '"' : (b.link ? '' : "");
    var tag = b.link ? "a" : "button";
    var attrs = b.link ? ('href="' + esc(b.link) + '" target="_blank" rel="noopener"') : ('type="button" ' + actAttr);
    return "<" + tag + ' class="banner' + (alt ? " alt" : "") + '" ' + attrs + ">" + SQUIGGLE +
      '<span class="banner-copy">' +
        '<span class="banner-chip">' + esc(b.chip) + "</span>" +
        '<span class="banner-title">' + esc(b.title) + "</span>" +
        (b.note ? '<span class="banner-note">' + esc(b.note) + "</span>" : "") +
        '<span class="banner-btn">' + esc(b.btn) + '<i class="btn-arr"><svg viewBox="0 0 24 24"><path d="M7 17 17 7M9 7h8v8"/></svg></i></span>' +
      "</span>" +
      (b.img ? '<img class="banner-img" src="' + esc(b.img) + '" alt=""' + IMG_ERR + ">" : "") +
      "</" + tag + ">";
  }
  function renderBanners() {
    var data = bannerData();
    var wrap = $("#bannersWrap");
    if (!data.length) { if (wrap) wrap.hidden = true; return; }
    wrap.hidden = false;
    var track = $("#bannerTrack");
    track.innerHTML = data.map(function (b, i) { return bannerHTML(b, i % 2 === 1); }).join("");
    $("#bannerDots").innerHTML = data.map(function (_, i) { return '<i class="' + (i === 0 ? "is-on" : "") + '"></i>'; }).join("");
    bannerIdx = 0;
    clearInterval(bannerTimer);
    if (data.length > 1) {
      bannerTimer = setInterval(function () {
        bannerIdx = (bannerIdx + 1) % data.length;
        var rtl = document.documentElement.dir === "rtl";
        track.scrollTo({ left: (track.clientWidth + 12) * bannerIdx * (rtl ? -1 : 1), behavior: "smooth" });
      }, 3800);
    }
  }
  $("#bannerTrack").addEventListener("scroll", function () {
    var track = $("#bannerTrack");
    var i = Math.round(Math.abs(track.scrollLeft) / (track.clientWidth + 12));
    bannerIdx = Math.min(i, track.children.length - 1);
    $$("#bannerDots i").forEach(function (d, di) { d.classList.toggle("is-on", di === bannerIdx); });
  }, { passive: true });

  /* ── stories (rail + full-screen viewer) ──────────────── */
  (function () {
    var EP = CFG.endpoints || {};
    var rail = $("#storyRail");
    if (!rail || !EP.stories) return;
    var stories = [], sIdx = 0, sTimer = null, sT = 0;
    var DUR = 5; /* seconds per story */

    (window.mbStoriesGet?window.mbStoriesGet(EP.stories):fetch(EP.stories).then(function(r){return r.json();})).then(function (d) {
      stories = (d && d.data) || [];
      if (!stories.length) { rail.hidden = true; return; }
      rail.hidden = false;
      rail.innerHTML = stories.map(function (s, i) {
        return '<button class="story-pill" data-story="' + i + '" type="button">' +
          '<span class="sp-ring"><img src="' + esc(s.media_url) + '" alt="" loading="lazy"' + IMG_ERR + "></span>" +
          "<small>" + esc(s.caption ? String(s.caption).slice(0, 14) : T("popular", "Popular")) + "</small></button>";
      }).join("");
    }).catch(function () { rail.hidden = true; });

    var viewer = $("#storyViewer"), media = $("#svMedia"), cap = $("#svCaption"),
        cta = $("#svCta"), bars = $("#svBars");
    if (!viewer || !media || !bars) return;

    function buildBars() {
      bars.innerHTML = stories.map(function () { return '<span class="sv-bar"><i></i></span>'; }).join("");
    }
    function paintBars() {
      $$(".sv-bar i", bars).forEach(function (b, i) {
        b.style.width = i < sIdx ? "100%" : (i === sIdx ? (sT / DUR * 100) + "%" : "0%");
      });
    }
    function renderStory() {
      var s = stories[sIdx]; if (!s) return;
      media.src = s.media_url;
      cap.textContent = s.caption || "";
      if (cta) {
        if (s.cta_url && !(window.mbStoryCta && window.mbStoryCta.isCurrentPage(s.cta_url))) {
          cta.hidden = false;
          cta.href = s.cta_url;
          if (window.mbStoryCta) window.mbStoryCta.apply(cta, s.cta_url);
          if (s.cta_label) cta.querySelector("span").textContent = s.cta_label;
        } else { cta.hidden = true; }
      }
      sT = 0; paintBars();
    }
    function tick() { sT += 0.05; if (sT >= DUR) { nextStory(); return; } paintBars(); }
    function stopStory() { if (sTimer) { clearInterval(sTimer); sTimer = null; } }
    function startStory() { stopStory(); renderStory(); sTimer = setInterval(tick, 50); }
    function openStory(i) {
      sIdx = i; buildBars();
      viewer.hidden = false;
      requestAnimationFrame(function () { viewer.classList.add("is-open"); });
      startStory();
    }
    function closeStory() {
      stopStory();
      viewer.classList.remove("is-open");
      setTimeout(function () { viewer.hidden = true; }, 300);
    }
    function nextStory() { if (sIdx < stories.length - 1) { sIdx++; renderStory(); } else { closeStory(); } }
    function prevStory() { if (sIdx > 0) { sIdx--; renderStory(); } else { sT = 0; paintBars(); } }

    rail.addEventListener("click", function (e) {
      var b = e.target.closest("[data-story]");
      if (b) openStory(Number(b.getAttribute("data-story")));
    });
    on("svClose", "click", closeStory);
    /* CTA opens the item in-page beneath this viewer; close the viewer so the item sheet is the top layer. */
    if (cta) cta.addEventListener("click", closeStory);
    on("svNext", "click", nextStory);
    on("svPrev", "click", prevStory);
    var stage = $("#svStage");
    if (stage) {
      stage.addEventListener("pointerdown", stopStory);
      stage.addEventListener("pointerup", function () { if (!viewer.hidden && !sTimer) sTimer = setInterval(tick, 50); });
    }
  })();

  /* ── search overlay ───────────────────────────────────── */
  function openSearch() { var o = $("#searchOverlay"); o.hidden = false; requestAnimationFrame(function () { o.classList.add("is-open"); }); renderSoChips(); renderSearch(); setTimeout(function () { $("#soInput").focus(); }, 120); }
  function closeSearch() { var o = $("#searchOverlay"); o.classList.remove("is-open"); setTimeout(function () { o.hidden = true; }, 320); }
  function searchPresets() { return CATS.filter(function (c) { return ITEMS.some(function (i) { return String(itemRoot(i)) === String(c.id); }); }).slice(0, 5); }
  function renderSoChips() {
    $("#soChips").innerHTML = searchPresets().map(function (c) { return '<button data-preset="' + c.id + '" class="' + (S.soPreset === String(c.id) ? "is-on" : "") + '">' + esc(c.name) + "</button>"; }).join("");
  }
  function renderSearch() {
    /* the overlay is optional (`search.enabled`), and rerenderMoney() repaints blind */
    var box = $("#soResults"); if (!box) return;
    var q = S.search.trim().toLowerCase(), list;
    if (S.soPreset) list = ITEMS.filter(function (i) { return String(itemRoot(i)) === String(S.soPreset); });
    else if (q) list = ITEMS.filter(function (i) { return (i.name + " " + i.desc).toLowerCase().indexOf(q) !== -1; });
    else list = ITEMS.filter(function (i) { return i.is_popular; });
    if (!list.length && !q && !S.soPreset) list = ITEMS.slice(0, 6);
    $("#soEmpty").hidden = list.length > 0;
    box.innerHTML = list.map(function (i, idx) {
      return '<button class="so-row" data-open="' + i.id + '" style="animation-delay:' + Math.min(idx * 35, 300) + 'ms">' +
        '<img src="' + esc(i.img) + '" alt=""' + IMG_ERR + "><div><b>" + esc(i.name) + "</b><small>" + esc(catName(i.cat)) + " · " + money(i.price) + "</small></div>" + ICONS.arr + "</button>";
    }).join("");
  }

  /* ── product view ─────────────────────────────────────── */
  function openProduct(id) { var so = $("#searchOverlay"); if (so && !so.hidden) closeSearch(); setProduct(id, true); show("product"); itemEvent(id, "view"); }
  /* The demo's variant data was literally S / M / L / XL, so square chips read
     correctly there. Real variants are WORDS ("Regular", "Spicy"), and squaring
     them meant slicing to two letters — a customer saw "RE" and "SP" and could not
     tell what either one cost without tapping it. So: keep the squares only when
     every label genuinely is a short token (a size code or a number), otherwise
     fall back to full-width rows that spell the label out with its price. */
  function vlAbbr(s) {
    s = String(s == null ? "" : s).trim();
    var m = s.match(/^(\d+(?:\.\d+)?)\s*\w{0,3}$/); if (m) return m[1];
    return s.toUpperCase();
  }
  function vlSquareable(variants) {
    return (variants || []).every(function (v) {
      var l = String(v.label == null ? "" : v.label).trim();
      return l.length > 0 && (l.length <= 3 || /^\d+(\.\d+)?\s*\w{0,3}$/.test(l));
    });
  }
  function sizeNoteText() {
    var i = S.current; if (!i || !(i.variants || []).length) return "";
    var v = (i.variants || []).filter(function (x) { return x.id === S.pVariant; })[0];
    return v ? v.label + " · " + money(v.price) : "";
  }
  function selectedMods() { var mods = []; for (var gid in S.pMods) (S.pMods[gid] || []).forEach(function (m) { mods.push(m); }); return mods; }
  function selectedExtras() { var i = S.current, out = []; if (!i) return out; (i.extras || []).forEach(function (e) { if (S.pExtras[e.id]) out.push(e); }); return out; }

  function setProduct(id, fresh) {
    var i = item(id); if (!i) return;
    var same = S.current && S.current.id === i.id;
    S.current = i;
    if (fresh || !same) { S.pQty = 1; S.pVariant = (i.variants || []).length ? i.variants[0].id : 0; S.pMods = {}; S.pExtras = {}; S.pImgIdx = 0; }

    var imgs = itemImgs(i);
    var multiImg = imgs.length > 1;
    $("#pGallery").innerHTML = imgs.map(function (g) { return '<img src="' + esc(g) + '" alt=""' + IMG_ERR + ">"; }).join("");
    $("#pGalleryDots").innerHTML = multiImg ? imgs.map(function (_, gi) { return '<i class="' + (gi === 0 ? "is-on" : "") + '"></i>'; }).join("") : "";
    /* prev/next arrows only page multi-image items (R8) */
    var pgp = $("#pGalPrev"); if (pgp) pgp.hidden = !multiImg;
    var pgn = $("#pGalNext"); if (pgn) pgn.hidden = !multiImg;
    $("#pGallery").scrollTo({ left: 0 });
    var stockEl = $("#pStock"); stockEl.hidden = !soldOut(i); if (soldOut(i)) stockEl.textContent = T("sold_out", "Sold out");

    var cn = catName(i.cat);
    var diet = [
      i.is_vegan ? '<span class="diet-chip">' + ICONS.leaf + "Vegan</span>" : "",
      i.is_halal ? '<span class="diet-chip">Halal</span>' : "",
      i.is_gluten_free ? '<span class="diet-chip">GF</span>' : "",
      i.spice_level > 0 ? '<span class="diet-chip">' + "🌶".repeat(Math.min(i.spice_level, 3)) + "</span>" : "",
    ].filter(Boolean).join("");

    var meta = [
      i.rating ? '<span class="m star">' + ICONS.star + i.rating + (i.reviews ? " (" + i.reviews + ")" : "") + "</span>" : "",
      i.calories ? '<span class="m">' + ICONS.fire + i.calories + " kcal</span>" : "",
    ].filter(Boolean).join("");

    /* variant picker + qty. The heading follows the store's OWN option-group title
       ("Style", "Size", …) instead of a hardcoded "Select size". */
    var vTitle = i.variantTitle || T("options", "Options");
    var squares = vlSquareable(i.variants);
    var sizesHTML = (i.variants || []).length ?
      '<div class="sec-row"><h3>' + esc(vTitle) + "</h3><h3>" + esc(T("qty", "Qty")) + "</h3></div>" +
      (squares
        ? '<div class="size-qty"><div class="size-row" role="radiogroup" aria-label="' + esc(vTitle) + '">' +
            i.variants.map(function (v) { return '<button type="button" role="radio" aria-checked="' + (S.pVariant === v.id) + '" class="size-sq ' + (S.pVariant === v.id ? "is-on" : "") + '" data-variant="' + v.id + '" title="' + esc(v.label) + '">' + esc(vlAbbr(v.label)) + "</button>"; }).join("") +
          "</div>" + qtyHTML() + "</div>" +
          '<p class="size-note" id="sizeNote">' + esc(sizeNoteText()) + "</p>"
        : '<div class="sec-row qty-only">' + qtyHTML() + "</div>" +
          '<div class="variant-list" role="radiogroup" aria-label="' + esc(vTitle) + '">' +
            i.variants.map(function (v) {
              return '<button type="button" role="radio" aria-checked="' + (S.pVariant === v.id) + '" class="variant-row' + (S.pVariant === v.id ? " is-on" : "") + '" data-variant="' + v.id + '">' +
                '<span class="variant-box radio">' + ICONS.check + "</span>" +
                '<span class="variant-name">' + esc(v.label) + "</span>" +
                '<span class="variant-price">' + money(v.price) + "</span></button>";
            }).join("") +
          "</div>")
      : '<div class="sec-row"><h3>' + esc(T("qty", "Qty")) + "</h3>" + qtyHTML() + "</div>";

    /* global option groups (rendered as extra-style rows) */
    var groupsHTML = "";
    (i.optionGroups || []).forEach(function (g) {
      var multi = g.max === null || g.max > 1;
      groupsHTML += '<div class="p-sec" data-group="' + g.id + '" data-multi="' + (multi ? 1 : 0) + '" data-min="' + (g.min || 0) + '" data-max="' + (g.max === null ? "" : g.max) + '">' +
        '<div class="p-sec-head"><h3>' + esc(g.title) + '</h3><span class="req' + (g.required ? "" : " optional") + '">' + esc(g.required ? T("required", "Required") : T("optional", "Optional")) + "</span></div>" +
        /* real buttons with roles — a keyboard or screen-reader user could not
           satisfy a REQUIRED option group when these were inert <div>s. */
        '<div class="extras" role="' + (multi ? "group" : "radiogroup") + '" aria-label="' + esc(g.title) + '">' + g.options.map(function (o) {
          return '<button type="button" role="' + (multi ? "checkbox" : "radio") + '" aria-checked="false" class="extra" data-opt="' + o.i + '" data-price="' + o.price + '" data-label="' + esc(o.label) + '"><span class="extra-box' + (multi ? "" : " radio") + '">' + ICONS.check + '</span><span class="extra-name">' + esc(o.label) + '</span><span class="extra-price">' + (o.price ? "+" + money(o.price) : "") + "</span></button>";
        }).join("") + "</div></div>";
    });

    /* paid extras */
    var extrasHTML = (i.extras || []).length ?
      '<div class="p-sec"><div class="p-sec-head"><h3>' + esc(T("extras", "Extras")) + '</h3><span class="opt-note">' + esc(T("optional", "Optional")) + '</span></div><div class="extras">' +
        i.extras.map(function (e) {
          return '<button type="button" role="checkbox" aria-checked="false" class="extra" data-extra="' + e.id + '"><span class="extra-box">' + ICONS.check + "</span>" +
            (e.img ? '<img class="extra-thumb" src="' + esc(e.img) + '" alt=""' + IMG_ERR + ">" : "") +
            '<span class="extra-name">' + esc(e.title) + '</span><span class="extra-price">+' + money(e.price) + "</span></button>";
        }).join("") + "</div></div>" : "";

    var allergensHTML = (i.allergies || []).length ?
      '<div class="p-allergens">' + i.allergies.map(function (a) { return '<span class="allergen">' + (a.img ? '<img src="' + esc(a.img) + '" alt="">' : "⚠") + esc(a.title) + "</span>"; }).join("") + "</div>" : "";

    var macros = (i.protein || i.carbs || i.fat) ? '<span class="macros">' + (i.calories ? i.calories + " kcal · " : "") + "P " + (i.protein || 0) + "g · C " + (i.carbs || 0) + "g · F " + (i.fat || 0) + "g</span>" : "";

    var reviewsHTML = window.__mbItemReviewsOn === false ? "" : (i.reviewList || []).length
      ? '<section class="product-reviews"><div class="p-sec-head"><h3>' + esc(T("reviews_title", "Reviews")) + '</h3><span>' + esc(i.reviewTotal || i.reviewList.length) + '</span></div>' +
        i.reviewList.map(function (review) {
          var stars = Math.max(1, Math.min(5, Number(review.stars) || 0));
          return '<article><b>' + esc(review.name) + '</b><span class="review-stars">' +
            "★".repeat(stars) + '<span class="review-stars-dim">' + "★".repeat(5 - stars) + "</span>" +
            '</span>' + (review.body ? '<p>' + esc(review.body) + "</p>" : "") + "</article>";
        }).join("") + "</section>"
      : '<section class="product-reviews empty-reviews"><div class="p-sec-head"><h3>' + esc(T("reviews_title", "Reviews")) + '</h3></div><p>' + esc(T("reviews_empty", "No reviews yet.")) + "</p></section>";

    $("#pBody").innerHTML =
      '<button class="ghostbtn product-home-link" data-go="home" type="button">' + esc(T("back_to_menu", "Back to menu")) + "</button>" +
      '<p class="p-eyebrow">' + esc(cn) + (i.is_daily_special ? " · ★ " + esc(T("daily_special", "Daily Special")) : "") + "</p>" +
      '<div class="p-title-row"><h1 class="p-title">' + esc(i.name) + "</h1>" +
        '<button class="cbtn sm p-fav ' + (favs.has(String(i.id)) ? "is-on" : "") + '" data-fav="' + i.id + '" aria-label="Favorite">' + ICONS.heart + "</button></div>" +
      (meta || diet ? '<div class="p-meta">' + meta + diet + "</div>" : "") +
      allergensHTML +
      /* The demo put a "Following" pill and an "Official restaurant ✓" badge here.
         Neither has anything behind it — there is no follow feature and no
         verification programme — so per the owner rule on demo fakery they are not
         shipped. The row keeps the store's real identity and now carries data the
         customer can actually act on: whether the kitchen is open, and how long
         food takes. */
      '<div class="seller">' +
        (CFG.logo ? '<img class="seller-av" src="' + esc(CFG.logo) + '" alt=""' + IMG_ERR + ">" : '<span class="seller-av">' + esc(String(CFG.restaurantName || "?").trim().charAt(0).toUpperCase()) + "</span>") +
        '<div class="seller-txt"><b>' + esc(CFG.restaurantName) + "</b>" +
          '<small class="seller-status' + (CFG.storeOpen ? "" : " is-closed") + '"><i></i>' +
            esc(CFG.storeOpen ? T("open_now", "Open now") : T("closed", "Closed")) +
            (CFG.prepEta ? " · " + esc(CFG.prepEta) : "") +
          "</small></div>" +
      "</div>" +
      sizesHTML + groupsHTML + extrasHTML +
      (i.desc ? '<div class="p-sec"><div class="p-sec-head"><h3>' + esc(T("description", "Description")) + "</h3></div></div><p class=\"p-desc\">" + esc(i.desc) + macros + "</p>" : (macros ? '<p class="p-desc">' + macros + "</p>" : "")) +
      (window.__mbItemReviewsOn === false ? "" : '<div class="rate-row"><span>' + esc(T("rate_dish", "Rate this dish")) + '</span><span class="rate-stars" id="rateStars">' +
        [1, 2, 3, 4, 5].map(function (n) { return '<button data-rate="' + n + '" aria-label="' + n + '">' + ICONS.star + "</button>"; }).join("") +
      '</span><span class="rate-thanks" id="rateThanks" hidden>' + esc(T("thanks", "Thanks!")) + "</span></div>") +
      reviewsHTML;

    updateProductCta();
  }
  function qtyHTML() {
    return '<div class="stepper" id="pQty"><button data-q="-1" aria-label="Decrease">' + ICONS.minus + '</button><output id="pQtyVal">' + S.pQty + '</output><button data-q="1" aria-label="Increase">' + ICONS.plus + "</button></div>";
  }
  function updateProductCta() {
    var i = S.current; if (!i) return;
    var q = $("#pQtyVal"); if (q) q.textContent = S.pQty;
    var n = $("#sizeNote"); if (n) n.textContent = sizeNoteText();
    var pt = $("#pTotal"); if (pt) pt.textContent = money(unitPrice(i, S.pVariant, selectedMods(), selectedExtras()) * S.pQty);
    var btn = $("#addToCart"); if (btn) btn.disabled = soldOut(i);
  }
  $("#pGallery").addEventListener("scroll", function () {
    var g = $("#pGallery"); var i = Math.round(Math.abs(g.scrollLeft) / g.clientWidth); S.pImgIdx = i;
    $$("#pGalleryDots i").forEach(function (d, di) { d.classList.toggle("is-on", di === i); });
  }, { passive: true });
  /* R8 — pointer/desktop paging arrows. Index read from scroll position so it
     stays in step with swipes; RTL scrolls negative, so mirror the offset. */
  function galIndex() { var g = $("#pGallery"); return g ? Math.round(Math.abs(g.scrollLeft) / g.clientWidth) : 0; }
  function galGo(i) {
    var g = $("#pGallery"); if (!g) return;
    var n = g.querySelectorAll("img").length;
    i = Math.max(0, Math.min(n - 1, i));
    var dir = getComputedStyle(g).direction === "rtl" ? -1 : 1;
    g.scrollTo({ left: dir * i * g.clientWidth, behavior: "smooth" });
  }
  on("pGalPrev", "click", function () { galGo(galIndex() - 1); });
  on("pGalNext", "click", function () { galGo(galIndex() + 1); });

  /* ── cart ─────────────────────────────────────────────── */
  function addToCart(id, variantId, mods, extras, qty, fromEl) {
    var i = item(id);
    if (!i || soldOut(i)) { toast(T("sold_out", "Sold out")); return; }
    var missing = (i.optionGroups || []).filter(function (g) {
      var need = g.required ? Math.max(1, g.min || 0) : (g.min || 0);
      var have = (mods || []).filter(function (m) { return String(m.groupId) === String(g.id); }).length;
      return have < need;
    });
    if (missing.length) { toast(T("choose", "Choose an option") + ": " + missing[0].title); return; }
    var key = id + "|" + (variantId || 0) + "|" + (mods || []).map(function (m) { return m.groupId + ":" + m.optIndex; }).sort().join(",") + "|" + (extras || []).map(function (e) { return e.id; }).sort().join(",");
    var line = cart.filter(function (l) { return l.key === key; })[0];
    if (line) line.qty += qty;
    else cart.push({ key: key, id: id, qty: qty, variantId: variantId || 0, mods: mods || [], extras: extras || [], note: "" });
    writeCart(); syncBadge(true);
    toast(i.name + " — " + T("added", "Added to bag"));
    if (fromEl) flyToBag(fromEl);
    itemEvent(id, "add_to_cart");
  }
  function flyToBag(fromEl) {
    var img = fromEl && fromEl.closest && fromEl.closest(".gcard, .so-row") ? fromEl.closest(".gcard, .so-row").querySelector("img") : ($$("#pGallery img")[S.pImgIdx] || $("#pGallery img"));
    var target = $(".view.is-active .bagbtn") || $('.nav-tab[data-tab="cart"]');
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
      ghost.style.transform = "scale(.16)"; ghost.style.opacity = ".25"; ghost.style.borderRadius = "50%";
    });
    setTimeout(function () { ghost.remove(); }, 800);
  }
  function syncBadge(bump) {
    var n = cartCount() + comboCount();
    $$(".bagcount").forEach(function (b) { b.hidden = n === 0; b.textContent = n; if (bump && n) { b.classList.remove("bump"); void b.offsetWidth; b.classList.add("bump"); } });
    /* product footer: a direct jump to the cart, shown once anything is in it
       (orbit #pCartJump parity). */
    var pj = $("#pCartJump"); if (pj) { pj.hidden = n === 0; var pjc = $("#pCartJumpCount"); if (pjc) pjc.textContent = n; }
  }

  /* COMBOS-V2 — read-only "Combos" group appended to the cart list and the
     checkout summary. Tapping a row reopens the combo sheet's basket view,
     which is where quantities are edited. */
  function comboGroupHTML(compact) {
    var lines = comboLines();
    if (!lines.length) return "";
    var h = '<div class="vl-combo-group" data-combo-open style="border-top:1px dashed var(--line,#8883);padding-top:8px;margin-top:8px;cursor:pointer">';
    lines.forEach(function (L) {
      h += '<div class="ck-row ck-row--combo">' +
        '<div class="ck-mid"><b>' + esc(L.name) + "</b>" +
        (L.parts.length ? "<small>" + esc(L.parts.join(" · ")) + "</small>" : "") + "</div>" +
        '<span class="ck-qty">×' + L.qty + "</span>" +
        '<span class="ck-price">' + money(L.price) + "</span></div>";
    });
    if (!compact) h += '<small style="opacity:.65">' + esc(T("edit", "Edit")) + " ›</small>";
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
    $("#clearCart").style.visibility = cart.length ? "" : "hidden";
    $("#cartList").innerHTML = comboGroupHTML(false) + cart.map(function (l, li) {
      var i = item(l.id); if (!i) return "";
      var label = lineLabel(l);
      var unit = unitPrice(i, l.variantId, l.mods, l.extras);
      /* Happy-hour strike-through: original_price is the pre-schedule BASE
         price, so the pre-discount unit is unit + (original − current base). */
      var wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
        ? unit + (Number(i.original_price) - Number(i.price)) : 0;
      return '<div class="cart-item" data-key="' + esc(l.key) + '" style="animation-delay:' + li * 50 + 'ms">' +
        '<button class="ci-im" data-open="' + i.id + '" type="button" aria-label="' + esc(i.name) + '"><img src="' + esc(i.img) + '" alt=""' + IMG_ERR + ">" +
          (i.schedule_label ? '<span class="ci-hh">' + esc(i.schedule_label) + "</span>" : "") + "</button>" +
        '<div class="ci-main">' +
          '<button class="ci-name" data-open="' + i.id + '" type="button">' + esc(i.name) + "</button>" +
          (label ? '<small class="ci-opts">' + esc(label) + "</small>" : "") +
          '<div class="ci-priceline"><span class="ci-price">' + money(unit * l.qty) + "</span>" +
            (wasUnit ? '<del class="ci-was">' + money(wasUnit * l.qty) + "</del>" : "") +
            (l.qty > 1 ? '<small class="ci-unit">' + l.qty + " × " + money(unit) + "</small>" : "") +
          "</div>" +
          '<button class="ci-notebtn" data-note>' + ICONS.note + "<span>" + (l.note ? T("note_edit", "Edit note") : T("note_add", "Add note")) + "</span></button>" +
          '<textarea class="ci-note" data-notekey="' + esc(l.key) + '" rows="2" placeholder="' + esc(T("note_ph", "No onions please…")) + '" ' + (l.noteOpen ? "" : "hidden") + ">" + esc(l.note || "") + "</textarea>" +
        "</div>" +
        '<div class="ci-side">' +
          '<button class="ci-remove" data-rm aria-label="' + esc(CFG.i18n.remove || "Remove") + '">' + ICONS.trash + "</button>" +
          '<div class="stepper">' +
            '<button data-cq="-1" aria-label="Decrease">' + ICONS.minus + "</button><output>" + l.qty + '</output><button data-cq="1" aria-label="Increase">' + ICONS.plus + "</button></div>" +
        "</div></div>";
    }).join("");
    renderOtFields(); renderTotals();
  }
  function renderOtFields() {
    var f = $("#otFields");
    var waiterRow = $("#waiterRow"); if (waiterRow) waiterRow.hidden = S.orderType !== "on-table" || !CFG.allowCallWaiter;
    $$("[data-ot]").forEach(function (b) { b.classList.toggle("is-on", b.dataset.ot === S.orderType); });
    if (!f) return;
    if (S.orderType === "on-table") {
      f.innerHTML = '<input id="tableSel" type="number" inputmode="numeric" placeholder="' + esc(T("choose_table", "Pick your table number")) + '" value="' + esc(CFG.defaultTable || "") + '" ' + (CFG.forceTable ? "readonly" : "") + '><p class="ot-hint">' + ICONS.check.replace("<svg", '<svg class="ic"') + "<span>" + esc(T("choose_table", "Pick your table number")) + "</span></p>";
    } else if (S.orderType === "delivery") {
      /* Same autocomplete + use-my-location affordance as checkout — the field
         is rebuilt on every order-type switch, so it is re-wired right here. */
      f.innerHTML = '<div class="addr-field" id="cartAddrField">' +
        '<input id="addrInput" value="' + esc(S.addr || "") + '" placeholder="' + esc(T("addr_ph", "Street, building, floor…")) + '" autocomplete="off" autocorrect="off" spellcheck="false">' +
        '<button class="uselocbtn" id="cartUseLocation" type="button" aria-label="' + esc(T("use_my_location", "Use my location")) + '" title="' + esc(T("use_my_location", "Use my location")) + '">' + ICONS.loc + "</button>" +
        '<div class="addr-suggest" id="cartAddrSuggest" hidden></div></div>' +
        '<p class="ot-hint">' + ICONS.check.replace("<svg", '<svg class="ic"') + "<span>" + esc(T("delivery_hint", "Delivered to your address")) + "</span></p>";
      wireAddressField($("#addrInput"), $("#cartAddrSuggest"), $("#cartUseLocation"), null);
    } else if (S.orderType === "room_service") {
      /* Room service is fulfilled in the guest's room, so like on-table it needs
         neither an address nor a phone number -- the room rides along on the
         hidden `table` field the room QR pre-fills. */
      f.innerHTML = '<p class="ot-hint">' + ICONS.check.replace("<svg", '<svg class="ic"') + "<span>" + esc(T("room_hint", "Delivered to your room")) + "</span></p>";
    } else {
      f.innerHTML = '<p class="ot-hint">' + ICONS.check.replace("<svg", '<svg class="ic"') + "<span>" + esc(T("pickup_hint", "Ready for pickup")) + "</span></p>";
    }
  }
  function totalsHTML() {
    var Tt = totals();
    var h = '<div class="tr"><span>' + esc(T("subtotal", "Subtotal")) + "</span><b>" + money(Tt.sub) + "</b></div>";
    if (Tt.disc) h += '<div class="tr disc"><span>' + esc(T("discount", "Discount")) + " (" + esc(S.coupon.code) + ")</span><b>−" + money(Tt.disc) + "</b></div>";
    if (Tt.tax) h += '<div class="tr"><span>' + esc(CFG.taxLabel) + "</span><b>" + money(Tt.tax) + "</b></div>";
    if (S.orderType === "delivery" && (cart.length || comboCount())) h += '<div class="tr"><span>' + esc(T("delivery", "Delivery fee")) + "</span><b>" + money(Tt.del) + "</b></div>";
    if (Tt.tip) h += '<div class="tr"><span>' + esc(T("tip", "Tip")) + "</span><b>" + money(Tt.tip) + "</b></div>";
    h += '<div class="tr grand"><span>' + esc(T("total", "Total")) + "</span><b>" + money(Tt.total) + "</b></div>";
    return h;
  }
  function renderTotals() {
    var Tt = totals();
    var box = $("#totalsBox"); if (box) box.innerHTML = totalsHTML();
    var ct = $("#cartCtaTotal"); if (ct) ct.textContent = money(Tt.total);
    var below = Tt.sub > 0 && CFG.minOrderValue > 0 && Tt.sub < CFG.minOrderValue;
    var msg = $("#minOrderMsg");
    if (msg) { msg.hidden = !below; if (below) msg.textContent = "⚠ " + T("min_order", "Minimum order") + " " + money(CFG.minOrderValue); }
    var go = $("#goCheckout"); if (go) go.disabled = below;
  }

  /* ── checkout ─────────────────────────────────────────── */
  function renderCheckout() {
    var addr = $("#addrBlock"); if (addr) addr.style.display = S.orderType === "delivery" ? "" : "none";
    var tf = $("#tableField"); if (tf) tf.style.display = S.orderType === "on-table" ? "" : "none";
    var pf = $("#phoneField"); if (pf) pf.style.display = (S.orderType !== "on-table" && S.orderType !== "room_service") ? "" : "none";
    var ca = $("#custAddr"); if (ca && !ca.value.trim() && S.addr) ca.value = S.addr;
    /* Order summary — what the customer is about to pay for, tap Edit → cart. */
    var ck = $("#ckItems");
    if (ck) {
      ck.hidden = cart.length === 0 && comboCount() === 0;
      ck.innerHTML = '<div class="ck-head"><h3>' + esc(T("your_order", "Your order")) + '</h3><button class="linkbtn" id="ckEdit" type="button">' + esc(T("edit", "Edit")) + "</button></div>" +
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
    var ct = $("#checkoutTotals"); if (ct) ct.innerHTML = totalsHTML();
    var cc = $("#checkoutCtaTotal"); if (cc) cc.textContent = money(totals().total);
    renderRecentAddrs();
  }
  function placeOrder() {
    var err = $("#formError"); err.hidden = true;
    if (!S.orderType) { err.textContent = T("select_type", "Please choose an order type"); err.hidden = false; show("cart"); return; }
    if (!cart.length && !comboCount()) return; /* combos-only orders are legal */
    var Tt = totals();
    if (CFG.minOrderValue > 0 && Tt.sub < CFG.minOrderValue) { err.textContent = T("min_order", "Minimum order") + " " + money(CFG.minOrderValue); err.hidden = false; return; }
    var name = ($("#custName") ? $("#custName").value.trim() : "") || CFG.customerName || "";
    if (!name) { err.textContent = T("fill_contact", "Add your name & phone first"); err.hidden = false; return; }
    var addrVal = (($("#custAddr") && $("#custAddr").value.trim()) || S.addr || "").trim();
    if (S.orderType === "delivery" && !addrVal) { err.textContent = T("fill_addr", "Add a delivery address in the cart"); err.hidden = false; return; }
    if (CFG.authMode === "required" && !CFG.isLoggedIn) { openAuth(); return; }

    var payload = orderLines();
    var payVia = (root.querySelector(".pm.is-on") || {}).dataset ? root.querySelector(".pm.is-on").dataset.pm : "pay_on_counter";
    var useWallet = payVia === "wallet";
    if (useWallet) payVia = "pay_on_counter";

    var fd = new FormData();
    fd.append("_token", CFG.csrf);
    fd.append("items", JSON.stringify(payload));
    fd.append("name", name);
    fd.append("ordering-type", S.orderType);
    if (S.orderType === "on-table" || S.orderType === "room_service") fd.append("table", ($("#tableInput") && $("#tableInput").value) || ($("#tableSel") && $("#tableSel").value) || "");
    else fd.append("phone-number", ($("#custPhone") && $("#custPhone").value) || "");
    if (S.orderType === "delivery") fd.append("address", addrVal);
    fd.append("pay_via", payVia);
    fd.append("tip_amount", String(Tt.tip || 0));
    fd.append("message", ($("#orderNote") && $("#orderNote").value) || "");
    if (S.when === "later" && $("#whenTime") && $("#whenTime").value) fd.append("scheduled_at", $("#whenTime").value);
    if (S.coupon) fd.append("coupon_code", S.coupon.code);
    if (useWallet) fd.append("use_wallet", "1");
    var bid = new URLSearchParams(window.location.search).get("branch_id"); if (bid) fd.append("branch_id", bid);
    var consent = $("#consentCb"); if (consent && consent.checked) { fd.append("marketing_consent", "1"); var ce = $("#captureEmail"); if (ce && ce.value) fd.append("capture_email", ce.value); }
    var waOpt = $("#waOptin"); if (waOpt && waOpt.checked) fd.append("whatsapp_optin", "1");

    /* A server error message is UNTRUSTED text. Rendering it as innerHTML let a raw
       PHP exception ("foreach() argument must be of type array|object…") land in the
       customer's face in a sibling theme. Text only, and anything that smells like a
       stack trace is swallowed in favour of a human sentence. */
    function safeServerMsg(m) {
      m = String(m == null ? "" : m).trim();
      if (!m || m.length > 180) return "";
      if (/\.php|on line \d|SQLSTATE|foreach\(|::|->|<[a-z/]/i.test(m)) return "";
      return m;
    }
    function showFormError(msg) { err.textContent = msg; err.hidden = false; show("checkout"); }

    var btn = $("#placeOrder"); btn.disabled = true; btn.querySelector(".spinner").hidden = false; btn.querySelector(".po-label").textContent = "…";
    var quoted = money(Tt.total);
    fetch(CFG.endpoints.submitOrder, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" }, body: fd })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (out) {
        var res = out.body;
        btn.disabled = false; btn.querySelector(".spinner").hidden = true; btn.querySelector(".po-label").textContent = T("place_order", "Place Order");
        if (!out.ok) {
          /* Never invite a blind retry on a 5xx — the order row may already exist. */
          showFormError(T("order_failed_check", "Something went wrong on our side. Check your orders before trying again so you are not charged twice."));
          return;
        }
        if (res && res.success) {
          rememberRecentOrder(res);
          /* Remember the delivery address for this guest so it returns as a chip. */
          if (S.orderType === "delivery") rememberAddress(addrVal, ($("#custLat") || {}).value, ($("#custLng") || {}).value);
          cart = []; writeCart(); syncBadge(false); resetCartExtras();
          if (res.message) { window.location.href = res.message; return; }  /* online payment redirect */
          $("#confirmEta").textContent = T("eta_" + S.orderType.replace(/-/g, "_"), "");
          var track = $("#confirmTrack"); track.href = res.track_url || "#";
          /* The customer's only record of what they just agreed to. `submitOrder` does
             not return an order id today (BLOCKED-SHARED), so the reference falls
             back to the track link and the AMOUNT is always printed. */
          var ref = res.order_number || res.order_id || res.id || "";
          var code = $("#confirmCode");
          if (ref) { code.textContent = T("order_label", "Order") + " #" + ref; code.hidden = false; } else { code.textContent = ""; code.hidden = true; }
          $("#confirmPts").textContent = T("paid_total", "Total") + " " + quoted;
          show("confirmed"); stack.length = 0;
          burstConfetti();
          if (res.whatsapp_url) mbWhatsAppHandoff(res.whatsapp_url, res.whatsapp_handoff);
          /* No auto-redirect: it used to fire at 3.4s and made the NPS row and the
             Google-review card unreachable UI. The customer leaves via Track Order. */
        } else {
          showFormError(safeServerMsg(res && res.message) || T("order_failed", "We couldn't place that order. Please try again."));
        }
      }).catch(function () { btn.disabled = false; btn.querySelector(".spinner").hidden = true; btn.querySelector(".po-label").textContent = T("place_order", "Place Order"); err.textContent = T("network_error", "Network error. Please try again."); err.hidden = false; });
  }
  function burstConfetti() {
    var colors = ["#b5d336", "#a6ce39", "#141414", "#ffffff", "#8fb024"];
    $("#confetti").innerHTML = new Array(28).fill(0).map(function (_, i) {
      var tx = (Math.random() * 2 - 1) * 150, ty = -40 - Math.random() * 200;
      return '<i class="' + (Math.random() > 0.5 ? "round" : "") + '" style="--tx:' + tx + "px;--ty:" + ty + "px;--c:" + colors[i % colors.length] + ";--d:" + (Math.random() * 0.35) + "s;--rz:" + (Math.random() * 360) + "deg;--spin:" + (180 + Math.random() * 400) + 'deg"></i>';
    }).join("");
  }

  /* ── favorites ────────────────────────────────────────── */
  function renderFavs() {
    var list = ITEMS.filter(function (i) { return favs.has(String(i.id)); });
    $("#favGrid").innerHTML = list.map(cardHTML).join("");
    $("#favEmpty").hidden = list.length > 0;
  }

  /* ── loyalty ──────────────────────────────────────────── */
  var loyaltyLoaded = false;
  function loadLoyalty() {
    if (loyaltyLoaded || !CFG.endpoints.loyaltyBalance) return;
    loyaltyLoaded = true;
    fetch(CFG.endpoints.loyaltyBalance).then(function (r) { return r.json(); }).then(function (d) { if (d && d.active) paintLoyalty(d); }).catch(function () {});
  }
  function paintLoyalty(d) {
    var bal = d.balance || 0;
    var b1 = $("#loyaltyBalance"); if (b1) b1.textContent = bal;
    var b2 = $("#loySheetBal"); if (b2) b2.textContent = bal;
    if (CFG.loyaltyType === "stamps") { $$("#stampDots .stamp-dot").forEach(function (dot, i) { dot.classList.toggle("is-on", i < bal); }); }
    else { var bar = $("#loyaltyBar"); if (bar) bar.style.width = Math.min(100, d.progress_pct || 0) + "%"; }
    var rb = $("#loyaltyRedeem"); if (rb) rb.hidden = !d.ready_to_redeem;
    var sr = $("#loySheetRedeem"); if (sr) sr.hidden = !d.ready_to_redeem;
    var ss = $("#loySheetSub"); if (ss && d.ready_to_redeem) ss.textContent = T("redeem", "Redeem");
  }
  function redeemLoyalty(btn) {
    if (btn) btn.disabled = true;
    fetch(CFG.endpoints.loyaltyRedeem, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: "{}" })
      .then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.success) { var rb = $("#loyaltyRedeem"); if (rb) rb.hidden = true; var sr = $("#loySheetRedeem"); if (sr) sr.hidden = true; var ok = $("#loyaltyOk"); if (ok) ok.hidden = false; paintLoyalty({ balance: d.new_balance, progress_pct: 0, ready_to_redeem: false }); toast(T("redeemed", "Reward redeemed")); }
        else { if (btn) btn.disabled = false; toast((d && d.error) || T("coupon_bad", "That code doesn’t work")); }
      }).catch(function () { if (btn) btn.disabled = false; });
  }

  /* ── delegated clicks ─────────────────────────────────── */
  root.addEventListener("click", function (e) {
    var Tg = e.target; function closest(s) { return Tg.closest ? Tg.closest(s) : null; }

    var fav = closest("[data-fav]");
    if (fav) { e.stopPropagation(); e.preventDefault(); var fid = String(fav.dataset.fav); favs.has(fid) ? favs.delete(fid) : favs.add(fid); writeFavs(); fav.classList.toggle("is-on"); if (active === "favs") renderFavs(); return; }
    /* quick-add on a grid card — the card is an <a>, so stop the nav/open before it fires */
    var add = closest("[data-add]");
    if (add) {
      e.stopPropagation(); e.preventDefault();
      var ai = item(add.dataset.add); if (!ai) return;
      if (soldOut(ai)) { toast(T("sold_out", "Sold out")); return; }
      if (needsChoice(ai)) { openProduct(ai.id); return; }
      addToCart(ai.id, 0, [], [], 1, add);
      return;
    }
    /* R7 — same-page ?item= links: grid card anchors + share/CTA links. Checked
       AFTER the controls above so a click on a card's heart (inside the card anchor,
       which preventDefaults it) wins. Same-pathname item links open in place; real
       external links full-load. Boot handles a cold ?item= / new-tab load. */
    var itemLink = closest('a[href*="item="]');
    if (itemLink && !itemLink.target) {
      try {
        var u = new URL(itemLink.getAttribute("href") || "", window.location.href);
        var iid = u.searchParams.get("item");
        if (iid && u.pathname === window.location.pathname && item(iid)) { e.preventDefault(); openProduct(iid); return; }
      } catch (err) {}
    }

    var bopen = closest("[data-bopen]"); if (bopen) { openProduct(bopen.dataset.bopen); return; }
    var open = closest("[data-open]"); if (open) { openProduct(open.dataset.open); return; }
    var go = closest("[data-go]"); if (go) { show(go.dataset.go); return; }
    if (closest("[data-back]")) { back(); return; }
    var tab = closest(".nav-tab");
    if (tab) {
      var _nt = tab.dataset.tab;
      // Menu and Search are not views: menu jumps to the grid on home, search
      // opens the overlay. Everything else is a real view switch.
      if (_nt === "search") { openSearch(); return; }
      if (_nt === "menu") { if (active !== "home") show("home"); var _g = document.querySelector('[data-mb-grid-lead]') || document.getElementById("grid"); if (_g) _g.scrollIntoView({ behavior: "smooth", block: "start" }); return; }
      show(_nt, { push: false }); return;
    }
    var chip = closest("[data-cat]"); if (chip) { S.cat = chip.dataset.cat; S.sub = "root"; renderCats(); renderGrid(); return; }
    var subchip = closest("#catSubRail [data-sub]"); if (subchip) { S.sub = subchip.dataset.sub; renderSubCats(); renderGrid(); return; }

    /* variant picker — squares (short labels) or labelled rows (real names) */
    var sq = closest("[data-variant]");
    if (sq && S.current) {
      S.pVariant = Number(sq.dataset.variant);
      $$("[data-variant]").forEach(function (b) {
        var on = Number(b.dataset.variant) === S.pVariant;
        b.classList.toggle("is-on", on);
        if (b.hasAttribute("role")) b.setAttribute("aria-checked", String(on));
      });
      updateProductCta(); return;
    }

    /* global option group option */
    var gOpt = closest(".p-sec[data-group] .extra");
    if (gOpt && S.current) {
      var grp = gOpt.closest("[data-group]"); var gid = grp.dataset.group, multi = grp.dataset.multi === "1";
      var max = grp.dataset.max === "" ? Infinity : Number(grp.dataset.max);
      var mod = { groupId: gid, optIndex: Number(gOpt.dataset.opt), label: gOpt.dataset.label, price: Number(gOpt.dataset.price) };
      var cur = S.pMods[gid] || [];
      if (multi) { var idx = cur.findIndex(function (m) { return m.optIndex === mod.optIndex; }); if (idx !== -1) cur.splice(idx, 1); else if (cur.length < max) cur.push(mod); else { toast("Max " + max); return; } }
      else { cur = cur.length && cur[0].optIndex === mod.optIndex ? [] : [mod]; }
      S.pMods[gid] = cur;
      $$(".extra", grp).forEach(function (o) { var on = cur.some(function (m) { return m.optIndex === Number(o.dataset.opt); }); o.classList.toggle("is-on", on); o.setAttribute("aria-checked", String(on)); });
      updateProductCta(); return;
    }
    /* paid extra */
    var ex = closest(".extra[data-extra]");
    if (ex && S.current) { var xid = ex.dataset.extra; S.pExtras[xid] = !S.pExtras[xid]; ex.classList.toggle("is-on", !!S.pExtras[xid]); ex.setAttribute("aria-checked", String(!!S.pExtras[xid])); updateProductCta(); return; }
    /* qty on product */
    var q = closest("#pQty [data-q]");
    if (q) { S.pQty = Math.max(1, S.pQty + Number(q.dataset.q)); updateProductCta(); return; }

    /* inline rate-this-dish — the tapped stars now OPEN the shared review sheet
       preselected to that rating, so rating and writing are one flow through one
       endpoint instead of a second text-less POST. The high-rating Google funnel
       that used to fire on the immediate POST is preserved via onSuccess, so it
       runs once the review is actually submitted. The direct POST remains only as
       a fallback when the shared partial is disabled. */
    var rate = closest("#rateStars [data-rate]");
    if (rate && S.current) {
      var n = Number(rate.dataset.rate);
      $$("#rateStars button").forEach(function (b, bi) { b.classList.toggle("is-on", bi < n); });
      if (window.mbItemReviews) {
        window.mbItemReviews.open(n, { onSuccess: function (stars) { if (stars >= 4 && CFG.googleReviewUrl) setTimeout(function () { window.open(CFG.googleReviewUrl, "_blank"); }, 700); } });
        return;
      }
      fetch(CFG.endpoints.review, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ menu_id: S.current.id, stars: n }) })
        .then(function (r) { return r.json(); }).then(function (d) { var t = $("#rateThanks"); if (t) { if (d && d.message) t.textContent = d.message; t.hidden = false; } if (n >= 4 && CFG.googleReviewUrl) setTimeout(function () { window.open(CFG.googleReviewUrl, "_blank"); }, 700); }).catch(function () {});
      return;
    }

    /* cart line qty / note / remove */
    var cq = closest("[data-cq]");
    if (cq) { var key1 = cq.closest(".cart-item").dataset.key; var line = cart.filter(function (l) { return l.key === key1; })[0]; if (!line) return; line.qty += Number(cq.dataset.cq); if (line.qty <= 0) cart = cart.filter(function (l) { return l !== line; }); writeCart(); syncBadge(false); renderCart(); return; }
    var nb = closest("[data-note]");
    if (nb) { var key3 = nb.closest(".cart-item").dataset.key; var l3 = cart.filter(function (l) { return l.key === key3; })[0]; if (l3) { l3.noteOpen = !l3.noteOpen; var ta = nb.closest(".ci-main").querySelector(".ci-note"); ta.hidden = !l3.noteOpen; if (l3.noteOpen) ta.focus(); } return; }
    var rm = closest("[data-rm]");
    if (rm) { var row = rm.closest(".cart-item"); row.classList.add("removing"); var key2 = row.dataset.key; setTimeout(function () { cart = cart.filter(function (l) { return l.key !== key2; }); writeCart(); syncBadge(false); renderCart(); toast(T("removed", "Removed")); }, 300); return; }

    /* order type / when */
    var ot = closest("[data-ot]");
    if (ot) { S.orderType = ot.dataset.ot; renderOtFields(); renderCheckout(); renderTotals(); return; }
    var wh = closest("#whenSeg [data-when]");
    if (wh) { S.when = wh.dataset.when; $$("#whenSeg button").forEach(function (b) { b.classList.toggle("is-on", b === wh); }); var wt = $("#whenTime"); if (wt) wt.hidden = S.when !== "later"; return; }

    /* payment / tip */
    var pm = closest("[data-pm]"); if (pm) { $$(".pm").forEach(function (b) { b.classList.toggle("is-on", b === pm); }); return; }
    var tip = closest("#tipRow [data-tip]");
    if (tip) { $$("#tipRow button").forEach(function (b) { b.classList.toggle("is-on", b === tip); }); var custom = $("#customTip"); if (tip.dataset.tip === "custom") { S.tipPct = 0; S.tipCustom = Number(custom.value) || 0; custom.hidden = false; } else { S.tipPct = Number(tip.dataset.tip); S.tipCustom = null; if (custom) custom.hidden = true; } renderCheckout(); return; }

    /* filters */
    var diet = closest("#dietRow [data-diet]"); if (diet) { S.filters.diets[diet.dataset.diet] = !S.filters.diets[diet.dataset.diet]; diet.classList.toggle("is-on"); refreshFilterCount(); return; }
    var al = closest("#allergenRow [data-al]"); if (al) { S.filters.allergens[al.dataset.al] = !S.filters.allergens[al.dataset.al]; al.classList.toggle("is-on"); refreshFilterCount(); return; }
    var sp = closest("#spiceScale [data-spice]"); if (sp) { S.filters.spice = Number(sp.dataset.spice); $$("#spiceScale button").forEach(function (b) { b.classList.toggle("is-on", b === sp); }); refreshFilterCount(); return; }
    var so = closest("#sortRow [data-sort]"); if (so) { S.sort = so.dataset.sort; $$("#sortRow button").forEach(function (b) { b.classList.toggle("is-on", b === so); }); refreshFilterCount(); return; }

    /* search presets */
    var pr = closest("[data-preset]"); if (pr) { S.soPreset = S.soPreset === String(pr.dataset.preset) ? null : String(pr.dataset.preset); S.search = ""; $("#soInput").value = ""; renderSoChips(); renderSearch(); return; }

    /* lang / currency */
    var lg = closest("[data-lang-code]"); if (lg) {
      setCookie("Quick_lang", lg.dataset.langName);
      setCookie("Quick_user_lang", lg.dataset.langName);
      setCookie("Quick_user_lang_code", lg.dataset.langCode);
      navigateLanguage(lg.dataset.langCode);
      return;
    }
    var cu = closest("[data-cur]"); if (cu) { S.curCode = cu.dataset.cur; S.curRate = parseFloat(cu.dataset.rate) || 1; $$("[data-cur]").forEach(function (b) { b.classList.toggle("is-on", b === cu); }); rerenderMoney(); if (window.mbCurrency) mbCurrency.save(cu.dataset.cur, cu.dataset.rate); return; }

    /* menu sheet rows */
    var mn = closest("[data-menu]");
    if (mn) { var m = mn.dataset.menu; if (m === "loyalty") { loadLoyalty(); sheet("loyaltySheet"); } else if (m === "reserve") sheet("reserveSheet"); else if (m === "waitlist") sheet("waitlistSheet"); else if (m === "branch") sheet("branchSheet"); return; }

    /* loyalty redeem */
    if (closest("#loyaltyRedeem")) { redeemLoyalty($("#loyaltyRedeem")); return; }
    if (closest("#loySheetRedeem")) { redeemLoyalty($("#loySheetRedeem")); return; }

    /* appearance */
    var ap = closest("#themeSeg [data-appearance]");
    if (ap) { applyAppearance(ap.dataset.appearance); return; }

    /* NPS */
    var nps = closest("#npsRow [data-nps]");
    if (nps) { $$("#npsRow button").forEach(function (x) { x.classList.remove("is-on"); }); nps.classList.add("is-on"); fetch(CFG.endpoints.nps, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ score: Number(nps.dataset.nps) }) }).catch(function () {}); $("#npsThanks").hidden = false; return; }
  });

  /* delegated inputs */
  root.addEventListener("input", function (e) {
    var Tg = e.target;
    if (Tg.id === "addrInput") { S.addr = Tg.value; return; }
    if (Tg.id === "soInput") { S.search = Tg.value; S.soPreset = null; renderSoChips(); renderSearch(); return; }
    if (Tg.id === "customTip") { S.tipCustom = Number(Tg.value) || 0; renderCheckout(); return; }
    if (Tg.classList && Tg.classList.contains("ci-note")) { var l = cart.filter(function (x) { return x.key === Tg.dataset.notekey; })[0]; if (l) { l.note = Tg.value; writeCart(); } }
  });
  root.addEventListener("change", function (e) { if (e.target.id === "tableSel") S.table = e.target.value; });

  function refreshFilterCount() { var c = $("#filterCount"); if (c) c.textContent = visibleItems().length; }
  function rerenderMoney() { renderGrid(); renderBanners(); renderCart(); renderCheckout(); renderSearch(); if (S.current) setProduct(S.current.id, false); }
  function setCookie(k, v) { document.cookie = k + "=" + encodeURIComponent(v) + ";path=/;max-age=31536000"; }
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

  /* ── single-purpose bindings ──────────────────────────── */
  function on(id, ev, fn) { var el = document.getElementById(id); if (el) el.addEventListener(ev, fn); }
  /* ── appearance (Auto / Light / Dark) ─────────────────
     The attribute is the authority; absent means "follow the OS". The choice is
     stored per store so a customer's preference does not leak between restaurants,
     and re-applied before first paint by the inline bootstrap in the blades. */
  var themeKey = "velora-theme-" + slug;
  function currentAppearance() {
    var v = null; try { v = localStorage.getItem(themeKey); } catch (e) {}
    return v === "dark" || v === "light" ? v : "auto";
  }
  function applyAppearance(v) {
    if (v === "dark" || v === "light") {
      root.setAttribute("data-velora-theme", v);
      try { localStorage.setItem(themeKey, v); } catch (e) {}
    } else {
      root.removeAttribute("data-velora-theme");
      try { localStorage.removeItem(themeKey); } catch (e) {}
    }
    $$("#themeSeg [data-appearance]").forEach(function (b) {
      var on = b.dataset.appearance === v;
      b.classList.toggle("is-on", on);
      b.setAttribute("aria-checked", String(on));
    });
  }
  applyAppearance(currentAppearance());

  /* topbar quick toggle (Lane B): flip the EFFECTIVE mode (explicit attribute,
     else the OS preference) into an explicit choice through applyAppearance,
     which persists it and keeps the profile Auto/Light/Dark segment in sync. */
  on("veloraThemeToggle", "click", function () {
    var explicit = root.getAttribute("data-velora-theme");
    var isDark = explicit ? explicit === "dark"
      : !!(window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches);
    applyAppearance(isDark ? "light" : "dark");
    var b = document.getElementById("veloraThemeToggle");
    if (b) b.setAttribute("aria-pressed", String(!isDark));
  });

  on("splashSkip", "click", function () { $("#splash").classList.add("is-done"); });
  on("menuBtn", "click", function () { sheet("menuSheet"); });
  on("langBtn", "click", function () { sheet("langSheet"); });
  on("cvCateringBtn", "click", function () { sheet("cvCateringSheet"); });
  on("searchOpen", "click", openSearch);
  on("soBack", "click", closeSearch);
  on("filterBtn", "click", function () { refreshFilterCount(); sheet("filterSheet"); });
  on("filterDone", "click", function () { sheet("filterSheet", false); renderGrid(); });
  on("filterReset", "click", function () { S.filters = { diets: {}, allergens: {}, spice: 3 }; S.sort = "featured"; $$("#dietRow button,#allergenRow button").forEach(function (b) { b.classList.remove("is-on"); }); $$("#spiceScale button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.spice === "3"); }); $$("#sortRow button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.sort === "featured"); }); refreshFilterCount(); });
  on("clearFilters", "click", function () { var r = $("#filterReset"); if (r) r.click(); S.cat = "all"; S.sub = "root"; S.search = ""; renderCats(); renderGrid(); });
  on("catSeeAll", "click", function () { S.cat = "all"; S.sub = "root"; renderCats(); renderGrid(); });
  on("gridSeeAll", "click", function () { S.cat = "all"; S.sub = "root"; renderCats(); renderGrid(); var g = $("#grid"); if (g) g.scrollIntoView({ behavior: "smooth", block: "start" }); });
  /* Emptying the cart used to leave S.coupon / S.tipPct / S.tipCustom set AND the
     applied-coupon chrome in the DOM, so the NEXT basket silently inherited a
     discount and a tip the customer never entered in that session. */
  function resetCartExtras() {
    S.coupon = null; S.tipPct = 0; S.tipCustom = null;
    var ci = $("#couponInput"); if (ci) { ci.value = ""; ci.disabled = false; }
    var cf = ci && ci.closest(".coupon-field"); if (cf) cf.classList.remove("is-applied");
    var cm = $("#couponMsg"); if (cm) { cm.hidden = true; cm.textContent = ""; cm.className = "coupon-msg"; }
    $$("#tipRow button").forEach(function (b, bi) { b.classList.toggle("is-on", bi === 0); });
    var custom = $("#customTip"); if (custom) { custom.value = ""; custom.hidden = true; }
  }
  /* Two-step confirm: "Clear" arms, a second tap inside 3.5s empties. */
  var clearArmed = null;
  on("clearCart", "click", function () {
    var b = $("#clearCart");
    if (!clearArmed) {
      b.classList.add("is-armed");
      b.textContent = T("tap_again", "Tap again");
      clearArmed = setTimeout(function () {
        clearArmed = null; b.classList.remove("is-armed"); b.textContent = T("clear", "Clear");
      }, 3500);
      return;
    }
    clearTimeout(clearArmed); clearArmed = null;
    b.classList.remove("is-armed"); b.textContent = T("clear", "Clear");
    cart = []; writeCart(); syncBadge(false); resetCartExtras(); renderCart();
    toast(T("cart_cleared", "Bag emptied"));
  });
  on("goCheckout", "click", function () { show("checkout"); });
  on("placeOrder", "click", placeOrder);
  on("addToCart", "click", function () { if (!S.current) return; addToCart(S.current.id, S.pVariant, selectedMods(), selectedExtras(), S.pQty, null); });
  on("couponApply", "click", function () {
    var input = $("#couponInput"), msg = $("#couponMsg"), code = input.value.trim(); if (!code) return; msg.hidden = true;
    fetch(CFG.endpoints.coupon, { method: "POST", headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf }, body: couponBody(code) })
      .then(function (r) { return r.json(); }).then(function (res) {
        msg.hidden = false;
        if (res && res.success) { S.coupon = { code: res.label, coupon_type: res.coupon_type, percentage: res.percentage, discount: Number(res.discount) || 0, sig: couponSig() }; msg.className = "coupon-msg"; msg.textContent = "✓ " + (res.message || (T("coupon_ok", "Coupon applied — you save"))); $(".coupon-field").classList.add("is-applied"); }
        else { S.coupon = null; msg.className = "coupon-msg err"; msg.textContent = "✕ " + ((res && res.message) || T("coupon_bad", "That code doesn’t work")); $(".coupon-field").classList.remove("is-applied"); }
        renderTotals(); renderCheckout();
      }).catch(function () { msg.hidden = false; msg.className = "coupon-msg err"; msg.textContent = "✕ " + T("coupon_bad", "That code doesn’t work"); });
  });
  function waiterCall(type) {
    var tbl = ($("#tableInput") && $("#tableInput").value) || ($("#tableSel") && $("#tableSel").value) || CFG.defaultTable || "";
    fetch(CFG.endpoints.waiter, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ table: tbl, type: type, _token: CFG.csrf }) })
      .then(function (r) { return r.json(); }).then(function () { toast(type === "request_bill" ? T("bill_ok", "Bill requested — one moment") : T("waiter_ok", "A waiter is on the way")); }).catch(function () {});
  }
  on("callWaiter", "click", function () { waiterCall("call_waiter"); });
  on("requestBill", "click", function () { waiterCall("request_bill"); });
  on("rowReserve", "click", function () { sheet("reserveSheet"); });
  on("rowWaitlist", "click", function () { sheet("waitlistSheet"); });

  /* auth */
  function openAuth() { sheet("authSheet"); }
  on("loginLink", "click", openAuth);
  on("meAuthBtn", "click", function () { if (!CFG.isLoggedIn) openAuth(); });

  /* ── F2 · follow / brand row (crave/orbit parity) ────────
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
      if (!unfollow) { btn.classList.add("followbtn--pop"); btn.addEventListener("animationend", function h() { btn.classList.remove("followbtn--pop"); btn.removeEventListener("animationend", h); }); }
      fetch(ep, { method: unfollow ? "DELETE" : "POST", headers: headers(), body: "{}" })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!apply(d)) throw new Error("bad"); })
        .catch(function () { btn.dataset.following = prev ? "1" : "0"; btn.classList.toggle("is-on", prev); bump(unfollow ? 1 : -1); toast(T("action_failed", "Failed")); });
    });
  })();

  $$("#authTabs button").forEach(function (b) { b.addEventListener("click", function () { $$("#authTabs button").forEach(function (x) { x.classList.toggle("is-on", x === b); }); $$(".auth-form[data-auth]").forEach(function (f) { f.hidden = f.dataset.auth !== b.dataset.authTab; }); }); });
  function authPost(url, body, errEl) {
    return fetch(url, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf, "X-Requested-With": "XMLHttpRequest" }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); }).then(function (res) { if (res && res.success) { window.location.reload(); return res; } if (errEl) { errEl.textContent = (res && res.message) || "Failed."; errEl.hidden = false; } return res; });
  }
  on("loginBtn", "click", function () { authPost(CFG.endpoints.login, { store_id: CFG.storeId, email: $("#loginEmail").value.trim(), password: $("#loginPass").value }, $("#authErr")); });
  on("registerBtn", "click", function () { authPost(CFG.endpoints.register, { store_id: CFG.storeId, name: $("#regName").value.trim(), email: $("#regEmail").value.trim(), phone: $("#regPhone").value.trim(), password: $("#regPass").value, password_confirmation: ($("#regPass2") || {}).value || "" }, $("#authErr")); });
  on("otpSend", "click", function () { var phone = $("#otpPhone").value.trim(); if (!phone) return; fetch(CFG.endpoints.otpRequest, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ store_id: CFG.storeId, phone: phone }) }).then(function (r) { return r.json(); }).then(function (res) { if (res && res.success) { $("#otpStep1").hidden = true; $("#otpStep2").hidden = false; } else { var e2 = $("#authErr"); e2.textContent = (res && res.message) || "Failed."; e2.hidden = false; } }).catch(function () {}); });
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

  /* guest order lookup + orders placed in this browser */
  var recentOrdersKey = "velora_recent_orders_" + slug;
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
    var box = $("#lookupResults"), empty = $("#lookupEmpty"), recent = readRecentOrders();
    if (!box || !recent.length) return;
    if (empty) empty.hidden = true;
    box.innerHTML = recent.map(function (o) {
      return '<a class="order-row" href="' + esc(o.track_url) + '"><div><b>' +
        esc(o.id ? "#" + o.id : T("track_order", "Track order")) + "</b><small>" +
        esc(new Date(o.placed_at || Date.now()).toLocaleString()) +
        '</small></div><span class="order-status">' + esc(T("track_order", "Track order")) + "</span></a>";
    }).join("");
  }
  /* guest multi-address (localStorage) — chips for non-logged-in customers so they
     don't have to retype a delivery address. Gated on !isLoggedIn so it never fights
     the server address book. Storage key is computed INLINE (var-hoist gotcha: a
     `var KEY = …` declared after render reads undefined). */
  function addrKey() { return "velora_recent_addrs_" + slug; }
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
    var saved = $("#savedAddrsGuest");
    if (!saved || CFG.isLoggedIn) return;
    var l = readRecentAddrs();
    if (!l.length) { saved.hidden = true; saved.innerHTML = ""; return; }
    saved.hidden = false;
    saved.innerHTML = l.map(function (a, i) {
      return '<button class="saved-addr' + (i === 0 ? " is-on" : "") + '" type="button" data-addr="' + esc(a.addr) + '" data-lat="' + esc(a.lat) + '" data-lng="' + esc(a.lng) + '">' +
        '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
        '<span class="sa-text"><b>' + esc(T("saved_address", "Saved address")) + '</b><small>' + esc(a.addr) + "</small></span></button>";
    }).join("");
  }

  on("lookupBtn", "click", function () {
    var val = $("#lookupInput").value.trim(); if (!val) return;
    var box = $("#lookupResults"), empty = $("#lookupEmpty");
    box.innerHTML = "…"; if (empty) empty.hidden = true;
    function showEmpty(msg) {
      box.innerHTML = "";
      if (!empty) return;
      empty.hidden = false;
      var p = empty.querySelector("p"); if (p && msg) p.textContent = msg;
    }
    fetch(CFG.endpoints.orderLookup, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ identifier: val }) })
      .then(function (r) { return r.json(); }).then(function (data) {
        if (!data.success || !(data.orders || []).length) {
          showEmpty(data && data.message ? String(data.message) : T("no_orders_yet", "Your past orders will show up here."));
          return;
        }
        box.innerHTML = data.orders.map(function (o) { return '<a class="order-row" href="' + esc(o.track_url) + '"><div><b>#' + esc(o.id) + " · " + esc(o.total) + "</b><small>" + esc(o.date) + '</small></div><span class="order-status">' + esc(o.status) + "</span></a>"; }).join("");
      }).catch(function () { showEmpty(T("network_error", "Network error. Please try again.")); });
  });

  /* navbar hide on scroll */
  var lastY = 0;
  $$(".view-scroll").forEach(function (sc) {
    sc.addEventListener("scroll", function () {
      if (ROOTS.indexOf(active) === -1) return;
      var y = sc.scrollTop, nb = $("#navbar");
      nb.classList.toggle("is-hidden", y > lastY + 4 && y > 130);
      if (y < lastY - 4 || y < 130) nb.classList.remove("is-hidden");
      lastY = y;
    }, { passive: true });
  });

  /* reorder handoff from account page */
  /* Shared engine (_shared/js/reorder-pickup.js, loaded by store_templates.layout):
     resolves the server's choice-group selections + note onto this theme's
     cart-line shape so a reordered item keeps its modifiers and note. */
  window.__veloraPickup = function (line) {
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
    var r = window.JuwriReorder.pickup(slug, window.__veloraPickup, { toast: toast });
    if (r.added) { writeCart(); syncBadge(true); if (!r.unavailable.length) toast(T("added", "Added to bag")); }
  }

  /* ── nearest-branch map (self-hosted Leaflet) ─────────── */
  (function () {
    var EP = CFG.endpoints || {};
    var openBtn = $("#nearestBranchBtn");
    if (!openBtn || !EP.branchesGeo || typeof L === "undefined") { if (openBtn) openBtn.style.display = "none"; return; }
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
          "<b>" + esc(b.name) + (b.address ? "<small>" + esc(b.address) + "</small>" : "") + "</b>" + dist +
          '<span class="branch-check"></span></button>';
      }).join("") || '<p class="orders-empty">' + esc(T("no_branches", "No branches")) + "</p>";
    }
    function drawMap(branches) {
      var withCoords = branches.filter(function (b) { return b.latitude != null && b.longitude != null; });
      if (!map) {
        map = L.map("branchMap", { attributionControl: false });
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 }).addTo(map);
        L.control.attribution({ prefix: false }).addAttribution("© OpenStreetMap").addTo(map);
      }
      markers.forEach(function (m) { map.removeLayer(m); }); markers = [];
      if (!withCoords.length) { map.setView([24.7136, 46.6753], 11); return; }
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
      var url = EP.branchesGeo + (lat != null ? "?lat=" + lat + "&lng=" + lng : "");
      fetch(url).then(function (r) { return r.json(); }).then(function (d) {
        var branches = (d && d.branches) || [];
        var attrib = $("#branchMapAttrib"); if (attrib) attrib.textContent = (d && d.attribution) || "© OpenStreetMap contributors";
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

  /* ── delivery address autocomplete + use-my-location ────
     Shared by the checkout field (#custAddr, wired once below) and the cart's
     delivery field (#addrInput, re-wired by renderOtFields each time it is
     rebuilt). Coordinates always land in the checkout's hidden #custLat/#custLng
     pair — the order payload reads only those. */
  function wireAddressField(input, box, locBtn, attrib) {
    if (!input || !CFG.endpoints || !CFG.endpoints.geoAutocomplete) return;
    var timer = null, lastAttrib = "";
    function setAttrib(a) { if (a && attrib && a !== lastAttrib) { attrib.textContent = a; attrib.hidden = false; lastAttrib = a; } }
    function hideBox() { if (box) { box.hidden = true; box.innerHTML = ""; } }
    function setCoords(lat, lng) { var la = $("#custLat"), ln = $("#custLng"); if (la) la.value = lat || ""; if (ln) ln.value = lng || ""; }
    input.addEventListener("input", function () {
      var q = input.value.trim();
      S.addr = input.value;                 /* keep cart/checkout address in sync */
      setCoords("", "");                     /* typing invalidates a picked pin */
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
      input.value = opt.dataset.label || ""; S.addr = input.value;
      setCoords(opt.dataset.lat, opt.dataset.lng);
      hideBox();
    });
    input.addEventListener("blur", function () { setTimeout(hideBox, 200); });
    if (locBtn) locBtn.addEventListener("click", function () {
      if (!navigator.geolocation) { toast(T("loc_unavailable", "Location unavailable")); return; }
      locBtn.classList.add("is-loading");
      navigator.geolocation.getCurrentPosition(function (pos) {
        var lat = pos.coords.latitude, lng = pos.coords.longitude;
        fetch((CFG.endpoints.geoReverse || "") + "?lat=" + lat + "&lng=" + lng)
          .then(function (r) { return r.json(); })
          .then(function (d) {
            locBtn.classList.remove("is-loading");
            setAttrib(d && d.attribution);
            if (d && d.result) { input.value = d.result.label || ""; S.addr = input.value; setCoords(d.result.lat || lat, d.result.lng || lng); }
            else { toast(T("no_address", "No address found")); }
          }).catch(function () { locBtn.classList.remove("is-loading"); });
      }, function () { locBtn.classList.remove("is-loading"); toast(T("loc_denied", "Location denied")); }, { enableHighAccuracy: true, timeout: 8000 });
    });
  }
  (function () {
    var input = $("#custAddr");
    if (!input) return;
    wireAddressField(input, $("#custAddrSuggest"), $("#custUseLocation"), $("#custGeoAttrib"));
    /* Same fill behaviour for the server address book (#savedAddrs, logged-in) and
       the guest chips (#savedAddrsGuest, localStorage): tap → field + hidden coords. */
    function wireSavedAddrs(saved) {
      if (!saved) return;
      saved.addEventListener("click", function (e) {
        var b = e.target.closest(".saved-addr"); if (!b) return;
        input.value = b.dataset.addr || ""; S.addr = input.value;
        var la = $("#custLat"), ln = $("#custLng");
        if (la) la.value = b.dataset.lat || ""; if (ln) ln.value = b.dataset.lng || "";
        $$(".saved-addr", saved).forEach(function (x) { x.classList.remove("is-on"); });
        b.classList.add("is-on");
        var box = $("#custAddrSuggest"); if (box) { box.hidden = true; box.innerHTML = ""; }
      });
    }
    wireSavedAddrs($("#savedAddrs"));
    wireSavedAddrs($("#savedAddrsGuest"));
  })();

  /* ── boot ─────────────────────────────────────────────── */
  function boot() {
    pickupReorder();
    renderRecentOrders();
    renderRecentAddrs();
    /* default order type: room service > forced table > first enabled.
       The room-service button only renders when the scanned QR resolved to a
       hotel room, so its presence is the signal that this guest is in a room. */
    var otBtns = $$("#orderTypeSeg [data-ot]");
    if (otBtns.some(function (b) { return b.dataset.ot === "room_service"; })) S.orderType = "room_service";
    else if (CFG.forceTable && otBtns.some(function (b) { return b.dataset.ot === "on-table"; })) S.orderType = "on-table";
    else if (otBtns.length) S.orderType = otBtns[0].dataset.ot;

    renderCats();
    skeletons();
    syncBadge(false);
    renderBanners();
    renderOtFields();
    setTimeout(renderGrid, 650);

    /* splash: once per session */
    var splash = $("#splash");
    if (splash) {
      /* A1: a ?item=/?table= deep link opens straight on the product — dismiss the
         splash up front so it never overlays it. Not persisted: a later plain visit
         this session still gets the intro. */
      var _dl = null; try { _dl = new URLSearchParams(location.search); } catch (e) {}
      var deepLinked = !!(_dl && (_dl.get("item") || _dl.get("table")));
      if (deepLinked) splash.classList.add("is-done");
      else if (sessionStorage.getItem("vl_splash_" + slug)) splash.classList.add("is-done");
      else { sessionStorage.setItem("vl_splash_" + slug, "1"); setTimeout(function () { splash.classList.add("is-done"); }, 2300); }
    }

    /* replay the saved store-scoped currency choice (shared helper) */
    if (window.mbCurrency) mbCurrency.restore("#curSeg");

    /* R7 — open a product straight from a ?item= deep-link on first load. */
    var deepItem = new URLSearchParams(location.search).get("item");
    if (deepItem && item(deepItem)) openProduct(deepItem);

    if (CFG.openResSheet && $("#reserveSheet")) { show("profile"); sheet("reserveSheet"); }
    if (CFG.openWaitlistSheet && $("#waitlistSheet")) { show("profile"); sheet("waitlistSheet"); }

    requestAnimationFrame(layNav);
    setTimeout(layNav, 400);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { layNav(); });
  }
  boot();

  /* Collapsing header — condensed bar in once the cover header leaves the home
     scroll container. velora's category chips are not top-sticky, so no guard.
     Condensed buttons forward their click to the real top-chrome controls. */
  (function mountCondensedHeader() {
    var homeView = document.querySelector('.view[data-view="home"]');
    if (!homeView) return;
    var anchor = homeView.querySelector(".velora-cover") || homeView.querySelector(".home-top");
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
