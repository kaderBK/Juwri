// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ═══════════════════════════════════════════════════════════
   scoop demo — self-contained app logic (no backend)
   Two-mode black × yellow ice-cream ordering app:
   yellow catalog ↔ dark item detail · base swatch variants ·
   paid toppings · stock & sold-out · favourites · pin/share ·
   cart notes · coupon · order types · schedule · tips · wallet ·
   tracking · loyalty redeem · reservations + waitlist · review ·
   call waiter · WhatsApp stub · multi-currency · i18n + RTL
   ═══════════════════════════════════════════════════════════ */
/* Wrapped in an IIFE so the DOM helpers below (`const $ = querySelector`,
   `$$`, etc.) stay local. At top level they leaked into the global lexical
   scope and SHADOWED jQuery's `$` for other inline scripts on the page — a
   shared handler doing `$(window).on(...)` then hit querySelector(window) and
   threw `'[object Window]' is not a valid selector` (seen on the product
   page). `window.scoopImgFail` is still exported explicitly below, so the
   image-error hook keeps working. */
(function () {
"use strict";

/* ═══════════════════════════════════════════════════════════
   REAL DATA ADAPTER — the standalone demo shipped its own mock
   ITEMS/CATS/currencies; here everything is fed from Blade via
   window.SCOOP (real $total_menus, variants, extras, currency,
   endpoints). The UI layer below is the demo's, unchanged.
   ═══════════════════════════════════════════════════════════ */
const CFG = window.SCOOP || {};
const EP  = CFG.endpoints || {};
const RAW = CFG.items || {};                 // object keyed by menu id
const SCI = CFG.i18n || {};

/* image helpers are now pass-throughs (Blade sends full URLs) */
const IMG = u => u || CFG.defaultImg || "";

/* transparent 1×1 — swapping a failed photo to this keeps the load SUCCESSFUL,
   so Chrome paints no broken-image icon and .no-img's cone glyph shows through.
   (clearing src instead leaves the element in its broken state, icon and all) */
const BLANK_PX = "data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==";

/* a missing/404 photo must fall back to the cone glyph, never a dead gap */
function scoopImgFail(el) {
  if (el.dataset.imgFailed) { return; }   /* guard: never re-enter on the swap */
  el.dataset.imgFailed = "1";
  el.classList.add("no-img");
  el.src = BLANK_PX;
}
window.scoopImgFail = scoopImgFail;

/* restore a reused <img> (#pImg) before showing the next item's photo */
function scoopImgReset(el) {
  delete el.dataset.imgFailed;
  el.classList.remove("no-img");
}

/* build an <img> that degrades gracefully — never emits src="" */
function imgTag(url, extra) {
  const u = THUMB(url);
  return u
    ? `<img src="${u}" alt="" ${extra || ""} data-csp-error="scoop-image-fail">`
    : `<img src="${BLANK_PX}" alt="" class="no-img" ${extra || ""}>`;
}
const THUMB = u => u || CFG.defaultImg || "";

/* deterministic swatch dots for real variants (no colour in the DB) */
const SWATCH_DOTS = ["#F6EBD9", "#6B4A32", "#E56A9C", "#7CB47C", "#D9B98A", "#E8D6BE", "#F5C518", "#C58B5A", "#B07C5A", "#9BC7E0"];

/* categories: synthetic "all" + real TOP-LEVEL parent categories that hold items.
   CFG.cats is now parent pills only; CFG.subCats maps parentId → item-bearing subs. */
const CATS = (() => {
  const out = [{ id: "all", name: SCI.all_products || "All" }];
  (CFG.cats || []).forEach(c => out.push({ id: String(c.id), name: c.name }));
  return out;
})();
const SUBCATS = CFG.subCats || {};              // { parentId: [{id,name}] }
/* an item's top-level ancestor (parent-chip filter); falls back to its own cat */
const itemRoot = i => i.root != null ? i.root : i.cat;

/* map one Blade item → the shape the scoop UI renders.
   base swatch card ← real variants (price is ABSOLUTE per the ordering
   contract → stored as a delta off the first variant for display);
   toppings ← real extras (additive). */
function mapItem(m) {
  const variants = m.variants || [];
  const basePrice = variants.length ? Number(variants[0].price) : Number(m.price || 0);
  const baseOpts = variants.map((v, idx) => ({
    name: v.label,
    dot: SWATCH_DOTS[idx % SWATCH_DOTS.length],
    delta: Number(v.price) - basePrice,
    variantId: v.id,
  }));
  const tracked = !!m.track_inventory;
  const qty = (m.stock_qty !== null && m.stock_qty !== undefined) ? Number(m.stock_qty) : null;
  const soldOut = !!m.is_sold_out || (tracked && qty !== null && qty <= 0);
  /* `stock` is the ORDER CAP; `stockKnown` is whether we may say anything about it.
     The demo defaulted an untracked item to 99 and then printed "99 in stock" on the
     product page and "in stock" on every card — a number the restaurant never entered. */
  const stockKnown = tracked && qty !== null;
  const stock = stockKnown ? qty : 99;
  const tags = [];
  if (m.is_vegan) tags.push("vegan");
  if (m.is_gluten_free) tags.push("gf");
  return {
    id: String(m.id), cat: String(m.cat), root: String(m.root != null ? m.root : m.cat),
    name: m.name || "", desc: m.desc || "",
    price: basePrice,
    rating: m.rating || "", reviews: m.rating_count ? String(m.rating_count) : "",
    reviewList: m.reviewList || [],
    /* Happy-hour strike-through: original_price is the pre-schedule BASE price,
       so the pre-discount unit is unit + (original − current base). */
    wasDelta: (m.original_price && Number(m.original_price) > Number(m.price))
      ? Number(m.original_price) - Number(m.price) : 0,
    /* Happy-hour label ("Happy Hour") for the card ribbon. The blade serialized
       schedule_label but the model never carried it, so the badge could never
       render — even though the strike (wasDelta) did. */
    saleLabel: m.schedule_label || "",
    time: (m.prep_time && Number(m.prep_time) > 0) ? Number(m.prep_time) : null,
    kcal: (m.calories && Number(m.calories) > 0) ? Number(m.calories) : null,
    stock, stockKnown, soldOut,
    /* the demo stamped the SAME "★ Daily special" chip on daily specials AND populars —
       7 of the lane's 15 items wore it, which makes the badge mean nothing */
    daily: !!m.is_daily_special,
    popular: !!m.is_popular,
    lowThreshold: (m.low_stock_threshold != null) ? Number(m.low_stock_threshold) : 3,
    tags,
    /* the owner's own name for the variant axis, never "Base ice cream" */
    base: baseOpts.length ? { label: (m.variantGroupLabel || "").trim(), opts: baseOpts } : null,
    extras: (m.extras || []).map(e => ({ name: e.title, price: Number(e.price), id: e.id })),
    /* #global-modifiers: option groups the owner attached to this item. `max === 1`
       behaves as a radio, anything else as a multi-select capped at max. */
    groups: (m.optionGroups || []).map(g => {
      const max = (g.max !== null && g.max !== undefined) ? Number(g.max) : null;
      return {
        id: "g" + g.id,
        groupId: Number(g.id),
        name: g.title || "",
        required: !!g.required,
        min: Number(g.min) || 0,
        max,
        type: max === 1 ? "radio" : "check",
        opts: (g.options || []).map(o => ({ i: Number(o.i), name: o.label, price: Number(o.price) || 0 })),
      };
    }).filter(g => g.opts.length),
    img: m.img || CFG.defaultImg || "",
  };
}
const ITEMS = Object.keys(RAW).map(k => mapItem(RAW[k]));

/* payment methods built from real order/payment flags */
const PAY_METHODS = (() => {
  const list = [{ id: "cash", key: "pm_cash", sub: "pay_on_handover", icon: "cash" }];
  if (CFG.allowPayment) {
    list.push({ id: "card", key: "pm_card", sub: "Visa · Mastercard · Mada", icon: "card" });
    if (Number(CFG.storeCredit) > 0) list.push({ id: "wallet", key: "pm_wallet", sub: "", icon: "wallet", walletSub: true });
  }
  return list;
})();

/* (the demo's invented client-side REWARDS catalogue lived here — see loadLoyalty()) */

/* currency — driven by the restaurant's real currency + optional manual rates */
const BASE_CUR = (CFG.baseCurrency || "USD").toUpperCase();
const CUR_SIGN = CFG.currencySign || "$";
const CUR_POS  = String(CFG.currencyPos || "1");          // "1" = sign before the number
const CURRENCIES = (() => {
  const map = {}; map[BASE_CUR] = { sign: CUR_SIGN, rate: 1 };
  Object.keys(CFG.currencyRates || {}).forEach(code => {
    const c = code.toUpperCase();
    if (c !== BASE_CUR && Number(CFG.currencyRates[code]) > 0) map[c] = { sign: c, rate: Number(CFG.currencyRates[code]) };
  });
  return map;
})();
/* per-currency decimals: 3-decimal currencies (OMR/KWD/BHD/JOD/LYD/TND) must keep their fils */
const CUR_DP_BASE = Number.isInteger(CFG.currencyDecimals) ? CFG.currencyDecimals : 2;
/* bug batch 10 · 4: order arithmetic (coupon / tax / tip) at the store currency's minor unit — what the order stores (CFG.moneyDecimals); display keeps currencyDecimals */
const MONEY_DP = Number.isInteger(CFG.moneyDecimals) ? CFG.moneyDecimals : CUR_DP_BASE;
const CUR_DP_MAP = CFG.decimalsByCode || {};
/* decimals follow the DISPLAYED currency: the store's own from the server, any
   other from the map (2 unless it is a 3-decimal currency). */
const curDp = code => (code === BASE_CUR ? CUR_DP_BASE : (CUR_DP_MAP[code] ?? 2));
/* percent-of-money rounded to the store currency's decimals. PCT_F is 1 on
   2-decimal stores, so this stays byte-identical there. */
const PCT_F = Math.pow(10, MONEY_DP - 2);
const pctOf = (amount, pct) => Math.round(amount * pct * PCT_F) / (100 * PCT_F);
const DELIVERY_FEE = Number(CFG.deliveryCharge) || 0;
const TAX_CHARGE   = Number(CFG.taxCharge) || 0;           // 0 = no tax row
const TAX_TYPE     = String(CFG.taxType || "percentage");  // 'percentage' | 'fixed'
const WALLET_BAL   = Number(CFG.storeCredit) || 0;
const PREP_ETA     = (CFG.prepEta || "").trim();           // e.g. "25-35"; "" = unset
/* round a money amount to the STORE's own currency decimals (never a hardcoded 2) */
/* M16: delegates to the shared money core so this theme rounds exactly like PHP's round(). */
const roundCur = x => mbMoney.round(x, MONEY_DP);

/* ── i18n ─────────────────────────────────────────────── */
const I18N = {
  en: {
    skip: "Skip", splash_tag: "cold · bold · yellow",
    all_products: "all products", search_ph: "Search flavours…",
    promo_kicker: "Sweet deal", promo_title: "10% off every scoop today", promo_use: "use code",
    promo_copied: "SAVE10 is waiting in your cart",
    daily_special: "Daily special", in_stock: "in stock", left_n: "{n} left", stock_n: "{n} in stock",
    sold_out: "Sold out", to_cart: "To cart", no_results: "No scoops match — try clearing things",
    clear_filters: "Clear filters",
    favourite: "Favourite", favourites: "Favourites", fav_empty: "Tap the heart on any scoop to keep it here",
    fav_on: "Saved to favourites", fav_off: "Removed from favourites",
    pin_on: "Saved for later", pin_off: "Removed from saved", share_ok: "Link copied to clipboard",
    base_ice: "Base ice cream", base_milk: "Milk base", toppings: "Toppings", optional: "optional · paid",
    required: "required", pick_upto: "Pick up to {n}",
    whats_inside: "What’s inside", kcal_u: "kcal", min_u: "min",
    added: "Added to cart", removed: "Removed", only_left: "Only {n} in stock",
    cart_title: "Cart", clear: "Clear", cart_empty: "Your cart is melting away — nothing in it",
    browse_menu: "Browse the menu", order_type: "Order type",
    ot_delivery: "Delivery", ot_pickup: "Pickup", ot_dinein: "Dine-in", ot_roomservice: "Room service",
    room_hint: "Brought straight to your room",
    table: "Table", choose_table: "Choose your table",
    delivery_hint: "Delivered chilled in 20–30 min", pickup_hint: "Ready for pickup in ~10 min",
    when: "When", asap: "ASAP", later: "Later",
    coupon_ph: "Promo code — try SAVE10", apply: "Apply",
    coupon_ok: "Coupon applied — you save", coupon_bad: "That code doesn’t work",
    note_add: "Add note", note_ph: "Extra crunchy please…",
    call_waiter: "Call waiter", waiter_ok: "A waiter is on the way",
    wa_order: "Order via WhatsApp instead", wa_stub: "Opening WhatsApp… (demo)",
    wa_us: "Chat with us on WhatsApp",
    nearest_branch: "Find nearest branch", on_map: "On the map",
    nearest_branch_h: "Nearest branch", no_branches: "No branches", km_away: "km",
    checkout: "Checkout", payment: "Payment", contact: "Contact",
    your_name: "Your name", phone: "Phone", delivery_address: "Delivery address",
    addr_ph: "Street, building, floor…", pay_with: "Pay with",
    pm_cash: "Cash", pm_card: "Card", pm_wallet: "Wallet", pay_on_handover: "Pay on handover",
    card_name: "Cardholder name", wallet_bal: "Balance",
    tip: "Add a tip", no_tip: "None", place_order: "Place order",
    fill_contact: "Add your name & phone first", fill_addr: "Add a delivery address",
    order_confirmed: "Order confirmed", confirm_sub: "Your scoops just reached the kitchen",
    pts_earned: "+{n} pts earned", track_order: "Track order", back_menu: "Back to menu",
    order_no: "Order", eta: "Ready in",
    stage_placed: "Order placed", stage_scooping: "Scooping & packing", stage_way: "On the way",
    stage_ready: "Ready for pickup", stage_served: "Served to your table",
    stage_done: "Delivered", stage_done_p: "Picked up", stage_done_t: "Enjoy your scoops",
    rate_q: "How were your scoops?", rate_google: "Continue on Google Reviews",
    rate_bad_ph: "Tell us what melted…", send: "Send", rate_thanks: "Thank you — sweet of you!",
    google_stub: "Opening Google reviews… (demo)",
    reviews_title: "Reviews", reviews_empty: "No reviews yet.", rate_dish: "Rate this dish",
    profile_title: "Profile", points: "Loyalty points", pts: "pts", redeem: "Redeem",
    my_favs: "My favourites", saved_later: "Saved for later", reservations: "Reservations",
    book_table: "Book a table", preferences: "Preferences", language: "Language", currency: "Currency",
    my_orders: "My orders", orders_empty: "Orders you place appear here",
    n_home: "Home", n_search: "Search", n_menu: "menu", n_cart: "cart", n_user: "user",
    filters: "Filters", dietary: "Dietary", sort_by: "Sort by", reset: "Reset", show_items: "Show scoops",
    tag_vegan: "Vegan", tag_gf: "Gluten-free", tag_sf: "Sugar-free", tag_nf: "Nut-free",
    s_featured: "Featured", s_price_asc: "Price · low → high", s_price_desc: "Price · high → low", s_rating: "Top rated",
    redeem_title: "Redeem points", your_balance: "Your balance",
    r10_t: "10% off your order", r10_s: "Applied at checkout instantly",
    rdel_t: "Free delivery", rdel_s: "Your next delivery rides free",
    rscoop_t: "Bonus scoop", rscoop_s: "One extra scoop, on us",
    redeemed: "Reward unlocked", not_enough: "Not enough points yet",
    book_title: "Book a table", reserve: "Reserve", waitlist: "Waitlist",
    day: "Day", today: "Today", tomorrow: "Tomorrow", friday: "Friday",
    time: "Time", guests: "Guests", book_btn: "Reserve table", booked: "Table reserved",
    wl_ahead: "3 parties ahead of you right now", wl_join: "Join waitlist",
    wl_joined: "You’re #4 in line", wl_leave: "Leave waitlist", wl_left: "Left the waitlist",
    subtotal: "Subtotal", discount: "Discount", tax: "Tax (10%)", service: "Service (5%)",
    delivery_fee: "Delivery fee", free: "Free", tip_label: "Tip", total: "Total", min_order: "Minimum order",
  },
  ar: {
    skip: "تخطي", splash_tag: "بارد · جريء · أصفر",
    all_products: "كل المنتجات", search_ph: "ابحث عن نكهة…",
    promo_kicker: "عرض حلو", promo_title: "خصم ١٠٪ على كل سكوب اليوم", promo_use: "استخدم الكود",
    promo_copied: "SAVE10 بانتظارك في السلة",
    daily_special: "طبق اليوم", in_stock: "متوفر", left_n: "بقي {n}", stock_n: "{n} في المخزون",
    sold_out: "نفدت الكمية", to_cart: "إلى السلة", no_results: "لا نتائج — جرّب مسح الفلاتر",
    clear_filters: "مسح الفلاتر",
    favourite: "مفضلة", favourites: "المفضلة", fav_empty: "اضغط القلب على أي سكوب لحفظه هنا",
    fav_on: "أُضيف إلى المفضلة", fav_off: "أُزيل من المفضلة",
    pin_on: "حُفظ لوقت لاحق", pin_off: "أُزيل من المحفوظات", share_ok: "تم نسخ الرابط",
    base_ice: "الآيس كريم الأساسي", base_milk: "نوع الحليب", toppings: "الإضافات", optional: "اختياري · مدفوع",
    required: "مطلوب", pick_upto: "اختر حتى {n}",
    whats_inside: "المكونات", kcal_u: "سعرة", min_u: "دقيقة",
    added: "أُضيف إلى السلة", removed: "حُذف", only_left: "متوفر {n} فقط",
    cart_title: "السلة", clear: "مسح", cart_empty: "سلتك تذوب فراغاً — لا شيء فيها",
    browse_menu: "تصفح المنيو", order_type: "نوع الطلب",
    ot_delivery: "توصيل", ot_pickup: "استلام", ot_dinein: "في المحل", ot_roomservice: "خدمة الغرف",
    room_hint: "يصل إلى غرفتك مباشرة",
    table: "طاولة", choose_table: "اختر طاولتك",
    delivery_hint: "يصل بارداً خلال ٢٠–٣٠ دقيقة", pickup_hint: "جاهز للاستلام خلال ~١٠ دقائق",
    when: "متى", asap: "الآن", later: "لاحقاً",
    coupon_ph: "كود الخصم — جرّب SAVE10", apply: "تطبيق",
    coupon_ok: "تم تطبيق الكود — وفّرت", coupon_bad: "الكود غير صالح",
    note_add: "أضف ملاحظة", note_ph: "مقرمش زيادة لو سمحت…",
    call_waiter: "نادِ النادل", waiter_ok: "النادل في الطريق إليك", rate_dish: "قيّم هذا الطبق",
    wa_order: "اطلب عبر واتساب بدلاً من ذلك", wa_stub: "جارٍ فتح واتساب… (تجريبي)",
    wa_us: "تواصل معنا عبر واتساب",
    nearest_branch: "أقرب فرع", on_map: "على الخريطة",
    nearest_branch_h: "أقرب فرع", no_branches: "لا توجد فروع", km_away: "كم",
    checkout: "إتمام الطلب", payment: "الدفع", contact: "بيانات التواصل",
    your_name: "اسمك", phone: "الهاتف", delivery_address: "عنوان التوصيل",
    addr_ph: "الشارع، المبنى، الطابق…", pay_with: "ادفع بواسطة",
    pm_cash: "نقداً", pm_card: "بطاقة", pm_wallet: "المحفظة", pay_on_handover: "ادفع عند الاستلام",
    card_name: "اسم حامل البطاقة", wallet_bal: "الرصيد",
    tip: "أضف إكرامية", no_tip: "بدون", place_order: "إرسال الطلب",
    fill_contact: "أضف اسمك ورقم هاتفك أولاً", fill_addr: "أضف عنوان التوصيل",
    order_confirmed: "تم تأكيد الطلب", confirm_sub: "وصلت سكوباتك إلى المطبخ للتو",
    pts_earned: "كسبت +{n} نقطة", track_order: "تتبع الطلب", back_menu: "عودة للمنيو",
    order_no: "الطلب", eta: "جاهز خلال",
    stage_placed: "تم استلام الطلب", stage_scooping: "نجهّز السكوبات", stage_way: "في الطريق",
    stage_ready: "جاهز للاستلام", stage_served: "قُدّم إلى طاولتك",
    stage_done: "تم التوصيل", stage_done_p: "تم الاستلام", stage_done_t: "بالهناء",
    rate_q: "كيف كانت سكوباتك؟", rate_google: "تابع على تقييمات جوجل",
    rate_bad_ph: "أخبرنا ما الذي ذاب…", send: "إرسال", rate_thanks: "شكراً — لطف منك!",
    google_stub: "جارٍ فتح تقييمات جوجل… (تجريبي)",
    profile_title: "حسابي", points: "نقاط الولاء", pts: "نقطة", redeem: "استبدال",
    my_favs: "مفضلتي", saved_later: "محفوظ لوقت لاحق", reservations: "الحجوزات",
    book_table: "احجز طاولة", preferences: "التفضيلات", language: "اللغة", currency: "العملة",
    my_orders: "طلباتي", orders_empty: "طلباتك ستظهر هنا",
    n_home: "الرئيسية", n_search: "بحث", n_menu: "المنيو", n_cart: "السلة", n_user: "حسابي",
    filters: "الفلاتر", dietary: "النظام الغذائي", sort_by: "ترتيب حسب", reset: "إعادة", show_items: "عرض السكوبات",
    tag_vegan: "نباتي", tag_gf: "بدون غلوتين", tag_sf: "بدون سكر", tag_nf: "بدون مكسرات",
    s_featured: "مميز", s_price_asc: "السعر · من الأقل", s_price_desc: "السعر · من الأعلى", s_rating: "الأعلى تقييماً",
    redeem_title: "استبدال النقاط", your_balance: "رصيدك",
    r10_t: "خصم ١٠٪ على طلبك", r10_s: "يُطبق عند الدفع فوراً",
    rdel_t: "توصيل مجاني", rdel_s: "توصيلتك القادمة مجانية",
    rscoop_t: "سكوب هدية", rscoop_s: "سكوب إضافي منّا",
    redeemed: "فُعّلت المكافأة", not_enough: "نقاطك لا تكفي بعد",
    book_title: "احجز طاولة", reserve: "حجز", waitlist: "قائمة الانتظار",
    day: "اليوم", today: "اليوم", tomorrow: "غداً", friday: "الجمعة",
    time: "الوقت", guests: "الضيوف", book_btn: "احجز الطاولة", booked: "تم حجز الطاولة",
    wl_ahead: "أمامك ٣ مجموعات الآن", wl_join: "انضم لقائمة الانتظار",
    wl_joined: "أنت رقم ٤ في الطابور", wl_leave: "غادر القائمة", wl_left: "غادرت قائمة الانتظار",
    subtotal: "المجموع الفرعي", discount: "الخصم", tax: "الضريبة (١٠٪)", service: "الخدمة (٥٪)",
    delivery_fee: "رسوم التوصيل", free: "مجاني", tip_label: "الإكرامية", total: "الإجمالي", min_order: "الحد الأدنى للطلب",
  },
};

/* ── state ────────────────────────────────────────────── */
const store = {
  get: (k, d) => { try { return JSON.parse(localStorage.getItem("scoop_" + k)) ?? d; } catch { return d; } },
  set: (k, v) => localStorage.setItem("scoop_" + k, JSON.stringify(v)),
};

/* which order type to default to (respect real allowed types / table QR) */
/* theme-local order-type names; OT_SERVER (below) maps them to the server's
   ordering-type values — roomservice → room_service. */
const OT_ALLOWED = { delivery: !!CFG.allowDelivery, pickup: !!CFG.allowPickup, dinein: !!CFG.allowDinein, roomservice: !!CFG.allowRoomservice };
/* forceTable is set by ANY table QR, including a hotel room QR. Prefer dine-in when
   the owner actually enables it, otherwise fall through the allowed chain — a hotel
   that offers room service but not dine-in would otherwise default to a hidden
   "dinein" button and submit on-table. */
/* Room service wins outright: allowRoomservice is 1 only when hotel mode is on AND
   the scanned QR resolved to an area_type='room' table, so it IS the "this guest is
   in a room" signal. A room QR also sets forceTable, so without this branch the guest
   boots on dine-in and the order reaches the kitchen as a table order. */
const DEFAULT_OT = OT_ALLOWED.roomservice ? "roomservice"
  : (CFG.forceTable && OT_ALLOWED.dinein) ? "dinein"
  : (["delivery", "pickup", "dinein", "roomservice"].find(k => OT_ALLOWED[k]) || "delivery");

/* default chrome language follows the server's rendered direction
   (Arabic menu → RTL + Arabic UI strings) unless the diner toggled it */
const SERVER_LANG = (CFG.dir === "rtl" || CFG.lang === "ar") ? "ar" : "en";
const S = {
  /* the language is the SERVER's, always. The theme used to keep its own localStorage
     EN/AR flag that re-labelled the chrome but left the menu data, the account page and
     the tracking page in the old language — and forgot itself on any full navigation.
     The profile now links to /lang/{code}, which is session-wide and real. */
  lang: SERVER_LANG,
  appearance: store.get("appearance", "dark"),
  /* Currency is NOT read from the "scoop_" store: that prefix is shared across every
     scoop store on the origin, so scoop_cur leaked one store's choice onto the next.
     Default to base here; the store-scoped mbCurrency helper replays the real choice
     at boot (see boot()'s mbCurrency.restore). */
  cur: BASE_CUR,
  favs: new Set(store.get("favs", [])),
  /* Menu item ids are globally unique, so a stored line whose item is absent is
     either stale (the owner deleted that item) or carried over from another store
     on this origin — the "scoop_" storage key is NOT per-restaurant. item() would
     return undefined and renderCart() would throw on i.img, aborting the whole cart
     render at boot with no way back (Clear never paints). Drop those lines. */
  cart: store.get("cart", []).filter(l => ITEMS.some(i => i.id === l.id)),
  orders: store.get("orders", []),
  points: 0, loyalty: null,          /* server-owned; see loadLoyalty() */
  freeDel: false,
  cat: "all", sub: "root", search: "", sort: "featured",
  filters: { diets: new Set() },
  orderType: DEFAULT_OT, when: "asap",
  coupon: null, couponPrefill: "", tip: 0, pay: "cash",
  currentItem: null, pQty: 1, pSel: { base: 0, extras: new Set(), groups: {} },
  gridList: false,
  bookMode: "reserve",
};

/* write the pruned cart back so a poisoned entry can't linger in storage */
if (S.cart.length !== (store.get("cart", []) || []).length) { store.set("cart", S.cart); }

/* Server strings win. CFG.strings is built in the blade through ___(), so it is
   translated into whatever locale the storefront is actually running — the bundled
   en/ar dictionary below only fills gaps. Before this, a French or Turkish store got
   English chrome (the [data-i18n] sweep overwrote Blade's translated markup), and every
   restaurant inherited the standalone demo's ice-cream vocabulary. */
const SRV = CFG.strings || {};
const t = k => (SRV[k] ?? I18N[S.lang][k] ?? I18N.en[k] ?? k);
/* names come straight from the server (already translated per cookie);
   fall back to the demo's en/ar shape for any chrome objects */
const name = o => o == null ? "" : (o.name != null ? o.name : (S.lang === "ar" ? (o.ar ?? o.en) : o.en));
const desc = i => i == null ? "" : (i.desc != null ? i.desc : (S.lang === "ar" ? (i.descAr ?? i.descEn) : i.descEn));
/* HTML-escape for every innerHTML template below. name()/desc() return raw owner/staff-typed
   text (item, group, option, extra and category names), so any template that interpolates
   them wraps the value in eh() — textContent sinks must NOT (they would double-escape). */
const eh = s => String(s == null ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
/* Crawlable + new-tab-able per-item URL: keeps existing query (table, lang…)
   and sets ?item=ID — the deep-link the R7 intercept + boot loader understand.
   scoop's cards carry real <button>s (fav/share/cart), so the whole card can't be
   an <a>; the dish name is the anchor instead — enough for crawl + open-in-new-tab. */
const itemHref = id => { try { const u = new URL(location.href); u.searchParams.set("item", id); u.hash = ""; return u.pathname + u.search; } catch { return "?item=" + encodeURIComponent(id); } };
const cap = s => S.lang === "ar" ? s : (s ? s.charAt(0).toUpperCase() + s.slice(1) : s);
const catOf = id => CATS.find(c => c.id === id);
const item = id => ITEMS.find(i => i.id === id);
const reduceMotion = () => matchMedia("(prefers-reduced-motion: reduce)").matches;

function fmt(base) { const c = CURRENCIES[S.cur] || CURRENCIES[BASE_CUR]; const n = (base * c.rate).toFixed(curDp(S.cur)); const pre = S.cur !== BASE_CUR ? "≈ " : ""; /* MC-34: converted = estimate */ return pre + (CUR_POS === "1" ? `${c.sign} ${n}` : `${n} ${c.sign}`); }
/* Money is never abbreviated. The demo's fmtShort() parseFloat'd the decimals away, so a
   $4.50 dish was priced "$ 4.5" and a 50c side "$ 0.5" on every card in the menu. */
const fmtShort = fmt;

/* ── dom helpers ──────────────────────────────────────── */
const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
const ICONS = {
  check: '<svg viewBox="0 0 24 24"><path d="m5 12 5 5 9-10"/></svg>',
  star: '<svg viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg>',
  clock: '<svg class="ic" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
  flame: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 22c4.4 0 7-2.8 7-6.6 0-4.8-4.2-6.6-4.9-11.4-2.5 1.6-3.4 4-3.2 6.6-1.2-.7-2-1.7-2.4-3.4C6.6 9 5 11.6 5 15.4 5 19.2 7.6 22 12 22Z"/></svg>',
  leaf: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 19C5 9 13 5 20 5c0 8-4 14-12 14-1 0-2-.1-3 0Z"/><path d="M5 19c2-5 6-9 10-11"/></svg>',
  plus: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 6v12M6 12h12"/></svg>',
  minus: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 12h12"/></svg>',
  pencil: '<svg class="ic" viewBox="0 0 24 24"><path d="M4 20h4L20 8l-4-4L4 16v4Z"/><path d="m13 7 4 4"/></svg>',
  pin: '<svg class="ic" viewBox="0 0 24 24"><path d="M9 3h6l-1 6 3.5 3.5V14h-5v6l-.5 1.5L11.5 20v-6h-5v-1.5L10 9 9 3Z"/></svg>',
  share: '<svg class="ic" viewBox="0 0 24 24"><path d="m14 5 5 5-5 5"/><path d="M19 10H10a6 6 0 0 0-6 6v3"/></svg>',
  heart: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 20.5s-8-4.9-8-10.6C4 6.6 6.4 4.5 9 4.5c1.5 0 2.5.9 3 1.7.5-.8 1.5-1.7 3-1.7 2.6 0 5 2.1 5 5.4 0 5.7-8 10.6-8 10.6Z"/></svg>',
  cart: '<svg class="ic" viewBox="0 0 24 24"><circle cx="9.5" cy="19.5" r="1.6"/><circle cx="17" cy="19.5" r="1.6"/><path d="M3 4h2l2.3 11h10.4L20 8H6.1"/></svg>',
  bag: '<svg class="ic" viewBox="0 0 24 24"><path d="M6 8h12l-1.2 12.2a2 2 0 0 1-2 1.8H9.2a2 2 0 0 1-2-1.8L6 8Z"/><path d="M9 8a3 3 0 0 1 6 0"/></svg>',
  cash: '<svg class="ic" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="11" rx="2"/><circle cx="12" cy="12.5" r="2.6"/><path d="M6.5 10.5v0M17.5 14.5v0"/></svg>',
  card: '<svg class="ic" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M7 15h4"/></svg>',
  wallet: '<svg class="ic" viewBox="0 0 24 24"><path d="M4 7a2 2 0 0 1 2-2h12v3M4 7v10a2 2 0 0 0 2 2h13a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1H4"/><circle cx="16.5" cy="14" r="1.3" fill="currentColor" stroke="none"/></svg>',
  tag: '<svg class="ic" viewBox="0 0 24 24"><path d="M4 4h7l9 9-7 7-9-9V4Z"/><circle cx="8.5" cy="8.5" r="1.4"/></svg>',
  truck: '<svg class="ic" viewBox="0 0 24 24"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h6V8h5l3 5v4h-2M12 8h-2M9 17l3-6"/></svg>',
  gift: '<svg class="ic" viewBox="0 0 24 24"><rect x="4" y="9" width="16" height="12" rx="2"/><path d="M12 9v12M4 13h16M12 9c-2 0-4.5-.8-4.5-2.8S9.8 3.5 12 9Zm0 0c2 0 4.5-.8 4.5-2.8S14.2 3.5 12 9Z"/></svg>',
  trash: '<svg class="ic" viewBox="0 0 24 24"><path d="M5 7h14M10 7V5h4v2M8 7l.8 13h6.4L16 7"/></svg>',
  loc: '<svg class="ic" viewBox="0 0 24 24"><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/></svg>',
};

/* ── router (mode crossfade dark ↔ yellow) ────────────── */
const stack = [];
const ROOTS = ["home", "cart", "favs", "profile"];
let active = "home";

function show(nameV, { push = true, fromPop = false } = {}) {
  if (nameV === active) return;
  const from = $(`.view[data-view="${active}"]`), to = $(`.view[data-view="${nameV}"]`);
  if (!to) return;
  const isRoot = ROOTS.includes(nameV);
  if (push && !isRoot) {
    stack.push(active);
    /* Mirror the internal stack into browser history so the phone's back
       button walks product → home instead of leaving the site. */
    if (!fromPop) { try { history.pushState({ sc: 1 }, ""); } catch (e) {} }
  }
  if (isRoot) stack.length = 0;

  from?.classList.remove("is-active");
  to.classList.add("is-active");
  to.querySelector(".view-scroll")?.scrollTo({ top: 0, behavior: "instant" });
  active = nameV;

  /* the signature: phone background crossfades between charcoal & yellow */
  $("#phone").dataset.mode = to.dataset.mode || "dark";

  $("#navbar").classList.toggle("is-hidden", !isRoot);
  $$(".nav-item").forEach(b => b.classList.toggle("is-on", b.dataset.tab === nameV));
  if (nameV === "cart") renderCart();
  if (nameV === "favs") renderFavs();
  if (nameV === "profile") renderProfile();
  if (nameV === "checkout") renderCheckout();
  if (nameV === "home") requestAnimationFrame(() => setInk($("#homeTabs")));
  if (nameV === "product") requestAnimationFrame(() => setInk($("#pTabs")));
}
function internalBack() { show(stack.pop() ?? "home", { push: false, fromPop: true }); }
function back() {
  /* UI back buttons go through history when we pushed a state, so the
     browser's own back stack stays in step with what is on screen. */
  if (stack.length && history.state && history.state.sc) { history.back(); return; }
  internalBack();
}
window.addEventListener("popstate", () => {
  if (openSheet) { sheet(openSheet, false); try { history.pushState({ sc: 1 }, ""); } catch (e) {} return; }
  if (stack.length) internalBack();
  else if (active !== "home") show("home", { push: false, fromPop: true });
});

/* ── sheets ───────────────────────────────────────────── */
let openSheet = null;
const sheetHideTimers = {};
function sheet(id, open = true) {
  const s = $("#" + id), scrim = $("#scrim");
  if (open) {
    if (openSheet && openSheet !== id) sheet(openSheet, false);
    clearTimeout(sheetHideTimers[id]); // a pending close must not kill this fresh open
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
$("#scrim")?.addEventListener("click", () => openSheet && sheet(openSheet, false));

/* ── toast ────────────────────────────────────────────── */
let toastTimer;
function toast(msg) {
  const tst = $("#toast");
  tst.innerHTML = ICONS.check.replace("<svg", '<svg class="ic"') + `<span>${msg}</span>`;
  tst.classList.add("is-on");
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => tst.classList.remove("is-on"), 2200);
}
/* Exposed so the shared reorder-pickup helper (loaded by index.blade.php after
   script.js) can route its "skipped these items" message through scoop's
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

/* ── underline tabs · sliding ink ─────────────────────── */
function setInk(nav) {
  if (!nav) return;
  const ink = nav.querySelector(".tabs-ink"), on = nav.querySelector("button.is-on");
  if (!ink || !on) return;
  ink.style.transform = `translateX(${on.offsetLeft + on.offsetWidth / 2 - 9}px)`;
}

const catName = c => c.id === "all" ? t("all_products") : name(c);
/* label for a sub-category id (SUBCATS is keyed by parent id) */
function subName(subId) {
  for (const pid in SUBCATS) {
    const hit = (SUBCATS[pid] || []).find(s => String(s.id) === String(subId));
    if (hit) return hit.name;
  }
  return "";
}
function renderHomeTabs() {
  const nav = $("#homeTabs");
  if (!nav) return; /* catbar section hidden via customizer */
  nav.innerHTML = CATS.map(c => {
    const hasSub = c.id !== "all" && (SUBCATS[c.id] || []).length > 0;
    return `<button data-cat="${c.id}"${hasSub ? ' data-has-sub="1"' : ""} class="${c.id === S.cat ? "is-on" : ""}">${eh(catName(c))}</button>`;
  }).join("") + `<i class="tabs-ink"></i>`;
  requestAnimationFrame(() => setInk(nav));
  renderSubTabs();
}

/* Reveal the active parent's sub-category chip row (only when it has item-bearing
   subcategories). "All" (data-sub="root") shows every descendant of the parent. */
function renderSubTabs() {
  const wrap = $("#homeSubTabs");
  if (!wrap) return;
  const subs = (S.cat !== "all" && SUBCATS[S.cat]) ? SUBCATS[S.cat] : null;
  if (!subs || !subs.length) { wrap.hidden = true; wrap.innerHTML = ""; return; }
  wrap.hidden = false;
  wrap.innerHTML = `<button data-sub="root" class="${S.sub === "root" ? "is-on" : ""}">${t("all_products")}</button>`
    + subs.map(s => `<button data-sub="${s.id}" class="${String(s.id) === String(S.sub) ? "is-on" : ""}">${eh(s.name)}</button>`).join("");
}

function renderProductTabs(i) {
  const crumbs = [`<button data-cat="all">${t("all_products")}</button>`];
  /* crumb → the item's top-level parent (i.cat may be a subcategory not in CATS) */
  const cat = catOf(String(itemRoot(i)));
  if (cat) crumbs.push(`<button data-cat="${cat.id}">${eh(name(cat))}</button>`);
  crumbs.push(`<button class="is-on" data-self>${eh((name(i) || "").toLowerCase())}</button>`);
  $("#pTabs").innerHTML = crumbs.join("") + `<i class="tabs-ink"></i>`;
  requestAnimationFrame(() => setInk($("#pTabs")));
}

/* ── pricing ──────────────────────────────────────────── */
/* #global-modifiers: cart lines stored before groups existed have no sel.groups —
   localStorage survives the deploy, so every reader normalises through this. */
function selGroups(sel) { return (sel && sel.groups) || {}; }
/* the option objects chosen in each group, in group order */
function chosenOpts(i, sel) {
  const picked = selGroups(sel);
  const out = [];
  for (const g of (i.groups || [])) {
    for (const oi of (picked[g.id] || [])) {
      const o = g.opts.find(x => x.i === oi);
      if (o) out.push(o);
    }
  }
  return out;
}

function unitPrice(i, sel) {
  let p = i.price + (i.base ? (i.base.opts[sel.base]?.delta ?? 0) : 0);
  for (const xi of sel.extras) p += (i.extras[xi]?.price ?? 0);
  for (const o of chosenOpts(i, sel)) p += o.price;
  return p;
}
function selSummary(i, sel) {
  const parts = [];
  if (i.base && i.base.opts[sel.base]) parts.push(eh(name(i.base.opts[sel.base])));
  for (const o of chosenOpts(i, sel)) parts.push(eh(name(o)));
  for (const xi of sel.extras) if (i.extras[xi]) parts.push(eh(name(i.extras[xi])));
  return parts.join(" · ");
}
/* the first required group still short of its minimum, or null when satisfied */
function missingRequired(i, sel) {
  const picked = selGroups(sel);
  return (i.groups || []).find(g => g.required && (picked[g.id] || []).length < Math.max(1, g.min)) || null;
}
/* the chosen variant id for a cart line (0 = none) */
function selVariantId(i, sel) { return (i.base && i.base.opts[sel.base]) ? (i.base.opts[sel.base].variantId || 0) : 0; }
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
function cartSubtotal() { return S.cart.reduce((s, l) => s + unitPrice(item(l.id), l.sel) * l.qty, 0) + comboTotal(); }
/* The server order type for the coupon check (inline: the place-order map is a later `const`). */
function couponOrderType() { return ({ delivery: "delivery", pickup: "takeaway", dinein: "on-table", roomservice: "room_service" })[S.orderType] || ""; }
/* The cart as submitOrder's `items` — also posted to the coupon check (bug batch 7 · 4). */
function orderLines() {
  return S.cart.map(l => {
    const i = item(l.id);
    return {
      id: Number(i.id) || i.id,
      quantity: l.qty,
      variants: selVariantId(i, { base: l.sel.base, extras: l.sel.extras, groups: selGroups(l.sel) }),
      extras: (l.sel.extras || []).map(xi => ({ id: i.extras[xi]?.id })).filter(e => e.id != null),
      /* #global-modifiers: server re-derives the price from option_prices and
         clamps to the group's max — we only send which option was chosen. */
      mods: (i.groups || []).flatMap(g =>
        (selGroups(l.sel)[g.id] || [])
          .filter(oi => g.opts.some(o => o.i === oi))
          .map(oi => ({ groupId: g.groupId, optIndex: oi }))),
    };
  });
}
/* Bug batch 7 · 4: buy-X-get-Y is priced by the server on the cart LINES (the discount the order will get);
   the quote is refreshed whenever the cart or the order type changes. */
function couponBody(code) {
  return JSON.stringify({ code, subtotal: cartSubtotal(), items: orderLines(), "ordering-type": couponOrderType() || "" });
}
function couponSig() { return JSON.stringify([orderLines(), couponOrderType() || ""]); }
function bxgyDiscount() {
  const c = S.coupon, sig = couponSig();
  if (c.sig !== sig) {
    c.sig = sig;
    fetch(EP.coupon, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf },
      body: couponBody(c.code),
    }).then(r => r.json()).then(res => {
      if (S.coupon !== c || c.sig !== sig) return;
      c.discount = res && res.success ? Number(res.discount) || 0 : 0;
      renderTotals();
      if (active === "checkout") renderCheckout();
    }).catch(() => {});
  }
  return Number(c.discount) || 0;
}
function couponDisc(sub) {
  if (!S.coupon) return 0;
  let d = S.coupon.coupon_type === "percentage"
    ? pctOf(sub, Number(S.coupon.percentage))
    : S.coupon.coupon_type === "bogo" ? bxgyDiscount() : Number(S.coupon.percentage) || 0;
  return d > sub ? sub : d;
}
/* TOTALS CONTRACT — a byte-for-byte mirror of Order::computeTax().
   Two modes: 'percentage' of the subtotal, or 'fixed' (a flat amount, NOT a rate).
   Guards identically: charge <= 0 or subtotal <= 0 ⇒ exactly 0. */
function computeTax(sub) {
  if (TAX_CHARGE <= 0 || sub <= 0) return 0;
  return TAX_TYPE === "fixed" ? roundCur(TAX_CHARGE) : roundCur(sub * (TAX_CHARGE / 100));
}

/* TOTALS CONTRACT — must equal Order::getTotalAttribute() to the cent.
   The demo taxed `subtotal − discount`; the server taxes the RAW subtotal and
   subtracts the discount LAST, so every coupon order was quoted short (measured:
   $21.85 quoted vs $21.95 charged on the lane's contract basket). */
function cartTotals() {
  const sub = cartSubtotal();
  const disc = couponDisc(sub);
  const tax = computeTax(sub);                     // ← on the raw subtotal
  const del = S.orderType === "delivery" && (S.cart.length || comboCount()) ? (S.freeDel ? 0 : DELIVERY_FEE) : 0;
  /* the tip that is DISPLAYED must be the tip that is SENT (it is posted rounded) */
  const tip = roundCur((sub - disc) * (S.tip / 100));
  return { sub, disc, tax, del, tip, total: roundCur(sub + del + tax + tip - disc) };
}

/* ── yellow catalog render ────────────────────────────── */
function visibleItems() {
  const q = S.search.trim().toLowerCase();
  let list = ITEMS.filter(i => {
    /* Two-tier: a parent chip matches by top-level ancestor (i.root, so it shows
       every descendant); the sub-chip then narrows to one real category (i.cat). */
    if (S.cat !== "all") {
      if (String(itemRoot(i)) !== String(S.cat)) return false;
      if (S.sub !== "root" && String(i.cat) !== String(S.sub)) return false;
    }
    if (q && !((i.name || "") + " " + (i.desc || "")).toLowerCase().includes(q)) return false;
    for (const d of S.filters.diets) if (!(i.tags ?? []).includes(d)) return false;
    return true;
  });
  if (S.sort === "price_asc") list = [...list].sort((a, b) => a.price - b.price);
  if (S.sort === "price_desc") list = [...list].sort((a, b) => b.price - a.price);
  if (S.sort === "rating") list = [...list].sort((a, b) => b.rating - a.rating);
  return list;
}

/* the ONE badge this card has earned, or nothing. A daily special outranks a popular. */
function cardBadge(i) {
  if (i.daily) return `<span class="pc-special">★ ${t("daily_special")}</span>`;
  if (i.popular) return `<span class="pc-special is-pop">${t("popular")}</span>`;
  return "";
}
function cardHTML(i, idx) {
  const fav = S.favs.has(i.id);
  const low = i.stockKnown && i.stock <= (i.lowThreshold ?? 3);
  /* only an item the owner actually tracks may say anything about its stock */
  const stockTxt = i.soldOut ? t("sold_out") : (low ? t("left_n").replace("{n}", i.stock) : "");
  return `
  <article class="pcard" data-open="${i.id}" tabindex="0" style="--d:${Math.min(idx * 55, 440)}ms">
    <div class="pc-head">
      <div>
        <h3 class="pc-name"><a href="${itemHref(i.id)}" data-open="${i.id}" style="color:inherit;text-decoration:none">${eh(name(i))}</a></h3>
        <p class="pc-desc">${eh(desc(i))}</p>
      </div>
      <div class="pc-rail">
        <button class="pc-fav ${fav ? "is-on" : ""}" data-fav="${i.id}" aria-pressed="${fav}" aria-label="${t("favourite")} — ${eh(name(i))}">${ICONS.heart}</button>
        <button class="pc-dot" data-share="${i.id}" aria-label="${t("share")} — ${eh(name(i))}">${ICONS.share}</button>
      </div>
    </div>
    <div class="pc-imgwrap">
      ${imgTag(i.img, 'loading="lazy"')}
      ${cardBadge(i)}
      ${i.saleLabel ? `<span class="pc-hh">${i.saleLabel}</span>` : ""}
      ${i.soldOut ? `<span class="pc-soldout">${t("sold_out")}</span>` : ""}
    </div>
    <div class="pc-foot">
      <span>
        ${stockTxt ? `<span class="pc-stock ${low && !i.soldOut ? "low" : ""}">${stockTxt}</span>` : ""}
        <span class="pc-price">${i.wasDelta > 0 ? `<s class="pc-was">${fmtShort(i.price + i.wasDelta)}</s> ` : ""}${fmtShort(i.price)}</span>
      </span>
      <button class="pc-cart" data-add="${i.id}" ${i.soldOut ? "disabled" : ""} aria-label="${t("to_cart")} — ${eh(name(i))}">
        <span>${t("to_cart")}</span>${ICONS.cart}
      </button>
    </div>
  </article>`;
}

/* The staggered rail is a set of equal columns, so the count has to follow the viewport.
   It was hardcoded to 2, which is why a 768px tablet drew 357px-wide phone cards and a
   desktop drew them wider still. */
function gridCols() {
  const w = window.innerWidth;
  if (w >= 1180) return 4;
  if (w >= 861) return 3;
  if (w >= 620) return 3;
  return 2;
}
function renderGrid() {
  const list = visibleItems();
  const wrap = $("#grid");
  if (!wrap) return; /* menu section hidden via customizer */
  wrap.classList.toggle("is-list", S.gridList);
  if (S.gridList) {
    wrap.innerHTML = `<div class="col">${list.map(cardHTML).join("")}</div>`;
  } else {
    const n = gridCols();
    const cols = Array.from({ length: n }, () => []);
    list.forEach((it, idx) => cols[idx % n].push(cardHTML(it, idx)));
    wrap.innerHTML = cols.map((c, ci) => `<div class="col${ci % 2 ? " offset" : ""}">${c.join("")}</div>`).join("");
  }
  $("#gridEmpty").hidden = list.length > 0;
  $("#filterDot").hidden = !(S.filters.diets.size || S.sort !== "featured");
  const _homeTitle = $("#homeTitle");
  if (_homeTitle) {
    const titleTxt = S.cat === "all" ? t("all_products")
      : (S.sub !== "root" ? subName(S.sub) : name(catOf(S.cat)));
    _homeTitle.textContent = cap(titleTxt);
  }
}

function renderFavs() {
  const favs = ITEMS.filter(i => S.favs.has(i.id));
  const n = gridCols();
  const cols = Array.from({ length: n }, () => []);
  favs.forEach((it, idx) => cols[idx % n].push(cardHTML(it, idx)));
  $("#favGrid").innerHTML = favs.length
    ? cols.map((c, ci) => `<div class="col${ci % 2 ? " offset" : ""}">${c.join("")}</div>`).join("") : "";
  $("#favEmpty").hidden = favs.length > 0;
}

/* ── dark product render ──────────────────────────────── */
function openProduct(id) { setProduct(id, { fresh: true }); show("product"); }

function setProduct(id, { fresh = false } = {}) {
  const i = item(id);
  if (!i) return;
  const same = S.currentItem?.id === id;
  S.currentItem = i;
  /* groups start empty on purpose: nothing is auto-priced, and a required group
     stays unsatisfied until the diner actually picks — that is what the gate reads. */
  if (fresh || !same) { S.pQty = 1; S.pSel = { base: 0, extras: new Set(), groups: {} }; }

  $("#pName").textContent = name(i);
  renderProductTabs(i);
  /* #pImg is reused across items — always clear a previous item's fallback */
  const pimg = $("#pImg");
  const purl = IMG(i.img);
  scoopImgReset(pimg);
  pimg.onerror = () => scoopImgFail(pimg);
  if (purl) { pimg.src = purl; } else { scoopImgFail(pimg); }
  $("#pFav").classList.toggle("is-on", S.favs.has(id));
  $("#pFav").setAttribute("aria-pressed", String(S.favs.has(id)));

  /* honest stock line: sold out, a real low-stock count, or nothing at all.
     The demo printed "99 in stock" for every untracked item on the menu. */
  const stockEl = $("#pStock");
  stockEl.classList.remove("low", "out");
  if (i.soldOut) { stockEl.textContent = t("sold_out"); stockEl.classList.add("out"); }
  else if (i.stockKnown) {
    stockEl.textContent = t("stock_n").replace("{n}", i.stock);
    if (i.stock <= (i.lowThreshold ?? 3)) stockEl.classList.add("low");
  } else { stockEl.textContent = ""; }
  $("#pPrice").textContent = fmt(i.price);

  const tags = (i.tags ?? []).map(tg => `<span class="meta-chip tag">${tg === "vegan" ? ICONS.leaf : ""}${t("tag_" + tg)}</span>`).join("");
  $("#pMeta").innerHTML = `
    ${i.rating ? `<span class="meta-chip star">${ICONS.star.replace("<svg", '<svg class="ic"')}${i.rating}${i.reviews ? ` (${i.reviews})` : ""}</span>` : ""}
    ${i.time ? `<span class="meta-chip">${ICONS.clock}${i.time} ${t("min_u")}</span>` : ""}
    ${i.kcal ? `<span class="meta-chip">${ICONS.flame}${i.kcal} ${t("kcal_u")}</span>` : ""}
    ${tags}`;

  /* base swatch card only when the item has real variants */
  const baseCard = $("#baseSwatches").closest(".optcard");
  if (i.base) {
    if (baseCard) baseCard.hidden = false;
    /* the OWNER's group name ("Style"/"Size"), falling back to a neutral prompt */
    $("#baseLabel").textContent = i.base.label || t("base_group");
    renderSwatches(i);
  } else if (baseCard) {
    baseCard.hidden = true;
  }
  renderGroups(i);
  renderExtras(i);
  $("#pDesc").textContent = desc(i);
  $(".p-desc").hidden = !desc(i);

  /* product reviews — approved MenuReview rows shipped in the item payload */
  const pr = $("#pReviews");
  if (pr) {
    const escR = s => String(s == null ? "" : s).replace(/[&<>"]/g, c =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
    pr.innerHTML = window.__mbItemReviewsOn === false ? "" : (i.reviewList || []).length
      ? `<header class="optcard-head"><span class="optcard-ic">${ICONS.star}</span><b>${t("reviews_title")}</b></header>` +
        i.reviewList.map(review => {
          const stars = Math.max(1, Math.min(5, Number(review.stars) || 0));
          return `<article class="review-card"><b>${escR(review.name)}</b><span class="review-stars">${"★".repeat(stars)}<span class="review-stars-dim">${"★".repeat(5 - stars)}</span></span>${review.body ? `<p>${escR(review.body)}</p>` : ""}</article>`;
        }).join("")
      : `<header class="optcard-head"><span class="optcard-ic">${ICONS.star}</span><b>${t("reviews_title")}</b></header><p class="reviews-empty">${t("reviews_empty")}</p>`;
  }

  /* inline "Rate this dish" quick-rate — rebuilt each open (stars reset), sits
     just above the reviews section. Tapping a star opens the shared review
     write-sheet preselected to that many stars (window.mbItemReviews.open). */
  if (window.__mbItemReviewsOn !== false && pr && pr.parentNode) {
    let rr = document.getElementById("rateRow");
    if (!rr) { rr = document.createElement("div"); rr.className = "rate-row"; rr.id = "rateRow"; pr.parentNode.insertBefore(rr, pr); }
    rr.innerHTML = `<span class="rate-label">${t("rate_dish")}</span><span class="rate-stars" id="rateStars">${[1, 2, 3, 4, 5].map(n => `<button data-rate="${n}" type="button" aria-label="${n}"><svg class="ic" viewBox="0 0 24 24"><path d="m12 3 2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8Z"/></svg></button>`).join("")}</span>`;
  }
  updateProductCta();
}

function renderSwatches(i) {
  if (!i.base) { $("#baseSwatches").innerHTML = ""; return; }
  $("#baseSwatches").innerHTML = i.base.opts.map((o, oi) => `
    <button class="swatch ${S.pSel.base === oi ? "is-on" : ""}" data-sw="${oi}">
      <i class="sw-dot" style="background:${o.dot}"></i>
      <span>${eh(name(o))}</span>
      ${o.delta ? `<span class="sw-delta">+${fmtShort(o.delta)}</span>` : ""}
      <span class="sw-check">${ICONS.check}</span>
    </button>`).join("");
}

/* #global-modifiers: each group is its own optcard, styled with the same
   swatch/extra language the rest of the sheet uses. */
function groupHTML(g, picked) {
  const tag = g.required
    ? `<span class="optcard-tag req">${SCI.required || t("required")}</span>`
    : `<span class="optcard-tag">${SCI.optional || t("optional")}</span>`;
  const hint = g.max && g.max > 1 ? `<span class="optcard-hint">${t("pick_upto").replace("{n}", g.max)}</span>` : "";
  return `
    <section class="optcard" id="grp-${g.id}" data-grp="${g.id}">
      <header class="optcard-head">
        <span class="optcard-ic">${ICONS.check}</span>
        <b>${eh(name(g))}</b>
        ${tag}${hint}
      </header>
      <div class="extras">
        ${g.opts.map(o => `
        <button class="extra ${g.type === "radio" ? "is-radio" : ""} ${picked.includes(o.i) ? "is-on" : ""}" data-opt="${o.i}">
          <span class="extra-box">${ICONS.check}</span>
          <span class="extra-name">${eh(name(o))}</span>
          ${o.price ? `<span class="extra-price">+${fmt(o.price)}</span>` : ""}
        </button>`).join("")}
      </div>
    </section>`;
}

function renderGroups(i) {
  const host = $("#pGroups");
  if (!host) return;
  const picked = selGroups(S.pSel);
  host.innerHTML = (i.groups || []).map(g => groupHTML(g, picked[g.id] || [])).join("");
}

function renderExtras(i) {
  $("#extrasCard").hidden = !i.extras.length;
  $("#extraList").innerHTML = i.extras.map((x, xi) => `
    <button class="extra ${S.pSel.extras.has(xi) ? "is-on" : ""}" data-ex="${xi}">
      <span class="extra-box">${ICONS.check}</span>
      <span class="extra-name">${eh(name(x))}</span>
      <span class="extra-price">+${fmt(x.price)}</span>
    </button>`).join("");
}

function updateProductCta() {
  const i = S.currentItem; if (!i) return;
  $("#pQtyVal").textContent = S.pQty;
  $("#pTotal").textContent = fmt(unitPrice(i, S.pSel) * S.pQty);
  $("#addToCart").disabled = !!i.soldOut;
}

/* ── cart ─────────────────────────────────────────────── */
/* two lines merge only when base, extras AND group selections all match */
function groupSig(sel) {
  const picked = selGroups(sel);
  return Object.keys(picked).sort().map(gid => `${gid}:${[...picked[gid]].sort((a, b) => a - b).join("-")}`).join(";");
}
function lineKey(id, sel) { return `${id}|${sel.base}|${[...sel.extras].sort().join(",")}|${groupSig(sel)}`; }

function addToCart(id, sel, qty, fromImg) {
  const i = item(id);
  if (i.soldOut) { toast(t("sold_out")); return; }
  const key = lineKey(id, sel);
  const line = S.cart.find(l => l.key === key);
  const cap9 = i.stock ?? 99;
  if (line) {
    if (line.qty + qty > cap9) {
      line.qty = cap9;
      store.set("cart", S.cart);
      syncBadge(false);
      toast(t("only_left").replace("{n}", cap9)); /* clamp wins — no “added” toast */
      return;
    }
    line.qty += qty;
  } else {
    S.cart.push({ key, id, qty: Math.min(qty, cap9), sel: { base: sel.base, extras: [...sel.extras], groups: JSON.parse(JSON.stringify(selGroups(sel))) }, note: "" });
  }
  store.set("cart", S.cart);
  syncBadge(true);
  toast(`${eh(name(i))} — ${t("added")}`);
  if (fromImg) flyToCart(fromImg);
}

/* ── account-page reorder handoff (P6-301) ────────────────
   The account page (account.blade.php) writes the reorder payload to
   cv_reorder_<slug> and redirects here. The shared helper reads it back and
   calls __scoopPickup for each line. We translate the choice-group selections
   (which carry labels) onto scoop's `sel.groups['g' + groupId] = [optIdx, …]`
   shape — the variant goes into sel.base; addToCart resets `note: ""` so we
   push the line ourselves. */
window.__scoopPickup = function (line) {
  const i = item(line.item_id);
  if (!i || i.soldOut) return false;
  /* scoop's variant lives in `i.base.opts[]`; payload.variant is the DB id.
     Resolve it to the local base index (sel.base). */
  let baseIdx = 0;
  if (i.base && i.base.opts && i.base.opts.length) {
    let found = 0;
    for (let bi = 0; bi < i.base.opts.length; bi++) {
      if (Number(i.base.opts[bi].variantId || 0) === Number(line.variant || 0)) { found = bi; break; }
    }
    baseIdx = found;
  }
  /* payload.extras is [{id,title,price}]; scoop's sel.extras is a Set of
     indices into i.extras. Translate ids back to local indices. */
  const extrasIdx = new Set();
  (line.extras || []).forEach(function (e) {
    if (!i.extras) return;
    for (let xi = 0; xi < i.extras.length; xi++) {
      if (Number(i.extras[xi].id) === Number(e.id)) { extrasIdx.add(xi); break; }
    }
  });
  const groups = {};
  (line.options || []).forEach(function (group) {
    const gid = "g" + group.group_id;
    const grp = (i.groups || []).find(function (g) { return String(g.id) === gid; });
    if (!grp) return;
    const picks = (group.selected || [])
      .map(function (s) { const o = grp.opts.find(function (x) { return x.name === s.option; }); return o ? o.i : -1; })
      .filter(function (oi) { return oi >= 0; });
    if (picks.length) groups[grp.id] = picks;
  });
  const sel = { base: baseIdx, extras: extrasIdx, groups: groups };
  if ((i.groups || []).some(function (g) {
    const need = g.required ? Math.max(1, g.min || 0) : (g.min || 0);
    return (groups[g.id] || []).length < need;
  })) return false;
  const key = lineKey(line.item_id, sel);
  const cap9 = (i && i.stock) ? i.stock : 99;
  const existing = S.cart.find(function (l) { return l.key === key; });
  if (existing) {
    existing.qty = Math.min(existing.qty + line.qty, cap9);
  } else {
    S.cart.push({ key: key, id: line.item_id, qty: Math.min(line.qty, cap9), sel: sel, note: line.note || "" });
  }
  store.set("cart", S.cart);
  syncBadge(true);
  toast(`${eh(name(i))} — ${t("added")}`);
  return true;
};

/* Share really shares. The demo toasted "Link copied to clipboard" and copied nothing —
   the customer pasted whatever was already on their clipboard into the family chat. */
function shareItem(id) {
  const i = item(id);
  if (!i) return;
  const url = location.origin + location.pathname + "?item=" + encodeURIComponent(id);
  const data = { title: name(i), text: name(i), url };
  if (navigator.share) {
    navigator.share(data).catch(() => {});
    return;
  }
  const done = () => toast(t("share_ok"));
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(done, () => toast(t("share_fail")));
  } else {
    /* http/older-Safari fallback: a hidden textarea + execCommand still works */
    const ta = document.createElement("textarea");
    ta.value = url; ta.setAttribute("readonly", ""); ta.style.cssText = "position:fixed;opacity:0";
    document.body.appendChild(ta); ta.select();
    let ok = false; try { ok = document.execCommand("copy"); } catch { ok = false; }
    ta.remove();
    toast(ok ? t("share_ok") : t("share_fail"));
  }
}

/* arc fly-to-cart: the cup hops into the cart chip */
function flyToCart(img) {
  if (reduceMotion() || !img) return;
  const target = ROOTS.includes(active)
    ? $('.nav-item[data-tab="cart"] .nav-chip')
    : $("#addToCart");
  if (!target) return;
  const a = img.getBoundingClientRect(), b = target.getBoundingClientRect();
  const lay = $("#flyLayer"), lr = lay.getBoundingClientRect();
  const ghost = document.createElement("img");
  ghost.src = img.currentSrc || img.src; ghost.className = "fly-img";
  ghost.style.left = a.left - lr.left + a.width / 2 - 30 + "px";
  ghost.style.top = a.top - lr.top + a.height / 2 - 30 + "px";
  lay.appendChild(ghost);
  const dx = (b.left + b.width / 2) - (a.left + a.width / 2);
  const dy = (b.top + b.height / 2) - (a.top + a.height / 2);
  ghost.animate([
    { transform: "translate(0,0) scale(1)", opacity: 1 },
    { transform: `translate(${dx * 0.5}px, ${dy * 0.5 - 110}px) scale(.72)`, opacity: 1, offset: 0.55 },
    { transform: `translate(${dx}px, ${dy}px) scale(.15)`, opacity: 0.35 },
  ], { duration: 720, easing: "cubic-bezier(.3,.05,.3,1)" }).onfinish = () => ghost.remove();
}

function syncBadge(bump) {
  const n = cartCount() + comboCount(), b = $("#cartBadge");
  b.hidden = n === 0; b.textContent = n;
  if (bump && n) { b.classList.remove("bump"); void b.offsetWidth; b.classList.add("bump"); }
  /* product footer: a direct jump to the cart, shown once anything is in it */
  const pj = $("#pCartJump");
  if (pj) { pj.hidden = n === 0; const pjc = $("#pCartJumpCount"); if (pjc) pjc.textContent = n; }
}

/* count on the new Favourites nav tab — mirrors the cart badge */
function syncFavBadge() {
  const b = $("#favNavBadge"); if (!b) return;
  const n = S.favs.size;
  b.hidden = n === 0; b.textContent = n;
}

/* COMBOS-V2 — read-only "Combos" group appended to the cart list and the
   checkout summary. Tapping a row reopens the combo sheet's basket view,
   which is where quantities are edited. */
function comboGroupHTML(compact) {
  const lines = comboLines();
  if (!lines.length) return "";
  let h = '<div class="sc-combo-group" data-combo-open style="border-top:1px dashed var(--line,#8883);padding-top:8px;margin-top:8px;cursor:pointer">';
  lines.forEach(L => {
    h += '<div class="ck-row ck-row--combo">' +
      '<div class="ck-mid"><b>' + spEsc(L.name) + "</b>" +
      (L.parts.length ? "<small>" + spEsc(L.parts.join(" · ")) + "</small>" : "") + "</div>" +
      '<span class="ck-qty">×' + L.qty + "</span>" +
      '<span class="ck-price">' + fmt(L.price) + "</span></div>";
  });
  if (!compact) h += '<small style="opacity:.65">' + spEsc(t("edit")) + " ›</small>";
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
  const has = S.cart.length > 0 || comboCount() > 0;
  $("#cartEmpty").hidden = has;
  $("#cartTools").style.display = has ? "" : "none";
  $("#cartCta").hidden = !has;
  $("#clearCart").style.visibility = S.cart.length ? "" : "hidden";
  $("#cartList").innerHTML = comboGroupHTML(false) + S.cart.map((l, li) => {
    const i = item(l.id);
    const unit = unitPrice(i, { base: l.sel.base, extras: l.sel.extras, groups: selGroups(l.sel) });
    const wasUnit = i.wasDelta ? unit + i.wasDelta : 0;
    return `
    <div class="cart-item" data-key="${l.key}" style="animation-delay:${li * 50}ms">
      <div class="ci-row">
        <button class="ci-im" data-open="${i.id}" type="button" aria-label="${eh(name(i))}">${imgTag(i.img)}</button>
        <div>
          <button class="ci-name" data-open="${i.id}" type="button">${eh(name(i))}</button>
          <div class="ci-opts">${selSummary(i, { base: l.sel.base, extras: l.sel.extras, groups: selGroups(l.sel) })}</div>
          <div class="ci-priceline"><span class="ci-price">${fmt(unit * l.qty)}</span>${
            wasUnit ? `<del class="ci-was">${fmt(wasUnit * l.qty)}</del>` : ""}${
            l.qty > 1 ? `<small class="ci-unit">${l.qty} × ${fmt(unit)}</small>` : ""}</div>
        </div>
        <div class="ci-side">
          <div class="qty">
            <button class="qty-btn" data-cq="-1" aria-label="Decrease">${ICONS.minus}</button>
            <output>${l.qty}</output>
            <button class="qty-btn" data-cq="1" aria-label="Increase">${ICONS.plus}</button>
          </div>
          <div class="ci-tools">
            <button class="ci-note-btn ${l.note ? "is-on" : ""}" data-note-toggle>${ICONS.pencil}<span>${t("note_add")}</span></button>
            <button class="ci-rm" data-rm aria-label="${t("removed")}">${ICONS.trash}</button>
          </div>
        </div>
      </div>
      <div class="ci-note" ${l.note ? "" : "hidden"}>
        <input data-notekey="${l.key}" value="${l.note.replace(/"/g, "&quot;")}" placeholder="${t("note_ph")}">
      </div>
    </div>`;
  }).join("");
  /* S.coupon is an OBJECT — assigning it straight to the field printed
     "[object Object]" into the promo box the moment the cart re-rendered. */
  const ci = $("#couponInput");
  if (!ci.value && (S.coupon || S.couponPrefill)) ci.value = S.coupon ? S.coupon.code : S.couponPrefill;
  syncCouponUi();
  renderOtFields(); renderTotals();
}

function renderOtFields() {
  const f = $("#otFields");
  /* boot() removes #waiterRow outright when call-waiter is off (no dine-in),
     so this must be null-guarded — throwing here aborts renderCart() before
     renderTotals() and leaves the cart + checkout totals blank. */
  const w = $("#waiterRow");
  if (w) { w.hidden = S.orderType !== "dinein"; }
  if (S.orderType === "dinein") {
    /* A QR scan already knows the table — show it, don't ask again. Otherwise a plain
       number field: the demo offered a hardcoded "Table 01 … Table 12" dropdown, which
       is fiction on a restaurant with 6 tables or 40, and its permanent ✓ "Choose your
       table" hint read as a satisfied validation under an untouched control. */
    const forced = CFG.forceTable && CFG.defaultTable ? String(CFG.defaultTable) : "";
    f.innerHTML = `<label class="ot-field"><span class="ot-field-label">${t("table")}</span>
      <input id="tableSel" inputmode="numeric" autocomplete="off" value="${eh(forced)}"
        placeholder="${t("table_ph")}" ${forced ? "readonly" : ""}></label>`;
  } else {
    /* Room service: no table picker (the room came from the QR), no phone, no
       address — the room key rides along on the shared hotel partial's hidden
       `room` field. */
    const hint = S.orderType === "delivery" ? "delivery_hint"
      : S.orderType === "roomservice" ? "room_hint" : "pickup_hint";
    /* the owner's real prep window when they set one; never an invented "20–30 min" */
    const eta = PREP_ETA ? ` · ${t("eta_hint").replace("{n}", PREP_ETA)}` : "";
    if (S.orderType === "delivery") {
      /* Same autocomplete + use-my-location affordance as checkout — the field
         is rebuilt on every order-type switch, so it is re-wired right here. */
      const co = $("#custAddr");
      const cur = (co ? co.value : "").replace(/"/g, "&quot;");
      f.innerHTML = `
        <div class="addr-wrap" id="cartAddrField">
          <label class="addr-field">
            ${ICONS.pin}
            <input id="cartAddr" value="${cur}" placeholder="${t("addr_ph")}" autocomplete="off" autocorrect="off" spellcheck="false">
          </label>
          <button class="uselocbtn" id="cartUseLocation" type="button" title="${t("use_location")}" aria-label="${t("use_location")}">${ICONS.loc}</button>
          <div class="addr-suggest" id="cartAddrSuggest" hidden></div>
        </div>
        <p class="ot-hint">${ICONS.clock}<span>${t(hint)}${eta}</span></p>`;
      wireAddressField($("#cartAddr"), $("#cartAddrSuggest"), $("#cartUseLocation"), null, v => {
        const ci = $("#custAddr"); if (ci) ci.value = v;
      });
      return;
    }
    f.innerHTML = `<p class="ot-hint">${ICONS.clock}<span>${t(hint)}${eta}</span></p>`;
  }
}

function totalsHTML() {
  const T = cartTotals();
  const delRow = S.orderType === "delivery" && (S.cart.length || comboCount())
    ? `<div class="tr"><span>${t("delivery_fee")}</span>${S.freeDel ? `<b class="free">${t("free")}</b>` : `<b>${fmt(T.del)}</b>`}</div>`
    : "";
  /* a FIXED tax is a flat amount — printing "(5%)" beside it would be a lie */
  const taxLabel = (CFG.taxLabel || "Tax") + (TAX_TYPE === "percentage" && TAX_CHARGE ? ` (${TAX_CHARGE}%)` : "");
  return `
    <div class="tr"><span>${t("subtotal")}</span><b>${fmt(T.sub)}</b></div>
    ${T.disc ? `<div class="tr disc"><span>${t("discount")} (${S.coupon ? S.coupon.code : ""})</span><b>−${fmt(T.disc)}</b></div>` : ""}
    ${T.tax ? `<div class="tr"><span>${taxLabel}</span><b>${fmt(T.tax)}</b></div>` : ""}
    ${delRow}
    ${T.tip ? `<div class="tr"><span>${t("tip_label")} (${S.tip}%)</span><b>${fmt(T.tip)}</b></div>` : ""}
    ${(CFG.minOrderValue > 0 && T.sub > 0 && T.sub < CFG.minOrderValue) ? `<div class="tr minwarn"><span>⚠ ${t("min_order")}</span><b>${fmt(CFG.minOrderValue)}</b></div>` : ""}
    <div class="tr grand"><span>${t("total")}</span><b>${fmt(T.total)}</b></div>`;
}

function renderTotals() {
  $("#totalsBox").innerHTML = totalsHTML();
  $("#cartCtaTotal").textContent = fmt(cartTotals().total);
}

/* ── R6 · guest saved addresses (localStorage) ──────────
   A guest gets the same tap-to-fill address chips the server gives a signed-in
   diner (#savedAddrs). Stored under scoop_recent_addrs_<slug> — the key is built
   INLINE every call (never a hoisted var), so it is never `undefined` when a
   render runs at load. Gated on !isLoggedIn so it never fights the server book. */
function scoopAddrKey() { return "recent_addrs_" + (CFG.restaurantSlug || ""); }
function readRecentAddrs() {
  try { return (store.get(scoopAddrKey(), []) || []).filter(a => a && a.addr); }
  catch (e) { return []; }
}
function rememberAddress(addr, lat, lng) {
  addr = (addr || "").trim();
  if (!addr || CFG.isLoggedIn) return;
  const l = readRecentAddrs().filter(a => a.addr !== addr);
  l.unshift({ addr, lat: lat || "", lng: lng || "" });
  try { store.set(scoopAddrKey(), l.slice(0, 5)); } catch (e) {}
  renderRecentAddrs();
}
function renderRecentAddrs() {
  const box = $("#guestAddrs");
  if (!box || CFG.isLoggedIn) return;
  const l = readRecentAddrs();
  if (!l.length) { box.hidden = true; box.innerHTML = ""; return; }
  const esc = s => String(s == null ? "" : s).replace(/[&<>"]/g, c =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
  box.hidden = false;
  box.innerHTML = l.map(a => `
    <button class="saved-addr" type="button" data-addr="${esc(a.addr)}" data-lat="${esc(a.lat)}" data-lng="${esc(a.lng)}">
      ${ICONS.pin}
      <span class="sa-text"><small>${esc(a.addr)}</small></span>
    </button>`).join("");
}

/* ── checkout ─────────────────────────────────────────── */
function renderCheckout() {
  renderRecentAddrs();   /* R6: refresh guest address chips each time checkout opens */
  $("#addrBlock").style.display = S.orderType === "delivery" ? "" : "none";
  $("#pmList").innerHTML = PAY_METHODS.map(m => {
    const sub = m.walletSub ? `${t("wallet_bal")} ${fmt(WALLET_BAL)}`
      : (m.sub === "pay_on_handover" ? t("pay_on_handover") : m.sub);
    return `
    <button class="pm ${S.pay === m.id ? "is-on" : ""}" data-pm="${m.id}">
      <span class="pm-ic">${ICONS[m.icon]}</span>
      <b>${t(m.key)}${sub ? `<small>${sub}</small>` : ""}</b>
      <span class="pm-radio"></span>
    </button>`;
  }).join("");
  $("#cardForm").hidden = S.pay !== "card";
  /* Order summary — what the customer is about to pay for, tap Edit → cart. */
  const ck = $("#ckItems");
  if (ck) {
    ck.hidden = S.cart.length === 0 && comboCount() === 0;
    ck.innerHTML = `<div class="ck-head"><h3>${t("your_order")}</h3><button class="ck-edit" id="ckEdit" type="button">${t("edit")}</button></div>` +
      S.cart.map(l => {
        const i = item(l.id); if (!i) return "";
        const sel = { base: l.sel.base, extras: l.sel.extras, groups: selGroups(l.sel) };
        const unit = unitPrice(i, sel);
        const wasUnit = i.wasDelta ? unit + i.wasDelta : 0;
        const sum = selSummary(i, sel);
        return `<div class="ck-row">
          ${imgTag(i.img)}
          <div class="ck-mid"><b>${eh(name(i))}</b>${sum ? `<small>${sum}</small>` : ""}</div>
          <span class="ck-qty">×${l.qty}</span>
          <span class="ck-price">${fmt(unit * l.qty)}${wasUnit ? `<del>${fmt(wasUnit * l.qty)}</del>` : ""}</span>
        </div>`;
      }).join("") + comboGroupHTML(true);
    const ed = $("#ckEdit"); if (ed) ed.onclick = () => show("cart");
  }
  $("#checkoutTotals").innerHTML = totalsHTML();
  $("#checkoutCtaTotal").textContent = fmt(cartTotals().total);
}

/* ── place order → real POST to restaurant.submitOrder ──── */
const OT_SERVER = { delivery: "delivery", pickup: "takeaway", dinein: "on-table", roomservice: "room_service" };
const PAY_SERVER = { cash: "pay_on_counter", card: "pay_online", wallet: "pay_online" };

function placeOrder() {
  const nameV = $("#custName").value.trim();
  const phoneV = $("#custPhone").value.trim();
  if (!nameV) return toast(t("fill_contact"));
  /* dine-in and room service are both fulfilled on-premise: name only, no phone. */
  const onPrem = S.orderType === "dinein" || S.orderType === "roomservice";
  if (!onPrem && !phoneV) return toast(t("fill_contact"));
  if (S.orderType === "delivery" && !$("#custAddr").value.trim()) return toast(t("fill_addr"));
  if (!S.cart.length && !comboCount()) return; /* combos-only orders are legal */
  if (CFG.minOrderValue > 0 && cartSubtotal() < CFG.minOrderValue) return toast(t("min_order") + " " + fmt(CFG.minOrderValue));

  const btn = $("#placeOrder");
  btn.disabled = true;
  btn.querySelector(".spinner").hidden = false;
  btn.querySelector(".po-label").textContent = "…";
  const restore = () => {
    btn.disabled = false;
    btn.querySelector(".spinner").hidden = true;
    btn.querySelector(".po-label").textContent = t("place_order");
  };

  const T = cartTotals();
  const payload = orderLines();
  const notes = S.cart.filter(l => l.note).map(l => `${name(item(l.id))}: ${l.note}`).join(" | ");

  const fd = new FormData();
  fd.append("_token", CFG.csrf);
  fd.append("items", JSON.stringify(payload));
  fd.append("name", nameV);
  fd.append("ordering-type", OT_SERVER[S.orderType]);
  if (onPrem) fd.append("table", ($("#tableSel") || {}).value || CFG.defaultTable || "");
  else fd.append("phone-number", phoneV);
  if (S.orderType === "delivery") fd.append("address", $("#custAddr").value.trim());
  fd.append("pay_via", PAY_SERVER[S.pay] || "pay_on_counter");
  if (T.tip) fd.append("tip_amount", String(T.tip.toFixed(MONEY_DP)));
  if (notes) fd.append("message", notes);
  if (S.pay === "wallet") fd.append("use_wallet", "1");
  if (S.coupon) fd.append("coupon_code", S.coupon.code);
  if (S.when === "later" && $("#whenTime").value) fd.append("scheduled_at", $("#whenTime").value);
  const bid = new URLSearchParams(location.search).get("branch_id");
  if (bid) fd.append("branch_id", bid);

  fetch(EP.submitOrder, { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" }, body: fd })
    .then(r => r.json())
    .then(res => {
      if (!res || !res.success) {
        restore();
        toast(res && res.message ? String(res.message).replace(/<[^>]+>/g, "").slice(0, 120) : t("coupon_bad"));
        return;
      }
      /* online-payment redirect wins immediately */
      if (res.message) { window.location.href = res.message; return; }

      /* NEVER invent an order number. The demo fell back to "#SCP-" + a random 4 digits
         when the server returned none (it returns none — see BLOCKED-SHARED), so every
         customer walked away quoting a reference that existed in no database. */
      const code = res.order_code || res.code || res.order_no || res.order_id || "";
      const order = {
        code, type: S.orderType, total: T.total, cur: S.cur,
        items: S.cart.map(l => ({ ...l })), placedAt: Date.now(),
        trackUrl: res.track_url || "",
        when: S.when === "later" ? $("#whenTime").value : "asap",
      };
      S.orders.unshift(order);
      store.set("orders", S.orders.slice(0, 20));
      /* R6: a delivered order remembers its address (guests only, no-op if logged in) */
      if (S.orderType === "delivery") rememberAddress($("#custAddr").value, ($("#custLat") || {}).value, ($("#custLng") || {}).value);
      if (S.freeDel && S.orderType === "delivery") { S.freeDel = false; store.set("freeDel", false); }
      S.cart = []; S.coupon = null; S.couponPrefill = ""; S.tip = 0;
      store.set("cart", S.cart);
      $("#couponInput").value = "";
      $$("#tipRow button").forEach(b => b.classList.toggle("is-on", b.dataset.tip === "0"));
      syncBadge(false);
      restore();

      const codeEl = $("#confirmCode");
      codeEl.textContent = code ? (t("order_no") + " " + (String(code).startsWith("#") ? code : "#" + code)) : "";
      codeEl.hidden = !code;
      /* the server's own estimate, or nothing — not a client-side countdown */
      const etaEl = $("#confirmEta");
      const etaLine = (res.prep_eta && res.prep_eta.line) || (PREP_ETA ? t("eta_hint").replace("{n}", PREP_ETA) : "");
      etaEl.textContent = etaLine; etaEl.hidden = !etaLine;
      /* Track = a real link to the server-rendered tracking page for THIS order */
      const link = $("#trackLink");
      if (order.trackUrl) { link.href = order.trackUrl; link.hidden = false; }
      else { link.removeAttribute("href"); link.hidden = true; }
      show("confirmed");
      stack.length = 0; /* confirmed is terminal; back → home */
      burstConfetti();
      if (res.whatsapp_url) mbWhatsAppHandoff(res.whatsapp_url, res.whatsapp_handoff);
    })
    .catch(() => { restore(); toast(t("net_err")); });
}

/* confetti — black, white & yellow */
function burstConfetti() {
  const colors = ["#141416", "#F5C518", "#FFFFFF", "#FFC93C", "#141416"];
  $("#confetti").innerHTML = Array.from({ length: 30 }, (_, i) => {
    const tx = (Math.random() * 2 - 1) * 150, ty = -40 - Math.random() * 200;
    return `<i class="${Math.random() > .5 ? "round" : ""}" style="--tx:${tx}px;--ty:${ty}px;--c:${colors[i % colors.length]};--d:${Math.random() * .35}s;--rz:${Math.random() * 360}deg;--spin:${180 + Math.random() * 400}deg"></i>`;
  }).join("");
}

/* ── tracking ──────────────────────────────────────────
   The demo's timeline was a lie generator: a setInterval walked "Order placed →
   Scooping & packing → On the way → Delivered" every 7 seconds, and the ETA was
   `12 − stage*3` minutes. It never asked the server anything. Real tracking is the
   server-rendered track page (track.blade.php), reached through res.track_url. */

/* ── profile ──────────────────────────────────────────── */
function renderProfile() {
  const pts = $("#ptsAmt"); if (pts) pts.textContent = S.points;
  $("#favCount").textContent = S.favs.size;
  const bs = $("#bookSub");
  if (bs) bs.textContent = t("book_table");

  $("#ordersEmpty").hidden = S.orders.length > 0;
  /* each row links to that order's REAL tracking page; an order placed before this
     fix (or one the server gave no track_url for) simply is not clickable. */
  $("#ordersList").innerHTML = S.orders.slice(0, 5).map(o => {
    const when = new Date(o.placedAt || Date.now())
      .toLocaleDateString(document.documentElement.lang || "en", { day: "2-digit", month: "short" });
    const label = o.code ? `${o.code} · ${fmt(o.total)}` : fmt(o.total);
    return `
    <${o.trackUrl ? "a" : "div"} class="order-row"${o.trackUrl ? ` href="${o.trackUrl}"` : ""}>
      ${ICONS.bag}
      <div><b>${label}</b>
      <small>${o.items.length} × · ${t("ot_" + o.type)} · ${when}</small></div>
      ${o.trackUrl ? `<span class="order-status">${t("track_order")}</span>` : ""}
    </${o.trackUrl ? "a" : "div"}>`;
  }).join("");
}

/* ── loyalty — the REAL programme ──────────────────────
   The demo kept a localStorage points balance (+1 per currency unit, credited by the
   client), and a catalogue of three invented rewards that debited it. The restaurant
   never saw any of it. Points now come from LoyaltyController@customerBalance and
   redemption goes through @redeem; both require a signed-in diner, so a guest simply
   does not see the card. */
function loadLoyalty() {
  if (!EP.loyaltyBalance || !CFG.isLoggedIn) { hideLoyalty(); return; }
  fetch(EP.loyaltyBalance, { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
    .then(r => (r.ok ? r.json() : null))
    .then(d => {
      if (!d || !d.active) { hideLoyalty(); return; }
      S.loyalty = d;
      S.points = Number(d.balance) || 0;
      renderProfile();
      renderRedeemSheet();
      const btn = $("#redeemBtn");
      if (btn) btn.disabled = !d.ready_to_redeem;
    })
    .catch(hideLoyalty);
}
function hideLoyalty() {
  const card = document.querySelector(".points-card");
  if (card) card.hidden = true;
}
function renderRedeemSheet() {
  const list = $("#rewardList");
  if (!list) return;
  const d = S.loyalty;
  $("#redeemPts").textContent = S.points;
  if (!d) { list.innerHTML = ""; return; }
  list.innerHTML = `
    <button class="rrow" id="redeemNow" ${d.ready_to_redeem ? "" : "disabled"}>
      ${ICONS.gift}
      <b>${eh(d.reward_description) || t("redeem")}<small>${t("your_balance")} ${d.balance} / ${d.reward_threshold} ${t("pts")}</small></b>
      <span class="cost">${d.reward_threshold} ${t("pts")}</span>
    </button>`;
}
document.addEventListener("click", e => {
  if (!e.target.closest("#redeemNow") || !EP.loyaltyRedeem) return;
  fetch(EP.loyaltyRedeem, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf, Accept: "application/json" },
    body: JSON.stringify({ _token: CFG.csrf }),
  }).then(r => r.json()).then(res => {
    if (res && res.success) {
      toast(res.reward_description || t("redeemed"));
      loadLoyalty();
      sheet("redeemSheet", false);
    } else { toast((res && res.error) || t("not_enough")); }
  }).catch(() => toast(t("net_err")));
});

/* Reserve / Waitlist are real POST forms rendered by the blade against
   publicView.reservation.book and publicView.waitlist.join — this only swaps panels. */
function renderBookSheet() {
  const seg = $("#bookSeg");
  if (!seg) return;
  $$("#bookSeg button").forEach(b => b.classList.toggle("is-on", b.dataset.book === S.bookMode));
  const rp = $("#reservePanel"), wp = $("#waitPanel");
  if (rp) rp.hidden = S.bookMode !== "reserve";
  if (wp) wp.hidden = S.bookMode !== "waitlist";
}

/* ── i18n apply ───────────────────────────────────────── */
function applyLang() {
  document.documentElement.lang = S.lang;
  document.documentElement.dir = S.lang === "ar" ? "rtl" : "ltr";
  /* Only fill nodes we have a string for. Blade already rendered every [data-i18n]
     element through ___() in the STORE's locale — blindly overwriting them with the
     bundled en/ar dictionary is what forced English onto every other locale. */
  $$("[data-i18n]").forEach(n => { const v = SRV[n.dataset.i18n]; if (v) n.textContent = v; });
  $$("[data-i18n-ph]").forEach(n => { const v = SRV[n.dataset.i18nPh]; if (v) { n.placeholder = v; if (n.hasAttribute("aria-label")) n.setAttribute("aria-label", v); } });
  $$("#curSeg button").forEach(b => b.classList.toggle("is-on", b.dataset.cur === S.cur));
  renderHomeTabs(); renderGrid(); renderCart(); renderProfile();
  if (S.currentItem) setProduct(S.currentItem.id); /* refresh copy in place, keep selections */
  if (active === "favs") renderFavs();
  if (active === "checkout") renderCheckout();
  if (openSheet === "bookSheet") renderBookSheet();
}

/* ── events (delegated) ───────────────────────────────── */
document.addEventListener("click", e => {
  const T = e.target;
  const closest = s => T.closest(s);

  /* R7 · a same-page ?item=<id> link (scoop's own share links, promo CTAs) opens
     the product IN PLACE instead of reloading the SPA. External / other-path links
     and new-tab links fall through untouched. */
  const itemLink = closest('a[href*="item="]');
  if (itemLink && !itemLink.target) {
    try {
      const u = new URL(itemLink.getAttribute("href") || "", location.href);
      const iid = u.searchParams.get("item");
      if (iid && u.pathname === location.pathname && item(iid)) {
        e.preventDefault(); openProduct(iid); return;
      }
    } catch (err) {}
  }

  /* card inner actions run before the card-open handler */
  const fav = closest("[data-fav]");
  if (fav) {
    const id = fav.dataset.fav;
    const on = !S.favs.has(id);
    on ? S.favs.add(id) : S.favs.delete(id);
    store.set("favs", [...S.favs]);
    fav.classList.toggle("is-on", on);
    syncFavBadge();
    toast(t(on ? "fav_on" : "fav_off"));
    if (active === "favs") renderFavs();
    return;
  }
  const share = closest("[data-share]");
  if (share) { shareItem(share.dataset.share); return; }

  /* product quick-rate — inline stars open the shared review write-sheet
     preselected to the tapped rating (one flow, one endpoint). */
  const qr = closest("#rateStars [data-rate]");
  if (qr) {
    const qn = Number(qr.dataset.rate);
    [...document.querySelectorAll("#rateStars button")].forEach((b, bi) => b.classList.toggle("is-on", bi < qn));
    if (window.mbItemReviews) window.mbItemReviews.open(qn);
    return;
  }

  const add = closest("[data-add]");
  if (add) {
    const i = item(add.dataset.add);
    /* #global-modifiers: quick-add can't answer a required group — send the diner
       to the sheet instead of dropping the choice. */
    if ((i.groups || []).some(g => g.required)) { openProduct(i.id); return; }
    addToCart(i.id, { base: 0, extras: new Set(), groups: {} }, 1, add.closest(".pcard")?.querySelector(".pc-imgwrap img"));
    return;
  }
  const open = closest("[data-open]");
  if (open) { openProduct(open.dataset.open); return; }
  const go = closest("[data-go]");
  if (go) { show(go.dataset.go); return; }
  if (closest("[data-back]")) { back(); return; }

  const tab = closest(".nav-item");
  if (tab) {
    const _nt = tab.dataset.tab;
    // Menu jumps to the grid on home; search opens the search popup. Neither is a view.
    if (_nt === "search") { const s = document.getElementById("searchBtn"); if (s) s.click(); return; }
    if (_nt === "menu") { if (active !== "home") show("home"); const g = document.querySelector('[data-mb-grid-lead]') || document.getElementById("grid"); if (g) g.scrollIntoView({ behavior: "smooth", block: "start" }); return; }
    show(_nt, { push: false }); return;
  }

  /* secondary sub-category chips — narrow within the active parent */
  const subchip = closest("#homeSubTabs [data-sub]");
  if (subchip) {
    S.sub = subchip.dataset.sub;
    $$("#homeSubTabs button").forEach(b => b.classList.toggle("is-on", b === subchip));
    renderGrid();
    return;
  }

  /* underline tabs — home categories (parent chips) + product crumbs */
  const tb = closest(".tabs [data-cat]");
  if (tb) {
    S.cat = tb.dataset.cat;
    S.sub = "root"; /* a fresh parent always starts on its "All" sub */
    const nav = tb.closest(".tabs");
    if (nav.id === "homeTabs") {
      /* slide the ink from its previous tab, then swap the sub-chip row */
      nav.querySelectorAll("button").forEach(b => b.classList.toggle("is-on", b === tb));
      setInk(nav);
      renderSubTabs();
      renderGrid();
    } else {
      renderHomeTabs(); renderGrid(); /* product crumb → land on home with cat applied */
    }
    if (active !== "home") show("home", { push: false });
    return;
  }
  if (closest(".tabs [data-self]")) return;

  /* product options */
  const sw = closest("[data-sw]");
  if (sw && S.currentItem) {
    S.pSel.base = +sw.dataset.sw;
    $$("#baseSwatches .swatch").forEach((b, bi) => b.classList.toggle("is-on", bi === S.pSel.base));
    updateProductCta();
    return;
  }
  const ex = closest("[data-ex]");
  if (ex && S.currentItem) {
    const xi = +ex.dataset.ex;
    S.pSel.extras.has(xi) ? S.pSel.extras.delete(xi) : S.pSel.extras.add(xi);
    ex.classList.toggle("is-on", S.pSel.extras.has(xi));
    updateProductCta();
    return;
  }
  /* #global-modifiers: radio replaces, check toggles and refuses past max */
  const opt = closest("#pGroups [data-opt]");
  if (opt && S.currentItem) {
    const gid = opt.closest("[data-grp]").dataset.grp;
    const g = (S.currentItem.groups || []).find(x => x.id === gid);
    if (g) {
      const oi = +opt.dataset.opt;
      const cur = selGroups(S.pSel)[gid] || [];
      if (g.type === "radio") {
        S.pSel.groups[gid] = cur.includes(oi) && !g.required ? [] : [oi];
      } else {
        const set = new Set(cur);
        if (set.has(oi)) set.delete(oi);
        else if (g.max !== null && set.size >= g.max) { toast(t("pick_upto").replace("{n}", g.max)); return; }
        else set.add(oi);
        S.pSel.groups[gid] = [...set];
      }
      const now = S.pSel.groups[gid];
      opt.closest(".extras").querySelectorAll("[data-opt]")
        .forEach(el => el.classList.toggle("is-on", now.includes(+el.dataset.opt)));
      opt.closest("[data-grp]").classList.remove("needs");
      updateProductCta();
    }
    return;
  }
  const q = closest("#pQty [data-q]");
  if (q && S.currentItem) {
    const max = S.currentItem.soldOut ? 1 : (S.currentItem.stock ?? 99);
    S.pQty = Math.min(Math.max(1, S.pQty + +q.dataset.q), max);
    updateProductCta();
    return;
  }

  /* cart line remove (trash) */
  const rm = closest("[data-rm]");
  if (rm) {
    const row = rm.closest(".cart-item");
    const line = S.cart.find(l => l.key === row.dataset.key);
    if (!line) return;
    row.classList.add("removing");
    setTimeout(() => {
      S.cart = S.cart.filter(l => l !== line);
      store.set("cart", S.cart);
      syncBadge(false); renderCart(); toast(t("removed"));
    }, 300);
    return;
  }

  /* cart line qty / note */
  const cq = closest("[data-cq]");
  if (cq) {
    const row = cq.closest(".cart-item");
    const line = S.cart.find(l => l.key === row.dataset.key);
    if (!line) return;
    const d = +cq.dataset.cq;
    const max = item(line.id).stock ?? 99;
    if (d > 0 && line.qty + 1 > max) { toast(t("only_left").replace("{n}", max)); return; }
    line.qty += d;
    if (line.qty <= 0) {
      row.classList.add("removing");
      setTimeout(() => {
        S.cart = S.cart.filter(l => l !== line);
        store.set("cart", S.cart);
        syncBadge(false); renderCart(); toast(t("removed"));
      }, 300);
    } else {
      store.set("cart", S.cart);
      syncBadge(false); renderCart();
    }
    return;
  }
  const nt = closest("[data-note-toggle]");
  if (nt) {
    const note = nt.closest(".cart-item").querySelector(".ci-note");
    note.hidden = !note.hidden;
    if (!note.hidden) note.querySelector("input").focus();
    return;
  }

  /* order type / when */
  const ot = closest("#orderTypeSeg [data-ot]");
  if (ot) {
    S.orderType = ot.dataset.ot;
    $$("#orderTypeSeg button").forEach(b => b.classList.toggle("is-on", b === ot));
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
        opts.push(`<option>${d.toLocaleTimeString(S.lang === "ar" ? "ar" : "en", { hour: "2-digit", minute: "2-digit" })}</option>`);
      }
      sel2.innerHTML = opts.join("");
    }
    return;
  }

  /* payment / tips */
  const pm = closest("[data-pm]");
  if (pm) {
    S.pay = pm.dataset.pm;
    $$(".pm").forEach(b => b.classList.toggle("is-on", b === pm));
    $("#cardForm").hidden = S.pay !== "card";
    return;
  }
  const tip = closest("#tipRow [data-tip]");
  if (tip) {
    S.tip = +tip.dataset.tip;
    $$("#tipRow button").forEach(b => b.classList.toggle("is-on", b === tip));
    renderCheckout();
    return;
  }

  /* filter sheet */
  const diet = closest("#dietRow [data-diet]");
  if (diet) {
    const d = diet.dataset.diet;
    S.filters.diets.has(d) ? S.filters.diets.delete(d) : S.filters.diets.add(d);
    diet.classList.toggle("is-on");
    updateFilterCount();
    return;
  }
  const so = closest("#sortList [data-sort]");
  if (so) {
    S.sort = so.dataset.sort;
    $$("#sortList .rrow").forEach(b => b.classList.toggle("is-on", b === so));
    updateFilterCount();
    return;
  }

  /* rewards — the client-side "10% off / free delivery / bonus scoop" catalogue is gone.
     It debited an imaginary localStorage balance and, for the 10%-off reward, assigned
     S.coupon = "REWARD10" (a STRING where every other reader expects an object), so the
     next order POSTed coupon_code=undefined and the discount silently never applied. */

  /* book sheet — reserve/waitlist panel swap (the panels themselves are real forms) */
  const bk = closest("#bookSeg [data-book]");
  if (bk) { S.bookMode = bk.dataset.book; renderBookSheet(); return; }

  /* currency — persist via the store-scoped mbCurrency helper, not the shared
     "scoop_" localStorage key (which leaked across stores on one origin). */
  const cu = closest("#curSeg [data-cur]");
  if (cu) { S.cur = cu.dataset.cur; if (window.mbCurrency) mbCurrency.save(S.cur, (CURRENCIES[S.cur] || {}).rate || 1); applyLang(); return; }

  /* appearance — dark (default) / light / follow the OS */
  const ap = closest("#apSeg [data-ap]");
  if (ap) { setAppearance(ap.dataset.ap); return; }
});

/* note inputs (delegated, no re-render per keystroke) */
document.addEventListener("input", e => {
  const n = e.target.closest("[data-notekey]");
  if (n) {
    const line = S.cart.find(l => l.key === n.dataset.notekey);
    if (line) { line.note = n.value; store.set("cart", S.cart); }
  }
});

/* ── single-purpose buttons ───────────────────────────── */
function dismissSplash() {
  $("#splash")?.classList.add("is-done"); /* the intro is optional — still swap the phone mode below */
  /* Remember we've shown it: reloads and in-session revisits skip the intro
     (the inline pre-paint script + boot both read this). Per session, not forever,
     so a genuine new visit still gets the brand moment. */
  try { sessionStorage.setItem("scoop_splash_seen", "1"); } catch (e) {}
  const v = $(`.view[data-view="${active}"]`);
  $("#phone").dataset.mode = v?.dataset.mode || "dark"; /* dark splash → yellow home crossfade */
}
$("#splashSkip")?.addEventListener("click", dismissSplash);

/* Find my orders — guest history lookup by the phone/email used at checkout.
   Posts to the shared orderLookup endpoint; each result deep-links to that order's
   real tracking page. The block is server-gated to guests, so bail if it's absent. */
(function () {
  const btn = $("#lookupBtn"), inp = $("#lookupInput");
  if (!btn || !inp || !EP.orderLookup) return;
  const box = $("#lookupResults"), empty = $("#lookupEmpty");
  const escL = s => String(s == null ? "" : s).replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
  function showEmpty(msg) {
    if (box) box.innerHTML = "";
    if (!empty) return;
    empty.hidden = false;
    if (msg) empty.textContent = msg;
  }
  function run() {
    const val = inp.value.trim();
    if (!val) return;
    if (box) box.innerHTML = "";
    if (empty) empty.hidden = true;
    btn.disabled = true;
    fetch(EP.orderLookup, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf },
      body: JSON.stringify({ identifier: val }),
    })
      .then(r => r.json())
      .then(data => {
        if (!data || !data.success || !(data.orders || []).length) {
          showEmpty(data && data.message ? String(data.message) : t("orders_empty"));
          return;
        }
        box.innerHTML = data.orders.map(o => `
          <a class="order-row" href="${escL(o.track_url)}">
            ${ICONS.bag}
            <div><b>#${escL(o.id)} · ${escL(o.total)}</b><small>${escL(o.date)}</small></div>
            <span class="order-status">${escL(o.status)}</span>
          </a>`).join("");
      })
      .catch(() => showEmpty(t("orders_empty")))
      .finally(() => { btn.disabled = false; });
  }
  btn.addEventListener("click", run);
  inp.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); run(); } });
})();

