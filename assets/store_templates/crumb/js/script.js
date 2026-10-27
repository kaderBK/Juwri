// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ═══════════════════════════════════════════════════════════
   crumb — warm bakery-marketplace post_template
   Faithful port of rev/crumb/demo.js, wired to REAL menu data
   (window.CRUMB) and the real ordering / coupon / review /
   waiter / loyalty endpoints. UI/interactions match the demo.
   ═══════════════════════════════════════════════════════════ */
/* Wrapped in an IIFE so the DOM helpers below (`const $ = querySelector`, `$$`,
   …) stay local. At top level they land in the global lexical scope and SHADOW
   jQuery's `$` for every other script on the page — picnic and scoop both shipped
   that bug (a shared `$(window).on(...)` then hit querySelector(window) and threw
   "'[object Window]' is not a valid selector"). crumb was missed in that pass.
   Nothing here is read from outside: no inline handlers in the blade, no
   `window.*` exports, so the wrap is transparent. */
(function () {
"use strict";

const C = window.CRUMB || {};

/* ── data (from real Blade payload) ───────────────────── */
const ITEMS = Object.values(C.items || {});
const CATS = [{ id: "all", name: "All", emoji: "✦", kids: [] }]
  .concat((C.cats || []).map(c => ({ id: c.id, name: c.name, emoji: c.emoji, img: c.img, kids: c.kids || [] })));
/* id → name for parents AND subcategories (chips only exist for parents) */
const CAT_NAMES = C.catNames || {};
const BRANCHES = (C.branches || []).map((b, i) => ({
  key: b.key, name: b.name, addr: b.addr || "",
  pin: b.pin || { x: 30 + i * 20, y: 30 + i * 15 },
}));
const SELLER = { name: C.sellerName || "", img: C.sellerImg || "", addr: C.sellerAddr || "" };

const CUR_BASE = { sign: C.currencySign || "$", before: String(C.currencyPos) === "1", rate: 1 };
/* per-currency decimals: 3-decimal currencies (OMR/KWD/BHD/JOD/LYD/TND) must keep their fils */
const CUR_DP_BASE = Number.isInteger(C.currencyDecimals) ? C.currencyDecimals : 2;
/* bug batch 10 · 4: order arithmetic (coupon / tax / tip) at the store currency's minor unit — what the order stores (CFG.moneyDecimals); display keeps currencyDecimals */
const MONEY_DP = Number.isInteger(C.moneyDecimals) ? C.moneyDecimals : CUR_DP_BASE;
const CUR_DP_MAP = C.decimalsByCode || {};
/* decimals follow the DISPLAYED currency: the store's own from the server, any
   other from the map (2 unless it is a 3-decimal currency). */
const curDp = code => (code === C.baseCurrency ? CUR_DP_BASE : (CUR_DP_MAP[code] ?? 2));
const DELIVERY_FEE = +C.deliveryCharge || 0;
const MIN_ORDER = +C.minOrderValue || 0;
const STORE_CREDIT = +C.storeCredit || 0;
/* parent ids that own a rendered sub-chip group (drives data-has-sub + reveal) */
const SUB_PARENTS = new Set([...document.querySelectorAll("#subChips .subgroup")].map(g => g.dataset.parent));

/* payment methods from real flags */
const PAY_METHODS = [{ id: "pay_on_counter", icon: "cash" }];
if (C.allowPayment) PAY_METHODS.push({ id: "pay_online", icon: "card" });

/* ── i18n (server-translated strings for the active language) ── */
const I = C.i18n || {};
const t = k => (I[k] != null ? I[k] : k);
const tn = (k, n) => t(k).replace(":n", n).replace("{n}", n);

/* ── state ────────────────────────────────────────────── */
const store = {
  get: (k, d) => { try { return JSON.parse(localStorage.getItem("crumb_" + C.storeId + "_" + k)) ?? d; } catch { return d; } },
  set: (k, v) => { try { localStorage.setItem("crumb_" + C.storeId + "_" + k, JSON.stringify(v)); } catch {} },
};

/* A cart line persists in the shopper's browser indefinitely, so a line written
   by an older build (or any hand-edited value) outlives the shape this code
   expects. `unitPrice` reads `sel.w`, so a line with no `sel` threw inside the
   `.map()` that builds the cart — one bad line took the WHOLE cart view down
   (empty list, dead buttons), not just itself. Normalise on the way in: keep
   every line that still names an item, give it the selection shape. */
function normalizeCart(raw) {
  return (Array.isArray(raw) ? raw : []).filter(l => l && l.id != null).map(l => ({
    ...l,
    qty: Math.max(1, +l.qty || 1),
    sel: l.sel && typeof l.sel === "object" ? l.sel : { w: 0, ex: [] },
  }));
}

const S = {
  curSign: CUR_BASE.sign, curBefore: CUR_BASE.before, curRate: 1, curCode: C.baseCurrency,
  address: store.get("address", null),
  branch: store.get("branch", BRANCHES[0] ? BRANCHES[0].key : "branch_number1"),
  favs: new Set(store.get("favs", [])),
  cart: normalizeCart(store.get("cart", [])),
  orders: store.get("orders", []),
  savedAddrs: store.get("savedAddrs", []),
  myReviews: {},
  cat: "all", sub: "root", featAll: false, query: "",
  filters: { diets: {}, spice: 3 },
  useWallet: false,
  /* room service wins the default: allowRoomService is only true when the
     scanned QR resolved to a hotel room, so it means this guest is in a room. */
  orderType: C.allowRoomService ? "room_service" : (C.allowDelivery ? "delivery" : (C.allowTakeaway ? "takeaway" : (C.allowOnTable ? "on-table" : "room_service"))),
  table: C.defaultTable || 1,
  coupon: null, tip: 0, pay: PAY_METHODS[0].id,
  currentItem: null, pQty: 1, pSel: { w: 0, ex: [], g: {} }, pImg: 0, descOpen: false,
  rv: { q: "", verified: false, latest: true, detailed: false, stars: 0 },
  wr: { itemId: null, qty: 1, price: 0, order: null, rating: 0 },
  activeOrder: null,
};

/* ── helpers ──────────────────────────────────────────── */
const name = o => (o && o.name != null) ? o.name : o;
const desc = i => i.desc || "";
const item = id => ITEMS.find(i => String(i.id) === String(id));
const branch = key => BRANCHES.find(b => b.key === key) || BRANCHES[0];
function fmt(v) {
  const x = ((+v || 0) * S.curRate).toFixed(curDp(S.curCode));
  const pre = S.curCode !== C.baseCurrency ? '≈ ' : '';   // MC-34: converted = estimate
  // UAE dirham U+20C3 has no side-bearing → a space off the number. Keyed off the
  // CURRENT sign (not the base) so it follows the currency switcher; the sign-last
  // branch already carries its own space.
  const cg = (S.curSign || '').indexOf('⃃') > -1 ? ' ' : '';
  return S.curBefore ? `${pre}${S.curSign}${cg}${x}` : `${pre}${x} ${S.curSign}`;
}
const IMG = u => u || C.defaultImg;
const THUMB = u => u || C.defaultImg;

const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
const esc = s => String(s ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
/* Crawlable + new-tab-able per-item URL: keeps existing query (table, lang…)
   and sets ?item=ID — the deep-link the R7 intercept + boot loader understand. */
const itemHref = id => { try { const u = new URL(location.href); u.searchParams.set("item", id); u.hash = ""; return u.pathname + u.search; } catch { return "?item=" + encodeURIComponent(id); } };
const on = (id, ev, fn) => { const el = $("#" + id); if (el) el.addEventListener(ev, fn); };

const ICONS = {
  plus: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg>',
  minus: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg>',
  heart: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>',
  star: '<svg class="ic star-f" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>',
  starBig: '<svg viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>',
  clock: '<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
  fire: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 22c4.4 0 7-2.8 7-6.6 0-4.8-4.2-6.6-4.9-11.4-2.5 1.6-3.4 4-3.2 6.6-1.2-.7-2-1.7-2.4-3.4C6.6 9 5 11.6 5 15.4 5 19.2 7.6 22 12 22Z"/></svg>',
  leaf: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 19C5 9 13 5 20 5c0 8-4 14-12 14-1 0-2-.1-3 0Z"/><path d="M5 19c2-5 6-9 10-11"/></svg>',
  check: '<svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>',
  trash: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M10 7V5h4v2M8 7l.8 13h6.4L16 7"/></svg>',
  noteIc: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 4h11l3 3v13H5V4Z"/><path d="M15 4v4h4M8.5 12h7M8.5 16h5"/></svg>',
  pin: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>',
  bag: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>',
  cash: '<svg class="ic" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="11" rx="2"/><circle cx="12" cy="12.5" r="2.6"/></svg>',
  card: '<svg class="ic" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M7 15h4"/></svg>',
  home: '<svg class="ic" viewBox="0 0 24 24"><path d="M4 11.5 12 4l8 7.5M6 10v9h12v-9"/></svg>',
};
function starRow(r, size = "") {
  let out = "";
  for (let i = 1; i <= 5; i++) out += `<svg class="ic star-f ${i <= Math.round(r) ? "" : "dim"}" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>`;
  return `<span class="rv-stars ${size}">${out}</span>`;
}

/* ── router ───────────────────────────────────────────── */
const stack = [];
/* CRUMB-F4/F15: the fake chatbot tab is gone and the fabricated street map is
   gated on a real multi-branch store; the freed nav slot goes to the cart. */
const ROOTS = ["home", "explore", "wishlist", "profile"];
/* NB: `cart` is deliberately NOT a root. It has its own sticky Checkout CTA, and a
   root view keeps the tabbar pinned to the same bottom strip — exactly the overlap
   that made a sibling theme's Checkout button unclickable. Tapping the Cart tab
   pushes the cart as a sub-view, so the nav gets out of the CTA's way. */
/* Track/account surfaces also load this shared bundle but do not have a home
   view. Derive the initial route view from the server-rendered active section
   so syncInert() never disables the only visible page. */
let active = document.querySelector(".view.is-active")?.dataset.view || "home";

function show(nameV, { push = true, fromPop = false } = {}) {
  if (nameV === active) return;
  const from = $(`.view[data-view="${active}"]`), to = $(`.view[data-view="${nameV}"]`);
  if (!to) return;
  const isRoot = ROOTS.includes(nameV);
  if (push && !isRoot) {
    stack.push(active);
    /* Mirror the internal stack into browser history so the phone's back
       button walks product → home instead of leaving the site. */
    if (!fromPop) { try { history.pushState({ cb: 1 }, ""); } catch (e) {} }
  }
  if (isRoot) stack.length = 0;

  $$(".view").forEach(v => v.classList.remove("is-under"));
  if (!isRoot) from?.classList.add("is-under");
  from?.classList.remove("is-active");
  to.classList.add("is-active");
  to.querySelector(".view-scroll")?.scrollTo({ top: 0, behavior: "instant" });
  active = nameV;

  $("#tabbar")?.classList.toggle("is-hidden", !isRoot);
  syncInert();   /* CRUMB-F24 */
  $$(".tab").forEach(b => b.classList.toggle("is-on", b.dataset.tab === nameV));

  if (nameV === "cart") renderCart();
  if (nameV === "checkout") renderCheckout();
  if (nameV === "wishlist") renderWishlist();
  if (nameV === "profile") { renderOrders(); if (C.loyaltyEnabled) loadLoyalty(); }
  if (nameV === "explore") renderExplore(true);
  if (nameV === "reviews") renderReviews();
  if (nameV === "location") renderLocResults();
}
function internalBack() { show(stack.pop() ?? "home", { push: false, fromPop: true }); }
function back() {
  /* UI back buttons go through history when we pushed a state, so the
     browser's own back stack stays in step with what is on screen. */
  if (stack.length && history.state && history.state.cb) { history.back(); return; }
  internalBack();
}
window.addEventListener("popstate", () => {
  if (openSheet) { sheet(openSheet, false); try { history.pushState({ cb: 1 }, ""); } catch (e) {} return; }
  if (stack.length) internalBack();
  else if (active !== "home" && $('.view[data-view="home"]')) show("home", { push: false, fromPop: true });
});
/* CRUMB-F24 — every non-active view stayed in the accessibility tree, so the home
   grid's cards and hearts were still focusable behind an open product page. */
function syncInert() {
  $$(".view").forEach(v => { if (v.dataset.view === active) v.removeAttribute("inert"); else v.setAttribute("inert", ""); });
}

/* ── sheets ───────────────────────────────────────────── */
let openSheet = null;
const sheetTimers = {};
function sheet(id, open = true, { blur = false } = {}) {
  const s = $("#" + id), scrim = $("#scrim");
  if (!s) return;
  if (open) {
    if (openSheet && openSheet !== id) sheet(openSheet, false);
    clearTimeout(sheetTimers[id]);
    s.hidden = false; scrim.hidden = false;
    scrim.classList.toggle("blur", blur);
    void s.offsetWidth;
    s.classList.add("is-open"); scrim.classList.add("is-open");
    openSheet = id;
  } else {
    s.classList.remove("is-open"); scrim.classList.remove("is-open");
    if (openSheet === id) openSheet = null;
    clearTimeout(sheetTimers[id]);
    sheetTimers[id] = setTimeout(() => { s.hidden = true; if (!openSheet) { scrim.hidden = true; scrim.classList.remove("blur"); } }, 420);
  }
}
$("#scrim")?.addEventListener("click", () => openSheet && sheet(openSheet, false));

/* ── toast ────────────────────────────────────────────── */
let toastTimer, toastGo = null;
function toast(msg, go = null) {
  const tst = $("#toast"); if (!tst) return;
  tst.innerHTML = ICONS.check.replace("<svg", '<svg class="ic"') + `<span>${esc(msg)}</span>` + (go ? " <b>→</b>" : "");
  tst.classList.add("is-on");
  toastGo = go;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => tst.classList.remove("is-on"), 2200);
}
/* Exposed so the shared reorder-pickup helper (loaded by index.blade.php after
   script.js) can route its "skipped these items" message through crumb's
   toast — same component, same animation. */
window.mbToast = toast;

/* Share this menu (profile row) — native row, not the floating pill.
   Web Share where supported, else copy the link + toast. */
(function () {
  const sb = document.getElementById("mbShareRow");
  if (!sb) return;
  const copied = () => toast(sb.getAttribute("data-copied") || "Link copied");
  const fallbackCopy = () => {
    try {
      const ta = document.createElement("textarea");
      ta.value = location.href; ta.setAttribute("readonly", "");
      ta.style.position = "absolute"; ta.style.left = "-9999px";
      document.body.appendChild(ta); ta.select();
      document.execCommand("copy"); document.body.removeChild(ta); copied();
    } catch (e) {}
  };
  sb.addEventListener("click", () => {
    const data = { title: document.title, url: location.href };
    if (navigator.share) { navigator.share(data).catch(() => {}); return; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(location.href).then(copied).catch(fallbackCopy);
    } else { fallbackCopy(); }
  });
})();
$("#toast")?.addEventListener("click", () => { if (toastGo) { show(toastGo); $("#toast").classList.remove("is-on"); } });

/* ── pricing (weights & extras are deltas over base) ──── */
/* Both helpers run over persisted cart lines, so they take the belt as well as
   the braces of normalizeCart(): a missing `sel`, `weights` or `extras` costs
   that line its modifier price, never the whole render. */
function unitPrice(i, sel) {
  const s = sel || {};
  let p = i.price + ((i.weights || [])[s.w]?.delta ?? 0);
  for (const xi of s.ex ?? []) p += (i.extras || [])[xi]?.delta ?? 0;
  for (const g of i.optionGroups || []) {
    for (const o of (s.g?.[g.id] || [])) p += +o.price || 0;
  }
  return p;
}
function selSummary(i, sel) {
  const s = sel || {}, w = i.weights || [];
  const parts = [name(w[s.w] ?? w[0])];
  for (const g of i.optionGroups || []) {
    for (const o of (s.g?.[g.id] || [])) parts.push(o.label);
  }
  for (const xi of s.ex ?? []) parts.push(name((i.extras || [])[xi]));
  return parts.filter(Boolean).join(" · ");
}
function cartCount() { return S.cart.reduce((n, l) => n + l.qty, 0); }
/* COMBOS-V2 — the shared combos partial (_partials/combos.blade.php) keeps its
   own basket and publishes display state on window.mbCombos {count,total,lines}.
   The server prices combo lines into the ORDER subtotal (and taxes them like any
   other line), so the quote must fold them in too — QUOTED == CHARGED. Quantity
   edits stay in the combo sheet; here they render read-only. */
function comboCount() { return (window.mbCombos && window.mbCombos.count) || 0; }
function comboTotal() { return (window.mbCombos && window.mbCombos.total) || 0; }
function comboLines() { return (window.mbCombos && window.mbCombos.lines) || []; }
/* Combos ride in the subtotal: the server taxes them, min-order counts them,
   and the coupon endpoint is quoted the same base the order will carry. */
function cartSubtotal() { return S.cart.reduce((s, l) => { const i = item(l.id); return i ? s + unitPrice(i, l.sel) * l.qty : s; }, 0) + comboTotal(); }
/* CRUMB-F1 — quoted must equal charged.
   crumb previously computed NO TAX AT ALL and carried a comment asserting the
   server added none. It does: Order::computeTax() runs on every order. On the
   lane store (5%) the cart quoted $18.00 where the kitchen billed $18.80.
   This mirrors Order::computeTax() exactly:
     - the base is the RAW subtotal, never the post-discount one (the discount is
       subtracted last and is never taxed);
     - `restaurant_tax_type === 'fixed'` is a FLAT AMOUNT, not a rate;
     - rounding uses the store's own currency decimals, not a hardcoded 2;
     - charge <= 0 || subtotal <= 0  ⇒  exactly 0. */
const TAX_CHARGE = +C.taxCharge || 0;
const TAX_TYPE = String(C.taxType || "percentage");
const TAX_LABEL = C.taxLabel || "VAT";
/* M16: delegates to the shared money core (assets/store_templates/_shared/js/mb-money.js)
   so tax rounds exactly like Order::computeTax(). */
function computeTax(subtotal) {
  return mbMoney.tax(subtotal, { taxCharge: TAX_CHARGE, taxType: TAX_TYPE, decimals: MONEY_DP });
}
/* The cart as submitOrder's `items` — also posted to the coupon check (bug batch 7 · 4). */
function orderLines() {
  return S.cart.map(l => {
    const i = item(l.id);
    return {
      id: i.id, quantity: l.qty,
      variants: i.weights[l.sel.w]?.vid || 0,
      extras: (l.sel.ex || []).map(xi => ({ id: i.extras[xi]?.xid })).filter(x => x.id),
      mods: Object.keys(l.sel.g || {}).flatMap(gid => (l.sel.g[gid] || []).map(o => ({ groupId: gid, optIndex: o.i }))),
      /* CRUMB-F29: the per-line note ("no onions") was written to localStorage and
         never sent. submitOrder reads `order_note` off each item — send it. */
      order_note: (l.note || "").trim() || null,
    };
  });
}
function couponForm(code) {
  const fd = new FormData(); fd.append("_token", C.csrf); fd.append("code", code); fd.append("subtotal", cartSubtotal().toFixed(MONEY_DP));
  fd.append("items", JSON.stringify(orderLines())); fd.append("ordering-type", S.orderType || "");
  return fd;
}
function couponSig() { return JSON.stringify([cartSubtotal().toFixed(MONEY_DP), orderLines(), S.orderType || ""]); }
/* Bug batch 7 · 4: crumb shows the SERVER's discount, quoted once at apply time — a cart change kept the old
   number (a percentage or buy-X-get-Y coupon on a changed basket). Re-quote whenever the basket or order type
   changes, with the cart lines (buy-X-get-Y is priced on them). */
function couponDiscount() {
  const c = S.coupon, sig = couponSig();
  if (c.sig !== sig) {
    c.sig = sig;
    fetch(C.endpoints.coupon, { method: "POST", body: couponForm(c.code), headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": C.csrf } })
      .then(r => r.json()).then(j => {
        if (S.coupon !== c || c.sig !== sig) return;
        c.discount = j && j.success ? +j.discount || 0 : 0;
        renderTotals();
      }).catch(() => {});
  }
  return +c.discount || 0;
}
function cartTotals() {
  const sub = cartSubtotal();
  let disc = S.coupon ? couponDiscount() : 0;
  disc = Math.min(disc, sub);
  const del = (S.orderType === "delivery" && (S.cart.length || comboCount())) ? DELIVERY_FEE : 0;
  const tax = computeTax(sub);
  const tip = C.allowTips ? (sub - disc) * (S.tip / 100) : 0;
  return { sub, disc, del, tax, tip, total: Math.max(0, sub - disc + del + tax + tip) };
}

/* ── home ─────────────────────────────────────────────── */
function fcardHTML(i, idx) {
  const fav = S.favs.has(String(i.id));
  const rating = i.rating ? i.rating : "";
  return `
  <a class="fcard" href="${esc(itemHref(i.id))}" data-open="${i.id}" style="animation-delay:${Math.min(idx * 45, 400)}ms;color:inherit;text-decoration:none">
    <span class="fcard-imgwrap">
      <img src="${THUMB(i.gallery[0])}" alt="" loading="lazy" data-csp-error="fallback" data-csp-fallback="${esc(C.defaultImg)}">
      ${rating ? `<span class="fcard-rate">${ICONS.star.replace('class="ic', 'class="ic ic-xs')} ${rating}</span>` : ""}
      <span class="fcard-fav ${fav ? "is-on" : ""}" data-fav="${i.id}">${ICONS.heart}</span>
      <span class="fcard-badges">
        ${i.saleLabel ? `<span class="fcard-sale"><svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>${esc(i.saleLabel)}</span>` : ""}
        ${!i.inStock ? `<span class="fcard-special" style="background:var(--red)">${t("out_stock")}</span>` : (i.special ? `<span class="fcard-special">${t("daily_special")}</span>` : "")}
      </span>
    </span>
    <span class="fcard-name">${esc(name(i))}</span>
    <span class="fcard-sub">${esc(SELLER.name)}</span>
    <span class="fcard-foot">
      <span class="fcard-pricewrap">
        ${i.original_price && i.original_price > i.price ? `<del class="fcard-was">${fmt(i.original_price)}</del>` : ""}<span class="fcard-price">${fmt(i.price)}</span>
      </span>
      ${C.allowOrder ? `<span class="fcard-add" data-add="${i.id}">${ICONS.plus}</span>` : ""}
    </span>
  </a>`;
}
/* a chip claims an item filed directly under it OR under any of its
   subcategories — chips are top-level only, items key off their own id */
function catClaims(c, i) {
  return String(c.id) === String(i.cat) || (c.kids || []).some(k => String(k) === String(i.cat));
}
/* the item's own category name (subcategory included), else its parent chip */
function catLabel(i) {
  if (CAT_NAMES[i.cat]) { return CAT_NAMES[i.cat]; }
  const c = CATS.find(x => x.id !== "all" && catClaims(x, i));
  return c ? name(c) : "";
}
/* dietary + max-spice filter — composes inside visibleItems() and search */
function matchesFilters(i) {
  if (!C.allowDietary) return true;
  const f = S.filters;
  if (f.diets.vegan && !i.isVegan) return false;
  if (f.diets.halal && !i.isHalal) return false;
  if (f.diets.gf && !i.isGf) return false;
  if ((i.spice || 0) > f.spice) return false;
  return true;
}
function inCat(i) {
  if (S.cat === "all") return true;
  const c = CATS.find(x => String(x.id) === String(S.cat));
  const claimed = c ? catClaims(c, i) : String(i.cat) === String(S.cat);
  if (!claimed) return false;
  /* sub-chip narrows within the active parent to one real category */
  if (S.sub !== "root" && String(i.cat) !== String(S.sub)) return false;
  return true;
}
function visibleItems() {
  return ITEMS.filter(i => inCat(i) && matchesFilters(i));
}
/* Reveal the active parent's sub-chip group (if any), reset its selection to
   "All", hide every other group. Called on each parent change. */
function syncSubChips() {
  const wrap = $("#subChips"); if (!wrap) return;
  let shown = null;
  $$("#subChips .subgroup").forEach(g => {
    const match = S.cat !== "all" && g.dataset.parent === String(S.cat);
    g.hidden = !match;
    if (match) { shown = g; $$(".subchip", g).forEach(c => c.classList.toggle("is-on", c.dataset.sub === "root")); }
  });
  wrap.hidden = !shown;
}
function renderCats() {
  const row = $("#catRow"); if (!row) return;
  row.innerHTML = CATS.map(c => {
    const hasSub = c.id !== "all" && SUB_PARENTS.has(String(c.id));
    return `<button class="cat-tile ${String(c.id) === String(S.cat) ? "is-on" : ""}"${hasSub ? ' data-has-sub="1"' : ""} data-cat="${c.id}" type="button"><i>${c.img ? `<img src="${c.img}" alt="" data-csp-error="replace-text" data-csp-text="${esc(c.emoji)}">` : c.emoji}</i><span>${esc(name(c))}</span></button>`;
  }).join("");
}
function renderFeatured() {
  /* CRUMB-F30 — script.js is auto-loaded by store_templates.layout on EVERY crumb
     surface, including track.blade.php and account.blade.php, which have no home
     grid. `$("#featGrid").innerHTML = ...` threw
     "TypeError: Cannot set properties of null" out of boot() on both of those pages,
     aborting boot before the appearance bootstrap and the inert sync ever ran. */
  const grid = $("#featGrid"); if (!grid) return;
  const all = visibleItems();
  let list = all;
  if (!S.featAll) {
    /* Home is a 12-item preview (soiree parity): show featured first, then fill the
       rest of the preview with the remaining menu so the grid never looks sparse
       when a store marks only a couple of items popular. "See All" expands to full. */
    const feat = all.filter(i => i.featured);
    const rest = all.filter(i => !i.featured);
    list = feat.concat(rest).slice(0, 12);
  }
  grid.innerHTML = list.map(fcardHTML).join("");
  const sa = $("#featSeeAll");
  if (sa) {
    const lbl = sa.querySelector(".mm-label") || sa;
    lbl.textContent = S.featAll ? t("see_less") : t("see_all");
    sa.classList.toggle("is-open", S.featAll);
    sa.hidden = !S.featAll && all.length <= 12; // whole menu already fits the preview
  }
}
function renderHome() { renderCats(); renderFeatured(); }

/* search overlay */
function openSearch() {
  const ov = $("#searchOverlay"); ov.hidden = false;
  void ov.offsetWidth; ov.classList.add("is-open");
  renderSearch();
  setTimeout(() => $("#soInput").focus(), 120);
}
function closeSearch() { const ov = $("#searchOverlay"); ov.classList.remove("is-open"); setTimeout(() => { ov.hidden = true; }, 300); }
function renderSearch() {
  /* CRUMB-F13: these were three hardcoded bakery words ("Chocolate", "Cake",
     "Cookies") presented under a "POPULAR" heading on every storefront. Use the
     store's own popular/daily-special dishes, and drop the row when it has none. */
  const recents = ITEMS.filter(i => i.featured || i.special).slice(0, 3).map(i => name(i));
  const recentRow = $("#soRecent"), recentLbl = $("#soLabel");
  if (recentLbl) recentLbl.hidden = !recents.length;
  if (recentRow) recentRow.hidden = !recents.length;
  $("#soRecent").innerHTML = recents.map(r => `<button data-q="${esc(r)}" type="button">${esc(r)}</button>`).join("");
  const q = S.query.trim().toLowerCase();
  const f = S.filters;
  const anyDiet = C.allowDietary && (f.diets.vegan || f.diets.halal || f.diets.gf || f.spice < 3);
  const res = (q || anyDiet)
    ? ITEMS.filter(i => matchesFilters(i) && (!q || (name(i) + " " + desc(i)).toLowerCase().includes(q)))
    : [];
  $("#soResults").innerHTML = res.map((i, idx) => `
    <button class="so-row" data-open="${i.id}" style="animation-delay:${idx * 40}ms" type="button">
      <img src="${THUMB(i.gallery[0])}" alt="" data-csp-error="fallback" data-csp-fallback="${esc(C.defaultImg)}">
      <div><b>${esc(name(i))}</b><small>${i.rating ? "★ " + i.rating : ""}</small></div>
      <span class="price">${fmt(i.price)}</span>
    </button>`).join("");
  $("#soEmpty").hidden = !((q || anyDiet) && !res.length);
}

/* ── product ──────────────────────────────────────────── */
const MAX_THUMBS = 5;
function openProduct(id) { setProduct(id, { fresh: true }); if ($("#searchOverlay").classList.contains("is-open")) closeSearch(); show("product"); }
function setProduct(id, { fresh = false } = {}) {
  const i = item(id); if (!i) return;
  const same = S.currentItem?.id === i.id;
  S.currentItem = i;
  if (fresh || !same) { S.pQty = 1; S.pSel = { w: 0, ex: [], g: {} }; S.pImg = 0; S.descOpen = false; }

  setHero(i, S.pImg);
  renderThumbs(i);
  $("#pFav").classList.toggle("is-on", S.favs.has(String(id)));
  $("#pEyebrow").textContent = catLabel(i);
  $("#pRating").innerHTML = i.rating ? `${ICONS.star.replace('class="ic', 'class="ic ic-xs')} ${i.rating}` : "";
  $("#pTitle").textContent = name(i);
  const dot = $("#pStockDot");
  dot.classList.toggle("out", !i.inStock);
  dot.title = i.inStock ? t("in_stock") : t("out_stock");

  /* CRUMB-F16: crumb printed the invented role "Baker" under the store's real name.
     Show the store's own address instead, or just the name. */
  $("#pSeller").innerHTML = `
    <span class="me-avatar sm" aria-hidden="true">${esc((SELLER.name || "?").trim().charAt(0).toUpperCase())}${SELLER.img ? `<img src="${IMG(SELLER.img)}" alt="" data-csp-error="remove">` : ""}</span>
    <div><b>${esc(SELLER.name)}</b>${SELLER.addr ? `<small>${esc(SELLER.addr)}</small>` : ""}</div>`;

  const meta = [];
  if (i.time) meta.push(`<span class="meta-chip">${ICONS.clock}${i.time} ${t("min")}</span>`);
  if (i.kcal) meta.push(`<span class="meta-chip">${ICONS.fire}${i.kcal} ${t("kcal")}</span>`);
  if (i.tags.includes("vegan")) meta.push(`<span class="meta-chip">${ICONS.leaf}${t("vegan")}</span>`);
  (i.allergens || []).forEach(a => meta.push(`<span class="meta-chip warn">⚠ ${esc(a)}</span>`));
  $("#pMeta").innerHTML = meta.join("");
  $("#pMeta").hidden = !meta.length;   /* CRUMB-F22 */

  $("#pDesc").textContent = desc(i);
  $("#pDesc").classList.toggle("clamp", !S.descOpen);
  $("#pReadMore").hidden = (desc(i) || "").length < 120;
  $("#pReadMore").textContent = S.descOpen ? t("read_less") : t("read_more");

  $("#pWeightLabel").textContent = i.weightGroup || (i.weightType === "weight" ? t("select_weight") : t("select_pack"));
  const wBlock = $("#pWeightLabel").closest(".p-weight-block");
  if (wBlock) wBlock.hidden = i.weights.length <= 1 && !i.weights[0].vid;
  renderWeights(i);
  renderGroups(i);
  $("#pExtrasBlock").hidden = !i.extras.length;
  renderExtras(i);
  const revCount = i.reviews + (S.myReviews[i.id]?.length || 0);
  $("#pRevCount").textContent = `(${revCount})`;
  $("#pRevAvg").textContent = i.rating || "";
  /* inline "Rate this dish" quick-rate — rebuilt each open (stars reset), sits
     just above the reviews row. Tapping a star opens the shared review
     write-sheet preselected to that many stars (window.mbItemReviews.open). */
  const rrRow = $("#pRevRow");
  if (window.__mbItemReviewsOn !== false && rrRow && rrRow.parentNode) {
    let rr = document.getElementById("rateRow");
    if (!rr) { rr = document.createElement("div"); rr.className = "rate-row"; rr.id = "rateRow"; rrRow.parentNode.insertBefore(rr, rrRow); }
    rr.innerHTML = `<span class="rate-label">${t("rate_dish")}</span><span class="rate-stars" id="rateStars">${[1, 2, 3, 4, 5].map(n => `<button data-rate="${n}" type="button" aria-label="${n}"><svg class="ic" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg></button>`).join("")}</span>`;
  }
  updateProductCta();
}
function setHero(i, idx) {
  const img = $("#pHeroImg");
  img.style.opacity = "";
  img.src = IMG(i.gallery[idx] || i.gallery[0]);
}
function renderThumbs(i) {
  const g = i.gallery, extra = g.length - MAX_THUMBS;
  const el = $("#pThumbs");
  if (g.length <= 1) { el.innerHTML = ""; return; }
  el.innerHTML = g.slice(0, MAX_THUMBS).map((th, ti) => {
    const isMore = ti === MAX_THUMBS - 1 && extra > 0;
    return `<button class="p-thumb ${ti === S.pImg ? "is-on" : ""}" data-thumb="${ti}" type="button">
      <img src="${THUMB(th)}" alt="" data-csp-error="fallback" data-csp-fallback="${esc(C.defaultImg)}">${isMore ? `<span class="more">+${extra + 1}</span>` : ""}
    </button>`;
  }).join("");
}
function renderWeights(i) {
  $("#pWeights").innerHTML = i.weights.map((w2, wi) => {
    const extra = w2.delta ? ` <small>+${fmt(w2.delta)}</small>` : "";
    return `<button class="wchip ${wi === S.pSel.w ? "is-on" : ""}" data-w="${wi}" type="button">${esc(name(w2))}${extra}</button>`;
  }).join("");
}
/* global modifier groups — additive options with required/min/max */
function renderGroups(i) {
  const wrap = $("#pGroups"); if (!wrap) return;
  const groups = i.optionGroups || [];
  wrap.innerHTML = groups.map(g => {
    const multi = g.max === null || g.max > 1;
    const sel = S.pSel.g[g.id] || [];
    const req = g.required
      ? `<span class="opt-req">${t("required")}</span>`
      : `<span class="opt-req optional">${t("optional")}</span>`;
    const opts = g.options.map(o => {
      const isOn = sel.some(s => s.i === o.i);
      return `<div class="opt ${isOn ? "is-on" : ""}" data-group="${g.id}" data-opt="${o.i}" data-price="${o.price}" data-label="${esc(o.label)}" data-multi="${multi ? 1 : 0}" data-max="${g.max === null ? "" : g.max}">
        <span class="opt-ctl ${multi ? "box" : ""}">${ICONS.check}</span>
        <span class="opt-name">${esc(o.label)}</span>
        <span class="opt-price">${o.price ? `+${fmt(o.price)}` : t("free")}</span>
      </div>`;
    }).join("");
    return `<div class="opt-group" data-groupwrap="${g.id}"><div class="opt-head"><b>${esc(g.title)}</b>${req}</div><div class="opt-list">${opts}</div></div>`;
  }).join("");
}
function renderExtras(i) {
  const anyImg = i.extras.some(x => x.img);
  $("#pExtras").classList.toggle("has-imgs", anyImg);
  $("#pExtras").innerHTML = i.extras.map((x, xi) => `
    <div class="opt ${x.img ? "opt--img" : ""} ${S.pSel.ex.includes(xi) ? "is-on" : ""}" data-ex="${xi}">
      <span class="opt-ctl">${ICONS.check}</span>
      ${x.img ? `<img class="opt-img" src="${esc(x.img)}" alt="" loading="lazy" data-csp-error="fallback" data-csp-fallback="${esc(C.defaultImg)}">` : ""}
      <span class="opt-name">${esc(name(x))}</span>
      <span class="opt-price">${x.delta ? `+${fmt(x.delta)}` : t("free")}</span>
    </div>`).join("");
}
function updateProductCta() {
  const i = S.currentItem; if (!i) return;
  const qv = $("#pQtyVal"); if (qv) qv.textContent = String(S.pQty).padStart(2, "0");
  const pt = $("#pTotal"); if (pt) pt.textContent = fmt(unitPrice(i, S.pSel) * S.pQty);
  const btn = $("#addToCart"); if (btn) btn.disabled = !i.inStock;
}

/* ── cart ─────────────────────────────────────────────── */
function addToCart(id, sel, qty, fromEl) {
  const i = item(id); if (!i) return;
  if (!i.inStock) { toast(t("out_stock")); return; }
  sel = { w: sel.w || 0, ex: sel.ex || [], g: sel.g || {} };
  /* enforce required option groups (min selections) before adding */
  const missing = (i.optionGroups || []).filter(g => {
    const need = g.required ? Math.max(1, g.min || 0) : (g.min || 0);
    return (sel.g[g.id] || []).length < need;
  });
  if (missing.length) { toast(`${t("choose")}: ${missing[0].title}`); return; }
  const key = id + "|" + JSON.stringify(sel);
  const line = S.cart.find(l => l.key === key);
  if (line) line.qty += qty; else S.cart.push({ key, id, qty, sel, note: "" });
  store.set("cart", S.cart);
  syncBadge(true);
  toast(`${name(i)} — ${t("added")}`, "cart");
  if (fromEl) flyToCart(fromEl);
}
function flyToCart(fromEl) {
  const img = fromEl.closest(".fcard, .so-row")?.querySelector("img") ?? $("#pHeroImg");
  const target = $("#cartBtn") || document.querySelector('.tab--cart .tab-ic') || document.querySelector('[data-tab="cart"]');
  if (!img || !target || active !== "home") return;
  const a = img.getBoundingClientRect(), b = target.getBoundingClientRect();
  const ghost = document.createElement("img");
  ghost.src = img.src; ghost.className = "fly-img";
  const lay = $("#flyLayer"), lr = lay.getBoundingClientRect();
  ghost.style.left = a.left - lr.left + a.width / 2 - 32 + "px";
  ghost.style.top = a.top - lr.top + a.height / 2 - 32 + "px";
  lay.appendChild(ghost);
  void ghost.offsetWidth;
  ghost.style.left = b.left - lr.left + b.width / 2 - 32 + "px";
  ghost.style.top = b.top - lr.top + b.height / 2 - 32 + "px";
  ghost.style.transform = "scale(.14)"; ghost.style.opacity = ".3";
  setTimeout(() => ghost.remove(), 800);
}
function syncBadge(bump) {
  const n = cartCount() + comboCount(), b = $("#cartBadge"); if (!b) return;
  b.hidden = n === 0; b.textContent = n;
  if (bump && n) { b.classList.remove("bump"); void b.offsetWidth; b.classList.add("bump"); }
  /* product footer: a direct jump to the cart, shown once anything is in it */
  const pj = $("#pCartJump"); if (pj) { pj.hidden = n === 0; const pjc = $("#pCartJumpCount"); if (pjc) pjc.textContent = n; }
}

/* COMBOS-V2 — read-only "Combos" group appended to the cart list and the
   checkout summary. Tapping a row reopens the combo sheet's basket view,
   which is where quantities are edited. */
function comboGroupHTML(compact) {
  const lines = comboLines();
  if (!lines.length) return "";
  let h = '<div class="crumb-combo-group" data-combo-open style="border-top:1px dashed var(--line,#8883);padding-top:8px;margin-top:8px;cursor:pointer">';
  for (const L of lines) {
    h += `<div class="ck-row ck-row--combo">
      <div class="ck-mid"><b>${esc(L.name)}</b>${L.parts.length ? `<small>${esc(L.parts.join(" · "))}</small>` : ""}</div>
      <span class="ck-qty">×${L.qty}</span>
      <span class="ck-price">${fmt(L.price)}</span>
    </div>`;
  }
  if (!compact) h += `<small style="opacity:.65">${esc(t("edit"))} ›</small>`;
  h += "</div>";
  return h;
}
document.addEventListener("click", e => {
  const g = e.target.closest && e.target.closest("[data-combo-open]");
  if (g && window.mbCombos && window.mbCombos.openSheet) window.mbCombos.openSheet("basket");
});
/* Re-quote whenever the combo basket changes (add/remove/qty in the sheet). */
window.addEventListener("mb:combos-changed", () => {
  try {
    syncBadge(true);
    renderTotals();
    if (active === "cart") renderCart();
    if (active === "checkout") renderCheckout();
  } catch (e) {}
});

function renderCart() {
  if (!$("#cartList")) return;
  const has = S.cart.length > 0 || comboCount() > 0;
  $("#cartEmpty").hidden = has;
  $("#cartTools").style.display = has ? "" : "none";
  $("#cartCta").hidden = !has;
  /* "clear cart" only empties the ITEM cart, so it stays tied to real items */
  $("#clearCart").style.visibility = S.cart.length ? "" : "hidden";
  $("#cartList").innerHTML = comboGroupHTML(false) + S.cart.map((l, li) => {
    const i = item(l.id); if (!i) return "";
    const unit = unitPrice(i, l.sel);
    /* Happy-hour strike-through: original_price is the pre-schedule BASE
       price, so the pre-discount unit is unit + (original − current base). */
    const wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
      ? unit + (Number(i.original_price) - Number(i.price)) : 0;
    return `
    <div class="cart-item" data-key="${esc(l.key)}" style="animation-delay:${li * 50}ms">
      <img src="${THUMB(i.gallery[0])}" alt="" data-csp-error="fallback" data-csp-fallback="${esc(C.defaultImg)}" data-open="${i.id}" role="button" tabindex="0">
      <div>
        <div class="ci-name" data-open="${i.id}" role="button" tabindex="0">${esc(name(i))}</div>
        <div class="ci-opts">${esc(selSummary(i, l.sel))}</div>
        <div class="ci-priceline"><span class="ci-price">${fmt(unit * l.qty)}</span>${
          wasUnit ? `<del class="ci-was">${fmt(wasUnit * l.qty)}</del>` : ""}${
          l.qty > 1 ? `<small class="ci-unit">${l.qty} × ${fmt(unit)}</small>` : ""}</div>
      </div>
      <div class="ci-side">
        <div class="ci-actions">
          <button class="ci-note-btn ${l.note ? "has-note" : ""}" data-note aria-label="Note" type="button">${ICONS.noteIc}</button>
          <button class="ci-remove" data-rm aria-label="${esc(I.remove || "Remove")}" type="button">${ICONS.trash}</button>
        </div>
        <div class="qty">
          <button class="qty-btn" data-cq="-1" aria-label="−" type="button">${ICONS.minus}</button>
          <output>${l.qty}</output>
          <button class="qty-btn" data-cq="1" aria-label="+" type="button">${ICONS.plus}</button>
        </div>
      </div>
      <div class="ci-note" hidden><input value="${esc(l.note)}" data-notein placeholder="…"></div>
    </div>`;
  }).join("");
  renderOtFields(); renderTotals();
}

function syncOrderTypeSeg() {
  $$("#orderTypeSeg [data-ot]").forEach(b => b.classList.toggle("is-on", b.dataset.ot === S.orderType));
}

function renderOtFields() {
  syncOrderTypeSeg();
  const f = $("#otFields"); if (!f) return;
  const wr = $("#waiterRow"); if (wr) wr.hidden = S.orderType !== "on-table";
  const hintRow = k => `<p class="ot-hint">${ICONS.check.replace("<svg", '<svg class="ic"')}<span>${t(k)}</span></p>`;
  if (S.orderType === "on-table") {
    f.innerHTML = `<select id="tableSel">${Array.from({ length: 20 }, (_, n) =>
      `<option value="${n + 1}" ${String(S.table) === String(n + 1) ? "selected" : ""}>${t("table")} ${String(n + 1).padStart(2, "0")}</option>`).join("")}</select>${hintRow("dinein_hint")}`;
  } else if (S.orderType === "delivery") {
    const a = S.address;
    f.innerHTML = `
      <button class="addr-row" data-goloc type="button">
        <span class="loc-ic">${ICONS.pin}</span>
        <div>${a ? `<b>${esc(a)}</b>` : `<b>${t("add_address")}</b>`}</div>
        <svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>
      </button>${hintRow("delivery_hint")}`;
  } else if (S.orderType === "room_service") {
    /* Room service is fulfilled in the guest's room, so like on-table it needs
       neither an address nor a phone number -- the room rides along on the
       `table` field the room QR pre-fills. */
    f.innerHTML = `
      <div class="addr-row"><span class="loc-ic">${ICONS.home}</span>
      <div><b>${t("room")} ${esc(String(S.table))}</b></div></div>${hintRow("room_hint")}`;
  } else {
    const b = branch(S.branch);
    f.innerHTML = `
      <button class="addr-row" data-gobranch type="button">
        <span class="loc-ic">${ICONS.home}</span>
        <div><b>${esc(b.name)}</b>${b.addr ? `<small>${esc(b.addr)}</small>` : ""}</div>
        ${BRANCHES.length > 1 ? '<svg class="ic ic-xs arr" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>' : ""}
      </button>${hintRow("pickup_hint")}`;
  }
}

function creditApplied(total) {
  return (S.useWallet && STORE_CREDIT > 0) ? Math.min(STORE_CREDIT, total) : 0;
}
function totalsHTML(withCredit) {
  const T = cartTotals();
  const delLine = S.orderType === "delivery" && (S.cart.length || comboCount())
    ? `<div class="tr"><span>${t("delivery_fee")}</span><b>${T.del ? fmt(T.del) : t("free")}</b></div>` : "";
  const credit = withCredit ? creditApplied(T.total) : 0;
  const creditLine = credit ? `<div class="tr disc"><span>${t("store_credit")}</span><b>−${fmt(credit)}</b></div>` : "";
  return `
    <div class="tr"><span>${t("subtotal")}</span><b>${fmt(T.sub)}</b></div>
    ${T.disc ? `<div class="tr disc"><span>${t("discount")}${S.coupon ? " (" + esc(S.coupon.code) + ")" : ""}</span><b>−${fmt(T.disc)}</b></div>` : ""}
    ${delLine}
    ${T.tax ? `<div class="tr tax"><span>${esc(TAX_LABEL)}</span><b>${fmt(T.tax)}</b></div>` : ""}
    ${T.tip ? `<div class="tr"><span>${t("tip_label")} (${S.tip}%)</span><b>${fmt(T.tip)}</b></div>` : ""}
    ${creditLine}
    <div class="tr grand"><span>${t("total")}</span><b>${fmt(T.total - credit)}</b></div>`;
}
function renderTotals() {
  const T = cartTotals();
  $("#totalsBox").innerHTML = totalsHTML();
  $("#cartCtaTotal").textContent = fmt(T.total);
  const below = MIN_ORDER > 0 && T.sub > 0 && T.sub < MIN_ORDER;
  const msg = $("#minOrderMsg");
  msg.hidden = !below;
  if (below) msg.textContent = `⚠ ${t("min_order_msg")} ${fmt(MIN_ORDER)}`;
  $("#goCheckout").disabled = below;
}

/* remove-confirm sheet */
let rmKey = null;
function openRemoveSheet(key) {
  const l = S.cart.find(x => x.key === key); if (!l) return;
  const i = item(l.id); rmKey = key;
  $("#rmThumb").src = THUMB(i.gallery[0]);
  $("#rmName").textContent = name(i);
  $("#rmMeta").textContent = `${selSummary(i, l.sel)} · ${t("qty")}: ${String(l.qty).padStart(2, "0")}`;
  $("#rmPrice").textContent = fmt(unitPrice(i, l.sel) * l.qty);
  sheet("removeSheet", true, { blur: true });
}
on("rmCancel", "click", () => sheet("removeSheet", false));
on("rmConfirm", "click", () => {
  sheet("removeSheet", false);
  const row = $(`.cart-item[data-key="${CSS.escape(rmKey)}"]`);
  row?.classList.add("leaving");
  setTimeout(() => {
    S.cart = S.cart.filter(l => l.key !== rmKey);
    store.set("cart", S.cart);
    syncBadge(false); renderCart(); toast(t("removed_toast"));
  }, 300);
});

/* ── checkout ─────────────────────────────────────────── */
function renderCkContext() {
  const box = $("#ckContext");
  if (S.orderType === "delivery") {
    const a = S.address;
    box.innerHTML = `<h3>${t("delivery_addr")}</h3>
      <button class="addr-row" data-goloc type="button">
        <span class="loc-ic">${ICONS.pin}</span>
        <div>${a ? `<b>${esc(a)}</b>` : `<b>${t("add_address")}</b>`}</div>
        <span class="seeall">${t("change")}</span>
      </button>`;
  } else if (S.orderType === "takeaway") {
    const b = branch(S.branch);
    box.innerHTML = `<h3>${t("pickup_branch")}</h3>
      <button class="addr-row" data-gobranch type="button">
        <span class="loc-ic">${ICONS.home}</span>
        <div><b>${esc(b.name)}</b>${b.addr ? `<small>${esc(b.addr)}</small>` : ""}</div>
        ${BRANCHES.length > 1 ? `<span class="seeall">${t("change")}</span>` : ""}
      </button>`;
  } else if (S.orderType === "room_service") {
    box.innerHTML = `<h3>${t("room")}</h3>
      <div class="addr-row"><span class="loc-ic">${ICONS.home}</span>
      <div><b>${t("room")} ${esc(String(S.table))}</b><small>${t("room_hint")}</small></div></div>`;
  } else {
    box.innerHTML = `<h3>${t("table")}</h3>
      <div class="addr-row"><span class="loc-ic">${ICONS.home}</span>
      <div><b>${t("table")} ${String(S.table).padStart(2, "0")}</b><small>${t("dinein_hint")}</small></div></div>`;
  }
}
function renderCheckout() {
  renderCkContext();
  $("#pmList").innerHTML = PAY_METHODS.map(m => `
    <button class="pm ${S.pay === m.id ? "is-on" : ""}" data-pm="${m.id}" type="button">
      <span class="pm-ic">${ICONS[m.icon]}</span>
      <b>${m.id === "pay_on_counter" ? t("pay_counter") : t("pay_online")}</b>
      <span class="pm-radio"></span>
    </button>`).join("");
  $("#cardForm").hidden = S.pay !== "pay_online";
  /* Order summary — what the customer is about to pay for, tap Edit → cart. */
  const ck = $("#ckItems");
  if (ck) {
    ck.hidden = S.cart.length === 0 && comboCount() === 0;
    ck.innerHTML = `<div class="ck-head"><h3>${t("your_order")}</h3><button class="linkbtn" id="ckEdit" type="button">${t("edit")}</button></div>` +
      S.cart.map(l => {
        const i = item(l.id); if (!i) return "";
        const unit = unitPrice(i, l.sel);
        const wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
          ? unit + (Number(i.original_price) - Number(i.price)) : 0;
        const sum = selSummary(i, l.sel);
        return `<div class="ck-row">
          <img src="${THUMB(i.gallery[0])}" alt="" data-csp-error="fallback" data-csp-fallback="${esc(C.defaultImg)}">
          <div class="ck-mid"><b>${esc(name(i))}</b>${sum ? `<small>${esc(sum)}</small>` : ""}</div>
          <span class="ck-qty">×${l.qty}</span>
          <span class="ck-price">${fmt(unit * l.qty)}${wasUnit ? `<del>${fmt(wasUnit * l.qty)}</del>` : ""}</span>
        </div>`;
      }).join("") + comboGroupHTML(true);
    const ed = $("#ckEdit"); if (ed) ed.onclick = () => show("cart");
  }
  $("#checkoutTotals").innerHTML = totalsHTML(true);
  $("#checkoutCtaTotal").textContent = fmt(cartTotals().total - creditApplied(cartTotals().total));
  /* Nothing to pay for → the CTA stays visibly dead rather than posting an
     empty order (placeOrder guards it too, for the keyboard/scripted path). */
  const po = $("#placeOrder");
  if (po) po.disabled = S.cart.length === 0 && comboCount() === 0;
}

function placeOrder() {
  const err = $("#checkoutErr");
  const fail = m => { if (err) { err.hidden = false; err.textContent = m; } toast(m); };
  /* An empty basket must never reach /send-order: the server used to accept
     `items: []` and write a real order with no lines, and the checkout view can
     be reached with an empty cart (clear the cart while standing on it, or a
     restored session). Checked first, before the contact fields. */
  if (!S.cart.length && !comboCount()) { show("cart"); return fail(t("cart_empty")); } /* combos-only orders are legal */
  if (!$("#termsChk").checked) return fail(t("accept_terms"));
  const nm = $("#custName").value.trim();
  if (!nm) return fail(t("fill_contact"));
  if (S.orderType !== "on-table" && S.orderType !== "room_service" && !$("#custPhone").value.trim()) return fail(t("fill_contact"));
  if (S.orderType === "delivery" && !S.address) return fail(t("fill_addr"));
  if (err) err.hidden = true;

  const btn = $("#placeOrder");
  btn.disabled = true;
  btn.querySelector(".spinner").hidden = false;
  const poLabel = btn.querySelector(".po-label");
  const origLabel = poLabel.textContent;
  poLabel.textContent = "…";

  const payload = orderLines();

  const fd = new FormData();
  fd.append("_token", C.csrf);
  fd.append("items", JSON.stringify(payload));
  fd.append("name", nm);
  fd.append("ordering-type", S.orderType);
  fd.append("pay_via", S.pay);
  fd.append("branching-type", S.branch || "branch_number1");
  const note = ($("#orderNote")?.value || "").trim();
  if (note) { fd.append("message", note); fd.append("order_note", note); }
  if (S.coupon) fd.append("coupon_code", S.coupon.code);
  /* CRUMB-F27 — the field is `tip_amount`; crumb posted `tip`, which submitOrder
     ignores (`$request->get('tip_amount', 0)`), so every tip crumb ever quoted was
     silently dropped: order #99593 quoted $18.64 with a $1.44 tip and was written
     as $17.20 with tip_amount = 0.00. The customer's tip never reached the
     restaurant, and the quote was wrong in the other direction. */
  if (C.allowTips) fd.append("tip_amount", cartTotals().tip.toFixed(MONEY_DP));
  const sched = $("#scheduledAt");
  if (C.allowOrderSched && sched && sched.value) fd.append("scheduled_at", sched.value);
  const uw = $("#mbUseWallet");
  if (uw && uw.checked) fd.append("use_wallet", "1");
  if (S.orderType === "on-table" || S.orderType === "room_service") fd.append("table", String(S.table));
  else fd.append("phone-number", $("#custPhone").value.trim());
  if (S.orderType === "delivery") fd.append("address", S.address);

  const resetBtn = () => { btn.disabled = false; btn.querySelector(".spinner").hidden = true; poLabel.textContent = origLabel; };

  fetch(C.endpoints.submitOrder, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": C.csrf } })
    .then(r => r.json())
    .then(json => {
      if (json && json.success) {
        const T = cartTotals();
        /* CRUMB-F2 — crumb used to mint `#CRB-` + a random 4-digit number here and
           print it as the customer's order reference on the confirmation screen, the
           track header, the order list and the Reorder key. It matched no row in
           `orders`. The server's submitOrder response carries `track_url` (and
           `prep_eta`) but no order id, so the honest thing to show is the amount that
           was actually charged and a link to the real track page, where the real
           `orders.id` is rendered server-side. */
        const order = {
          id: json.order_id || null,
          type: S.orderType,
          total: T.total,
          items: S.cart.map(l => ({ id: l.id, qty: l.qty, sel: { w: l.sel.w, ex: [...l.sel.ex], g: JSON.parse(JSON.stringify(l.sel.g || {})) } })),
          placedAt: Date.now(),
          trackUrl: json.track_url || null,
          prepEta: (json.prep_eta && (json.prep_eta.label || json.prep_eta.range)) || C.prepEta || null,
        };
        S.orders.unshift(order); S.activeOrder = order; store.set("orders", S.orders);
        /* CRUMB-F19 — the tip and coupon used to survive into the next basket. */
        S.cart = []; store.set("cart", S.cart); resetCartExtras();
        syncBadge(false);
        if (json.message) { window.location.href = json.message; return; }
        if (json.whatsapp_url) { window.location.href = json.whatsapp_url; return; }
        resetBtn();
        showConfirmed(order);
      } else {
        resetBtn();
        fail((json && json.message) ? String(json.message).replace(/<[^>]+>/g, " ") : t("order_error"));
      }
    })
    .catch(() => { resetBtn(); fail(t("order_error")); });
}

function showConfirmed(order) {
  /* Show the real server order id and amount; never invent a reference. */
  $("#confirmCode").textContent = order.id ? `#${order.id}` : "";
  $("#confirmCode").hidden = !order.id;
  $("#confirmPts").textContent = `${t("total")} ${fmt(order.total)}`;
  /* CRUMB-F18 — the ETA was a hardcoded `now + 40 min`, which contradicted both the
     store's configured window and the track page it linked to. Use the store's own
     prep_eta (from the order response, else the page payload) and print nothing when
     the store has not configured one. */
  $("#confirmEta").textContent = order.prepEta ? `${t("arrives_in")} ${order.prepEta}` : "";
  /* CRUMB-F3 — the Track button now opens the REAL server-rendered track page. */
  const tb = $("#confirmTrack");
  if (tb) { tb.hidden = !order.trackUrl; if (order.trackUrl) tb.href = order.trackUrl; }
  show("confirmed");
  stack.length = 0;
  burstConfetti();
  if (C.loyaltyEnabled) loadLoyalty();
}
function burstConfetti() {
  const colors = ["#F5A623", "#8B5A2B", "#FFD9A0", "#FCFAF7", "#D9503F"];
  const el = $("#confetti"); if (!el) return;
  el.innerHTML = Array.from({ length: 30 }, (_, i) => {
    const tx = (Math.random() * 2 - 1) * 150, ty = -40 - Math.random() * 200;
    return `<i class="${Math.random() > .5 ? "round" : ""}" style="--tx:${tx}px;--ty:${ty}px;--c:${colors[i % colors.length]};--d:${Math.random() * .35}s;--rz:${Math.random() * 360}deg;--spin:${180 + Math.random() * 400}deg"></i>`;
  }).join("");
}

/* ── order history ──────────────────────────────
   CRUMB-F3 — there used to be a whole in-app "track" view here whose timeline
   ADVANCED ON A TIMER: setInterval(() => order.stage++, 8000) drove the customer
   to "Delivered" 24 seconds after load, with fabricated per-stage timestamps
   (placedAt + i*7000) and a fabricated countdown (30 - stage*9), while never once
   contacting the server. It has been deleted. Order status is only ever shown by
   the server-rendered track page at `track_url`, which prints the real orders.id,
   the real status and the real ETA. */
function orderTrackHref(o) { return o && o.trackUrl ? o.trackUrl : null; }

/* ── reviews ──────────────────────────────────────────── */
function monthsLabel(m) { if (m <= 0) return t("just_now"); if (m === 1) return t("month_ago"); return tn("months_ago", m); }
function itemReviews(i) { return [...(S.myReviews[i.id] || []), ...(i.reviewList || [])]; }
function renderReviews() {
  const i = S.currentItem ?? ITEMS[0]; if (!i) return;
  S.currentItem = i;
  const all = itemReviews(i);
  const total = all.length;
  const dist = [0, 0, 0, 0, 0];
  all.forEach(r => dist[5 - Math.max(1, Math.min(5, r.rating))]++);
  const avg = total ? (all.reduce((s, r) => s + r.rating, 0) / total) : (i.rating || 0);

  $("#rvAvg").textContent = avg ? avg.toFixed(1) : "–";
  const stEl = $("#rvStars"); if (stEl) stEl.outerHTML = starRow(avg).replace('class="rv-stars "', 'class="rv-stars" id="rvStars"');
  $("#rvCount").textContent = `(${total} ${t("reviews")})`;
  const maxN = Math.max(1, ...dist);
  $("#rvBars").innerHTML = dist.map((n, bi) => `
    <div class="rv-bar"><span>${5 - bi}</span><span class="track"><i style="width:${Math.max(4, n / maxN * 100)}%;animation-delay:${bi * 90}ms"></i></span><span>${n}</span></div>`).join("");

  $$("#rvChips [data-rvf]").forEach(b => {
    const k = b.dataset.rvf;
    b.classList.toggle("is-on", !!S.rv[k]);
    b.classList.toggle("is-dark", k === "latest" && S.rv.latest);
  });
  $("#rvFilterBtn").classList.toggle("is-on", S.rv.stars > 0);

  let list = all.slice();
  const q = S.rv.q.trim().toLowerCase();
  if (q) list = list.filter(r => (r.name + " " + r.body).toLowerCase().includes(q));
  if (S.rv.verified) list = list.filter(r => r.verified);
  if (S.rv.detailed) list = list.filter(r => (r.body || "").length > 90);
  if (S.rv.stars) list = list.filter(r => r.rating === S.rv.stars);
  if (S.rv.latest) list = [...list].sort((a, b) => a.months - b.months);

  $("#rvList").innerHTML = list.map((r, ri) => `
    <article class="rv-card" style="animation-delay:${ri * 45}ms">
      <div class="rv-head">
        <span class="rv-ava">${esc((r.name || "?").charAt(0).toUpperCase())}</span>
        <div><b>${esc(r.name)}</b><small>${monthsLabel(r.months)}</small></div>
        ${r.verified ? `<span class="rv-vrf">${t("verified")}</span>` : ""}
      </div>
      ${r.body ? `<p class="rv-body">${esc(r.body)}</p>` : ""}
      <div class="rv-foot">${starRow(r.rating)}<b>${r.rating.toFixed(1)}</b></div>
    </article>`).join("");
  $("#rvEmpty").hidden = list.length > 0;
  $("#rvEmpty").textContent = total ? t("no_match") : t("no_reviews");
}
function renderRateFilter() {
  const opts = [0, 5, 4, 3, 2, 1];
  $("#rfList").innerHTML = opts.map(v => `
    <button class="reward-row ${S.rv.stars === v ? "is-on" : ""}" data-rf="${v}" type="button">
      <i class="emoji">${v ? "★" : "✦"}</i>
      <div><b>${v ? `${v} ★` : t("all_ratings")}</b></div>
    </button>`).join("");
}

/* write review → real endpoint */
function openWriteReview({ itemId, qty = 1, price = null, order = null, rating = 0 } = {}) {
  const i = item(itemId) ?? S.currentItem ?? ITEMS[0]; if (!i) return;
  S.wr = { itemId: i.id, qty, price: price ?? unitPrice(i, { w: 0, ex: [] }), order, rating };
  $("#wrSumImg").src = THUMB(i.gallery[0]);
  $("#wrSumName").textContent = name(i);
  const cat = catLabel(i);
  $("#wrSumMeta").textContent = `${cat ? cat + " | " : ""}${t("qty")}: ${String(qty).padStart(2, "0")}`;
  $("#wrSumPrice").textContent = fmt(S.wr.price);
  $("#wrText").value = "";
  if ($("#wrErr")) $("#wrErr").hidden = true;
  renderWrStars();
  show("review-new");
}
function renderWrStars() {
  $("#wrStars").innerHTML = Array.from({ length: 5 }, (_, si) =>
    `<button class="wr-star ${si < S.wr.rating ? "is-on" : ""}" data-wr="${si + 1}" role="radio" aria-checked="${si + 1 === S.wr.rating}" aria-label="${si + 1}" type="button">${ICONS.starBig}</button>`).join("");
}
function submitReview() {
  if (!S.wr.rating) return toast(t("rate_first"));
  const body = $("#wrText").value.trim();
  const reviewerName = C.customerName || ($("#wrName")?.value || "").trim() || t("you");
  const btn = $("#wrSubmit"); btn.disabled = true; btn.querySelector(".spinner").hidden = false;

  const fd = new FormData();
  fd.append("_token", C.csrf);
  fd.append("menu_id", S.wr.itemId);
  fd.append("stars", S.wr.rating);
  fd.append("reviewer_name", reviewerName);
  if (body) fd.append("body", body);

  fetch(C.endpoints.review, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": C.csrf } })
    .then(r => r.json())
    .then(json => {
      btn.disabled = false; btn.querySelector(".spinner").hidden = true;
      if (json && json.success) {
        (S.myReviews[S.wr.itemId] = S.myReviews[S.wr.itemId] || []).unshift({
          name: reviewerName, months: 0, rating: S.wr.rating, body, verified: !!C.isLoggedIn,
        });
        if (S.wr.order) { S.wr.order.reviewed = true; store.set("orders", S.orders); }
        toast(t("review_thanks"));
        const rvG = $("#rvGoogle"); if (rvG) rvG.hidden = false;
        back();
        if (active === "reviews") renderReviews();
      } else {
        const e = $("#wrErr"); if (e) { e.hidden = false; e.textContent = (json && json.message) || t("review_fail"); }
        else toast((json && json.message) || t("review_fail"));
      }
    })
    .catch(() => { btn.disabled = false; btn.querySelector(".spinner").hidden = true; toast(t("review_fail")); });
}

/* ── location picker + mock keyboard (delivery address) ─ */
let locSearchTimer = null;
let locSearchSequence = 0;
function renderLocResults() {
  const results = $("#locResults"); if (!results) return;
  const query = ($("#locInput")?.value || "").trim();
  if (locSearchTimer) clearTimeout(locSearchTimer);
  if (!query) {
    results.innerHTML = S.savedAddrs.map((a, si) => `
      <button class="loc-row" data-address="${esc(a)}" style="animation-delay:${si * 35}ms" type="button">
        <span class="loc-ic">${ICONS.pin}</span>
        <div><b>${esc(a)}</b><small>${esc(t("saved_address"))}</small></div>
      </button>`).join("");
    return;
  }
  if (query.length < 3 || !C.endpoints.geoAutocomplete) {
    results.innerHTML = `<p class="so-empty">${esc(t("loc_min_chars"))}</p>`;
    return;
  }
  const sequence = ++locSearchSequence;
  results.innerHTML = `<p class="so-empty">${esc(t("loc_searching"))}</p>`;
  locSearchTimer = setTimeout(() => {
    fetch(C.endpoints.geoAutocomplete + "?q=" + encodeURIComponent(query))
      .then(r => r.json())
      .then(data => {
        if (sequence !== locSearchSequence) return;
        const attribution = $("#locGeoAttrib");
        if (attribution) {
          attribution.textContent = data?.attribution || "";
          attribution.hidden = !data?.attribution;
        }
        const found = data?.results || [];
        if (!found.length) {
          results.innerHTML = `<p class="so-empty">${esc(t("loc_no_results"))}</p>`;
          return;
        }
        results.innerHTML = found.map((result, index) => `
          <button class="loc-row" data-address="${esc(result.label)}" data-lat="${result.lat ?? ""}" data-lng="${result.lng ?? ""}" style="animation-delay:${index * 35}ms" type="button">
            <span class="loc-ic">${ICONS.pin}</span>
            <div><b>${esc(result.label)}</b><small>${esc(t("map_result"))}</small></div>
          </button>`).join("");
      })
      .catch(() => {
        if (sequence === locSearchSequence) {
          results.innerHTML = `<p class="so-empty">${esc(t("loc_error"))}</p>`;
        }
      });
  }, 350);
}
function pickAddress(a) {
  a = (a || "").trim(); if (!a) return;
  S.address = a; store.set("address", a);
  if (!S.savedAddrs.includes(a)) { S.savedAddrs.unshift(a); S.savedAddrs = S.savedAddrs.slice(0, 5); store.set("savedAddrs", S.savedAddrs); }
  const lt = $("#locText"); if (lt) lt.textContent = a;
  toast(t("addr_set"));
  back();
  if (active === "cart") renderCart();
  if (active === "checkout") renderCheckout();
}
function renderBanners() {
  const wrap = $("#crumbBanners"), banners = C.banners || [];
  if (!wrap || !banners.length) return;
  wrap.innerHTML = banners.map(banner => `
    <a class="crumb-banner" href="${esc(banner.link || "#")}">
      ${banner.img ? `<img src="${esc(banner.img)}" alt="" data-csp-error="remove">` : ""}
      <span><small>${esc(banner.subtitle || "")}</small><b>${esc(banner.title || C.sellerName)}</b><em>${t("order_now")}</em></span>
    </a>`).join("");
  wrap.hidden = false;
}
const KB_ROWS = ["qwertyuiop", "asdfghjkl", "zxcvbnm"];
let kbShift = false;
function buildKeyboard() {
  const kb = $("#kb"); if (!kb) return;
  const bkspSvg = '<svg viewBox="0 0 24 24"><path d="M8 5h11a1.5 1.5 0 0 1 1.5 1.5v11A1.5 1.5 0 0 1 19 19H8l-5.5-7L8 5Z"/><path d="m11.5 9.5 5 5M16.5 9.5l-5 5"/></svg>';
  const shiftSvg = '<svg viewBox="0 0 24 24"><path d="M12 4 4.5 12h4v7h7v-7h4L12 4Z"/></svg>';
  const micSvg = '<svg viewBox="0 0 24 24"><rect x="9" y="3.5" width="6" height="11" rx="3"/><path d="M5.5 11.5a6.5 6.5 0 0 0 13 0M12 18v3"/></svg>';
  kb.innerHTML =
    `<div class="kb-row">${[...KB_ROWS[0]].map(k => `<button class="kb-key" data-k="${k}" type="button">${k.toUpperCase()}</button>`).join("")}</div>` +
    `<div class="kb-row">${[...KB_ROWS[1]].map(k => `<button class="kb-key" data-k="${k}" type="button">${k.toUpperCase()}</button>`).join("")}</div>` +
    `<div class="kb-row"><button class="kb-key fn shift" data-kfn="shift" type="button">${shiftSvg}</button>${[...KB_ROWS[2]].map(k => `<button class="kb-key" data-k="${k}" type="button">${k.toUpperCase()}</button>`).join("")}<button class="kb-key fn" data-kfn="bksp" type="button">${bkspSvg}</button></div>` +
    `<div class="kb-row"><button class="kb-key mini" data-kfn="num" type="button">123</button><button class="kb-key mini" data-kfn="emoji" type="button">🙂</button><button class="kb-key space" data-k=" " type="button">space</button><button class="kb-key mini" data-kfn="mic" type="button">${micSvg}</button><button class="kb-key ret" data-kfn="ret" type="button">return</button></div>`;
}
function kbType(ch) {
  const inp = $("#locInput");
  inp.value += kbShift ? ch.toUpperCase() : ch;
  if (kbShift) { kbShift = false; $(".kb-key.shift")?.classList.remove("is-on"); }
}

/* ── explore / map (branch picker) ────────────────────── */
function renderExplore(drop = false) {
  const cards = $("#branchCards"); if (!cards) return;
  const list = BRANCHES;
  cards.innerHTML = list.map((b, bi) => `
    <button class="bcard ${b.key === S.branch ? "is-on" : ""}" data-pick="${b.key}" style="animation-delay:${bi * 60}ms" type="button">
      <span class="bcard-top"><b>${esc(b.name)}</b></span>
      ${b.addr ? `<span class="bcard-addr">${ICONS.pin} ${esc(b.addr)}</span>` : ""}
      <span class="bcard-pick">${b.key === S.branch ? "✓ " + t("selected_branch") : t("pick_branch")}</span>
    </button>`).join("");
}
/* Keep the header branch selector's label (#locText) in step with the active
   branch — server renders the first branch; a stored pick overrides it. No-op
   unless locBtn is in branch mode. */
function syncBranchLabel() {
  const lb = $("#locBtn"); if (!lb || !lb.dataset.branchOpen) return;
  const lt = $("#locText"); if (lt) lt.textContent = branch(S.branch).name;
}
function pickBranch(key) {
  S.branch = key; store.set("branch", key);
  renderExplore(false);
  syncBranchLabel();
  toast(`${branch(key).name} ✓`);
  if ($("#otFields")) renderOtFields();
}

/* CRUMB-F5/F16 — the hard-coded "story" (a 40%-off promotion no store had created,
   with a like counter seeded at 2.4k that incremented locally) has been removed
   along with its progress timer and heart-spawner. Real owner-published stories are
   handled by the #reelRail / #reelViewer block further down, which is fed by the
   /stories endpoint and renders nothing when the store has published nothing. */

/* ── wishlist ─────────────────────────────────────────── */
function renderWishlist() {
  const favs = ITEMS.filter(i => S.favs.has(String(i.id)));
  $("#wishGrid").innerHTML = favs.map(fcardHTML).join("");
  $("#wishEmpty").hidden = favs.length > 0;
}

/* CRUMB-F4 — the fake customer-service chatbot lived here. chatMsg()/botReply()/
   sendChat() rendered a convincing conversation ("A baker will reply shortly") and
   posted to no endpoint whatsoever, so a customer reporting an allergy believed the
   restaurant had been told. Deleted; the owner-gated real agent chat widget and the
   WhatsApp line on the Profile tab are the only messaging surfaces now. */

/* ── profile / orders / loyalty ───────────────────────── */
function renderOrders() {
  const L = $("#ordersList"); if (!L) return;
  $("#ordersEmpty").hidden = S.orders.length > 0;
  /* CRUMB-F2 — no invented reference and no invented status word: the amount that
     was charged, when it was placed, and a link to the REAL track page. */
  L.innerHTML = S.orders.slice(0, 5).map((o, oi) => {
    const href = orderTrackHref(o);
    const when = new Date(o.placedAt).toLocaleDateString([], { day: "2-digit", month: "short" });
    return `
    <div class="order-row">
      ${ICONS.bag}
      <div><b>${o.id ? `#${esc(o.id)} · ` : ""}${fmt(o.total)}</b>
      <small>${when} · ${o.items.length} item(s) · ${t("ot_" + (o.type === "on-table" ? "table" : o.type))}</small></div>
      ${href ? `<a class="order-status" href="${esc(href)}">${t("track_order")}</a>` : ""}
      <button class="minibtn brown" data-reorder="${oi}" type="button">${t("reorder")}</button>
    </div>`;
  }).join("");
}
on("lookupBtn", "click", () => {
  const input = $("#lookupInput");
  const value = (input?.value || "").trim();
  if (!value || !C.endpoints.orderLookup) return;
  const list = $("#ordersList"), empty = $("#ordersEmpty");
  list.innerHTML = "…";
  if (empty) empty.hidden = true;
  fetch(C.endpoints.orderLookup, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": C.csrf },
    body: JSON.stringify({ identifier: value }),
  }).then(r => r.json()).then(data => {
    const orders = data?.orders || [];
    if (!data?.success || !orders.length) {
      list.innerHTML = "";
      if (empty) {
        empty.textContent = data?.message || "No orders found for that phone/email.";
        empty.hidden = false;
      }
      return;
    }
    list.innerHTML = orders.map(o => `
      <a class="order-row link" href="${esc(o.track_url)}">
        ${ICONS.bag}
        <div><b>#${esc(o.id)} · ${esc(o.total)}</b><small>${esc(o.date)}</small></div>
        <span class="order-status">${esc(o.status)}</span>
      </a>`).join("");
  }).catch(() => {
    list.innerHTML = "";
    if (empty) {
      empty.textContent = "Network error. Please try again.";
      empty.hidden = false;
    }
  });
});
function reorder(idx) {
  const o = S.orders[+idx]; if (!o) return;
  for (const l of o.items) {
    const sel = { w: l.sel.w, ex: [...l.sel.ex], g: JSON.parse(JSON.stringify(l.sel.g || {})) };
    const key = l.id + "|" + JSON.stringify(sel);
    const line = S.cart.find(x => x.key === key);
    if (line) line.qty += l.qty; else S.cart.push({ key, id: l.id, qty: l.qty, sel, note: "" });
  }
  store.set("cart", S.cart); syncBadge(true); toast(t("added"), "cart");
}

/* loyalty (real balance + redeem endpoints) */
let loyaltyBalance = 0, loyaltyRewards = [];
function loadLoyalty() {
  if (!C.endpoints.loyaltyBalance) return;
  fetch(C.endpoints.loyaltyBalance, { headers: { "X-Requested-With": "XMLHttpRequest" } })
    .then(r => r.json()).then(j => {
      loyaltyBalance = j.balance ?? j.points ?? 0;
      loyaltyRewards = j.rewards || [];
      const pv = $("#pointsVal"); if (pv) pv.textContent = loyaltyBalance;
    }).catch(() => {});
}
function renderLoyalty() {
  const lp = $("#loyPts"); if (lp) lp.textContent = loyaltyBalance;
  const list = $("#rewardList"); if (!list) return;
  if (!loyaltyRewards.length) { list.innerHTML = `<p class="sheet-sub" style="text-align:center">${t("points")}: ${loyaltyBalance}</p>`; return; }
  list.innerHTML = loyaltyRewards.map(r => `
    <button class="reward-row" data-redeem="${r.id}" type="button">
      <i class="emoji">🎁</i>
      <div><b>${esc(r.description || r.name || "")}</b><small>${r.threshold || r.cost || ""} ${t("points")}</small></div>
      <span class="minibtn ${loyaltyBalance >= (r.threshold || r.cost || 0) ? "brown" : ""}">${t("redeem")}</span>
    </button>`).join("");
}
function redeemReward(id) {
  if (!C.endpoints.loyaltyRedeem) return;
  const fd = new FormData(); fd.append("_token", C.csrf); if (id) fd.append("reward_id", id);
  fetch(C.endpoints.loyaltyRedeem, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": C.csrf } })
    .then(r => r.json()).then(j => {
      if (j && j.success) { toast(t("redeemed")); const ok = $("#loyaltyOk"); if (ok) ok.hidden = false; loadLoyalty(); }
      else toast((j && j.message) || t("need_pts"));
    }).catch(() => toast(t("need_pts")));
}

/* ── currency switch ──────────────────────────────────── */
function applyCurrency(sign, before, rate, code) {
  S.curSign = sign; S.curBefore = before; S.curRate = rate; S.curCode = code || C.baseCurrency;
  renderHome(); if (active === "cart") renderCart();
  if (active === "checkout") renderCheckout();
  if (S.currentItem && active === "product") setProduct(S.currentItem.id);
  renderOrders();
}

/* ── delegated events ─────────────────────────────────── */
document.addEventListener("click", e => {
  const T2 = e.target, closest = s => T2.closest(s);

  const fav = closest("[data-fav]");
  if (fav) { e.stopPropagation(); e.preventDefault(); const id = String(fav.dataset.fav);
    S.favs.has(id) ? S.favs.delete(id) : S.favs.add(id);
    store.set("favs", [...S.favs]); fav.classList.toggle("is-on");
    if (active === "wishlist") renderWishlist(); return; }
  const add = closest("[data-add]");
  if (add) { e.stopPropagation(); e.preventDefault();
    const ai = item(add.dataset.add);
    /* items with modifier groups need the modal (required/choice) — open it */
    if (ai && (ai.optionGroups || []).length) { openProduct(add.dataset.add); return; }
    addToCart(add.dataset.add, { w: 0, ex: [], g: {} }, 1, add); return; }
  /* R7 — same-page ?item= links: grid card anchors + share/CTA links. Checked
     AFTER the controls above so a click on a card's ＋/heart (inside the card
     anchor, each preventDefaults it) wins. Same-pathname item links open in place;
     real external links full-load. Boot handles a cold ?item= / new-tab load. */
  const itemLink = closest('a[href*="item="]');
  if (itemLink) {
    try {
      const u = new URL(itemLink.getAttribute("href"), location.href);
      const iid = u.searchParams.get("item");
      if (iid && u.pathname === location.pathname && item(iid)) {
        e.preventDefault(); openProduct(iid); return;
      }
    } catch { /* malformed href: fall through to normal handling */ }
  }

  const open = closest("[data-open]");
  if (open) { openProduct(open.dataset.open); return; }
  const go = closest("[data-go]");
  if (go) { show(go.dataset.go); return; }
  if (closest("[data-back]")) { back(); return; }

  const tab = closest(".tab");
  if (tab) {
    const _nt = tab.dataset.tab;
    // Menu jumps to the grid on home; search opens the overlay. Neither is a view.
    if (_nt === "search") { openSearch(); return; }
    if (_nt === "menu") { if (active !== "home") show("home"); const g = document.querySelector('[data-mb-grid-lead]') || document.getElementById("featGrid"); if (g) g.scrollIntoView({ behavior: "smooth", block: "start" }); return; }
    show(_nt, { push: false }); return;
  }
  const cat = closest("[data-cat]");
  if (cat) { S.cat = cat.dataset.cat; S.sub = "root"; syncSubChips(); renderHome(); return; }
  /* secondary sub-category chip narrows within the active parent */
  const subchip = closest("[data-sub]");
  if (subchip) { S.sub = subchip.dataset.sub;
    const grp = subchip.closest(".subgroup");
    if (grp) $$(".subchip", grp).forEach(c => c.classList.toggle("is-on", c === subchip));
    renderFeatured(); return; }
  /* dietary + spice filter chips (search overlay) */
  const diet = closest("#dietRow [data-diet]");
  if (diet) { const k = diet.dataset.diet; S.filters.diets[k] = !S.filters.diets[k];
    diet.classList.toggle("is-on"); renderSearch(); renderFeatured(); return; }
  const spice = closest("#dietRow [data-spice]");
  if (spice) { S.filters.spice = +spice.dataset.spice;
    $$("#dietRow [data-spice]").forEach(b => b.classList.toggle("is-on", b === spice));
    renderSearch(); renderFeatured(); return; }
  const soQ = closest("#soRecent [data-q]");
  if (soQ) { S.query = soQ.dataset.q; $("#soInput").value = S.query; renderSearch(); return; }

  /* product */
  /* quick-rate — inline stars open the shared review write-sheet preselected to
     the tapped rating (scoped to #rateStars so it never catches the currency
     [data-rate] chips, which live under #curSeg with their own handler). */
  const qr = closest("#rateStars [data-rate]");
  if (qr) {
    const qn = Number(qr.dataset.rate);
    $$("#rateStars button").forEach((b, bi) => b.classList.toggle("is-on", bi < qn));
    if (window.mbItemReviews) window.mbItemReviews.open(qn);
    return;
  }
  const th = closest("[data-thumb]");
  if (th && S.currentItem) { S.pImg = +th.dataset.thumb; setHero(S.currentItem, S.pImg);
    $$("#pThumbs .p-thumb").forEach(x => x.classList.toggle("is-on", x === th)); return; }
  const w2 = closest("[data-w]");
  if (w2 && S.currentItem) {
    S.pSel.w = +w2.dataset.w;
    renderWeights(S.currentItem);
    renderExtras(S.currentItem);
    updateProductCta();
    return;
  }
  const gopt = closest(".opt[data-group]");
  if (gopt && S.currentItem) {
    const gid = gopt.dataset.group, oi = +gopt.dataset.opt;
    const multi = gopt.dataset.multi === "1";
    const max = gopt.dataset.max === "" ? Infinity : +gopt.dataset.max;
    const opt = { i: oi, price: +gopt.dataset.price || 0, label: gopt.dataset.label };
    let cur = S.pSel.g[gid] || [];
    if (multi) {
      const idx = cur.findIndex(s => s.i === oi);
      if (idx !== -1) cur.splice(idx, 1);
      else if (cur.length < max) cur.push(opt);
      else { toast(`${t("max_sel")} ${max}`); return; }
    } else {
      cur = (cur.length && cur[0].i === oi) ? [] : [opt];
    }
    S.pSel.g[gid] = cur;
    const grp = gopt.closest("[data-groupwrap]");
    if (grp) $$(".opt", grp).forEach(o => o.classList.toggle("is-on", cur.some(s => s.i === +o.dataset.opt)));
    /* Repaint extras from the canonical selection state. Some product option
       groups share the generic `.opt` class, so a group repaint must never
       leave a selected extra looking deselected. */
    renderExtras(S.currentItem);
    updateProductCta(); return;
  }
  const ex = closest("[data-ex]");
  if (ex && S.currentItem) { const xi = +ex.dataset.ex; const set = new Set(S.pSel.ex);
    set.has(xi) ? set.delete(xi) : set.add(xi); S.pSel.ex = [...set]; ex.classList.toggle("is-on"); updateProductCta(); return; }
  const q = closest("#pQty [data-q]");
  if (q) { S.pQty = Math.max(1, S.pQty + +q.dataset.q); updateProductCta(); return; }

  /* cart */
  const cq = closest("[data-cq]");
  if (cq) { const key = cq.closest(".cart-item").dataset.key; const line = S.cart.find(l => l.key === key);
    if (line.qty + +cq.dataset.cq <= 0) { openRemoveSheet(key); return; }
    line.qty += +cq.dataset.cq; store.set("cart", S.cart); syncBadge(false); renderCart(); return; }
  const noteBtn = closest("[data-note]");
  if (noteBtn) { const box = noteBtn.closest(".cart-item").querySelector(".ci-note");
    box.hidden = !box.hidden; if (!box.hidden) box.querySelector("input").focus(); return; }
  const rm = closest("[data-rm]");
  if (rm) { openRemoveSheet(rm.closest(".cart-item").dataset.key); return; }

  const ot = closest("[data-ot]");
  if (ot) { S.orderType = ot.dataset.ot; $$("[data-ot]").forEach(b => b.classList.toggle("is-on", b.dataset.ot === S.orderType));
    renderOtFields(); renderTotals(); return; }
  if (closest("[data-goloc]")) { show("location"); return; }
  if (closest("[data-gobranch]")) { if (BRANCHES.length > 1) show("explore", { push: false }); return; }

  const pm = closest("[data-pm]");
  if (pm) { S.pay = pm.dataset.pm; $$(".pm").forEach(b => b.classList.toggle("is-on", b === pm));
    $("#cardForm").hidden = S.pay !== "pay_online"; return; }
  const tip = closest("#tipRow [data-tip]");
  if (tip) { S.tip = +tip.dataset.tip; $$("#tipRow button").forEach(b => b.classList.toggle("is-on", b === tip)); renderCheckout(); return; }

  /* reviews */
  const rvf = closest("[data-rvf]");
  if (rvf) { const k = rvf.dataset.rvf; S.rv[k] = !S.rv[k]; renderReviews(); return; }
  const rf = closest("[data-rf]");
  if (rf) { S.rv.stars = +rf.dataset.rf; sheet("ratefilterSheet", false); renderReviews(); return; }
  const wrs = closest("[data-wr]");
  if (wrs) { S.wr.rating = +wrs.dataset.wr; renderWrStars(); return; }
  /* map / branch */
  const pick = closest("[data-pick]");
  if (pick) { pickBranch(pick.dataset.pick); return; }

  /* location saved rows */
  const saved = closest("[data-address]");
  if (saved) { pickAddress(saved.dataset.address); return; }

  /* keyboard */
  const key = closest("[data-k]");
  if (key) { kbType(key.dataset.k); return; }
  const kfn = closest("[data-kfn]");
  if (kfn) { const fn = kfn.dataset.kfn;
    if (fn === "bksp") { const inp = $("#locInput"); inp.value = inp.value.slice(0, -1); }
    else if (fn === "shift") { kbShift = !kbShift; kfn.classList.toggle("is-on", kbShift); }
    else if (fn === "ret") { pickAddress($("#locInput").value); }
    return; }

  /* loyalty / orders */
  const rd = closest("[data-redeem]");
  if (rd) { redeemReward(rd.dataset.redeem); return; }
  const ro = closest("[data-reorder]");
  if (ro) { reorder(ro.dataset.reorder); return; }
  /* chat chips */
});

document.addEventListener("input", e => {
  if (e.target.matches("[data-notein]")) {
    const key = e.target.closest(".cart-item").dataset.key;
    const line = S.cart.find(l => l.key === key);
    if (line) { line.note = e.target.value; store.set("cart", S.cart); }
    e.target.closest(".cart-item").querySelector("[data-note]").classList.toggle("has-note", !!e.target.value);
  }
});
document.addEventListener("change", e => {
  if (e.target.id === "tableSel") S.table = +e.target.value;
  if (e.target.id === "mbUseWallet") { S.useWallet = e.target.checked; if (active === "checkout") renderCheckout(); }
});

/* ── F2 · brand / follow row (ported from crave) ─────────
   Guest tap opens the login/register sheet and records an intent so a
   follow interrupted by sign-in still lands once the page reloads. */
(function () {
  const btn = $("#crumbFollowBtn");
  if (!btn || !C.endpoints.follow) return;
  const FOLLOW_INTENT = "crumb_follow_intent";

  function followHeaders() {
    const h = { "Content-Type": "application/json", "Accept": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": C.csrf };
    if (C.customerToken) h["Authorization"] = "Bearer " + C.customerToken;
    return h;
  }
  function applyFollowState(d) {
    if (!d || typeof d.following === "undefined") return false;
    btn.dataset.following = d.following ? "1" : "0";
    btn.classList.toggle("is-on", d.following);
    const c = $("#crumbFollowerCount"); if (c && typeof d.follower_count !== "undefined") c.textContent = d.follower_count;
    return true;
  }
  function doFollow(unfollow) {
    const prev = btn.dataset.following === "1";
    btn.dataset.following = unfollow ? "0" : "1";
    btn.classList.toggle("is-on", !unfollow);
    fetch(C.endpoints.follow, { method: unfollow ? "DELETE" : "POST", headers: followHeaders(), body: "{}" })
      .then(r => r.json()).then(d => {
        if (!d || typeof d.following === "undefined") throw new Error("bad");
        applyFollowState(d);
      }).catch(() => {
        btn.dataset.following = prev ? "1" : "0";
        btn.classList.toggle("is-on", prev);
        toast(t("order_error"));
      });
  }
  btn.addEventListener("click", () => {
    if (!C.customerToken) {
      try { sessionStorage.setItem(FOLLOW_INTENT, "1"); } catch {}
      sheet("crumbAuthSheet", true, { blur: true });
      return;
    }
    doFollow(btn.dataset.following === "1");
  });

  /* replay a pending follow intent once the visitor is signed in (post-reload) */
  (function () {
    let pending = false;
    try { pending = sessionStorage.getItem(FOLLOW_INTENT) === "1"; } catch {}
    if (!pending || !C.customerToken) return;
    try { sessionStorage.removeItem(FOLLOW_INTENT); } catch {}
    if (btn.dataset.following === "1") return;
    fetch(C.endpoints.follow, { method: "POST", headers: followHeaders(), body: "{}" })
      .then(r => r.json()).then(applyFollowState).catch(() => {});
  })();
})();

/* ── single-purpose wiring ────────────────────────────── */
on("splashSkip", "click", () => $("#splash").classList.add("is-done"));
on("locBtn", "click", () => {
  /* Branch mode (data-branch-open, multi-branch stores) opens the branch picker,
     crave-style; otherwise it stays the delivery-address field. */
  const lb = $("#locBtn");
  if (lb && lb.dataset.branchOpen) { if (BRANCHES.length > 1) show("explore", { push: false }); return; }
  if (C.allowDelivery) show("location");
});
on("crumbAuthBtn", "click", () => sheet("crumbAuthSheet", true, { blur: true }));
$("#crumbAuthTabs")?.addEventListener("click", e => {
  const tab = e.target.closest("[data-crumb-auth-tab]"); if (!tab) return;
  $$("#crumbAuthTabs button").forEach(b => b.classList.toggle("is-on", b === tab));
  $$("[data-crumb-auth-form]").forEach(form => { form.hidden = form.dataset.crumbAuthForm !== tab.dataset.crumbAuthTab; });
  const err = $("#crumbAuthError"); if (err) err.hidden = true;
});
function crumbAuthPost(url, body) {
  const err = $("#crumbAuthError"); if (err) err.hidden = true;
  return fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": C.csrf, "X-Requested-With": "XMLHttpRequest" },
    body: JSON.stringify(body),
  }).then(r => r.json()).then(json => {
    if (json && json.success) { location.reload(); return; }
    if (err) { err.textContent = (json && json.message) || "Unable to continue."; err.hidden = false; }
  }).catch(() => { if (err) { err.textContent = "Network error. Please try again."; err.hidden = false; } });
}
on("crumbLoginSubmit", "click", () => crumbAuthPost(C.endpoints.customerLogin, {
  store_id: C.storeId,
  email: $("#crumbLoginEmail").value.trim(),
  password: $("#crumbLoginPassword").value,
}));
on("crumbRegisterSubmit", "click", () => crumbAuthPost(C.endpoints.customerRegister, {
  store_id: C.storeId,
  name: $("#crumbRegisterName").value.trim(),
  email: $("#crumbRegisterEmail").value.trim(),
  phone: $("#crumbRegisterPhone").value.trim(),
  password: $("#crumbRegisterPassword").value,
  password_confirmation: $("#crumbRegisterPasswordConfirm").value,
}));
/* A3 · phone-OTP login — send code, then verify the 6-box code under `code` */
on("crumbOtpSend", "click", () => {
  const phone = $("#crumbOtpPhone").value.trim(); if (!phone) return;
  const err = $("#crumbAuthError"); if (err) err.hidden = true;
  fetch(C.endpoints.otpRequest, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": C.csrf, "X-Requested-With": "XMLHttpRequest" },
    body: JSON.stringify({ store_id: C.storeId, phone }),
  }).then(r => r.json()).then(res => {
    if (res && res.success) { $("#crumbOtpStep1").hidden = true; $("#crumbOtpStep2").hidden = false; const f = $("#crumbOtpBoxes input"); if (f) f.focus(); }
    else if (err) { err.textContent = (res && res.message) || "Unable to continue."; err.hidden = false; }
  }).catch(() => { if (err) { err.textContent = "Network error. Please try again."; err.hidden = false; } });
});
on("crumbOtpVerify", "click", () => {
  const code = $$("#crumbOtpBoxes input").map(i => i.value).join("");
  crumbAuthPost(C.endpoints.otpVerifyLogin, { store_id: C.storeId, phone: $("#crumbOtpPhone").value.trim(), code: code });
});
(function () {
  const arr = $$("#crumbOtpBoxes input");
  arr.forEach((inp, i) => {
    inp.addEventListener("input", () => { inp.value = inp.value.replace(/\D/g, "").slice(0, 1); if (inp.value && arr[i + 1]) arr[i + 1].focus(); });
    inp.addEventListener("keydown", e => { if (e.key === "Backspace" && !inp.value && arr[i - 1]) arr[i - 1].focus(); });
    inp.addEventListener("paste", e => {
      e.preventDefault();
      const d = ((e.clipboardData || window.clipboardData).getData("text") || "").replace(/\D/g, "").slice(0, arr.length);
      if (!d) return;
      for (let j = 0; j < arr.length; j++) arr[j].value = d.charAt(j) || "";
      (arr[Math.min(d.length, arr.length) - 1] || arr[0]).focus();
    });
  });
})();
on("cartBtn", "click", () => show("cart"));
/* #notifBtn is now #mbNotifBell, driven by the shared mb-notify.js bell engine
   (real notification center) — the old always-"nothing" toast handler is gone. */
on("homeSearchBtn", "click", openSearch);
on("homeFilterBtn", "click", openSearch);
on("soClose", "click", closeSearch);
on("soClear", "click", () => { S.query = ""; $("#soInput").value = ""; renderSearch(); $("#soInput").focus(); });
on("soInput", "input", e => { S.query = e.target.value; renderSearch(); });
on("catSeeAll", "click", () => { S.cat = "all"; S.featAll = true; renderHome(); });
on("featSeeAll", "click", () => { S.featAll = !S.featAll; renderFeatured(); });

on("pFav", "click", () => { const id = String(S.currentItem.id);
  S.favs.has(id) ? S.favs.delete(id) : S.favs.add(id); store.set("favs", [...S.favs]); $("#pFav").classList.toggle("is-on"); });
on("pShare", "click", () => { if (navigator.share) navigator.share({ title: name(S.currentItem), url: location.href }).catch(() => {}); else toast(t("share_stub")); });
on("pReadMore", "click", () => { S.descOpen = !S.descOpen;
  $("#pDesc").classList.toggle("clamp", !S.descOpen); $("#pReadMore").textContent = S.descOpen ? t("read_less") : t("read_more"); });
on("addToCart", "click", e => { addToCart(S.currentItem.id, { w: S.pSel.w, ex: [...S.pSel.ex], g: JSON.parse(JSON.stringify(S.pSel.g)) }, S.pQty, null);
  const b = e.currentTarget; b.classList.remove("pop"); void b.offsetWidth; b.classList.add("pop"); });

on("writeReviewBtn", "click", () => openWriteReview({ itemId: (S.currentItem ?? ITEMS[0]).id }));
on("rvSearch", "input", e => { S.rv.q = e.target.value; renderReviews(); });
on("rvFilterBtn", "click", () => { renderRateFilter(); sheet("ratefilterSheet"); });
on("wrCancel", "click", back);
on("wrSubmit", "click", submitReview);

on("locInput", "input", renderLocResults);
on("locClear", "click", () => { $("#locInput").value = ""; renderLocResults(); });
$("#locInput")?.addEventListener("keydown", e => {
  if (e.key === "Enter") { e.preventDefault(); pickAddress(e.currentTarget.value); }
});
on("useCurrent", "click", e => {
  if (!navigator.geolocation || !C.endpoints.geoReverse) return toast(C.i18n.loc_unavailable || "Location unavailable");
  const button = e.currentTarget;
  button.disabled = true;
  navigator.geolocation.getCurrentPosition(position => {
    const lat = position.coords.latitude, lng = position.coords.longitude;
    fetch(C.endpoints.geoReverse + "?lat=" + lat + "&lng=" + lng)
      .then(r => r.json())
      .then(data => {
        button.disabled = false;
        if (data?.result?.label) pickAddress(data.result.label);
        else toast(C.i18n.no_address || "No address found");
      })
      .catch(() => { button.disabled = false; toast(C.i18n.loc_unavailable || "Location unavailable"); });
  }, () => {
    button.disabled = false;
    toast(C.i18n.loc_denied || "Location permission denied");
  }, { enableHighAccuracy: true, timeout: 8000 });
});


/* CRUMB-F26 — removing ONE line opened a confirm sheet; emptying the whole cart was
   a single unguarded tap. Two-step confirm in the theme's own language.
   CRUMB-F19 — the coupon and the tip used to survive the clear and were silently
   re-applied to the next basket. */
let clearArmed = 0;
on("clearCart", "click", e => {
  const btn = e.currentTarget;
  if (Date.now() > clearArmed) {
    clearArmed = Date.now() + 3500;
    btn.dataset.label = btn.dataset.label || btn.textContent;
    btn.textContent = t("clear_cart_confirm");
    setTimeout(() => { if (Date.now() > clearArmed - 100) { btn.textContent = btn.dataset.label; clearArmed = 0; } }, 3600);
    return;
  }
  clearArmed = 0;
  btn.textContent = btn.dataset.label || btn.textContent;
  resetCartExtras();
  S.cart = []; store.set("cart", S.cart); syncBadge(false); renderCart();
});
/* clears the coupon and tip in STATE and in the DOM */
function resetCartExtras() {
  S.coupon = null; S.tip = 0;
  const ci = $("#couponInput"); if (ci) ci.value = "";
  const cm = $("#couponMsg"); if (cm) { cm.hidden = true; cm.textContent = ""; cm.classList.remove("ok", "bad"); }
  $$("#tipRow button").forEach(b => b.classList.toggle("is-on", b.dataset.tip === "0"));
}

/* CRUMB-F5 — the real coupon strip copies the store's own code */
on("offerStrip", "click", e => {
  const code = e.currentTarget.dataset.copyCoupon || "";
  const ci = $("#couponInput"); if (ci) ci.value = code;
  try { navigator.clipboard?.writeText(code); } catch (err) {}
  toast(t("coupon_copied"));
});

/* CRUMB-F7 — appearance (Auto / Light / Dark), persisted and applied before paint
   by the inline bootstrap in _brand.blade.php */
const APPEARANCE_KEY = "appearance";
function applyAppearance(pref) {
  const dark = pref === "dark" || (pref !== "light" && matchMedia("(prefers-color-scheme: dark)").matches);
  document.documentElement.setAttribute("data-crumb-theme", dark ? "dark" : "light");
  $$("#appearanceSeg button").forEach(b => b.classList.toggle("is-on", b.dataset.appearance === pref));
}
$("#appearanceSeg")?.addEventListener("click", e => {
  const b = e.target.closest("[data-appearance]"); if (!b) return;
  store.set(APPEARANCE_KEY, b.dataset.appearance);
  applyAppearance(b.dataset.appearance);
});
matchMedia("(prefers-color-scheme: dark)").addEventListener("change", () => {
  if (store.get(APPEARANCE_KEY, "auto") === "auto") applyAppearance("auto");
});
on("goCheckout", "click", () => show("checkout"));
on("placeOrder", "click", placeOrder);
on("backToMenu", "click", () => show("home", { push: false }));
on("couponApply", "click", () => {
  const code = $("#couponInput").value.trim();
  const msg = $("#couponMsg"); if (!code) return;
  const fd = couponForm(code);
  fetch(C.endpoints.coupon, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": C.csrf } })
    .then(r => r.json()).then(j => {
      msg.hidden = false;
      if (j && j.success) { S.coupon = { code: j.label || code.toUpperCase(), discount: j.discount || 0, sig: couponSig() };
        msg.className = "coupon-msg"; msg.textContent = `✓ ${t("coupon_ok")} ${fmt(j.discount || 0)}`;
        document.querySelector(".coupon-field")?.classList.add("is-applied"); }
      else { S.coupon = null; msg.className = "coupon-msg err"; msg.textContent = `✕ ${(j && j.message) ? String(j.message).replace(/<[^>]+>/g, " ") : t("coupon_bad")}`;
        document.querySelector(".coupon-field")?.classList.remove("is-applied"); }
      renderTotals();
    }).catch(() => { msg.hidden = false; msg.className = "coupon-msg err"; msg.textContent = `✕ ${t("coupon_bad")}`; });
});
/* CRUMB-F28 — both of these lied.
   "Call waiter" fired its success toast SYNCHRONOUSLY from the click handler and
   swallowed every error with `.catch(() => {})`, so a 500, a validation rejection or
   an offline phone all told the diner "A waiter is on the way".
   "Request bill" was worse: it posted nothing at all — a toast and no server contact
   whatsoever. `qrtest_waiter_call` held **0 rows** for this store.
   Both now post the type the controller validates (`call_waiter` / `request_bill`) and
   only confirm on `json.success`. */
function callWaiter(type, okKey, btn) {
  const fd = new FormData();
  fd.append("_token", C.csrf);
  fd.append("table", String(S.table));
  fd.append("type", type);
  if (btn) btn.disabled = true;
  fetch(C.endpoints.waiter, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": C.csrf } })
    .then(r => r.ok ? r.json() : Promise.reject())
    .then(json => {
      if (btn) btn.disabled = false;
      if (json && json.success) toast(t(okKey));
      else toast(t("order_error"));
    })
    .catch(() => { if (btn) btn.disabled = false; toast(t("order_error")); });
}
on("callWaiter", "click", e => callWaiter("call_waiter", "waiter_ok", e.currentTarget));
on("requestBill", "click", e => callWaiter("request_bill", "bill_ok", e.currentTarget));

/* loyalty + reservations */
on("redeemBtn", "click", () => { renderLoyalty(); sheet("loyaltySheet"); });
on("loyaltyRedeem", "click", () => redeemReward(null));
on("reserveBtn", "click", () => sheet("reserveSheet"));
on("waitlistBtn", "click", () => sheet("waitlistSheet"));
on("rsGm", "click", () => { const o = $("#rsGuestsVal"); const v = Math.max(1, (+o.textContent || 2) - 1); o.textContent = v; $("#rsPartySize").value = v; });
on("rsGp", "click", () => { const o = $("#rsGuestsVal"); const v = Math.min(20, (+o.textContent || 2) + 1); o.textContent = v; $("#rsPartySize").value = v; });

/* chat */

/* currency + language segments */
$("#curSeg")?.addEventListener("click", e => {
  const b = e.target.closest("[data-cur]"); if (!b) return;
  $$("#curSeg button").forEach(x => x.classList.toggle("is-on", x === b));
  const code = b.dataset.cur, rate = +b.dataset.rate || 1;
  const isBase = code === C.baseCurrency;
  applyCurrency(isBase ? CUR_BASE.sign : code, isBase ? CUR_BASE.before : false, rate, code);
  if (window.mbCurrency) mbCurrency.save(code, rate);   // persist store-scoped (shared helper)
});
const setLangCookie = (k, v) => { document.cookie = k + "=" + encodeURIComponent(v) + ";path=/;max-age=31536000"; };
function chooseLanguage(e) {
  const b = e.target.closest("[data-lang-code]"); if (!b) return;
  const code = b.dataset.langCode, langName = b.dataset.langName || b.textContent.trim();
  /* The cookies carry the choice in session mode; the path swap only applies in
     prefix mode (/en/slug). Inserting a prefix where none exists 404s. */
  setLangCookie("Quick_lang", langName);
  setLangCookie("Quick_user_lang", langName);
  setLangCookie("Quick_user_lang_code", code);
  const u = new URL(location.href), parts = u.pathname.split("/");
  if (parts.length > 2 && /^[a-z]{2,3}(?:-[a-z]{2})?$/i.test(parts[1])) parts[1] = code;
  u.pathname = parts.join("/");
  u.searchParams.delete("lang");
  location.assign(u.toString());
}
$("#langSeg")?.addEventListener("click", chooseLanguage);
$("#homeLangSeg")?.addEventListener("click", chooseLanguage);
on("homeLangBtn", "click", () => sheet("homeLangSheet", true, { blur: true }));
on("cvCateringBtn", "click", () => sheet("cvCateringSheet"));
on("homeAuthBtn", "click", () => {
  if (C.customer) show("profile");
  else sheet("crumbAuthSheet", true, { blur: true });
});

/* tabbar hide on scroll (roots) */
let lastY = 0;
$$(".view-scroll").forEach(sc => sc.addEventListener("scroll", () => {
  if (!ROOTS.includes(active)) return;
  const y = sc.scrollTop;
  $("#tabbar").classList.toggle("is-hidden", y > lastY + 4 && y > 130);
  if (y < lastY - 4 || y < 130) $("#tabbar").classList.remove("is-hidden");
  lastY = y;
}, { passive: true }));

/* ── stories rail + full-screen viewer ────────────────── */
(function () {
  const rail = $("#reelRail");
  if (!rail || !C.endpoints || !C.endpoints.stories) return;

  const DUR = 5;                       // seconds per story
  let stories = [], idx = 0, timer = null, elapsed = 0;

  (window.mbStoriesGet?window.mbStoriesGet(C.endpoints.stories):fetch(C.endpoints.stories).then(function(r){return r.json();})).then(d => {
    stories = (d && d.data) || [];
    if (!stories.length) { rail.hidden = true; return; }
    rail.innerHTML = stories.map((s, i) => `
      <button class="reel-bubble" data-reel="${i}" type="button">
        <span class="reel-ring"><img src="${esc(s.media_url)}" alt="" data-csp-error="fallback" data-csp-fallback="${esc(C.defaultImg)}"></span>
        <small>${esc(s.caption ? String(s.caption).slice(0, 14) : SELLER.name)}</small>
      </button>`).join("");
    rail.hidden = false;
  }).catch(() => { rail.hidden = true; });

  const viewer = $("#reelViewer"), media = $("#reelMedia"),
        cap = $("#reelCaption"), cta = $("#reelCta"), prog = $("#reelProgress");
  if (!viewer) return;

  const buildProgress = () => { prog.innerHTML = stories.map(() => '<span class="reel-seg"><i></i></span>').join(""); };
  function paintSeg() {
    $$("#reelProgress .reel-seg i").forEach((bar, i) => {
      bar.style.width = i < idx ? "100%" : (i === idx ? (elapsed / DUR * 100) + "%" : "0%");
    });
  }
  function render() {
    const s = stories[idx]; if (!s) return;
    media.src = s.media_url;
    media.style.opacity = "";
    cap.textContent = s.caption || "";
    if (s.cta_url && !(window.mbStoryCta && window.mbStoryCta.isCurrentPage(s.cta_url))) {
      cta.hidden = false;
      const ctaUrl = new URL(s.cta_url, location.href);
      /* Only rewrite the language segment when BOTH the current page and the CTA
         already run in prefix mode — inserting a prefix where none exists 404s
         in session mode. */
      const langSeg = /^\/([a-z]{2,3}(?:-[a-z]{2})?)(\/|$)/i;
      if (ctaUrl.origin === location.origin && langSeg.test(location.pathname) && langSeg.test(ctaUrl.pathname)) {
        ctaUrl.pathname = ctaUrl.pathname.replace(langSeg, "/" + location.pathname.match(langSeg)[1] + "$2");
      }
      cta.href = ctaUrl.toString();
      cta.querySelector("span").textContent = s.cta_label || t("order_now");
    } else { cta.hidden = true; }
    elapsed = 0; paintSeg();
  }
  function tick() { elapsed += 0.05; if (elapsed >= DUR) { next(); return; } paintSeg(); }
  const run = () => { stop(); timer = setInterval(tick, 50); };
  function stop() { if (timer) { clearInterval(timer); timer = null; } }
  function openReel(i) {
    idx = i; buildProgress();
    viewer.hidden = false;
    void viewer.offsetWidth;            // force a frame so the opacity transition runs
    viewer.classList.add("is-open");
    render(); run();
  }
  function closeReel() {
    stop();
    viewer.classList.remove("is-open");
    setTimeout(() => { viewer.hidden = true; media.removeAttribute("src"); }, 300);
  }
  function next() { if (idx < stories.length - 1) { idx++; render(); } else { closeReel(); } }
  function prev() { if (idx > 0) { idx--; render(); } else { elapsed = 0; paintSeg(); } }

  rail.addEventListener("click", e => {
    const b = e.target.closest("[data-reel]"); if (b) openReel(Number(b.dataset.reel));
  });
  on("reelClose", "click", closeReel);
  cta?.addEventListener("click", e => {
    e.preventDefault();
    stop();
    /* The stored cta_url is prefix-less, so a hard nav to it drops the visitor's
       locale (/en/ -> default language). Carry the current locale prefix across
       with the shared policy helper before navigating. */
    var href = (window.mbStoryCta && window.mbStoryCta.localizeSameOrigin)
      ? window.mbStoryCta.localizeSameOrigin(cta.href) : cta.href;
    location.assign(href);
  });
  on("reelNext", "click", next);
  on("reelPrev", "click", prev);
  const stage = $("#reelStage");
  if (stage) {
    /* press-and-hold pauses, like the native story UIs */
    stage.addEventListener("pointerdown", stop);
    stage.addEventListener("pointerup", () => { if (!viewer.hidden) run(); });
    stage.addEventListener("pointercancel", () => { if (!viewer.hidden) run(); });
  }
})();

/* ── nearest-branch map (self-hosted Leaflet) ─────────── */
(function () {
  const openBtn = document.getElementById("crumbNearestBranchBtn");
  if (!openBtn || !C.endpoints || !C.endpoints.branchesGeo || typeof L === "undefined") {
    if (openBtn) openBtn.style.display = "none";
    return;
  }
  let map = null, markers = [];
  const icon = L.icon({
    iconUrl: C.leafletImgPath + "/marker-icon.png",
    iconRetinaUrl: C.leafletImgPath + "/marker-icon-2x.png",
    shadowUrl: C.leafletImgPath + "/marker-shadow.png",
    iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41],
  });
  function selectBranch(id) {
    const url = new URL(window.location.href);
    url.searchParams.set("branch_id", id);
    window.location.href = url.toString();
  }
  function renderList(branches) {
    const el = $("#crumbBranchGeoList"); if (!el) return;
    if (!branches.length) { el.innerHTML = `<p class="so-empty">${t("no_branches")}</p>`; return; }
    el.innerHTML = branches.map(b => {
      const dist = b.distance_km != null ? `<span class="branch-dist" dir="ltr">${b.distance_km.toFixed(1)} ${t("km")}</span>` : "";
      return `<button class="branch-row" data-bid="${b.id}" type="button">
        <span class="loc-ic">${ICONS.pin}</span>
        <div><b>${esc(b.name)}</b>${b.address ? `<small>${esc(b.address)}</small>` : ""}</div>${dist}
      </button>`;
    }).join("");
  }
  function drawMap(branches) {
    const withCoords = branches.filter(b => b.latitude != null && b.longitude != null);
    if (!map) {
      map = L.map("crumbBranchMap", { attributionControl: false });
      L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 }).addTo(map);
    }
    markers.forEach(m => map.removeLayer(m)); markers = [];
    if (!withCoords.length) { map.setView([24.7136, 46.6753], 11); return; }
    const group = [];
    withCoords.forEach(b => {
      const mk = L.marker([b.latitude, b.longitude], { icon }).addTo(map);
      mk.bindPopup("<b>" + esc(b.name) + "</b>");
      mk.on("click", () => selectBranch(b.id));
      markers.push(mk); group.push([b.latitude, b.longitude]);
    });
    map.fitBounds(group, { padding: [40, 40], maxZoom: 14 });
  }
  function load(lat, lng) {
    const url = C.endpoints.branchesGeo + (lat != null ? "?lat=" + lat + "&lng=" + lng : "");
    fetch(url).then(r => r.json()).then(d => {
      const branches = (d && d.branches) || [];
      const attr = $("#crumbMapAttrib"); if (attr) attr.textContent = (d && d.attribution) || "© OpenStreetMap contributors";
      renderList(branches);
      setTimeout(() => { drawMap(branches); if (map) map.invalidateSize(); }, 60);
    }).catch(() => {});
  }
  openBtn.addEventListener("click", () => {
    sheet("branchMapSheet");
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(
        p => load(p.coords.latitude, p.coords.longitude),
        () => load(), { timeout: 6000 });
    } else { load(); }
  });
  const geoList = $("#crumbBranchGeoList");
  if (geoList) geoList.addEventListener("click", e => {
    const b = e.target.closest("[data-bid]"); if (b) selectBranch(b.dataset.bid);
  });
})();

/* ── boot ─────────────────────────────────────────────── */
/* ── account-page reorder handoff (P6-301) ────────────────
   The account page (account.blade.php) writes the reorder payload to
   cv_reorder_<slug> and redirects here. The shared helper reads it back and
   calls __crumbPickup for each line; we map the choice-group options from the
   payload (which uses labels) onto crumb's `sel.g` shape (which uses indices
   into the JS-side optionGroups), then push the line into the cart. */
window.__crumbPickup = function (line) {
    const i = item(line.item_id);
    if (!i || !i.inStock) { return false; }
    /* weights[] carries the DB variant id (vid); extras[] the DB extra id (xid).
       sel.w / sel.ex are INDICES into those arrays, sel.g[groupId] holds the
       chosen option OBJECTS {i, price, label} exactly like the product sheet. */
    let w = 0;
    if (line.variant) {
        const wi = (i.weights || []).findIndex(function (x) { return Number(x.vid) === Number(line.variant); });
        if (wi < 0) { return false; }
        w = wi;
    }
    const ex = [];
    (line.extras || []).forEach(function (e) {
        const xi = (i.extras || []).findIndex(function (x) { return Number(x.xid) === Number(e.id); });
        if (xi >= 0 && ex.indexOf(xi) < 0) ex.push(xi);
    });
    ex.sort(function (a, b) { return a - b; });
    const sel = { w: w, ex: ex, g: {} };
    (line.options || []).forEach(function (group) {
        const grp = (i.optionGroups || []).find(function (g) { return String(g.id) === String(group.group_id); });
        if (!grp) return;
        const picks = [];
        (group.selected || []).forEach(function (s) {
            const o = (grp.options || []).find(function (x) { return x.label === s.option; });
            if (o) picks.push({ i: o.i, price: +o.price || 0, label: o.label });
        });
        if (picks.length) sel.g[grp.id] = picks;
    });
    /* a required group that can no longer be satisfied would fail checkout */
    const missing = (i.optionGroups || []).some(function (g) {
        const need = g.required ? Math.max(1, g.min || 0) : (g.min || 0);
        return (sel.g[g.id] || []).length < need;
    });
    if (missing) { return false; }
    const key = line.item_id + "|" + JSON.stringify(sel);
    const existing = S.cart.find(function (l) { return l.key === key; });
    if (existing) { existing.qty += line.qty; }
    else { S.cart.push({ key: key, id: line.item_id, qty: line.qty, sel: sel, note: line.note || "" }); }
    store.set("cart", S.cart);
    syncBadge(true);
    toast(`${name(i)} — ${t("added")}`, "cart");
    return true;
};

function boot() {
  /* a room QR sets forceTable too, so room service must win over dine-in here */
  if (C.allowRoomService) { S.orderType = "room_service"; S.table = C.defaultTable || 1; }
  else if (C.forceTable && C.allowOnTable) { S.orderType = "on-table"; S.table = C.defaultTable || 1; }
  syncOrderTypeSeg();
  buildKeyboard();
  /* FIX-3c — pre-select the diner's default account address when this device has
     no address of its own yet; the saved-addr chips (delegated [data-address]
     handler) let them switch. */
  if (!S.address && C.defaultAddress) { S.address = C.defaultAddress; store.set("address", S.address); }
  /* Branch mode owns #locText (active branch name); only the delivery-address
     variant reflects the saved address there. */
  const _lb = $("#locBtn");
  if (_lb && _lb.dataset.branchOpen) { syncBranchLabel(); }
  else if (S.address) { const lt = $("#locText"); if (lt) lt.textContent = S.address; }
  renderHome();
  if (window.mbCurrency) mbCurrency.restore("#curSeg");   // replay saved currency choice (fixes reset-on-reload)
  renderBanners();
  syncSubChips();
  syncBadge(false);
  if (C.loyaltyEnabled) loadLoyalty();
  /* R2 — splash session-gate: the intro used to replay on every navigation
     because nothing recorded that it had been seen. Show the timed intro once
     per session; on repeat views dismiss it immediately (no replay). crumb
     keys localStorage elsewhere but the splash flag lives in sessionStorage so
     it clears when the tab closes. */
  (() => {
    const splash = $("#splash"); if (!splash) return;
    /* A1: a ?item=/?table= deep link opens straight on the product — dismiss the
       splash up front so it never overlays it (the splash sits above the sheet).
       Not persisted: a later plain visit this session still gets the intro. */
    try {
      const dl = new URLSearchParams(location.search);
      if (dl.get("item") || dl.get("table")) { splash.classList.add("is-done"); return; }
    } catch { /* URLSearchParams unsupported / bad query: fall through */ }
    const splashKey = "crumb_splash_" + (C.restaurantSlug || C.storeId || "");
    try {
      if (sessionStorage.getItem(splashKey)) { splash.classList.add("is-done"); return; }
      sessionStorage.setItem(splashKey, "1");
    } catch { /* private mode: fall through to the normal timed dismiss */ }
    setTimeout(() => splash.classList.add("is-done"), 2200);
  })();
  S.activeOrder = S.orders[0] || null;
  applyAppearance(store.get(APPEARANCE_KEY, "auto"));
  syncInert();
  /* R7 — a ?item=<id> deeplink lands straight on that product (share/CTA links
     built by other pages). Open it in place once the home view is rendered. */
  try {
    const deepItem = new URLSearchParams(location.search).get("item");
    if (deepItem && item(deepItem)) openProduct(deepItem);
  } catch { /* URLSearchParams unsupported / bad query: ignore */ }
  if (C.reservations && document.querySelector("#reserveSheet .sheet-done")) sheet("reserveSheet");
  if (C.waitlist && document.querySelector("#waitlistSheet .sheet-done")) sheet("waitlistSheet");

  /* topbar quick toggle (Lane B): rides crumb's NATIVE appearance system
     (applyAppearance/data-crumb-theme, store key "appearance") so the
     #appearanceSeg Auto/Light/Dark segment stays in sync — the shared engine
     is NOT used because crumb already owns palette + persistence (CRUMB-F7). */
  on("crumbThemeToggle", "click", () => {
    const isDark = document.documentElement.getAttribute("data-crumb-theme") === "dark";
    const next = isDark ? "light" : "dark";
    store.set(APPEARANCE_KEY, next);
    applyAppearance(next);
    $("#crumbThemeToggle")?.setAttribute("aria-pressed", String(!isDark));
  });
}
boot();

/* explicit corner-X close on sheets (crumb closes via the scrim, not a grab bar). */
document.querySelectorAll(".sheet-x").forEach(x => x.addEventListener("click", () => { if (openSheet) sheet(openSheet, false); }));

/* Collapsing header — condensed bar in once the brown head leaves the home scroll
   container. crumb's category tiles are not top-sticky, so no overlap guard.
   Condensed buttons forward their click to the real top-chrome controls. */
(function mountCondensedHeader() {
  const homeView = document.querySelector('.view[data-view="home"]');
  if (!homeView) return;
  const anchor = homeView.querySelector(".brown-head");
  const bar = homeView.querySelector(".cv-condensed");
  const scroller = homeView.querySelector(".view-scroll");
  if (!anchor || !bar || !scroller || !("IntersectionObserver" in window)) return;
  const io = new IntersectionObserver(es => {
    const e = es[0];
    if (e) bar.classList.toggle("is-on", e.intersectionRatio < 0.05);
  }, { root: scroller, threshold: [0, 0.05, 1] });
  io.observe(anchor);
  const T = { lang: "#homeLangBtn", catering: "#cvCateringBtn", notif: "#mbNotifBell" };
  bar.querySelectorAll("[data-cv-proxy]").forEach(b => b.addEventListener("click", () => {
    const t = homeView.querySelector(T[b.getAttribute("data-cv-proxy")]);
    if (t) t.click();
  }));
})();
})();
