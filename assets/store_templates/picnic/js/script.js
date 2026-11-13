// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ═══════════════════════════════════════════════════════════
   picnic post_template — app logic wired to real menu data.

   Rebuilt from rev/picnic/demo.js. Everything the demo faked is now
   either wired to a real server endpoint or gone: order references,
   order tracking, reservations, the waitlist, the loyalty economy,
   the star rating, the bill request and the card form. If this file
   tells the customer something happened, a server row backs it.
   ═══════════════════════════════════════════════════════════ */
/* Wrapped in an IIFE so the DOM helpers below (`const $ = querySelector`,
   `$$`, etc.) stay local. At top level they leaked into the global lexical
   scope and SHADOWED jQuery's `$` for every other inline script on the page —
   a shared handler doing `$(window).on(...)` then hit querySelector(window)
   and threw `'[object Window]' is not a valid selector`. Keeping them scoped
   restores jQuery's `$` for the rest of the page. */
(function () {
"use strict";

const P = window.PICNIC || {};
const ITEMS = Array.isArray(P.items) ? P.items : [];
const CATS  = (Array.isArray(P.cats) ? P.cats : []).map(c => ({ id: c.id, name: c.name, emoji: c.emoji, image: c.image || "", subs: Array.isArray(c.subs) ? c.subs : [] }));
const I18N  = P.i18n || {};
// dp = decimals for the store currency (3 on OMR/KWD/JOD/BHD/LYD/TND, else 2) — must match
// the server-rendered prices, which now go through price_format()/currency_decimals().
const CUR   = { sign: P.curSign || "$", gap: P.curGap || "", pos: String(P.curPos || "1"), dp: Number.isFinite(Number(P.curDecimals)) ? Number(P.curDecimals) : 2 }; // pos 1 = sign before
const DELIVERY_FEE = Number(P.deliveryCharge || 0);
const TAX_CHARGE   = Number(P.taxCharge || 0);
const TAX_TYPE     = String(P.taxType || "percentage");
const TAX_LABEL    = P.taxLabel || "Tax";
const MIN_ORDER    = Number(P.minOrder || 0);
const DEFAULT_IMG  = P.defaultImg || "";
const ORDER_TYPES  = Array.isArray(P.orderTypes) && P.orderTypes.length ? P.orderTypes : ["delivery"];
const DOW = Array.isArray(P.dow) && P.dow.length === 7 ? P.dow : ["S", "M", "T", "W", "T", "F", "S"];

/* payment methods available on this restaurant */
const PAY_METHODS = [{ id: "cash", icon: "cash", label: "Cash" }];
if (P.allowPayment) {
  PAY_METHODS.push({ id: "card", icon: "card", label: "Card" });
  if (P.storeCredit && P.storeCredit > 0) PAY_METHODS.push({ id: "wallet", icon: "wallet", label: "Wallet" });
}

/* foods list — one row per top-level parent, expands to every descendant item.
   Keyed on i.root so subcategory items stay visible under their parent. */
const FOOD_GROUPS = {};
for (const c of CATS) {
  const its = ITEMS.filter(i => String(i.root) === String(c.id)).map(i => i.id);
  if (its.length) FOOD_GROUPS[c.id] = { name: c.name, img: (ITEMS.find(i => i.id === its[0]) || {}).gallery?.[0] || DEFAULT_IMG, items: its };
}
const FOOD_ROWS = Object.keys(FOOD_GROUPS).map((id, i) => ({ type: "cat", id: id, hl: i === 0 }));

/* search suggestions from category names */
const SUGGESTS = CATS.slice(0, 5).map(c => c.name);

const PLAN_BASE = (() => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), d.getDate()); })();

/* ── state ────────────────────────────────────────────── */
/* storage is scoped per restaurant: an unscoped "picnic_" prefix made every
   picnic store on this origin share one cart (and favs/points/orders). */
const SKEY = "picnic_" + (P.slug || P.storeId || "") + "_";
const store = {
  get: (k, d) => { try { return JSON.parse(localStorage.getItem(SKEY + k)) ?? d; } catch { return d; } },
  set: (k, v) => localStorage.setItem(SKEY + k, JSON.stringify(v)),
};

/* A stored line whose item is no longer on the menu — the owner deleted it, or
   the cart was persisted before a menu change — makes item() return undefined,
   so unitPrice() throws on i.price and cartSubtotal() kills every cart render
   (Clear included). Prune on load and write back so a poisoned line can't
   linger. Menu ids are globally unique, so a foreign line can never match. */
const MENU_IDS = new Set(ITEMS.map(i => String(i.id)));
const cartStored = store.get("cart", []);
const cartPruned = cartStored.filter(l => l && MENU_IDS.has(String(l.id)));

/* Orders recorded here are ONLY orders this device actually placed and the server
   actually accepted; each carries the server's own track_url and the amount the
   server charged. Nothing in this file ever mints an order reference or a status. */
const S = {
  favs: new Set(store.get("favs", [])),
  cart: cartPruned,
  orders: store.get("orders", []).filter(o => o && o.trackUrl),
  loyalty: null,               // filled from publicView.loyalty.balance, or stays null
  cat: "all", sub: "root", search: "",
  filters: { diets: {}, allergens: {}, spice: 0 },
  foodFilter: null,
  orderType: ORDER_TYPES[0], when: "asap", plan: null,
  coupon: null, couponDisc: 0, tip: 0, pay: "cash",
  currentItem: null, pQty: 1, pSel: {}, pImgIdx: 0, descOpen: false,
  weekOff: 0, planDay: 0, planSlot: "12:30",
  resDate: 0, resTime: null, resParty: 2,
  noteLi: null, activeOrder: null,
  mode: store.get("mode", "auto"),
  /* display currency (velora parity): base until the diner picks another in #curSeg.
     The shared mbCurrency helper replays a stored choice after the first price render. */
  curCode: P.baseCurrency, curRate: 1,
};

/* persist the prune so the poisoned line is gone for good, not just this load */
if (cartPruned.length !== cartStored.length) store.set("cart", cartPruned);

const RM = matchMedia("(prefers-reduced-motion: reduce)").matches;
const t = k => (I18N[k] ?? k);
const name = o => (o && typeof o === "object") ? (o.name ?? "") : o;
const desc = i => i.desc || "";
const locale = () => (P.lang || "en");

/* Quantise to the STORE's currency decimals — the same rounding the server uses.
   A hardcoded 2 diverges on every 0- or 3-decimal currency. */
/* M16: delegates to the shared money core (drops the epsilon nudge for PHP-exact rounding). */
const MONEY_DP = Number.isFinite(Number(P.moneyDecimals)) && P.moneyDecimals !== null ? Number(P.moneyDecimals) : CUR.dp; /* bug batch 10 · 4: order arithmetic (coupon / tax / tip) at the store currency's minor unit — what the order stores (CFG.moneyDecimals); display keeps currencyDecimals */
const roundDp = v => mbMoney.round(v, MONEY_DP);
/* Decimals follow the DISPLAYED currency, not the store's (velora parity): the base
   currency keeps the store's own decimals; a converted code uses decimalsByCode (a code
   absent from it is 2 by definition — never fall back to this store's decimals). */
function moneyDp(code) {
  const dp = code === P.baseCurrency ? CUR.dp : (P.decimalsByCode || {})[code];
  const n = Number(dp);
  return Number.isFinite(n) ? n : 2;
}
function fmt(v) {
  const code = S.curCode || P.baseCurrency;
  /* Non-base display currency: convert at the chosen rate and mark it an estimate
     (MC-34: ≈). Base currency keeps the store's exact sign/position/decimals. */
  if (code && code !== P.baseCurrency) {
    const conv = (Number(v) || 0) * (S.curRate || 1);
    return "≈ " + code + " " + conv.toFixed(moneyDp(code));
  }
  const n = roundDp(v).toFixed(CUR.dp);
  return CUR.pos === "1" ? `${CUR.sign}${CUR.gap}${n}` : `${n} ${CUR.sign}`;
}
const starsOf = r => "★".repeat(Math.floor(r)) + "☆".repeat(Math.max(0, 5 - Math.floor(r)));

/* ── dom helpers ──────────────────────────────────────── */
const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
const esc = s => String(s == null ? "" : s).replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
/* Crawlable + new-tab-able per-item URL: keeps existing query (table, lang…)
   and sets ?item=ID — the deep-link the R7 intercept + boot loader understand. */
const itemHref = id => { try { const u = new URL(location.href); u.searchParams.set("item", id); u.hash = ""; return u.pathname + u.search; } catch { return "?item=" + encodeURIComponent(id); } };
const img = u => esc(u || DEFAULT_IMG);
const ICONS = {
  plus: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg>',
  heart: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>',
  star: '<svg viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>',
  check: '<svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>',
  chev: '<svg class="ic ic-xs chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg>',
  trash: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M10 7V5h4v2M8 7l.8 13h6.4L16 7"/></svg>',
  pen: '<svg class="ic" viewBox="0 0 24 24"><path d="M4 20h4L20 8l-4-4L4 16v4Z"/><path d="m13 7 4 4"/></svg>',
  bag: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>',
  cash: '<svg class="ic" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="11" rx="2"/><circle cx="12" cy="12.5" r="2.6"/><path d="M6.5 10.5v0M17.5 14.5v0"/></svg>',
  card: '<svg class="ic" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M7 15h4"/></svg>',
  wallet: '<svg class="ic" viewBox="0 0 24 24"><path d="M4 7a2 2 0 0 1 2-2h12v3M4 7v10a2 2 0 0 0 2 2h13a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1H4"/><circle cx="16.5" cy="14" r="1.3" fill="currentColor" stroke="none"/></svg>',
  clock: '<svg class="ic ic-xs" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
  fire: '<svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M12 22c4.4 0 7-2.8 7-6.6 0-4.8-4.2-6.6-4.9-11.4-2.5 1.6-3.4 4-3.2 6.6-1.2-.7-2-1.7-2.4-3.4C6.6 9 5 11.6 5 15.4 5 19.2 7.6 22 12 22Z"/></svg>',
  leaf: '<svg class="ic ic-xs" viewBox="0 0 24 24"><path d="M5 19C5 9 13 5 20 5c0 8-4 14-12 14-1 0-2-.1-3 0Z"/><path d="M5 19c2-5 6-9 10-11"/></svg>',
};

/* broken image guard — swap to the restaurant default */
document.addEventListener("error", e => {
  const im = e.target;
  if (!im || im.tagName !== "IMG") return;
  /* Category chip photo → restore the emoji, not a food default. */
  if (im.hasAttribute("data-cat-img")) {
    const circle = im.closest(".cat-circle");
    if (circle) { circle.classList.remove("has-img"); circle.textContent = im.getAttribute("data-emoji") || "🍽️"; }
    return;
  }
  if (!im.dataset.fbk) { im.dataset.fbk = "1"; im.src = DEFAULT_IMG; }
}, true);

/* ── router ───────────────────────────────────────────── */
const stack = [];
const ROOTS = ["home", "cart", "favs", "profile"];
let active = "home";

const ORDERING_ON = P.allowOrder !== false;
function show(v, { push = true, fromPop = false } = {}) {
  // view-only menu: there is no bag and no checkout to route to
  if (!ORDERING_ON && (v === "cart" || v === "checkout" || v === "confirmed")) v = "home";
  if (v === active) return;
  const from = $(`.view[data-view="${active}"]`), to = $(`.view[data-view="${v}"]`);
  if (!to) return;
  const isRoot = ROOTS.includes(v);
  if (push && !isRoot) {
    stack.push(active);
    /* Mirror the internal stack into browser history so the phone's back
       button walks product → home instead of leaving the site. */
    if (!fromPop) { try { history.pushState({ pn: 1 }, ""); } catch (e) {} }
  }
  if (isRoot) stack.length = 0;

  $$(".view").forEach(x => x.classList.remove("is-under"));
  if (!isRoot) from?.classList.add("is-under");
  from?.classList.remove("is-active");
  to.classList.add("is-active");
  to.querySelector(".view-scroll")?.scrollTo({ top: 0, behavior: "instant" });
  active = v;

  $("#tabbar").classList.toggle("is-hidden", !isRoot);
  $$(".tab").forEach(b => b.classList.toggle("is-on", b.dataset.tab === v));
  if (v === "cart") renderCart();
  if (v === "favs") renderFavs();
  if (v === "profile") renderProfile();
  if (v === "checkout") renderCheckout();
  if (v === "foods") renderFoods();
  if (v === "mealplan") renderPlan();
  if (v === "points") renderPoints();
}
function internalBack() { show(stack.pop() ?? "home", { push: false, fromPop: true }); }
function back() {
  /* UI back buttons go through history when we pushed a state, so the
     browser's own back stack stays in step with what is on screen. */
  if (stack.length && history.state && history.state.pn) { history.back(); return; }
  internalBack();
}
window.addEventListener("popstate", () => {
  if (openSheet) { sheet(openSheet, false); try { history.pushState({ pn: 1 }, ""); } catch (e) {} return; }
  if (stack.length) internalBack();
  else if (active !== "home") show("home", { push: false, fromPop: true });
});

/* ── sheets / toast / fly ─────────────────────────────── */
let openSheet = null;
const sheetHideTimers = {};
function sheet(id, open = true) {
  const s = $("#" + id), scrim = $("#scrim");
  if (!s) return;
  if (open) {
    if (openSheet && openSheet !== id) sheet(openSheet, false);
    clearTimeout(sheetHideTimers[id]);
    s.hidden = false; scrim.hidden = false;
    requestAnimationFrame(() => { s.classList.add("is-open"); scrim.classList.add("is-open"); });
    openSheet = id;
  } else {
    s.classList.remove("is-open"); scrim.classList.remove("is-open");
    clearTimeout(sheetHideTimers[id]);
    sheetHideTimers[id] = setTimeout(() => { s.hidden = true; if (!openSheet || openSheet === id) scrim.hidden = true; }, 420);
    if (openSheet === id) openSheet = null;
  }
}
/* WAVE3-V2 (same class as CRUMB-F30). layout.blade.php:637 auto-loads this file on
   EVERY picnic surface, but only index.blade.php has a #scrim — track and account do
   not. The unguarded listener therefore threw
   "TypeError: Cannot read properties of null (reading 'addEventListener')" at module
   top level on both of those pages, so every declaration BELOW this line was dead
   there. Guarded. */
$("#scrim")?.addEventListener("click", () => openSheet && sheet(openSheet, false));
document.addEventListener("keydown", e => { if (e.key === "Escape" && openSheet) sheet(openSheet, false); });