/* Header globe: language sheet (rendered only for multi-language stores). */
$("#langBtn")?.addEventListener("click", () => { if ($("#langSheet")) sheet("langSheet"); });
$("#cvCateringBtn")?.addEventListener("click", () => { if ($("#cvCateringSheet")) sheet("cvCateringSheet"); });
/* ── SEARCH-RUN: orbit-style full-screen search overlay ─────────
   The popup is now full-screen and renders its own live result rows (tap → the
   item's product view via its ?item= / data-open). scoop has no top-level esc(),
   so use a local one. */
function spEsc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c])); }
function renderScoopSearch() {
  const box = $("#searchResults"), inp = $("#searchInput");
  if (!box || !inp) return;
  const q = inp.value.trim().toLowerCase();
  let pool = q
    ? ITEMS.filter(i => ((name(i) || "") + " " + (desc(i) || "")).toLowerCase().includes(q))
    : ITEMS.filter(i => i.popular).slice(0, 12);
  if (!q && !pool.length) pool = ITEMS.slice(0, 12);
  box.innerHTML = pool.length ? pool.map((i, ix) => {
    let sub = (desc(i) || "").replace(/\s+/g, " ").trim();
    if (sub.length > 48) sub = sub.slice(0, 47) + "…";
    return `<a class="sp-row" href="${itemHref(i.id)}" data-open="${i.id}" style="--d:${Math.min(ix * 30, 300)}ms">` +
      `<span class="sp-thumb">${imgTag(i.img)}</span>` +
      `<span class="sp-meta"><b>${spEsc(name(i))}</b>${sub ? `<small>${spEsc(sub)}</small>` : ""}</span>` +
      `<span class="sp-price">${fmtShort(i.price)}</span></a>`;
  }).join("") : `<div class="sp-none"><svg viewBox="0 0 64 64"><circle cx="28" cy="28" r="17"/><path d="m41 41 12 12"/></svg><p>${spEsc(box.getAttribute("data-none") || "")}</p></div>`;
}
function openScoopSearch() {
  const p = $("#searchPop"); if (!p) return;
  p.classList.add("is-open");
  renderScoopSearch();
  setTimeout(() => $("#searchInput")?.focus(), 60);
}
function closeScoopSearch() {
  const p = $("#searchPop"); if (!p) return;
  p.classList.remove("is-open");
  S.search = ""; const si = $("#searchInput"); if (si) si.value = "";
  renderGrid();
}
$("#searchBtn")?.addEventListener("click", () => {
  const p = $("#searchPop");
  if (p.classList.contains("is-open")) closeScoopSearch(); else openScoopSearch();
});
$("#searchClose")?.addEventListener("click", closeScoopSearch);
/* Tap a result → open the product via its ?item= href (global delegate); close
   the overlay first so the product view isn't behind it. */
