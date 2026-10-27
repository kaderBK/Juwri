// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * A1/A2 — Ordering engine (platform E2E, lane A).
 *
 * Drives a full customer order end-to-end on a `{template}-demo` storefront, in
 * Arabic, at 390×844: open home → assert RTL + the template root class → the
 * categories nav sits above the grid → search a real fixture item → open an item
 * with REQUIRED modifier groups and satisfy them → add to cart → change qty →
 * open cart → apply the `P1TEST10` coupon → exercise every order type the theme
 * exposes (delivery address, pickup phone, dine-in table) → checkout with
 * cash/COD → success → **db()-assert the new order row** (store id, status,
 * grand_total = subtotal − discount + tax with tax PRE-discount, matching the
 * theme's displayed total, `locale='ar'`) → open the track page (status text,
 * RTL) → leave a review if the store exposes one. Finishes by asserting no
 * console errors / no ENFORCED CSP refusals.
 *
 * Parametrised by template via `E2E_TEMPLATES` (default = the 17 modern kept
 * templates). The per-template CONTRACT map names the selectors the journey needs
 * on top of the shared cart/checkout flow, plus a `family` discriminator:
 *   - `cv`    → crave / matcha / glow: the crave "cv" SPA (data-view states, the
 *              `#cv…` id scheme, a `.pm[data-pm]` tender, `window.<GLOBAL>.items`).
 *   - `sheet` → aurora / vivid: the `au-`/`vd-` bottom-sheet engine (order form in
 *              the cart sheet, `[data-type]` order types, a `<select name=pay_via>`
 *              tender, an AJAX submit that shows a success panel or redirects to
 *              track).
 * A1 shipped the crave contract; A2 adds matcha, glow, aurora, vivid. A template
 * without a contract is a hard failure so the batch item knows it still owes one.
 * The journey reports per-template PASS/FAIL and throws only at the end, like
 * `retained-template-csp.js`.
 *
 * Run: `E2E_TEMPLATES=matcha,glow,aurora,vivid bash rev/platform-e2e/bin/e2e.sh a a-order`.
 */

const {
  cfg: pcfg, localeUrl, assertRtl, assertNoOverflow,
  collectConsole, assertNoConsoleErrors, db, normalizeDigits,
} = require('../../lib/platform');
const RETAINED = require('../../lib/retained');
const { resetRateLimits } = require('../../lib/platform');

// ── per-template contracts ───────────────────────────────────────────────────

// The crave "cv" family (crave/matcha/glow): the same SPA with per-fork global
// name and search shape. crave uses an overlay search (`#cvSearchOpen` →
// `#cvSearchOverlayInput` → `#cvSearchResults`); matcha/glow filter the grid in
// place through an inline `#cvSearchInput`. Everything else is shared cv markup.
const CV_SHARED = {
  family: 'cv',
  catNav: '#cvChips',
  grid: '#cvGrid',
  openAttr: 'data-open',
  productView: '[data-view="product"]',
  add: '#addToCart',
  cartBadge: '#cvCartBadge',
  cartQtyPlus: '#cvCartList [data-cq="1"]',
  cartQtyOut: '#cvCartList .cart-item .qty output',
  orderTypeSeg: '#cvOrderTypeSeg',
  otAttr: 'data-ot',
  otDelivery: '#cvOrderTypeSeg [data-ot="delivery"]',
  otTakeaway: '#cvOrderTypeSeg [data-ot="takeaway"]',
  otDineIn: '#cvOrderTypeSeg [data-ot="on-table"]',
  couponInput: '#cvCouponInput',
  couponApply: '#cvCouponApply',
  couponMsg: '#cvCouponMsg',
  discountRow: '#cvTotalsBox .tr-row.disc',
  goCheckout: '#cvGoCheckout',
  checkoutView: '[data-view="checkout"]',
  name: '#cvCustName',
  phone: '#cvCustPhone',
  addr: '#cvCustAddr',
  table: '#cvTableInput',
  addrBlock: '#cvAddrBlock',
  phoneBlock: '#cvPhoneField',
  tableBlock: '#cvTableField',
  payCash: '.pm[data-pm="pay_on_counter"]',
  checkoutGrand: '#cvCheckoutTotals .tr-row.grand b',
  placeOrder: '#cvPlaceOrder',
  confirmView: '[data-view="confirmed"]',
  confirmTrack: '#cvConfirmTrack',
  confirmOrderNo: '#cvConfirmOrderNo',
  rateStars: '#cvRateStars',
  rateOne: '#cvRateStars [data-rate="4"]',
  rateThanks: '#cvRateThanks',
  trackStatus: '#cvStatusWord',
  trackTotal: '.order-line b',
};

// The `au-`/`vd-` bottom-sheet family (aurora/vivid): the order form lives inside
// the cart sheet (revealed once the cart is non-empty), order types are
// `[data-type]` buttons, the tender is a `<select name=pay_via>` (cash =
// `pay_on_counter`), and placing the order shows a success panel or redirects.
const SHEET_SHARED = {
  family: 'sheet',
  openAttr: 'data-open-item',
  otAttr: 'data-type',
  otDeliveryType: 'delivery',
  otTakeawayType: 'takeaway',
  otDineInType: 'on-table',
  rateStars: '#___no_review_control___', // aurora/vivid review is a sheet, not exercised here (parity with crave A1 skip)
};