let toastTimer;
function toast(msg) {
  const tst = $("#toast");
  tst.innerHTML = '<svg class="ic" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>' + `<span>${esc(msg)}</span>`;
  tst.classList.add("is-on");
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => tst.classList.remove("is-on"), 2200);
}
/* Exposed so the shared reorder-pickup helper (loaded by index.blade.php after
   script.js) can route its "skipped these items" message through picnic's
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

function flyToBag(fromEl) {
  if (RM) return;
  const src = fromEl?.closest(".bs-card, .rail-card, .rw-card, .food-row, .mp-card, .so-row")?.querySelector("img") ?? $("#pImg");
  const target = $('.tab[data-tab="cart"] .tab-pill');
  if (!src || !target) return;
  const a = src.getBoundingClientRect(), b = target.getBoundingClientRect();
  const ghost = document.createElement("img");
  ghost.src = src.src; ghost.className = "fly-img";
  const lay = $("#flyLayer"), lr = lay.getBoundingClientRect();
  ghost.style.left = a.left - lr.left + a.width / 2 - 32 + "px";
  ghost.style.top = a.top - lr.top + a.height / 2 - 32 + "px";
  lay.appendChild(ghost);
  requestAnimationFrame(() => {
    ghost.style.left = b.left - lr.left + b.width / 2 - 32 + "px";
    ghost.style.top = b.top - lr.top + b.height / 2 - 32 + "px";
    ghost.style.transform = "scale(.14)";
    ghost.style.opacity = ".3";
  });
  setTimeout(() => ghost.remove(), 800);
}

/* ── pricing ──────────────────────────────────────────── */
const item = id => ITEMS.find(i => String(i.id) === String(id));
function unitPrice(i, sel) {
  let base = i.price;
  for (const g of i.groups) {
    if (g.variant) { const idx = (sel[g.id] ?? [])[0]; if (idx != null && g.opts[idx]) base = g.opts[idx].abs; }
  }
  let add = 0;
  for (const g of i.groups) {
    if (g.extra || g.global) for (const idx of (sel[g.id] ?? [])) { if (g.opts[idx]) add += g.opts[idx].delta; }
  }
  return base + add;
}

/* A required group is unsatisfied when its picks fall short of max(1, min).
   Radio/variant groups auto-select [0] on open so they're always satisfied. */
function missingRequired(i, sel) {
  for (const g of i.groups) {
    if (!g.required) continue;
    const need = Math.max(1, Number(g.min) || 0);
    if ((sel[g.id] ?? []).length < need) return g;
  }
  return null;
}
/* Quick-add (from a card) can't satisfy a required multi-select group with the
   default empty selection — those items must open the product modal first. */
function needsConfig(i) { return i.groups.some(g => g.required && g.type === "check"); }
function selSummary(i, sel) {
  const parts = [];
  for (const g of i.groups) {
    const picks = (sel[g.id] ?? []).map(x => name(g.opts[x]));
    if (picks.length) parts.push(picks.join(", "));
  }
  return parts.join(" · ");
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

function cartSubtotal() {
  /* Combos ride in the subtotal: the server taxes them, min-order counts them,
     and the coupon endpoint is quoted the same base the order will carry. */
  return S.cart.reduce((s, l) => s + unitPrice(item(l.id), l.sel) * l.qty, 0) + comboTotal();
}

/* TOTALS CONTRACT — a byte-for-byte port of Order::computeTax().
   `restaurant_taxx_charge` is a PERCENTAGE by default and a FLAT amount when
   restaurant_tax_type === 'fixed'; both guard on charge<=0 || subtotal<=0 and
   both round to the store's own currency decimals. picnic previously shipped no
   tax at all ("server does not add tax" — it does), so on the lane store it
   quoted $16.85 for an order the server charged $17.60. */
function taxOf(subtotal) {
  if (TAX_CHARGE <= 0 || subtotal <= 0) return 0;
  if (TAX_TYPE === "fixed") return roundDp(TAX_CHARGE);
  return roundDp(subtotal * (TAX_CHARGE / 100));
}

/* total = subtotal + delivery + tax + tip − discount.
   Tax is taken on the RAW subtotal and the discount is subtracted last, exactly
   as getTotalAttribute() does; taxing the post-discount base under-quotes.
   The tip is quantised here to the same decimals that go on the wire in
   placeOrder(), so the number shown is the number charged. */
/* The server order type for the coupon check (inline: the place-order map is a later `const`). */
function couponOrderType() { return ({ delivery: "delivery", pickup: "takeaway", dinein: "on-table", roomservice: "room_service" })[S.orderType] || ""; }
function couponForm(code) {
  const fd = new FormData();
  fd.append("_token", P.csrf);
  fd.append("code", code);
  fd.append("subtotal", cartSubtotal().toFixed(MONEY_DP));
  fd.append("items", JSON.stringify(buildOrderItems()));
  fd.append("ordering-type", couponOrderType() || "");
  return fd;
}
function couponSig() { return JSON.stringify([cartSubtotal().toFixed(MONEY_DP), buildOrderItems(), couponOrderType() || ""]); }
/* Bug batch 7 · 4: picnic shows the SERVER's discount, quoted once at apply time — a cart change kept the old
   number (a percentage or buy-X-get-Y coupon on a changed basket). Re-quote whenever the basket or order type
   changes, with the cart lines (buy-X-get-Y is priced on them). */
function couponDiscount() {
  const code = S.coupon, sig = couponSig();
  if (S.couponSig !== sig) {
    S.couponSig = sig;
    fetch(P.couponUrl, { method: "POST", body: couponForm(code), headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } })
      .then(r => r.json()).then(res => {
        if (S.coupon !== code || S.couponSig !== sig) return;
        S.couponDisc = res && res.success ? Number(res.discount || 0) : 0;
        renderTotals();
      }).catch(() => {});
  }
  return Number(S.couponDisc) || 0;
}
function cartTotals() {
  const sub  = roundDp(cartSubtotal());
  const disc = Math.min(S.coupon ? couponDiscount() : 0, sub);
  const del  = S.orderType === "delivery" && (S.cart.length || comboCount()) ? DELIVERY_FEE : 0;
  const tax  = taxOf(sub);
  const tip  = roundDp((sub - disc) * (S.tip / 100));
  return { sub, disc, del, tax, tip, total: roundDp(sub - disc + del + tax + tip) };
}
/* Tax row label: "Tax (5%)" for a rate, plain "Tax" for a flat charge. */
function taxRowLabel() {
  return TAX_TYPE === "fixed" ? TAX_LABEL : `${TAX_LABEL} (${TAX_CHARGE}%)`;
}

/* ── loyalty (REAL program only) ───────────────────────
   The demo minted its own points in localStorage and sold a reward catalogue
   priced at max(20, price*10) whose "Get Now" button dropped a zero-priced line
   into the cart — quoted $0.00, charged full price by the server, and the points
   never left the browser. There is exactly one real source of truth for loyalty
   here: publicView.loyalty.balance, which needs a signed-in customer AND an
   active LoyaltyProgram. Without both, the entire surface hides itself. */
