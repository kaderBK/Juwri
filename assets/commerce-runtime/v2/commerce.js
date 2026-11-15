// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Storefront commerce runtime — v2  (Theme Studio B0.10)
 * ---------------------------------------------------------------------------
 * v2 = every v1 hook and behaviour below, plus the vertical commerce layer a
 * theme opts into with theme.json `"commerce_runtime": "v2"`:
 *
 *   Variant matrix   options per dimension (size × colour …) built from
 *                    item.dimensions / variant.dims; per-variant price + stock;
 *                    impossible / out-of-stock combinations are disabled.
 *   Stock            <span data-mb-stock="ID"></span> badge (data-mb-stock-state
 *                    = in | low | out); adding beyond tracked stock is blocked.
 *   Booking          the checkout's time field becomes a slot picker fed by
 *                    the existing restaurant.scheduleSlots endpoint; the chosen
 *                    slot rides the unchanged submitOrder POST as `scheduled_at`.
 *                    Service stores (capability `service`) must pick a slot.
 *   Age gate         age_restricted items ask for confirmation before adding;
 *                    age_gate stores with the entry toggle on get the carely
 *                    entry interstitial. Remembered per store (sessionStorage).
 *   Fitment          <form data-mb-fitment> make/model/year selects fed by
 *                    storefront.fitment.options + part-number search via
 *                    storefront.fitment.oemSearch; filters (or, with
 *                    data-mb-fitment-mode="highlight", marks) [data-mb-item]
 *                    cards via data-mb-fit = fit | universal | nofit.
 *   Share            <button data-mb-share hidden> (optional data-mb-share-url
 *                    / -title / -text): the Web Share API sheet, else copies
 *                    the link with a localized toast. Ships `hidden`; the
 *                    runtime reveals it, so a v1 page never shows a dead button.
 *
 * Dialogs are native <dialog> (focus trap + Esc) with an ARIA fallback; all
 * markup uses no physical-direction styling, so RTL is the theme's CSS.
 * No inline handlers, no external requests — same-origin endpoints only.
 * ---------------------------------------------------------------------------
 * Platform-owned cart + checkout so sandboxed Liquid themes ship ZERO custom
 * JS. A theme binds declaratively and skins the runtime UI with CSS variables:
 *
 *   <button data-mb-add="{{ item.id }}">Add</button>
 *   <span   data-mb-cart-count></span>
 *   <span   data-mb-cart-total></span>
 *   <button data-mb-open-cart>Cart</button>
 *   <button data-mb-call-waiter>Call waiter</button>
 *   <div    data-mb-item-sheet-mount></div>   <!-- runtime renders the option sheet here -->
 *   <div    data-mb-cart-mount></div>         <!-- runtime renders cart + checkout here -->
 *
 * Config comes from window.MB_COMMERCE (emitted by {% commerce %}). The order
 * POST reproduces the first-party contract exactly, so the existing untouched
 * `restaurant.submitOrder` controller keeps working.
 *
 * NOTE (P2-8 → P2-9): end-to-end order placement is verified in the browser
 * during the crave port. This bundle is the extraction; the port exercises it.
 */
