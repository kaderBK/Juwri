// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ═══════════════════════════════════════════════════════════
   matcha — theme runtime
   Reads window.MATCHA (set by index.blade.php):
   items, endpoints, flags, i18n, currency, rates, csrf …
   ═══════════════════════════════════════════════════════════ */
(function () {
  "use strict";
  var CFG = window.MATCHA;
  if (!CFG) return;

  /* ── dom helpers ──────────────────────────────────────── */
  var root = document.getElementById("matcha");
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
  var ICONS = {
    plus: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg>',
    minus: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg>',
    heart: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>',
    star: '<svg class="ic" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>',
    clock: '<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
    fire: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 22c4.4 0 7-2.8 7-6.6 0-4.8-4.2-6.6-4.9-11.4-2.5 1.6-3.4 4-3.2 6.6-1.2-.7-2-1.7-2.4-3.4C6.6 9 5 11.6 5 15.4 5 19.2 7.6 22 12 22Z"/></svg>',
    check: '<svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>',
    trash: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M10 7V5h4v2M8 7l.8 13h6.4L16 7"/></svg>',
    leaf: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 19C5 9 13 5 20 5c0 8-4 14-12 14-1 0-2-.1-3 0Z"/><path d="M5 19c2-5 6-9 10-11"/></svg>',
    cup: '<svg class="ic" viewBox="0 0 24 24"><path d="M7 8h10l1.2 3.5a6.2 6.2 0 0 1-12.4 0Z"/><path d="M9 8V6.4A2.4 2.4 0 0 1 11.4 4h1.2A2.4 2.4 0 0 1 15 6.4V8M9.6 16.5 8.8 21M14.4 16.5l.8 4.5M7 21h10"/></svg>',
  };

  /* ── state ────────────────────────────────────────────── */
  var slug = CFG.restaurantSlug;
  var cartKey = "mb-cart-" + slug;          /* shared key with other themes */
  var favKey = "cv-favs-" + slug;
  var cart = [];
  try { cart = JSON.parse(localStorage.getItem(cartKey)) || []; } catch (e) {}
  if (!Array.isArray(cart)) cart = [];
  var favs = new Set();
  try { (JSON.parse(localStorage.getItem(favKey)) || []).forEach(function (id) { favs.add(String(id)); }); } catch (e) {}

  var S = {
    cat: "all", sub: "root", search: "", sort: "featured",
    filters: { diets: {}, allergens: {}, spice: 3 },
    orderType: null, coupon: null, tipPct: 0, tipCustom: null,
    current: null, pQty: 1, pVariant: 0, pMods: {}, pExtras: {},
    gridList: false,
    curCode: CFG.baseCurrency,
    curRate: 1,
  };

  /* ── money ────────────────────────────────────────────── */
  /* Decimals follow the DISPLAYED currency: the store's own code uses the
     server-supplied count, any converted code uses the shared map (else 2). */
  function decimalsFor(code) {
    var dp;
    if (!code || code === CFG.baseCurrency) dp = CFG.currencyDecimals;
    else dp = (CFG.decimalsByCode || {})[code];
    return dp == null ? 2 : dp;
  }

  /* PHP's round() is half-up; JS Math.round is half-up on positives too, so
     scaling by 10^dp reproduces the server's rounding exactly. */
  /* M16: delegates to the shared money core (assets/store_templates/_shared/js/mb-money.js)
     so this theme rounds exactly like PHP's round(). */
  function roundTo(v, dp) { return mbMoney.round(v, dp == null ? 2 : dp); }
  /* bug batch 9 · 2/6: order arithmetic rounds to the store currency's minor unit (what the order stores — IQD 3,
     MGA 2, ISK 0); CFG.currencyDecimals stays the DISPLAY precision */
  function moneyDp() { return CFG.moneyDecimals != null ? CFG.moneyDecimals : (CFG.currencyDecimals == null ? 2 : CFG.currencyDecimals); }

  function money(base) {
    var v = (Number(base) || 0) * S.curRate;
    var str = v.toFixed(decimalsFor(S.curCode));
    if (S.curCode !== CFG.baseCurrency) return "≈ " + S.curCode + " " + str;
    return CFG.currencyPos === "1" ? CFG.currencySign + (CFG.currencyGap || "") + str : str + (CFG.currencyGap || "") + CFG.currencySign;
  }

  /* ── items ────────────────────────────────────────────── */
  var ITEMS = Object.keys(CFG.items).map(function (k) { return CFG.items[k]; });
  function item(id) { return CFG.items[id]; }
  var FALLBACK_IMG = CFG.defaultImg || "";
  var IMG_ERR = FALLBACK_IMG ? ' data-csp-error="fallback" data-csp-fallback="' + esc(FALLBACK_IMG) + '"' : "";
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

  /* pricing: variant price is ABSOLUTE (overrides base); group options and extras are additive */
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
    if (l.variantId) {
      var v = (i.variants || []).filter(function (x) { return x.id === l.variantId; })[0];
      if (v) parts.push(v.label);
    }
    (l.mods || []).forEach(function (m) { parts.push(m.label); });
    (l.extras || []).forEach(function (e) { parts.push(e.title); });
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

  /* COMBOS-V2 — the shared combos partial (_partials/combos.blade.php) keeps its
     own basket and publishes display state on window.mbCombos {count,total,lines}.
     The server prices combo lines into the ORDER subtotal (and taxes them like any
     other line), so the quote must fold them in too — QUOTED == CHARGED. Quantity
     edits stay in the combo sheet; here they render read-only. */
  function comboCount() { return (window.mbCombos && window.mbCombos.count) || 0; }
  function comboTotal() { return (window.mbCombos && window.mbCombos.total) || 0; }
  function comboLines() { return (window.mbCombos && window.mbCombos.lines) || []; }

  function totals() {
    var sub = subtotal();
    var disc = 0;
    if (S.coupon) {
      /* mirrors CouponService: rounded in the store currency's decimals (bug batch 7 · 4: was 2 — a KWD store's
         10 % of 12.345 previewed 1.23, the order took 1.235) */
      disc = S.coupon.coupon_type === "percentage"
        ? roundTo(sub * (Number(S.coupon.percentage) || 0) / 100, moneyDp())
        : S.coupon.coupon_type === "bogo" ? bxgyDiscount() : roundTo(S.coupon.percentage, moneyDp());
      if (disc > sub) disc = sub;
    }
    /* Tax must be quoted exactly the way the server charges it (Order::computeTax):
       on the PRE-discount subtotal, honouring `restaurant_tax_type`, rounded to the
       store's own currency decimals. Taxing (sub - disc) here quoted $0.36 on an
       $8.00 / SAVE10 cart while the server charged $0.40 — the CTA lied. */
    var dp = moneyDp();
    var tax = 0;
    if (CFG.taxCharge > 0 && sub > 0) {
      tax = CFG.taxType === "fixed" ? roundTo(CFG.taxCharge, dp) : roundTo(sub * (CFG.taxCharge / 100), dp);
    }
    var del = S.orderType === "delivery" && (cart.length || comboCount()) ? CFG.deliveryCharge : 0;
    /* bug batch 9 · 6: the tip rounds like the order stores it (store currency's minor unit — KWD fils, JPY yen), not 2 */
    var tip = S.tipPct ? roundTo(sub * (S.tipPct / 100), dp) : (S.tipCustom || 0);
    return { sub: sub, disc: disc, tax: tax, del: del, tip: tip, total: sub - disc + tax + del + tip };
  }

  /* ── router ───────────────────────────────────────────── */
  var stack = [];
  var ROOTS = ["home"];
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
      if (!opts.fromPop) { try { history.pushState({ mc: 1 }, ""); } catch (e) {} }
    }
    if (isRoot) stack.length = 0;
    $$(".view").forEach(function (v) { v.classList.remove("is-under"); });
    if (!isRoot && from) from.classList.add("is-under");
    if (from) from.classList.remove("is-active");
    to.classList.add("is-active");
    var sc = to.querySelector(".view-scroll");
    if (sc) sc.scrollTop = 0;
    active = name;
    /* Floating dock is a home-screen chrome: hide it on every sub-view, which
       carries its own back button + footer CTA (a dock over those would collide). */
    var _mcDock = document.getElementById("mcDock");
    if (_mcDock) _mcDock.classList.toggle("is-hidden", name !== "home");
    if (name === "cart") renderCart();
    if (name === "favs") renderFavs();
    if (name === "checkout") renderCheckout();
  }
  function internalBack() { show(stack.pop() || "home", { push: false, fromPop: true }); }
  function back() {
    /* UI back buttons go through history when we pushed a state, so the
       browser's own back stack stays in step with what is on screen. */
    if (stack.length && history.state && history.state.mc) { history.back(); return; }
    internalBack();
  }
  window.addEventListener("popstate", function () {
    var s = root.querySelector(".sheet.is-open");
    if (s) { sheet(s.id, false); try { history.pushState({ mc: 1 }, ""); } catch (e) {} return; }
    if (stack.length) internalBack();
    else if (active !== "home") show("home", { push: false, fromPop: true });
  });

  /* ── sheets / toast ───────────────────────────────────── */
  var openSheetId = null;
  function sheet(id, open) {
    var s = document.getElementById(id), scrim = $("#cvScrim");
    if (!s) return;
    if (open !== false) {
      if (openSheetId && openSheetId !== id) sheet(openSheetId, false);
      s.hidden = false; scrim.hidden = false;
      requestAnimationFrame(function () { s.classList.add("is-open"); scrim.classList.add("is-open"); });
      openSheetId = id;
    } else {
      s.classList.remove("is-open"); scrim.classList.remove("is-open");
      setTimeout(function () { s.hidden = true; if (!openSheetId || openSheetId === id) scrim.hidden = true; }, 420);
      openSheetId = null;
    }
  }
  function closeAnySheet() {
    var id = openSheetId || (root.querySelector(".sheet.is-open") || {}).id;
    if (id) sheet(id, false);
  }
  $("#cvScrim").addEventListener("click", closeAnySheet);
  $$(".sheet-grab").forEach(function (g) {
    g.addEventListener("click", function () { var s = g.closest(".sheet"); if (s) sheet(s.id, false); });
  });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") closeAnySheet(); });

  var toastTimer;
  function toast(msg) {
    var t = $("#cvToast");
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

  /* ── analytics (#29) ──────────────────────────────────── */
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

  /* ── home render ──────────────────────────────────────── */
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
    var sub = (i.desc || "").split(/[.،!\n]/)[0].slice(0, 40);
    return '' +
      '<a class="card' + (unavailable ? " is-unavailable" : "") + '" href="' + esc(itemHref(i.id)) + '" data-open="' + i.id + '" style="animation-delay:' + Math.min(idx * 45, 400) + 'ms;color:inherit;text-decoration:none">' +
        '<span class="card-imgwrap">' +
          '<img src="' + esc(i.img) + '" alt="" loading="lazy"' + IMG_ERR + ">" +
          (i.is_sold_out ? '<span class="card-soldout">' + esc(CFG.i18n.sold_out) + "</span>" : "") +
          (unavailable ? '<span class="avail-badge">⏰ ' + esc(CFG.i18n.not_available) + (i.available_from_label ? " · " + esc(i.available_from_label) : "") + "</span>" : "") +
          hhBadgeHTML(i) +
        "</span>" +
        '<span class="card-badges">' +
          (i.is_popular ? '<span class="badge-pop">★ ' + esc(CFG.i18n.popular) + "</span>" : "") +
          '<span class="badge-veg ' + (i.type === "nonveg" ? "nv" : "") + '" title="' +
            esc(i.type === "nonveg" ? CFG.i18n.nonveg : CFG.i18n.veg) + '" aria-label="' +
            esc(i.type === "nonveg" ? CFG.i18n.nonveg : CFG.i18n.veg) + '"><i></i></span>' +
        "</span>" +
        '<span class="card-fav' + (fav ? " is-on" : "") + '" data-fav="' + i.id + '">' + ICONS.heart + "</span>" +
        '<span class="card-name">' + esc(i.name) + "</span>" +
        (sub ? '<span class="card-sub">' + esc(sub) + "</span>" : "") +
        '<span class="card-meta">' +
          (i.rating ? '<span class="pill-rate">' + ICONS.star + i.rating + "</span>" : "") +
          (i.calories ? '<span class="pill-rev">' + i.calories + " cal</span>" : "") +
        "</span>" +
        '<span class="card-from">' + ((i.variants || []).length || (i.optionGroups || []).length ? esc(CFG.i18n.starting_from) : "") + "</span>" +
        '<span class="card-price">' + hhWasHTML(i) + money(i.price) + "</span>" +
        (CFG.allowOrder && !i.is_sold_out && !unavailable ? '<span class="card-add" data-add="' + i.id + '">' + ICONS.plus + "</span>" : "") +
      "</a>";
  }

  /* a chip owns its own items plus everything in its subcategories — items carry only
     their own category_id, so a parent chip matches nothing without this (CFG.catKids) */
  function catMatch(itemCat, chipCat) {
    if (String(itemCat) === String(chipCat)) return true;
    var kids = (CFG.catKids || {})[String(chipCat)] || [];
    for (var k = 0; k < kids.length; k++) if (String(kids[k]) === String(itemCat)) return true;
    return false;
  }

  function visibleItems() {
    var q = S.search.trim().toLowerCase();
    var list = ITEMS.filter(function (i) {
      if (S.cat !== "all" && !catMatch(i.cat, S.cat)) return false;
      if (S.sub !== "root" && String(i.cat) !== String(S.sub)) return false;
      if (q && (String(i.name) + " " + String(i.desc)).toLowerCase().indexOf(q) === -1) return false;
      var f = S.filters;
      if (f.diets.vegan && !i.is_vegan) return false;
      if (f.diets.halal && !i.is_halal) return false;
      if (f.diets.gluten_free && !i.is_gluten_free) return false;
      if ((i.spice_level || 0) > f.spice) return false;
      for (var aId in f.allergens) {
        if (f.allergens[aId] && (i.allergies || []).some(function (a) { return String(a.id) === String(aId); })) return false;
      }
      return true;
    });
    if (S.sort === "price_asc") list.sort(function (a, b) { return a.price - b.price; });
    if (S.sort === "price_desc") list.sort(function (a, b) { return b.price - a.price; });
    if (S.sort === "pop") list.sort(function (a, b) { return (b.is_popular ? 1 : 0) - (a.is_popular ? 1 : 0) || b.rating - a.rating; });
    return list;
  }

  /* Reveal the active parent's subcategory chip row (if it has one), reset its
     selection to "All", and hide every other group. Called on each parent change. */
  function syncSubChips() {
    var wrap = $("#cvSubChips");
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

  /* Grid heading tracks the active parent chip, narrowed to the active sub-chip. */
  var gridTitleDefault = null;
  function syncGridTitle() {
    var el = $("#cvGridTitle");
    if (!el) return;
    if (gridTitleDefault === null) gridTitleDefault = el.textContent;
    if (S.cat === "all") { el.textContent = gridTitleDefault; return; }
    var lbl = null;
    if (S.sub && S.sub !== "root") {
      var scEl = $('#cvSubChips .subchip[data-sub="' + S.sub + '"]');
      if (scEl) lbl = scEl.textContent.trim();
    }
    if (!lbl) {
      var catEl = $('#cvChips .cat[data-cat="' + S.cat + '"] span:last-child');
      if (catEl) lbl = catEl.textContent.trim();
    }
    el.textContent = lbl || gridTitleDefault;
  }

  function renderGrid() {
    var list = visibleItems();
    $("#cvGrid").innerHTML = list.map(cardHTML).join("");
    $("#cvGridEmpty").hidden = list.length > 0;
    syncGridTitle();
    var f = S.filters;
    var activeF = f.diets.vegan || f.diets.halal || f.diets.gluten_free || f.spice < 3 ||
      Object.keys(f.allergens).some(function (k) { return f.allergens[k]; });
    var dot = $("#cvFilterDot"); if (dot) dot.hidden = !activeF;
    var cnt = $("#cvFilterCount"); if (cnt) cnt.textContent = list.length;
  }

  function renderSpecials() {
    var sp = ITEMS.filter(function (i) { return i.is_daily_special; });
    if (!sp.length) sp = ITEMS.filter(function (i) { return i.is_popular; }).slice(0, 8);
    if (!sp.length) { var blk = $("#cvSpecialsBlock"); if (blk) blk.hidden = true; return; }
    $("#cvSpecialsRail").innerHTML = sp.map(function (i) {
      return '<button class="rail-card" data-open="' + i.id + '">' +
        '<span class="rail-fav' + (favs.has(String(i.id)) ? " is-on" : "") + '" data-fav="' + i.id + '">' + ICONS.heart + "</span>" +
        '<img src="' + esc(i.img) + '" alt="" loading="lazy"' + IMG_ERR + "><b>" + esc(i.name) + '</b><span class="price">' + money(i.price) + "</span>" +
        (CFG.allowOrder && !i.is_sold_out ? '<span class="rail-add" data-add="' + i.id + '">' + ICONS.plus + "</span>" : "") +
        "</button>";
    }).join("");
  }

  function skeletons() {
    $("#cvGrid").innerHTML = new Array(6).fill(
      '<div class="card sk"><span class="card-imgwrap"></span><div class="sk-line"></div><div class="sk-line short"></div></div>'
    ).join("");
  }


  /* featured quick-add blob */
  var featItems = [], featIdx = 0, featQty = 1;
  function renderFeat() {
    var blob = $("#cvFeatBlob");
    if (!blob) return;
    featItems = ITEMS.filter(function (i) { return i.is_daily_special && !i.is_sold_out; }).slice(0, 3);
    if (!featItems.length) { blob.hidden = true; return; }
    blob.hidden = false;
    if (featIdx >= featItems.length) featIdx = 0;
    $("#cvFeatThumbs").innerHTML = featItems.map(function (i, ix) {
      /* The thumb is decorative (alt=""), so the BUTTON carries the name — an
         icon-only control with no label is announced as just "button". */
      return '<button data-feat="' + ix + '" class="' + (ix === featIdx ? "is-on" : "") + '" type="button" aria-label="' + esc(i.name || "") + '"><img src="' + esc(i.img) + '" alt=""' + IMG_ERR + "></button>";
    }).join("");
    var cur = featItems[featIdx];
    var fi = $("#cvFeatImg");
    if (FALLBACK_IMG) fi.onerror = function () { this.onerror = null; this.src = FALLBACK_IMG; };
    fi.src = cur.img;
    $("#cvFeatName").textContent = cur.name;
    $("#cvFeatQtyVal").textContent = featQty;
    syncFeatPrice();
  }
  /* the product sheet's CTA multiplies by qty; this quick-add surface did not,
     so qty 2 still advertised the single-unit price */
  function syncFeatPrice() {
    var cur = featItems[featIdx], el = $("#cvFeatPrice");
    if (!cur || !el) return;
    el.textContent = money(cur.price * featQty);
  }

  /* banners */
  var bannerIdx = 0, bannerTimer;
  function initBanners() {
    var track = $("#cvBannerTrack");
    if (!track || !track.children.length) return;
    var n = track.children.length;
    $("#cvBannerDots").innerHTML = new Array(n).fill(0).map(function (_, i) {
      return '<i class="' + (i === 0 ? "is-on" : "") + '"></i>';
    }).join("");
    if (n > 1) {
      bannerTimer = setInterval(function () {
        bannerIdx = (bannerIdx + 1) % n;
        var rtl = document.documentElement.dir === "rtl";
        track.scrollTo({ left: track.clientWidth * 0.9 * bannerIdx * (rtl ? -1 : 1), behavior: "smooth" });
      }, 3800);
      track.addEventListener("scroll", function () {
        var i = Math.round(Math.abs(track.scrollLeft) / (track.clientWidth * 0.9));
        $$("#cvBannerDots i").forEach(function (d, di) { d.classList.toggle("is-on", di === Math.min(i, n - 1)); });
      }, { passive: true });
      track.addEventListener("pointerdown", function () { clearInterval(bannerTimer); });
    }
  }

  /* ── product view ─────────────────────────────────────── */
  function openProduct(id) { setProduct(id, true); show("product"); itemEvent(id, "view"); }

  function setProduct(id, fresh) {
    var i = item(id); if (!i) return;
    var same = S.current && S.current.id === i.id;
    S.current = i;
    if (fresh || !same) { S.pQty = 1; S.pVariant = (i.variants || []).length ? i.variants[0].id : 0; S.pMods = {}; S.pExtras = {}; }

    var imgs = itemImgs(i);
    $("#pGallery").innerHTML = imgs.map(function (g) { return '<img src="' + esc(g) + '" alt=""' + IMG_ERR + ">"; }).join("");
    var multiImg = imgs.length > 1;
    /* dots are buttons so a pointer user can jump straight to an image */
    $("#pGalleryDots").innerHTML = multiImg ? imgs.map(function (_, gi) { return '<button class="' + (gi === 0 ? "is-on" : "") + '" data-gdot="' + gi + '" type="button" aria-label="' + (gi + 1) + '"></button>'; }).join("") : "";
    /* arrows for pointer/desktop users who can't swipe */
    if ($("#pGalPrev")) $("#pGalPrev").hidden = !multiImg;
    if ($("#pGalNext")) $("#pGalNext").hidden = !multiImg;
    $("#pGallery").scrollTo({ left: 0 });
    $("#pFav").classList.toggle("is-on", favs.has(String(id)));
    var stockEl = $("#pStock");
    stockEl.hidden = !i.is_sold_out;
    if (i.is_sold_out) stockEl.textContent = CFG.i18n.sold_out;

    var chips = [
      (i.rating ? '<span class="meta-chip star">' + ICONS.star + i.rating + "</span>" : ""),
      i.calories ? '<span class="meta-chip kcal">' + ICONS.fire + i.calories + " kcal</span>" : "",
      i.is_vegan ? '<span class="meta-chip">' + ICONS.leaf + "Vegan</span>" : "",
      i.is_gluten_free ? '<span class="meta-chip">GF</span>' : "",
      i.spice_level > 0 ? '<span class="meta-chip"><span class="spice">' + "🌶".repeat(Math.min(i.spice_level, 3)) + "</span></span>" : "",
    ].join("");

    var macros = (i.protein || i.carbs || i.fat)
      ? '<br><small style="color:var(--ink-3)">P ' + (i.protein || 0) + "g · C " + (i.carbs || 0) + "g · F " + (i.fat || 0) + "g</small>"
      : "";

    var groupsHTML = "";
    if ((i.variants || []).length) {
      groupsHTML += '<div class="opt-group" data-kind="variant"><div class="opt-head"><b>' + esc(i.variantTitle || CFG.i18n.choose) + '</b><span class="opt-req">' + esc(CFG.i18n.required) + '</span></div><div class="opt-list">' +
        i.variants.map(function (v) {
          return '<div class="opt' + (S.pVariant === v.id ? " is-on" : "") + '" data-variant="' + v.id + '"><span class="opt-ctl">' + ICONS.check + '</span><span class="opt-name">' + esc(v.label) + '</span><span class="opt-price">' + money(v.price) + "</span></div>";
        }).join("") + "</div></div>";
    }
    (i.optionGroups || []).forEach(function (g) {
      var multi = g.max === null || g.max > 1;
      groupsHTML += '<div class="opt-group" data-group="' + g.id + '" data-multi="' + (multi ? 1 : 0) + '" data-min="' + (g.min || 0) + '" data-max="' + (g.max === null ? "" : g.max) + '">' +
        '<div class="opt-head"><b>' + esc(g.title) + '</b><span class="opt-req' + (g.required ? "" : " optional") + '">' + esc(g.required ? CFG.i18n.required : CFG.i18n.optional) + "</span></div>" +
        '<div class="opt-list">' + g.options.map(function (o) {
          return '<div class="opt" data-opt="' + o.i + '" data-price="' + o.price + '" data-label="' + esc(o.label) + '"><span class="opt-ctl' + (multi ? " box" : "") + '">' + ICONS.check + '</span><span class="opt-name">' + esc(o.label) + '</span><span class="opt-price">' + (o.price ? "+" + money(o.price) : "") + "</span></div>";
        }).join("") + "</div></div>";
    });
    if ((i.extras || []).length) {
      groupsHTML += '<div class="opt-group" data-kind="extras"><div class="opt-head"><b>' + esc(CFG.i18n.extras) + '</b><span class="opt-req optional">' + esc(CFG.i18n.optional) + '</span></div><div class="opt-list">' +
        i.extras.map(function (e) {
          return '<div class="opt' + (e.img ? " opt--img" : "") + '" data-extra="' + e.id + '"><span class="opt-ctl box">' + ICONS.check + "</span>" +
            (e.img ? '<img class="opt-thumb" src="' + esc(e.img) + '" alt="" loading="lazy"' + IMG_ERR + ">" : "") +
            '<span class="opt-name">' + esc(e.title) + '</span><span class="opt-price">+' + money(e.price) + "</span></div>";
        }).join("") + "</div></div>";
    }

    var allergensHTML = (i.allergies || []).length
      ? '<div class="p-allergens">' + i.allergies.map(function (a) {
          return '<span class="allergen">' + (a.img ? '<img src="' + esc(a.img) + '" alt="">' : "⚠ ") + esc(a.title) + "</span>";
        }).join("") + "</div>"
      : "";

    $("#pBody").innerHTML =
      '<div class="p-title-row"><h1 class="p-title">' + esc(i.name) + '</h1>' +
      '<div class="p-price"><b id="pUnitPrice">' + money(unitPrice(i, S.pVariant, selectedMods(), selectedExtras())) + "</b></div></div>" +
      '<div class="p-meta">' + chips + "</div>" +
      (i.desc ? '<p class="p-desc">' + esc(i.desc) + macros + "</p>" : "") +
      allergensHTML +
      '<div class="p-groups">' + groupsHTML + "</div>" +
      (EP.reviewsBase ? '<div class="rate-row"><span>' + esc(CFG.i18n.rate_dish) + '</span><span class="rate-stars" id="cvRateStars">' +
        [1, 2, 3, 4, 5].map(function (n) { return '<button data-rate="' + n + '" aria-label="' + n + '">' + ICONS.star + "</button>"; }).join("") +
      '</span><span class="rate-thanks" id="cvRateThanks" hidden>' + esc(CFG.i18n.thanks) + "</span></div>" : "");

    var rel = ITEMS.filter(function (x) { return String(x.cat) === String(i.cat) && x.id !== i.id; }).slice(0, 6);
    $("#relatedRail").innerHTML = rel.map(function (r) {
      return '<button class="rail-card" data-open="' + r.id + '"><img src="' + esc(r.img) + '" alt=""' + IMG_ERR + "><b>" + esc(r.name) + '</b><span class="price">' + money(r.price) + "</span></button>";
    }).join("");
    var relBlock = $("#cvRelatedBlock"); if (relBlock) relBlock.hidden = rel.length === 0;

    updateProductCta();
  }

  function selectedMods() {
    var mods = [];
    for (var gid in S.pMods) {
      (S.pMods[gid] || []).forEach(function (m) { mods.push(m); });
    }
    return mods;
  }
  function selectedExtras() {
    var i = S.current, out = [];
    if (!i) return out;
    (i.extras || []).forEach(function (e) { if (S.pExtras[e.id]) out.push(e); });
    return out;
  }
  function updateProductCta() {
    var i = S.current; if (!i) return;
    $("#pQtyVal").textContent = String(S.pQty).padStart(2, "0");
    var unit = unitPrice(i, S.pVariant, selectedMods(), selectedExtras());
    /* the header price used to be written once from the base price and never
       re-rendered — it read $4.50 while the CTA charged $8.00/unit */
    var up = $("#pUnitPrice"); if (up) up.textContent = money(unit);
    $("#pTotal").textContent = money(unit * S.pQty);
    var btn = $("#addToCart"); if (btn) btn.disabled = !!i.is_sold_out;
  }

  /* ── cart ─────────────────────────────────────────────── */
  function addToCart(id, variantId, mods, extras, qty, fromEl) {
    var i = item(id);
    if (!i || i.is_sold_out) { toast(CFG.i18n.sold_out); return; }
    /* enforce required option groups (min selections) */
    var missing = (i.optionGroups || []).filter(function (g) {
      var need = g.required ? Math.max(1, g.min || 0) : (g.min || 0);
      var have = (mods || []).filter(function (m) { return String(m.groupId) === String(g.id); }).length;
      return have < need;
    });
    if (missing.length) {
      var m0 = missing[0];
      toast(CFG.i18n.choose + ": " + m0.title);
      /* Point the guest AT the group they still owe, not just a toast that fades:
         ring + scroll the first unmet required group into view. */
      var gEl = $('[data-group="' + m0.id + '"]');
      if (gEl) {
        $$(".opt-group.needs-pick").forEach(function (el) { el.classList.remove("needs-pick"); });
        gEl.classList.add("needs-pick");
        gEl.scrollIntoView({ behavior: "smooth", block: "center" });
        setTimeout(function () { gEl.classList.remove("needs-pick"); }, 2600);
      }
      return;
    }

    var key = id + "|" + (variantId || 0) + "|" +
      (mods || []).map(function (m) { return m.groupId + ":" + m.optIndex; }).sort().join(",") + "|" +
      (extras || []).map(function (e) { return e.id; }).sort().join(",");
    var line = cart.filter(function (l) { return l.key === key; })[0];
    if (line) line.qty += qty;
    else cart.push({ key: key, id: id, qty: qty, variantId: variantId || 0, mods: mods || [], extras: extras || [] });
    writeCart(); syncBadge(true);
    toast(i.name + " — " + CFG.i18n.added);
    if (fromEl) flyToCart(fromEl);
    itemEvent(id, "add_to_cart");
  }

  function flyToCart(fromEl) {
    var img = fromEl.closest(".card, .rail-card") ? fromEl.closest(".card, .rail-card").querySelector("img") : $("#pGallery img");
    var target = $('#cvCartBadge') ? $('#cvCartBadge').parentElement : null;
    if (!img || !target) return;
    var a = img.getBoundingClientRect(), b = target.getBoundingClientRect();
    var lay = $("#cvFlyLayer"), lr = lay.getBoundingClientRect();
    var ghost = document.createElement("img");
    ghost.src = img.src; ghost.className = "fly-img";
    ghost.style.left = a.left - lr.left + a.width / 2 - 32 + "px";
    ghost.style.top = a.top - lr.top + a.height / 2 - 32 + "px";
    lay.appendChild(ghost);
    requestAnimationFrame(function () {
      ghost.style.left = b.left - lr.left + b.width / 2 - 32 + "px";
      ghost.style.top = b.top - lr.top + b.height / 2 - 32 + "px";
      ghost.style.transform = "scale(.14)";
      ghost.style.opacity = ".3";
    });
    setTimeout(function () { ghost.remove(); }, 800);
  }

  function syncBadge(bump) {
    var n = cartCount() + comboCount();
    /* product-page "View cart" shortcut: appears beside Add to Cart once the bag
       has anything in it, so adding an item offers an immediate route to checkout */
    var vc = $("#pViewCart"), vcn = $("#pViewCartCount");
    if (vc) vc.hidden = n === 0;
    if (vcn) vcn.textContent = n;
    var db = $("#mcDockCartBadge");
    if (db) { db.hidden = n === 0; db.textContent = n; }
    var b = $("#cvCartBadge");
    if (!b) return;
    b.hidden = n === 0; b.textContent = n;
    if (bump && n) { b.classList.remove("bump"); void b.offsetWidth; b.classList.add("bump"); }
  }

  /* COMBOS-V2 — read-only "Combos" group appended to the cart list and the
     checkout summary. Tapping a row reopens the combo sheet's basket view,
     which is where quantities are edited. */
  function comboGroupHTML(compact) {
    var lines = comboLines();
    if (!lines.length) return "";
    var h = '<div class="mc-combo-group" data-combo-open style="border-top:1px dashed var(--line,#8883);padding-top:8px;margin-top:8px;cursor:pointer">';
    lines.forEach(function (L) {
      h += '<div class="ck-row ck-row--combo">' +
        '<div class="ck-mid"><b>' + esc(L.name) + "</b>" +
        (L.parts.length ? "<small>" + esc(L.parts.join(" · ")) + "</small>" : "") + "</div>" +
        '<span class="ck-qty">×' + L.qty + "</span>" +
        '<span class="ck-price">' + money(L.price) + "</span></div>";
    });
    if (!compact) h += '<small style="opacity:.65">' + esc(CFG.i18n.edit || "Edit") + " ›</small>";
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
    $("#cvCartEmpty").hidden = has;
    $("#cvCartTools").classList.toggle("is-off", !has);
    $("#cvCartCta").hidden = !has;
    $("#cvClearCart").style.visibility = cart.length ? "" : "hidden";
    $("#cvCartList").innerHTML = comboGroupHTML(false) + cart.map(function (l, li) {
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
        '<div><button class="ci-name" data-open="' + i.id + '" type="button">' + esc(i.name) + "</button>" +
        (label ? '<div class="ci-opts">' + esc(label) + "</div>" : "") +
        '<div class="ci-price">' + money(unit * l.qty) +
          (wasUnit ? ' <del class="ci-was">' + money(wasUnit * l.qty) + "</del>" : "") +
          (l.qty > 1 ? ' <small class="ci-unit">' + l.qty + " × " + money(unit) + "</small>" : "") +
        "</div></div>" +
        '<div class="ci-side">' +
          '<button class="ci-remove" data-rm aria-label="' + esc(CFG.i18n.remove || "Remove") + '">' + ICONS.trash + "</button>" +
          '<div class="qty">' +
          '<button class="qty-btn" data-cq="-1" aria-label="−">' + ICONS.minus + "</button>" +
          "<output>" + String(l.qty).padStart(2, "0") + "</output>" +
          '<button class="qty-btn" data-cq="1" aria-label="+">' + ICONS.plus + "</button></div></div></div>";
    }).join("");
    renderOtFields(); renderTotals();
  }

  function renderOtFields() {
    var waiterRow = $("#cvWaiterRow");
    if (waiterRow) waiterRow.hidden = S.orderType !== "on-table" || !CFG.allowCallWaiter;
    $$("#cvOrderTypeSeg button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.ot === S.orderType); });
    // checkout field visibility is handled by renderCheckout() via element IDs
    // (#cvTableField/#cvPhoneField/#cvAddrBlock); the cart carries one inline
    // field of its own: the delivery address, rebuilt on every order-type switch.
    var f = $("#cvOtFields");
    if (!f) return;
    if (S.orderType === "delivery") {
      var co = $("#cvCustAddr");
      var locIcon = ($("#cvUseLocation") || { innerHTML: "" }).innerHTML;
      f.innerHTML = '<div class="addr-field" id="cvCartAddrField">' +
        '<input id="cvCartAddr" value="' + esc((co && co.value) || "") + '" placeholder="' + esc(CFG.i18n.delivery_address || "Delivery address") + '" autocomplete="off" autocorrect="off" spellcheck="false">' +
        '<button class="uselocbtn" id="cvCartUseLocation" type="button" title="' + esc(CFG.i18n.use_my_location || "Use my location") + '" aria-label="' + esc(CFG.i18n.use_my_location || "Use my location") + '">' + locIcon + "</button>" +
        '<div class="addr-suggest" id="cvCartAddrSuggest" hidden></div></div>';
      wireAddressField($("#cvCartAddr"), $("#cvCartAddrSuggest"), $("#cvCartUseLocation"), null, function (v) {
        var c = $("#cvCustAddr"); if (c) c.value = v;
      });
    } else {
      f.innerHTML = "";
    }
  }

  function totalsHTML() {
    var T = totals();
    var h = '<div class="tr-row"><span>' + esc(CFG.i18n.subtotal) + "</span><b>" + money(T.sub) + "</b></div>";
    if (T.disc) h += '<div class="tr-row disc"><span>' + esc(CFG.i18n.discount) + " (" + esc(S.coupon.code) + ")</span><b>−" + money(T.disc) + "</b></div>";
    if (T.tax) h += '<div class="tr-row"><span>' + esc(CFG.taxLabel) + "</span><b>" + money(T.tax) + "</b></div>";
    if (T.del) h += '<div class="tr-row"><span>' + esc(CFG.i18n.delivery) + "</span><b>" + money(T.del) + "</b></div>";
    if (T.tip) h += '<div class="tr-row"><span>' + esc(CFG.i18n.tip) + "</span><b>" + money(T.tip) + "</b></div>";
    h += '<div class="tr-row grand"><span>' + esc(CFG.i18n.total) + "</span><b>" + money(T.total) + "</b></div>";
    return h;
  }

  function renderTotals() {
    var T = totals();
    $("#cvTotalsBox").innerHTML = totalsHTML();
    $("#cvCartCtaTotal").textContent = money(T.total);
    var below = T.sub > 0 && CFG.minOrderValue > 0 && T.sub < CFG.minOrderValue;
    var msg = $("#cvMinOrderMsg");
    msg.hidden = !below;
    if (below) msg.textContent = "⚠ " + CFG.i18n.min_order + " " + money(CFG.minOrderValue);
    $("#cvGoCheckout").disabled = below;
  }

  /* ── checkout ─────────────────────────────────────────── */
  function renderCheckout() {
    var addr = $("#cvAddrBlock");
    if (addr) addr.classList.toggle("is-off", S.orderType !== "delivery");
    var tblField = $("#cvTableField");
    if (tblField) tblField.classList.toggle("is-off", S.orderType !== "on-table");
    var phoneField = $("#cvPhoneField");
    /* Room service is fulfilled in the guest's room, so like on-table it needs
       neither a phone number nor an address -- the room rides along on the
       hidden `room` field the hotel partial renders. */
    if (phoneField) phoneField.classList.toggle("is-off", S.orderType === "on-table" || S.orderType === "room_service");
    /* Order summary — what the customer is about to pay for, tap Edit → cart. */
    var ck = $("#cvCkItems");
    if (ck) {
      ck.hidden = cart.length === 0 && comboCount() === 0;
      ck.innerHTML = '<div class="ck-head"><h3>' + esc(CFG.i18n.your_order || "Your order") + "</h3>" +
        '<button class="linkbtn ck-edit" id="cvCkEdit" type="button">' + esc(CFG.i18n.edit || "Edit") + "</button></div>" +
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
      var ckEd = $("#cvCkEdit");
      if (ckEd) ckEd.onclick = function () { show("cart"); };
    }
    $("#cvCheckoutTotals").innerHTML = totalsHTML();
    $("#cvCheckoutCtaTotal").textContent = money(totals().total);
  }

  /* One pass over every field, so the customer is not marched through
     "add your name" → submit → "add an address" → submit one error at a time. */
  function checkoutProblems() {
    var out = [];
    if (!S.orderType) out.push(CFG.i18n.select_type);
    var T = totals();
    if (CFG.minOrderValue > 0 && T.sub < CFG.minOrderValue) out.push(CFG.i18n.min_order + " " + money(CFG.minOrderValue));
    var nameEl = $("#cvCustName");
    if (!(nameEl ? nameEl.value.trim() : (CFG.customerName || ""))) out.push(CFG.i18n.fill_contact);
    var addrEl = $("#cvCustAddr");
    if (S.orderType === "delivery" && !(addrEl && addrEl.value.trim())) out.push(CFG.i18n.fill_addr);
    return out;
  }
  function showProblems(list) {
    var err = $("#cvFormError");
    if (!list.length) { err.hidden = true; err.innerHTML = ""; return false; }
    err.innerHTML = list.map(function (m) { return esc(m); }).join("<br>");
    err.hidden = false;
    err.scrollIntoView({ behavior: "smooth", block: "nearest" });
    return true;
  }
  /* live re-validation: a corrected field clears its line instead of the box
     sitting there stale until the next submit */
  ["cvCustName", "cvCustPhone", "cvCustAddr", "cvTableInput"].forEach(function (id) {
    on(id, "input", function () {
      var box = $("#cvFormError");
      if (box && !box.hidden) showProblems(checkoutProblems());
    });
  });

  function placeOrder() {
    var err = $("#cvFormError");
    if (!cart.length && !comboCount()) return; /* combos-only orders are legal */
    var T = totals();
    if (showProblems(checkoutProblems())) return;
    var nameEl = $("#cvCustName");
    var name = nameEl ? nameEl.value.trim() : (CFG.customerName || "");
    if (CFG.authMode === "required" && !CFG.isLoggedIn) { openAuth(); return; }

    var payload = orderLines();

    var fd = new FormData();
    fd.append("_token", CFG.csrf);
    fd.append("items", JSON.stringify(payload));
    fd.append("name", name);
    fd.append("ordering-type", S.orderType);
    if (S.orderType === "on-table" || S.orderType === "room_service") fd.append("table", ($("#cvTableInput") || {}).value || "");
    else fd.append("phone-number", ($("#cvCustPhone") || {}).value || "");
    if (S.orderType === "delivery") fd.append("address", $("#cvCustAddr").value.trim());
    fd.append("pay_via", (root.querySelector(".pm.is-on") || {}).dataset ? root.querySelector(".pm.is-on").dataset.pm : "pay_on_counter");
    fd.append("tip_amount", String(T.tip || 0));
    fd.append("message", ($("#cvOrderNote") || {}).value || "");
    var sched = $("#cvScheduledAt");
    if (sched && sched.value) fd.append("scheduled_at", sched.value);
    if (S.coupon) fd.append("coupon_code", S.coupon.code);
    var uw = $("#mbUseWallet"); if (uw && uw.checked) fd.append("use_wallet", "1");
    var bid = new URLSearchParams(window.location.search).get("branch_id");
    if (bid) fd.append("branch_id", bid);
    var capEmail = $("#cvCaptureEmail"), consent = $("#cvConsentCb");
    if (consent && consent.checked) {
      fd.append("marketing_consent", "1");
      if (capEmail && capEmail.value) fd.append("capture_email", capEmail.value);
    }
    var waOpt = $("#cvWaOptin");
    if (waOpt && waOpt.checked) fd.append("whatsapp_optin", "1");

    var btn = $("#cvPlaceOrder");
    btn.disabled = true;
    btn.querySelector(".spinner").hidden = false;

    fetch(CFG.endpoints.submitOrder, {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: fd,
    }).then(function (r) { return r.json(); }).then(function (res) {
      btn.disabled = false;
      btn.querySelector(".spinner").hidden = true;
      if (res && res.success) {
        if (S.orderType === "delivery") rememberAddress(($("#cvCustAddr") || {}).value, ($("#cvCustLat") || {}).value, ($("#cvCustLng") || {}).value);
        cart = []; writeCart(); syncBadge(false);
        S.coupon = null;
        if (res.message) { window.location.href = res.message; return; } /* online payment redirect */
        $("#cvConfirmTrack").href = res.track_url || "#";
        var ono = $("#cvConfirmOrderNo");
        if (ono && res.order_id) { ono.hidden = false; ono.textContent = (CFG.i18n.order_label || "Order") + " #" + res.order_id; }
        rememberOrder(res);   /* so the profile Orders section lists it, no lookup needed */
        show("confirmed");
        stack.length = 0;
        burstConfetti();
        if (res.whatsapp_url) mbWhatsAppHandoff(res.whatsapp_url, res.whatsapp_handoff);
        if (res.track_url) setTimeout(function () { window.location.href = res.track_url; }, 3200);
      } else if (res && res.otp_required) {
        /* F7 · phone-verification gate — open the OTP sheet, then re-submit */
        if (typeof openCheckoutOtp === "function") openCheckoutOtp(res.phone || ($("#cvCustPhone") || {}).value || "");
      } else {
        /* textContent, not innerHTML: res.message is a server string that can carry
           back user-supplied values (coupon code, address, name), so injecting it as
           HTML is an XSS sink. Nothing here needs markup. */
        err.textContent = (res && res.message) || "Something went wrong.";
        err.hidden = false;
      }
    }).catch(function () {
      btn.disabled = false;
      btn.querySelector(".spinner").hidden = true;
      err.textContent = "Network error. Please try again.";
      err.hidden = false;
    });
  }

  function burstConfetti() {
    var colors = ["#3fd463", "#8bf0a2", "#ffb02e", "#ffffff", "#ff6259"];
    $("#cvConfetti").innerHTML = new Array(30).fill(0).map(function (_, i) {
      var tx = (Math.random() * 2 - 1) * 150, ty = -40 - Math.random() * 200;
      return '<i class="' + (Math.random() > 0.5 ? "round" : "") + '" style="--tx:' + tx + "px;--ty:" + ty + "px;--c:" + colors[i % colors.length] + ";--d:" + (Math.random() * 0.35) + "s;--rz:" + (Math.random() * 360) + "deg;--spin:" + (180 + Math.random() * 400) + 'deg"></i>';
    }).join("");
  }

  /* ── favorites ────────────────────────────────────────── */
  function renderFavs() {
    var list = ITEMS.filter(function (i) { return favs.has(String(i.id)); });
    $("#cvFavGrid").innerHTML = list.map(cardHTML).join("");
    $("#cvFavEmpty").hidden = list.length > 0;
  }
  function writeFavs() { localStorage.setItem(favKey, JSON.stringify(Array.from(favs))); }

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
      writeFavs();
      fav.classList.toggle("is-on");
      if (active === "favs") renderFavs();
      return;
    }
    var add = closest("[data-add]");
    if (add) {
      e.stopPropagation();
      e.preventDefault();
      var ai = item(add.dataset.add);
      if (!ai) return;
      if ((ai.variants || []).length || (ai.optionGroups || []).length || (ai.extras || []).length) { openProduct(ai.id); return; }
      addToCart(ai.id, 0, [], [], 1, add);
      return;
    }
    /* R7 — same-page ?item= links: grid card anchors + promo/banner/CTA links.
       Checked AFTER the controls above so a click on a card's ＋/heart (inside the
       card anchor, each preventDefaults it) wins. Same-pathname item links open in
       place; real external links full-load. Boot handles a cold ?item= / new-tab load. */
    var itemLink = closest('a[href*="item="]');
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

    /* ── floating dock ── home/cart/favs/profile route through show(); menu and
       search are NOT views — they land on home and steer the grid / search input. */
    var dk = closest("[data-dock]");
    if (dk) {
      var dd = dk.dataset.dock;
      /* NB: instant scroll only. matcha's SPA slides views with a translateX
         transform on .view, and Chrome cancels smooth-scroll animations inside a
         transformed scroller — so scrollIntoView/scrollTo with behavior:'smooth'
         silently no-op here (show() sidesteps this with a plain scrollTop=0). */
      if (dd === "menu") {
        if (active !== "home") show("home");
        /* land on the category selector (the grid-lead), not the grid itself, so the
           categories stay in view instead of being scrolled past. */
        var g = document.querySelector('[data-mb-grid-lead]') || $("#cvGrid");
        if (g) (g.closest(".grid-block") || g).scrollIntoView({ block: "start" });
        return;
      }
      if (dd === "search") {
        if (active !== "home") show("home");
        var si = $("#cvSearchInput");
        if (si) {
          (si.closest(".searchrow") || si).scrollIntoView({ block: "center" });
          /* mirror the layout's csp-focus unlock so the field accepts typing
             (readonly is the anti-autofill guard; focus is what lifts it). */
          si.removeAttribute("readonly");
          try { si.focus({ preventScroll: true }); } catch (e) { try { si.focus(); } catch (e2) {} }
        }
        return;
      }
      if (dd === "home") { show("home"); var hs = $('.view[data-view="home"] .view-scroll'); if (hs) hs.scrollTop = 0; return; }
      show(dd); /* cart · favs · profile */
      return;
    }

    var open = closest("[data-open]");
    if (open) { openProduct(open.dataset.open); return; }
    var go = closest("[data-go]");
    if (go) { show(go.dataset.go); return; }
    if (closest("[data-back]")) { back(); return; }
    var chip = closest("[data-cat]");
    if (chip) {
      S.cat = chip.dataset.cat;
      S.sub = "root";
      $$("#cvChips [data-cat]").forEach(function (c) { c.classList.toggle("is-on", c === chip); });
      syncSubChips();
      renderGrid(); return;
    }

    /* secondary sub-category chip narrows within the active parent */
    var subchip = closest("[data-sub]");
    if (subchip) {
      S.sub = subchip.dataset.sub;
      var grp = subchip.closest(".subgroup");
      if (grp) $$(".subchip", grp).forEach(function (c) { c.classList.toggle("is-on", c === subchip); });
      renderGrid(); return;
    }

    /* featured blob */
    var ft = closest("[data-feat]");
    if (ft) { featIdx = Number(ft.dataset.feat); featQty = 1; renderFeat(); return; }
    var fq = closest("#cvFeatQty [data-fq]");
    if (fq) {
      featQty = Math.max(1, featQty + Number(fq.dataset.fq));
      $("#cvFeatQtyVal").textContent = featQty;
      syncFeatPrice();
      return;
    }

    /* product options */
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
        if (idx !== -1) cur.splice(idx, 1);
        else if (cur.length < max) cur.push(mod);
        else { toast("Max " + max); return; }
      } else {
        cur = cur.length && cur[0].optIndex === mod.optIndex ? [] : [mod];
      }
      S.pMods[gid] = cur;
      $$(".opt", grp).forEach(function (o) {
        o.classList.toggle("is-on", cur.some(function (m) { return m.optIndex === Number(o.dataset.opt); }));
      });
      updateProductCta(); return;
    }
    var xOpt = closest("[data-extra]");
    if (xOpt && S.current) {
      var xid = xOpt.dataset.extra;
      S.pExtras[xid] = !S.pExtras[xid];
      xOpt.classList.toggle("is-on", S.pExtras[xid]);
      updateProductCta(); return;
    }
    var q = closest("#pQty [data-q]");
    if (q) { S.pQty = Math.max(1, S.pQty + Number(q.dataset.q)); updateProductCta(); return; }

    /* rate stars */
    var rate = closest("#cvRateStars [data-rate]");
    if (rate && S.current) {
      var n = Number(rate.dataset.rate);
      $$("#cvRateStars button").forEach(function (b, bi) { b.classList.toggle("is-on", bi < n); });
      fetch(CFG.endpoints.review, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf },
        body: JSON.stringify({ menu_id: S.current.id, stars: n }),
      }).then(function (r) { return r.json(); }).then(function (d) {
        var t = $("#cvRateThanks");
        if (t) { if (d && d.message) t.textContent = d.message; t.hidden = false; }
      }).catch(function () {});
      return;
    }

    /* cart line qty / remove */
    var cq = closest("[data-cq]");
    if (cq) {
      var key1 = cq.closest(".cart-item").dataset.key;
      var line = cart.filter(function (l) { return l.key === key1; })[0];
      if (!line) return;
      line.qty += Number(cq.dataset.cq);
      if (line.qty <= 0) cart = cart.filter(function (l) { return l !== line; });
      writeCart(); syncBadge(false); renderCart(); return;
    }
    var rm = closest("[data-rm]");
    if (rm) {
      var row = rm.closest(".cart-item");
      row.classList.add("removing");
      var key2 = row.dataset.key;
      setTimeout(function () {
        cart = cart.filter(function (l) { return l.key !== key2; });
        writeCart(); syncBadge(false); renderCart(); toast(CFG.i18n.removed);
      }, 300);
      return;
    }

    /* order type */
    var ot = closest("#cvOrderTypeSeg [data-ot]");
    if (ot) { S.orderType = ot.dataset.ot; renderOtFields(); renderCheckout(); renderTotals(); return; }

    /* payment method */
    var pm = closest("[data-pm]");
    if (pm) {
      $$(".pm").forEach(function (b) { b.classList.toggle("is-on", b === pm); });
      return;
    }
    /* tip */
    var tip = closest("#cvTipRow [data-tip]");
    if (tip) {
      $$("#cvTipRow button").forEach(function (b) { b.classList.toggle("is-on", b === tip); });
      var custom = $("#cvCustomTip");
      var customLbl = $("#cvCustomTipLabel");
      if (tip.dataset.tip === "custom") {
        S.tipPct = 0; S.tipCustom = Number(custom.value) || 0; custom.hidden = false;
        if (customLbl) customLbl.hidden = false;
      } else {
        S.tipPct = Number(tip.dataset.tip); S.tipCustom = null; custom.hidden = true;
        if (customLbl) customLbl.hidden = true;
      }
      renderCheckout(); renderTotals(); return;
    }

    /* filter sheet */
    var diet = closest("#cvDietRow [data-diet]");
    if (diet) {
      S.filters.diets[diet.dataset.diet] = !S.filters.diets[diet.dataset.diet];
      diet.classList.toggle("is-on"); renderGrid(); return;
    }
    var al = closest("#cvAllergenRow [data-al]");
    if (al) {
      S.filters.allergens[al.dataset.al] = !S.filters.allergens[al.dataset.al];
      al.classList.toggle("is-on"); renderGrid(); return;
    }
    var sp = closest("#cvSpiceScale [data-spice]");
    if (sp) {
      S.filters.spice = Number(sp.dataset.spice);
      $$("#cvSpiceScale button").forEach(function (b) { b.classList.toggle("is-on", b === sp); });
      renderGrid(); return;
    }
    var so = closest("#cvSortRow [data-sort]");
    if (so) {
      S.sort = so.dataset.sort;
      $$("#cvSortRow button").forEach(function (b) { b.classList.toggle("is-on", b === so); });
      renderGrid(); return;
    }

    /* lang */
    var lg = closest("[data-lang-code]");
    if (lg) {
      setCookie("Quick_lang", lg.dataset.langName);
      setCookie("Quick_user_lang", lg.dataset.langName);
      setCookie("Quick_user_lang_code", lg.dataset.langCode);
      window.location.reload(); return;
    }
    /* currency */
    var cu = closest("[data-cur]");
    if (cu) {
      S.curCode = cu.dataset.cur;
      S.curRate = parseFloat(cu.dataset.rate) || 1;
      if (window.mbCurrency) mbCurrency.save(S.curCode, S.curRate);   // persist store-scoped (shared helper)
      $$("[data-cur]").forEach(function (b) { b.classList.toggle("is-on", b === cu); });
      rerenderMoney(); return;
    }
  });

  function rerenderMoney() {
    renderGrid(); renderSpecials(); renderFeat(); renderCart();
    if (S.current) setProduct(S.current.id, false);
    renderCheckout();
  }

  function setCookie(k, v) { document.cookie = k + "=" + encodeURIComponent(v) + ";path=/;max-age=31536000"; }

  /* ── single-purpose bindings ──────────────────────────── */
  function on(id, ev, fn) { var el = document.getElementById(id); if (el) el.addEventListener(ev, fn); }

  /* F1: the CTA was decorative and the layer dropped pointer-events the instant
     the 2.3s timer fired, so a tap aimed at "Get started" landed on a category
     chip behind it and silently filtered the menu. Now any tap on the splash
     dismisses it, and the layer keeps swallowing taps through the fade. */
  function dismissSplash() {
    var sp = $("#cvSplash");
    if (sp) sp.classList.add("is-done");
  }
  on("cvSplashSkip", "click", dismissSplash);
  on("cvSplash", "click", dismissSplash);
  on("cvFeatAdd", "click", function () {
    var cur = featItems[featIdx];
    if (!cur) return;
    var mods = [];
    (cur.optionGroups || []).forEach(function (g) {
      if (g.required && g.options.length) {
        var o = g.options[0];
        mods.push({ groupId: g.id, optIndex: o.i, label: o.label, price: Number(o.price) || 0 });
      }
    });
    var variantId = (cur.variants || []).length ? cur.variants[0].id : 0;
    addToCart(cur.id, variantId, mods, [], featQty, null);
    var img = $("#cvFeatImg"), target = $("#cvCartBadge") ? $("#cvCartBadge").parentElement : null;
    if (img && target) {
      var a = img.getBoundingClientRect(), b = target.getBoundingClientRect();
      var lay = $("#cvFlyLayer"), lr = lay.getBoundingClientRect();
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
      setTimeout(function () { ghost.remove(); }, 800);
    }
    featQty = 1; $("#cvFeatQtyVal").textContent = "1"; syncFeatPrice();
  });
  function scrollToGrid() { var g = root.querySelector(".grid-block"); if (g) g.scrollIntoView({ behavior: "smooth", block: "start" }); }
  on("cvViewAllNew", "click", scrollToGrid);
  on("cvViewAllSpecial", "click", scrollToGrid);
  on("cvBranchBtn", "click", function () { sheet("cvBranchSheet"); });
  on("cvFilterBtn", "click", function () { sheet("cvFilterSheet"); });
  on("cvLangBtn", "click", function () { sheet("cvLangSheet"); });
  on("cvConsentCb", "change", function (e) {
    var w = $("#cvCaptureEmailWrap"); if (w) w.hidden = !e.target.checked;
  });
  on("cvCurbside", "change", function (e) {
    var w = $("#cvCurbsideFields"); if (w) w.hidden = !e.target.checked;
  });
  on("cvFilterDone", "click", function () { sheet("cvFilterSheet", false); renderGrid(); });
  on("cvFilterReset", "click", function () {
    S.filters = { diets: {}, allergens: {}, spice: 3 };
    $$("#cvDietRow button,#cvAllergenRow button").forEach(function (b) { b.classList.remove("is-on"); });
    $$("#cvSpiceScale button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.spice === "3"); });
    S.sort = "featured";
    $$("#cvSortRow button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.sort === "featured"); });
    renderGrid();
  });
  on("cvClearFilters", "click", function () {
    var r = $("#cvFilterReset"); if (r) r.click();
    S.search = ""; var si = $("#cvSearchInput"); if (si) si.value = "";
    renderGrid();
  });
  on("cvSearchInput", "input", function (e) { S.search = e.target.value; renderGrid(); });
  on("cvGridBtn", "click", function () { S.gridList = !S.gridList; $("#cvGrid").classList.toggle("is-list", S.gridList); });
  /* F9: "Clear" used to empty the cart irreversibly on a single mis-tap. */
  var clearArmed = null;
  on("cvClearCart", "click", function () {
    var btn = $("#cvClearCart");
    if (!btn.classList.contains("is-armed")) {
      btn.dataset.label = btn.dataset.label || btn.textContent;
      btn.textContent = CFG.i18n.confirm_clear;
      btn.classList.add("is-armed");
      clearTimeout(clearArmed);
      clearArmed = setTimeout(function () {
        btn.classList.remove("is-armed");
        btn.textContent = btn.dataset.label;
      }, 4000);
      return;
    }
    clearTimeout(clearArmed);
    btn.classList.remove("is-armed");
    btn.textContent = btn.dataset.label;
    cart = []; writeCart(); syncBadge(false); renderCart();
  });
  on("cvGoCheckout", "click", function () { show("checkout"); });
  on("cvPlaceOrder", "click", placeOrder);
  on("cvCustomTip", "input", function (e) { S.tipCustom = Number(e.target.value) || 0; renderCheckout(); renderTotals(); });
  on("addToCart", "click", function (e) {
    if (!S.current) return;
    addToCart(S.current.id, S.pVariant, selectedMods(), selectedExtras(), S.pQty, null);
    flyToCart(e.currentTarget);
  });
  on("pFav", "click", function () {
    if (!S.current) return;
    var fid = String(S.current.id);
    favs.has(fid) ? favs.delete(fid) : favs.add(fid);
    writeFavs();
    $("#pFav").classList.toggle("is-on");
  });

  /* coupon */
  on("cvCouponApply", "click", function () {
    var input = $("#cvCouponInput"), msg = $("#cvCouponMsg");
    var code = input.value.trim();
    if (!code) return;
    msg.hidden = true;
    fetch(CFG.endpoints.coupon, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf },
      body: couponBody(code),
    }).then(function (r) { return r.json(); }).then(function (res) {
      msg.hidden = false;
      if (res && res.success) {
        S.coupon = { code: res.label, coupon_type: res.coupon_type, percentage: res.percentage, discount: Number(res.discount) || 0, sig: couponSig() };
        msg.className = "coupon-msg";
        msg.textContent = "✓ " + (res.message || code);
        $(".coupon-field").classList.add("is-applied");
      } else {
        S.coupon = null;
        msg.className = "coupon-msg err";
        msg.textContent = "✕ " + ((res && res.message) || CFG.i18n.coupon_bad);
        $(".coupon-field").classList.remove("is-applied");
      }
      renderTotals(); renderCheckout();
    }).catch(function () {
      msg.hidden = false;
      msg.className = "coupon-msg err";
      msg.textContent = "✕ " + CFG.i18n.coupon_bad;
    });
  });

  /* waiter / bill */
  function waiterCall(type) {
    var tbl = ($("#cvTableInput") || {}).value || CFG.defaultTable || "";
    fetch(CFG.endpoints.waiter, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf },
      body: JSON.stringify({ table: tbl, type: type, _token: CFG.csrf }),
    }).then(function (r) { return r.json(); }).then(function () {
      toast(type === "request_bill" ? CFG.i18n.bill_ok : CFG.i18n.waiter_ok);
    }).catch(function () {});
  }
  on("cvCallWaiter", "click", function () { waiterCall("call_waiter"); });
  on("cvRequestBill", "click", function () { waiterCall("request_bill"); });

  /* NPS on confirmed view */
  $$(".nps-row button", $("#cvNpsRow") ? $("#cvNpsRow").parentElement : root).forEach(function (b) {
    b.addEventListener("click", function () {
      $$("#cvNpsRow button").forEach(function (x) { x.classList.remove("is-on"); });
      b.classList.add("is-on");
      fetch(CFG.endpoints.nps, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf },
        body: JSON.stringify({ score: Number(b.dataset.nps) }),
      }).catch(function () {});
      $("#cvNpsThanks").hidden = false;
    });
  });

  /* ── auth: login / register / OTP ─────────────────────── */
  function openAuth() { sheet("cvAuthSheet"); }
  on("cvLoginLink", "click", openAuth);
  on("cvMeAuthBtn", "click", function () { if (!CFG.isLoggedIn) openAuth(); });
  $$("#cvAuthTabs button").forEach(function (b) {
    b.addEventListener("click", function () {
      $$("#cvAuthTabs button").forEach(function (x) { x.classList.toggle("is-on", x === b); });
      $$(".auth-form[data-auth]").forEach(function (f) { f.hidden = f.dataset.auth !== b.dataset.authTab; });
    });
  });
  function authPost(url, body, errEl) {
    return fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf, "X-Requested-With": "XMLHttpRequest" },
      body: JSON.stringify(body),
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (res && res.success) { window.location.reload(); return res; }
      if (errEl) { errEl.textContent = (res && res.message) || "Failed."; errEl.hidden = false; }
      return res;
    });
  }
  on("cvLoginBtn", "click", function () {
    authPost(CFG.endpoints.login, {
      store_id: CFG.storeId,
      email: $("#cvLoginEmail").value.trim(),
      password: $("#cvLoginPass").value,
    }, $("#cvAuthErr"));
  });
  on("cvRegisterBtn", "click", function () {
    authPost(CFG.endpoints.register, {
      store_id: CFG.storeId,
      name: $("#cvRegName").value.trim(),
      email: $("#cvRegEmail").value.trim(),
      phone: $("#cvRegPhone").value.trim(),
      password: $("#cvRegPass").value,
      password_confirmation: $("#cvRegPassConfirm").value,
    }, $("#cvAuthErr"));
  });
  on("cvOtpSend", "click", function () {
    var phone = $("#cvOtpPhone").value.trim();
    if (!phone) return;
    fetch(CFG.endpoints.otpRequest, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf },
      body: JSON.stringify({ store_id: CFG.storeId, phone: phone }),
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (res && res.success) { $("#cvOtpStep1").hidden = true; $("#cvOtpStep2").hidden = false; }
      else { var e2 = $("#cvAuthErr"); e2.textContent = (res && res.message) || "Failed."; e2.hidden = false; }
    }).catch(function () {});
  });
  on("cvOtpVerify", "click", function () {
    var code = $$("#cvOtpBoxes input").map(function (i) { return i.value; }).join("");
    authPost(CFG.endpoints.otpVerify, { store_id: CFG.storeId, phone: $("#cvOtpPhone").value.trim(), code: code }, $("#cvAuthErr"));
  });
  $$("#cvOtpBoxes input").forEach(function (inp, i, arr) {
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
  on("cvLogoutBtn", "click", function () {
    fetch(CFG.endpoints.logout, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf },
    }).then(function () { window.location.reload(); });
  });

  /* Remember guest orders in this browser so the profile Orders section LISTS what
     was placed here — the server only returns them to a phone/email lookup, so a
     guest who just ordered otherwise saw nothing until they typed their details. */
  var CV_RECENT_KEY = "cv_recent_orders_" + slug;
  function readRecentOrders() {
    try { return (JSON.parse(localStorage.getItem(CV_RECENT_KEY) || "[]") || []).filter(function (o) { return o && o.track_url; }); }
    catch (e) { return []; }
  }
  function rememberOrder(res) {
    if (!res || !res.track_url) return;
    var list = readRecentOrders().filter(function (o) { return o.track_url !== res.track_url; });
    list.unshift({ id: res.order_id || "", track_url: res.track_url, total: res.total || "", placed_at: Date.now() });
    try { localStorage.setItem(CV_RECENT_KEY, JSON.stringify(list.slice(0, 10))); } catch (e) {}
    renderRecentOrders();
  }
  function renderRecentOrders() {
    var box = $("#cvLookupResults"); if (!box) return;   /* guest profile only */
    var list = readRecentOrders(); if (!list.length) return;
    box.innerHTML = list.map(function (o) {
      var when = new Date(o.placed_at || Date.now()).toLocaleDateString(document.documentElement.lang || "en", { day: "2-digit", month: "short" });
      var head = o.id ? "#" + esc(o.id) + (o.total ? " · " + esc(o.total) : "") : esc(when);
      return '<a class="order-row" href="' + esc(o.track_url) + '"><div><b>' + head + "</b><small>" + esc(when) + '</small></div><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg></a>';
    }).join("");
  }

  /* guest order lookup (#22) */
  on("cvLookupBtn", "click", function () {
    var val = $("#cvLookupInput").value.trim();
    if (!val) return;
    var box = $("#cvLookupResults");
    box.innerHTML = "…";
    fetch(CFG.endpoints.orderLookup, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf },
      body: JSON.stringify({ identifier: val }),
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (!data.success) { box.innerHTML = '<p class="orders-empty">' + esc(data.message || "") + "</p>"; return; }
      box.innerHTML = data.orders.map(function (o) {
        /* the row rendered as a bare <a> with no affordance, and an order with no
           track token produced href="" — a link that silently reloaded the page */
        var inner = '<div><b>#' + esc(o.id) + " · " + esc(o.total) + "</b><small>" + esc(o.date) + '</small></div>' +
          '<span class="order-status">' + esc(o.status) + "</span>";
        if (!o.track_url) return '<div class="order-row">' + inner + "</div>";
        return '<a class="order-row" href="' + esc(o.track_url) + '">' + inner +
          '<span class="order-track">' + esc(CFG.i18n.track) + '</span>' +
          '<svg class="ic ic-xs arr" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg></a>';
      }).join("");
    }).catch(function () { box.innerHTML = ""; });
  });

  /* ── reservation / waitlist sheets ───────────────────── */
  on("cvResOpen", "click", function () { sheet("cvResSheet"); });
  on("cvWaitlistOpen", "click", function () { sheet("cvWaitlistSheet"); });
  if (CFG.openResSheet && $("#cvResSheet")) { show("profile"); sheet("cvResSheet"); }
  if (CFG.openWaitlistSheet && $("#cvWaitlistSheet")) { show("profile"); sheet("cvWaitlistSheet"); }

  /* ── loyalty stamp / points card ──────────────────────── */
  (function () {
    var block = $("#cvLoyaltyBlock");
    if (!block || !CFG.endpoints.loyaltyBalance) return;
    var type = CFG.loyaltyType;

    function paint(d) {
      var bal = d.balance || 0;
      var balEl = $("#cvLoyaltyBalance");
      if (balEl) balEl.textContent = bal;
      if (type === "stamps") {
        $$("#cvStampDots .stamp-dot").forEach(function (dot, i) {
          dot.classList.toggle("is-on", i < bal);
        });
      } else {
        var bar = $("#cvLoyaltyBar");
        if (bar) bar.style.width = Math.min(100, d.progress_pct || 0) + "%";
      }
      var rb = $("#cvLoyaltyRedeem");
      if (rb) rb.hidden = !d.ready_to_redeem;
    }

    fetch(CFG.endpoints.loyaltyBalance)
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d && d.active) paint(d); })
      .catch(function () {});

    on("cvLoyaltyRedeem", "click", function () {
      var rb = $("#cvLoyaltyRedeem");
      rb.disabled = true;
      fetch(CFG.endpoints.loyaltyRedeem, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf },
        body: "{}",
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.success) {
          rb.hidden = true;
          $("#cvLoyaltyOk").hidden = false;
          paint({ balance: d.new_balance, progress_pct: 0, ready_to_redeem: false });
        } else {
          rb.disabled = false;
          toast((d && d.error) || CFG.i18n.coupon_bad);
        }
      }).catch(function () { rb.disabled = false; });
    });
  })();

  /* ── gallery dots / arrows / tabbar autohide ───────────── */
  var gal = $("#pGallery");
  function galIndex() { return gal ? Math.round(Math.abs(gal.scrollLeft) / gal.clientWidth) : 0; }
  function galGo(i) {
    if (!gal) return;
    var n = gal.querySelectorAll("img").length;
    i = Math.max(0, Math.min(n - 1, i));
    var dir = getComputedStyle(gal).direction === "rtl" ? -1 : 1;
    gal.scrollTo({ left: dir * i * gal.clientWidth, behavior: "smooth" });
  }
  if (gal) gal.addEventListener("scroll", function () {
    var i = galIndex();
    $$("#pGalleryDots button, #pGalleryDots i").forEach(function (d, di) { d.classList.toggle("is-on", di === i); });
  }, { passive: true });
  on("pGalPrev", "click", function () { galGo(galIndex() - 1); });
  on("pGalNext", "click", function () { galGo(galIndex() + 1); });
  var dotsWrap = $("#pGalleryDots");
  if (dotsWrap) dotsWrap.addEventListener("click", function (e) {
    var b = e.target.closest("[data-gdot]"); if (b) galGo(+b.dataset.gdot);
  });


  /* reorder handoff from account page */
  /* Shared engine (_shared/js/reorder-pickup.js, loaded by store_templates.layout):
     resolves the server's choice-group selections + note onto this theme's
     cart-line shape so a reordered item keeps its modifiers and note. */
  window.__matchaPickup = function (line) {
    var i = item(line.item_id);
    if (!i || i.is_sold_out) return false;
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
    var r = window.JuwriReorder.pickup(slug, window.__matchaPickup, { toast: toast });
    if (r.added) { writeCart(); syncBadge(true); if (!r.unavailable.length) toast(CFG.i18n.added); }
  }

  /* ══════════════════════════════════════════════════════════
     theme-pack-blade features (F1–F7)
     ══════════════════════════════════════════════════════════ */
  var EP = CFG.endpoints || {};
  function tokenHeaders(json) {
    var h = {};
    if (json) h["Content-Type"] = "application/json";
    h["Accept"] = "application/json";
    h["X-Requested-With"] = "XMLHttpRequest";
    /* feature routes inherit the `web` group → CSRF is enforced, so send the
       token alongside the bearer. */
    if (CFG.csrf) h["X-CSRF-TOKEN"] = CFG.csrf;
    if (CFG.customerToken) h["Authorization"] = "Bearer " + CFG.customerToken;
    return h;
  }
  function hasToken() { return !!CFG.customerToken; }

  /* ── F2 · follow / verified brand (B4/B5/B6) ──────────── */
  (function () {
    var btn = $("#cvFollowBtn");
    if (!btn || !EP.follow) return;
    var FOLLOW_INTENT = "cv_follow_intent";
    var REDUCED = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    function applyFollowState(d) {
      if (!d || typeof d.following === "undefined") { return false; }
      btn.dataset.following = d.following ? "1" : "0";
      btn.classList.toggle("is-on", d.following);
      var c = $("#cvFollowerCount"); if (c && typeof d.follower_count !== "undefined") c.textContent = d.follower_count;
      return true;
    }
    // B5: optimistic count nudge (the count span only exists past the B1 threshold).
    function bumpCount(delta) {
      var c = $("#cvFollowerCount"); if (!c) return;
      var n = parseInt(String(c.textContent || "").replace(/[^0-9]/g, ""), 10);
      if (!isNaN(n)) c.textContent = String(Math.max(0, n + delta));
    }
    // B5: scale-pop beat on a successful follow (skipped under reduced-motion).
    function popBeat() {
      if (REDUCED) return;
      btn.classList.add("followbtn--pop");
      btn.addEventListener("animationend", function h() { btn.classList.remove("followbtn--pop"); btn.removeEventListener("animationend", h); });
    }
    // B5: optimistic follow/unfollow — flip UI immediately, reconcile, revert on fail.
    function doFollow(unfollow) {
      var prevFollowing = btn.dataset.following === "1";
      btn.dataset.following = unfollow ? "0" : "1";
      btn.classList.toggle("is-on", !unfollow);
      bumpCount(unfollow ? -1 : 1);
      if (!unfollow) popBeat();
      fetch(EP.follow, { method: unfollow ? "DELETE" : "POST", headers: tokenHeaders(true), body: "{}" })
        .then(function (r) { return r.json(); }).then(function (d) {
          if (!d || typeof d.following === "undefined") { throw new Error("bad"); }
          applyFollowState(d);
        }).catch(function () {
          btn.dataset.following = prevFollowing ? "1" : "0";
          btn.classList.toggle("is-on", prevFollowing);
          bumpCount(unfollow ? 1 : -1);
          toast((CFG.i18n && CFG.i18n.action_failed) || "Failed");
        });
    }
    // B6: the "Following" management sheet — supersedes any two-tap guard when the button
    // is already is-on. Notifications toggle writes the follow row's notify via the same
    // follow endpoint ({notify: 0|1}); Unfollow runs the optimistic unfollow.
    var notifyBox = $("#cvFollowNotify");
    function openFollowingSheet() {
      if (notifyBox) notifyBox.checked = CFG.followNotify !== false;
      if (typeof sheet === "function") sheet("cvFollowingSheet");
    }
    if (notifyBox) {
      notifyBox.addEventListener("change", function () {
        var want = notifyBox.checked;
        fetch(EP.follow, { method: "POST", headers: tokenHeaders(true), body: JSON.stringify({ notify: want ? 1 : 0 }) })
          .then(function (r) { return r.json(); }).then(function (d) {
            if (d && typeof d.notify !== "undefined") { CFG.followNotify = !!d.notify; notifyBox.checked = !!d.notify; }
            else { throw new Error("bad"); }
          }).catch(function () { notifyBox.checked = !want; toast((CFG.i18n && CFG.i18n.action_failed) || "Failed"); });
      });
    }
    on("cvFollowUnfollow", "click", function () { if (typeof sheet === "function") sheet("cvFollowingSheet", false); doFollow(true); });
    on("cvFollowCancel", "click", function () { if (typeof sheet === "function") sheet("cvFollowingSheet", false); });

    btn.addEventListener("click", function () {
      // B4: guest tap records a follow intent, then opens the auth sheet. After a
      // successful sign-in the page reloads and the intent is replayed below.
      if (!hasToken()) {
        try { sessionStorage.setItem(FOLLOW_INTENT, "1"); } catch (e) {}
        if (typeof openAuth === "function") openAuth();
        return;
      }
      if (btn.dataset.following === "1") {
        openFollowingSheet(); // B6: manage notifications / unfollow
      } else {
        doFollow(false);
      }
    });

    // B4: replay a pending follow intent once the visitor is signed in (post-reload).
    (function () {
      var pending = false;
      try { pending = sessionStorage.getItem(FOLLOW_INTENT) === "1"; } catch (e) {}
      if (!pending || !hasToken()) return;
      try { sessionStorage.removeItem(FOLLOW_INTENT); } catch (e) {}
      if (btn.dataset.following === "1") return;
      fetch(EP.follow, { method: "POST", headers: tokenHeaders(true), body: "{}" })
        .then(function (r) { return r.json(); }).then(function (d) {
          if (applyFollowState(d) && d.following) { toast((CFG.i18n && CFG.i18n.now_following) || "✓"); }
        }).catch(function () {});
    })();
  })();

  /* ── F2b · about sheet (B8) ───────────────────────────── */
  (function () {
    if (!document.getElementById("cvAboutSheet")) return;
    function openAbout() { if (typeof sheet === "function") sheet("cvAboutSheet"); }
    var info = $(".brand-info");
    if (info) {
      info.setAttribute("role", "button");
      info.setAttribute("tabindex", "0");
      info.style.cursor = "pointer";
      info.addEventListener("click", openAbout);
      info.addEventListener("keydown", function (e) { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); openAbout(); } });
    }
    // The logo opens stories when it wears the live ring (B7); otherwise the about sheet.
    var logo = $(".brand-logo");
    if (logo) logo.addEventListener("click", function () { if (!logo.classList.contains("brand-logo--live")) openAbout(); });
  })();

  /* ── F3 · stories ─────────────────────────────────────── */
  (function () {
    var rail = $("#cvStoriesRail");
    if (!rail || !EP.stories) return;
    var stories = [], idx = 0, timer = null, T = 0;
    var DUR = 5; // seconds per story

    (window.mbStoriesGet?window.mbStoriesGet(EP.stories):fetch(EP.stories).then(function(r){return r.json();})).then(function (d) {
      stories = (d && d.data) || [];
      if (!stories.length) { rail.hidden = true; return; }
      rail.hidden = false;
      rail.innerHTML = stories.map(function (s, i) {
        return '<button class="story-bubble" data-si="' + i + '" type="button">' +
          '<span class="sb-ring"><img src="' + esc(s.media_url) + '" alt=""' + IMG_ERR + "></span>" +
          '<small>' + esc(s.caption ? String(s.caption).slice(0, 14) : (CFG.i18n.popular || "")) + "</small></button>";
      }).join("");
      // B7: with stories live, the brand logo gets the story ring and opens the viewer
      // at index 0 (same call the first story bubble uses). No stories → no change.
      var brandLogo = $(".brand-logo");
      if (brandLogo && !brandLogo.classList.contains("brand-logo--live")) {
        brandLogo.classList.add("brand-logo--live");
        brandLogo.setAttribute("role", "button");
        brandLogo.setAttribute("tabindex", "0");
        brandLogo.setAttribute("aria-label", (CFG.i18n && CFG.i18n.view_stories) || "");
        brandLogo.addEventListener("click", function () { open(0); });
        brandLogo.addEventListener("keydown", function (e) { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); open(0); } });
      }
    }).catch(function () { rail.hidden = true; });

    var viewer = $("#cvStoryViewer"), media = $("#cvSvMedia"), cap = $("#cvSvCaption"), cta = $("#cvSvCta"), prog = $("#cvSvProgress");
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
        cta.querySelector("span").textContent = s.cta_label || (CFG.i18n.popular || "Order now");
      } else { cta.hidden = true; }
      T = 0; paintSeg();
    }
    function tick() {
      T += 0.05;
      if (T >= DUR) { next(); return; }
      paintSeg();
    }
    function start() { stop(); render(); timer = setInterval(tick, 50); }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function open(i) {
      idx = i; buildProgress();
      viewer.hidden = false;
      // forced reflow so the transition runs
      void viewer.offsetWidth;
      viewer.classList.add("is-open");
      document.body.classList.add("cv-noscroll");
      start();
    }
    function close() {
      stop();
      viewer.classList.remove("is-open");
      document.body.classList.remove("cv-noscroll");
      setTimeout(function () { viewer.hidden = true; }, 300);
    }
    function next() { if (idx < stories.length - 1) { idx++; render(); } else { close(); } }
    function prev() { if (idx > 0) { idx--; render(); } else { T = 0; } }

    rail.addEventListener("click", function (e) {
      var b = e.target.closest("[data-si]"); if (b) open(Number(b.dataset.si));
    });
    on("cvSvClose", "click", close);
    /* CTA opens the item in-page beneath this viewer; close the viewer so the item sheet is the top layer. */
    if (cta) cta.addEventListener("click", close);
    on("cvSvNext", "click", next);
    on("cvSvPrev", "click", prev);
    // pause while touching
    var stage = $("#cvSvStage");
    if (stage) {
      stage.addEventListener("pointerdown", stop);
      stage.addEventListener("pointerup", function () { if (!viewer.hidden) timer = setInterval(tick, 50); });
    }
  })();

  /* ── F5 · address autocomplete + use-my-location ────────
     Shared by the checkout field (#cvCustAddr, wired once below) and the cart's
     delivery field (#cvCartAddr, re-wired by renderOtFields each time it is
     rebuilt). Coordinates always land in the checkout's hidden #cvCustLat/#cvCustLng
     pair — the order payload reads only those. `onValue` (optional) receives every
     value change so a secondary field can mirror into the checkout input. */
  function wireAddressField(input, box, locBtn, attrib, onValue) {
    var EPX = (CFG.endpoints || {});
    if (!input || !EPX.geoAutocomplete) return;
    var t = null, lastAttrib = "";
    function setAttrib(a) { if (a && attrib && a !== lastAttrib) { attrib.textContent = a; attrib.hidden = false; lastAttrib = a; } }
    function hideBox() { if (box) { box.hidden = true; box.innerHTML = ""; } }
    function setCoords(lat, lng) { var la = $("#cvCustLat"), ln = $("#cvCustLng"); if (la) la.value = lat || ""; if (ln) ln.value = lng || ""; }
    function sync() { if (onValue) onValue(input.value); }
    input.addEventListener("input", function () {
      var q = input.value.trim();
      setCoords("", "");                    /* typing invalidates a picked pin */
      sync();
      if (t) clearTimeout(t);
      if (q.length < 3) { hideBox(); return; }
      t = setTimeout(function () {
        fetch(EPX.geoAutocomplete + "?q=" + encodeURIComponent(q))
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
      setCoords(opt.dataset.lat, opt.dataset.lng);
      sync();
      hideBox();
    });
    input.addEventListener("blur", function () { setTimeout(hideBox, 200); });
    if (locBtn) locBtn.addEventListener("click", function () {
      if (!navigator.geolocation) { toast(CFG.i18n.loc_unavailable || "Location unavailable"); return; }
      locBtn.classList.add("is-loading");
      navigator.geolocation.getCurrentPosition(function (pos) {
        var lat = pos.coords.latitude, lng = pos.coords.longitude;
        fetch((EPX.geoReverse || "") + "?lat=" + lat + "&lng=" + lng)
          .then(function (r) { return r.json(); })
          .then(function (d) {
            locBtn.classList.remove("is-loading");
            setAttrib(d && d.attribution);
            if (d && d.result) {
              input.value = d.result.label || "";
              setCoords(d.result.lat || lat, d.result.lng || lng);
              sync();
            } else { toast(CFG.i18n.no_address || "No address found"); }
          }).catch(function () { locBtn.classList.remove("is-loading"); });
      }, function () { locBtn.classList.remove("is-loading"); toast(CFG.i18n.loc_denied || "Location denied"); }, { enableHighAccuracy: true, timeout: 8000 });
    });
  }
  (function () {
    var input = $("#cvCustAddr");
    if (!input) return;
    wireAddressField(input, $("#cvAddrSuggest"), $("#cvUseLocation"), $("#cvGeoAttrib"), null);
    /* saved addresses (logged-in): tap a chip to fill the field + coords */
    var saved = $("#cvSavedAddrs");
    if (saved) saved.addEventListener("click", function (e) {
      var chip = e.target.closest(".saved-addr"); if (!chip) return;
      input.value = chip.dataset.addr || "";
      $("#cvCustLat").value = chip.dataset.lat || "";
      $("#cvCustLng").value = chip.dataset.lng || "";
      $$(".saved-addr", saved).forEach(function (x) { x.classList.remove("is-on"); });
      chip.classList.add("is-on");
      var box = $("#cvAddrSuggest"); if (box) { box.hidden = true; box.innerHTML = ""; }
    });
    renderRecentAddrs();
  })();

  /* ── guest multi-address (remember addresses used on this device) ─────── */
  /* key computed inline from slug (top-level var): a hoisted `var` declared here
     would still be undefined when the IIFE above calls renderRecentAddrs() at load. */
  function addrKey() { return "cv_recent_addrs_" + slug; }
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
    var saved = $("#cvSavedAddrs");
    if (!saved || CFG.isLoggedIn) return;
    var list = readRecentAddrs();
    if (!list.length) { saved.hidden = true; return; }
    saved.hidden = false;
    saved.innerHTML = list.map(function (a, i) {
      return '<button class="saved-addr' + (i === 0 ? " is-on" : "") + '" type="button" data-addr="' + esc(a.addr) + '" data-lat="' + esc(a.lat) + '" data-lng="' + esc(a.lng) + '">' +
        '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
        '<span class="sa-text"><b>' + esc(CFG.i18n.recent_address || "Recent address") + '</b><small>' + esc(a.addr.length > 42 ? a.addr.slice(0, 42) + "…" : a.addr) + "</small></span></button>";
    }).join("");
  }

  /* ── F4 · nearest-branch map ──────────────────────────── */
  (function () {
    var openBtn = $("#cvNearestBranchBtn");
    if (!openBtn || !EP.branchesGeo || typeof L === "undefined") { if (openBtn) openBtn.style.display = "none"; return; }
    var map = null, markers = [];
    // self-hosted marker icons
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
    function renderList(branches, hasCoords) {
      $("#cvBranchGeoList").innerHTML = branches.map(function (b) {
        var dist = b.distance_km != null ? '<span class="branch-dist" dir="ltr">' + b.distance_km.toFixed(1) + " km</span>" : "";
        return '<button class="branch-row" data-bid="' + b.id + '" type="button">' +
          '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
          '<b>' + esc(b.name) + (b.address ? '<small>' + esc(b.address) + "</small>" : "") + "</b>" + dist +
          '<span class="branch-check"></span></button>';
      }).join("") || '<p class="orders-empty">' + esc(CFG.i18n.no_branches || "No branches") + "</p>";
    }
    function drawMap(branches) {
      var withCoords = branches.filter(function (b) { return b.latitude != null && b.longitude != null; });
      if (!map) {
        map = L.map("cvBranchMap", { attributionControl: false });
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 }).addTo(map);
        L.control.attribution({ prefix: false }).addAttribution('© OpenStreetMap').addTo(map);
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
      var url = EP.branchesGeo + (lat != null ? "?lat=" + lat + "&lng=" + lng : "");
      fetch(url).then(function (r) { return r.json(); }).then(function (d) {
        var branches = (d && d.branches) || [];
        $("#cvMapAttrib").textContent = (d && d.attribution) || "© OpenStreetMap contributors";
        renderList(branches);
        setTimeout(function () { drawMap(branches); if (map) map.invalidateSize(); }, 60);
      }).catch(function () {});
    }
    openBtn.addEventListener("click", function () {
      sheet("cvBranchMapSheet");
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
          function (p) { load(p.coords.latitude, p.coords.longitude); },
          function () { load(); }, { timeout: 6000 });
      } else { load(); }
    });
    $("#cvBranchGeoList").addEventListener("click", function (e) {
      var b = e.target.closest("[data-bid]"); if (b) selectBranch(b.dataset.bid);
    });
  })();

  /* ── F6 · rich reviews ────────────────────────────────── */
  (function () {
    var wrap = $("#cvReviews");
    if (!wrap || !EP.reviewsBase) return;
    var curFilter = "latest", curItemId = null;

    function starBar(n) { return '<span class="stars-mini">' + "★".repeat(n) + '<span class="s-dim">' + "★".repeat(5 - n) + "</span></span>"; }

    function render(d) {
      var avg = d.average || 0, count = d.count || 0, dist = d.distribution || {};
      var headHTML = '<div class="section-head"><h2>' + esc(CFG.i18n.reviews || "Reviews") + "</h2>" +
        '<button class="minibtn" id="cvWriteReview" type="button">' + esc(CFG.i18n.rv_write || "Write") + "</button></div>";
      if (!count) {
        /* "0.0", a five-bar histogram of zeroes and three filter chips used to
           eat a full viewport on every item page of a store with no reviews */
        wrap.innerHTML = headHTML +
          '<div class="rv-compact">' +
            '<svg class="ic" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>' +
            "<span>" + esc(CFG.i18n.rv_empty || "No reviews yet — be the first!") + "</span>" +
          "</div>";
        wrap.hidden = false;
        return;
      }
      var head = '<div class="rv-head"><div class="rv-avg"><b>' + avg.toFixed(1) + '</b>' + starBar(Math.round(avg)) +
        '<small>' + count + " " + esc((CFG.i18n.reviews || "reviews")) + "</small></div>" +
        '<div class="rv-dist">' + [5, 4, 3, 2, 1].map(function (s) {
          var c = dist[s] || 0, pct = count ? Math.round(c / count * 100) : 0;
          return '<span class="rv-bar"><em>' + s + '</em><i><s style="width:' + pct + '%"></s></i><b>' + c + "</b></span>";
        }).join("") + "</div></div>";
      var filters = '<div class="rv-filters">' +
        ['latest', 'verified', 'detailed'].map(function (f) {
          return '<button data-rvf="' + f + '" class="' + (f === curFilter ? "is-on" : "") + '" type="button">' + esc(CFG.i18n["rv_" + f] || f) + "</button>";
        }).join("") + "</div>";
      var list = (d.data || []).map(function (r) {
        var photos = (r.photos || []).map(function (p) { return '<img src="' + esc(p.url) + '" alt=""' + IMG_ERR + ">"; }).join("");
        return '<div class="rv-item"><div class="rv-item-top"><b>' + esc(r.name || "—") + "</b>" +
          (r.is_verified ? '<span class="rv-verified">✓ ' + esc(CFG.i18n.rv_verified || "Verified") + "</span>" : "") +
          "</div>" + starBar(r.stars) + (r.body ? '<p>' + esc(r.body) + "</p>" : "") +
          (photos ? '<div class="rv-photos">' + photos + "</div>" : "") + "</div>";
      }).join("");
      if (!(d.data || []).length) list = '<p class="rv-empty">' + esc(CFG.i18n.rv_empty || "No reviews yet — be the first!") + "</p>";
      wrap.innerHTML = headHTML + head + filters + '<div class="rv-list">' + list + "</div>";
      wrap.hidden = false;
    }
    function load(itemId, filter) {
      curItemId = itemId; curFilter = filter || "latest";
      fetch(EP.reviewsBase + "/" + itemId + "/reviews?filter=" + curFilter)
        .then(function (r) { return r.json(); }).then(render)
        .catch(function () { wrap.hidden = true; });
    }
    // expose to product open (see openProduct wrap below)
    window.__matchaLoadReviews = function (id) { load(id, "latest"); };

    wrap.addEventListener("click", function (e) {
      var f = e.target.closest("[data-rvf]");
      if (f) { load(curItemId, f.dataset.rvf); return; }
      if (e.target.closest("#cvWriteReview")) { openReview(); }
    });

    var rvStars = 0;
    function openReview() {
      if (!curItemId) return;
      rvStars = 0;
      $$("#cvReviewStars button").forEach(function (b) { b.classList.remove("is-on"); });
      $("#cvReviewText").value = "";
      var nm = $("#cvReviewName"); if (nm) nm.value = "";
      $("#cvReviewErr").hidden = true;
      var it = item(curItemId);
      $("#cvReviewItemName").textContent = it ? it.name : "";
      sheet("cvReviewSheet");
    }
    $$("#cvReviewStars button").forEach(function (b) {
      b.addEventListener("click", function () {
        rvStars = Number(b.dataset.star);
        $$("#cvReviewStars button").forEach(function (x, i) { x.classList.toggle("is-on", i < rvStars); });
      });
    });
    on("cvReviewSubmit", "click", function () {
      var err = $("#cvReviewErr");
      if (!rvStars) { err.textContent = CFG.i18n.choose || "Choose a rating"; err.hidden = false; return; }
      var body = { rating: rvStars, text: $("#cvReviewText").value.trim() };
      var nm = $("#cvReviewName"); if (nm && nm.value.trim()) body.name = nm.value.trim();
      var btn = $("#cvReviewSubmit"); btn.disabled = true;
      fetch(EP.reviewsBase + "/" + curItemId + "/reviews", {
        method: "POST", headers: tokenHeaders(true), body: JSON.stringify(body),
      }).then(function (r) { return r.json(); }).then(function (d) {
        btn.disabled = false;
        if (d && d.ok) {
          sheet("cvReviewSheet", false);
          toast(CFG.i18n.thanks || "Thanks!");
          load(curItemId, curFilter);
        } else {
          err.textContent = (d && d.message) || "Failed"; err.hidden = false;
        }
      }).catch(function () { btn.disabled = false; err.textContent = CFG.i18n.network_error || "Network error"; err.hidden = false; });
    });

    // wrap openProduct to load reviews (reassign the hoisted binding)
    var _openProduct = openProduct;
    openProduct = function (id) {
      _openProduct(id);
      wrap.hidden = true; wrap.innerHTML = "";
      window.__matchaLoadReviews(id);
    };
  })();

  /* ── F1 · meal-plan create sheet ──────────────────────── */
  (function () {
    var sheetEl = $("#cvMealPlanSheet"), cta = $("#cvMealPlanCta");
    if (!cta) return;
    if (!CFG.mealPlansEnabled) { cta.style.display = "none"; return; }
    var cadence = "weekly", cadences = [];
    try { cadences = JSON.parse(sheetEl.getAttribute("data-cadences") || "[]"); } catch (e) {}
    if (!cadences.length) cadences = ["daily", "weekly"];
    var days = {};

    function label(c) { return CFG.i18n["cad_" + c] || { daily: "Daily", weekly: "Weekly", custom: "Custom days" }[c] || c; }
    function buildCadence() {
      var el = $("#cvMpCadence"); if (!el) return;
      el.innerHTML = cadences.map(function (c, i) {
        return '<button data-cad="' + c + '" class="' + (c === cadence || (i === 0) ? "is-on" : "") + '" type="button">' + esc(CFG.i18n["cad_" + c] || label(c)) + "</button>";
      }).join("");
      cadence = cadences.indexOf("weekly") !== -1 ? "weekly" : cadences[0];
      $$("#cvMpCadence button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.cad === cadence); });
    }

    cta.addEventListener("click", function () {
      if (!cart.length) { toast(CFG.i18n.cart_empty || "Cart is empty"); return; }
      if (!hasToken()) { if (typeof openAuth === "function") openAuth(); return; }
      buildCadence();
      var sum = $("#cvMpItemsSummary");
      if (sum) sum.textContent = cart.reduce(function (a, l) { return a + l.qty; }, 0) + " × " + (CFG.i18n.total || "items");
      $("#cvMpDaysWrap").hidden = cadence !== "custom";
      sheet("cvMealPlanSheet");
    });
    on("cvMpLoginBtn", "click", function () { if (typeof openAuth === "function") { sheet("cvMealPlanSheet", false); openAuth(); } });

    if (sheetEl) sheetEl.addEventListener("click", function (e) {
      var c = e.target.closest("[data-cad]");
      if (c) {
        cadence = c.dataset.cad;
        $$("#cvMpCadence button").forEach(function (b) { b.classList.toggle("is-on", b === c); });
        $("#cvMpDaysWrap").hidden = cadence !== "custom";
        return;
      }
      var d = e.target.closest("[data-day]");
      if (d) { var k = d.dataset.day; days[k] = !days[k]; d.classList.toggle("is-on", days[k]); }
    });

    on("cvMpSubmit", "click", function () {
      var err = $("#cvMpErr"); err.hidden = true;
      var items = cart.map(function (l) {
        return { item_id: l.id, quantity: l.qty, variation: l.variantId || null, extras: (l.extras || []).map(function (e) { return e.id; }) };
      });
      if (!items.length) { err.textContent = CFG.i18n.cart_empty || "Cart is empty"; err.hidden = false; return; }
      var body = {
        name: $("#cvMpName").value.trim() || null,
        cadence: cadence,
        start_date: $("#cvMpStart").value,
        items: items,
      };
      if (cadence === "custom") {
        body.days = Object.keys(days).filter(function (k) { return days[k]; }).map(Number);
        if (!body.days.length) { err.textContent = CFG.i18n.choose || "Pick at least one day"; err.hidden = false; return; }
      }
      var btn = $("#cvMpSubmit"); btn.disabled = true;
      fetch(EP.mealPlan, { method: "POST", headers: tokenHeaders(true), body: JSON.stringify(body) })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          btn.disabled = false;
          if (res.ok && res.j && res.j.id) {
            $("#cvMealPlanForm").hidden = true;
            $("#cvMpDone").hidden = false;
            $("#cvMpDoneSub").textContent = (CFG.i18n.mp_next || "Next order") + ": " + (res.j.next_run || "");
          } else {
            var msg = res.j && (res.j.message || (res.j.errors && Object.values(res.j.errors)[0][0]));
            err.textContent = msg || "Failed"; err.hidden = false;
          }
        }).catch(function () { btn.disabled = false; err.textContent = CFG.i18n.network_error || "Network error"; err.hidden = false; });
    });
  })();

  /* ── F7 · OTP checkout gate ───────────────────────────── */
  var openCheckoutOtp;
  (function () {
    var sheetId = "cvCheckoutOtpSheet";
    var boxes = $$("#cvOtpGateBoxes input");
    var curPhone = "";
    function code() { return boxes.map(function (b) { return b.value; }).join(""); }
    boxes.forEach(function (inp, i) {
      inp.addEventListener("input", function () {
        inp.value = inp.value.replace(/\D/g, "").slice(0, 1);
        if (inp.value && boxes[i + 1]) boxes[i + 1].focus();
      });
      inp.addEventListener("keydown", function (e) { if (e.key === "Backspace" && !inp.value && boxes[i - 1]) boxes[i - 1].focus(); });
    });
    function send(phone) {
      fetch(EP.otpSend, {
        method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf },
        body: JSON.stringify({ phone: phone, store_id: CFG.storeId }),
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || !d.success) { var e = $("#cvOtpGateErr"); e.textContent = (d && d.message) || "Failed"; e.hidden = false; }
      }).catch(function () {});
    }
    openCheckoutOtp = function (phone) {
      curPhone = phone;
      $("#cvOtpGatePhone").textContent = phone;
      boxes.forEach(function (b) { b.value = ""; });
      $("#cvOtpGateErr").hidden = true;
      sheet(sheetId);
      setTimeout(function () { if (boxes[0]) boxes[0].focus(); }, 300);
      send(phone);
    };
    on("cvOtpGateResend", "click", function () { $("#cvOtpGateErr").hidden = true; send(curPhone); toast("Sent"); });
    on("cvOtpGateVerify", "click", function () {
      var err = $("#cvOtpGateErr"); err.hidden = true;
      var c = code();
      if (c.length < 4) { err.textContent = CFG.i18n.required || "Enter the code"; err.hidden = false; return; }
      var btn = $("#cvOtpGateVerify"); btn.disabled = true; btn.querySelector(".spinner").hidden = false;
      fetch(EP.otpVerify2, {
        method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf },
        body: JSON.stringify({ phone: curPhone, code: c, store_id: CFG.storeId }),
      }).then(function (r) { return r.json(); }).then(function (d) {
        btn.disabled = false; btn.querySelector(".spinner").hidden = true;
        if (d && d.success) {
          sheet(sheetId, false);
          if (typeof placeOrder === "function") placeOrder(); // retry — gate now passes
        } else {
          err.textContent = (d && d.message) || "Invalid code"; err.hidden = false;
        }
      }).catch(function () { btn.disabled = false; btn.querySelector(".spinner").hidden = true; err.textContent = CFG.i18n.network_error || "Network error"; err.hidden = false; });
    });
  })();

  /* ── boot ─────────────────────────────────────────────── */
  /* hide category chips that have no items (prevents tapping into an empty category) */
  function hideEmptyChips() {
    $$('#cvChips [data-cat]').forEach(function (chip) {
      var cat = chip.dataset.cat;
      if (cat === "all") return;
      if (!ITEMS.some(function (i) { return catMatch(i.cat, cat); })) chip.hidden = true;
    });
  }

  /* Any logo/category image that 404s at runtime is hidden so the monogram or
     emoji painted underneath shows, instead of Chrome's broken-image glyph.
     Runs at boot (covers images that already failed) and on later errors. */
  function monoSweep() {
    $$("img[data-mono]").forEach(function (im) {
      if (im.complete && im.naturalWidth === 0) im.hidden = true;
      else im.addEventListener("error", function () { im.hidden = true; }, { once: true });
    });
  }

  function boot() {
    monoSweep();
    pickupReorder();
    /* default order type: room service > forced table > first enabled.
       The room-service button only renders when $allow_room_service is set, which
       already means hotel mode is on AND this QR resolved to a room — so its mere
       presence is the signal that the guest scanned from their room. Preferring it
       saves them a tap and, more importantly, stops a room order silently going to
       the kitchen as a table order when dine-in also happens to be enabled. */
    var otBtns = $$("#cvOrderTypeSeg [data-ot]");
    if (otBtns.some(function (b) { return b.dataset.ot === "room_service"; })) S.orderType = "room_service";
    else if (CFG.forceTable && otBtns.some(function (b) { return b.dataset.ot === "on-table"; })) S.orderType = "on-table";
    else if (otBtns.length) S.orderType = otBtns[0].dataset.ot;

    skeletons();
    syncBadge(false);
    setTimeout(renderGrid, 650);
    renderSpecials();
    renderFeat();
    initBanners();
    renderOtFields();
    hideEmptyChips();
    syncSubChips();
    renderRecentOrders();   /* list this browser's past orders on the guest profile */

    /* Open a shared / deep-linked product (?item=<id>) in place: a link that lands
       here goes straight to the item instead of the bare home view (also makes any
       legacy full-navigation CTA graceful if the click interceptor is bypassed). */
    try {
      var deepItem = new URLSearchParams(window.location.search).get("item");
      if (deepItem && item(deepItem)) openProduct(deepItem);
    } catch (e) {}

    /* splash: once per session */
    var splash = $("#cvSplash");
    if (splash) {
      /* A1: a ?item=/?table= deep link opens straight on the product — dismiss the
         splash up front so it never overlays it (the splash sits above the sheet).
         Not persisted: a later plain visit this session still gets the intro. */
      var _dl = null; try { _dl = new URLSearchParams(location.search); } catch (e) {}
      var deepLinked = !!(_dl && (_dl.get("item") || _dl.get("table")));
      if (deepLinked) splash.classList.add("is-done");
      else if (sessionStorage.getItem("mt_splash_" + slug)) splash.classList.add("is-done");
      else {
        sessionStorage.setItem("mt_splash_" + slug, "1");
        setTimeout(function () { splash.classList.add("is-done"); }, 2300);
      }
    }

    /* restore saved currency choice (store-scoped shared helper; replays the handler) */
    if (window.mbCurrency) mbCurrency.restore("#curSeg");

    /* light/dark toggle — shared engine owns persistence + the data-theme
       attribute on the .matcha root and binds [data-mb-theme-toggle] */
    if (window.mbTheme) window.mbTheme.init({ rootSel: ".matcha" });
  }
  boot();

  /* Collapsing header — reveal a condensed bar once the wave header scrolls out of
     the home view's own scroll container (window is a no-op in these SPA views).
     matcha's category chips are NOT sticky, so no menu-overlap guard is needed.
     The condensed buttons forward their click to the real wave controls
     (single-id-bound), never duplicating them. */
  (function mountCondensedHeader() {
    var homeView = document.querySelector('.view[data-view="home"]');
    if (!homeView) return;
    var wave = homeView.querySelector(".wave");
    var bar = homeView.querySelector(".cv-condensed");
    var scroller = homeView.querySelector(".view-scroll");
    if (!wave || !bar || !scroller || !("IntersectionObserver" in window)) return;
    var io = new IntersectionObserver(function (es) {
      var e = es[0];
      if (e) bar.classList.toggle("is-on", e.intersectionRatio < 0.1);
    }, { root: scroller, threshold: [0, 0.1, 1] });
    io.observe(wave);
    var T = { lang: "#cvLangBtn", catering: "#cvCateringBtn", notif: "#mbNotifBell" };
    bar.querySelectorAll("[data-cv-proxy]").forEach(function (b) {
      b.addEventListener("click", function () {
        var t = homeView.querySelector(T[b.getAttribute("data-cv-proxy")]);
        if (t) t.click();
      });
    });
  })();

  /* The shared home order-type bar (_partials/home-order-type → #mbOrderType)
     anchors itself via mbAnchor, but matcha's category-first home leaves it
     stranded at document-body level: harmless on mobile (behind the fixed
     phone frame) but on desktop it floats loose at the top corner, outside
     the phone column and the companion panel. Pull it into the frame just
     above the offers strip — its intended slot — and re-assert briefly in
     case the shared onSettled re-strands it. Idempotent: only moves the bar
     when it is currently outside the matcha root, so it never fights itself. */
  (function () {
    var root = document.getElementById("matcha");
    function reseat() {
      var bar = document.getElementById("mbOrderType");
      if (!bar || !root || root.contains(bar)) return;
      try {
        var offers = document.getElementById("mbOffersStrip");
        if (offers && root.contains(offers) && offers.parentElement) {
          offers.parentElement.insertBefore(bar, offers);
          return;
        }
        var scroll = root.querySelector('.view[data-view="home"] .view-scroll') || root.querySelector(".view-scroll");
        if (!scroll) return;
        /* the anchor may be nested inside the scroll, so insert relative to its
           own parent (insertBefore requires a direct child); else just prepend. */
        var ref = scroll.querySelector(".cats") || scroll.querySelector(".search");
        if (ref && ref.parentElement) ref.parentElement.insertBefore(bar, ref);
        else scroll.insertBefore(bar, scroll.firstChild);
      } catch (e) { /* transient DOM race during settle — next pass fixes it */ }
    }
    [0, 200, 600, 1200, 2500].forEach(function (t) { setTimeout(reseat, t); });
    var mo = new MutationObserver(reseat);
    mo.observe(document.body, { childList: true, subtree: true });
    setTimeout(function () { mo.disconnect(); }, 4000);
  })();
})();