function loyaltyVisible() { return !!(S.loyalty && S.loyalty.active); }
function syncPts() {
  // Customizer: the `rewards` section can be hidden — the Rewards view is then
  // not in the DOM, so the pills that open it must stay hidden too.
  const on = loyaltyVisible() && !!$('.view[data-view="points"]');
  const v = on ? String(S.loyalty.balance ?? 0) : "0";
  for (const id of ["#ptsPillBtn", "#profPtsBtn", "#ptsRow"]) { const el = $(id); if (el) el.hidden = !on; }
  const pill = $("#ptsPill"), prof = $("#profPts"), num = $("#ptsNum");
  if (pill) pill.textContent = v;
  if (prof) prof.textContent = v;
  if (num) num.textContent = v;
}
function loadLoyalty() {
  if (!P.loyaltyUrl) { syncPts(); return; }
  fetch(P.loyaltyUrl, { headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" } })
    .then(r => (r.ok ? r.json() : null))
    .then(d => { S.loyalty = d && d.active ? d : null; syncPts(); if (active === "points") renderPoints(); })
    .catch(() => { S.loyalty = null; syncPts(); });
}

/* ── home renders ─────────────────────────────────────── */
/* Two-tier nav: parent circular chips (+ an "All" chip); data-has-sub marks
   parents that own a second row of sub-category chips (renderSubChips). */
function renderCats() {
  const wrap = $("#cats"); if (!wrap) return;   // `catbar` section hidden by the customizer
  const allOn = S.cat === "all";
  /* "ALL" has no category of its own, so it gets a drawn grid glyph (everything),
     not a photo — distinct from the real-image category chips beside it. */
  wrap.innerHTML =
    `<button class="cat ${allOn ? "is-on" : ""}" data-cat="all">
      <span class="cat-circle cat-all">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.6"/><rect x="14" y="3" width="7" height="7" rx="1.6"/><rect x="3" y="14" width="7" height="7" rx="1.6"/><rect x="14" y="14" width="7" height="7" rx="1.6"/></svg>
      </span>
      <span class="cat-label">${esc(t("all_cats"))}</span>
    </button>` +
    CATS.map(c => `
    <button class="cat ${String(c.id) === String(S.cat) ? "is-on" : ""}" data-cat="${c.id}"${c.subs.length ? ' data-has-sub="1"' : ""}>
      <span class="cat-circle${c.image ? " has-img" : ""}">${c.image ? `<img src="${esc(c.image)}" alt="" loading="lazy" data-cat-img data-emoji="${esc(c.emoji)}">` : c.emoji}</span>
      <span class="cat-label">${esc(name(c))}</span>
    </button>`).join("");
  renderSubChips();
}

/* Secondary sub-chip row for the active parent; hidden on "All" or parents with
   no item-bearing subcategory. "All" (data-sub="root") = every descendant. */
function renderSubChips() {
  const row = $("#subCats"); if (!row) return;
  const c = CATS.find(x => String(x.id) === String(S.cat));
  if (S.cat === "all" || !c || !c.subs.length) { row.hidden = true; row.innerHTML = ""; return; }
  row.hidden = false;
  row.innerHTML =
    `<button class="subcat ${S.sub === "root" ? "is-on" : ""}" data-sub="root">${esc(t("all_foods"))}</button>` +
    c.subs.map(s => `<button class="subcat ${String(s.id) === String(S.sub) ? "is-on" : ""}" data-sub="${s.id}">${esc(name(s))}</button>`).join("");
}

function catName(id) {
  const c = CATS.find(x => String(x.id) === String(id));
  if (c) return c.name;
  for (const p of CATS) { const s = p.subs.find(x => String(x.id) === String(id)); if (s) return s.name; }
  return "";
}
let GRID_TITLE_DEFAULT = null;
function updateGridTitle() {
  const el = $("#bestTitle"); if (!el) return;
  if (GRID_TITLE_DEFAULT == null) GRID_TITLE_DEFAULT = t("best");
  const label = S.sub !== "root" ? catName(S.sub) : (S.cat !== "all" ? catName(S.cat) : GRID_TITLE_DEFAULT);
  el.textContent = label || GRID_TITLE_DEFAULT;
}

/* dietary / spice / allergen filter — composes with the two-tier nav */
function passFilters(i) {
  const f = S.filters;
  if (f.diets.vegan && !i.vegan) return false;
  if (f.diets.halal && !i.halal) return false;
  if (f.diets.gluten_free && !i.glutenFree) return false;
  if (f.spice && (Number(i.spice) || 0) < f.spice) return false;
  for (const aId in f.allergens) {
    if (f.allergens[aId] && (i.allergies || []).some(a => String(a.id) === String(aId))) return false;
  }
  return true;
}
function filtersActive() {
  const f = S.filters;
  return !!(f.diets.vegan || f.diets.halal || f.diets.gluten_free || f.spice ||
    Object.keys(f.allergens).some(k => f.allergens[k]));
}

function bsCardHTML(i, idx) {
  const fav = S.favs.has(i.id);
  const favBtn = `<span class="bs-fav ${fav ? "is-on" : ""}" data-fav="${i.id}">${ICONS.heart}</span>`;
  const badge = i.soldOut ? `<span class="special-badge" style="background:var(--ink-2)">${esc(t("sold_out"))}</span>`
    : (i.special ? `<span class="special-badge">${esc(t("daily_special"))}</span>` : "");
  const metaR = i.rating ? `${i.rating}★` : "";
  if (idx % 2 === 0) return `
    <a class="bs-card a ${i.soldOut ? "sold" : ""}" href="${esc(itemHref(i.id))}" data-open="${i.id}" style="animation-delay:${Math.min(idx * 50, 350)}ms;color:inherit;text-decoration:none">
      ${badge}${favBtn}${i.schedule_label ? `<span class="bs-hh">${esc(i.schedule_label)}</span>` : ""}
      <img class="bs-thumb" src="${img(i.gallery[0])}" alt="" loading="lazy">
      <span class="bs-name">${esc(name(i))}</span>
      <span class="bs-meta">${metaR ? `<span class="star">${metaR}</span> · ` : ""}${i.time} ${esc(t("min_short"))}</span>
      <span class="bs-foot">
        <span class="bs-pricewrap">${i.original_price && i.original_price > i.price ? `<del class="bs-was" style="opacity:.55;font-weight:400;margin-inline-end:.35em">${fmt(i.original_price)}</del>` : ""}<span class="bs-price">${fmt(i.price)}</span></span>
        ${i.soldOut ? "" : `<span class="bs-buy" data-add="${i.id}">${esc(t("buy_now"))}</span>`}
      </span>
    </a>`;
  return `
    <a class="bs-card b ${i.soldOut ? "sold" : ""}" href="${esc(itemHref(i.id))}" data-open="${i.id}" style="animation-delay:${Math.min(idx * 50, 350)}ms;color:inherit;text-decoration:none">
      ${badge}${favBtn}${i.schedule_label ? `<span class="bs-hh">${esc(i.schedule_label)}</span>` : ""}
      <img class="bs-photo" src="${img(i.gallery[0])}" alt="" loading="lazy">
      <span class="bs-shade"></span>
      <span class="bs-copy">
        <span class="bs-name">${esc(name(i))}</span>
        <span class="bs-meta">${metaR ? metaR + " · " : ""}${i.kcal ? i.kcal + " " + esc(t("kcal")) : i.time + " " + esc(t("min_short"))}</span>
        <span class="bs-pricewrap">${i.original_price && i.original_price > i.price ? `<del class="bs-was" style="opacity:.55;font-weight:400;margin-inline-end:.35em">${fmt(i.original_price)}</del>` : ""}<span class="bs-price">${fmt(i.price)}</span></span>
      </span>
      ${i.soldOut ? "" : `<span class="bs-add" data-add="${i.id}">${ICONS.plus}</span>`}
    </a>`;
}

/* Parent chip (S.cat) matches the item's top-level ancestor 'root' so a parent
   shows every descendant; the sub-chip (S.sub) narrows to the real category id.
   passFilters() then stacks the dietary/allergen/spice filters on top. */
function homeItems() {
  return ITEMS.filter(i => {
    if (S.cat !== "all" && String(i.root) !== String(S.cat)) return false;
    if (S.sub !== "root" && String(i.cat) !== String(S.sub)) return false;
    if (!passFilters(i)) return false;
    return true;
  });
}
function renderGrid() {
  const grid = $("#grid"); if (!grid) return;   // `bestsellers` section hidden by the customizer
  updateGridTitle();
  const fd = $("#filterDot"); if (fd) fd.hidden = !filtersActive();
  const list = homeItems();
  grid.innerHTML = list.length ? list.map(bsCardHTML).join("")
    : `<div class="grid-empty"><span class="empty-emoji">🍽️</span><p>${esc(t("so_empty"))}</p></div>`;
}
function syncFilterCount() {
  const fc = $("#filterCount"); if (fc) fc.textContent = homeItems().length;
  const fd = $("#filterDot"); if (fd) fd.hidden = !filtersActive();
}
function resetFilters() {
  S.filters = { diets: {}, allergens: {}, spice: 0 };
  $$("#dietRow [data-diet], #spiceRow [data-spice], #allergenRow [data-al]").forEach(b => b.classList.remove("is-on"));
  syncFilterCount();
}

/* ── search overlay ───────────────────────────────────── */
function openSearch() {
  const ov = $("#searchOv");
  S.search = ""; $("#soInput").value = "";   // never reopen onto a stale result set
  ov.hidden = false;
  requestAnimationFrame(() => ov.classList.add("is-open"));
  renderSearch();
  setTimeout(() => $("#soInput").focus(), 80);
}
function closeSearch() {
  const ov = $("#searchOv");
  ov.classList.remove("is-open");
  setTimeout(() => { ov.hidden = true; }, 300);
}
function renderSearch() {
  $("#soChips").innerHTML = SUGGESTS.map(s2 => `<button data-sochip="${esc(s2)}">${esc(s2)}</button>`).join("");
  const q = S.search.trim().toLowerCase();
  $("#soSuggest").hidden = !!q;
  const res = $("#soResults");
  if (!q) { res.innerHTML = ""; return; }
  const list = ITEMS.filter(i => passFilters(i) && (i.name + " " + i.desc).toLowerCase().includes(q));
  res.innerHTML = list.length ? list.map((i, idx) => `
    <button class="so-row" data-open="${i.id}" style="animation-delay:${idx * 35}ms">
      <img src="${img(i.gallery[0])}" alt="">
      <b>${esc(name(i))}<small>${i.rating ? i.rating + "★ · " : ""}${i.time} ${esc(t("min_short"))}</small></b>
      <span class="price">${fmt(i.price)}</span>
      ${ICONS.chev}
    </button>`).join("")
    : `<p class="so-empty">${esc(t("so_empty"))}</p>`;
}

/* ── foods list ───────────────────────────────────────── */
function foodItemRow(i, idx) {
  return `
    <button class="food-row" data-open="${i.id}" style="animation-delay:${idx * 45}ms">
      <img src="${img(i.gallery[0])}" alt="" loading="lazy">
      <b>${esc(name(i))}<small>${fmt(i.price)}${i.rating ? " · " + i.rating + "★" : ""}</small></b>
      ${ICONS.chev}
    </button>`;
}
function renderFoods() {
  const box = $("#foodRows");
  if (S.foodFilter && FOOD_GROUPS[S.foodFilter]) {
    const g = FOOD_GROUPS[S.foodFilter];
    box.innerHTML = `
      <button class="food-row crumb" data-fcat="">
        <span class="backchev">${ICONS.chev}</span>
        <b>${esc(t("all_foods"))}<small>${esc(g.name)}</small></b>
      </button>` +
      g.items.map((id, idx) => foodItemRow(item(id), idx + 1)).join("");
    return;
  }
  box.innerHTML = FOOD_ROWS.map((r, idx) => {
    const g = FOOD_GROUPS[r.id];
    if (!g) return "";
    return `
      <button class="food-row ${r.hl ? "hl" : ""}" data-fcat="${r.id}" style="animation-delay:${idx * 45}ms">
        <img src="${img(g.img)}" alt="" loading="lazy">
        <b>${esc(g.name)}<small>${g.items.length} ${esc(String(t("items")).toLowerCase())}</small></b>
        ${ICONS.chev}
      </button>`;
  }).join("");
}

/* ── product detail ───────────────────────────────────── */
function openProduct(id) { setProduct(id, { fresh: true }); show("product"); }

function setProduct(id, { fresh = false } = {}) {
  const i = item(id);
  if (!i) return;
  const same = S.currentItem?.id === i.id;
  S.currentItem = i;
  if (fresh || !same) {
    S.pQty = 1; S.pSel = {}; S.pImgIdx = 0; S.descOpen = false;
    for (const g of i.groups) S.pSel[g.id] = g.type === "radio" ? [0] : [];
  }

  $("#pName").textContent = name(i);
  $("#pStars").textContent = i.rating ? starsOf(i.rating) : "";
  $("#pImg").src = i.gallery[S.pImgIdx] ?? i.gallery[0] ?? DEFAULT_IMG;
  $("#pFav").classList.toggle("is-on", S.favs.has(i.id));
  $("#pDots").innerHTML = i.gallery.map((_, gi) => `<i data-dot="${gi}" class="${gi === S.pImgIdx ? "is-on" : ""}"></i>`).join("");
  /* R8: desktop/tap gallery arrows only make sense for a multi-image dish. */
  const multiImg = (i.gallery || []).length > 1;
  const galPrev = $("#pGalPrev"), galNext = $("#pGalNext");
  if (galPrev) galPrev.hidden = !multiImg;
  if (galNext) galNext.hidden = !multiImg;

  const meta = [`<span class="hmc">${ICONS.clock}${i.time} ${esc(t("m_delivery"))}</span>`];
  if (i.reviewTotal) meta.push(`<span class="hmc">💬 ${i.reviewTotal} ${esc(t("m_reviews"))}</span>`);
  if (i.rating) meta.push(`<span class="hmc">⭐ ${i.rating} ${esc(t("m_rating"))}</span>`);
  $("#pMeta").innerHTML = meta.join("");

  const d = $("#pDesc");
  d.textContent = desc(i);
  d.classList.toggle("open", S.descOpen);
  $("#readMore").hidden = !desc(i) || desc(i).length < 90;
  $("#readMore").textContent = t(S.descOpen ? "read_less" : "read_more");

  const tags = [
    i.vegan && `<span class="tag green">${ICONS.leaf}${esc(t("vegan"))}</span>`,
    i.halal && `<span class="tag green">☪ ${esc(t("halal"))}</span>`,
    i.glutenFree && `<span class="tag yellow">🌾 ${esc(t("gf"))}</span>`,
    i.spice > 0 && `<span class="tag orange">${"🌶".repeat(i.spice)}</span>`,
    i.kcal ? `<span class="tag">${ICONS.fire}${i.kcal} ${esc(t("kcal"))}</span>` : "",
    `<span class="tag">${ICONS.clock}${i.time} ${esc(t("prep"))}</span>`,
  ].filter(Boolean).join("");
  $("#pTags").innerHTML = tags;

  $("#pGroups").innerHTML = i.groups.map(groupHTML).join("") + (window.__mbItemReviewsOn !== false ? rateRowHTML() + reviewsHTML(i) : "");

  const rel = ITEMS.filter(x => x.id !== i.id && (x.cat === i.cat || x.popular)).slice(0, 6);
  $("#relRail").innerHTML = rel.map(r => `
    <button class="rail-card" data-open="${r.id}">
      <img src="${img(r.gallery[0])}" alt=""><b>${esc(name(r))}</b><span class="price">${fmt(r.price)}</span>
    </button>`).join("");

  const add = $("#addToCart");
  add.disabled = !!i.soldOut;
  const lbl = add.querySelector("[data-i18n]");
  if (lbl) lbl.textContent = i.soldOut ? t("sold_out") : t("add_cart");
  updateProductCta();
}

/* R8: step the product gallery to an image and keep the dots (and prev/next
   arrows) in sync. Wraps in both directions so the arrows never dead-end.
   Shared by the dot taps, the image tap and the arrow buttons. */
function showGalImg(idx) {
  const g = S.currentItem?.gallery ?? [];
  if (!g.length) return;
  S.pImgIdx = ((idx % g.length) + g.length) % g.length;
  $("#pImg").src = g[S.pImgIdx] || DEFAULT_IMG;
  $$("#pDots i").forEach((d, di) => d.classList.toggle("is-on", di === S.pImgIdx));
}

/* inline per-item quick-rate — the tapped star opens the shared review
   write-sheet (window.mbItemReviews) pre-selected, so a rating and a written
   review are one flow through one endpoint. Own id (#rateStars) so it never
   collides with the review write-sheet's own #revStars. */
function rateRowHTML() {
  return `<div class="rate-row"><span>${esc(t("rate_dish"))}</span>` +
    `<span class="rate-stars" id="rateStars">${[1, 2, 3, 4, 5].map(n => `<button data-rate="${n}" type="button" aria-label="${n}">★</button>`).join("")}</span>` +
    `<span class="rate-thanks" id="rateThanks" hidden>${esc(t("thanks"))}</span></div>`;
}

/* approved product reviews — the shared storefront layer hooks on the
   .product-reviews class for the histogram / write-review affordances. */
function reviewsHTML(i) {
  const list = i.reviewList || [];
  const head = `<div class="pr-head"><h3>${esc(t("reviews_title"))}</h3>${list.length ? `<span>${i.reviews || list.length}</span>` : ""}</div>`;
  if (!list.length) {
    return `<section class="product-reviews empty-reviews">${head}<p class="pr-empty">${esc(t("reviews_empty"))}</p></section>`;
  }
  return `<section class="product-reviews">${head}${list.map(review => {
    const stars = Math.max(1, Math.min(5, Number(review.stars) || 0));
    return `<article class="pr-card"><b>${esc(review.name)}</b><span class="review-stars">${"★".repeat(stars)}<span class="review-stars-dim">${"★".repeat(5 - stars)}</span></span>${review.body ? `<p>${esc(review.body)}</p>` : ""}</article>`;
  }).join("")}</section>`;
}

function groupHTML(g) {
  return `
  <div class="opt-group" data-group="${g.id}">
    <div class="opt-head"><b>${esc(name(g))}</b>
      <span class="opt-req ${g.required ? "" : "optional"}">${esc(t(g.required ? "required" : "optional"))}</span></div>
    <div class="opt-list">
      ${g.opts.map((o, oi) => {
        const priceTxt = g.variant ? fmt(o.abs) : (o.delta ? "+" + fmt(o.delta) : "");
        return `
      <div class="opt ${(S.pSel[g.id] ?? []).includes(oi) ? "is-on" : ""}" data-opt="${oi}">
        <span class="opt-ctl ${g.type === "check" ? "box" : ""}">${ICONS.check}</span>
        <span class="opt-name">${esc(name(o))}</span>
        <span class="opt-price">${priceTxt}</span>
      </div>`; }).join("")}
    </div>
  </div>`;
}

function updateProductCta() {
  const i = S.currentItem; if (!i) return;
  $("#pQtyVal").textContent = S.pQty;
  $("#pTotal").textContent = fmt(unitPrice(i, S.pSel) * S.pQty);
}

/* ── meal plan (client-side; writes scheduled_at at checkout) ── */
const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
const fmtDay = d => d.toLocaleDateString(locale(), { month: "short", day: "2-digit" });
function planDate() { return addDays(PLAN_BASE, S.weekOff * 7 + S.planDay); }

function renderPlan() {
  const label = $("#weekLabel"), row = $("#dayRow");
  if (!label || !row) return;   // `mealplan` section hidden by the customizer
  const start = addDays(PLAN_BASE, S.weekOff * 7);
  label.textContent = `${fmtDay(start)} - ${fmtDay(addDays(start, 6))}`;
  row.innerHTML = Array.from({ length: 7 }, (_, di) => {
    const d = addDays(start, di);
    return `<button class="day ${di === S.planDay ? "is-on" : ""}" data-day="${di}">${DOW[d.getDay()]}</button>`;
  }).join("");
}

function continuePlan() {
  const d = planDate();
  const label = d.toLocaleDateString(locale(), { weekday: "short", month: "short", day: "2-digit" });
  const [hh, mm] = S.planSlot.split(":");
  const iso = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")} ${String(hh).padStart(2, "0")}:${String(mm).padStart(2, "0")}:00`;
  S.plan = { label, time: S.planSlot, iso };
  S.when = "plan";
  // The chosen slot rides on the order as `scheduled_at`, which SchedulingService
  // validates server-side. It no longer pushes two arbitrary "recipe" items into
  // the bag on the customer's behalf — the customer chooses the food.
  toast(`${t("mp_done")} ${label} · ${S.planSlot}`);
  show(S.cart.length ? "cart" : "home");
}

/* Read-only view of the real program: balance, progress to the reward threshold,
   and the server's own ledger. No redeem button — the reward is a discount the
   server issues, and submitOrder has no field to carry it, so offering "redeem" in
   the storefront would be another promise nothing honours (see BLOCKED-SHARED). */
function renderPoints() {
  const L = S.loyalty;
  const bar = $("#ptsBar"), fill = $("#ptsBarFill"), hint = $("#ptsHint"), led = $("#rwLedger");
  if (!L || !L.active) {
    if (bar) bar.hidden = true;
    if (hint) hint.textContent = t("pts_earn");
    if (led) led.innerHTML = "";
    return;
  }
  if (bar) { bar.hidden = false; fill.style.width = Math.max(0, Math.min(100, Number(L.progress_pct) || 0)) + "%"; }
  if (hint) hint.textContent = L.ready_to_redeem ? t("pts_ready") : (L.reward_description || t("pts_earn"));
  if (led) {
    const rows = Array.isArray(L.ledger) ? L.ledger : [];
    led.innerHTML = rows.length ? rows.map(h => `
      <div class="hist-row">${Number(h.delta) >= 0 ? "🟢" : "🟠"}
        <span>${esc(h.note || h.type || "")}<small>${esc(h.created_at || "")}</small></span>
        <b class="${Number(h.delta) >= 0 ? "plus" : "minus"}">${Number(h.delta) >= 0 ? "+" : ""}${esc(String(h.delta))}</b>
      </div>`).join("") : "";
  }
}

/* ── cart ─────────────────────────────────────────────── */
function addToCart(id, sel, qty, fromEl) {
  if (!ORDERING_ON) return;
  const i = item(id);
  if (!i || i.soldOut) return;
  const key = id + "|" + JSON.stringify(sel);
  const line = S.cart.find(l => l.key === key);
  if (line) line.qty += qty; else S.cart.push({ key, id, qty, sel, note: "" });
  store.set("cart", S.cart);
  syncBadge(true);
  toast(`${name(i)} — ${t("added")}`);
  if (fromEl) flyToBag(fromEl);
}

/* ── account-page reorder handoff (P6-301) ────────────────
     The account page (account.blade.php) writes the reorder payload to
     cv_reorder_<slug> and redirects here. The shared helper reads it back and
     calls __picnicPickup for each line. We translate the choice-group selections
     (which carry labels) onto picnic's `sel[g.id] = [optIdx, ...]` shape — the
     group id is `'g' + db_id` (matches the @json group id passed by the blade).
     addToCart resets `note: ""`, so we push the line ourselves to preserve it. */
window.__picnicPickup = function (line) {
  const i = item(line.item_id);
  if (!i || i.soldOut) return false;
  const sel = {};
  /* the sheet's default: a required radio group starts on its first option */
  for (const g of i.groups) sel[g.id] = g.type === "radio" ? [0] : [];
  let bad = false;
  for (const g of i.groups) {
    if (g.variant) {
      const vi = g.opts.findIndex(function (o) { return Number(o.variantId) === Number(line.variant || 0); });
      if (vi >= 0) sel[g.id] = [vi]; else if (line.variant) bad = true;
    } else if (g.extra) {
      sel[g.id] = (line.extras || [])
        .map(function (e) { return g.opts.findIndex(function (o) { return Number(o.extraId) === Number(e.id); }); })
        .filter(function (oi) { return oi >= 0; });
    } else if (g.global) {
      const group = (line.options || []).find(function (o) { return Number(o.group_id) === Number(g.groupId); });
      const picks = ((group && group.selected) || [])
        .map(function (s) { return g.opts.findIndex(function (o) { return o.name === s.option; }); })
        .filter(function (oi) { return oi >= 0; });
      sel[g.id] = picks;
    }
  }
  if (bad) return false;
  /* a required group left unsatisfied would fail checkout */
  if (i.groups.some(function (g) {
    const need = g.required ? Math.max(1, g.min || 0) : (g.min || 0);
    return (sel[g.id] || []).length < need;
  })) return false;
  const key = line.item_id + "|" + JSON.stringify(sel);
  const existing = S.cart.find(function (l) { return l.key === key; });
  if (existing) { existing.qty += line.qty; }
  else { S.cart.push({ key: key, id: line.item_id, qty: line.qty, sel: sel, note: line.note || "" }); }
  store.set("cart", S.cart);
  syncBadge(true);
  toast(`${name(i)} — ${t("added")}`);
  return true;
};

function syncBadge(bump) {
  const n = cartCount() + comboCount(), b = $("#cartBadge");
  b.hidden = n === 0; b.textContent = n;
  if (bump && n) { b.classList.remove("bump"); void b.offsetWidth; b.classList.add("bump"); }
  /* product footer: a direct jump to the cart, shown once anything is in it */
  const pj = $("#pCartJump");
  if (pj) { pj.hidden = n === 0; const pjc = $("#pCartJumpCount"); if (pjc) pjc.textContent = n; }
}

/* COMBOS-V2 — read-only "Combos" group appended to the cart list and the
   checkout summary. Tapping a row reopens the combo sheet's basket view,
   which is where quantities are edited. */
function comboGroupHTML(compact) {
  const lines = comboLines();
  if (!lines.length) return "";
  let h = '<div class="pn-combo-group" data-combo-open style="border-top:1px dashed var(--line,#8883);padding-top:8px;margin-top:8px;cursor:pointer">';
  for (const L of lines) {
    h += `<div class="ck-row ck-row--combo">
      <div class="ck-mid"><b>${esc(L.name)}</b>${L.parts.length ? `<small>${esc(L.parts.join(" · "))}</small>` : ""}</div>
      <span class="ck-qty">×${L.qty}</span>
      <span class="ck-price">${fmt(L.price)}</span></div>`;
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
  const list = $("#cartList");
  const has = S.cart.length > 0 || comboCount() > 0;
  $("#cartEmpty").hidden = has;
  $("#cartTools").style.display = has ? "" : "none";
  $("#cartCta").hidden = !has;
  /* "clear cart" only clears real item lines — combos are cleared in their sheet */
  $("#clearCart").style.visibility = S.cart.length ? "" : "hidden";
  list.innerHTML = comboGroupHTML(false) + S.cart.map((l, li) => {
    const i = item(l.id); if (!i) return "";
    const opts = selSummary(i, l.sel);
    const unit = unitPrice(i, l.sel);
    /* Happy-hour strike-through: original_price is the pre-schedule BASE
       price, so the pre-discount unit is unit + (original − current base). */
    const wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
      ? unit + (Number(i.original_price) - Number(i.price)) : 0;
    return `
    <div class="cart-item" data-li="${li}" style="animation-delay:${li * 50}ms">
      <img src="${img(i.gallery[0])}" alt="" data-open="${i.id}" role="button" tabindex="0">
      <div>
        <div class="ci-name" data-open="${i.id}" role="button" tabindex="0">${esc(name(i))}</div>
        ${opts ? `<div class="ci-opts">${esc(opts)}</div>` : ""}
        ${l.note ? `<div class="ci-note">✎ ${esc(l.note)}</div>` : ""}
        <div class="ci-priceline"><span class="ci-price">${fmt(unit * l.qty)}</span>${
          wasUnit ? `<del class="ci-was">${fmt(wasUnit * l.qty)}</del>` : ""}${
          l.qty > 1 ? `<small class="ci-unit">${l.qty} × ${fmt(unit)}</small>` : ""}</div>
      </div>
      <div class="ci-side">
        <div class="ci-tools">
          <button class="ci-notebtn" data-note aria-label="Note">${ICONS.pen}</button>
          <button class="ci-remove" data-rm aria-label="${esc(I18N.remove || "Remove")}">${ICONS.trash}</button>
        </div>
        <div class="qty">
          <button class="qty-btn" data-cq="-1" aria-label="−"><svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg></button>
          <output>${l.qty}</output>
          <button class="qty-btn" data-cq="1" aria-label="+">${ICONS.plus}</button>
        </div>
      </div>
    </div>`;
  }).join("");
  renderOtFields(); renderSchedule(); renderTotals();
}

function renderOtFields() {
  const f = $("#otFields");
  $("#waiterRow").hidden = S.orderType !== "dinein";
  const check = '<svg class="ic" viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>';
  if (S.orderType === "dinein") {
    if (P.forceTable) {
      f.innerHTML = `<p class="ot-hint">${check}<span>${esc(t("table"))} ${esc(P.forceTable)}</span></p>`;
    } else {
      f.innerHTML = `<select id="tableSel">${Array.from({ length: 12 }, (_, n) =>
        `<option value="${n + 1}">${esc(t("table"))} ${String(n + 1).padStart(2, "0")}</option>`).join("")}</select>
        <p class="ot-hint">${check}<span>${esc(t("choose_table"))}</span></p>`;
    }
  } else if (S.orderType === "roomservice") {
    /* Hotel in-room dining: no table picker (the room came from the QR), no phone,
       no address — the room key rides along on the shared hotel partial's hidden
       `room` field. */
    f.innerHTML = `<p class="ot-hint">${check}<span>${esc(t("room_hint"))}</span></p>`;
  } else if (S.orderType === "delivery") {
    /* Full address widget in the cart — same autocomplete + use-my-location as
       checkout. Rebuilt on every order-type switch, so it is re-wired right
       here; the value syncs into checkout's #custAddr and picked coordinates
       land in the hidden #custLat/#custLng pair. */
    const ca = $("#custAddr");
    f.innerHTML = `<div class="addr-field" id="cartAddrField">
      <input id="cartAddrInput" value="${esc(ca ? ca.value : "")}" placeholder="${esc(t("addr_ph"))}" autocomplete="off" autocorrect="off" spellcheck="false">
      <button class="uselocbtn" id="cartUseLocation" type="button" aria-label="${esc(t("use_location"))}" title="${esc(t("use_location"))}">
        <svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>
      </button>
      <div class="addr-suggest" id="cartAddrSuggest" hidden></div></div>
      <p class="ot-hint">${check}<span>${esc(t("delivery_hint"))}</span></p>`;
    wireAddressField($("#cartAddrInput"), $("#cartAddrSuggest"), $("#cartUseLocation"), null, v => {
      const c = $("#custAddr"); if (c) c.value = v;
    });
  } else {
    f.innerHTML = `<p class="ot-hint">${check}<span>${esc(t("pickup_hint"))}</span></p>`;
  }
}

function renderSchedule() {
  const row = $("#schedRow"), chip = $("#planChip");
  const hasPlan = !!S.plan;
  if (row) row.hidden = hasPlan;
  chip.hidden = !hasPlan;
  if (hasPlan) chip.innerHTML = `📅 <span>${esc(t("plan_tag"))} · ${esc(S.plan.label)} · ${esc(S.plan.time)}</span><button class="plan-clear" data-planclear aria-label="Clear">✕</button>`;
}

/* Every line the customer is charged for is itemised, and the lines sum to the
   grand total in front of them — the tax row in particular was missing entirely. */
function totalsHTML(T, ctx) {
  T = T || cartTotals();
  const coupon = ctx ? ctx.coupon : S.coupon;
  const tipPct = ctx ? ctx.tipPct : S.tip;
  return `
    <div class="tr"><span>${esc(t("subtotal"))}</span><b>${fmt(T.sub)}</b></div>
    ${T.disc ? `<div class="tr disc"><span>${esc(t("discount"))}${coupon ? " (" + esc(coupon) + ")" : ""}</span><b>−${fmt(T.disc)}</b></div>` : ""}
    ${T.del ? `<div class="tr"><span>${esc(t("delivery_fee"))}</span><b>${fmt(T.del)}</b></div>` : ""}
    ${T.tax ? `<div class="tr"><span>${esc(taxRowLabel())}</span><b>${fmt(T.tax)}</b></div>` : ""}
    ${T.tip ? `<div class="tr"><span>${esc(t("tip_label"))}${tipPct ? " (" + tipPct + "%)" : ""}</span><b>${fmt(T.tip)}</b></div>` : ""}
    <div class="tr grand"><span>${esc(t("total"))}</span><b>${fmt(T.total)}</b></div>`;
}
function renderTotals() {
  $("#totalsBox").innerHTML = totalsHTML();
  $("#cartCtaTotal").textContent = fmt(cartTotals().total);
}

/* ── checkout ─────────────────────────────────────────── */
function renderCheckout() {
  $("#addrBlock").style.display = S.orderType === "delivery" ? "" : "none";
  const dc = $("#dineChip");
  dc.hidden = S.orderType !== "dinein";
  if (S.orderType === "dinein") {
    const tbl = P.forceTable || $("#tableSel")?.value || "1";
    dc.innerHTML = `🍽 <span>${esc(t("dinein_at"))} ${esc(String(tbl))}</span>`;
  }
  $("#pmList").innerHTML = PAY_METHODS.map(m => {
    const sub = m.id === "wallet" ? `${t("wallet_sub")} ${fmt(P.storeCredit)}` : "";
    return `
    <button class="pm ${S.pay === m.id ? "is-on" : ""}" data-pm="${m.id}">
      <span class="pm-ic">${ICONS[m.icon]}</span>
      <b>${esc(m.label)}${sub ? `<small>${esc(sub)}</small>` : ""}</b>
      <span class="pm-radio"></span>
    </button>`;
  }).join("");
  const note = $("#cardNote"); if (note) note.hidden = S.pay !== "card";
  /* Order summary — what the customer is about to pay for, tap Edit → bag.
     Built entirely here (no data-i18n attrs), so applyLang() can't clobber it. */
  const ck = $("#ckItems");
  if (ck) {
    ck.hidden = S.cart.length === 0 && comboCount() === 0;
    ck.innerHTML = `<div class="ck-head"><h3>${esc(t("your_order"))}</h3><button class="linkbtn green" id="ckEdit" type="button">${esc(t("edit"))}</button></div>` +
      S.cart.map(l => {
        const i = item(l.id); if (!i) return "";
        const unit = unitPrice(i, l.sel);
        const wasUnit = i.original_price && Number(i.original_price) > Number(i.price)
          ? unit + (Number(i.original_price) - Number(i.price)) : 0;
        const sum = selSummary(i, l.sel);
        return `<div class="ck-row">
          <img src="${img(i.gallery[0])}" alt="">
          <div class="ck-mid"><b>${esc(name(i))}</b>${sum ? `<small>${esc(sum)}</small>` : ""}</div>
          <span class="ck-qty">×${l.qty}</span>
          <span class="ck-price">${fmt(unit * l.qty)}${wasUnit ? `<del>${fmt(wasUnit * l.qty)}</del>` : ""}</span>
        </div>`;
      }).join("") + comboGroupHTML(true);
    const ed = $("#ckEdit"); if (ed) ed.onclick = () => show("cart");
  }
  $("#checkoutTotals").innerHTML = totalsHTML();
  $("#checkoutCtaTotal").textContent = fmt(cartTotals().total);
}

/* map demo order-type → server ordering-type */
const OT_MAP = { delivery: "delivery", pickup: "takeaway", dinein: "on-table", roomservice: "room_service" };

function buildOrderItems() {
  return S.cart.map(l => {
    const i = item(l.id);
    let variantId = 0; const extras = []; const mods = [];
    for (const g of i.groups) {
      if (g.variant) { const idx = (l.sel[g.id] ?? [])[0]; if (idx != null && g.opts[idx]?.variantId) variantId = g.opts[idx].variantId; }
      if (g.extra) for (const idx of (l.sel[g.id] ?? [])) { if (g.opts[idx]?.extraId) extras.push({ id: g.opts[idx].extraId }); }
      // global option-groups → server re-derives price from group id + option index
      if (g.global) for (const idx of (l.sel[g.id] ?? [])) { if (g.opts[idx]) mods.push({ groupId: g.groupId, optIndex: g.opts[idx].optIndex }); }
    }
    return { id: i.id, quantity: l.qty, variants: variantId, extras, mods, order_note: l.note || "" };
  });
}

function placeOrder() {
  if (!$("#termsChk").checked) return toast(t("accept_terms"));
  const nm = $("#custName").value.trim();
  const ph = $("#custPhone").value.trim();
  /* dine-in and room service are both fulfilled on-premise: name only, no phone. */
  const onPrem = S.orderType === "dinein" || S.orderType === "roomservice";
  if (!onPrem && (!nm || !ph)) return toast(t("fill_contact"));
  if (onPrem && !nm) return toast(t("fill_contact"));
  if (S.orderType === "delivery" && !$("#custAddr").value.trim()) return toast(t("fill_addr"));
  const T = cartTotals();
  if (MIN_ORDER > 0 && T.sub < MIN_ORDER) return toast(`${t("min_order")} ${fmt(MIN_ORDER)}`);
  if (!S.cart.length && !comboCount()) return; /* combos-only orders are legal */

  const btn = $("#placeOrder");
  btn.disabled = true;
  btn.querySelector(".spinner").hidden = false;
  btn.querySelector(".po-label").textContent = "…";
  const restore = () => {
    btn.disabled = false;
    btn.querySelector(".spinner").hidden = true;
    btn.querySelector(".po-label").textContent = t("place_order");
  };

  const otype = OT_MAP[S.orderType] || "delivery";
  const fd = new FormData();
  fd.append("_token", P.csrf);
  fd.append("items", JSON.stringify(buildOrderItems()));
  fd.append("name", nm);
  fd.append("ordering-type", otype);
  if (otype === "on-table" || otype === "room_service") fd.append("table", P.forceTable || $("#tableSel")?.value || "1");
  if (otype !== "on-table" && otype !== "room_service") fd.append("phone-number", ph);
  if (otype === "delivery") fd.append("address", $("#custAddr").value.trim());
  fd.append("pay_via", (S.pay === "cash" || !P.allowPayment) ? "pay_on_counter" : "pay_online");
  fd.append("message", "");
  fd.append("tip_amount", (T.tip || 0).toFixed(MONEY_DP));
  if (S.coupon) fd.append("coupon_code", S.coupon);
  if (S.plan && S.plan.iso) fd.append("scheduled_at", S.plan.iso);
  else if (S.when === "later" && $("#whenTime")?.dataset.iso) fd.append("scheduled_at", $("#whenTime").dataset.iso);

  fetch(P.orderUrl, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } })
    .then(r => r.json())
    .then(res => {
      if (!res || !res.success) {
        restore();
        toast(safeServerMessage(res && res.message, t("order_failed")));
        return;
      }
      /* The order reference comes from the server or is not shown at all.
         submitOrder currently returns track_url + prep_eta but NO order id
         (BLOCKED-SHARED), so the chip stays hidden — it never invents one. */
      const ref = res.order_number || res.order_id || res.order || null;
      const order = {
        ref, type: S.orderType, total: T.total,
        items: S.cart.map(l => ({ id: l.id, qty: l.qty, sel: l.sel, note: l.note || "" })),
        trackUrl: res.track_url || null,
        prepEta: res.prep_eta || null,
        breakdown: T, coupon: S.coupon, tipPct: S.tip,
        status: "pending", placedAt: Date.now(),
      };
      if (order.trackUrl) { S.orders.unshift(order); store.set("orders", S.orders.slice(0, 20)); }
      /* R6: remember a guest's delivery address on this device so a returning guest
         can tap it next time instead of retyping (no-op for signed-in customers). */
      if (S.orderType === "delivery") {
        rememberAddress($("#custAddr").value, $("#custLat")?.value, $("#custLng")?.value);
        renderRecentAddrs();
      }
      S.activeOrder = order;
      S.cart = []; S.coupon = null; S.couponDisc = 0; S.plan = null; S.when = "asap"; S.tip = 0;
      store.set("cart", S.cart); syncBadge(false);

      if (res.message) { window.location.href = res.message; return; }
      if (res.whatsapp_url) { window.location.href = res.whatsapp_url; return; }

      restore();
      const codeEl = $("#confirmCode");
      codeEl.hidden = !ref;
      codeEl.textContent = ref ? t("order_no") + " #" + ref : "";
      $("#confirmEta").textContent = etaText(order);
      $("#confirmTotal").textContent = `${t("total")} ${fmt(T.total)}`;
      const ct = $("#confirmTrack");
      if (ct) { ct.href = order.trackUrl || "#"; ct.hidden = !order.trackUrl; }
      resetReview();
      show("confirmed");
      stack.length = 0;
      burstConfetti();
      startTracking(order);
    })
    .catch(() => { restore(); toast(t("net_failed")); });
}

/* Never render a raw PHP exception to a diner: only pass through short, plainly
   customer-facing server text. */
function safeServerMessage(msg, fallback) {
  const s = String(msg || "").replace(/<[^>]*>/g, " ").trim();
  if (!s || s.length > 160) return fallback;
  if (/Exception|SQLSTATE|Stack trace|\bfunction\b|::|\$this|\.php\b/i.test(s)) return fallback;
  return s;
}

/* Prep ETA comes from the server's EtaEstimator (or the store's static range) —
   the demo printed "now + 35 minutes" regardless of what the kitchen said. */
function etaText(order) {
  const e = order && order.prepEta;
  if (e && e.line) return e.line;                       // "Estimated ready in about 20–30 min"
  const range = (e && e.text) || P.prepEta || "";       // else the store's static range
  return range ? `${t("est_at")} ${range} ${t("min_short")}` : "";
}

function burstConfetti() {
  const colors = ["#f4581c", "#ffc93d", "#23a66d", "#17232e", "#ffffff"];
  $("#confetti").innerHTML = Array.from({ length: 30 }, (_, i) => {
    const tx = (Math.random() * 2 - 1) * 150, ty = -40 - Math.random() * 200;
    return `<i class="${Math.random() > .5 ? "round" : ""}" style="--tx:${tx}px;--ty:${ty}px;--c:${colors[i % colors.length]};--d:${Math.random() * .35}s;--rz:${Math.random() * 360}deg;--spin:${180 + Math.random() * 400}deg"></i>`;
  }).join("");
}

/* ── tracking — driven by the REAL order status ─────────
   The demo advanced the timeline on a 7-second setInterval: it reached
   "Delivered" 21 s after checkout whatever the kitchen was doing (reproduced on
   order #99590, still `pending` server-side while this screen said it had
   arrived). It now polls the order's own track page, which answers JSON with the
   real `status`, and NEVER moves a step the server has not reported. */
const STAGES = {
  delivery: ["stage_placed", "stage_kitchen", "stage_way", "stage_done"],
  pickup: ["stage_placed", "stage_kitchen", "stage_ready", "stage_picked"],
  dinein: ["stage_placed", "stage_kitchen", "stage_served", "stage_enjoy"],
  roomservice: ["stage_placed", "stage_kitchen", "stage_served", "stage_enjoy"],
};
/* Same map the server-rendered track page uses (track.blade.php STEP). */
const STATUS_STEP = { unpaid: -1, new: 0, pending: 0, preparing: 1, ready: 2, completed: 3, cancelled: -2 };
const stepOf = st => { const s = STATUS_STEP[st]; return s === undefined ? 0 : s; };

let trackTimer;
function renderTimeline(order) {
  if (!order) return;
  const stages = STAGES[order.type] || STAGES.delivery;
  const step = stepOf(order.status);
  /* No server-issued reference (see BLOCKED-SHARED) ⇒ show the time the order was
     placed, which is at least a true fact the diner can quote on the phone. */
  const code = $("#trackCode");
  if (code) code.textContent = order.ref ? "#" + order.ref
    : (STATUS_WORD(order.status) || new Date(order.placedAt).toLocaleTimeString(locale(), { hour: "2-digit", minute: "2-digit" }));
  const eta = $("#trackEta");
  if (eta) eta.textContent = step >= 3 ? "✓" : (etaText(order) || "—");
  $("#timeline").innerHTML = stages.map((sk, si) => {
    let st = "";
    if (order.status === "cancelled") st = si === 0 ? "cancelled" : "";
    else if (step >= 0) st = si < step ? "done" : (si === step ? (step === 3 ? "done" : "now") : "");
    return `<li class="tl ${st}">
      <span class="tl-node">${ICONS.check}</span>${si < stages.length - 1 ? '<span class="tl-line"></span>' : ""}
      <span class="tl-copy"><b>${esc(t(sk))}</b><small>${si === 0 ? new Date(order.placedAt).toLocaleTimeString(locale(), { hour: "2-digit", minute: "2-digit" }) : ""}</small></span>
    </li>`;
  }).join("");
  // Receipt: the amount the server charged, itemised exactly as at checkout.
  const box = $("#trackTotals");
  if (box) box.innerHTML = order.breakdown
    ? totalsHTML(order.breakdown, { coupon: order.coupon, tipPct: order.tipPct })
    : `<div class="tr grand"><span>${esc(t("total"))}</span><b>${fmt(order.total)}</b></div>`;
  const full = $("#trackFull");
  if (full) { full.href = order.trackUrl || "#"; full.hidden = !order.trackUrl; }
}
function STATUS_WORD(st) {
  if (st === "cancelled") return t("stage_cancelled");
  if (st === "unpaid") return t("stage_unpaid");
  return "";
}
function pollTrack(order) {
  if (!order || !order.trackUrl) return Promise.resolve();
  return fetch(order.trackUrl, { headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" } })
    .then(r => (r.ok ? r.json() : null))
    .then(res => {
      if (!res || !res.status || res.status === order.status) return;
      order.status = res.status;
      const idx = S.orders.indexOf(order);
      if (idx > -1) store.set("orders", S.orders);
      if (S.activeOrder === order && active === "track") renderTimeline(order);
      if (active === "profile") renderProfile();
    })
    .catch(() => {});
}
function startTracking(order) {
  clearInterval(trackTimer);
  renderTimeline(order);
  if (!order || !order.trackUrl) return;
  pollTrack(order);
  trackTimer = setInterval(() => {
    if (stepOf(order.status) >= 3 || order.status === "cancelled") { clearInterval(trackTimer); return; }
    pollTrack(order).then(() => renderTimeline(order));
  }, 15000);
}

/* review — the stars used to light up and say "Thanks for the feedback!" with no
   request of any kind, so every 1★ complaint was thanked and thrown away. They now
   POST to restaurant.submitNps (stars × 2 on the 0–10 NPS scale), debounced so a
   2★-on-the-way-to-5★ tap files once, and the thanks only appears on success. */
let revScore = 0, revTimer = null;
function buildReview() {
  $("#revStars").innerHTML = [1, 2, 3, 4, 5].map(n => `<button data-rate="${n}" aria-label="${n} stars">★</button>`).join("");
}
function resetReview() {
  revScore = 0;
  clearTimeout(revTimer);
  $$("#revStars button").forEach(b => b.classList.remove("lit"));
  $("#revGoogle").hidden = true;
  $("#revThanks").hidden = true;
}
function submitRating(score) {
  if (!P.npsUrl) return;
  clearTimeout(revTimer);
  revTimer = setTimeout(() => {
    const fd = new FormData();
    fd.append("_token", P.csrf);
    fd.append("score", String(Math.max(0, Math.min(10, score * 2))));
    fetch(P.npsUrl, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } })
      .then(r => (r.ok ? r.json() : null))
      .then(res => { $("#revThanks").hidden = !(res && res.success !== false); })
      .catch(() => { $("#revThanks").hidden = true; toast(t("net_failed")); });
  }, 700);
}

/* ── favorites / profile ──────────────────────────────── */
function renderFavs() {
  const favs = ITEMS.filter(i => S.favs.has(i.id));
  $("#favGrid").innerHTML = favs.map(bsCardHTML).join("");
  $("#favEmpty").hidden = favs.length > 0;
}

/* Order history: device-recorded orders (real track_url + the amount the server
   charged) merged with any rows a phone/email lookup returned from the server.
   Every row links to the REAL track page; none of them invents a reference. */
let lookupRows = [];
function orderRowHTML(o) {
  if (o.server) {
    return `<a class="order-row" href="${esc(o.track_url || "#")}">
      ${ICONS.bag}
      <div><b>#${esc(String(o.id))} · ${esc(o.total)}</b><small>${esc(o.date || "")}</small></div>
      <span class="order-status">${esc(t("stage_" + (o.status === "completed" ? "done" : o.status === "preparing" ? "kitchen" : "placed")) || o.status)}</span>
    </a>`;
  }
  const idx = S.orders.indexOf(o);
  const label = o.ref ? "#" + o.ref : t("this_device");
  return `<div class="order-row local">
    ${ICONS.bag}
    <div><b>${esc(label)} · ${fmt(o.total)}</b>
    <small>${o.items.reduce((n, l) => n + l.qty, 0)} × · ${esc(t("ot_" + o.type))}</small></div>
    <span class="order-tools">
      ${o.items.length ? `<button class="minibtn" data-reorder="${idx}">${esc(t("reorder"))}</button>` : ""}
      ${o.trackUrl ? `<a class="minibtn ghost" data-track="${idx}" href="${esc(o.trackUrl)}">${esc(t("track_order"))}</a>` : ""}
    </span>
  </div>`;
}
function renderProfile() {
  const L = $("#ordersList");
  const rows = [...S.orders.slice(0, 5), ...lookupRows];
  $("#ordersEmpty").hidden = rows.length > 0;
  L.innerHTML = rows.map(orderRowHTML).join("");
  const wa = $("#walletAmt"); if (wa) wa.textContent = fmt(P.storeCredit);
  syncPts();
}

/* Rebuild the exact cart of a past order — same item, same variant, same extras,
   same quantity, same note. */
function reorder(idx) {
  const o = S.orders[idx]; if (!o) return;
  let added = 0;
  for (const l of o.items) {
    const i = item(l.id); if (!i || i.soldOut) continue;
    const key = l.id + "|" + JSON.stringify(l.sel);
    const line = S.cart.find(x => x.key === key);
    if (line) line.qty += l.qty; else S.cart.push({ key, id: l.id, qty: l.qty, sel: l.sel, note: l.note || "" });
    added++;
  }
  if (!added) return toast(t("sold_out"));
  store.set("cart", S.cart); syncBadge(true);
  show("cart");
}

/* ── reservations + waitlist — REAL endpoints ──────────
   publicView.reservation.book creates a Reservation the restaurant sees;
   publicView.waitlist.join creates a WaitlistEntry and returns the real queue
   position. Both 404 unless the owner enabled the feature, which is exactly what
   the blade gates the sheet on. The demo wrote both to localStorage and told the
   diner they were booked, and invented a permanently-"Full" 20:00 slot. */
const RES_TIMES = ["18:00", "18:30", "19:00", "19:30", "20:00", "20:30"];
function resDates() {
  return [0, 1, 2, 3].map(n => {
    const d = addDays(new Date(), n);
    return n === 0 ? t("today") : d.toLocaleDateString(locale(), { weekday: "short", day: "2-digit" });
  });
}
/* ISO the server will accept for `reserved_at` (required|date|after:now). */
function resIso() {
  if (!S.resTime) return null;
  const d = addDays(PLAN_BASE, S.resDate);
  const [hh, mm] = S.resTime.split(":");
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")} ${hh}:${mm}:00`;
}
function renderReserveSheet() {
  const dates = $("#resDates"), times = $("#resTimes");
  if (dates) dates.innerHTML = resDates().map((lbl, di) =>
    `<button data-rd="${di}" class="${S.resDate === di ? "is-on" : ""}">${esc(lbl)}</button>`).join("");
  if (times) times.innerHTML = RES_TIMES.map(tm =>
    `<button data-rt="${tm}" class="${S.resTime === tm ? "is-on" : ""}">${tm}</button>`).join("");
  $("#resParty").textContent = S.resParty;
  const cta = $("#resConfirm");
  if (cta) cta.disabled = !S.resTime;
  const msg = $("#resMsg"); if (msg) { msg.hidden = true; msg.textContent = ""; }
}
function resMessage(text, err) {
  const msg = $("#resMsg"); if (!msg) return;
  msg.hidden = false; msg.className = "coupon-msg" + (err ? " err" : "");
  msg.textContent = text;
}

/* ── i18n apply (labels only; item text is server-localized) ── */
function applyLang() {
  $$("[data-i18n]").forEach(n => { const k = n.dataset.i18n; if (I18N[k] != null) n.textContent = I18N[k]; });
  $$("[data-i18n-ph]").forEach(n => { const k = n.dataset.i18nPh; if (I18N[k] != null) { n.placeholder = I18N[k]; if (n.hasAttribute("aria-label")) n.setAttribute("aria-label", I18N[k]); } });
  renderCats(); renderGrid(); renderFoods(); renderPlan(); renderPoints();
  renderCart(); renderCheckout(); renderProfile(); renderSearch();
  if (S.currentItem) setProduct(S.currentItem.id);
  if (S.activeOrder) renderTimeline(S.activeOrder);
  syncPts();
}

/* ── onboarding ───────────────────────────────────────── */
function obSlides() { return $$(".onb-slide"); }
function goSlide(i2) {
  const tr = $("#onbTrack"), sl = obSlides()[i2];
  if (!sl) return;
  $$("#onbDots i").forEach((d, di) => d.classList.toggle("is-on", di === i2));
  const from = tr.scrollLeft, to = sl.offsetLeft;
  if (RM || Math.abs(to - from) < 2) { tr.scrollLeft = to; return; }
  const t0 = performance.now(), dur = 460;
  const settle = setTimeout(() => { tr.scrollLeft = to; }, dur + 140);
  const step = now => {
    const p = Math.min((now - t0) / dur, 1), e = 1 - Math.pow(1 - p, 3);
    tr.scrollLeft = from + (to - from) * e;
    if (p < 1) requestAnimationFrame(step); else clearTimeout(settle);
  };
  requestAnimationFrame(step);
}
function finishOnb() { $("#onb").classList.add("is-done"); store.set("onbDone", 1); }
$("#onbTrack")?.addEventListener("scroll", () => {
  const tr = $("#onbTrack");
  const i2 = Math.min(2, Math.round(Math.abs(tr.scrollLeft) / tr.clientWidth));
  $$("#onbDots i").forEach((d, di) => d.classList.toggle("is-on", di === i2));
}, { passive: true });
$("#onbSkip")?.addEventListener("click", finishOnb);
$("#obNext1")?.addEventListener("click", () => goSlide(1));
$("#obStart")?.addEventListener("click", finishOnb);
$$("#onbDots i").forEach((d, di) => d.addEventListener("click", () => goSlide(di)));

/* ── events (delegated) ───────────────────────────────── */
function setCookie(k, v) { document.cookie = k + "=" + encodeURIComponent(v) + ";path=/;max-age=31536000"; }

document.addEventListener("click", e => {
  const T = e.target;
  const closest = s => T.closest(s);

  const fav = closest("[data-fav]");
  if (fav) {
    e.stopPropagation();
    e.preventDefault();
    const id = item(fav.dataset.fav)?.id;
    if (id == null) return;
    S.favs.has(id) ? S.favs.delete(id) : S.favs.add(id);
    store.set("favs", [...S.favs]);
    fav.classList.toggle("is-on");
    if (active === "favs") renderFavs();
    return;
  }
  const add = closest("[data-add]");
  if (add) {
    e.stopPropagation();
    e.preventDefault();
    const i = item(add.dataset.add); if (!i) return;
    // items with a required multi-select group can't be quick-added — open the modal
    if (needsConfig(i)) { openProduct(i.id); return; }
    const sel = {};
    for (const g of i.groups) sel[g.id] = g.type === "radio" ? [0] : [];
    addToCart(i.id, sel, 1, add);
    return;
  }
  const cq = closest("[data-cq]");
  if (cq) {
    const line = S.cart[+cq.closest(".cart-item").dataset.li];
    if (!line) return;
    line.qty += +cq.dataset.cq;
    if (line.qty <= 0) S.cart = S.cart.filter(l => l !== line);
    store.set("cart", S.cart);
    clearCouponMsg();
    syncBadge(false); renderCart();
    return;
  }
  const noteBtn = closest("[data-note]");
  if (noteBtn) {
    S.noteLi = +noteBtn.closest(".cart-item").dataset.li;
    $("#noteText").value = S.cart[S.noteLi]?.note ?? "";
    sheet("noteSheet");
    return;
  }
  const rm = closest("[data-rm]");
  if (rm) {
    const row = rm.closest(".cart-item");
    row.classList.add("removing");
    const line = S.cart[+row.dataset.li];
    setTimeout(() => {
      S.cart = S.cart.filter(l => l !== line);
      store.set("cart", S.cart);
      syncBadge(false); renderCart(); toast(t("removed"));
    }, 300);
    return;
  }

  const opt = closest(".opt");
  if (opt && S.currentItem) {
    const gid = opt.closest("[data-group]").dataset.group;
    const g = S.currentItem.groups.find(x => x.id === gid);
    const oi = +opt.dataset.opt;
    if (g.type === "radio") S.pSel[gid] = [oi];
    else {
      const set = new Set(S.pSel[gid]);
      set.has(oi) ? set.delete(oi) : set.add(oi);
      S.pSel[gid] = [...set];
    }
    opt.closest(".opt-list").querySelectorAll(".opt").forEach((o, i2) =>
      o.classList.toggle("is-on", S.pSel[gid].includes(i2)));
    updateProductCta();
    return;
  }
  const q = closest(".hero-qty[data-q]");
  if (q) { S.pQty = Math.max(1, S.pQty + +q.dataset.q); updateProductCta(); return; }
  const dot = closest("#pDots [data-dot]");
  if (dot && S.currentItem) {
    showGalImg(+dot.dataset.dot);
    return;
  }

  const day = closest("#dayRow [data-day]");
  if (day) {
    S.planDay = +day.dataset.day;
    $$("#dayRow .day").forEach(b => { b.classList.toggle("is-on", b === day); b.classList.remove("pop"); });
    void day.offsetWidth; day.classList.add("pop");
    return;
  }
  const slot = closest("#mpTimes [data-slot]");
  if (slot) {
    S.planSlot = slot.dataset.slot;
    $$("#mpTimes button").forEach(b => b.classList.toggle("is-on", b === slot));
    return;
  }
  if (closest("[data-planclear]")) { S.plan = null; S.when = "asap"; renderCart(); return; }

  const fcat = closest("[data-fcat]");
  if (fcat) { S.foodFilter = fcat.dataset.fcat || null; renderFoods(); return; }

  const sochip = closest("[data-sochip]");
  if (sochip) { S.search = sochip.dataset.sochip; $("#soInput").value = S.search; renderSearch(); return; }

  /* dietary / spice / allergen filter chips */
  const diet = closest("[data-diet]");
  if (diet) { S.filters.diets[diet.dataset.diet] = !S.filters.diets[diet.dataset.diet]; diet.classList.toggle("is-on"); syncFilterCount(); return; }
  const spice = closest("[data-spice]");
  if (spice) { const lvl = +spice.dataset.spice; S.filters.spice = (S.filters.spice === lvl) ? 0 : lvl; $$("#spiceRow [data-spice]").forEach(b => b.classList.toggle("is-on", +b.dataset.spice === S.filters.spice)); syncFilterCount(); return; }
  const al = closest("[data-al]");
  if (al) { S.filters.allergens[al.dataset.al] = !S.filters.allergens[al.dataset.al]; al.classList.toggle("is-on"); syncFilterCount(); return; }

  const ot = closest("#orderTypeSeg [data-ot]");
  if (ot) {
    S.orderType = ot.dataset.ot;
    $$("#orderTypeSeg button").forEach(b => b.classList.toggle("is-on", b === ot));
    clearCouponMsg();
    renderOtFields(); renderTotals();
    return;
  }
  const wh = closest("#whenSeg [data-when]");
  if (wh) {
    S.when = wh.dataset.when;
    $$("#whenSeg button").forEach(b => b.classList.toggle("is-on", b === wh));
    const sel2 = $("#whenTime");
    sel2.hidden = S.when !== "later";
    if (S.when === "later" && !sel2.options.length) {
      const opts = [];
      for (let m = 30; m <= 180; m += 30) {
        const d = new Date(Date.now() + m * 60000);
        const iso = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")} ${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}:00`;
        opts.push(`<option data-iso="${iso}">${d.toLocaleTimeString(locale(), { hour: "2-digit", minute: "2-digit" })}</option>`);
      }
      sel2.innerHTML = opts.join("");
    }
    if (S.when === "later" && sel2.selectedOptions[0]) sel2.dataset.iso = sel2.selectedOptions[0].dataset.iso;
    return;
  }

  const pm = closest("[data-pm]");
  if (pm) {
    S.pay = pm.dataset.pm;
    $$(".pm").forEach(b => b.classList.toggle("is-on", b === pm));
    const cn = $("#cardNote"); if (cn) cn.hidden = S.pay !== "card";
    return;
  }
  const tip = closest("#tipRow [data-tip]");
  if (tip) {
    S.tip = +tip.dataset.tip;
    $$("#tipRow button").forEach(b => b.classList.toggle("is-on", b === tip));
    clearCouponMsg();
    renderCheckout();
    return;
  }

  const rd = closest("#resDates [data-rd]");
  if (rd) { S.resDate = +rd.dataset.rd; renderReserveSheet(); return; }
  const rt = closest("#resTimes [data-rt]");
  if (rt) { S.resTime = rt.dataset.rt; renderReserveSheet(); return; }

  /* inline per-dish rating — light the tapped run of stars, then open the
     shared review write-sheet pre-selected. Scoped to #rateStars, distinct from
     the review write-sheet's own #revStars. */
  const qrate = closest("#rateStars [data-rate]");
  if (qrate && S.currentItem) {
    const qn = +qrate.dataset.rate;
    $$("#rateStars button").forEach((b, bi) => b.classList.toggle("is-on", bi < qn));
    if (window.mbItemReviews) window.mbItemReviews.open(qn);
    return;
  }

  const rate = closest("#revStars [data-rate]");
  if (rate) {
    revScore = +rate.dataset.rate;
    $$("#revStars button").forEach((b, bi) => b.classList.toggle("lit", bi < revScore));
    $("#revGoogle").hidden = revScore < 4;
    $("#revThanks").hidden = true;      // only shown once the server accepts it
    submitRating(revScore);
    return;
  }

  const reo = closest("[data-reorder]");
  if (reo) { reorder(+reo.dataset.reorder); return; }

  const lg = closest("[data-lang-code]");
  if (lg) { setCookie("Quick_user_lang_code", lg.dataset.langCode); window.location.reload(); return; }

  /* R7 — same-page ?item= links: grid card anchors (bs-card a/b) + CTA/share links.
     Checked AFTER the controls above so a click on a card's buy/heart (inside the
     card anchor, each preventDefaults it) wins. Same-pathname item links open in
     place; real external links full-load. Boot handles a cold ?item= / new-tab load. */
  const itemLink = closest('a[href*="item="]');
  if (itemLink && !itemLink.target) {
    try {
      const u = new URL(itemLink.getAttribute("href") || "", window.location.href);
      const iid = u.searchParams.get("item");
      if (iid && u.pathname === window.location.pathname && item(iid)) {
        e.preventDefault();
        openProduct(iid);
        return;
      }
    } catch (err) {}
  }

  const open = closest("[data-open]");
  if (open) {
    if (closest("#searchOv")) closeSearch();
    openProduct(open.dataset.open);
    return;
  }
  const go = closest("[data-go]");
  if (go) { show(go.dataset.go); return; }
  if (closest("[data-back]")) { back(); return; }
  const tab = closest(".tab");
  if (tab) {
    const _nt = tab.dataset.tab;
    // Menu jumps to the grid on home; search opens the search overlay. Neither is a view.
    if (_nt === "search") { openSearch(); return; }
    if (_nt === "menu") { if (active !== "home") show("home"); const g = document.querySelector('[data-mb-grid-lead]') || document.getElementById("grid"); if (g) g.scrollIntoView({ behavior: "smooth", block: "start" }); return; }
    show(_nt, { push: false }); return;
  }

  const cat = closest("[data-cat]");
  if (cat) {
    const v = cat.dataset.cat;
    S.cat = v === "all" ? "all" : (CATS.find(c => String(c.id) === String(v))?.id ?? S.cat);
    S.sub = "root";
    renderCats(); renderGrid();
    const circle = $(`#cats [data-cat="${S.cat}"]`);
    circle?.scrollIntoView({ behavior: RM ? "instant" : "smooth", inline: "center", block: "nearest" });
    if (circle) { circle.classList.add("pop"); setTimeout(() => circle.classList.remove("pop"), 500); }
    return;
  }
  const subChip = closest("[data-sub]");
  if (subChip) {
    S.sub = subChip.dataset.sub;
    renderSubChips(); renderGrid();
    return;
  }
});

/* single-purpose buttons */
/* Header globe: language sheet (rendered only for multi-language stores). */
$("#langBtn")?.addEventListener("click", () => { if ($("#langSheet")) sheet("langSheet"); });
/* Catering & Events: opens the dedicated bottom-sheet (picnic has no on()
   helper, so this mirrors the $("#id")?.addEventListener + sheet(id) pattern). */
$("#cvCateringBtn")?.addEventListener("click", () => { sheet("cvCateringSheet"); });
$("#searchBtn")?.addEventListener("click", openSearch);
$("#foodsSearch")?.addEventListener("click", openSearch);
$("#soClose")?.addEventListener("click", closeSearch);
$("#soInput")?.addEventListener("input", e => { S.search = e.target.value; renderSearch(); });
/* #promoHero carries data-open for the dish it actually advertises; the delegated
   [data-open] handler opens THAT item instead of an arbitrary first-with-a-photo. */
const openReserve = () => { S.resTime = null; renderReserveSheet(); sheet("reserveSheet"); };
$("#reserveBtn")?.addEventListener("click", openReserve);
$("#reserveRow")?.addEventListener("click", openReserve);
$("#resMinus")?.addEventListener("click", () => { S.resParty = Math.max(1, S.resParty - 1); $("#resParty").textContent = S.resParty; });
$("#resPlus")?.addEventListener("click", () => { S.resParty = Math.min(12, S.resParty + 1); $("#resParty").textContent = S.resParty; });

/* Real booking. The success line is printed from the SERVER's answer; a failure
   shows the server's own validation text and the sheet stays open. */
$("#resConfirm")?.addEventListener("click", e => {
  const btn = e.currentTarget;
  const nm = $("#resName").value.trim(), ph = $("#resPhone").value.trim();
  const iso = resIso();
  if (!nm) return resMessage(t("fill_contact"), true);
  if (!iso) return resMessage(t("time"), true);
  btn.disabled = true;
  const fd = new FormData();
  fd.append("_token", P.csrf);
  fd.append("name", nm);
  if (ph) fd.append("phone", ph);
  fd.append("party_size", String(S.resParty));
  fd.append("reserved_at", iso);
  fetch(P.reserveUrl, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } })
    .then(r => (r.ok ? { ok: true } : r.json().then(j => ({ ok: false, j })).catch(() => ({ ok: false }))))
    .then(r => {
      btn.disabled = false;
      if (!r.ok) { resMessage(safeServerMessage(r.j && (r.j.message || firstError(r.j)), t("order_failed")), true); return; }
      sheet("reserveSheet", false);
      toast(`✅ ${t("res_ok")}`);
    })
    .catch(() => { btn.disabled = false; resMessage(t("net_failed"), true); });
});