$("#searchResults")?.addEventListener("click", e => {
  if (e.target.closest && e.target.closest("[data-open]")) closeScoopSearch();
});
document.addEventListener("keydown", e => {
  if (e.key === "Escape" && $("#searchPop")?.classList.contains("is-open")) closeScoopSearch();
});
/* Search box: readonly is lifted on focus (layout csp-focus="unlock") but was never
   re-applied, so after the first focus Chrome could autofill a saved name/email into
   it — wiping the grid to "no matches". Re-lock on blur (an unfocused field can't be
   autofilled) and ignore any input event that fires while the box isn't the active
   element (page-level autofill), restoring the box to the real query. */
$("#searchInput")?.addEventListener("input", e => {
  if (document.activeElement !== e.target) { e.target.value = S.search; return; }
  S.search = e.target.value; renderScoopSearch();
});
$("#searchInput")?.addEventListener("focusout", e => { e.target.setAttribute("readonly", ""); });
$("#pSearchBtn")?.addEventListener("click", () => {
  show("home", { push: false });
  openScoopSearch();
});

$("#gridBtn")?.addEventListener("click", () => { S.gridList = !S.gridList; renderGrid(); });
$("#filterBtn")?.addEventListener("click", () => { updateFilterCount(); renderSortList(); sheet("filterSheet"); });

