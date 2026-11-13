// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ═══════════════════════════════════════════════════════════
   dough — production runtime (faithful port of rev/dough/demo.js)
   Real menu data + real ordering/OTP/coupon/waiter/loyalty endpoints.
   Mock catalog / stub actions from the demo are replaced with the
   live window.DOUGH contract injected by index.blade.php.
   ═══════════════════════════════════════════════════════════ */
(function () {
  "use strict";
  var CFG = window.DOUGH;
  if (!CFG) return;
  var root = document.getElementById("dough");
  if (!root) return;

  var T = CFG.i18n || {};
  var ITEMS = CFG.items || {};
  var CATS = CFG.categories || [];
  var EP = CFG.endpoints || {};

  /* ── dom helpers ──────────────────────────────────────── */
  function $(s) { return root.querySelector(s); }
  function $$(s) { return Array.prototype.slice.call(root.querySelectorAll(s)); }
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }
  /* Crawlable + new-tab-able per-item URL: keeps existing query (table, lang…)
     and sets ?item=ID — the deep-link the R7 intercept + boot loader understand. */
  function itemHref(id) {
    try { var u = new URL(window.location.href); u.searchParams.set("item", id); u.hash = ""; return u.pathname + u.search; }
    catch (e) { return "?item=" + encodeURIComponent(id); }
  }
  function setCookie(k, v) { document.cookie = k + "=" + encodeURIComponent(v) + ";path=/;max-age=" + (60 * 60 * 24 * 365); }
  function item(id) { return ITEMS[id] || ITEMS[String(id)] || null; }
  function itemList() { return Object.keys(ITEMS).map(function (k) { return ITEMS[k]; }); }

  var ICONS = {
    plus: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg>',
    minus: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg>',
    trash: '<svg class="ic" viewBox="0 0 24 24"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-9 0 1 12a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2l1-12"/></svg>',
    locate: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>',
    /* The old "bag" drew a tapering body with the handle arc sitting ON the rim —
       at 30px that reads as a TRASH CAN, which is a disastrous glyph for the
       primary add-to-cart control. The bag now has straight sides, a flat rim and
       the handle ABOVE it; the add controls use `plus` (unambiguous) instead. */
    bag: '<svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-.9 11.4a2 2 0 0 1-2 1.85H8.5a2 2 0 0 1-2-1.85L5.6 8.4Z"/><path d="M9 8.4V6.6a3 3 0 0 1 6 0v1.8"/></svg>',
    reorder: '<svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M20 11a8 8 0 1 0-.6 4"/><path d="M20 4.5V11h-6"/></svg>',
    check: '<svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>',
    clock: '<svg class="ic ic-xs" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
    fire: '<svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 22c4.4 0 7-2.8 7-6.6 0-4.8-4.2-6.6-4.9-11.4-2.5 1.6-3.4 4-3.2 6.6-1.2-.7-2-1.7-2.4-3.4C6.6 9 5 11.6 5 15.4 5 19.2 7.6 22 12 22Z"/></svg>',
    leaf: '<svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 19C5 9 13 5 20 5c0 8-4 14-12 14-1 0-2-.1-3 0Z"/><path d="M5 19c2-5 6-9 10-11"/></svg>',
    pencil: '<svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M4 20l1-4L16.5 4.5a2.1 2.1 0 0 1 3 3L8 19l-4 1Z"/></svg>',
    scooter: '<svg class="ic ic-xs" viewBox="0 0 24 24"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h6V8h5l3 5v4h-2M12 8h-2M9 17l3-6"/></svg>',
    pin: '<svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>',
    tableic: '<svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M4 10h16M12 10v9M5 19h14M7 10 5.5 5h13L17 10"/></svg>',
  };

  /* ── state ────────────────────────────────────────────── */
  var LS = {
    get: function (k, d) { try { var v = JSON.parse(localStorage.getItem("dough_" + CFG.slug + "_" + k)); return v == null ? d : v; } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem("dough_" + CFG.slug + "_" + k, JSON.stringify(v)); } catch (e) {} },
  };

  var SUBP = {};
  (CFG.subParents || []).forEach(function (p) { SUBP[String(p)] = true; });

  var S = {
    favs: new Set(LS.get("favs", [])),
    cart: LS.get("cart", []),          // [{key,id,variant,extras:[xi],mods:{gid:optIdx},qty,note}]
    cat: "all", sub: "root", search: "",
    filters: { diets: {}, allergens: {}, spice: 0 },
    orderType: null, when: "asap", addr: "", table: CFG.defaultTable || "",
    coupon: null, tip: 0, pay: "cash", payVia: "pay_on_counter", useWallet: false,
    curCode: CFG.baseCurrency, rate: 1,
    currentId: null, iVariant: 0, iExtras: new Set(), iMods: {}, iQty: 1,
    obIdx: 0, rvStars: 0, lastTrackUrl: null,
  };

  /* ── money ────────────────────────────────────────────── */
  // Decimals follow the DISPLAYED currency, not the store's base currency. Codes absent from
  // the map are 2-decimal; only fall back to the store's own decimals for its own currency
  // (otherwise an OMR store showing USD would render "USD 2.600").
  function curDp() {
    var m = CFG.decimalsByCode || {};
    if (m[S.curCode] != null) return m[S.curCode];
    return S.curCode === CFG.baseCurrency ? (CFG.currencyDecimals ?? 2) : 2;
  }
  // Percentage-of-money, quantized to the STORE's decimals: totals are computed in the base
  // currency (fmt() converts only at display), so this uses the store's dp, not curDp().
  // At dp=2 this is bit-identical to the previous Math.round(n * pct) / 100.
  /* M16: delegates to the shared money core so tax/percentage math rounds exactly
     like PHP's round(). */
  /* bug batch 10 · 4: order arithmetic at the stored precision (CFG.moneyDecimals); display keeps currencyDecimals */
  var MONEY_DP = CFG.moneyDecimals ?? CFG.currencyDecimals ?? 2;
  function pctOf(n, pct) { return mbMoney.pct(n, pct, MONEY_DP); }
  function fmt(usd) {
    var v = (Number(usd || 0) * S.rate).toFixed(curDp());
    // A non-base display currency is labelled with its CODE, never the store's own
    // sign — else a converted price reads as "143.00 OMR" (the USD value, base label).
    if (S.curCode && S.curCode !== CFG.baseCurrency) return "≈ " + S.curCode + " " + v;
    return CFG.curPos === "1" ? CFG.curSign + (CFG.curGap || "") + v : v + " " + CFG.curSign;
  }
  // The grand total is the ONE number a diner must be able to trust, so it can never
  // print the word "null": stores whose currency_code is unset (settings.currency_code
  // is genuinely null on a fresh install) fall back to the theme's normal sign format
  // rather than inventing a code the store never chose.
  function fmtCode(usd) {
    if (!S.curCode) return fmt(usd);
    return S.curCode + " " + (Number(usd || 0) * S.rate).toFixed(curDp());
  }

  /* ── appearance (light / dark / follow OS) ────────────────
     dough shipped with a single hard-coded palette. The dark palette is dough's
     OWN — the charcoal/orange/sage it already uses for onboarding and the OTP
     view, promoted to the whole app — not an inversion of the cream one.
     `auto` (the default) follows prefers-color-scheme; an explicit choice is
     remembered per store. The attribute is also set by an inline boot script in
     index.blade.php so there is no flash of the wrong palette. */
  var darkMq = window.matchMedia ? window.matchMedia("(prefers-color-scheme: dark)") : null;
  function applyTheme(mode) {
    var dark = mode === "dark" || (mode !== "light" && !!(darkMq && darkMq.matches));
    root.setAttribute("data-dough-theme", dark ? "dark" : "light");
    $$("#themeSeg [data-theme-mode]").forEach(function (b) { b.classList.toggle("is-on", b.dataset.themeMode === mode); });
  }
  function setThemeMode(mode) { LS.set("theme", mode); applyTheme(mode); }
  if (darkMq && darkMq.addEventListener) {
    darkMq.addEventListener("change", function () { applyTheme(LS.get("theme", "auto")); });
  }

  /* ── toast ────────────────────────────────────────────── */
  var toastTimer;
  function toast(msg) {
    var el = $("#toast");
    el.innerHTML = '<svg class="ic" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg><span>' + esc(msg) + "</span>";
    el.classList.add("is-on");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.classList.remove("is-on"); }, 2200);
  }
  /* Exposed so the shared reorder-pickup helper (loaded by index.blade.php
     after script.js) can route its "skipped these items" message through
     dough's toast — same component, same animation. */
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

  /* ── router ───────────────────────────────────────────── */
  var stack = [];
  var ROOTS = ["home", "cart", "profile"];
  var active = "home";
  function show(name, opts) {
    opts = opts || {};
    var push = opts.push !== false;
    if (name === active) return;
    var from = $('.view[data-view="' + active + '"]'), to = $('.view[data-view="' + name + '"]');
    if (!to) return;
    var isRoot = ROOTS.indexOf(name) !== -1;
    if (push && !isRoot) {
      stack.push(active);
      /* Mirror the internal stack into browser history so the phone's back
         button walks item → home instead of leaving the site. */
      if (!opts.fromPop) { try { history.pushState({ dg: 1 }, ""); } catch (e) {} }
    }
    if (isRoot) stack.length = 0;
    $$(".view").forEach(function (v) { v.classList.remove("is-under"); });
    if (!isRoot && from) from.classList.add("is-under");
    if (from) from.classList.remove("is-active");
    to.classList.add("is-active");
    var sc = to.querySelector(".view-scroll"); if (sc) sc.scrollTop = 0;
    active = name;
    if (name === "cart") renderCart();
    if (name === "checkout") renderCheckout();
    if (name === "profile") renderProfile();
    updatePill();
  }
  function internalBack() { show(stack.pop() || "home", { push: false, fromPop: true }); }
  function back() {
    /* UI back buttons go through history when we pushed a state, so the
       browser's own back stack stays in step with what is on screen. */
    if (stack.length && history.state && history.state.dg) { history.back(); return; }
    internalBack();
  }
  window.addEventListener("popstate", function () {
    var so = $("#searchOverlay");
    if (so && !so.hidden) { closeSearch(); try { history.pushState({ dg: 1 }, ""); } catch (e) {} return; }
    if (stack.length) internalBack();
    else if (active !== "home") show("home", { push: false, fromPop: true });
  });

  /* ── menu pill ────────────────────────────────────────── */
  function updatePill() {
    var pill = $("#menuPill");
    /* The onboarding layer is optional — a store can turn the splash off, and then
       #onboard is not in the DOM at all. No splash means nothing to wait for, so
       only the view itself decides whether the pill hides. */
    var ob = $("#onboard");
    var hide = ["item", "checkout", "otp"].indexOf(active) !== -1 || (!!ob && !ob.classList.contains("is-done"));
    pill.classList.toggle("is-hidden", hide);
    /* once there is food in the cart, the cart tab must be ONE tap away
       (a combos-only basket counts as food too) */
    pill.classList.toggle("is-open", cartCount() + comboCount() > 0);
    $$(".mp-btn").forEach(function (b) { b.classList.toggle("is-on", b.dataset.nav === active); });
  }
  $("#mpToggle").addEventListener("click", function (e) { e.stopPropagation(); $("#menuPill").classList.add("is-open"); });
  document.addEventListener("click", function (e) {
    var pill = $("#menuPill");
    if (pill.classList.contains("is-open") && !e.target.closest("#menuPill")) pill.classList.remove("is-open");
  });

  /* ── sheets ───────────────────────────────────────────── */
  var openSheet = null, sheetTimers = {};
  function sheet(id, open) {
    open = open !== false;
    var s = $("#" + id), scrim = $("#scrim");
    if (!s) return;
    if (open) {
      if (openSheet && openSheet !== id) sheet(openSheet, false);
      clearTimeout(sheetTimers[id]);
      s.hidden = false; scrim.hidden = false;
      requestAnimationFrame(function () { s.classList.add("is-open"); scrim.classList.add("is-open"); });
      openSheet = id;
    } else {
      s.classList.remove("is-open"); scrim.classList.remove("is-open");
      clearTimeout(sheetTimers[id]);
      sheetTimers[id] = setTimeout(function () { s.hidden = true; if (!openSheet || openSheet === id) scrim.hidden = true; }, 420);
      if (openSheet === id) openSheet = null;
    }
  }
  $("#scrim").addEventListener("click", function () { if (openSheet) sheet(openSheet, false); });
  /* explicit corner-X close buttons on sheets (dough closes via the scrim, not the
     grab bar, so wire the X to the same close). */
  Array.prototype.forEach.call(document.querySelectorAll(".sheet-x"), function (x) {
    x.addEventListener("click", function () { if (openSheet) sheet(openSheet, false); });
  });

  /* ── onboarding ───────────────────────────────────────── */
  var OB = CFG.obSlides || [];
  function renderOb() {
    /* Nothing to paint when the splash is switched off and the layer never rendered. */
    var title = $("#obTitle"), sub = $("#obSub");
    if (!title || !sub) return;
    var sl = OB[S.obIdx] || { lines: [CFG.restaurantName], sub: "" };
    title.innerHTML = (sl.lines || []).map(function (l) { return "<span>" + esc(l) + "</span>"; }).join("");
    sub.textContent = sl.sub || "";
    $$("#obDots i").forEach(function (d, i) { d.classList.toggle("is-on", i === S.obIdx); });
  }
  function dismissOb() {
    var ob = $("#onboard"); if (ob) ob.classList.add("is-done");
    try { sessionStorage.setItem("dough_ob_" + CFG.slug, "1"); } catch (e) {}
    updatePill();
  }
  var obNext = $("#obNext");
  if (obNext) obNext.addEventListener("click", function () {
    if (S.obIdx >= OB.length - 1) { dismissOb(); return; }
    S.obIdx++; renderOb();
  });
  var obSkip = $("#obSkip"); if (obSkip) obSkip.addEventListener("click", dismissOb);

  /* ── home ─────────────────────────────────────────────── */
  function renderCats() {
    $("#cats").innerHTML = CATS.map(function (c) {
      var hasSub = SUBP[String(c.id)] ? ' data-has-sub="1"' : "";
      return '<button class="chip ' + (String(c.id) === String(S.cat) ? "is-on" : "") + '" data-cat="' + esc(c.id) + '"' + hasSub + ' type="button"><span class="emoji">' + esc(c.emoji || "") + "</span>" + esc(c.name) + "</button>";
    }).join("");
  }
  /* Reveal the active parent's subcategory chip row (if it has one), reset its
     selection to "All", and hide every other group. Called on each parent change. */
  function syncSubChips() {
    var wrap = $("#subChips");
    if (!wrap) return;
    var shown = null;
    $$(".subgroup", wrap).forEach(function (g) {
      var match = S.cat !== "all" && S.cat !== "favs" && g.dataset.parent === String(S.cat);
      g.hidden = !match;
      if (match) {
        shown = g;
        $$(".subchip", g).forEach(function (c) { c.classList.toggle("is-on", c.dataset.sub === "root"); });
      }
    });
    wrap.hidden = !shown;
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
    var list = itemList();
    if (S.cat === "favs") list = list.filter(function (i) { return S.favs.has(String(i.id)); });
    else if (S.cat !== "all") {
      /* a chip covers its own id plus any subcategory ids beneath it; the sub-chip
         then narrows to one real subcategory */
      var kids = (CFG.catKids && CFG.catKids[String(S.cat)]) || [];
      list = list.filter(function (i) {
        var c = String(i.cat);
        if (c !== String(S.cat) && kids.indexOf(c) === -1) return false;
        if (S.sub !== "root" && c !== String(S.sub)) return false;
        return true;
      });
    }
    if (filtersActive()) list = list.filter(passFilters);
    return list;
  }
  function imgOf(i) { return i.img || CFG.defaultImg; }
  /* Fallback art in dough's OWN palette (cream disc, charcoal wheat mark). The
     platform default.png is a pale-blue plate on a pale-blue disc — off-brand on
     every card, hero, promo and on the orange band. Inline data: URI so it can
     never 404 and never loops (see onErr below); CSP allows img-src data:. */
  var PLACEHOLDER = CFG.placeholderImg || ("data:image/svg+xml;charset=utf-8," + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120">' +
    '<rect width="120" height="120" fill="#E9E2D4"/>' +
    '<g fill="none" stroke="#1A1B1E" stroke-opacity=".38" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">' +
    '<circle cx="60" cy="60" r="30"/><path d="M60 40v40M60 48c-6-6-14-4-14-4s0 8 6 11M60 48c6-6 14-4 14-4s0 8-6 11"/>' +
    '<path d="M60 62c-6-6-14-4-14-4s0 8 6 11M60 62c6-6 14-4 14-4s0 8-6 11"/></g></svg>'));
  var IMG_FALLBACK = 'data-csp-error="fallback-class" data-csp-class="is-ph" data-csp-fallback="' + esc(PLACEHOLDER) + '"';
  /* Treat the platform placeholder itself as "no photo" so it never renders. */
  function photoOf(i) {
    var src = imgOf(i);
    return (!src || /\/default\.png(\?|$)/i.test(src)) ? PLACEHOLDER : src;
  }
  /* main image + any gallery images, deduped — drives the product gallery */
  function itemImgs(i) {
    var g = (i.gallery && i.gallery.length) ? i.gallery.slice() : [];
    if (i.img && g.indexOf(i.img) === -1) g.unshift(i.img);
    g = g.filter(function (s) { return s && !/\/default\.png(\?|$)/i.test(s); });
    return g.length ? g : [photoOf(i)];
  }
  function cardHTML(i, idx) {
    var fav = S.favs.has(String(i.id));
    var meta = [];
    if (i.rating) meta.push('<span class="st">★ ' + esc(i.rating) + "</span>");
    if (i.calories) { if (meta.length) meta.push("<span>·</span>"); meta.push("<span>" + esc(i.calories) + " " + esc(T.kcal) + "</span>"); }
    var badge = i.is_sold_out ? '<span class="g-soldout">' + esc(T.sold_out) + "</span>"
      : i.is_daily_special ? '<span class="g-special">' + esc(T.daily_special) + "</span>"
      /* is_popular was shipped in the payload but never drawn — the one badge a
         first-time diner actually navigates by. */
      : i.is_popular ? '<span class="g-popular">' + esc(T.popular_badge || T.popular) + "</span>" : "";
    var desc = String(i.desc || "").trim();
    return '' +
      '<a class="g-card ' + (i.is_sold_out ? "is-sold" : "") + '" href="' + esc(itemHref(i.id)) + '" data-open="' + esc(i.id) + '" style="animation-delay:' + Math.min((idx || 0) * 45, 400) + 'ms;color:inherit;text-decoration:none">' +
        '<span class="g-fav ' + (fav ? "is-on" : "") + '" data-fav="' + esc(i.id) + '"><svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg></span>' +
        badge +
        (i.schedule_label ? '<span class="g-hh">' + esc(i.schedule_label) + "</span>" : "") +
        '<img class="g-photo" src="' + esc(photoOf(i)) + '" alt="" loading="lazy" ' + IMG_FALLBACK + '>' +
        '<span class="g-name">' + esc(i.name) + "</span>" +
        (meta.length ? '<span class="g-meta">' + meta.join("") + "</span>" : "") +
        /* the seeded copy ("Crispy fried quarter, house spice blend.") was thrown
           away on every card while the search rows showed it and hid the price */
        (desc ? '<span class="g-desc" dir="auto">' + esc(desc) + "</span>" : "") +
        '<span class="g-foot">' +
          /* K8: happy-hour reference price struck through when a schedule is live.
             was + live price wrapped so they stack vertically (see .g-pricewrap). */
          '<span class="g-pricewrap">' +
          (i.original_price && i.original_price > i.price ? '<del class="g-was" style="opacity:.55;font-weight:400;margin-inline-end:.35em">' + fmt(i.original_price) + "</del>" : "") +
          '<span class="g-price">' + fmt(i.price) + "</span>" +
          "</span>" +
          (CFG.allowOrder && !i.is_sold_out ? '<span class="g-add" data-add="' + esc(i.id) + '" role="button" aria-label="' + esc(T.add_to_cart || T.added) + '">' + ICONS.plus + "</span>" : "") +
        "</span>" +
      "</a>";
  }
  function renderGrid() {
    var list = visibleItems();
    $("#grid").innerHTML = list.map(cardHTML).join("");
    $("#gridEmpty").hidden = list.length > 0;
    var cat = CATS.filter(function (c) { return String(c.id) === String(S.cat); })[0];
    var title = S.cat === "all" ? T.popular : (cat ? cat.name : T.popular);
    if (S.cat !== "all" && S.cat !== "favs" && S.sub !== "root") {
      var scEl = $('#subChips .subchip[data-sub="' + S.sub + '"]');
      if (scEl) title = scEl.textContent.trim();
    }
    $("#gridTitle").textContent = title;
    var fd = $("#filterDot"); if (fd) fd.hidden = !filtersActive();
  }
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

  /* strip the leftover empty svg produced above (cleanup: fav uses heart only) */
  // (cardHTML fav markup kept minimal; heart icon is the visible one)

  /* ── item view ────────────────────────────────────────── */
  /* A variant axis is only a SIZE axis if every one of its labels is a real size
     word. The old code truncated anything else to two letters, so "Regular"/"Spicy"
     rendered as two circles reading "M" and "SP" (and "خي"/"خي" in Arabic) — a
     customer literally could not tell the options apart. Size axes keep the
     signature S/M/L circles; every other axis renders full-label pills. */
  var SIZE_WORDS = [
    { re: /^(small|sm|s|صغير|صغيرة)$/i, token: "S" },
    { re: /^(medium|med|md|m|regular|reg|وسط|متوسط|متوسطة|عادي)$/i, token: "M" },
    { re: /^(large|lg|l|كبير|كبيرة)$/i, token: "L" },
    { re: /^(x-?large|xl|extra large|كبير جدا|كبير جداً)$/i, token: "XL" },
  ];
  function sizeToken(label) {
    var l = String(label || "").trim();
    for (var i = 0; i < SIZE_WORDS.length; i++) if (SIZE_WORDS[i].re.test(l)) return SIZE_WORDS[i].token;
    return null;
  }
  function isSizeAxis(variants) {
    if (!variants || variants.length < 2) return false;
    return variants.every(function (v) { return sizeToken(v.label) !== null; });
  }
  function optPrice(i) {
    var p = 0;
    (i.optionGroups || []).forEach(function (g, gi) {
      var sel = S.iMods[g.id];
      if (sel != null && g.options[sel]) p += Number(g.options[sel].price || 0);
    });
    return p;
  }
  function unitPrice(i, vIdx, extrasArr, mods) {
    var p;
    if (i.variants && i.variants.length && vIdx != null && i.variants[vIdx]) p = Number(i.variants[vIdx].price || 0);
    else p = Number(i.price || 0);
    (extrasArr || []).forEach(function (xi) { if (i.extras[xi]) p += Number(i.extras[xi].price || 0); });
    (i.optionGroups || []).forEach(function (g) {
      var sel = mods ? mods[g.id] : null;
      if (sel != null && g.options[sel]) p += Number(g.options[sel].price || 0);
    });
    return p;
  }
  function openItem(id, fresh) {
    var i = item(id); if (!i) return;
    var same = String(S.currentId) === String(id);
    S.currentId = i.id;
    if (fresh !== false && !same) { S.iVariant = 0; S.iExtras = new Set(); S.iMods = {}; S.iQty = 1; }

    $("#iImg").src = photoOf(i);
    $("#iImg").classList.toggle("is-ph", photoOf(i) === PLACEHOLDER);
    $("#iImg").style.visibility = "";
    /* per-item gallery: thumbnail strip that swaps the hero image */
    var imgs = itemImgs(i), gal = $("#iGallery");
    if (gal) {
      if (imgs.length > 1) {
        $("#iImg").src = imgs[0];
        gal.hidden = false;
        gal.innerHTML = imgs.map(function (g, gi) {
          return '<button class="i-thumb ' + (gi === 0 ? "is-on" : "") + '" data-galthumb="' + esc(g) + '" type="button"><img src="' + esc(g) + '" alt="" ' + IMG_FALLBACK + '></button>';
        }).join("");
      } else { gal.hidden = true; gal.innerHTML = ""; }
    }
    $("#iName").textContent = i.name;
    var rate = $("#iRate"); rate.hidden = !i.rating; rate.textContent = "★ " + i.rating;
    $("#iIng").textContent = i.desc || "";
    $("#iBadge").hidden = !i.is_daily_special;
    $("#iFav").classList.toggle("is-on", S.favs.has(String(id)));
    $("#iSheet").classList.remove("is-tall");

    var tags = [];
    if (i.calories) tags.push('<span class="tagchip">' + ICONS.fire + esc(i.calories) + " " + esc(T.kcal) + "</span>");
    if (i.prep_time) tags.push('<span class="tagchip">' + ICONS.clock + esc(i.prep_time) + " " + esc(T.min) + "</span>");
    if (i.spice_level > 0) tags.push('<span class="tagchip">' + new Array(i.spice_level + 1).join("🌶") + "</span>");
    if (i.is_vegan) tags.push('<span class="tagchip sage">' + ICONS.leaf + esc(T.vegan) + "</span>");
    if (i.is_halal) tags.push('<span class="tagchip sage">' + esc(T.halal) + "</span>");
    if (i.is_gluten_free) tags.push('<span class="tagchip sage">' + esc(T.gluten_free) + "</span>");
    (i.allergies || []).forEach(function (a) { tags.push('<span class="tagchip">' + esc(a.title) + "</span>"); });
    $("#iMeta").innerHTML = tags.join("");

    /* size circles ← real variants */
    var hasVar = i.variants && i.variants.length;
    $("#iSizeBlock").hidden = !hasVar;
    if (hasVar) {
      var sized = isSizeAxis(i.variants);
      /* "Size" is only honest when the axis really is a size — otherwise the
         heading says "Choose one" and the options carry their own names. */
      $("#iSizeTitle").textContent = sized ? T.size : (T.choose_one || T.size);
      $("#iSizes").classList.toggle("is-labels", !sized);
      $("#iSizes").innerHTML = i.variants.map(function (v, vi) {
        var on = vi === S.iVariant ? "is-on" : "";
        if (sized) return '<button class="size ' + on + '" data-size="' + vi + '" type="button" aria-label="' + esc(v.label) + '">' + esc(sizeToken(v.label)) + "</button>";
        var delta = i.variants[0] ? Number(v.price || 0) - Number(i.variants[0].price || 0) : 0;
        return '<button class="size wide ' + on + '" data-size="' + vi + '" type="button"><span class="size-name">' + esc(v.label) + "</span>" +
          (delta > 0 ? '<span class="size-delta">+' + fmt(delta) + "</span>" : "") + "</button>";
      }).join("");
      var sel = i.variants[S.iVariant] || i.variants[0];
      /* the hint repeats the chosen label; with full-label pills that is noise */
      $("#iSizeHint").textContent = sized && sel ? sel.label : "";
    }

    /* option groups (global modifiers) — single-select chip rows */
    var ow = $("#iOptWrap"); ow.innerHTML = "";
    (i.optionGroups || []).forEach(function (g) {
      var chips = (g.options || []).map(function (o, oi) {
        var lbl = esc(o.label) + (o.price > 0 ? " +" + fmt(o.price) : "");
        return '<button class="opt-chip ' + (S.iMods[g.id] === oi ? "is-on" : "") + '" data-mod="' + esc(g.id) + '" data-oi="' + oi + '" type="button">' + lbl + "</button>";
      }).join("");
      ow.insertAdjacentHTML("beforeend",
        '<div class="i-block optgroup"><div class="i-bhead"><h3>' + esc(g.title) + "</h3><span class=\"i-bhint\">" + (g.required ? "" : esc(T.optional)) + "</span></div><div class=\"opt-chips\">" + chips + "</div></div>");
    });

    /* extras */
    $("#iExtraBlock").hidden = !(i.extras && i.extras.length);
    if (i.extras && i.extras.length) {
      $("#iExtras").innerHTML = i.extras.map(function (x, xi) {
        return '<button class="extra ' + (S.iExtras.has(xi) ? "is-on" : "") + '" data-extra="' + xi + '" type="button">' +
          '<span class="extra-box">' + ICONS.check + "</span>" +
          '<span class="extra-name">' + esc(x.title) + "</span>" +
          '<span class="extra-price">+' + fmt(x.price) + "</span></button>";
      }).join("");
    }

    /* product reviews — real approved MenuReview rows shipped per item; the
       shared _partials/item-reviews layer injects the histogram + write button
       into this .p-reviews section. */
    var rv = $("#iReviews");
    if (window.__mbItemReviewsOn !== false && rv) {
      var list = i.reviewList || [];
      var head = '<div class="i-bhead"><h3>' + esc(T.reviews_title) + '</h3><span class="i-bhint">' + (list.length ? list.length : "") + "</span></div>";
      rv.innerHTML = list.length
        ? head + list.map(function (review) {
            var stars = Math.max(1, Math.min(5, Number(review.stars) || 0));
            return '<article class="rv-card"><b>' + esc(review.name) + '</b><span class="review-stars">' +
              new Array(stars + 1).join("★") + '<span class="review-stars-dim">' + new Array(5 - stars + 1).join("★") + "</span></span>" +
              (review.body ? "<p>" + esc(review.body) + "</p>" : "") + "</article>";
          }).join("")
        : head + '<p class="rv-empty">' + esc(T.reviews_empty) + "</p>";
    }

    /* inline per-item quick-rate — stars OPEN the shared review write-sheet
       (window.mbItemReviews) pre-selected, so a rating and a written review are
       one flow through one endpoint. */
    var ra = $("#iQuickRate");
    if (window.__mbItemReviewsOn !== false && ra) {
      ra.innerHTML = '<div class="rate-row"><span>' + esc(T.rate_dish || "Rate this dish") + '</span>' +
        '<span class="rate-stars" id="rateStars">' +
        [1, 2, 3, 4, 5].map(function (n) { return '<button data-rate="' + n + '" type="button" aria-label="' + n + '">★</button>'; }).join("") +
        '</span><span class="rate-thanks" id="rateThanks" hidden>' + esc(T.thanks || "") + "</span></div>";
    }

    updateItemCta();
    var sc = $("#iScroll"); if (sc) sc.scrollTop = 0;
    if (active !== "item") show("item");
  }
  function updateItemCta() {
    var i = item(S.currentId); if (!i) return;
    $("#iQty").textContent = S.iQty;
    var pill = $("#iAdd"); if (pill) $("#iTotal").textContent = fmt(unitPrice(i, i.variants && i.variants.length ? S.iVariant : null, Array.from(S.iExtras), S.iMods) * S.iQty);
    if (i.variants && i.variants.length && isSizeAxis(i.variants)) { var sel = i.variants[S.iVariant]; if (sel) $("#iSizeHint").textContent = sel.label; }
  }

  /* item sheet drag / toggle */
  (function () {
    var dragY = null, dragged = false;
    var iNotch = $("#iNotch"), iSheetEl = $("#iSheet");
    if (!iNotch) return;
    iNotch.addEventListener("pointerdown", function (e) { dragY = e.clientY; dragged = false; try { iNotch.setPointerCapture(e.pointerId); } catch (x) {} });
    iNotch.addEventListener("pointermove", function (e) {
      if (dragY == null) return;
      var dy = e.clientY - dragY;
      if (dy < -30) { iSheetEl.classList.add("is-tall"); dragY = null; dragged = true; }
      else if (dy > 30) { iSheetEl.classList.remove("is-tall"); dragY = null; dragged = true; }
    });
    iNotch.addEventListener("pointerup", function () { dragY = null; });
    iNotch.addEventListener("click", function () { if (dragged) { dragged = false; return; } iSheetEl.classList.toggle("is-tall"); });
  })();

  /* ── cart ─────────────────────────────────────────────── */
  function cartCount() { return S.cart.reduce(function (n, l) { return n + l.qty; }, 0); }
  function cartSubtotal() {
    /* Combos ride in the subtotal: the server taxes them, min-order counts them,
       and the coupon endpoint is quoted the same base the order will carry. */
    return S.cart.reduce(function (s, l) { return s + unitPrice(item(l.id), l.variant, l.extras, l.mods) * l.qty; }, 0) + comboTotal();
  }
  /* The cart as submitOrder's `items` — also posted to the coupon check (bug batch 7 · 4). */
  function orderLines() {
    return S.cart.map(function (l) {
      var i = item(l.id);
      var vid = (i.variants && i.variants.length && l.variant != null && i.variants[l.variant]) ? i.variants[l.variant].id : 0;
      var mods = [];
      (i.optionGroups || []).forEach(function (g) { var sel = l.mods ? l.mods[g.id] : null; if (sel != null) mods.push({ groupId: g.id, optIndex: sel }); });
      return { id: l.id, quantity: l.qty, variants: vid, extras: (l.extras || []).map(function (xi) { return { id: i.extras[xi].id }; }), mods: mods, note: l.note || "" };
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
      fetch(EP.coupon, {
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
  function writeCart() { LS.set("cart", S.cart); }

  /* COMBOS-V2 — the shared combos partial (_partials/combos.blade.php) keeps its
     own basket and publishes display state on window.mbCombos {count,total,lines}.
     The server prices combo lines into the ORDER subtotal (and taxes them like any
     other line), so the quote must fold them in too — QUOTED == CHARGED. Quantity
     edits stay in the combo sheet; here they render read-only. */
  function comboCount() { return (window.mbCombos && window.mbCombos.count) || 0; }
  function comboTotal() { return (window.mbCombos && window.mbCombos.total) || 0; }
  function comboLines() { return (window.mbCombos && window.mbCombos.lines) || []; }

  function addLine(id, variant, extras, mods, qty, fromEl) {
    var key = id + "|" + (variant == null ? "-" : variant) + "|" + extras.slice().sort().join(",") + "|" + JSON.stringify(mods || {});
    var line = null;
    for (var k = 0; k < S.cart.length; k++) if (S.cart[k].key === key) { line = S.cart[k]; break; }
    if (line) line.qty += qty; else S.cart.push({ key: key, id: id, variant: variant, extras: extras.slice(), mods: mods || {}, qty: qty, note: "" });
    writeCart();
    syncBag(true);
    toast(item(id).name + " — " + T.added);
    if (fromEl) flyToBag(fromEl);
  }
  /* ── account-page reorder handoff (P6-301) ────────────────
     The account page (account.blade.php) writes the reorder payload to
     cv_reorder_<slug> and redirects here. The shared helper reads it back and
     calls __doughPickup for each line. We translate the choice-group selections
     (which carry labels) onto dough's single-pick `{[groupId]: optIndex}`
     mods shape, push the line ourselves (so the note survives — addLine
     resets it to ""), and let the family's storage/badge handlers re-sync. */
  window.__doughPickup = function (line) {
    var i = item(line.item_id);
    if (!i || (i.inStock === false)) return false;
    /* payload.variant is the DB variantId; dough's cart key is the index into
       i.variants. Resolve back to the index the cart expects. */
    var variantIdx = (i.variants && i.variants.length) ? 0 : null;
    if (line.variant && i.variants && i.variants.length) {
      for (var vi = 0; vi < i.variants.length; vi++) {
        if (Number(i.variants[vi].id) === Number(line.variant)) { variantIdx = vi; break; }
      }
    }
    /* payload.extras is [{id,title,price}]; dough stores extras as indices
       into i.extras so unitPrice() + the cart key both work. */
    var extrasIdx = [];
    (line.extras || []).forEach(function (e) {
      if (!i.extras) return;
      for (var xi = 0; xi < i.extras.length; xi++) {
        if (Number(i.extras[xi].id) === Number(e.id)) { extrasIdx.push(xi); break; }
      }
    });
    var mods = {};
    (line.options || []).forEach(function (group) {
      var grp = (i.optionGroups || []).find(function (g) { return String(g.id) === String(group.group_id); });
      if (!grp) return;
      /* dough is single-pick per group; take the first label that matches. */
      var picks = (group.selected || [])
        .map(function (s) { return grp.options.findIndex(function (o) { return o.label === s.option; }); })
        .filter(function (oi) { return oi >= 0; });
      if (picks.length) mods[grp.id] = picks[0];
    });
    /* a required group that can no longer be satisfied would fail checkout */
    if ((i.optionGroups || []).some(function (g) { return g.required && mods[g.id] == null; })) return false;
    var key = line.item_id + "|" + (variantIdx == null ? "-" : variantIdx) + "|" + extrasIdx.slice().sort().join(",") + "|" + JSON.stringify(mods || {});
    var existing = null;
    for (var k = 0; k < S.cart.length; k++) if (S.cart[k].key === key) { existing = S.cart[k]; break; }
    if (existing) { existing.qty += line.qty; }
    else { S.cart.push({ key: key, id: line.item_id, variant: variantIdx, extras: extrasIdx.slice(), mods: mods, qty: line.qty, note: line.note || "" }); }
    writeCart();
    syncBag(true);
    toast(i.name + " — " + T.added);
    return true;
  };

  function flyToBag(fromEl) {
    var img = fromEl.closest(".g-card, .so-row"); img = img ? img.querySelector("img") : null;
    var target = $("#bagBtn");
    if (!img || !target || active !== "home") return;
    var a = img.getBoundingClientRect(), b = target.getBoundingClientRect();
    var ghost = document.createElement("img");
    ghost.src = img.src; ghost.className = "fly-img";
    var lay = $("#flyLayer"), lr = lay.getBoundingClientRect();
    ghost.style.left = (a.left - lr.left + a.width / 2 - 30) + "px";
    ghost.style.top = (a.top - lr.top + a.height / 2 - 30) + "px";
    lay.appendChild(ghost);
    requestAnimationFrame(function () {
      ghost.style.left = (b.left - lr.left + b.width / 2 - 30) + "px";
      ghost.style.top = (b.top - lr.top + b.height / 2 - 30) + "px";
      ghost.style.transform = "scale(.16)"; ghost.style.opacity = ".3";
    });
    setTimeout(function () { ghost.remove(); }, 800);
  }
  function syncBag(bump) {
    var n = cartCount() + comboCount();
    var bd = $("#bagDot"); if (bd) { bd.hidden = n === 0; bd.textContent = n; }
    var mb = $("#mpBadge"); if (mb) { mb.hidden = n === 0; mb.textContent = n; }
    /* direct "go to cart" jump on the item page, shown only once the cart
       holds something (mirrors orbit's #pCartJump / aurora's #au-cart-jump). */
    var cj = $("#iCartJump"); if (cj) { cj.hidden = n === 0; var cjc = $("#iCartJumpCount"); if (cjc) cjc.textContent = n; }
    updatePill();
    var cb = $("#cartCount");
    if (cb) { cb.textContent = n; if (bump) { cb.classList.remove("bump"); void cb.offsetWidth; cb.classList.add("bump"); } }
  }
  function lineSummary(l) {
    var i = item(l.id), parts = [];
    if (i.variants && i.variants.length && l.variant != null && i.variants[l.variant]) parts.push(i.variants[l.variant].label);
    (i.optionGroups || []).forEach(function (g) { var sel = l.mods ? l.mods[g.id] : null; if (sel != null && g.options[sel]) parts.push(g.options[sel].label); });
    if (l.extras.length) parts.push(l.extras.map(function (xi) { return i.extras[xi] ? i.extras[xi].title : ""; }).filter(Boolean).join(", "));
    return parts.filter(Boolean).join(" · ");
  }
  /* COMBOS-V2 — read-only "Combos" group appended to the cart list and the
     checkout summary. Tapping a row reopens the combo sheet's basket view,
     which is where quantities are edited. */
  function comboGroupHTML(compact) {
    var lines = comboLines();
    if (!lines.length) return "";
    var h = '<div class="dg-combo-group" data-combo-open style="border-top:1px dashed var(--line,#8883);padding-top:8px;margin-top:8px;cursor:pointer">';
    lines.forEach(function (L) {
      h += '<div class="ck-row ck-row--combo">' +
        '<div class="ck-mid"><b>' + esc(L.name) + "</b>" +
        (L.parts.length ? "<small>" + esc(L.parts.join(" · ")) + "</small>" : "") + "</div>" +
        '<span class="ck-qty">×' + L.qty + "</span>" +
        '<span class="ck-price">' + fmt(L.price) + "</span></div>";
    });
    if (!compact) h += '<small style="opacity:.65">' + esc(T.edit || "Edit") + " ›</small>";
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
      syncBag(true);
      renderTotals();
      if (active === "cart") renderCart();
      if (active === "checkout") renderCheckout();
    } catch (e) {}
  });

  function renderCart() {
    if (!CFG.allowOrder) return;
    var has = S.cart.length > 0 || comboCount() > 0;
    $("#cartEmpty").hidden = has;
    $("#cartTools").style.display = has ? "" : "none";
    $("#cartCta").hidden = !has;
    $("#cartList").innerHTML = comboGroupHTML(false) + S.cart.map(function (l, li) {
      var i = item(l.id), sum = lineSummary(l);
      var unit = unitPrice(i, l.variant, l.extras, l.mods);
      /* Happy-hour strike-through: original_price is the pre-schedule BASE
         price, so the pre-discount unit is unit + (original − current base). */
      var wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
        ? unit + (Number(i.original_price) - Number(i.price)) : 0;
      return '' +
        '<div class="ct-row" data-key="' + esc(l.key) + '" style="animation-delay:' + (li * 45) + 'ms">' +
          '<img class="ct-img" data-open="' + i.id + '" src="' + esc(photoOf(i)) + '" alt="" ' + IMG_FALLBACK + '>' +
          '<div class="ct-mid"><b data-open="' + i.id + '">' + esc(i.name) + "</b>" +
            (sum ? "<small>" + esc(sum) + "</small>" : "") +
            '<span class="ct-price">' + fmt(unit * l.qty) + "</span>" +
            (wasUnit ? '<del class="ct-was">' + fmt(wasUnit * l.qty) + "</del>" : "") +
            (l.qty > 1 ? '<small class="ct-unit">' + l.qty + " × " + fmt(unit) + "</small>" : "") +
            '<button class="ct-notebtn" data-note type="button">' + ICONS.pencil + "<span>" + (l.note ? "✎ " + esc(l.note) : esc(T.add_note)) + "</span></button>" +
            '<input class="ct-note" data-notein placeholder="' + esc(T.note_ph) + '" value="' + esc(l.note) + '" hidden>' +
          "</div>" +
          '<div class="ct-side">' +
            '<button class="ct-rm" data-rm type="button" aria-label="' + esc(T.remove || "Remove") + '">' + ICONS.trash + "</button>" +
            '<div class="ct-step"><button data-cq="-1" type="button" aria-label="−">' + ICONS.minus + "</button><output>" + l.qty + "</output><button data-cq=\"1\" type=\"button\" aria-label=\"+\">" + ICONS.plus + "</button></div>" +
          "</div>" +
        "</div>";
    }).join("");
    renderOtFields();
    renderTotals();
    syncBag(false);
  }
  /* mark the segment matching S.orderType — used by boot and by the seg tap */
  function syncOtSeg() {
    $$("#otSeg button").forEach(function (b) { b.classList.toggle("is-on", b.dataset.ot === S.orderType); });
  }
  function renderOtFields() {
    var f = $("#otFields"); if (!f) return;
    var wr = $("#waiterRow"); if (wr) wr.hidden = S.orderType !== "on-table";
    if (S.orderType === "delivery") {
      /* Full address widget (autocomplete + use-my-location), same wiring as the
         checkout's #coAddr field — rebuilt on every order-type switch, so it is
         re-wired here each time. Coordinates land in the checkout's hidden
         #coLat/#coLng pair; the visible value is mirrored into #coAddr. */
      f.innerHTML = '<div class="addr-field" id="ctAddrField">' +
          '<input id="ctAddr" dir="auto" placeholder="' + esc(T.addr_ph) + '" value="' + esc(S.addr) + '" autocomplete="off" autocorrect="off" spellcheck="false">' +
          '<button class="uselocbtn" id="ctUseLocation" type="button" title="' + esc(T.use_location) + '" aria-label="' + esc(T.use_location) + '">' + ICONS.locate + "</button>" +
          '<div class="addr-suggest" id="ctAddrSuggest" hidden></div>' +
        "</div>" +
        '<p class="geo-attrib" id="ctGeoAttrib" hidden></p>' +
        '<p class="ot-hint"><svg class="ic" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg><span>' + esc(T.delivery_hint) + "</span></p>";
      wireAddressField($("#ctAddr"), $("#ctAddrSuggest"), $("#ctUseLocation"), $("#ctGeoAttrib"), function (label) {
        var co = $("#coAddr"); if (co) co.value = label;
      });
    } else if (S.orderType === "takeaway") {
      f.innerHTML = '<p class="ot-hint"><svg class="ic" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg><span>' + esc(T.pickup_hint) + "</span></p>";
    } else if (S.orderType === "on-table") {
      if (CFG.forceTable) {
        f.innerHTML = '<p class="ot-hint">' + ICONS.tableic + "<span>" + esc(T.table) + " " + esc(S.table) + "</span></p>";
      } else {
        f.innerHTML = '<input id="ctTable" type="number" inputmode="numeric" placeholder="' + esc(T.table) + '" value="' + esc(S.table || "") + '"><p class="ot-hint">' + ICONS.tableic + "<span>" + esc(T.table_hint) + "</span></p>";
      }
    } else if (S.orderType === "room_service") {
      /* Room service is fulfilled in the guest's room, so like on-table it needs
         neither an address nor a table picker -- the room rides along on the
         `table` field (the scanned room QR key). */
      f.innerHTML = '<p class="ot-hint">' + ICONS.tableic + "<span>" + esc(T.room_hint) + "</span></p>";
    } else { f.innerHTML = ""; }
  }
  /* The tax the SERVER will charge, computed identically to Order::computeTax():
     percentage (default) or fixed, applied to the PRE-discount subtotal and
     rounded to the store's currency decimals. Dropping this is what made the
     theme quote $4.50 on an order the server charged $4.63 for. */
  function taxOf(sub) {
    var charge = Number(CFG.taxCharge || 0);
    if (charge <= 0 || sub <= 0) return 0;
    var dp = MONEY_DP, p = Math.pow(10, dp);
    if (String(CFG.taxType || "percentage") === "fixed") return Math.round(charge * p) / p;
    return Math.round(sub * (charge / 100) * p) / p;
  }
  /* Mirrors StoreController::submitOrder exactly:
       amount = subtotal + delivery + tax + tip − discount
     Every term is quantised to the store's decimals the same way the server
     quantises it, so the quoted total and the charged total are bit-identical. */
  function computeTotals() {
    var sub = cartSubtotal();
    var disc = 0;
    if (S.coupon) disc = S.coupon.coupon_type === "percentage" ? pctOf(sub, S.coupon.percentage) : S.coupon.coupon_type === "bogo" ? bxgyDiscount() : Number(S.coupon.percentage) || 0;
    disc = Math.min(disc, sub);
    var base = Math.max(0, sub - disc);
    var tip = pctOf(base, S.tip);
    var del = (S.orderType === "delivery" && (S.cart.length || comboCount())) ? Number(CFG.deliveryCharge || 0) : 0;
    if (S.coupon && S.coupon.free_delivery) del = 0;
    var tax = taxOf(sub);
    return { sub: sub, disc: disc, tip: tip, del: del, tax: tax, total: Math.max(0, sub + del + tax + tip - disc) };
  }
  function breakdownHTML(t) {
    var h = '<div class="tr"><span>' + esc(T.subtotal) + "</span><b>" + fmt(t.sub) + "</b></div>";
    if (t.disc) h += '<div class="tr disc"><span>' + esc(T.discount) + (S.coupon ? " (" + esc(S.coupon.code) + ")" : "") + "</span><b>−" + fmt(t.disc) + "</b></div>";
    if (t.tax) h += '<div class="tr"><span>' + esc(CFG.taxLabel || T.tax_label || "Tax") + (String(CFG.taxType || "percentage") !== "fixed" ? " (" + esc(CFG.taxCharge) + "%)" : "") + "</span><b>" + fmt(t.tax) + "</b></div>";
    if (t.tip) h += '<div class="tr"><span>' + esc(T.tip_label) + " (" + S.tip + "%)</span><b>" + fmt(t.tip) + "</b></div>";
    return h;
  }
  function renderTotals() {
    if (!CFG.allowOrder || !$("#breakdown")) return;   /* view-only menu: no cart chrome exists */
    var t = computeTotals();
    $("#breakdown").innerHTML = breakdownHTML(t);
    var showDel = S.orderType === "delivery";
    $("#bandDelRow").style.display = showDel ? "" : "none";
    /* the dashed rule belongs to the delivery row above it — without this the band
       floats an orphan divider over empty space on pickup/dine-in */
    $("#bandDelRow").closest(".band-inner").classList.toggle("no-del", !showDel);
    $("#bandDel").textContent = fmt(t.del);
    $("#bandTotal").textContent = fmtCode(t.total);
    var ck = $("#ckTotal"); if (ck) ck.textContent = fmt(t.total);
    var po2 = $("#poTotal"); if (po2) po2.textContent = fmt(t.total);
    var mo = $("#minOrderMsg");
    if (mo) {
      if (CFG.minOrderValue > 0 && t.sub > 0 && t.sub < CFG.minOrderValue) { mo.hidden = false; mo.textContent = T.min_order + " " + fmt(CFG.minOrderValue); }
      else mo.hidden = true;
    }
  }

  /* ── checkout ─────────────────────────────────────────── */
  function renderCheckout() {
    var otLabel = { delivery: T.delivery, takeaway: T.pickup_hint, "on-table": T.table, room_service: T.room_service }[S.orderType] || "";
    var otIc = { delivery: ICONS.scooter, takeaway: ICONS.bag.replace('class="ic"', 'class="ic ic-xs"'), "on-table": ICONS.tableic, room_service: ICONS.tableic }[S.orderType] || "";
    var whenTxt = S.when === "asap" ? T.asap : (($("#whenTime") && $("#whenTime").value) || T.later);
    var place = (S.orderType === "on-table" || S.orderType === "room_service") ? ((S.orderType === "room_service" ? T.room_service : T.table) + " " + (S.table || "")) : S.orderType === "delivery" ? (S.addr || T.fill_addr) : T.pickup_hint;
    $("#coSummary").innerHTML =
      '<span class="co-chip">' + otIc + esc(otLabel) + "</span>" +
      '<span class="co-chip">' + ICONS.clock + esc(whenTxt) + "</span>" +
      '<span class="co-chip">' + ICONS.pin + esc(place) + "</span>";
    $("#coAddrWrap").style.display = S.orderType === "delivery" ? "" : "none";
    $("#coTableWrap").style.display = (S.orderType === "on-table" && !CFG.forceTable) ? "" : "none";
    if ($("#coAddr") && !$("#coAddr").value) $("#coAddr").value = S.addr;
    renderRecentAddrs();               /* guest saved-address chips (no-op for members) */
    /* Order summary — what the customer is about to pay for, tap Edit → cart. */
    var ckI = $("#ckItems");
    if (ckI) {
      ckI.hidden = S.cart.length === 0 && comboCount() === 0;
      ckI.innerHTML = '<div class="ck-head"><h3>' + esc(T.your_order) + '</h3><button class="ck-edit" id="ckEdit" type="button">' + esc(T.edit) + "</button></div>" +
        S.cart.map(function (l) {
          var i = item(l.id); if (!i) return "";
          var unit = unitPrice(i, l.variant, l.extras, l.mods);
          /* Happy-hour strike-through: original_price is the pre-schedule BASE
             price, so the pre-discount unit is unit + (original − current base). */
          var wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
            ? unit + (Number(i.original_price) - Number(i.price)) : 0;
          var sum = lineSummary(l);
          return '<div class="ck-row">' +
            '<img src="' + esc(photoOf(i)) + '" alt="" ' + IMG_FALLBACK + ">" +
            '<div class="ck-mid"><b>' + esc(i.name) + "</b>" + (sum ? "<small>" + esc(sum) + "</small>" : "") + "</div>" +
            '<span class="ck-qty">×' + l.qty + "</span>" +
            '<span class="ck-price">' + fmt(unit * l.qty) + (wasUnit ? "<del>" + fmt(wasUnit * l.qty) + "</del>" : "") + "</span></div>";
        }).join("") + comboGroupHTML(true);
      var ckEd = $("#ckEdit"); if (ckEd) ckEd.onclick = function () { show("cart"); };
    }
    var t = computeTotals();
    $("#coBreakdown").innerHTML = breakdownHTML(t);
    var coDel = S.orderType === "delivery";
    $("#coBandDelRow").style.display = coDel ? "" : "none";
    $("#coBandDelRow").closest(".band-inner").classList.toggle("no-del", !coDel);
    $("#coBandDel").textContent = fmt(t.del);
    $("#coBandTotal").textContent = fmtCode(t.total);
    var po3 = $("#poTotal"); if (po3) po3.textContent = fmt(t.total);
    var ck2 = $("#ckTotal"); if (ck2) ck2.textContent = fmt(t.total);
    syncTipRows();
  }
  function syncTipRows() { $$("[data-tiprow] [data-tip]").forEach(function (b) { b.classList.toggle("is-on", (+b.dataset.tip) === S.tip); }); }

  /* ── place order (real submitOrder) ─────────────────────── */
  var otpVerified = false;
  function collectContact() {
    S.addr = ($("#coAddr") && $("#coAddr").value.trim()) || S.addr;
    if (S.orderType === "on-table" && !CFG.forceTable && $("#coTable")) S.table = $("#coTable").value.trim();
  }
  /* Validation used to print into #coError at the very bottom of a long scroll,
     under the sticky footer, with no focus and no highlight — tapping "Place order"
     looked like nothing happened at all. Now the message is scrolled into view and
     the offending field is focused and outlined. */
  function showError(el, field) {
    if (!el) return;
    /* scroll to whichever is HIGHER on the page so the reason and the field the
       diner has to fix are on screen together */
    var anchor = field && field.offsetParent ? field : el;
    try { anchor.scrollIntoView({ block: "center", behavior: "smooth" }); } catch (e) { }
    el.classList.remove("shake"); void el.offsetWidth; el.classList.add("shake");
    $$(".is-invalid").forEach(function (f) { f.classList.remove("is-invalid"); });
    if (field) {
      field.classList.add("is-invalid");
      setTimeout(function () { try { field.focus({ preventScroll: true }); } catch (e) { field.focus(); } }, 320);
    }
  }
  function placeOrder() {
    if (!CFG.allowOrder || (!S.cart.length && !comboCount())) return; /* combos-only orders are legal */
    var err = $("#coError"); err.hidden = true;
    if (!S.orderType) { err.textContent = T.select_type; err.hidden = false; showError(err); return; }
    collectContact();
    var name = ($("#coName") && $("#coName").value.trim()) || CFG.customerName || "";
    var phone = ($("#coPhone") && $("#coPhone").value.trim()) || "";
    if (!name || (S.orderType !== "on-table" && S.orderType !== "room_service" && !phone)) {
      err.textContent = T.fill_contact; err.hidden = false; showError(err, !name ? $("#coName") : $("#coPhone")); return;
    }
    if (S.orderType === "delivery" && !S.addr) { err.textContent = T.fill_addr; err.hidden = false; showError(err, $("#coAddr")); return; }
    var t = computeTotals();
    if (CFG.minOrderValue > 0 && t.sub < CFG.minOrderValue) { err.textContent = T.min_order + " " + fmt(CFG.minOrderValue); err.hidden = false; showError(err); return; }

    var payload = orderLines();

    var fd = new FormData();
    fd.append("_token", CFG.csrf);
    fd.append("items", JSON.stringify(payload));
    fd.append("name", name);
    fd.append("ordering-type", S.orderType);
    if (S.orderType === "on-table" || S.orderType === "room_service") fd.append("table", S.table || "");
    else fd.append("phone-number", phone);
    if (S.orderType === "delivery") fd.append("address", S.addr);
    fd.append("pay_via", S.payVia);
    fd.append("tip_amount", String(t.tip.toFixed(MONEY_DP)));
    var noteAll = S.cart.map(function (l) { return l.note; }).filter(Boolean).join(" | ");
    if (noteAll) fd.append("message", noteAll);
    if (S.coupon) fd.append("coupon_code", S.coupon.code);
    if (S.useWallet) fd.append("use_wallet", "1");
    var sched = $("#whenTime");
    if (S.when === "later" && sched && sched.value) fd.append("scheduled_at", sched.value);
    var bid = new URLSearchParams(window.location.search).get("branch_id");
    if (bid) fd.append("branch_id", bid);

    var btn = $("#placeOrder"), otpBtn = $("#otpConfirm");
    var spin = btn.querySelector(".spinner"); btn.disabled = true; if (spin) spin.hidden = false;
    if (otpBtn) { otpBtn.disabled = true; var os = otpBtn.querySelector(".spinner"); if (os) os.hidden = false; }

    fetch(EP.submitOrder, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false; if (spin) spin.hidden = true;
        if (otpBtn) { otpBtn.disabled = false; var o2 = otpBtn.querySelector(".spinner"); if (o2) o2.hidden = true; }
        if (res && res.otp_required && !otpVerified) { openOtp(res.phone || phone); return; }
        if (res && res.success) {
          if (res.message) { S.cart = []; writeCart(); syncBag(false); S.coupon = null; otpVerified = false; window.location.href = res.message; return; }   /* online payment redirect */
          rememberOrder(res, t);          /* record BEFORE the cart is cleared — Reorder needs the lines */
          if (S.orderType === "delivery") rememberAddress(S.addr, ($("#coLat") && $("#coLat").value) || "", ($("#coLng") && $("#coLng").value) || "");
          S.cart = []; writeCart(); syncBag(false); S.coupon = null; otpVerified = false;
          showConfirmed(res, t);
          if (res.whatsapp_url) mbWhatsAppHandoff(res.whatsapp_url, res.whatsapp_handoff);
        } else {
          /* Never render the server's raw exception to a diner — a real customer saw
             "foreach() argument must be of type array|object, string given". Only
             messages that are plainly customer-facing (short, no PHP punctuation)
             pass through; anything else becomes a generic failure. */
          var raw = (res && typeof res.message === "string") ? res.message.trim() : "";
          var safe = (raw && raw.length <= 160 && !/[<>{}]|\(\)|::|\$|Exception|SQLSTATE|Stack trace/i.test(raw)) ? raw : (T.order_failed || T.net_err);
          err.textContent = safe; err.hidden = false;
          showError(err);
          if (active === "otp") { var oe = $("#otpErr"); oe.textContent = safe; oe.hidden = false; }
        }
      })
      .catch(function () {
        btn.disabled = false; if (spin) spin.hidden = true;
        if (otpBtn) { otpBtn.disabled = false; var o3 = otpBtn.querySelector(".spinner"); if (o3) o3.hidden = true; }
        err.textContent = T.net_err; err.hidden = false; showError(err);
      });
  }
  function showConfirmed(res, t) {
    S.lastTrackUrl = res.track_url || null;
    $("#otpMain").hidden = true;
    $("#otpDone").hidden = false;
    var code = res.order_number || res.order_id || res.order || "";
    /* When the server sends no order number the screen used to print the RESTAURANT
       NAME where the reference belongs — the diner saw "Theme Lane B" as their order
       id, contradicting the track page one tap away. Say nothing rather than lie. */
    var codeEl = $("#otpOrderCode");
    codeEl.hidden = !code;
    codeEl.textContent = code ? "#" + code : "";
    /* the confirmation also carried no total, no items and no ETA */
    var recap = $("#otpRecap");
    if (recap) {
      var lines = (t && t.total != null) ? '<div class="tr"><span>' + esc(T.total || "Total") + "</span><b>" + fmtCode(t.total) + "</b></div>" : "";
      /* prep_eta arrives as an EtaRange object ({p25,p75,text,line}), not a string —
         concatenating it printed "[object Object]" at the diner. */
      var eta = res.prep_eta && typeof res.prep_eta === "object"
        ? (res.prep_eta.line || res.prep_eta.text || "")
        : (res.eta || res.prep_eta || "");
      if (eta) lines += '<div class="tr"><span>' + esc(T.when) + "</span><b>" + esc(eta) + "</b></div>";
      recap.innerHTML = lines;
      recap.hidden = !lines;
    }
    $("#goTrack").setAttribute("href", res.track_url || "#");
    show("otp", { push: false });
    stack.length = 0;
    toast(T.points_earned);
    if (CFG.googleReviewUrl) setTimeout(openReview, 1600);
  }

  /* ── OTP checkout gate (real endpoints) ───────────────── */
  var otpPhone = "";
  function openOtp(phone) {
    otpPhone = phone || "";
    $("#otpMain").hidden = false;
    $("#otpDone").hidden = true;
    $("#otpErr").hidden = true;
    $("#otpPhone").textContent = phone || "+0000 0000 0000";
    $$("#otpBoxes input").forEach(function (b) { b.value = ""; b.classList.remove("filled"); });
    show("otp");
    setTimeout(function () { var f = $("#otpBoxes input"); if (f) f.focus(); }, 380);
    otpSend(phone);
  }
  function otpSend(phone) {
    fetch(EP.otpSend, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ phone: phone, store_id: CFG.storeId }) })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (!d || !d.success) { var e = $("#otpErr"); e.textContent = (d && d.message) || T.net_err; e.hidden = false; } })
      .catch(function () {});
  }
  (function () {
    /* The whole OTP/confirmation view lives inside @if($allow_order). On a
       view-only QR menu it is simply not in the DOM, and the unguarded
       $("#otpConfirm").addEventListener below threw on boot — which killed every
       listener registered after this IIFE and painted a blank charcoal shell with
       no title, no Skip, no categories and no grid. Bail out instead. */
    var otpConfirm = $("#otpConfirm");
    if (!otpConfirm) return;
    var boxes = $$("#otpBoxes input");
    boxes.forEach(function (inp, i) {
      inp.addEventListener("input", function () {
        var digits = inp.value.replace(/\D/g, "");
        inp.value = digits.slice(0, 1);
        var last = i;
        for (var d = 1; d < digits.length && i + d < boxes.length; d++) { boxes[i + d].value = digits[d]; last = i + d; }
        boxes.forEach(function (b) { b.classList.toggle("filled", !!b.value); });
        if (digits && boxes[last + 1] && boxes[last].value) boxes[last + 1].focus(); else if (digits) boxes[last].focus();
      });
      inp.addEventListener("keydown", function (e) { if (e.key === "Backspace" && !inp.value && boxes[i - 1]) boxes[i - 1].focus(); });
    });
    otpConfirm.addEventListener("click", function () {
      var code = boxes.map(function (b) { return b.value; }).join("");
      var e = $("#otpErr"); e.hidden = true;
      if (code.length < 4) { e.textContent = T.code_incomplete; e.hidden = false; return; }
      var btn = $("#otpConfirm"); btn.disabled = true; var sp = btn.querySelector(".spinner"); if (sp) sp.hidden = false;
      fetch(EP.otpVerify, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ phone: otpPhone, code: code, store_id: CFG.storeId }) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          btn.disabled = false; if (sp) sp.hidden = true;
          if (d && d.success) { otpVerified = true; placeOrder(); }
          else { e.textContent = (d && d.message) || T.code_bad; e.hidden = false; }
        })
        .catch(function () { btn.disabled = false; if (sp) sp.hidden = true; e.textContent = T.net_err; e.hidden = false; });
    });
    $("#otpResend").addEventListener("click", function () { $("#otpErr").hidden = true; otpSend(otpPhone); toast(T.sent); });
  })();

  /* ── coupon (real endpoint) ───────────────────────────── */
  function clearCouponMsg() {
    var m = $("#couponMsg");
    if (m && m.classList.contains("err")) { m.hidden = true; m.textContent = ""; m.className = "coupon-msg"; }
  }
  function applyCoupon() {
    var input = $("#couponInput"); if (!input) return;
    var code = input.value.trim(); if (!code) return;
    var msg = $("#couponMsg"); msg.hidden = false;
    fetch(EP.coupon, { method: "POST", headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf }, body: couponBody(code) })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success) {
          S.coupon = { code: res.label || code, coupon_type: res.coupon_type, percentage: res.percentage, free_delivery: !!res.free_delivery, discount: Number(res.discount) || 0, sig: couponSig() };
          msg.className = "coupon-msg"; msg.textContent = "✓ " + (T.coupon_ok);
          $(".coupon-field").classList.add("is-applied");
        } else {
          S.coupon = null; msg.className = "coupon-msg err"; msg.textContent = "✕ " + ((res && res.message) || T.coupon_bad);
          $(".coupon-field").classList.remove("is-applied");
        }
        renderTotals();
      })
      .catch(function () { msg.className = "coupon-msg err"; msg.textContent = "✕ " + T.coupon_bad; });
  }

  /* ── waiter (real endpoint) ───────────────────────────── */
  function callWaiter() {
    fetch(EP.waiter, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ type: "call_waiter", table: S.table || "" }) })
      .then(function () { toast(T.waiter_ok); }).catch(function () { toast(T.waiter_ok); });
  }

  /* ── loyalty (real balance/redeem) ────────────────────── */
  function loadLoyalty() {
    if (!EP.loyaltyBalance) return;
    fetch(EP.loyaltyBalance, { headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var bal = (d && (d.balance != null ? d.balance : d.points)) || 0;
        var pb = $("#ptsBig"); if (pb) pb.textContent = bal;
        var lp = $("#loyaltyPts"); if (lp) lp.textContent = bal;
        var lb = $("#loyBal"); if (lb) lb.textContent = bal;
        var canRedeem = d && (d.can_redeem || (CFG.loyaltyThreshold && bal >= CFG.loyaltyThreshold));
        var st = $("#loyRedeemState"); if (st) st.textContent = canRedeem ? T.reward_redeemed.replace("!", "") || "Redeem" : (T.not_enough || "");
        var rb = $("#loyRedeem"); if (rb) rb.disabled = !canRedeem;
      }).catch(function () {});
  }
  function redeemLoyalty() {
    if (!EP.loyaltyRedeem) return;
    fetch(EP.loyaltyRedeem, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({}) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.success) { $("#loyOk").hidden = false; toast(T.reward_redeemed); loadLoyalty(); }
        else toast((d && d.message) || T.not_enough);
      }).catch(function () { toast(T.net_err); });
  }

  /* ── review: real POST, then google ───────────────────── */
  var ratingSent = 0, ratingTimer = null;
  function submitRating(stars) {
    if (!EP.nps || !stars) return;
    /* debounce: tapping 2★ on the way to 5★ must not file two scores */
    clearTimeout(ratingTimer);
    ratingTimer = setTimeout(function () {
      if (ratingSent === stars) return;
      ratingSent = stars;
      fetch(EP.nps, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf },
        body: JSON.stringify({ score: Math.round(stars * 2), comment: "" }),
      }).catch(function () { ratingSent = 0; });
    }, 700);
  }
  function openReview() {
    ratingSent = 0;
    if (openSheet === "reviewSheet") return;
    S.rvStars = 0;
    $$("#rvStars button").forEach(function (b) { b.classList.remove("is-on"); });
    var g = $("#rvGoogle"); if (g) g.hidden = true;
    $("#rvThanks").hidden = true;
    sheet("reviewSheet");
  }

  /* ── search ───────────────────────────────────────────── */
  var QUICKS = [{ q: "", label: "🔥 " + T.popular }, { q: "vegan", label: "🌿 " + T.vegan }, { q: "spicy", label: "🌶 " + (T.min ? "Spicy" : "Spicy") }];
  function openSearch() {
    var ov = $("#searchOverlay"); ov.hidden = false;
    $("#soInput").value = "";   /* a fresh search starts empty, not on last week's query */
    requestAnimationFrame(function () { ov.classList.add("is-open"); });
    $("#soChips").innerHTML = QUICKS.map(function (q) { return '<button class="chip" data-q="' + esc(q.q) + '" type="button">' + esc(q.label) + "</button>"; }).join("");
    renderSoResults();
    setTimeout(function () { $("#soInput").focus(); }, 250);
  }
  function closeSearch() { var ov = $("#searchOverlay"); ov.classList.remove("is-open"); setTimeout(function () { ov.hidden = true; }, 320); }
  function searchMatches(q) {
    q = (q || "").trim().toLowerCase();
    var list = itemList();
    if (!q) return list.filter(function (i) { return i.is_popular; });
    if (q === "vegan") return list.filter(function (i) { return i.is_vegan; });
    if (q === "spicy") return list.filter(function (i) { return i.spice_level >= 2; });
    return list.filter(function (i) { return (i.name + " " + (i.desc || "")).toLowerCase().indexOf(q) !== -1; });
  }
  function renderSoResults() {
    var list = searchMatches($("#soInput").value);
    $("#soResults").innerHTML = list.length ? list.map(function (i, li) {
      return '<button class="so-row" data-open="' + esc(i.id) + '" type="button" style="animation-delay:' + (li * 35) + 'ms">' +
        '<img src="' + esc(photoOf(i)) + '" alt="" ' + IMG_FALLBACK + '>' +
        '<span><b>' + esc(i.name) + '</b><small dir="auto">' + esc((i.desc || "").split(",").slice(0, 3).join(",")) + "</small></span>" +
        /* a search result with no price is a dead end — the grid shows one, so must this */
        '<span class="so-price">' + fmt(i.price) + "</span>" +
        (CFG.allowOrder && !i.is_sold_out ? '<span class="g-add" data-add="' + esc(i.id) + '" role="button" aria-label="' + esc(T.add_to_cart || T.added) + '">' + ICONS.plus + "</span>" : "") + "</button>";
    }).join("") : '<div class="empty so-empty"><svg class="empty-art" viewBox="0 0 100 100"><circle cx="44" cy="44" r="26"/><path d="m64 64 22 22"/><path d="M34 44h20"/></svg><p>' + esc(T.search_none) + "</p></div>";
  }

  /* ── guest order history + reorder ────────────────────────
     `#ordersList`/`#ordersEmpty` were rendered but nothing in this file ever
     touched them, and no orders key was ever written — so a guest who had just
     ordered still read "Orders you place appear here". Two real sources now feed
     the list: orders confirmed on this device (recorded in rememberOrder, which
     also keeps the line items so Reorder can rebuild the cart) and the server's
     own /order-lookup by phone for a diner on a new device. */
  function orderHistory() { return LS.get("orders", []); }
  function rememberOrder(res, t) {
    var rec = {
      id: res.order_number || res.order_id || res.order || null,
      total: fmtCode(t.total),
      track: res.track_url || null,
      at: Date.now(),
      lines: S.cart.map(function (l) { return { id: l.id, variant: l.variant, extras: (l.extras || []).slice(), mods: l.mods || {}, qty: l.qty }; }),
    };
    var all = orderHistory(); all.unshift(rec); LS.set("orders", all.slice(0, 10));
  }
  function orderRowHTML(o) {
    var when = o.date || (o.at ? new Date(o.at).toLocaleString() : "");
    var label = (o.id ? "#" + o.id : T.order_placed);
    return '<div class="order-row" data-order="' + esc(o.id == null ? "" : o.id) + '">' +
      '<svg class="ic" viewBox="0 0 24 24"><path d="M5.6 8.4h12.8l-.9 11.4a2 2 0 0 1-2 1.85H8.5a2 2 0 0 1-2-1.85L5.6 8.4Z"/><path d="M9 8.4V6.6a3 3 0 0 1 6 0v1.8"/></svg>' +
      "<div><b>" + esc(label) + "</b><small>" + esc(when) + (o.total ? " · " + esc(o.total) : "") + "</small></div>" +
      (o.lines && o.lines.length ? '<button class="row-btn" data-reorder="' + esc(o.at) + '" type="button">' + ICONS.reorder + "<span>" + esc(T.reorder) + "</span></button>" : "") +
      (o.track ? '<a class="row-status" href="' + esc(o.track) + '">' + esc(T.view_order) + "</a>" : "") +
      "</div>";
  }
  function renderOrders(extra) {
    var list = $("#ordersList"); if (!list) return;
    var rows = orderHistory().concat(extra || []);
    list.innerHTML = rows.map(orderRowHTML).join("");
    var empty = $("#ordersEmpty"); if (empty) empty.hidden = rows.length > 0;
  }
  function reorder(stamp) {
    var rec = orderHistory().filter(function (o) { return String(o.at) === String(stamp); })[0];
    if (!rec || !rec.lines || !rec.lines.length) return;
    var added = 0;
    rec.lines.forEach(function (l) {
      var i = item(l.id); if (!i || i.is_sold_out) return;
      var key = l.id + "|" + (l.variant == null ? "-" : l.variant) + "|" + (l.extras || []).slice().sort().join(",") + "|" + JSON.stringify(l.mods || {});
      var found = null;
      for (var k = 0; k < S.cart.length; k++) if (S.cart[k].key === key) { found = S.cart[k]; break; }
      if (found) found.qty += l.qty;
      else S.cart.push({ key: key, id: l.id, variant: l.variant, extras: (l.extras || []).slice(), mods: l.mods || {}, qty: l.qty, note: "" });
      added++;
    });
    if (!added) { toast(T.sold_out); return; }
    writeCart(); syncBag(true); toast(T.added); show("cart");
  }
  function lookupOrders() {
    var input = $("#lookupInput"), msg = $("#lookupMsg");
    if (!input || !EP.orderLookup) return;
    var id = input.value.trim(); if (!id) return;
    msg.hidden = false; msg.className = "coupon-msg"; msg.textContent = "…";
    fetch(EP.orderLookup, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf },
      body: JSON.stringify({ identifier: id }),
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.success && d.orders && d.orders.length) {
        msg.hidden = true;
        renderOrders(d.orders.map(function (o) { return { id: o.id, total: o.total, track: o.track_url, date: o.date }; }));
      } else {
        msg.className = "coupon-msg err"; msg.textContent = (d && d.message) || T.orders_none;
      }
    }).catch(function () { msg.className = "coupon-msg err"; msg.textContent = T.net_err; });
  }

  /* ── guest multi-address (localStorage) ───────────────────
     A logged-in diner picks from the server address book; a guest had nowhere to
     stash a delivery address, so every order meant retyping it. Delivery addresses
     confirmed on this device are remembered locally and offered back as chips at
     checkout. Guests only (!isLoggedIn) so this never fights the server book.
     Key is built INLINE via addrKey() — a `var KEY = …` declared after the boot
     render would read `undefined` under var-hoisting and yield zero chips. */
  function addrKey() { return "dough_recent_addrs_" + CFG.slug; }
  function readRecentAddrs() {
    try { return (JSON.parse(localStorage.getItem(addrKey()) || "[]") || []).filter(function (a) { return a && a.addr; }); } catch (e) { return []; }
  }
  function rememberAddress(addr, lat, lng) {
    addr = (addr || "").trim();
    if (!addr || CFG.isLoggedIn) return;
    var l = readRecentAddrs().filter(function (a) { return a.addr !== addr; });
    l.unshift({ addr: addr, lat: lat || "", lng: lng || "" });
    try { localStorage.setItem(addrKey(), JSON.stringify(l.slice(0, 5))); } catch (e) {}
  }
  function renderRecentAddrs() {
    var saved = $("#savedAddrs");
    if (!saved || CFG.isLoggedIn) return;   /* server chips own the box for members */
    var l = readRecentAddrs();
    if (!l.length) { saved.hidden = true; saved.innerHTML = ""; return; }
    saved.hidden = false;
    saved.innerHTML = l.map(function (a, i) {
      return '<button class="saved-addr' + (i === 0 ? " is-on" : "") + '" type="button" data-addr="' + esc(a.addr) + '" data-lat="' + esc(a.lat) + '" data-lng="' + esc(a.lng) + '">' +
        '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
        '<span class="sa-text"><b>' + esc(T.delivery || "Delivery") + '</b><small>' + esc(a.addr) + "</small></span></button>";
    }).join("");
  }

  /* ── customer auth (real endpoints) ───────────────────── */
  function authPost(url, body, btn) {
    var err = $("#auErr"); if (err) err.hidden = true;
    var sp = btn && btn.querySelector(".spinner");
    if (btn) { btn.disabled = true; if (sp) sp.hidden = false; }
    return fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf },
      body: JSON.stringify(body),
    }).then(function (r) { return r.json().catch(function () { return null; }); }).then(function (d) {
      if (btn) { btn.disabled = false; if (sp) sp.hidden = true; }
      if (d && (d.success || d.status === "success")) { window.location.reload(); return; }
      if (err) { err.textContent = (d && d.message) || T.net_err; err.hidden = false; }
    }).catch(function () {
      if (btn) { btn.disabled = false; if (sp) sp.hidden = true; }
      if (err) { err.textContent = T.net_err; err.hidden = false; }
    });
  }

  /* ── brand / follow row (ported from crave's F2) ─────────
     Direct toggle: a tap on "Follow" posts the follow, a tap on "Following"
     deletes it — no separate manage sheet (crave's B6). A guest tap opens
     dough's own auth sheet instead of the CustomerToken-guarded endpoint. */
  (function () {
    var btn = $("#followBtn");
    if (!btn || !EP.follow) return;
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
      var c = $("#followerCount"); if (c && typeof d.follower_count !== "undefined") c.textContent = d.follower_count;
      return true;
    }
    function doFollow(unfollow) {
      var prev = btn.dataset.following === "1";
      btn.dataset.following = unfollow ? "0" : "1";
      btn.classList.toggle("is-on", !unfollow);
      fetch(EP.follow, { method: unfollow ? "DELETE" : "POST", headers: followHeaders(true), body: "{}" })
        .then(function (r) { return r.json(); }).then(function (d) {
          if (!d || typeof d.following === "undefined") { throw new Error("bad"); }
          applyFollowState(d);
        }).catch(function () {
          btn.dataset.following = prev ? "1" : "0";
          btn.classList.toggle("is-on", prev);
          toast(T.net_err);
        });
    }
    btn.addEventListener("click", function () {
      if (!CFG.customerToken) { sheet("authSheet"); return; }
      doFollow(btn.dataset.following === "1");
    });
  })();

  /* ── profile ──────────────────────────────────────────── */
  function renderProfile() { loadLoyalty(); renderOrders(); }

  /* ── delegated events ─────────────────────────────────── */
  root.addEventListener("click", function (e) {
    var Tt = e.target;
    function c(s) { return Tt.closest ? Tt.closest(s) : null; }

    var fav = c("[data-fav]");
    if (fav) { e.stopPropagation(); e.preventDefault(); var fid = String(fav.dataset.fav); if (S.favs.has(fid)) S.favs.delete(fid); else S.favs.add(fid); LS.set("favs", Array.from(S.favs)); fav.classList.toggle("is-on"); if (S.cat === "favs") renderGrid(); return; }

    var add = c("[data-add]");
    if (add) {
      e.stopPropagation();
      e.preventDefault();
      var ai = item(add.dataset.add); if (!ai || ai.is_sold_out) return;
      if ((ai.variants && ai.variants.length) || (ai.optionGroups && ai.optionGroups.length) || (ai.extras && ai.extras.length)) { openItem(ai.id); return; }
      addLine(ai.id, null, [], {}, 1, add); return;
    }
    /* R7 — same-page ?item= links: grid card anchors + share/CTA links. Checked
       AFTER the controls above so a click on a card's ＋/heart (inside the card
       anchor, each preventDefaults it) wins. Same-pathname item links open in place;
       real external links full-load. Boot handles a cold ?item= / new-tab load. */
    var itemLink = c('a[href*="item="]');
    if (itemLink) {
      try {
        var lu = new URL(itemLink.getAttribute("href"), window.location.href);
        if (lu.pathname === window.location.pathname) {
          var lid = lu.searchParams.get("item");
          if (lid && item(lid)) { e.preventDefault(); if (!$("#searchOverlay").hidden) closeSearch(); openItem(lid); return; }
        }
      } catch (ex) {}
    }

    var open = c("[data-open]");
    if (open) { if (!$("#searchOverlay").hidden) closeSearch(); openItem(open.dataset.open); return; }
    var go = c("[data-go]"); if (go) { show(go.dataset.go); return; }
    if (c("[data-back]")) { back(); return; }
    var nav = c(".mp-btn");
    if (nav) {
      var _nt = nav.dataset.nav;
      // Menu shows all items on home; favourites reuse the grid's own favs filter;
      // search opens the overlay. None of these are separate views.
      if (_nt === "search") { openSearch(); return; }
      if (_nt === "menu") { if (active !== "home") show("home"); if (S.cat === "favs") { S.cat = "all"; renderGrid(); } var _mg = document.querySelector('[data-mb-grid-lead]') || $("#grid"); if (_mg) _mg.scrollIntoView({ behavior: "smooth", block: "start" }); return; }
      if (_nt === "favs") { if (active !== "home") show("home"); S.cat = "favs"; renderGrid(); var _fg = $("#grid"); if (_fg) _fg.scrollIntoView({ behavior: "smooth", block: "start" }); return; }
      /* Home must also LEAVE the favourites filter — favs reuses the grid's S.cat,
         so without this the grid stays stuck on favourites (only Menu cleared it). */
      if (_nt === "home") { if (active !== "home") show("home"); if (S.cat === "favs") { S.cat = "all"; renderCats(); syncSubChips(); renderGrid(); } window.scrollTo({ top: 0, behavior: "smooth" }); return; }
      show(_nt, { push: false }); return;
    }

    var chip = c("[data-cat]"); if (chip) { S.cat = chip.dataset.cat; S.sub = "root"; renderCats(); syncSubChips(); renderGrid(); return; }
    /* secondary sub-category chip narrows within the active parent */
    var subchip = c("[data-sub]");
    if (subchip) {
      S.sub = subchip.dataset.sub;
      var grp = subchip.closest(".subgroup");
      if (grp) $$(".subchip", grp).forEach(function (b) { b.classList.toggle("is-on", b === subchip); });
      renderGrid();
      return;
    }
    /* gallery thumbnail → swap the hero image */
    var galt = c("[data-galthumb]");
    if (galt) {
      var iimg = $("#iImg"); if (iimg) iimg.src = galt.dataset.galthumb;
      $$("#iGallery .i-thumb").forEach(function (b) { b.classList.toggle("is-on", b === galt); });
      return;
    }
    /* dietary / spice / allergen filter chips */
    var diet = c("[data-diet]"); if (diet) { S.filters.diets[diet.dataset.diet] = !S.filters.diets[diet.dataset.diet]; diet.classList.toggle("is-on"); syncFilterCount(); return; }
    var sp = c("[data-spice]"); if (sp) { var lvl = +sp.dataset.spice; S.filters.spice = (S.filters.spice === lvl) ? 0 : lvl; $$("#spiceRow [data-spice]").forEach(function (b) { b.classList.toggle("is-on", +b.dataset.spice === S.filters.spice); }); syncFilterCount(); return; }
    var al = c("[data-al]"); if (al) { S.filters.allergens[al.dataset.al] = !S.filters.allergens[al.dataset.al]; al.classList.toggle("is-on"); syncFilterCount(); return; }
    var qk = c("[data-q]"); if (qk) { $("#soInput").value = qk.dataset.q; renderSoResults(); return; }

    var sz = c("[data-size]"); if (sz) { S.iVariant = +sz.dataset.size; $$("#iSizes .size").forEach(function (b) { b.classList.toggle("is-on", b === sz); }); updateItemCta(); return; }
    var md = c("[data-mod]"); if (md) { var gid = md.dataset.mod, oi = +md.dataset.oi; S.iMods[gid] = (S.iMods[gid] === oi ? null : oi); $$('[data-mod="' + gid + '"]').forEach(function (b) { b.classList.toggle("is-on", +b.dataset.oi === S.iMods[gid]); }); updateItemCta(); return; }
    var ex = c("[data-extra]"); if (ex) { var xi = +ex.dataset.extra; if (S.iExtras.has(xi)) S.iExtras.delete(xi); else S.iExtras.add(xi); ex.classList.toggle("is-on"); updateItemCta(); return; }
    var iq = c("[data-iq]"); if (iq) { S.iQty = Math.max(1, S.iQty + (+iq.dataset.iq)); updateItemCta(); return; }

    /* inline per-dish rating — light the tapped run of stars, then open the
       shared review write-sheet pre-selected to that many stars. Scoped to
       #rateStars so the currency switcher's own data-rate never matches. */
    var rate = c("#rateStars [data-rate]");
    if (rate) {
      var rn = +rate.dataset.rate;
      $$("#rateStars button").forEach(function (b, bi) { b.classList.toggle("is-on", bi < rn); });
      if (window.mbItemReviews) window.mbItemReviews.open(rn);
      return;
    }

    var cq = c("[data-cq]");
    if (cq) {
      var row = cq.closest(".ct-row"), line = null;
      for (var k = 0; k < S.cart.length; k++) if (S.cart[k].key === row.dataset.key) { line = S.cart[k]; break; }
      if (!line) return;
      line.qty += (+cq.dataset.cq);
      clearCouponMsg();
      if (line.qty <= 0) { row.classList.add("removing"); setTimeout(function () { S.cart = S.cart.filter(function (l) { return l !== line; }); writeCart(); renderCart(); toast(T.removed); }, 280); }
      else { writeCart(); renderCart(); }
      return;
    }
    /* dedicated remove — drops the whole line regardless of quantity */
    var rm = c("[data-rm]");
    if (rm) {
      var rmRow = rm.closest(".ct-row"); if (!rmRow) return;
      clearCouponMsg();
      rmRow.classList.add("removing");
      setTimeout(function () { S.cart = S.cart.filter(function (l) { return l.key !== rmRow.dataset.key; }); writeCart(); renderCart(); toast(T.removed); }, 280);
      return;
    }
    var noteBtn = c("[data-note]"); if (noteBtn) { var inp = noteBtn.closest(".ct-mid").querySelector("[data-notein]"); inp.hidden = !inp.hidden; if (!inp.hidden) inp.focus(); return; }

    var ot = c("#otSeg [data-ot]"); if (ot) { S.orderType = ot.dataset.ot; clearCouponMsg(); syncOtSeg(); renderOtFields(); renderCheckout(); renderTotals(); return; }
    var wh = c("#whenSeg [data-when]");
    if (wh) {
      S.when = wh.dataset.when; $$("#whenSeg button").forEach(function (b) { b.classList.toggle("is-on", b === wh); });
      var sel = $("#whenTime"); if (sel) sel.hidden = S.when !== "later"; return;
    }
    var tip = c("[data-tiprow] [data-tip]"); if (tip) { S.tip = +tip.dataset.tip; clearCouponMsg(); syncTipRows(); renderTotals(); if (active === "checkout") renderCheckout(); return; }

    var pm = c("[data-pm]"); if (pm) { S.pay = pm.dataset.pm; S.payVia = pm.dataset.pv || "pay_on_counter"; S.useWallet = pm.dataset.wallet === "1"; $$(".pm").forEach(function (b) { b.classList.toggle("is-on", b === pm); }); return; }

    var atab = c("[data-atab]");
    if (atab) {
      var which = atab.dataset.atab;
      $$("#authTabs button").forEach(function (b) { b.classList.toggle("is-on", b === atab); });
      var pl = $("#authPaneLogin"), pr2 = $("#authPaneRegister"), po2 = $("#authPaneOtp");
      if (pl) pl.hidden = which !== "login";
      if (pr2) pr2.hidden = which !== "register";
      if (po2) po2.hidden = which !== "otp";
      var ae = $("#auErr"); if (ae) ae.hidden = true;
      return;
    }
    var tm = c("[data-theme-mode]");
    if (tm) { setThemeMode(tm.dataset.themeMode); return; }

    var rtab = c("[data-rtab]"); if (rtab) { var tab = rtab.dataset.rtab; $$("#resTabs button").forEach(function (b) { b.classList.toggle("is-on", b === rtab); }); var pr = $("#resPaneR"), pw = $("#resPaneW"); if (pr) pr.hidden = tab !== "reserve"; if (pw) pw.hidden = tab !== "waitlist"; return; }

    var reo = c("[data-reorder]"); if (reo) { e.stopPropagation(); reorder(reo.dataset.reorder); return; }

    var star = c("[data-star]");
    if (star) {
      S.rvStars = +star.dataset.star;
      $$("#rvStars button").forEach(function (b) { b.classList.toggle("is-on", (+b.dataset.star) <= S.rvStars); });
      var g = $("#rvGoogle");
      if (S.rvStars >= 4 && g) { g.hidden = false; $("#rvThanks").hidden = true; }
      else { if (g) g.hidden = true; $("#rvThanks").hidden = S.rvStars === 0; }
      /* The sheet used to light the stars, say "Thanks for your feedback!" and throw
         every 1–3★ complaint away — no POST anywhere in this file. It now records the
         score against the store (NpsResponse, 0–10 scale) before thanking anybody. */
      submitRating(S.rvStars);
      return;
    }

    var lg = c("#langSeg [data-lang-code]"); if (lg) { setCookie("Quick_lang", lg.dataset.langName); setCookie("Quick_user_lang", lg.dataset.langName); setCookie("Quick_user_lang_code", lg.dataset.langCode); window.location.reload(); return; }
    var cu = c("#curSeg [data-cur]"); if (cu) { S.curCode = cu.dataset.cur; S.rate = parseFloat(cu.dataset.rate) || 1; $$("#curSeg [data-cur]").forEach(function (b) { b.classList.toggle("is-on", b === cu); }); rerenderMoney(); if (window.mbCurrency) mbCurrency.save(cu.dataset.cur, cu.dataset.rate); return; }
  });

  root.addEventListener("input", function (e) {
    var Tt = e.target;
    /* the typed delivery address used to evaporate on reload while the cart survived */
    if (Tt.id === "ctAddr" || Tt.id === "coAddr") { S.addr = Tt.value; LS.set("addr", S.addr); return; }
    if (Tt.id === "ctTable" || Tt.id === "coTable") { S.table = Tt.value; return; }
    if (Tt.matches && Tt.matches("[data-notein]")) { var row = Tt.closest(".ct-row"); for (var k = 0; k < S.cart.length; k++) if (S.cart[k].key === row.dataset.key) { S.cart[k].note = Tt.value; writeCart(); break; } return; }
    if (Tt.id === "soInput") renderSoResults();
  });

  function rerenderMoney() { renderGrid(); if (active === "item") openItem(S.currentId, false); renderCart(); if (active === "checkout") renderCheckout(); }

  /* single-purpose buttons */
  var promo = $("#promoCard");
  if (promo) promo.addEventListener("click", function () {
    /* open the dish the card actually advertises, not itemList()[0] */
    var target = item(promo.dataset.promoId) || itemList()[0];
    if (target) openItem(target.id);
  });
  /* The search trigger is optional — a store can hide it — and with no way to open
     the overlay its close button is equally moot, so neither may be assumed present. */
  /* Header globe: language sheet (rendered only for multi-language stores). */
  var langBtn = $("#langBtn"); if (langBtn) langBtn.addEventListener("click", function () { if ($("#langSheet")) sheet("langSheet"); });
  var cvCateringBtn = $("#cvCateringBtn"); if (cvCateringBtn) cvCateringBtn.addEventListener("click", function () { sheet("cvCateringSheet"); });
  var searchBtn = $("#searchBtn"); if (searchBtn) searchBtn.addEventListener("click", openSearch);
  var soClose = $("#soClose"); if (soClose) soClose.addEventListener("click", closeSearch);
  var seeAll = $("#seeAll"); if (seeAll) seeAll.addEventListener("click", function () { S.cat = "all"; S.sub = "root"; renderCats(); syncSubChips(); renderGrid(); });
  var filterBtn = $("#filterBtn"); if (filterBtn) filterBtn.addEventListener("click", function () { syncFilterCount(); sheet("filterSheet"); });
  var filterDone = $("#filterDone"); if (filterDone) filterDone.addEventListener("click", function () { sheet("filterSheet", false); renderGrid(); });
  var filterReset = $("#filterReset"); if (filterReset) filterReset.addEventListener("click", function () { resetFilters(); renderGrid(); });
  var goCo = $("#goCheckout"); if (goCo) goCo.addEventListener("click", function () { show("checkout"); });
  var couponBtn = $("#couponApply"); if (couponBtn) couponBtn.addEventListener("click", applyCoupon);
  var cw = $("#callWaiter"); if (cw) cw.addEventListener("click", callWaiter);
  var po = $("#placeOrder"); if (po) po.addEventListener("click", placeOrder);
  var lb = $("#loyaltyBtn"); if (lb) lb.addEventListener("click", function () { loadLoyalty(); sheet("loyaltySheet"); });
  var rb = $("#redeemBtn"); if (rb) rb.addEventListener("click", function () { loadLoyalty(); sheet("loyaltySheet"); });
  var lr = $("#loyRedeem"); if (lr) lr.addEventListener("click", redeemLoyalty);
  var rr = $("#reserveRow"); if (rr) rr.addEventListener("click", function () { sheet("reserveSheet"); });
  var lu = $("#lookupBtn"); if (lu) lu.addEventListener("click", lookupOrders);
  var meAuth = $("#meAuthBtn"); if (meAuth) meAuth.addEventListener("click", function () { sheet("authSheet"); });
  var auLogin = $("#auLogin");
  if (auLogin) auLogin.addEventListener("click", function () {
    authPost(EP.login, { email: ($("#auEmail").value || "").trim(), password: $("#auPass").value || "" }, auLogin);
  });
  var auReg = $("#auRegister");
  if (auReg) auReg.addEventListener("click", function () {
    authPost(EP.register, {
      name: ($("#arName").value || "").trim(), email: ($("#arEmail").value || "").trim(),
      phone: ($("#arPhone").value || "").trim(), password: $("#arPass").value || "",
      password_confirmation: $("#arPass").value || "",
    }, auReg);
  });
  /* A3 · phone-OTP login — send code, then verify the 6-box code under `code`.
     Distinct from the checkout OTP gate (#otpBoxes / EP.otpVerify). */
  var doughOtpSendBtn = $("#doughOtpSend");
  if (doughOtpSendBtn) doughOtpSendBtn.addEventListener("click", function () {
    var phone = ($("#doughOtpPhone").value || "").trim(); if (!phone) return;
    var err = $("#auErr"); if (err) err.hidden = true;
    var sp = doughOtpSendBtn.querySelector(".spinner"); doughOtpSendBtn.disabled = true; if (sp) sp.hidden = false;
    fetch(EP.otpRequest, { method: "POST", headers: { "Content-Type": "application/json", "Accept": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf }, body: JSON.stringify({ store_id: CFG.storeId, phone: phone }) })
      .then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (d) {
        doughOtpSendBtn.disabled = false; if (sp) sp.hidden = true;
        if (d && (d.success || d.status === "success")) { $("#doughOtpStep1").hidden = true; $("#doughOtpStep2").hidden = false; var f = $("#doughOtpBoxes input"); if (f) f.focus(); }
        else if (err) { err.textContent = (d && d.message) || T.net_err; err.hidden = false; }
      })
      .catch(function () { doughOtpSendBtn.disabled = false; if (sp) sp.hidden = true; if (err) { err.textContent = T.net_err; err.hidden = false; } });
  });
  var doughOtpVerifyBtn = $("#doughOtpVerify");
  if (doughOtpVerifyBtn) doughOtpVerifyBtn.addEventListener("click", function () {
    var code = $$("#doughOtpBoxes input").map(function (b) { return b.value; }).join("");
    authPost(EP.otpVerifyLogin, { store_id: CFG.storeId, phone: ($("#doughOtpPhone").value || "").trim(), code: code }, doughOtpVerifyBtn);
  });
  (function () {
    var boxes = $$("#doughOtpBoxes input");
    boxes.forEach(function (inp, i) {
      inp.addEventListener("input", function () {
        var digits = inp.value.replace(/\D/g, "");
        inp.value = digits.slice(0, 1);
        var last = i;
        for (var d = 1; d < digits.length && i + d < boxes.length; d++) { boxes[i + d].value = digits[d]; last = i + d; }
        boxes.forEach(function (b) { b.classList.toggle("filled", !!b.value); });
        if (digits && boxes[last + 1] && boxes[last].value) boxes[last + 1].focus(); else if (digits) boxes[last].focus();
      });
      inp.addEventListener("keydown", function (e) { if (e.key === "Backspace" && !inp.value && boxes[i - 1]) boxes[i - 1].focus(); });
      inp.addEventListener("paste", function (e) {
        e.preventDefault();
        var d = ((e.clipboardData || window.clipboardData).getData("text") || "").replace(/\D/g, "").slice(0, boxes.length);
        if (!d) return;
        for (var j = 0; j < boxes.length; j++) { boxes[j].value = d.charAt(j) || ""; boxes[j].classList.toggle("filled", !!boxes[j].value); }
        (boxes[Math.min(d.length, boxes.length) - 1] || boxes[0]).focus();
      });
    });
  })();
  var gt = $("#goTrack"); if (gt) gt.addEventListener("click", function (e) { if (!S.lastTrackUrl) { e.preventDefault(); toast(T.net_err); } });

  /* item view fav + add */
  var iFav = $("#iFav"); if (iFav) iFav.addEventListener("click", function () { var id = String(S.currentId); if (S.favs.has(id)) S.favs.delete(id); else S.favs.add(id); LS.set("favs", Array.from(S.favs)); iFav.classList.toggle("is-on"); renderGrid(); });
  var iAdd = $("#iAdd");
  if (iAdd) iAdd.addEventListener("click", function () {
    var i = item(S.currentId); if (!i || i.is_sold_out) return;
    var missG = (i.optionGroups || []).filter(function (g) { return g.required && (S.iMods[g.id] === undefined || S.iMods[g.id] === null); })[0];
    if (missG) { toast(T.choose + ": " + missG.title); return; }
    addLine(i.id, i.variants && i.variants.length ? S.iVariant : null, Array.from(S.iExtras), Object.assign({}, S.iMods), S.iQty, null);
    iAdd.classList.remove("pop"); void iAdd.offsetWidth; iAdd.classList.add("pop");
    setTimeout(function () { show("cart"); }, 260);
  });

  /* ── delivery address autocomplete + use-my-location ────
     Shared by the checkout field (#coAddr, wired once below) and the cart's
     delivery field (#ctAddr, re-wired by renderOtFields each time it is
     rebuilt). Coordinates always land in the checkout's hidden #coLat/#coLng
     pair — the order payload reads only those. `onValue` mirrors the visible
     value into the sibling field so the two never disagree. */
  function wireAddressField(input, box, locBtn, attrib, onValue) {
    if (!input || !EP.geoAutocomplete) return null;
    var timer = null, lastAttrib = "";
    function setAttrib(a) { if (a && attrib && a !== lastAttrib) { attrib.textContent = a; attrib.hidden = false; lastAttrib = a; } }
    function hideBox() { if (box) { box.hidden = true; box.innerHTML = ""; } }
    function setCoords(lat, lng) { var la = $("#coLat"), ln = $("#coLng"); if (la) la.value = lat || ""; if (ln) ln.value = lng || ""; }
    /* keep S.addr (what placeOrder actually sends) in sync when the field is
       filled programmatically — a picked suggestion / saved chip / pin does not
       fire the input listener that normally mirrors typing into S.addr. */
    function fill(label, lat, lng) { input.value = label || ""; S.addr = input.value; LS.set("addr", S.addr); setCoords(lat, lng); if (onValue) onValue(input.value); }
    input.addEventListener("input", function () {
      var q = input.value.trim();
      setCoords("", "");                 /* typing invalidates a picked pin */
      if (onValue) onValue(input.value);
      if (timer) clearTimeout(timer);
      if (q.length < 3) { hideBox(); return; }
      timer = setTimeout(function () {
        fetch(EP.geoAutocomplete + "?q=" + encodeURIComponent(q))
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
      fill(opt.dataset.label, opt.dataset.lat, opt.dataset.lng);
      hideBox();
    });
    input.addEventListener("blur", function () { setTimeout(hideBox, 200); });
    if (locBtn) locBtn.addEventListener("click", function () {
      if (!navigator.geolocation) { toast(T.loc_unavailable || "Location unavailable"); return; }
      locBtn.classList.add("is-loading");
      navigator.geolocation.getCurrentPosition(function (pos) {
        var lat = pos.coords.latitude, lng = pos.coords.longitude;
        fetch((EP.geoReverse || "") + "?lat=" + lat + "&lng=" + lng)
          .then(function (r) { return r.json(); })
          .then(function (d) {
            locBtn.classList.remove("is-loading");
            setAttrib(d && d.attribution);
            if (d && d.result) { fill(d.result.label, d.result.lat || lat, d.result.lng || lng); }
            else { toast(T.no_address || "No address found"); }
          }).catch(function () { locBtn.classList.remove("is-loading"); });
      }, function () { locBtn.classList.remove("is-loading"); toast(T.loc_denied || "Location denied"); }, { enableHighAccuracy: true, timeout: 8000 });
    });
    return { fill: fill, hideBox: hideBox };
  }
  (function () {
    var wired = wireAddressField($("#coAddr"), $("#coAddrSuggest"), $("#coUseLocation"), $("#coGeoAttrib"), function (label) {
      var ct = $("#ctAddr"); if (ct) ct.value = label;
    });
    if (!wired) return;
    var saved = $("#savedAddrs");
    if (saved) saved.addEventListener("click", function (e) {
      var b = e.target.closest(".saved-addr"); if (!b) return;
      wired.fill(b.dataset.addr, b.dataset.lat, b.dataset.lng);
      $$(".saved-addr").forEach(function (x) { x.classList.remove("is-on"); });
      b.classList.add("is-on");
      wired.hideBox();
    });
  })();

  /* ── stories rail + full-screen viewer ────────────────── */
  (function () {
    var rail = $("#storiesRail");
    if (!rail || !EP.stories) return;

    var DUR = 5;                       // seconds per story
    var stories = [], idx = 0, timer = null, elapsed = 0;

    (window.mbStoriesGet?window.mbStoriesGet(EP.stories):fetch(EP.stories).then(function(r){return r.json();})).then(function (d) {
      stories = (d && d.data) || [];
      if (!stories.length) { rail.hidden = true; return; }
      rail.innerHTML = stories.map(function (s, i) {
        return '<button class="st-bubble" data-story="' + i + '" type="button">' +
          '<span class="st-ring"><img src="' + esc(s.media_url) + '" alt="" data-csp-error="fallback" data-csp-fallback="' + esc(CFG.defaultImg) + '"></span>' +
          "<small>" + esc(s.caption ? String(s.caption).slice(0, 14) : (CFG.restaurantName || T.popular || "")) + "</small></button>";
      }).join("");
      rail.hidden = false;
    }).catch(function () { rail.hidden = true; });

    var viewer = $("#storyViewer"), media = $("#svMedia"),
        cap = $("#svCaption"), cta = $("#svCta"), prog = $("#svProgress");
    if (!viewer) return;

    function buildProgress() {
      prog.innerHTML = stories.map(function () { return '<span class="sv-seg"><i></i></span>'; }).join("");
    }
    function paintSeg() {
      $$("#svProgress .sv-seg i").forEach(function (bar, i) {
        bar.style.width = i < idx ? "100%" : (i === idx ? (elapsed / DUR * 100) + "%" : "0%");
      });
    }
    function render() {
      var s = stories[idx]; if (!s) return;
      media.src = s.media_url;
      media.style.opacity = "";
      cap.textContent = s.caption || "";
      if (s.cta_url && !(window.mbStoryCta && window.mbStoryCta.isCurrentPage(s.cta_url))) {
        cta.hidden = false;
        cta.href = s.cta_url;
        if (window.mbStoryCta) window.mbStoryCta.apply(cta, s.cta_url);
        cta.querySelector("span").textContent = s.cta_label || T.order_now || "";
      } else { cta.hidden = true; }
      elapsed = 0; paintSeg();
    }
    function tick() { elapsed += 0.05; if (elapsed >= DUR) { next(); return; } paintSeg(); }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function run() { stop(); timer = setInterval(tick, 50); }
    function openStory(i) {
      idx = i; buildProgress();
      viewer.hidden = false;
      void viewer.offsetWidth;         // force a frame so the opacity transition runs
      viewer.classList.add("is-open");
      render(); run();
    }
    function closeStory() {
      stop();
      viewer.classList.remove("is-open");
      setTimeout(function () { viewer.hidden = true; media.removeAttribute("src"); }, 300);
    }
    function next() { if (idx < stories.length - 1) { idx++; render(); } else { closeStory(); } }
    function prev() { if (idx > 0) { idx--; render(); } else { elapsed = 0; paintSeg(); } }

    rail.addEventListener("click", function (e) {
      var b = e.target.closest("[data-story]"); if (b) openStory(Number(b.dataset.story));
    });
    $("#svClose").addEventListener("click", closeStory);
    /* CTA opens the item in-page beneath this viewer; close the viewer so the item sheet is the top layer. */
    if (cta) cta.addEventListener("click", closeStory);
    $("#svNext").addEventListener("click", next);
    $("#svPrev").addEventListener("click", prev);
    var stage = $("#svStage");
    if (stage) {
      /* press-and-hold pauses, like the native story UIs */
      stage.addEventListener("pointerdown", stop);
      stage.addEventListener("pointerup", function () { if (!viewer.hidden) run(); });
      stage.addEventListener("pointercancel", function () { if (!viewer.hidden) run(); });
    }
  })();

  /* ── nearest-branch map (self-hosted Leaflet; geo branch switcher) ── */
  (function () {
    var openBtn = document.getElementById("nearestBranchBtn");
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
          "</button>";
      }).join("");
    }
    function drawMap(branches) {
      var withCoords = branches.filter(function (b) { return b.latitude != null && b.longitude != null; });
      if (!map) {
        map = L.map("branchMap", { attributionControl: false });
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

  /* ── boot ─────────────────────────────────────────────── */
  function boot() {
    // default order type: room service first. The room-service segment only
    // renders when $allow_room_service is set, which already means hotel mode is
    // on AND this QR resolved to a room — so its mere presence is the signal that
    // the guest scanned from their room. Preferring it saves them a tap and, more
    // importantly, stops a room order silently going to the kitchen as a table
    // order when dine-in also happens to be enabled. There is no CFG flag for it,
    // so read the rendered segment; every other branch below is unchanged.
    if ($("#otSeg [data-ot='room_service']")) S.orderType = "room_service";
    else if (CFG.forceTable && CFG.allowOnTable) S.orderType = "on-table";
    else if (CFG.allowDelivery) S.orderType = "delivery";
    else if (CFG.allowTakeaway) S.orderType = "takeaway";
    else if (CFG.allowOnTable) S.orderType = "on-table";
    // the seg only got is-on from a tap, so the default type rendered its fields
    // while no segment looked selected — mark it up front.
    syncOtSeg();

    applyTheme(LS.get("theme", "auto"));
    S.addr = S.addr || LS.get("addr", "");
    renderOb();
    renderCats();
    syncSubChips();
    renderGrid();
    if (window.mbCurrency) mbCurrency.restore("#curSeg");   // replay saved currency choice (fixes reset-on-reload)
    syncBag(false);

    // A deep link (?item= or ?table=) must land the diner straight on the
    // product / table order — the splash otherwise overlays it until "Skip" is
    // tapped. On those loads the intro is auto-dismissed (never shown). NOT
    // persisted to sessionStorage: a later plain visit this session still gets
    // the intro, exactly as a first-time visitor would.
    var bootParams = null;
    try { bootParams = new URLSearchParams(window.location.search); } catch (e) {}
    var deepLinked = !!(bootParams && (bootParams.get("item") || bootParams.get("table")));

    // onboarding once per session
    var seen = false; try { seen = !!sessionStorage.getItem("dough_ob_" + CFG.slug); } catch (e) {}
    if (seen || !OB.length || deepLinked) { var ob = $("#onboard"); if (ob) ob.classList.add("is-done"); }
    updatePill();
    renderRecentAddrs();               /* guest saved-address chips, ready before checkout opens */

    // ?item= deep link → open that product on load (share links / QR to a dish)
    try {
      var deepItem = bootParams ? bootParams.get("item") : new URLSearchParams(window.location.search).get("item");
      if (deepItem && item(deepItem)) openItem(deepItem);
    } catch (e) {}

    if (CFG.isLoggedIn && EP.loyaltyBalance) loadLoyalty();

    /* topbar quick toggle (Lane B): rides dough's NATIVE appearance system
       (setThemeMode/data-dough-theme, LS key "theme") so the #themeSeg
       Auto/Light/Dark segment stays in sync — the shared engine is NOT used
       because dough already owns palette + persistence. */
    var thBtn = document.getElementById("doughThemeToggle");
    if (thBtn) thBtn.addEventListener("click", function () {
      var isDark = root.getAttribute("data-dough-theme") === "dark";
      setThemeMode(isDark ? "light" : "dark");
      thBtn.setAttribute("aria-pressed", String(!isDark));
    });
  }
  boot();

  /* Collapsing header — reveal a condensed bar once the greeting scrolls out of
     the home view's scroll container, and hide it again as the sticky category
     bar reaches the top (dough's .cat-row is position:sticky). The condensed
     buttons forward their click to the real top-chrome controls. */
  (function mountCondensedHeader() {
    var homeView = document.querySelector('.view[data-view="home"]');
    if (!homeView) return;
    var anchor = homeView.querySelector(".home-top");
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