/* Real waitlist join — the position in the toast is the server's own number. */
$("#wlJoin")?.addEventListener("click", e => {
  const btn = e.currentTarget;
  const nm = $("#resName").value.trim(), ph = $("#resPhone").value.trim();
  if (!nm) return resMessage(t("fill_contact"), true);
  btn.disabled = true;
  const fd = new FormData();
  fd.append("_token", P.csrf);
  fd.append("name", nm);
  if (ph) fd.append("phone", ph);
  fd.append("party_size", String(S.resParty));
  fetch(P.waitlistUrl, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } })
    .then(r => (r.ok ? r.json().catch(() => ({})) : Promise.reject()))
    .then(d => {
      btn.disabled = false;
      sheet("reserveSheet", false);
      toast(d && d.position ? `⏳ ${t("wl_ok")} · ${t("wl_pos_n")} ${d.position}` : `⏳ ${t("wl_ok")}`);
    })
    .catch(() => { btn.disabled = false; resMessage(t("order_failed"), true); });
});
function firstError(j) {
  const errs = j && j.errors;
  if (!errs) return "";
  const k = Object.keys(errs)[0];
  return k ? [].concat(errs[k])[0] : "";
}
$("#noteSave")?.addEventListener("click", () => {
  const line = S.cart[S.noteLi];
  if (line) { line.note = $("#noteText").value.trim(); store.set("cart", S.cart); }
  sheet("noteSheet", false);
  renderCart();
});
$("#mpContinue")?.addEventListener("click", continuePlan);
$("#weekPrev")?.addEventListener("click", () => { if (S.weekOff > 0) { S.weekOff--; renderPlan(); } });
$("#weekNext")?.addEventListener("click", () => { S.weekOff++; renderPlan(); });
$("#readMore")?.addEventListener("click", () => {
  S.descOpen = !S.descOpen;
  $("#pDesc").classList.toggle("open", S.descOpen);
  $("#readMore").textContent = t(S.descOpen ? "read_less" : "read_more");
});
$("#pImg")?.addEventListener("click", () => {
  if ((S.currentItem?.gallery ?? []).length < 2) return;
  showGalImg(S.pImgIdx + 1);
});
/* R8: prev/next gallery arrows (only visible for multi-image dishes; setProduct
   toggles their [hidden]). stopPropagation so the tap doesn't also fire the
   image-tap advance above. */
