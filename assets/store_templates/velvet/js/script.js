// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Velvet QR Menu Theme Javascript Controller
 */

(function () {
    'use strict';

    // State management
    const config = window.VELVET;
    if (!config) return;

    const storageKey = `v_cart_${config.storeId}`;
    let cart = loadCart();
    let currentDetailItem = null;
    let currentDetailQty = 1;

    /* coupon (crave contract: {code,type,value}) + tips */
    let coupon = null;
    let tipPct = 0;
    let tipCustom = 0;

    /* MC · display-currency switch (#curSeg). curRate multiplies base prices for
       display; curCode drives the per-code decimals and the ≈ estimate prefix on
       non-base codes. Defaults keep every price bit-identical to the server render. */
    let curCode = config.baseCurrency || '';
    let curRate = 1;

    /* two-tier nav + dietary filters — S composes every visible-items rule */
    const S = {
        cat: 'all',
        sub: 'root',
        q: '',
        filters: { diets: new Set(), allergens: new Set(), spice: 0 }
    };

    // Cache DOM Elements
    const elements = {
        app: document.getElementById('velvet-app'),
        searchInput: document.getElementById('v-search-input'),
        searchClear: document.getElementById('v-search-clear'),
        catButtons: document.querySelectorAll('.v-cat-btn'),
        menuSections: document.querySelectorAll('.v-menu-section'),
        menuCards: document.querySelectorAll('.v-menu-card'),
        
        detailDrawer: document.getElementById('v-detail-drawer'),
        detailContent: document.getElementById('v-detail-content'),
        detailClose: document.getElementById('v-detail-close'),
        detailBackdrop: document.getElementById('v-detail-backdrop'),
        
        cartToggle: document.getElementById('v-cart-toggle'),
        cartBadge: document.querySelector('.v-cart-badge'),
        cartTotalPrice: document.querySelector('.v-cart-btn-price'),
        
        cartDrawer: document.getElementById('v-cart-drawer'),
        cartBackdrop: document.getElementById('v-cart-backdrop'),
        cartClose: document.getElementById('v-cart-close'),
        cartItemsList: document.getElementById('v-cart-items-list'),
        
        couponCodeInput: document.getElementById('v-coupon-code'),
        couponApplyBtn: document.getElementById('v-coupon-apply-btn'),
        couponMsg: document.getElementById('v-coupon-msg'),

        sumSubtotal: document.getElementById('v-sum-subtotal'),
        sumDelivery: document.getElementById('v-sum-delivery'),
        sumTax: document.getElementById('v-sum-tax'),
        taxRow: document.getElementById('v-tax-row'),
        sumDiscount: document.getElementById('v-sum-discount'),
        sumTip: document.getElementById('v-sum-tip'),
        tipSummaryRow: document.getElementById('v-tip-summary-row'),
        sumTotal: document.getElementById('v-sum-total'),

        subcatsNav: document.getElementById('v-subcats'),
        specialCards: document.querySelectorAll('.v-special-card'),
        
        checkoutForm: document.getElementById('v-checkout-form'),
        orderTypeTabs: document.querySelectorAll('.v-order-type-tabs .v-tab-btn'),
        orderTypeInput: document.getElementById('v-order-type-input'),
        groupTable: document.getElementById('v-group-table'),
        groupPhone: document.getElementById('v-group-phone'),
        groupAddress: document.getElementById('v-group-address'),
        deliveryRow: document.getElementById('v-delivery-row'),
        discountRow: document.getElementById('v-discount-row'),
        checkoutErrors: document.getElementById('v-checkout-errors'),
        submitOrderBtn: document.getElementById('v-submit-order-btn')
    };

    // Initialize
    init();

    function init() {
        bindEvents();
        pickupReorder();
        updateCartUI();
        initDefaultOrderType();
        applyFilters();
    }

    /**
     * Reorder handoff from the account page — a DEAD CONTROL until now.
     * account.blade.php POSTs to customer.orders.reorder, stashes the returned
     * lines under `v_reorder_<slug>` and redirects here; nothing in this file
     * ever read that key, so "Reorder" walked the customer back to the menu
     * with an empty cart and no explanation. Lines are rebuilt against the
     * live catalogue (prices and titles come from config.items, never from the
     * stashed payload) so a since-changed price cannot be resurrected, and a
     * line whose item is gone or sold out is skipped rather than poisoning the
     * cart with an unorderable row.
     */
    function pickupReorder() {
        const key = 'v_reorder_' + (config.restaurantSlug || config.storeId);
        let raw = null;
        try { raw = JSON.parse(localStorage.getItem(key)); } catch (e) { raw = null; }
        if (!raw || !raw.length) return;
        localStorage.removeItem(key);

        let added = 0;
        raw.forEach(function (line) {
            const base = config.items[line.id];
            if (!base || base.is_sold_out) return;

            const variantId = Number(line.variantId || line.variants || 0) || 0;
            const variant = variantId ? (base.variants || []).find(v => v.id === variantId) : null;

            const extras = (line.extras || []).map(function (e) {
                const match = (base.extras || []).find(x => x.id === Number(e.id || e));
                return match ? { id: match.id, price: match.price } : null;
            }).filter(Boolean);

            addToCartInternal({
                id: base.id,
                quantity: Math.max(1, Number(line.quantity || line.qty || 1)),
                variants: variant ? variant.id : 0,
                extras: extras,
                mods: [],
                price: variant ? variant.price : base.price,
                name: base.name
            });
            added++;
        });

        if (added) {
            openCartDrawer();
            toast(config.lang.addedToCart || config.lang.added);
        }
    }

    // Cart Storage
    function loadCart() {
        try {
            return JSON.parse(localStorage.getItem(storageKey)) || [];
        } catch (e) {
            return [];
        }
    }

    function saveCart() {
        localStorage.setItem(storageKey, JSON.stringify(cart));
        updateCartUI();
    }

    function clearCart() {
        cart = [];
        saveCart();
    }

    /**
     * Tax, reproducing Order::computeTax() EXACTLY.
     *
     *   - computed on the RAW subtotal, before any discount, and the discount is
     *     never taxed (TOTALS-CONTRACT trap #1);
     *   - `restaurant_tax_type` has two modes: 'percentage' multiplies, 'fixed'
     *     is a FLAT amount and must not be multiplied (trap #3);
     *   - rounded to the RESTAURANT's currency decimals, not a hardcoded 2
     *     (trap #2);
     *   - the same guard as the server: a non-positive rate or a non-positive
     *     subtotal is exactly 0.
     */
    function decimals() {
        // bug batch 10 · 4: order arithmetic at the stored precision (config.moneyDecimals); display keeps currencyDecimals
        const d = Number(config.moneyDecimals ?? config.currencyDecimals);
        return Number.isFinite(d) ? d : 2;
    }
    /* M16: delegates to the shared money core (drops the EPSILON approximation
       for PHP-exact rounding). */
    function roundTo(n, d) { return mbMoney.round(n, d); }
    /**
     * The tip, rounded ONCE to the store's currency decimals. Both the summary
     * row and the `tip_amount` field posted to /send-order read this, so the
     * number quoted is bit-identical to the number the server adds — a 15% tip
     * on $9.50 is 1.425, and displaying 1.43 while posting 1.425 would have the
     * receipt disagree with the charge by a cent.
     */
    function tipAmount(subtotal) {
        return roundTo(tipPct ? subtotal * tipPct / 100 : (Number(tipCustom) || 0), decimals());
    }
    function computeTax(subtotal) {
        const charge = Number(config.taxCharge) || 0;
        if (charge <= 0 || subtotal <= 0) return 0;
        if (config.taxType === 'fixed') return roundTo(charge, decimals());
        return roundTo(subtotal * (charge / 100), decimals());
    }

    /* Server messages arrive as HTML (validation errors are joined with `<br>`).
       Nothing from the network is ever assigned to innerHTML in this theme, so
       flatten the markup to text instead of rendering it. */
    function stripTags(s) {
        return String(s == null ? '' : s)
            .replace(/<br\s*\/?>/gi, ' · ')
            .replace(/<[^>]*>/g, '')
            .trim();
    }

    // Formatting Helpers
    /* Decimals follow the DISPLAYED currency, not the store's: an OMR store viewed in
       USD needs 2, not 3. decimalsByCode lists every non-2 code; an absent code is 2. */
    function moneyDp(code) {
        var dp = code === config.baseCurrency ? config.currencyDecimals : (config.decimalsByCode || {})[code];
        dp = Number(dp);
        return isFinite(dp) ? dp : 2;
    }
    function formatPrice(amount) {
        const v = (Number(amount) || 0) * curRate;
        // MC-34: non-base codes are a converted ESTIMATE — prefix ≈ and name the code,
        // never the store sign, so a shopper never reads it as the exact charge.
        if (curCode && curCode !== config.baseCurrency) {
            return `≈ ${curCode} ${v.toFixed(moneyDp(curCode))}`;
        }
        const formatted = v.toFixed(config.currencyDecimals ?? 2);
        if (config.currencyPos === '1') {
            return `${config.currencySign}${config.currencyGap || ''}${formatted}`;
        }
        return `${formatted} ${config.currencySign}`;
    }

    /* Apply a chosen display currency across every price surface:
         - server-rendered menu grid + specials repaint via their [data-base-price]
           raw value (aurora DOM-scan pattern), since those cards are static HTML;
         - the cart FAB, cart items and checkout totals repaint via updateCartUI();
         - an open detail sheet is rebuilt so its variant/extra/option prices convert.
       Totals math is unchanged — currency is display-only (TOTALS-CONTRACT). */
    function applyCurrency(code, rate) {
        curCode = code || config.baseCurrency || '';
        curRate = Number(rate) || 1;
        const label = document.getElementById('v-cur-label');
        if (label) label.textContent = curCode;
        document.querySelectorAll('[data-base-price]').forEach(function (el) {
            const base = parseFloat(el.dataset.basePrice);
            if (isFinite(base)) el.textContent = formatPrice(base);
        });
        updateCartUI();
        if (currentDetailItem && elements.detailDrawer &&
            elements.detailDrawer.getAttribute('aria-hidden') === 'false') {
            openDetailDrawer(currentDetailItem.id);
        }
    }

    // Event Bindings
    function bindEvents() {
        // Search & Filter
        if (elements.searchInput) {
            elements.searchInput.addEventListener('input', handleSearch);
            elements.searchClear.addEventListener('click', () => {
                elements.searchInput.value = '';
                elements.searchClear.style.display = 'none';
                handleSearch();
            });
        }

        // Two-tier Category Navigation (parent chips filter, sub chips narrow)
        elements.catButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const cat = btn.getAttribute('data-cat');
                S.cat = cat;
                S.sub = 'root';
                elements.catButtons.forEach(b => b.classList.toggle('active', b === btn));
                syncSubChips(cat);
                applyFilters();
                // Bring the menu just below the sticky nav into view
                const main = document.querySelector('.v-menu-main');
                if (main) {
                    const topOffset = main.getBoundingClientRect().top + window.scrollY - 120;
                    window.scrollTo({ top: Math.max(0, topOffset), behavior: 'smooth' });
                }
            });
        });

        // Sub-category chips (event delegation — survives the hidden groups)
        if (elements.subcatsNav) {
            elements.subcatsNav.addEventListener('click', (e) => {
                const b = e.target.closest('.v-subcat');
                if (!b) return;
                const group = b.closest('.v-subgroup');
                group.querySelectorAll('.v-subcat').forEach(s => s.classList.toggle('active', s === b));
                S.sub = b.getAttribute('data-sub');
                applyFilters();
            });
        }

        // Daily-specials rail cards open the detail drawer
        elements.specialCards.forEach(card => {
            const openIt = () => openDetailDrawer(card.getAttribute('data-id'));
            card.addEventListener('click', openIt);
            card.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openIt(); }
            });
        });

        const emptyReset = document.getElementById('v-empty-reset');
        if (emptyReset) emptyReset.addEventListener('click', resetAllFilters);

        // Dietary filter drawer
        bindDietaryFilters();

        // Tips
        bindTips();

        // Call waiter
        bindCallWaiter();

        // Splash dismiss
        bindSplash();

        // Menu Cards click for details
        elements.menuCards.forEach(card => {
            card.addEventListener('click', (e) => {
                // If clicked on quick-add button, don't open detail
                if (e.target.closest('.v-quick-add-btn')) return;
                const itemId = card.getAttribute('data-id');
                openDetailDrawer(itemId);
            });
            card.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    if (e.target.closest('.v-quick-add-btn')) return;
                    openDetailDrawer(card.getAttribute('data-id'));
                }
            });
        });

        // Quick add button click
        document.querySelectorAll('.v-quick-add-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const itemId = btn.getAttribute('data-add-id');
                quickAdd(itemId, btn);
            });
        });

        // Drawer Close Handlers
        if (elements.detailClose) elements.detailClose.addEventListener('click', closeDetailDrawer);
        if (elements.detailBackdrop) elements.detailBackdrop.addEventListener('click', closeDetailDrawer);
        if (elements.cartClose) elements.cartClose.addEventListener('click', closeCartDrawer);
        if (elements.cartBackdrop) elements.cartBackdrop.addEventListener('click', closeCartDrawer);

        // Cart floating toggle
        if (elements.cartToggle) {
            elements.cartToggle.addEventListener('click', openCartDrawer);
        }

        // Order Type Tab selection
        elements.orderTypeTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                setOrderType(tab.getAttribute('data-type'));
            });
        });

        // Coupon application
        if (elements.couponApplyBtn) {
            elements.couponApplyBtn.addEventListener('click', handleCouponApply);
        }

        // Checkout submit
        if (elements.checkoutForm) {
            elements.checkoutForm.addEventListener('submit', handleCheckoutSubmit);
        }

        // Escape key to close drawers
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeDetailDrawer();
                closeCartDrawer();
            }
        });
    }

    // Search feeds the shared filter engine
    function handleSearch() {
        S.q = elements.searchInput.value.toLowerCase().trim();
        elements.searchClear.style.display = S.q.length > 0 ? 'block' : 'none';
        applyFilters();
    }

    /* ── shared visible-items filter — composes two-tier nav (root/cat) + search +
       dietary/spice/allergen filters. Every input calls this single entry point. ── */
    function matchesDietary(i) {
        const f = S.filters;
        if (f.diets.has('vegan') && !i.is_vegan) return false;
        if (f.diets.has('halal') && !i.is_halal) return false;
        if (f.diets.has('gluten_free') && !i.is_gluten_free) return false;
        if (f.spice && (Number(i.spice_level) || 0) < f.spice) return false;
        if (f.allergens.size) {
            const ids = (i.allergies || []).map(a => String(a.id));
            for (const al of f.allergens) { if (ids.indexOf(String(al)) !== -1) return false; }
        }
        return true;
    }

    function cardVisible(card) {
        const item = config.items[card.getAttribute('data-id')];
        const root = card.getAttribute('data-root');
        const cat = card.getAttribute('data-cat');
        if (S.cat !== 'all' && root !== S.cat) return false;
        if (S.sub !== 'root' && cat !== S.sub) return false;
        if (S.q) {
            const nameMatch = item && item.name.toLowerCase().includes(S.q);
            const descMatch = item && item.desc.toLowerCase().includes(S.q);
            if (!nameMatch && !descMatch) return false;
        }
        if (item && !matchesDietary(item)) return false;
        return true;
    }

    function applyFilters() {
        let total = 0;
        elements.menuSections.forEach(section => {
            let visibleInSection = 0;
            section.querySelectorAll('.v-menu-card').forEach(card => {
                const show = cardVisible(card);
                card.style.display = show ? '' : 'none';
                if (show) { visibleInSection++; total++; }
            });
            section.style.display = visibleInSection > 0 ? '' : 'none';
        });
        const countEl = document.getElementById('v-filter-count');
        if (countEl) countEl.textContent = total ? '(' + total + ')' : '';
        // Filtering to zero must say so, not leave an unexplained blank page.
        const empty = document.getElementById('v-empty-results');
        if (empty) empty.hidden = total > 0;
        const specials = document.getElementById('v-specials-section');
        if (specials) specials.style.display = total > 0 ? '' : 'none';
        return total;
    }

    /* "Reset" on the empty state clears every narrowing input at once — search,
       parent/sub category and the dietary drawer — and puts the chips back in
       sync, so one tap always returns the full menu. */
    function resetAllFilters() {
        S.cat = 'all'; S.sub = 'root'; S.q = '';
        S.filters.diets.clear(); S.filters.allergens.clear(); S.filters.spice = 0;
        if (elements.searchInput) elements.searchInput.value = '';
        if (elements.searchClear) elements.searchClear.style.display = 'none';
        elements.catButtons.forEach(b => b.classList.toggle('active', b.getAttribute('data-cat') === 'all'));
        syncSubChips('all');
        document.querySelectorAll('#v-filter-drawer .v-chiprow button.active')
            .forEach(x => x.classList.remove('active'));
        const dot = document.getElementById('v-filter-dot');
        if (dot) dot.hidden = true;
        applyFilters();
    }

    // Reveal the active parent's sub-chip group ("All" resets the row).
    function syncSubChips(cat) {
        const nav = elements.subcatsNav;
        if (!nav) return;
        let anyShown = false;
        nav.querySelectorAll('.v-subgroup').forEach(g => {
            const on = cat !== 'all' && g.getAttribute('data-parent') === cat;
            g.hidden = !on;
            if (on) {
                anyShown = true;
                g.querySelectorAll('.v-subcat').forEach(s =>
                    s.classList.toggle('active', s.getAttribute('data-sub') === 'root'));
            }
        });
        nav.hidden = !anyShown;
    }

    // Running cart subtotal (items + extras + option-group mods)
    function cartSubtotal() {
        let subtotal = 0;
        cart.forEach(item => {
            let itemPrice = item.price;
            item.extras.forEach(e => { itemPrice += e.price; });
            (item.mods || []).forEach(m => { itemPrice += m.price; });
            subtotal += itemPrice * item.quantity;
        });
        /* Combos ride in the subtotal: the server taxes them, min-order counts them,
           and the coupon endpoint is quoted the same base the order will carry. */
        return subtotal + comboTotal();
    }

    /* COMBOS-V2 — the shared combos partial (_partials/combos.blade.php) keeps its
       own basket and publishes display state on window.mbCombos {count,total,lines}.
       The server prices combo lines into the ORDER subtotal (and taxes them like any
       other line), so the quote must fold them in too — QUOTED == CHARGED. Quantity
       edits stay in the combo sheet; here they render read-only. */
    function comboCount() { return (window.mbCombos && window.mbCombos.count) || 0; }
    function comboTotal() { return (window.mbCombos && window.mbCombos.total) || 0; }
    function comboLines() { return (window.mbCombos && window.mbCombos.lines) || []; }

    /* ── Dietary / spice / allergen filter drawer ── */
    function bindDietaryFilters() {
        const openBtn = document.getElementById('v-filter-btn');
        if (!openBtn) return;
        openBtn.addEventListener('click', () => openDrawer('v-filter-drawer'));
        bindDrawerClose('v-filter-drawer', 'v-filter-close', 'v-filter-backdrop');

        const dietRow = document.getElementById('v-diet-row');
        if (dietRow) dietRow.addEventListener('click', (e) => {
            const b = e.target.closest('[data-diet]'); if (!b) return;
            const v = b.getAttribute('data-diet');
            if (S.filters.diets.has(v)) S.filters.diets.delete(v); else S.filters.diets.add(v);
            b.classList.toggle('active');
            afterFilterChange();
        });
        const spiceRow = document.getElementById('v-spice-row');
        if (spiceRow) spiceRow.addEventListener('click', (e) => {
            const b = e.target.closest('[data-spice]'); if (!b) return;
            const n = Number(b.getAttribute('data-spice'));
            S.filters.spice = (S.filters.spice === n) ? 0 : n;
            spiceRow.querySelectorAll('button').forEach(x =>
                x.classList.toggle('active', Number(x.getAttribute('data-spice')) === S.filters.spice));
            afterFilterChange();
        });
        const alRow = document.getElementById('v-allergen-row');
        if (alRow) alRow.addEventListener('click', (e) => {
            const b = e.target.closest('[data-al]'); if (!b) return;
            const v = b.getAttribute('data-al');
            if (S.filters.allergens.has(v)) S.filters.allergens.delete(v); else S.filters.allergens.add(v);
            b.classList.toggle('active');
            afterFilterChange();
        });

        const done = document.getElementById('v-filter-done');
        if (done) done.addEventListener('click', () => { applyFilters(); closeDrawer('v-filter-drawer'); });
        const reset = document.getElementById('v-filter-reset');
        if (reset) reset.addEventListener('click', () => {
            S.filters.diets.clear(); S.filters.allergens.clear(); S.filters.spice = 0;
            document.querySelectorAll('#v-filter-drawer .v-chiprow button.active')
                .forEach(x => x.classList.remove('active'));
            afterFilterChange();
        });
    }

    function afterFilterChange() {
        const active = S.filters.diets.size + S.filters.allergens.size + (S.filters.spice ? 1 : 0);
        const dot = document.getElementById('v-filter-dot');
        if (dot) dot.hidden = active === 0;
        applyFilters();
    }

    /* ── Tips ── */
    function bindTips() {
        const row = document.getElementById('v-tip-btns');
        if (!row) return;
        row.addEventListener('click', (e) => {
            const b = e.target.closest('[data-tip]'); if (!b) return;
            row.querySelectorAll('button').forEach(x => x.classList.toggle('active', x === b));
            const custom = document.getElementById('v-custom-tip');
            if (b.getAttribute('data-tip') === 'custom') {
                tipPct = 0; tipCustom = Number(custom && custom.value) || 0;
                if (custom) { custom.hidden = false; }
            } else {
                tipPct = Number(b.getAttribute('data-tip')); tipCustom = 0;
                if (custom) custom.hidden = true;
            }
            updateCartUI();
        });
        const custom = document.getElementById('v-custom-tip');
        if (custom) custom.addEventListener('input', () => { tipCustom = Number(custom.value) || 0; updateCartUI(); });
    }

    /* ── Call waiter (dine-in) ── */
    function bindCallWaiter() {
        const btn = document.getElementById('v-call-waiter');
        if (!btn || !config.endpoints || !config.endpoints.waiter) return;
        /* §2c FABRICATION — this handler used to toast "A waiter will be with you
           shortly!" from a bare `.then()`, i.e. on ANY parsed response. The server
           validates `table` as required|integer, so with no table number (the
           common case: dine-in chosen by hand rather than resolved from a table QR)
           it answers {success:false,"The table field is required."} and writes NO
           WaiterCall row — while the diner was told a waiter was coming. Verified
           live before the fix: table:'' -> success:false, table:'5' -> success:true.
           Now the toast is bound to the server's own verdict, and the empty-table
           case is caught in the UI before the pointless round trip. */
        btn.addEventListener('click', () => {
            const tableEl = document.getElementById('v-table-num');
            const tbl = String((tableEl && tableEl.value) || config.defaultTable || '').trim();
            if (!tbl) {
                toast(config.lang.fieldRequired);
                if (tableEl) tableEl.focus();
                return;
            }
            btn.disabled = true;
            fetch(config.endpoints.waiter, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf },
                body: JSON.stringify({ table: tbl, type: 'call_waiter', _token: config.csrf })
            }).then(function (r) { return r.json(); }).then(function (d) {
                btn.disabled = false;
                if (d && d.success) {
                    toast(config.lang.waiterOk || 'A waiter will be with you shortly!');
                } else {
                    toast(stripTags((d && d.message) || config.lang.failed));
                }
            }).catch(function () {
                btn.disabled = false;
                toast(config.lang.networkError);
            });
        });
    }

    /* ── Splash dismiss (CSS auto-dismisses; skip is a progressive enhancement) ── */
    function bindSplash() {
        const splash = document.getElementById('v-splash');
        if (!splash) return;
        const hide = () => { splash.classList.add('v-splash-hidden'); };
        const skip = document.getElementById('v-splash-skip');
        if (skip) skip.addEventListener('click', hide);
        /* R2 · show the splash once per session. It replayed on every navigation
           (each page load re-ran the CSS reveal), so a diner tapping between the menu
           and a dish sat through the intro again and again. On a repeat view (the key
           is already set) dismiss it immediately — `.v-splash-hidden` sets
           `animation:none`, cancelling the CSS auto-play so it never flashes. */
        const splashKey = 'v_splash_' + (config.restaurantSlug || config.storeId);
        let splashSeen = false;
        try { splashSeen = !!sessionStorage.getItem(splashKey); } catch (e) {}
        if (splashSeen) { hide(); return; }
        /* A1: a ?item=/?table= deep link opens straight on the product — dismiss the
           splash up front so it never overlays it. Not persisted: a later plain
           visit this session still gets the intro. */
        let deepLinked = false;
        try { const dl = new URLSearchParams(location.search); deepLinked = !!(dl.get('item') || dl.get('table')); } catch (e) {}
        if (deepLinked) { hide(); return; }
        try { sessionStorage.setItem(splashKey, '1'); } catch (e) {}
        // Safety net if the CSS animation's forwards state is interrupted
        setTimeout(hide, 3200);
    }

    // Body scroll lock
    function setScrollLock(lock) {
        if (lock) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    }

    // Quick Add
    function quickAdd(itemId, btn) {
        const item = config.items[itemId];
        if (!item || item.is_sold_out) return;

        /* R1 · required-addon gate. Quick-add used to fence variants ONLY, so an item
           with a REQUIRED option group (a base burger with a mandatory "Choose a bun")
           dropped into the cart with nothing chosen — the detail sheet enforces those
           groups at handleDetailAddToCart, but the quick path skipped it. A group is
           required when it flags `required` or carries a min>0 (same rule the sheet
           uses). Any such item — or any variant item — opens the detail so the pick
           can't be stranded. */
        const hasRequiredGroup = (item.optionGroups || [])
            .some(g => g.required || (Number(g.min) || 0) > 0);
        if ((item.variants && item.variants.length > 0) || hasRequiredGroup) {
            openDetailDrawer(itemId);
            return;
        }

        const cartItem = {
            id: Number(itemId),
            quantity: 1,
            variants: 0,
            extras: [],
            price: item.price,
            name: item.name
        };

        addToCartInternal(cartItem);

        // Quick add feedback animation
        const originalHtml = btn.innerHTML;
        btn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>`;
        btn.style.transform = 'scale(1.2)';
        setTimeout(() => {
            btn.innerHTML = originalHtml;
            btn.style.transform = '';
        }, 1200);
    }

    // Open item detail
    function openDetailDrawer(itemId) {
        const item = config.items[itemId];
        if (!item) return;

        currentDetailItem = item;
        currentDetailQty = 1;

        let variantsHtml = '';
        if (item.variants && item.variants.length > 0) {
            variantsHtml = `
                <div class="v-option-group">
                    <h4 class="v-option-title">${esc(item.variantGroup || config.lang.chooseOption || 'Choose an option')}</h4>
                    <div class="v-options-list">
                        ${item.variants.map((v, idx) => `
                            <label class="v-radio-option">
                                <span class="v-option-left">
                                    <input type="radio" name="v-variant-choice" value="${v.id}" data-price="${v.price}" ${idx === 0 ? 'checked' : ''}>
                                    <span>${esc(v.label)}</span>
                                </span>
                                <span class="v-option-price">${formatPrice(v.price)}</span>
                            </label>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        let extrasHtml = '';
        if (item.extras && item.extras.length > 0) {
            extrasHtml = `
                <div class="v-option-group">
                    <h4 class="v-option-title">${esc(config.lang.extras || 'Extras')}</h4>
                    <div class="v-options-list">
                        ${item.extras.map(e => `
                            <label class="v-checkbox-option">
                                <span class="v-option-left">
                                    <input type="checkbox" name="v-extra-choice" value="${e.id}" data-price="${e.price}">
                                    ${e.img ? `<img class="v-opt-thumb" src="${esc(e.img)}" alt="${esc(e.title)}" loading="lazy">` : ''}
                                    <span>${esc(e.title)}</span>
                                </span>
                                <span class="v-option-price">+${formatPrice(e.price)}</span>
                            </label>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        let groupsHtml = '';
        if (item.optionGroups && item.optionGroups.length) {
            groupsHtml = item.optionGroups.map(g => {
                const single = g.max === 1;
                const ruleTxt = single ? (g.required ? ' *' : '') : ` (${g.min || 0}${g.max ? '-' + g.max : '+'})`;
                const rows = g.options.map((o, oi) => {
                    const preset = single && g.required && oi === 0;
                    const input = single
                        ? `<input type="radio" name="v-group-${g.id}" data-group="${g.id}" data-opt="${o.i}" data-gmax="1" data-price="${o.price}" data-label="${esc(o.label)}" ${preset ? 'checked' : ''}>`
                        : `<input type="checkbox" data-group="${g.id}" data-opt="${o.i}" data-gmax="${g.max || 0}" data-price="${o.price}" data-label="${esc(o.label)}">`;
                    return `<label class="${single ? 'v-radio-option' : 'v-checkbox-option'}"><span class="v-option-left">${input}<span>${esc(o.label)}</span></span><span class="v-option-price">${o.price > 0 ? '+' + formatPrice(o.price) : ''}</span></label>`;
                }).join('');
                return `<div class="v-option-group"><h4 class="v-option-title">${esc(g.title)}${ruleTxt}</h4><div class="v-options-list">${rows}</div></div>`;
            }).join('');
        }

        elements.detailContent.innerHTML = `
            ${item.img ? `
                <div class="v-detail-img-wrap">
                    ${window.mbCarousel ? mbCarousel.html([item.img].concat(item.gallery || [])) : `<img src="${esc(item.img)}" alt="${esc(item.name)}" class="v-detail-img">`}
                </div>
            ` : ''}

            <div class="v-detail-title-row">
                <h3 class="v-detail-title">${esc(item.name)}</h3>
                <span class="v-detail-price" id="v-detail-price-display">${formatPrice(item.price)}</span>
            </div>

            ${item.desc ? `<p class="v-detail-desc">${esc(item.desc)}</p>` : ''}

            <div class="v-detail-options">
                ${variantsHtml}
                ${extrasHtml}
                ${groupsHtml}
            </div>

            <div class="v-detail-qty-row">
                <div class="v-qty-selectors">
                    <button class="v-qty-btn" id="v-qty-minus">&minus;</button>
                    <span class="v-qty-val" id="v-qty-value">1</span>
                    <button class="v-qty-btn" id="v-qty-plus">&plus;</button>
                </div>

                <div class="v-detail-cta-group">
                    <button class="v-btn-primary" id="v-detail-add-btn">
                        ${config.lang.add}
                    </button>
                    <!-- shown once the cart has items: a direct jump to the cart -->
                    <button class="v-cart-jump" id="v-cart-jump" type="button" hidden aria-label="${esc(config.lang.view_cart || 'View Cart')}">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"></path>
                            <line x1="3" y1="6" x2="21" y2="6"></line>
                            <path d="M16 10a4 4 0 01-8 0"></path>
                        </svg>
                        <i class="v-cart-jump-count" id="v-cart-jump-count" aria-hidden="true">0</i>
                    </button>
                </div>
            </div>

            ${EP.reviewsBase ? `<div class="v-rate-row" id="vRateRow">
                <span class="v-option-title v-rate-label">${config.lang.rateThisItem}</span>
                <div class="v-star-row v-rate-stars" id="vRateStars">
                    ${[1,2,3,4,5].map(s => `<button type="button" class="v-star-btn" data-star="${s}" aria-label="${s}">★</button>`).join('')}
                </div>
            </div>` : ''}

            <!-- F6 · rich reviews (avg + distribution + filters + list), populated on open -->
            <section class="v-reviews" id="cvReviews" hidden></section>
        `;

        elements.detailDrawer.setAttribute('aria-hidden', 'false');
        setScrollLock(true);

        // Bind inner detail events
        const btnMinus = document.getElementById('v-qty-minus');
        const btnPlus = document.getElementById('v-qty-plus');
        const addBtn = document.getElementById('v-detail-add-btn');

        btnMinus.addEventListener('click', () => updateDetailQty(-1));
        btnPlus.addEventListener('click', () => updateDetailQty(1));
        addBtn.addEventListener('click', handleDetailAddToCart);

        // Direct jump to the cart, shown once it already has items — re-injected
        // with the rest of the sheet each time an item is opened, so sync + bind
        // it fresh rather than relying on the load-time `elements` cache.
        const cartJumpBtn = document.getElementById('v-cart-jump');
        if (cartJumpBtn) {
            cartJumpBtn.addEventListener('click', () => {
                closeDetailDrawer();
                openCartDrawer();
            });
        }
        syncCartJump();

        // Inline "Rate this dish" — tapping a star lights it and opens the shared
        // review sheet preselected to that rating (one shared endpoint: stars +
        // optional text), rather than a second stars-only direct POST.
        const vRateStars = document.getElementById('vRateStars');
        if (vRateStars) {
            const rstars = vRateStars.querySelectorAll('.v-star-btn');
            rstars.forEach(btn => {
                btn.addEventListener('click', () => {
                    const star = Number(btn.getAttribute('data-star'));
                    rstars.forEach((x, i) => x.classList.toggle('v-star-on', i < star));
                    if (window.mbItemReviews) window.mbItemReviews.open(star);
                });
            });
        }

        // The shared carousel only fades a broken image out (opacity:0), which leaves
        // the bordered, fixed-height wrap behind as an empty box. Collapse the wrap
        // once every image in it has failed — same graceful degradation the menu
        // cards already apply via their inline onerror.
        collapseDetailImgWrapIfEmpty();

        if (window.__velvetLoadReviews) window.__velvetLoadReviews(item.id);

        // Listen for option changes to update displayed price
        const choices = elements.detailContent.querySelectorAll('input[name="v-variant-choice"], input[name="v-extra-choice"], [data-group]');
        choices.forEach(input => {
            input.addEventListener('change', function () {
                // Enforce max on multi-select groups.
                if (input.hasAttribute('data-group') && input.type === 'checkbox') {
                    const gmax = parseInt(input.getAttribute('data-gmax'), 10) || 0;
                    if (input.checked && gmax > 0) {
                        const checked = elements.detailContent.querySelectorAll(`[data-group="${input.getAttribute('data-group')}"]:checked`);
                        if (checked.length > gmax) { input.checked = false; return; }
                    }
                }
                calculateDetailPrice();
            });
        });

        calculateDetailPrice();
    }

    /**
     * Hide `.v-detail-img-wrap` when none of its images can render, so a 404 does
     * not leave a 220px bordered blank box above the item title.
     */
    function collapseDetailImgWrapIfEmpty() {
        const wrap = elements.detailContent.querySelector('.v-detail-img-wrap');
        if (!wrap) return;

        const imgs = Array.from(wrap.querySelectorAll('img'));
        if (!imgs.length) { wrap.style.display = 'none'; return; }

        const settle = () => {
            const alive = imgs.some(img => !img.complete || img.naturalWidth > 0);
            wrap.style.display = alive ? '' : 'none';
        };

        imgs.forEach(img => {
            if (img.complete) return;
            img.addEventListener('load', settle, { once: true });
            img.addEventListener('error', settle, { once: true });
        });

        settle();
    }

    function closeDetailDrawer() {
        elements.detailDrawer.setAttribute('aria-hidden', 'true');
        setScrollLock(false);
        currentDetailItem = null;
    }

    function updateDetailQty(diff) {
        currentDetailQty = Math.max(1, currentDetailQty + diff);
        const qtyValueEl = document.getElementById('v-qty-value');
        if (qtyValueEl) qtyValueEl.textContent = currentDetailQty;
        calculateDetailPrice();
    }

    function calculateDetailPrice() {
        if (!currentDetailItem) return;

        let basePrice = currentDetailItem.price;

        // Check variant
        const selectedVariant = elements.detailContent.querySelector('input[name="v-variant-choice"]:checked');
        if (selectedVariant) {
            basePrice = parseFloat(selectedVariant.getAttribute('data-price'));
        }

        // Check extras
        const selectedExtras = elements.detailContent.querySelectorAll('input[name="v-extra-choice"]:checked');
        selectedExtras.forEach(extra => {
            basePrice += parseFloat(extra.getAttribute('data-price'));
        });

        // Check option-group selections
        elements.detailContent.querySelectorAll('[data-group]:checked').forEach(g => {
            basePrice += parseFloat(g.getAttribute('data-price')) || 0;
        });

        const total = basePrice * currentDetailQty;
        const priceDisplay = document.getElementById('v-detail-price-display');
        if (priceDisplay) {
            priceDisplay.textContent = formatPrice(total);
        }
    }

    function handleDetailAddToCart() {
        if (!currentDetailItem) return;

        let variantId = 0;
        let variantPrice = currentDetailItem.price;

        const selectedVariant = elements.detailContent.querySelector('input[name="v-variant-choice"]:checked');
        if (selectedVariant) {
            variantId = Number(selectedVariant.value);
            variantPrice = parseFloat(selectedVariant.getAttribute('data-price'));
        }

        const extras = [];
        const selectedExtras = elements.detailContent.querySelectorAll('input[name="v-extra-choice"]:checked');
        selectedExtras.forEach(extra => {
            extras.push({
                id: Number(extra.value),
                price: parseFloat(extra.getAttribute('data-price'))
            });
        });

        // Collect + validate option-group selections.
        const mods = [];
        let ruleErr = null;
        (currentDetailItem.optionGroups || []).forEach(g => {
            const sel = elements.detailContent.querySelectorAll(`[data-group="${g.id}"]:checked`);
            const need = g.required ? Math.max(1, g.min || 0) : (g.min || 0);
            if (sel.length < need) ruleErr = ruleErr || g.title;
            sel.forEach(inp => mods.push({
                groupId: g.id, optIndex: parseInt(inp.getAttribute('data-opt'), 10),
                groupTitle: g.title, label: inp.getAttribute('data-label') || '',
                price: parseFloat(inp.getAttribute('data-price')) || 0
            }));
        });
        if (ruleErr) { alert((config.lang.add || 'Please choose') + ': ' + ruleErr); return; }

        const cartItem = {
            id: Number(currentDetailItem.id),
            quantity: currentDetailQty,
            variants: variantId,
            extras: extras,
            mods: mods,
            price: variantPrice,
            name: currentDetailItem.name
        };

        addToCartInternal(cartItem);
        
        // Show success state on button
        const addBtn = document.getElementById('v-detail-add-btn');
        if (addBtn) {
            addBtn.textContent = config.lang.added;
            addBtn.classList.add('success-state');
            setTimeout(() => {
                closeDetailDrawer();
            }, 500);
        }
    }

    // Add to cart internals
    function addToCartInternal(newItem) {
        // Check if matching item exists (same id, same variants, same extras)
        const match = cart.find(item => {
            if (item.id !== newItem.id) return false;
            if (item.variants !== newItem.variants) return false;
            if (item.extras.length !== newItem.extras.length) return false;
            
            // Check matching extras ids
            const extraIds = item.extras.map(e => e.id).sort();
            const newExtraIds = newItem.extras.map(e => e.id).sort();
            if (!extraIds.every((val, index) => val === newExtraIds[index])) return false;
            // Check matching option-group selections
            const modKey = m => (m || []).map(x => x.groupId + ':' + x.optIndex).sort().join(',');
            return modKey(item.mods) === modKey(newItem.mods);
        });

        if (match) {
            match.quantity += newItem.quantity;
        } else {
            cart.push(newItem);
        }

        saveCart();
    }

    // Product-sheet "view cart" jump — the button lives inside the JS-templated
    // detail drawer (rebuilt per item), so it's looked up fresh rather than cached
    // in `elements`; a no-op when the drawer has never been opened this session.
    function syncCartJump(totalCount) {
        const jump = document.getElementById('v-cart-jump');
        if (!jump) return;
        /* COMBOS-V2 — combo lines count toward the jump badge too */
        const n = totalCount === undefined ? cart.reduce((sum, it) => sum + it.quantity, 0) + comboCount() : totalCount;
        jump.hidden = n === 0;
        const jc = document.getElementById('v-cart-jump-count');
        if (jc) jc.textContent = n;
    }

    // Update Cart Floating button + calculations
    function updateCartUI() {
        let totalCount = 0;
        let subtotal = 0;

        cart.forEach(item => {
            totalCount += item.quantity;
            let itemPrice = item.price;
            item.extras.forEach(e => {
                itemPrice += e.price;
            });
            (item.mods || []).forEach(m => { itemPrice += m.price; });
            subtotal += itemPrice * item.quantity;
        });

        /* COMBOS-V2 — fold the combo basket into the quote (flows into tax, tip,
           coupon math and the grand total below) and into the badge count. */
        subtotal += comboTotal();
        const badgeCount = totalCount + comboCount();

        syncCartJump(badgeCount);

        // Floating button visibility
        if (elements.cartToggle) {
            if (badgeCount > 0) {
                elements.cartToggle.style.display = 'flex';
                elements.cartBadge.textContent = badgeCount;
                elements.cartTotalPrice.textContent = formatPrice(subtotal);
            } else {
                elements.cartToggle.style.display = 'none';
            }
        }

        // Summary values
        if (elements.sumSubtotal) {
            elements.sumSubtotal.textContent = formatPrice(subtotal);
            
            // Apply delivery charges if delivery mode
            const isDelivery = elements.orderTypeInput && elements.orderTypeInput.value === 'delivery';
            const deliveryCost = isDelivery ? config.deliveryCharge : 0;
            
            if (isDelivery && config.deliveryCharge > 0) {
                elements.deliveryRow.style.display = '';
                elements.sumDelivery.textContent = formatPrice(config.deliveryCharge);
            } else {
                elements.deliveryRow.style.display = 'none';
            }

            // Coupon Discount (percentage or flat, crave contract)
            let discountAmt = 0;
            if (coupon) {
                // bug batch 7 · 4: a percentage rounds in the store decimals like CouponService (was unrounded)
                discountAmt = coupon.type === 'percentage'
                    ? roundTo(subtotal * (Number(coupon.value) || 0) / 100, decimals())
                    : coupon.type === 'bogo' ? bxgyDiscount() : (Number(coupon.value) || 0);
                discountAmt = Math.min(discountAmt, subtotal);
            }
            if (discountAmt > 0) {
                elements.discountRow.style.display = '';
                elements.sumDiscount.textContent = `-${formatPrice(discountAmt)}`;
            } else {
                elements.discountRow.style.display = 'none';
            }

            // Tax — on the RAW subtotal, never on the discounted one.
            const taxAmt = computeTax(subtotal);
            if (elements.taxRow) {
                if (taxAmt > 0) {
                    elements.taxRow.style.display = '';
                    if (elements.sumTax) elements.sumTax.textContent = formatPrice(taxAmt);
                } else {
                    elements.taxRow.style.display = 'none';
                }
            }

            // Tip (percentage of subtotal, or a custom absolute amount)
            const tipAmt = tipAmount(subtotal);
            if (elements.tipSummaryRow) {
                if (tipAmt > 0) {
                    elements.tipSummaryRow.style.display = '';
                    if (elements.sumTip) elements.sumTip.textContent = formatPrice(tipAmt);
                } else {
                    elements.tipSummaryRow.style.display = 'none';
                }
            }

            /* Order::getTotalAttribute():
                 subtotal + delivery + tax + tip - discount           */
            const total = Math.max(0, subtotal + deliveryCost + taxAmt + tipAmt - discountAmt);
            elements.sumTotal.textContent = formatPrice(total);
            serverQuote(tipAmt);
        }

        renderCartItems();
    }

    /* P3-13 (THEME-CONTRACT §5.2): the summary rows and the total are the server quote of the cart this checkout
       posts; the sums above are only the estimate shown until it answers. */
    function serverQuote(tipAmt) {
        if (!window.mbQuote || (cart.length === 0 && comboCount() === 0) || !config.orderUrl) return;
        const box = elements.sumTotal ? elements.sumTotal.closest('.v-summary, .v-cart-summary, form') : null;
        window.mbQuote.update('cart', window.mbQuote.urlFrom(config.orderUrl), {
            items: orderLines(),
            'ordering-type': couponOrderType(),
            coupon_code: coupon && coupon.code ? coupon.code : '',
            tip_amount: tipAmt > 0 ? tipAmt.toFixed(decimals()) : 0,
            branch_id: config.branchId || ''
        }, function (q) {
            const byKey = {};
            window.mbQuote.rows(q).forEach(function (r) { byKey[r.key] = r; });
            const put = function (row, el, r) {
                if (row) row.style.display = r ? '' : 'none';
                if (el && r) el.textContent = r.formatted;
            };
            if (elements.sumSubtotal && byKey.subtotal) elements.sumSubtotal.textContent = byKey.subtotal.formatted;
            put(elements.deliveryRow, elements.sumDelivery, byKey.delivery);
            put(elements.discountRow, elements.sumDiscount, byKey.discount);
            put(elements.taxRow, elements.sumTax, byKey.tax);
            put(elements.tipSummaryRow, elements.sumTip, byKey.tip);
            if (elements.sumTotal) elements.sumTotal.textContent = q.total.formatted;
        }, box ? [box] : []);
    }

    // Render Items inside Cart drawer
    function renderCartItems() {
        if (!elements.cartItemsList) return;

        /* COMBOS-V2 — a combos-only basket is not an empty cart */
        if (cart.length === 0 && comboCount() === 0) {
            elements.cartItemsList.innerHTML = `<div class="v-no-items">${config.lang.cartEmpty || 'Your cart is empty'}</div>`;
            return;
        }

        elements.cartItemsList.innerHTML = comboGroupHTML(false) + cart.map((item, cartIndex) => {
            const baseItem = config.items[item.id];
            if (!baseItem) return '';

            let label = '';
            if (item.variants > 0) {
                const v = baseItem.variants.find(x => x.id === item.variants);
                if (v) label = v.label;
            }

            let extrasLabel = '';
            if (item.extras.length > 0) {
                const names = item.extras.map(e => {
                    const match = baseItem.extras.find(x => x.id === e.id);
                    return match ? match.title : '';
                }).filter(Boolean);
                if (names.length > 0) extrasLabel = `+ ${names.join(', ')}`;
            }

            let modsLabel = '';
            if (item.mods && item.mods.length > 0) {
                modsLabel = item.mods.map(m => (m.groupTitle ? m.groupTitle + ': ' : '') + m.label).join(', ');
            }

            let singleItemPrice = item.price;
            item.extras.forEach(e => {
                singleItemPrice += e.price;
            });
            (item.mods || []).forEach(m => { singleItemPrice += m.price; });

            /* Happy-hour strike-through: original_price is the pre-schedule BASE
               price, so the pre-discount unit is unit + (original − current base). */
            const wasUnit = baseItem.original_price && Number(baseItem.original_price) > Number(baseItem.price)
                ? singleItemPrice + (Number(baseItem.original_price) - Number(baseItem.price)) : 0;

            return `
                <div class="v-cart-item">
                    <div class="v-cart-item-info">
                        <button type="button" class="v-cart-item-name" data-open="${baseItem.id}">${esc(item.name)}</button>
                        <div class="v-cart-item-customizations">
                            ${label ? `<span>${esc(label)}</span>` : ''}
                            ${modsLabel ? `<div style="font-size:10px; opacity:0.8;">${esc(modsLabel)}</div>` : ''}
                            ${extrasLabel ? `<div style="font-size:10px; opacity:0.8;">${esc(extrasLabel)}</div>` : ''}
                        </div>
                    </div>
                    <div class="v-cart-item-right">
                        <div class="v-cart-item-toprow">
                            <div class="v-cart-item-priceline">
                                <span class="v-cart-item-price">${formatPrice(singleItemPrice * item.quantity)}</span>
                                ${wasUnit ? `<del class="v-cart-item-was">${formatPrice(wasUnit * item.quantity)}</del>` : ''}
                            </div>
                            <button type="button" class="v-cart-item-remove" data-rm="${cartIndex}" aria-label="${esc(config.lang.remove || 'Remove')}"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="15" height="15"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-9 0 1 12a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2l1-12"/></svg></button>
                        </div>
                        ${item.quantity > 1 ? `<small class="v-cart-item-unit">${item.quantity} &times; ${formatPrice(singleItemPrice)}</small>` : ''}
                        <div class="v-cart-item-qty">
                            <button type="button" class="v-cart-item-qty-btn" data-csp-qty-handler="VELVET_UPDATE_QTY" data-csp-index="${cartIndex}" data-csp-delta="-1">&minus;</button>
                            <span>${item.quantity}</span>
                            <button type="button" class="v-cart-item-qty-btn" data-csp-qty-handler="VELVET_UPDATE_QTY" data-csp-index="${cartIndex}" data-csp-delta="1">&plus;</button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    /* Delegated cart-line actions: tap name → reopen the item's detail drawer,
       trash → drop the whole line regardless of quantity. Bound once — the list
       is re-rendered by innerHTML, so per-node listeners would be lost. */
    if (elements.cartItemsList && !elements.cartItemsList.dataset.lineActions) {
        elements.cartItemsList.dataset.lineActions = '1';
        elements.cartItemsList.addEventListener('click', function (e) {
            const rm = e.target.closest('[data-rm]');
            if (rm) {
                const idx = Number(rm.getAttribute('data-rm'));
                if (cart[idx]) { cart.splice(idx, 1); saveCart(); }
                return;
            }
            const open = e.target.closest('[data-open]');
            if (open) { closeCartDrawer(); openDetailDrawer(open.getAttribute('data-open')); }
        });
    }

    // Expose global Qty Updater for inline cart buttons
    window.VELVET_UPDATE_QTY = function (index, diff) {
        if (cart[index]) {
            cart[index].quantity += diff;
            if (cart[index].quantity <= 0) {
                cart.splice(index, 1);
            }
            saveCart();
        }
    };

    /* COMBOS-V2 — read-only "Combos" group prepended to the cart list (velvet's
       single cart+checkout drawer, so this list doubles as the "your order"
       summary; the totals block above the form is always rendered and now
       quotes combos via the folded subtotal). Tapping a row reopens the combo
       sheet's basket view, which is where quantities are edited. */
    function comboGroupHTML(compact) {
        const lines = comboLines();
        if (!lines.length) return '';
        let h = '<div class="v-combo-group" data-combo-open style="border-top:1px dashed rgba(128,128,128,.4);padding-top:8px;margin-top:8px;cursor:pointer">';
        lines.forEach(function (L) {
            h += '<div class="v-cart-item v-cart-item--combo">' +
                '<div class="v-cart-item-info"><span class="v-cart-item-name">' + esc(L.name) + '</span>' +
                (L.parts.length ? '<div class="v-cart-item-customizations" style="font-size:10px;opacity:.8;">' + esc(L.parts.join(' · ')) + '</div>' : '') +
                '</div>' +
                '<div class="v-cart-item-right"><div class="v-cart-item-priceline">' +
                '<span class="v-cart-item-unit">&times;' + L.qty + '</span> ' +
                '<span class="v-cart-item-price">' + formatPrice(L.price) + '</span>' +
                '</div></div></div>';
        });
        if (!compact) h += '<small style="opacity:.65">' + esc((config.lang || {}).edit || 'Edit') + ' ›</small>';
        h += '</div>';
        return h;
    }
    document.addEventListener('click', function (e) {
        const g = e.target.closest && e.target.closest('[data-combo-open]');
        if (g && window.mbCombos && window.mbCombos.openSheet) window.mbCombos.openSheet('basket');
    });
    /* Re-quote whenever the combo basket changes (add/remove/qty in the sheet):
       badge + pill, totals block, and the drawer's item list all re-render. */
    window.addEventListener('mb:combos-changed', function () {
        try { updateCartUI(); } catch (e) {}
    });

    // Open/Close Cart
    function openCartDrawer() {
        elements.cartDrawer.setAttribute('aria-hidden', 'false');
        setScrollLock(true);
    }

    function closeCartDrawer() {
        elements.cartDrawer.setAttribute('aria-hidden', 'true');
        setScrollLock(false);
    }

    /* reservation / waitlist drawers (parity) */
    function bindParityDrawer(openId, drawerId, closeId, backdropId, autoFlag) {
        var drawer = document.getElementById(drawerId);
        if (!drawer) return;
        function open() { drawer.setAttribute('aria-hidden', 'false'); setScrollLock(true); }
        function close() { drawer.setAttribute('aria-hidden', 'true'); setScrollLock(false); }
        var ob = document.getElementById(openId); if (ob) ob.addEventListener('click', open);
        var cb = document.getElementById(closeId); if (cb) cb.addEventListener('click', close);
        var bd = document.getElementById(backdropId); if (bd) bd.addEventListener('click', close);
        if (window.VELVET && VELVET[autoFlag]) open();
    }
    bindParityDrawer('v-res-open', 'v-res-drawer', 'v-res-close', 'v-res-backdrop', 'openResSheet');
    bindParityDrawer('v-waitlist-open', 'v-waitlist-drawer', 'v-waitlist-close', 'v-waitlist-backdrop', 'openWaitlistSheet');
    bindParityDrawer('v-auth-open', 'v-auth-drawer', 'v-auth-close', 'v-auth-backdrop');
    bindParityDrawer('v-account-open', 'v-account-drawer', 'v-account-close', 'v-account-backdrop');
    /* Header globe -> language drawer (multi-language stores only). */
    bindParityDrawer('v-lang-open', 'v-lang-drawer', 'v-lang-close', 'v-lang-backdrop');

    /* Header chip -> currency drawer; #curSeg picks the display currency. Persisted
       store-scoped via the shared mb-currency.js helper so it survives a reload. */
    bindParityDrawer('v-cur-open', 'v-cur-drawer', 'v-cur-close', 'v-cur-backdrop');
    (function () {
        var seg = document.getElementById('curSeg');
        if (!seg) return;
        seg.addEventListener('click', function (e) {
            var b = e.target.closest('[data-cur]');
            if (!b) return;
            var code = b.dataset.cur, rate = parseFloat(b.dataset.rate) || 1;
            seg.querySelectorAll('[data-cur]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
            applyCurrency(code, rate);
            if (window.mbCurrency) mbCurrency.save(code, rate);   // persist store-scoped (shared helper)
        });
        if (window.mbCurrency) mbCurrency.restore('#curSeg');   // replay saved currency choice on load
    })();

    /* ── success drawer (opened on order success, F1 google-review) ── */
    function openSuccessDrawer(msg) {
        var d = document.getElementById('v-success-drawer');
        if (!d) return;
        if (msg) {
            var m = document.getElementById('v-success-msg');
            if (m) m.textContent = msg;
        }
        d.setAttribute('aria-hidden', 'false');
        setScrollLock(true);
    }
    (function () {
        var d = document.getElementById('v-success-drawer');
        if (!d) return;
        function close() { d.setAttribute('aria-hidden', 'true'); setScrollLock(false); window.location.reload(); }
        var cb = document.getElementById('v-success-close'); if (cb) cb.addEventListener('click', close);
        var bd = document.getElementById('v-success-backdrop'); if (bd) bd.addEventListener('click', close);
        var done = document.getElementById('v-success-done'); if (done) done.addEventListener('click', close);
    })();

    /* ── auth: login / register / logout (parity) ── */
    (function () {
        var ep = config.endpoints || {};
        var tabs = document.getElementById('v-auth-tabs');
        if (tabs) {
            tabs.querySelectorAll('button[data-auth-tab]').forEach(function (b) {
                b.addEventListener('click', function () {
                    tabs.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-on', x === b); });
                    document.querySelectorAll('.v-auth-form[data-auth]').forEach(function (f) {
                        f.style.display = f.getAttribute('data-auth') === b.getAttribute('data-auth-tab') ? '' : 'none';
                    });
                });
            });
        }
        function authPost(url, body) {
            var errEl = document.getElementById('v-auth-err');
            if (!url) return;
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body)
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (res && res.success) { window.location.reload(); return; }
                if (errEl) { errEl.textContent = (res && res.message) || 'Failed.'; errEl.style.display = 'block'; }
            }).catch(function () {
                if (errEl) { errEl.textContent = 'Network error.'; errEl.style.display = 'block'; }
            });
        }
        var loginBtn = document.getElementById('v-login-btn');
        if (loginBtn) loginBtn.addEventListener('click', function () {
            authPost(ep.login, {
                store_id: config.storeId,
                email: (document.getElementById('v-login-email').value || '').trim(),
                password: document.getElementById('v-login-pass').value
            });
        });
        var regBtn = document.getElementById('v-register-btn');
        if (regBtn) regBtn.addEventListener('click', function () {
            authPost(ep.register, {
                store_id: config.storeId,
                name: (document.getElementById('v-reg-name').value || '').trim(),
                email: (document.getElementById('v-reg-email').value || '').trim(),
                phone: (document.getElementById('v-reg-phone').value || '').trim(),
                password: document.getElementById('v-reg-pass').value,
                password_confirmation: (document.getElementById('v-reg-pass2') || {}).value || ''
            });
        });
        var logoutBtn = document.getElementById('v-logout-btn');
        if (logoutBtn) logoutBtn.addEventListener('click', function () {
            if (!ep.logout) return;
            fetch(ep.logout, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf }
            }).then(function () { window.location.reload(); });
        });

        /* A3 · phone-OTP login — send code, then verify the 6-box code under `code`.
           Distinct ids from the checkout OTP gate (#cvOtpGateBoxes). */
        var vOtpSend = document.getElementById('v-otp-send');
        if (vOtpSend) vOtpSend.addEventListener('click', function () {
            var phone = (document.getElementById('v-otp-phone').value || '').trim(); if (!phone) return;
            var errEl = document.getElementById('v-auth-err'); if (errEl) errEl.style.display = 'none';
            fetch(ep.otpRequest, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ store_id: config.storeId, phone: phone }) })
                .then(function (r) { return r.json(); }).then(function (res) {
                    if (res && res.success) { document.getElementById('v-otp-step1').style.display = 'none'; document.getElementById('v-otp-step2').style.display = ''; var f = document.querySelector('#v-otp-boxes input'); if (f) f.focus(); }
                    else if (errEl) { errEl.textContent = (res && res.message) || 'Failed.'; errEl.style.display = 'block'; }
                }).catch(function () { if (errEl) { errEl.textContent = 'Network error.'; errEl.style.display = 'block'; } });
        });
        var vOtpVerify = document.getElementById('v-otp-verify');
        if (vOtpVerify) vOtpVerify.addEventListener('click', function () {
            var code = Array.prototype.slice.call(document.querySelectorAll('#v-otp-boxes input')).map(function (b) { return b.value; }).join('');
            authPost(ep.otpVerifyLogin, { store_id: config.storeId, phone: (document.getElementById('v-otp-phone').value || '').trim(), code: code });
        });
        (function () {
            var boxes = Array.prototype.slice.call(document.querySelectorAll('#v-otp-boxes input'));
            boxes.forEach(function (inp, i) {
                inp.addEventListener('input', function () { inp.value = inp.value.replace(/\D/g, '').slice(0, 1); if (inp.value && boxes[i + 1]) boxes[i + 1].focus(); });
                inp.addEventListener('keydown', function (e) { if (e.key === 'Backspace' && !inp.value && boxes[i - 1]) boxes[i - 1].focus(); });
                inp.addEventListener('paste', function (e) {
                    e.preventDefault();
                    var d = ((e.clipboardData || window.clipboardData).getData('text') || '').replace(/\D/g, '').slice(0, boxes.length);
                    if (!d) return;
                    for (var j = 0; j < boxes.length; j++) boxes[j].value = d.charAt(j) || '';
                    (boxes[Math.min(d.length, boxes.length) - 1] || boxes[0]).focus();
                });
            });
        })();
    })();

    /* ── loyalty stamp / points card (F2) ── */
    (function () {
        var block = document.getElementById('cvLoyaltyBlock');
        var ep = config.endpoints || {};
        if (!block || !ep.loyaltyBalance) return;
        var type = config.loyaltyType;
        function paint(d) {
            var bal = d.balance || 0;
            var balEl = document.getElementById('cvLoyaltyBalance');
            if (balEl) balEl.textContent = bal;
            if (type === 'stamps') {
                block.querySelectorAll('#cvStampDots .v-stamp-dot').forEach(function (dot, i) {
                    dot.classList.toggle('is-on', i < bal);
                });
            } else {
                var bar = document.getElementById('cvLoyaltyBar');
                if (bar) bar.style.width = Math.min(100, d.progress_pct || 0) + '%';
            }
            var rb = document.getElementById('cvLoyaltyRedeem');
            if (rb) rb.style.display = d.ready_to_redeem ? '' : 'none';
        }
        fetch(ep.loyaltyBalance)
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.active) paint(d); })
            .catch(function () {});
        var rb = document.getElementById('cvLoyaltyRedeem');
        if (rb) rb.addEventListener('click', function () {
            rb.disabled = true;
            fetch(ep.loyaltyRedeem, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf },
                body: '{}'
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.success) {
                    rb.style.display = 'none';
                    var ok = document.getElementById('cvLoyaltyOk');
                    if (ok) ok.style.display = 'block';
                    paint({ balance: d.new_balance, progress_pct: 0, ready_to_redeem: false });
                } else {
                    rb.disabled = false;
                }
            }).catch(function () { rb.disabled = false; });
        });
    })();

    // Order Type Tabs controller
    function initDefaultOrderType() {
        /* Room service wins the default. The room-service tab only renders when
           $allow_room_service is set, which already means hotel mode is on AND this
           QR resolved to a room — so its mere presence is the signal that the guest
           scanned from their room. Preferring it saves them a tap and, more
           importantly, stops a room order silently going to the kitchen as a table
           order when dine-in also happens to be enabled. Routing through
           setOrderType() (rather than just marking the tab active) is what makes
           #v-order-type-input actually hold room_service and clears the `required`
           flags on the table/phone/address inputs. */
        const roomTab = document.querySelector('.v-order-type-tabs .v-tab-btn[data-type="room_service"]');
        const activeTab = roomTab || document.querySelector('.v-order-type-tabs .v-tab-btn.active') || document.querySelector('.v-order-type-tabs .v-tab-btn');
        if (activeTab) {
            setOrderType(activeTab.getAttribute('data-type'));
        }
    }

    function setOrderType(type) {
        elements.orderTypeTabs.forEach(btn => {
            if (btn.getAttribute('data-type') === type) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        if (elements.orderTypeInput) elements.orderTypeInput.value = type;

        // Hide/Show fields based on order type
        if (type === 'on-table') {
            elements.groupTable.style.display = '';
            elements.groupPhone.style.display = 'none';
            elements.groupAddress.style.display = 'none';
            
            // Require table input
            const tblInput = document.getElementById('v-table-num');
            if (tblInput) tblInput.required = true;
            
            const phoneInput = document.getElementById('v-phone-num');
            if (phoneInput) phoneInput.required = false;
            
            const addrInput = document.getElementById('v-address');
            if (addrInput) addrInput.required = false;
        } else if (type === 'takeaway') {
            elements.groupTable.style.display = 'none';
            elements.groupPhone.style.display = '';
            elements.groupAddress.style.display = 'none';
            
            const tblInput = document.getElementById('v-table-num');
            if (tblInput) tblInput.required = false;
            
            const phoneInput = document.getElementById('v-phone-num');
            if (phoneInput) phoneInput.required = true;
            
            const addrInput = document.getElementById('v-address');
            if (addrInput) addrInput.required = false;
        } else if (type === 'delivery') {
            elements.groupTable.style.display = 'none';
            elements.groupPhone.style.display = '';
            elements.groupAddress.style.display = '';
            
            const tblInput = document.getElementById('v-table-num');
            if (tblInput) tblInput.required = false;
            
            const phoneInput = document.getElementById('v-phone-num');
            if (phoneInput) phoneInput.required = true;
            
            const addrInput = document.getElementById('v-address');
            if (addrInput) addrInput.required = true;
        } else if (type === 'room_service') {
            /* T1/H2 hotel room service — fulfilled in the guest's room, so like
               dine-in it needs no phone and no delivery address. It needs no table
               number either: the room rides along on the hidden `room` field the
               shared hotel partial renders, which the order-field-forwarder injects
               into the /send-order POST. Every `required` flag is cleared so the
               form's native validation cannot block the submit. */
            elements.groupTable.style.display = 'none';
            elements.groupPhone.style.display = 'none';
            elements.groupAddress.style.display = 'none';

            const tblInput = document.getElementById('v-table-num');
            if (tblInput) tblInput.required = false;

            const phoneInput = document.getElementById('v-phone-num');
            if (phoneInput) phoneInput.required = false;

            const addrInput = document.getElementById('v-address');
            if (addrInput) addrInput.required = false;
        }

        // Call-waiter only makes sense for dine-in
        const waiterRow = document.getElementById('v-waiter-row');
        if (waiterRow) waiterRow.style.display = (type === 'on-table' && config.allowCallWaiter) ? '' : 'none';

        updateCartUI();
    }

    // The cart as submitOrder's `items` — also posted to the coupon check (bug batch 7 · 4).
    function orderLines() {
        return cart.map(item => ({
            id: item.id,
            quantity: item.quantity,
            variants: item.variants,
            extras: item.extras.map(e => ({ id: e.id })),
            mods: (item.mods || []).map(m => ({ groupId: m.groupId, optIndex: m.optIndex }))
        }));
    }
    function couponOrderType() { return (elements.orderTypeInput && elements.orderTypeInput.value) || ''; }
    function couponBody(code) {
        return JSON.stringify({ code: code, subtotal: cartSubtotal(), items: orderLines(), 'ordering-type': couponOrderType() });
    }
    function couponSig() { return JSON.stringify([orderLines(), couponOrderType()]); }
    // Bug batch 7 · 4: buy-X-get-Y is priced by the server on the cart LINES (the discount the order will get);
    // re-quoted whenever the cart or the order type changes.
    function bxgyDiscount() {
        const c = coupon, sig = couponSig();
        if (c.sig !== sig) {
            c.sig = sig;
            fetch((config.endpoints || {}).coupon, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': config.csrf },
                body: couponBody(c.code)
            }).then(res => res.json()).then(data => {
                if (coupon !== c || c.sig !== sig) return;
                c.discount = data && data.success ? Number(data.discount) || 0 : 0;
                updateCartUI();
            }).catch(() => {});
        }
        return Number(c.discount) || 0;
    }

    // Coupon Apply logic (crave contract: POST applyCoupon {code, subtotal})
    function handleCouponApply() {
        const code = elements.couponCodeInput.value.trim();
        if (!code) return;

        elements.couponMsg.textContent = '';
        elements.couponMsg.className = 'v-coupon-message';

        const ep = config.endpoints || {};
        if (!ep.coupon) return;

        fetch(ep.coupon, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': config.csrf
            },
            body: couponBody(code)
        })
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    // percentage → % of subtotal; otherwise `percentage` carries a flat amount
                    coupon = {
                        code: data.label || code,
                        type: data.coupon_type,
                        value: Number(data.percentage) || 0,
                        discount: Number(data.discount) || 0,
                        sig: couponSig()
                    };
                    elements.couponMsg.textContent = data.message
                        ? ('✓ ' + data.message)
                        : config.lang.couponApplied;
                    elements.couponMsg.classList.add('v-coupon-success');
                } else {
                    coupon = null;
                    elements.couponMsg.textContent = (data && data.message) || config.lang.couponInvalid;
                    elements.couponMsg.classList.add('v-coupon-error');
                }
                updateCartUI();
            })
            .catch(() => {
                coupon = null;
                elements.couponMsg.textContent = config.lang.couponInvalid;
                elements.couponMsg.classList.add('v-coupon-error');
                updateCartUI();
            });
    }

    // Checkout Submit handler
    function handleCheckoutSubmit(e) {
        if (e && e.preventDefault) e.preventDefault();

        if (cart.length === 0 && comboCount() === 0) return; /* COMBOS-V2 — combos-only orders are legal */

        elements.checkoutErrors.style.display = 'none';
        elements.checkoutErrors.innerHTML = '';

        // Validate minimum order
        let subtotal = 0;
        cart.forEach(item => {
            let itemPrice = item.price;
            item.extras.forEach(e => {
                itemPrice += e.price;
            });
            (item.mods || []).forEach(m => { itemPrice += m.price; });
            subtotal += itemPrice * item.quantity;
        });
        /* COMBOS-V2 — combos count toward the minimum order and the tip base,
           exactly as the summary quoted them. */
        subtotal += comboTotal();

        if (config.minOrderValue > 0 && subtotal < config.minOrderValue) {
            showError(`${config.lang.minOrderError} ${formatPrice(config.minOrderValue)}`);
            return;
        }

        // Collect Form Data
        const formData = new FormData(elements.checkoutForm);
        
        // Structured items payload matching the expected schema:
        // [ { id, quantity, variants, extras: [ { id } ] } ]
        const itemsPayload = orderLines();

        formData.append('items', JSON.stringify(itemsPayload));
        if (coupon && coupon.code) {
            formData.append('coupon_code', coupon.code);
        }
        // Tip: the SAME rounded number the summary quoted, under the field name
        // the server actually reads (`tip_amount`, not `tip`).
        const tipAmt = tipAmount(subtotal);
        if (tipAmt > 0) {
            formData.append('tip_amount', tipAmt.toFixed(decimals()));
        }
        if (config.branchId) {
            formData.append('branch_id', config.branchId);
        }

        // Disable button
        elements.submitOrderBtn.disabled = true;
        const originalBtnText = elements.submitOrderBtn.textContent;
        elements.submitOrderBtn.textContent = config.lang.sending;

        fetch(config.orderUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                /* R6 · remember this delivery address on the device (guests only, gated
                   inside rememberAddress) so the saved-address chips are offered next
                   time. Read the live field values BEFORE the cart/form are cleared. */
                if (elements.orderTypeInput && elements.orderTypeInput.value === 'delivery') {
                    const addrEl = document.getElementById('v-address');
                    const latSaved = document.getElementById('cvCustLat');
                    const lngSaved = document.getElementById('cvCustLng');
                    rememberAddress(addrEl && addrEl.value, latSaved && latSaved.value, lngSaved && lngSaved.value);
                }
                /* R5 · remember the order in this browser so a guest sees it listed
                   without a phone/email lookup. */
                rememberOrder(data);

                clearCart();
                closeCartDrawer();

                // Response handling matching the submitOrder contract:
                if (data.message && data.message.startsWith('http')) {
                    // Redirect to payment URL
                    window.location.href = data.message;
                } else if (data.whatsapp_url) {
                    // Redirect to WhatsApp
                    window.location.href = data.whatsapp_url;
                } else {
                    // Elegant success screen (with google-review prompt — F1)
                    var orderNoEl = document.getElementById('v-success-orderno');
                    if (orderNoEl && data.order_id) {
                        orderNoEl.textContent = (config.lang.orderLabel || 'Order') + ' #' + data.order_id;
                        orderNoEl.hidden = false;
                    }
                    openSuccessDrawer(data.message && !data.message.startsWith('http') ? data.message : '');
                }
            } else if (data.otp_required) {
                /* F7 · phone-verification gate — open the OTP drawer, then re-submit */
                resetSubmitBtn();
                var ph = data.phone || (document.getElementById('v-phone-num') || {}).value || '';
                if (window.__velvetOpenCheckoutOtp) window.__velvetOpenCheckoutOtp(ph);
            } else {
                showError(data.message || 'Error occurred while sending order.');
                resetSubmitBtn();
            }
        })
        .catch(err => {
            showError('Network error occurred. Please try again.');
            resetSubmitBtn();
        });

        function resetSubmitBtn() {
            elements.submitOrderBtn.disabled = false;
            elements.submitOrderBtn.textContent = originalBtnText;
        }
    }

    /* XSS sink: this was `innerHTML = msg` with `msg` being the server's own
       message, which echoes user-supplied values back (coupon code, address,
       name) — a scripted payload typed into the coupon box came back and ran.
       textContent, with the server's `<br>` separators flattened first. */
    function showError(msg) {
        elements.checkoutErrors.style.display = 'block';
        elements.checkoutErrors.textContent = stripTags(msg);
        elements.checkoutErrors.scrollIntoView({ behavior: 'smooth' });
    }

    /* ══════════════════════════════════════════════════════════════════════
       theme-pack-blade features (F1–F7) — reference wiring adapted from crave
       ══════════════════════════════════════════════════════════════════════ */
    const L10N = config.lang || {};
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    const IMG_ERR = ' data-csp-error="hide"';
    function toast(msg) {
        const t = document.getElementById('cvToast');
        if (!t) return;
        t.textContent = msg || '';
        t.classList.add('is-on');
        clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.classList.remove('is-on'); }, 2200);
    }
    function tokenHeaders(json) {
        const h = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (json) h['Content-Type'] = 'application/json';
        if (config.csrf) h['X-CSRF-TOKEN'] = config.csrf;
        if (config.customerToken) h['Authorization'] = 'Bearer ' + config.customerToken;
        return h;
    }
    function hasToken() { return !!config.customerToken; }
    function openAuthDrawer() {
        const d = document.getElementById('v-auth-drawer');
        if (d) { d.setAttribute('aria-hidden', 'false'); setScrollLock(true); }
    }
    function openDrawer(id) {
        const d = document.getElementById(id);
        if (d) { d.setAttribute('aria-hidden', 'false'); setScrollLock(true); }
    }
    function closeDrawer(id) {
        const d = document.getElementById(id);
        if (d) { d.setAttribute('aria-hidden', 'true'); setScrollLock(false); }
    }
    function bindDrawerClose(drawerId, closeId, backdropId) {
        const cb = document.getElementById(closeId); if (cb) cb.addEventListener('click', function () { closeDrawer(drawerId); });
        const bd = document.getElementById(backdropId); if (bd) bd.addEventListener('click', function () { closeDrawer(drawerId); });
    }
    const EP = config.endpoints || {};

    /* ── F2 · follow / verified brand (B4/B5/B6) ──────────── */
    (function () {
        const btn = document.getElementById('cvFollowBtn');
        if (!btn || !EP.follow) return;
        const FOLLOW_INTENT = 'cv_follow_intent';
        const REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function applyFollowState(d) {
            if (!d || typeof d.following === 'undefined') { return false; }
            btn.dataset.following = d.following ? '1' : '0';
            btn.classList.toggle('is-on', d.following);
            const c = document.getElementById('cvFollowerCount');
            if (c && typeof d.follower_count !== 'undefined') c.textContent = d.follower_count;
            return true;
        }
        // B5: optimistic count nudge (the count span only exists past the B1 threshold).
        function bumpCount(delta) {
            const c = document.getElementById('cvFollowerCount'); if (!c) return;
            const n = parseInt(String(c.textContent || '').replace(/[^0-9]/g, ''), 10);
            if (!isNaN(n)) c.textContent = String(Math.max(0, n + delta));
        }
        // B5: scale-pop beat on a successful follow (skipped under reduced-motion).
        function popBeat() {
            if (REDUCED) return;
            btn.classList.add('v-followbtn--pop');
            btn.addEventListener('animationend', function h() { btn.classList.remove('v-followbtn--pop'); btn.removeEventListener('animationend', h); });
        }
        // B5: optimistic follow/unfollow — flip UI immediately, reconcile, revert on fail.
        function doFollow(unfollow) {
            const prevFollowing = btn.dataset.following === '1';
            btn.dataset.following = unfollow ? '0' : '1';
            btn.classList.toggle('is-on', !unfollow);
            bumpCount(unfollow ? -1 : 1);
            if (!unfollow) popBeat();
            fetch(EP.follow, { method: unfollow ? 'DELETE' : 'POST', headers: tokenHeaders(true), body: '{}' })
                .then(function (r) { return r.json(); }).then(function (d) {
                    if (!d || typeof d.following === 'undefined') { throw new Error('bad'); }
                    applyFollowState(d);
                }).catch(function () {
                    btn.dataset.following = prevFollowing ? '1' : '0';
                    btn.classList.toggle('is-on', prevFollowing);
                    bumpCount(unfollow ? 1 : -1);
                    toast(L10N.action_failed || L10N.failed);
                });
        }
        // B6: the "Following" management drawer — supersedes any two-tap guard when the
        // button is already is-on. Notifications toggle writes the follow row's notify via
        // the same follow endpoint ({notify: 0|1}); Unfollow runs the optimistic unfollow.
        const notifyBox = document.getElementById('cvFollowNotify');
        function openFollowingSheet() {
            if (notifyBox) notifyBox.checked = config.followNotify !== false;
            openDrawer('cvFollowingSheet');
        }
        if (notifyBox) {
            notifyBox.addEventListener('change', function () {
                const want = notifyBox.checked;
                fetch(EP.follow, { method: 'POST', headers: tokenHeaders(true), body: JSON.stringify({ notify: want ? 1 : 0 }) })
                    .then(function (r) { return r.json(); }).then(function (d) {
                        if (d && typeof d.notify !== 'undefined') { config.followNotify = !!d.notify; notifyBox.checked = !!d.notify; }
                        else { throw new Error('bad'); }
                    }).catch(function () { notifyBox.checked = !want; toast(L10N.action_failed || L10N.failed); });
            });
        }
        bindDrawerClose('cvFollowingSheet', 'cvFollowingClose', 'cvFollowingBackdrop');
        const unfollowBtn = document.getElementById('cvFollowUnfollow');
        if (unfollowBtn) unfollowBtn.addEventListener('click', function () { closeDrawer('cvFollowingSheet'); doFollow(true); });
        const cancelBtn = document.getElementById('cvFollowCancel');
        if (cancelBtn) cancelBtn.addEventListener('click', function () { closeDrawer('cvFollowingSheet'); });

        btn.addEventListener('click', function () {
            // B4: guest tap records a follow intent, then opens the auth drawer. After a
            // successful sign-in the page reloads and the intent is replayed below.
            if (!hasToken()) {
                try { sessionStorage.setItem(FOLLOW_INTENT, '1'); } catch (e) {}
                openAuthDrawer();
                return;
            }
            if (btn.dataset.following === '1') {
                openFollowingSheet(); // B6: manage notifications / unfollow
            } else {
                doFollow(false);
            }
        });

        // B4: replay a pending follow intent once the visitor is signed in (post-reload).
        (function () {
            let pending = false;
            try { pending = sessionStorage.getItem(FOLLOW_INTENT) === '1'; } catch (e) {}
            if (!pending || !hasToken()) return;
            try { sessionStorage.removeItem(FOLLOW_INTENT); } catch (e) {}
            if (btn.dataset.following === '1') return;
            fetch(EP.follow, { method: 'POST', headers: tokenHeaders(true), body: '{}' })
                .then(function (r) { return r.json(); }).then(function (d) {
                    if (applyFollowState(d) && d.following) { toast(L10N.now_following || '✓'); }
                }).catch(function () {});
        })();
    })();

    /* ── F2b · about drawer (B8) ──────────────────────────── */
    (function () {
        if (!document.getElementById('cvAboutSheet')) return;
        bindDrawerClose('cvAboutSheet', 'cvAboutClose', 'cvAboutBackdrop');
        function openAbout() { openDrawer('cvAboutSheet'); }
        const info = document.querySelector('.v-brand-details');
        if (info) {
            // P3-13 axe: a group, not a button — the follow button and follower links sit inside it
            // (a button may not contain other controls). Click and Enter/Space still open the drawer.
            info.setAttribute('role', 'group');
            info.setAttribute('tabindex', '0');
            info.style.cursor = 'pointer';
            info.addEventListener('click', function (e) {
                // don't hijack taps on the follow button / follower count links inside
                if (e.target.closest('#cvFollowBtn')) return;
                openAbout();
            });
            info.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openAbout(); } });
        }
        // The logo opens stories when it wears the live ring (B7); otherwise the about drawer.
        const logo = document.querySelector('.v-restaurant-logo');
        if (logo) logo.addEventListener('click', function () { if (!logo.classList.contains('v-restaurant-logo--live')) openAbout(); });
    })();

    /* ── F3 · stories ─────────────────────────────────────── */
    (function () {
        const rail = document.getElementById('cvStoriesRail');
        if (!rail || !EP.stories) return;
        let stories = [], idx = 0, timer = null, T = 0;
        const DUR = 5;

        (window.mbStoriesGet?window.mbStoriesGet(EP.stories):fetch(EP.stories).then(function(r){return r.json();})).then(function (d) {
            stories = (d && d.data) || [];
            if (!stories.length) { rail.hidden = true; return; }
            rail.hidden = false;
            rail.innerHTML = stories.map(function (s, i) {
                return '<button class="v-story-bubble" data-si="' + i + '" type="button" aria-label="' + esc((s.caption ? String(s.caption) : (rail.getAttribute('data-story-label') || 'Story')) + ' ' + (i + 1)) + '">' +
                    '<span class="v-sb-ring"><img src="' + esc(s.media_url) + '" alt=""' + IMG_ERR + '></span>' +
                    '<small>' + esc(s.caption ? String(s.caption).slice(0, 14) : '') + '</small></button>';
            }).join('');
            // B7: with stories live, the brand logo gets the story ring and opens the
            // viewer at index 0 (same call the first story bubble uses). No stories → no
            // change, so an untouched store renders identically.
            const brandLogo = document.querySelector('.v-restaurant-logo');
            if (brandLogo && !brandLogo.classList.contains('v-restaurant-logo--live')) {
                brandLogo.classList.add('v-restaurant-logo--live');
                brandLogo.setAttribute('role', 'button');
                brandLogo.setAttribute('tabindex', '0');
                brandLogo.setAttribute('aria-label', L10N.view_stories || '');
                brandLogo.addEventListener('click', function () { open(0); });
                brandLogo.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(0); } });
            }
        }).catch(function () { rail.hidden = true; });

        const viewer = document.getElementById('cvStoryViewer'),
            media = document.getElementById('cvSvMedia'),
            cap = document.getElementById('cvSvCaption'),
            cta = document.getElementById('cvSvCta'),
            prog = document.getElementById('cvSvProgress');
        function buildProgress() { prog.innerHTML = stories.map(function () { return '<span class="v-sv-seg"><i></i></span>'; }).join(''); }
        function paintSeg() {
            prog.querySelectorAll('.v-sv-seg i').forEach(function (bar, i) {
                bar.style.width = i < idx ? '100%' : (i === idx ? (T / DUR * 100) + '%' : '0%');
            });
        }
        function render() {
            const s = stories[idx]; if (!s) return;
            media.src = s.media_url;
            cap.textContent = s.caption || '';
            if (s.cta_url && !(window.mbStoryCta && window.mbStoryCta.isCurrentPage(s.cta_url))) {
                cta.hidden = false; cta.href = s.cta_url;
 if (window.mbStoryCta) window.mbStoryCta.apply(cta, s.cta_url);
                cta.querySelector('span').textContent = s.cta_label || (L10N.add || 'Order now');
            } else { cta.hidden = true; }
            T = 0; paintSeg();
        }
        function tick() { T += 0.05; if (T >= DUR) { next(); return; } paintSeg(); }
        function start() { stop(); render(); timer = setInterval(tick, 50); }
        function stop() { if (timer) { clearInterval(timer); timer = null; } }
        function open(i) {
            idx = i; buildProgress();
            viewer.hidden = false;
            void viewer.offsetWidth; // forced reflow so the transition runs
            viewer.classList.add('is-open');
            setScrollLock(true);
            start();
        }
        function close() {
            stop();
            viewer.classList.remove('is-open');
            setScrollLock(false);
            setTimeout(function () { viewer.hidden = true; }, 300);
        }
        function next() { if (idx < stories.length - 1) { idx++; render(); } else { close(); } }
        function prev() { if (idx > 0) { idx--; render(); } else { T = 0; } }

        rail.addEventListener('click', function (e) {
            const b = e.target.closest('[data-si]'); if (b) open(Number(b.dataset.si));
        });
        const closeBtn = document.getElementById('cvSvClose'); if (closeBtn) closeBtn.addEventListener('click', close);
        /* The CTA is an in-page ?item deep link: the delegated menu-anchor handler
           opens the item sheet and prevents navigation, so the URL never changes.
           But the viewer sits at z-index:3000 while the item drawer stacks at the
           theme's default, so the sheet opened UNDER the still-playing story and
           "Order now" looked dead until the story auto-advanced off the top. Close
           the viewer on the same click (stop the timer, release the scroll lock)
           so the item sheet is the top layer immediately. Runs alongside the
           anchor handler; it only tears down the viewer. */
        if (cta) cta.addEventListener('click', close);
        const nextBtn = document.getElementById('cvSvNext'); if (nextBtn) nextBtn.addEventListener('click', next);
        const prevBtn = document.getElementById('cvSvPrev'); if (prevBtn) prevBtn.addEventListener('click', prev);
        const stage = document.getElementById('cvSvStage');
        if (stage) {
            stage.addEventListener('pointerdown', stop);
            stage.addEventListener('pointerup', function () { if (!viewer.hidden) timer = setInterval(tick, 50); });
        }
    })();

    /* ── F5 · address autocomplete + use-my-location ──────── */
    (function () {
        const input = document.getElementById('v-address'),
            box = document.getElementById('cvAddrSuggest'),
            attrib = document.getElementById('cvGeoAttrib'),
            latEl = document.getElementById('cvCustLat'),
            lngEl = document.getElementById('cvCustLng');
        if (!input || !EP.geoAutocomplete) return;
        let t = null, lastAttrib = '';
        function setAttrib(a) { if (a && a !== lastAttrib) { attrib.textContent = a; attrib.hidden = false; lastAttrib = a; } }
        function hideBox() { box.hidden = true; box.innerHTML = ''; }
        input.addEventListener('input', function () {
            const q = input.value.trim();
            latEl.value = ''; lngEl.value = '';
            if (t) clearTimeout(t);
            if (q.length < 3) { hideBox(); return; }
            t = setTimeout(function () {
                fetch(EP.geoAutocomplete + '?q=' + encodeURIComponent(q))
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        setAttrib(d && d.attribution);
                        const res = (d && d.results) || [];
                        if (!res.length) { hideBox(); return; }
                        box.innerHTML = res.map(function (r) {
                            return '<button class="v-addr-opt" data-lat="' + (r.lat || '') + '" data-lng="' + (r.lng || '') +
                                '" data-label="' + esc(r.label) + '" type="button">' + esc(r.label) + '</button>';
                        }).join('');
                        box.hidden = false;
                    }).catch(hideBox);
            }, 350);
        });
        box.addEventListener('click', function (e) {
            const opt = e.target.closest('.v-addr-opt'); if (!opt) return;
            input.value = opt.dataset.label;
            latEl.value = opt.dataset.lat || '';
            lngEl.value = opt.dataset.lng || '';
            hideBox();
        });
        input.addEventListener('blur', function () { setTimeout(hideBox, 200); });
        // Saved-address chips (logged-in): fill the field + hidden coords on tap.
        const savedWrap = document.getElementById('cvSavedAddrs');
        if (savedWrap) savedWrap.addEventListener('click', function (e) {
            const chip = e.target.closest('.v-saved-addr'); if (!chip) return;
            savedWrap.querySelectorAll('.v-saved-addr').forEach(function (c) { c.classList.toggle('is-on', c === chip); });
            input.value = chip.getAttribute('data-addr') || '';
            latEl.value = chip.getAttribute('data-lat') || '';
            lngEl.value = chip.getAttribute('data-lng') || '';
            hideBox();
        });
        const useLoc = document.getElementById('cvUseLocation');
        if (useLoc) useLoc.addEventListener('click', function () {
            if (!navigator.geolocation) { toast(L10N.locationUnavailable); return; }
            useLoc.classList.add('is-loading');
            navigator.geolocation.getCurrentPosition(function (pos) {
                const lat = pos.coords.latitude, lng = pos.coords.longitude;
                fetch(EP.geoReverse + '?lat=' + lat + '&lng=' + lng)
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        useLoc.classList.remove('is-loading');
                        setAttrib(d && d.attribution);
                        if (d && d.result) {
                            input.value = d.result.label || '';
                            latEl.value = d.result.lat || lat;
                            lngEl.value = d.result.lng || lng;
                        } else { toast(L10N.noAddress); }
                    }).catch(function () { useLoc.classList.remove('is-loading'); });
            }, function () { useLoc.classList.remove('is-loading'); toast(L10N.locationDenied); }, { enableHighAccuracy: true, timeout: 8000 });
        });
    })();

    /* ── F4 · nearest-branch map ──────────────────────────── */
    (function () {
        const openBtn = document.getElementById('cvNearestBranchBtn');
        if (!openBtn || !EP.branchesGeo || typeof L === 'undefined') { if (openBtn) openBtn.style.display = 'none'; return; }
        bindDrawerClose('v-branchmap-drawer', 'v-branchmap-close', 'v-branchmap-backdrop');
        let map = null, markers = [];
        const icon = L.icon({
            iconUrl: config.leafletImgPath + '/marker-icon.png',
            iconRetinaUrl: config.leafletImgPath + '/marker-icon-2x.png',
            shadowUrl: config.leafletImgPath + '/marker-shadow.png',
            iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
        });
        function selectBranch(id) {
            const url = new URL(window.location.href);
            url.searchParams.set('branch_id', id);
            window.location.href = url.toString();
        }
        function renderList(branches) {
            document.getElementById('cvBranchGeoList').innerHTML = branches.map(function (b) {
                const dist = b.distance_km != null ? '<span class="v-branch-dist" dir="ltr">' + b.distance_km.toFixed(1) + ' km</span>' : '';
                return '<button class="v-branch-geo-row" data-bid="' + b.id + '" type="button">' +
                    '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
                    '<b>' + esc(b.name) + (b.address ? '<small>' + esc(b.address) + '</small>' : '') + '</b>' + dist + '</button>';
            }).join('') || '<p style="color:var(--v-text-muted);text-align:center;padding:14px 0">—</p>';
        }
        function drawMap(branches) {
            const withCoords = branches.filter(function (b) { return b.latitude != null && b.longitude != null; });
            if (!map) {
                map = L.map('cvBranchMap', { attributionControl: false });
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
                L.control.attribution({ prefix: false }).addAttribution('© OpenStreetMap').addTo(map);
            }
            markers.forEach(function (m) { map.removeLayer(m); }); markers = [];
            if (!withCoords.length) { map.setView([24.7136, 46.6753], 11); return; }
            const group = [];
            withCoords.forEach(function (b) {
                const mk = L.marker([b.latitude, b.longitude], { icon: icon }).addTo(map);
                mk.bindPopup('<b>' + esc(b.name) + '</b>');
                mk.on('click', function () { selectBranch(b.id); });
                markers.push(mk); group.push([b.latitude, b.longitude]);
            });
            map.fitBounds(group, { padding: [40, 40], maxZoom: 14 });
        }
        function load(lat, lng) {
            const url = EP.branchesGeo + (lat != null ? '?lat=' + lat + '&lng=' + lng : '');
            fetch(url).then(function (r) { return r.json(); }).then(function (d) {
                const branches = (d && d.branches) || [];
                document.getElementById('cvMapAttrib').textContent = (d && d.attribution) || '© OpenStreetMap contributors';
                renderList(branches);
                setTimeout(function () { drawMap(branches); if (map) map.invalidateSize(); }, 80);
            }).catch(function () {});
        }
        openBtn.addEventListener('click', function () {
            openDrawer('v-branchmap-drawer');
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function (p) { load(p.coords.latitude, p.coords.longitude); },
                    function () { load(); }, { timeout: 6000 });
            } else { load(); }
        });
        document.getElementById('cvBranchGeoList').addEventListener('click', function (e) {
            const b = e.target.closest('[data-bid]'); if (b) selectBranch(b.dataset.bid);
        });
    })();

    /* ── F6 · rich reviews ────────────────────────────────── */
    (function () {
        const wrap = document.getElementById('cvReviews'); // re-resolved on each open below
        if (!EP.reviewsBase) return;
        let curFilter = 'latest', curItemId = null, rvStars = 0;
        bindDrawerClose('v-review-drawer', 'v-review-close', 'v-review-backdrop');

        function starBar(n) { return '<span class="v-stars-mini">' + '★'.repeat(n) + '<span class="v-s-dim">' + '★'.repeat(5 - n) + '</span></span>'; }
        function render(d) {
            const el = document.getElementById('cvReviews');
            if (!el) return;
            const avg = d.average || 0, count = d.count || 0, dist = d.distribution || {};
            const head = '<div class="v-rv-head"><div class="v-rv-avg"><b>' + avg.toFixed(1) + '</b>' + starBar(Math.round(avg)) +
                '<small>' + count + ' ' + esc(L10N.reviews || 'reviews') + '</small></div>' +
                '<div class="v-rv-dist">' + [5, 4, 3, 2, 1].map(function (s) {
                    const c = dist[s] || 0, pct = count ? Math.round(c / count * 100) : 0;
                    return '<span class="v-rv-bar"><em>' + s + '</em><i><s style="width:' + pct + '%"></s></i><b>' + c + '</b></span>';
                }).join('') + '</div></div>';
            const filterKey = { latest: L10N.rvLatest, verified: L10N.rvVerified, detailed: L10N.rvDetailed };
            const filters = '<div class="v-rv-filters">' + ['latest', 'verified', 'detailed'].map(function (f) {
                return '<button data-rvf="' + f + '" class="' + (f === curFilter ? 'is-on' : '') + '" type="button">' + esc(filterKey[f] || f) + '</button>';
            }).join('') + '</div>';
            let list = (d.data || []).map(function (r) {
                const photos = (r.photos || []).map(function (p) { return '<img src="' + esc(p.url) + '" alt=""' + IMG_ERR + '>'; }).join('');
                return '<div class="v-rv-item"><div class="v-rv-item-top"><b>' + esc(r.name || '—') + '</b>' +
                    (r.is_verified ? '<span class="v-rv-verified">✓ ' + esc(L10N.rvVerified || 'Verified') + '</span>' : '') + '</div>' +
                    starBar(r.stars) + (r.body ? '<p>' + esc(r.body) + '</p>' : '') +
                    (photos ? '<div class="v-rv-photos">' + photos + '</div>' : '') + '</div>';
            }).join('');
            if (!(d.data || []).length) list = '<p class="v-rv-empty">' + esc(L10N.rvEmpty || 'No reviews yet') + '</p>';
            el.innerHTML = '<div class="v-rv-section-head"><h4>' + esc(L10N.reviews || 'Reviews') + '</h4>' +
                '<button class="v-rv-write" id="cvWriteReview" type="button">' + esc(L10N.rvWrite || 'Write') + '</button></div>' +
                head + filters + '<div class="v-rv-list">' + list + '</div>';
            el.hidden = false;
        }
        function load(itemId, filter) {
            curItemId = itemId; curFilter = filter || 'latest';
            fetch(EP.reviewsBase + '/' + itemId + '/reviews?filter=' + curFilter)
                .then(function (r) { return r.json(); }).then(render)
                .catch(function () { const el = document.getElementById('cvReviews'); if (el) el.hidden = true; });
        }
        window.__velvetLoadReviews = function (id) {
            const el = document.getElementById('cvReviews');
            if (el) { el.hidden = true; el.innerHTML = ''; }
            load(id, 'latest');
        };
        // event delegation on detail content (survives re-renders)
        document.addEventListener('click', function (e) {
            const f = e.target.closest('[data-rvf]');
            if (f && document.getElementById('cvReviews') && document.getElementById('cvReviews').contains(f)) { load(curItemId, f.dataset.rvf); return; }
            if (e.target.closest('#cvWriteReview')) { openReview(); }
        });

        function openReview() {
            if (!curItemId) return;
            rvStars = 0;
            document.querySelectorAll('#cvReviewStars .v-star-btn').forEach(function (b) { b.classList.remove('v-star-on'); });
            const txt = document.getElementById('cvReviewText'); if (txt) txt.value = '';
            const nm = document.getElementById('cvReviewName'); if (nm) nm.value = '';
            document.getElementById('cvReviewErr').style.display = 'none';
            const it = config.items[curItemId];
            document.getElementById('cvReviewItemName').textContent = it ? it.name : '';
            openDrawer('v-review-drawer');
        }
        document.querySelectorAll('#cvReviewStars .v-star-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                rvStars = Number(b.dataset.star);
                document.querySelectorAll('#cvReviewStars .v-star-btn').forEach(function (x, i) { x.classList.toggle('v-star-on', i < rvStars); });
            });
        });
        const submit = document.getElementById('cvReviewSubmit');
        if (submit) submit.addEventListener('click', function () {
            const err = document.getElementById('cvReviewErr');
            if (!rvStars) { err.textContent = L10N.choose || 'Choose a rating'; err.style.display = 'block'; return; }
            const body = { rating: rvStars, text: document.getElementById('cvReviewText').value.trim() };
            const nm = document.getElementById('cvReviewName');
            if (nm && nm.value.trim()) body.name = nm.value.trim();
            submit.disabled = true;
            fetch(EP.reviewsBase + '/' + curItemId + '/reviews', { method: 'POST', headers: tokenHeaders(true), body: JSON.stringify(body) })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    submit.disabled = false;
                    if (d && d.ok) {
                        closeDrawer('v-review-drawer');
                        toast(L10N.thankYou || 'Thanks!');
                        load(curItemId, curFilter);
                    } else { err.textContent = (d && d.message) || L10N.failed; err.style.display = 'block'; }
                })
                .catch(function () { submit.disabled = false; err.textContent = L10N.networkError; err.style.display = 'block'; });
        });
    })();

    /* ── F1 · meal-plan create drawer ─────────────────────── */
    (function () {
        const cta = document.getElementById('cvMealPlanCta');
        if (!cta) return;
        if (!config.mealPlansEnabled) { cta.style.display = 'none'; return; }
        const drawer = document.getElementById('v-mealplan-drawer');
        bindDrawerClose('v-mealplan-drawer', 'v-mealplan-close', 'v-mealplan-backdrop');
        let cadence = 'weekly', cadences = [], days = {};
        try { cadences = JSON.parse((drawer && drawer.getAttribute('data-cadences')) || '[]'); } catch (e) {}
        if (!cadences.length) cadences = ['daily', 'weekly'];

        function label(c) { return ({ daily: L10N.cadDaily, weekly: L10N.cadWeekly, custom: L10N.cadCustom })[c] || c; }
        function buildCadence() {
            const el = document.getElementById('cvMpCadence'); if (!el) return;
            cadence = cadences.indexOf('weekly') !== -1 ? 'weekly' : cadences[0];
            el.innerHTML = cadences.map(function (c) {
                return '<button data-cad="' + c + '" class="' + (c === cadence ? 'is-on' : '') + '" type="button">' + esc(label(c)) + '</button>';
            }).join('');
        }
        const loginBtn = document.getElementById('cvMpLoginBtn');
        if (loginBtn) loginBtn.addEventListener('click', function () { closeDrawer('v-mealplan-drawer'); openAuthDrawer(); });

        cta.addEventListener('click', function () {
            if (!cart.length) { toast(L10N.cartEmpty); return; }
            if (!hasToken()) { openAuthDrawer(); return; }
            buildCadence();
            const sum = document.getElementById('cvMpItemsSummary');
            if (sum) sum.textContent = cart.reduce(function (a, l) { return a + l.quantity; }, 0) + ' × ' + (L10N.items || 'items');
            const dw = document.getElementById('cvMpDaysWrap'); if (dw) dw.hidden = cadence !== 'custom';
            closeCartDrawer();
            openDrawer('v-mealplan-drawer');
        });
        if (drawer) drawer.addEventListener('click', function (e) {
            const c = e.target.closest('[data-cad]');
            if (c) {
                cadence = c.dataset.cad;
                drawer.querySelectorAll('#cvMpCadence button').forEach(function (b) { b.classList.toggle('is-on', b === c); });
                const dw = document.getElementById('cvMpDaysWrap'); if (dw) dw.hidden = cadence !== 'custom';
                return;
            }
            const d = e.target.closest('[data-day]');
            if (d) { const k = d.dataset.day; days[k] = !days[k]; d.classList.toggle('is-on', days[k]); }
        });
        const mpSubmit = document.getElementById('cvMpSubmit');
        if (mpSubmit) mpSubmit.addEventListener('click', function () {
            const err = document.getElementById('cvMpErr'); err.style.display = 'none';
            const items = cart.map(function (l) {
                return { item_id: l.id, quantity: l.quantity, variation: l.variants || null, extras: (l.extras || []).map(function (e) { return e.id; }) };
            });
            if (!items.length) { err.textContent = L10N.cartEmpty; err.style.display = 'block'; return; }
            const body = {
                name: document.getElementById('cvMpName').value.trim() || null,
                cadence: cadence,
                start_date: document.getElementById('cvMpStart').value,
                items: items
            };
            if (cadence === 'custom') {
                body.days = Object.keys(days).filter(function (k) { return days[k]; }).map(Number);
                if (!body.days.length) { err.textContent = L10N.choose || 'Pick a day'; err.style.display = 'block'; return; }
            }
            mpSubmit.disabled = true;
            fetch(EP.mealPlan, { method: 'POST', headers: tokenHeaders(true), body: JSON.stringify(body) })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (res) {
                    mpSubmit.disabled = false;
                    if (res.ok && res.j && res.j.id) {
                        document.getElementById('cvMealPlanForm').hidden = true;
                        document.getElementById('cvMpDone').hidden = false;
                        document.getElementById('cvMpDoneSub').textContent = (L10N.mpNext || 'Next order') + ': ' + (res.j.next_run || '');
                    } else {
                        const msg = res.j && (res.j.message || (res.j.errors && Object.values(res.j.errors)[0][0]));
                        err.textContent = msg || L10N.failed; err.style.display = 'block';
                    }
                })
                .catch(function () { mpSubmit.disabled = false; err.textContent = L10N.networkError; err.style.display = 'block'; });
        });
    })();

    /* ── F7 · OTP checkout gate ───────────────────────────── */
    (function () {
        const boxes = Array.prototype.slice.call(document.querySelectorAll('#cvOtpGateBoxes input'));
        if (!boxes.length) return;
        bindDrawerClose('v-otp-drawer', 'v-otp-close', 'v-otp-backdrop');
        let curPhone = '';
        function code() { return boxes.map(function (b) { return b.value; }).join(''); }
        boxes.forEach(function (inp, i) {
            inp.addEventListener('input', function () {
                inp.value = inp.value.replace(/\D/g, '').slice(0, 1);
                if (inp.value && boxes[i + 1]) boxes[i + 1].focus();
            });
            inp.addEventListener('keydown', function (e) { if (e.key === 'Backspace' && !inp.value && boxes[i - 1]) boxes[i - 1].focus(); });
        });
        function send(phone) {
            fetch(EP.otpSend, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf },
                body: JSON.stringify({ phone: phone, store_id: config.storeId })
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d || !d.success) { const e = document.getElementById('cvOtpGateErr'); e.textContent = (d && d.message) || L10N.failed; e.style.display = 'block'; }
            }).catch(function () {});
        }
        window.__velvetOpenCheckoutOtp = function (phone) {
            curPhone = phone;
            document.getElementById('cvOtpGatePhone').textContent = phone;
            boxes.forEach(function (b) { b.value = ''; });
            document.getElementById('cvOtpGateErr').style.display = 'none';
            openDrawer('v-otp-drawer');
            setTimeout(function () { if (boxes[0]) boxes[0].focus(); }, 300);
            send(phone);
        };
        const resend = document.getElementById('cvOtpGateResend');
        if (resend) resend.addEventListener('click', function () { document.getElementById('cvOtpGateErr').style.display = 'none'; send(curPhone); toast(L10N.sent); });
        const verify = document.getElementById('cvOtpGateVerify');
        if (verify) verify.addEventListener('click', function () {
            const err = document.getElementById('cvOtpGateErr'); err.style.display = 'none';
            const c = code();
            if (c.length < 4) { err.textContent = L10N.required || 'Enter the code'; err.style.display = 'block'; return; }
            verify.disabled = true;
            fetch(EP.otpVerify, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf },
                body: JSON.stringify({ phone: curPhone, code: c, store_id: config.storeId })
            }).then(function (r) { return r.json(); }).then(function (d) {
                verify.disabled = false;
                if (d && d.success) {
                    closeDrawer('v-otp-drawer');
                    openCartDrawer();
                    if (elements.checkoutForm.requestSubmit) elements.checkoutForm.requestSubmit();
                    else handleCheckoutSubmit();
                } else { err.textContent = (d && d.message) || L10N.failed; err.style.display = 'block'; }
            }).catch(function () { verify.disabled = false; err.textContent = L10N.networkError; err.style.display = 'block'; });
        });
    })();

    /* ══════════════════════════════════════════════════════════════════════
       R5 guest order history · R6 guest multi-address · R7 ?item= deeplink
       (localStorage; ports of the crave recipes, adapted to velvet's ids) ──── */
    const V_SLUG = config.restaurantSlug || config.storeId;

    /* ── R5 · guest order history ─────────────────────────────
       The server only returns a guest's orders to a phone/email lookup, so a diner
       who just ordered saw nothing. Mirror the placed order into localStorage and
       list it (with its track link) in the guest recent-orders block. */
    const V_RECENT_ORDERS_KEY = 'v_recent_orders_' + V_SLUG;
    function readRecentOrders() {
        try { return (JSON.parse(localStorage.getItem(V_RECENT_ORDERS_KEY) || '[]') || []).filter(function (o) { return o && o.track_url; }); }
        catch (e) { return []; }
    }
    function rememberOrder(res) {
        if (!res || !res.track_url) return;
        var list = readRecentOrders().filter(function (o) { return o.track_url !== res.track_url; });
        list.unshift({ id: res.order_id || '', track_url: res.track_url, total: res.total || '', placed_at: Date.now() });
        try { localStorage.setItem(V_RECENT_ORDERS_KEY, JSON.stringify(list.slice(0, 10))); } catch (e) {}
        renderRecentOrders();
    }
    function renderRecentOrders() {
        // The block is server-omitted for signed-in customers (they get the account
        // drawer's Order History link instead), so guests only.
        var block = document.getElementById('vRecentOrdersBlock');
        var box = document.getElementById('vRecentOrders');
        if (!block || !box) return;
        var list = readRecentOrders();
        if (!list.length) { block.hidden = true; return; }
        box.innerHTML = list.map(function (o) {
            var when = new Date(o.placed_at || Date.now()).toLocaleDateString(document.documentElement.lang || 'en', { day: '2-digit', month: 'short' });
            var head = o.id ? ('#' + esc(o.id) + (o.total ? ' · ' + esc(o.total) : '')) : esc(when);
            return '<a class="v-recent-order" href="' + esc(o.track_url) + '">' +
                '<span class="v-recent-order-info"><b>' + head + '</b><small>' + esc(when) + '</small></span>' +
                '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m9 6 6 6-6 6"/></svg></a>';
        }).join('');
        block.hidden = false;
    }

    /* ── R6 · guest multi-address ─────────────────────────────
       velvet shipped saved-address chips for logged-in diners only. Give guests the
       same convenience from localStorage; the F5 IIFE already delegates .v-saved-addr
       clicks on #cvSavedAddrs to fill the field + hidden coords, so these chips wire
       up for free. CRITICAL: compute the key via a function — a `var KEY` declared
       after this section is hoisted-but-undefined when renderRecentAddrs runs at boot
       (the var-hoist gap → 0 chips). */
    function addrKey() { return 'v_recent_addrs_' + V_SLUG; }
    function readRecentAddrs() {
        try { return (JSON.parse(localStorage.getItem(addrKey()) || '[]') || []).filter(function (a) { return a && a.addr; }); }
        catch (e) { return []; }
    }
    function rememberAddress(addr, lat, lng) {
        addr = (addr || '').trim();
        if (!addr || config.isLoggedIn) return;   // guests only — never fight the server address book
        var list = readRecentAddrs().filter(function (a) { return a.addr !== addr; });
        list.unshift({ addr: addr, lat: lat || '', lng: lng || '' });
        try { localStorage.setItem(addrKey(), JSON.stringify(list.slice(0, 5))); } catch (e) {}
    }
    function renderRecentAddrs() {
        var saved = document.getElementById('cvSavedAddrs');
        if (!saved || config.isLoggedIn) return;   // logged-in chips are server-rendered
        var list = readRecentAddrs();
        if (!list.length) { saved.hidden = true; return; }
        saved.hidden = false;
        saved.innerHTML = list.map(function (a, i) {
            var short = a.addr.length > 42 ? (a.addr.slice(0, 42) + '…') : a.addr;
            return '<button class="v-saved-addr' + (i === 0 ? ' is-on' : '') + '" type="button"' +
                ' data-addr="' + esc(a.addr) + '" data-lat="' + esc(a.lat) + '" data-lng="' + esc(a.lng) + '">' +
                '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
                '<span class="v-sa-text"><b>' + esc(short) + '</b></span></button>';
        }).join('');
    }

    /* ── R7 · same-page ?item= deeplink / SPA interceptor ─────
       A promo banner / CTA that deep-links with ?item=<id> on THIS page reloaded the
       whole page just to reopen a product the theme can show instantly. Intercept the
       same-page link and open in place; real external links fall through. */
    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('a[href*="item="]') : null;
        if (!link || link.target) return;
        try {
            var u = new URL(link.getAttribute('href') || '', window.location.href);
            var iid = u.searchParams.get('item');
            if (iid && u.pathname === window.location.pathname && config.items[iid]) {
                e.preventDefault();
                openDetailDrawer(iid);
            }
        } catch (err) {}
    });

    /* boot: paint stored lists, then open any ?item= product in place. */
    renderRecentOrders();
    renderRecentAddrs();
    try {
        var deepItem = new URLSearchParams(window.location.search).get('item');
        if (deepItem && config.items[deepItem]) openDetailDrawer(deepItem);
    } catch (e) {}

})();