/* Relocate the filter + layout toggles onto the shared order-type bar so the
   browse controls sit on one line with Delivery/Pickup/Dine-in instead of the
   header. #mbOrderType is server-rendered (hidden, top of <main>) and moved into
   place by mbAnchor, so moving the buttons in now means they ride along with no
   flash. If the bar stands down (a store with <2 order types removes it), the
   tools go back to the header rather than vanishing. moving a node keeps its
   listeners, so the handlers above stay wired. */
(function relocateBrowseTools(tries) {
  const fb = $("#filterBtn"), gb = $("#gridBtn"), head = $(".y-tools");
  if (!fb || !gb) return;
  /* Hidden (CSS) until this settles their final home, so they never flash in the
     header while we wait for the order bar to mount. */
  const reveal = () => { fb.classList.add("browse-tool--ready"); gb.classList.add("browse-tool--ready"); };
  const bar = document.getElementById("mbOrderType");
  if (!bar) {
    if (tries < 30) { setTimeout(() => relocateBrowseTools(tries + 1), 80); return; }
    reveal(); return; // no order bar (store with <2 order types) — keep them in the header
  }
  if (!bar.querySelector(".mbt-tools")) {
    const box = document.createElement("div");
    box.className = "mbt-tools";
    box.appendChild(fb); box.appendChild(gb);
    bar.appendChild(box);
  }
  reveal();
  setTimeout(() => {
    if (!document.getElementById("mbOrderType") && head && !head.contains(fb)) {
      head.appendChild(fb); head.appendChild(gb);
    }
  }, 3400);
})(0);
$("#filterDone")?.addEventListener("click", () => { sheet("filterSheet", false); renderGrid(); });
$("#filterReset")?.addEventListener("click", () => {
  S.filters.diets = new Set(); S.sort = "featured";
  $$("#dietRow button").forEach(b => b.classList.remove("is-on"));
  renderSortList(); updateFilterCount();
});
$("#clearAll")?.addEventListener("click", () => {
  S.filters.diets = new Set(); S.sort = "featured"; S.search = ""; S.cat = "all"; S.sub = "root";
  const si = $("#searchInput"); if (si) si.value = "";
  $$("#dietRow button").forEach(b => b.classList.remove("is-on"));
  renderHomeTabs(); renderGrid();
});
$("#promoCard")?.addEventListener("click", () => { const id = $("#promoCard").dataset.open; if (id && item(id)) openProduct(id); else show("cart"); });