$("#pGalPrev")?.addEventListener("click", e => { e.stopPropagation(); showGalImg(S.pImgIdx - 1); });
$("#pGalNext")?.addEventListener("click", e => { e.stopPropagation(); showGalImg(S.pImgIdx + 1); });
$("#pFav")?.addEventListener("click", () => {
  const id = S.currentItem.id;
  S.favs.has(id) ? S.favs.delete(id) : S.favs.add(id);
  store.set("favs", [...S.favs]);
  $("#pFav").classList.toggle("is-on");
});
$("#addToCart")?.addEventListener("click", e => {
  if (!S.currentItem || S.currentItem.soldOut) return;
  const miss = missingRequired(S.currentItem, S.pSel);
  if (miss) {
    toast(`${t("required")} · ${name(miss)}`);
    const gEl = $(`#pGroups [data-group="${miss.id}"]`);
    if (gEl) { gEl.classList.remove("needs"); void gEl.offsetWidth; gEl.classList.add("needs"); gEl.scrollIntoView({ behavior: RM ? "instant" : "smooth", block: "center" }); }
    return;
  }
  addToCart(S.currentItem.id, JSON.parse(JSON.stringify(S.pSel)), S.pQty, null);
  flyToBag(e.currentTarget);
});
$("#clearCart")?.addEventListener("click", () => {
  S.cart = []; store.set("cart", S.cart);
  syncBadge(false); renderCart();
});
$("#goCheckout")?.addEventListener("click", () => show("checkout"));
$("#placeOrder")?.addEventListener("click", placeOrder);
$("#backToMenu")?.addEventListener("click", () => show("home", { push: false }));
$("#couponApply")?.addEventListener("click", () => {
  const code = $("#couponInput").value.trim();
  const msg = $("#couponMsg");
  if (!code) return;
  msg.hidden = false; msg.className = "coupon-msg"; msg.textContent = "…";
  const fd = couponForm(code);
  fetch(P.couponUrl, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } })
    .then(r => r.json())
    .then(res => {
      if (res && res.success) {
        S.coupon = res.label || code.toUpperCase();
        S.couponDisc = Number(res.discount || 0);
        S.couponSig = couponSig();
        msg.className = "coupon-msg";
        msg.textContent = `✓ ${t("coupon_ok")} ${fmt(S.couponDisc)}`;
        $(".coupon-field").classList.add("is-applied");
      } else {
        S.coupon = null; S.couponDisc = 0;
        msg.className = "coupon-msg err";
        msg.textContent = `✕ ${(res && res.message ? res.message.replace(/<[^>]*>/g, " ").trim() : t("coupon_bad"))}`;
        $(".coupon-field").classList.remove("is-applied");
      }
      renderTotals();
    })
    .catch(() => { msg.className = "coupon-msg err"; msg.textContent = `✕ ${t("coupon_bad")}`; });
});
$("#whenTime")?.addEventListener("change", e => { e.target.dataset.iso = e.target.selectedOptions[0]?.dataset.iso || ""; });
/* A stale "✓ Coupon applied" line under a total that has since moved is its own
   small lie — clear it whenever the basket, order type or tip changes. */
