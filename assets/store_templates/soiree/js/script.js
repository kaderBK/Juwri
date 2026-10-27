// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ═══════════════════════════════════════════════════════════
   soirée — dark drinks-lounge post_template runtime
   Faithful port of rev/soiree/demo.js wired to real menu data
   and the real ordering / coupon / review / loyalty endpoints.
   Reads window.SOIREE (set by index.blade.php).
   ═══════════════════════════════════════════════════════════ */
(function () {
  "use strict";
  var CFG = window.SOIREE;
  if (!CFG) return;

  var root = document.getElementById("app");
  if (!root) return;
  var RM = matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ── dom helpers ──────────────────────────────────────── */
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
  var I = CFG.i18n || {};
  function t(k) { return I[k] != null ? I[k] : k; }

  var ICONS = {
    plus: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg>',
    minus: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg>',
    heart: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>',
    star: '<svg class="ic" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>',
    clock: '<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
    fire: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 22c4.4 0 7-2.8 7-6.6 0-4.8-4.2-6.6-4.9-11.4-2.5 1.6-3.4 4-3.2 6.6-1.2-.7-2-1.7-2.4-3.4C6.6 9 5 11.6 5 15.4 5 19.2 7.6 22 12 22Z"/></svg>',
    check: '<svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>',
    trash: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M10 7V5h4v2M8 7l.8 13h6.4L16 7"/></svg>',
    pen: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 4h11l3 3v13H5V4Z"/><path d="M15 4v4h4M8.5 12h7M8.5 16h5"/></svg>',
    loc: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>',
    arrow: '<svg class="ic" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>',
    leaf: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 19C5 9 13 5 20 5c0 8-4 14-12 14-1 0-2-.1-3 0Z"/><path d="M5 19c2-5 6-9 10-11"/></svg>',
  };

  /* ── state ────────────────────────────────────────────── */
  var slug = CFG.restaurantSlug;
  var cartKey = "mb-cart-" + slug;      /* shared key across themes */
  var favKey = "cv-favs-" + slug;
  var vmKey = "mb-view-" + slug;        /* list | grid layout choice, per store */
  var cart = [];
  try { cart = JSON.parse(localStorage.getItem(cartKey)) || []; } catch (e) {}
  if (!Array.isArray(cart)) cart = [];
  var favs = new Set();
  try { (JSON.parse(localStorage.getItem(favKey)) || []).forEach(function (id) { favs.add(String(id)); }); } catch (e) {}

  var S = {
    htab: "promo",
    cat: "all", bcat: "all", bsub: "root", browseMode: "menu",
    viewMode: (function () { try { return localStorage.getItem(vmKey) === "list" ? "list" : "grid"; } catch (e) { return "grid"; } })(),
    search: "", sort: "featured",
    filters: { diets: {}, allergens: {} },
    orderType: null, coupon: null, tipPct: 0, tipCustom: null, pay: "pay_on_counter",
    current: null, pQty: 1, pVariant: 0, pMods: {}, pExtras: {},
    noteKey: null, resParty: 2,
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
  var IMG_ERR = FALLBACK ? ' data-csp-error="fallback" data-csp-fallback="' + esc(FALLBACK) + '"' : "";
  function firstImg(i) { return (i.gallery && i.gallery.length) ? i.gallery[0] : i.img; }
  function itemImgs(i) {
    var g = (i.gallery && i.gallery.length) ? i.gallery.slice() : [];
    if (i.img && g.indexOf(i.img) === -1) g.unshift(i.img);
    return g.length ? g : [i.img];
  }
  function soldOut(i) { return !!i.is_sold_out; }
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
  function complex(i) { return (i.variants || []).length || (i.optionGroups || []).length || (i.extras || []).length; }

  /* pricing: variant absolute, groups + extras additive */
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
  function lineUnit(l) { var i = item(l.id); return i ? unitPrice(i, l.variantId, l.mods, l.extras) : 0; }
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
    return cart.reduce(function (s, l) { return s + lineUnit(l) * l.qty; }, 0) + comboTotal();
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
        renderTotals(); paintCoupon();
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
  function writeVM() { try { localStorage.setItem(vmKey, S.viewMode); } catch (e) {} }
  /* reflect the active layout on every list/grid toggle (home + menu share one choice) */
  function syncVMToggles() {
    $$("[data-vm]").forEach(function (b) {
      var on = b.dataset.vm === S.viewMode;
      b.classList.toggle("is-on", on);
      b.setAttribute("aria-pressed", on ? "true" : "false");
    });
  }

  /* percent-of-money rounded to the store currency's decimals.
     PCT_F is 1 on 2-decimal stores, so this stays byte-identical there; on a
     3-decimal store it rounds to fils instead of flattening them to cents. */
  var PCT_F = Math.pow(10, MONEY_DP - 2);
  var PCT_D = 100 * PCT_F;
  /* M16: delegates to the shared money core (PHP-exact rounding). */
  function pctOf(amount, pct) { return mbMoney.pct(amount, pct, MONEY_DP); }

  function totals() {
    var sub = subtotal(), disc = 0;
    if (S.coupon) {
      disc = S.coupon.coupon_type === "percentage"
        ? pctOf(sub, S.coupon.percentage)
        : S.coupon.coupon_type === "bogo" ? bxgyDiscount() : Number(S.coupon.percentage) || 0;
      if (disc > sub) disc = sub;
    }
    /* MONEY MATH — this must agree with Order::computeTax()/getTotalAttribute()
       to the cent or the diner is quoted one number and charged another:
         · the tax base is the GROSS subtotal, NOT (subtotal − discount). Quoting
           tax on the discounted base under-quoted every coupon order.
         · `restaurant_tax_type` may be "fixed", in which case the stored number is
           a flat amount, not a percentage. The theme used to treat it as a % of
           the discounted base regardless, so a fixed-tax store mis-quoted always. */
    var tax = 0;
    if (CFG.taxCharge > 0 && sub > 0) {
      tax = CFG.taxType === "fixed"
        ? mbMoney.round(Number(CFG.taxCharge), MONEY_DP)
        : pctOf(sub, CFG.taxCharge);
    }
    var del = S.orderType === "delivery" && (cart.length || comboCount()) ? CFG.deliveryCharge : 0;
    var tip = S.tipPct ? pctOf(sub - disc, S.tipPct) : (S.tipCustom || 0);
    return { sub: sub, disc: disc, tax: tax, del: del, tip: tip, total: sub - disc + tax + del + tip };
  }

  /* ── router ───────────────────────────────────────────── */
  /* ── shell scroll trap (F1) ────────────────────────────
     The fixed `.soiree` shell used to be `overflow:hidden`, which makes it a
     SCROLL CONTAINER. The off-stage `.view` panels are translated 26% aside and
     that counts as scrollable overflow, so ANY focus()/scrollIntoView() inside an
     inactive panel scrolled the entire UI 130px sideways — no scrollbar, no way
     back, "Place order" measured at x=-112. The structural fix is `overflow:clip`
     in style.css (a clip box is not a scroll container at all). This is the guard
     for engines without `overflow:clip`: if anything ever manages to scroll the
     shell or the panel stage, snap it straight back. */
  (function guardShellScroll() {
    ["scroll", "focusin"].forEach(function (ev) {
      [root, document.getElementById("views")].forEach(function (el) {
        if (!el) return;
        el.addEventListener(ev, function () {
          if (el.scrollLeft !== 0) el.scrollLeft = 0;
          if (el.scrollTop !== 0) el.scrollTop = 0;
        }, true);
      });
    });
  })();

  var stack = [];
  var ROOTS = ["home", "browse"];
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
      if (!opts.fromPop) { try { history.pushState({ soi: 1 }, ""); } catch (e) {} }
    }
    if (isRoot) stack.length = 0;
    $$(".view").forEach(function (v) { v.classList.remove("is-under"); });
    if (!isRoot && from) from.classList.add("is-under");
    if (from) from.classList.remove("is-active");
    to.classList.add("is-active");
    var sc = to.querySelector(".view-scroll"); if (sc) sc.scrollTop = 0;
    active = name;
    var pill = $("#pillnav"); if (pill) pill.classList.toggle("is-hidden", !isRoot);
    $$(".pn-slot[data-tab]").forEach(function (b) { b.classList.toggle("is-on", b.dataset.tab === name); });
    if (name === "cart") renderCart();
    if (name === "checkout") renderCheckout();
    if (name === "browse") renderBrowse();
  }
  function internalBack() { show(stack.pop() || "home", { push: false, fromPop: true }); }
  function back() {
    /* UI back buttons go through history when we pushed a state, so the
       browser's own back stack stays in step with what is on screen. */
    if (stack.length && history.state && history.state.soi) { history.back(); return; }
    internalBack();
  }
  window.addEventListener("popstate", function () {
    var s = root.querySelector(".sheet.is-open");
    if (s) { sheet(s.id, false); try { history.pushState({ soi: 1 }, ""); } catch (e) {} return; }
    var sov = $("#searchOv");
    if (sov && !sov.hidden) { closeSearch(); try { history.pushState({ soi: 1 }, ""); } catch (e) {} return; }
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
      sheetTimers[id] = setTimeout(function () { s.hidden = true; if (!openSheetId || openSheetId === id) scrim.hidden = true; }, 440);
      if (openSheetId === id) openSheetId = null;
    }
  }
  function closeAnySheet() {
    var id = openSheetId || ((root.querySelector(".sheet.is-open") || {}).id);
    if (id) sheet(id, false);
  }
  $("#scrim").addEventListener("click", closeAnySheet);
  $$(".sheet-grab").forEach(function (g) { g.addEventListener("click", function () { var s = g.closest(".sheet"); if (s) sheet(s.id, false); }); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") { closeAnySheet(); var ov = $("#searchOv"); if (ov && !ov.hidden) closeSearch(); } });

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

  /* ── cart chips + order band ──────────────────────────── */
  function syncCartChips(bump) {
    var n = cartCount() + comboCount(), T = totals();
    $$(".cc-badge").forEach(function (b) { b.hidden = n === 0; b.textContent = n; if (bump && n) { b.classList.remove("bump"); void b.offsetWidth; b.classList.add("bump"); } });
    $$(".cc-total").forEach(function (b) { b.textContent = money(T.total); });
    $$(".band-count").forEach(function (b) { b.textContent = n; if (bump) { b.classList.remove("bump"); void b.offsetWidth; b.classList.add("bump"); } });
    $$(".band-total").forEach(function (b) { b.textContent = money(T.total); });
  }

  /* ── home · hero carousel ─────────────────────────────── */
  /* F13 — the same dish used to be featured FOUR times on one screen: hero rail,
     daily card, first row of the menu preview, and the Featured panel. Each home
     surface now claims its items and the next one skips them, so the preview grid
     is a genuine "here is the rest of the menu" instead of an echo. */
  var dailyItem = null, heroIds = null;
  function pickDaily() {
    if (dailyItem !== null) return dailyItem;
    dailyItem = ITEMS.filter(function (x) { return x.is_daily_special && firstImg(x); })[0]
      || ITEMS.filter(function (x) { return x.is_popular && firstImg(x); })[0]
      || ITEMS.filter(function (x) { return firstImg(x); })[0]
      || null;
    return dailyItem;
  }
  function heroItems() {
    var d = pickDaily();
    var taken = d ? String(d.id) : null;   /* the daily card already headlines it */
    var promo = ITEMS.filter(function (i) {
      return (i.is_popular || i.is_daily_special) && firstImg(i) && String(i.id) !== taken;
    });
    if (!promo.length) promo = ITEMS.filter(function (i) { return firstImg(i) && i.img && i.img.indexOf("default") === -1 && String(i.id) !== taken; });
    if (!promo.length) promo = ITEMS.filter(function (i) { return String(i.id) !== taken; });
    promo = promo.slice(0, 6);
    heroIds = promo.map(function (i) { return String(i.id); });
    return promo;
  }
  /* ids the home page has already put in front of the diner above the menu grid */
  function featuredIds() {
    var d = pickDaily();
    if (heroIds === null) heroItems();
    return (heroIds || []).concat(d ? [String(d.id)] : []);
  }
  function heroChip(i) {
    if (i.is_daily_special) return '<span class="glass-chip"><b>✦</b><small>' + esc(t("daily_special")) + "</small></span>";
    if (i.is_popular) return '<span class="glass-chip"><b>★</b><small>' + esc(t("popular")) + "</small></span>";
    return "";
  }
  function renderHero() {
    var track = $("#heroTrack"); if (!track) return;
    var list = heroItems();
    track.innerHTML = list.map(function (i, idx) {
      return '<a class="hcard" href="' + esc(itemHref(i.id)) + '" data-open="' + i.id + '" style="animation:row-in .5s var(--ease) ' + (idx * 90) + 'ms backwards;color:inherit;text-decoration:none">' +
        '<img src="' + esc(firstImg(i)) + '" alt="" loading="lazy"' + IMG_ERR + ">" +
        heroChip(i) +
        '<span class="hname"><b>' + esc(i.name) + "</b><small>" + esc(i.sub || "") + "</small></span>" +
        "</a>";
    }).join("");
    requestAnimationFrame(heroParallax);
  }
  function heroParallax() {
    if (RM) return;
    var track = $("#heroTrack"); if (!track) return;
    var r = track.getBoundingClientRect(), mid = r.left + r.width / 2;
    $$(".hcard", track).forEach(function (c) {
      var cr = c.getBoundingClientRect();
      var off = (cr.left + cr.width / 2 - mid) / cr.width;
      var img = c.querySelector("img");
      if (img) img.style.transform = "translateX(" + (-off * 12).toFixed(2) + "%) scale(1.18)";
    });
  }
  (function () { var tk = $("#heroTrack"); if (tk) tk.addEventListener("scroll", function () { requestAnimationFrame(heroParallax); }, { passive: true }); })();

  function renderDaily() {
    var card = $("#dailyCard"); if (!card) return;
    var i = pickDaily();
    if (!i) { card.hidden = true; return; }
    card.hidden = false;
    card.dataset.open = i.id;
    card.innerHTML =
      '<img src="' + esc(firstImg(i)) + '" alt=""' + IMG_ERR + ">" +
      '<span class="dc-copy"><span class="dc-tag">' + esc(i.is_daily_special ? t("daily_special") : t("popular")) + "</span><b>" + esc(i.name) + "</b><small>" + esc(i.sub || "") + "</small></span>" +
      '<span class="dc-price">' + hhWasHTML(i) + money(i.price) + "</span> " + hhBadgeHTML(i) +
      '<span class="dc-arr">' + ICONS.arrow + "</span>";
  }

  /* ── stories (rail + full-screen viewer) ──────────────── */
  (function () {
    var EP = CFG.endpoints || {};
    var rail = $("#storyRail");
    if (!rail || !EP.stories) return;
    var stories = [], idx = 0, timer = null, T = 0;
    var DUR = 5; /* seconds per story */

    (window.mbStoriesGet?window.mbStoriesGet(EP.stories):fetch(EP.stories).then(function(r){return r.json();})).then(function (d) {
      stories = (d && d.data) || [];
      if (!stories.length) { rail.hidden = true; return; }
      rail.hidden = false;
      rail.innerHTML = stories.map(function (s, i) {
        return '<button class="story-bub" data-story="' + i + '" type="button">' +
          '<span class="sb-ring"><img src="' + esc(s.media_url) + '" alt="" loading="lazy"' + IMG_ERR + "></span>" +
          "<small>" + esc(s.caption ? String(s.caption).slice(0, 14) : t("popular")) + "</small></button>";
      }).join("");
    }).catch(function () { rail.hidden = true; });

    var viewer = $("#storyViewer"), media = $("#svMedia"), cap = $("#svCaption"),
        cta = $("#svCta"), prog = $("#svProgress");
    if (!viewer || !media || !prog) return;

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
      if (cta) {
        if (s.cta_url && !(window.mbStoryCta && window.mbStoryCta.isCurrentPage(s.cta_url))) {
          cta.hidden = false;
          cta.href = s.cta_url;
          if (window.mbStoryCta) window.mbStoryCta.apply(cta, s.cta_url);
          if (s.cta_label) cta.querySelector("span").textContent = s.cta_label;
        } else { cta.hidden = true; }
      }
      T = 0; paintSeg();
    }
    function tick() { T += 0.05; if (T >= DUR) { next(); return; } paintSeg(); }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function start() { stop(); render(); timer = setInterval(tick, 50); }
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
      setTimeout(function () { viewer.hidden = true; }, RM ? 0 : 300);
    }
    function next() { if (idx < stories.length - 1) { idx++; render(); } else { close(); } }
    function prev() { if (idx > 0) { idx--; render(); } else { T = 0; paintSeg(); } }

    rail.addEventListener("click", function (e) {
      var b = e.target.closest("[data-story]");
      if (b) open(Number(b.getAttribute("data-story")));
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
  })();

  /* ── menu rows ────────────────────────────────────────── */
  /* F23 — the row used to be ONE <button> containing two `role="button"` spans.
     Nested interactive content is invalid HTML, and a span is not focusable, so
     the entire favourite + add-to-cart path was unreachable by keyboard. The row
     is now a plain container holding three real, individually focusable buttons.
     `opts.noTag` suppresses the "Popular" pill inside the Featured panel, where
     every card is popular and the badge therefore says nothing (F13). */
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
  function rowHTML(i, idx, opts) {
    opts = opts || {};
    var fav = favs.has(String(i.id));
    var so = soldOut(i);
    var metaBits = [];
    if (i.rating) metaBits.push("★ " + i.rating);
    if (i.calories) metaBits.push(i.calories + " " + t("kcal"));
    if (i.prep) metaBits.push(i.prep + " " + t("min"));
    if (!metaBits.length && i.sub) metaBits.push(i.sub);
    var favLbl = esc((fav ? t("remove_favorite") : t("add_favorite")) + ": " + i.name);
    return '' +
      '<div class="mrow' + (so ? " is-soldout" : "") + '" style="animation-delay:' + Math.min(idx * 45, 380) + 'ms">' +
        '<a class="m-main" href="' + esc(itemHref(i.id)) + '" data-open="' + i.id + '" style="color:inherit;text-decoration:none">' +
          '<img src="' + esc(firstImg(i)) + '" alt="" loading="lazy"' + IMG_ERR + ">" +
          '<span class="m-copy"><b>' + esc(i.name) + "</b><small>" + esc(metaBits.join(" · ")) + "</small></span>" +
          ((i.is_popular && !opts.noTag) ? '<span class="m-tag">' + esc(t("popular")) + "</span>" : "") +
          '<span class="m-price"><b>' + hhWasHTML(i) + money(i.price) + "</b></span> " + hhBadgeHTML(i) +
        "</a>" +
        '<button class="m-fav ' + (fav ? "is-on" : "") + '" data-fav="' + i.id + '" type="button" aria-pressed="' + (fav ? "true" : "false") + '" aria-label="' + favLbl + '">' + ICONS.heart + "</button>" +
        (so ? '<span class="m-soldout">' + esc(t("sold_out")) + "</span>" :
          (CFG.allowOrder ? '<button class="m-add" data-add="' + i.id + '" type="button" aria-label="' + esc(t("add_to_order") + ": " + i.name) + '">' + ICONS.plus + "</button>" : "")) +
      "</div>";
  }

  /* Featured showcase card — a vertical, photo-led tile (photo → name → meta →
     price) so the curated picks read as a gallery, not the browse list squeezed
     into a grid. Same data-attrs/classes as the row so the delegated open / fav /
     add handlers bind with no extra wiring. */
  function featCardHTML(i, idx) {
    var fav = favs.has(String(i.id));
    var so = soldOut(i);
    var metaBits = [];
    if (i.rating) metaBits.push("★ " + i.rating);
    if (i.calories) metaBits.push(i.calories + " " + t("kcal"));
    if (i.prep) metaBits.push(i.prep + " " + t("min"));
    if (!metaBits.length && i.sub) metaBits.push(i.sub);
    var favLbl = esc((fav ? t("remove_favorite") : t("add_favorite")) + ": " + i.name);
    return '' +
      '<div class="feat-card' + (so ? " is-soldout" : "") + '" style="animation-delay:' + Math.min(idx * 55, 420) + 'ms">' +
        '<a class="feat-main" href="' + esc(itemHref(i.id)) + '" data-open="' + i.id + '" style="color:inherit;text-decoration:none">' +
          '<span class="feat-media">' +
            '<img src="' + esc(firstImg(i)) + '" alt="" loading="lazy"' + IMG_ERR + ">" +
            (i.schedule_label ? '<span class="feat-badge">' + esc(i.schedule_label) + "</span>" : "") +
          "</span>" +
          '<span class="feat-body">' +
            "<b>" + esc(i.name) + "</b>" +
            (metaBits.length ? "<small>" + esc(metaBits.join(" · ")) + "</small>" : "") +
            '<span class="feat-price">' + hhWasHTML(i) + money(i.price) + "</span>" +
          "</span>" +
        "</a>" +
        '<button class="m-fav feat-fav ' + (fav ? "is-on" : "") + '" data-fav="' + i.id + '" type="button" aria-pressed="' + (fav ? "true" : "false") + '" aria-label="' + favLbl + '">' + ICONS.heart + "</button>" +
        (so ? '<span class="feat-soldout">' + esc(t("sold_out")) + "</span>" :
          (CFG.allowOrder ? '<button class="m-add feat-add" data-add="' + i.id + '" type="button" aria-label="' + esc(t("add_to_order") + ": " + i.name) + '">' + ICONS.plus + "</button>" : "")) +
      "</div>";
  }

  /* Two-tier nav: CFG.cats are top-level parents (each with its own item-bearing
     `subs`). Parent chips filter by item.root (a parent shows every descendant); the
     browse sub-row narrows to one subcategory by item.cat. */
  function renderCatChips() {
    var cats = (CFG.cats || []);
    // home parent row (preview grid; parent-only, no sub-row)
    var html = '<button data-cat="all" class="' + (S.cat === "all" ? "is-on" : "") + '" type="button">' + esc(t("all")) + "</button>" +
      cats.map(function (c) { return '<button data-cat="' + esc(c.id) + '" class="' + (String(c.id) === String(S.cat) ? "is-on" : "") + '" type="button">' + esc(c.name) + "</button>"; }).join("");
    var el = $("#catChips"); if (el) el.innerHTML = html;
    // browse parent row: data-has-sub flags parents that own a secondary row
    var bhtml = '<button data-bcat="all" class="' + (S.bcat === "all" ? "is-on" : "") + '" type="button">' + esc(t("all")) + "</button>" +
      cats.map(function (c) {
        var hasSub = c.subs && c.subs.length ? ' data-has-sub="1"' : "";
        return '<button data-bcat="' + esc(c.id) + '"' + hasSub + ' class="' + (String(c.id) === String(S.bcat) ? "is-on" : "") + '" type="button">' + esc(c.name) + "</button>";
      }).join("");
    var bel = $("#browseChips"); if (bel) bel.innerHTML = bhtml;
    renderBrowseSubChips();
  }
  function renderBrowseSubChips() {
    var el = $("#browseSubChips"); if (!el) return;
    var parent = (CFG.cats || []).filter(function (c) { return String(c.id) === String(S.bcat); })[0];
    var subs = (parent && parent.subs) || [];
    // top-level "All", a parent with no subs, or Favorites mode → hide the row
    if (S.bcat === "all" || !subs.length || S.browseMode === "favs") { el.hidden = true; el.innerHTML = ""; return; }
    el.hidden = false;
    el.innerHTML = '<button data-sub="root" class="' + (S.bsub === "root" ? "is-on" : "") + '" type="button">' + esc(t("all")) + "</button>" +
      subs.map(function (s) { return '<button data-sub="' + esc(s.id) + '" class="' + (String(s.id) === String(S.bsub) ? "is-on" : "") + '" type="button">' + esc(s.name) + "</button>"; }).join("");
  }
  function renderMenuList() {
    var el = $("#menuList"); if (!el) return;
    /* skip whatever the hero rail + daily card already showed above (F13) */
    var shown = featuredIds();
    var pool = ITEMS.filter(function (i) { return S.cat === "all" || String(i.root) === String(S.cat); });
    var fresh = pool.filter(function (i) { return shown.indexOf(String(i.id)) === -1; });
    var list = (fresh.length ? fresh : pool).slice(0, 12);
    var card = S.viewMode === "grid";
    el.classList.toggle("as-cards", card);
    el.innerHTML = list.map(function (i, ix) { return card ? featCardHTML(i, ix) : rowHTML(i, ix); }).join("");
  }

  function passesFilters(i) {
    var f = S.filters;
    if (f.diets.vegan && !i.is_vegan) return false;
    if (f.diets.halal && !i.is_halal) return false;
    if (f.diets.gluten_free && !i.is_gluten_free) return false;
    for (var aId in f.allergens) {
      if (f.allergens[aId] && (i.allergies || []).some(function (a) { return String(a.id) === String(aId); })) return false;
    }
    return true;
  }
  function sortList(list) {
    if (S.sort === "price_asc") list.sort(function (a, b) { return a.price - b.price; });
    if (S.sort === "price_desc") list.sort(function (a, b) { return b.price - a.price; });
    if (S.sort === "pop") list.sort(function (a, b) { return (b.is_popular ? 1 : 0) - (a.is_popular ? 1 : 0); });
    return list;
  }
  function renderBrowse() {
    var el = $("#browseList"); if (!el) return;
    var favsMode = S.browseMode === "favs";
    var chips = $("#browseChips"); if (chips) chips.hidden = favsMode;
    renderBrowseSubChips();
    var list = favsMode
      ? ITEMS.filter(function (i) { return favs.has(String(i.id)); })
      : sortList(ITEMS.filter(function (i) {
          return (S.bcat === "all" || String(i.root) === String(S.bcat))
            && (S.bsub === "root" || String(i.cat) === String(S.bsub))
            && passesFilters(i);
        }));
    var card = S.viewMode === "grid";
    el.classList.toggle("as-cards", card);
    el.innerHTML = list.map(function (i, ix) { return card ? featCardHTML(i, ix) : rowHTML(i, ix); }).join("");
    var f = S.filters;
    /* an over-narrow filter used to leave a blank void: the empty state only ever
       showed in Favorites mode. Every empty result now gets a designed state with
       the right message and a way out. */
    var emptyBox = $("#browseEmpty");
    emptyBox.hidden = list.length > 0;
    if (!list.length) {
      var copy = emptyBox.querySelector("p");
      var escape = $("#browseEmptyClear");
      if (favsMode) {
        if (copy) copy.textContent = emptyBox.dataset.favsCopy || copy.textContent;
        if (escape) escape.hidden = true;
      } else {
        if (copy) copy.textContent = t("search_none");
        if (escape) escape.hidden = false;
      }
    }
    var activeF = f.diets.vegan || f.diets.halal || f.diets.gluten_free || Object.keys(f.allergens).some(function (k) { return f.allergens[k]; });
    var dot = $("#filterDot"); if (dot) dot.hidden = !activeF;
    var cnt = $("#filterCount"); if (cnt) cnt.textContent = list.length;
  }
  function renderHappy() {
    var el = $("#hhList"), tab = $("#happyTab"); if (!el) return;
    var list = ITEMS.filter(function (i) { return i.is_popular || i.is_daily_special; });
    if (tab) tab.hidden = list.length === 0;
    /* Featured gets its own vertical showcase card (not the metre-wide browse row,
       which collapses the name to nothing inside the 2/3-column grid). */
    el.innerHTML = list.map(function (i, ix) { return featCardHTML(i, ix); }).join("");
  }

  /* ── loyalty (Free Drink tab) ─────────────────────────── */
  function loadLoyalty() {
    if (!CFG.hasLoyalty || !CFG.endpoints.loyaltyBalance || !CFG.isLoggedIn) return;
    fetch(CFG.endpoints.loyaltyBalance).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || !d.active) return;
      var big = $("#ptsBig"); if (big) countUp(big, d.balance || 0);
      var fill = $("#ptsFill"); if (fill) fill.style.width = Math.min(100, d.progress_pct || 0) + "%";
      var next = $("#ptsNext");
      if (next) next.textContent = d.ready_to_redeem ? t("pts_all") : t("pts_next").replace(":n", Math.max(0, (CFG.loyaltyThreshold || 0) - (d.balance || 0)) + (CFG.loyaltyType === "stamps" ? " stamps" : " pts"));
      $$("#stampDots .stamp-dot").forEach(function (dot, idx) { dot.classList.toggle("is-on", idx < (d.balance || 0)); });
      var rb = $("#loyaltyRedeem"); if (rb) rb.hidden = !d.ready_to_redeem;
    }).catch(function () {});
  }
  var countTimer;
  function countUp(node, to2) {
    clearInterval(countTimer);
    if (RM) { node.textContent = to2; return; }
    var cur = +String(node.textContent).replace(/\D/g, "") || 0;
    if (cur === to2) { node.textContent = to2; return; }
    var step = Math.max(1, Math.round(Math.abs(to2 - cur) / 24)) * (to2 > cur ? 1 : -1);
    countTimer = setInterval(function () {
      cur += step;
      if ((step > 0 && cur >= to2) || (step < 0 && cur <= to2)) { cur = to2; clearInterval(countTimer); }
      node.textContent = cur;
    }, 24);
  }

  /* ── home tab switching ───────────────────────────────── */
  function setHtab(tab) {
    S.htab = tab;
    $$("#homeTabs .htab").forEach(function (b) { b.classList.toggle("is-on", b.dataset.htab === tab); });
    $$(".hpanel").forEach(function (p) { p.classList.toggle("is-on", p.dataset.hpanel === tab); });
    if (tab === "free") loadLoyalty();
    if (tab === "promo") requestAnimationFrame(heroParallax);
  }

  /* ── product page ─────────────────────────────────────── */
  function openProduct(id) { setProduct(id, true); show("product"); itemEvent(id, "view"); }
  function setProduct(id, fresh) {
    var i = item(id); if (!i) return;
    var same = S.current && S.current.id === i.id;
    S.current = i;
    if (fresh || !same) {
      S.pQty = 1;
      S.pVariant = (i.variants || []).length ? i.variants[0].id : 0;
      S.pMods = {}; S.pExtras = {};
    }
    var img = $("#pHeroImg");
    if (FALLBACK) img.onerror = function () { this.onerror = null; this.src = FALLBACK; };
    img.src = firstImg(i);
    $("#pFav").classList.toggle("is-on", favs.has(String(id)));
    var stock = $("#pStock"); stock.hidden = !soldOut(i); if (soldOut(i)) stock.textContent = t("sold_out");

    $("#pTitle").textContent = i.name;
    $("#pSub").textContent = i.sub || "";

    var meta = [];
    if (i.rating) meta.push('<span class="star">' + ICONS.star + " " + i.rating + (i.rating_count ? " (" + i.rating_count + ")" : "") + "</span>");
    if (i.calories) meta.push("<span>" + ICONS.fire + i.calories + " " + t("kcal") + "</span>");
    if (i.prep) meta.push("<span>" + ICONS.clock + i.prep + " " + t("min") + "</span>");
    if (i.is_vegan) meta.push('<span class="diet">' + ICONS.leaf + "Vegan</span>");
    if (i.is_gluten_free) meta.push('<span class="diet">GF</span>');
    if (i.spice_level > 0) meta.push("<span>" + "🌶".repeat(Math.min(i.spice_level, 3)) + "</span>");
    $("#pMeta").innerHTML = meta.join("");

    /* spec chips — real nutrition macros only (no fabricated data) */
    var specs = [];
    if (i.protein != null && i.protein !== "") specs.push({ v: i.protein + "g", l: "Protein" });
    if (i.carbs != null && i.carbs !== "") specs.push({ v: i.carbs + "g", l: "Carbs" });
    if (i.fat != null && i.fat !== "") specs.push({ v: i.fat + "g", l: "Fat" });
    $("#pSpecs").innerHTML = specs.map(function (s, si) { return '<span class="spec" style="--i:' + si + '"><b>' + esc(s.v) + "</b><small>" + esc(s.l) + "</small></span>"; }).join("");

    $("#pDesc").textContent = i.desc || "";
    $("#pAllergens").innerHTML = (i.allergies || []).map(function (a) {
      return '<span class="allergen">' + (a.img ? '<img src="' + esc(a.img) + '" alt="">' : "⚠ ") + esc(a.title) + "</span>";
    }).join("");

    $("#pGroups").innerHTML = groupsHTML(i);

    /* product reviews — approved MenuReview rows shipped in the item payload */
    var pr = $("#pReviews");
    if (pr) {
      pr.innerHTML = window.__mbItemReviewsOn === false ? "" : (i.reviewList || []).length
        ? '<h4>' + esc(t("reviews_title")) + '<span class="opt-req">' + esc(i.reviewTotal || i.reviewList.length) + "</span></h4>" +
          i.reviewList.map(function (review) {
            var stars = Math.max(1, Math.min(5, Number(review.stars) || 0));
            return '<article class="review-card"><b>' + esc(review.name) + '</b><span class="review-stars">' +
              "★".repeat(stars) + '<span class="review-stars-dim">' + "★".repeat(5 - stars) + "</span>" +
              "</span>" + (review.body ? "<p>" + esc(review.body) + "</p>" : "") + "</article>";
          }).join("")
        : '<h4>' + esc(t("reviews_title")) + '</h4><p class="reviews-empty">' + esc(t("reviews_empty")) + "</p>";
    }
    /* inline "Rate this dish" quick-rate — rebuilt each open (so the stars reset),
       sits just above the reviews section. Tapping a star opens the shared review
       write-sheet preselected to that many stars (window.mbItemReviews.open). */
    if (window.__mbItemReviewsOn !== false && pr && pr.parentNode) {
      var rr = document.getElementById("rateRow");
      if (!rr) { rr = document.createElement("div"); rr.className = "rate-row"; rr.id = "rateRow"; pr.parentNode.insertBefore(rr, pr); }
      rr.innerHTML = '<span class="rate-label">' + esc(t("rate_dish")) + '</span><span class="rate-stars" id="rateStars">' +
        [1, 2, 3, 4, 5].map(function (n) { return '<button data-rate="' + n + '" type="button" aria-label="' + n + '">' + ICONS.star + "</button>"; }).join("") + "</span>";
    }
    var out = $("#pQtyVal"); if (out) out.innerHTML = "<span>" + S.pQty + "</span>";

    /* related */
    updateProductCta();
  }
  function groupsHTML(i) {
    var html = "";
    if ((i.variants || []).length) {
      html += '<div class="opt-group" data-kind="variant"><h4>' + esc(t("serve")) + '<span class="opt-req">' + esc(t("required")) + '</span></h4><div class="optchips">' +
        i.variants.map(function (v) {
          return '<button class="optchip ' + (S.pVariant === v.id ? "is-on" : "") + '" data-variant="' + v.id + '" type="button">' + esc(v.label) + '<small>' + money(v.price) + "</small></button>";
        }).join("") + "</div></div>";
    }
    (i.optionGroups || []).forEach(function (g) {
      var multi = g.max === null || g.max > 1;
      html += '<div class="opt-group" data-group="' + g.id + '" data-multi="' + (multi ? 1 : 0) + '" data-min="' + (g.min || 0) + '" data-max="' + (g.max === null ? "" : g.max) + '"><h4>' + esc(g.title) + '<span class="opt-req">' + esc(g.required ? t("required") : t("optional")) + "</span></h4><div class=\"optchips\">" +
        g.options.map(function (o) {
          var on = (S.pMods[g.id] || []).some(function (m) { return m.optIndex === o.i; });
          return '<button class="optchip ' + (on ? "is-on" : "") + '" data-opt="' + o.i + '" data-price="' + o.price + '" data-label="' + esc(o.label) + '" type="button">' + esc(o.label) + (o.price ? '<small>+' + money(o.price) + "</small>" : "") + "</button>";
        }).join("") + "</div></div>";
    });
    if ((i.extras || []).length) {
      html += '<div class="opt-group" data-kind="extras"><h4>' + esc(t("extras")) + '<span class="opt-req">' + esc(t("optional")) + '</span></h4><div class="optchips">' +
        i.extras.map(function (e) {
          return '<button class="optchip ' + (S.pExtras[e.id] ? "is-on" : "") + '" data-extra="' + e.id + '" type="button">' + (e.img ? '<img src="' + esc(e.img) + '" alt="">' : "") + esc(e.title) + '<small>+' + money(e.price) + "</small></button>";
        }).join("") + "</div></div>";
    }
    return html;
  }
  function selectedMods() { var out = []; for (var g in S.pMods) { (S.pMods[g] || []).forEach(function (m) { out.push(m); }); } return out; }
  function selectedExtras() { var i = S.current, out = []; if (!i) return out; (i.extras || []).forEach(function (e) { if (S.pExtras[e.id]) out.push(e); }); return out; }
  function updateProductCta() {
    var i = S.current; if (!i) return;
    var unit = unitPrice(i, S.pVariant, selectedMods(), selectedExtras());
    var u = $("#pUnit"); if (u) u.textContent = money(unit);
    var lt = $("#pLineTotal"); if (lt) lt.textContent = money(unit * S.pQty);
    var btn = $("#pAddBtn"); if (btn) btn.disabled = soldOut(i);
  }
  function setQty(next, dir) {
    S.pQty = Math.max(1, next);
    var out = $("#pQtyVal"); if (!out) return;
    out.innerHTML = "<span>" + S.pQty + "</span>";
    out.classList.remove("up", "down"); void out.offsetWidth;
    out.classList.add(dir > 0 ? "up" : "down");
    updateProductCta();
  }
  /* product hero parallax */
  (function () {
    var ps = $("#pScroll"); if (!ps) return;
    ps.addEventListener("scroll", function () {
      if (RM) return;
      var y = ps.scrollTop, img = $("#pHeroImg");
      if (img) img.style.transform = "translateY(" + (Math.min(y, 460) * 0.3) + "px)";
    }, { passive: true });
  })();

  /* ── add to cart ──────────────────────────────────────── */
  function addToCart(id, variantId, mods, extras, qty, fromEl) {
    var i = item(id);
    if (!i || soldOut(i)) { toast(t("sold_out")); return; }
    var missing = (i.optionGroups || []).filter(function (g) {
      var need = g.required ? Math.max(1, g.min || 0) : (g.min || 0);
      var have = (mods || []).filter(function (m) { return String(m.groupId) === String(g.id); }).length;
      return have < need;
    });
    if (missing.length) {
      toast(t("choose") + ": " + missing[0].title);
      /* a toast alone is easy to miss — take the diner to the group that needs a
         pick and flash it, so the "why didn't Add work" is answered on the spot. */
      var mg = document.querySelector('.opt-group[data-group="' + missing[0].id + '"]');
      if (mg) {
        mg.scrollIntoView({ block: "center", behavior: RM ? "auto" : "smooth" });
        mg.classList.remove("needs-pick"); void mg.offsetWidth; mg.classList.add("needs-pick");
      }
      return;
    }
    var key = id + "|" + (variantId || 0) + "|" +
      (mods || []).map(function (m) { return m.groupId + ":" + m.optIndex; }).sort().join(",") + "|" +
      (extras || []).map(function (e) { return e.id; }).sort().join(",");
    var line = cart.filter(function (l) { return l.key === key; })[0];
    if (line) line.qty += qty;
    else cart.push({ key: key, id: id, qty: qty, variantId: variantId || 0, mods: mods || [], extras: extras || [] });
    writeCart(); syncCartChips(true);
    toast(i.name + " — " + t("added"));
    if (fromEl) flyToChip(fromEl);
    itemEvent(id, "add_to_cart");
  }
  function flyToChip(fromEl) {
    if (RM) return;
    var img = fromEl.closest(".mrow, .hcard, .daily-card") ? fromEl.closest(".mrow, .hcard, .daily-card").querySelector("img") : $("#pHeroImg");
    var target = $$(".cartchip").filter(function (c) { return c.offsetParent !== null; })[0];
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
      ghost.style.top = (b.top - lr.top + b.height / 2 - 28) + "px";
      ghost.style.transform = "scale(.16)"; ghost.style.opacity = ".25";
    });
    setTimeout(function () { ghost.remove(); }, 750);
  }

  /* ── cart render ──────────────────────────────────────── */
  /* COMBOS-V2 — read-only "Combos" group appended to the cart list and the
     checkout summary. Tapping a row reopens the combo sheet's basket view,
     which is where quantities are edited. */
  function comboGroupHTML(compact) {
    var lines = comboLines();
    if (!lines.length) return "";
    var h = '<div class="so-combo-group" data-combo-open style="border-top:1px dashed var(--line,#8883);padding-top:8px;margin-top:8px;cursor:pointer">';
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
      syncCartChips(true);
      renderTotals();
      if (active === "cart") renderCart();
      if (active === "checkout") renderCheckout();
    } catch (e) {}
  });

  function renderCart() {
    var has = cart.length > 0 || comboCount() > 0;
    $("#cartEmpty").hidden = has;
    var tools = $("#cartTools"); if (tools) tools.style.display = has ? "" : "none";
    var band = $("#cartBand"); if (band) band.hidden = !has;
    /* "Clear cart" only clears real item lines, so it hides on a combos-only cart. */
    var clr = $("#clearCart"); if (clr) clr.style.visibility = cart.length ? "" : "hidden";
    $("#cartList").innerHTML = comboGroupHTML(false) + cart.map(function (l, li) {
      var i = item(l.id); if (!i) return "";
      var label = lineLabel(l);
      var unit = lineUnit(l);
      /* Happy-hour strike-through: original_price is the pre-schedule BASE
         price, so the pre-discount unit is unit + (original − current base). */
      var wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
        ? unit + (Number(i.original_price) - Number(i.price)) : 0;
      return '<div class="cline" data-key="' + esc(l.key) + '" style="animation-delay:' + li * 50 + 'ms">' +
        '<button class="cl-im" data-open="' + i.id + '" type="button" aria-label="' + esc(i.name) + '"><img src="' + esc(firstImg(i)) + '" alt=""' + IMG_ERR + "></button>" +
        '<div class="cl-copy"><button class="cl-name" data-open="' + i.id + '" type="button"><b>' + esc(i.name) + "</b></button>" +
          (label ? '<span class="cl-opts">' + esc(label) + "</span>" : "") +
          (l.note ? '<span class="cl-note">' + ICONS.pen + esc(l.note) + "</span>" : "") +
          '<span class="cl-priceline"><span class="cl-price">' + money(unit * l.qty) + "</span>" +
            (wasUnit ? '<del class="cl-was">' + money(wasUnit * l.qty) + "</del>" : "") +
            (l.qty > 1 ? '<small class="cl-unit">' + l.qty + " × " + money(unit) + "</small>" : "") +
          "</span></div>" +
        '<div class="cl-side"><div class="cl-qty">' +
          '<button data-cq="-1" aria-label="−">' + ICONS.minus + "</button>" +
          "<output>" + l.qty + "</output>" +
          '<button data-cq="1" aria-label="+">' + ICONS.plus + "</button></div>" +
        '<div class="cl-tools"><button data-note aria-label="Note">' + ICONS.pen + "</button>" +
          '<button data-rm aria-label="' + esc(I.remove || "Remove") + '">' + ICONS.trash + "</button></div></div>" +
        "</div>";
    }).join("");
    renderOtFields(); renderTotals(); paintCoupon();
  }
  function renderOtFields() {
    var f = $("#otFields"); if (!f) return;
    var wr = $("#waiterRow"); if (wr) wr.hidden = S.orderType !== "on-table" || !CFG.allowCallWaiter;
    $$("#orderTypeSeg button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.ot === S.orderType); });
    if (S.orderType === "on-table") {
      if (CFG.forceTable) {
        f.innerHTML = '<p class="ot-hint">' + ICONS.check.replace("<svg", '<svg class="ic"') + "<span>" + esc(t("table")) + " " + esc(CFG.defaultTable || "") + "</span></p>";
      } else {
        /* F17: the hint under this input repeated the placeholder verbatim AND
           carried a ✓, so an empty table field looked like a satisfied one. */
        f.innerHTML = '<input id="tableSel" type="number" inputmode="numeric" aria-label="' + esc(t("pick_table")) + '" placeholder="' + esc(t("pick_table")) + '">';
      }
    } else if (S.orderType === "takeaway") {
      f.innerHTML = '<p class="ot-hint">' + ICONS.check.replace("<svg", '<svg class="ic"') + "<span>" + esc(t("pickup_hint")) + "</span></p>";
    } else if (S.orderType === "delivery") {
      /* Same autocomplete + use-my-location affordance as checkout — the field
         is rebuilt on every order-type switch, so it is re-wired right here. */
      var co = $("#custAddr");
      f.innerHTML = '<div class="addr-field" id="cartAddrField">' +
        '<input id="cartAddr" value="' + esc(co ? co.value : "") + '" placeholder="' + esc(t("addr_ph")) + '" autocomplete="off" autocorrect="off" spellcheck="false">' +
        '<button class="uselocbtn" id="cartUseLocation" type="button" aria-label="' + esc(t("use_my_location")) + '" title="' + esc(t("use_my_location")) + '">' + ICONS.loc + "</button>" +
        '<div class="addr-suggest" id="cartAddrSuggest" hidden></div></div>' +
        '<p class="ot-hint">' + ICONS.check.replace("<svg", '<svg class="ic"') + "<span>" + esc(t("delivery_hint")) + "</span></p>";
      wireAddressField($("#cartAddr"), $("#cartAddrSuggest"), $("#cartUseLocation"), null, function (v) {
        var ci = $("#custAddr"); if (ci) ci.value = v;
      });
    } else if (S.orderType === "room_service") {
      f.innerHTML = '<p class="ot-hint">' + ICONS.check.replace("<svg", '<svg class="ic"') + "<span>" + esc(t("room_hint")) + "</span></p>";
    } else { f.innerHTML = ""; }
  }
  function totalsHTML() {
    var T = totals();
    var h = '<div class="tr"><span>' + esc(t("subtotal")) + "</span><b>" + money(T.sub) + "</b></div>";
    if (T.disc) h += '<div class="tr disc"><span>' + esc(t("discount")) + " (" + esc(S.coupon.code) + ")</span><b>−" + money(T.disc) + "</b></div>";
    if (T.tax) h += '<div class="tr"><span>' + esc(CFG.taxLabel) + "</span><b>" + money(T.tax) + "</b></div>";
    if (T.del) h += '<div class="tr"><span>' + esc(t("delivery")) + "</span><b>" + money(T.del) + "</b></div>";
    if (T.tip) h += '<div class="tr"><span>' + esc(t("tip")) + "</span><b>" + money(T.tip) + "</b></div>";
    h += '<div class="tr grand"><span>' + esc(t("total")) + "</span><b>" + money(T.total) + "</b></div>";
    return h;
  }
  function renderTotals() {
    var T = totals();
    var box = $("#totalsBox"); if (box) box.innerHTML = totalsHTML();
    var below = T.sub > 0 && CFG.minOrderValue > 0 && T.sub < CFG.minOrderValue;
    var msg = $("#minOrderMsg");
    if (msg) { msg.hidden = !below; if (below) msg.textContent = "⚠ " + t("min_order") + " " + money(CFG.minOrderValue); }
    syncCartChips(false);
  }

  /* ── checkout ─────────────────────────────────────────── */
  function renderCheckout() {
    var addr = $("#addrBlock"); if (addr) addr.hidden = S.orderType !== "delivery";
    var tbl = $("#tableField"); if (tbl) tbl.classList.toggle("is-hidden", S.orderType !== "on-table");
    /* F17: the cart and the checkout both asked for a table number. The cart is
       the single source of truth; this field mirrors it so the diner is never
       asked the same question twice with two boxes that can disagree. */
    var tblIn = $("#tableInput"), tblSel = $("#tableSel");
    if (tblIn && tblSel && tblSel.value && !tblIn.readOnly) tblIn.value = tblSel.value;
    /* Room service is fulfilled in the guest's room, so like on-table it needs
       neither a phone number nor an address -- the room rides along on the hidden
       `room` field the shared hotel partial renders. */
    var ph = $("#phoneField"); if (ph) ph.style.display = (S.orderType !== "on-table" && S.orderType !== "room_service") ? "" : "none";
    /* Order summary — what the customer is about to pay for, tap Edit → cart. */
    var ck = $("#ckItems");
    if (ck) {
      ck.hidden = cart.length === 0 && comboCount() === 0;
      ck.innerHTML = '<div class="ck-head"><h3>' + esc(t("your_order")) + '</h3><button class="linkbtn" id="ckEdit" type="button">' + esc(t("edit")) + "</button></div>" +
        cart.map(function (l) {
          var i = item(l.id); if (!i) return "";
          var unit = lineUnit(l);
          /* Happy-hour strike-through: original_price is the pre-schedule BASE
             price, so the pre-discount unit is unit + (original − current base). */
          var wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
            ? unit + (Number(i.original_price) - Number(i.price)) : 0;
          var label = lineLabel(l);
          return '<div class="ck-row">' +
            '<img src="' + esc(firstImg(i)) + '" alt=""' + IMG_ERR + ">" +
            '<div class="ck-mid"><b>' + esc(i.name) + "</b>" + (label ? "<small>" + esc(label) + "</small>" : "") + "</div>" +
            '<span class="ck-qty">×' + l.qty + "</span>" +
            '<span class="ck-price">' + money(unit * l.qty) + (wasUnit ? "<del>" + money(wasUnit * l.qty) + "</del>" : "") + "</span></div>";
        }).join("") + comboGroupHTML(true);
      var ed = $("#ckEdit"); if (ed) ed.onclick = function () { show("cart"); };
    }
    $("#checkoutTotals").innerHTML = totalsHTML();
    $("#checkoutCtaTotal").textContent = money(totals().total);
  }
  /* F2 + F3 — one place that shows a checkout error.
     F2: the box used to render below the fold with no scroll-into-view, so a
         failed "Place order" looked like a dead button. It now lives inside the
         always-visible order band and announces itself to assistive tech.
     F3: whatever the server put in `message` used to be written with innerHTML,
         which printed raw PHP exception strings ("foreach() argument must be of
         type array|object, string given") verbatim to the diner — an information
         leak AND gibberish. Server text is only shown when it looks like a real
         human sentence; anything that smells like a stack trace / exception is
         replaced with a translated, actionable line. */
  function showFormError(msg, fromServer) {
    var err = $("#formError"); if (!err) return;
    var text = String(msg == null ? "" : msg).replace(/<[^>]*>/g, "").trim();
    if (fromServer) {
      var looksInternal = !text
        || text.length > 180
        || /(^|\s)(Exception|Error|Throwable|Fatal|SQLSTATE|Stack trace)\b/i.test(text)
        || /\b(argument|must be of type|Undefined|null given|given, called in)\b/i.test(text)
        || /(\.php\b|::|\$[a-z_]+\s*\()/i.test(text);
      if (looksInternal) text = t("order_failed");
    }
    err.textContent = text || t("order_failed");
    err.hidden = false;
    /* the band is fixed, so nothing needs scrolling — but focus moves there so a
       screen reader and a keyboard user both land on the reason. */
    try { err.focus({ preventScroll: true }); } catch (e) {}
  }
  function placeOrder() {
    var err = $("#formError"); if (err) err.hidden = true;
    if (!S.orderType) { showFormError(t("select_type")); return; }
    if (!cart.length && !comboCount()) { toast(t("cart_empty")); return; } /* combos-only orders are legal */
    var T = totals();
    if (CFG.minOrderValue > 0 && T.sub < CFG.minOrderValue) { showFormError(t("min_order") + " " + money(CFG.minOrderValue)); return; }
    var name = ($("#custName") || {}).value ? $("#custName").value.trim() : (CFG.customerName || "");
    if (!name) { showFormError(t("fill_contact")); return; }
    if (S.orderType === "delivery" && !$("#custAddr").value.trim()) { showFormError(t("fill_addr")); return; }
    if (CFG.authMode === "required" && !CFG.isLoggedIn) { sheet("authSheet"); return; }

    var payload = orderLines();
    var fd = new FormData();
    fd.append("_token", CFG.csrf);
    fd.append("items", JSON.stringify(payload));
    fd.append("name", name);
    fd.append("ordering-type", S.orderType);
    if (S.orderType === "on-table" || S.orderType === "room_service") fd.append("table", (($("#tableInput") || {}).value || ($("#tableSel") || {}).value || CFG.defaultTable || ""));
    else fd.append("phone-number", ($("#custPhone") || {}).value || "");
    if (S.orderType === "delivery") fd.append("address", $("#custAddr").value.trim());
    fd.append("pay_via", (root.querySelector(".pm.is-on") || {}).dataset ? root.querySelector(".pm.is-on").dataset.pm : "pay_on_counter");
    fd.append("tip_amount", String(T.tip || 0));
    fd.append("message", ($("#orderNote") || {}).value || "");
    var sched = $("#scheduledAt"); if (sched && sched.value) fd.append("scheduled_at", sched.value);
    if (S.coupon) fd.append("coupon_code", S.coupon.code);
    var uw = $("#mbUseWallet"); if (uw && uw.checked) fd.append("use_wallet", "1");
    var bid = new URLSearchParams(window.location.search).get("branch_id"); if (bid) fd.append("branch_id", bid);
    var capEmail = $("#captureEmail"), consent = $("#consentCb");
    if (consent && consent.checked) { fd.append("marketing_consent", "1"); if (capEmail && capEmail.value) fd.append("capture_email", capEmail.value); }
    var waOpt = $("#waOptin"); if (waOpt && waOpt.checked) fd.append("whatsapp_optin", "1");

    var btn = $("#placeOrder");
    var origLabel = btn.querySelector(".po-label").textContent;
    btn.disabled = true;
    btn.querySelector(".spinner").hidden = false;
    btn.querySelector(".po-label").textContent = "…";

    fetch(CFG.endpoints.submitOrder, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false; btn.querySelector(".spinner").hidden = true; btn.querySelector(".po-label").textContent = origLabel;
        if (res && res.success) {
          /* R6: remember this delivery address for the guest's next order (chips).
             R5: remember the order itself so the profile sheet can list it. Both
             run before the online-payment redirect so nothing is lost on the hop. */
          if (S.orderType === "delivery") { var _ca = $("#custAddr"); rememberAddress(_ca ? _ca.value : "", ($("#custLat") || {}).value, ($("#custLng") || {}).value); }
          rememberOrder(res);
          cart = []; writeCart(); syncCartChips(false); S.coupon = null;
          if (res.message) { window.location.href = res.message; return; }   /* online payment redirect */
          /* F15: submitOrder returns neither `order_no` nor `order_id`, so the old
             empty-string fallback shipped a blank dashed "#" pill on every single
             confirmation. Render the pill only when there is a real number to put
             in it. (A shared-code change would be the better fix — see
             SOIREE-B4 in the findings ledger.) */
          var codeEl = $("#confirmCode");
          var orderNo = res.order_no || res.order_id || null;
          if (codeEl) { codeEl.textContent = orderNo ? (t("order_label") + " #" + orderNo) : ""; codeEl.hidden = !orderNo; }
          $("#confirmTrack").href = res.track_url || "#";
          var eta = new Date(Date.now() + (S.orderType === "delivery" ? 40 : 15) * 60000);
          $("#confirmEta").textContent = t("est_ready") + " " + eta.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
          show("confirmed"); stack.length = 0;
          burstBubbles();
          if (res.whatsapp_url) mbWhatsAppHandoff(res.whatsapp_url, res.whatsapp_handoff);
          if (res.track_url) setTimeout(function () { window.location.href = res.track_url; }, 3400);
        } else {
          showFormError(res && res.message, true);
        }
      }).catch(function () {
        btn.disabled = false; btn.querySelector(".spinner").hidden = true; btn.querySelector(".po-label").textContent = origLabel;
        showFormError(t("network_error"));
      });
  }
  function burstBubbles() {
    if (RM) return;
    var b = $("#bubbles"); if (!b) return;
    var arr = [];
    for (var k = 0; k < 26; k++) {
      var tx = (Math.random() * 2 - 1) * 140, ty = -60 - Math.random() * 220;
      arr.push('<i class="' + (Math.random() > .5 ? "fill" : "") + '" style="--tx:' + tx.toFixed(0) + "px;--ty:" + ty.toFixed(0) + "px;--d:" + (Math.random() * .4).toFixed(2) + 's"></i>');
    }
    b.innerHTML = arr.join("");
  }

  /* ── search ───────────────────────────────────────────── */
  function openSearch() {
    var ov = $("#searchOv"); ov.hidden = false;
    requestAnimationFrame(function () { ov.classList.add("is-open"); });
    var cats = (CFG.cats || []).slice(0, 6);
    $("#soChips").innerHTML = cats.map(function (c) { return '<button data-sq="' + esc(c.name) + '" type="button">' + esc(c.name) + "</button>"; }).join("");
    $("#soResults").innerHTML = ""; $("#soInput").value = ""; $("#soSuggest").hidden = false;
    setTimeout(function () { $("#soInput").focus(); }, 80);
  }
  function closeSearch() { var ov = $("#searchOv"); ov.classList.remove("is-open"); setTimeout(function () { ov.hidden = true; }, 300); }
  function runSearch(q) {
    q = q.trim().toLowerCase();
    var box = $("#soResults");
    if (!q) { box.innerHTML = ""; $("#soSuggest").hidden = false; return; }
    $("#soSuggest").hidden = true;
    var list = ITEMS.filter(function (i) { return (String(i.name) + " " + String(i.desc) + " " + String(i.sub)).toLowerCase().indexOf(q) !== -1; });
    box.innerHTML = list.length
      ? list.map(function (i, ix) { return rowHTML(i, ix); }).join("")
      : '<div class="grid-empty"><svg class="empty-art" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg><p>' + esc(t("search_none")) + "</p></div>";
  }

  /* ── review stars ─────────────────────────────────────── */
  /* buildStars still powers the track-review stars (#revStars). The old product
     quick-rate (#pRateStars + submitReview) was removed with the element it drove
     (F20) — the shared reviews layer under #pReviews now owns list + submit. */
  function buildStars(sel) {
    var el = $(sel); if (!el) return;
    el.innerHTML = [1, 2, 3, 4, 5].map(function (n) { return '<button data-rate="' + n + '" aria-label="' + n + '" type="button">' + ICONS.star + "</button>"; }).join("");
  }

  /* ── delegated events ─────────────────────────────────── */
  root.addEventListener("click", function (e) {
    var T = e.target;
    function closest(s) { return T.closest ? T.closest(s) : null; }

    var fav = closest("[data-fav]");
    if (fav) {
      e.stopPropagation(); e.preventDefault();
      var fid = String(fav.dataset.fav);
      favs.has(fid) ? favs.delete(fid) : favs.add(fid);
      writeFavs();
      var nowOn = favs.has(fid);
      fav.classList.toggle("is-on", nowOn);
      fav.setAttribute("aria-pressed", nowOn ? "true" : "false");
      var nm = (item(fid) || {}).name || "";
      fav.setAttribute("aria-label", (nowOn ? t("remove_favorite") : t("add_favorite")) + (nm ? ": " + nm : ""));
      if (active === "browse" && S.browseMode === "favs") renderBrowse();
      return;
    }
    var add = closest("[data-add]");
    if (add) {
      e.stopPropagation(); e.preventDefault();
      var ai = item(add.dataset.add); if (!ai) return;
      if (complex(ai)) { openProduct(ai.id); return; }
      addToCart(ai.id, 0, [], [], 1, add);
      return;
    }
    /* R7 — same-page ?item= links: grid card anchors (hcard/m-main) + share/CTA
       links. Checked AFTER the controls above so a click on a row's ＋/heart (a
       sibling button, each preventDefaults) wins. Same-pathname item links open in
       place; real external links full-load. Boot handles a cold ?item= / new-tab load. */
    var itemLink = closest('a[href*="item="]');
    if (itemLink && itemLink.getAttribute("href")) {
      try {
        var _u = new URL(itemLink.href, location.href);
        if (_u.pathname === location.pathname) {
          var _iid = _u.searchParams.get("item");
          if (_iid && item(_iid)) {
            e.preventDefault();
            var _sov = $("#searchOv"); if (_sov && !_sov.hidden) closeSearch();
            openProduct(_iid); return;
          }
        }
      } catch (e0) {}
    }

    var open = closest("[data-open]");
    if (open) { var sov = $("#searchOv"); if (sov && !sov.hidden) closeSearch(); openProduct(open.dataset.open); return; }
    var go = closest("[data-go]");
    if (go) { show(go.dataset.go); return; }
    if (closest("[data-back]")) { back(); return; }

    var tab = closest(".pn-slot[data-tab]");
    if (tab) { show(tab.dataset.tab, { push: false }); return; }
    var htab = closest("[data-htab]");
    if (htab) { setHtab(htab.dataset.htab); return; }

    var pt = closest("[data-paytile]");
    if (pt) { if (!cart.length && !comboCount()) { toast(t("cart_empty")); return; } show("checkout"); return; }

    var cat = closest("#catChips [data-cat]");
    if (cat) { S.cat = cat.dataset.cat; renderCatChips(); renderMenuList(); return; }
    var bcat = closest("#browseChips [data-bcat]");
    if (bcat) { S.bcat = bcat.dataset.bcat; S.bsub = "root"; renderCatChips(); renderBrowse(); return; }
    var bsub = closest("#browseSubChips [data-sub]");
    if (bsub) { S.bsub = bsub.dataset.sub; renderBrowseSubChips(); renderBrowse(); return; }
    var bm = closest("#browseSeg [data-bm]");
    if (bm) { S.browseMode = bm.dataset.bm; $$("#browseSeg button").forEach(function (b) { b.classList.toggle("is-on", b === bm); }); renderBrowse(); return; }
    /* list ⇄ grid layout toggle (home + menu share one choice) */
    var vm = closest("[data-vm]");
    if (vm) {
      if (S.viewMode !== vm.dataset.vm) { S.viewMode = vm.dataset.vm; writeVM(); syncVMToggles(); renderMenuList(); renderBrowse(); }
      return;
    }

    /* product variant / option / extra */
    var vOpt = closest("[data-variant]");
    if (vOpt && S.current) {
      S.pVariant = Number(vOpt.dataset.variant);
      $$("[data-variant]", vOpt.closest(".optchips")).forEach(function (o) { o.classList.toggle("is-on", Number(o.dataset.variant) === S.pVariant); });
      updateProductCta(); return;
    }
    var gOpt = closest(".opt-group[data-group] .optchip");
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
        else { toast(t("max") + " " + max); return; }
      } else { cur = (cur.length && cur[0].optIndex === mod.optIndex) ? [] : [mod]; }
      S.pMods[gid] = cur;
      $$(".optchip", grp).forEach(function (o) { o.classList.toggle("is-on", cur.some(function (m) { return m.optIndex === Number(o.dataset.opt); })); });
      updateProductCta(); return;
    }
    var xOpt = closest("[data-extra]");
    if (xOpt && S.current) {
      var xid = xOpt.dataset.extra;
      S.pExtras[xid] = !S.pExtras[xid];
      xOpt.classList.toggle("is-on", S.pExtras[xid]);
      updateProductCta(); return;
    }
    var q = closest("#qtyCol [data-q]");
    if (q) { setQty(S.pQty + Number(q.dataset.q), Number(q.dataset.q)); return; }

    /* cart qty / note / remove */
    var cq = closest("[data-cq]");
    if (cq) {
      var line = cart.filter(function (l) { return l.key === cq.closest(".cline").dataset.key; })[0];
      if (!line) return;
      line.qty += Number(cq.dataset.cq);
      if (line.qty <= 0) cart = cart.filter(function (l) { return l !== line; });
      writeCart(); renderCart(); return;
    }
    var noteBtn = closest("[data-note]");
    if (noteBtn) {
      S.noteKey = noteBtn.closest(".cline").dataset.key;
      var l2 = cart.filter(function (l) { return l.key === S.noteKey; })[0];
      $("#noteText").value = (l2 && l2.note) || "";
      sheet("noteSheet"); return;
    }
    var rm = closest("[data-rm]");
    if (rm) {
      var row = rm.closest(".cline"); row.classList.add("removing");
      var key = row.dataset.key;
      setTimeout(function () { cart = cart.filter(function (l) { return l.key !== key; }); writeCart(); renderCart(); toast(t("removed")); }, 300);
      return;
    }

    /* order type */
    var ot = closest("#orderTypeSeg [data-ot]");
    if (ot) { S.orderType = ot.dataset.ot; renderOtFields(); renderCheckout(); renderTotals(); return; }

    /* payment / tip */
    var pm = closest("#pmList [data-pm]");
    if (pm) { S.pay = pm.dataset.pm; $$("#pmList .pm").forEach(function (b) { b.classList.toggle("is-on", b === pm); }); return; }
    var tip = closest("#tipRow [data-tip]");
    if (tip) {
      $$("#tipRow button").forEach(function (b) { b.classList.toggle("is-on", b === tip); });
      var custom = $("#customTip");
      if (tip.dataset.tip === "custom") { S.tipPct = 0; S.tipCustom = Number(custom.value) || 0; if (custom) custom.hidden = false; }
      else { S.tipPct = Number(tip.dataset.tip); S.tipCustom = null; if (custom) custom.hidden = true; }
      renderCheckout(); return;
    }

    /* filter sheet */
    var diet = closest("#dietRow [data-diet]");
    if (diet) { S.filters.diets[diet.dataset.diet] = !S.filters.diets[diet.dataset.diet]; diet.classList.toggle("is-on"); return; }
    var al = closest("#allergenRow [data-al]");
    if (al) { S.filters.allergens[al.dataset.al] = !S.filters.allergens[al.dataset.al]; al.classList.toggle("is-on"); return; }
    var so = closest("#sortRow [data-sort]");
    if (so) { S.sort = so.dataset.sort; $$("#sortRow button").forEach(function (b) { b.classList.toggle("is-on", b === so); }); return; }

    /* product quick-rate — the inline "Rate this dish" stars open the shared
       review write-sheet preselected to the tapped rating, so a rating and a
       written review are one flow through one endpoint (mbItemReviews). */
    var qr = closest("#rateStars [data-rate]");
    if (qr) {
      var qn = Number(qr.dataset.rate);
      $$("#rateStars button").forEach(function (b, bi) { b.classList.toggle("is-on", bi < qn); });
      if (window.mbItemReviews) window.mbItemReviews.open(qn);
      return;
    }

    /* track review stars */
    var rate = closest("#revStars [data-rate]");
    if (rate) {
      var rn = Number(rate.dataset.rate);
      $$("#revStars button").forEach(function (b, bi) { b.classList.toggle("lit", bi < rn); });
      var gv = $("#revGoogle"); if (gv) gv.hidden = rn < 4;
      var th = $("#revThanks"); if (th) th.hidden = rn >= 4;
      return;
    }

    /* language / currency */
    var lg = closest("[data-lang-code]");
    if (lg) { setCookie("Quick_lang", lg.dataset.langName); setCookie("Quick_user_lang", lg.dataset.langName); setCookie("Quick_user_lang_code", lg.dataset.langCode); navigateLanguage(lg.dataset.langCode); return; }
    var cu = closest("[data-cur]");
    if (cu) {
      S.curCode = cu.dataset.cur; S.curRate = parseFloat(cu.dataset.rate) || 1;
      $$("[data-cur]").forEach(function (b) { b.classList.toggle("is-on", b === cu); });
      rerenderMoney(); if (window.mbCurrency) mbCurrency.save(cu.dataset.cur, cu.dataset.rate); return;
    }

    /* search suggestion */
    var sq = closest("#soChips [data-sq]");
    if (sq) { $("#soInput").value = sq.dataset.sq; runSearch(sq.dataset.sq); return; }
  });

  function rerenderMoney() {
    renderHero(); renderDaily(); renderMenuList(); renderHappy(); renderBrowse();
    if (S.current) setProduct(S.current.id, false);
    renderCart(); renderCheckout(); syncCartChips(false);
  }
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

  on("splashSkip", "click", function () { $("#splash").classList.add("is-done"); });
  on("navSearch", "click", openSearch);
  on("soClose", "click", closeSearch);
  on("soInput", "input", function (e) { runSearch(e.target.value); });
  on("profileBtn", "click", function () { renderRecentOrders(); sheet("profileSheet"); });
  /* Home header carries its own Profile & Settings button; same sheet. */
  on("homeProfileBtn", "click", function () { renderRecentOrders(); sheet("profileSheet"); });
  /* Dock favourites: open the browse view already switched to its Favorites mode. */
  on("navFavs", "click", function () { show("browse"); var seg = document.querySelector('#browseSeg [data-bm="favs"]'); if (seg) seg.click(); });
  /* Dock profile: same destination as the home avatar button. */
  on("navProfile", "click", function () { renderRecentOrders(); sheet("profileSheet"); });
  /* Globe in the home header — only rendered for stores with enough
     languages that the inline segment would wrap. */
  on("homeLangBtn", "click", function () { sheet("langSheet"); });
  on("cvCateringBtn", "click", function () { sheet("cvCateringSheet"); });
  on("reserveBtn", "click", function () { sheet("reserveSheet"); });
  on("clearCart", "click", function () { cart = []; writeCart(); renderCart(); syncCartChips(false); });
  on("placeOrder", "click", placeOrder);
  on("customTip", "input", function (e) { S.tipCustom = Number(e.target.value) || 0; renderCheckout(); });
  on("pAddBtn", "click", function () { if (!S.current) return; addToCart(S.current.id, S.pVariant, selectedMods(), selectedExtras(), S.pQty, null); });
  on("pFav", "click", function () { if (!S.current) return; var fid = String(S.current.id); favs.has(fid) ? favs.delete(fid) : favs.add(fid); writeFavs(); $("#pFav").classList.toggle("is-on"); });
  on("browseFilter", "click", function () { sheet("filterSheet"); });
  on("browseEmptyClear", "click", function () {
    S.filters = { diets: {}, allergens: {} }; S.sort = "featured"; S.bsub = "root";
    $$("#dietRow button,#allergenRow button").forEach(function (b) { b.classList.remove("is-on"); });
    $$("#sortRow button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.sort === "featured"); });
    renderBrowse();
  });
  on("filterDone", "click", function () { sheet("filterSheet", false); renderBrowse(); });
  on("filterReset", "click", function () {
    S.filters = { diets: {}, allergens: {} }; S.sort = "featured";
    $$("#dietRow button,#allergenRow button").forEach(function (b) { b.classList.remove("is-on"); });
    $$("#sortRow button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.sort === "featured"); });
    renderBrowse();
  });
  on("loyaltyLogin", "click", function () { sheet("authSheet"); });
  on("meAuthBtn", "click", function () { sheet("authSheet"); });
  on("loginLink", "click", function () { sheet("authSheet"); });

  /* note save */
  on("noteSave", "click", function () {
    var l = cart.filter(function (x) { return x.key === S.noteKey; })[0];
    if (l) { l.note = $("#noteText").value.trim(); writeCart(); renderCart(); }
    sheet("noteSheet", false); toast(t("note_saved"));
  });

  /* coupon — F19: an applied coupon is now a removable chip. Before this there was
     no chip, no ✕ and no way to drop a code except retyping over the field. */
  function paintCoupon() {
    var field = $("#couponField"), chip = $("#couponChip");
    var on = !!S.coupon;
    if (field) { field.classList.toggle("is-applied", on); field.hidden = on; }
    if (chip) {
      chip.hidden = !on;
      if (on) {
        var T = totals();
        $("#couponChipCode").textContent = S.coupon.code;
        $("#couponChipAmt").textContent = "−" + money(T.disc);
      }
    }
  }
  function clearCoupon() {
    S.coupon = null;
    var input = $("#couponInput"); if (input) input.value = "";
    var msg = $("#couponMsg"); if (msg) msg.hidden = true;
    paintCoupon(); renderTotals();
  }
  on("couponClear", "click", clearCoupon);
  on("couponApply", "click", function () {
    var input = $("#couponInput"), msg = $("#couponMsg");
    var code = input.value.trim(); if (!code) return;
    msg.hidden = true;
    fetch(CFG.endpoints.coupon, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf },
      body: couponBody(code),
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (res && res.success) {
        S.coupon = { code: res.label || code, coupon_type: res.coupon_type, percentage: res.percentage, discount: Number(res.discount) || 0, sig: couponSig() };
        msg.hidden = true;
      } else {
        S.coupon = null; msg.hidden = false; msg.className = "coupon-msg err";
        msg.textContent = "✕ " + ((res && res.message) || t("coupon_bad"));
      }
      renderTotals(); paintCoupon();
    }).catch(function () { msg.hidden = false; msg.className = "coupon-msg err"; msg.textContent = "✕ " + t("coupon_bad"); });
  });
  on("couponInput", "keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); var b = document.getElementById("couponApply"); if (b) b.click(); } });

  /* waiter / bill */
  function waiterCall(type) {
    var tbl = ($("#tableInput") || {}).value || ($("#tableSel") || {}).value || CFG.defaultTable || "";
    fetch(CFG.endpoints.waiter, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf },
      body: JSON.stringify({ table: tbl, type: type, _token: CFG.csrf }),
    }).then(function (r) { return r.json(); }).then(function () {
      toast(type === "request_bill" ? t("bill_ok") : t("waiter_ok"));
    }).catch(function () {});
  }
  on("callWaiter", "click", function () { waiterCall("call_waiter"); });
  on("requestBill", "click", function () { waiterCall("request_bill"); });

  /* NPS lives on the order-tracking page now (F20) — this binding is a no-op
     inside the app shell, where no NPS row is rendered. */
  $$("#npsRow button").forEach(function (b) {
    b.addEventListener("click", function () {
      $$("#npsRow button").forEach(function (x) { x.classList.remove("is-on"); });
      b.classList.add("is-on");
      fetch(CFG.endpoints.nps, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ score: Number(b.dataset.nps) }) }).catch(function () {});
      $("#npsThanks").hidden = false;
    });
  });

  /* auth */
  $$("#authTabs button").forEach(function (b) {
    b.addEventListener("click", function () {
      $$("#authTabs button").forEach(function (x) { x.classList.toggle("is-on", x === b); });
      $$(".auth-form[data-auth]").forEach(function (f) { f.hidden = f.dataset.auth !== b.dataset.authTab; });
    });
  });
  function authPost(url, body, errEl) {
    return fetch(url, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf, "X-Requested-With": "XMLHttpRequest" }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.success) { window.location.reload(); return res; }
        if (errEl) { errEl.textContent = (res && res.message) || "Failed."; errEl.hidden = false; }
        return res;
      });
  }
  on("loginBtn", "click", function () { authPost(CFG.endpoints.login, { store_id: CFG.storeId, email: $("#loginEmail").value.trim(), password: $("#loginPass").value }, $("#authErr")); });
  on("registerBtn", "click", function () { authPost(CFG.endpoints.register, { store_id: CFG.storeId, name: $("#regName").value.trim(), email: $("#regEmail").value.trim(), phone: $("#regPhone").value.trim(), password: $("#regPass").value, password_confirmation: ($("#regPass2") || {}).value || "" }, $("#authErr")); });
  on("otpSend", "click", function () {
    var phone = $("#otpPhone").value.trim(); if (!phone) return;
    fetch(CFG.endpoints.otpRequest, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ store_id: CFG.storeId, phone: phone }) })
      .then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.success) { $("#otpStep1").hidden = true; $("#otpStep2").hidden = false; }
        else { var e2 = $("#authErr"); e2.textContent = (res && res.message) || "Failed."; e2.hidden = false; }
      }).catch(function () {});
  });
  on("otpVerify", "click", function () {
    var code = $$("#otpBoxes input").map(function (i) { return i.value; }).join("");
    authPost(CFG.endpoints.otpVerify, { store_id: CFG.storeId, phone: $("#otpPhone").value.trim(), code: code }, $("#authErr"));
  });
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

  /* brand / follow row (crave F2 parity): toggles the store follow via the
     CustomerToken-guarded endpoint. Guests are sent to the sign-in sheet
     instead of hitting the API with no token. */
  (function () {
    var btn = document.getElementById("soFollowBtn");
    if (!btn || !CFG.endpoints.follow) return;
    function followHeaders(json) {
      var h = { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" };
      if (json) h["Content-Type"] = "application/json";
      if (CFG.csrf) h["X-CSRF-TOKEN"] = CFG.csrf;
      if (CFG.customerToken) h["Authorization"] = "Bearer " + CFG.customerToken;
      return h;
    }
    function applyFollowState(d) {
      if (!d || typeof d.following === "undefined") return false;
      btn.dataset.following = d.following ? "1" : "0";
      btn.classList.toggle("is-on", d.following);
      var c = document.getElementById("soFollowerCount");
      if (c && typeof d.follower_count !== "undefined") c.textContent = d.follower_count;
      return true;
    }
    btn.addEventListener("click", function () {
      if (!CFG.customerToken) { sheet("authSheet"); return; }
      var wasFollowing = btn.dataset.following === "1";
      btn.dataset.following = wasFollowing ? "0" : "1";
      btn.classList.toggle("is-on", !wasFollowing);
      fetch(CFG.endpoints.follow, { method: wasFollowing ? "DELETE" : "POST", headers: followHeaders(true), body: "{}" })
        .then(function (r) { return r.json(); }).then(function (d) {
          if (!applyFollowState(d)) throw new Error("bad");
        }).catch(function () {
          btn.dataset.following = wasFollowing ? "1" : "0";
          btn.classList.toggle("is-on", wasFollowing);
          toast(t("action_failed"));
        });
    });
  })();

  /* order lookup */
  on("lookupBtn", "click", function () {
    var val = $("#lookupInput").value.trim(); if (!val) return;
    var box = $("#lookupResults"); box.innerHTML = "…";
    fetch(CFG.endpoints.orderLookup, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ identifier: val }) })
      .then(function (r) { return r.json(); }).then(function (data) {
        if (!data.success) { box.innerHTML = '<p class="orders-empty">' + esc(data.message || "") + "</p>"; return; }
        box.innerHTML = data.orders.map(function (o) {
          return '<a class="order-row" href="' + esc(o.track_url) + '"><div><b>#' + esc(o.id) + " · " + esc(o.total) + "</b><small>" + esc(o.date) + '</small></div><span class="order-status">' + esc(o.status) + "</span></a>";
        }).join("");
      }).catch(function () { box.innerHTML = ""; });
  });

  /* reservation / waitlist sheets */
  on("resOpen", "click", function () { sheet("reserveSheet"); });
  on("waitlistOpen", "click", function () { sheet("waitlistSheet"); });
  on("resMinus", "click", function () { S.resParty = Math.max(1, S.resParty - 1); $("#resParty").textContent = S.resParty; $("#resPartyInput").value = S.resParty; });
  on("resPlus", "click", function () { S.resParty = Math.min(20, S.resParty + 1); $("#resParty").textContent = S.resParty; $("#resPartyInput").value = S.resParty; });
  if (CFG.openResSheet && document.getElementById("reserveSheet")) { sheet("profileSheet"); setTimeout(function () { sheet("reserveSheet"); }, 60); }
  if (CFG.openWaitlistSheet && document.getElementById("waitlistSheet")) { sheet("profileSheet"); setTimeout(function () { sheet("waitlistSheet"); }, 60); }

  /* loyalty redeem */
  on("loyaltyRedeem", "click", function () {
    var rb = $("#loyaltyRedeem"); rb.disabled = true;
    fetch(CFG.endpoints.loyaltyRedeem, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: "{}" })
      .then(function (r) { return r.json(); }).then(function (d) {
        if (d && d.success) { rb.hidden = true; $("#loyaltyOk").hidden = false; var big = $("#ptsBig"); if (big) big.textContent = d.new_balance != null ? d.new_balance : big.textContent; $$("#stampDots .stamp-dot").forEach(function (dot) { dot.classList.remove("is-on"); }); var fl = $("#ptsFill"); if (fl) fl.style.width = "0%"; }
        else { rb.disabled = false; toast((d && d.error) || t("coupon_bad")); }
      }).catch(function () { rb.disabled = false; });
  });

  /* pillnav hide on scroll down (roots) */
  var lastY = 0;
  $$(".view-scroll").forEach(function (sc) {
    sc.addEventListener("scroll", function () {
      if (ROOTS.indexOf(active) === -1) return;
      var y = sc.scrollTop, pill = $("#pillnav");
      if (!pill) return;
      pill.classList.toggle("is-hidden", y > lastY + 4 && y > 140);
      if (y < lastY - 4 || y < 140) pill.classList.remove("is-hidden");
      lastY = y;
    }, { passive: true });
  });

  /* reorder handoff from account page */
  /* Shared engine (_shared/js/reorder-pickup.js, loaded by store_templates.layout):
     resolves the server's choice-group selections + note onto this theme's
     cart-line shape so a reordered item keeps its modifiers and note. */
  window.__soireePickup = function (line) {
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
    var r = window.JuwriReorder.pickup(slug, window.__soireePickup, { toast: toast });
    if (r.added) { writeCart(); syncCartChips(true); if (!r.unavailable.length) toast(t("added")); }
  }

  /* ── appearance (F6) ──────────────────────────────────────
     soirée is a dark theme and stays one by default. "System" follows the OS
     (style.css carries a real light palette behind prefers-color-scheme), and the
     diner can pin either face. Persisted per store so it survives navigation. */
  var APPEARANCE_KEY = "soiree-appearance-" + slug;
  function applyAppearance(mode) {
    /* dark is the theme's identity and its default; "light" pins the daytime
       palette and "auto" hands the choice to the OS (see style.css). */
    if (mode === "light" || mode === "auto") root.setAttribute("data-appearance", mode);
    else { root.removeAttribute("data-appearance"); mode = "dark"; }
    $$("#appearanceSeg [data-appearance]").forEach(function (b) {
      b.classList.toggle("is-on", b.dataset.appearance === mode);
    });
  }
  (function initAppearance() {
    var saved = null;
    try { saved = localStorage.getItem(APPEARANCE_KEY); } catch (e) {}
    applyAppearance(saved || "dark");
    var seg = document.getElementById("appearanceSeg");
    if (!seg) return;
    seg.addEventListener("click", function (e) {
      var b = e.target.closest("[data-appearance]"); if (!b) return;
      var mode = b.dataset.appearance;
      try { mode === "dark" ? localStorage.removeItem(APPEARANCE_KEY) : localStorage.setItem(APPEARANCE_KEY, mode); } catch (err) {}
      applyAppearance(mode);
    });
  })();

  /* ── guest order history (R5) ─────────────────────────────
     A guest who orders without an account still gets a short, on-device list of
     their recent orders on the profile sheet, above the server phone/email
     lookup (which stays as the cross-device fallback). Keyed per store. */
  function recentOrdersKey() { return "soiree_recent_orders_" + slug; }
  function readRecentOrders() {
    try { return (JSON.parse(localStorage.getItem(recentOrdersKey()) || "[]") || []).filter(function (o) { return o && o.track_url; }); }
    catch (e) { return []; }
  }
  function rememberOrder(res) {
    if (!res || !res.track_url) return;
    var l = readRecentOrders().filter(function (o) { return o.track_url !== res.track_url; });
    l.unshift({ id: res.order_no || res.order_id || "", track_url: res.track_url, total: res.total || "", placed_at: Date.now() });
    try { localStorage.setItem(recentOrdersKey(), JSON.stringify(l.slice(0, 10))); } catch (e) {}
    renderRecentOrders();
  }
  function renderRecentOrders() {
    var box = $("#soRecentOrders"); if (!box) return;   /* guest branch only */
    var l = readRecentOrders();
    if (!l.length) { box.hidden = true; box.innerHTML = ""; return; }
    box.hidden = false;
    box.innerHTML = l.map(function (o) {
      var ds = "";
      if (o.placed_at) {
        var lang = document.documentElement.getAttribute("lang");
        try { ds = new Date(o.placed_at).toLocaleDateString(lang ? [lang] : [], { month: "short", day: "numeric" }); } catch (e) { ds = ""; }
      }
      var head = o.id ? "#" + esc(o.id) : esc(t("your_order"));
      return '<a class="order-row" href="' + esc(o.track_url) + '"><div><b>' + head + (o.total ? " · " + esc(o.total) : "") + "</b>" +
        (ds ? "<small>" + esc(ds) + "</small>" : "") + '</div><svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg></a>';
    }).join("");
  }

  /* ── guest saved addresses (R6) ───────────────────────────
     A guest's last few typed delivery addresses become tap-to-fill chips in the
     checkout address block — the same #savedAddrs the server fills for logged-in
     customers, so the click wiring below is shared. Guests only; the server
     address book owns logged-in users. KEY is computed inline (addrKey()), never
     a hoisted `var KEY = …` — that reads `undefined` at load and paints 0 chips. */
  function addrKey() { return "soiree_recent_addrs_" + slug; }
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
  function renderRecentAddrs() {
    var saved = $("#savedAddrs"); if (!saved || CFG.isLoggedIn) return;
    var l = readRecentAddrs();
    if (!l.length) { saved.hidden = true; saved.innerHTML = ""; return; }
    saved.hidden = false;
    saved.innerHTML = l.map(function (a, i) {
      return '<button class="saved-addr' + (i === 0 ? " is-on" : "") + '" type="button" data-addr="' + esc(a.addr) + '" data-lat="' + esc(a.lat) + '" data-lng="' + esc(a.lng) + '">' +
        '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
        '<span class="sa-text"><b>' + esc(a.addr) + "</b></span></button>";
    }).join("");
  }

  /* ── boot ─────────────────────────────────────────────── */
  function boot() {
    pickupReorder();
    /* default order type: room service > forced table > first enabled.
       The room-service control only renders when the scanned QR resolved to a
       hotel room, so its presence IS the "this guest is in a room" signal — a
       room QR also sets forceTable, so without this branch the guest would boot
       on dine-in and silently send the order to the kitchen as a table order. */
    var otBtns = $$("#orderTypeSeg [data-ot]");
    if (otBtns.some(function (b) { return b.dataset.ot === "room_service"; })) S.orderType = "room_service";
    else if (CFG.forceTable && otBtns.some(function (b) { return b.dataset.ot === "on-table"; })) S.orderType = "on-table";
    else if (otBtns.length) S.orderType = otBtns[0].dataset.ot;

    /* F16: `toLocaleDateString([])` uses the BROWSER's locale, so an Arabic UI
       rendered "Tuesday, July 21" in English next to Arabic headings. Follow the
       document's own language (set by the storefront locale), and fall back to
       the browser only if the page never declared one. */
    var td = $("#tonightDate");
    if (td) {
      var docLang = document.documentElement.getAttribute("lang") || "";
      var loc = docLang ? [docLang] : [];
      try {
        td.textContent = new Date().toLocaleDateString(loc, { weekday: "long", month: "long", day: "numeric" });
      } catch (e) {
        td.textContent = new Date().toLocaleDateString([], { weekday: "long", month: "long", day: "numeric" });
      }
    }

    renderHero(); renderDaily(); renderCatChips(); renderMenuList(); renderHappy();
    renderBrowse(); syncVMToggles();
    /* AL-10: host the shared language-suggest banner in-flow as soirée's FIRST
       section (it ships fixed to the viewport bottom, covering content). Moving the
       node keeps the shared reveal script working — it finds the same id. No-op when
       the banner isn't offered (owner/preview/kiosk/≤1 language). */
    (function () {
      var ls = document.getElementById("qc-lang-suggest");
      var mount = $('.view[data-view="home"] .view-scroll');
      if (ls && mount && ls.parentElement !== mount) mount.insertBefore(ls, mount.firstElementChild);
      /* GOL claim toast: pull it under the theme root (not into a transformed .view,
         so its position:fixed stays viewport-anchored) so soirée's CSS can dress it. */
      var cb = document.getElementById("gol-claim-banner");
      var app = document.querySelector(".soiree");
      if (cb && app && cb.parentElement !== app) app.appendChild(cb);
    })();
    renderOtFields(); syncCartChips(false);
    buildStars("#revStars");
    renderRecentOrders();   /* R5: guest recent-orders list on the profile sheet */

    /* restore currency selection UI — replay saved store-scoped choice (shared helper) */
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
      else if (sessionStorage.getItem("mt_splash_" + slug)) splash.classList.add("is-done");
      else { sessionStorage.setItem("mt_splash_" + slug, "1"); setTimeout(function () { splash.classList.add("is-done"); }, 2400); }
    }

    /* R7 — open a product straight from a ?item=<id> deeplink on first load. */
    var deepItem = new URLSearchParams(location.search).get("item");
    if (deepItem && item(deepItem)) openProduct(deepItem);
  }

  /* ── delivery address autocomplete + use-my-location ────
     Shared by the checkout field (#custAddr, wired once below) and the cart's
     delivery field (#cartAddr, re-wired by renderOtFields each time it is
     rebuilt). Coordinates always land in the checkout's hidden #custLat/#custLng
     pair — the order payload reads only those. */
  function wireAddressField(input, box, locBtn, attrib, onValue) {
    if (!input || !CFG.endpoints || !CFG.endpoints.geoAutocomplete) return;
    var timer = null, lastAttrib = "";
    function setAttrib(a) { if (a && attrib && a !== lastAttrib) { attrib.textContent = a; attrib.hidden = false; lastAttrib = a; } }
    function hideBox() { if (box) { box.hidden = true; box.innerHTML = ""; } }
    function setCoords(lat, lng) { var la = $("#custLat"), ln = $("#custLng"); if (la) la.value = lat || ""; if (ln) ln.value = lng || ""; }
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
    var input = $("#custAddr");
    if (!input) return;
    wireAddressField(input, $("#custAddrSuggest"), $("#custUseLocation"), $("#custGeoAttrib"), null);
    function setCoords(lat, lng) { var la = $("#custLat"), ln = $("#custLng"); if (la) la.value = lat || ""; if (ln) ln.value = lng || ""; }
    var saved = $("#savedAddrs");
    if (saved) saved.addEventListener("click", function (e) {
      var b = e.target.closest(".saved-addr"); if (!b) return;
      input.value = b.dataset.addr || "";
      setCoords(b.dataset.lat, b.dataset.lng);
      $$(".saved-addr", saved).forEach(function (x) { x.classList.remove("is-on"); });
      b.classList.add("is-on");
    });
    renderRecentAddrs();   /* R6: paint the guest's remembered chips into #savedAddrs */
  })();

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
      $("#branchGeoList").innerHTML = branches.map(function (b) {
        var dist = b.distance_km != null ? '<span class="branch-dist" dir="ltr" style="margin-inline-start:auto;font-size:12px;opacity:.7">' + b.distance_km.toFixed(1) + " km</span>" : "";
        return '<button class="branch-row" data-bid="' + b.id + '" type="button">' +
          '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
          "<b>" + esc(b.name) + (b.address ? "<small>" + esc(b.address) + "</small>" : "") + "</b>" + dist +
          '<span class="branch-check"></span></button>';
      }).join("") || '<p class="orders-empty">' + esc("No branches") + "</p>";
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
        $("#branchMapAttrib").textContent = (d && d.attribution) || "© OpenStreetMap contributors";
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

  boot();

  /* Collapsing header — condensed bar in once the moody cover header leaves the
     home scroll container. soirée's category chips are not top-sticky, so no
     overlap guard. Condensed buttons forward their click to the real controls. */
  (function mountCondensedHeader() {
    var homeView = document.querySelector('.view[data-view="home"]');
    if (!homeView) return;
    var anchor = homeView.querySelector(".soiree-cover") || homeView.querySelector(".home-top");
    var bar = homeView.querySelector(".cv-condensed");
    var scroller = homeView.querySelector(".view-scroll");
    if (!anchor || !bar || !scroller || !("IntersectionObserver" in window)) return;
    var io = new IntersectionObserver(function (es) {
      var e = es[0];
      if (e) bar.classList.toggle("is-on", e.intersectionRatio < 0.05);
    }, { root: scroller, threshold: [0, 0.05, 1] });
    io.observe(anchor);
    var T = { lang: "#homeLangBtn", catering: "#cvCateringBtn", notif: "#mbNotifBell" };
    Array.prototype.forEach.call(bar.querySelectorAll("[data-cv-proxy]"), function (b) {
      b.addEventListener("click", function () {
        var t = homeView.querySelector(T[b.getAttribute("data-cv-proxy")]);
        if (t) t.click();
      });
    });
  })();
})();