const CONTRACTS = {
  crave: Object.assign({}, CV_SHARED, {
    globalName: 'CRAVE',
    rootSel: '#crave.crave',
    searchOpen: '#cvSearchOpen',
    searchInput: '#cvSearchOverlayInput',
    searchResult: '#cvSearchResults [data-open]',
    searchClose: '#cvSearchClose',
    searchSeed: '#cvGrid [data-open]',
    cartTab: '[data-tab="cart"]',
  }),
  matcha: Object.assign({}, CV_SHARED, {
    globalName: 'MATCHA',
    rootSel: '#matcha.matcha',
    searchOpen: null,                       // inline grid search — no overlay
    searchInput: '#cvSearchInput',
    searchResult: '#cvGrid [data-open]',
    searchClose: null,
    searchSeed: '#cvGrid [data-open]',
    cartTab: '[data-go="cart"]',            // matcha routes tab-bar via data-go
  }),
  glow: Object.assign({}, CV_SHARED, {
    globalName: 'GLOW',
    rootSel: '#glow.glow',
    searchOpen: null,
    searchInput: '#cvSearchInput',
    searchResult: '#cvGrid [data-open]',
    searchClose: null,
    searchSeed: '#cvGrid [data-open]',
    cartTab: '[data-tab="cart"]',
  }),
  aurora: Object.assign({}, SHEET_SHARED, {
    globalName: 'AURORA',
    rootSel: '#aurora.aurora',
    catNav: '#au-catbar',
    grid: '#au-menu-root',
    searchInput: '#au-search-input',
    searchResult: '#au-menu-root [data-item-card]',
    searchSeed: '#au-menu-root [data-item-card][data-name]',
    searchClear: '#au-search-clear',
    productView: '#au-item-sheet',
    itemBody: '#au-item-body',
    variantSel: '[data-variant]',
    add: '#au-add-to-cart',
    cartBadge: '#au-cart-count',
    cartTab: '#au-cart-toggle',
    cartList: '#au-cart-list',
    cartQtyPlus: '#au-cart-list [data-cart-qty] [data-q="1"]',
    cartQtyOut: '#au-cart-list [data-cart-qty] span',
    orderForm: '#au-order-form',
    orderTypeSeg: '#au-ordertype-seg',
    couponInput: '#au-coupon-code',
    couponApply: '#au-apply-coupon',
    couponMsg: '#au-coupon-msg',
    couponOkToken: 'success',
    couponErrToken: 'error',
    totalsBox: '#au-totals',
    discountSel: '#au-totals',              // the discount row carries the code text
    addrBlock: '#au-order-form [data-field="address"]',
    phoneBlock: '#au-order-form [data-field="phone"]',
    tableBlock: '#au-order-form [data-field="table"]',
    paySelect: '#au-payvia',
    checkoutGrand: '#au-checkout-total',
    placeOrder: '#au-checkout-btn',
    confirmView: '#au-order-success',
    trackStatus: '#status-title',
  }),
  vivid: Object.assign({}, SHEET_SHARED, {
    globalName: 'VIVID',
    rootSel: '#vd.vd',
    catNav: '#vd-catnav',
    grid: '#vd-menu-root',
    searchInput: '#vd-search-input',
    searchResult: '#vd-menu-root [data-item-card]',
    searchSeed: '#vd-menu-root [data-item-card][data-name]',
    searchClear: '#vd-search-clear',
    productView: '#vd-item-sheet',
    itemBody: '#vd-item-body',
    variantSel: 'input[name="vd-detail-variant"]',
    add: '#vd-detail-add',
    cartBadge: '#vd-cart-count',
    cartTab: '#vd-cart-toggle',
    cartList: '#vd-cart-list',
    cartQtyPlus: '#vd-cart-list [data-cart-inc]',
    cartQtyOut: '#vd-cart-list .vd-cart-qty span',
    orderForm: '#vd-order-form',
    orderTypeSeg: '#vd-ordertype-seg',
    couponInput: '#vd-coupon-code',
    couponApply: '#vd-apply-coupon',
    couponMsg: '#vd-coupon-msg',
    couponOkToken: 'coupon-ok',
    couponErrToken: 'coupon-err',
    totalsBox: '#vd-totals',
    discountSel: '#vd-totals .vd-total-discount',
    addrBlock: '#vd-order-form [data-field="address"]',
    phoneBlock: '#vd-order-form [data-field="phone"]',
    tableBlock: '#vd-order-form [data-field="table"]',
    paySelect: '#vd-payvia',
    checkoutGrand: '#vd-checkout-total',
    placeOrder: '#vd-checkout-btn',
    confirmView: '#vd-order-success',       // vivid redirects to track; success panel may not show
    trackStatus: '#vdStatusWord',
  }),

  // ── feast / velvet: the `.ff-`/`.v-` form-checkout engine (A3) ─────────────
  // A single-page storefront whose checkout is a form INSIDE the cart drawer.
  // An item opens a detail drawer (variants = `input[name=…-variant-choice]`
  // radios, required option groups = `[data-group][data-opt]`); the floating
  // `…-cart-toggle` opens the cart; the order type is a `.…-tab-btn[data-type]`
  // segment that display-toggles the `#…-group-{table,phone,address}` field
  // blocks; cash is a `pay_via=pay_on_counter` radio; a successful submit reveals
  // the `#…-success-drawer` (no auto-redirect — the journey opens track itself).
  // feast search is an OVERLAY (`#ff-search-open` → `#ff-sl-input` →
  // `#ff-sl-results [data-open-item]`); velvet filters the grid in place through
  // `#v-search-input`. The track page reuses velvet's `#vStatusWord`.
  feast: {
    family: 'ffform',
    globalName: 'FEAST',
    rootSel: '#feast-app',
    catNav: '#ff-cats',
    grid: '.ff-menu-grid',
    cardClass: '.ff-menu-card',
    openAttr: 'data-id',
    searchOpen: '#ff-search-open',
    searchInput: '#ff-sl-input',
    searchResult: '#ff-sl-results [data-open-item]',
    searchSeed: '.ff-menu-card[data-id]',
    searchClose: '#ff-sl-close',
    searchClear: null,
    productView: '#ff-detail-drawer',
    detailContent: '#ff-detail-content',
    add: '#ff-detail-add-btn',
    cartBadge: '.ff-cart-badge',
    cartToggle: '#ff-cart-toggle',
    checkoutForm: '#ff-checkout-form',
    cartQtyPlus: '.ff-cart-item-qty-btn[data-csp-delta="1"]',
    cartQtyOut: '.ff-cart-item-qty span',
    couponInput: '#ff-coupon-code',
    couponApply: '#ff-coupon-apply-btn',
    couponMsg: '#ff-coupon-msg',
    couponOkClass: 'ff-coupon-success',
    couponErrClass: 'ff-coupon-error',
    discountRow: '#ff-discount-row',
    orderTabSel: '.ff-order-type-tabs .ff-tab-btn',
    otAttr: 'data-type',
    addrBlock: '#ff-group-address',
    phoneBlock: '#ff-group-phone',
    tableBlock: '#ff-group-table',
    name: '#ff-cust-name',
    phone: '#ff-phone-num',
    addr: '#ff-address',
    table: '#ff-table-num',
    payRadio: 'input[name="pay_via"][value="pay_on_counter"]',
    placeOrder: '#ff-submit-order-btn',
    checkoutGrand: '#ff-sum-total',
    confirmView: '#ff-success-drawer',
    trackStatus: '#vStatusWord',
    rateStars: '#___ffform_no_review___',   // feast/velvet item-rating opens a write-sheet, not exercised here
  },
  velvet: {
    family: 'ffform',
    globalName: 'VELVET',
    rootSel: '#velvet-app',
    catNav: '#v-cats',
    grid: '.v-menu-grid',
    cardClass: '.v-menu-card',
    openAttr: 'data-id',
    searchOpen: null,                        // velvet: inline grid-filter search
    searchInput: '#v-search-input',
    searchResult: '.v-menu-card[data-id]',
    searchSeed: '.v-menu-card[data-id]',
    searchClose: null,
    searchClear: '#v-search-clear',
    productView: '#v-detail-drawer',
    detailContent: '#v-detail-content',
    add: '#v-detail-add-btn',
    cartBadge: '.v-cart-badge',
    cartToggle: '#v-cart-toggle',
    checkoutForm: '#v-checkout-form',
    cartQtyPlus: '.v-cart-item-qty-btn[data-csp-delta="1"]',
    cartQtyOut: '.v-cart-item-qty span',
    couponInput: '#v-coupon-code',
    couponApply: '#v-coupon-apply-btn',
    couponMsg: '#v-coupon-msg',
    couponOkClass: 'v-coupon-success',
    couponErrClass: 'v-coupon-error',
    discountRow: '#v-discount-row',
    orderTabSel: '.v-order-type-tabs .v-tab-btn',
    otAttr: 'data-type',
    addrBlock: '#v-group-address',
    phoneBlock: '#v-group-phone',
    tableBlock: '#v-group-table',
    name: '#v-cust-name',
    phone: '#v-phone-num',
    addr: '#v-address',
    table: '#v-table-num',
    payRadio: 'input[name="pay_via"][value="pay_on_counter"]',
    placeOrder: '#v-submit-order-btn',
    checkoutGrand: '#v-sum-total',
    confirmView: '#v-success-drawer',
    trackStatus: '#vStatusWord',
    rateStars: '#___ffform_no_review___',
  },

  // ── dough / plate: the `data-view` single-page ("cv"-style) engine (A3) ────
  // A SPA whose home/item/cart/checkout/confirm are `.view[data-view]` states.
  // The grid + search open the item view (`[data-open]` / `[data-sopen]`);
  // required option groups are single-select `[data-mod]` chips; the coupon and
  // the `#otSeg [data-ot]` order-type segment live in the CART view; the contact
  // fields live in the CHECKOUT view; cash is the default-selected tender.
  // dough shows a `#otpDone` confirmation panel (no redirect); plate shows a
  // `[data-view="confirmed"]` panel then auto-redirects to the track page. Both
  // track pages reuse crave's `#cvStatusWord`.
  dough: {
    family: 'spa',
    globalName: 'DOUGH',
    rootSel: '#dough',
    catNav: '#cats',
    grid: '#grid',
    openAttr: 'data-open',
    searchOpen: '#searchBtn',
    searchInput: '#soInput',
    searchResult: '#soResults [data-open]',
    searchSeed: '#grid [data-open]',
    searchClose: '#soClose',
    searchClear: null,
    productView: '[data-view="item"]',
    add: '#iAdd',
    cartBadge: '#bagDot',
    cartTab: '#bagBtn',
    cartView: '[data-view="cart"]',
    cartQtyPlus: '.ct-step [data-cq="1"]',
    cartQtyOut: '.ct-step output',
    couponInput: '#couponInput',
    couponApply: '#couponApply',
    couponMsg: '#couponMsg',
    couponErrClass: 'err',
    discountRow: '.tr.disc',
    orderTypeSeg: '#otSeg',
    otAttr: 'data-ot',
    addrProbe: '#ctAddr',                    // dough (re)builds the cart-view field into #otFields per order type
    tableProbe: '#ctTable',
    goCheckout: '#goCheckout',
    checkoutView: '[data-view="checkout"]',
    name: '#coName',
    phone: '#coPhone',
    addr: '#coAddr',                         // dough's contact address field is on the checkout view
    table: '#ctTable',
    payCash: '.pm[data-pm="cash"]',
    placeOrder: '#placeOrder',
    checkoutGrand: '#coBandTotal',
    confirmView: '#otpDone',
    trackStatus: '#cvStatusWord',
    rateStars: '#___spa_no_review___',
  },
  plate: {
    family: 'spa',
    globalName: 'PLATE',
    rootSel: '#plate',
    catNav: '#cats',
    grid: '#grid',
    openAttr: 'data-open',
    searchOpen: '#searchBtn',
    searchInput: '#searchInput',
    searchResult: '#searchResults [data-sopen]',
    searchSeed: '#grid [data-open]',
    searchClose: '#searchClose',
    searchClear: null,
    productView: '[data-view="item"]',
    add: '#addBtn',
    cartBadge: '#cartCount',
    cartTab: '#cartBtn',
    cartView: '[data-view="cart"]',
    cartQtyPlus: '.qty.mini [data-cq="1"]',
    cartQtyOut: '.qty.mini output',
    couponInput: '#couponInput',
    couponApply: '#couponApply',
    couponMsg: '#couponMsg',
    couponErrClass: 'err',
    discountRow: '.tr.disc',
    orderTypeSeg: '#otSeg',
    otAttr: 'data-ot',
    addrProbe: '#addrInput',                 // plate delivery field is (re)built into the cart's #otFields
    tableProbe: '#tableInput',
    addrFill: '#addrInput',                  // so the address is filled in the cart, while delivery is active
    goCheckout: '#checkoutBtn',
    checkoutView: '[data-view="checkout"]',
    name: '#ckName',
    phone: '#ckPhone',
    addr: null,                              // captured from the cart's #addrInput (see addrFill)
    table: '#tableInput',
    payCash: '#payList .pay[data-pay="pay_on_counter"]',
    placeOrder: '#placeBtn',
    checkoutGrand: '#ckTotalCta',
    confirmView: '[data-view="confirmed"]',
    trackStatus: '#cvStatusWord',
    rateStars: '#___spa_no_review___',
  },

  // ── lime / picnic / orbit / velora: the `.view[data-view]` SPA engine (A4) ──
  // Four single-page storefronts in the dough/plate shape: home → product → cart
  // → checkout → confirmed are `[data-view]` states toggled by `.is-active`, the
  // grid/search open an item with `[data-open]`, coupon + the `#… [data-ot]`
  // order-type segment live in the CART view, the contact fields live in the
  // CHECKOUT view, and cash is a `.pm[data-pm]` tender. They differ from
  // dough/plate in four ways the contract has to carry:
  //   1. required modifier groups are `.opt-group[data-group="<gid>"]` wrappers
  //      with indexed `[data-opt]` children (NOT dough's `[data-mod="<gid>"]`
  //      chip; picnic's group ids already carry a `g` prefix),
  //   2. lunchbox/picnic names its pickup + dine-in order types `pickup`/`dinein`
  //      instead of `takeaway`/`on-table`,
  //   3. the delivery/table field is (re)BUILT into the cart's `#otFields` on
  //      every order-type switch (so it only exists while that type is active —
  //      the sharpest possible field-toggle probe), except on orbit, which
  //      display-toggles the checkout's `#tableField` wrapper instead,
  //   4. each cart's qty stepper has its own wrapper class.
  lime: {
    family: 'spa',
    globalName: 'LIME',
    rootSel: '#lime.lime',
    catNav: '#chips',
    grid: '#grid',
    openAttr: 'data-open',
    searchOpen: '[data-tab="search"]',       // lime's search is a full tab view with its own #searchGrid
    searchInput: '#searchInput',
    searchResult: '#searchGrid [data-open]',
    searchSeed: '#grid [data-open]',
    searchClose: '[data-tab="home"]',
    searchClear: null,
    productView: '[data-view="product"]',
    add: '#addBtn',
    cartBadge: '#bagBadge',
    cartTab: '[data-tab="cart"]',
    cartView: '[data-view="cart"]',
    cartQtyPlus: '#cartList .qty-min [data-cq="1"]',
    cartQtyOut: '#cartList .qty-min output',
    couponInput: '#couponInput',
    couponApply: '#couponApply',
    couponMsg: '#couponMsg',
    couponErrClass: 'err',
    discountRow: '.tr.disc',
    orderTypeSeg: '#otSeg',
    otAttr: 'data-ot',
    addrProbe: '#cartAddrInput',             // rebuilt into #otFields per order type
    tableProbe: '#tableSel',
    addrFill: '#cartAddrInput',              // mirrors into checkout's #custAddr
    goCheckout: '#goCheckout',
    checkoutView: '[data-view="checkout"]',
    name: '#custName',
    phone: '#custPhone',
    addr: '#custAddr',
    table: '#tableSel',
    payCash: '.pm[data-pm="pay_on_counter"]',
    placeOrder: '#placeOrder',
    checkoutGrand: '#checkoutTotals .tr.grand b',
    confirmView: '[data-view="confirmed"]',
    trackStatus: '#cvStatusWord',
    rateStars: '#___spa_no_review___',
  },
  picnic: {
    family: 'spa',
    globalName: 'PICNIC',
    rootSel: '#phone',                       // `.stage > .phone > .app`; picnic ships no `.picnic` root class
    catNav: '#cats',
    grid: '#grid',
    openAttr: 'data-open',
    searchOpen: '#searchBtn',
    searchInput: '#soInput',
    searchResult: '#soResults [data-open]',
    searchSeed: '#grid [data-open]',
    searchClose: '#soClose',
    searchClear: null,
    productView: '[data-view="product"]',
    add: '#addToCart',
    cartBadge: '#cartBadge',
    cartTab: '[data-tab="cart"]',
    cartView: '[data-view="cart"]',
    cartQtyPlus: '#cartList .qty [data-cq="1"]',
    cartQtyOut: '#cartList .qty output',
    couponInput: '#couponInput',
    couponApply: '#couponApply',
    couponMsg: '#couponMsg',
    couponErrClass: 'err',
    discountRow: '.tr.disc',
    orderTypeSeg: '#orderTypeSeg',
    otAttr: 'data-ot',
    otTakeawayType: 'pickup',                // picnic: pickup / dinein, not takeaway / on-table
    otDineInType: 'dinein',
    addrProbe: '#cartAddrInput',
    tableProbe: '#tableSel',                 // a <select> of the store's tables
    addrFill: '#cartAddrInput',
    goCheckout: '#goCheckout',
    checkoutView: '[data-view="checkout"]',
    name: '#custName',
    phone: '#custPhone',
    addr: '#custAddr',
    table: '#tableSel',
    payCash: '.pm[data-pm="cash"]',
    placeOrder: '#placeOrder',
    checkoutGrand: '#checkoutTotals .tr.grand b',
    confirmView: '[data-view="confirmed"]',
    trackStatus: '#cvStatusWord',
    rateStars: '#___spa_no_review___',
  },
  orbit: {
    family: 'spa',
    globalName: 'ORBIT',
    rootSel: '#orbit',
    catNav: '#arc',
    grid: '#grid',
    openAttr: 'data-open',
    searchOpen: '#searchOpen',
    searchInput: '#searchInput',
    searchResult: '#searchResults [data-open]',
    searchSeed: '#grid [data-open]',
    searchClose: '#searchClose',
    searchClear: null,
    productView: '[data-view="product"]',
    add: '#addToCart',
    cartBadge: '#cartBadge',
    cartTab: '[data-tab="cart"]',
    cartView: '[data-view="cart"]',
    cartQtyPlus: '#cartList .ci-stepper [data-cq="1"]',
    cartQtyOut: '#cartList .ci-stepper output',
    couponInput: '#couponInput',
    couponApply: '#couponApply',
    couponMsg: '#couponMsg',
    couponErrClass: 'err',
    discountRow: '.tr.disc',
    orderTypeSeg: '#orderTypeSeg',
    otAttr: 'data-ot',
    addrProbe: '#cartAddr',                  // orbit rebuilds the delivery widget into #otFields…
    tableProbe: '#tableField',               // …but dine-in only display-toggles the checkout wrapper
    tableInCheckout: true,                   // so the dine-in assertion walks cart → checkout → back
    cartBack: '#ckEdit',
    addrFill: '#cartAddr',                   // mirrors into checkout's #coAddr
    goCheckout: '#goCheckout',
    checkoutView: '[data-view="checkout"]',
    name: '#coName',
    phone: '#coPhone',
    addr: '#coAddr',
    table: '#tableInput',
    payCash: '.pm[data-pm="pay_on_counter"]',
    placeOrder: '#placeOrder',
    checkoutGrand: '#checkoutTotals .tr.grand b',
    confirmView: '[data-view="confirmed"]',
    trackStatus: '#cvStatusWord',
    rateStars: '#___spa_no_review___',
  },
  velora: {
    family: 'spa',
    globalName: 'VELORA',
    rootSel: '#velora.velora',
    catNav: '#catRail',
    grid: '#grid',
    openAttr: 'data-open',
    searchOpen: '#searchOpen',
    searchInput: '#soInput',
    searchResult: '#soResults [data-open]',
    searchSeed: '#grid [data-open]',
    searchClose: '#soBack',
    searchClear: null,
    productView: '[data-view="product"]',
    add: '#addToCart',
    cartBadge: '.nav-badge',                 // velora's cart badge is an id-less `i.bagcount.nav-badge`
    cartTab: '[data-tab="cart"]',
    cartView: '[data-view="cart"]',
    cartQtyPlus: '#cartList .stepper [data-cq="1"]',
    cartQtyOut: '#cartList .stepper output',
    couponInput: '#couponInput',
    couponApply: '#couponApply',
    couponMsg: '#couponMsg',
    couponErrClass: 'err',
    discountRow: '.tr.disc',
    orderTypeSeg: '#orderTypeSeg',
    otAttr: 'data-ot',
    addrProbe: '#addrInput',                 // velora's cart delivery input (checkout's is #custAddr)
    tableProbe: '#tableSel',
    addrFill: '#addrInput',
    goCheckout: '#goCheckout',
    checkoutView: '[data-view="checkout"]',
    name: '#custName',
    phone: '#custPhone',
    addr: '#custAddr',
    table: '#tableSel',
    payCash: '.pm[data-pm="pay_on_counter"]',
    placeOrder: '#placeOrder',
    checkoutGrand: '#checkoutTotals .tr.grand b',
    confirmView: '[data-view="confirmed"]',
    trackStatus: '#cvStatusWord',
    rateStars: '#___spa_no_review___',
  },

  // ── crumb / soiree / scoop / ember: the spa `[data-view]` engine (A5) ────
  // Four single-page storefronts in the dough/plate/lime shape. crumb and soiree
  // lack explicit `id="crumb/soiree"` (both use `id="app" class="crumb/soiree"`),
  // so use `.crumb/.soiree` class selectors instead. scoop names its order types
  // `pickup`/`dinein` (not `takeaway`/`on-table`) and `roomservice` (not
  // `room_service`). soiree lacks a grid container id — items are rendered
  // client-side by its global. scoop's checkout total is `#placeTotal` (not
  // `#checkoutCtaTotal`). soiree's `#totalsBox` wraps the grand total display.
  crumb: {
    family: 'spa',
    globalName: 'CRUMB',
    rootSel: '#app.crumb',
    catNav: '#catRow',
    grid: '#featGrid',                       // crumb's main menu grid (fancy grid)
    openAttr: 'data-open',
    searchOpen: '[data-tab="search"]',       // crumb: search is a bottom tab
    searchInput: '#soInput',
    searchResult: '#soResults [data-open]',  // items are rendered in the search results box
    searchSeed: '#featGrid [data-open]',
    searchClose: '[data-tab="home"]',        // close by clicking home tab
    searchClear: null,
    productView: '[data-view="product"]',
    add: '#addToCart',
    cartBadge: '#cartBadge',
    cartTab: '[data-tab="cart"]',
    cartView: '[data-view="cart"]',
    cartQtyPlus: '#cartList .qty [data-cq="1"]',
    cartQtyOut: '#cartList .qty output',
    couponInput: '#couponInput',
    couponApply: '#couponApply',
    couponMsg: '#couponMsg',
    couponErrClass: 'err',
    discountRow: '.tr.disc',
    orderTypeSeg: '#orderTypeSeg',
    otAttr: 'data-ot',
    // crumb renders order-type fields into the cart's #otFields: dine-in shows a
    // <select id="tableSel">; delivery shows an address-picker row [data-goloc]
    // (there is no #custAddr text input — the address is chosen via a location
    // sheet). Probe those real controls.
    addrProbe: '#otFields [data-goloc]',     // delivery address-picker row (cart)
    tableProbe: '#tableSel',                 // dine-in table <select> (cart)
    addrPick: { row: '#otFields [data-goloc]', input: '#locInput', results: '#locResults' },
    goCheckout: '#goCheckout',
    checkoutView: '[data-view="checkout"]',
    name: '#custName',
    phone: '#custPhone',
    addr: '#custAddr',
    table: '#tableSel',
    payCash: '.pm[data-pm="pay_on_counter"]',
    placeOrder: '#placeOrder',
    checkoutGrand: '#totalsBox .tr.grand b',
    confirmView: '[data-view="confirmed"]',
    trackStatus: '#cvStatusWord',
    rateStars: '#___spa_no_review___',
  },
  soiree: {
    family: 'spa',
    globalName: 'SOIREE',
    rootSel: '#app.soiree',
    catNav: '#catChips',
    grid: '#menuList',                        // soiree's real item grid sits directly below #catChips
    openAttr: 'data-open',
    searchOpen: '#navSearch',                // soiree: search button in nav
    searchInput: '#soInput',
    searchResult: '#soResults [data-open]',  // items are rendered in the search results box
    searchSeed: '#menuList [data-open]',      // seed the query from a rendered menu item
    searchClose: '#soClose',
    searchClear: null,
    productView: '[data-view="product"]',
    add: '#pAddBtn',                          // soiree's product-footer add button (price-add pab)
    cartBadge: '.cc-badge',                   // soiree's id-less `i.cc-badge` cart count
    cartTab: '[data-go="cart"]',             // soiree's cart toggle is data-go, not data-tab
    cartView: '[data-view="cart"]',
    cartQtyPlus: '#cartList .cl-qty [data-cq="1"]',
    cartQtyOut: '#cartList .cl-qty output',
    couponInput: '#couponInput',
    couponApply: '#couponApply',
    couponMsg: '#couponMsg',
    couponErrClass: 'err',
    discountRow: '.tr.disc',
    orderTypeSeg: '#orderTypeSeg',
    otAttr: 'data-ot',
    // soiree renders order-type fields into the cart's #otFields: delivery shows
    // a plain #cartAddr input; dine-in (on-table) shows a #tableSel number input.
    addrProbe: '#cartAddr',
    tableProbe: '#tableSel',
    addrFill: '#cartAddr',
    goCheckout: '[data-paytile]',            // soiree's checkout CTA is the cart-band paytile
    checkoutView: '[data-view="checkout"]',
    name: '#custName',
    phone: '#custPhone',
    addr: '#cartAddr',
    table: '#tableSel',
    payCash: '.pm[data-pm="pay_on_counter"]',
    placeOrder: '#placeOrder',
    checkoutGrand: '#checkoutCtaTotal',
    confirmView: '[data-view="confirmed"]',
    trackStatus: '#cvStatusWord',
    rateStars: '#___spa_no_review___',
  },
  scoop: {
    family: 'spa',
    globalName: 'SCOOP',
    rootSel: '#scoop',
    catNav: '#homeTabs',
    grid: '#grid',
    openAttr: 'data-open',
    searchOpen: '#searchBtn',
    searchInput: '#searchInput',
    searchResult: '#grid [data-open]',
    searchSeed: '#grid [data-open]',
    searchClose: null,                       // scoop: inline grid search, no explicit close
    searchClear: null,
    productView: '[data-view="product"]',
    add: '#addToCart',                       // scoop's product-view add button is #addToCart (not #addBtn)
    cartBadge: '#cartBadge',
    cartTab: '[data-go="cart"]',
    cartView: '[data-view="cart"]',
    cartQtyPlus: '#cartList .qty [data-cq="1"]',
    cartQtyOut: '#cartList .qty output',
    couponInput: '#couponInput',
    couponApply: '#couponApply',
    couponMsg: '#couponMsg',
    couponErrClass: 'err',
    discountRow: '.tr.disc',
    orderTypeSeg: '#orderTypeSeg',
    otAttr: 'data-ot',
    otTakeawayType: 'pickup',                // scoop: pickup, not takeaway
    otDineInType: 'dinein',                  // scoop: dinein, not on-table
    // scoop renders delivery address as #cartAddr and dine-in table as #tableSel
    // inside the cart's #otFields.
    addrProbe: '#cartAddr',
    tableProbe: '#tableSel',                 // scoop's dine-in table field is #tableSel (not #custTable)
    addrFill: '#cartAddr',
    goCheckout: '#goCheckout',
    checkoutView: '[data-view="checkout"]',
    name: '#custName',
    phone: '#custPhone',
    addr: '#cartAddr',
    table: '#tableSel',
    payCash: '.pm[data-pm="cash"], .pm[data-pm="pay_on_counter"]',  // scoop's demo tender is "cash"
    placeOrder: '#placeOrder',
    checkoutGrand: '#checkoutCtaTotal',      // scoop's checkout CTA total
    confirmView: '[data-view="confirmed"]',
    trackStatus: '#cvStatusWord',
    rateStars: '#___spa_no_review___',
  },
  ember: {
    family: 'spa',
    globalName: 'EMBER',
    rootSel: '#ember',
    catNav: '#catRow',
    grid: '#menuList',                        // ember renders items into #menuList (there is no #grid)
    openAttr: 'data-open',
    searchOpen: '#searchOpen',
    searchInput: '#searchInput',
    searchResult: '#searchResults [data-open]',
    searchSeed: '#menuList [data-open]',
    searchClose: '#searchClose',
    searchClear: null,
    productView: '[data-view="product"]',
    add: '#addToCart',
    cartBadge: '#cpCount',                    // ember's cart-pill count (there is no #cartBadge)
    cartTab: '[data-tab="cart"]',
    cartView: '[data-view="cart"]',
    cartQtyPlus: '#cartList .ci-qty [data-cq="1"]',
    cartQtyOut: '#cartList .ci-qty output',
    couponInput: '#couponInput',
    couponApply: '#couponApply',
    couponMsg: '#couponMsg',
    couponErrClass: 'err',
    discountRow: '.tr.disc',
    orderTypeSeg: '#otSeg',                  // ember's order-type segment is #otSeg
    otAttr: 'data-ot',
    // ember renders delivery address as a plain #cartAddr input in the cart's
    // #otFields; its dine-in is full waiter-service and collects no table number
    // (only an explanatory hint) — dineInNoTable skips the table-field assertion.
    addrProbe: '#cartAddr',
    tableProbe: '#custTable',                // ember has no dine-in table field (see dineInNoTable)
    addrFill: '#cartAddr',
    dineInNoTable: true,
    goCheckout: '#goCheckout',
    checkoutView: '[data-view="checkout"]',
    name: '#coName',                         // ember uses #coName in checkout
    phone: '#coPhone',
    addr: '#cartAddr',
    table: '#custTable',
    payCash: '.pm[data-pm="pay_on_counter"]',
    placeOrder: '#placeOrder',
    checkoutGrand: '#totalsBox .tr.grand b',
    confirmView: '[data-view="confirmed"]',
    trackStatus: '#cvStatusWord',
    rateStars: '#___spa_no_review___',
  },
};