/* ── VELVET 3.1 · bottom dock wiring — thin launchers over existing controls ── */
(function () {
    var dock = document.getElementById('vDock');
    if (!dock) return;
    function on(btn) {
        dock.querySelectorAll('.v-dock-btn').forEach(function (b) { b.classList.remove('is-on'); });
        btn.classList.add('is-on');
    }
    dock.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-vdock]');
        if (!btn) return;
        var act = btn.getAttribute('data-vdock');
        if (act === 'home') { window.scrollTo({ top: 0, behavior: 'smooth' }); on(btn); }
        else if (act === 'menu') {
            var m = document.querySelector('[data-mb-grid-lead]') || document.querySelector('.v-menu-main');
            if (m) m.scrollIntoView({ behavior: 'smooth', block: 'start' });
            on(btn);
        }
        else if (act === 'search') {
            var bar = document.querySelector('.v-nav-bar-sticky');
            if (bar) bar.scrollIntoView({ behavior: 'smooth', block: 'start' });
            var inp = document.getElementById('v-search-input');
            if (inp) setTimeout(function () { inp.focus(); }, 350);
            on(btn);
        }
        else if (act === 'cart') {
            var t = document.getElementById('v-cart-toggle');
            if (t) t.click();
        }
    });
    /* mirror the floating pill's badge into the dock */
    var src = document.querySelector('#v-cart-toggle .v-cart-badge');
    var dst = document.getElementById('vDockCartCount');
    function sync() {
        if (!src || !dst) return;
        var n = parseInt(src.textContent, 10) || 0;
        dst.textContent = n;
        dst.hidden = n < 1;
    }
    if (src && dst && window.MutationObserver) {
        new MutationObserver(sync).observe(src, { childList: true, characterData: true, subtree: true });
        sync();
    }
})();