$("#pFav")?.addEventListener("click", () => {
  const id = S.currentItem.id;
  const on = !S.favs.has(id);
  on ? S.favs.add(id) : S.favs.delete(id);
  store.set("favs", [...S.favs]);
  $("#pFav").classList.toggle("is-on", on);
  toast(t(on ? "fav_on" : "fav_off"));
});
$("#pShare")?.addEventListener("click", () => S.currentItem && shareItem(S.currentItem.id));
$("#addToCart")?.addEventListener("click", () => {
  if (!S.currentItem) return;
  /* #global-modifiers: a required group must be satisfied before the line exists —
     otherwise the choice is silently dropped from the order. */
  const miss = missingRequired(S.currentItem, S.pSel);
  if (miss) {
    toast(`${SCI.required || t("required")} · ${eh(name(miss))}`);
    const gEl = $(`#pGroups [data-grp="${miss.id}"]`);
    if (gEl) {
      gEl.classList.remove("needs");
      void gEl.offsetWidth; /* restart the flash on a repeat tap */
      gEl.classList.add("needs");
      gEl.scrollIntoView({ behavior: reduceMotion() ? "auto" : "smooth", block: "center" });
    }
    return;
  }
  addToCart(
    S.currentItem.id,
    { base: S.pSel.base, extras: new Set(S.pSel.extras), groups: JSON.parse(JSON.stringify(selGroups(S.pSel))) },
    S.pQty,
    $("#pImg"),
  );
});