// ── small ported helpers (patterns from retained.js) ─────────────────────────

/** Click the first ENABLED, visible element matching `selector`. */
async function clickFirst(page, selector, label) {
  const ok = await page.evaluate((sel) => {
    const el = Array.from(document.querySelectorAll(sel)).find((e) => {
      const s = getComputedStyle(e); const r = e.getBoundingClientRect();
      return !e.disabled && !e.hidden && s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0;
    });
    if (!el) return false;
    el.scrollIntoView({ block: 'center' });
    el.click();
    return true;
  }, selector);
  if (!ok) throw new Error(`${label}: no enabled visible control matched ${selector}`);
}

/** Is a selector's first match visible? */
function isVisible(page, selector) {
  return page.evaluate((sel) => {
    const e = document.querySelector(sel);
    if (!e) return false;
    const s = getComputedStyle(e); const r = e.getBoundingClientRect();
    return !e.hidden && s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0;
  }, selector);
}

/**
 * Whether ANY match of `selector` has `display !== 'none'` — the order-type
 * fields toggle their own `display`, but sit inside a view/sheet that may itself
 * be off-screen (so a rect-based visibility check would wrongly report hidden).
 * Returns `null` when nothing matches. Mirrors A1's original field-toggle probe.
 */
function displayShown(page, selector) {
  return page.evaluate((sel) => {
    const els = Array.from(document.querySelectorAll(sel));
    if (!els.length) return null;
    return els.some((e) => getComputedStyle(e).display !== 'none');
  }, selector);
}