/* ═══ Favorites (velvet shipped with none) ═══
   Hearts on the blade menu cards persist to localStorage and feed a favourites
   drawer opened from the dock. Self-contained: reads the DOM cards and reuses the
   theme's own ?item= open handler, so it needs nothing from the main closure. */
(function () {
    var cfg = window.VELVET || {};
    var KEY = 'velvet-favs-' + (cfg.restaurantSlug || cfg.storeId || 'default');
    var favs;
    try { favs = new Set((JSON.parse(localStorage.getItem(KEY) || '[]') || []).map(String)); } catch (e) { favs = new Set(); }
    function persist() { try { localStorage.setItem(KEY, JSON.stringify(Array.from(favs))); } catch (e) {} }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function paint() {
        document.querySelectorAll('.v-fav-btn[data-vfav]').forEach(function (b) {
            b.classList.toggle('is-fav', favs.has(String(b.getAttribute('data-vfav'))));
        });
    }
    function cardData(id) {
        var card = document.querySelector('.v-menu-card[data-id="' + id + '"]');
        if (!card) return null;
        var n = card.querySelector('.v-card-name'), img = card.querySelector('.v-card-img'), p = card.querySelector('.v-card-price-now');
        return { name: n ? n.textContent.trim() : '', img: img ? img.getAttribute('src') : '', price: p ? p.textContent.trim() : '' };
    }
    var drawer = document.getElementById('v-fav-drawer');
    var list = document.getElementById('v-fav-list');
    var empty = document.getElementById('v-fav-empty');
    function render() {
        if (!list) return;
        var ids = Array.from(favs).filter(function (id) { return document.querySelector('.v-menu-card[data-id="' + id + '"]'); });
        if (!ids.length) { list.innerHTML = ''; if (empty) empty.style.display = ''; return; }
        if (empty) empty.style.display = 'none';
        list.innerHTML = ids.map(function (id) {
            var d = cardData(id); if (!d) return '';
            return '<a class="v-fav-row" href="?item=' + encodeURIComponent(id) + '">' +
                (d.img ? '<img class="v-fav-row-img" src="' + esc(d.img) + '" alt="">' : '<span class="v-fav-row-img"></span>') +
                '<span class="v-fav-row-body"><span class="v-fav-row-name">' + esc(d.name) + '</span>' +
                '<span class="v-fav-row-price">' + esc(d.price) + '</span></span></a>';
        }).join('');
    }
    function open() { render(); if (drawer) { drawer.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; } }
    function close() { if (drawer) { drawer.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; } }

    document.addEventListener('click', function (e) {
        var h = e.target.closest && e.target.closest('.v-fav-btn[data-vfav]');
        if (!h) return;
        e.preventDefault(); e.stopPropagation();
        var id = String(h.getAttribute('data-vfav'));
        if (favs.has(id)) favs.delete(id); else favs.add(id);
        persist(); paint();
        if (drawer && drawer.getAttribute('aria-hidden') === 'false') render();
    }, true);

    var dockFav = document.querySelector('#vDock [data-vdock="favs"]');
    if (dockFav) dockFav.addEventListener('click', open);
    var dockProfile = document.querySelector('#vDock [data-vdock="profile"]');
    if (dockProfile) dockProfile.addEventListener('click', function () { var a = document.getElementById('v-account-open') || document.getElementById('v-auth-open'); if (a) a.click(); });
    var cb = document.getElementById('v-fav-close'); if (cb) cb.addEventListener('click', close);
    var bd = document.getElementById('v-fav-backdrop'); if (bd) bd.addEventListener('click', close);
    if (list) list.addEventListener('click', function (e) { if (e.target.closest('.v-fav-row')) close(); });

    paint();
})();