function clearCouponMsg() {
  const msg = $("#couponMsg"); if (msg) msg.hidden = true;
}
/* callTheWaiter VALIDATES `table` as required|integer — picnic only sent it when a
   QR forced the table, so an ordinary dine-in call failed validation while the UI
   still said a waiter was coming. It now always sends the chosen table, and the
   toast only fires on {success:true}. "Request bill" previously fired NO request
   at all; it posts with type=request_bill, which the same endpoint accepts. */
function waiterCall(type, okKey) {
  const table = P.forceTable || $("#tableSel")?.value || "";
  if (!table) return toast(t("choose_table"));
  const fd = new FormData();
  fd.append("_token", P.csrf);
  fd.append("table", String(table));
  fd.append("type", type);
  fetch(P.waiterUrl, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } })
    .then(r => (r.ok ? r.json() : null))
    .then(res => { if (res && res.success) toast(t(okKey)); else toast(safeServerMessage(res && res.message, t("order_failed"))); })
    .catch(() => toast(t("net_failed")));
}
$("#callWaiter")?.addEventListener("click", () => waiterCall("call_waiter", "waiter_ok"));
$("#requestBill")?.addEventListener("click", () => waiterCall("request_bill", "bill_ok"));

/* Real guest order lookup — returns the store's own order ids, totals and statuses. */
$("#lookupGo")?.addEventListener("click", () => {
  const id = $("#lookupInput").value.trim();
  const msg = $("#lookupMsg");
  if (!id) return;
  msg.hidden = false; msg.className = "coupon-msg"; msg.textContent = "…";
  const fd = new FormData();
  fd.append("_token", P.csrf);
  fd.append("identifier", id);
  fetch(P.lookupUrl, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" } })
    .then(r => r.json())
    .then(res => {
      if (res && res.success && Array.isArray(res.orders) && res.orders.length) {
        lookupRows = res.orders.map(o => ({ ...o, server: true }));
        msg.hidden = true;
      } else {
        lookupRows = [];
        msg.className = "coupon-msg err";
        msg.textContent = safeServerMessage(res && res.message, t("lookup_none"));
      }
      renderProfile();
    })
    .catch(() => { msg.className = "coupon-msg err"; msg.textContent = t("net_failed"); });
});

/* Appearance — Auto follows the OS, Light/Dark pin it; the choice persists. */
function applyMode() {
  const r = document.documentElement;
  if (S.mode === "auto") r.removeAttribute("data-picnic-mode");
  else r.setAttribute("data-picnic-mode", S.mode);
  $$("#themeSeg button").forEach(b => b.classList.toggle("is-on", b.dataset.mode === S.mode));
}
$("#themeSeg")?.addEventListener("click", e => {
  const b = e.target.closest("[data-mode]"); if (!b) return;
  S.mode = b.dataset.mode; store.set("mode", S.mode); applyMode();
});

/* topbar quick toggle (Lane B): flip the EFFECTIVE mode (explicit choice, else
   the OS preference) into an explicit one via the same S.mode/store path, so
   Profile's Auto/Light/Dark segment stays in sync. */
$("#picnicThemeToggle")?.addEventListener("click", () => {
  const isDark = (S.mode === "dark" || S.mode === "light") ? S.mode === "dark"
    : !!(window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches);
  S.mode = isDark ? "light" : "dark"; store.set("mode", S.mode); applyMode();
  $("#picnicThemeToggle")?.setAttribute("aria-pressed", String(!isDark));
});

/* ── display-currency switcher (velora parity) ─────────────────────────
   Repaints every price surface through fmt() at the chosen rate (≈-prefixed on
   non-base). Currency is display-only — it never changes the amount the server
   charges. Persistence is store-scoped via the shared mbCurrency helper (replayed
   in boot). Not gated on allow_order: a view-only menu still shows prices. */
function rerenderMoney() {
  renderGrid(); renderFoods(); renderPlan(); renderPoints();
  renderCart(); renderCheckout(); renderProfile(); renderSearch();
  if (S.currentItem) setProduct(S.currentItem.id);
  if (S.activeOrder) renderTimeline(S.activeOrder);
}
$("#curSeg")?.addEventListener("click", e => {
  const b = e.target.closest("[data-cur]"); if (!b) return;
  $$("#curSeg button").forEach(x => x.classList.toggle("is-on", x === b));
  S.curCode = b.dataset.cur;
  S.curRate = parseFloat(b.dataset.rate) || 1;
  if (window.mbCurrency) mbCurrency.save(S.curCode, S.curRate);   // persist store-scoped (shared helper)
  rerenderMoney();
});

/* Onboarding: continue as a guest (the three fake social buttons are gone). */
$("#obGuest")?.addEventListener("click", () => goSlide(2));

/* dietary / allergen / spice filter sheet */
$("#filterBtn")?.addEventListener("click", () => { syncFilterCount(); sheet("filterSheet"); });
$("#filterDone")?.addEventListener("click", () => { sheet("filterSheet", false); renderGrid(); });
$("#filterReset")?.addEventListener("click", () => { resetFilters(); renderGrid(); });

/* ── nearest-branch map (self-hosted Leaflet; geo branch switcher) ── */
(function () {
  const openBtn = document.getElementById("nearestBranchBtn");
  if (!openBtn || !P.branchesGeo || typeof L === "undefined") { if (openBtn) openBtn.style.display = "none"; return; }
  let map = null, markers = [];
  const icon = L.icon({
    iconUrl: P.leafletImgPath + "/marker-icon.png",
    iconRetinaUrl: P.leafletImgPath + "/marker-icon-2x.png",
    shadowUrl: P.leafletImgPath + "/marker-shadow.png",
    iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41],
  });
  function selectBranch(id) { const url = new URL(window.location.href); url.searchParams.set("branch_id", id); window.location.href = url.toString(); }
  function renderList(branches) {
    const el = $("#branchGeoList"); if (!el) return;
    el.innerHTML = branches.map(b => {
      const dist = b.distance_km != null ? `<span class="branch-dist" dir="ltr">${b.distance_km.toFixed(1)} km</span>` : "";
      return `<button class="branch-row" data-bid="${b.id}" type="button">
        <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
        <b>${esc(b.name)}${b.address ? `<small>${esc(b.address)}</small>` : ""}</b>${dist}
        <span class="branch-check"></span></button>`;
    }).join("");
  }
  function drawMap(branches) {
    const withCoords = branches.filter(b => b.latitude != null && b.longitude != null);
    if (!map) {
      map = L.map("branchMap", { attributionControl: false });
      L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 }).addTo(map);
      L.control.attribution({ prefix: false }).addAttribution("© OpenStreetMap").addTo(map);
    }
    markers.forEach(m => map.removeLayer(m)); markers = [];
    if (!withCoords.length) { map.setView([24.7136, 46.6753], 11); return; } // Riyadh fallback
    const group = [];
    withCoords.forEach(b => {
      const mk = L.marker([b.latitude, b.longitude], { icon }).addTo(map);
      mk.bindPopup(`<b>${esc(b.name)}</b>`);
      mk.on("click", () => selectBranch(b.id));
      markers.push(mk); group.push([b.latitude, b.longitude]);
    });
    map.fitBounds(group, { padding: [40, 40], maxZoom: 14 });
  }
  function load(lat, lng) {
    const url = P.branchesGeo + (lat != null ? "?lat=" + lat + "&lng=" + lng : "");
    fetch(url).then(r => r.json()).then(d => {
      const branches = (d && d.branches) || [];
      const at = $("#branchMapAttrib"); if (at) at.textContent = (d && d.attribution) || "© OpenStreetMap contributors";
      renderList(branches);
      setTimeout(() => { drawMap(branches); if (map) map.invalidateSize(); }, 60);
    }).catch(() => {});
  }
  openBtn.addEventListener("click", () => {
    sheet("branchMapSheet");
    if (navigator.geolocation) navigator.geolocation.getCurrentPosition(p => load(p.coords.latitude, p.coords.longitude), () => load(), { timeout: 6000 });
    else load();
  });
  const gl = $("#branchGeoList");
  if (gl) gl.addEventListener("click", e => { const b = e.target.closest("[data-bid]"); if (b) selectBranch(b.dataset.bid); });
})();

