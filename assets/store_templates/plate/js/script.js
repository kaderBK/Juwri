// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ═══════════════════════════════════════════════════════════
   plate — theme runtime
   DOM & motion mirror rev/plate/demo.js; data + ordering are the
   real app (window.PLATE from index.blade.php).
   variants / extras / global modifiers · dietary tags · calories
   · order types · coupon · tax + delivery · tips · wallet ·
   scheduling · loyalty · reservations + waitlist · waiter call ·
   confirmed + live tracking · Google review · WhatsApp ·
   multi-currency · i18n + full RTL · vertical cart rail
   ═══════════════════════════════════════════════════════════ */
(function () {
  "use strict";
  var CFG = window.PLATE;
  if (!CFG) return;

  var root = document.getElementById("plate");
  if (!root) return;
  function $(s, c) { return (c || root).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || root).querySelectorAll(s)); }
  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }
  /* Crawlable + new-tab-able per-item URL: keeps existing query (table, lang…)
     and sets ?item=ID — the deep-link the R7 intercept + boot loader understand.
     plate's cards carry real <button>s (fav/add/chev), so the whole card can't be
     an <a>; the dish name is the anchor instead — enough for crawl + open-in-new-tab. */
  function itemHref(id) {
    try { var u = new URL(window.location.href); u.searchParams.set("item", id); u.hash = ""; return u.pathname + u.search; }
    catch (e) { return "?item=" + encodeURIComponent(id); }
  }
  var T = CFG.i18n || {};
  function tt(k) { return T[k] != null ? T[k] : k; }
  var PLACE_LABEL = T.place_order || "Place Order";

  var STAR = '<svg viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>';
  var ICONS = {
    check: '<svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>',
    x: '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    leaf: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 19C5 9 13 5 20 5c0 8-4 14-12 14-1 0-2-.1-3 0Z"/><path d="M5 19c2-5 6-9 10-11"/></svg>',
    moon: '<svg class="ic" viewBox="0 0 24 24"><path d="M14 3a8 8 0 1 0 7 11.5A9 9 0 0 1 14 3Z"/></svg>',
    gf: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 4v16M8.5 7.5C8.5 6 10 5 12 5s3.5 1 3.5 2.5M8.5 12c0 1.5 1.5 2.5 3.5 2.5s3.5-1 3.5-2.5"/><path d="M4 20 20 4"/></svg>',
    flame: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 22c4.4 0 7-2.8 7-6.6 0-4.8-4.2-6.6-4.9-11.4-2.5 1.6-3.4 4-3.2 6.6-1.2-.7-2-1.7-2.4-3.4C6.6 9 5 11.6 5 15.4 5 19.2 7.6 22 12 22Z"/></svg>',
    pin: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>',
  };

  /* ── state ────────────────────────────────────────────── */
  var slug = CFG.restaurantSlug;
  var cartKey = "mb-cart-" + slug;
  var favKey = "plate-favs-" + slug;
  var cart = [];
  try { cart = JSON.parse(localStorage.getItem(cartKey)) || []; } catch (e) {}
  if (!Array.isArray(cart)) cart = [];
  var favs = new Set();
  try { (JSON.parse(localStorage.getItem(favKey)) || []).forEach(function (id) { favs.add(String(id)); }); } catch (e) {}

  var S = {
    cat: "all", sub: "root", search: "", sort: "featured",
    filters: { diets: {}, allergens: {}, spice: 0 },
    orderType: null, coupon: null, tipPct: 0,
    table: CFG.defaultTable || "",
    addr: (CFG.savedAddresses && CFG.savedAddresses[0] && CFG.savedAddresses[0].addr) || "",
    lat: (CFG.savedAddresses && CFG.savedAddresses[0] && CFG.savedAddresses[0].lat) || "",
    lng: (CFG.savedAddresses && CFG.savedAddresses[0] && CFG.savedAddresses[0].lng) || "",
    when: "now", pay: "pay_on_counter", walletUse: false,
    item: null, iQty: 1, iVar: 0, iExtras: new Set(), iMods: {},
    railOpen: true, activeOrder: null,
    curCode: CFG.baseCurrency,
    curRate: 1,
  };

  /* ── items / money ────────────────────────────────────── */
  var ITEMS = Object.keys(CFG.items).map(function (k) { return CFG.items[k]; });
  var CATS = CFG.cats || [];   // two-tier nav: [{ id, name, subs:[{id,name}] }]
  function item(id) { return CFG.items[id]; }
  function itemImgs(i) {
    var g = (i && i.gallery && i.gallery.length) ? i.gallery.slice() : [];
    if (i && i.img && g.indexOf(i.img) === -1) g.unshift(i.img);
    return g.length ? g : [(i && i.img) || ""];
  }
  var FALLBACK = CFG.defaultImg || "";
  var IMG_ERR = FALLBACK ? ' data-csp-error="fallback" data-csp-fallback="' + esc(FALLBACK) + '"' : "";

  // Decimals follow the DISPLAYED currency, not the store's base currency. Codes absent from
  // the map are 2-decimal; only fall back to the store's own decimals for its own currency
  // (otherwise an OMR store showing USD would render "USD 2.600").
  function curDp() {
    var m = CFG.decimalsByCode || {};
    if (m[S.curCode] != null) return m[S.curCode];
    return S.curCode === CFG.baseCurrency ? (CFG.currencyDecimals ?? 2) : 2;
  }
  // Money rounding to a given number of decimals. Totals are computed in the STORE's
  // base currency (money() converts only at display), so every quantization below uses
  // the store's own decimals — mirroring currency_decimals($postOptions->currency_code)
  // on the server. EPSILON keeps 1.005-style binary artefacts off the receipt.
  /* bug batch 10 · 4: order arithmetic at the stored precision (CFG.moneyDecimals); display keeps currencyDecimals */
  function storeDp() { return CFG.moneyDecimals != null ? CFG.moneyDecimals : (CFG.currencyDecimals == null ? 2 : CFG.currencyDecimals); }
  /* M16: delegates to the shared money core (drops the EPSILON approximation
     for PHP-exact rounding). */
  function roundTo(n, dp) { return mbMoney.round(n, dp == null ? storeDp() : dp); }

  function money(base) {
    var v = (Number(base) || 0) * S.curRate;
    var str = v.toFixed(curDp());
    if (S.curCode !== CFG.baseCurrency) return "≈ " + S.curCode + " " + str;
    return CFG.currencyPos === "1" ? CFG.currencySign + (CFG.currencyGap || "") + str : str + (CFG.currencyGap || "") + CFG.currencySign;
  }

  function soldOut(i) {
    if (!i) return true;
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
  function availableNow(i) {
    return i.available_now !== false;
  }

  function starsHTML(rating) {
    var full = Math.round(Number(rating) || 0), out = '<span class="stars">';
    for (var i = 0; i < 5; i++)
      out += STAR.replace("<svg", '<svg style="fill:' + (i < full ? "var(--gold)" : "var(--hair-2)") + ';stroke:none"');
    return out + "</span>";
  }

  /* ── pricing ──────────────────────────────────────────── */
  function unitPrice(line) {
    if (line.reward) return 0;
    var i = item(line.id); if (!i) return 0;
    var p = Number(i.price) || 0;
    if (line.variantId) {
      var v = (i.variants || []).filter(function (x) { return x.id === line.variantId; })[0];
      if (v) p = Number(v.price) || 0;
    }
    (line.mods || []).forEach(function (m) { p += Number(m.price) || 0; });
    (line.extras || []).forEach(function (e) { p += Number(e.price) || 0; });
    return p;
  }
  function lineSummary(line) {
    var i = item(line.id); if (!i) return "";
    var parts = [];
    if (line.variantId) {
      var v = (i.variants || []).filter(function (x) { return x.id === line.variantId; })[0];
      if (v) parts.push(v.label);
    }
    (line.mods || []).forEach(function (m) { parts.push(m.label); });
    (line.extras || []).forEach(function (e) { parts.push(e.title); });
    return parts.join(" · ");
  }
  /* The cart as submitOrder's `items` — also posted to the coupon check (bug batch 7 · 4). */
  function orderLines() {
    return cart.map(function (l) {
      return {
        id: l.id, quantity: l.qty, variants: l.variantId || 0,
        extras: (l.extras || []).map(function (e) { return { id: e.id }; }),
        mods: (l.mods || []).map(function (m) { return { groupId: m.groupId, optIndex: m.optIndex }; }),
        // Per-line note. `order_note` is exactly what OrderItem::create() reads, so
        // "no onions" reaches the kitchen ticket instead of dying in localStorage.
        order_note: l.note || "",
      };
    });
  }
  /* Bug batch 7 · 4: buy-X-get-Y is priced by the server on the cart LINES (the discount the order will get);
     the quote is refreshed whenever the cart or the order type changes. */
  function couponBody(code) {
    return JSON.stringify({ code: code, subtotal: cartSubtotal(), items: orderLines(), "ordering-type": S.orderType || "" });
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
        renderCartTotals(); if (active === "checkout") renderCheckout();
      }).catch(function () {});
    }
    return Number(c.discount) || 0;
  }
  function cartCount() { return cart.reduce(function (n, l) { return n + l.qty; }, 0); }
  function cartSubtotal() {
    /* Combos ride in the subtotal: the server taxes them, min-order counts them,
       and the coupon endpoint is quoted the same base the order will carry. */
    return cart.reduce(function (s, l) { return s + unitPrice(l) * l.qty; }, 0) + comboTotal();
  }
  function persistCart() { localStorage.setItem(cartKey, JSON.stringify(cart)); }

  /* COMBOS-V2 — the shared combos partial (_partials/combos.blade.php) keeps its
     own basket and publishes display state on window.mbCombos {count,total,lines}.
     The server prices combo lines into the ORDER subtotal (and taxes them like any
     other line), so the quote must fold them in too — QUOTED == CHARGED. Quantity
     edits stay in the combo sheet; here they render read-only. */
  function comboCount() { return (window.mbCombos && window.mbCombos.count) || 0; }
  function comboTotal() { return (window.mbCombos && window.mbCombos.total) || 0; }
  function comboLines() { return (window.mbCombos && window.mbCombos.lines) || []; }

  /* THE TOTALS CONTRACT — this must reproduce Order::getTotalAttribute() to the cent:
       TOTAL = subtotal + delivery + tax + tray + tip − discount
     Three traps, all of which this theme used to fall into:
       1. Tax is computed on the RAW subtotal. The discount is subtracted LAST and is
          never taxed. (Taxing `sub − disc` quoted $18.72 against a charged $18.80.)
       2. `restaurant_tax_type === 'fixed'` is a FLAT amount, not a rate.
       3. Rounding follows the STORE's currency decimals, not a hardcoded 2.
     CouponService::resolve() rounds the discount to 2 regardless of currency, so the
     discount alone is quantized to 2 here — deliberately, not by omission. */
  function cartTotals() {
    var sub = cartSubtotal();
    var dp = storeDp();

    var disc = 0;
    if (S.coupon) {
      // Bug batch 7 · 4: in the store currency's decimals like CouponService (was 2: a KWD 1.2345 previewed 1.23).
      disc = S.coupon.coupon_type === "percentage"
        ? roundTo(sub * ((Number(S.coupon.percentage) || 0) / 100), storeDp())
        : S.coupon.coupon_type === "bogo" ? bxgyDiscount() : roundTo(Number(S.coupon.percentage) || 0, storeDp());
      if (disc > sub) disc = sub;
    }

    var tax = 0;
    if (CFG.taxCharge > 0 && sub > 0) {
      tax = CFG.taxType === "fixed"
        ? roundTo(CFG.taxCharge, dp)
        : roundTo(sub * (CFG.taxCharge / 100), dp);
    }

    var del = S.orderType === "delivery" && (cart.length || comboCount()) ? (Number(CFG.deliveryCharge) || 0) : 0;

    // H8 tray charge: room_service only, "amount" or "percent" of subtotal.
    var tray = 0;
    var tc = CFG.trayCharge;
    if (S.orderType === "room_service" && sub > 0 && tc && (Number(tc.value) || 0) > 0) {
      tray = tc.type === "percent"
        ? roundTo(sub * (Number(tc.value) / 100), dp)
        : roundTo(Number(tc.value), dp);
    }

    var tip = S.tipPct ? roundTo(sub * (S.tipPct / 100), dp) : 0;

    return {
      sub: sub, disc: disc, tax: tax, del: del, tray: tray, tip: tip,
      total: roundTo(sub + del + tax + tray + tip - disc, dp),
    };
  }

  /* ── router ───────────────────────────────────────────── */
  var stack = [], ROOTS = ["home"], active = "home";
  function show(name, opts) {
    opts = opts || {};
    /* Search is a full overlay, not a view — leaving it open while switching to
       another view (e.g. dock search → dock cart) stranded the search panel on top
       with the dock hidden. Any navigation dismisses it. */
    var _sp = document.getElementById("searchPanel");
    if (_sp && !_sp.hidden) closeSearch();
    if (name === active) return;
    var from = $('.view[data-view="' + active + '"]'), to = $('.view[data-view="' + name + '"]');
    if (!to) return;
    var isRoot = ROOTS.indexOf(name) !== -1;
    if (opts.push !== false && !isRoot) {
      stack.push(active);
      /* Mirror the internal stack into browser history so the phone's back
         button walks item → home instead of leaving the site. */
      if (!opts.fromPop) { try { history.pushState({ pl: 1 }, ""); } catch (e) {} }
    }
    if (isRoot) stack.length = 0;
    $$(".view").forEach(function (v) { v.classList.remove("is-under"); });
    if (!isRoot && from) from.classList.add("is-under");
    if (from) from.classList.remove("is-active");
    to.classList.add("is-active");
    var sc = to.querySelector(".view-scroll"); if (sc) sc.scrollTop = 0;
    active = name;
    var _dk = document.getElementById("plDock"); if (_dk) _dk.classList.toggle("is-hidden", name !== "home");
    if (name === "cart") renderCart();
    if (name === "checkout") renderCheckout();
    syncRail();
  }
  function internalBack() { show(stack.pop() || "home", { push: false, fromPop: true }); }
  function back() {
    /* UI back buttons go through history when we pushed a state, so the
       browser's own back stack stays in step with what is on screen. */
    if (stack.length && history.state && history.state.pl) { history.back(); return; }
    internalBack();
  }
  window.addEventListener("popstate", function () {
    if (openSheetId) { sheet(openSheetId, false); try { history.pushState({ pl: 1 }, ""); } catch (e) {} return; }
    if (stack.length) internalBack();
    else if (active !== "home") show("home", { push: false, fromPop: true });
  });

  /* ── sheets / scrim ───────────────────────────────────── */
  var openSheetId = null, hideTimers = {};
  function sheet(id, open) {
    var s = document.getElementById(id), scrim = $("#scrim");
    if (!s) return;
    if (open !== false) {
      if (openSheetId && openSheetId !== id) sheet(openSheetId, false);
      clearTimeout(hideTimers[id]);
      s.hidden = false; scrim.hidden = false;
      requestAnimationFrame(function () { s.classList.add("is-open"); scrim.classList.add("is-open"); });
      openSheetId = id;
      /* The dock sits at z-60, above the sheet (z-50) + scrim (z-40), so it covered
         the bottom of any open sheet (favourites etc.). Hide it while a sheet is up. */
      var _dkO = document.getElementById("plDock"); if (_dkO) _dkO.classList.add("is-hidden");
    } else {
      s.classList.remove("is-open");
      clearTimeout(hideTimers[id]);
      hideTimers[id] = setTimeout(function () { s.hidden = true; if (!openSheetId || openSheetId === id) { scrim.classList.remove("is-open"); scrim.hidden = true; } }, 450);
      if (openSheetId === id) openSheetId = null;
      /* Restore the dock on close — but only where it belongs (home view). */
      var _dkC = document.getElementById("plDock"); if (_dkC) _dkC.classList.toggle("is-hidden", active !== "home");
    }
  }
  function closeAnySheet() {
    var open = root.querySelector(".sheet.is-open");
    if (open) sheet(open.id, false);
    else if (openSheetId) sheet(openSheetId, false);
  }
  $("#scrim").addEventListener("click", closeAnySheet);
  $$(".sheet-grab").forEach(function (g) { g.addEventListener("click", function () { var s = g.closest(".sheet"); if (s) sheet(s.id, false); }); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") { var sp = $("#searchPanel"); if (sp && !sp.hidden && sp.classList.contains("is-open")) closeSearch(); else closeAnySheet(); } });

  /* ── toast ────────────────────────────────────────────── */
  var toastTimer;
  function toast(msg) {
    var el = $("#toast");
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

  /* ── analytics ────────────────────────────────────────── */
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
  var io = ("IntersectionObserver" in window) && !matchMedia("(prefers-reduced-motion:reduce)").matches
    ? new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add("in"); io.unobserve(e.target); } }); }, { threshold: .12 })
    : null;

  function flagHTML(i) {
    if (soldOut(i)) return '<span class="flag sold">' + esc(tt("sold_out")) + "</span>";
    if (i.is_daily_special) return '<span class="flag spec">' + esc(tt("daily_special")) + "</span>";
    if (i.is_popular) return '<span class="flag pop">★ ' + esc(tt("popular")) + "</span>";
    return "";
  }
  function hasOptions(i) { return (i.variants && i.variants.length) || (i.extras && i.extras.length) || (i.optionGroups && i.optionGroups.length); }

  /* PARITY-RUN T5 — happy-hour price display. `i.price` is ALREADY the price the
     server will charge; `i.original_price` is what it was before the schedule,
     present only while a schedule is live. Purely presentational: the cart and
     the order path both take their number from the server, never from here. */
  function hhWasHTML(i) {
    return i.original_price ? '<s style="opacity:.55;font-weight:400;margin-inline-end:.35em">' + money(i.original_price) + "</s>" : "";
  }
  function hhBadgeHTML(i) {
    return i.schedule_label
      ? '<span class="hh-badge" style="display:inline-block;padding:.1em .5em;border-radius:999px;background:#f59e0b;color:#1b1b1b;font-size:.68em;font-weight:700;vertical-align:middle">' + esc(i.schedule_label) + "</span>"
      : "";
  }
  function cardHTML(i, idx, xl) {
    var favOn = favs.has(String(i.id)) ? "is-on" : "";
    var d = 'style="--d:' + Math.min(idx * 60, 420) + 'ms"';
    var unav = !availableNow(i);
    var out = soldOut(i);
    var addBtn = (CFG.allowOrder && !out)
      ? (hasOptions(i)
        ? '<button class="card-add" data-open="' + i.id + '">' + esc(tt("add_to_cart")) + "</button>"
        : '<button class="card-add" data-add="' + i.id + '">' + esc(tt("add_to_cart")) + "</button>")
      : "";
    if (xl) {
      var sub = (i.desc || "").split(/[.،!\n]/)[0].slice(0, 90);
      return '<article class="card card-xl' + (unav || out ? " is-unavailable" : "") + '" data-open="' + i.id + '" ' + d + '>' +
        '<span class="xl-img"><img src="' + esc(i.img) + '" alt="" loading="lazy"' + IMG_ERR + ">" +
        '<button class="fav ' + favOn + '" data-fav="' + i.id + '" aria-label="Favorite"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button></span>' +
        '<span class="xl-body">' + flagHTML(i) +
        '<b class="card-name"><a href="' + esc(itemHref(i.id)) + '" data-open="' + i.id + '" style="color:inherit;text-decoration:none">' + esc(i.name) + "</a></b>" +
        (i.rating ? '<span class="card-stars">' + starsHTML(i.rating) + "</span>" : "") +
        (sub ? '<span class="xl-desc">' + esc(sub) + "</span>" : "") +
        '<span class="xl-foot"><span class="card-price">' + hhWasHTML(i) + money(i.price) + "</span> " + hhBadgeHTML(i) + addBtn.replace("card-add", "xl-add") + "</span>" +
        "</span></article>";
    }
    return '<article class="card' + (unav || out ? " is-unavailable" : "") + '" data-open="' + i.id + '" ' + d + '>' +
      flagHTML(i) +
      '<button class="fav ' + favOn + '" data-fav="' + i.id + '" aria-label="Favorite"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></button>' +
      '<span class="card-ring"><img src="' + esc(i.img) + '" alt="" loading="lazy"' + IMG_ERR + "></span>" +
      '<b class="card-name"><a href="' + esc(itemHref(i.id)) + '" data-open="' + i.id + '" style="color:inherit;text-decoration:none">' + esc(i.name) + "</a></b>" +
      (i.rating ? '<span class="card-stars">' + starsHTML(i.rating) + "</span>" : '<span class="card-stars"></span>') +
      (hasOptions(i) ? '<span class="card-from">' + esc(tt("from")) + "</span>" : "") +
      '<span class="card-price">' + hhWasHTML(i) + money(i.price) + "</span> " + hhBadgeHTML(i) +
      addBtn +
      '<button class="card-chev" data-chev aria-label="Details"><svg class="ic" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg></button>' +
      '<div class="card-more"><p>' + esc(i.desc || "") + "</p>" +
      (i.calories || i.prep_time ? '<span class="cap">' + [i.calories ? i.calories + " " + tt("kcal") : "", i.prep_time ? i.prep_time + " " + tt("min") : ""].filter(Boolean).join(" · ") + "</span>" : "") +
      "</div></article>";
  }

  /* dietary / allergen / spice filter — composes with the two-tier nav */
  function passFilters(i) {
    var f = S.filters;
    if (f.diets.vegan && !i.is_vegan) return false;
    if (f.diets.halal && !i.is_halal) return false;
    if (f.diets.gluten_free && !i.is_gluten_free) return false;
    if (f.spice && (Number(i.spice_level) || 0) < f.spice) return false;
    for (var aId in f.allergens) {
      if (f.allergens[aId] && (i.allergies || []).some(function (a) { return String(a.id) === String(aId); })) return false;
    }
    return true;
  }
  function filtersActive() {
    var f = S.filters;
    return !!(f.diets.vegan || f.diets.halal || f.diets.gluten_free || f.spice ||
      Object.keys(f.allergens).some(function (k) { return f.allergens[k]; }));
  }

  function visibleItems() {
    var q = S.search.trim().toLowerCase();
    /* Parent chip (S.cat) matches the item's top-level ancestor 'root' so a parent
       shows every descendant; the sub-chip (S.sub) narrows to the real category id. */
    var list = ITEMS.filter(function (i) {
      if (S.cat !== "all" && String(i.root) !== String(S.cat)) return false;
      if (S.sub !== "root" && String(i.cat) !== String(S.sub)) return false;
      if (!passFilters(i)) return false;
      if (q && (String(i.name) + " " + String(i.desc) + " " + String(i.sub || "")).toLowerCase().indexOf(q) === -1) return false;
      return true;
    });
    if (S.sort === "price_asc") list = list.slice().sort(function (a, b) { return a.price - b.price; });
    else if (S.sort === "price_desc") list = list.slice().sort(function (a, b) { return b.price - a.price; });
    else if (S.sort === "rated") list = list.slice().sort(function (a, b) { return (b.rating || 0) - (a.rating || 0); });
    else if (S.sort === "pop") list = list.slice().sort(function (a, b) { return (b.is_popular ? 1 : 0) - (a.is_popular ? 1 : 0) || (b.rating || 0) - (a.rating || 0); });
    return list;
  }

  /* two-tier nav: reveal the active parent's sub-chip group; hide row on "All" */
  function renderSubChips() {
    var row = $("#subCats"); if (!row) return;
    var groups = $$(".subgroup", row);
    var showRow = false;
    groups.forEach(function (g) {
      var on = S.cat !== "all" && String(g.dataset.parent) === String(S.cat);
      g.hidden = !on;
      if (on) {
        showRow = g.querySelector("[data-sub]") != null;
        $$("[data-sub]", g).forEach(function (b) { b.classList.toggle("is-on", b.dataset.sub === S.sub); });
      }
    });
    row.hidden = !showRow;
  }
  function catName(id) {
    for (var k = 0; k < CATS.length; k++) if (String(CATS[k].id) === String(id)) return CATS[k].name;
    return "";
  }
  var MENU_LABEL = null;
  function updateTitle() {
    var t = $(".m-title"); if (!t) return;
    if (MENU_LABEL == null) MENU_LABEL = t.textContent;
    t.textContent = S.cat === "all" ? MENU_LABEL : (catName(S.cat) || MENU_LABEL);
  }

  function renderGrid() {
    var list = visibleItems();
    var grid = $("#grid"); if (!grid) return;
    var empty = $("#gridEmpty"); if (empty) empty.hidden = list.length > 0;
    var cf = $("#clearFilters"); if (cf) cf.hidden = !(list.length === 0 && filtersActive());
    var fd = $("#filterDot"); if (fd) fd.hidden = !filtersActive();
    /* first popular item in the "all" view becomes the enlarged card */
    var xlId = null;
    if (S.cat === "all" && S.sub === "root" && !S.search && !filtersActive()) {
      var pop = list.filter(function (i) { return (i.is_daily_special || i.is_popular) && !soldOut(i); });
      if (pop.length) xlId = pop[0].id;
    }
    grid.innerHTML = list.map(function (i, idx) { return cardHTML(i, idx, i.id === xlId); }).join("");
    var cards = $$(".card", grid);
    if (io) cards.forEach(function (c) { io.observe(c); });
    else cards.forEach(function (c) { c.classList.add("in"); });
    setTimeout(revealCards, 80);
  }
  function revealCards() {
    var vh = window.innerHeight;
    $$("#grid .card:not(.in)").forEach(function (c) {
      var r = c.getBoundingClientRect();
      if (r.top < vh - 30 && r.bottom > 0) c.classList.add("in");
    });
  }

  /* ── item detail ──────────────────────────────────────── */
  function openItem(id) { itemEvent(id, "view"); setItem(id, true); show("item"); }

  function setItem(id, fresh) {
    var i = item(id); if (!i) return;
    var same = S.item === id;
    S.item = id;
    if (fresh || !same) { S.iQty = 1; S.iVar = 0; S.iExtras = new Set(); S.iMods = {}; }

    var img = $("#plateImg");
    if (FALLBACK) img.onerror = function () { this.onerror = null; this.src = FALLBACK; };
    var imgs = itemImgs(i);
    img.src = imgs[0];

    /* per-item gallery: thumbnail strip swaps the disc image */
    var gal = $("#plateGallery");
    if (gal) {
      if (imgs.length > 1) {
        gal.hidden = false;
        gal.innerHTML = imgs.map(function (g, gi) {
          return '<button class="pg-thumb ' + (gi === 0 ? "is-on" : "") + '" data-gimg="' + esc(g) + '" type="button"><img src="' + esc(g) + '" alt=""' + IMG_ERR + "></button>";
        }).join("");
      } else { gal.hidden = true; gal.innerHTML = ""; }
    }

    var tb = $("#chipTimeBox"), sb = $("#chipServesBox");
    if (i.prep_time) { $("#chipTime").textContent = i.prep_time + " " + tt("min"); tb.hidden = false; } else tb.hidden = true;
    if (i.portion_size) { $("#chipServes").textContent = i.portion_size; sb.hidden = false; } else sb.hidden = true;

    var flag = $("#itemFlag");
    if (soldOut(i)) { flag.hidden = false; flag.textContent = tt("sold_out"); }
    else if (i.is_daily_special) { flag.hidden = false; flag.textContent = tt("daily_special"); }
    else if (i.is_popular) { flag.hidden = false; flag.textContent = "★ " + tt("popular"); }
    else flag.hidden = true;

    $("#itemTitle").textContent = i.name;
    var stars = $("#itemStars");
    // Bare count beside the star AVERAGE: i.reviews is rating_count (all star
    // taps), the correct denominator for the average — but labelling it
    // "reviews" claimed that many written reviews. No rating → no star meta.
    if (i.rating) { stars.innerHTML = starsHTML(i.rating) + '<span class="rv">(' + (i.reviews || 0) + ")</span>"; stars.style.display = ""; }
    else { stars.innerHTML = ""; stars.style.display = "none"; }
    $("#itemDesc").textContent = i.desc || "";
    $("#itemFav").classList.toggle("is-on", favs.has(String(id)));

    var tags = [];
    if (i.is_vegan) tags.push('<span class="tag">' + ICONS.leaf + tt("tag_vegan") + "</span>");
    else if (i.type === "veg") tags.push('<span class="tag">' + ICONS.leaf + tt("tag_veg") + "</span>");
    if (i.is_halal) tags.push('<span class="tag">' + ICONS.moon + tt("tag_halal") + "</span>");
    if (i.is_gluten_free) tags.push('<span class="tag">' + ICONS.gf + tt("tag_gf") + "</span>");
    if (i.spice_level > 0) tags.push('<span class="tag hot">' + "🌶".repeat(Math.min(3, i.spice_level)) + "</span>");
    if (i.calories) tags.push('<span class="tag kcal">' + ICONS.flame + i.calories + " " + tt("kcal") + "</span>");
    (i.allergies || []).forEach(function (a) { tags.push('<span class="tag">' + esc(a.title) + "</span>"); });
    $("#itemTags").innerHTML = tags.join("");

    /* variants (absolute price → shown as a size segmented control) */
    var base = Number(i.price) || 0;
    $("#itemVars").innerHTML = (i.variants && i.variants.length) ? (
      '<span class="cap">' + esc(tt("size")) + "</span>" +
      '<div class="seg" id="varSeg">' + i.variants.map(function (v, vi) {
        var delta = (Number(v.price) || 0) - base;
        return '<button data-var="' + vi + '" class="' + (S.iVar === vi ? "is-on" : "") + '">' + esc(v.label) + (delta ? " · " + (delta > 0 ? "+" : "") + money(delta) : "") + "</button>";
      }).join("") + "</div>"
    ) : "";

    /* extras (additive) + global modifier groups */
    var ex = "";
    if (i.extras && i.extras.length) {
      ex += '<div class="grp"><span class="cap">' + esc(tt("extras")) + " — " + esc(tt("optional")) + "</span>" +
        '<div class="x-list">' + i.extras.map(function (x, xi) {
          return '<button class="xrow ' + (S.iExtras.has(xi) ? "is-on" : "") + '" data-extra="' + xi + '">' +
            '<span class="x-ctl">' + ICONS.check + "</span>" +
            '<span class="x-name">' + esc(x.title) + "</span>" +
            '<span class="x-price">+' + money(x.price) + "</span></button>";
        }).join("") + "</div></div>";
    }
    (i.optionGroups || []).forEach(function (g) {
      var single = g.max === 1;
      var sel = S.iMods[g.id] || (S.iMods[g.id] = new Set());
      ex += '<div class="grp"><span class="cap">' + esc(g.title) + (g.required ? " — " + esc(tt("required")) : " — " + esc(tt("optional"))) + "</span>" +
        '<div class="x-list">' + g.options.map(function (o) {
          return '<button class="xrow ' + (sel.has(o.i) ? "is-on" : "") + '" data-mod="' + g.id + '" data-opt="' + o.i + '" data-single="' + (single ? 1 : 0) + '">' +
            '<span class="x-ctl' + (single ? " radio" : "") + '">' + ICONS.check + "</span>" +
            '<span class="x-name">' + esc(o.label) + "</span>" +
            '<span class="x-price">' + (o.price ? "+" + money(o.price) : "") + "</span></button>";
        }).join("") + "</div></div>";
    });
    $("#itemExtras").innerHTML = ex;

    /* product reviews — real approved MenuReview rows shipped per item; the
       shared _partials/item-reviews layer injects the histogram + write button
       into this .p-reviews section. */
    var rv = $("#itemReviews");
    if (rv) {
      var list = i.reviewList || [];
      var head = '<div class="rv-head"><span class="cap">' + esc(tt("reviews")) + "</span>" + (list.length ? '<span class="rv-count">' + (i.reviewTotal || list.length) + "</span>" : "") + "</div>";
      rv.innerHTML = list.length
        ? head + list.map(function (review) {
            var stars = Math.max(1, Math.min(5, Number(review.stars) || 0));
            return '<article class="rv-card"><b>' + esc(review.name) + '</b><span class="review-stars">' +
              new Array(stars + 1).join("★") + '<span class="review-stars-dim">' + new Array(5 - stars + 1).join("★") + "</span></span>" +
              (review.body ? "<p>" + esc(review.body) + "</p>" : "") + "</article>";
          }).join("")
        : head + '<p class="rv-empty">' + esc(tt("no_reviews")) + "</p>";
    }

    updateAddBtn();
  }

  function collectMods() {
    var i = item(S.item), out = [];
    (i.optionGroups || []).forEach(function (g) {
      var sel = S.iMods[g.id];
      if (!sel) return;
      sel.forEach(function (oi) {
        var o = g.options[oi];
        if (o) out.push({ groupId: g.id, optIndex: oi, label: o.label, price: o.price });
      });
    });
    return out;
  }
  function collectExtras() {
    var i = item(S.item), out = [];
    S.iExtras.forEach(function (xi) { var x = i.extras[xi]; if (x) out.push({ id: x.id, title: x.title, price: x.price }); });
    return out;
  }
  function itemUnit() {
    var i = item(S.item); if (!i) return 0;
    var p = Number(i.price) || 0;
    if (i.variants && i.variants.length && i.variants[S.iVar]) p = Number(i.variants[S.iVar].price) || 0;
    collectMods().forEach(function (m) { p += Number(m.price) || 0; });
    collectExtras().forEach(function (e) { p += Number(e.price) || 0; });
    return p;
  }
  function updateAddBtn() {
    if (!S.item) return;
    $("#iQtyVal").textContent = S.iQty;
    var ap = $("#addPrice"); if (ap) ap.textContent = money(itemUnit() * S.iQty);
  }

  /* ── cart core ────────────────────────────────────────── */
  function addToCart(id, variantIndex, extras, mods, qty, fromEl) {
    var i = item(id); if (!i) return;
    if (soldOut(i)) { toast(tt("sold_out")); return; }
    var vId = (i.variants && i.variants.length && i.variants[variantIndex]) ? i.variants[variantIndex].id : 0;
    var key = id + "|" + vId + "|" + extras.map(function (e) { return e.id; }).sort().join(",") + "|" + mods.map(function (m) { return m.groupId + ":" + m.optIndex; }).sort().join(",");
    var line = cart.filter(function (l) { return l.key === key && !l.reward; })[0];
    if (line) line.qty += qty;
    else cart.push({ key: key, id: id, qty: qty, variantId: vId, extras: extras, mods: mods, note: "" });
    persistCart();
    S.railOpen = true;
    syncBadge(true); renderRail(); syncRail();
    itemEvent(id, "add_to_cart");
    /* Full-sentence key: the item name is a :item placeholder, so the verb never
       has to agree with the dish's gender (fr/pt/ar/hi/ur/ru all mis-agreed when
       this was `name + " — " + fragment`). toast() escapes, so pass the raw name —
       esc() here would double-escape ("Fish & Chips" → "Fish &amp; Chips"). */
    toast(tt("added_item").replace(":item", function () { return i.name; }));
    if (fromEl) flyToRail(fromEl);
  }
  function removeLine(key) {
    cart = cart.filter(function (l) { return l.key !== key; });
    persistCart();
    syncBadge(false); renderRail(); syncRail();
    if (active === "cart") renderCart();
  }
  function syncBadge(bump) {
    var n = cartCount() + comboCount(), b = $("#cartCount");
    if (b) { b.hidden = n === 0; b.textContent = n; }
    var nc = $("#navCartN"); if (nc) nc.textContent = n;
    if (bump && n && b) { b.classList.remove("bump"); void b.offsetWidth; b.classList.add("bump"); }
    /* item footer: a direct jump to the cart, shown once anything is in it */
    var pj = $("#pCartJump"); if (pj) { pj.hidden = n === 0; var pjc = $("#pCartJumpCount"); if (pjc) pjc.textContent = n; }
  }

  /* COMBOS-V2 — read-only "Combos" group appended to the cart list and the
     checkout summary. Tapping a row reopens the combo sheet's basket view,
     which is where quantities are edited. */
  function comboGroupHTML(compact) {
    var lines = comboLines();
    if (!lines.length) return "";
    var h = '<div class="pl-combo-group" data-combo-open style="border-top:1px dashed var(--hair-2,#8883);padding-top:8px;margin-top:8px;cursor:pointer">';
    lines.forEach(function (L) {
      /* plate's .ck-row grid leads with a 40px image column the combo rows don't
         have; the .ck-row--combo modifier drops it to name/qty/price. */
      h += '<div class="ck-row ck-row--combo">' +
        '<div class="ck-mid"><b>' + esc(L.name) + "</b>" +
        (L.parts.length ? "<small>" + esc(L.parts.join(" · ")) + "</small>" : "") + "</div>" +
        '<span class="ck-qty">×' + L.qty + "</span>" +
        '<span class="ck-price">' + money(L.price) + "</span></div>";
    });
    if (!compact) h += '<small style="opacity:.65">' + esc(tt("edit")) + " ›</small>";
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
      renderCartTotals();
      if (active === "cart") renderCart();
      if (active === "checkout") renderCheckout();
    } catch (e) {}
  });

  function flyToRail(fromEl) {
    if (matchMedia("(prefers-reduced-motion:reduce)").matches) return;
    var card = fromEl.closest(".card");
    var img = (card && card.querySelector("img")) || $("#plateImg");
    var rail = $("#rail");
    var target = (rail && rail.classList.contains("is-open")) ? $("#railThumbs") : $("#cartBtn");
    if (!img || !target) return;
    var a = img.getBoundingClientRect(), b = target.getBoundingClientRect();
    var lay = $("#flyLayer"), lr = lay.getBoundingClientRect();
    var ghost = document.createElement("img");
    ghost.src = img.src; ghost.className = "fly-img";
    ghost.style.left = (a.left - lr.left + a.width / 2 - 28) + "px";
    ghost.style.top = (a.top - lr.top + a.height / 2 - 28) + "px";
    lay.appendChild(ghost);
    requestAnimationFrame(function () {
      ghost.style.left = (b.left - lr.left + b.width / 2 - 28) + "px";
      ghost.style.top = (b.top - lr.top + Math.min(b.height / 2, 40) - 28) + "px";
      ghost.style.transform = "scale(.2)"; ghost.style.opacity = ".25";
    });
    setTimeout(function () { ghost.remove(); }, 750);
  }

  /* ── cart rail ────────────────────────────────────────── */
  function renderRail() {
    var wrap = $("#railThumbs"); if (!wrap) return;
    var lines = cart.slice(0, 5);
    wrap.innerHTML = lines.map(function (l, li) {
      var i = item(l.id) || {};
      return '<div class="rthumb" data-rline="' + l.key + '" style="animation-delay:' + (li * 40) + 'ms">' +
        '<img src="' + esc(i.img || FALLBACK) + '" alt=""' + IMG_ERR + ">" +
        '<button class="rt-x" data-rrm="' + l.key + '" aria-label="' + esc(T.remove || "Remove") + '">' + ICONS.x + "</button>" +
        (l.qty > 1 ? '<i class="rt-q">×' + l.qty + "</i>" : "") + "</div>";
    }).join("") + (cart.length > 5 ? '<span class="rt-more">+' + (cart.length - 5) + "</span>" : "");
    var rt = $("#railTotal"); if (rt) rt.textContent = money(cartTotals().total);
  }
  function syncRail() {
    var rail = $("#rail"); if (!rail) return;
    var shouldOpen = cart.length > 0 && S.railOpen && (active === "home" || active === "item");
    rail.classList.toggle("is-open", shouldOpen);
    root.classList.toggle("rail-open", shouldOpen);
  }

  /* ── cart screen ──────────────────────────────────────── */
  function renderCart() {
    var has = cart.length > 0 || comboCount() > 0;
    var ce = $("#cartEmpty"); if (ce) ce.hidden = has;
    var ct = $("#cartTools"); if (ct) ct.style.display = has ? "" : "none";
    var cta = $("#cartCta"); if (cta) cta.hidden = !has;
    /* clear-cart only empties the ITEM cart; combos are cleared in their sheet */
    var clr = $("#clearCart"); if (clr) clr.style.visibility = cart.length ? "" : "hidden";
    $("#cartLines").innerHTML = comboGroupHTML(false) + cart.map(function (l, li) {
      var i = item(l.id) || {};
      var sum = lineSummary(l);
      var unit = unitPrice(l);
      /* Happy-hour strike-through: original_price is the pre-schedule BASE
         price, so the pre-discount unit is unit + (original − current base). */
      var wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
        ? unit + (Number(i.original_price) - Number(i.price)) : 0;
      return '<div class="cline" data-key="' + l.key + '" style="--d:' + (li * 45) + 'ms">' +
        '<span class="cl-img" data-open="' + i.id + '"><img src="' + esc(i.img || FALLBACK) + '" alt=""' + IMG_ERR + "></span>" +
        '<div class="cl-mid"><b class="cl-name" data-open="' + i.id + '">' + esc(i.name || "") + (l.reward ? '<span class="rwd">★</span>' : "") + "</b>" +
        (sum ? '<small class="cl-opts">' + esc(sum) + "</small>" : "") +
        '<span class="cl-price">' + money(unit * l.qty) + "</span>" +
        (wasUnit ? '<del class="cl-was">' + money(wasUnit * l.qty) + "</del>" : "") +
        (l.qty > 1 ? '<small class="cl-unit">' + l.qty + " × " + money(unit) + "</small>" : "") +
        '<button class="cl-note-t" data-notet>' + (l.note ? "✎ " + esc(l.note) : esc(tt("add_note"))) + "</button>" +
        '<input class="cl-note-in" data-note-input value="' + esc(l.note || "") + '" hidden></div>' +
        '<div class="cl-side">' +
        '<button class="cl-rm" data-rm aria-label="' + esc(T.remove || "Remove") + '"><svg class="ic" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button>' +
        '<div class="qty mini"><button class="qty-btn" data-cq="-1" type="button" aria-label="' + esc(T.decrease_qty || "Decrease quantity") + '"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>' +
        "<output>" + l.qty + "</output>" +
        '<button class="qty-btn" data-cq="1" type="button" aria-label="' + esc(T.increase_qty || "Increase quantity") + '"><svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg></button></div>' +
        "</div></div>";
    }).join("");
    renderOtFields(); renderCartTotals();
  }

  function renderOtFields() {
    var f = $("#otFields"); if (!f) return;
    var wb = $("#waiterBtn"); if (wb) wb.hidden = S.orderType !== "on-table";
    if (S.orderType === "on-table") {
      if (CFG.forceTable) {
        f.innerHTML = '<input id="tableInput" value="' + esc(S.table) + '" readonly>' +
          '<p class="ot-hint">' + ICONS.check.replace("<svg", '<svg class="ic"') + "<span>" + esc(tt("dinein_hint")) + "</span></p>";
      } else {
        f.innerHTML = '<input id="tableInput" type="number" inputmode="numeric" placeholder="' + esc(tt("table")) + '" value="' + esc(S.table) + '">' +
          '<p class="ot-hint">' + ICONS.check.replace("<svg", '<svg class="ic"') + "<span>" + esc(tt("dinein_hint")) + "</span></p>";
      }
    } else if (S.orderType === "room_service") {
      /* Room service is fulfilled in the guest's room -- like on-table it needs
         neither an address nor a phone; the room rides along on the `table`
         field (the scanned room QR key). */
      f.innerHTML = "";
    } else if (S.orderType === "delivery") {
      var saved = CFG.savedAddresses || [];
      var savedHTML = saved.length ? '<div class="saved-addrs" id="savedAddrs">' + saved.map(function (a) {
        var on = String(a.addr) === String(S.addr) ? " is-on" : "";
        return '<button class="saved-addr' + on + '" type="button" data-saved-addr data-saddr="' + esc(a.addr) +
          '" data-lat="' + esc(a.lat == null ? "" : a.lat) + '" data-lng="' + esc(a.lng == null ? "" : a.lng) + '">' +
          ICONS.pin.replace("<svg", '<svg class="ic"') +
          '<span class="sa-text"><b>' + esc(a.label) + "</b><small>" + esc(a.addr) + "</small></span></button>";
      }).join("") + "</div>"
      : (CFG.isLoggedIn ? "" : '<div class="saved-addrs" id="savedAddrs" hidden></div>');   // guest chips fill this (R6)
      f.innerHTML = savedHTML +
        '<div class="addr-field" id="addrField">' +
          '<input id="addrInput" data-addr placeholder="' + esc(tt("addr_ph")) + '" value="' + esc(S.addr) + '" autocomplete="off" autocorrect="off" spellcheck="false">' +
          '<input type="hidden" id="addrLat" value="' + esc(S.lat || "") + '">' +
          '<input type="hidden" id="addrLng" value="' + esc(S.lng || "") + '">' +
          '<button class="useloc-btn" id="useLocBtn" type="button" data-useloc title="' + esc(tt("use_location")) + '" aria-label="' + esc(tt("use_location")) + '">' +
            '<svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>' +
          "</button>" +
          '<div class="addr-suggest" id="addrSuggest" hidden></div>' +
        "</div>" +
        '<p class="geo-attrib" id="addrAttrib" hidden></p>' +
        '<p class="ot-hint">' + ICONS.pin.replace("<svg", '<svg class="ic"') + "<span>" + esc(tt("delivery_hint")) + "</span></p>";
      renderRecentAddrs();   // R6: guest saved-address chips into #savedAddrs
    } else {
      f.innerHTML = '<p class="ot-hint">' + ICONS.check.replace("<svg", '<svg class="ic"') + "<span>" + esc(tt("pickup_hint")) + "</span></p>";
    }
  }

  function totalsHTML(TT) {
    return '<div class="tr"><span>' + esc(tt("subtotal")) + "</span><b>" + money(TT.sub) + "</b></div>" +
      (TT.disc ? '<div class="tr disc"><span>' + esc(tt("discount")) + (S.coupon ? " (" + esc(S.coupon.code) + ")" : "") + "</span><b>−" + money(TT.disc) + "</b></div>" : "") +
      (TT.tax ? '<div class="tr"><span>' + esc(CFG.taxLabel) + "</span><b>" + money(TT.tax) + "</b></div>" : "") +
      (TT.del ? '<div class="tr"><span>' + esc(tt("delivery_fee")) + "</span><b>" + money(TT.del) + "</b></div>" : "") +
      (TT.tray ? '<div class="tr"><span>' + esc(tt("tray_fee")) + "</span><b>" + money(TT.tray) + "</b></div>" : "") +
      (TT.tip ? '<div class="tr"><span>' + esc(tt("tip_label")) + " (" + S.tipPct + "%)</span><b>" + money(TT.tip) + "</b></div>" : "") +
      '<div class="tr grand"><span>' + esc(tt("total")) + "</span><b>" + money(TT.total) + "</b></div>";
  }
  function renderCartTotals() {
    var TT = cartTotals();
    $("#cartTotals").innerHTML = totalsHTML(TT);
    var cc = $("#cartTotalCta"); if (cc) cc.textContent = money(TT.total);
    var mo = $("#minOrderMsg");
    if (mo) {
      if (CFG.minOrderValue > 0 && TT.sub < CFG.minOrderValue) { mo.hidden = false; mo.textContent = tt("min_order") + " " + money(CFG.minOrderValue); }
      else mo.hidden = true;
    }
    renderRail();
  }

  /* ── checkout ─────────────────────────────────────────── */
  function renderCheckout() {
    var pw = $("#ckPhoneWrap"); if (pw) pw.style.display = (S.orderType === "on-table" || S.orderType === "room_service") ? "none" : "";
    /* Order summary — what the customer is about to pay for, tap Edit → cart. */
    var ckI = $("#ckItems");
    if (ckI) {
      ckI.hidden = cart.length === 0 && comboCount() === 0;
      ckI.innerHTML = '<div class="ck-head"><h3>' + esc(tt("your_order")) + '</h3><button class="ck-edit" id="ckEdit" type="button">' + esc(tt("edit")) + "</button></div>" +
        cart.map(function (l) {
          var i = item(l.id); if (!i) return "";
          var unit = unitPrice(l);
          /* Happy-hour strike-through: original_price is the pre-schedule BASE
             price, so the pre-discount unit is unit + (original − current base). */
          var wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
            ? unit + (Number(i.original_price) - Number(i.price)) : 0;
          var sum = lineSummary(l);
          return '<div class="ck-row">' +
            '<img src="' + esc(i.img || FALLBACK) + '" alt=""' + IMG_ERR + ">" +
            '<div class="ck-mid"><b>' + esc(i.name || "") + "</b>" + (sum ? "<small>" + esc(sum) + "</small>" : "") + "</div>" +
            '<span class="ck-qty">×' + l.qty + "</span>" +
            '<span class="ck-price">' + money(unit * l.qty) + (wasUnit ? "<del>" + money(wasUnit * l.qty) + "</del>" : "") + "</span></div>";
        }).join("") + comboGroupHTML(true);
      var ckEd = $("#ckEdit"); if (ckEd) ckEd.onclick = function () { show("cart"); };
    }
    $("#ckTotals").innerHTML = totalsHTML(cartTotals());
    var cc = $("#ckTotalCta"); if (cc) cc.textContent = money(cartTotals().total);
    $$("#payList .pay").forEach(function (b) { b.classList.toggle("is-on", b.dataset.pay === S.pay); });
    $$("#tipRow button").forEach(function (b) { b.classList.toggle("is-on", Number(b.dataset.tip) === S.tipPct); });
  }

  function placeOrder() {
    var err = $("#ckError"); err.hidden = true;
    if (!S.orderType) { err.textContent = tt("select_type"); err.hidden = false; return; }
    if (!cart.length && !comboCount()) return; /* combos-only orders are legal */
    var TT = cartTotals();
    if (CFG.minOrderValue > 0 && TT.sub < CFG.minOrderValue) { err.textContent = tt("min_order") + " " + money(CFG.minOrderValue); err.hidden = false; return; }
    var name = ($("#ckName") ? $("#ckName").value.trim() : "") || CFG.customerName || "";
    if (!name) { err.textContent = tt("fill_contact"); err.hidden = false; return; }
    if (S.orderType === "delivery" && !S.addr.trim()) { err.textContent = tt("fill_addr"); err.hidden = false; return; }
    if (CFG.authMode === "required" && !CFG.isLoggedIn) { sheet("authSheet"); return; }

    var payload = orderLines();

    var fd = new FormData();
    fd.append("_token", CFG.csrf);
    fd.append("items", JSON.stringify(payload));
    fd.append("name", name);
    fd.append("ordering-type", S.orderType);
    if (S.orderType === "on-table" || S.orderType === "room_service") fd.append("table", S.table || "");
    else fd.append("phone-number", ($("#ckPhone") ? $("#ckPhone").value : "") || "");
    if (S.orderType === "delivery") fd.append("address", S.addr.trim());
    fd.append("pay_via", S.pay);
    fd.append("tip_amount", String(TT.tip || 0));
    fd.append("message", ($("#orderNote") ? $("#orderNote").value : "") || "");
    var when = $("#whenTime");
    if (S.when === "later" && when && when.value) fd.append("scheduled_at", when.value);
    if (S.coupon) fd.append("coupon_code", S.coupon.code);
    if (S.walletUse) fd.append("use_wallet", "1");
    var bid = new URLSearchParams(window.location.search).get("branch_id");
    if (bid) fd.append("branch_id", bid);
    var consent = $("#consentCb"), capEmail = $("#captureEmail");
    if (consent && consent.checked) { fd.append("marketing_consent", "1"); if (capEmail && capEmail.value) fd.append("capture_email", capEmail.value); }
    var waOpt = $("#waOptin"); if (waOpt && waOpt.checked) fd.append("whatsapp_optin", "1");

    var btn = $("#placeBtn");
    btn.disabled = true;
    btn.querySelector(".spinner").hidden = false;
    btn.querySelector(".po-label").textContent = "…";

    fetch(CFG.endpoints.submitOrder, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false;
        btn.querySelector(".spinner").hidden = true;
        btn.querySelector(".po-label").textContent = PLACE_LABEL;
        if (res && res.success) {
          if (S.orderType === "delivery") rememberAddress(S.addr, S.lat, S.lng);   // R6: remember guest delivery address
          rememberOrder(res, money(TT.total));                                     // R5: remember guest order for history
          cart = []; persistCart(); syncBadge(false); renderRail(); syncRail();
          S.coupon = null; S.tipPct = 0; S.walletUse = false;
          if (res.message) { window.location.href = res.message; return; } /* online-payment redirect */
          var code = res.order_id ? "#" + res.order_id : "";
          var cc = $("#confCode");
          cc.textContent = code;
          cc.hidden = !code;   // no invented number when the endpoint returns none
          /* ETA comes from the server's own estimator (heuristic → the owner's
             prep_eta_static). The old line invented "now + 20/40 min" and contradicted
             the store's configured 25–35 window; with no estimate we show nothing. */
          $("#confEta").textContent = CFG.prepEtaLine || "";
          var track = $("#confTrack"); if (track) track.href = res.track_url || "#";
          show("confirmed"); stack.length = 0;
          if (res.whatsapp_url) mbWhatsAppHandoff(res.whatsapp_url, res.whatsapp_handoff);
          if (res.track_url) setTimeout(function () { window.location.href = res.track_url; }, 3400);
        } else {
          /* textContent, not innerHTML: res.message is a server string that can carry
             back user-supplied values (coupon code, address, name), so injecting it as
             HTML is an XSS sink. Nothing here needs markup. */
          err.textContent = (res && res.message) || tt("network_err"); err.hidden = false;
        }
      })
      .catch(function () {
        btn.disabled = false; btn.querySelector(".spinner").hidden = true;
        err.textContent = tt("network_err"); err.hidden = false;
      });
  }

  /* ── guest order history (localStorage) ───────────────── R5
     Logged-in customers get server-backed "My Orders"; guests only ever had a
     track link that vanished on navigation. Remember the last few successful
     orders locally and surface them in the nav sheet. */
  var PLATE_ORDERS_KEY = "plate_recent_orders_" + slug;
  function readRecentOrders() {
    try { return (JSON.parse(localStorage.getItem(PLATE_ORDERS_KEY) || "[]") || []).filter(function (o) { return o && o.track_url; }); }
    catch (e) { return []; }
  }
  function rememberOrder(res, totalStr) {
    if (!res || !res.track_url) return;
    var l = readRecentOrders().filter(function (o) { return o.track_url !== res.track_url; });
    l.unshift({ id: res.order_id || "", track_url: res.track_url, total: res.total || totalStr || "", placed_at: Date.now() });
    try { localStorage.setItem(PLATE_ORDERS_KEY, JSON.stringify(l.slice(0, 10))); } catch (e) {}
    renderRecentOrders();
  }
  function renderRecentOrders() {
    var box = $("#plateRecentOrders"); if (!box) return;
    var l = readRecentOrders();
    if (!l.length) { box.hidden = true; box.innerHTML = ""; return; }
    box.hidden = false;
    box.innerHTML = '<h3 class="cap ro-head">' + esc(tt("recent_orders")) + "</h3>" + l.map(function (o) {
      var code = o.id ? "#" + String(o.id).replace(/^#/, "") : tt("order_now");
      return '<a class="ro-row" href="' + esc(o.track_url) + '">' +
        '<span class="ro-mid"><b>' + esc(code) + "</b>" + (o.total ? "<small>" + esc(o.total) + "</small>" : "") + "</span>" +
        '<svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="M4 12h15M14 6l6 6-6 6"/></svg></a>';
    }).join("");
  }

  /* ── guest multi-address (localStorage) ───────────────── R6
     The server ships $customerAddresses chips for logged-in users only. Give
     guests the same convenience from their own past deliveries. The storage key
     is computed INLINE (never a hoisted var) so it is defined the first time
     renderRecentAddrs() runs at load. */
  function addrKey() { return "plate_recent_addrs_" + slug; }
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
  }
  /* Guests only — never fight the server address book. Renders into the same
     #savedAddrs slot the delivery step already lays out for logged-in chips,
     reusing the [data-saved-addr] click handler that fills addr + hidden lat/lng. */
  function renderRecentAddrs() {
    if (CFG.isLoggedIn) return;
    var saved = $("#savedAddrs"); if (!saved) return;
    var l = readRecentAddrs();
    if (!l.length) { saved.hidden = true; saved.innerHTML = ""; return; }
    saved.hidden = false;
    saved.innerHTML = l.map(function (a) {
      var on = String(a.addr) === String(S.addr) ? " is-on" : "";
      return '<button class="saved-addr' + on + '" type="button" data-saved-addr data-saddr="' + esc(a.addr) +
        '" data-lat="' + esc(a.lat) + '" data-lng="' + esc(a.lng) + '">' +
        ICONS.pin.replace("<svg", '<svg class="ic"') +
        '<span class="sa-text"><b>' + esc(a.addr) + "</b></span></button>";
    }).join("");
  }

  /* ── favorites toggle helper ──────────────────────────── */
  function toggleFav(id, el) {
    var on = !favs.has(String(id));
    if (on) favs.add(String(id)); else favs.delete(String(id));
    localStorage.setItem(favKey, JSON.stringify(Array.from(favs)));
    if (el) el.classList.toggle("is-on", on);
    var itemFav = $("#itemFav"); if (S.item === id && itemFav) itemFav.classList.toggle("is-on", on);
    toast(tt(on ? "fav_on" : "fav_off"));
  }

  /* ── search ───────────────────────────────────────────── */
  function openSearch() { var p = $("#searchPanel"); p.hidden = false; requestAnimationFrame(function () { p.classList.add("is-open"); }); $("#searchInput").focus(); renderSearch(); }
  /* Closing the overlay MUST drop the query. visibleItems() also reads S.search, so a
     leftover term kept filtering the home grid after the panel was gone — search for
     something with no matches, close, and the whole menu was an empty "Nothing matches"
     with the Clear-filters escape hatch hidden (it only shows for dietary filters). */
  function closeSearch() {
    var p = $("#searchPanel");
    p.classList.remove("is-open");
    setTimeout(function () { p.hidden = true; }, 320);
    if (S.search) {
      S.search = "";
      var si = $("#searchInput"); if (si) si.value = "";
      renderGrid();
    }
  }
  function renderSearch() {
    var q = S.search.trim().toLowerCase();
    var list = q ? ITEMS.filter(function (i) { return (String(i.name) + " " + String(i.desc)).toLowerCase().indexOf(q) !== -1; }) : ITEMS;
    $("#searchEmpty").hidden = list.length > 0;
    $("#searchResults").innerHTML = list.map(function (i) {
      return '<button class="srow" data-sopen="' + i.id + '"><img src="' + esc(i.img) + '" alt=""' + IMG_ERR + ">" +
        '<span class="sr-mid"><b>' + esc(i.name) + "</b><small>" + (i.prep_time ? i.prep_time + " " + tt("min") : "") + "</small></span>" +
        '<span class="sr-price">' + money(i.price) + "</span></button>";
    }).join("");
  }

  /* ── sort sheet ───────────────────────────────────────── */
  var SORTS = ["featured", "pop", "price_asc", "price_desc", "rated"];
  var SORT_KEY = { featured: "sort_featured", pop: "sort_pop", price_asc: "sort_price_asc", price_desc: "sort_price_desc", rated: "sort_rated" };
  function renderSortSheet() {
    $("#sortList").innerHTML = SORTS.map(function (s) {
      return '<button class="nav-row ' + (S.sort === s ? "is-on" : "") + '" data-sort="' + s + '"><span class="cap">' + esc(tt(SORT_KEY[s])) + "</span>" + (S.sort === s ? '<span class="dot-on"></span>' : "") + "</button>";
    }).join("");
  }

  /* ── dietary filter sheet ─────────────────────────────── */
  function syncFilterCount() {
    var n = visibleItems().length;
    var fc = $("#filterCount"); if (fc) fc.textContent = n;
    var fd = $("#filterDot"); if (fd) fd.hidden = !filtersActive();
  }
  function resetFilters() {
    S.filters = { diets: {}, allergens: {}, spice: 0 };
    $$("#dietRow [data-diet], #spiceRow [data-spice], #allergenRow [data-al]").forEach(function (b) { b.classList.remove("is-on"); });
    syncFilterCount();
  }

  /* ── review stars ─────────────────────────────────────── */
  function buildStars(wrapId) {
    var wrap = $("#" + wrapId); if (!wrap) return;
    wrap.innerHTML = Array.from({ length: 5 }, function (_, i) { return '<button data-star="' + (i + 1) + '" aria-label="' + (i + 1) + ' stars">' + STAR + "</button>"; }).join("");
  }

  /* ── loyalty ──────────────────────────────────────────── */
  function loadLoyalty() {
    if (!CFG.endpoints.loyaltyBalance) return;
    fetch(CFG.endpoints.loyaltyBalance).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || !d.active) return;
      var bal = d.balance || 0;
      var pv = $("#ptsVal"); if (pv) pv.textContent = bal;
      var np = $("#navPts"); if (np) np.textContent = bal + (CFG.loyaltyType === "stamps" ? "" : " pts");
      var big = $("#ptsBig"); if (big) big.textContent = bal;
      var thr = CFG.loyaltyThreshold || 0;
      var bar = $("#ptsBar"); if (bar) bar.style.width = Math.min(100, thr ? (bal / thr * 100) : (d.progress_pct || 0)) + "%";
      var next = $("#ptsNext");
      if (next) next.textContent = d.ready_to_redeem ? tt("reward_ready") : (thr && bal < thr ? (thr - bal) + " " + tt("to_reward") : "");
      var rb = $("#redeemBtn"); if (rb) rb.disabled = !d.ready_to_redeem;
    }).catch(function () {});
  }

  /* ── auth ─────────────────────────────────────────────── */
  function authPost(url, body, errEl) {
    fetch(url, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf, "X-Requested-With": "XMLHttpRequest" }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); })
      .then(function (res) { if (res && res.success) { window.location.reload(); } else if (errEl) { errEl.textContent = (res && res.message) || tt("coupon_bad"); errEl.hidden = false; } })
      .catch(function () { if (errEl) { errEl.textContent = tt("network_err"); errEl.hidden = false; } });
  }

  /* ── appearance (auto / light / dark) ─────────────────── */
  /* `auto` leaves the palette to prefers-color-scheme; an explicit choice stamps
     data-mode on the theme root and outranks the media query. Persisted per store so
     it survives the item → cart → checkout → track page hops. */
  var MODE_KEY = "plate-mode-" + slug;
  function applyMode(m) {
    m = (m === "dark" || m === "light") ? m : "auto";
    root.setAttribute("data-mode", m);
    $$("#modeSeg button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.mode === m); });
    return m;
  }
  function setMode(m) { m = applyMode(m); try { localStorage.setItem(MODE_KEY, m); } catch (e) {} }

  /* topbar quick toggle (Lane B): flip the EFFECTIVE mode (explicit data-mode,
     else the OS preference) into an explicit choice through setMode, which
     persists it and keeps the Settings Auto/Light/Dark segment in sync. */
  (function () {
    var b = document.getElementById("plateThemeToggle");
    if (!b) return;
    b.addEventListener("click", function () {
      var m = root.getAttribute("data-mode");
      var isDark = (m === "dark" || m === "light") ? m === "dark"
        : !!(window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches);
      setMode(isDark ? "light" : "dark");
      b.setAttribute("aria-pressed", String(!isDark));
    });
  })();

  /* ── currency / language ──────────────────────────────── */
  function setCookie(k, v) { document.cookie = k + "=" + encodeURIComponent(v) + ";path=/;max-age=31536000"; }
  function rerenderMoney() { renderGrid(); if (S.item) setItem(S.item); if (cart.length || comboCount()) { renderRail(); if (active === "cart") renderCart(); if (active === "checkout") renderCheckout(); } var sp = $("#searchPanel"); if (sp && !sp.hidden) renderSearch(); }

  /* ══════════ delegated clicks ══════════ */
  root.addEventListener("click", function (e) {
    var Tg = e.target;
    function c(s) { return Tg.closest ? Tg.closest(s) : null; }

    /* Same-page ?item= deeplink → open the product in place, no reload (R7). */
    var iLink = c('a[href*="item="]');
    if (iLink) {
      try {
        var iu = new URL(iLink.getAttribute("href"), location.href);
        if (iu.pathname === location.pathname) {
          var iid = iu.searchParams.get("item");
          if (iid && item(iid)) { e.preventDefault(); openItem(iid); return; }
        }
      } catch (e0) {}
    }

    var dk = c("[data-dock]");
    if (dk) {
      var dd = dk.dataset.dock;
      if (dd === "home") { show("home"); window.scrollTo({ top: 0, behavior: "smooth" }); return; }
      if (dd === "menu") { if (active !== "home") show("home"); var mg = document.querySelector('[data-mb-grid-lead]') || $("#grid"); if (mg) mg.scrollIntoView({ behavior: "smooth", block: "start" }); return; }
      if (dd === "search") { openSearch(); return; }
      if (dd === "cart") { show("cart"); return; }
      if (dd === "profile") { var mb = $("#menuBtn"); if (mb) mb.click(); return; }
      if (dd === "favs") {
        var fl = $("#favList"), fe = $("#favEmpty");
        var ids = Array.from(favs).filter(function (x) { return item(x); });
        if (!ids.length) { if (fl) fl.innerHTML = ""; if (fe) fe.style.display = ""; }
        else {
          if (fe) fe.style.display = "none";
          if (fl) fl.innerHTML = ids.map(function (x) {
            var it = item(x);
            return '<button type="button" class="pl-fav-row" data-open="' + it.id + '">' +
              '<img class="pl-fav-img" src="' + esc(it.img) + '" alt=""' + IMG_ERR + '>' +
              '<span class="pl-fav-body"><span class="pl-fav-name">' + esc(it.name) + '</span>' +
              '<span class="pl-fav-price">' + money(it.price) + '</span></span></button>';
          }).join("");
        }
        sheet("favSheet"); return;
      }
    }
    var favrow = c(".pl-fav-row"); if (favrow) { sheet("favSheet", false); openItem(favrow.dataset.open); return; }

    var fav = c("[data-fav]"); if (fav) { e.stopPropagation(); toggleFav(fav.dataset.fav, fav); return; }
    /* Quick-add gate (R1): a bare "+" must never strand a required pick. If the
       item carries variants OR a required option group, open the detail so the
       customer chooses first — mirroring the detail-add missG check below. */
    var add = c("[data-add]");
    if (add) {
      e.stopPropagation();
      var ai = item(add.dataset.add);
      if (!ai) return;
      var needsPick = (ai.variants && ai.variants.length) ||
        (ai.optionGroups || []).some(function (g) { return g.required; });
      if (needsPick) { openItem(ai.id); return; }
      addToCart(add.dataset.add, 0, [], [], 1, add);
      return;
    }
    var chev = c("[data-chev]"); if (chev) { e.stopPropagation(); chev.closest(".card").classList.toggle("is-more"); return; }
    var open = c("[data-open]"); if (open) { openItem(open.dataset.open); return; }
    var sopen = c("[data-sopen]"); if (sopen) { closeSearch(); openItem(sopen.dataset.sopen); return; }
    var go = c("[data-go]"); if (go) { show(go.dataset.go); return; }
    if (c("[data-back]")) { back(); return; }
    var openSheetBtn = c("[data-open-sheet]"); if (openSheetBtn) { sheet(openSheetBtn.dataset.openSheet); return; }

    var cat = c("[data-cat]"); if (cat) { S.cat = cat.dataset.cat; S.sub = "root"; $$("#cats .cat").forEach(function (b) { b.classList.toggle("is-on", b === cat); }); renderSubChips(); updateTitle(); renderGrid(); return; }
    var sub = c("[data-sub]"); if (sub) { S.sub = sub.dataset.sub; renderSubChips(); renderGrid(); return; }

    /* gallery thumbnail → swap the item disc image */
    var gimg = c("[data-gimg]"); if (gimg) { var pi = $("#plateImg"); if (pi) pi.src = gimg.dataset.gimg; $$("#plateGallery .pg-thumb").forEach(function (b) { b.classList.toggle("is-on", b === gimg); }); return; }

    /* dietary / spice / allergen filter chips */
    var diet = c("[data-diet]"); if (diet) { S.filters.diets[diet.dataset.diet] = !S.filters.diets[diet.dataset.diet]; diet.classList.toggle("is-on"); syncFilterCount(); return; }
    var sp = c("[data-spice]"); if (sp) { var lvl = +sp.dataset.spice; S.filters.spice = (S.filters.spice === lvl) ? 0 : lvl; $$("#spiceRow [data-spice]").forEach(function (b) { b.classList.toggle("is-on", +b.dataset.spice === S.filters.spice); }); syncFilterCount(); return; }
    var al = c("[data-al]"); if (al) { S.filters.allergens[al.dataset.al] = !S.filters.allergens[al.dataset.al]; al.classList.toggle("is-on"); syncFilterCount(); return; }

    var vr = c("[data-var]"); if (vr) { S.iVar = +vr.dataset.var; $$("#varSeg button").forEach(function (b) { b.classList.toggle("is-on", b === vr); }); updateAddBtn(); return; }
    var ex = c("[data-extra]"); if (ex) { var xi = +ex.dataset.extra; if (S.iExtras.has(xi)) S.iExtras.delete(xi); else S.iExtras.add(xi); ex.classList.toggle("is-on"); updateAddBtn(); return; }
    var md = c("[data-mod]");
    if (md) {
      var gid = md.dataset.mod, oi = +md.dataset.opt, single = md.dataset.single === "1";
      var sel = S.iMods[gid] || (S.iMods[gid] = new Set());
      if (single) { sel.clear(); sel.add(oi); $$('[data-mod="' + gid + '"]').forEach(function (b) { b.classList.toggle("is-on", b === md); }); }
      else { if (sel.has(oi)) sel.delete(oi); else sel.add(oi); md.classList.toggle("is-on"); }
      updateAddBtn(); return;
    }
    var iq = c("[data-iq]"); if (iq) { S.iQty = Math.max(1, S.iQty + (+iq.dataset.iq)); updateAddBtn(); return; }

    /* rail */
    var rrm = c("[data-rrm]"); if (rrm) { e.stopPropagation(); removeLine(rrm.dataset.rrm); return; }
    var rline = c("[data-rline]"); if (rline) { var l = cart.filter(function (x) { return x.key === rline.dataset.rline; })[0]; if (l) openItem(l.id); return; }

    /* cart lines */
    var cq = c("[data-cq]");
    if (cq) {
      var key = cq.closest(".cline").dataset.key;
      var line = cart.filter(function (x) { return x.key === key; })[0];
      line.qty += (+cq.dataset.cq);
      if (line.qty <= 0) cart = cart.filter(function (x) { return x !== line; });
      persistCart(); syncBadge(false); renderCart(); renderRail();
      return;
    }
    var rm = c("[data-rm]");
    if (rm) { var row = rm.closest(".cline"); row.classList.add("removing"); var k = row.dataset.key; setTimeout(function () { removeLine(k); toast(tt("removed")); }, 280); return; }
    var notet = c("[data-notet]"); if (notet) { var inp = notet.closest(".cl-mid").querySelector("[data-note-input]"); inp.hidden = !inp.hidden; if (!inp.hidden) inp.focus(); return; }

    /* order type / when */
    var ot = c("#otSeg [data-ot]"); if (ot) { S.orderType = ot.dataset.ot; $$("#otSeg button").forEach(function (b) { b.classList.toggle("is-on", b === ot); }); renderOtFields(); renderCartTotals(); return; }
    var wh = c("#whenSeg [data-when]"); if (wh) { S.when = wh.dataset.when; $$("#whenSeg button").forEach(function (b) { b.classList.toggle("is-on", b === wh); }); var wt = $("#whenTime"); if (wt) wt.hidden = S.when !== "later"; return; }

    /* payment / tip */
    var pay = c("[data-pay]"); if (pay) { S.pay = pay.dataset.pay; $$("#payList .pay").forEach(function (b) { b.classList.toggle("is-on", b === pay); }); return; }
    var tip = c("#tipRow [data-tip]"); if (tip) { S.tipPct = +tip.dataset.tip; renderCheckout(); return; }

    /* nav rows */
    var nv = c("[data-nav]");
    if (nv) {
      sheet("navSheet", false);
      var to = nv.dataset.nav;
      if (to === "home") show("home", { push: false });
      else if (to === "cart") show("cart");
      else if (to === "login") setTimeout(function () { sheet("authSheet"); }, 240);
      else if (to === "rewards") setTimeout(function () { loadLoyalty(); sheet("rewardsSheet"); }, 240);
      else if (to === "reserve") setTimeout(function () { openReserve(); }, 240);
      else if (to === "settings") setTimeout(function () { sheet("settingsSheet"); }, 240);
      return;
    }

    /* sort rows */
    var so = c("[data-sort]"); if (so) { S.sort = so.dataset.sort; var sv = $("#sortVal"); if (sv) sv.textContent = tt(SORT_KEY[S.sort]); sheet("sortSheet", false); renderGrid(); return; }

    /* review stars */
    /* review stars — the "Thank you" used to fire from the click handler, so a
       rejected or failed POST still thanked the customer for a review that was never
       stored. Only the server's own success reveals it. */
    var star = c("[data-star]");
    if (star) {
      var wrap = star.closest(".rate-stars"); var n = +star.dataset.star;
      Array.prototype.forEach.call(wrap.children, function (ch, ci) { ch.classList.toggle("is-on", ci < n); });
      if (!S.item) return;
      fetch(CFG.endpoints.review, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf, "X-Requested-With": "XMLHttpRequest" }, body: JSON.stringify({ menu_id: S.item, stars: n }) })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.success) {
            var g = $("#rvGoogle"); if (g && n >= 4) g.hidden = false;
            var th = $("#rvThanks"); if (th) th.hidden = false;
          } else {
            toast(String((res && res.message) || tt("network_err")).replace(/<[^>]*>/g, " "));
          }
        })
        .catch(function () { toast(tt("network_err")); });
      return;
    }

    /* auth tabs */
    var at = c("[data-auth-tab]");
    if (at) {
      $$("#authTabs button").forEach(function (b) { b.classList.toggle("is-on", b === at); });
      $$("#authSheet .auth-form").forEach(function (f) { f.hidden = f.dataset.auth !== at.dataset.authTab; });
      return;
    }

    /* language / currency */
    /* Match only the tri-state toggle BUTTONS: the #plate root itself carries
       data-mode="<current>" as state, so a bare [data-mode] closest() matched the
       root for every click inside it and returned early — shadowing the currency
       and language switchers below. */
    var mo = c("button[data-mode]"); if (mo) { setMode(mo.dataset.mode); return; }
    var lg = c("[data-lang-code]"); if (lg) { setCookie("Quick_user_lang", lg.dataset.langName); setCookie("Quick_user_lang_code", lg.dataset.langCode); window.location.reload(); return; }
    var cu = c("[data-cur]"); if (cu) { S.curCode = cu.dataset.cur; S.curRate = parseFloat(cu.dataset.rate) || 1; $$("#curSeg button").forEach(function (b) { b.classList.toggle("is-on", b === cu); }); rerenderMoney(); if (window.mbCurrency) mbCurrency.save(cu.dataset.cur, cu.dataset.rate); return; }
  });

  /* dynamic inputs */
  root.addEventListener("input", function (e) {
    var Tg = e.target;
    if (Tg.matches && Tg.matches("[data-note-input]")) { var key = Tg.closest(".cline").dataset.key; var line = cart.filter(function (x) { return x.key === key; })[0]; if (line) { line.note = Tg.value; persistCart(); } }
    if (Tg.matches && Tg.matches("[data-addr]")) S.addr = Tg.value;
    if (Tg.id === "tableInput") S.table = Tg.value;
    if (Tg.id === "searchInput") { S.search = Tg.value; renderSearch(); }
  });
  root.addEventListener("change", function (e) {
    if (e.target.id === "walletChk") { S.walletUse = e.target.checked; }
    if (e.target.id === "consentCb") { var w = $("#captureEmailWrap"); if (w) w.hidden = !e.target.checked; }
  });

  /* ── reservation hub ──────────────────────────────────── */
  function openReserve() {
    if (document.getElementById("reserveHub")) sheet("reserveHub");
    else if (document.getElementById("reserveSheet")) sheet("reserveSheet");
    else if (document.getElementById("waitlistSheet")) sheet("waitlistSheet");
  }

  /* ── single-purpose buttons ───────────────────────────── */
  function on(id, ev, fn) { var el = document.getElementById(id); if (el) el.addEventListener(ev, fn); }
  on("splashSkip", "click", function () { $("#splash").classList.add("is-done"); });
  on("menuBtn", "click", function () { renderRecentOrders(); sheet("navSheet"); });
  /* Header globe: language sheet (rendered only for multi-language stores). */
  on("langBtn", "click", function () { if (document.getElementById("langSheet")) sheet("langSheet"); });
  on("cvCateringBtn", "click", function () { if (document.getElementById("cvCateringSheet")) sheet("cvCateringSheet"); });
  on("searchBtn", "click", openSearch);
  on("searchClose", "click", closeSearch);
  on("sortBtn", "click", function () { renderSortSheet(); sheet("sortSheet"); });
  on("filterBtn", "click", function () { syncFilterCount(); sheet("filterSheet"); });
  on("filterDone", "click", function () { sheet("filterSheet", false); renderGrid(); });
  on("filterReset", "click", resetFilters);
  on("clearFilters", "click", function () { resetFilters(); renderGrid(); });
  on("loyaltyChip", "click", function () { loadLoyalty(); sheet("rewardsSheet"); });
  on("reserveStrip", "click", openReserve);
  on("railClose", "click", function () { S.railOpen = false; syncRail(); });
  on("railGo", "click", function () { show("cart"); });
  on("cartBtn", "click", function () { if (!cart.length) { show("cart"); return; } var rail = $("#rail"); if (rail && rail.classList.contains("is-open")) show("cart"); else { S.railOpen = true; syncRail(); } });
  on("itemFav", "click", function () { if (S.item) toggleFav(S.item, $("#itemFav")); });
  on("itemStars", "click", function () { buildStars("rvStars"); var g = $("#rvGoogle"); if (g) g.hidden = true; var th = $("#rvThanks"); if (th) th.hidden = true; sheet("reviewSheet"); });
  on("addBtn", "click", function (e) {
    if (!S.item) return;
    var it = item(S.item);
    var missG = (it.optionGroups || []).filter(function (g) {
      var need = g.required ? Math.max(1, Number(g.min) || 0) : 0;
      var sel = S.iMods[g.id];
      return need > 0 && (!sel || sel.size < need);
    })[0];
    if (missG) { toast(tt("choose") + ": " + missG.title); return; }
    addToCart(S.item, S.iVar, collectExtras(), collectMods(), S.iQty, e.currentTarget);
  });
  on("clearCart", "click", function () { cart = []; S.coupon = null; persistCart(); syncBadge(false); renderCart(); renderRail(); syncRail(); toast(tt("cleared")); });
  on("checkoutBtn", "click", function () { show("checkout"); });
  on("placeBtn", "click", placeOrder);
  on("confBack", "click", function () { show("home", { push: false }); });
  on("loginLink", "click", function () { sheet("authSheet"); });
  on("redeemBtn", "click", function () {
    var rb = $("#redeemBtn"); rb.disabled = true;
    fetch(CFG.endpoints.loyaltyRedeem, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: "{}" })
      .then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.success) { $("#redeemOk").hidden = false; toast(tt("redeemed")); loadLoyalty(); }
        else { rb.disabled = false; toast((d && d.error) || tt("no_pts")); }
      }).catch(function () { rb.disabled = false; });
  });

  /* coupon */
  on("couponApply", "click", function () {
    var codeEl = $("#couponInput"); if (!codeEl) return;
    var code = codeEl.value.trim().toUpperCase(); if (!code) return;
    var msg = $("#couponMsg"); msg.hidden = false;
    fetch(CFG.endpoints.coupon, { method: "POST", headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf }, body: couponBody(code) })
      .then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.success) {
          S.coupon = { code: res.label || code, coupon_type: res.coupon_type, percentage: res.percentage, discount: Number(res.discount) || 0, sig: couponSig() };
          msg.className = "coupon-msg"; msg.textContent = "✓ " + tt("coupon_ok") + " " + (res.coupon_type === "percentage" ? Math.round(res.percentage) + "%" : money(res.coupon_type === "bogo" ? Number(res.discount) || 0 : res.percentage));
          $("#couponBox").classList.add("is-applied");
        } else {
          S.coupon = null; msg.className = "coupon-msg err"; msg.textContent = "✕ " + ((res && res.message) || tt("coupon_bad"));
          $("#couponBox").classList.remove("is-applied");
        }
        renderCartTotals();
      }).catch(function () { S.coupon = null; msg.className = "coupon-msg err"; msg.textContent = "✕ " + tt("coupon_bad"); renderCartTotals(); });
  });

  /* waiter — callTheWaiter() validates `table` as a required integer and `type` as
     in:call_waiter,request_bill. The old payload sent type:"waiter" (always rejected
     with "The selected type is invalid.") and toasted success from BOTH .then and
     .catch, so the customer was told a waiter was coming while no WaiterCall row was
     ever written. Confirm only what the server confirms. */
  on("waiterBtn", "click", function (e) {
    var table = String(S.table || "").trim();
    if (!/^\d+$/.test(table)) {
      toast(tt("waiter_table"));
      var ti = $("#tableInput"); if (ti) ti.focus();
      return;
    }
    var btn = e.currentTarget;
    btn.disabled = true;
    fetch(CFG.endpoints.waiter, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf, "X-Requested-With": "XMLHttpRequest" },
      body: JSON.stringify({ table: table, type: "call_waiter", _token: CFG.csrf }),
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false;
        if (res && res.success) toast(tt("waiter_ok"));
        else toast(String((res && res.message) || tt("network_err")).replace(/<[^>]*>/g, " "));
      })
      .catch(function () { btn.disabled = false; toast(tt("network_err")); });
  });

  /* auth submit */
  on("loginBtn", "click", function () { authPost(CFG.endpoints.login, { store_id: CFG.storeId, email: ($("#loginEmail") || {}).value, password: ($("#loginPass") || {}).value }, $("#authErr")); });
  on("registerBtn", "click", function () { authPost(CFG.endpoints.register, { store_id: CFG.storeId, name: ($("#regName") || {}).value, email: ($("#regEmail") || {}).value, phone: ($("#regPhone") || {}).value, password: ($("#regPass") || {}).value, password_confirmation: ($("#regPass2") || {}).value }, $("#authErr")); });
  on("otpSend", "click", function () {
    var phone = ($("#otpPhone") || {}).value; if (!phone) return;
    fetch(CFG.endpoints.otpRequest, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ store_id: CFG.storeId, phone: phone }) })
      .then(function (r) { return r.json(); }).then(function (res) { if (res && res.success) { $("#otpStep1").hidden = true; $("#otpStep2").hidden = false; } else { var e2 = $("#authErr"); e2.textContent = (res && res.message) || "Failed."; e2.hidden = false; } }).catch(function () {});
  });
  on("otpVerify", "click", function () { var code = $$("#otpBoxes input").map(function (i) { return i.value; }).join(""); authPost(CFG.endpoints.otpVerify, { store_id: CFG.storeId, phone: ($("#otpPhone") || {}).value, code: code }, $("#authErr")); });
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

  /* ── F2 · follow / brand row (ported from crave) ────────
     Guest → open the login sheet (the follow API needs a customer token).
     Signed-in → optimistic follow/unfollow via the token-auth endpoint. */
  (function () {
    var btn = $("#plateFollowBtn"); if (!btn) return;
    var ep = CFG.endpoints && CFG.endpoints.follow;
    function headers() {
      var h = { "Content-Type": "application/json", "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" };
      if (CFG.csrf) h["X-CSRF-TOKEN"] = CFG.csrf;
      if (CFG.customerToken) h["Authorization"] = "Bearer " + CFG.customerToken;
      return h;
    }
    function bumpCount(delta) {
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
      if (!CFG.customerToken) { sheet("authSheet"); return; } // guest → login first
      if (!ep) return;
      var unfollow = btn.dataset.following === "1", prev = unfollow;
      btn.dataset.following = unfollow ? "0" : "1";
      btn.classList.toggle("is-on", !unfollow);
      bumpCount(unfollow ? -1 : 1);
      fetch(ep, { method: unfollow ? "DELETE" : "POST", headers: headers(), body: "{}" })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!apply(d)) throw new Error("bad"); })
        .catch(function () {
          btn.dataset.following = prev ? "1" : "0";
          btn.classList.toggle("is-on", prev);
          bumpCount(unfollow ? 1 : -1);
          toast(tt("network_err"));
        });
    });
  })();

  /* back-to-top */
  var homeScroll = $("#homeScroll");
  if (homeScroll) {
    homeScroll.addEventListener("scroll", function () { var tt2 = $("#toTop"); if (tt2) tt2.hidden = homeScroll.scrollTop < 420; revealCards(); }, { passive: true });
    on("toTop", "click", function () { homeScroll.scrollTo({ top: 0, behavior: matchMedia("(prefers-reduced-motion:reduce)").matches ? "instant" : "smooth" }); });
  }

  /* ── reorder handoff from account page ────────────────── */
  /* Shared engine (_shared/js/reorder-pickup.js, loaded by store_templates.layout):
     resolves the server's choice-group selections + note onto this theme's
     cart-line shape so a reordered item keeps its modifiers and note. */
  window.__platePickup = function (line) {
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
    var r = window.JuwriReorder.pickup(slug, window.__platePickup, { toast: toast });
    if (r.added) { persistCart(); syncBadge(true); if (!r.unavailable.length) toast(tt("added")); }
  }

  /* ── nearest-branch map (self-hosted Leaflet; geo branch switcher) ── */
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
      var el = $("#branchGeoList"); if (!el) return;
      el.innerHTML = branches.map(function (b) {
        var dist = b.distance_km != null ? '<span class="branch-dist" dir="ltr">' + b.distance_km.toFixed(1) + " km</span>" : "";
        return '<button class="branch-row" data-bid="' + b.id + '" type="button">' +
          '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
          "<b>" + esc(b.name) + (b.address ? "<small>" + esc(b.address) + "</small>" : "") + "</b>" + dist +
          '<span class="branch-check"></span></button>';
      }).join("");
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
      var url = EP.branchesGeo + (lat != null ? "?lat=" + lat + "&lng=" + lng : "");
      fetch(url).then(function (r) { return r.json(); }).then(function (d) {
        var branches = (d && d.branches) || [];
        var at = $("#branchMapAttrib"); if (at) at.textContent = (d && d.attribution) || "© OpenStreetMap contributors";
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
    var gl = $("#branchGeoList");
    if (gl) gl.addEventListener("click", function (e) {
      var b = e.target.closest("[data-bid]"); if (b) selectBranch(b.dataset.bid);
    });
  })();

  /* ── delivery address: autocomplete + use-my-location + saved addresses ──
     The delivery field is (re)built by renderOtFields() on every order-type
     switch, so binding must be delegated on `root` rather than to the elements
     directly. geo.autocomplete → {results:[{label,lat,lng}], attribution};
     geo.reverse → {result:{label,lat,lng}, attribution}. */
  (function () {
    var EP = CFG.endpoints || {};
    if (!EP.geoAutocomplete && !EP.geoReverse) return;
    var acTimer = null;

    function setCoords(lat, lng) {
      S.lat = lat == null ? "" : lat; S.lng = lng == null ? "" : lng;
      var la = $("#addrLat"), lo = $("#addrLng");
      if (la) la.value = S.lat; if (lo) lo.value = S.lng;
    }
    function fillAddress(label, lat, lng) {
      S.addr = label || "";
      var inp = $("#addrInput"); if (inp) inp.value = S.addr;
      setCoords(lat, lng);
      hideSuggest();
    }
    function hideSuggest() { var sg = $("#addrSuggest"); if (sg) { sg.hidden = true; sg.innerHTML = ""; } }
    function setAttrib(text) {
      var at = $("#addrAttrib"); if (!at) return;
      at.textContent = text || ""; at.hidden = !text;
    }
    function renderSuggest(results, attribution) {
      var sg = $("#addrSuggest"); if (!sg) return;
      if (!results || !results.length) { hideSuggest(); return; }
      sg.innerHTML = results.map(function (r) {
        return '<button class="addr-opt" type="button" data-addr-opt data-lat="' + esc(r.lat) +
          '" data-lng="' + esc(r.lng) + '" data-label="' + esc(r.label) + '">' + esc(r.label) + "</button>";
      }).join("");
      sg.hidden = false;
      setAttrib(attribution);
    }
    function runAutocomplete(q) {
      if (!EP.geoAutocomplete) return;
      fetch(EP.geoAutocomplete + "?q=" + encodeURIComponent(q), { headers: { "X-Requested-With": "XMLHttpRequest" } })
        .then(function (r) { return r.json(); })
        .then(function (d) { renderSuggest((d && d.results) || [], d && d.attribution); })
        .catch(function () {});
    }

    /* typing invalidates any picked coordinate and drives a debounced lookup */
    root.addEventListener("input", function (e) {
      if (!e.target.matches || !e.target.matches("[data-addr]")) return;
      setCoords("", "");
      var q = e.target.value.trim();
      clearTimeout(acTimer);
      if (q.length < 3) { hideSuggest(); return; }
      acTimer = setTimeout(function () { runAutocomplete(q); }, 300);
    });

    root.addEventListener("click", function (e) {
      var opt = e.target.closest ? e.target.closest("[data-addr-opt]") : null;
      if (opt) { fillAddress(opt.dataset.label, opt.dataset.lat, opt.dataset.lng); return; }

      var sv = e.target.closest ? e.target.closest("[data-saved-addr]") : null;
      if (sv) {
        $$("[data-saved-addr]").forEach(function (b) { b.classList.toggle("is-on", b === sv); });
        fillAddress(sv.dataset.saddr, sv.dataset.lat, sv.dataset.lng);
        return;
      }

      var ul = e.target.closest ? e.target.closest("[data-useloc]") : null;
      if (!ul) return;
      if (!navigator.geolocation) { toast(tt("loc_unavail")); return; }
      toast(tt("use_location"));
      navigator.geolocation.getCurrentPosition(function (p) {
        var lat = p.coords.latitude, lng = p.coords.longitude;
        if (!EP.geoReverse) { setCoords(lat, lng); return; }
        fetch(EP.geoReverse + "?lat=" + lat + "&lng=" + lng, { headers: { "X-Requested-With": "XMLHttpRequest" } })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            var res = d && d.result;
            if (res && res.label) { fillAddress(res.label, res.lat, res.lng); setAttrib(d && d.attribution); }
            else { setCoords(lat, lng); toast(tt("no_address")); }
          })
          .catch(function () { setCoords(lat, lng); toast(tt("no_address")); });
      }, function (err) {
        toast(err && err.code === 1 ? tt("loc_denied") : tt("loc_unavail"));
      }, { timeout: 8000 });
    });
  })();

  /* ── stories (rail + full-screen viewer) ──────────────── */
  (function () {
    var EP = CFG.endpoints || {};
    var rail = $("#storiesRail");
    if (!rail || !EP.stories) return;
    var stories = [], idx = 0, timer = null, elapsed = 0;
    var DUR = 5; // seconds per story

    (window.mbStoriesGet?window.mbStoriesGet(EP.stories):fetch(EP.stories).then(function(r){return r.json();})).then(function (d) {
      stories = (d && d.data) || [];
      if (!stories.length) { rail.hidden = true; return; }
      rail.hidden = false;
      rail.innerHTML = stories.map(function (s, i) {
        var label = s.caption ? String(s.caption).slice(0, 14) : tt("stories");
        return '<button class="story" data-si="' + i + '" type="button">' +
          '<span class="story-ring"><img src="' + esc(s.media_url) + '" alt=""' + IMG_ERR + "></span>" +
          '<small class="cap">' + esc(label) + "</small></button>";
      }).join("");
    }).catch(function () { rail.hidden = true; });

    var view = $("#storyView"), media = $("#svMedia"), cap = $("#svCaption"),
        cta = $("#svCta"), prog = $("#svProgress");
    if (!view) return;

    function buildProgress() {
      prog.innerHTML = stories.map(function () { return '<span class="sv-seg"><i></i></span>'; }).join("");
    }
    function paintSeg() {
      $$(".sv-seg i", prog).forEach(function (bar, i) {
        bar.style.width = i < idx ? "100%" : (i === idx ? (elapsed / DUR * 100) + "%" : "0%");
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
        cta.querySelector("span").textContent = s.cta_label || tt("order_now");
      } else { cta.hidden = true; }
      elapsed = 0; paintSeg();
    }
    function tick() { elapsed += 0.05; if (elapsed >= DUR) { next(); return; } paintSeg(); }
    function start() { stop(); render(); timer = setInterval(tick, 50); }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function open(i) {
      idx = i; buildProgress();
      view.hidden = false;
      void view.offsetWidth;
      view.classList.add("is-open");
      start();
    }
    function close() {
      stop();
      view.classList.remove("is-open");
      setTimeout(function () { view.hidden = true; }, 300);
    }
    function next() { if (idx < stories.length - 1) { idx++; render(); } else { close(); } }
    function prev() { if (idx > 0) { idx--; render(); } else { elapsed = 0; } }

    rail.addEventListener("click", function (e) {
      var b = e.target.closest("[data-si]"); if (b) open(Number(b.dataset.si));
    });
    on("svClose", "click", close);
    /* The CTA opens the item in place via the a[href*="item="] delegate; close the
       story viewer too so it isn't left auto-playing behind the product sheet. */
    if (cta) cta.addEventListener("click", close);
    on("svNext", "click", next);
    on("svPrev", "click", prev);
    var stage = $("#svStage");
    if (stage) {
      stage.addEventListener("pointerdown", stop);
      stage.addEventListener("pointerup", function () { if (!view.hidden && !timer) timer = setInterval(tick, 50); });
    }
  })();

  /* ── boot ─────────────────────────────────────────────── */
  function boot() {
    var savedMode = null;
    try { savedMode = localStorage.getItem(MODE_KEY); } catch (e) {}
    applyMode(savedMode);
    pickupReorder();
    /* default order type: room service > forced table > first enabled seg button.
       The room-service button only renders when $allow_room_service is set, which
       already means hotel mode is on AND this QR resolved to a room — so its mere
       presence is the signal that the guest scanned from their room. Preferring it
       saves them a tap and, more importantly, stops a room order silently going to
       the kitchen as a table order when dine-in also happens to be enabled. Note the
       blade ships `is-on` on the dine-in button, so the toggle pass below has to run
       to move the highlight — it already does. */
    var otBtns = $$("#otSeg [data-ot]");
    if (otBtns.some(function (b) { return b.dataset.ot === "room_service"; })) S.orderType = "room_service";
    else if (CFG.forceTable && otBtns.some(function (b) { return b.dataset.ot === "on-table"; })) S.orderType = "on-table";
    else if (otBtns.length) S.orderType = (otBtns.filter(function (b) { return b.classList.contains("is-on"); })[0] || otBtns[0]).dataset.ot;
    $$("#otSeg [data-ot]").forEach(function (b) { b.classList.toggle("is-on", b.dataset.ot === S.orderType); });

    var sv = $("#sortVal"); if (sv) sv.textContent = tt(SORT_KEY[S.sort]);
    buildStars("rvStars");
    updateTitle();
    renderSubChips();
    renderGrid();
    syncBadge(false);
    renderRail();
    syncRail();
    loadLoyalty();
    renderRecentOrders();   // R5: guest order history in the nav sheet

    /* restore currency UI — replay the saved store-scoped choice (shared helper) */
    if (window.mbCurrency) mbCurrency.restore("#curSeg");

    /* splash: once per session */
    var splash = $("#splash");
    if (splash) {
      /* A1: a ?item=/?table= deep link opens straight on the product — dismiss the
         splash up front so it never overlays it. Not persisted: a later plain visit
         this session still gets the intro. */
      var _dl = null; try { _dl = new URLSearchParams(location.search); } catch (e) {}
      var deepLinked = !!(_dl && (_dl.get("item") || _dl.get("table")));
      if (deepLinked) splash.classList.add("is-done");
      else if (sessionStorage.getItem("plate_splash_" + slug)) splash.classList.add("is-done");
      else { sessionStorage.setItem("plate_splash_" + slug, "1"); setTimeout(function () { splash.classList.add("is-done"); }, 2300); }
    }

    /* ?item= deeplink → open that product on boot (R7) */
    var deepItem = new URLSearchParams(location.search).get("item");
    if (deepItem && item(deepItem)) openItem(deepItem);

    /* deep-link a server-opened reservation/waitlist sheet */
    if (CFG.openResSheet && document.getElementById("reserveSheet")) setTimeout(function () { sheet("reserveSheet"); }, 400);
    else if (CFG.openWaitlistSheet && document.getElementById("waitlistSheet")) setTimeout(function () { sheet("waitlistSheet"); }, 400);
  }
  boot();

  /* Collapsing header — condensed bar in once the editorial cover / title leaves
     the home scroll container, out again as the sticky .cats bar reaches the top.
     Condensed buttons forward their click to the real top-chrome controls. */
  (function mountCondensedHeader() {
    var homeView = document.querySelector('.view[data-view="home"]');
    if (!homeView) return;
    var anchor = homeView.querySelector(".plate-cover") || homeView.querySelector(".m-titlerow");
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
    var T = { lang: "#langBtn", notif: "#mbNotifBell", catering: "#cvCateringBtn" };
    Array.prototype.forEach.call(bar.querySelectorAll("[data-cv-proxy]"), function (b) {
      b.addEventListener("click", function () {
        var t = homeView.querySelector(T[b.getAttribute("data-cv-proxy")]);
        if (t) t.click();
      });
    });
  })();
})();