/**
 * True iff at least one match of `selector` is present AND not display:none /
 * visibility:hidden — reading the element's OWN computed display, so it stays
 * correct for a field the theme display-toggles while it sits inside an
 * off-screen view (dough's checkout wraps), and returns false when the selector
 * matches nothing (plate rebuilds its delivery/table field only for that type).
 */
function shown(page, selector) {
  return page.evaluate((sel) => Array.from(document.querySelectorAll(sel)).some((e) => {
    const s = getComputedStyle(e);
    return s.display !== 'none' && s.visibility !== 'hidden';
  }), selector);
}

/** Bump the cart quantity until the checkout entry is enabled (cv min-order gate). */
async function satisfyMinimumOrder(page, checkoutSelector, plusSelector, label) {
  const res = await page.evaluate(({ selector, plusSel }) => {
    const checkout = document.querySelector(selector);
    if (!checkout) return { ready: false, reason: `missing ${selector}` };
    let n = 0;
    while (checkout.disabled && n < 50) {
      const plus = Array.from(document.querySelectorAll(plusSel)).find((el) => {
        const s = getComputedStyle(el); const r = el.getBoundingClientRect();
        return !el.disabled && !el.hidden && s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0;
      });
      if (!plus) break;
      plus.click(); n += 1;
    }
    return { ready: !checkout.disabled, n, reason: checkout.disabled ? 'minimum order unsatisfied' : null };
  }, { selector: checkoutSelector, plusSel: plusSelector });
  if (!res.ready) throw new Error(`${label}: ${res.reason} after ${res.n} increments`);
}

/** Exercise the search with a real fixture query, then clear it to restore the grid. */
async function exerciseSearch(page, c, theme, sleep) {
  const query = await page.evaluate((seedSel) => {
    const seed = Array.from(document.querySelectorAll(seedSel)).find((e) => {
      const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0;
    });
    if (!seed) return '';
    const name = seed.getAttribute('data-name')
      || seed.querySelector('.card-name, b, h3, [class*="name"]')?.textContent
      || seed.textContent || '';
    return String(name).replace(/\s+/g, ' ').trim().slice(0, 40);
  }, c.searchSeed);
  if (!query) throw new Error(`${theme} search: could not derive a real product query`);

  if (c.searchOpen) { await clickFirst(page, c.searchOpen, `${theme} search open`); await sleep(300); }

  const typed = await page.evaluate((sel, value) => {
    const input = document.querySelector(sel);
    if (!input) return false;
    input.removeAttribute('readonly');          // crave overlay input is readonly until unlocked
    input.setAttribute('data-csp-focus', 'unlock');
    input.focus();
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    return true;
  }, c.searchInput, query);
  if (!typed) throw new Error(`${theme} search: missing input ${c.searchInput}`);
  await sleep(300);

  const hits = await page.evaluate((sel) => Array.from(document.querySelectorAll(sel)).filter((e) => {
    const s = getComputedStyle(e); const r = e.getBoundingClientRect();
    return !e.hidden && s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0;
  }).length, c.searchResult);
  if (!hits) throw new Error(`${theme} search: no visible result for "${query}" via ${c.searchResult}`);

  // Restore the full grid so the target item's open control is reachable. Inline
  // grid-filter themes (matcha/glow/aurora/vivid) leave the grid narrowed to the
  // query otherwise; clearing the crave overlay input is harmless before closing.
  await page.evaluate((inSel, clearSel) => {
    const input = document.querySelector(inSel);
    if (input) { input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); }
    const clear = clearSel && document.querySelector(clearSel);
    if (clear) clear.click();
  }, c.searchInput, c.searchClear);
  await sleep(200);

  if (c.searchClose) { await clickFirst(page, c.searchClose, `${theme} search close`); await sleep(350); }
  return query;
}

/** Parse the numeric value out of a money string (Arabic-Indic digits aware). */
function money(str) {
  const s = normalizeDigits(String(str || '')).replace(/[^\d.,-]/g, '').replace(/,/g, '');
  const m = s.match(/-?\d+(?:\.\d+)?/);
  return m ? parseFloat(m[0]) : NaN;
}

/** The last VISIBLE, non-empty text among matches of `selector` (money-total scrape). */
function readMoneyText(page, selector) {
  return page.evaluate((sel) => {
    const vis = Array.from(document.querySelectorAll(sel)).filter((e) => {
      const s = getComputedStyle(e); const r = e.getBoundingClientRect();
      return !e.hidden && s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0
        && (e.textContent || '').trim() !== '';
    });
    return vis.length ? (vis[vis.length - 1].textContent || '').trim() : null;
  }, selector);
}

/** Find the target menu item: first item with a required option group whose open
 *  control is present in the DOM. Returns `{ id, name, requiredGroups: [gid…] }`. */
async function findTargetItem(page, c) {
  return page.evaluate((glob, openAttr) => {
    const items = (window[glob] && window[glob].items) || {};
    const cands = [];
    for (const k in items) {
      const it = items[k];
      // lime/orbit/velora ship `optionGroups`; picnic names the same array `groups`.
      const req = (it.optionGroups || it.groups || []).filter((g) => g.required);
      if (req.length) cands.push({ id: it.id, name: it.name, requiredGroups: req.map((g) => g.id) });
    }
    if (!cands.length) return null;
    const openable = cands.find((o) => document.querySelector(`[${openAttr}="${o.id}"]`));
    return openable || cands[0];
  }, c.globalName, c.openAttr);
}

// ── shared: home shell + track/review + db-assert ────────────────────────────

async function openHomeShell(theme, c, slug, { page, log, shot, sleep, clearServiceWorkers }, step) {
  const url = localeUrl('/' + slug);
  const resp = await page.goto(url, { waitUntil: 'networkidle2' });
  if (!resp || resp.status() >= 400) throw new Error(`storefront ${url} returned ${resp && resp.status()}`);

  await clearServiceWorkers(page);
  await page.evaluate(async () => {
    try { localStorage.clear(); sessionStorage.clear(); } catch (_e) {}
    if (window.caches) { const k = await caches.keys(); await Promise.all(k.map((x) => caches.delete(x))); }
  });
  await page.reload({ waitUntil: 'networkidle2' });
  collectConsole(page);

  const shell = await assertRtl(page);
  step(`home RTL ok (lang=${shell.lang} dir=${shell.dir})`);
  if (!(await page.evaluate((s) => !!document.querySelector(s), c.rootSel))) {
    throw new Error(`template root class not found: ${c.rootSel}`);
  }
  step(`template root class present (${c.rootSel})`);

  const layout = await page.evaluate((navSel, gridSel) => {
    const nav = document.querySelector(navSel); const grid = document.querySelector(gridSel);
    if (!nav || !grid) return { ok: false, reason: 'nav or grid missing', navSel, gridSel };
    const nr = nav.getBoundingClientRect(); const gr = grid.getBoundingClientRect();
    return { ok: nr.top <= gr.top + 1, navTop: Math.round(nr.top), gridTop: Math.round(gr.top) };
  }, c.catNav, c.grid);
  if (!layout.ok) throw new Error(`category nav must sit above the grid (${JSON.stringify(layout)})`);
  step(`category nav above grid (navTop=${layout.navTop} ≤ gridTop=${layout.gridTop})`);

  await page.evaluate(() => {
    document.querySelectorAll('#splashSkip, #mbSplashSkip, #obSkip, #onbSkip, [id$="SplashSkip"], [id$="splash-skip"], [id$="OnbSkip"], [id$="onb-skip"], .splash-skip, .ob-skip, .onb-skip').forEach((s) => s.click());
  });
  await sleep(400);
  await shot(page, `a-order-${theme}-1-home`);
}

