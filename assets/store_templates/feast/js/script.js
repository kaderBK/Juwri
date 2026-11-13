// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Feast QR Menu Theme — javascript controller
 * (the header read "Velvet" — a copy-paste leftover from a different theme)
 */

(function () {
    'use strict';

    // State management
    const config = window.FEAST;
    if (!config) return;

    const storageKey = `ff_cart_${config.storeId}`;
    // Stable per-store slug for localStorage/sessionStorage keys (guest orders,
    // guest addresses, splash gate). Top-level so it's ready before any IIFE runs.
    const slug = config.restaurantSlug || config.storeId;
    let cart = loadCart();
    let currentDetailItem = null;
    let currentDetailQty = 1;
    // coupon (crave contract: store type + percentage/fixed, recompute per cart change)
    let couponCode = '';
    let couponType = '';   // 'percentage' | 'fixed'
    let couponPct = 0;     // percentage OR fixed amount depending on couponType
    let couponDiscount = 0; // bug batch 7 · 4: buy-X-get-Y — the server's discount for the cart lines
    let couponSigSent = ''; // the cart + order type that discount was quoted for
    // tips
    let tipPct = 0;        // percent preset (0 = none/custom)
    let tipCustom = 0;     // absolute custom tip
    // two-tier category nav filter state
    let activeCat = 'all';
    let activeSub = 'root';
    // dietary / spice / allergen filter state
    const dietFilters = { diets: [], allergens: [], spice: [] };
    // Multi-currency DISPLAY state (base = the store's own currency). Switching only
    // repaints what the diner sees; every total is computed and every order submitted
    // in the base currency, so the charged amount never changes.
    let curCode = config.baseCurrency;
    let curRate = 1;

    // Cache DOM Elements
    const elements = {
        app: document.getElementById('feast-app'),
        searchInput: document.getElementById('ff-search-input'),
        searchClear: document.getElementById('ff-search-clear'),
        catButtons: document.querySelectorAll('.ff-cat-btn'),
        menuSections: document.querySelectorAll('.ff-menu-section'),
        menuCards: document.querySelectorAll('.ff-menu-card'),
        
        detailDrawer: document.getElementById('ff-detail-drawer'),
        detailContent: document.getElementById('ff-detail-content'),
        detailClose: document.getElementById('ff-detail-close'),
        detailBackdrop: document.getElementById('ff-detail-backdrop'),
        
        cartToggle: document.getElementById('ff-cart-toggle'),
        cartBadge: document.querySelector('.ff-cart-badge'),
        cartTotalPrice: document.querySelector('.ff-cart-btn-price'),
        
        cartDrawer: document.getElementById('ff-cart-drawer'),
        cartBackdrop: document.getElementById('ff-cart-backdrop'),
        cartClose: document.getElementById('ff-cart-close'),
        cartItemsList: document.getElementById('ff-cart-items-list'),
        
        couponCodeInput: document.getElementById('ff-coupon-code'),
        couponApplyBtn: document.getElementById('ff-coupon-apply-btn'),
        couponMsg: document.getElementById('ff-coupon-msg'),
        
        sumSubtotal: document.getElementById('ff-sum-subtotal'),
        sumDelivery: document.getElementById('ff-sum-delivery'),
        sumDiscount: document.getElementById('ff-sum-discount'),
        sumTotal: document.getElementById('ff-sum-total'),
        
        checkoutForm: document.getElementById('ff-checkout-form'),
        orderTypeTabs: document.querySelectorAll('.ff-order-type-tabs .ff-tab-btn'),
        orderTypeInput: document.getElementById('ff-order-type-input'),
        groupTable: document.getElementById('ff-group-table'),
        groupPhone: document.getElementById('ff-group-phone'),
        groupAddress: document.getElementById('ff-group-address'),
        deliveryRow: document.getElementById('ff-delivery-row'),
        discountRow: document.getElementById('ff-discount-row'),
        checkoutErrors: document.getElementById('ff-checkout-errors'),
        submitOrderBtn: document.getElementById('ff-submit-order-btn')
    };

    // Initialize
    init();

    function init() {
        bindEvents();
        pickupReorder();
        updateCartUI();
        initCatNav();
        initDefaultOrderType();
        initMotion();
        initTabBar();
        initSpecials();
        initTips();
        initWaiter();
        initFilterDrawer();
        initFace();
        initLangMenu();
        initCurrency();         // multi-currency display switcher (binds #curSeg handler)
        initSplash();
        renderRecentOrders();   // R5 · list this browser's past orders (guest block)
        initDeeplink();         // R7 · ?item= deeplink + same-page CTA intercept
        // Replay the saved display currency AFTER the first price render (cart UI above,
        // server-rendered grid already painted): clicks the stored option so the theme's
        // own handler repaints. No-op when nothing saved or that currency is gone.
        if (window.mbCurrency) mbCurrency.restore('#curSeg');
    }

    /* ── scroll-reveal + sticky chrome (motion) ── */
    function initMotion() {
        // reveal cards/hero on scroll into view
        var revealEls = document.querySelectorAll('.ff-reveal');
        if ('IntersectionObserver' in window && revealEls.length) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); }
                });
            }, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });
            revealEls.forEach(function (el) { io.observe(el); });
        } else {
            revealEls.forEach(function (el) { el.classList.add('is-in'); });
        }

        // shadow on app bar + sticky nav once the page scrolls
        var appbar = document.getElementById('ff-appbar');
        var stickyNav = document.getElementById('ff-sticky-nav');
        var onScroll = function () {
            var y = window.scrollY || document.documentElement.scrollTop;
            if (appbar) appbar.classList.toggle('is-stuck', y > 6);
            if (stickyNav) {
                var top = stickyNav.getBoundingClientRect().top;
                stickyNav.classList.toggle('is-stuck', top <= 66);
            }
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    /* ── glassy bottom tab bar ── */
    function initTabBar() {
        var bar = document.getElementById('ff-tabbar');
        if (!bar) return;
        var tabs = bar.querySelectorAll('.ff-tab');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var action = tab.getAttribute('data-tab');
                if (action === 'home') {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } else if (action === 'menu') {
                    var g = document.querySelector('[data-mb-grid-lead]') || document.querySelector('.ff-menu-grid');
                    if (g) g.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else if (action === 'search') {
                    openFfSearch();
                } else if (action === 'reserve') {
                    var ro = document.getElementById('ff-res-open') || document.getElementById('ff-waitlist-open');
                    if (ro) ro.click();
                } else if (action === 'cart') {
                    openCartDrawer();
                } else if (action === 'account') {
                    var ao = document.getElementById('ff-account-open') || document.getElementById('ff-auth-open');
                    if (ao) ao.click();
                }
                // active state only for the persistent Home/Menu/Search tabs
                if (action === 'home' || action === 'menu' || action === 'search') {
                    tabs.forEach(function (t) { t.classList.toggle('is-on', t === tab); });
                }
            });
        });
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

    /* COMBOS-V2 — the shared combos partial (_partials/combos.blade.php) keeps its
       own basket and publishes display state on window.mbCombos {count,total,lines}.
       The server prices combo lines into the ORDER subtotal (and taxes them like any
       other line), so the quote must fold them in too — QUOTED == CHARGED. Quantity
       edits stay in the combo sheet; here they render read-only. */
    function comboCount() { return (window.mbCombos && window.mbCombos.count) || 0; }
    function comboTotal() { return (window.mbCombos && window.mbCombos.total) || 0; }
    function comboLines() { return (window.mbCombos && window.mbCombos.lines) || []; }

    // Decimals follow the DISPLAYED currency, not the store's (velora money() contract):
    // decimalsByCode lists every non-2-decimal currency, so a code absent from it is 2
    // by definition — never fall back to this store's own decimals for a foreign display.
    function moneyDp(code) {
        var dp = code === config.baseCurrency ? config.currencyDecimals : (config.decimalsByCode || {})[code];
        dp = Number(dp);
        return isFinite(dp) ? dp : 2;
    }

    // Formatting Helpers
    function formatPrice(amount) {
        const v = (Number(amount) || 0) * curRate;
        // Non-base display: a converted estimate, prefixed "≈ CODE" (MC-34). Base
        // currency keeps the store's own sign/position formatting, byte-identical to before.
        if (curCode && curCode !== config.baseCurrency) {
            return `≈ ${curCode} ${v.toFixed(moneyDp(curCode))}`;
        }
        const formatted = v.toFixed(config.currencyDecimals ?? 2);
        if (config.currencyPos === '1') {
            return `${config.currencySign}${config.currencyGap || ''}${formatted}`;
        }
        return `${formatted} ${config.currencySign}`;
    }

    /* ── SEARCH-RUN: orbit-style full-screen search overlay ─────────
       Sticky search bar is now a button (#ff-search-open); typing happens in this
       overlay, which reads the server-rendered .ff-menu-card nodes and shows live
       result rows (tap → the item's detail drawer via its ?item= / data-open-item). */
    function ffEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function ffSearchItems() {
        return Array.prototype.slice.call(document.querySelectorAll('.ff-menu-card[data-id]')).map(function (card) {
            var n = card.querySelector('.ff-card-name'), d = card.querySelector('.ff-card-desc'),
                img = card.querySelector('.ff-card-img'), p = card.querySelector('.ff-card-price');
            return {
                id: card.getAttribute('data-id'),
                name: n ? n.textContent.trim() : '',
                desc: d ? d.textContent.trim() : '',
                price: p ? p.textContent.trim() : '',
                img: img ? (img.getAttribute('src') || '') : '',
                pop: !!card.querySelector('.ff-tag-pop')
            };
        });
    }
    function renderFfSearch() {
        var inp = document.getElementById('ff-sl-input');
        var box = document.getElementById('ff-sl-results');
        if (!inp || !box) return;
        var q = inp.value.trim().toLowerCase();
        var all = ffSearchItems();
        var pool = q ? all.filter(function (i) { return (i.name + ' ' + i.desc).toLowerCase().indexOf(q) !== -1; })
                     : all.filter(function (i) { return i.pop; }).slice(0, 12);
        if (!q && !pool.length) pool = all.slice(0, 12);
        box.innerHTML = pool.length ? pool.map(function (i, ix) {
            var sub = i.desc.length > 48 ? i.desc.slice(0, 47) + '…' : i.desc;
            return '<a class="ff-sl-row" href="?item=' + encodeURIComponent(i.id) + '" data-open-item="' + ffEsc(i.id) + '" style="animation-delay:' + Math.min(ix * 30, 300) + 'ms">' +
                (i.img ? '<img src="' + ffEsc(i.img) + '" alt="" loading="lazy">' : '<span class="ff-sl-noimg"></span>') +
                '<div><b>' + ffEsc(i.name) + '</b>' + (sub ? '<small>' + ffEsc(sub) + '</small>' : '') + '</div>' +
                '<span class="ff-sl-price">' + ffEsc(i.price) + '</span></a>';
        }).join('') : '<div class="ff-sl-none"><svg viewBox="0 0 64 64"><circle cx="28" cy="28" r="17"/><path d="m41 41 12 12"/></svg><p>' + ffEsc(box.getAttribute('data-none') || '') + '</p></div>';
    }
    function openFfSearch() {
        var l = document.getElementById('ff-search-layer'); if (!l) return;
        l.hidden = false; renderFfSearch();
        setTimeout(function () { l.classList.add('is-open'); var si = document.getElementById('ff-sl-input'); if (si) { try { si.focus(); } catch (e) {} } }, 20);
    }
    function closeFfSearch() {
        var l = document.getElementById('ff-search-layer'); if (!l) return;
        l.classList.remove('is-open');
        setTimeout(function () { l.hidden = true; }, 260);
        var si = document.getElementById('ff-sl-input'); if (si) si.value = '';
    }

    // Event Bindings
    function bindEvents() {
        // Search overlay (orbit-style)
        var ffso = document.getElementById('ff-search-open'); if (ffso) ffso.addEventListener('click', openFfSearch);
        var ffsc = document.getElementById('ff-sl-close'); if (ffsc) ffsc.addEventListener('click', closeFfSearch);
        var ffsi = document.getElementById('ff-sl-input'); if (ffsi) ffsi.addEventListener('input', renderFfSearch);
        var ffsr = document.getElementById('ff-sl-results');
        if (ffsr) ffsr.addEventListener('click', function (e) {
            var row = e.target.closest && e.target.closest('[data-open-item]');
            if (!row) return;
            e.preventDefault();
            closeFfSearch();
            openDetailDrawer(row.getAttribute('data-open-item'));
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { var l = document.getElementById('ff-search-layer'); if (l && !l.hidden) closeFfSearch(); } });
        if (elements.searchInput) {
            elements.searchInput.addEventListener('input', handleSearch);
            elements.searchClear.addEventListener('click', () => {
                elements.searchInput.value = '';
                elements.searchClear.style.display = 'none';
                handleSearch();
            });
        }

        // Category Navigation is handled by initCatNav() (two-tier filter)

        // Menu Cards click for details
        elements.menuCards.forEach(card => {
            card.addEventListener('click', (e) => {
                // If clicked on quick-add button, don't open detail
                if (e.target.closest('.ff-quick-add-btn')) return;
                // The title link (?item=ID) is the keyboard route into the card: open the drawer instead of navigating.
                if (e.target.closest('a')) e.preventDefault();
                const itemId = card.getAttribute('data-id');
                openDetailDrawer(itemId);
            });
            // P6-95: the card itself is no longer role=button/tabindex=0 (axe nested-interactive: it holds the favourite and
            // quick-add buttons and the title link), so there is nothing to focus on it: Enter/Space on those go to them.
            card.addEventListener('keydown', (e) => {
                if (e.target === card && (e.key === 'Enter' || e.key === ' ')) {
                    e.preventDefault();
                    openDetailDrawer(card.getAttribute('data-id'));
                }
            });
        });

        // Quick add button click
        document.querySelectorAll('.ff-quick-add-btn').forEach(btn => {
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

    /* ── Two-tier category nav (parent chips + per-parent sub-chip row) ──
       Ported from plate: parent chip filters by item.root; sub chip narrows by
       item.cat. Feast renders sections server-side, so we filter whole sections
       (each section = one real category with a known root) + compose search +
       dietary. "All" hides the sub-row. */
    function initCatNav() {
        const catsWrap = document.getElementById('ff-cats');
        const subWrap = document.getElementById('ff-subcats');
        if (!catsWrap) return;

        function syncSubChips() {
            if (!subWrap) return;
            const parentBtn = catsWrap.querySelector('.ff-cat-btn.active');
            const hasSub = !!parentBtn && parentBtn.getAttribute('data-has-sub') === '1' && activeCat !== 'all';
            subWrap.hidden = !hasSub;
            subWrap.querySelectorAll('.ff-subgroup').forEach(g => {
                g.hidden = !(hasSub && g.getAttribute('data-parent') === activeCat);
            });
        }

        catsWrap.addEventListener('click', (e) => {
            const btn = e.target.closest('.ff-cat-btn');
            if (!btn) return;
            activeCat = btn.getAttribute('data-cat');
            activeSub = 'root';
            catsWrap.querySelectorAll('.ff-cat-btn').forEach(b => b.classList.toggle('active', b === btn));
            if (subWrap) {
                subWrap.querySelectorAll('.ff-subcat').forEach(s => {
                    s.classList.toggle('is-on', s.getAttribute('data-sub') === 'root');
                });
            }
            syncSubChips();
            applyFilters();
            const main = document.querySelector('.ff-menu-main');
            if (main) {
                const top = main.getBoundingClientRect().top + window.scrollY - 130;
                window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
            }
        });

        if (subWrap) {
            subWrap.addEventListener('click', (e) => {
                const btn = e.target.closest('.ff-subcat');
                if (!btn) return;
                const group = btn.closest('.ff-subgroup');
                activeSub = btn.getAttribute('data-sub');
                group.querySelectorAll('.ff-subcat').forEach(s => s.classList.toggle('is-on', s === btn));
                applyFilters();
            });
        }
    }

    // Unified filter: category + sub + search + dietary compose over the sections.
    function applyFilters() {
        const query = (elements.searchInput ? elements.searchInput.value : '').toLowerCase().trim();
        if (elements.searchClear) elements.searchClear.style.display = query.length > 0 ? 'block' : 'none';

        let anyVisible = 0;
        elements.menuSections.forEach(section => {
            const sroot = section.getAttribute('data-root');
            const scat = section.getAttribute('data-cat');
            let sectionPass = (activeCat === 'all') || (sroot === activeCat);
            if (sectionPass && activeCat !== 'all' && activeSub !== 'root') {
                sectionPass = (scat === activeSub);
            }

            let visibleInSection = 0;
            section.querySelectorAll('.ff-menu-card').forEach(card => {
                const item = config.items[card.getAttribute('data-id')];
                let show = sectionPass && !!item;
                if (show && query) {
                    show = item.name.toLowerCase().includes(query) || (item.desc || '').toLowerCase().includes(query);
                }
                if (show) show = passesDiet(item);
                card.style.display = show ? '' : 'none';
                if (show) visibleInSection++;
            });

            section.style.display = (sectionPass && visibleInSection > 0) ? '' : 'none';
            anyVisible += visibleInSection;
        });

        const noMatch = document.getElementById('ff-no-match');
        if (noMatch) noMatch.hidden = anyVisible > 0;
    }

    // Dietary / spice / allergen predicate (excludes items failing any active filter).
    function passesDiet(item) {
        if (!item) return false;
        const f = dietFilters;
        if (f.diets.length) {
            for (const d of f.diets) {
                if (d === 'vegan' && !item.is_vegan) return false;
                if (d === 'halal' && !item.is_halal) return false;
                if (d === 'gluten_free' && !item.is_gluten_free) return false;
            }
        }
        if (f.spice.length && f.spice.indexOf(String(item.spice_num || 0)) === -1) return false;
        if (f.allergens.length) {
            const ids = (item.allergies || []).map(a => String(a.id));
            for (const al of f.allergens) if (ids.indexOf(al) !== -1) return false;
        }
        return true;
    }

    // Back-compat: the search input listener calls handleSearch().
    function handleSearch() { applyFilters(); }

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

        // R1 · If the item needs a choice — variants, OR an option group that is
        // required / has a minimum selection — open the detail sheet so the pick is
        // made. Quick-adding would otherwise strand a required group unsatisfied.
        // The required/incomplete notion mirrors handleDetailAddToCart's rule below
        // (need = required ? max(1,min) : min).
        if ((item.variants && item.variants.length > 0) ||
            (item.optionGroups || []).some(g => g.required || (Number(g.min) || 0) > 0)) {
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
        btn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polyline points="20 6 9 17 4 12"></polyline></svg>`;
        btn.classList.add('ff-added');
        setTimeout(() => {
            btn.innerHTML = originalHtml;
            btn.classList.remove('ff-added');
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
                <div class="ff-option-group">
                    <h4 class="ff-option-title">${ffEsc(item.variantGroup || config.lang.chooseOption)}</h4>
                    <div class="ff-options-list">
                        ${item.variants.map((v, idx) => `
                            <label class="ff-radio-option">
                                <span class="ff-option-left">
                                    <input type="radio" name="ff-variant-choice" value="${v.id}" data-price="${v.price}" ${idx === 0 ? 'checked' : ''}>
                                    <span>${ffEsc(v.label)}</span>
                                </span>
                                <span class="ff-option-price">${formatPrice(v.price)}</span>
                            </label>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        let extrasHtml = '';
        if (item.extras && item.extras.length > 0) {
            extrasHtml = `
                <div class="ff-option-group">
                    <h4 class="ff-option-title">${config.lang.extras}</h4>
                    <div class="ff-options-list">
                        ${item.extras.map(e => `
                            <label class="ff-checkbox-option">
                                <span class="ff-option-left">
                                    <input type="checkbox" name="ff-extra-choice" value="${e.id}" data-price="${e.price}">
                                    ${e.img ? `<img class="ff-opt-thumb" src="${e.img}" alt="" loading="lazy">` : ''}
                                    <span>${ffEsc(e.title)}</span>
                                </span>
                                <span class="ff-option-price">+${formatPrice(e.price)}</span>
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
                        ? `<input type="radio" name="ff-group-${g.id}" data-group="${g.id}" data-opt="${o.i}" data-gmax="1" data-price="${o.price}" data-label="${ffEsc(o.label)}" ${preset ? 'checked' : ''}>`
                        : `<input type="checkbox" data-group="${g.id}" data-opt="${o.i}" data-gmax="${g.max || 0}" data-price="${o.price}" data-label="${ffEsc(o.label)}">`;
                    return `<label class="${single ? 'ff-radio-option' : 'ff-checkbox-option'}"><span class="ff-option-left">${input}<span>${ffEsc(o.label)}</span></span><span class="ff-option-price">${o.price > 0 ? '+' + formatPrice(o.price) : ''}</span></label>`;
                }).join('');
                return `<div class="ff-option-group"><h4 class="ff-option-title">${ffEsc(g.title)}${ruleTxt}</h4><div class="ff-options-list">${rows}</div></div>`;
            }).join('');
        }

        elements.detailContent.innerHTML = `
            ${item.img ? `
                <div class="ff-detail-img-wrap">
                    ${window.mbCarousel ? mbCarousel.html([item.img].concat(item.gallery || [])) : `<img src="${ffEsc(item.img)}" alt="${ffEsc(item.name)}" class="ff-detail-img">`}
                </div>
            ` : ''}

            <div class="ff-detail-title-row">
                <h3 class="ff-detail-title">${ffEsc(item.name)}</h3>
                <span class="ff-detail-price" id="ff-detail-price-display">${formatPrice(item.price)}</span>
            </div>

            ${item.desc ? `<p class="ff-detail-desc">${ffEsc(item.desc)}</p>` : ''}

            <div class="ff-detail-options">
                ${variantsHtml}
                ${extrasHtml}
                ${groupsHtml}
            </div>

            <div class="ff-detail-qty-row">
                <div class="ff-qty-selectors">
                    <button class="ff-qty-btn" id="ff-qty-minus">&minus;</button>
                    <span class="ff-qty-val" id="ff-qty-value">1</span>
                    <button class="ff-qty-btn" id="ff-qty-plus">&plus;</button>
                </div>

                <button class="ff-btn-primary" id="ff-detail-add-btn">
                    ${config.lang.add}
                </button>

                <!-- shown by updateCartUI() once the cart holds something: a direct jump to the cart -->
                <button class="ff-cart-jump" id="ff-cart-jump" type="button" hidden aria-label="${config.lang.viewCart}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 01-8 0"/></svg>
                    <i class="ff-cart-jump-count" id="ff-cart-jump-count" aria-hidden="true">0</i>
                </button>
            </div>

            ${EP.reviewsBase ? `<div class="ff-review-widget" id="ff-review-widget" data-item-id="${item.id}">
                <h4 class="ff-option-title">${config.lang.rateThisItem}</h4>
                <div class="ff-star-row" id="ff-star-row">
                    ${[1,2,3,4,5].map(s => `<button type="button" class="ff-star" data-star="${s}" aria-label="${s}">&#9733;</button>`).join('')}
                </div>
            </div>

            <section class="ff-reviews" id="ff-reviews-display" hidden></section>` : ''}
        `;

        elements.detailDrawer.setAttribute('aria-hidden', 'false');
        setScrollLock(true);

        // Bind inner detail events
        const btnMinus = document.getElementById('ff-qty-minus');
        const btnPlus = document.getElementById('ff-qty-plus');
        const addBtn = document.getElementById('ff-detail-add-btn');
        const cartJumpBtn = document.getElementById('ff-cart-jump');

        btnMinus.addEventListener('click', () => updateDetailQty(-1));
        btnPlus.addEventListener('click', () => updateDetailQty(1));
        addBtn.addEventListener('click', handleDetailAddToCart);
        if (cartJumpBtn) cartJumpBtn.addEventListener('click', () => { closeDetailDrawer(); openCartDrawer(); });
        // freshly built above (hidden by default) — sync it to the current cart right away
        updateCartUI();

        // Listen for option changes to update displayed price
        const choices = elements.detailContent.querySelectorAll('input[name="ff-variant-choice"], input[name="ff-extra-choice"], [data-group]');
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

        // image 404 → drop the reserved media slot so the sheet has no dead band
        bindDetailImageFallback();

        // Inline "Rate this dish" widget
        bindReviewWidget();
        // F6 · rich reviews (avg + distribution bars + filters)
        if (window.FEAST_LOAD_REVIEWS) window.FEAST_LOAD_REVIEWS(item.id);
    }

    /* ── detail sheet media: hide the 240px slot when no image survives ──
       The shared carousel keeps 404'd <img> nodes in place, so the reserved
       wrap would otherwise render as an empty grey band above the title. */
    function bindDetailImageFallback() {
        const wrap = elements.detailContent.querySelector('.ff-detail-img-wrap');
        if (!wrap) return;

        const imgs = wrap.querySelectorAll('img');
        if (!imgs.length) { wrap.style.display = 'none'; return; }

        const check = function () {
            const alive = [...wrap.querySelectorAll('img')].some(function (i) {
                return !i.complete || i.naturalWidth > 0;
            });
            wrap.style.display = alive ? '' : 'none';
        };

        imgs.forEach(function (i) {
            i.addEventListener('error', check);
            i.addEventListener('load', check);
        });
        check();
    }

    function bindReviewWidget() {
        const widget = document.getElementById('ff-review-widget');
        const starRow = document.getElementById('ff-star-row');
        if (!widget || !starRow) return;

        let selectedStar = 0;
        const stars = starRow.querySelectorAll('.ff-star');

        function paint(upTo) {
            stars.forEach(b => {
                b.classList.toggle('is-active', Number(b.getAttribute('data-star')) <= upTo);
            });
        }

        stars.forEach(btn => {
            btn.addEventListener('mouseenter', () => paint(Number(btn.getAttribute('data-star'))));
            btn.addEventListener('mouseleave', () => paint(selectedStar));
            btn.addEventListener('click', () => {
                selectedStar = Number(btn.getAttribute('data-star'));
                paint(selectedStar);
                /* The inline stars OPEN the shared review sheet preselected to the
                   tapped rating, so a rating and any written review go through the
                   one shared endpoint (stars + optional text) — replacing the old
                   stars-then-inline-form direct POST that was a second review path. */
                if (window.mbItemReviews) window.mbItemReviews.open(selectedStar);
            });
        });
    }

    function closeDetailDrawer() {
        elements.detailDrawer.setAttribute('aria-hidden', 'true');
        setScrollLock(false);
        currentDetailItem = null;
    }

    function updateDetailQty(diff) {
        currentDetailQty = Math.max(1, currentDetailQty + diff);
        const qtyValueEl = document.getElementById('ff-qty-value');
        if (qtyValueEl) qtyValueEl.textContent = currentDetailQty;
        calculateDetailPrice();
    }

    function calculateDetailPrice() {
        if (!currentDetailItem) return;

        let basePrice = currentDetailItem.price;

        // Check variant
        const selectedVariant = elements.detailContent.querySelector('input[name="ff-variant-choice"]:checked');
        if (selectedVariant) {
            basePrice = parseFloat(selectedVariant.getAttribute('data-price'));
        }

        // Check extras
        const selectedExtras = elements.detailContent.querySelectorAll('input[name="ff-extra-choice"]:checked');
        selectedExtras.forEach(extra => {
            basePrice += parseFloat(extra.getAttribute('data-price'));
        });

        // Check option-group selections
        elements.detailContent.querySelectorAll('[data-group]:checked').forEach(g => {
            basePrice += parseFloat(g.getAttribute('data-price')) || 0;
        });

        const total = basePrice * currentDetailQty;
        const priceDisplay = document.getElementById('ff-detail-price-display');
        if (priceDisplay) {
            priceDisplay.textContent = formatPrice(total);
        }
    }

    function handleDetailAddToCart() {
        if (!currentDetailItem) return;
        if (currentDetailItem.is_sold_out) { showError(config.lang.soldOut || 'Sold Out'); return; }

        let variantId = 0;
        let variantPrice = currentDetailItem.price;

        const selectedVariant = elements.detailContent.querySelector('input[name="ff-variant-choice"]:checked');
        if (selectedVariant) {
            variantId = Number(selectedVariant.value);
            variantPrice = parseFloat(selectedVariant.getAttribute('data-price'));
        }

        const extras = [];
        const selectedExtras = elements.detailContent.querySelectorAll('input[name="ff-extra-choice"]:checked');
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
        const addBtn = document.getElementById('ff-detail-add-btn');
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

    /**
     * Reorder handoff from the account page — a DEAD CONTROL until now.
     * account.blade.php POSTs to customer.orders.reorder, stashes the returned
     * lines under `ff_reorder_<slug>` and redirects here; nothing in this file
     * ever read that key back, so "Reorder" walked the customer to the menu with
     * an empty cart, the stash still sitting in localStorage, and no explanation.
     *
     * Lines are rebuilt against the LIVE catalogue (config.items) — titles and
     * prices come from the page, never from the stashed payload — so a
     * since-changed price cannot be resurrected, and an item that has vanished or
     * sold out is skipped rather than poisoning the cart with an unorderable row.
     * The key is removed first so a mid-rebuild throw cannot leave a stash that
     * re-fires on every reload.
     */
    function pickupReorder() {
        const key = `ff_reorder_${config.restaurantSlug || config.storeId}`;
        let lines = null;
        try { lines = JSON.parse(localStorage.getItem(key)); } catch (e) { lines = null; }
        if (!Array.isArray(lines) || !lines.length) return;
        try { localStorage.removeItem(key); } catch (e) {}

        let added = 0;
        let skipped = 0;

        lines.forEach(line => {
            const base = config.items[line.id];
            if (!base || base.is_sold_out) { skipped++; return; }

            /* variant: matched by id against the live list. A variant that no
               longer exists drops the line — falling back to the base price would
               quietly charge for a different thing than the customer reordered. */
            let variantId = 0;
            let variantPrice = base.price;
            const wantVariant = Number(line.variantId || 0) || 0;
            if (wantVariant) {
                const v = (base.variants || []).find(x => Number(x.id) === wantVariant);
                if (!v) { skipped++; return; }
                variantId = Number(v.id);
                variantPrice = Number(v.price);
            }

            /* extras rebuilt from the live catalogue in the same {id, price} shape
               the detail drawer emits, so a reordered line merges with a
               hand-added identical one instead of doubling up */
            const extras = [];
            (line.extras || []).forEach(e => {
                const id = Number(e && e.id != null ? e.id : e);
                const match = (base.extras || []).find(x => Number(x.id) === id);
                if (match) extras.push({ id: Number(match.id), price: Number(match.price) });
            });

            addToCartInternal({
                id: Number(base.id),
                quantity: Math.max(1, Number(line.qty || line.quantity || 1)),
                variants: variantId,
                extras: extras,
                /* option-group mods: the reorder endpoint does not return them */
                mods: [],
                price: variantPrice,
                name: base.name
            });
            added++;
        });

        if (!added) return;
        if (elements.cartDrawer) openCartDrawer();
        ffToast(skipped
            ? (config.lang.reorderPartial || 'Some items were unavailable and were skipped.')
            : (config.lang.reorderAdded || 'Your previous order is back in the cart.'));
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

        /* COMBOS-V2 · combos ride in the subtotal: the server taxes them, min-order
           counts them, and the coupon endpoint is quoted the same base the order
           will carry. The badge counts them too. */
        subtotal += comboTotal();
        const dispCount = totalCount + comboCount();

        // Floating button visibility
        if (elements.cartToggle) {
            if (dispCount > 0) {
                elements.cartToggle.style.display = 'flex';
                elements.cartBadge.textContent = dispCount;
                elements.cartTotalPrice.textContent = formatPrice(subtotal);
            } else {
                elements.cartToggle.style.display = 'none';
            }
        }

        // Bottom tab-bar cart badge
        var tabBadge = document.getElementById('ff-tab-cart-badge');
        if (tabBadge) {
            if (dispCount > 0) { tabBadge.textContent = dispCount; tabBadge.style.display = 'inline-flex'; }
            else { tabBadge.style.display = 'none'; }
        }

        // Product-detail footer: direct jump to the cart, shown once anything is
        // in it. Looked up fresh (not cached in `elements`) because the detail
        // drawer's markup is only in the DOM while a dish sheet is open.
        var cartJump = document.getElementById('ff-cart-jump');
        if (cartJump) {
            cartJump.hidden = dispCount === 0;
            var cartJumpCount = document.getElementById('ff-cart-jump-count');
            if (cartJumpCount) cartJumpCount.textContent = dispCount;
        }

        // Summary values
        if (elements.sumSubtotal) {
            elements.sumSubtotal.textContent = formatPrice(subtotal);
            
            // Apply delivery charges if delivery mode (COMBOS-V2: a combos-only
            // basket is deliverable too, so the fee gates on either kind of line)
            const isDelivery = elements.orderTypeInput && elements.orderTypeInput.value === 'delivery';
            const deliveryCost = isDelivery && (cart.length || comboCount()) ? config.deliveryCharge : 0;

            if (deliveryCost > 0) {
                elements.deliveryRow.style.display = '';
                elements.sumDelivery.textContent = formatPrice(deliveryCost);
            } else {
                elements.deliveryRow.style.display = 'none';
            }

            // Coupon Discount (percentage or fixed — crave contract)
            let discountAmt = 0;
            if (couponCode) {
                // bug batch 7 · 4: a percentage rounds in the store decimals like CouponService (was unrounded)
                discountAmt = couponType === 'percentage' ? roundTo(subtotal * (couponPct / 100), config.moneyDecimals ?? config.currencyDecimals ?? 2) : couponType === 'bogo' ? bxgyDiscount() : couponPct;
                discountAmt = Math.min(discountAmt, subtotal);
            }
            if (discountAmt > 0) {
                elements.discountRow.style.display = '';
                elements.sumDiscount.textContent = `-${formatPrice(discountAmt)}`;
            } else {
                elements.discountRow.style.display = 'none';
            }

            // FEAST-F1 · TAX. Computed on the RAW subtotal, exactly like the server:
            // the discount is subtracted from the total last and is never taxed.
            const taxAmt = computeTax(subtotal);
            const taxRow = document.getElementById('ff-tax-row');
            const sumTax = document.getElementById('ff-sum-tax');
            if (taxRow && sumTax) {
                if (taxAmt > 0) { taxRow.style.display = ''; sumTax.textContent = formatPrice(taxAmt); }
                else { taxRow.style.display = 'none'; }
            }

            // Tip
            const tipAmt = tipCurrentAmount(subtotal);
            const tipRow = document.getElementById('ff-tip-summary-row');
            const sumTip = document.getElementById('ff-sum-tip');
            if (tipRow && sumTip) {
                if (tipAmt > 0) { tipRow.style.display = ''; sumTip.textContent = formatPrice(tipAmt); }
                else { tipRow.style.display = 'none'; }
            }

            // Mirrors Order::getTotalAttribute():
            //   subtotal + delivery + tax + tip − discount
            const total = Math.max(0, subtotal + deliveryCost + taxAmt + tipAmt - discountAmt);
            elements.sumTotal.textContent = formatPrice(total);
        }

        renderCartItems();
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
        const sig = couponSig(), code = couponCode;
        if (couponSigSent !== sig) {
            couponSigSent = sig;
            fetch(config.endpoints.coupon, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': config.csrf },
                body: couponBody(code)
            }).then(res => res.json()).then(data => {
                if (couponCode !== code || couponSigSent !== sig) return;
                couponDiscount = data && data.success ? Number(data.discount) || 0 : 0;
                updateCartUI();
            }).catch(() => {});
        }
        return couponDiscount;
    }

    // Subtotal of the current cart (base + extras + option-group mods).
    // COMBOS-V2: combos ride in, so the coupon endpoint is quoted the same
    // base the order will carry.
    function cartSubtotal() {
        let subtotal = 0;
        cart.forEach(item => {
            let itemPrice = item.price;
            item.extras.forEach(e => { itemPrice += e.price; });
            (item.mods || []).forEach(m => { itemPrice += m.price; });
            subtotal += itemPrice * item.quantity;
        });
        return subtotal + comboTotal();
    }

    /* FEAST-F1 · TOTALS CONTRACT — a line-for-line mirror of Order::computeTax().
       `restaurant_taxx_charge` is read through `restaurant_tax_type`: a percentage
       of the subtotal (default) or a FLAT amount when the type is 'fixed'. Rounding
       uses the STORE's currency decimals, not a hardcoded 2 — a 3-decimal currency
       loses fils otherwise. Guards match the server exactly: a non-positive charge
       or a non-positive subtotal is taxed at exactly 0. */
    /* M16: delegates to the shared money core (drops the EPSILON approximation
       for PHP-exact rounding). */
    function roundTo(v, d) { return mbMoney.round(v, d); }
    function computeTax(subtotal) {
        const charge = Number(config.taxCharge) || 0;
        if (charge <= 0 || subtotal <= 0) return 0;
        const d = config.moneyDecimals ?? config.currencyDecimals ?? 2; // bug batch 10 · 4: the stored precision
        if (config.taxType === 'fixed') return roundTo(charge, d);
        return roundTo(subtotal * (charge / 100), d);
    }

    // Tip amount for a given subtotal (percent preset OR absolute custom).
    function tipCurrentAmount(subtotal) {
        if (!config.allowTips) return 0;
        // bug batch 10 · 4: rounded like the order stores the tip (was unrounded)
        return tipPct ? roundTo(subtotal * tipPct / 100, config.moneyDecimals ?? config.currencyDecimals ?? 2) : (Number(tipCustom) || 0);
    }

    /* COMBOS-V2 — read-only "Combos" group prepended to the cart drawer's list
       (which doubles as feast's checkout "your order" summary — the drawer holds
       the summary rows and the checkout form). Tapping the group reopens the
       combo sheet's basket view, which is where quantities are edited. */
    function comboGroupHTML() {
        const lines = comboLines();
        if (!lines.length) return '';
        let h = '<div class="ff-combo-group" data-combo-open style="border-bottom:1px dashed rgba(128,128,128,.4);padding-bottom:8px;margin-bottom:8px;cursor:pointer">';
        lines.forEach(L => {
            h += '<div class="ff-cart-item ff-cart-item--combo">' +
                '<div class="ff-cart-item-info">' +
                '<span class="ff-cart-item-name" style="pointer-events:none"><b>' + ffEsc(L.name) + '</b></span>' +
                (L.parts.length ? '<div class="ff-cart-item-customizations"><div style="font-size:10px; opacity:0.8;">' + ffEsc(L.parts.join(' · ')) + '</div></div>' : '') +
                '</div>' +
                '<div class="ff-cart-item-right">' +
                '<div class="ff-cart-item-price">' + formatPrice(L.price) + '</div>' +
                '<div class="ff-cart-item-unit">&times;' + L.qty + '</div>' +
                '</div></div>';
        });
        h += '<small style="opacity:.65">' + ffEsc((config.lang && config.lang.edit) || 'Edit') + ' ›</small></div>';
        return h;
    }
    /* Tapping the read-only group reopens the combo sheet (basket view). */
    document.addEventListener('click', function (e) {
        var g = e.target.closest && e.target.closest('[data-combo-open]');
        if (g && window.mbCombos && window.mbCombos.openSheet) window.mbCombos.openSheet('basket');
    });
    /* Re-quote whenever the combo basket changes (add/remove/qty in the sheet):
       updateCartUI() repaints badge + summary totals + the drawer's list. */
    window.addEventListener('mb:combos-changed', function () {
        try { updateCartUI(); } catch (e) {}
    });

    // Render Items inside Cart drawer
    function renderCartItems() {
        if (!elements.cartItemsList) return;

        // COMBOS-V2: a combos-only basket is not empty
        if (cart.length === 0 && comboCount() === 0) {
            elements.cartItemsList.innerHTML = `<div class="ff-no-items">${config.lang.cartEmpty || 'Your cart is empty'}</div>`;
            return;
        }

        elements.cartItemsList.innerHTML = comboGroupHTML() + cart.map((item, cartIndex) => {
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
                <div class="ff-cart-item">
                    <div class="ff-cart-item-info">
                        <button type="button" class="ff-cart-item-name" data-open-item="${item.id}">${ffEsc(item.name)}</button>
                        <div class="ff-cart-item-customizations">
                            ${label ? `<span>${ffEsc(label)}</span>` : ''}
                            ${modsLabel ? `<div style="font-size:10px; opacity:0.8;">${ffEsc(modsLabel)}</div>` : ''}
                            ${extrasLabel ? `<div style="font-size:10px; opacity:0.8;">${ffEsc(extrasLabel)}</div>` : ''}
                        </div>
                    </div>
                    <div class="ff-cart-item-right">
                        <div class="ff-cart-item-price">${formatPrice(singleItemPrice * item.quantity)}${wasUnit ? ` <del class="ff-cart-item-was">${formatPrice(wasUnit * item.quantity)}</del>` : ''}</div>
                        ${item.quantity > 1 ? `<div class="ff-cart-item-unit">${item.quantity} × ${formatPrice(singleItemPrice)}</div>` : ''}
                        <div class="ff-cart-item-actions">
                            <div class="ff-cart-item-qty">
                                <button type="button" class="ff-cart-item-qty-btn" data-csp-qty-handler="FEAST_UPDATE_QTY" data-csp-index="${cartIndex}" data-csp-delta="-1">&minus;</button>
                                <span>${item.quantity}</span>
                                <button type="button" class="ff-cart-item-qty-btn" data-csp-qty-handler="FEAST_UPDATE_QTY" data-csp-index="${cartIndex}" data-csp-delta="1">&plus;</button>
                            </div>
                            <button type="button" class="ff-cart-item-rm" data-rm="${cartIndex}" aria-label="${I18N.remove || 'Remove'}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m3 0-1 12a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L6 7"/><path d="M10 11v6M14 11v6"/></svg></button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    /* cart line remove — delegated once (rows are rebuilt on every render),
       with the same fade-out + toast idiom velora uses for data-rm */
    if (elements.cartItemsList) {
        elements.cartItemsList.addEventListener('click', function (e) {
            var rm = e.target.closest('[data-rm]');
            if (!rm) return;
            /* capture the LINE, not the index — a second quick removal would
               otherwise splice a shifted index after the animation delay */
            var line = cart[parseInt(rm.getAttribute('data-rm'), 10)];
            if (!line) return;
            var row = rm.closest('.ff-cart-item');
            if (row) row.classList.add('removing');
            setTimeout(function () {
                var i = cart.indexOf(line);
                if (i !== -1) { cart.splice(i, 1); saveCart(); }
                ffToast(I18N.removed || 'Removed');
            }, 250);
        });
    }

    // Expose global Qty Updater for inline cart buttons
    window.FEAST_UPDATE_QTY = function (index, diff) {
        if (cart[index]) {
            cart[index].quantity += diff;
            if (cart[index].quantity <= 0) {
                cart.splice(index, 1);
            }
            saveCart();
        }
    };

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
        if (window.FEAST && FEAST[autoFlag]) open();
    }
    bindParityDrawer('ff-res-open', 'ff-res-drawer', 'ff-res-close', 'ff-res-backdrop', 'openResSheet');
    bindParityDrawer('ff-waitlist-open', 'ff-waitlist-drawer', 'ff-waitlist-close', 'ff-waitlist-backdrop', 'openWaitlistSheet');
    bindParityDrawer('ff-auth-open', 'ff-auth-drawer', 'ff-auth-close', 'ff-auth-backdrop');
    bindParityDrawer('ff-account-open', 'ff-account-drawer', 'ff-account-close', 'ff-account-backdrop');

    /* ── success drawer (opened on order success, F1 google-review) ── */
    function openSuccessDrawer(msg, orderId) {
        var d = document.getElementById('ff-success-drawer');
        if (!d) return;
        if (msg) {
            var m = document.getElementById('ff-success-msg');
            if (m) m.textContent = msg;
        }
        var on = document.getElementById('ff-success-order');
        if (on) {
            if (orderId) {
                on.textContent = (config.lang.orderLabel || 'Order') + ' #' + orderId;
                on.hidden = false;
            } else {
                on.hidden = true;
            }
        }
        d.setAttribute('aria-hidden', 'false');
        setScrollLock(true);
    }
    (function () {
        var d = document.getElementById('ff-success-drawer');
        if (!d) return;
        function close() { d.setAttribute('aria-hidden', 'true'); setScrollLock(false); window.location.reload(); }
        var cb = document.getElementById('ff-success-close'); if (cb) cb.addEventListener('click', close);
        var bd = document.getElementById('ff-success-backdrop'); if (bd) bd.addEventListener('click', close);
        var done = document.getElementById('ff-success-done'); if (done) done.addEventListener('click', close);
    })();

    /* ── auth: login / register / logout (parity) ── */
    (function () {
        var ep = config.endpoints || {};
        var tabs = document.getElementById('ff-auth-tabs');
        if (tabs) {
            tabs.querySelectorAll('button[data-auth-tab]').forEach(function (b) {
                b.addEventListener('click', function () {
                    tabs.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-on', x === b); });
                    document.querySelectorAll('.ff-auth-form[data-auth]').forEach(function (f) {
                        f.style.display = f.getAttribute('data-auth') === b.getAttribute('data-auth-tab') ? '' : 'none';
                    });
                });
            });
        }
        function authPost(url, body) {
            var errEl = document.getElementById('ff-auth-err');
            if (!url) return;
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body)
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (res && res.success) { window.location.reload(); return; }
                if (errEl) { errEl.textContent = (res && res.message) || 'Failed.'; errEl.style.display = 'block'; }
            }).catch(function () {
                if (errEl) { errEl.textContent = (I18N.networkError || 'Network error'); errEl.style.display = 'block'; }
            });
        }
        var loginBtn = document.getElementById('ff-login-btn');
        if (loginBtn) loginBtn.addEventListener('click', function () {
            authPost(ep.login, {
                store_id: config.storeId,
                email: (document.getElementById('ff-login-email').value || '').trim(),
                password: document.getElementById('ff-login-pass').value
            });
        });
        var regBtn = document.getElementById('ff-register-btn');
        if (regBtn) regBtn.addEventListener('click', function () {
            authPost(ep.register, {
                store_id: config.storeId,
                name: (document.getElementById('ff-reg-name').value || '').trim(),
                email: (document.getElementById('ff-reg-email').value || '').trim(),
                phone: (document.getElementById('ff-reg-phone').value || '').trim(),
                password: document.getElementById('ff-reg-pass').value,
                password_confirmation: (document.getElementById('ff-reg-pass2') || {}).value || ''
            });
        });
        var logoutBtn = document.getElementById('ff-logout-btn');
        if (logoutBtn) logoutBtn.addEventListener('click', function () {
            if (!ep.logout) return;
            fetch(ep.logout, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf }
            }).then(function () { window.location.reload(); });
        });

        /* A3 · phone-OTP login — send code, then verify the 6-box code under `code`.
           Distinct ids from the checkout OTP gate (#cvOtpGateBoxes). */
        var ffOtpSend = document.getElementById('ff-otp-send');
        if (ffOtpSend) ffOtpSend.addEventListener('click', function () {
            var phone = (document.getElementById('ff-otp-phone').value || '').trim(); if (!phone) return;
            var errEl = document.getElementById('ff-auth-err'); if (errEl) errEl.style.display = 'none';
            fetch(ep.otpRequest, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ store_id: config.storeId, phone: phone }) })
                .then(function (r) { return r.json(); }).then(function (res) {
                    if (res && res.success) { document.getElementById('ff-otp-step1').style.display = 'none'; document.getElementById('ff-otp-step2').style.display = ''; var f = document.querySelector('#ff-otp-boxes input'); if (f) f.focus(); }
                    else if (errEl) { errEl.textContent = (res && res.message) || 'Failed.'; errEl.style.display = 'block'; }
                }).catch(function () { if (errEl) { errEl.textContent = (I18N.networkError || 'Network error'); errEl.style.display = 'block'; } });
        });
        var ffOtpVerify = document.getElementById('ff-otp-verify');
        if (ffOtpVerify) ffOtpVerify.addEventListener('click', function () {
            var code = Array.prototype.slice.call(document.querySelectorAll('#ff-otp-boxes input')).map(function (b) { return b.value; }).join('');
            authPost(ep.otpVerifyLogin, { store_id: config.storeId, phone: (document.getElementById('ff-otp-phone').value || '').trim(), code: code });
        });
        (function () {
            var boxes = Array.prototype.slice.call(document.querySelectorAll('#ff-otp-boxes input'));
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
                block.querySelectorAll('#cvStampDots .ff-stamp-dot').forEach(function (dot, i) {
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
           #ff-order-type-input actually hold room_service and clears the `required`
           flags on the table/phone/address inputs. */
        const roomTab = document.querySelector('.ff-order-type-tabs .ff-tab-btn[data-type="room_service"]');
        const activeTab = roomTab || document.querySelector('.ff-order-type-tabs .ff-tab-btn.active') || document.querySelector('.ff-order-type-tabs .ff-tab-btn');
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
            const tblInput = document.getElementById('ff-table-num');
            if (tblInput) tblInput.required = true;
            
            const phoneInput = document.getElementById('ff-phone-num');
            if (phoneInput) phoneInput.required = false;
            
            const addrInput = document.getElementById('ff-address');
            if (addrInput) addrInput.required = false;
        } else if (type === 'takeaway') {
            elements.groupTable.style.display = 'none';
            elements.groupPhone.style.display = '';
            elements.groupAddress.style.display = 'none';
            
            const tblInput = document.getElementById('ff-table-num');
            if (tblInput) tblInput.required = false;
            
            const phoneInput = document.getElementById('ff-phone-num');
            if (phoneInput) phoneInput.required = true;
            
            const addrInput = document.getElementById('ff-address');
            if (addrInput) addrInput.required = false;
        } else if (type === 'delivery') {
            elements.groupTable.style.display = 'none';
            elements.groupPhone.style.display = '';
            elements.groupAddress.style.display = '';
            
            const tblInput = document.getElementById('ff-table-num');
            if (tblInput) tblInput.required = false;
            
            const phoneInput = document.getElementById('ff-phone-num');
            if (phoneInput) phoneInput.required = true;
            
            const addrInput = document.getElementById('ff-address');
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

            const tblInput = document.getElementById('ff-table-num');
            if (tblInput) tblInput.required = false;

            const phoneInput = document.getElementById('ff-phone-num');
            if (phoneInput) phoneInput.required = false;

            const addrInput = document.getElementById('ff-address');
            if (addrInput) addrInput.required = false;
        }

        // Call-waiter row is on-table only.
        const waiterRow = document.getElementById('ff-waiter-row');
        if (waiterRow) waiterRow.style.display = (type === 'on-table' && config.allowCallWaiter) ? '' : 'none';

        updateCartUI();
    }

    // Coupon Apply logic (POST restaurant.applyCoupon — crave contract).
    // Response: { success, label, coupon_type, percentage, discount, message }.
    // We store type + percentage and recompute the discount per cart change.
    function handleCouponApply() {
        if (!elements.couponCodeInput) return;
        const code = elements.couponCodeInput.value.trim();
        if (!code) return;

        elements.couponMsg.textContent = '';
        elements.couponMsg.className = 'ff-coupon-message';

        fetch(config.endpoints.coupon, {
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
                    couponCode = data.label || code;
                    couponType = data.coupon_type || 'percentage';
                    couponPct = Number(data.percentage) || 0;
                    couponDiscount = Number(data.discount) || 0;
                    couponSigSent = couponSig();
                    elements.couponMsg.textContent = '✓ ' + (data.message || config.lang.couponApplied);
                    elements.couponMsg.classList.add('ff-coupon-success');
                } else {
                    couponCode = ''; couponType = ''; couponPct = 0;
                    elements.couponMsg.textContent = (data && data.message) || config.lang.couponInvalid;
                    elements.couponMsg.classList.add('ff-coupon-error');
                }
                updateCartUI();
            })
            .catch(() => {
                couponCode = ''; couponType = ''; couponPct = 0;
                elements.couponMsg.textContent = config.lang.couponInvalid;
                elements.couponMsg.classList.add('ff-coupon-error');
                updateCartUI();
            });
    }

    // Checkout Submit handler
    function handleCheckoutSubmit(e) {
        e.preventDefault();

        if (cart.length === 0 && !comboCount()) return; /* combos-only orders are legal (COMBOS-V2) */

        elements.checkoutErrors.style.display = 'none';
        elements.checkoutErrors.textContent = '';

        // Validate minimum order (combos count toward it — COMBOS-V2)
        let subtotal = 0;
        cart.forEach(item => {
            let itemPrice = item.price;
            item.extras.forEach(e => {
                itemPrice += e.price;
            });
            (item.mods || []).forEach(m => { itemPrice += m.price; });
            subtotal += itemPrice * item.quantity;
        });
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
        if (couponCode) {
            formData.append('coupon_code', couponCode);
        }
        // Tip (absolute amount) — read by the submitOrder controller as tip_amount.
        formData.append('tip_amount', String(tipCurrentAmount(subtotal) || 0));
        // Store credit — crave/plate contract: post use_wallet=1 when the signed-in
        // diner opts to spend their wallet balance; the shared submitOrder concern applies it.
        var useWallet = document.getElementById('ff-use-wallet');
        if (useWallet && useWallet.checked) {
            formData.append('use_wallet', '1');
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
            if (data && data.otp_required) {
                /* F7 · phone-verification gate — open the OTP sheet, then re-submit */
                resetSubmitBtn();
                var ph = data.phone || (document.getElementById('ff-phone-num') || {}).value || '';
                if (window.FEAST_OPEN_OTP) window.FEAST_OPEN_OTP(ph);
                return;
            }
            if (data.success) {
                // R6 · remember this delivery address for next time (guests only —
                // rememberAddress no-ops for signed-in customers, who have a real book).
                if (elements.orderTypeInput && elements.orderTypeInput.value === 'delivery') {
                    var _addr = document.getElementById('ff-address');
                    var _lat = document.getElementById('cvCustLat');
                    var _lng = document.getElementById('cvCustLng');
                    rememberAddress(_addr ? _addr.value : '', _lat ? _lat.value : '', _lng ? _lng.value : '');
                }
                // R5 · stash the order so the guest recent-orders block can list it.
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
                    openSuccessDrawer(data.message && !data.message.startsWith('http') ? data.message : '', data.order_id);
                }
            } else {
                showError(data.message || I18N.order_error || 'Error occurred while sending order.');
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

    function showError(msg) {
        elements.checkoutErrors.style.display = 'block';
        /* FEAST-F3 · this was `innerHTML = msg`, and `msg` is the server's own
           `data.message`, which echoes back user-supplied values (coupon code,
           name, address). Any of those could carry markup straight into the DOM. */
        elements.checkoutErrors.textContent = msg;
        elements.checkoutErrors.scrollIntoView({ behavior: 'smooth' });
    }

    /* ══════════════════════════════════════════════════════════
       theme-pack-blade features (F1–F7) — adapted to feast's shell
       ══════════════════════════════════════════════════════════ */
    var EP = config.endpoints || {};
    var I18N = config.lang || {};
    function q(id) { return document.getElementById(id); }
    function ffEsc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function ffToken(json) {
        var h = {};
        if (json) h['Content-Type'] = 'application/json';
        h['Accept'] = 'application/json';
        h['X-Requested-With'] = 'XMLHttpRequest';
        if (config.csrf) h['X-CSRF-TOKEN'] = config.csrf;
        if (config.customerToken) h['Authorization'] = 'Bearer ' + config.customerToken;
        return h;
    }
    function hasToken() { return !!config.customerToken; }
    var toastTimer = null;
    function ffToast(msg) {
        var t = q('cvToast'); if (!t || !msg) return;
        t.textContent = msg; t.classList.add('is-on');
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.classList.remove('is-on'); }, 2200);
    }
    function ffSheetOpen(id) { var d = q(id); if (!d) return; d.setAttribute('aria-hidden', 'false'); setScrollLock(true); }
    function ffSheetClose(id) { var d = q(id); if (!d) return; d.setAttribute('aria-hidden', 'true'); setScrollLock(false); }
    function ffBindSheetClose(sheetId, closeId, backdropId) {
        var c = q(closeId), b = q(backdropId);
        if (c) c.addEventListener('click', function () { ffSheetClose(sheetId); });
        if (b) b.addEventListener('click', function () { ffSheetClose(sheetId); });
    }
    function ffOpenAuth() {
        var d = q('ff-auth-drawer');
        if (d) { d.setAttribute('aria-hidden', 'false'); setScrollLock(true); }
    }

    /* ── F2 · follow / verified brand (B4/B5/B6) ──────────── */
    (function () {
        var btn = q('cvFollowBtn');
        if (!btn || !EP.follow) return;
        var FOLLOW_INTENT = 'cv_follow_intent'; // shared key across themes
        var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function applyFollowState(d) {
            if (!d || typeof d.following === 'undefined') { return false; }
            btn.dataset.following = d.following ? '1' : '0';
            btn.classList.toggle('is-on', d.following);
            var c = q('cvFollowerCount'); if (c && typeof d.follower_count !== 'undefined') c.textContent = d.follower_count;
            return true;
        }
        // B5: optimistic count nudge (the count span only exists past the B1 threshold).
        function bumpCount(delta) {
            var c = q('cvFollowerCount'); if (!c) return;
            var n = parseInt(String(c.textContent || '').replace(/[^0-9]/g, ''), 10);
            if (!isNaN(n)) c.textContent = String(Math.max(0, n + delta));
        }
        // B5: scale-pop beat on a successful follow (skipped under reduced-motion).
        function popBeat() {
            if (REDUCED) return;
            btn.classList.add('ff-followbtn--pop');
            btn.addEventListener('animationend', function h() { btn.classList.remove('ff-followbtn--pop'); btn.removeEventListener('animationend', h); });
        }
        // B5: optimistic follow/unfollow — flip UI immediately, reconcile, revert on fail.
        function doFollow(unfollow) {
            var prevFollowing = btn.dataset.following === '1';
            btn.dataset.following = unfollow ? '0' : '1';
            btn.classList.toggle('is-on', !unfollow);
            bumpCount(unfollow ? -1 : 1);
            if (!unfollow) popBeat();
            fetch(EP.follow, { method: unfollow ? 'DELETE' : 'POST', headers: ffToken(true), body: '{}' })
                .then(function (r) { return r.json(); }).then(function (d) {
                    if (!d || typeof d.following === 'undefined') { throw new Error('bad'); }
                    applyFollowState(d);
                }).catch(function () {
                    btn.dataset.following = prevFollowing ? '1' : '0';
                    btn.classList.toggle('is-on', prevFollowing);
                    bumpCount(unfollow ? 1 : -1);
                    ffToast(I18N.action_failed || I18N.failed);
                });
        }
        // B6: the "Following" management sheet — supersedes any two-tap guard when the
        // button is already is-on. Notifications toggle writes the follow row's notify
        // via the same follow endpoint ({notify: 0|1}); Unfollow runs the optimistic path.
        var notifyBox = q('cvFollowNotify');
        function openFollowingSheet() {
            if (notifyBox) notifyBox.checked = config.followNotify !== false;
            ffSheetOpen('cvFollowingSheet');
        }
        if (notifyBox) {
            notifyBox.addEventListener('change', function () {
                var want = notifyBox.checked;
                fetch(EP.follow, { method: 'POST', headers: ffToken(true), body: JSON.stringify({ notify: want ? 1 : 0 }) })
                    .then(function (r) { return r.json(); }).then(function (d) {
                        if (d && typeof d.notify !== 'undefined') { config.followNotify = !!d.notify; notifyBox.checked = !!d.notify; }
                        else { throw new Error('bad'); }
                    }).catch(function () { notifyBox.checked = !want; ffToast(I18N.action_failed || I18N.failed); });
            });
        }
        ffBindSheetClose('cvFollowingSheet', 'cvFollowingClose', 'cvFollowingBackdrop');
        var unfollowBtn = q('cvFollowUnfollow');
        if (unfollowBtn) unfollowBtn.addEventListener('click', function () { ffSheetClose('cvFollowingSheet'); doFollow(true); });
        var cancelBtn = q('cvFollowCancel');
        if (cancelBtn) cancelBtn.addEventListener('click', function () { ffSheetClose('cvFollowingSheet'); });

        btn.addEventListener('click', function () {
            // B4: guest tap records a follow intent, then opens the auth drawer. After a
            // successful sign-in the page reloads and the intent is replayed below.
            if (!hasToken()) {
                try { sessionStorage.setItem(FOLLOW_INTENT, '1'); } catch (e) {}
                ffOpenAuth();
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
            var pending = false;
            try { pending = sessionStorage.getItem(FOLLOW_INTENT) === '1'; } catch (e) {}
            if (!pending || !hasToken()) return;
            try { sessionStorage.removeItem(FOLLOW_INTENT); } catch (e) {}
            if (btn.dataset.following === '1') return;
            fetch(EP.follow, { method: 'POST', headers: ffToken(true), body: '{}' })
                .then(function (r) { return r.json(); }).then(function (d) {
                    if (applyFollowState(d) && d.following) { ffToast(I18N.now_following || '✓'); }
                }).catch(function () {});
        })();
    })();

    /* ── F2b · about sheet (B8) ───────────────────────────── */
    (function () {
        if (!q('cvAboutSheet')) return;
        ffBindSheetClose('cvAboutSheet', 'cvAboutClose', 'cvAboutBackdrop');
        function openAbout() { ffSheetOpen('cvAboutSheet'); }
        var info = document.querySelector('.ff-brand-info');
        if (info) {
            info.setAttribute('role', 'button');
            info.setAttribute('tabindex', '0');
            info.style.cursor = 'pointer';
            info.addEventListener('click', openAbout);
            info.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openAbout(); } });
        }
        // The logo opens stories when it wears the live ring (B7); otherwise the about sheet.
        var logo = document.querySelector('.ff-brand-logo');
        if (logo) logo.addEventListener('click', function () { if (!logo.classList.contains('ff-brand-logo--live')) openAbout(); });
    })();

    /* ── F3 · stories rail + full-screen viewer ───────────── */
    (function () {
        var rail = q('cvStoriesRail');
        if (!rail || !EP.stories) return;
        var stories = [], idx = 0, timer = null, T = 0;
        var DUR = 5;
        var viewer = q('cvStoryViewer'), media = q('cvSvMedia'), cap = q('cvSvCaption'), cta = q('cvSvCta'), prog = q('cvSvProgress');

        (window.mbStoriesGet?window.mbStoriesGet(EP.stories):fetch(EP.stories).then(function(r){return r.json();})).then(function (d) {
            stories = (d && d.data) || [];
            if (!stories.length) { rail.hidden = true; return; }
            rail.hidden = false;
            rail.innerHTML = stories.map(function (s, i) {
                return '<button class="ff-story-bubble" data-si="' + i + '" type="button">' +
                    '<span class="ff-sb-ring"><img src="' + ffEsc(s.media_url) + '" alt="" data-csp-error="hide"></span>' +
                    '<small>' + ffEsc(s.caption ? String(s.caption).slice(0, 14) : (I18N.popular || '')) + '</small></button>';
            }).join('');
            // B7: with stories live, the brand logo gets the story ring and opens the
            // viewer at index 0 (same call the first story bubble uses). No stories → no
            // change, so an untouched store renders identically.
            var brandLogo = document.querySelector('.ff-brand-logo');
            if (brandLogo && !brandLogo.classList.contains('ff-brand-logo--live')) {
                brandLogo.classList.add('ff-brand-logo--live');
                brandLogo.setAttribute('role', 'button');
                brandLogo.setAttribute('tabindex', '0');
                brandLogo.setAttribute('aria-label', I18N.view_stories || '');
                brandLogo.addEventListener('click', function () { open(0); });
                brandLogo.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(0); } });
            }
        }).catch(function () { rail.hidden = true; });

        function buildProgress() { if (prog) prog.innerHTML = stories.map(function () { return '<span class="ff-sv-seg"><i></i></span>'; }).join(''); }
        function paintSeg() {
            if (!prog) return;
            prog.querySelectorAll('.ff-sv-seg i').forEach(function (bar, i) {
                bar.style.width = i < idx ? '100%' : (i === idx ? (T / DUR * 100) + '%' : '0%');
            });
        }
        function render() {
            var s = stories[idx]; if (!s) return;
            media.src = s.media_url;
            cap.textContent = s.caption || '';
            if (s.cta_url && !(window.mbStoryCta && window.mbStoryCta.isCurrentPage(s.cta_url))) { cta.hidden = false; cta.href = s.cta_url;
 if (window.mbStoryCta) window.mbStoryCta.apply(cta, s.cta_url); cta.querySelector('span').textContent = s.cta_label || (I18N.popular || 'Order now'); }
            else { cta.hidden = true; }
            T = 0; paintSeg();
        }
        function tick() { T += 0.05; if (T >= DUR) { next(); return; } paintSeg(); }
        function start() { stop(); render(); timer = setInterval(tick, 50); }
        function stop() { if (timer) { clearInterval(timer); timer = null; } }
        function open(i) {
            idx = i; buildProgress();
            viewer.hidden = false; void viewer.offsetWidth;
            viewer.classList.add('is-open');
            document.body.classList.add('ff-noscroll');
            start();
        }
        function close() {
            stop(); viewer.classList.remove('is-open');
            document.body.classList.remove('ff-noscroll');
            setTimeout(function () { viewer.hidden = true; }, 300);
        }
        function next() { if (idx < stories.length - 1) { idx++; render(); } else { close(); } }
        function prev() { if (idx > 0) { idx--; render(); } else { T = 0; } }

        rail.addEventListener('click', function (e) { var b = e.target.closest('[data-si]'); if (b) open(Number(b.dataset.si)); });
        var cl = q('cvSvClose'); if (cl) cl.addEventListener('click', close);
        /* CTA opens the item in-page beneath this viewer; close the viewer so the item sheet is the top layer. */
        if (cta) cta.addEventListener('click', close);
        var nx = q('cvSvNext'); if (nx) nx.addEventListener('click', next);
        var pv = q('cvSvPrev'); if (pv) pv.addEventListener('click', prev);
        var stage = q('cvSvStage');
        if (stage) {
            stage.addEventListener('pointerdown', stop);
            stage.addEventListener('pointerup', function () { if (!viewer.hidden) timer = setInterval(tick, 50); });
        }
    })();

    /* ── F5 · address autocomplete + use-my-location ──────── */
    (function () {
        var input = q('ff-address'), box = q('cvAddrSuggest'), attrib = q('cvGeoAttrib');
        if (!input || !EP.geoAutocomplete) return;
        var t = null, lastAttrib = '';
        function setAttrib(a) { if (a && a !== lastAttrib && attrib) { attrib.textContent = a; attrib.hidden = false; lastAttrib = a; } }
        function hideBox() { if (box) { box.hidden = true; box.innerHTML = ''; } }
        input.addEventListener('input', function () {
            var qy = input.value.trim();
            var la = q('cvCustLat'), ln = q('cvCustLng'); if (la) la.value = ''; if (ln) ln.value = '';
            if (t) clearTimeout(t);
            if (qy.length < 3) { hideBox(); return; }
            t = setTimeout(function () {
                fetch(EP.geoAutocomplete + '?q=' + encodeURIComponent(qy))
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        setAttrib(d && d.attribution);
                        var res = (d && d.results) || [];
                        if (!res.length || !box) { hideBox(); return; }
                        box.innerHTML = res.map(function (r) {
                            return '<button class="ff-addr-opt" data-lat="' + (r.lat || '') + '" data-lng="' + (r.lng || '') + '" data-label="' + ffEsc(r.label) + '" type="button">' + ffEsc(r.label) + '</button>';
                        }).join('');
                        box.hidden = false;
                    }).catch(hideBox);
            }, 350);
        });
        if (box) box.addEventListener('click', function (e) {
            var opt = e.target.closest('.ff-addr-opt'); if (!opt) return;
            input.value = opt.dataset.label;
            var la = q('cvCustLat'), ln = q('cvCustLng');
            if (la) la.value = opt.dataset.lat || ''; if (ln) ln.value = opt.dataset.lng || '';
            hideBox();
        });
        input.addEventListener('blur', function () { setTimeout(hideBox, 200); });
        var saved = q('cvSavedAddrs');
        if (saved) saved.addEventListener('click', function (e) {
            var b = e.target.closest('.ff-saved-addr'); if (!b) return;
            input.value = b.dataset.addr || '';
            var la = q('cvCustLat'), ln = q('cvCustLng');
            if (la) la.value = b.dataset.lat || ''; if (ln) ln.value = b.dataset.lng || '';
            saved.querySelectorAll('.ff-saved-addr').forEach(function (x) { x.classList.remove('is-on'); });
            b.classList.add('is-on');
            hideBox();
        });
        // R6 · paint the guest's remembered addresses into #cvSavedAddrs (guests only).
        renderRecentAddrs();
        var loc = q('cvUseLocation');
        if (loc) loc.addEventListener('click', function () {
            if (!navigator.geolocation) { ffToast(I18N.locUnavailable || 'Location unavailable'); return; }
            loc.classList.add('is-loading');
            navigator.geolocation.getCurrentPosition(function (pos) {
                var lat = pos.coords.latitude, lng = pos.coords.longitude;
                fetch(EP.geoReverse + '?lat=' + lat + '&lng=' + lng)
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        loc.classList.remove('is-loading');
                        setAttrib(d && d.attribution);
                        if (d && d.result) {
                            input.value = d.result.label || '';
                            var la = q('cvCustLat'), ln = q('cvCustLng');
                            if (la) la.value = d.result.lat || lat; if (ln) ln.value = d.result.lng || lng;
                        } else { ffToast(I18N.locNoAddress || 'No address found'); }
                    }).catch(function () { loc.classList.remove('is-loading'); });
            }, function () { loc.classList.remove('is-loading'); ffToast(I18N.locDenied || 'Location denied'); }, { enableHighAccuracy: true, timeout: 8000 });
        });
    })();

    /* ── F4 · nearest-branch map ──────────────────────────── */
    (function () {
        var openBtn = q('cvNearestBranchBtn');
        if (!openBtn || !EP.branchesGeo || typeof L === 'undefined') { if (openBtn) openBtn.style.display = 'none'; return; }
        ffBindSheetClose('cvBranchMapSheet', 'cvBranchMapClose', 'cvBranchMapBackdrop');
        var map = null, markers = [];
        var icon = L.icon({
            iconUrl: config.leafletImgPath + '/marker-icon.png',
            iconRetinaUrl: config.leafletImgPath + '/marker-icon-2x.png',
            shadowUrl: config.leafletImgPath + '/marker-shadow.png',
            iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
        });
        function selectBranch(id) {
            var url = new URL(window.location.href);
            url.searchParams.set('branch_id', id);
            window.location.href = url.toString();
        }
        function renderList(branches) {
            q('cvBranchGeoList').innerHTML = branches.map(function (b) {
                var dist = b.distance_km != null ? '<span class="ff-branch-dist" dir="ltr">' + b.distance_km.toFixed(1) + ' km</span>' : '';
                return '<button class="ff-branch-row" data-bid="' + b.id + '" type="button">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
                    '<b>' + ffEsc(b.name) + (b.address ? '<small>' + ffEsc(b.address) + '</small>' : '') + '</b>' + dist + '</button>';
            }).join('') || '<p class="ff-orders-empty">' + ffEsc('No branches') + '</p>';
        }
        function drawMap(branches) {
            var withCoords = branches.filter(function (b) { return b.latitude != null && b.longitude != null; });
            if (!map) {
                map = L.map('cvBranchMap', { attributionControl: false });
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
                L.control.attribution({ prefix: false }).addAttribution('© OpenStreetMap').addTo(map);
            }
            markers.forEach(function (m) { map.removeLayer(m); }); markers = [];
            if (!withCoords.length) { map.setView([24.7136, 46.6753], 11); return; }
            var group = [];
            withCoords.forEach(function (b) {
                var mk = L.marker([b.latitude, b.longitude], { icon: icon }).addTo(map);
                mk.bindPopup('<b>' + ffEsc(b.name) + '</b>');
                mk.on('click', function () { selectBranch(b.id); });
                markers.push(mk); group.push([b.latitude, b.longitude]);
            });
            map.fitBounds(group, { padding: [40, 40], maxZoom: 14 });
        }
        function load(lat, lng) {
            var url = EP.branchesGeo + (lat != null ? '?lat=' + lat + '&lng=' + lng : '');
            fetch(url).then(function (r) { return r.json(); }).then(function (d) {
                var branches = (d && d.branches) || [];
                q('cvMapAttrib').textContent = (d && d.attribution) || '© OpenStreetMap contributors';
                renderList(branches);
                setTimeout(function () { drawMap(branches); if (map) map.invalidateSize(); }, 80);
            }).catch(function () {});
        }
        openBtn.addEventListener('click', function () {
            ffSheetOpen('cvBranchMapSheet');
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function (p) { load(p.coords.latitude, p.coords.longitude); },
                    function () { load(); }, { timeout: 6000 });
            } else { load(); }
        });
        q('cvBranchGeoList').addEventListener('click', function (e) {
            var b = e.target.closest('[data-bid]'); if (b) selectBranch(b.dataset.bid);
        });
    })();

    /* ── F6 · rich reviews (display) ──────────────────────── */
    (function () {
        var curFilter = 'latest', curItemId = null;
        function starBar(n) { return '<span class="ff-stars-mini">' + '★'.repeat(n) + '<span class="ff-s-dim">' + '★'.repeat(5 - n) + '</span></span>'; }
        function render(d) {
            var wrap = q('ff-reviews-display'); if (!wrap) return;
            var avg = d.average || 0, count = d.count || 0, dist = d.distribution || {};
            var head = '<div class="ff-rv-head"><div class="ff-rv-avg"><b>' + avg.toFixed(1) + '</b>' + starBar(Math.round(avg)) +
                '<small>' + count + ' ' + ffEsc(I18N.reviews || 'reviews') + '</small></div>' +
                '<div class="ff-rv-dist">' + [5, 4, 3, 2, 1].map(function (s) {
                    var c = dist[s] || 0, pct = count ? Math.round(c / count * 100) : 0;
                    return '<span class="ff-rv-bar"><em>' + s + '</em><i><s style="width:' + pct + '%"></s></i><b>' + c + '</b></span>';
                }).join('') + '</div></div>';
            var filters = '<div class="ff-rv-filters">' + ['latest', 'verified', 'detailed'].map(function (f) {
                return '<button data-rvf="' + f + '" class="' + (f === curFilter ? 'is-on' : '') + '" type="button">' + ffEsc(I18N['rv_' + f] || f) + '</button>';
            }).join('') + '</div>';
            var list = (d.data || []).map(function (r) {
                var photos = (r.photos || []).map(function (p) { return '<img src="' + ffEsc(p.url) + '" alt="" data-csp-error="hide">'; }).join('');
                return '<div class="ff-rv-item"><div class="ff-rv-item-top"><b>' + ffEsc(r.name || '—') + '</b>' +
                    (r.is_verified ? '<span class="ff-rv-verified">✓ ' + ffEsc(I18N.rv_verified || 'Verified') + '</span>' : '') + '</div>' +
                    starBar(r.stars) + (r.body ? '<p>' + ffEsc(r.body) + '</p>' : '') +
                    (photos ? '<div class="ff-rv-photos">' + photos + '</div>' : '') + '</div>';
            }).join('');
            if (!(d.data || []).length) list = '<p class="ff-rv-empty">' + ffEsc(I18N.rv_empty || 'No reviews yet — be the first!') + '</p>';
            wrap.innerHTML = '<div class="ff-section-head"><h4 class="ff-option-title">' + ffEsc(I18N.reviews || 'Reviews') + '</h4></div>' +
                head + filters + '<div class="ff-rv-list">' + list + '</div>';
            wrap.hidden = false;
        }
        function load(itemId, filter) {
            curItemId = itemId; curFilter = filter || 'latest';
            var wrap = q('ff-reviews-display');
            fetch(EP.reviewsBase + '/' + itemId + '/reviews?filter=' + curFilter)
                .then(function (r) { return r.json(); }).then(render)
                .catch(function () { if (wrap) wrap.hidden = true; });
        }
        window.FEAST_LOAD_REVIEWS = function (id) { load(id, 'latest'); };
        /* filter clicks (delegated on the detail content, which is re-rendered per open) */
        document.addEventListener('click', function (e) {
            var f = e.target.closest('[data-rvf]');
            if (f && curItemId) { load(curItemId, f.dataset.rvf); }
        });
    })();

    /* ── F1 · meal-plan create sheet ──────────────────────── */
    (function () {
        var cta = q('cvMealPlanCta');
        if (!cta) return;
        if (!config.mealPlansEnabled) { cta.style.display = 'none'; return; }
        ffBindSheetClose('cvMealPlanSheet', 'cvMealPlanClose', 'cvMealPlanBackdrop');
        var sheetEl = q('cvMealPlanSheet');
        var cadence = 'weekly', cadences = [], days = {};
        try { cadences = JSON.parse(sheetEl.getAttribute('data-cadences') || '[]'); } catch (e) {}
        if (!cadences.length) cadences = ['daily', 'weekly'];
        function label(c) { return I18N['cad_' + c] || { daily: 'Daily', weekly: 'Weekly', custom: 'Custom days' }[c] || c; }
        function buildCadence() {
            var el = q('cvMpCadence'); if (!el) return;
            cadence = cadences.indexOf('weekly') !== -1 ? 'weekly' : cadences[0];
            el.innerHTML = cadences.map(function (c) {
                return '<button data-cad="' + c + '" class="' + (c === cadence ? 'is-on' : '') + '" type="button">' + ffEsc(I18N['cad_' + c] || label(c)) + '</button>';
            }).join('');
        }
        cta.addEventListener('click', function () {
            if (!cart.length) { ffToast(I18N.cartEmpty || 'Cart is empty'); return; }
            if (!hasToken()) { ffOpenAuth(); return; }
            buildCadence();
            var sum = q('cvMpItemsSummary');
            if (sum) sum.textContent = cart.reduce(function (a, l) { return a + l.quantity; }, 0) + ' × ' + (I18N.items || 'items');
            var dw = q('cvMpDaysWrap'); if (dw) dw.hidden = cadence !== 'custom';
            ffSheetOpen('cvMealPlanSheet');
        });
        var lb = q('cvMpLoginBtn'); if (lb) lb.addEventListener('click', function () { ffSheetClose('cvMealPlanSheet'); ffOpenAuth(); });
        if (sheetEl) sheetEl.addEventListener('click', function (e) {
            var c = e.target.closest('[data-cad]');
            if (c) {
                cadence = c.dataset.cad;
                sheetEl.querySelectorAll('#cvMpCadence button').forEach(function (b) { b.classList.toggle('is-on', b === c); });
                var dw = q('cvMpDaysWrap'); if (dw) dw.hidden = cadence !== 'custom';
                return;
            }
            var d = e.target.closest('[data-day]');
            if (d) { var k = d.dataset.day; days[k] = !days[k]; d.classList.toggle('is-on', days[k]); }
        });
        var sub = q('cvMpSubmit');
        if (sub) sub.addEventListener('click', function () {
            var err = q('cvMpErr'); if (err) err.style.display = 'none';
            var items = cart.map(function (l) {
                return { item_id: l.id, quantity: l.quantity, variation: l.variants || null, extras: (l.extras || []).map(function (e) { return e.id; }) };
            });
            if (!items.length) { if (err) { err.textContent = I18N.cartEmpty || 'Cart is empty'; err.style.display = 'block'; } return; }
            var body = { name: (q('cvMpName') || {}).value || null, cadence: cadence, start_date: (q('cvMpStart') || {}).value, items: items };
            if (typeof body.name === 'string') body.name = body.name.trim() || null;
            if (cadence === 'custom') {
                body.days = Object.keys(days).filter(function (k) { return days[k]; }).map(Number);
                if (!body.days.length) { if (err) { err.textContent = I18N.choose || 'Pick at least one day'; err.style.display = 'block'; } return; }
            }
            sub.disabled = true;
            fetch(EP.mealPlan, { method: 'POST', headers: ffToken(true), body: JSON.stringify(body) })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (res) {
                    sub.disabled = false;
                    if (res.ok && res.j && res.j.id) {
                        q('cvMealPlanForm').hidden = true;
                        q('cvMpDone').hidden = false;
                        q('cvMpDoneSub').textContent = (I18N.mp_next || 'Next order') + ': ' + (res.j.next_run || '');
                    } else {
                        var msg = res.j && (res.j.message || (res.j.errors && Object.values(res.j.errors)[0][0]));
                        if (err) { err.textContent = msg || 'Failed'; err.style.display = 'block'; }
                    }
                }).catch(function () { sub.disabled = false; if (err) { err.textContent = (I18N.networkError || 'Network error'); err.style.display = 'block'; } });
        });
    })();

    /* ── F7 · OTP checkout gate ───────────────────────────── */
    (function () {
        var boxes = Array.prototype.slice.call(document.querySelectorAll('#cvOtpGateBoxes input'));
        var curPhone = '';
        ffBindSheetClose('cvCheckoutOtpSheet', 'cvOtpGateClose', 'cvOtpGateBackdrop');
        function code() { return boxes.map(function (b) { return b.value; }).join(''); }
        boxes.forEach(function (inp, i) {
            inp.addEventListener('input', function () {
                inp.value = inp.value.replace(/\D/g, '').slice(0, 1);
                if (inp.value && boxes[i + 1]) boxes[i + 1].focus();
            });
            inp.addEventListener('keydown', function (e) { if (e.key === 'Backspace' && !inp.value && boxes[i - 1]) boxes[i - 1].focus(); });
        });
        /* FEAST-F12 · `notify` reports the outcome the SERVER gave, never the click.
           The resend button used to toast "Sent" from its own handler, so a rejected
           or unreachable send still told the customer a code was on its way. */
        function send(phone, notify) {
            fetch(EP.otpSend, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf },
                body: JSON.stringify({ phone: phone, store_id: config.storeId })
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d || !d.success) {
                    var e = q('cvOtpGateErr');
                    if (e) { e.textContent = (d && d.message) || I18N.failed || 'Failed'; e.style.display = 'block'; }
                    return;
                }
                if (notify) ffToast(I18N.codeSent || 'Code sent');
            }).catch(function () {
                var e = q('cvOtpGateErr');
                if (e) { e.textContent = (I18N.networkError || 'Network error'); e.style.display = 'block'; }
            });
        }
        window.FEAST_OPEN_OTP = function (phone) {
            curPhone = phone;
            var ph = q('cvOtpGatePhone'); if (ph) ph.textContent = phone;
            boxes.forEach(function (b) { b.value = ''; });
            var e = q('cvOtpGateErr'); if (e) e.style.display = 'none';
            ffSheetOpen('cvCheckoutOtpSheet');
            setTimeout(function () { if (boxes[0]) boxes[0].focus(); }, 300);
            send(phone);
        };
        var rs = q('cvOtpGateResend');
        if (rs) rs.addEventListener('click', function () { var e = q('cvOtpGateErr'); if (e) e.style.display = 'none'; send(curPhone, true); });
        var vf = q('cvOtpGateVerify');
        if (vf) vf.addEventListener('click', function () {
            var err = q('cvOtpGateErr'); if (err) err.style.display = 'none';
            var c = code();
            if (c.length < 4) { if (err) { err.textContent = I18N.required || 'Enter the code'; err.style.display = 'block'; } return; }
            vf.disabled = true;
            fetch(EP.otpVerify, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf },
                body: JSON.stringify({ phone: curPhone, code: c, store_id: config.storeId })
            }).then(function (r) { return r.json(); }).then(function (d) {
                vf.disabled = false;
                if (d && d.success) {
                    ffSheetClose('cvCheckoutOtpSheet');
                    /* retry — the gate now passes */
                    handleCheckoutSubmit(new Event('submit'));
                } else {
                    if (err) { err.textContent = (d && d.message) || I18N.invalid_code || 'Invalid code'; err.style.display = 'block'; }
                }
            }).catch(function () { vf.disabled = false; if (err) { err.textContent = (I18N.networkError || 'Network error'); err.style.display = 'block'; } });
        });
    })();

    /* ══════════════════════════════════════════════════════════
       R5 · guest order history (localStorage, no login needed)
       ══════════════════════════════════════════════════════════ */
    function readRecentOrders() {
        try { return (JSON.parse(localStorage.getItem('ff_recent_orders_' + slug) || '[]') || []).filter(function (o) { return o && o.track_url; }); }
        catch (e) { return []; }
    }
    function rememberOrder(res) {
        if (!res || !res.track_url) return;
        var list = readRecentOrders().filter(function (o) { return o.track_url !== res.track_url; });
        list.unshift({ id: res.order_id || '', track_url: res.track_url, total: res.total || '', placed_at: Date.now() });
        try { localStorage.setItem('ff_recent_orders_' + slug, JSON.stringify(list.slice(0, 10))); } catch (e) {}
        renderRecentOrders();
    }
    function renderRecentOrders() {
        var box = q('cvLookupResults'); if (!box) return;   /* guest recent-orders block only */
        var wrap = q('ff-recent-orders');
        var list = readRecentOrders();
        if (!list.length) { if (wrap) wrap.hidden = true; return; }
        if (wrap) wrap.hidden = false;
        box.innerHTML = list.map(function (o) {
            var when = new Date(o.placed_at || Date.now()).toLocaleDateString(document.documentElement.lang || 'en', { day: '2-digit', month: 'short' });
            var head = o.id ? '#' + ffEsc(o.id) + (o.total ? ' · ' + ffEsc(o.total) : '') : ffEsc(when);
            return '<a class="ff-recent-order-row" href="' + ffEsc(o.track_url) + '"><div><b>' + head + '</b><small>' + ffEsc(when) + '</small></div>' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m9 6 6 6-6 6"/></svg></a>';
        }).join('');
    }

    /* ══════════════════════════════════════════════════════════
       R6 · guest multi-address (remember addresses used on this device)
       The key is computed INLINE via addrKey(); a hoisted `var KEY = ...` declared
       after the address IIFE would still be undefined when renderRecentAddrs() runs
       at load (var-hoist gap → 0 chips).
       ══════════════════════════════════════════════════════════ */
    function addrKey() { return 'ff_recent_addrs_' + slug; }
    function readRecentAddrs() {
        try { return (JSON.parse(localStorage.getItem(addrKey()) || '[]') || []).filter(function (a) { return a && a.addr; }); }
        catch (e) { return []; }
    }
    function rememberAddress(addr, lat, lng) {
        addr = (addr || '').trim(); if (!addr || config.isLoggedIn) return;   /* signed-in have a real book */
        var list = readRecentAddrs().filter(function (a) { return a.addr !== addr; });
        list.unshift({ addr: addr, lat: lat || '', lng: lng || '' });
        try { localStorage.setItem(addrKey(), JSON.stringify(list.slice(0, 5))); } catch (e) {}
    }
    function renderRecentAddrs() {
        var saved = q('cvSavedAddrs');
        if (!saved || config.isLoggedIn) return;   /* server book owns the container for signed-in */
        var list = readRecentAddrs();
        if (!list.length) { saved.hidden = true; return; }
        saved.hidden = false;
        saved.innerHTML = list.map(function (a, i) {
            var short = a.addr.length > 42 ? a.addr.slice(0, 42) + '…' : a.addr;
            return '<button class="ff-saved-addr' + (i === 0 ? ' is-on' : '') + '" type="button" data-addr="' + ffEsc(a.addr) + '" data-lat="' + ffEsc(a.lat) + '" data-lng="' + ffEsc(a.lng) + '">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>' +
                '<span class="ff-sa-text"><b>' + ffEsc(I18N.recentAddress || 'Recent address') + '</b><small>' + ffEsc(short) + '</small></span></button>';
        }).join('');
    }

    /* ══════════════════════════════════════════════════════════
       R7 · ?item=<id> deeplink + same-page CTA intercept
       ══════════════════════════════════════════════════════════ */
    function initDeeplink() {
        /* Same-page ?item=<id> link clicks (promo banners, internal CTAs) open the
           product in place instead of reloading the whole page. External links and
           links opening a new tab fall through untouched. */
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
        /* Boot: open any ?item=<id> product on first load (shared / deep link). */
        try {
            var deepItem = new URLSearchParams(window.location.search).get('item');
            if (deepItem && config.items[deepItem]) openDetailDrawer(deepItem);
        } catch (e) {}
    }

    /* ── Today's specials rail: tap a spotlight to open its detail sheet ── */
    function initSpecials() {
        var rail = document.getElementById('ff-specials');
        if (!rail) return;
        rail.addEventListener('click', function (e) {
            var card = e.target.closest('[data-open-item]');
            if (card) openDetailDrawer(card.getAttribute('data-open-item'));
        });
        rail.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var card = e.target.closest('[data-open-item]');
            if (card) { e.preventDefault(); openDetailDrawer(card.getAttribute('data-open-item')); }
        });
    }

    /* ── Tips: preset % chips + custom amount; folds into the cart total ── */
    function initTips() {
        var row = document.getElementById('ff-tip-row');
        if (!row) return;
        var custom = document.getElementById('ff-custom-tip');
        row.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-tip]');
            if (!btn) return;
            row.querySelectorAll('button').forEach(function (b) { b.classList.toggle('is-on', b === btn); });
            var val = btn.getAttribute('data-tip');
            if (val === 'custom') {
                tipPct = 0;
                if (custom) { custom.hidden = false; tipCustom = Number(custom.value) || 0; }
            } else {
                tipPct = Number(val) || 0;
                tipCustom = 0;
                if (custom) { custom.hidden = true; custom.value = ''; }
            }
            updateCartUI();
        });
        if (custom) custom.addEventListener('input', function () {
            tipCustom = Number(custom.value) || 0;
            updateCartUI();
        });
    }

    /* ── Call the waiter (on-table): POST table + type, confirm via toast ── */
    function initWaiter() {
        var btn = document.getElementById('ff-call-waiter');
        if (!btn || !config.allowCallWaiter) return;
        btn.addEventListener('click', function () {
            var tblInput = document.getElementById('ff-table-num');
            var table = (tblInput && tblInput.value) || config.defaultTable || '';
            if (!table) { ffToast(config.lang.tableRequired || 'Please enter your table number'); return; }
            btn.disabled = true;
            fetch(config.endpoints.waiter, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': config.csrf },
                body: JSON.stringify({ table: table, type: 'call_waiter', _token: config.csrf })
            }).then(function (r) { return r.json(); }).then(function (d) {
                btn.disabled = false;
                if (d && d.success) { ffToast(config.lang.waiterOk); }
                else { ffToast((d && d.message) || config.lang.failed); }
            }).catch(function () { btn.disabled = false; ffToast(config.lang.failed); });
        });
    }

    /* ── Dietary / spice / allergen filter drawer ── */
    function initFilterDrawer() {
        var openBtn = document.getElementById('ff-filter-btn');
        var drawer = document.getElementById('ff-filter-drawer');
        if (!openBtn || !drawer) return;
        var dot = document.getElementById('ff-filter-dot');

        function open() { drawer.setAttribute('aria-hidden', 'false'); setScrollLock(true); }
        function close() { drawer.setAttribute('aria-hidden', 'true'); setScrollLock(false); }
        openBtn.addEventListener('click', open);
        var cb = document.getElementById('ff-filter-close'); if (cb) cb.addEventListener('click', close);
        var bd = document.getElementById('ff-filter-backdrop'); if (bd) bd.addEventListener('click', close);

        function toggleChip(btn, arr, key) {
            var v = btn.getAttribute(key);
            var i = arr.indexOf(v);
            if (i === -1) { arr.push(v); btn.classList.add('is-on'); }
            else { arr.splice(i, 1); btn.classList.remove('is-on'); }
        }
        var dietRow = document.getElementById('ff-diet-row');
        if (dietRow) dietRow.addEventListener('click', function (e) {
            var b = e.target.closest('[data-diet]'); if (b) toggleChip(b, dietFilters.diets, 'data-diet');
        });
        var spiceRow = document.getElementById('ff-spice-row');
        if (spiceRow) spiceRow.addEventListener('click', function (e) {
            var b = e.target.closest('[data-spice]'); if (b) toggleChip(b, dietFilters.spice, 'data-spice');
        });
        var alRow = document.getElementById('ff-allergen-row');
        if (alRow) alRow.addEventListener('click', function (e) {
            var b = e.target.closest('[data-al]'); if (b) toggleChip(b, dietFilters.allergens, 'data-al');
        });

        function activeCount() { return dietFilters.diets.length + dietFilters.spice.length + dietFilters.allergens.length; }
        function syncDot() { if (dot) dot.hidden = activeCount() === 0; }

        var apply = document.getElementById('ff-filter-apply');
        if (apply) apply.addEventListener('click', function () { syncDot(); applyFilters(); close(); });

        var reset = document.getElementById('ff-filter-reset');
        if (reset) reset.addEventListener('click', function () {
            dietFilters.diets = []; dietFilters.spice = []; dietFilters.allergens = [];
            drawer.querySelectorAll('.ff-chiprow button.is-on').forEach(function (b) { b.classList.remove('is-on'); });
            syncDot(); applyFilters();
        });
    }

    /* ── FEAST-F5 · appearance: cycles system → dark → light, persists per store.
         The pre-paint inline script in the blade reads the same key, so the choice
         holds on the menu, the account page and the track page alike. ── */
    function initFace() {
        var btn = document.getElementById('ff-face-toggle');
        if (!btn) return;
        var key = 'ff_face_' + config.storeId;
        var root = document.documentElement;
        function read() { try { return localStorage.getItem(key) || ''; } catch (e) { return ''; } }
        function sync() {
            var v = read();
            if (v === 'dark' || v === 'light') root.setAttribute('data-ff-face', v);
            else root.removeAttribute('data-ff-face');
            var isDark = v === 'dark' ||
                (!v && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        }
        btn.addEventListener('click', function () {
            /* Always flip the EFFECTIVE face. The old system→dark→light cycle produced
               a "dead" click whenever the current system scheme already matched the
               next state (e.g. system-dark → 'dark' looks identical), so the user had
               to click twice to see a change. A 2-state flip guarantees every click
               visibly toggles. */
            var v = read();
            var isDark = v === 'dark' ||
                (!v && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            var next = isDark ? 'light' : 'dark';
            try { localStorage.setItem(key, next); } catch (e) {}
            sync();
        });
        sync();
    }

    /* ── FEAST-F15 · language menu. The links are real /lang/{code} navigations,
         which set the SESSION locale, so the whole UI follows on every page. ── */
    function initLangMenu() {
        var btn = document.getElementById('ff-lang-toggle');
        var menu = document.getElementById('ff-lang-menu');
        if (!btn || !menu) return;
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = menu.hidden;
            menu.hidden = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) {
            if (menu.hidden || menu.contains(e.target) || btn.contains(e.target)) return;
            menu.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !menu.hidden) { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
        });
    }

    /* ── Multi-currency switcher (parity: crumb/velora contract). The switcher
         changes DISPLAY only — totals stay in the base currency and the order
         payload always carries base amounts, so the charged amount never moves.
         The choice persists store-scoped via the shared mb-currency.js helper. ── */
    function repaintStaticPrices() {
        // The menu grid + specials rail are server-rendered; each price node carries
        // data-ff-price with its raw base amount. Repaint them through the now
        // currency-aware formatter (the client-rendered cart/detail/checkout all
        // already go through formatPrice on their next render).
        document.querySelectorAll('[data-ff-price]').forEach(function (el) {
            el.textContent = formatPrice(parseFloat(el.getAttribute('data-ff-price')) || 0);
        });
    }
    function rerenderMoney() {
        repaintStaticPrices();
        updateCartUI();   // cart list + checkout summary (every figure via formatPrice)
        // Detail sheet, if it happens to be open, rebuilds so its variant/extra prices convert.
        if (currentDetailItem && elements.detailDrawer &&
            elements.detailDrawer.getAttribute('aria-hidden') === 'false') {
            openDetailDrawer(currentDetailItem.id);
        }
    }
    function initCurrency() {
        var seg = document.getElementById('curSeg');
        if (seg) {
            seg.addEventListener('click', function (e) {
                var b = e.target.closest('[data-cur]');
                if (!b) return;
                seg.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-on', x === b); });
                curCode = b.dataset.cur;
                curRate = parseFloat(b.dataset.rate) || 1;
                var codeLabel = document.getElementById('ff-cur-code');
                if (codeLabel) codeLabel.textContent = curCode;
                rerenderMoney();
                if (window.mbCurrency) mbCurrency.save(curCode, curRate);   // persist store-scoped (shared helper)
            });
        }
        // Appbar dropdown open/close — mirrors initLangMenu.
        var btn = document.getElementById('ff-cur-toggle');
        var menu = document.getElementById('ff-cur-menu');
        if (!btn || !menu) return;
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = menu.hidden;
            menu.hidden = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) {
            if (menu.hidden || menu.contains(e.target) || btn.contains(e.target)) return;
            menu.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !menu.hidden) { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
        });
    }

    /* ── Splash: once per session, dismiss on skip / tap (auto-fade via CSS) ──
       R2 · on the FIRST view of a session we set the session key and keep the
       normal timed CSS fade; on a REPEAT view the key is present, so we drop the
       splash instantly (the blade's pre-paint guard also flags <html> so it never
       even flashes). Keeps the existing is-hidden dismiss class/animation. */
    function initSplash() {
        var splash = document.getElementById('ff-splash');
        if (!splash) return;
        var key = 'ff_splash_' + slug;
        var seen = false;
        try { seen = !!sessionStorage.getItem(key); } catch (e) {}
        if (seen) { splash.remove(); return; }
        /* A1: a ?item=/?table= deep link opens straight on the product — remove the
           splash up front so it never overlays it (splash z-index sits above the
           sheet). Not persisted: a later plain visit this session still gets it. */
        var deepLinked = false;
        try { var _dl = new URLSearchParams(location.search); deepLinked = !!(_dl.get('item') || _dl.get('table')); } catch (e) {}
        if (deepLinked) { splash.remove(); return; }
        try { sessionStorage.setItem(key, '1'); } catch (e) {}
        function hide() { splash.classList.add('is-hidden'); setTimeout(function () { splash.remove(); }, 600); }
        var skip = document.getElementById('ff-splash-skip');
        if (skip) skip.addEventListener('click', hide);
        splash.addEventListener('click', hide);
    }

})();