$("#clearCart")?.addEventListener("click", () => { S.cart = []; store.set("cart", S.cart); syncBadge(false); renderCart(); });
$("#goCheckout")?.addEventListener("click", () => show("checkout"));
$("#placeOrder")?.addEventListener("click", placeOrder);
$("#backToMenu")?.addEventListener("click", () => show("home", { push: false }));
/* call the waiter — real endpoint (dine-in) */
$("#callWaiter")?.addEventListener("click", () => {
  if (!EP.waiter) { toast(t("waiter_ok")); return; }
  const tbl = ($("#tableSel") || {}).value || CFG.defaultTable || "";
  fetch(EP.waiter, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": CFG.csrf },
    body: JSON.stringify({ table: tbl, type: "call_waiter", _token: CFG.csrf }),
  }).then(() => toast(SCI.waiter_ok || t("waiter_ok"))).catch(() => toast(t("waiter_ok")));
});
/* WhatsApp — open the restaurant's real number when configured */
function openWhatsApp() {
  if (CFG.waNumber) window.open("https://wa.me/" + CFG.waNumber, "_blank");
  else toast(t("wa_stub"));
}
$("#waOrder")?.addEventListener("click", openWhatsApp);
$("#waUs")?.addEventListener("click", openWhatsApp);

/* an applied coupon must be removable — the demo offered no way back but retyping */
function syncCouponUi() {
  const field = $(".coupon-field"); if (!field) return;
  field.classList.toggle("is-applied", !!S.coupon);
  const clear = $("#couponClear"), apply = $("#couponApply"), input = $("#couponInput");
  if (clear) clear.hidden = !S.coupon;
  if (apply) apply.hidden = !!S.coupon;
  if (input) input.readOnly = !!S.coupon;
}
$("#couponClear")?.addEventListener("click", () => {
  S.coupon = null; S.couponPrefill = "";
  $("#couponInput").value = "";
  const msg = $("#couponMsg"); msg.hidden = true; msg.textContent = "";
  syncCouponUi(); renderTotals();
  if (active === "checkout") renderCheckout();
});
$("#couponInput")?.addEventListener("keydown", e => {
  if (e.key === "Enter") { e.preventDefault(); $("#couponApply")?.click(); }
});