/* ── stories (rail + full-screen viewer) ──────────────── */
/* The rail is populated from the public stories feed. Empty feed / network
   error ⇒ rail stays [hidden] so it contributes no gap above the cat chips. */
(function () {
  const rail = $("#storiesRail");
  if (!rail || !P.storiesUrl) return;
  let stories = [], idx = 0, timer = null, T = 0;
  const DUR = 5; // seconds per story

  (window.mbStoriesGet?window.mbStoriesGet(P.storiesUrl):fetch(P.storiesUrl).then(function(r){return r.json();})).then(d => {
    stories = (d && d.data) || [];
    if (!stories.length) { rail.hidden = true; return; }
    rail.hidden = false;
    rail.innerHTML = stories.map((s, i) =>
      `<button class="story" data-si="${i}" type="button">` +
      `<span class="story-ring"><img src="${img(s.media_url)}" alt=""></span>` +
      `<small>${esc(s.caption ? String(s.caption).slice(0, 14) : t("stories"))}</small></button>`
    ).join("");
  }).catch(() => { rail.hidden = true; });

  const viewer = $("#storyView"), media = $("#svMedia"), cap = $("#svCaption"),
        cta = $("#svCta"), prog = $("#svProgress");
  if (!viewer) return;

  function buildProgress() { prog.innerHTML = stories.map(() => '<span class="sv-seg"><i></i></span>').join(""); }
  function paintSeg() {
    $$("#svProgress .sv-seg i").forEach((bar, i) => {
      bar.style.width = i < idx ? "100%" : (i === idx ? (T / DUR * 100) + "%" : "0%");
    });
  }
  function render() {
    const s = stories[idx]; if (!s) return;
    media.src = s.media_url;
    cap.textContent = s.caption || "";
    if (s.cta_url && !(window.mbStoryCta && window.mbStoryCta.isCurrentPage(s.cta_url))) {
      cta.hidden = false; cta.href = s.cta_url;
 if (window.mbStoryCta) window.mbStoryCta.apply(cta, s.cta_url);
      cta.querySelector("span").textContent = s.cta_label || t("order_now");
    } else { cta.hidden = true; }
    T = 0; paintSeg();
  }
  function tick() { T += 0.05; if (T >= DUR) { next(); return; } paintSeg(); }
  function start() { stop(); render(); timer = setInterval(tick, 50); }
  function stop() { if (timer) { clearInterval(timer); timer = null; } }
  function open(i) {
    idx = i; buildProgress();
    viewer.hidden = false;
    void viewer.offsetWidth; // forced reflow so the fade transition runs
    viewer.classList.add("is-open");
    start();
  }
  function close() {
    stop();
    viewer.classList.remove("is-open");
    setTimeout(() => { viewer.hidden = true; }, 300);
  }
  /* next/prev are chronological, not spatial: in RTL the .prev / .next tap
     zones swap sides via inset-inline-start/end, so "next" still moves forward. */
  function next() { if (idx < stories.length - 1) { idx++; render(); } else { close(); } }
  function prev() { if (idx > 0) { idx--; render(); } else { T = 0; paintSeg(); } }

  rail.addEventListener("click", e => {
    const b = e.target.closest("[data-si]"); if (b) open(Number(b.dataset.si));
  });
  $("#svClose").addEventListener("click", close);
  /* CTA opens the item in-page beneath this viewer; close the viewer so the item sheet is the top layer. */
  if (cta) cta.addEventListener("click", close);
  $("#svNext").addEventListener("click", next);
  $("#svPrev").addEventListener("click", prev);
  document.addEventListener("keydown", e => { if (e.key === "Escape" && !viewer.hidden) close(); });
  const stage = $("#svStage");
  if (stage) {
    stage.addEventListener("pointerdown", stop);
    stage.addEventListener("pointerup", () => { if (!viewer.hidden && !timer) timer = setInterval(tick, 50); });
  }
})();

/* ── delivery address autocomplete + use-my-location + saved addresses ──
   Wires #custAddr to geo.autocomplete / geo.reverse (GeoController). The picked
   pin's coords ride in the hidden #custLat/#custLng; typing invalidates them.
   Response shapes: autocomplete {results:[{label,lat,lng}], attribution},
   reverse {result:{label,lat,lng}, attribution}. */