/* ═══ Favorites (feast shipped with none) ═══
   Hearts on the blade menu cards persist to localStorage and feed a favourites
   drawer opened from the dock. Self-contained: reads the DOM cards and reuses the
   theme's own ?item= open handler. */
(function () {
    var cfg = window.FEAST || {};
    var KEY = 'feast-favs-' + (cfg.restaurantSlug || cfg.storeId || 'default');
    var favs;
    try { favs = new Set((JSON.parse(localStorage.getItem(KEY) || '[]') || []).map(String)); } catch (e) { favs = new Set(); }
    function persist() { try { localStorage.setItem(KEY, JSON.stringify(Array.from(favs))); } catch (e) {} }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function paint() {
        document.querySelectorAll('.ff-fav-btn[data-fffav]').forEach(function (b) {
            b.classList.toggle('is-fav', favs.has(String(b.getAttribute('data-fffav'))));
        });
    }
    function cardData(id) {
        var card = document.querySelector('.ff-menu-card[data-id="' + id + '"]');
        if (!card) return null;
        var n = card.querySelector('.ff-card-name'), img = card.querySelector('.ff-card-img'), p = card.querySelector('.ff-card-price');
        return { name: n ? n.textContent.trim() : '', img: img ? img.getAttribute('src') : '', price: p ? p.textContent.trim() : '' };
    }
    var drawer = document.getElementById('ff-fav-drawer');
    var list = document.getElementById('ff-fav-list');
    var empty = document.getElementById('ff-fav-empty');
    function render() {
        if (!list) return;
        var ids = Array.from(favs).filter(function (id) { return document.querySelector('.ff-menu-card[data-id="' + id + '"]'); });
        if (!ids.length) { list.innerHTML = ''; if (empty) empty.style.display = ''; return; }
        if (empty) empty.style.display = 'none';
        list.innerHTML = ids.map(function (id) {
            var d = cardData(id); if (!d) return '';
            return '<a class="ff-fav-row" href="?item=' + encodeURIComponent(id) + '">' +
                (d.img ? '<img class="ff-fav-row-img" src="' + esc(d.img) + '" alt="">' : '<span class="ff-fav-row-img"></span>') +
                '<span class="ff-fav-row-body"><span class="ff-fav-row-name">' + esc(d.name) + '</span>' +
                '<span class="ff-fav-row-price">' + esc(d.price) + '</span></span></a>';
        }).join('');
    }
    function open() { render(); if (drawer) { drawer.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; } }
    function close() { if (drawer) { drawer.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; } }

    document.addEventListener('click', function (e) {
        var h = e.target.closest && e.target.closest('.ff-fav-btn[data-fffav]');
        if (!h) return;
        e.preventDefault(); e.stopPropagation();
        var id = String(h.getAttribute('data-fffav'));
        if (favs.has(id)) favs.delete(id); else favs.add(id);
        persist(); paint();
        if (drawer && drawer.getAttribute('aria-hidden') === 'false') render();
    }, true);

    var dockFav = document.querySelector('#ff-tabbar .ff-tab[data-tab="favs"]');
    if (dockFav) dockFav.addEventListener('click', open);
    var cb = document.getElementById('ff-fav-close'); if (cb) cb.addEventListener('click', close);
    var bd = document.getElementById('ff-fav-backdrop'); if (bd) bd.addEventListener('click', close);
    if (list) list.addEventListener('click', function (e) { if (e.target.closest('.ff-fav-row')) close(); });

    paint();
})();