(function () {
  "use strict";

  var CFG = window.MB_COMMERCE;
  if (!CFG || !CFG.items) return;

  var doc = document;
  var CART_KEY = "mb_cart_" + CFG.restaurantSlug;
  var cart = [];
  var coupon = null;
  var orderType = CFG.flags.forceTable ? "on-table" : null;
  var tipPct = 0;      // preset tip percentage of subtotal
  var tipCustom = null; // explicit tip amount (overrides the percentage)
  var curCode = CFG.currency.code; // active display currency (P8-8)
  var curRate = 1;                 // conversion rate from the base currency

  try { cart = JSON.parse(localStorage.getItem(CART_KEY)) || []; } catch (e) { cart = []; }

  /* ----- helpers -------------------------------------------------------- */
  function item(id) { return CFG.items[String(id)] || CFG.items[id]; }
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  /* Bug batch 9 · 6: PHP round($value, $dp) (15 significant digits, half up — mb-money.js's idiom). */
  function roundTo(value, dp) {
    var q = Math.pow(10, Math.max(0, dp | 0));
    return Math.round(Number(((Number(value) || 0) * q).toPrecision(15))) / q;
  }
  /* The store currency's stored precision (KWD 3, USD 2, JPY 0) — tax / tip previews round like the order. */
  function moneyDp() { return CFG.currency.moneyDecimals != null ? CFG.currency.moneyDecimals : 2; }
  function money(n) {
    // Bug batch 9 · 6: the base currency shows its own decimals (KWD 1.235, JPY 1200); a converted one keeps 2.
    var dp = (curCode && curCode !== CFG.currency.code) || CFG.currency.decimals == null ? 2 : CFG.currency.decimals;
    var v = roundTo((Number(n) || 0) * curRate, dp).toFixed(dp);
    // Non-base currency is shown as "CODE 12.34"; the base uses the sign.
    if (curCode && curCode !== CFG.currency.code) return curCode + " " + v;
    return String(CFG.currency.pos) === "1" ? CFG.currency.sign + v : v + CFG.currency.sign;
  }
  function all(sel, ctx) { return Array.prototype.slice.call((ctx || doc).querySelectorAll(sel)); }
  // Translated UI string (keyed by the English source; falls back to English).
  function t(s) { return (CFG.i18n && CFG.i18n[s]) || s; }
  /* The fulfillment modes this store offers, in its OWN vocabulary.
     CFG.fulfillmentModes is the vertical's list (ship / pickup_slot /
     in_store for a goods store, the legacy trio for a restaurant); the
     legacy booleans are the fallback for a config written before it. */
  function modes() {
    if (Array.isArray(CFG.fulfillmentModes) && CFG.fulfillmentModes.length) return CFG.fulfillmentModes;
    var ot = CFG.orderTypes || {}, out = [];
    if (ot.onTable) out.push({ value: "on-table", label: t("Dine-in"), needsAddress: false, needsSlot: false });
    if (ot.takeaway) out.push({ value: "takeaway", label: t("Takeaway"), needsAddress: false, needsSlot: false });
    if (ot.delivery) out.push({ value: "delivery", label: t("Delivery"), needsAddress: true, needsSlot: false });
    return out;
  }
  // The mode currently chosen (falls back to the first one the store offers).
  function mode() {
    var ms = modes();
    for (var i = 0; i < ms.length; i++) { if (ms[i].value === orderType) return ms[i]; }
    return ms[0] || { value: orderType || "", label: "", needsAddress: false, needsSlot: false };
  }
  // A goods store ships to an address; a restaurant delivers to one.
  function addressLabel() { return mode().value === "ship" ? t("Shipping address") : t("Delivery address"); }
  // Translate a freshly-rendered subtree in place: leaf text, placeholders,
  // and aria-labels whose English matches an i18n key. Called after each
  // mount.innerHTML so the runtime UI speaks the diner's language.
  function translate(root) {
    if (!CFG.i18n || !root) return;
    all("[placeholder]", root).forEach(function (el) {
      var p = CFG.i18n[el.getAttribute("placeholder")]; if (p) el.setAttribute("placeholder", p);
    });
    all("[aria-label]", root).forEach(function (el) {
      var a = CFG.i18n[el.getAttribute("aria-label")]; if (a) el.setAttribute("aria-label", a);
    });
    var walk = doc.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
    var nodes = [], n;
    while ((n = walk.nextNode())) nodes.push(n);
    nodes.forEach(function (tn) {
      var txt = tn.nodeValue.trim();
      if (txt && CFG.i18n[txt]) tn.nodeValue = tn.nodeValue.replace(txt, CFG.i18n[txt]);
    });
  }
  function save() { try { localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch (e) {} }
  // v2: translated string with :placeholders replaced.
  function tf(s, rep) {
    var out = t(s);
    Object.keys(rep || {}).forEach(function (k) { out = out.split(":" + k).join(String(rep[k])); });
    return out;
  }
  var STORE = CFG.store || { type: "restaurant", capabilities: {}, ageGate: { enabled: false } };
  var CAPS = STORE.capabilities || {};

  /* ----- v2: stock ------------------------------------------------------ *
   * item.stock / variant.stock are tracked units (null = not tracked). A
   * variant with its own stock is counted per variant; otherwise the item's
   * stock is shared by all of its lines.
   */
  function variantOf(it, vid) {
    return it && vid ? (it.variants || []).filter(function (x) { return x.id === Number(vid); })[0] : null;
  }
  function stockFor(it, vid) {
    if (!it) return null;
    var v = variantOf(it, vid);
    if (v && v.stock != null) return Number(v.stock);
    return it.stock == null ? null : Number(it.stock);
  }
  function isOut(it) {
    if (!it) return true;
    if (it.isSoldOut) return true;
    var vs = it.variants || [];
    if (vs.length) return vs.every(function (v) { var s = stockFor(it, v.id); return s != null && s <= 0; });
    return it.stock != null && Number(it.stock) <= 0;
  }
  function inCart(id, vid, skipIdx) {
    var it = item(id), v = variantOf(it, vid), perVariant = !!(v && v.stock != null);
    return cart.reduce(function (n, l, i) {
      if (i === skipIdx || Number(l.id) !== Number(id)) return n;
      if (perVariant && Number(l.variantId) !== Number(vid)) return n;
      return n + l.qty;
    }, 0);
  }
  function remaining(id, vid, skipIdx) {
    var s = stockFor(item(id), vid);
    return s == null ? Infinity : Math.max(0, s - inCart(id, vid, skipIdx));
  }
  function stockState(it, vid) {
    var s = vid ? stockFor(it, vid) : (isOut(it) ? 0 : (it.variants && it.variants.length ? null : stockFor(it, 0)));
    if (it && it.isSoldOut) return "out";
    if (s == null) return null;
    if (s <= 0) return "out";
    return s <= (Number(it.lowStock) || 5) ? "low" : "in";
  }
  function stockText(state, it, vid) {
    if (state === "out") return t("Out of stock");
    if (state === "low") return tf("Only :count left", { count: stockFor(it, vid) });
    if (state === "in") return t("In stock");
    return "";
  }

  /* ----- v2: toast (polite live region for blocked actions) ------------- */
  var toastEl = null, toastTimer = null;
  function toast(msg) {
    if (!msg) return;
    if (!toastEl) {
      toastEl = doc.createElement("div");
      toastEl.className = "mb-toast";
      toastEl.setAttribute("role", "status");
      toastEl.setAttribute("aria-live", "polite");
      doc.body.appendChild(toastEl);
    }
    toastEl.textContent = msg;
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, 3200);
  }

  /* ----- v2: share (data-mb-share) --------------------------------------- *
   * Native share sheet when the browser has one (mobile), else copy the link
   * to the clipboard and say so. Only http(s) links are shared; anything else
   * falls back to this page's own URL (without the hash).
   * ---------------------------------------------------------------------- */
  function initShare() { all("[data-mb-share][hidden]").forEach(function (b) { b.hidden = false; }); }
  function shareUrl(btn) {
    var u = btn.getAttribute("data-mb-share-url") || "";
    return /^https?:\/\//i.test(u) ? u : String(location.href).split("#")[0];
  }
  function copyLink(url) {
    function fallback() {
      var ta = doc.createElement("textarea"), ok = false;
      ta.value = url; ta.setAttribute("readonly", "");
      ta.style.position = "fixed"; ta.style.opacity = "0";
      doc.body.appendChild(ta); ta.select();
      try { ok = doc.execCommand("copy"); } catch (e) { ok = false; }
      doc.body.removeChild(ta);
      toast(ok ? t("Link copied") : t("Could not copy the link"));
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function () { toast(t("Link copied")); }, fallback);
    } else { fallback(); }
  }
  function share(btn) {
    var url = shareUrl(btn);
    var data = { title: btn.getAttribute("data-mb-share-title") || doc.title, url: url };
    var text = btn.getAttribute("data-mb-share-text"); if (text) data.text = text;
    if (navigator.share) {
      navigator.share(data).catch(function (e) { if (!e || e.name !== "AbortError") copyLink(url); });
      return;
    }
    copyLink(url);
  }

  /* ----- v2: accessible dialog ------------------------------------------ *
   * Native <dialog>.showModal() (Esc, inert page) when available, else an
   * ARIA role=dialog fallback. Either way Tab / Shift+Tab cycle INSIDE the
   * dialog while it is open (trapFocus) and focus returns to the opener on
   * close. Themes skin .mb-dialog; it is usable unstyled.
   */
  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  /* Keep Tab focus inside box: wraps last→first and first→last (Shift). */
  function trapFocus(box, ev) {
    if (ev.key !== "Tab") return;
    var f = all(FOCUSABLE, box).filter(function (el) { return !el.hidden && el.getClientRects().length; });
    if (!f.length) { ev.preventDefault(); return; }
    var first = f[0], last = f[f.length - 1], cur = doc.activeElement;
    var inside = box.contains(cur);
    if (ev.shiftKey && (cur === first || !inside)) { ev.preventDefault(); last.focus(); }
    else if (!ev.shiftKey && (cur === last || !inside)) { ev.preventDefault(); first.focus(); }
  }
  var dlgSeq = 0;
  function mbDialog(opts, onConfirm, onCancel) {
    var opener = doc.activeElement;
    var id = "mb-dlg-" + (++dlgSeq);
    var native = typeof window.HTMLDialogElement === "function";
    var d = doc.createElement(native ? "dialog" : "div");
    d.className = "mb-dialog";
    d.setAttribute("aria-labelledby", id + "-t");
    d.setAttribute("aria-describedby", id + "-d");
    if (!native) { d.setAttribute("role", "dialog"); d.setAttribute("aria-modal", "true"); }
    var h = '<div class="mb-dialog-card"><h2 class="mb-dialog-title" id="' + id + '-t">' + esc(opts.title) + "</h2>" +
      '<p class="mb-dialog-text" id="' + id + '-d">' + esc(opts.text) + "</p>" +
      '<div class="mb-dialog-actions">';
    if (opts.cancel) h += '<button type="button" class="mb-ghost" data-mb-dlg="cancel">' + esc(opts.cancel) + "</button>";
    h += '<button type="button" class="mb-place" data-mb-dlg="ok">' + esc(opts.confirm) + "</button></div></div>";
    d.innerHTML = h;
    var mount = doc.querySelector("[data-mb-dialog-mount]") || doc.body;
    mount.appendChild(d);
    var done = false;
    function close(ok) {
      if (done) return;
      done = true;
      try { if (native && d.open) d.close(); } catch (e) {}
      if (d.parentNode) d.parentNode.removeChild(d);
      try { if (opener && opener.focus) opener.focus(); } catch (e) {}
      if (ok) { if (onConfirm) onConfirm(); } else if (onCancel) onCancel();
    }
    d.addEventListener("click", function (ev) {
      var b = ev.target.closest("[data-mb-dlg]");
      if (b) { close(b.getAttribute("data-mb-dlg") === "ok"); return; }
      if (ev.target === d && opts.cancel) close(false); // backdrop tap
    });
    d.addEventListener("cancel", function (ev) { ev.preventDefault(); if (opts.cancel) close(false); });
    d.addEventListener("keydown", function (ev) {
      if (ev.key === "Escape" && !native) { if (opts.cancel) close(false); return; }
      trapFocus(d, ev);
    });
    if (native) { try { d.showModal(); } catch (e) { d.setAttribute("open", ""); } }
    var ok = d.querySelector('[data-mb-dlg="ok"]');
    if (ok) ok.focus();
    return d;
  }

  /* ----- v2: age gate (mirrors carely) ---------------------------------- */
  var AGE_KEY = "mb_age_ok_" + CFG.restaurantSlug;
  function ageOk() { try { return sessionStorage.getItem(AGE_KEY) === "1"; } catch (e) { return false; } }
  function setAgeOk() { try { sessionStorage.setItem(AGE_KEY, "1"); } catch (e) {} }
  var ageSessionOk = false; // in-memory fallback when sessionStorage is blocked
  function withAgeCheck(it, proceed) {
    if (!it || !it.ageRestricted || ageSessionOk || ageOk()) { proceed(); return; }
    mbDialog({
      title: t("Age-restricted item"),
      text: t("This item is age-restricted. Please confirm you meet the minimum legal age to buy it."),
      confirm: t("I confirm"), cancel: t("Cancel"),
    }, function () { ageSessionOk = true; setAgeOk(); proceed(); });
  }
  function initAgeGate() {
    var g = STORE.ageGate || {};
    if (!g.enabled || !g.entry || ageOk()) return;
    mbDialog({ title: t("Before you continue"), text: g.disclaimer || "", confirm: t("Continue") },
      function () { ageSessionOk = true; setAgeOk(); });
  }

  function unitPrice(it, variantId, mods, extras) {
    var p = Number(it.price) || 0;
    if (variantId) {
      var v = (it.variants || []).filter(function (x) { return x.id === variantId; })[0];
      if (v) p = Number(v.price) || 0; // variants are absolute
    }
    (mods || []).forEach(function (m) { p += Number(m.price) || 0; });
    (extras || []).forEach(function (e) { p += Number(e.price) || 0; });
    return p;
  }
  function linePrice(l) { return unitPrice(item(l.id), l.variantId, l.mods, l.extras) * l.qty; }
  function lineLabel(l) {
    var it = item(l.id);
    if (!it) return "";
    var parts = [];
    if (l.variantId) {
      var v = (it.variants || []).filter(function (x) { return x.id === l.variantId; })[0];
      if (v) parts.push(v.displayLabel || v.label);
    }
    (l.mods || []).forEach(function (m) { if (m.label) parts.push(m.label); });
    (l.extras || []).forEach(function (e) { if (e.title) parts.push(e.title); });
    return parts.join(" · ");
  }
  function subtotal() { return cart.reduce(function (s, l) { return s + linePrice(l); }, 0); }
  function count() { return cart.reduce(function (n, l) { return n + l.qty; }, 0); }

  function totals() {
    var sub = subtotal();
    var disc = 0;
    if (coupon) {
      /* Bug batch 7 · 4: the SERVER's discount for this basket (re-quoted when it changes) — exact in the store
         currency for every type, and the only way to price buy-X-get-Y (it needs the cart lines). */
      disc = Math.min(couponDiscount(), sub);
    }
    /* Bug batch 9 · 6: Order::computeTax — fixed or percentage, on the pre-discount subtotal, at the store currency's
       stored precision (was always a percentage at 2 decimals: KWD 10 % of 12.345 previewed 1.23, the order took 1.235). */
    var tax = 0;
    if (CFG.charges.tax > 0 && sub > 0) {
      tax = String(CFG.charges.taxType || "percentage") === "fixed"
        ? roundTo(CFG.charges.tax, moneyDp())
        : roundTo(sub * (CFG.charges.tax / 100), moneyDp());
    }
    var del = orderType === "delivery" && cart.length ? Number(CFG.charges.delivery) || 0 : 0;
    var t = CFG.flags.allowTips ? (tipCustom != null ? Number(tipCustom) || 0 : roundTo(sub * (tipPct / 100), moneyDp())) : 0;
    return { sub: sub, disc: disc, tax: tax, del: del, tip: t, total: Math.max(0, sub - disc + tax + del + t) };
  }

  /* ----- badge / bindings ---------------------------------------------- */
  function syncBindings() {
    all("[data-mb-cart-count]").forEach(function (el) {
      el.textContent = String(count());
      // Opt-in: a count element marked data-mb-hide-empty (e.g. a tab badge)
      // hides itself at zero.
      if (el.hasAttribute("data-mb-hide-empty")) el.hidden = count() === 0;
    });
    all("[data-mb-cart-total]").forEach(function (el) { el.textContent = money(subtotal()); });
    all("[data-mb-add]").forEach(function (el) {
      var it = item(el.getAttribute("data-mb-add"));
      if (it && isOut(it)) el.setAttribute("disabled", "disabled");
    });
    // v2: stock badges on cards — <span data-mb-stock="ID">.
    all("[data-mb-stock]").forEach(function (el) {
      var it = item(el.getAttribute("data-mb-stock"));
      if (!it) return;
      var st = stockState(it, 0);
      el.textContent = stockText(st, it, 0);
      if (st) el.setAttribute("data-mb-stock-state", st); else el.removeAttribute("data-mb-stock-state");
      el.hidden = !st;
    });
    renderCart();
  }

  function addLine(id, variantId, mods, extras, qty) {
    // v2: never add beyond tracked stock (item- or variant-level).
    var left = remaining(id, variantId || 0);
    qty = qty || 1;
    if (left <= 0) { toast(t("Out of stock")); return false; }
    if (qty > left) { toast(tf("Only :count left", { count: left })); return false; }
    cart.push({ id: Number(id), variantId: variantId || 0, mods: mods || [], extras: extras || [], qty: qty });
    save();
    syncBindings();
    return true;
  }
  function setQty(idx, qty) {
    if (!cart[idx]) return;
    if (qty > cart[idx].qty) {
      var left = remaining(cart[idx].id, cart[idx].variantId, idx);
      if (qty > left) { toast(left > 0 ? tf("Only :count left", { count: left }) : t("Out of stock")); return; }
    }
    cart[idx].qty = Math.max(0, qty);
    if (cart[idx].qty === 0) cart.splice(idx, 1);
    save();
    syncBindings();
  }

  /* ----- option sheet --------------------------------------------------- */
  function needsSheet(it) {
    return (it.variants && it.variants.length) || (it.extras && it.extras.length) || (it.groups && it.groups.length);
  }

  // v2: one sheet at a time — the mount's listeners are bound once and act on
  // the currently open item (v1 re-bound them on every open).
  var sheetId = null, sheetBound = false, sheetOpener = null;
  // The option sheet is a modal dialog too: close it and hand focus back.
  function closeItemSheet(mount) {
    mount.hidden = true;
    mount.innerHTML = "";
    var back = sheetOpener; sheetOpener = null;
    try { if (back && back.focus && doc.contains(back)) back.focus(); } catch (e) {}
  }

  function openSheet(id) {
    var it = item(id);
    if (!it) return;
    if (!CFG.flags.allowOrder || isOut(it)) return;
    // v2: confirm age-restricted items before they can reach the cart.
    if (it.ageRestricted && !(ageSessionOk || ageOk())) { withAgeCheck(it, function () { openSheet(id); }); return; }
    if (!needsSheet(it)) { addLine(id, 0, [], [], 1); return; }

    var mount = doc.querySelector("[data-mb-item-sheet-mount]");
    if (!mount) {
      // No sheet: quick-add the first purchasable variant (v1 added the base).
      var fv = firstAvailableVariant(it);
      addLine(id, fv ? fv.id : 0, [], [], 1);
      return;
    }

    sheetId = id;
    if (mount.hidden || !sheetOpener) sheetOpener = doc.activeElement;
    var sel = firstAvailableVariant(it);
    var h = '<div class="mb-sheet" role="dialog" aria-modal="true" aria-label="' + esc(it.name) + '">';
    h += '<header class="mb-sheet-head"><b>' + esc(it.name) + "</b>" +
      '<button type="button" class="mb-sheet-close" data-mb-close aria-label="Close">&times;</button></header>';
    h += '<div class="mb-sheet-body">' + itemMeta(it) + optionsMarkup(it, sel ? sel.id : 0) + "</div>";
    h += '<footer class="mb-sheet-foot"><button type="button" class="mb-sheet-add" data-mb-sheet-add><span>Add</span> · <span></span></button></footer>';
    h += "</div>";
    mount.innerHTML = h; translate(mount);
    mount.hidden = false;
    refreshOptions(mount, it);
    priceFoot();
    var first = mount.querySelector("input:not([disabled]), button");
    if (first) first.focus();

    function priceFoot() {
      var c = collectOptions(mount, it);
      var span = mount.querySelector(".mb-sheet-add span:last-child");
      if (span) span.textContent = money(unitPrice(it, c.variantId, c.mods, c.extras));
    }

    if (sheetBound) return;
    sheetBound = true;
    mount.addEventListener("change", function () {
      var cur = item(sheetId); if (!cur) return;
      refreshOptions(mount, cur);
      var c = collectOptions(mount, cur);
      var span = mount.querySelector(".mb-sheet-add span:last-child");
      if (span) span.textContent = money(unitPrice(cur, c.variantId, c.mods, c.extras));
    });
    mount.addEventListener("keydown", function (ev) {
      if (mount.hidden) return;
      if (ev.key === "Escape") { closeItemSheet(mount); return; }
      trapFocus(mount, ev);
    });
    mount.addEventListener("click", function (ev) {
      if (ev.target.closest("[data-mb-close]")) { closeItemSheet(mount); return; }
      if (ev.target.closest("[data-mb-sheet-add]")) {
        var cur = item(sheetId); if (!cur) return;
        if (optionsMissing(mount, cur)) { toast(t("Choose an option")); return; }
        var c = collectOptions(mount, cur);
        if (hasVariants(cur) && !c.variantId) { toast(t("Choose an option")); return; }
        if (addLine(sheetId, c.variantId, c.mods, c.extras, 1) === false) return;
        closeItemSheet(mount);
      }
    });
  }

  /* ----- product view (P8-5) ------------------------------------------- *
   * A full-screen product screen (gallery + options + qty + related), rendered
   * into [data-mb-product-mount] and shown via the router. Falls back to the
   * option sheet for themes without a product view. Reuses the pricing helpers.
   *   <article data-mb-open="ID"> … </article>   (card tap opens the product)
   */
  var itemList = Object.keys(CFG.items).map(function (k) { return CFG.items[k]; });
  var pId = null, pQty = 1;

  /* ----- v2: variant matrix -------------------------------------------- *
   * item.dimensions = [{name, values[]}]; variant.dims = {name: value}. When
   * every variant has a value for every dimension the runtime renders one
   * radio group per dimension and resolves the chosen cell to its variant;
   * otherwise it keeps v1's flat variant list (out-of-stock rows disabled).
   */
  function hasVariants(it) { return !!(it.variants && it.variants.length); }
  function variantBuyable(it, v) { var s = stockFor(it, v.id); return s == null || s > 0; }
  function firstAvailableVariant(it) {
    if (!hasVariants(it)) return null;
    return it.variants.filter(function (v) { return variantBuyable(it, v); })[0] || null;
  }
  function isMatrix(it) {
    var dims = it.dimensions || [];
    if (!dims.length || !hasVariants(it)) return false;
    return it.variants.every(function (v) {
      var d = v.dims || {};
      return dims.every(function (dm) { return d[dm.name] != null && d[dm.name] !== ""; });
    });
  }
  function matrixSelection(ctx, it) {
    var sel = {};
    (it.dimensions || []).forEach(function (dm, di) {
      var inp = ctx.querySelector('input[name="mb-dim-' + di + '"]:checked');
      if (inp) sel[dm.name] = dm.values[Number(inp.value)];
    });
    return sel;
  }
  function matchVariant(it, sel, anyStock) {
    return (it.variants || []).filter(function (v) {
      if (!anyStock && !variantBuyable(it, v)) return false;
      return Object.keys(sel).every(function (k) { return String((v.dims || {})[k]) === String(sel[k]); });
    })[0] || null;
  }
  function matrixMarkup(it, selVariant) {
    var v0 = variantOf(it, selVariant) || it.variants[0];
    var h = "";
    (it.dimensions || []).forEach(function (dm, di) {
      h += '<fieldset class="mb-opt-group mb-dim" data-mb-dim="' + di + '"><legend class="mb-opt-title">' + esc(dm.name) + "</legend>";
      dm.values.forEach(function (val, vi) {
        var on = v0 && String((v0.dims || {})[dm.name]) === String(val);
        h += '<label class="mb-opt mb-dim-opt"><input type="radio" name="mb-dim-' + di + '" value="' + vi + '"' +
          (on ? " checked" : "") + "><span>" + esc(val) + "</span></label>";
      });
      h += "</fieldset>";
    });
    return h;
  }
  // Re-evaluate which dimension values are purchasable given the others,
  // keep the selection on a buyable cell, and refresh price/stock/add state.
  function refreshOptions(ctx, it) {
    if (!ctx || !it) return;
    var vid = 0;
    if (isMatrix(it)) {
      var dims = it.dimensions || [];
      var sel = matrixSelection(ctx, it);
      if (!matchVariant(it, sel, false)) {
        // Selection landed on an unavailable cell: keep the value the diner
        // just picked and move the others to the first buyable combination.
        var best = it.variants.filter(function (v) { return variantBuyable(it, v); })
          .sort(function (a, b) { return overlap(b) - overlap(a) || prefix(b) - prefix(a); })[0];
        if (best) {
          dims.forEach(function (dm, di) {
            var vi = dm.values.indexOf(String(best.dims[dm.name]));
            var inp = ctx.querySelector('input[name="mb-dim-' + di + '"][value="' + vi + '"]');
            if (inp) inp.checked = true;
          });
          sel = matrixSelection(ctx, it);
        }
      }
      // A value is offered when some buyable variant has it together with the
      // selections of the dimensions BEFORE it (Shopify-style cascade), so any
      // buyable cell stays reachable: picking it re-seats the later dimensions.
      dims.forEach(function (dm, di) {
        all('input[name="mb-dim-' + di + '"]', ctx).forEach(function (inp) {
          var probe = {};
          dims.slice(0, di).forEach(function (prev) { if (sel[prev.name] != null) probe[prev.name] = sel[prev.name]; });
          probe[dm.name] = dm.values[Number(inp.value)];
          var ok = !!matchVariant(it, probe, false);
          inp.disabled = !ok;
          var lab = inp.closest("label");
          if (lab) lab.classList.toggle("is-disabled", !ok);
        });
      });
      var mv = matchVariant(it, sel, false);
      vid = mv ? mv.id : 0;
    } else if (hasVariants(it)) {
      all('input[name="mb-variant"]', ctx).forEach(function (inp) {
        var v = variantOf(it, inp.value), ok = v ? variantBuyable(it, v) : false;
        inp.disabled = !ok;
        if (!ok && inp.checked) inp.checked = false;
        var lab = inp.closest("label");
        if (lab) lab.classList.toggle("is-disabled", !ok);
      });
      if (!ctx.querySelector('input[name="mb-variant"]:checked')) {
        var fv = ctx.querySelector('input[name="mb-variant"]:not([disabled])');
        if (fv) fv.checked = true;
      }
      var vi2 = ctx.querySelector('input[name="mb-variant"]:checked');
      vid = vi2 ? Number(vi2.value) : 0;
    }
    function overlap(v) {
      var s2 = matrixSelection(ctx, it), n = 0;
      Object.keys(s2).forEach(function (k) { if (String((v.dims || {})[k]) === String(s2[k])) n++; });
      return n;
    }
    // Tie-break: how many LEADING dimensions match (earlier choices win).
    function prefix(v) {
      var s2 = matrixSelection(ctx, it), n = 0, dl = it.dimensions || [];
      for (var i = 0; i < dl.length; i++) {
        if (String((v.dims || {})[dl[i].name]) !== String(s2[dl[i].name])) break;
        n++;
      }
      return n;
    }
    // Live stock line for the chosen cell (or the plain item).
    var st = hasVariants(it) ? (vid ? stockState(it, vid) : "out") : stockState(it, 0);
    all("[data-mb-stock-live]", ctx).forEach(function (el) {
      el.textContent = stockText(st, it, vid);
      if (st) el.setAttribute("data-mb-stock-state", st); else el.removeAttribute("data-mb-stock-state");
      el.hidden = !st;
    });
    var blocked = (hasVariants(it) && !vid) || st === "out";
    all("[data-mb-sheet-add], [data-mb-prod-add]", ctx).forEach(function (b) { b.disabled = blocked; });
  }
  // SKU / service duration / unit / stock line shown in the sheet + product view.
  function itemMeta(it) {
    var h = "";
    var bits = [];
    if (it.turnaroundHours) bits.push(tf("Ready in :hours h", { hours: it.turnaroundHours }));
    if (it.pricingUnit === "kg") bits.push(t("per kg"));
    if (bits.length) h += '<p class="mb-item-meta">' + esc(bits.join(" · ")) + "</p>";
    h += '<p class="mb-stock" data-mb-stock-live aria-live="polite" hidden></p>';
    return h;
  }

  function optionsMarkup(it, selVariant) {
    var h = "";
    if (isMatrix(it)) {
      h += matrixMarkup(it, selVariant);
    } else if (it.variants && it.variants.length) {
      h += '<div class="mb-opt-group">';
      it.variants.forEach(function (v) {
        h += '<label class="mb-opt"><input type="radio" name="mb-variant" value="' + v.id + '"' +
          (v.id === selVariant ? " checked" : "") + '><span>' + esc(v.displayLabel || v.label) + "</span><em>" + money(v.price) + "</em></label>";
      });
      h += "</div>";
    }
    (it.groups || []).forEach(function (g) {
      h += '<div class="mb-opt-group" data-group="' + g.id + '"><p class="mb-opt-title">' + esc(g.title) +
        (g.required ? ' <span class="mb-req">*</span>' : "") + "</p>";
      (g.options || []).forEach(function (o) {
        var multi = g.max !== 1;
        h += '<label class="mb-opt"><input type="' + (multi ? "checkbox" : "radio") + '" name="mb-g' + g.id +
          '" value="' + o.i + '"><span>' + esc(o.label) + "</span>" + (o.price ? "<em>+" + money(o.price) + "</em>" : "") + "</label>";
      });
      h += "</div>";
    });
    if (it.extras && it.extras.length) {
      h += '<div class="mb-opt-group"><p class="mb-opt-title">Extras</p>';
      it.extras.forEach(function (e) {
        h += '<label class="mb-opt"><input type="checkbox" name="mb-extra" value="' + e.id + '"><span>' + esc(e.title) + "</span><em>+" + money(e.price) + "</em></label>";
      });
      h += "</div>";
    }
    return h;
  }
  function collectOptions(ctx, it) {
    var mods = [];
    (it.groups || []).forEach(function (g) {
      all('input[name="mb-g' + g.id + '"]:checked', ctx).forEach(function (inp) {
        var opt = (g.options || []).filter(function (o) { return String(o.i) === inp.value; })[0];
        mods.push({ groupId: g.id, optIndex: Number(inp.value), label: opt ? opt.label : "", price: opt ? opt.price : 0 });
      });
    });
    var extras = [];
    all('input[name="mb-extra"]:checked', ctx).forEach(function (inp) {
      var ex = (it.extras || []).filter(function (e) { return String(e.id) === inp.value; })[0];
      if (ex) extras.push({ id: ex.id, title: ex.title, price: ex.price });
    });
    var variantId = 0;
    if (isMatrix(it)) {
      var mv = matchVariant(it, matrixSelection(ctx, it), false);
      variantId = mv ? mv.id : 0;
    } else {
      var vinp = ctx.querySelector('input[name="mb-variant"]:checked');
      variantId = vinp ? Number(vinp.value) : 0;
    }
    return { variantId: variantId, mods: mods, extras: extras };
  }
  function optionsMissing(ctx, it) {
    return (it.groups || []).some(function (g) {
      return g.required && all('input[name="mb-g' + g.id + '"]:checked', ctx).length < Math.max(1, g.min);
    });
  }
  function productImgs(it) {
    var g = (it.gallery && it.gallery.length) ? it.gallery.slice() : [];
    if (it.img && g.indexOf(it.img) === -1) g.unshift(it.img);
    return g.length ? g : (it.img ? [it.img] : []);
  }

  function openProduct(id) {
    var it = item(id), mount = doc.querySelector("[data-mb-product-mount]");
    if (!it) return;
    if (!mount || !hasRouter || !viewEl("product")) { openSheet(id); return; }
    pId = id; pQty = 1;
    var imgs = productImgs(it);
    var fav0 = firstAvailableVariant(it);
    var selVariant = fav0 ? fav0.id : ((it.variants && it.variants.length) ? it.variants[0].id : 0);

    var h = '<div class="mb-prod">';
    h += '<button type="button" class="mb-prod-back" data-mb-back aria-label="Back">&lsaquo;</button>';
    h += '<div class="mb-prod-gallery">';
    imgs.forEach(function (src, i) { h += '<img class="mb-prod-img" src="' + esc(src) + '" alt=""' + (i ? " hidden" : "") + ' data-i="' + i + '">'; });
    h += "</div>";
    if (imgs.length > 1) {
      h += '<div class="mb-prod-dots">';
      imgs.forEach(function (_, i) { h += '<button type="button" class="mb-prod-dot' + (i === 0 ? " is-on" : "") + '" data-mb-gallery-dot="' + i + '"></button>'; });
      h += "</div>";
    }
    h += '<div class="mb-prod-body">';
    h += '<div class="mb-prod-head"><h2>' + esc(it.name) + "</h2>";
    h += '<button type="button" class="mb-prod-fav' + (favs[id] ? " is-on" : "") + '" data-mb-fav="' + id + '" aria-pressed="' + (favs[id] ? "true" : "false") + '">&#9829;</button></div>';
    var meta = [];
    if (it.rating) meta.push("★ " + it.rating);
    if (it.popular) meta.push("★ Popular");
    if (meta.length) h += '<p class="mb-prod-meta">' + esc(meta.join("  ·  ")) + "</p>";
    if (it.desc) h += '<p class="mb-prod-desc">' + esc(it.desc) + "</p>";
    if (isOut(it)) h += '<p class="mb-prod-stock">Sold out</p>';
    h += itemMeta(it);
    h += '<div class="mb-prod-opts">' + optionsMarkup(it, selVariant) + "</div>";

    var rel = itemList.filter(function (x) { return x.id !== it.id && !isOut(x); }).slice(0, 8);
    if (rel.length) {
      h += '<div class="mb-prod-related"><p class="mb-opt-title">You might also like</p><div class="mb-rail">';
      rel.forEach(function (r) {
        h += '<button type="button" class="mb-rail-card" data-mb-open="' + r.id + '">' +
          (r.img ? '<img src="' + esc(r.img) + '" alt="">' : "") + "<b>" + esc(r.name) + "</b><span>" + money(r.price) + "</span></button>";
      });
      h += "</div></div>";
    }
    h += "</div>"; // body

    if (CFG.flags.allowOrder && !isOut(it)) {
      h += '<footer class="mb-prod-cta"><div class="mb-qty">' +
        '<button type="button" data-mb-qty="-1" aria-label="-">&minus;</button><output data-mb-qty-val>1</output>' +
        '<button type="button" data-mb-qty="1" aria-label="+">+</button></div>' +
        '<button type="button" class="mb-place" data-mb-prod-add><span>Add</span> &middot; <span data-mb-prod-total></span></button></footer>';
    }
    h += "</div>";
    mount.innerHTML = h; translate(mount);
    showView("product");
    refreshOptions(mount, it);
    productRecalc();
  }

  function productRecalc() {
    var mount = doc.querySelector("[data-mb-product-mount]"), it = item(pId);
    if (!it || !mount) return;
    var c = collectOptions(mount, it);
    var span = mount.querySelector("[data-mb-prod-total]");
    if (span) span.textContent = money(unitPrice(it, c.variantId, c.mods, c.extras) * pQty);
    var qv = mount.querySelector("[data-mb-qty-val]");
    if (qv) qv.textContent = String(pQty);
  }
  function productSetQty(d) {
    var mount = doc.querySelector("[data-mb-product-mount]"), it = item(pId);
    var next = Math.max(1, pQty + d);
    if (d > 0 && it && mount) {
      var left = remaining(pId, collectOptions(mount, it).variantId);
      if (next > left) { toast(left > 0 ? tf("Only :count left", { count: left }) : t("Out of stock")); return; }
    }
    pQty = next; productRecalc();
  }
  function productAdd() {
    var mount = doc.querySelector("[data-mb-product-mount]"), it = item(pId);
    if (!it || !mount) return;
    if (optionsMissing(mount, it)) { toast(t("Choose an option")); return; }
    var c = collectOptions(mount, it);
    if (hasVariants(it) && !c.variantId) { toast(t("Choose an option")); return; }
    var addId = pId, qty = pQty;
    withAgeCheck(it, function () {
      if (addLine(addId, c.variantId, c.mods, c.extras, qty) === false) return;
      backView();
    });
  }
  function productGallery(i) {
    var mount = doc.querySelector("[data-mb-product-mount]");
    if (!mount) return;
    all(".mb-prod-img", mount).forEach(function (img) { img.hidden = String(img.getAttribute("data-i")) !== String(i); });
    all("[data-mb-gallery-dot]", mount).forEach(function (d) { d.classList.toggle("is-on", d.getAttribute("data-mb-gallery-dot") === String(i)); });
  }

  /* ----- cart + checkout ------------------------------------------------ */
  // v2: the checkout form is re-rendered on every change (order type, tip,
  // qty…); carry the diner's typed values across instead of wiping them.
  function formSnapshot(mount) {
    var snap = {};
    all("[data-mb-checkout-form] [name]", mount).forEach(function (f) {
      if (f.type !== "checkbox" && f.type !== "radio") snap[f.name] = f.value;
    });
    return snap;
  }
  function formRestore(mount, snap) {
    Object.keys(snap).forEach(function (k) {
      var f = mount.querySelector('[data-mb-checkout-form] [name="' + k + '"]');
      if (f && !f.readOnly && snap[k] !== "") f.value = snap[k];
    });
  }

  function renderCart() {
    var mount = doc.querySelector("[data-mb-cart-mount]");
    if (!mount) return;
    var snap = formSnapshot(mount);
    var T = totals();
    var h = '<div class="mb-cart">';
    if (!cart.length) {
      h += '<p class="mb-cart-empty">Your cart is empty.</p>';
    } else {
      h += '<ul class="mb-cart-lines">';
      cart.forEach(function (l, i) {
        var it = item(l.id);
        var lbl = lineLabel(l);
        h += '<li class="mb-cart-line"><span class="mb-cl-name">' + esc(it ? it.name : "") +
          (lbl ? '<em class="mb-cl-opts">' + esc(lbl) + "</em>" : "") + "</span>" +
          '<span class="mb-cl-qty"><button data-mb-dec="' + i + '">−</button>' + l.qty +
          '<button data-mb-inc="' + i + '">+</button></span>' +
          '<span class="mb-cl-price">' + money(linePrice(l)) + "</span></li>";
      });
      h += "</ul>";
      h += checkoutForm(T);
    }
    h += "</div>";
    mount.innerHTML = h; translate(mount);
    formRestore(mount, snap);
    if (cart.length) ensureSlots();
  }

  function checkoutForm(T) {
    var ms = modes();
    var seg = "";
    ms.forEach(function (m) {
      seg += '<button type="button" data-mb-ot="' + esc(m.value) + '"' +
        (orderType === m.value ? ' class="is-on"' : "") + ">" + esc(m.label) + "</button>";
    });

    var h = '<form class="mb-checkout" data-mb-checkout-form>';
    h += '<div class="mb-ot-seg">' + seg + "</div>";
    h += '<input class="mb-field" name="name" placeholder="Your name" value="' + esc(CFG.flags.customerName || "") + '">';
    if (orderType === "on-table") {
      h += '<input class="mb-field" name="table" placeholder="Table" value="' + esc(CFG.flags.defaultTable || "") + '"' + (CFG.flags.forceTable ? " readonly" : "") + ">";
    } else {
      h += '<input class="mb-field" name="phone" placeholder="Phone">';
    }
    if (mode().needsAddress) h += '<input class="mb-field" name="address" placeholder="' + esc(addressLabel()) + '">';
    if (slotsWanted()) h += slotsMarkup();
    else if (CFG.flags.allowScheduling && !slotlessGoodsMode()) h += '<input class="mb-field" type="datetime-local" name="scheduled_at">';
    if (CFG.flags.allowCoupons) h += '<div class="mb-coupon"><input class="mb-field" data-mb-coupon-input placeholder="Coupon"><button type="button" data-mb-coupon-apply>Apply</button></div>';
    if (CFG.flags.giftCards) h += '<div class="mb-giftcard"><input class="mb-field" data-mb-gift-card-input placeholder="Gift card code" aria-label="Gift card code"></div>';
    h += '<textarea class="mb-field" name="message" placeholder="Notes"></textarea>';

    if (CFG.flags.allowTips) {
      h += '<div class="mb-tip"><span class="mb-tip-label">Tip</span><div class="mb-tip-opts">';
      [0, 10, 15, 20].forEach(function (p) {
        h += '<button type="button" data-mb-tip="' + p + '"' +
          (tipCustom == null && tipPct === p ? ' class="is-on"' : "") + ">" + (p ? p + "%" : "None") + "</button>";
      });
      h += '</div><input class="mb-field mb-tip-custom" data-mb-tip-custom type="number" min="0" step="0.5" placeholder="Custom"' +
        (tipCustom != null ? ' value="' + tipCustom + '"' : "") + "></div>";
    }

    h += '<div class="mb-totals">';
    h += '<div class="mb-tr"><span>Subtotal</span><b>' + money(T.sub) + "</b></div>";
    if (T.disc) h += '<div class="mb-tr"><span>Discount</span><b>−' + money(T.disc) + "</b></div>";
    if (T.tax) h += '<div class="mb-tr"><span>' + esc(CFG.charges.taxLabel) + "</span><b>" + money(T.tax) + "</b></div>";
    if (T.del) h += '<div class="mb-tr"><span>Delivery</span><b>' + money(T.del) + "</b></div>";
    if (T.tip) h += '<div class="mb-tr"><span>Tip</span><b>' + money(T.tip) + "</b></div>";
    h += '<div class="mb-tr mb-tr-total"><span>Total</span><b>' + money(T.total) + "</b></div>";
    h += "</div>";
    h += '<p class="mb-err" data-mb-err hidden></p>';
    h += '<button type="submit" class="mb-place">Place order</button>';
    h += '<div class="mb-cart-actions">';
    if (CFG.flags.allowCallWaiter) h += '<button type="button" class="mb-ghost" data-mb-call-waiter>Call waiter</button>';
    h += '<button type="button" class="mb-ghost" data-mb-clear-cart>Clear cart</button>';
    h += "</div>";
    h += "</form>";
    return h;
  }

  /* ----- v2: booking / scheduling slot picker --------------------------- *
   * Fed by the EXISTING restaurant.scheduleSlots endpoint (T1 — the same one
   * the Blade schedule-slot picker, bookla and carely call):
   *   GET ?type=<order type>&date=Y-m-d[&branch_id=]
   *   → {enabled, date, dates:[Y-m-d], slots:[{value:"Y-m-d H:i", label, full}]}
   * The chosen value is posted as `scheduled_at` on the unchanged submitOrder.
   * Non-service stores lead with "As soon as possible" (empty = ASAP, the T1
   * picker's rule); service stores (capability `service`) must pick a slot.
   * If the endpoint says disabled/fails, v1's datetime field comes back.
   */
  var slot = { key: null, loading: false, enabled: false, failed: false, data: null, value: "", date: null };
  function isService() { return !!CAPS.service; }
  /* Delivery and in-store for a shop are not a restaurant pre-order.
     A time belongs to pickup, an appointment, or a food store that turned
     scheduling on. */
  function slotlessGoodsMode() {
    var v = mode().value || "";
    return v === "ship" || v === "in_store";
  }
  function slotsWanted() {
    /* A mode that NEEDS a slot (pickup_slot / appointment) asks for one even
       when the store has no general scheduling switch on — the slot IS the
       mode's contract (the platform's own goods checkout requires it too). */
    return !!(CFG.endpoints && CFG.endpoints.scheduleSlots) && !slot.failed &&
      !slotlessGoodsMode() &&
      (CFG.flags.allowScheduling || isService() || mode().needsSlot);
  }
  function slotRequired() {
    return (isService() || mode().needsSlot) && slot.enabled && !!(slot.data && (slot.data.slots || []).length);
  }
  function slotType() { return orderType || (modes()[0] && modes()[0].value) || "takeaway"; }
  function ensureSlots() {
    if (!slotsWanted()) return;
    var key = slotType() + "|" + (slot.date || "");
    if (slot.key === key || slot.loading) return;
    slot.loading = true;
    var url = CFG.endpoints.scheduleSlots + "?type=" + encodeURIComponent(slotType()) +
      (slot.date ? "&date=" + encodeURIComponent(slot.date) : "");
    var bid = new URLSearchParams(window.location.search).get("branch_id");
    if (bid) url += "&branch_id=" + encodeURIComponent(bid);
    fetch(url, { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        slot.loading = false; slot.key = key;
        if (!d || d.enabled === false) { slot.enabled = false; slot.failed = true; slot.value = ""; renderCart(); return; }
        slot.enabled = true; slot.data = d; slot.date = d.date || slot.date;
        slot.key = slotType() + "|" + (slot.date || "");
        var vals = (d.slots || []).filter(function (s) { return !s.full; }).map(function (s) { return s.value; });
        if (slot.value && vals.indexOf(slot.value) === -1) slot.value = "";
        renderCart();
      })
      .catch(function () { slot.loading = false; slot.failed = true; renderCart(); });
  }
  function slotDayLabel(ymd) {
    var pad = function (n) { return (n < 10 ? "0" : "") + n; };
    var d = new Date(), today = d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
    d.setDate(d.getDate() + 1);
    var tomorrow = d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
    if (ymd === today) return t("Today");
    if (ymd === tomorrow) return t("Tomorrow");
    try {
      return new Intl.DateTimeFormat(doc.documentElement.lang || undefined, { weekday: "short", month: "short", day: "numeric" })
        .format(new Date(ymd + "T12:00:00"));
    } catch (e) { return ymd; }
  }
  function slotsMarkup() {
    var h = '<fieldset class="mb-slots" data-mb-slots><legend class="mb-opt-title">' + esc(t("Choose a time")) + "</legend>";
    var d = slot.data;
    if (!slot.enabled || !d) return h + '<p class="mb-slots-loading" aria-busy="true">…</p></fieldset>';
    h += '<div class="mb-slot-days" role="group" aria-label="' + esc(t("Choose a time")) + '">';
    (d.dates || []).forEach(function (ymd) {
      var on = ymd === slot.date;
      h += '<button type="button" class="mb-slot-day' + (on ? " is-on" : "") + '" data-mb-slot-day="' + esc(ymd) + '" aria-pressed="' + on + '">' + esc(slotDayLabel(ymd)) + "</button>";
    });
    h += "</div>";
    var slots = d.slots || [];
    h += '<div class="mb-slot-times" role="group">';
    if (!isService() && !mode().needsSlot) {
      h += '<button type="button" class="mb-slot' + (!slot.value ? " is-on" : "") + '" data-mb-slot="" aria-pressed="' + !slot.value + '">' + esc(t("As soon as possible")) + "</button>";
    }
    if (!slots.length) h += '<p class="mb-slots-empty">' + esc(t("No times available")) + "</p>";
    slots.forEach(function (s) {
      var on = s.value === slot.value;
      h += '<button type="button" class="mb-slot' + (on ? " is-on" : "") + '" data-mb-slot="' + esc(s.value) + '" aria-pressed="' + on + '"' +
        (s.full ? " disabled" : "") + ">" + esc(s.label) + "</button>";
    });
    h += "</div></fieldset>";
    return h;
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
  /* Bug batch 7 · 4: restaurant.applyCoupon takes {code, subtotal} — this posted `coupon_code` and read a
     `res.coupon` the endpoint never returns, so no coupon ever applied. It now posts the code, the subtotal and
     the cart lines (buy-X-get-Y is priced on them) and keeps the server's discount, re-quoted on a basket change. */
  function couponForm(code) {
    var fd = new FormData();
    fd.append("_token", CFG.csrf);
    fd.append("code", code);
    fd.append("subtotal", String(subtotal()));
    fd.append("items", JSON.stringify(orderLines()));
    fd.append("ordering-type", orderType || "");
    return fd;
  }
  function couponSig() { return JSON.stringify([subtotal(), orderLines(), orderType || ""]); }
  function couponDiscount() {
    var c = coupon, sig = couponSig();
    if (c.sig !== sig) {
      c.sig = sig;
      fetch(CFG.endpoints.coupon, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" }, body: couponForm(c.code) })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (coupon !== c || c.sig !== sig) return;
          c.discount = res && res.success ? Number(res.discount) || 0 : 0;
          syncBindings();
        })
        .catch(function () {});
    }
    return Number(c.discount) || 0;
  }
  function applyCoupon(code) {
    if (!code) return;
    var sig = couponSig();
    fetch(CFG.endpoints.coupon, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" }, body: couponForm(code) })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success) {
          coupon = { code: res.label || code, coupon_type: res.coupon_type, percentage: res.percentage, discount: Number(res.discount) || 0, sig: sig };
          syncBindings();
        } else {
          toast(res && res.message ? res.message : "Invalid promo code.");
        }
      })
      .catch(function () {});
  }

  function callWaiter() {
    var fd = new FormData();
    fd.append("_token", CFG.csrf);
    var t = orderType === "on-table" ? (CFG.flags.defaultTable || "") : "";
    fd.append("table", t);
    fetch(CFG.endpoints.waiter, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" }, body: fd }).catch(function () {});
  }

  function placeOrder(form) {
    var err = form.querySelector("[data-mb-err]");
    function fail(m) { if (err) { err.textContent = m; err.hidden = false; } }

    if (!orderType) return fail(t("Select an order type."));
    if (!cart.length) return;
    var T = totals();
    if (CFG.charges.minOrder > 0 && T.sub < CFG.charges.minOrder) return fail(t("Minimum order is") + " " + money(CFG.charges.minOrder));

    var name = (form.querySelector('[name="name"]') || {}).value;
    name = (name || "").trim() || CFG.flags.customerName || "";
    if (!name) return fail(t("Please enter your name."));
    if (mode().needsAddress && !((form.querySelector('[name="address"]') || {}).value || "").trim()) return fail(t("Please enter a delivery address."));
    if (CFG.flags.authMode === "required" && !CFG.flags.isLoggedIn) return fail(t("Please sign in to order."));
    if (slotRequired() && !slot.value) return fail(t("Please choose a time."));

    var payload = orderLines();

    var fd = new FormData();
    fd.append("_token", CFG.csrf);
    fd.append("items", JSON.stringify(payload));
    fd.append("name", name);
    fd.append("ordering-type", orderType);
    if (orderType === "on-table") fd.append("table", ((form.querySelector('[name="table"]') || {}).value) || "");
    else fd.append("phone-number", ((form.querySelector('[name="phone"]') || {}).value) || "");
    if (mode().needsAddress) fd.append("address", ((form.querySelector('[name="address"]') || {}).value || "").trim());
    fd.append("pay_via", "pay_on_counter");
    fd.append("tip_amount", String(T.tip || 0));
    fd.append("message", ((form.querySelector('[name="message"]') || {}).value) || "");
    var sched = form.querySelector('[name="scheduled_at"]');
    if (slot.enabled && slot.value) fd.append("scheduled_at", slot.value);
    else if (sched && sched.value) fd.append("scheduled_at", sched.value);
    if (coupon) fd.append("coupon_code", coupon.code);
    /* T2: the gift card is a TENDER, not a discount — it is redeemed against
       the amount due when the order is created (OrderPricing), and the server
       validates the code up front (StoreController@submitOrder). So there is no
       apply button and no client-side price math here: the code travels with
       the order and the server owns the money. */
    var gcInput = form.querySelector("[data-mb-gift-card-input]");
    if (gcInput && gcInput.value.trim()) fd.append("gift_card_code", gcInput.value.trim());
    var bid = new URLSearchParams(window.location.search).get("branch_id");
    if (bid) fd.append("branch_id", bid);

    var btn = form.querySelector(".mb-place");
    if (btn) btn.disabled = true;

    fetch(CFG.endpoints.submitOrder, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (btn) btn.disabled = false;
        if (res && res.success) {
          cart = []; coupon = null; tipPct = 0; tipCustom = null; slot.value = ""; save(); syncBindings();
          if (res.whatsapp_url) window.open(res.whatsapp_url, "_blank");
          var track = res.track_url || (res.message && /^https?:\/\//.test(res.message) ? res.message : "");
          // P8-7: in-app confirmed view when the theme has one, else redirect.
          if (hasRouter && viewEl("confirmed") && showConfirmed(track)) return;
          if (track) { window.location.href = track; return; }
        } else {
          fail((res && res.message) || t("Something went wrong."));
        }
      })
      .catch(function () { if (btn) btn.disabled = false; fail(t("Network error. Please try again.")); });
  }

  /* ----- confirmed view (P8-7) ----------------------------------------- *
   * In-app order confirmation (check + confetti + track link) instead of a
   * hard redirect. Auto-forwards to live tracking after a beat.
   */
  function showConfirmed(track) {
    var mount = doc.querySelector("[data-mb-confirmed-mount]");
    if (!mount) return false;
    var h = '<div class="mb-confirm">';
    h += '<div class="mb-confetti" aria-hidden="true">';
    for (var i = 0; i < 16; i++) h += "<i></i>";
    h += "</div>";
    h += '<div class="mb-confirm-check"><svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg></div>';
    h += "<h2>Order confirmed</h2><p>Your order has been placed successfully.</p>";
    if (track) h += '<a class="mb-place" href="' + esc(track) + '">Track order</a>';
    h += '<button type="button" class="mb-ghost" data-mb-go="home">Back to menu</button>';
    h += "</div>";
    mount.innerHTML = h; translate(mount);
    showView("confirmed");
    if (track) setTimeout(function () { window.location.href = track; }, 4000);
    return true;
  }

  /* ----- catalog: search / filter / favorites / language --------------- *
   * All theme-declarative — themes ship zero JS. A card opts in with
   *   <article data-mb-item="ID" data-mb-name="…" data-mb-veg data-mb-halal
   *            data-mb-gf data-mb-special> … <button data-mb-fav="ID"> …
   * and the theme adds any of:
   *   <input data-mb-search>                     filter by name
   *   <button data-mb-filter="vegan|halal|gluten_free|special|fav|all">
   *   <span data-mb-fav-count></span>
   *   <button data-mb-set-lang="ar">             set the menu language
   * Sections with no visible items get [data-mb-cat] hidden automatically.
   */
  var FAV_KEY = "mb_fav_" + CFG.restaurantSlug;
  var favs = {};
  try { favs = JSON.parse(localStorage.getItem(FAV_KEY)) || {}; } catch (e) { favs = {}; }
  var search = "";
  var filter = "all";

  function saveFavs() { try { localStorage.setItem(FAV_KEY, JSON.stringify(favs)); } catch (e) {} }

  function itemMatches(el) {
    if (fitFilterOn && fitHide && el.getAttribute("data-mb-fit") === "nofit") return false;
    if (search) {
      var name = (el.getAttribute("data-mb-name") || "").toLowerCase();
      if (name.indexOf(search) === -1) return false;
    }
    // The catalogue facets (size / colour / price) — a variant-level rule.
    if (!facetMatch(item(el.getAttribute("data-mb-item")))) return false;
    switch (filter) {
      case "vegan": return el.hasAttribute("data-mb-veg");
      case "halal": return el.hasAttribute("data-mb-halal");
      case "gluten_free": return el.hasAttribute("data-mb-gf");
      case "special": return el.hasAttribute("data-mb-special");
      case "fav": return !!favs[el.getAttribute("data-mb-item")];
      default: return true;
    }
  }

  function applyCatalog() {
    all("[data-mb-item]").forEach(function (el) {
      el.hidden = !itemMatches(el);
    });
    // Hide category sections left with no visible items.
    all("[data-mb-cat]").forEach(function (sec) {
      var any = all("[data-mb-item]", sec).some(function (el) { return !el.hidden; });
      sec.hidden = !any;
    });
    // Reflect favourite state + count.
    all("[data-mb-fav]").forEach(function (btn) {
      btn.classList.toggle("is-on", !!favs[btn.getAttribute("data-mb-fav")]);
      btn.setAttribute("aria-pressed", favs[btn.getAttribute("data-mb-fav")] ? "true" : "false");
    });
    all("[data-mb-fav-count]").forEach(function (el) {
      el.textContent = String(Object.keys(favs).filter(function (k) { return favs[k]; }).length);
    });
    all("[data-mb-filter]").forEach(function (b) {
      b.classList.toggle("is-on", b.getAttribute("data-mb-filter") === filter);
    });
    // Global empty state (P8-4) — shown when nothing matches the search/filter.
    var anyVisible = all("[data-mb-item]").some(function (el) { return !el.hidden; });
    all("[data-mb-empty]").forEach(function (el) { el.hidden = anyVisible; });
  }

  /* ----- catalogue facets: size / colour / price ------------------------- *
   * The store's facets arrive in CFG.facets — the sizes on offer, the colours
   * with their swatch, the price range — and every item's variants carry the
   * size and colour they stand for. An item matches when ONE of its variants
   * satisfies every chosen facet, so the panel never shows an item the
   * customer cannot actually buy. A store with no facets (a restaurant) never
   * shows the button at all.
   */
  var FACETS = CFG.facets || {};
  var facetSel = { size: [], color: [] };
  var facetPrice = null;   // the chosen maximum price (null = no limit)

  function facetOn() {
    return facetSel.size.length > 0 || facetSel.color.length > 0 || facetPrice !== null;
  }
  function facetCount() {
    return facetSel.size.length + facetSel.color.length + (facetPrice !== null ? 1 : 0);
  }
  function facetMatch(it) {
    if (!facetOn()) return true;
    if (!it) return true;              // a card the runtime doesn't know: leave it be
    var vs = it.variants || [];
    if (!vs.length) {
      // No variants: only a price limit can match it.
      if (facetSel.size.length || facetSel.color.length) return false;
      return facetPrice === null || Number(it.price || 0) <= facetPrice;
    }
    for (var i = 0; i < vs.length; i++) {
      var v = vs[i];
      if (facetSel.size.length && facetSel.size.indexOf(String(v.size)) === -1) continue;
      if (facetSel.color.length && facetSel.color.indexOf(String(v.color)) === -1) continue;
      if (facetPrice !== null && Number(v.price || 0) > facetPrice) continue;
      return true;
    }
    return false;
  }
  function facetVisible() {
    var n = 0;
    all("[data-mb-item]").forEach(function (el) { if (!el.hidden) n++; });
    return n;
  }

  var facetsMountEl = null, facetsOpener = null, facetsBound = false;
  function facetsMount() {
    var m = doc.querySelector("[data-mb-filters-mount]");
    if (m) return m;
    if (facetsMountEl) return facetsMountEl;
    facetsMountEl = doc.createElement("div");
    facetsMountEl.className = "mb-filters-mount";
    facetsMountEl.setAttribute("data-mb-filters-mount", "");
    doc.body.appendChild(facetsMountEl);
    return facetsMountEl;
  }

  function facetsMarkup() {
    var h = '<div class="mb-sheet mb-facets" role="dialog" aria-modal="true" aria-label="' + esc(t("Filters")) + '">';
    h += '<header class="mb-sheet-head"><b>' + esc(t("Filters")) + "</b>" +
      '<button type="button" class="mb-sheet-close" data-mb-facets-close aria-label="' + esc(t("Close")) + '">&times;</button></header>';
    h += '<div class="mb-sheet-body">';
    if ((FACETS.sizes || []).length) {
      h += '<section class="mb-facets__group"><h3 class="mb-facets__title">' + esc(t("Size")) + '</h3>' +
        '<div class="mb-facets__chips" role="group" aria-label="' + esc(t("Size")) + '">';
      FACETS.sizes.forEach(function (s) {
        var on = facetSel.size.indexOf(s) !== -1;
        h += '<button type="button" class="mb-facets__chip' + (on ? " is-on" : "") + '" data-mb-facet="size" data-value="' + esc(s) + '" aria-pressed="' + (on ? "true" : "false") + '">' + esc(s) + "</button>";
      });
      h += "</div></section>";
    }
    if ((FACETS.colors || []).length) {
      h += '<section class="mb-facets__group"><h3 class="mb-facets__title">' + esc(t("Colour")) + '</h3>' +
        '<div class="mb-facets__swatches" role="group" aria-label="' + esc(t("Colour")) + '">';
      FACETS.colors.forEach(function (c) {
        var on = facetSel.color.indexOf(c.value) !== -1;
        h += '<button type="button" class="mb-facets__swatch' + (on ? " is-on" : "") + '" data-mb-facet="color" data-value="' + esc(c.value) + '" aria-pressed="' + (on ? "true" : "false") + '" aria-label="' + esc(c.value) + '" title="' + esc(c.value) + '"><span style="background:' + esc(c.hex || "transparent") + '"></span></button>';
      });
      h += "</div></section>";
    }
    if (FACETS.priceMax !== undefined && FACETS.priceMax !== null && Number(FACETS.priceMax) > 0) {
      var pmin = Number(FACETS.priceMin || 0), pmax = Number(FACETS.priceMax || 0);
      var cur = facetPrice === null ? pmax : facetPrice;
      h += '<section class="mb-facets__group"><h3 class="mb-facets__title">' + esc(t("Price")) + "</h3>";
      h += '<input type="range" class="mb-facets__range" data-mb-facet-price min="' + pmin + '" max="' + pmax + '" step="1" value="' + cur + '" aria-label="' + esc(t("Price")) + '" aria-valuetext="' + esc(money(cur)) + '">';
      h += '<div class="mb-facets__rangevals"><span>' + money(pmin) + '</span><span data-mb-facet-price-label>' + money(cur) + "</span></div>";
      h += "</section>";
    }
    h += "</div>";
    h += '<footer class="mb-sheet-foot mb-facets__foot">';
    h += '<button type="button" class="mb-ghost" data-mb-facets-clear>' + esc(t("Clear all")) + "</button>";
    h += '<span class="mb-facets__count" data-mb-facets-count aria-live="polite"></span>';
    h += '<button type="button" class="mb-place" data-mb-facets-apply>' + esc(t("Show results")) + "</button>";
    h += "</footer></div>";
    return h;
  }

  function facetsCountUp() {
    var label = t(":count items").replace(":count", String(facetVisible()));
    all("[data-mb-facets-count]").forEach(function (el) { el.textContent = label; });
    // The button carries how many filters are on — the customer must be able
    // to see that a filter is doing something without opening the panel.
    var n = facetCount();
    all("[data-mb-filters-badge]").forEach(function (el) {
      el.textContent = String(n);
      el.hidden = !n;
    });
    all("[data-mb-filters-open]").forEach(function (el) {
      el.setAttribute("aria-expanded", "false");
      el.classList.toggle("is-on", n > 0);
    });
  }

  function openFacets(opener) {
    var mount = facetsMount();
    mount.innerHTML = facetsMarkup();
    translate(mount);
    mount.hidden = false;
    facetsOpener = opener || doc.activeElement;
    facetsCountUp();
    var first = mount.querySelector("button");
    if (first) first.focus();
    if (facetsBound) return;
    facetsBound = true;
    mount.addEventListener("keydown", function (ev) {
      if (mount.hidden) return;
      if (ev.key === "Escape") { closeFacets(); return; }
      trapFocus(mount, ev);
    });
  }

  function closeFacets() {
    var mount = doc.querySelector("[data-mb-filters-mount]");
    if (!mount) return;
    mount.hidden = true;
    mount.innerHTML = "";
    var back = facetsOpener; facetsOpener = null;
    try { if (back && back.focus && doc.contains(back)) back.focus(); } catch (e) {}
  }

  function facetsReset() {
    facetSel.size = [];
    facetSel.color = [];
    facetPrice = null;
    var mount = doc.querySelector("[data-mb-filters-mount]");
    if (mount) { mount.innerHTML = facetsMarkup(); translate(mount); }
    applyCatalog();
    facetsCountUp();
  }

  // The price slider: the customer drags it, the catalogue narrows live.
  function onFacetPrice(input) {
    facetPrice = Number(input.value);
    var lbl = doc.querySelector("[data-mb-facet-price-label]");
    if (lbl) lbl.textContent = money(facetPrice);
    input.setAttribute("aria-valuetext", money(facetPrice));
    applyCatalog();
    facetsCountUp();
  }

  /* ----- favourites view (fix) ----------------------------------------- *
   * Renders the saved items as cards into [data-mb-favs-mount]. Shares the
   * favs store with the home hearts, so toggling anywhere stays in sync.
   */
  function favCardHTML(it) {
    // v2 renders its own .mb-* markup (skinned by the section library's runtime
    // CSS); v1 keeps crave's cl-* classes.
    return '<article class="mb-fav-card" data-mb-item="' + it.id + '" data-mb-open="' + it.id + '">' +
      '<div class="mb-fav-media">' +
        (it.img ? '<img src="' + esc(it.img) + '" alt="" loading="lazy">' : "") +
        '<button class="mb-fav-btn is-on" type="button" data-mb-fav="' + it.id + '" aria-pressed="true" aria-label="' + esc(it.name) + '">♥</button>' +
      "</div>" +
      '<div class="mb-fav-body"><h3 class="mb-fav-name">' + esc(it.name) + "</h3>" +
        (it.rating ? '<span class="mb-fav-rate">★ ' + it.rating + "</span>" : "") +
        '<div class="mb-fav-foot"><b class="mb-fav-price">' + money(it.price) + "</b>" +
        (CFG.flags.allowOrder && !isOut(it) ? '<button class="mb-fav-add" type="button" data-mb-add="' + it.id + '" aria-label="Add">+</button>' : "") +
      "</div></div></article>";
  }
  function renderFavs() {
    var mount = doc.querySelector("[data-mb-favs-mount]");
    if (!mount) return;
    var ids = Object.keys(favs).filter(function (k) { return favs[k] && item(k); });
    if (!ids.length) {
      mount.innerHTML = '<div class="mb-favs-empty"><p>Tap the heart on any dish to save it here</p>' +
        '<button type="button" class="mb-ghost" data-mb-go="home">Browse menu</button></div>';
      translate(mount);
      return;
    }
    mount.innerHTML = '<div class="mb-favs-grid">' + ids.map(function (id) { return favCardHTML(item(id)); }).join("") + "</div>";
    translate(mount);
  }

  /* ----- v2: fitment (car parts) --------------------------------------- *
   *   <form data-mb-fitment [data-mb-fitment-mode="highlight"]>
   *     <select data-mb-fitment-make></select> <select data-mb-fitment-model></select>
   *     <select data-mb-fitment-year></select> <input data-mb-fitment-oem>
   *     <button type="submit">…</button> <button type="button" data-mb-fitment-reset>…</button>
   *     <p data-mb-fitment-status></p>
   *   </form>
   * Missing controls are rendered by the runtime. Feeds: the EXISTING
   * storefront.fitment.options ({enabled, fitments:[{menu_id,year,make,model,
   * submodel,engine}]}) and storefront.fitment.oemSearch?q= ({menu_ids}).
   * Torque's rule: an item with no fitment rows is universal (fits all).
   * Cards get data-mb-fit = fit | universal | nofit; default mode hides nofit.
   */
  var FITMENTS = null, fitFilterOn = false, fitHide = true, oemIds = null;
  var VEH_KEY = "mb_vehicle_" + CFG.restaurantSlug;
  var vehicle = {};
  try { vehicle = JSON.parse(sessionStorage.getItem(VEH_KEY)) || {}; } catch (e) { vehicle = {}; }
  function eqi(a, b) { return String(a || "").toLowerCase() === String(b || "").toLowerCase(); }
  function uniq(list) { var o = []; list.forEach(function (x) { if (o.indexOf(x) === -1) o.push(x); }); return o; }
  function fitForm() { return doc.querySelector("[data-mb-fitment]"); }
  function fitSelect(form, key, label) {
    var el = form.querySelector("[data-mb-fitment-" + key + "]");
    if (!el) {
      var wrap = doc.createElement("label");
      wrap.className = "mb-fit-field";
      wrap.innerHTML = "<span>" + esc(t(label)) + '</span><select class="mb-field" data-mb-fitment-' + key + "></select>";
      // Before the form's own buttons / status line when they are direct children.
      var anchor = form.querySelector("button, [type=submit], [data-mb-fitment-status]");
      form.insertBefore(wrap, anchor && anchor.parentNode === form ? anchor : null);
      el = wrap.querySelector("select");
    }
    if (!el.getAttribute("aria-label")) el.setAttribute("aria-label", t(label));
    return el;
  }
  function fillSelect(sel, values, current, label) {
    sel.innerHTML = '<option value="">' + esc(t(label)) + "</option>" + values.map(function (v) {
      return '<option value="' + esc(v) + '"' + (String(v) === String(current || "") ? " selected" : "") + ">" + esc(v) + "</option>";
    }).join("");
    sel.disabled = values.length === 0;
  }
  function fitStatusOf(id) {
    var rows = FITMENTS.filter(function (f) { return String(f.menu_id) === String(id); });
    if (!rows.length) return "universal";
    var hit = rows.some(function (f) {
      return eqi(f.make, vehicle.make) && (!vehicle.model || eqi(f.model, vehicle.model)) &&
        (!vehicle.year || String(f.year) === String(vehicle.year));
    });
    return hit ? "fit" : "nofit";
  }
  function applyFitment() {
    var form = fitForm();
    if (!form || !FITMENTS) return;
    var makeSel = fitSelect(form, "make", "Make"), modelSel = fitSelect(form, "model", "Model"), yearSel = fitSelect(form, "year", "Year");
    fillSelect(makeSel, uniq(FITMENTS.map(function (f) { return f.make; })).sort(), vehicle.make, "Make");
    var byMake = FITMENTS.filter(function (f) { return eqi(f.make, vehicle.make); });
    fillSelect(modelSel, vehicle.make ? uniq(byMake.map(function (f) { return f.model; })).sort() : [], vehicle.model, "Model");
    var byModel = byMake.filter(function (f) { return eqi(f.model, vehicle.model); });
    fillSelect(yearSel, vehicle.model ? uniq(byModel.map(function (f) { return String(f.year); })).sort().reverse() : [], vehicle.year, "Year");

    fitFilterOn = !!vehicle.make || oemIds !== null;
    fitHide = form.getAttribute("data-mb-fitment-mode") !== "highlight";
    var fits = 0;
    all("[data-mb-item]").forEach(function (el) {
      var id = el.getAttribute("data-mb-item"), st = null;
      if (oemIds !== null) st = oemIds.indexOf(Number(id)) !== -1 ? "fit" : "nofit";
      else if (vehicle.make) st = fitStatusOf(id);
      if (st) el.setAttribute("data-mb-fit", st); else el.removeAttribute("data-mb-fit");
      if (st === "fit" || st === "universal") fits++;
    });
    var status = form.querySelector("[data-mb-fitment-status]");
    if (!status) {
      status = doc.createElement("p");
      status.className = "mb-fit-status";
      status.setAttribute("data-mb-fitment-status", "");
      form.appendChild(status);
    }
    status.setAttribute("role", "status");
    status.textContent = fitFilterOn ? (fits ? tf(":count parts fit your vehicle", { count: fits }) : t("No parts match your vehicle.")) : "";
    applyCatalog();
  }
  function saveVehicle() { try { sessionStorage.setItem(VEH_KEY, JSON.stringify(vehicle)); } catch (e) {} }
  function onFitmentChange(el) {
    if (el.matches("[data-mb-fitment-make]")) vehicle = { make: el.value };
    else if (el.matches("[data-mb-fitment-model]")) vehicle = { make: vehicle.make, model: el.value };
    else if (el.matches("[data-mb-fitment-year]")) vehicle.year = el.value;
    else return;
    oemIds = null;
    saveVehicle();
    applyFitment();
  }
  function oemSearch(form) {
    var inp = form.querySelector("[data-mb-fitment-oem]");
    var q = inp ? (inp.value || "").trim() : "";
    if (!q) { oemIds = null; applyFitment(); return; }
    if (!CFG.endpoints.fitmentOem) return;
    fetch(CFG.endpoints.fitmentOem + "?q=" + encodeURIComponent(q), { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json(); })
      .then(function (d) { oemIds = ((d && d.menu_ids) || []).map(Number); applyFitment(); })
      .catch(function () {});
  }
  function resetFitment() {
    vehicle = {}; oemIds = null; saveVehicle();
    var form = fitForm(), inp = form && form.querySelector("[data-mb-fitment-oem]");
    if (inp) inp.value = "";
    applyFitment();
  }
  function initFitment() {
    var form = fitForm();
    if (!form || !CFG.endpoints.fitmentOptions) { if (form) form.hidden = true; return; }
    fetch(CFG.endpoints.fitmentOptions, { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || d.enabled === false) { form.hidden = true; return; }
        FITMENTS = d.fitments || [];
        form.hidden = false;
        applyFitment();
      })
      .catch(function () { /* degrade: the form stays inert */ });
  }

  /* ----- sort + layout (P8-4) ------------------------------------------ *
   * Server-rendered cards, reordered in place — no re-render, SEO intact.
   *   <select data-mb-sort> featured | price_asc | price_desc | rating
   *   <button data-mb-layout-toggle>   toggles .is-list on [data-mb-catalog]
   * Cards carry data-mb-price / data-mb-rating; "featured" restores the
   * original server order (captured once).
   */
  var sortMode = "featured";
  function numAttr(el, name) { return parseFloat(el.getAttribute(name)) || 0; }
  function applySort() {
    all("[data-mb-cat]").forEach(function (sec) {
      var cards = all("[data-mb-item]", sec);
      if (cards.length < 2) return;
      cards.forEach(function (c, i) { if (c.getAttribute("data-mb-order") == null) c.setAttribute("data-mb-order", i); });
      var sorted = cards.slice();
      if (sortMode === "price_asc") sorted.sort(function (a, b) { return numAttr(a, "data-mb-price") - numAttr(b, "data-mb-price"); });
      else if (sortMode === "price_desc") sorted.sort(function (a, b) { return numAttr(b, "data-mb-price") - numAttr(a, "data-mb-price"); });
      else if (sortMode === "rating") sorted.sort(function (a, b) { return numAttr(b, "data-mb-rating") - numAttr(a, "data-mb-rating"); });
      else sorted.sort(function (a, b) { return numAttr(a, "data-mb-order") - numAttr(b, "data-mb-order"); });
      sorted.forEach(function (c) { c.parentNode.appendChild(c); });
    });
  }
  function toggleLayout() {
    all("[data-mb-catalog]").forEach(function (c) { c.classList.toggle("is-list"); });
    all("[data-mb-layout-toggle]").forEach(function (b) {
      var on = all("[data-mb-catalog]").some(function (c) { return c.classList.contains("is-list"); });
      b.classList.toggle("is-on", on);
      b.setAttribute("aria-pressed", on ? "true" : "false");
    });
  }

  function setLang(code) {
    if (!/^[a-z]{2}(-[a-z0-9]{2,8})?$/i.test(code)) return;
    document.cookie = "Quick_user_lang_code=" + code + ";path=/;max-age=31536000";
    window.location.reload();
  }

  /* ----- multi-currency (P8-8) ----------------------------------------- *
   * Client-side display conversion via the config rate map; the cart still
   * POSTs base-currency amounts (server owns the money). A theme opts in with
   *   <button data-mb-currency="EUR">   and marks static prices with
   *   <b data-mb-money="12.50">…</b>     (the runtime reformats them).
   */
  function setCurrency(code) {
    var rates = CFG.currency.rates || {};
    if (code === CFG.currency.code) curRate = 1;
    else if (rates[code]) curRate = rates[code];
    else return;
    curCode = code;
    refreshMoney();
    all("[data-mb-currency]").forEach(function (b) { b.classList.toggle("is-on", b.getAttribute("data-mb-currency") === curCode); });
  }
  function refreshMoney() {
    all("[data-mb-money]").forEach(function (el) { el.textContent = money(parseFloat(el.getAttribute("data-mb-money")) || 0); });
    syncBindings(); // cart total/count + renderCart
    if (pId != null) productRecalc();
  }

  /* Language + currency bottom sheet (opened by the header globe icon). */
  function langSheet(open) {
    var s = doc.querySelector("[data-mb-lang-sheet]");
    if (!s) return;
    if (open) { s.hidden = false; requestAnimationFrame(function () { s.classList.add("is-on"); }); }
    else { s.classList.remove("is-on"); setTimeout(function () { s.hidden = true; }, 300); }
  }

  /* ----- customer account panel (B6) ----------------------------------- *
   * Login / register / SMS-OTP / logout + order history — all through the
   * platform runtime so themes ship ZERO JS. A theme opts in with:
   *   <button data-mb-account>Account</button>   (or data-mb-login)
   *   <div data-mb-account-mount hidden></div>
   * On a successful auth transition the page reloads so the server re-emits
   * a fresh CSRF token + isLoggedIn / customerName (login/register/logout all
   * regenerate the session). Order history rides the guest orderLookup route,
   * so it works for logged-in diners and guests alike.
   */
  var acctTab = "login";
  var acctNextBefore = null;

  function acctErr(msg) {
    var el = doc.querySelector("[data-mb-acct-err]");
    if (el) { el.textContent = msg || ""; el.hidden = !msg; }
  }

  function acctField(form, name) {
    var f = form.querySelector('[name="' + name + '"]');
    return f ? f.value : "";
  }

  function acctPost(url, fields) {
    var fd = new FormData();
    fd.append("_token", CFG.csrf);
    fd.append("store_id", CFG.storeId);
    Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" }, body: fd })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
  }

  /* ----- the account sheet ------------------------------------------------ *
   * The first-party storefronts give the customer an identity block, grouped
   * cards and tappable rows; this sheet now does the same, and adds the three
   * platform features the runtime never called: the wallet top-up, the
   * notification inbox and the dark mode.
   * ------------------------------------------------------------------- */

  /* Inline icons — the Liquid side has an icon snippet, the runtime does not. */
  var ACCT_ICONS = {
    gift: '<path d="M20 12v10H4V12M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>',
    wallet: '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M16 14h2"/>',
    bell: '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
    phone: '<path d="M5 4h4l2 5-2.5 1.5a12 12 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/>',
    track: '<rect x="2" y="7" width="14" height="10" rx="2"/><path d="M16 10h4l2 3v4h-6z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
    heart: '<path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/>',
    share: '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>',
    moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/>',
    trash: '<path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/>',
    home: '<path d="M4 11l8-7 8 7M6 10v9a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-9"/>',
    out: '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 12h9M16 8l3 4-3 4"/>'
  };
  function acctIcon(name, size) {
    var d = ACCT_ICONS[name] || "";
    if (!d) return "";
    return '<svg class="mb-acct-ico" viewBox="0 0 24 24" width="' + (size || 20) + '" height="' + (size || 20) +
      '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + "</svg>";
  }
  function acctChev() {
    return '<svg class="mb-acct-chev" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" ' +
      'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';
  }
  function acctRow(icon, label, attr, value) {
    return '<button type="button" class="mb-acct-rowbtn" ' + attr + ">" +
      '<span class="mb-acct-rowbtn__ico">' + acctIcon(icon) + "</span>" +
      '<span class="mb-acct-rowbtn__txt">' + esc(label) + "</span>" +
      (value ? '<span class="mb-acct-rowbtn__val">' + esc(value) + "</span>" : "") +
      acctChev() + "</button>";
  }
  function acctCard(label, inner) {
    return '<div class="mb-acct-card"><div class="mb-acct-card__label">' + esc(label) + "</div>" + inner + "</div>";
  }

  /* The rows every storefront's account has: the platform's own customer
     features, reached the way the first-party templates reach them. */
  function accountRows(opts) {
    opts = opts || {};
    var h = "";

    /* The notification inbox — the platform's own feed, with its unread count. */
    if (opts.notifications) {
      h += acctRow("bell", t("Notifications"), 'data-mb-acct-notif data-mb-notif-toggle',
        "");
    }

    /* Help & support — the store's phone, tap to call. */
    if (CFG.store && CFG.store.phone) {
      h += '<a class="mb-acct-rowbtn" href="tel:' + esc(String(CFG.store.phone).replace(/[^\d+]/g, "")) + '">' +
        '<span class="mb-acct-rowbtn__ico">' + acctIcon("phone") + "</span>" +
        '<span class="mb-acct-rowbtn__txt">' + t("Help & support") + "</span>" +
        '<span class="mb-acct-rowbtn__val">' + esc(CFG.store.phone) + "</span>" + acctChev() + "</a>";
    }

    /* The wishlist — the favourites view the runtime already ships. */
    h += acctRow("heart", t("Wishlist"), "data-mb-acct-favs");

    /* Share this store — the runtime's own share (native sheet, else copy). */
    h += '<button type="button" class="mb-acct-rowbtn" data-mb-share data-mb-share-title="' +
      esc(CFG.store ? CFG.store.name : "") + '">' +
      '<span class="mb-acct-rowbtn__ico">' + acctIcon("share") + "</span>" +
      '<span class="mb-acct-rowbtn__txt">' + t("Share this store") + "</span>" + acctChev() + "</button>";

    /* Dark mode — the theme paints the dark palette; the runtime only owns
       the choice and keeps it on this device. */
    h += acctRow("moon", t("Dark mode"), 'data-mb-acct-dark aria-pressed="' + (isDark() ? "true" : "false") + '"');

    /* Privacy — wipe what this browser kept (the platform's own engine). */
    h += acctRow("trash", t("Clear saved data on this device"), "data-mb-acct-clear mb-acct-danger");

    /* Continue shopping — back to the page. */
    h += acctRow("home", t("Continue shopping"), "data-mb-back");

    return h;
  }

  /* The rewards card: the platform's own loyalty widget, opened from here.
     The card disappears when the theme renders no widget. */
  function afterAccountRender(mount) {
    var card = mount.querySelector("[data-mb-rewards]");
    if (card) {
      if (doc.querySelector('[data-mbw-open="loyalty"]')) {
        card.hidden = false;
        loadRewards(card);
      } else {
        card.remove();
      }
    }
    if (CFG.flags.isLoggedIn) {
      loadWallet(mount);
      loadNotifications(mount);
    }
  }

  /* The live loyalty balance and tier, from the platform's own endpoint. */
  function loadRewards(card) {
    var url = CFG.endpoints.loyaltyBalance;
    if (!url) return;
    fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) { card.remove(); return; }
        var bal = card.querySelector("[data-mb-rewards-bal]");
        if (bal) bal.textContent = String(d.balance != null ? d.balance : "0");
        var tier = card.querySelector("[data-mb-rewards-tier]");
        if (tier && d.tier) { tier.textContent = d.tier; tier.hidden = false; }
      })
      .catch(function () {});
  }

  /* The customer's store credit, and the route that tops it up. */
  function loadWallet(mount) {
    var url = CFG.endpoints.walletBalance;
    if (!url) return;
    fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        var card = mount.querySelector("[data-mb-wallet]");
        if (!card) return;
        var bal = card.querySelector("[data-mb-wallet-bal]");
        if (bal) bal.textContent = String(d.formatted != null ? d.formatted : d.balance);
        card.hidden = false;
      })
      .catch(function () {});
  }

  /* The notification inbox — the platform's own feed. */
  function loadNotifications(mount) {
    var url = CFG.endpoints.notifications;
    if (!url) return;
    fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        var badge = mount.querySelector("[data-mb-notif-count]");
        if (badge) {
          if (d.unread > 0) { badge.textContent = String(d.unread); badge.hidden = false; }
          else badge.hidden = true;
        }
        var list = mount.querySelector("[data-mb-notif-list]");
        if (!list) return;
        list.innerHTML = "";
        if (!d.notifications || !d.notifications.length) {
          list.innerHTML = '<p class="mb-acct-empty">' + t("No notifications yet.") + "</p>";
          return;
        }
        d.notifications.forEach(function (n) {
          var item = doc.createElement("a");
          item.className = "mb-acct-notif" + (n.read ? "" : " is-new");
          item.href = n.url || "#";
          item.innerHTML = "<b>" + esc(n.title || "") + "</b>" +
            (n.body ? "<span>" + esc(n.body) + "</span>" : "");
          list.appendChild(item);
        });
      })
      .catch(function () {});
  }

  /* ----- the header bell ----------------------------------------------- *
   * The platform's notification bell. The header renders the button (the
   * library header, so EVERY Liquid theme gets it) and the server paints the
   * unread count; the runtime owns the panel behind it — the same feed the
   * account sheet lists, from the same endpoint, scoped to the same rows.
   * A guest gets no bell (the endpoint would answer 401).
   * ------------------------------------------------------------------- */
  var notifEl = null;
  var notifFocus = null;

  function notifBadge(n) {
    all("[data-mb-notif-count]").forEach(function (b) {
      if (n > 0) { b.textContent = String(n); b.hidden = false; }
      else b.hidden = true;
    });
  }

  function notifPanel() {
    if (notifEl && notifEl.parentNode) return notifEl;
    var p = doc.createElement("div");
    /* The panel reuses the runtime's own dialog + sheet chrome, so the bell
       looks like the rest of the platform's overlays. */
    p.className = "mb-dialog mb-bell";
    p.setAttribute("data-mb-bell-panel", "");
    p.setAttribute("role", "dialog");
    p.setAttribute("aria-modal", "true");
    p.setAttribute("aria-label", t("Notifications"));
    p.hidden = true;
    p.innerHTML =
      '<div class="mb-dialog-card mb-bell__card">' +
        '<div class="mb-sheet-head">' +
          '<b class="mb-bell__title">' + esc(t("Notifications")) + "</b>" +
          '<button type="button" class="mb-ghost mb-bell__act" data-mb-bell-read-all>' + esc(t("Mark all read")) + "</button>" +
          '<button type="button" class="mb-sheet-close" data-mb-bell-close aria-label="' + esc(t("Close")) + '">&#10005;</button>' +
        "</div>" +
        '<div class="mb-acct-notifs mb-bell__list" data-mb-bell-list></div>' +
      "</div>";
    doc.body.appendChild(p);
    notifEl = p;
    return p;
  }

  function notifOpen(trigger) {
    var p = notifPanel();
    notifFocus = trigger || doc.activeElement;
    p.hidden = false;
    all("[data-mb-notifications]").forEach(function (b) { b.setAttribute("aria-expanded", "true"); });
    var x = p.querySelector("[data-mb-bell-close]");
    if (x) x.focus();
    notifLoad();
  }

  function notifClose() {
    if (notifEl) notifEl.hidden = true;
    all("[data-mb-notifications]").forEach(function (b) { b.setAttribute("aria-expanded", "false"); });
    if (notifFocus && notifFocus.focus) { try { notifFocus.focus(); } catch (e) {} }
    notifFocus = null;
  }

  /* The feed — the platform's own rows, scoped to the signed-in diner. */
  function notifLoad() {
    var url = CFG.endpoints.notifications;
    var p = notifEl;
    if (!url || !p) return;
    var list = p.querySelector("[data-mb-bell-list]");
    fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        notifBadge(d.unread || 0);
        if (!list) return;
        list.innerHTML = "";
        if (!d.notifications || !d.notifications.length) {
          list.innerHTML = '<p class="mb-acct-empty">' + esc(t("No notifications yet.")) + "</p>";
          return;
        }
        d.notifications.forEach(function (n) {
          var item = doc.createElement("a");
          item.className = "mb-acct-notif" + (n.read ? "" : " is-new");
          item.href = n.url || "#";
          item.innerHTML = "<b>" + esc(n.title || "") + "</b>" +
            (n.body ? "<span>" + esc(n.body) + "</span>" : "");
          list.appendChild(item);
        });
      })
      .catch(function () {});
  }

  /* Mark the whole inbox read — the platform's own endpoint, the same rows. */
  function notifReadAll() {
    var url = CFG.endpoints.notificationsRead;
    if (!url) return;
    acctPost(url, {}).then(function (r) {
      if (!r.ok || !r.body || !r.body.ok) return;
      notifBadge(0);
      all(".mb-acct-notif.is-new").forEach(function (el) { el.classList.remove("is-new"); });
      var btn = notifEl ? notifEl.querySelector("[data-mb-bell-read-all]") : null;
      if (btn) btn.hidden = true;
    }).catch(function () {});
  }

  /* The badge poll — the COUNT only, so it stays cheap on a busy storefront. */
  function notifTick() {
    var url = CFG.endpoints.notificationsUnread;
    if (!url || !doc.querySelector("[data-mb-notifications]")) return;
    if (doc.visibilityState && doc.visibilityState !== "visible") return;
    fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d) notifBadge(d.unread || 0); })
      .catch(function () {});
  }

  /* The bell keeps its badge live while the page is open (signed-in diners
     only — a guest has no bell and no feed). */
  function notifInit() {
    if (!CFG.flags.isLoggedIn) return;
    if (!doc.querySelector("[data-mb-notifications]")) return;
    setInterval(notifTick, 60000);
  }
  if (doc.readyState !== "loading") notifInit();
  else doc.addEventListener("DOMContentLoaded", notifInit);

  /* Escape closes the panel; Tab stays inside it while it is open. */
  doc.addEventListener("keydown", function (ev) {
    if (!notifEl || notifEl.hidden) return;
    if (ev.key === "Escape") { ev.preventDefault(); notifClose(); return; }
    if (ev.key !== "Tab") return;
    var f = all("a[href], button:not([disabled]), input, select, textarea", notifEl);
    if (!f.length) return;
    var first = f[0], last = f[f.length - 1];
    if (ev.shiftKey && doc.activeElement === first) { ev.preventDefault(); last.focus(); }
    else if (!ev.shiftKey && doc.activeElement === last) { ev.preventDefault(); first.focus(); }
  });

  function showLoyaltyWidget() {
    var btn = doc.querySelector('[data-mbw-open="loyalty"]');
    if (btn) { btn.click(); return true; }
    return false;
  }

  /* A tracking LINK carries the token; a bare code is the token. */
  function trackOrderFromInput() {
    var inp = doc.querySelector("#mbTrackInput");
    var v = inp ? String(inp.value || "") : "";
    v = v.trim();
    if (!v) return;
    var m = v.match(/track\/([A-Za-z0-9_-]+)/);
    var token = m ? m[1] : v.replace(/[^A-Za-z0-9_-]/g, "");
    if (!token) return;
    var base = String(CFG.endpoints.orderTrack || "").replace(/TOKEN$/, "");
    if (base) window.location.href = base + encodeURIComponent(token);
  }

  function clearDeviceData() {
    if (!window.mbClearDeviceData) return;
    window.mbClearDeviceData({
      keys: [CART_KEY, FAV_KEY],
      slug: CFG.restaurantSlug,
      storeId: CFG.storeId,
      confirm: t("Are you sure?"),
      reload: true
    });
  }

  /* Dark mode: the theme paints the dark palette behind [data-theme="dark"];
     the runtime owns the choice and keeps it on this device. */
  var THEME_KEY = "mb_theme";
  function isDark() { try { return localStorage.getItem(THEME_KEY) === "dark"; } catch (e) { return false; } }
  function applyTheme(dark) {
    if (dark) doc.documentElement.setAttribute("data-theme", "dark");
    else doc.documentElement.removeAttribute("data-theme");
    try { localStorage.setItem(THEME_KEY, dark ? "dark" : "light"); } catch (e) {}
    all("[data-mb-acct-dark]").forEach(function (el) { el.setAttribute("aria-pressed", dark ? "true" : "false"); });
  }
  function toggleTheme() { applyTheme(!isDark()); }

  function drawAccount(mount, orders) {
    var ep = CFG.endpoints;
    if (!ep || !ep.login) { mount.innerHTML = ""; return; }
    var loggedIn = CFG.flags.isLoggedIn;
    var h = '<div class="mb-cart mb-account" role="dialog" aria-modal="true">';
    h += '<header class="mb-sheet-head"><b>' +
      esc(loggedIn ? (CFG.flags.customerName || "Account") : "Account") +
      '</b><button class="mb-sheet-close" data-mb-acct-close aria-label="Close">&times;</button></header>';
    h += '<p class="mb-err" data-mb-acct-err hidden></p>';

    if (loggedIn) {
      h += '<div class="mb-acct-hero"><span class="mb-acct-hero__avatar" aria-hidden="true">' +
      esc(String(CFG.flags.customerName || "?").trim().charAt(0).toUpperCase()) + "</span>" +
      '<span class="mb-acct-hero__txt"><b>' + esc(CFG.flags.customerName || "") + "</b></span></div>";
      h += '<button type="button" class="mb-place" data-mb-logout>Log out</button>';
    } else {
      h += '<div class="mb-acct-tabs" role="tablist">';
      [["login", "Log in"], ["register", "Register"], ["otp", "SMS code"]].forEach(function (t) {
        h += '<button type="button" data-mb-acct-tab="' + t[0] + '"' +
          (t[0] === acctTab ? ' class="is-on" aria-selected="true"' : "") + ">" + t[1] + "</button>";
      });
      h += "</div>";

      if (acctTab === "login") {
        h += '<form data-mb-acct-form="login">' +
          '<input class="mb-field" name="email" type="email" placeholder="Email" autocomplete="email">' +
          '<input class="mb-field" name="password" type="password" placeholder="Password" autocomplete="current-password">' +
          '<button type="submit" class="mb-place">Log in</button></form>';
      } else if (acctTab === "register") {
        h += '<form data-mb-acct-form="register">' +
          '<input class="mb-field" name="name" placeholder="Name">' +
          '<input class="mb-field" name="email" type="email" placeholder="Email" autocomplete="email">' +
          '<input class="mb-field" name="phone" placeholder="Phone (optional)" autocomplete="tel">' +
          '<input class="mb-field" name="password" type="password" placeholder="Password" autocomplete="new-password">' +
          '<input class="mb-field" name="password_confirmation" type="password" placeholder="Confirm password" autocomplete="new-password">' +
          '<button type="submit" class="mb-place">Create account</button></form>';
      } else {
        h += '<form data-mb-acct-form="otp">' +
          '<input class="mb-field" name="phone" placeholder="Phone" autocomplete="tel">' +
          '<div class="mb-acct-row"><input class="mb-field" name="code" placeholder="Code" inputmode="numeric">' +
          '<button type="button" class="mb-place" data-mb-otp-request>Send code</button></div>' +
          '<button type="submit" class="mb-place">Verify &amp; log in</button></form>';
      }
    }

    h += '<div class="mb-acct-rows">' + accountRows() + "</div>";

    // Order history — guest lookup route, works for everyone.
    h += '<div class="mb-acct-orders"><p class="mb-opt-title">Your orders</p>';
    h += '<form data-mb-acct-lookup><div class="mb-acct-row">' +
      '<input class="mb-field" name="identifier" placeholder="Phone or email">' +
      '<button type="submit" class="mb-place">Find</button></div></form>';
    if (orders && orders.length) {
      h += '<ul class="mb-acct-order-list">';
      orders.forEach(function (o) {
        h += '<li class="mb-acct-order"><a href="' + esc(o.track_url) + '">#' + esc(o.id) +
          " · " + esc(o.total) + " · " + esc(o.status) + " · " + esc(o.date) + "</a></li>";
      });
      h += "</ul>";
    }
    h += "</div></div>";
    mount.innerHTML = h; translate(mount); afterAccountRender(mount);
  }

  function openAccount() {
    var mount = doc.querySelector("[data-mb-account-mount]");
    if (!mount) return;
    drawAccount(mount);
    mount.hidden = false;
  }

  function submitAcctForm(form, kind) {
    var url, fields;
    if (kind === "login") {
      url = CFG.endpoints.login;
      fields = { email: acctField(form, "email"), password: acctField(form, "password") };
    } else if (kind === "register") {
      url = CFG.endpoints.register;
      fields = {
        name: acctField(form, "name"), email: acctField(form, "email"),
        phone: acctField(form, "phone"), password: acctField(form, "password"),
        password_confirmation: acctField(form, "password_confirmation"),
      };
    } else {
      url = CFG.endpoints.otpVerify;
      fields = { phone: acctField(form, "phone"), code: acctField(form, "code") };
    }
    acctErr("");
    acctPost(url, fields).then(function (res) {
      if (res.body && res.body.success) { window.location.reload(); return; }
      acctErr((res.body && res.body.message) || t("Something went wrong."));
    }).catch(function () { acctErr(t("Network error. Please try again.")); });
  }

  function requestOtp(form) {
    var phone = acctField(form, "phone");
    if (!phone) { acctErr(t("Enter your phone number.")); return; }
    acctErr("");
    acctPost(CFG.endpoints.otpRequest, { phone: phone }).then(function (res) {
      acctErr((res.body && res.body.message) || "");
    }).catch(function () { acctErr(t("Network error. Please try again.")); });
  }

  function logout() {
    acctPost(CFG.endpoints.logout, {}).then(function () { window.location.reload(); })
      .catch(function () { window.location.reload(); });
  }

  function loadOrders(identifier, quiet) {
    if (!identifier) return;
    acctPost(CFG.endpoints.orderLookup, { identifier: identifier }).then(function (res) {
      var mount = doc.querySelector("[data-mb-account-mount]");
      if (!mount) return;
      var ok = res.body && res.body.success;
      drawAccount(mount, ok ? (res.body.orders || []) : []);
      if (!ok && !quiet) acctErr((res.body && res.body.message) || t("No orders found."));
    }).catch(function () {});
  }
  function lookupOrders(form) { loadOrders(acctField(form, "identifier"), false); }

  /* ----- rich account (logged-in: profile + addresses + orders) -------- */
  function acctSubmit(url, fields, method) {
    var fd = new FormData();
    fd.append("_token", CFG.csrf);
    fd.append("store_id", CFG.storeId);
    if (method) fd.append("_method", method);
    Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" }, body: fd })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
  }
  function fetchAccount() {
    var mount = doc.querySelector("[data-mb-account-mount]");
    if (!mount) return;
    fetch(CFG.endpoints.account, { headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } })
      .then(function (r) { return r.json(); })
      .then(function (d) { renderAccountRich(mount, d); })
      .catch(function () { drawAccount(mount); });
  }
  function orderAnchor(o) {
    var status = o.payment_not_completed ? t("Payment not completed") : esc(o.status);
    return '<a href="' + esc(o.track_url || "#") + '">#' + esc(o.id) + " · " +
      esc(o.total) + " · " + status + " · " + esc(o.date) + "</a>";
  }
  function loadOlderOrders() {
    var mount = doc.querySelector("[data-mb-account-mount]");
    if (!mount || !acctNextBefore) return;
    var list = mount.querySelector(".mb-acct-order-list");
    var btn = mount.querySelector("[data-mb-acct-older]");
    if (btn) btn.disabled = true;
    var sep = CFG.endpoints.account.indexOf("?") >= 0 ? "&" : "?";
    fetch(CFG.endpoints.account + sep + "before=" + acctNextBefore, {
      headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" }
    }).then(function (r) { return r.json(); }).then(function (d) {
      acctNextBefore = (d && d.next_before) || null;
      if (list && d && d.orders) {
        d.orders.forEach(function (o) {
          var li = doc.createElement("li");
          li.className = "mb-acct-order";
          li.innerHTML = orderAnchor(o);
          list.appendChild(li);
        });
      }
      var btn2 = mount.querySelector("[data-mb-acct-older]");
      if (!acctNextBefore) { if (btn2 && btn2.parentNode) btn2.parentNode.removeChild(btn2); }
      else if (btn2) btn2.disabled = false;
    }).catch(function () { if (btn) btn.disabled = false; });
  }
  function renderAccountRich(mount, d) {
    var c = d.customer || {};
    var h = '<div class="mb-account mb-account-rich">';

    /* the identity block */
    h += '<div class="mb-acct-hero"><span class="mb-acct-hero__avatar" aria-hidden="true">' +
      esc(String(c.name || "?").trim().charAt(0).toUpperCase()) + "</span>" +
      '<span class="mb-acct-hero__txt"><b>' + esc(c.name || "") + "</b>" +
      (c.email ? "<span>" + esc(c.email) + "</span>" : "") + "</span></div>";
    h += '<p class="mb-err" data-mb-acct-err hidden></p>';

    /* rewards — the platform's own loyalty widget, opened from this card.
       One line: the label, the balance, the tier, the action. A balance on
       its own line, under a heading, with nothing beside it, reads as an
       empty box. */
    h += '<div class="mb-acct-card mb-acct-rewards" data-mb-rewards hidden>' +
      '<span class="mb-acct-card__label">' + t("Rewards") + "</span>" +
      '<b class="mb-acct-balance" data-mb-rewards-bal>–</b>' +
      '<span class="mb-acct-tier" data-mb-rewards-tier hidden></span>' +
      '<button type="button" class="mb-acct-btn" data-mb-acct-rewards>' + t("View rewards") + "</button></div>";

    /* wallet — the customer's store credit, and the route that tops it up */
    h += '<div class="mb-acct-card mb-acct-wallet" data-mb-wallet hidden>' +
      '<span class="mb-acct-card__label">' + t("Wallet") + "</span>" +
      '<b class="mb-acct-balance" data-mb-wallet-bal>–</b>' +
      '<a class="mb-acct-btn" href="' + esc(CFG.endpoints.walletTopup || "#") + '">' + t("Add funds") + "</a></div>";

    /* profile — the summary is what the customer came to see; the form is a
       form, so it opens when they ask for it instead of taking a screenful
       of the account before the orders. */
    h += acctCard(t("Profile"),
      '<div class="mb-acct-summary"><b>' + esc(c.name || "") + "</b>" +
      (c.email ? "<span>" + esc(c.email) + "</span>" : "") +
      (c.phone ? "<span>" + esc(c.phone) + "</span>" : "") + "</div>" +
      '<button type="button" class="mb-acct-btn" data-mb-acct-edit="profile">' + t("Edit profile") + "</button>" +
      '<form data-mb-profile-form class="mb-acct-form" hidden>' +
      '<input class="mb-field" name="name" placeholder="Name" value="' + esc(c.name || "") + '">' +
      '<input class="mb-field" name="email" type="email" placeholder="Email" value="' + esc(c.email || "") + '">' +
      '<input class="mb-field" name="phone" placeholder="Phone" value="' + esc(c.phone || "") + '">' +
      '<input class="mb-field" name="birthday" type="date" value="' + esc(c.birthday || "") + '">' +
      '<button type="submit" class="mb-place">Save profile</button></form>');

    /* addresses */
    var addr = '<ul class="mb-addr-list">';
    (d.addresses || []).forEach(function (a) {
      addr += '<li class="mb-addr"><span>' + (a.label ? "<b>" + esc(a.label) + "</b> " : "") + esc(a.line) +
        (a.city ? ", " + esc(a.city) : "") + (a.is_default ? ' <em class="mb-addr-default">Default</em>' : "") + "</span>" +
        '<button type="button" class="mb-addr-del" data-mb-address-del="' + esc(a.delete_url) + '" aria-label="Remove">&times;</button></li>';
    });
    addr += "</ul>";
    addr += '<button type="button" class="mb-acct-btn" data-mb-acct-edit="address">' + t("Add address") + "</button>";
    addr += '<form data-mb-address-form class="mb-addr-form" hidden>' +
      '<input class="mb-field" name="label" placeholder="Label (Home, Work)">' +
      '<input class="mb-field" name="line" placeholder="Address" required>' +
      '<input class="mb-field" name="city" placeholder="City">' +
      '<label class="mb-addr-def"><input type="checkbox" name="is_default"> Set as default</label>' +
      '<button type="submit" class="mb-ghost">Add address</button></form>';
    h += acctCard(t("Saved addresses"), addr);

    /* orders */
    var orders = "";
    acctNextBefore = d.next_before || null;
    if ((d.orders || []).length) {
      orders = '<ul class="mb-acct-order-list">';
      d.orders.forEach(function (o) {
        orders += '<li class="mb-acct-order">' + orderAnchor(o) + "</li>";
      });
      orders += "</ul>";
      if (acctNextBefore) orders += '<button type="button" class="mb-ghost" data-mb-acct-older>' + t("Load more") + "</button>";
    } else {
      orders = '<p class="mb-acct-empty">' + t("No orders yet.") + "</p>";
    }
    h += acctCard(t("Your orders"), orders);

    /* the track-an-order box */
    h += acctCard(t("Track an order"),
      '<div class="mb-acct-row"><input class="mb-field" id="mbTrackInput" placeholder="' +
      t("Order tracking link or code") + '">' +
      '<button type="button" class="mb-place mb-place--sm" data-mb-acct-track>' + t("Track order") + "</button></div>");

    /* the rows */
    h += '<div class="mb-acct-rows">' + accountRows({ notifications: true }) + "</div>";

    /* the notification inbox */
    h += '<div class="mb-acct-card mb-acct-notifs" data-mb-notif-panel hidden>' +
      '<div class="mb-acct-card__label">' + t("Notifications") + "</div>" +
      '<div data-mb-notif-list></div></div>';

    /* sign out */
    h += '<button type="button" class="mb-acct-signout" data-mb-logout>' + acctIcon("out", 18) +
      "<span>" + t("Log out") + "</span></button>";

    h += "</div>";
    mount.innerHTML = h; translate(mount); afterAccountRender(mount);
  }
  function saveProfile(form) {
    acctErr("");
    acctSubmit(CFG.endpoints.profile, {
      name: acctField(form, "name"), email: acctField(form, "email"),
      phone: acctField(form, "phone"), birthday: acctField(form, "birthday"),
    }).then(function (res) {
      if (res.body && res.body.success) fetchAccount();
      else acctErr((res.body && res.body.message) || t("Could not save."));
    }).catch(function () { acctErr(t("Network error. Please try again.")); });
  }
  function addAddress(form) {
    acctErr("");
    var fields = { label: acctField(form, "label"), line: acctField(form, "line"), city: acctField(form, "city") };
    if (form.querySelector('[name="is_default"]:checked')) fields.is_default = "1";
    acctSubmit(CFG.endpoints.addressStore, fields).then(function (res) {
      if (res.body && res.body.success) fetchAccount();
      else acctErr((res.body && res.body.message) || t("Could not save address."));
    }).catch(function () { acctErr(t("Network error. Please try again.")); });
  }
  function deleteAddress(url) {
    acctSubmit(url, {}, "DELETE").then(function (res) { if (res.body && res.body.success) fetchAccount(); }).catch(function () {});
  }

  /* ----- auto-advancing slider (B7) ------------------------------------ *
   * Any horizontal scroller opts in with:
   *   <section data-mb-slider data-mb-slider-interval="4000"> …cards… </section>
   * The runtime cycles its direct children, pausing while the diner points at
   * / focuses inside it, and stays inert when prefers-reduced-motion is set.
   * Physical getBoundingClientRect deltas → RTL-safe, zero theme JS.
   */
  function initSliders() {
    var reduce = false;
    try { reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches; } catch (e) {}
    if (reduce) return;

    all("[data-mb-slider]").forEach(function (box) {
      var kids = Array.prototype.filter.call(box.children, function (n) { return n.nodeType === 1; });
      if (kids.length < 2) return;

      var ms = parseInt(box.getAttribute("data-mb-slider-interval"), 10);
      if (!ms || ms < 2000) ms = 4000;

      var i = 0, paused = false;
      box.addEventListener("pointerenter", function () { paused = true; });
      box.addEventListener("pointerleave", function () { paused = false; });
      box.addEventListener("focusin", function () { paused = true; });
      box.addEventListener("focusout", function () { paused = false; });

      setInterval(function () {
        if (paused || !box.offsetParent) return; // skip while hidden or hovered
        i = (i + 1) % kids.length;
        var delta = kids[i].getBoundingClientRect().left - box.getBoundingClientRect().left;
        box.scrollBy({ left: delta, behavior: "smooth" });
      }, ms);
    });
  }

  /* ----- view router (P8-3) -------------------------------------------- *
   * SPA navigation for themes that lay out multiple screens as
   *   <section data-mb-view="home" class="is-active"> … </section>
   * A view is a "root" (a tab destination — clears the back stack, keeps the
   * tabbar visible) if a [data-mb-tab] points at it; anything else is a
   * sub-view (pushes the back stack, hides the tabbar). Opt-in: inert unless
   * the theme provides [data-mb-view] elements, so scroll-page themes are
   * unaffected. Slide/RTL transitions are the theme's CSS; the runtime only
   * toggles is-active / is-under and drives the tabbar + back stack.
   *   <button data-mb-go="cart">   <button data-mb-back>
   *   <nav data-mb-tabbar><button data-mb-tab="home">…</nav>
   */
  var mbViews = all("[data-mb-view]");
  var hasRouter = mbViews.length > 0;
  var viewStack = [];
  var activeView = null;

  function viewRoots() {
    return all("[data-mb-tab]").map(function (b) { return b.getAttribute("data-mb-tab"); });
  }
  function viewEl(name) {
    return mbViews.filter(function (v) { return v.getAttribute("data-mb-view") === name; })[0] || null;
  }
  function showView(name, push) {
    if (!hasRouter || !name || name === activeView) return;
    var to = viewEl(name);
    if (!to) return;
    var from = activeView ? viewEl(activeView) : null;
    var isRoot = viewRoots().indexOf(name) !== -1;
    if (push !== false && !isRoot && activeView) viewStack.push(activeView);
    if (isRoot) viewStack.length = 0;
    mbViews.forEach(function (v) { v.classList.remove("is-under"); });
    if (!isRoot && from) from.classList.add("is-under");
    if (from) from.classList.remove("is-active");
    to.classList.add("is-active");
    var sc = to.querySelector("[data-mb-view-scroll]");
    if (sc) sc.scrollTop = 0;
    activeView = name;
    all("[data-mb-tabbar]").forEach(function (tb) { tb.classList.toggle("is-hidden", !isRoot); });
    all("[data-mb-tab]").forEach(function (b) { b.classList.toggle("is-on", b.getAttribute("data-mb-tab") === name); });
    // Lazy hooks into the existing runtime surfaces when their view opens.
    if (name === "cart") renderCart();
    if (name === "favs") renderFavs();
    if (name === "account" || name === "profile") {
      var am = doc.querySelector("[data-mb-account-mount]");
      if (am) {
        am.hidden = false;
        // Logged-in: the rich account (profile + addresses + orders). Guest:
        // the auth panel.
        if (CFG.flags.isLoggedIn && CFG.endpoints.account) fetchAccount();
        else drawAccount(am);
      }
    }
  }
  function backView() { showView(viewStack.pop() || viewRoots()[0] || "home", false); }

  /* ----- delegated events ---------------------------------------------- */
  doc.addEventListener("click", function (ev) {
    var t = ev.target;
    var add = t.closest("[data-mb-add]"); if (add) { ev.preventDefault(); openSheet(add.getAttribute("data-mb-add")); return; }
    // Product view (P8-5): gallery / qty / add (data-mb-open is handled after
    // the fav/add button checks below so a card's inner buttons win).
    var gdot = t.closest("[data-mb-gallery-dot]"); if (gdot) { productGallery(gdot.getAttribute("data-mb-gallery-dot")); return; }
    var pq = t.closest("[data-mb-qty]"); if (pq) { productSetQty(parseInt(pq.getAttribute("data-mb-qty"), 10) || 0); return; }
    if (t.closest("[data-mb-prod-add]")) { ev.preventDefault(); productAdd(); return; }
    // Router (P8-3): navigate / back / tabs.
    var go = t.closest("[data-mb-go]"); if (go) { ev.preventDefault(); showView(go.getAttribute("data-mb-go")); return; }
    if (t.closest("[data-mb-back]")) { ev.preventDefault(); backView(); return; }
    var tab = t.closest("[data-mb-tab]"); if (tab) { ev.preventDefault(); showView(tab.getAttribute("data-mb-tab")); return; }
    if (t.closest("[data-mb-open-cart]")) {
      if (hasRouter && viewEl("cart")) { showView("cart"); }
      else { var m = doc.querySelector("[data-mb-cart-mount]"); if (m) { m.hidden = false; renderCart(); } }
      return;
    }
    if (t.closest("[data-mb-call-waiter]")) { ev.preventDefault(); callWaiter(); return; }
    var shr = t.closest("[data-mb-share]"); if (shr) { ev.preventDefault(); share(shr); return; }
    var inc = t.closest("[data-mb-inc]"); if (inc) { var i = +inc.getAttribute("data-mb-inc"); setQty(i, cart[i].qty + 1); return; }
    // v2: booking slot picker.
    var sday = t.closest("[data-mb-slot-day]"); if (sday) { ev.preventDefault(); slot.date = sday.getAttribute("data-mb-slot-day"); slot.value = ""; ensureSlots(); return; }
    var sbtn = t.closest("[data-mb-slot]"); if (sbtn) { ev.preventDefault(); if (!sbtn.disabled) { slot.value = sbtn.getAttribute("data-mb-slot") || ""; renderCart(); } return; }
    if (t.closest("[data-mb-fitment-reset]")) { ev.preventDefault(); resetFitment(); return; }
    var dec = t.closest("[data-mb-dec]"); if (dec) { var j = +dec.getAttribute("data-mb-dec"); setQty(j, cart[j].qty - 1); return; }
    var ot = t.closest("[data-mb-ot]"); if (ot) { orderType = ot.getAttribute("data-mb-ot"); slot.value = ""; slot.date = null; slot.key = null; slot.failed = false; renderCart(); return; }
    if (t.closest("[data-mb-coupon-apply]")) { var ci = doc.querySelector("[data-mb-coupon-input]"); applyCoupon(ci ? ci.value.trim() : ""); return; }
    var tp = t.closest("[data-mb-tip]"); if (tp) { tipPct = parseInt(tp.getAttribute("data-mb-tip"), 10) || 0; tipCustom = null; renderCart(); return; }
    if (t.closest("[data-mb-clear-cart]")) { cart = []; coupon = null; tipPct = 0; tipCustom = null; save(); syncBindings(); return; }
    var fav = t.closest("[data-mb-fav]"); if (fav) { ev.preventDefault(); var id = fav.getAttribute("data-mb-fav"); favs[id] = !favs[id]; saveFavs(); applyCatalog(); if (activeView === "favs") renderFavs(); return; }
    var open = t.closest("[data-mb-open]"); if (open) { ev.preventDefault(); openProduct(open.getAttribute("data-mb-open")); return; }
    var flt = t.closest("[data-mb-filter]"); if (flt) { filter = flt.getAttribute("data-mb-filter"); applyCatalog(); return; }
    /* ----- the catalogue facets panel ---------------------------------- */
    if (t.closest("[data-mb-filters-open]")) { ev.preventDefault(); openFacets(t.closest("[data-mb-filters-open]")); return; }
    if (t.closest("[data-mb-facets-close]")) { closeFacets(); return; }
    if (t.closest("[data-mb-facets-clear]")) { facetsReset(); return; }
    if (t.closest("[data-mb-facets-apply]")) { closeFacets(); return; }
    var fb = t.closest("[data-mb-facet]");
    if (fb) {
      var fkind = fb.getAttribute("data-mb-facet");
      var flist = facetSel[fkind];
      if (!flist) return;
      var fval = fb.getAttribute("data-value");
      var fi = flist.indexOf(fval);
      if (fi === -1) flist.push(fval); else flist.splice(fi, 1);
      fb.classList.toggle("is-on", fi === -1);
      fb.setAttribute("aria-pressed", fi === -1 ? "true" : "false");
      applyCatalog();
      facetsCountUp();
      return;
    }
    /* The gift-card purchase form (a storefront section): the denominations
       fill the amount field. The theme ships no JS — the platform's runtime
       owns this, so every theme's gift-card form behaves the same way. */
    var gcp = ev.target.closest && ev.target.closest("[data-mb-gc-preset]");
    if (gcp) {
      var gci = doc.querySelector("[data-mb-gc-amount]");
      if (gci) {
        gci.value = gcp.getAttribute("data-mb-gc-preset");
        all("[data-mb-gc-preset]").forEach(function (x) { x.classList.toggle("is-on", x === gcp); });
      }
      return;
    }
    if (t.closest("[data-mb-layout-toggle]")) { ev.preventDefault(); toggleLayout(); return; }
    var lg = t.closest("[data-mb-set-lang]"); if (lg) { ev.preventDefault(); setLang(lg.getAttribute("data-mb-set-lang")); return; }
    var cur = t.closest("[data-mb-currency]"); if (cur) { ev.preventDefault(); setCurrency(cur.getAttribute("data-mb-currency")); langSheet(false); return; }
    if (t.closest("[data-mb-lang-open]")) { ev.preventDefault(); langSheet(true); return; }
    if (t.closest("[data-mb-lang-close]")) { langSheet(false); return; }
    if (t.closest("[data-mb-account]") || t.closest("[data-mb-login]")) {
      ev.preventDefault();
      if (hasRouter && (viewEl("profile") || viewEl("account"))) { showView(viewEl("profile") ? "profile" : "account"); }
      else { openAccount(); }
      return;
    }
    if (t.closest("[data-mb-acct-close]")) { var am = doc.querySelector("[data-mb-account-mount]"); if (am) { am.hidden = true; am.innerHTML = ""; } return; }
    if (t.closest("[data-mb-acct-track]")) { ev.preventDefault(); trackOrderFromInput(); return; }
    if (t.closest("[data-mb-acct-favs]")) { ev.preventDefault(); var af = doc.querySelector("[data-mb-account-mount]"); if (af) { af.hidden = true; af.innerHTML = ""; } if (hasRouter && viewEl("favs")) showView("favs"); return; }
    if (t.closest("[data-mb-acct-clear]")) { ev.preventDefault(); clearDeviceData(); return; }
    if (t.closest("[data-mb-acct-dark]")) { ev.preventDefault(); toggleTheme(); return; }
    if (t.closest("[data-mb-acct-edit]")) {
      ev.preventDefault();
      var key = t.closest("[data-mb-acct-edit]").getAttribute("data-mb-acct-edit");
      var form = doc.querySelector(key === "address" ? "[data-mb-address-form]" : "[data-mb-profile-form]");
      if (form) {
        form.hidden = !form.hidden;
        var first = form.querySelector("input,select,textarea");
        if (first && !form.hidden) first.focus();
      }
      return;
    }
    if (t.closest("[data-mb-notifications]")) { ev.preventDefault(); notifOpen(t.closest("[data-mb-notifications]")); return; }
    if (t.closest("[data-mb-bell-close]")) { ev.preventDefault(); notifClose(); return; }
    if (t.closest("[data-mb-bell-read-all]")) { ev.preventDefault(); notifReadAll(); return; }
    // A click on the dimmed backdrop (the panel itself, not the card) closes it.
    if (t.classList && t.classList.contains("mb-bell")) { notifClose(); return; }
    if (t.closest("[data-mb-notif-toggle]")) {
      ev.preventDefault();
      var np = doc.querySelector("[data-mb-notif-panel]");
      if (np) { np.hidden = !np.hidden; if (!np.hidden) np.scrollIntoView({ block: "nearest" }); }
      return;
    }
    if (t.closest("[data-mb-acct-rewards]")) { ev.preventDefault(); if (!showLoyaltyWidget()) { var rc = t.closest("[data-mb-rewards]"); if (rc) rc.remove(); } return; }
    var atab = t.closest("[data-mb-acct-tab]"); if (atab) { acctTab = atab.getAttribute("data-mb-acct-tab"); var m2 = doc.querySelector("[data-mb-account-mount]"); if (m2) drawAccount(m2); return; }
    if (t.closest("[data-mb-logout]")) { ev.preventDefault(); logout(); return; }
    var otp = t.closest("[data-mb-otp-request]"); if (otp) { ev.preventDefault(); var of = otp.closest("form"); if (of) requestOtp(of); return; }
    var adel = t.closest("[data-mb-address-del]"); if (adel) { ev.preventDefault(); deleteAddress(adel.getAttribute("data-mb-address-del")); return; }
    if (t.closest("[data-mb-acct-older]")) { ev.preventDefault(); loadOlderOrders(); return; }
  });
  doc.addEventListener("input", function (ev) {
    if (ev.target.matches("[data-mb-search]")) { search = (ev.target.value || "").trim().toLowerCase(); applyCatalog(); return; }
    // The price slider filters live while the customer drags it.
    if (ev.target.matches("[data-mb-facet-price]")) { onFacetPrice(ev.target); }
  });
  doc.addEventListener("change", function (ev) {
    if (ev.target.matches("[data-mb-sort]")) { sortMode = ev.target.value || "featured"; applySort(); return; }
    if (ev.target.matches("[data-mb-facet-price]")) { onFacetPrice(ev.target); return; }
    if (ev.target.closest && ev.target.closest("[data-mb-fitment]")) { onFitmentChange(ev.target); return; }
    if (ev.target.matches("[data-mb-tip-custom]")) { var tv = parseFloat(ev.target.value); tipCustom = isNaN(tv) ? null : Math.max(0, tv); renderCart(); return; }
    if (ev.target.closest && ev.target.closest("[data-mb-product-mount]")) {
      var pm = doc.querySelector("[data-mb-product-mount]");
      refreshOptions(pm, item(pId));
      productRecalc();
    }
  });
  doc.addEventListener("submit", function (ev) {
    if (ev.target.matches("[data-mb-checkout-form]")) { ev.preventDefault(); placeOrder(ev.target); return; }
    if (ev.target.matches("[data-mb-fitment]")) { ev.preventDefault(); oemSearch(ev.target); return; }
    if (ev.target.matches("[data-mb-acct-form]")) { ev.preventDefault(); submitAcctForm(ev.target, ev.target.getAttribute("data-mb-acct-form")); return; }
    if (ev.target.matches("[data-mb-acct-lookup]")) { ev.preventDefault(); lookupOrders(ev.target); return; }
    if (ev.target.matches("[data-mb-profile-form]")) { ev.preventDefault(); saveProfile(ev.target); return; }
    if (ev.target.matches("[data-mb-address-form]")) { ev.preventDefault(); addAddress(ev.target); return; }
  });

  /* ----- splash intro (P8-9) ------------------------------------------- *
   * One-time animated intro. Opt-in via a [data-mb-splash] overlay; shown
   * once per session, dismissed on tap or after a beat, and skipped entirely
   * under prefers-reduced-motion. Pure CSS animation — the runtime only toggles
   * classes + remembers it was seen.
   */
  function initSplash() {
    var el = doc.querySelector("[data-mb-splash]");
    if (!el) return;
    var key = "mb_splash_" + CFG.restaurantSlug;
    var reduce = false;
    try { reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches; } catch (e) {}
    var seen;
    try { seen = sessionStorage.getItem(key); } catch (e) { seen = "1"; }
    if (seen || reduce) { if (el.parentNode) el.parentNode.removeChild(el); return; }
    try { sessionStorage.setItem(key, "1"); } catch (e) {}
    el.hidden = false;
    requestAnimationFrame(function () { el.classList.add("is-on"); });
    var done = false;
    function dismiss() {
      if (done) return;
      done = true;
      el.classList.add("is-out");
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 550);
    }
    el.addEventListener("click", dismiss);
    setTimeout(dismiss, 2400);
  }

  function initRouter() {
    if (!hasRouter) return;
    var cur = mbViews.filter(function (v) { return v.classList.contains("is-active"); })[0];
    activeView = cur ? cur.getAttribute("data-mb-view") : (viewRoots()[0] || null);
    all("[data-mb-tab]").forEach(function (b) { b.classList.toggle("is-on", b.getAttribute("data-mb-tab") === activeView); });
  }

  var v2Booted = false;
  function init() {
    syncBindings(); applyCatalog(); applySort(); initSliders(); initRouter(); initSplash(); initShare();
    // A store with nothing to filter by (a restaurant) shows no Filters button.
    if (!(FACETS.sizes || []).length && !(FACETS.colors || []).length) {
      all("[data-mb-filters-open]").forEach(function (el) { el.hidden = true; });
    } else {
      facetsCountUp();
    }
    if (isDark()) applyTheme(true);
    if (!v2Booted) { v2Booted = true; initAgeGate(); initFitment(); }
  }
  if (doc.readyState !== "loading") init();
  else doc.addEventListener("DOMContentLoaded", init);
  window.MBCommerce = { version: "v2", cart: function () { return cart; }, totals: totals, refresh: init };
})();