function wireAddressField(input, box, locBtn, attrib, onValue) {
  if (!input || !P.geoAutocomplete) return;
  let timer = null, lastAttrib = "";
  const setAttrib = a => { if (a && attrib && a !== lastAttrib) { attrib.textContent = a; attrib.hidden = false; lastAttrib = a; } };
  const hideBox = () => { if (box) { box.hidden = true; box.innerHTML = ""; } };
  const setCoords = (lat, lng) => { const la = $("#custLat"), ln = $("#custLng"); if (la) la.value = lat || ""; if (ln) ln.value = lng || ""; };
  const setVal = v => { input.value = v; if (onValue) onValue(v); };
  input.addEventListener("input", () => {
    if (onValue) onValue(input.value);       // keep cart/checkout address in sync
    const q = input.value.trim();
    setCoords("", "");                       // typing invalidates a picked pin
    if (timer) clearTimeout(timer);
    if (q.length < 3) { hideBox(); return; }
    timer = setTimeout(() => {
      fetch(P.geoAutocomplete + "?q=" + encodeURIComponent(q))
        .then(r => r.json())
        .then(d => {
          setAttrib(d && d.attribution);
          const res = (d && d.results) || [];
          if (!res.length || !box) { hideBox(); return; }
          box.innerHTML = res.map(r =>
            `<button class="addr-opt" data-lat="${r.lat || ""}" data-lng="${r.lng || ""}" data-label="${esc(r.label)}" type="button">${esc(r.label)}</button>`).join("");
          box.hidden = false;
        }).catch(hideBox);
    }, 350);
  });
  if (box) box.addEventListener("click", e => {
    const opt = e.target.closest(".addr-opt"); if (!opt) return;
    setVal(opt.dataset.label || "");
    setCoords(opt.dataset.lat, opt.dataset.lng);
    hideBox();
  });
  input.addEventListener("blur", () => setTimeout(hideBox, 200));
  if (locBtn) locBtn.addEventListener("click", () => {
    if (!navigator.geolocation) { toast(t("loc_unavailable")); return; }
    locBtn.classList.add("is-loading");
    navigator.geolocation.getCurrentPosition(pos => {
      const lat = pos.coords.latitude, lng = pos.coords.longitude;
      fetch((P.geoReverse || "") + "?lat=" + lat + "&lng=" + lng)
        .then(r => r.json())
        .then(d => {
          locBtn.classList.remove("is-loading");
          setAttrib(d && d.attribution);
          if (d && d.result) { setVal(d.result.label || ""); setCoords(d.result.lat || lat, d.result.lng || lng); }
          else { toast(t("no_address")); }
        }).catch(() => locBtn.classList.remove("is-loading"));
    }, () => { locBtn.classList.remove("is-loading"); toast(t("loc_denied")); }, { enableHighAccuracy: true, timeout: 8000 });
  });
}
/* ── guest multi-address (remember delivery addresses used on this device) ──
   Signed-in customers already have a server address book (rendered into #savedAddrs
   by the blade); guests instead get the last few addresses they typed, as tappable
   chips at checkout. Storage rides the already-initialised `store` helper (key
   picnic_<slug>_recent_addrs) — read at CALL time, never through a `var KEY = …`
   declared after the address IIFE below, which would be undefined when that IIFE
   runs renderRecentAddrs() at load (the var-hoist trap). Guests only, so this never
   fights the server book. */
function readRecentAddrs() {
  try { return (store.get("recent_addrs", []) || []).filter(a => a && a.addr); }
  catch (e) { return []; }
}
function rememberAddress(addr, lat, lng) {
  addr = (addr || "").trim();
  if (!addr || P.isLoggedIn) return;
  const list = readRecentAddrs().filter(a => a.addr !== addr);
  list.unshift({ addr, lat: lat || "", lng: lng || "" });
  store.set("recent_addrs", list.slice(0, 5));
}
function renderRecentAddrs() {
  const saved = $("#savedAddrs");
  if (!saved || P.isLoggedIn) return;
  const list = readRecentAddrs();
  if (!list.length) { saved.hidden = true; return; }
  saved.hidden = false;
  saved.innerHTML = list.map((a, i) =>
    `<button class="saved-addr${i === 0 ? " is-on" : ""}" type="button" data-addr="${esc(a.addr)}" data-lat="${esc(a.lat)}" data-lng="${esc(a.lng)}">` +
    '<svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
    `<span class="sa-text"><b>${esc(t("delivery_address"))}</b><small>${esc(a.addr.length > 42 ? a.addr.slice(0, 42) + "…" : a.addr)}</small></span></button>`
  ).join("");
}
(function () {
  const input = $("#custAddr");
  if (!input) return;
  wireAddressField(input, $("#custAddrSuggest"), $("#custUseLocation"), $("#custGeoAttrib"), null);
  const saved = $("#savedAddrs");
  if (saved) saved.addEventListener("click", e => {
    const b = e.target.closest(".saved-addr"); if (!b) return;
    input.value = b.dataset.addr || "";
    const la = $("#custLat"), ln = $("#custLng");
    if (la) la.value = b.dataset.lat || ""; if (ln) ln.value = b.dataset.lng || "";
    $$(".saved-addr").forEach(x => x.classList.remove("is-on"));
    b.classList.add("is-on");
    const box = $("#custAddrSuggest"); if (box) { box.hidden = true; box.innerHTML = ""; }
  });
})();

/* tabbar hide on scroll down (roots) */
let lastY = 0;
$$(".view-scroll").forEach(sc => sc.addEventListener("scroll", () => {
  if (!ROOTS.includes(active)) return;
  const y = sc.scrollTop;
  $("#tabbar").classList.toggle("is-hidden", y > lastY + 4 && y > 130);
  if (y < lastY - 4 || y < 130) $("#tabbar").classList.remove("is-hidden");
  lastY = y;
}, { passive: true }));

/* ── boot ─────────────────────────────────────────────── */
function boot() {
  /* default order type: room service > forced table > first enabled.
     The room-service control only renders when the scanned QR resolved to a hotel
     room, so its presence IS the "this guest is in a room" signal. A room QR also
     sets P.forceTable, so without this the guest boots on dine-in and the order
     reaches the kitchen as a table order. NB theme-local name: "roomservice",
     which OT_MAP turns into the server's "room_service" at submit time. */
  const roomBtn = $(`#orderTypeSeg [data-ot="roomservice"]`);
  const otBtn = roomBtn || $(`#orderTypeSeg [data-ot="${S.orderType}"]`) || $("#orderTypeSeg button");
  if (otBtn) { S.orderType = otBtn.dataset.ot; otBtn.classList.add("is-on"); }
  if (!roomBtn && P.forceTable && $(`#orderTypeSeg [data-ot="dinein"]`)) {
    S.orderType = "dinein";
    $$("#orderTypeSeg button").forEach(b => b.classList.toggle("is-on", b.dataset.ot === "dinein"));
  }

  buildReview();
  applyMode();
  applyLang();
  /* Replay a saved display-currency choice by clicking its #curSeg option, which
     runs the handler above and repaints prices (fixes reset-on-reload). No-op when
     nothing is saved or the store dropped that currency. Runs after applyLang()'s
     first full price render so the repaint lands on already-rendered surfaces. */
  if (window.mbCurrency) mbCurrency.restore("#curSeg");
  syncBadge(false);
  loadLoyalty();
  /* A1: a ?item=/?table= deep link opens straight on the product — dismiss the
     onboarding up front so it never overlays it (the layer sits above the sheet).
     NOT persisted (onbDone stays unset): a later plain visit still gets onboarding. */
  let onbDeepLinked = false;
  try { const dl = new URLSearchParams(location.search); onbDeepLinked = !!(dl.get("item") || dl.get("table")); } catch (e) {}
  if (store.get("onbDone", 0) || onbDeepLinked) $("#onb").classList.add("is-done");
  // Resume tracking the last order placed on this device — from the SERVER's
  // status, not from a timer that would have "delivered" it while we were away.
  const last = S.orders[0];
  if (last && last.trackUrl && stepOf(last.status) < 3 && last.status !== "cancelled") {
    S.activeOrder = last; startTracking(last);
  }

  /* R6: surface any addresses this guest used on a previous visit (localStorage). */
  renderRecentAddrs();

  /* R7: open a shared / deep-linked product (?item=<id>) in place on load — a link
     that lands on the storefront with ?item= opens that dish straight away. */
  try {
    const deepItem = new URLSearchParams(window.location.search).get("item");
    if (deepItem && item(deepItem)) openProduct(deepItem);
  } catch (e) {}
}
/* ── customer auth — login / phone OTP / register / logout ──────────────
   Elements only exist when the store's customer_auth_mode isn't guest_only
   (and this file also loads on track/account pages), so every hook is
   presence-guarded. On success the page reloads: the blade re-renders the
   me-card, saved addresses and wallet from the authed session. */
(function initAuth() {
  const authErr = $("#authErr");
  const showAuthErr = m => { if (authErr) { authErr.textContent = m || "Failed. Please try again."; authErr.hidden = false; } };
  $("#meAuthBtn")?.addEventListener("click", () => sheet("authSheet"));
  $$("#authTabs button").forEach(b => b.addEventListener("click", () => {
    $$("#authTabs button").forEach(x => x.classList.toggle("is-on", x === b));
    $$(".auth-form[data-auth]").forEach(f => { f.hidden = f.dataset.auth !== b.dataset.authTab; });
    if (authErr) authErr.hidden = true;
  }));
  const authPost = (url, body) =>
    fetch(url, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": P.csrf, "X-Requested-With": "XMLHttpRequest" }, body: JSON.stringify(body) })
      .then(r => r.json())
      .then(res => { if (res && res.success) window.location.reload(); else showAuthErr(res && res.message); })
      .catch(() => showAuthErr());
  $("#loginBtn")?.addEventListener("click", () =>
    authPost(P.loginUrl, { store_id: P.storeId, email: $("#loginEmail").value.trim(), password: $("#loginPass").value }));
  $("#registerBtn")?.addEventListener("click", () =>
    authPost(P.registerUrl, { store_id: P.storeId, name: $("#regName").value.trim(), email: $("#regEmail").value.trim(), phone: $("#regPhone").value.trim(), password: $("#regPass").value, password_confirmation: $("#regPass2").value }));
  $("#otpSend")?.addEventListener("click", () => {
    const phone = $("#otpPhone").value.trim();
    if (!phone) return;
    fetch(P.otpRequestUrl, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": P.csrf }, body: JSON.stringify({ store_id: P.storeId, phone }) })
      .then(r => r.json())
      .then(res => { if (res && res.success) { $("#otpStep1").hidden = true; $("#otpStep2").hidden = false; } else showAuthErr(res && res.message); })
      .catch(() => showAuthErr());
  });
  $("#otpVerify")?.addEventListener("click", () =>
    authPost(P.otpVerifyUrl, { store_id: P.storeId, phone: $("#otpPhone").value.trim(), code: $$("#otpBoxes input").map(i => i.value).join("") }));
  $$("#otpBoxes input").forEach((inp, i, arr) => {
    inp.addEventListener("input", () => { inp.value = inp.value.replace(/\D/g, "").slice(0, 1); if (inp.value && arr[i + 1]) arr[i + 1].focus(); });
    inp.addEventListener("keydown", e => { if (e.key === "Backspace" && !inp.value && arr[i - 1]) arr[i - 1].focus(); });
    inp.addEventListener("paste", e => {
      e.preventDefault();
      const d = ((e.clipboardData || window.clipboardData).getData("text") || "").replace(/\D/g, "").slice(0, arr.length);
      if (!d) return;
      arr.forEach((b, j) => { b.value = d.charAt(j) || ""; });
      (arr[Math.min(d.length, arr.length) - 1] || arr[0]).focus();
    });
  });
  $("#logoutBtn")?.addEventListener("click", () =>
    fetch(P.logoutUrl, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": P.csrf } }).then(() => window.location.reload()));
})();

/* ── brand / follow row (crave F2 parity) — customer follows the store from
   the home header; guest taps open the same auth sheet initAuth() wires
   above. Element only exists when the button is rendered (signed-in, or a
   store that isn't guest_only), so this is presence-guarded like the rest. ── */
(function initFollow() {
  const btn = $("#pcFollowBtn");
  if (!btn || !P.followUrl) return;

  function headers(json) {
    const h = { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" };
    if (json) h["Content-Type"] = "application/json";
    if (P.csrf) h["X-CSRF-TOKEN"] = P.csrf;
    if (P.customerToken) h["Authorization"] = "Bearer " + P.customerToken;
    return h;
  }

  function applyState(d) {
    if (!d || typeof d.following === "undefined") return false;
    btn.dataset.following = d.following ? "1" : "0";
    btn.classList.toggle("is-on", !!d.following);
    const c = $("#pcFollowerCount");
    if (c && typeof d.follower_count !== "undefined") c.textContent = d.follower_count;
    return true;
  }

  // optimistic count nudge (the count span only exists past the display threshold)
  function bumpCount(delta) {
    const c = $("#pcFollowerCount"); if (!c) return;
    const n = parseInt(String(c.textContent || "").replace(/[^0-9]/g, ""), 10);
    if (!isNaN(n)) c.textContent = String(Math.max(0, n + delta));
  }

  // flip UI immediately, reconcile on response, revert on failure
  function doFollow(unfollow) {
    const prevFollowing = btn.dataset.following === "1";
    btn.dataset.following = unfollow ? "0" : "1";
    btn.classList.toggle("is-on", !unfollow);
    bumpCount(unfollow ? -1 : 1);
    fetch(P.followUrl, { method: unfollow ? "DELETE" : "POST", headers: headers(true), body: "{}" })
      .then(r => r.json()).then(d => {
        if (!d || typeof d.following === "undefined") throw new Error("bad");
        applyState(d); // reconcile against the authoritative count/state
      }).catch(() => {
        btn.dataset.following = prevFollowing ? "1" : "0";
        btn.classList.toggle("is-on", prevFollowing);
        bumpCount(unfollow ? 1 : -1); // undo the optimistic nudge
        toast(t("action_failed"));
      });
  }

  btn.addEventListener("click", () => {
    if (!P.customerToken) { sheet("authSheet"); return; } // guest → sign in first
    doFollow(btn.dataset.following === "1");
  });
})();

/* WAVE3-V2 (same class as CRUMB-F30). layout.blade.php:637 auto-loads this file on
   EVERY picnic surface, but the SPA shell (#app, #grid, #revStars, #onb, #scrim) only
   exists in index.blade.php — track and account are server-rendered and carry their
   own inline scripts. Running boot() there threw on the first missing node and killed
   the rest of the module. Only boot the app when its shell is actually present. */
if ($("#app")) boot();

/* explicit corner-X close on sheets (picnic closes via the scrim, not a grab bar). */
$$(".sheet-x").forEach(x => x.addEventListener("click", () => { if (openSheet) sheet(openSheet, false); }));

/* Collapsing header — reveal a condensed bar once the cover band leaves the home
   scroll container. picnic's category chips are not sticky, so no overlap guard.
   Condensed buttons forward their click to the real top-chrome controls. */
(function mountCondensedHeader() {
  const homeView = document.querySelector('.view[data-view="home"]');
  if (!homeView) return;
  const anchor = homeView.querySelector(".pc-cover") || homeView.querySelector(".home-top");
  const bar = homeView.querySelector(".cv-condensed");
  const scroller = homeView.querySelector(".view-scroll");
  if (!anchor || !bar || !scroller || !("IntersectionObserver" in window)) return;
  const io = new IntersectionObserver(es => {
    const e = es[0];
    if (e) bar.classList.toggle("is-on", e.intersectionRatio < 0.05);
  }, { root: scroller, threshold: [0, 0.05, 1] });
  io.observe(anchor);
  const T = { lang: "#langBtn", catering: "#cvCateringBtn", notif: "#mbNotifBell" };
  bar.querySelectorAll("[data-cv-proxy]").forEach(b => b.addEventListener("click", () => {
    const t = homeView.querySelector(T[b.getAttribute("data-cv-proxy")]);
    if (t) t.click();
  }));
})();
})();
