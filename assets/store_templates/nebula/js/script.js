// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* =========================================================================
   NEBULA — Cosmic Dining Template Behaviour
   Vanilla JS (jQuery available from layout). Handles: starfield, search,
   category filtering, dietary filters, favorites, dish detail bottom-sheet
   with variants/extras, cart, checkout, theme toggle, calls, and more.
   ========================================================================= */
(function () {
    'use strict';
  /* HTML-escape for innerHTML templates: names/labels are owner/staff-typed. */
  function jwEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }


    var CFG = window.NEBULA || {};
    var ITEMS = CFG.items || {};
    var CART_KEY = 'nb_cart_' + (CFG.slug || 'default');
    var FAV_KEY = 'nb_fav_' + (CFG.slug || 'default');

    var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

    var CUR_DP = CFG.currencyDecimals != null ? CFG.currencyDecimals : 2;

    function money(amount) {
        var f = Math.pow(10, CUR_DP);
        var n = Math.round((Number(amount) || 0) * f) / f;
        var str = n.toFixed(CUR_DP);
        return CFG.curPos === 'right' ? str + CFG.curSign : CFG.curSign + str;
    }

    function readJSON(key, fallback) {
        try { return JSON.parse(localStorage.getItem(key)) || fallback; } catch (e) { return fallback; }
    }
    function writeJSON(key, val) { localStorage.setItem(key, JSON.stringify(val)); }

    function saveCart() { writeJSON(CART_KEY, { items: cart, timestamp: Date.now() }); }

    var rawCart = readJSON(CART_KEY, null);
    var cart = [];
    var showResume = false;

    if (rawCart) {
        if (rawCart.items && Array.isArray(rawCart.items)) {
            if (Date.now() - rawCart.timestamp < 86400000) {
                var valid = rawCart.items.filter(function (i) { return ITEMS[i.id] && !ITEMS[i.id].is_sold_out; });
                if (valid.length) { cart = valid; showResume = true; }
                else { localStorage.removeItem(CART_KEY); }
            } else { localStorage.removeItem(CART_KEY); }
        }
    }

    var favorites = readJSON(FAV_KEY, []);

    /* ─────────────────────────── STICKY HEADER ─────────────────────────── */
    var header = $('.nb-header');
    function onScroll() {
        if (header) header.classList.toggle('nb-header--stuck', window.pageYOffset > 60);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    /* ─────────────────────────── SEARCH ────────────────────────────────── */
    var searchInput = $('#nb-search');
    var searchClear = $('#nb-search-clear');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var term = this.value.toLowerCase().trim();
            if (searchClear) searchClear.style.display = term ? '' : 'none';
            filterItems();
        });
        if (searchClear) {
            searchClear.addEventListener('click', function () {
                if (searchInput) { searchInput.value = ''; searchInput.focus(); }
                searchClear.style.display = 'none';
                filterItems();
            });
        }
    }

    /* ─────────────────────────── CATEGORIES ────────────────────────────── */
    var activeCat = 'all';
    var chips = $$('.nb-chip');

    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            chips.forEach(function (c) { c.classList.remove('active'); });
            this.classList.add('active');
            activeCat = this.getAttribute('data-target') || 'all';
            filterItems();
            // Scroll categories into view on mobile
            var scrollEl = document.getElementById('nb-chips-scroll');
            if (scrollEl) {
                this.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            }
        });
    });

    /* ─────────────────────────── FILTERS ───────────────────────────────── */
    var filtersToggle = $('#nb-filters-toggle');
    var filtersPanel = $('#nb-filters-panel');
    if (filtersToggle && filtersPanel) {
        filtersToggle.addEventListener('click', function () {
            filtersPanel.classList.toggle('nb-panel--open');
        });
    }

    var filterChips = $$('.nb-filter-chip');
    filterChips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            this.classList.toggle('active');
            filterItems();
        });
    });

    var filterClear = $('#nb-filter-clear');
    if (filterClear) {
        filterClear.addEventListener('click', function () {
            filterChips.forEach(function (c) { c.classList.remove('active'); });
            filterItems();
        });
    }

    /* ─────────────────────────── FILTER ENGINE ─────────────────────────── */
    var emptyEl = $('#nb-empty');
    function filterItems() {
        var term = searchInput ? searchInput.value.toLowerCase().trim() : '';
        var filterVegan = !!$('.nb-filter-chip[data-filter="vegan"].active');
        var filterHalal = !!$('.nb-filter-chip[data-filter="halal"].active');
        var filterGf = !!$('.nb-filter-chip[data-filter="gf"].active');
        var filterPopular = !!$('.nb-filter-chip[data-filter="popular"].active');
        var activeSpices = $$('.nb-filter-chip[data-filter^="spice-"].active').map(function (c) {
            return c.getAttribute('data-filter').replace('spice-', '');
        });
        var excludedAllergens = $$('.nb-allergen-chip.active').map(function (c) {
            return c.getAttribute('data-allergen-id');
        });
        var favMode = !!$('#nb-fav-toggle.active');

        var anyVisible = false;

        $$('.nb-cat-section').forEach(function (section) {
            var catId = section.getAttribute('data-cat');
            var sectVis = false;
            $$('.nb-card', section).forEach(function (card) {
                var id = card.getAttribute('data-id');
                var name = (card.getAttribute('data-name') || '').toLowerCase();
                var desc = (card.getAttribute('data-desc') || '').toLowerCase();
                var matchCat = activeCat === 'all' || 'cat-' + catId === activeCat;
                var matchSearch = !term || name.indexOf(term) !== -1 || desc.indexOf(term) !== -1;
                var matchFav = !favMode || favorites.indexOf(Number(id)) !== -1;
                var matchVegan = !filterVegan || card.getAttribute('data-vegan') === '1';
                var matchHalal = !filterHalal || card.getAttribute('data-halal') === '1';
                var matchGf = !filterGf || card.getAttribute('data-gf') === '1';
                var matchPop = !filterPopular || card.getAttribute('data-popular') === '1';
                var cardSpice = card.getAttribute('data-spice') || '0';
                var matchSpice = !activeSpices.length || activeSpices.indexOf(cardSpice) !== -1;
                var cardAllergens = (card.getAttribute('data-allergens') || '').split(',').filter(Boolean);
                var matchAllergen = !excludedAllergens.some(function (ex) {
                    return cardAllergens.indexOf(ex) !== -1;
                });

                var show = matchCat && matchSearch && matchFav && matchVegan && matchHalal &&
                           matchGf && matchPop && matchSpice && matchAllergen;
                card.style.display = show ? '' : 'none';
                if (show) { sectVis = true; anyVisible = true; }
            });
            section.style.display = sectVis ? '' : 'none';
        });

        if (emptyEl) emptyEl.classList.toggle('show', !anyVisible);
    }

    /* ─────────────────────────── BOTTOM SHEETS ─────────────────────────── */
    function openSheet(id) {
        var sheet = document.getElementById(id);
        var overlay = document.getElementById('nb-overlay');
        if (sheet) { sheet.classList.add('nb-sheet--open'); sheet.setAttribute('aria-hidden', 'false'); }
        if (overlay) { overlay.classList.add('nb-overlay--active'); document.body.style.overflow = 'hidden'; }
    }
    function closeSheet(sheet) {
        var overlay = document.getElementById('nb-overlay');
        if (sheet) { sheet.classList.remove('nb-sheet--open'); sheet.setAttribute('aria-hidden', 'true'); }
        if (overlay) { overlay.classList.remove('nb-overlay--active'); document.body.style.overflow = ''; }
    }

    var overlay = document.getElementById('nb-overlay');
    if (overlay) {
        overlay.addEventListener('click', function () {
            $$('.nb-sheet--open').forEach(function (s) { closeSheet(s); });
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            $$('.nb-sheet--open').forEach(function (s) { closeSheet(s); });
        }
    });

    /* ─────────────────────────── ITEM DETAIL SHEET ─────────────────────── */
    function renderDetail(itemId) {
        var item = ITEMS[itemId];
        if (!item) return;
        var sheet = document.getElementById('nb-detail-sheet');
        var overlay = document.getElementById('nb-overlay');
        if (!sheet) return;

        var html = '<div class="nb-sheet-handle"></div>';
        html += '<div class="nb-sheet-content">';

        // Image gallery (carousel)
        if (item.img && item.img.indexOf('default') === -1) {
            var nbImages = [item.img].concat(item.gallery || []);
            html += '<div class="nb-detail-gallery">' +
                (window.mbCarousel ? mbCarousel.html(nbImages)
                    : '<img src="' + jwEsc(item.img) + '" alt="' + jwEsc(item.name) + '" class="nb-detail-img">') +
                '</div>';
        }

        // Badges
        var badges = [];
        if (item.is_vegan) badges.push('nb-badge--veg');
        if (item.is_halal) badges.push('nb-badge--halal');
        if (item.is_gluten_free) badges.push('nb-badge--gf');
        if (item.is_popular) badges.push('nb-badge--popular');
        if (item.is_daily_special) badges.push('nb-badge--special');
        if (item.is_sold_out) badges.push('nb-badge--soldout');

        if (badges.length) {
            html += '<div class="nb-detail-badges">';
            badges.forEach(function (b) {
                var labels = {
                    'nb-badge--veg': '<i class="ico ico-leaf" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 21c.5 -4.5 2.5 -8 7 -10"/><path d="M9 18c6.218 0 10.5 -3.288 11 -12v-2h-4.014c-9 0 -11.986 4 -12 9c0 1 0 3 2 5h3l.014 0"/></svg></i> ' + CFG.translations.vegan,
                    'nb-badge--halal': '<i class="ico ico-rosette-discount-check" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 7.2a2.2 2.2 0 0 1 2.2 -2.2h1a2.2 2.2 0 0 0 1.55 -.64l.7 -.7a2.2 2.2 0 0 1 3.12 0l.7 .7c.412 .41 .97 .64 1.55 .64h1a2.2 2.2 0 0 1 2.2 2.2v1c0 .58 .23 1.138 .64 1.55l.7 .7a2.2 2.2 0 0 1 0 3.12l-.7 .7a2.2 2.2 0 0 0 -.64 1.55v1a2.2 2.2 0 0 1 -2.2 2.2h-1a2.2 2.2 0 0 0 -1.55 .64l-.7 .7a2.2 2.2 0 0 1 -3.12 0l-.7 -.7a2.2 2.2 0 0 0 -1.55 -.64h-1a2.2 2.2 0 0 1 -2.2 -2.2v-1a2.2 2.2 0 0 0 -.64 -1.55l-.7 -.7a2.2 2.2 0 0 1 0 -3.12l.7 -.7a2.2 2.2 0 0 0 .64 -1.55v-1"/><path d="M9 12l2 2l4 -4"/></svg></i> ' + CFG.translations.halal,
                    'nb-badge--gf': '<i class="ico ico-wheat" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12.014 21.514v-3.75"/><path d="M5.93 9.504l-.43 1.604c-.712 2.659 .866 5.391 3.524 6.105c.997 .268 1.993 .535 2.99 .801v-3.44c-.164 -2.105 -1.637 -3.879 -3.676 -4.426l-2.408 -.644"/><path d="M13.744 11.164c.454 -.454 .815 -.994 1.061 -1.587c.246 -.594 .372 -1.23 .372 -1.873c0 -.643 -.126 -1.279 -.372 -1.872c-.246 -.594 -.606 -1.133 -1.061 -1.588l-1.73 -1.73l-1.73 1.73c-.454 .454 -.815 .994 -1.06 1.588c-.246 .594 -.372 1.23 -.373 1.872c0 .643 .127 1.279 .373 1.873c.246 .594 .606 1.133 1.06 1.587"/><path d="M18.099 9.504l.43 1.604c.712 2.659 -.866 5.391 -3.525 6.105c-.997 .268 -1.994 .535 -2.99 .801v-3.44c.164 -2.105 1.637 -3.879 3.677 -4.426l2.408 -.644"/></svg></i> ' + CFG.translations.gf,
                    'nb-badge--popular': '<i class="ico ico-flame" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 21a7 7 0 0 0 7-7c0-4-3-6-4-9-1.5 2-2 3.5-2 5-1-1-2-2-2.5-4C8 8 5 10.5 5 14a7 7 0 0 0 7 7z"/></svg></i> ' + CFG.translations.popular,
                    'nb-badge--special': '⭐ ' + CFG.translations.dailySpecial,
                    'nb-badge--soldout': '<i class="ico ico-ban" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M5.7 5.7l12.6 12.6"/></svg></i> ' + CFG.translations.soldOut
                };
                html += '<span class="nb-badge ' + b + '">' + (labels[b] || '') + '</span>';
            });
            html += '</div>';
        }

        html += '<h2 class="nb-detail-title">' + jwEsc(item.name) + '</h2>';

        if (item.rating) {
            html += '<div class="nb-detail-rating"><i class="ico ico-star-filled" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false"><path d="M8.243 7.34l-6.38 .925l-.113 .023a1 1 0 0 0 -.44 1.684l4.622 4.499l-1.09 6.355l-.013 .11a1 1 0 0 0 1.464 .944l5.706 -3l5.693 3l.1 .046a1 1 0 0 0 1.352 -1.1l-1.091 -6.355l4.624 -4.5l.078 -.085a1 1 0 0 0 -.633 -1.62l-6.38 -.926l-2.852 -5.78a1 1 0 0 0 -1.794 0l-2.853 5.78z"/></svg></i> ' + item.rating + '</div>';
        }

        html += '<div class="nb-detail-price">' + money(item.price) + '</div>';

        if (item.desc) {
            html += '<p class="nb-detail-desc">' + jwEsc(item.desc) + '</p>';
        }

        // Nutrition
        if (item.calories || item.protein || item.carbs || item.fat) {
            html += '<div class="nb-nutrition">';
            html += '<h4>' + CFG.translations.nutrition + '</h4>';
            html += '<div class="nb-nutrition-grid">';
            if (item.calories) html += '<div class="nb-nutrition-item"><span class="nb-nutrition-val">' + item.calories + '</span><span class="nb-nutrition-label">kcal</span></div>';
            if (item.protein) html += '<div class="nb-nutrition-item"><span class="nb-nutrition-val">' + item.protein + 'g</span><span class="nb-nutrition-label">' + CFG.translations.protein + '</span></div>';
            if (item.carbs) html += '<div class="nb-nutrition-item"><span class="nb-nutrition-val">' + item.carbs + 'g</span><span class="nb-nutrition-label">' + CFG.translations.carbs + '</span></div>';
            if (item.fat) html += '<div class="nb-nutrition-item"><span class="nb-nutrition-val">' + item.fat + 'g</span><span class="nb-nutrition-label">' + CFG.translations.fat + '</span></div>';
            html += '</div></div>';
        }

        // Allergies
        if (item.allergies && item.allergies.length) {
            html += '<div class="nb-detail-allergies">';
            html += '<h4>' + CFG.translations.allergens + '</h4><div class="nb-allergen-list">';
            item.allergies.forEach(function (a) {
                html += '<span class="nb-allergen-tag"><img src="' + jwEsc(a.img) + '" alt="' + jwEsc(a.title) + '"> ' + jwEsc(a.title) + '</span>';
            });
            html += '</div></div>';
        }

        // Variants
        if (item.variants && item.variants.length) {
            html += '<div class="nb-variant-group">';
            html += '<h4>' + CFG.translations.options + '</h4>';
            html += '<div class="nb-variant-options" data-item-id="' + item.id + '">';
            item.variants.forEach(function (v, i) {
                html += '<label class="nb-variant-option">';
                html += '<input type="radio" name="variant-' + item.id + '" value="' + v.id + '" data-price="' + v.price + '"' + (i === 0 ? ' checked' : '') + '>';
                html += '<span class="nb-variant-label">' + jwEsc(v.label) + '</span>';
                html += '<span class="nb-variant-price">' + money(v.price) + '</span>';
                html += '</label>';
            });
            html += '</div></div>';
        }

        // Extras
        if (item.extras && item.extras.length) {
            html += '<div class="nb-extras-group">';
            html += '<h4>' + CFG.translations.extras + '</h4>';
            html += '<div class="nb-extras-options" data-item-id="' + item.id + '">';
            item.extras.forEach(function (e) {
                html += '<label class="nb-extras-option">';
                html += '<input type="checkbox" value="' + e.id + '" data-price="' + e.price + '">';
                html += '<span class="nb-extras-label">' + jwEsc(e.title) + '</span>';
                html += '<span class="nb-extras-price">' + money(e.price) + '</span>';
                html += '</label>';
            });
            html += '</div></div>';
        }

        // Add to cart button
        html += '<button type="button" class="nb-add-to-cart-btn" data-item-id="' + item.id + '"' +
                (item.is_sold_out ? ' disabled' : '') + '>' +
                (item.is_sold_out
                    ? '<i class="ico ico-ban" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M5.7 5.7l12.6 12.6"/></svg></i> ' + CFG.translations.soldOut
                    : '<i class="ico ico-plus" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg></i> ' + CFG.translations.addToCart + ' — <span class="nb-add-price">' + money(item.price) + '</span>') +
                '</button>';

        html += '</div>';
        sheet.innerHTML = html;
        sheet.setAttribute('data-item-id', item.id);

        // Disable scrolling on sheet body
        var sheetBody = sheet.querySelector('.nb-sheet-content');
        if (sheetBody) sheetBody.style.overflowY = 'auto';

        // Variant change handler
        var variantRadios = sheet.querySelectorAll('input[name="variant-' + item.id + '"]');
        variantRadios.forEach(function (r) {
            r.addEventListener('change', function () { updateDetailPrice(item.id); });
        });

        // Extra checkbox handler
        var extraChecks = sheet.querySelectorAll('.nb-extras-options input[type="checkbox"]');
        extraChecks.forEach(function (c) {
            c.addEventListener('change', function () { updateDetailPrice(item.id); });
        });

        // Add to cart button
        var addBtn = sheet.querySelector('.nb-add-to-cart-btn');
        if (addBtn && !item.is_sold_out) {
            addBtn.addEventListener('click', function () { addToCart(item.id, sheet); });
        }

        openSheet('nb-detail-sheet');
    }

    function updateDetailPrice(itemId) {
        var item = ITEMS[itemId];
        if (!item) return;
        var sheet = document.getElementById('nb-detail-sheet');
        if (!sheet) return;

        var base = item.price;
        var selected = sheet.querySelector('input[name="variant-' + itemId + '"]:checked');
        if (selected) { base = parseFloat(selected.getAttribute('data-price')); }

        var extras = $$('.nb-extras-options input[type="checkbox"]:checked');
        extras.forEach(function (e) { base += parseFloat(e.getAttribute('data-price')); });

        var priceEl = sheet.querySelector('.nb-add-price');
        if (priceEl) priceEl.textContent = money(base);
    }

    /* ─────────────────────────── OPEN ITEMS ────────────────────────────── */
    document.addEventListener('click', function (e) {
        var target = e.target.closest('[data-open-item]');
        if (target) {
            var id = target.getAttribute('data-open-item');
            if (id && !target.closest('.nb-card--sold-out')) {
                renderDetail(id);
            }
        }
    });

    /* ─────────────────────────── CART ──────────────────────────────────── */
    function addToCart(itemId, sheet) {
        var item = ITEMS[itemId];
        if (!item || item.is_sold_out) return;

        var variantId = null;
        var variantPrice = item.price;
        var variantLabel = '';

        if (sheet) {
            var selected = sheet.querySelector('input[name="variant-' + itemId + '"]:checked');
            if (selected) {
                variantId = selected.value;
                variantPrice = parseFloat(selected.getAttribute('data-price'));
                variantLabel = selected.closest('.nb-variant-option') ? selected.closest('.nb-variant-option').querySelector('.nb-variant-label').textContent : '';
            }
        }

        var extras = [];
        if (sheet) {
            $$('.nb-extras-options input[type="checkbox"]:checked').forEach(function (e) {
                extras.push({ id: e.value, price: parseFloat(e.getAttribute('data-price')), label: e.closest('.nb-extras-option').querySelector('.nb-extras-label').textContent });
            });
        }

        var totalPrice = variantPrice;
        extras.forEach(function (e) { totalPrice += e.price; });

        var existing = -1;
        for (var i = 0; i < cart.length; i++) {
            if (cart[i].id === itemId && cart[i].variantId === variantId) {
                // Same variant - check extras match
                var exMatch = JSON.stringify(cart[i].extras) === JSON.stringify(extras);
                if (exMatch) { existing = i; break; }
            }
        }

        if (existing !== -1) {
            cart[existing].qty += 1;
        } else {
            cart.push({
                id: itemId,
                variantId: variantId,
                variantLabel: variantLabel,
                variantPrice: variantPrice,
                extras: extras,
                price: totalPrice,
                qty: 1,
                name: item.name,
                img: item.img
            });
        }

        saveCart();
        updateCartUI();
        showToast(CFG.translations.addedToCart, 'success');
        if (sheet) closeSheet(sheet);
    }

    function removeFromCart(index) {
        cart.splice(index, 1);
        saveCart();
        updateCartUI();
        if (!cart.length) closeSheet(document.getElementById('nb-cart-sheet'));
    }

    function updateQty(index, delta) {
        if (cart[index]) {
            cart[index].qty = Math.max(1, cart[index].qty + delta);
            if (cart[index].qty === 0) { cart.splice(index, 1); }
            saveCart();
            updateCartUI();
        }
    }

    function getCartTotal() {
        var total = 0;
        cart.forEach(function (c) { total += c.price * c.qty; });
        return total;
    }

    function updateCartUI() {
        var count = cart.reduce(function (s, c) { return s + c.qty; }, 0);
        var badge = $('#nb-cart-count');
        if (badge) { badge.textContent = count; badge.style.display = count ? '' : 'none'; }

        var cartSheet = document.getElementById('nb-cart-sheet');
        if (!cartSheet) return;

        var list = cartSheet.querySelector('.nb-cart-list');
        var empty = cartSheet.querySelector('.nb-cart-empty');
        var totalEl = cartSheet.querySelector('.nb-cart-total-price');
        var checkoutBtn = cartSheet.querySelector('.nb-checkout-btn');

        if (!cart.length) {
            if (list) list.style.display = 'none';
            if (empty) empty.style.display = '';
            if (totalEl) totalEl.textContent = money(0);
            if (checkoutBtn) checkoutBtn.disabled = true;
            return;
        }

        if (list) {
            list.style.display = '';
            var html = '';
            cart.forEach(function (c, i) {
                html += '<div class="nb-cart-item" data-index="' + i + '">';
                if (c.img && c.img.indexOf('default') === -1) {
                    html += '<img src="' + jwEsc(c.img) + '" alt="" class="nb-cart-item-img" data-csp-error="hide">';
                }
                html += '<div class="nb-cart-item-body">';
                html += '<div class="nb-cart-item-name">' + jwEsc(c.name) + '</div>';
                if (c.variantLabel) html += '<div class="nb-cart-item-variant">' + jwEsc(c.variantLabel) + '</div>';
                if (c.extras.length) {
                    html += '<div class="nb-cart-item-extras">';
                    c.extras.forEach(function (e) { html += '<span>+' + jwEsc(e.label) + '</span>'; });
                    html += '</div>';
                }
                html += '<div class="nb-cart-item-price">' + money(c.price) + '</div>';
                html += '</div>';
                html += '<div class="nb-cart-item-qty">';
                html += '<button type="button" class="nb-qty-btn nb-qty--minus" data-index="' + i + '">−</button>';
                html += '<span class="nb-qty-val">' + c.qty + '</span>';
                html += '<button type="button" class="nb-qty-btn nb-qty--plus" data-index="' + i + '">+</button>';
                html += '</div>';
                html += '<button type="button" class="nb-cart-item-remove" data-index="' + i + '" aria-label="' + String(CFG.translations.remove || 'Remove').replace(/"/g, '&quot;') + '"><i class="ico ico-trash" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12.5a1.5 1.5 0 0 0 1.5 1.5h7a1.5 1.5 0 0 0 1.5-1.5L18 7M9 7V4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V7"/></svg></i></button>';
                html += '</div>';
            });
            list.innerHTML = html;

            // Qty buttons
            list.querySelectorAll('.nb-qty--minus').forEach(function (btn) {
                btn.addEventListener('click', function () { updateQty(parseInt(this.dataset.index), -1); });
            });
            list.querySelectorAll('.nb-qty--plus').forEach(function (btn) {
                btn.addEventListener('click', function () { updateQty(parseInt(this.dataset.index), 1); });
            });
            list.querySelectorAll('.nb-cart-item-remove').forEach(function (btn) {
                btn.addEventListener('click', function () { removeFromCart(parseInt(this.dataset.index)); });
            });
        }

        if (empty) empty.style.display = 'none';
        if (totalEl) totalEl.textContent = money(getCartTotal());
        if (checkoutBtn) checkoutBtn.disabled = false;
    }

    /* ─────────────────────────── CART TOGGLE ───────────────────────────── */
    var cartToggle = $('#nb-cart-toggle');
    if (cartToggle) {
        cartToggle.addEventListener('click', function () {
            var sheet = document.getElementById('nb-cart-sheet');
            if (sheet) {
                updateCartUI();
                openSheet('nb-cart-sheet');
            }
        });
    }

    /* ─────────────────────────── FAVORITES ─────────────────────────────── */
    var favToggle = $('#nb-fav-toggle');
    if (favToggle) {
        favToggle.addEventListener('click', function () {
            this.classList.toggle('active');
            filterItems();
            updateFavCount();
        });
    }

    // Favorite hearts on cards
    document.addEventListener('click', function (e) {
        var heart = e.target.closest('.nb-fav-btn');
        if (heart) {
            e.stopPropagation();
            var id = Number(heart.getAttribute('data-id'));
            var idx = favorites.indexOf(id);
            if (idx === -1) { favorites.push(id); heart.classList.add('active'); }
            else { favorites.splice(idx, 1); heart.classList.remove('active'); }
            writeJSON(FAV_KEY, favorites);
            updateFavCount();
        }
    });

    function updateFavCount() {
        var count = favorites.length;
        var badge = $('#nb-fav-count');
        if (badge) { badge.textContent = count; badge.style.display = count ? '' : 'none'; }
        if (favToggle) {
            if (favorites.length) favToggle.classList.add('has-favs');
            else favToggle.classList.remove('has-favs');
        }
    }

    // Init favorite hearts on existing cards
    function initFavs() {
        $$('.nb-fav-btn').forEach(function (btn) {
            var id = Number(btn.getAttribute('data-id'));
            if (favorites.indexOf(id) !== -1) btn.classList.add('active');
        });
        updateFavCount();
    }

    /* ─────────────────────────── THEME TOGGLE ─────────────────────────── */
    var themeToggle = $('#nb-theme-toggle');
    var themeIcon = $('#nb-theme-icon');
    var nebulaEl = $('.nebula') || document.documentElement;

    if (themeToggle) {
        var storedTheme = localStorage.getItem('nb-theme-' + CFG.slug);
        var effective = storedTheme || (CFG.ownerTheme !== 'auto' ? CFG.ownerTheme : null);

        function applyTheme(t) {
            if (nebulaEl) {
                nebulaEl.setAttribute('data-theme', t);
                document.documentElement.setAttribute('data-theme', t);
            }
            if (themeIcon) {
                themeIcon.className = 'ico ico-' + (t === 'light' ? 'sun' : 'moon');
                themeIcon.innerHTML = t === 'light' ? "<svg width=\"1em\" height=\"1em\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.9\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\" focusable=\"false\"><path d=\"M8 12a4 4 0 1 0 8 0a4 4 0 1 0 -8 0\"/><path d=\"M3 12h1m8 -9v1m8 8h1m-9 8v1m-6.4 -15.4l.7 .7m12.1 -.7l-.7 .7m0 11.4l.7 .7m-12.1 -.7l-.7 .7\"/></svg>" : "<svg width=\"1em\" height=\"1em\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.9\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\" focusable=\"false\"><path d=\"M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446a9 9 0 1 1 -8.313 -12.454l0 .008\"/></svg>";
            }
        }

        if (effective) applyTheme(effective);
        else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) applyTheme('light');
        else applyTheme('dark');

        themeToggle.addEventListener('click', function () {
            var cur = nebulaEl.getAttribute('data-theme') || 'dark';
            var next = cur === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            localStorage.setItem('nb-theme-' + CFG.slug, next);
        });
    }

    /* ─────────────────────────── LANGUAGE DROPDOWN ─────────────────────── */
    var langToggle = $('#nb-lang-toggle');
    if (langToggle) {
        langToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var menu = document.getElementById('nb-lang-menu');
            if (menu) menu.classList.toggle('nb-dropdown--open');
        });
        document.addEventListener('click', function () {
            var menu = document.getElementById('nb-lang-menu');
            if (menu) menu.classList.remove('nb-dropdown--open');
        });
        var langBtns = document.querySelectorAll('#nb-lang-menu button');
        langBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var code = this.getAttribute('data-code');
                if (code) window.location.href = '?lang=' + code;
            });
        });
    }

    /* ─────────────────────────── CURRENCY ──────────────────────────────── */
    var curToggle = $('#nb-currency-toggle');
    if (curToggle && CFG.multiCurrency) {
        curToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var menu = document.getElementById('nb-currency-menu');
            if (menu) menu.classList.toggle('nb-dropdown--open');
        });
        document.addEventListener('click', function () {
            var menu = document.getElementById('nb-currency-menu');
            if (menu) menu.classList.remove('nb-dropdown--open');
        });
        document.querySelectorAll('#nb-currency-menu button[data-cur]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var code = btn.dataset.cur;
                var rate = parseFloat(btn.dataset.rate);
                sessionStorage.setItem('nb_currency', code);
                sessionStorage.setItem('nb_currency_rate', rate);
                document.getElementById('nb-cur-label').textContent = code;
                applyCurrency(code, rate);
            });
        });

        var activeCur = sessionStorage.getItem('nb_currency') || CFG.baseCurrency;
        var activeRate = parseFloat(sessionStorage.getItem('nb_currency_rate') || '1');
        if (activeCur !== CFG.baseCurrency) {
            applyCurrency(activeCur, activeRate);
            var cl = document.getElementById('nb-cur-label');
            if (cl) cl.textContent = activeCur;
        }
    }

    function applyCurrency(code, rate) {
        /* decimals follow the DISPLAYED currency: an OMR store shown in USD is 2dp,
           a USD store shown in KWD is 3dp. An unlisted code means 2 by definition. */
        var dp = rate === 1 ? CUR_DP : (CFG.decimalsByCode || {})[code];
        if (dp == null) dp = 2;
        document.querySelectorAll('[data-base-price]').forEach(function (el) {
            var base = parseFloat(el.dataset.basePrice);
            var sign = rate === 1 ? CFG.curSign : code + ' ';
            var pos = rate === 1 ? CFG.curPos : 'left';
            var n = (base * rate).toFixed(dp);
            el.textContent = pos === 'right' ? n + sign : sign + n;
        });
    }

    /* ─────────────────────────── BRANCH SELECTOR ───────────────────────── */
    var branchSelect = $('#nb-branch-select');
    var orderBranch = $('#nb-branch-ordering');
    if (branchSelect) {
        if (orderBranch) orderBranch.value = branchSelect.value;
        branchSelect.addEventListener('change', function () {
            if (orderBranch) orderBranch.value = this.value;
            var url = new URL(window.location.href);
            if (this.value) url.searchParams.set('branch', this.value);
            else url.searchParams.delete('branch');
            window.location.href = url.toString();
        });
    }

    /* ─────────────────────────── TOAST SYSTEM ──────────────────────────── */
    function showToast(msg, type) {
        var toast = document.getElementById('nb-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'nb-toast';
            toast.className = 'nb-toast';
            document.body.appendChild(toast);
        }
        toast.textContent = msg;
        toast.className = 'nb-toast nb-toast--visible';
        if (type === 'success') toast.style.color = 'var(--nb-accent)';
        else if (type === 'error') toast.style.color = 'var(--nb-hot)';
        clearTimeout(toast._timer);
        toast._timer = setTimeout(function () { toast.classList.remove('nb-toast--visible'); }, 2500);
    }

    /* ─────────────────────────── CHECKOUT ──────────────────────────────── */
    var checkoutBtn = $('#nb-checkout-btn');
    if (checkoutBtn) {
        checkoutBtn.addEventListener('click', function () {
            if (!cart.length) return;
            this.disabled = true;
            this.innerHTML = '<i class="ico ico-loader-2 ico-spin" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3a9 9 0 1 0 9 9"/></svg></i> ' + CFG.translations.processing;

            var items = cart.map(function (c) {
                return {
                    id: c.id,
                    qty: c.qty,
                    variant_id: c.variantId,
                    extras: c.extras.map(function (e) { return e.id; })
                };
            });

            var form = new FormData();
            form.append('items', JSON.stringify(items));
            var nbBid = new URLSearchParams(window.location.search).get('branch_id');
            if (nbBid) { form.append('branch_id', nbBid); }

            var orderType = $('input[name="nb-order-type"]:checked');
            var orderTypeVal = orderType ? orderType.value : 'on-table';
            form.append('order_type', orderTypeVal);

            var branchSelect = document.getElementById('nb-branch-ordering');
            if (branchSelect) form.append('branch', branchSelect.value);

            var tableEl = document.getElementById('nb-table-no');
            if (tableEl) form.append('table', tableEl.value);

            var couponInput = document.getElementById('nb-coupon');
            if (couponInput && couponInput.value) form.append('coupon_code', couponInput.value);

            var scheduleToggle = document.getElementById('nb-schedule-toggle');
            if (scheduleToggle && scheduleToggle.checked) {
                var dateEl = document.getElementById('nb-schedule-date');
                var timeEl = document.getElementById('nb-schedule-time');
                if (dateEl && dateEl.value) {
                    form.append('schedule_date', dateEl.value);
                    if (timeEl && timeEl.value) form.append('schedule_time', timeEl.value);
                }
            }

            // Special request
            var specialReq = document.getElementById('nb-special-req');
            if (specialReq) form.append('special_request', specialReq.value);

            var token = document.querySelector('meta[name="csrf-token"]');
            if (token) form.append('_token', token.getAttribute('content'));

            fetch(CFG.orderUrl, {
                method: 'POST',
                body: form,
                headers: { 'X-CSRF-TOKEN': token ? token.getAttribute('content') : '' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    // Clear cart
                    cart = [];
                    saveCart();
                    updateCartUI();
                    closeSheet(document.getElementById('nb-cart-sheet'));

                    // Show success
                    showToast(CFG.translations.orderSent, 'success');

                    // Handle redirects
                    if (data.redirect_url) {
                        window.location.href = data.redirect_url;
                    } else if (data.whatsapp_url) {
                        window.location.href = data.whatsapp_url;
                    }
                } else {
                    showToast(data.error || CFG.translations.orderFailed, 'error');
                    this.disabled = false;
                    this.innerHTML = '<i class="ico ico-check" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></i> ' + CFG.translations.placeOrder;
                }
            })
            .catch(function () {
                showToast(CFG.translations.orderFailed, 'error');
                this.disabled = false;
                this.innerHTML = '<i class="ico ico-check" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></i> ' + CFG.translations.placeOrder;
            }.bind(this));
        });
    }

    /* ─────────────────────────── CALL WAITER / REQUEST BILL ────────────── */
    function callAction(type) {
        var tableNo = CFG.tableNo;
        if (!tableNo) { showToast(CFG.translations.noTable, 'error'); return; }

        var btn = type === 'waiter' ? document.getElementById('nb-call-waiter-btn') : document.getElementById('nb-request-bill-btn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ico ico-loader-2 ico-spin" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3a9 9 0 1 0 9 9"/></svg></i>'; }

        var form = new FormData();
        form.append('table', tableNo);
        form.append('type', type === 'bill' ? 'request_bill' : 'call_waiter');
        var token = document.querySelector('meta[name="csrf-token"]');
        if (token) form.append('_token', token.getAttribute('content'));

        fetch('/' + CFG.slug + '/call-waiter', {
            method: 'POST',
            body: form
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                showToast(CFG.translations.sent, 'success');
            } else {
                showToast(data.message || CFG.translations.error, 'error');
            }
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ico ico-bell" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/></svg></i> ' + (type === 'waiter' ? CFG.translations.callWaiter : CFG.translations.requestBill); }
        })
        .catch(function () {
            showToast(CFG.translations.error, 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ico ico-bell" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/></svg></i> ' + (type === 'waiter' ? CFG.translations.callWaiter : CFG.translations.requestBill); }
        });
    }

    var waiterBtn = document.getElementById('nb-call-waiter-btn');
    if (waiterBtn) waiterBtn.addEventListener('click', function () { callAction('waiter'); });
    var billBtn = document.getElementById('nb-request-bill-btn');
    if (billBtn) billBtn.addEventListener('click', function () { callAction('bill'); });

    /* ─────────────────────────── SCROLL REVEAL ─────────────────────────── */
    var revealObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('nb-reveal--visible');
                revealObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

    $$('.nb-reveal').forEach(function (el) { revealObserver.observe(el); });

    // Also observe stagger containers
    $$('.nb-stagger').forEach(function (container) {
        var children = Array.prototype.slice.call(container.children);
        children.forEach(function (child, i) {
            child.style.transitionDelay = (i * 0.06) + 's';
            revealObserver.observe(child);
        });
    });

    /* ─────────────────────────── STARFIELD CANVAS ──────────────────────── */
    (function() {
        var canvas = document.getElementById('nb-stars');
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var stars = [];
        var W, H;

        function resize() {
            W = canvas.width = window.innerWidth;
            H = canvas.height = window.innerHeight;
        }
        resize();
        window.addEventListener('resize', resize, { passive: true });

        // Create stars
        var count = Math.min(120, Math.floor(W * H / 12000));
        for (var i = 0; i < count; i++) {
            stars.push({
                x: Math.random() * W,
                y: Math.random() * H,
                r: Math.random() * 1.5 + 0.3,
                a: Math.random(),
                da: (Math.random() - 0.5) * 0.008,
                s: Math.random() * 0.3 + 0.1
            });
        }

        function draw() {
            ctx.clearRect(0, 0, W, H);
            stars.forEach(function (s) {
                ctx.beginPath();
                ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(255,255,255,' + s.a + ')';
                ctx.fill();
                s.a += s.da;
                if (s.a > 1) { s.a = 1; s.da = -s.da; }
                if (s.a < 0.1) { s.a = 0.1; s.da = -s.da; }
                s.y -= s.s;
                if (s.y < -10) { s.y = H + 10; s.x = Math.random() * W; }
            });
            requestAnimationFrame(draw);
        }
        draw();
    })();

    /* ─────────────────────────── INIT ──────────────────────────────────── */
    initFavs();
    updateCartUI();

    // Resume banner
    if (showResume) {
        var banner = document.getElementById('nb-resume-banner');
        if (banner) {
            banner.style.display = '';
            var txt = banner.querySelector('.nb-resume-text');
            if (txt) txt.textContent = CFG.translations.resumeText.replace('{n}', cart.length);
            var restoreBtn = banner.querySelector('.nb-resume-restore');
            if (restoreBtn) {
                restoreBtn.addEventListener('click', function () {
                    banner.style.display = 'none';
                    document.getElementById('nb-cart-toggle').click();
                });
            }
            var dismissBtn = banner.querySelector('.nb-resume-dismiss');
            if (dismissBtn) {
                dismissBtn.addEventListener('click', function () {
                    banner.style.display = 'none';
                    cart = [];
                    saveCart();
                    updateCartUI();
                });
            }
        }
    }

    // Init price markers
    $$('[data-base-price]').forEach(function (el) {
        var txt = el.textContent.replace(/[^0-9.]/g, '');
        if (txt) el.dataset.basePrice = txt;
    });

    // Apply initial currency if set
    var initCur = sessionStorage.getItem('nb_currency');
    var initRate = sessionStorage.getItem('nb_currency_rate');
    if (initCur && initRate && initCur !== CFG.baseCurrency) {
        applyCurrency(initCur, parseFloat(initRate));
    }

    // Show / hide login modal
    var loginToggle = document.getElementById('nb-login-toggle');
    var loginSheet = document.getElementById('nb-login-sheet');
    if (loginToggle && loginSheet) {
        loginToggle.addEventListener('click', function () { openSheet('nb-login-sheet'); });
    }

    // Show / hide account menu
    var accMenu = document.getElementById('nb-account-menu');
    if (accMenu) {
        var accBtn = accMenu.querySelector('button');
        if (accBtn) {
            accBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var menu = accMenu.querySelector('.nb-dropdown-menu');
                if (menu) menu.classList.toggle('nb-dropdown--open');
            });
        }
        document.addEventListener('click', function () {
            var menu = accMenu.querySelector('.nb-dropdown-menu');
            if (menu) menu.classList.remove('nb-dropdown--open');
        });
    }

    // Logout
    var logoutBtn = document.getElementById('nb-logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function () {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = CFG.logoutUrl;
            var token = document.querySelector('meta[name="csrf-token"]');
            if (token) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = '_token';
                input.value = token.getAttribute('content');
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        });
    }

    // Schedule toggle
    var schedToggle = document.getElementById('nb-schedule-toggle');
    var schedFields = document.getElementById('nb-schedule-fields');
    if (schedToggle && schedFields) {
        schedToggle.addEventListener('change', function () {
            schedFields.style.display = this.checked ? '' : 'none';
        });
    }

})();