/* coupon — validated server-side via restaurant.applyCoupon */
$("#couponApply")?.addEventListener("click", () => {
  const code = $("#couponInput").value.trim();
  const msg = $("#couponMsg");
  if (!code) return;
  if (!EP.coupon) { msg.hidden = false; msg.className = "coupon-msg err"; msg.textContent = `✕ ${t("coupon_bad")}`; return; }
  fetch(EP.coupon, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CFG.csrf },
    body: couponBody(code),
  }).then(r => r.json()).then(res => {
    msg.hidden = false;
    if (res && res.success) {
      S.coupon = { code: res.label || code, coupon_type: res.coupon_type || "percentage", percentage: res.percentage, discount: Number(res.discount) || 0, sig: couponSig() };
      msg.className = "coupon-msg";
      msg.textContent = `✓ ${res.message || t("coupon_ok")}`;
    } else {
      S.coupon = null;
      msg.className = "coupon-msg err";
      msg.textContent = `✕ ${(res && res.message) || t("coupon_bad")}`;
    }
    syncCouponUi();
    renderTotals();
    if (active === "checkout") renderCheckout();
  }).catch(() => {
    msg.hidden = false; msg.className = "coupon-msg err"; msg.textContent = `✕ ${t("coupon_bad")}`;
  });
});

/* ── customer auth — Login / Phone OTP / Register sheet (#authSheet) ──
   The endpoints (EP.login/register/otpRequest/otpVerify/logout) always shipped
   in the config; this wires the UI to them. On success the page reloads so the
   Blade side re-renders with auth('customer'). */
function authFail(res) {
  const e = $("#authErr");
  if (!e) return;
  let msg = (res && (res.message || res.error)) || t("coupon_bad");
  if (res && res.errors) { const k = Object.keys(res.errors)[0]; if (k && res.errors[k][0]) msg = res.errors[k][0]; }
  e.textContent = msg; e.hidden = false;
}
function authPost(url, body) {
  if (!url) return;
  const e = $("#authErr"); if (e) e.hidden = true;
  fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": CFG.csrf },
    body: JSON.stringify(Object.assign({ store_id: CFG.storeId, _token: CFG.csrf }, body)),
  }).then(r => r.json().then(res => ({ ok: r.ok, res })))
    .then(({ ok, res }) => { if (ok && res && res.success !== false) window.location.reload(); else authFail(res); })
    .catch(() => authFail(null));
}
$("#meAuthBtn")?.addEventListener("click", () => sheet("authSheet"));
$$("#authTabs button").forEach(b => b.addEventListener("click", () => {
  $$("#authTabs button").forEach(x => x.classList.toggle("is-on", x === b));
  $$(".auth-form[data-auth]").forEach(f => { f.hidden = f.dataset.auth !== b.dataset.authTab; });
  const e = $("#authErr"); if (e) e.hidden = true;
}));
$("#loginBtn")?.addEventListener("click", () => authPost(EP.login, { email: $("#loginEmail").value.trim(), password: $("#loginPass").value }));
$("#registerBtn")?.addEventListener("click", () => authPost(EP.register, {
  name: $("#regName").value.trim(), email: $("#regEmail").value.trim(), phone: $("#regPhone").value.trim(),
  password: $("#regPass").value, password_confirmation: $("#regPass2").value,
}));
$("#otpSend")?.addEventListener("click", () => {
  const phone = $("#otpPhone").value.trim();
  if (!phone || !EP.otpRequest) return;
  const e = $("#authErr"); if (e) e.hidden = true;
  fetch(EP.otpRequest, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": CFG.csrf },
    body: JSON.stringify({ store_id: CFG.storeId, phone, _token: CFG.csrf }),
  }).then(r => r.json()).then(res => {
    if (res && res.success) { $("#otpStep1").hidden = true; $("#otpStep2").hidden = false; $$("#otpBoxes input")[0]?.focus(); }
    else authFail(res);
  }).catch(() => authFail(null));
});
$("#otpVerify")?.addEventListener("click", () => authPost(EP.otpVerify, {
  phone: $("#otpPhone").value.trim(),
  code: $$("#otpBoxes input").map(i => i.value).join(""),
}));
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
$("#logoutBtn")?.addEventListener("click", () => {
  if (!EP.logout) return;
  fetch(EP.logout, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": CFG.csrf },
    body: JSON.stringify({ _token: CFG.csrf }),
  }).then(() => window.location.reload());
});

/* ── F2 · follow / verified brand (crave parity) ─────────
   Bearer-guarded follow endpoint: POST to follow, DELETE to unfollow. A guest
   (no CFG.customerToken) is sent to the same sign-in sheet as everything else. */