/** db()-assert the placed order row + open the track page + optional review. */
async function assertOrderTrackReview(theme, c, slug, res, displayedNum, { page, shot, sleep }, step) {
  const token = (res.track_url.match(/order\/track\/([^/?#]+)/) || [])[1];
  if (!token) throw new Error(`${theme}: could not parse a track token from ${res.track_url}`);
  const rows = db(
    'SELECT o.id, o.store_id, o.status, o.type, o.locale, o.subtotal_minor / 100.0, o.discount_amount_minor / 100.0, o.tax_total_minor / 100.0, o.grand_total_minor / 100.0, o.coupon_id, p.slug '
    + 'FROM {p}orders o JOIN {p}stores p ON p.id = o.store_id WHERE o.track_token = ? LIMIT 1',
    [token],
  );
  if (!rows.length) throw new Error(`${theme}: no order row for track token ${token}`);
  const [id, , status, type, locale, subtotal, discount, taxTotal, grandTotal, couponId, storeSlug] = rows[0];
  const nSub = parseFloat(subtotal); const nDisc = parseFloat(discount || '0');
  const nTax = parseFloat(taxTotal || '0'); const nGrand = parseFloat(grandTotal);

  if (storeSlug !== slug) throw new Error(`${theme}: order store slug is "${storeSlug}", expected "${slug}"`);
  if (!status) throw new Error(`${theme}: order has no status`);
  if (type !== 'delivery') throw new Error(`${theme}: order type is "${type}", expected delivery`);
  if (locale !== 'ar') throw new Error(`${theme}: order locale is "${locale}", expected ar`);
  if (!(nDisc > 0) || !couponId) throw new Error(`${theme}: coupon discount not recorded (discount=${discount}, coupon_id=${couponId})`);
  const expectedGrand = nSub - nDisc + nTax;                    // tax PRE-discount
  if (Math.abs(nGrand - expectedGrand) > 0.02) {
    throw new Error(`${theme}: grand_total ${nGrand} ≠ subtotal ${nSub} − discount ${nDisc} + tax ${nTax} = ${expectedGrand.toFixed(2)}`);
  }
  if (!Number.isNaN(displayedNum) && Math.abs(displayedNum - nGrand) > 0.02) {
    throw new Error(`${theme}: displayed total ${displayedNum} ≠ stored grand_total ${nGrand}`);
  }
  step(`order #${id} db-asserted: store=${storeSlug} status=${status} locale=${locale} · `
    + `grand ${nGrand} = sub ${nSub} − disc ${nDisc} + tax ${nTax} (displayed ${displayedNum})`);

  // track page — status text + RTL + locale.
  // Every theme's confirm screen auto-redirects to the track page (immediate for
  // vivid, ~1.5s aurora, ~3.2s crave/matcha/glow). Wait that redirect out before
  // navigating ourselves, or the two navigations race (net::ERR_ABORTED). Only
  // goto explicitly if the theme did not auto-redirect within the window.
  await page.waitForFunction((tk) => location.pathname.indexOf(tk) >= 0, { timeout: 9000 }, token).catch(() => {});
  const onTrack = await page.evaluate((tk) => location.pathname.indexOf(tk) >= 0, token);
  if (!onTrack) {
    const tResp = await page.goto(res.track_url, { waitUntil: 'networkidle2' });
    if (!tResp || tResp.status() >= 400) throw new Error(`${theme}: track page returned ${tResp && tResp.status()}`);
  } else {
    await page.waitForNetworkIdle({ idleTime: 500, timeout: 5000 }).catch(() => {});
  }
  await assertRtl(page);
  await sleep(400);
  const trackStatus = await page.evaluate((sel) => {
    const e = document.querySelector(sel); return e ? (e.textContent || '').trim() : null;
  }, c.trackStatus);
  if (!trackStatus || trackStatus === '…') throw new Error(`${theme}: track page shows no status word (got "${trackStatus}")`);
  step(`track page: status "${trackStatus}" (order locale=${locale})`);
  await shot(page, `a-order-${theme}-6-track`);

  // leave a review if the store exposes an inline item-rating control. The
  // `?item=` deep-link opens the product detail; the rate handler is a no-op
  // until that product is open (it guards on the currently-open item), so wait
  // for the product view before clicking a star.
  await page.goto(localeUrl('/' + slug + '?item=' + res.item_id), { waitUntil: 'networkidle2' });
  if (c.productView) {
    await page.waitForFunction((pv) => {
      const e = document.querySelector(pv);
      return e && !e.hidden && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().width > 0;
    }, { timeout: 6000 }, c.productView).catch(() => {});
  }
  await sleep(400);
  const reviewed = await page.evaluate((starsSel, oneSel) => {
    const stars = document.querySelector(starsSel);
    if (!stars) return { skipped: true };
    const s = getComputedStyle(stars);
    if (stars.hidden || s.display === 'none' || stars.getBoundingClientRect().width === 0) return { skipped: true };
    const btn = document.querySelector(oneSel) || stars.querySelector('[data-rate]');
    if (!btn) return { skipped: true };
    btn.click();
    return { skipped: false };
  }, c.rateStars, c.rateOne || c.rateStars);
  if (reviewed.skipped) {
    step('review: store does not expose an inline item-rating control (skipped)');
  } else {
    await page.waitForFunction((sel) => {
      const t = document.querySelector(sel); return t && !t.hidden;
    }, { timeout: 8000 }, c.rateThanks).catch(() => {});
    const ack = await isVisible(page, c.rateThanks);
    if (!ack) throw new Error(`${theme}: review submitted but no acknowledgement (${c.rateThanks})`);
    step('review left (server acknowledged)');
  }
}

/** Place the order over the shared /send-order POST; returns `{ track_url, order_id }`. */
async function placeOrder(theme, c, target, { page, shot, sleep }, step) {
  const displayed = await readMoneyText(page, c.checkoutGrand);
  const displayedNum = money(displayed);
  await shot(page, `a-order-${theme}-4-checkout`);

  const orderRespP = page.waitForResponse(
    (r) => /\/send-order(\?|$)/.test(r.url()) && r.request().method() === 'POST',
    { timeout: 25000 },
  ).catch(() => null);
  await clickFirst(page, c.placeOrder, `${theme} place order`);
  const orderResp = await orderRespP;
  if (!orderResp) throw new Error(`${theme}: no send-order response captured`);
  let res;
  try { res = await orderResp.json(); } catch (_e) { res = null; }
  // A theme that redirects the instant the fetch resolves (vivid) can discard the
  // response body before json() buffers it. Recover the placed order from the
  // track-page navigation the client triggered — /order/track/ is only reached
  // on success, so this is not a false positive.
  if (!res || !res.success || !res.track_url) {
    await page.waitForFunction(() => /\/order\/track\//.test(location.pathname), { timeout: 15000 }).catch(() => {});
    const loc = await page.evaluate(() => (/\/order\/track\//.test(location.pathname) ? location.href : ''));
    if (loc) {
      res = (res && typeof res === 'object') ? res : {};
      res.success = true;
      res.track_url = loc.split('#')[0];
      res.order_id = res.order_id || '(from track redirect)';
    }
  }
  if (!res || !res.success) throw new Error(`${theme}: order rejected (${res ? JSON.stringify(res.message || res) : 'no JSON'})`);
  if (!res.track_url) throw new Error(`${theme}: send-order success carried no track_url (${JSON.stringify(res)})`);

  await page.waitForFunction((confirmSel) => {
    const v = document.querySelector(confirmSel);
    const vVisible = v && !v.hidden && getComputedStyle(v).display !== 'none' && v.getBoundingClientRect().width > 0;
    return vVisible || /\/order\/track\//.test(location.pathname);
  }, { timeout: 20000 }, c.confirmView).catch(() => {});
  const onConfirm = await page.evaluate((confirmSel) => {
    const v = document.querySelector(confirmSel);
    const vVisible = v && !v.hidden && getComputedStyle(v).display !== 'none' && v.getBoundingClientRect().width > 0;
    return { confirmed: !!vVisible, redirected: /\/order\/track\//.test(location.pathname) };
  }, c.confirmView);
  if (!onConfirm.confirmed && !onConfirm.redirected) {
    throw new Error(`${theme}: neither the confirm screen nor the track page appeared after placing the order`);
  }
  step(`order placed — ${onConfirm.confirmed ? 'confirm screen' : 'redirected to track'}, track=${res.track_url}, id=${res.order_id}`);
  await shot(page, `a-order-${theme}-5-confirmed`);
  res.item_id = target.id;
  return { res, displayedNum };
}

// ── the crave "cv" SPA flow (crave / matcha / glow) ──────────────────────────

async function runCv(theme, c, ctx) {
  const { page, shot, sleep } = ctx;
  const slug = `${theme}-demo`;
  const steps = [];
  const step = (s) => { steps.push(s); ctx.log(`  a-order ${theme} · ${s}`); };

  await openHomeShell(theme, c, slug, ctx, step);

  const q = await exerciseSearch(page, c, theme, sleep);
  step(`search returned results for "${q}"`);

  const target = await findTargetItem(page, c);
  if (!target) throw new Error(`${theme}: no item with a required modifier group in window.${c.globalName}.items`);

  let opened = await page.evaluate((id, openAttr) => {
    const card = document.querySelector(`[${openAttr}="${id}"]`);
    if (!card) return false;
    card.scrollIntoView({ block: 'center' });
    card.click();
    return true;
  }, target.id, c.openAttr);
  if (!opened) {
    await page.goto(localeUrl('/' + slug + '?item=' + target.id), { waitUntil: 'networkidle2' });
    opened = true;
  }
  await sleep(500);
  if (!(await isVisible(page, c.productView))) throw new Error(`${theme}: product view did not open for item ${target.id}`);
  step(`opened "${target.name}" (#${target.id}) with ${target.requiredGroups.length} required group(s)`);

  const satisfied = await page.evaluate(() => {
    let variant = 0; let groups = 0;
    const vgroup = document.querySelector('.opt-group[data-kind="variant"]');
    if (vgroup) { const o = vgroup.querySelector('[data-variant]'); if (o) { o.click(); variant += 1; } }
    document.querySelectorAll('.opt-group[data-group]').forEach((g) => {
      const req = g.querySelector('.opt-req:not(.optional)');
      if (!req) return;
      const first = g.querySelector('.opt[data-opt]');
      if (first) { first.click(); groups += 1; }
    });
    return { variant, groups };
  });
  step(`satisfied required groups (variant=${satisfied.variant}, choice=${satisfied.groups})`);
  await shot(page, `a-order-${theme}-2-item`);

  await clickFirst(page, c.add, `${theme} add-to-cart`);
  await sleep(500);
  const badge = await page.evaluate((sel) => {
    const el = document.querySelector(sel);
    return el ? { hidden: el.hasAttribute('hidden'), text: (el.textContent || '').trim() } : null;
  }, c.cartBadge);
  if (!badge || badge.hidden || !badge.text || badge.text === '0') {
    throw new Error(`${theme}: cart badge did not increment after add-to-cart (${JSON.stringify(badge)})`);
  }
  step(`added to cart (badge=${badge.text})`);

  await clickFirst(page, c.cartTab, `${theme} cart tab`);
  await sleep(400);
  const before = await page.evaluate((sel) => { const o = document.querySelector(sel); return o ? (o.textContent || '').trim() : null; }, c.cartQtyOut);
  await clickFirst(page, c.cartQtyPlus, `${theme} qty +`);
  await sleep(300);
  const after = await page.evaluate((sel) => { const o = document.querySelector(sel); return o ? (o.textContent || '').trim() : null; }, c.cartQtyOut);
  if (!after || after === before) throw new Error(`${theme}: quantity did not change (before=${before} after=${after})`);
  step(`quantity changed ${before} → ${after}`);

  await applyCouponCv(page, c, theme, sleep, step);
  await shot(page, `a-order-${theme}-3-cart`);

  // exercise every order type the theme exposes — assert field toggles
  const types = await page.evaluate((seg, otAttr) => Array.from(document.querySelectorAll(`${seg} [${otAttr}]`)).map((b) => b.getAttribute(otAttr)), c.orderTypeSeg, c.otAttr);
  const exercised = [];
  if (types.includes('takeaway')) {
    await clickFirst(page, c.otTakeaway, `${theme} order-type takeaway`);
    await sleep(250);
    const t = await orderTypeFields(page, c);
    if (!t.phone || t.addr || t.table) throw new Error(`${theme}: pickup should show phone only (${JSON.stringify(t)})`);
    exercised.push('pickup(phone)');
  }
  if (types.includes('on-table')) {
    await clickFirst(page, c.otDineIn, `${theme} order-type dine-in`);
    await sleep(250);
    const t = await orderTypeFields(page, c);
    if (!t.table || t.addr) throw new Error(`${theme}: dine-in should show the table field, not the address (${JSON.stringify(t)})`);
    await page.evaluate((sel) => { const e = document.querySelector(sel); if (e && !e.readOnly) { e.value = '7'; e.dispatchEvent(new Event('input', { bubbles: true })); } }, c.table);
    exercised.push('dine-in(table)');
  }
  await clickFirst(page, c.otDelivery, `${theme} order-type delivery`);
  await sleep(250);
  if ((await displayShown(page, c.addrBlock)) === false) throw new Error(`${theme}: delivery did not reveal the address block`);
  exercised.push('delivery(address)');
  step(`order types exercised: ${exercised.join(', ')}`);

  await satisfyMinimumOrder(page, c.goCheckout, '[data-cq="1"]', `${theme} checkout entry`);
  await clickFirst(page, c.goCheckout, `${theme} go-checkout`);
  await sleep(500);
  if (!(await isVisible(page, c.checkoutView))) throw new Error(`${theme}: checkout view not visible`);

  await page.evaluate((nameSel, phoneSel, addrSel) => {
    const set = (sel, v) => { const e = document.querySelector(sel); if (e) { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); } };
    set(nameSel, 'E2E Diner'); set(phoneSel, '+966512345678'); set(addrSel, '12 King Fahd Road, Riyadh');
  }, c.name, c.phone, c.addr);

  const pay = await page.evaluate((cashSel) => {
    const cash = document.querySelector(cashSel);
    return { present: !!cash, on: cash ? cash.classList.contains('is-on') : false };
  }, c.payCash);
  if (!pay.present) throw new Error(`${theme}: no cash / pay-on-counter tender`);
  if (!pay.on) await clickFirst(page, c.payCash, `${theme} cash tender`);
  step('checkout: name/phone/address filled, cash tender selected');

  const { res, displayedNum } = await placeOrder(theme, c, target, ctx, step);
  await assertOrderTrackReview(theme, c, slug, res, displayedNum, ctx, step);

  await assertNoOverflow(page);
  await assertNoConsoleErrors(page);
  step('no horizontal overflow, no console/CSP errors');
  return steps.length;
}

/** cv order-type field visibility triple. */
function orderTypeFields(page, c) {
  return page.evaluate((phoneSel, addrSel, tblSel) => {
    const vis = (sel) => { const e = document.querySelector(sel); return e ? getComputedStyle(e).display !== 'none' : null; };
    return { phone: vis(phoneSel), addr: vis(addrSel), table: vis(tblSel) };
  }, c.phoneBlock, c.addrBlock, c.tableBlock);
}

/** Apply P1TEST10 on a cv theme with a bounded poll for the rendered discount row. */
async function applyCouponCv(page, c, theme, sleep, step) {
  const applyCoupon = () => page.evaluate((inSel, applySel) => {
    const i = document.querySelector(inSel);
    if (i) { i.value = 'P1TEST10'; i.dispatchEvent(new Event('input', { bubbles: true })); }
    const a = document.querySelector(applySel); if (a) a.click();
  }, c.couponInput, c.couponApply);
  const readCoupon = () => page.evaluate((discSel, msgSel) => {
    const disc = document.querySelector(discSel);
    const msg = document.querySelector(msgSel);
    return {
      disc: disc ? (disc.textContent || '').replace(/\s+/g, ' ').trim() : null,
      msg: msg ? (msg.textContent || '').replace(/\s+/g, ' ').trim() : null,
      msgHidden: msg ? msg.hasAttribute('hidden') : null,
    };
  }, c.discountRow, c.couponMsg);

  await applyCoupon();
  let coupon = { disc: null, msg: null };
  const applied = (s) => s.disc && /P1TEST10/i.test(s.disc);
  const failed = (s) => !applied(s) && s.msgHidden === false && s.msg && /[✕xX×]/.test(s.msg);
  for (let attempt = 0; attempt < 40; attempt += 1) {
    await sleep(300);
    coupon = await readCoupon();
    if (applied(coupon)) break;
    if (failed(coupon)) break;
    if (attempt === 12 && !applied(coupon)) await applyCoupon();
  }
  if (!applied(coupon)) throw new Error(`${theme}: P1TEST10 did not render a discount row (${JSON.stringify(coupon)})`);
  step(`coupon P1TEST10 applied (${coupon.disc})`);
}

// ── the au-/vd- bottom-sheet flow (aurora / vivid) ───────────────────────────

async function runSheet(theme, c, ctx) {
  const { page, shot, sleep } = ctx;
  const slug = `${theme}-demo`;
  const steps = [];
  const step = (s) => { steps.push(s); ctx.log(`  a-order ${theme} · ${s}`); };

  await openHomeShell(theme, c, slug, ctx, step);

  const q = await exerciseSearch(page, c, theme, sleep);
  step(`search returned results for "${q}"`);

  const target = await findTargetItem(page, c);
  if (!target) throw new Error(`${theme}: no item with a required modifier group in window.${c.globalName}.items`);

  let opened = await page.evaluate((id, openAttr) => {
    const card = document.querySelector(`[${openAttr}="${id}"]`);
    if (!card) return false;
    card.scrollIntoView({ block: 'center' });
    card.click();
    return true;
  }, target.id, c.openAttr);
  if (!opened) {
    await page.goto(localeUrl('/' + slug + '?item=' + target.id), { waitUntil: 'networkidle2' });
    opened = true;
  }
  await sleep(600);
  if (!(await isVisible(page, c.productView))) throw new Error(`${theme}: item sheet did not open for item ${target.id}`);
  step(`opened "${target.name}" (#${target.id}) with ${target.requiredGroups.length} required group(s)`);

  // satisfy: first variant + first option of each required group (idempotent for
  // aurora's auto-selected singles; checks vivid's radios/checkboxes).
  const satisfied = await page.evaluate((bodySel, variantSel, gids) => {
    const body = document.querySelector(bodySel);
    if (!body) return { variant: 0, groups: 0 };
    let variant = 0; let groups = 0;
    const v = body.querySelector(variantSel);
    if (v) { v.click(); variant += 1; }
    gids.forEach((gid) => {
      const first = body.querySelector(`[data-group="${gid}"][data-opt], [data-group="${gid}"] [data-opt]`);
      if (first) { first.click(); groups += 1; }
    });
    return { variant, groups };
  }, c.itemBody, c.variantSel, target.requiredGroups);
  step(`satisfied required groups (variant=${satisfied.variant}, choice=${satisfied.groups})`);
  await shot(page, `a-order-${theme}-2-item`);

  await clickFirst(page, c.add, `${theme} add-to-cart`);
  await sleep(500);
  const badge = await page.evaluate((sel) => {
    const el = document.querySelector(sel);
    return el ? { text: (el.textContent || '').trim() } : null;
  }, c.cartBadge);
  if (!badge || !badge.text || parseInt(normalizeDigits(badge.text), 10) < 1) {
    throw new Error(`${theme}: cart badge did not increment after add-to-cart (${JSON.stringify(badge)})`);
  }
  step(`added to cart (badge=${badge.text})`);

  // open the cart sheet — the order form reveals once the cart is non-empty
  await clickFirst(page, c.cartTab, `${theme} cart toggle`);
  await sleep(500);
  if (!(await isVisible(page, c.orderForm))) throw new Error(`${theme}: order form not visible in the cart sheet`);

  const before = await page.evaluate((sel) => { const o = document.querySelector(sel); return o ? (o.textContent || '').trim() : null; }, c.cartQtyOut);
  await clickFirst(page, c.cartQtyPlus, `${theme} qty +`);
  await sleep(400);
  const after = await page.evaluate((sel) => { const o = document.querySelector(sel); return o ? (o.textContent || '').trim() : null; }, c.cartQtyOut);
  if (!after || after === before) throw new Error(`${theme}: quantity did not change (before=${before} after=${after})`);
  step(`quantity changed ${before} → ${after}`);

  await applyCouponSheet(page, c, theme, sleep, step);
  await shot(page, `a-order-${theme}-3-cart`);

  // exercise every order type the theme exposes — assert field toggles
  const types = await page.evaluate((seg, otAttr) => Array.from(document.querySelectorAll(`${seg} [${otAttr}]`)).map((b) => b.getAttribute(otAttr)), c.orderTypeSeg, c.otAttr);
  const clickType = (t) => page.evaluate((seg, otAttr, type) => {
    const b = document.querySelector(`${seg} [${otAttr}="${type}"]`); if (b) b.click();
  }, c.orderTypeSeg, c.otAttr, t);
  const exercised = [];
  if (types.includes(c.otTakeawayType)) {
    await clickType(c.otTakeawayType); await sleep(300);
    const t = await sheetOrderTypeFields(page, c);
    if (!t.phone || t.addr || t.table) throw new Error(`${theme}: pickup should show phone only (${JSON.stringify(t)})`);
    exercised.push('pickup(phone)');
  }
  if (types.includes(c.otDineInType)) {
    await clickType(c.otDineInType); await sleep(300);
    const t = await sheetOrderTypeFields(page, c);
    if (!t.table || t.addr) throw new Error(`${theme}: dine-in should show the table field, not the address (${JSON.stringify(t)})`);
    await page.evaluate((blk) => { const e = document.querySelector(`${blk} input, ${blk} select`); if (e && !e.readOnly) { e.value = e.tagName === 'SELECT' ? (e.options[e.options.length - 1] && e.options[e.options.length - 1].value) : '7'; e.dispatchEvent(new Event(e.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); } }, c.tableBlock);
    exercised.push('dine-in(table)');
  }
  await clickType(c.otDeliveryType); await sleep(300);
  if ((await displayShown(page, c.addrBlock)) === false) throw new Error(`${theme}: delivery did not reveal the address block`);
  exercised.push('delivery(address)');
  step(`order types exercised: ${exercised.join(', ')}`);

  // fill contact + delivery address inside the order form
  await page.evaluate((formSel) => {
    const form = document.querySelector(formSel); if (!form) return;
    const set = (sel, v) => { const e = form.querySelector(sel); if (e) { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); } };
    set('input[name="name"]:not([type=hidden])', 'E2E Diner');
    set('input[name="phone-number"]', '+966512345678');
    set('[data-field="address"] [name="address"]', '12 King Fahd Road, Riyadh');
  }, c.orderForm);

  // cash / COD tender: the select defaults to pay_on_counter; a single-method
  // store renders a hidden pay_via=pay_on_counter, so a missing select is fine.
  const pay = await page.evaluate((sel) => {
    const s = document.querySelector(sel);
    if (!s) return { present: false };
    s.value = 'pay_on_counter'; s.dispatchEvent(new Event('change', { bubbles: true }));
    return { present: true, value: s.value };
  }, c.paySelect);
  step(`checkout: name/phone/address filled, cash tender ${pay.present ? 'selected' : '(single-method hidden)'}`);

  // satisfy any subtotal-based minimum order before submitting
  await ensureSheetMinOrder(page, c, theme, sleep, step);

  const { res, displayedNum } = await placeOrder(theme, c, target, ctx, step);
  await assertOrderTrackReview(theme, c, slug, res, displayedNum, ctx, step);

  await assertNoOverflow(page);
  await assertNoConsoleErrors(page);
  step('no horizontal overflow, no console/CSP errors');
  return steps.length;
}

/** sheet order-type field visibility triple (display-toggled within the order form). */
async function sheetOrderTypeFields(page, c) {
  const [phone, addr, table] = await Promise.all([
    displayShown(page, c.phoneBlock), displayShown(page, c.addrBlock), displayShown(page, c.tableBlock),
  ]);
  return { phone, addr, table };
}

/** Apply P1TEST10 on a sheet theme; poll the coupon message for the success class. */
async function applyCouponSheet(page, c, theme, sleep, step) {
  const applyCoupon = () => page.evaluate((inSel, applySel) => {
    const i = document.querySelector(inSel);
    if (i) { i.value = 'P1TEST10'; i.dispatchEvent(new Event('input', { bubbles: true })); }
    const a = document.querySelector(applySel); if (a) a.click();
  }, c.couponInput, c.couponApply);
  const readCoupon = () => page.evaluate((msgSel, okTok, errTok, discSel) => {
    const m = document.querySelector(msgSel);
    const cls = m ? m.className : '';
    const disc = document.querySelector(discSel);
    return {
      ok: cls.indexOf(okTok) >= 0,
      err: cls.indexOf(errTok) >= 0,
      discShown: !!(disc && (disc.textContent || '').replace(/\s+/g, '')),
      msg: m ? (m.textContent || '').replace(/\s+/g, ' ').trim() : '',
    };
  }, c.couponMsg, c.couponOkToken, c.couponErrToken, c.discountSel);

  await applyCoupon();
  let coupon = { ok: false, err: false, msg: '' };
  for (let attempt = 0; attempt < 40; attempt += 1) {
    await sleep(300);
    coupon = await readCoupon();
    if (coupon.ok) break;
    if (coupon.err) break;
    if (attempt === 12 && !coupon.ok) await applyCoupon();
  }
  if (!coupon.ok) throw new Error(`${theme}: P1TEST10 did not apply (${JSON.stringify(coupon)})`);
  step(`coupon P1TEST10 applied (${coupon.msg || 'discount row shown'})`);
}

/** Bump the cart quantity until the subtotal clears the store's minimum order. */
async function ensureSheetMinOrder(page, c, theme, sleep, step) {
  const min = await page.evaluate((g) => Number((window[g] || {}).minOrderValue) || 0, c.globalName);
  if (!(min > 0)) return;
  const readSubtotal = () => page.evaluate((sel) => {
    const box = document.querySelector(sel);
    if (!box) return '';
    const row = box.firstElementChild;
    if (!row) return '';
    const spans = row.querySelectorAll('span');
    return spans.length ? spans[spans.length - 1].textContent : row.textContent;
  }, c.totalsBox);
  for (let i = 0; i < 30; i += 1) {
    const sub = money(await readSubtotal());
    if (!Number.isNaN(sub) && sub >= min) { step(`min-order satisfied (subtotal ${sub} ≥ ${min})`); return; }
    await clickFirst(page, c.cartQtyPlus, `${theme} qty bump for min-order`).catch(() => {});
    await sleep(250);
  }
  step(`min-order bump loop exhausted (min=${min}) — proceeding`);
}

// ── the ff-/v- form-checkout flow (feast / velvet) ───────────────────────────

async function runFfForm(theme, c, ctx) {
  const { page, shot, sleep } = ctx;
  const slug = `${theme}-demo`;
  const steps = [];
  const step = (s) => { steps.push(s); ctx.log(`  a-order ${theme} · ${s}`); };

  await openHomeShell(theme, c, slug, ctx, step);

  const q = await exerciseSearch(page, c, theme, sleep);
  step(`search returned results for "${q}"`);

  const target = await findTargetItem(page, c);
  if (!target) throw new Error(`${theme}: no item with a required modifier group in window.${c.globalName}.items`);

  // open the item's detail drawer via its grid card (fallback: the ?item= deeplink)
  let opened = await page.evaluate((cardClass, id) => {
    const card = document.querySelector(`${cardClass}[data-id="${id}"]`);
    if (!card) return false;
    card.scrollIntoView({ block: 'center' });
    card.click();
    return true;
  }, c.cardClass, target.id);
  if (!opened) {
    await page.goto(localeUrl('/' + slug + '?item=' + target.id), { waitUntil: 'networkidle2' });
    opened = true;
  }
  await sleep(500);
  if (!(await isVisible(page, c.productView))) throw new Error(`${theme}: detail drawer did not open for item ${target.id}`);
  step(`opened "${target.name}" (#${target.id}) with ${target.requiredGroups.length} required group(s)`);

  // satisfy: the variant's first radio is pre-checked in markup; for every
  // required option group pick its first `need = max(1, min)` `[data-group]` options.
  const satisfied = await page.evaluate((glob, id) => {
    const it = ((window[glob] && window[glob].items) || {})[id];
    if (!it) return { groups: 0 };
    let groups = 0;
    (it.optionGroups || []).forEach((g) => {
      if (!g.required) return;
      const need = Math.max(1, Number(g.min) || 0);
      const opts = Array.from(document.querySelectorAll(`[data-group="${g.id}"][data-opt]`));
      let picked = 0;
      for (const o of opts) { if (picked >= need) break; if (!o.checked) o.click(); picked += 1; }
      if (!opts.some((o) => o.checked) && opts[0]) opts[0].click();  // radio pre-check safety net
      groups += 1;
    });
    return { groups };
  }, c.globalName, target.id);
  step(`satisfied required groups (choice=${satisfied.groups})`);
  await shot(page, `a-order-${theme}-2-item`);

  await clickFirst(page, c.add, `${theme} add-to-cart`);
  await sleep(600);
  const badge = await page.evaluate((sel) => {
    const el = document.querySelector(sel);
    return el ? { text: (el.textContent || '').trim() } : null;
  }, c.cartBadge);
  if (!badge || !badge.text || parseInt(normalizeDigits(badge.text), 10) < 1) {
    throw new Error(`${theme}: cart badge did not increment after add-to-cart (${JSON.stringify(badge)})`);
  }
  step(`added to cart (badge=${badge.text})`);

  // open the cart drawer — the checkout form lives inside it
  await clickFirst(page, c.cartToggle, `${theme} cart toggle`);
  await sleep(500);
  if (!(await isVisible(page, c.checkoutForm))) throw new Error(`${theme}: checkout form not visible in the cart drawer`);

  const before = await page.evaluate((sel) => { const o = document.querySelector(sel); return o ? (o.textContent || '').trim() : null; }, c.cartQtyOut);
  await clickFirst(page, c.cartQtyPlus, `${theme} qty +`);
  await sleep(400);
  const after = await page.evaluate((sel) => { const o = document.querySelector(sel); return o ? (o.textContent || '').trim() : null; }, c.cartQtyOut);
  if (!after || after === before) throw new Error(`${theme}: quantity did not change (before=${before} after=${after})`);
  step(`quantity changed ${before} → ${after}`);

  await applyCouponFf(page, c, theme, sleep, step);
  await shot(page, `a-order-${theme}-3-cart`);

  // exercise every order type the theme exposes — assert the field-block toggles
  const types = await page.evaluate((seg, otAttr) => Array.from(document.querySelectorAll(`${seg}[${otAttr}]`)).map((b) => b.getAttribute(otAttr)), c.orderTabSel, c.otAttr);
  const clickType = (t) => clickFirst(page, `${c.orderTabSel}[${c.otAttr}="${t}"]`, `${theme} order-type ${t}`);
  const exercised = [];
  if (types.includes('takeaway')) {
    await clickType('takeaway'); await sleep(300);
    const t = await ffOrderTypeFields(page, c);
    if (!t.phone || t.addr || t.table) throw new Error(`${theme}: pickup should show phone only (${JSON.stringify(t)})`);
    exercised.push('pickup(phone)');
  }
  if (types.includes('on-table')) {
    await clickType('on-table'); await sleep(300);
    const t = await ffOrderTypeFields(page, c);
    if (!t.table || t.addr) throw new Error(`${theme}: dine-in should show the table field, not the address (${JSON.stringify(t)})`);
    await page.evaluate((sel) => { const e = document.querySelector(sel); if (e && !e.readOnly) { e.value = '7'; e.dispatchEvent(new Event('input', { bubbles: true })); } }, c.table);
    exercised.push('dine-in(table)');
  }
  await clickType('delivery'); await sleep(300);
  if (!(await shown(page, c.addrBlock))) throw new Error(`${theme}: delivery did not reveal the address block`);
  exercised.push('delivery(address)');
  step(`order types exercised: ${exercised.join(', ')}`);

  // fill contact + delivery address, then confirm the cash tender is selected
  await page.evaluate((nameSel, phoneSel, addrSel) => {
    const set = (sel, v) => { const e = document.querySelector(sel); if (e) { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); } };
    set(nameSel, 'E2E Diner'); set(phoneSel, '+966512345678'); set(addrSel, '12 King Fahd Road, Riyadh');
  }, c.name, c.phone, c.addr);
  const pay = await page.evaluate((sel) => {
    const r = document.querySelector(sel);
    if (!r) return { present: false };
    if (!r.checked) { r.checked = true; r.click(); r.dispatchEvent(new Event('change', { bubbles: true })); }
    return { present: true, on: r.checked };
  }, c.payRadio);
  if (!pay.present) throw new Error(`${theme}: no cash / pay-on-counter tender`);
  step('checkout: name/phone/address filled, cash tender selected');

  const { res, displayedNum } = await placeOrder(theme, c, target, ctx, step);
  await assertOrderTrackReview(theme, c, slug, res, displayedNum, ctx, step);

  await assertNoOverflow(page);
  await assertNoConsoleErrors(page);
  step('no horizontal overflow, no console/CSP errors');
  return steps.length;
}

/** ff/v order-type field visibility triple (the `#…-group-*` blocks toggle display). */
async function ffOrderTypeFields(page, c) {
  const [phone, addr, table] = await Promise.all([
    shown(page, c.phoneBlock), shown(page, c.addrBlock), shown(page, c.tableBlock),
  ]);
  return { phone, addr, table };
}

/** Apply P1TEST10 on an ff/v theme; poll the coupon message class + discount row. */
async function applyCouponFf(page, c, theme, sleep, step) {
  const applyCoupon = () => page.evaluate((inSel, applySel) => {
    const i = document.querySelector(inSel);
    if (i) { i.value = 'P1TEST10'; i.dispatchEvent(new Event('input', { bubbles: true })); }
    const a = document.querySelector(applySel); if (a) a.click();
  }, c.couponInput, c.couponApply);
  const readCoupon = () => page.evaluate((msgSel, okCls, errCls, discSel) => {
    const m = document.querySelector(msgSel);
    const cls = m ? m.className : '';
    const disc = document.querySelector(discSel);
    const discShown = !!(disc && getComputedStyle(disc).display !== 'none' && (disc.textContent || '').trim());
    return { ok: cls.indexOf(okCls) >= 0, err: cls.indexOf(errCls) >= 0, discShown, msg: m ? (m.textContent || '').replace(/\s+/g, ' ').trim() : '' };
  }, c.couponMsg, c.couponOkClass, c.couponErrClass, c.discountRow);

  await applyCoupon();
  let coupon = { ok: false, err: false, discShown: false, msg: '' };
  const applied = (s) => s.ok && s.discShown;
  for (let attempt = 0; attempt < 40; attempt += 1) {
    await sleep(300);
    coupon = await readCoupon();
    if (applied(coupon)) break;
    if (coupon.err) break;
    if (attempt === 12 && !applied(coupon)) await applyCoupon();
  }
  if (!applied(coupon)) throw new Error(`${theme}: P1TEST10 did not apply a discount (${JSON.stringify(coupon)})`);
  step(`coupon P1TEST10 applied (${coupon.msg || 'discount row shown'})`);
}

// ── the data-view SPA flow (dough / plate) ───────────────────────────────────

async function runSpa(theme, c, ctx) {
  const { page, shot, sleep } = ctx;
  const slug = `${theme}-demo`;
  const steps = [];
  const step = (s) => { steps.push(s); ctx.log(`  a-order ${theme} · ${s}`); };

  await openHomeShell(theme, c, slug, ctx, step);

  const q = await exerciseSearch(page, c, theme, sleep);
  step(`search returned results for "${q}"`);

  const target = await findTargetItem(page, c);
  if (!target) throw new Error(`${theme}: no item with a required modifier group in window.${c.globalName}.items`);

  let opened = await page.evaluate((id, openAttr) => {
    const card = document.querySelector(`[${openAttr}="${id}"]`);
    if (!card) return false;
    card.scrollIntoView({ block: 'center' });
    card.click();
    return true;
  }, target.id, c.openAttr);
  if (!opened) {
    await page.goto(localeUrl('/' + slug + '?item=' + target.id), { waitUntil: 'networkidle2' });
    opened = true;
  }
  await sleep(600);
  if (!(await isVisible(page, c.productView))) throw new Error(`${theme}: item view did not open for item ${target.id}`);
  step(`opened "${target.name}" (#${target.id}) with ${target.requiredGroups.length} required group(s)`);

  // satisfy: variants auto-select their first option; for each required group
  // click its first single-select `[data-mod]` chip inside the item view.
  const satisfied = await page.evaluate((viewSel, gids) => {
    // dough/plate expose a single-select `[data-mod="<gid>"]` chip per option;
    // lime/picnic/orbit/velora wrap the group in `.opt-group[data-group="<gid>"]`
    // and index its children with `[data-opt]` (picnic's ids already carry a `g`
    // prefix, the rest are bare numeric ids — hence the `g${gid}` fallback).
    // crumb/soiree/scoop/ember put `data-group` AND `data-opt` on the SAME `.opt`
    // element, so try the same-element form (no descendant space) first.
    const pick = (gid) => document.querySelector(`${viewSel} [data-mod="${gid}"]`)
      || document.querySelector(`[data-mod="${gid}"]`)
      || document.querySelector(`${viewSel} [data-group="${gid}"][data-opt]`)
      || document.querySelector(`[data-group="${gid}"][data-opt]`)
      || document.querySelector(`${viewSel} [data-group="${gid}"] [data-opt]`)
      || document.querySelector(`[data-group="${gid}"] [data-opt]`)
      || document.querySelector(`${viewSel} [data-group="g${gid}"][data-opt]`)
      || document.querySelector(`[data-group="g${gid}"][data-opt]`)
      || document.querySelector(`${viewSel} [data-group="g${gid}"] [data-opt]`)
      || document.querySelector(`[data-group="g${gid}"] [data-opt]`)
      // scoop wraps each group in `section[data-grp="g<gid>"]` with `.extra[data-opt]` children
      || document.querySelector(`${viewSel} [data-grp="g${gid}"] [data-opt]`)
      || document.querySelector(`[data-grp="g${gid}"] [data-opt]`)
      || document.querySelector(`${viewSel} [data-grp="${gid}"] [data-opt]`)
      || document.querySelector(`[data-grp="${gid}"] [data-opt]`);
    let groups = 0;
    gids.forEach((gid) => { const first = pick(gid); if (first) { first.click(); groups += 1; } });
    return { groups };
  }, c.productView, target.requiredGroups);
  step(`satisfied required groups (choice=${satisfied.groups})`);
  await shot(page, `a-order-${theme}-2-item`);

  await clickFirst(page, c.add, `${theme} add-to-cart`);
  await sleep(600);
  const badge = await page.evaluate((sel) => {
    const el = document.querySelector(sel);
    return el ? { hidden: el.hasAttribute('hidden'), text: (el.textContent || '').trim() } : null;
  }, c.cartBadge);
  if (!badge || badge.hidden || !badge.text || parseInt(normalizeDigits(badge.text), 10) < 1) {
    throw new Error(`${theme}: cart badge did not increment after add-to-cart (${JSON.stringify(badge)})`);
  }
  step(`added to cart (badge=${badge.text})`);

  // open the cart view (dough auto-navigates there after add; plate does not)
  if (!(await isVisible(page, c.cartView))) {
    await clickFirst(page, c.cartTab, `${theme} cart toggle`);
    await sleep(500);
  }
  if (!(await isVisible(page, c.cartView))) throw new Error(`${theme}: cart view not visible`);

  const before = await page.evaluate((sel) => { const o = document.querySelector(sel); return o ? (o.textContent || '').trim() : null; }, c.cartQtyOut);
  await clickFirst(page, c.cartQtyPlus, `${theme} qty +`);
  await sleep(400);
  const after = await page.evaluate((sel) => { const o = document.querySelector(sel); return o ? (o.textContent || '').trim() : null; }, c.cartQtyOut);
  if (!after || after === before) throw new Error(`${theme}: quantity did not change (before=${before} after=${after})`);
  step(`quantity changed ${before} → ${after}`);

  await applyCouponSpa(page, c, theme, sleep, step);
  await shot(page, `a-order-${theme}-3-cart`);

  // exercise every order type the theme exposes — assert the delivery/table field
  // toggles (both engines keep the order-type segment in the cart view)
  const types = await page.evaluate((seg, otAttr) => Array.from(document.querySelectorAll(`${seg} [${otAttr}]`)).map((b) => b.getAttribute(otAttr)), c.orderTypeSeg, c.otAttr);
  const clickType = (t) => clickFirst(page, `${c.orderTypeSeg} [${c.otAttr}="${t}"]`, `${theme} order-type ${t}`);
  // most of these themes say takeaway/on-table; picnic says pickup/dinein.
  const takeawayType = c.otTakeawayType || 'takeaway';
  const dineInType = c.otDineInType || 'on-table';
  const exercised = [];
  if (types.includes(takeawayType)) {
    await clickType(takeawayType); await sleep(350);
    if ((await shown(page, c.addrProbe)) || (await shown(page, c.tableProbe))) {
      throw new Error(`${theme}: pickup should show neither address nor table`);
    }
    exercised.push('pickup');
  }
  if (types.includes(dineInType)) {
    await clickType(dineInType); await sleep(350);
    if (await shown(page, c.addrProbe)) throw new Error(`${theme}: dine-in should not show the address field`);
    const fillTable = () => page.evaluate((sel) => {
      const e = document.querySelector(sel);
      if (e && !e.readOnly) { e.value = '7'; e.dispatchEvent(new Event('input', { bubbles: true })); }
    }, c.table);
    if (c.dineInNoTable) {
      // ember's dine-in is full waiter-service: it shows only an explanatory
      // hint ("your waiter brings it to the table") and collects no table number,
      // so there is no table field to assert — the address-absent check above is
      // the meaningful dine-in guarantee here.
      void 0;
    } else if (c.tableInCheckout) {
      // orbit has no table field in the CART (its dine-in card is a mode label
      // only) — the field lives on the CHECKOUT view, which is display-toggled by
      // renderCheckout(). Walk it the way a customer does: cart → checkout →
      // assert → back to the cart, so the order still goes out as delivery.
      await clickFirst(page, c.goCheckout, `${theme} go-checkout (dine-in probe)`);
      await sleep(500);
      if (!await shown(page, c.tableProbe)) throw new Error(`${theme}: dine-in did not reveal the table field on checkout (${c.tableProbe})`);
      if (await shown(page, c.addrProbe)) throw new Error(`${theme}: dine-in should not show the address field on checkout`);
      await fillTable();
      await clickFirst(page, c.cartBack, `${theme} back to cart`);
      await sleep(500);
      if (!(await isVisible(page, c.cartView))) throw new Error(`${theme}: could not get back to the cart from checkout`);
    } else {
      if (!(await shown(page, c.tableProbe))) throw new Error(`${theme}: dine-in should show the table field, not the address`);
      await fillTable();
    }
    exercised.push('dine-in(table)');
  }
  await clickType('delivery'); await sleep(350);
  if (!(await shown(page, c.addrProbe))) throw new Error(`${theme}: delivery did not reveal the address field`);
  if (c.addrPick) {
    // crumb collects the delivery address through a geocoding location sheet (no
    // plain text field): open the picker, type a real address, then select the
    // first geocode result so the order carries a resolved lat/lng.
    await clickFirst(page, c.addrPick.row, `${theme} open address picker`);
    await sleep(400);
    await page.evaluate((inSel) => {
      /* The suggestions come from the server's geocode proxy, which calls a public provider (Photon). A journey
         must not depend on a third party being up, rate-limit friendly and reachable from the runner, so answer
         that one endpoint locally with a fixed Riyadh result; everything else goes through untouched. */
      if (!window.__e2eGeoStub) {
        window.__e2eGeoStub = true;
        const realFetch = window.fetch;
        window.fetch = function (input, init) {
          const url = typeof input === 'string' ? input : (input && input.url) || '';
          if (/\/geo\/autocomplete\b/.test(url)) {
            return Promise.resolve(new Response(JSON.stringify({
              results: [{ label: 'King Fahd Road, Riyadh', lat: 24.7136, lng: 46.6753 }],
              attribution: '',
            }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
          }
          return realFetch.apply(this, arguments);
        };
      }
      const i = document.querySelector(inSel);
      if (i) {
        i.removeAttribute('readonly');
        i.focus();
        i.value = 'King Fahd Road, Riyadh';
        i.dispatchEvent(new Event('input', { bubbles: true }));
      }
    }, c.addrPick.input);
    await page.waitForFunction(
      (rs) => { const r = document.querySelector(rs); return r && r.querySelector('[data-lat], .loc-row, [data-address]'); },
      { timeout: 9000 }, c.addrPick.results,
    ).catch(() => {});
    await sleep(300);
    const picked = await page.evaluate((rs) => {
      const row = document.querySelector(`${rs} [data-lat]`) || document.querySelector(`${rs} .loc-row`) || document.querySelector(`${rs} [data-address]`);
      if (row) { row.click(); return true; }
      return false;
    }, c.addrPick.results);
    if (!picked) throw new Error(`${theme}: address picker returned no geocode result to select`);
    await sleep(500);
  } else if (c.addrFill) {
    await page.evaluate((sel) => { const e = document.querySelector(sel); if (e) { e.value = '12 King Fahd Road, Riyadh'; e.dispatchEvent(new Event('input', { bubbles: true })); } }, c.addrFill);
  }
  exercised.push('delivery(address)');
  step(`order types exercised: ${exercised.join(', ')}`);

  await clickFirst(page, c.goCheckout, `${theme} go-checkout`);
  await sleep(500);
  if (!(await isVisible(page, c.checkoutView))) throw new Error(`${theme}: checkout view not visible`);

  await page.evaluate((nameSel, phoneSel, addrSel) => {
    const set = (sel, v) => { if (!sel) return; const e = document.querySelector(sel); if (e) { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); } };
    set(nameSel, 'E2E Diner'); set(phoneSel, '+966512345678'); set(addrSel, '12 King Fahd Road, Riyadh');
  }, c.name, c.phone, c.addr);

  const pay = await page.evaluate((cashSel) => {
    const cash = document.querySelector(cashSel);
    return { present: !!cash, on: cash ? cash.classList.contains('is-on') : false };
  }, c.payCash);
  if (!pay.present) throw new Error(`${theme}: no cash / pay-on-counter tender`);
  if (!pay.on) await clickFirst(page, c.payCash, `${theme} cash tender`);
  step('checkout: name/phone/address filled, cash tender selected');

  const { res, displayedNum } = await placeOrder(theme, c, target, ctx, step);
  await assertOrderTrackReview(theme, c, slug, res, displayedNum, ctx, step);

  await assertNoOverflow(page);
  await assertNoConsoleErrors(page);
  step('no horizontal overflow, no console/CSP errors');
  return steps.length;
}

/** Apply P1TEST10 on a data-view SPA theme; poll for a visible discount row + a
 *  non-error coupon message. */
async function applyCouponSpa(page, c, theme, sleep, step) {
  const applyCoupon = () => page.evaluate((inSel, applySel) => {
    const i = document.querySelector(inSel);
    if (i) { i.value = 'P1TEST10'; i.dispatchEvent(new Event('input', { bubbles: true })); }
    const a = document.querySelector(applySel); if (a) a.click();
  }, c.couponInput, c.couponApply);
  const readCoupon = () => page.evaluate((msgSel, errCls, discSel) => {
    const m = document.querySelector(msgSel);
    const cls = m ? m.className : '';
    const discs = Array.from(document.querySelectorAll(discSel)).filter((e) => {
      const s = getComputedStyle(e); return s.display !== 'none' && s.visibility !== 'hidden' && (e.textContent || '').trim();
    });
    return { err: cls.indexOf(errCls) >= 0, discShown: discs.length > 0, msg: m ? (m.textContent || '').replace(/\s+/g, ' ').trim() : '' };
  }, c.couponMsg, c.couponErrClass, c.discountRow);

  await applyCoupon();
  let coupon = { err: false, discShown: false, msg: '' };
  const applied = (s) => s.discShown && !s.err;
  for (let attempt = 0; attempt < 40; attempt += 1) {
    await sleep(300);
    coupon = await readCoupon();
    if (applied(coupon)) break;
    if (coupon.err) break;
    if (attempt === 12 && !applied(coupon)) await applyCoupon();
  }
  if (!applied(coupon)) throw new Error(`${theme}: P1TEST10 did not apply a discount (${JSON.stringify(coupon)})`);
  step(`coupon P1TEST10 applied (${coupon.msg || 'discount row shown'})`);
}

// ── dispatch ─────────────────────────────────────────────────────────────────

async function runTemplate(theme, ctx) {
  const c = CONTRACTS[theme];
  if (!c) throw new Error(`no a-order contract for "${theme}" yet — A2–A5 owns this template`);
  if (c.family === 'ffform') return runFfForm(theme, c, ctx);
  if (c.family === 'spa') return runSpa(theme, c, ctx);
  return c.family === 'sheet' ? runSheet(theme, c, ctx) : runCv(theme, c, ctx);
}

module.exports = async function aOrder({ page, log, shot, sleep, clearServiceWorkers }) {
  await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 1 });

  // aurora/vivid alert() on a validation miss — auto-dismiss so a stray dialog
  // never blocks page.evaluate. (No dialog fires on the happy path.)
  const onDialog = (d) => { d.dismiss().catch(() => {}); };
  page.on('dialog', onDialog);

  const requested = (process.env.E2E_TEMPLATES || RETAINED.join(','))
    .split(',').map((t) => t.trim()).filter(Boolean);

  const passed = [];
  const failures = [];

  for (const theme of requested) {
    // Every theme checks a coupon and places an order from one IP: the per-minute limiters (coupon check 30/min,
    // order 10/min) are shared across the whole run, so give each theme a fresh window.
    resetRateLimits();
    try {
      const n = await runTemplate(theme, { page, log, shot, sleep, clearServiceWorkers });
      passed.push(theme);
      log(`a-order PASS ${theme} (${n} steps)`);
    } catch (err) {
      failures.push(`${theme}: ${err.message}`);
      log(`a-order FAIL ${theme}: ${err.message}`);
      await shot(page, `a-order-${theme}-FAIL`);
    }
  }

  page.off('dialog', onDialog);
  log(`a-order — PASS [${passed.join(', ') || 'none'}]${failures.length ? ` · FAIL ${failures.length}` : ''}`);
  if (failures.length) throw new Error('a-order failures:\n  - ' + failures.join('\n  - '));
  return { passed: passed.length };
};
