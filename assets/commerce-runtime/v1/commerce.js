// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Storefront commerce runtime — v1  (P2-8)
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
      if (v) parts.push(v.label);
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
      if (it && it.isSoldOut) el.setAttribute("disabled", "disabled");
    });
    renderCart();
  }

  function addLine(id, variantId, mods, extras, qty) {
    cart.push({ id: Number(id), variantId: variantId || 0, mods: mods || [], extras: extras || [], qty: qty || 1 });
    save();
    syncBindings();
  }
  function setQty(idx, qty) {
    if (!cart[idx]) return;
    cart[idx].qty = Math.max(0, qty);
    if (cart[idx].qty === 0) cart.splice(idx, 1);
    save();
    syncBindings();
  }

  /* ----- option sheet --------------------------------------------------- */
  function needsSheet(it) {
    return (it.variants && it.variants.length) || (it.extras && it.extras.length) || (it.groups && it.groups.length);
  }

  function openSheet(id) {
    var it = item(id);
    if (!it) return;
    if (!CFG.flags.allowOrder || it.isSoldOut) return;
    if (!needsSheet(it)) { addLine(id, 0, [], [], 1); return; }

    var mount = doc.querySelector("[data-mb-item-sheet-mount]");
    if (!mount) { addLine(id, 0, [], [], 1); return; }

    var selVariant = it.variants && it.variants.length ? it.variants[0].id : 0;
    var selExtras = {};
    var selMods = {}; // groupId -> [optIndex]

    function draw() {
      var h = '<div class="mb-sheet" role="dialog" aria-modal="true">';
      h += '<header class="mb-sheet-head"><b>' + esc(it.name) + "</b>" +
        '<button class="mb-sheet-close" data-mb-close>&times;</button></header>';
      h += '<div class="mb-sheet-body">';

      if (it.variants && it.variants.length) {
        h += '<div class="mb-opt-group">';
        it.variants.forEach(function (v) {
          h += '<label class="mb-opt"><input type="radio" name="mb-variant" value="' + v.id + '"' +
            (v.id === selVariant ? " checked" : "") + '><span>' + esc(v.label) + "</span>" +
            '<em>' + money(v.price) + "</em></label>";
        });
        h += "</div>";
      }
      (it.groups || []).forEach(function (g) {
        h += '<div class="mb-opt-group" data-group="' + g.id + '"><p class="mb-opt-title">' + esc(g.title) +
          (g.required ? ' <span class="mb-req">*</span>' : "") + "</p>";
        (g.options || []).forEach(function (o) {
          var multi = g.max !== 1;
          h += '<label class="mb-opt"><input type="' + (multi ? "checkbox" : "radio") + '" name="mb-g' + g.id +
            '" value="' + o.i + '"><span>' + esc(o.label) + "</span>" +
            (o.price ? "<em>+" + money(o.price) + "</em>" : "") + "</label>";
        });
        h += "</div>";
      });
      if (it.extras && it.extras.length) {
        h += '<div class="mb-opt-group"><p class="mb-opt-title">Extras</p>';
        it.extras.forEach(function (e) {
          h += '<label class="mb-opt"><input type="checkbox" name="mb-extra" value="' + e.id + '"' +
            (selExtras[e.id] ? " checked" : "") + '><span>' + esc(e.title) + "</span>" +
            "<em>+" + money(e.price) + "</em></label>";
        });
        h += "</div>";
      }
      h += "</div>";
      h += '<footer class="mb-sheet-foot"><button class="mb-sheet-add" data-mb-sheet-add><span>Add</span> · <span></span></button></footer>';
      h += "</div>";
      mount.innerHTML = h; translate(mount);
      mount.hidden = false;
      priceFoot();
    }

    function collect() {
      var mods = [];
      (it.groups || []).forEach(function (g) {
        all('input[name="mb-g' + g.id + '"]:checked', mount).forEach(function (inp) {
          var opt = (g.options || []).filter(function (o) { return String(o.i) === inp.value; })[0];
          mods.push({ groupId: g.id, optIndex: Number(inp.value), label: opt ? opt.label : "", price: opt ? opt.price : 0 });
        });
      });
      var extras = [];
      all('input[name="mb-extra"]:checked', mount).forEach(function (inp) {
        var ex = (it.extras || []).filter(function (e) { return String(e.id) === inp.value; })[0];
        if (ex) extras.push({ id: ex.id, title: ex.title, price: ex.price });
      });
      var vinp = mount.querySelector('input[name="mb-variant"]:checked');
      return { variantId: vinp ? Number(vinp.value) : 0, mods: mods, extras: extras };
    }

    function priceFoot() {
      var c = collect();
      // The price is the LAST span — the first one is the "Add" label.
      var span = mount.querySelector(".mb-sheet-add span:last-child");
      if (span) span.textContent = money(unitPrice(it, c.variantId, c.mods, c.extras));
    }

    mount.addEventListener("change", priceFoot);
    mount.addEventListener("click", function (ev) {
      if (ev.target.closest("[data-mb-close]")) { mount.hidden = true; mount.innerHTML = ""; return; }
      if (ev.target.closest("[data-mb-sheet-add]")) {
        var c = collect();
        var missing = (it.groups || []).some(function (g) {
          return g.required && all('input[name="mb-g' + g.id + '"]:checked', mount).length < Math.max(1, g.min);
        });
        if (missing) return;
        addLine(id, c.variantId, c.mods, c.extras, 1);
        mount.hidden = true;
        mount.innerHTML = "";
      }
    });
    draw();
  }

  /* ----- product view (P8-5) ------------------------------------------- *
   * A full-screen product screen (gallery + options + qty + related), rendered
   * into [data-mb-product-mount] and shown via the router. Falls back to the
   * option sheet for themes without a product view. Reuses the pricing helpers.
   *   <article data-mb-open="ID"> … </article>   (card tap opens the product)
   */
  var itemList = Object.keys(CFG.items).map(function (k) { return CFG.items[k]; });
  var pId = null, pQty = 1;

  function optionsMarkup(it, selVariant) {
    var h = "";
    if (it.variants && it.variants.length) {
      h += '<div class="mb-opt-group">';
      it.variants.forEach(function (v) {
        h += '<label class="mb-opt"><input type="radio" name="mb-variant" value="' + v.id + '"' +
          (v.id === selVariant ? " checked" : "") + '><span>' + esc(v.label) + "</span><em>" + money(v.price) + "</em></label>";
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
    var vinp = ctx.querySelector('input[name="mb-variant"]:checked');
    return { variantId: vinp ? Number(vinp.value) : 0, mods: mods, extras: extras };
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
    var selVariant = (it.variants && it.variants.length) ? it.variants[0].id : 0;

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
    if (it.isSoldOut) h += '<p class="mb-prod-stock">Sold out</p>';
    h += '<div class="mb-prod-opts">' + optionsMarkup(it, selVariant) + "</div>";

    var rel = itemList.filter(function (x) { return x.id !== it.id && !x.isSoldOut; }).slice(0, 8);
    if (rel.length) {
      h += '<div class="mb-prod-related"><p class="mb-opt-title">You might also like</p><div class="mb-rail">';
      rel.forEach(function (r) {
        h += '<button type="button" class="mb-rail-card" data-mb-open="' + r.id + '">' +
          (r.img ? '<img src="' + esc(r.img) + '" alt="">' : "") + "<b>" + esc(r.name) + "</b><span>" + money(r.price) + "</span></button>";
      });
      h += "</div></div>";
    }
    h += "</div>"; // body

    if (CFG.flags.allowOrder && !it.isSoldOut) {
      h += '<footer class="mb-prod-cta"><div class="mb-qty">' +
        '<button type="button" data-mb-qty="-1" aria-label="-">&minus;</button><output data-mb-qty-val>1</output>' +
        '<button type="button" data-mb-qty="1" aria-label="+">+</button></div>' +
        '<button type="button" class="mb-place" data-mb-prod-add><span>Add</span> &middot; <span data-mb-prod-total></span></button></footer>';
    }
    h += "</div>";
    mount.innerHTML = h; translate(mount);
    showView("product");
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
  function productSetQty(d) { pQty = Math.max(1, pQty + d); productRecalc(); }
  function productAdd() {
    var mount = doc.querySelector("[data-mb-product-mount]"), it = item(pId);
    if (!it || !mount || optionsMissing(mount, it)) return;
    var c = collectOptions(mount, it);
    addLine(pId, c.variantId, c.mods, c.extras, pQty);
    backView();
  }
  function productGallery(i) {
    var mount = doc.querySelector("[data-mb-product-mount]");
    if (!mount) return;
    all(".mb-prod-img", mount).forEach(function (img) { img.hidden = String(img.getAttribute("data-i")) !== String(i); });
    all("[data-mb-gallery-dot]", mount).forEach(function (d) { d.classList.toggle("is-on", d.getAttribute("data-mb-gallery-dot") === String(i)); });
  }

  /* ----- cart + checkout ------------------------------------------------ */
  function renderCart() {
    var mount = doc.querySelector("[data-mb-cart-mount]");
    if (!mount) return;
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
  }

  function checkoutForm(T) {
    var ot = CFG.orderTypes;
    var seg = "";
    if (ot.onTable) seg += '<button type="button" data-mb-ot="on-table"' + (orderType === "on-table" ? ' class="is-on"' : "") + ">Dine-in</button>";
    if (ot.takeaway) seg += '<button type="button" data-mb-ot="takeaway"' + (orderType === "takeaway" ? ' class="is-on"' : "") + ">Takeaway</button>";
    if (ot.delivery) seg += '<button type="button" data-mb-ot="delivery"' + (orderType === "delivery" ? ' class="is-on"' : "") + ">Delivery</button>";

    var h = '<form class="mb-checkout" data-mb-checkout-form>';
    h += '<div class="mb-ot-seg">' + seg + "</div>";
    h += '<input class="mb-field" name="name" placeholder="Your name" value="' + esc(CFG.flags.customerName || "") + '">';
    if (orderType === "on-table") {
      h += '<input class="mb-field" name="table" placeholder="Table" value="' + esc(CFG.flags.defaultTable || "") + '"' + (CFG.flags.forceTable ? " readonly" : "") + ">";
    } else {
      h += '<input class="mb-field" name="phone" placeholder="Phone">';
    }
    if (orderType === "delivery") h += '<input class="mb-field" name="address" placeholder="Delivery address">';
    if (CFG.flags.allowScheduling) h += '<input class="mb-field" type="datetime-local" name="scheduled_at">';
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
          /* v1 has no toast: the checkout form carries its own error line. */
          var formEl = doc.querySelector("[data-mb-checkout-form]");
          var errEl = formEl && formEl.querySelector("[data-mb-err]");
          if (errEl) { errEl.textContent = (res && res.message) || t("Invalid promo code."); errEl.hidden = false; }
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
    if (orderType === "delivery" && !((form.querySelector('[name="address"]') || {}).value || "").trim()) return fail(t("Please enter a delivery address."));
    if (CFG.flags.authMode === "required" && !CFG.flags.isLoggedIn) return fail(t("Please sign in to order."));

    var payload = orderLines();

    var fd = new FormData();
    fd.append("_token", CFG.csrf);
    fd.append("items", JSON.stringify(payload));
    fd.append("name", name);
    fd.append("ordering-type", orderType);
    if (orderType === "on-table") fd.append("table", ((form.querySelector('[name="table"]') || {}).value) || "");
    else fd.append("phone-number", ((form.querySelector('[name="phone"]') || {}).value) || "");
    if (orderType === "delivery") fd.append("address", ((form.querySelector('[name="address"]') || {}).value || "").trim());
    fd.append("pay_via", "pay_on_counter");
    fd.append("tip_amount", String(T.tip || 0));
    fd.append("message", ((form.querySelector('[name="message"]') || {}).value) || "");
    var sched = form.querySelector('[name="scheduled_at"]');
    if (sched && sched.value) fd.append("scheduled_at", sched.value);
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
          cart = []; coupon = null; tipPct = 0; tipCustom = null; save(); syncBindings();
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
    if (search) {
      var name = (el.getAttribute("data-mb-name") || "").toLowerCase();
      if (name.indexOf(search) === -1) return false;
    }
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

  /* ----- favourites view (fix) ----------------------------------------- *
   * Renders the saved items as cards into [data-mb-favs-mount]. Shares the
   * favs store with the home hearts, so toggling anywhere stays in sync.
   */
  function favCardHTML(it) {
    return '<article class="cl-card" data-mb-item="' + it.id + '" data-mb-open="' + it.id + '">' +
      '<div class="cl-card-media">' +
        (it.img ? '<img src="' + esc(it.img) + '" alt="" loading="lazy">' : "") +
        '<button class="cl-fav is-on" type="button" data-mb-fav="' + it.id + '" aria-pressed="true">♥</button>' +
      "</div>" +
      '<div class="cl-card-body"><h3 class="cl-card-name">' + esc(it.name) + "</h3>" +
        (it.rating ? '<span class="cl-card-rate">★ ' + it.rating + "</span>" : "") +
        '<div class="cl-card-foot"><b class="cl-price">' + money(it.price) + "</b>" +
        (CFG.flags.allowOrder && !it.isSoldOut ? '<button class="cl-add" type="button" data-mb-add="' + it.id + '" aria-label="Add">+</button>' : "") +
      "</div></div></article>";
  }
  function renderFavs() {
    var mount = doc.querySelector("[data-mb-favs-mount]");
    if (!mount) return;
    var ids = Object.keys(favs).filter(function (k) { return favs[k] && item(k); });
    if (!ids.length) {
      mount.innerHTML = '<div class="cl-empty"><p>Tap the heart on any dish to save it here</p>' +
        '<button type="button" class="cl-ghost" data-mb-go="home">Browse menu</button></div>';
      translate(mount);
      return;
    }
    mount.innerHTML = '<div class="cl-grid">' + ids.map(function (id) { return favCardHTML(item(id)); }).join("") + "</div>";
    translate(mount);
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
      h += '<p class="mb-acct-greet">Signed in as <b>' + esc(CFG.flags.customerName || "") + "</b></p>";
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
    mount.innerHTML = h; translate(mount);
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
    h += '<p class="mb-acct-greet">Signed in as <b>' + esc(c.name || "") + "</b></p>";
    h += '<p class="mb-err" data-mb-acct-err hidden></p>';

    h += '<b class="mb-acct-sec">Profile</b>';
    h += '<form data-mb-profile-form>' +
      '<input class="mb-field" name="name" placeholder="Name" value="' + esc(c.name || "") + '">' +
      '<input class="mb-field" name="email" type="email" placeholder="Email" value="' + esc(c.email || "") + '">' +
      '<input class="mb-field" name="phone" placeholder="Phone" value="' + esc(c.phone || "") + '">' +
      '<input class="mb-field" name="birthday" type="date" value="' + esc(c.birthday || "") + '">' +
      '<button type="submit" class="mb-place">Save profile</button></form>';

    h += '<b class="mb-acct-sec">Saved addresses</b>';
    h += '<ul class="mb-addr-list">';
    (d.addresses || []).forEach(function (a) {
      h += '<li class="mb-addr"><span>' + (a.label ? "<b>" + esc(a.label) + "</b> " : "") + esc(a.line) +
        (a.city ? ", " + esc(a.city) : "") + (a.is_default ? ' <em class="mb-addr-default">Default</em>' : "") + "</span>" +
        '<button type="button" class="mb-addr-del" data-mb-address-del="' + esc(a.delete_url) + '" aria-label="Remove">&times;</button></li>';
    });
    h += "</ul>";
    h += '<form data-mb-address-form class="mb-addr-form">' +
      '<input class="mb-field" name="label" placeholder="Label (Home, Work)">' +
      '<input class="mb-field" name="line" placeholder="Address" required>' +
      '<input class="mb-field" name="city" placeholder="City">' +
      '<label class="mb-addr-def"><input type="checkbox" name="is_default"> Set as default</label>' +
      '<button type="submit" class="mb-ghost">Add address</button></form>';

    h += '<b class="mb-acct-sec">Your orders</b>';
    acctNextBefore = d.next_before || null;
    if ((d.orders || []).length) {
      h += '<ul class="mb-acct-order-list">';
      d.orders.forEach(function (o) {
        h += '<li class="mb-acct-order">' + orderAnchor(o) + "</li>";
      });
      h += "</ul>";
      if (acctNextBefore) h += '<button type="button" class="mb-ghost" data-mb-acct-older>' + t("Load more") + "</button>";
    } else {
      h += '<p class="mb-acct-empty">No orders yet.</p>';
    }

    h += '<button type="button" class="mb-place mb-logout-btn" data-mb-logout>Log out</button>';
    h += "</div>";
    mount.innerHTML = h; translate(mount);
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
    var inc = t.closest("[data-mb-inc]"); if (inc) { var i = +inc.getAttribute("data-mb-inc"); setQty(i, cart[i].qty + 1); return; }
    var dec = t.closest("[data-mb-dec]"); if (dec) { var j = +dec.getAttribute("data-mb-dec"); setQty(j, cart[j].qty - 1); return; }
    var ot = t.closest("[data-mb-ot]"); if (ot) { orderType = ot.getAttribute("data-mb-ot"); renderCart(); return; }
    if (t.closest("[data-mb-coupon-apply]")) { var ci = doc.querySelector("[data-mb-coupon-input]"); applyCoupon(ci ? ci.value.trim() : ""); return; }
    var tp = t.closest("[data-mb-tip]"); if (tp) { tipPct = parseInt(tp.getAttribute("data-mb-tip"), 10) || 0; tipCustom = null; renderCart(); return; }
    if (t.closest("[data-mb-clear-cart]")) { cart = []; coupon = null; tipPct = 0; tipCustom = null; save(); syncBindings(); return; }
    var fav = t.closest("[data-mb-fav]"); if (fav) { ev.preventDefault(); var id = fav.getAttribute("data-mb-fav"); favs[id] = !favs[id]; saveFavs(); applyCatalog(); if (activeView === "favs") renderFavs(); return; }
    var open = t.closest("[data-mb-open]"); if (open) { ev.preventDefault(); openProduct(open.getAttribute("data-mb-open")); return; }
    var flt = t.closest("[data-mb-filter]"); if (flt) { filter = flt.getAttribute("data-mb-filter"); applyCatalog(); return; }
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
    var atab = t.closest("[data-mb-acct-tab]"); if (atab) { acctTab = atab.getAttribute("data-mb-acct-tab"); var m2 = doc.querySelector("[data-mb-account-mount]"); if (m2) drawAccount(m2); return; }
    if (t.closest("[data-mb-logout]")) { ev.preventDefault(); logout(); return; }
    var otp = t.closest("[data-mb-otp-request]"); if (otp) { ev.preventDefault(); var of = otp.closest("form"); if (of) requestOtp(of); return; }
    var adel = t.closest("[data-mb-address-del]"); if (adel) { ev.preventDefault(); deleteAddress(adel.getAttribute("data-mb-address-del")); return; }
    if (t.closest("[data-mb-acct-older]")) { ev.preventDefault(); loadOlderOrders(); return; }
  });
  doc.addEventListener("input", function (ev) {
    if (ev.target.matches("[data-mb-search]")) { search = (ev.target.value || "").trim().toLowerCase(); applyCatalog(); }
  });
  doc.addEventListener("change", function (ev) {
    if (ev.target.matches("[data-mb-sort]")) { sortMode = ev.target.value || "featured"; applySort(); return; }
    if (ev.target.matches("[data-mb-tip-custom]")) { var tv = parseFloat(ev.target.value); tipCustom = isNaN(tv) ? null : Math.max(0, tv); renderCart(); return; }
    if (ev.target.closest && ev.target.closest("[data-mb-product-mount]")) productRecalc();
  });
  doc.addEventListener("submit", function (ev) {
    if (ev.target.matches("[data-mb-checkout-form]")) { ev.preventDefault(); placeOrder(ev.target); return; }
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

  function init() { syncBindings(); applyCatalog(); applySort(); initSliders(); initRouter(); initSplash(); }
  if (doc.readyState !== "loading") init();
  else doc.addEventListener("DOMContentLoaded", init);
  window.MBCommerce = { cart: function () { return cart; }, totals: totals, refresh: init };
})();