(function () {
  const btn = $("#cvFollowBtn");
  if (!btn || !EP.follow) return;
  function followHeaders() {
    const h = { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": CFG.csrf };
    if (CFG.customerToken) h["Authorization"] = "Bearer " + CFG.customerToken;
    return h;
  }
  function applyFollowState(d) {
    if (!d || typeof d.following === "undefined") return;
    btn.dataset.following = d.following ? "1" : "0";
    btn.classList.toggle("is-on", !!d.following);
    const c = $("#cvFollowerCount");
    if (c && typeof d.follower_count !== "undefined") c.textContent = d.follower_count;
  }
  btn.addEventListener("click", () => {
    if (!CFG.customerToken) { sheet("authSheet"); return; }
    const unfollow = btn.dataset.following === "1";
    btn.disabled = true;
    fetch(EP.follow, { method: unfollow ? "DELETE" : "POST", headers: followHeaders(), body: "{}" })
      .then(r => r.json()).then(applyFollowState)
      .catch(() => {})
      .finally(() => { btn.disabled = false; });
  });
})();

$("#redeemBtn")?.addEventListener("click", () => sheet("redeemSheet"));
$("#bookRow")?.addEventListener("click", () => { renderBookSheet(); sheet("bookSheet"); });
/* the owner may land back here after a successful reservation / waitlist POST */
if (CFG.openResSheet || CFG.openWaitlistSheet) {
  S.bookMode = CFG.openWaitlistSheet ? "waitlist" : "reserve";
  setTimeout(() => { renderBookSheet(); sheet("bookSheet"); }, 900);
}

/* Appearance — dark stays the default face for every store; light is a real designed
   palette, and "system" is opt-in (flipping thousands of live storefronts because the
   diner's OS asked would change the product the owner bought). */
function setAppearance(mode) {
  S.appearance = mode;
  store.set("appearance", mode);
  document.getElementById("scoop")?.setAttribute("data-appearance", mode);
  $$("#apSeg [data-ap]").forEach(b => b.classList.toggle("is-on", b.dataset.ap === mode));
}

function updateFilterCount() { $("#filterCount").textContent = visibleItems().length; }

const SORTS = ["featured", "price_asc", "price_desc", "rating"];
function renderSortList() {
  $("#sortList").innerHTML = SORTS.map(s => `
    <button class="rrow ${S.sort === s ? "is-on" : ""}" data-sort="${s}">
      <b>${t("s_" + s)}</b><span class="rrow-check"></span>
    </button>`).join("");
}

/* keep underline inks honest on resize / font swap / RTL flip, and re-lay the grid
   when the viewport crosses a column breakpoint (rotation, desktop resize) */
let gridColsNow = null;
window.addEventListener("resize", () => {
  setInk($("#homeTabs")); setInk($("#pTabs"));
  const n = gridCols();
  if (n !== gridColsNow) { gridColsNow = n; renderGrid(); if (active === "favs") renderFavs(); }
});
if (document.fonts?.ready) document.fonts.ready.then(() => { setInk($("#homeTabs")); setInk($("#pTabs")); });

/* render the currency segment from real currencies (hide when only one) */
function renderCurSeg() {
  const seg = $("#curSeg"); if (!seg) return;
  const codes = Object.keys(CURRENCIES);
  const row = seg.closest(".pref-row");
  if (codes.length <= 1) { if (row) row.hidden = true; return; }
  seg.innerHTML = codes.map(c => `<button data-cur="${c}" class="${c === S.cur ? "is-on" : ""}">${c}</button>`).join("");
}

/* gate the UI to what the restaurant actually enables */
function applyConfigGates() {
  /* order types */
  $$("#orderTypeSeg [data-ot]").forEach(b => {
    const on = OT_ALLOWED[b.dataset.ot];
    b.hidden = !on;
    b.classList.toggle("is-on", b.dataset.ot === S.orderType);
  });
  /* if nothing is enabled server-side, hide ordering affordances */
  if (!CFG.allowOrder) {
    ["#cartCta", "#waOrder"].forEach(s => { const el = $(s); if (el) el.hidden = true; });
  }
  /* dine-in call-waiter only when allowed */
  if (!CFG.allowCallWaiter) { const w = $("#waiterRow"); if (w) w.remove(); }
  /* whatsapp chat row */
  if (!CFG.waNumber) { const w = $("#waUs"); if (w) w.hidden = true; }
  /* reservations / waitlist row */
  if (!CFG.reservationsEnabled && !CFG.waitlistEnabled) { const b = $("#bookRow"); if (b) b.hidden = true; }
  /* loyalty redeem — only meaningful when a program exists */
  if (!CFG.loyaltyType) { const r = $("#redeemBtn"); if (r) r.hidden = true; }
  /* force a table when scanned via a table QR */
  /* guarded on OT_ALLOWED.dinein: a hotel room QR also sets forceTable, and forcing a
     disabled/hidden dine-in button there would silently submit the order as on-table.
     Also skipped when room service is offered — DEFAULT_OT already picked "roomservice"
     for this room guest and this must not stomp it back to dine-in. */
  if (CFG.forceTable && OT_ALLOWED.dinein && !OT_ALLOWED.roomservice) {
    S.orderType = "dinein";
    $$("#orderTypeSeg [data-ot]").forEach(b => b.classList.toggle("is-on", b.dataset.ot === "dinein"));
  }
  /* prefill a signed-in diner's name */
  if (CFG.customerName) { const n = $("#custName"); if (n && !n.value) n.value = CFG.customerName; }
}

/* ── nearest-branch map (self-hosted Leaflet) ─────────── */
(function () {
  const openBtn = $("#nearestBranchBtn");
  /* guard: no button, no endpoint, or Leaflet failed to load → hide + bail */
  if (!openBtn || !EP.branchesGeo || typeof L === "undefined") {
    if (openBtn) openBtn.style.display = "none";
    return;
  }
  const escHtml = s => String(s == null ? "" : s).replace(/[&<>"]/g, c =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
  let map = null, markers = [];
  const icon = L.icon({
    iconUrl: CFG.leafletImgPath + "/marker-icon.png",
    iconRetinaUrl: CFG.leafletImgPath + "/marker-icon-2x.png",
    shadowUrl: CFG.leafletImgPath + "/marker-shadow.png",
    iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41],
  });
  function selectBranch(id) {
    const url = new URL(window.location.href);
    url.searchParams.set("branch_id", id);
    window.location.href = url.toString();
  }
  function renderList(branches) {
    const el = $("#branchGeoList");
    if (!el) return;
    el.innerHTML = branches.map(b => {
      const dist = b.distance_km != null ? `<span class="branch-dist" dir="ltr">${b.distance_km.toFixed(1)} ${t("km_away")}</span>` : "";
      return `<button class="branch-row" data-bid="${b.id}" type="button">
        <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
        <b>${escHtml(b.name)}${b.address ? `<small>${escHtml(b.address)}</small>` : ""}</b>${dist}
        <span class="branch-check"></span></button>`;
    }).join("") || `<p class="orders-empty">${t("no_branches")}</p>`;
  }
  function drawMap(branches) {
    const withCoords = branches.filter(b => b.latitude != null && b.longitude != null);
    if (!map) {
      map = L.map("branchMap", { attributionControl: false });
      L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19 }).addTo(map);
      L.control.attribution({ prefix: false }).addAttribution("© OpenStreetMap").addTo(map);
    }
    markers.forEach(m => map.removeLayer(m)); markers = [];
    if (!withCoords.length) { map.setView([24.7136, 46.6753], 11); return; }
    const group = [];
    withCoords.forEach(b => {
      const mk = L.marker([b.latitude, b.longitude], { icon }).addTo(map);
      mk.bindPopup(`<b>${escHtml(b.name)}</b>`);
      mk.on("click", () => selectBranch(b.id));
      markers.push(mk); group.push([b.latitude, b.longitude]);
    });
    map.fitBounds(group, { padding: [40, 40], maxZoom: 14 });
  }
  function load(lat, lng) {
    const url = EP.branchesGeo + (lat != null ? `?lat=${lat}&lng=${lng}` : "");
    fetch(url).then(r => r.json()).then(d => {
      const branches = (d && d.branches) || [];
      const attrib = $("#branchMapAttrib");
      if (attrib) attrib.textContent = (d && d.attribution) || "© OpenStreetMap contributors";
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
  $("#branchGeoList")?.addEventListener("click", e => {
    const b = e.target.closest("[data-bid]");
    if (b) selectBranch(b.dataset.bid);
  });
})();

/* ── stories (rail + full-screen viewer) ──────────────── */
(function () {
  const rail = $("#storyRail");
  /* guard: script.js is loaded on every theme page — no rail / no endpoint → bail */
  if (!rail || !EP.stories) return;

  const view = $("#storyViewer");
  const media = $("#svMedia"), cap = $("#svCaption"), cta = $("#svCta"), prog = $("#svProgress");
  if (!view) return;

  const DUR = 5;                       // seconds per story
  let stories = [], idx = 0, timer = null, elapsed = 0;

  (window.mbStoriesGet?window.mbStoriesGet(EP.stories):fetch(EP.stories).then(function(r){return r.json();})).then(d => {
    stories = (d && d.data) || [];
    if (!stories.length) { rail.hidden = true; return; }
    rail.hidden = false;
    rail.innerHTML = stories.map((s, i) => {
      const label = s.caption ? String(s.caption).slice(0, 14) : (SCI.stories || "Stories");
      return `<button class="story" data-si="${i}" type="button">
        <span class="story-ring">${imgTag(s.media_url)}</span>
        <small>${escStory(label)}</small></button>`;
    }).join("");
  }).catch(() => { rail.hidden = true; });

  function escStory(s) {
    return String(s == null ? "" : s).replace(/[&<>"]/g, c =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
  }
  const buildProgress = () => {
    prog.innerHTML = stories.map(() => '<span class="sv-seg"><i></i></span>').join("");
  };
  const paintSeg = () => {
    [...prog.querySelectorAll(".sv-seg i")].forEach((bar, i) => {
      bar.style.width = i < idx ? "100%" : (i === idx ? `${elapsed / DUR * 100}%` : "0%");
    });
  };
  function render() {
    const s = stories[idx];
    if (!s) return;
    scoopImgReset(media);
    media.src = IMG(s.media_url) || BLANK_PX;
    cap.textContent = s.caption || "";
    if (s.cta_url && !(window.mbStoryCta && window.mbStoryCta.isCurrentPage(s.cta_url))) {
      cta.hidden = false;
      cta.href = s.cta_url;
      if (window.mbStoryCta) window.mbStoryCta.apply(cta, s.cta_url);
      cta.querySelector("span").textContent = s.cta_label || SCI.order_now || "Order now";
    } else { cta.hidden = true; }
    elapsed = 0; paintSeg();
  }
  function tick() { elapsed += 0.05; if (elapsed >= DUR) { next(); return; } paintSeg(); }
  const stop = () => { if (timer) { clearInterval(timer); timer = null; } };
  const play = () => { stop(); timer = setInterval(tick, 50); };
  function open(i) {
    idx = i; buildProgress();
    view.hidden = false;
    void view.offsetWidth;
    view.classList.add("is-open");
    render(); play();
  }
  function close() {
    stop();
    view.classList.remove("is-open");
    setTimeout(() => { view.hidden = true; media.src = BLANK_PX; }, 320);
  }
  function next() { if (idx < stories.length - 1) { idx++; render(); } else { close(); } }
  function prev() { if (idx > 0) { idx--; render(); } else { elapsed = 0; } }

  rail.addEventListener("click", e => {
    const b = e.target.closest("[data-si]");
    if (b) open(Number(b.dataset.si));
  });
  $("#svClose")?.addEventListener("click", close);
  $("#svNext")?.addEventListener("click", next);
  $("#svPrev")?.addEventListener("click", prev);
  /* The CTA is a same-page ?item= link that the R7 document delegate opens IN PLACE
     (no reload). Without closing here, the product view opened UNDER the full-screen
     story overlay and the CTA looked dead. Fires in the target phase, before that
     delegate, so the viewer fades out as the product comes up. */
  cta?.addEventListener("click", close);
  const stage = $("#svStage");
  if (stage) {
    /* press-and-hold pauses, exactly like the native story UX */
    stage.addEventListener("pointerdown", stop);
    stage.addEventListener("pointerup", () => { if (!view.hidden && !timer) play(); });
  }
})();

/* The appearance choice belongs to the WHOLE storefront, so it is applied before the
   menu-SPA guard below — otherwise the account and tracking pages (same shell, no
   #homeTabs) stayed dark while the menu was light. */
setAppearance(S.appearance);

/* ── boot ─────────────────────────────────────────────── */
/* ── delivery address autocomplete + use-my-location + saved addresses ──
   geo.autocomplete / geo.reverse (GeoController). Response shapes:
   autocomplete → {results:[{label,lat,lng}], attribution}
   reverse      → {result:{label,lat,lng}, attribution} */
function wireAddressField(input, box, locBtn, attrib, onValue) {
  /* Shared by the checkout field (#custAddr, wired once below) and the cart's
     delivery field (#cartAddr, re-wired by renderOtFields each time it is
     rebuilt). Coordinates always land in the checkout's hidden #custLat/#custLng
     pair — the order payload reads only those. */
  if (!input) return;
  const escAttr = s => String(s == null ? "" : s).replace(/[&<>"]/g, c =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]));
  let timer = null, lastAttrib = "";
  const setAttrib = a => { if (a && attrib && a !== lastAttrib) { attrib.textContent = a; attrib.hidden = false; lastAttrib = a; } };
  const hideBox = () => { if (box) { box.hidden = true; box.innerHTML = ""; } };
  const setCoords = (lat, lng) => { const la = $("#custLat"), ln = $("#custLng"); if (la) la.value = lat || ""; if (ln) ln.value = lng || ""; };
  const sync = () => { if (onValue) onValue(input.value); };

  if (EP.geoAutocomplete) {
    input.addEventListener("input", () => {
      const q = input.value.trim();
      sync();
      setCoords("", "");                 /* typing invalidates a picked pin */
      if (timer) clearTimeout(timer);
      if (q.length < 3) { hideBox(); return; }
      timer = setTimeout(() => {
        fetch(EP.geoAutocomplete + "?q=" + encodeURIComponent(q))
          .then(r => r.json())
          .then(d => {
            setAttrib(d && d.attribution);
            const res = (d && d.results) || [];
            if (!res.length || !box) { hideBox(); return; }
            box.innerHTML = res.map(r =>
              `<button class="addr-opt" data-lat="${r.lat || ""}" data-lng="${r.lng || ""}" data-label="${escAttr(r.label)}" type="button">${escAttr(r.label)}</button>`
            ).join("");
            box.hidden = false;
          }).catch(hideBox);
      }, 350);
    });
    if (box) box.addEventListener("click", e => {
      const opt = e.target.closest(".addr-opt"); if (!opt) return;
      input.value = opt.dataset.label || ""; sync();
      setCoords(opt.dataset.lat, opt.dataset.lng);
      hideBox();
    });
    input.addEventListener("blur", () => setTimeout(hideBox, 200));
  }

  locBtn?.addEventListener("click", () => {
    if (!navigator.geolocation) { toast(t("loc_unavailable")); return; }
    locBtn.classList.add("is-loading");
    navigator.geolocation.getCurrentPosition(pos => {
      const lat = pos.coords.latitude, lng = pos.coords.longitude;
      fetch((EP.geoReverse || "") + "?lat=" + lat + "&lng=" + lng)
        .then(r => r.json())
        .then(d => {
          locBtn.classList.remove("is-loading");
          setAttrib(d && d.attribution);
          if (d && d.result) { input.value = d.result.label || ""; sync(); setCoords(d.result.lat || lat, d.result.lng || lng); }
          else { toast(t("no_address")); }
        }).catch(() => locBtn.classList.remove("is-loading"));
    }, () => { locBtn.classList.remove("is-loading"); toast(t("loc_denied")); }, { enableHighAccuracy: true, timeout: 8000 });
  });
}
(function () {
  const input = $("#custAddr");
  if (!input) return;
  wireAddressField(input, $("#custAddrSuggest"), $("#custUseLocation"), $("#custGeoAttrib"), null);
  const setCoords = (lat, lng) => { const la = $("#custLat"), ln = $("#custLng"); if (la) la.value = lat || ""; if (ln) ln.value = lng || ""; };
  /* logged-in server address book AND the R6 guest chips both use .saved-addr
     inside their own container — one handler shape, bound to whichever exists. */
  const onChipClick = e => {
    const b = e.target.closest(".saved-addr"); if (!b) return;
    input.value = b.dataset.addr || "";
    setCoords(b.dataset.lat, b.dataset.lng);
    $$(".saved-addr").forEach(x => x.classList.remove("is-on"));
    b.classList.add("is-on");
  };
  $("#savedAddrs")?.addEventListener("click", onChipClick);
  $("#guestAddrs")?.addEventListener("click", onChipClick);
  renderRecentAddrs();   /* R6: paint guest chips at load if any are stored */
})();

function boot() {
  /* script.js is auto-loaded on every theme page (incl. account/track);
     only run the menu SPA where its markup + config actually exist. #views is
     the sentinel (not #homeTabs — the catbar section can be customizer-hidden) */
  if (!window.SCOOP || !document.getElementById("views")) return;
  renderCurSeg();
  applyConfigGates();
  applyLang();
  if (window.mbCurrency) mbCurrency.restore("#curSeg");   // replay store-scoped saved currency (shared helper) after seg is built + first render
  syncBadge(false);
  syncFavBadge();
  loadLoyalty();
  /* no intro to sit through when the splash was gated out (or already seen this
     session) — flip the phone mode at once instead of leaving it dark for 2.4s */
  let splashSeen = false;
  try { splashSeen = sessionStorage.getItem("scoop_splash_seen") === "1"; } catch (e) {}
  /* A1: a ?item=/?table= deep link opens straight on the product — dismiss the
     splash at once (delay 0) instead of sitting behind the 2.4s intro. */
  let splashDeepLinked = false;
  try { const dl = new URLSearchParams(location.search); splashDeepLinked = !!(dl.get("item") || dl.get("table")); } catch (e) {}
  setTimeout(dismissSplash, ($("#splash") && !splashSeen && !splashDeepLinked) ? 2400 : 0);

  /* R7 · open a shared / deep-linked product (?item=<id>) in place at boot, so a
     shared link lands on the item instead of the bare home view. */
  try {
    const deepItem = new URLSearchParams(location.search).get("item");
    if (deepItem && item(deepItem)) openProduct(deepItem);
  } catch (e) {}
}
boot();

/* explicit corner-X close on sheets (scoop closes via the scrim, not a grab bar). */
document.querySelectorAll(".sheet-x").forEach(x => x.addEventListener("click", () => { if (openSheet) sheet(openSheet, false); }));

/* Collapsing header — condensed bar in once the arc cover leaves the home scroll
   container. scoop's category chips are not top-sticky, so no overlap guard.
   Condensed buttons forward their click to the real top-chrome controls. */
(function mountCondensedHeader() {
  const homeView = document.querySelector('.view[data-view="home"]');
  if (!homeView) return;
  const anchor = homeView.querySelector(".scoop-cover") || homeView.querySelector(".y-head");
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
