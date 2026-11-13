// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* =========================================================================
   AURORA — premium dining template behaviour
   Vanilla JS (jQuery is available but not required).
   Handles: sticky chrome, search, category filtering, favorites, dish sheet
   with variants/extras, cart, and checkout wired to StoreController@submitOrder.
   ========================================================================= */
(function () {
    'use strict';

    var CFG = window.AURORA || {};
    var ITEMS = CFG.items || {};
    var CART_KEY = 'aurora_cart_' + (CFG.restaurantSlug || 'default');
    var FAV_KEY = 'aurora_favorites_' + (CFG.restaurantSlug || 'default');
    var REORDER_PENDING_KEY = 'aurora_reorder_pending_' + (CFG.restaurantSlug || 'default');
    var REORDER_NOTICE_KEY = 'aurora_reorder_notice_' + (CFG.restaurantSlug || 'default');

    var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

    /* ------------------------------------------------------------- money */
    /* Mirrors price_format() server-side: decimals follow the store currency,
       and whole numbers keep them ("9.00", not "9") so the server-rendered
       card and this JS-rendered cart agree on the same item. */
    function money(amount) {
        var dp = CFG.currencyDecimals;
        if (dp == null) dp = 2;
        var str = (Number(amount) || 0).toFixed(dp);
        return (String(CFG.currencyPos) === '1') ? (CFG.currencySign + (CFG.currencyGap || '') + str) : (str + (CFG.currencyGap || '') + CFG.currencySign);
    }

    /* Round to the STORE's currency decimals, the way price_format() and
       Order::computeTax() do. `Math.round(x*100)/100` is only correct for a
       2-decimal currency; a 3-decimal store loses its fils and a 0-decimal one
       gains cents that the server never charges. */
    /* M16: delegates to the shared money core so this theme rounds exactly like PHP's round(). */
    function roundMoney(x) {
        // bug batch 10 · 4: the stored precision (CFG.moneyDecimals) — display keeps currencyDecimals
        return mbMoney.round(x, CFG.moneyDecimals != null ? CFG.moneyDecimals : (CFG.currencyDecimals == null ? 2 : CFG.currencyDecimals));
    }

    /* TOTALS CONTRACT — must equal Order::computeTax($subtotal, $postOptions):
       tax is taken on the RAW subtotal (never the discounted one), honours the
       'fixed' tax type as a flat amount rather than a rate, and is zero when
       either the charge or the subtotal is non-positive. */
    function computeTax(subtotal) {
        var charge = Number(CFG.taxCharge) || 0;
        if (charge <= 0 || subtotal <= 0) return 0;
        if (String(CFG.taxType) === 'fixed') return roundMoney(charge);
        return roundMoney(subtotal * (charge / 100));
    }

    /* ------------------------------------------------- storage helpers */
    function readJSON(key, fallback) {
        try { return JSON.parse(localStorage.getItem(key)) || fallback; }
        catch (e) { return fallback; }
    }
    function writeJSON(key, val) { localStorage.setItem(key, JSON.stringify(val)); }
    function writeCart(items) {
        writeJSON(CART_KEY, {
            items: items,
            timestamp: Date.now()
        });
    }

    var rawCart = readJSON(CART_KEY, null);
    var cart = [];
    var showResumePrompt = false;
    var reorderPending = false;
    var reorderNotice = '';

    try {
        reorderPending = sessionStorage.getItem(REORDER_PENDING_KEY) === '1';
        reorderNotice = sessionStorage.getItem(REORDER_NOTICE_KEY) || '';
    } catch (e) {
        reorderPending = false;
        reorderNotice = '';
    }

    function sendItemEvent(menuId, type) {
        var url = '/' + CFG.restaurantSlug + '/item-event';
        var data = new FormData();
        data.append('menu_id', menuId);
        data.append('type', type);
        var csrfTokenEl = document.querySelector('meta[name="csrf-token"]');
        if (csrfTokenEl) {
            data.append('_token', csrfTokenEl.getAttribute('content'));
        }
        if (navigator.sendBeacon) {
            navigator.sendBeacon(url, data);
        } else {
            fetch(url, { method: 'POST', body: data });
        }
    }

    if (rawCart) {
        if (rawCart.timestamp && rawCart.items) {
            if (Date.now() - rawCart.timestamp > 86400000) {
                localStorage.removeItem(CART_KEY);
            } else if (rawCart.items.length > 0) {
                // Filter out items that no longer exist or are sold out
                var validItems = rawCart.items.filter(function (item) {
                    return ITEMS[item.id] && !ITEMS[item.id].is_sold_out;
                });
                
                if (validItems.length > 0) {
                    cart = validItems;
                    showResumePrompt = true;
                    // Auto-save the sanitized cart
                    if (validItems.length !== rawCart.items.length) {
                        writeCart(cart);
                    }
                } else {
                    localStorage.removeItem(CART_KEY);
                }
            }
        } else if (Array.isArray(rawCart) && rawCart.length > 0) {
            var validItems = rawCart.filter(function (item) {
                return ITEMS[item.id] && !ITEMS[item.id].is_sold_out;
            });
            if (validItems.length > 0) {
                cart = validItems;
                showResumePrompt = true;
                if (validItems.length !== rawCart.length) {
                    writeCart(cart);
                }
            } else {
                localStorage.removeItem(CART_KEY);
            }
        }
    }

    var favorites = readJSON(FAV_KEY, []);
    var appliedCoupon = null;

    /* =====================================================================
       STICKY CHROME
       ===================================================================== */
    var topbar = $('#au-topbar');
    var catbar = $('#au-catbar');
    function onScroll() {
        var y = window.pageYOffset;
        if (topbar) topbar.classList.toggle('is-stuck', y > 12);
        if (catbar) catbar.classList.toggle('is-stuck', y > 220);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    /* =====================================================================
       FILTERING (search + category + favorites)
       ===================================================================== */
    var activeCat = 'all';
    var activeSub = 'root'; /* two-tier nav: 'root' = whole parent, or a subcategory id */
    var favMode = false;
    var searchTerm = '';
    var emptyEl = $('#au-empty');

    function isFav(id) { return favorites.indexOf(Number(id)) !== -1; }

    function applyFilters() {
        var anyVisible = false;
        
        // Gather filter states
        var filterVegan = !!$('.au-filter-chip[data-filter="vegan"].active');
        var filterHalal = !!$('.au-filter-chip[data-filter="halal"].active');
        var filterGf = !!$('.au-filter-chip[data-filter="gf"].active');
        var filterPopular = !!$('.au-filter-chip[data-filter="popular"].active');
        
        // Excluded allergens
        var excludedAllergens = $$('.allergen-exclude-chip.active').map(function (chip) {
            return chip.getAttribute('data-allergen-id');
        });
        
        // Active spice levels
        var activeSpices = $$('.au-filter-chip[data-filter^="spice-"].active').map(function (chip) {
            return chip.getAttribute('data-filter').replace('spice-', '');
        });

        $$('.au-cat-section').forEach(function (section) {
            var catId = section.getAttribute('data-cat');
            var sectionVisible = false;
            $$('.au-card', section).forEach(function (card) {
                var id = card.getAttribute('data-id');
                var name = card.getAttribute('data-name') || '';
                var desc = card.getAttribute('data-desc') || '';
                var matchCat = (activeCat === 'all') || ('cat-' + catId === activeCat);
                /* Two-tier sub-chip narrowing: 'root' keeps the whole parent; a
                   specific sub id keeps only cards whose OWN category_id matches. */
                var matchSub = (activeSub === 'root') || (card.getAttribute('data-cat') === activeSub);
                var matchSearch = !searchTerm || name.indexOf(searchTerm) !== -1 || desc.indexOf(searchTerm) !== -1;
                var matchFav = !favMode || isFav(id);
                
                // Dietary matches
                var matchVegan = !filterVegan || card.getAttribute('data-vegan') === '1';
                var matchHalal = !filterHalal || card.getAttribute('data-halal') === '1';
                var matchGf = !filterGf || card.getAttribute('data-gf') === '1';
                var matchPopular = !filterPopular || card.getAttribute('data-popular') === '1';
                
                // Allergen exclusion match
                var cardAllergensStr = card.getAttribute('data-allergens') || '';
                var cardAllergens = cardAllergensStr ? cardAllergensStr.split(',') : [];
                var matchAllergen = !excludedAllergens.some(function (exId) {
                    return cardAllergens.indexOf(exId) !== -1;
                });
                
                // Spice level match
                var cardSpice = card.getAttribute('data-spice') || '0';
                var matchSpice = activeSpices.length === 0 || activeSpices.indexOf(cardSpice) !== -1;
                
                var show = matchCat && matchSub && matchSearch && matchFav && matchVegan && matchHalal && matchGf && matchPopular && matchAllergen && matchSpice;
                card.style.display = show ? '' : 'none';
                if (show) { sectionVisible = true; anyVisible = true; }
            });
            /* hide sub-grids/headers that end up empty */
            section.style.display = sectionVisible ? '' : 'none';
            $$('.au-grid', section).forEach(function (grid) {
                var vis = $$('.au-card', grid).some(function (c) { return c.style.display !== 'none'; });
                grid.style.display = vis ? '' : 'none';
            });
            /* drop a whole subcategory wrapper (its label + grid) when it has no
               visible cards — keeps sub-headings from stranding under narrowing/search */
            $$('.au-subwrap', section).forEach(function (wrap) {
                var vis = $$('.au-card', wrap).some(function (c) { return c.style.display !== 'none'; });
                wrap.style.display = vis ? '' : 'none';
            });
        });
        /* specials rail only on the default unfiltered view */
        var specials = $('#au-specials');
        var hasActiveFilters = filterVegan || filterHalal || filterGf || filterPopular || excludedAllergens.length > 0 || activeSpices.length > 0;
        if (specials) specials.style.display = (activeCat === 'all' && !searchTerm && !favMode && !hasActiveFilters) ? '' : 'none';
        if (emptyEl) emptyEl.classList.toggle('show', !anyVisible);
    }

    /* search */
    var searchInput = $('#au-search-input');
    var searchClear = $('#au-search-clear');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            searchTerm = this.value.trim().toLowerCase();
            if (searchClear) searchClear.classList.toggle('show', !!searchTerm);
            applyFilters();
        });
    }
    if (searchClear) {
        searchClear.addEventListener('click', function () {
            searchInput.value = ''; searchTerm = ''; this.classList.remove('show');
            applyFilters(); searchInput.focus();
        });
    }

    /* category chips */
    var subchipsWrap = $('#au-subchips');

    /* Reveal the active parent's subcategory chip row (if any), reset its selection
       to "All", and hide every other group. Called on each parent change. */
    function syncSubChips() {
        if (!subchipsWrap) return;
        var parentId = (activeCat !== 'all') ? activeCat.replace('cat-', '') : null;
        var shown = null;
        $$('.au-subgroup', subchipsWrap).forEach(function (g) {
            var match = parentId !== null && g.getAttribute('data-parent') === parentId;
            g.hidden = !match;
            if (match) {
                shown = g;
                $$('.au-subchip', g).forEach(function (c) {
                    c.classList.toggle('active', c.getAttribute('data-sub') === 'root');
                });
            }
        });
        subchipsWrap.hidden = !shown;
    }

    $$('.au-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            $$('.au-chip').forEach(function (c) { c.classList.remove('active'); });
            chip.classList.add('active');
            activeCat = chip.getAttribute('data-target');
            activeSub = 'root';
            syncSubChips();
            favMode = false;
            updateFavToggle();
            applyFilters();
            var menuRoot = $('#au-menu-root');
            if (menuRoot && activeCat !== 'all') {
                window.scrollTo({ top: menuRoot.offsetTop - 130, behavior: 'smooth' });
            }
        });
    });

    /* secondary sub-category chips — narrow within the active parent */
    $$('.au-subchip').forEach(function (sc) {
        sc.addEventListener('click', function () {
            activeSub = sc.getAttribute('data-sub');
            var grp = sc.closest('.au-subgroup');
            if (grp) $$('.au-subchip', grp).forEach(function (c) { c.classList.toggle('active', c === sc); });
            applyFilters();
        });
    });

    /* favorites filter toggle (topbar heart) */
    var favToggle = $('#au-fav-toggle');
    function updateFavToggle() {
        if (favToggle) favToggle.style.color = favMode ? 'var(--au-nonveg)' : '';
    }
    if (favToggle) {
        favToggle.addEventListener('click', function () {
            favMode = !favMode;
            if (favMode) {
                activeCat = 'all';
                activeSub = 'root';
                $$('.au-chip').forEach(function (c) { c.classList.toggle('active', c.getAttribute('data-target') === 'all'); });
                syncSubChips();
            }
            updateFavToggle();
            applyFilters();
            window.scrollTo({ top: ($('#au-menu-root').offsetTop - 130), behavior: 'smooth' });
        });
    }

    /* favorite buttons on cards */
    function refreshFavButtons() {
        $$('.au-fav').forEach(function (btn) {
            btn.classList.toggle('is-fav', isFav(btn.getAttribute('data-fav')));
        });
        var count = favorites.length;
        var badge = $('#au-fav-count');
        if (badge) { badge.textContent = count; badge.classList.toggle('show', count > 0); }
    }
    document.addEventListener('click', function (e) {
        var favBtn = e.target.closest && e.target.closest('.au-fav');
        if (!favBtn) return;
        e.preventDefault(); e.stopPropagation();
        var id = Number(favBtn.getAttribute('data-fav'));
        var idx = favorites.indexOf(id);
        if (idx === -1) { favorites.push(id); } else { favorites.splice(idx, 1); }
        writeJSON(FAV_KEY, favorites);
        refreshFavButtons();
        if (favMode) applyFilters();
    });
    refreshFavButtons();

    /* =====================================================================
       SHEETS (open / close)
       ===================================================================== */
    function openSheet(name) {
        var sheet = $('#au-' + name + '-sheet');
        var scrim = $('#au-' + name + '-scrim');
        if (sheet) { sheet.classList.add('open'); sheet.setAttribute('aria-hidden', 'false'); }
        if (scrim) scrim.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeSheet(name) {
        var sheet = $('#au-' + name + '-sheet');
        var scrim = $('#au-' + name + '-scrim');
        if (sheet) { sheet.classList.remove('open'); sheet.setAttribute('aria-hidden', 'true'); }
        if (scrim) scrim.classList.remove('open');
        document.body.style.overflow = '';
    }
    $$('[data-close-sheet]').forEach(function (b) {
        b.addEventListener('click', function () { closeSheet(b.getAttribute('data-close-sheet')); });
    });
    ['item', 'cart', 'auth', 'following', 'about'].forEach(function (n) {
        var scrim = $('#au-' + n + '-scrim');
        if (scrim) scrim.addEventListener('click', function () { closeSheet(n); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeSheet('item'); closeSheet('cart'); closeSheet('auth'); closeSheet('following'); closeSheet('about'); }
    });

    /* =====================================================================
       DISH DETAIL SHEET (variants + extras + qty)
       ===================================================================== */
    var current = null; /* { item, variantId, extras:Set, qty } */

    function dietBadge(type) {
        return type === 'nonveg'
            ? '<span class="au-badge diet-nonveg"><i class="ico ico-circle-filled" style="font-size:8px" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false"><path d="M7 3.34a10 10 0 1 1 -4.995 8.984l-.005 -.324l.005 -.324a10 10 0 0 1 4.995 -8.336z"/></svg></i>' + label('Non Veg') + '</span>'
            : '<span class="au-badge diet-veg"><i class="ico ico-circle-filled" style="font-size:8px" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false"><path d="M7 3.34a10 10 0 1 1 -4.995 8.984l-.005 -.324l.005 -.324a10 10 0 0 1 4.995 -8.336z"/></svg></i>' + label('Veg') + '</span>';
    }
    /* small built-in fallbacks for badge words (translated via DOM where present) */
    function label(en) { return en; }

    function unitPrice() {
        if (!current) return 0;
        var base = current.item.price;
        if (current.variantId) {
            var v = current.item.variants.find(function (x) { return x.id === current.variantId; });
            if (v) base = v.price;
        }
        var extrasSum = 0;
        current.item.extras.forEach(function (ex) {
            if (current.extras.has(ex.id)) extrasSum += ex.price;
        });
        (current.item.optionGroups || []).forEach(function (g) {
            var sel = current.groups[g.id];
            if (sel) g.options.forEach(function (o) { if (sel.has(o.i)) extrasSum += o.price; });
        });
        return base + extrasSum;
    }

    function openItem(id) {
        var item = ITEMS[id];
        if (!item) return;
        current = { item: item, variantId: (item.variants[0] ? item.variants[0].id : 0), extras: new Set(), qty: 1, groups: {} };
        (item.optionGroups || []).forEach(function (g) {
            current.groups[g.id] = new Set();
            // Pre-select the first option for required single-choice groups.
            if (g.max === 1 && g.required && g.options.length) current.groups[g.id].add(g.options[0].i);
        });
        sendItemEvent(id, 'view');

        var allergiesHtml = '';
        if (item.allergies && item.allergies.length) {
            allergiesHtml = '<div class="au-detail-section"><h4>' + CFG.i18n.add + '</h4></div>';
            allergiesHtml = '<div class="au-card-allergies" style="margin-top:14px">' +
                item.allergies.map(function (a) {
                    return '<img src="' + a.img + '" title="' + esc(a.title) + '" alt="' + esc(a.title) + '">';
                }).join('') + '</div>';
        }

        // #8: gallery image strip
        var galleryHtml = '';
        if (item.gallery && item.gallery.length > 0) {
            galleryHtml = '<div style="display:flex;gap:6px;overflow-x:auto;padding:0 0 6px;scrollbar-width:none;">' +
                '<img src="' + item.img + '" alt="" data-gal-main data-csp-gallery-main=".au-detail-media img" style="height:56px;width:56px;object-fit:cover;border-radius:8px;cursor:pointer;border:2px solid var(--au-fx);flex-shrink:0;">' +
                item.gallery.map(function (g) {
                    return '<img src="' + g + '" alt="" data-gal-thumb data-csp-gallery-main=".au-detail-media img" style="height:56px;width:56px;object-fit:cover;border-radius:8px;cursor:pointer;border:2px solid transparent;flex-shrink:0;">';
                }).join('') + '</div>';
        }

        var auImages = [item.img].concat(item.gallery || []);
        var auMedia = (window.mbCarousel)
            ? mbCarousel.html(auImages)
            : '<img src="' + item.img + '" alt="' + esc(item.name) + '">';
        var html = '' +
            '<div class="au-detail-media">' + auMedia + '</div>' +
            (window.mbCarousel ? '' : galleryHtml) +
            '<h2 class="au-detail-title">' + esc(item.name) + '</h2>' +
            (item.rating ? '<div class="au-card-rating"><i class="ico ico-star-filled" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false"><path d="M8.243 7.34l-6.38 .925l-.113 .023a1 1 0 0 0 -.44 1.684l4.622 4.499l-1.09 6.355l-.013 .11a1 1 0 0 0 1.464 .944l5.706 -3l5.693 3l.1 .046a1 1 0 0 0 1.352 -1.1l-1.091 -6.355l4.624 -4.5l.078 -.085a1 1 0 0 0 -.633 -1.62l-6.38 -.926l-2.852 -5.78a1 1 0 0 0 -1.794 0l-2.853 5.78z"/></svg></i> ' + item.rating + '</div>' : '') +
            '<div class="au-detail-badges">' + dietBadge(item.type) + '</div>' +
            (item.desc ? '<p class="au-detail-desc">' + esc(item.desc) + '</p>' : '') +
            allergiesHtml;

        /* variants */
        if (item.variants.length) {
            html += '<div class="au-detail-section"><h4>' + CFG.i18n.choose + '</h4>';
            item.variants.forEach(function (v, i) {
                html += '<div class="au-opt' + (i === 0 ? ' selected' : '') + '" data-variant="' + v.id + '">' +
                    '<span class="au-opt-label"><span class="au-opt-mark"></span>' + esc(v.label) + '</span>' +
                    '<span class="au-opt-price">' + money(v.price) + '</span></div>';
            });
            html += '</div>';
        }
        /* extras */
        if (item.extras.length) {
            html += '<div class="au-detail-section"><h4>' + CFG.i18n.extras + '</h4>';
            item.extras.forEach(function (ex) {
                html += '<div class="au-opt" data-extra="' + ex.id + '">' +
                    '<span class="au-opt-label"><span class="au-opt-mark check"></span>' + esc(ex.title) + '</span>' +
                    (ex.img ? '<img class="au-opt-thumb" src="' + esc(ex.img) + '" alt="" loading="lazy" data-csp-error="opacity">' : '') +
                    '<span class="au-opt-price">+ ' + money(ex.price) + '</span></div>';
            });
            html += '</div>';
        }
        /* global option groups */
        (item.optionGroups || []).forEach(function (g) {
            var rule = g.max === 1 ? '' : (g.min > 0 ? ' (' + (CFG.i18n.choose || 'choose') + ' ' + g.min + (g.max ? '-' + g.max : '+') + ')' : (g.max ? ' (max ' + g.max + ')' : ''));
            html += '<div class="au-detail-section"><h4>' + esc(g.title) + (g.required ? ' *' : '') + '<span style="font-weight:400;color:var(--au-muted,#888);font-size:12px">' + rule + '</span></h4>';
            g.options.forEach(function (o) {
                var sel = current.groups[g.id] && current.groups[g.id].has(o.i);
                html += '<div class="au-opt' + (sel ? ' selected' : '') + '" data-group="' + g.id + '" data-opt="' + o.i + '" data-single="' + (g.max === 1 ? 1 : 0) + '" data-gmax="' + (g.max == null ? 0 : g.max) + '">' +
                    '<span class="au-opt-label"><span class="au-opt-mark' + (g.max === 1 ? '' : ' check') + '"></span>' + esc(o.label) + '</span>' +
                    '<span class="au-opt-price">' + (o.price > 0 ? '+ ' + money(o.price) : '') + '</span></div>';
            });
            html += '</div>';
        });
        /* order note */
        html += '<div class="au-detail-section"><h4>' + CFG.i18n.note + '</h4>' +
            '<textarea id="au-item-note" class="au-input" placeholder="' + CFG.i18n.note_placeholder + '" style="margin-top:8px;resize:vertical;min-height:60px;width:100%;border:1px solid var(--au-border);border-radius:12px;padding:12px;font-family:inherit;background:var(--au-card);color:var(--au-text)"></textarea></div>';
        /* inline quick-rate — the stars OPEN the shared review sheet preselected
           to that rating, so the rating and any written text go through ONE
           endpoint. This replaced the old stars-only POST straight to
           CFG.reviewUrl, which was a second, text-less path producing its own
           pending review. The shared sheet is defined by _partials/item-reviews. */
        if (EP.reviewsBase) {
            html += '<div class="au-detail-section au-rate-row" id="au-rate-row">' +
            '<span class="au-rate-label">' + esc(CFG.i18n.rate_this_item || 'Rate this dish') + '</span>' +
            '<span class="au-rate-stars" id="au-rateStars">' +
            [1, 2, 3, 4, 5].map(function (n) {
                return '<button type="button" data-rate="' + n + '" aria-label="' + n + '"><i class="ico ico-star-filled" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false"><path d="M8.243 7.34l-6.38 .925l-.113 .023a1 1 0 0 0 -.44 1.684l4.622 4.499l-1.09 6.355l-.013 .11a1 1 0 0 0 1.464 .944l5.706 -3l5.693 3l.1 .046a1 1 0 0 0 1.352 -1.1l-1.091 -6.355l4.624 -4.5l.078 -.085a1 1 0 0 0 -.633 -1.62l-6.38 -.926l-2.852 -5.78a1 1 0 0 0 -1.794 0l-2.853 5.78z"/></svg></i></button>';
            }).join('') +
            '</span>' +
            '<span class="au-rate-thanks" id="au-rateThanks" hidden>' + esc(CFG.i18n.thanks || 'Thanks!') + '</span>' +
            '</div>';
        }

        $('#au-item-body').innerHTML = html;

        /* foot (add to cart) only when ordering is allowed */
        var foot = $('#au-item-foot');
        if (CFG.allowOrder && foot) {
            /* shown once the cart already has items: a direct jump to the cart
               without having to close this sheet and hunt for the bag icon. */
            var jumpHidden = (cartCount() + comboCount()) === 0 ? ' hidden' : '';
            foot.innerHTML =
                '<div style="display:flex;align-items:center;gap:12px">' +
                '<div class="au-qty" id="au-detail-qty">' +
                '<button type="button" data-q="-1">&minus;</button><span>1</span><button type="button" data-q="1">+</button>' +
                '</div>' +
                '<button class="au-cta" id="au-add-to-cart" style="flex:1"><i class="ico ico-plus" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg></i> ' +
                CFG.i18n.add + '<span class="au-cta-price" id="au-detail-total"></span></button>' +
                '<button class="au-icon-btn au-cart-jump" id="au-cart-jump" type="button"' + jumpHidden + ' aria-label="' + esc(CFG.i18n.view_cart) + '">' +
                '<i class="ico ico-shopping-bag" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6.331 8h11.339a2 2 0 0 1 1.977 2.304l-1.255 8.152a3 3 0 0 1 -2.966 2.544h-6.852a3 3 0 0 1 -2.965 -2.544l-1.255 -8.152a2 2 0 0 1 1.977 -2.304"/><path d="M9 11v-5a3 3 0 0 1 6 0v5"/></svg></i><span class="au-badge-count show" id="au-cart-jump-count">' + (cartCount() + comboCount()) + '</span></button>' +
                '</div>';
            bindItemControls();
            refreshItemTotal();
        }

        $('#au-item-body').scrollTop = 0;
        openSheet('item');
    }

    function bindItemControls() {
        $$('[data-variant]', $('#au-item-body')).forEach(function (el) {
            el.addEventListener('click', function () {
                $$('[data-variant]').forEach(function (x) { x.classList.remove('selected'); });
                el.classList.add('selected');
                current.variantId = Number(el.getAttribute('data-variant'));
                refreshItemTotal();
            });
        });
        $$('[data-extra]', $('#au-item-body')).forEach(function (el) {
            el.addEventListener('click', function () {
                var id = Number(el.getAttribute('data-extra'));
                if (current.extras.has(id)) { current.extras.delete(id); el.classList.remove('selected'); }
                else { current.extras.add(id); el.classList.add('selected'); }
                refreshItemTotal();
            });
        });
        $$('[data-group]', $('#au-item-body')).forEach(function (el) {
            el.addEventListener('click', function () {
                var gid = Number(el.getAttribute('data-group'));
                var oi = Number(el.getAttribute('data-opt'));
                var single = el.getAttribute('data-single') === '1';
                var gmax = Number(el.getAttribute('data-gmax')) || 0;
                var set = current.groups[gid] || (current.groups[gid] = new Set());
                if (single) {
                    set.clear(); set.add(oi);
                    $$('[data-group="' + gid + '"]', $('#au-item-body')).forEach(function (x) { x.classList.remove('selected'); });
                    el.classList.add('selected');
                } else if (set.has(oi)) {
                    set.delete(oi); el.classList.remove('selected');
                } else {
                    if (gmax > 0 && set.size >= gmax) return; // respect max
                    set.add(oi); el.classList.add('selected');
                }
                refreshItemTotal();
            });
        });
        var qtyBox = $('#au-detail-qty');
        if (qtyBox) {
            $$('button', qtyBox).forEach(function (b) {
                b.addEventListener('click', function () {
                    current.qty = Math.max(1, current.qty + Number(b.getAttribute('data-q')));
                    $('span', qtyBox).textContent = current.qty;
                    refreshItemTotal();
                });
            });
        }
        var addBtn = $('#au-add-to-cart');
        if (addBtn) addBtn.addEventListener('click', addCurrentToCart);
        var jumpBtn = $('#au-cart-jump');
        if (jumpBtn) jumpBtn.addEventListener('click', function () { closeSheet('item'); renderCart(); openSheet('cart'); });
    }
    function refreshItemTotal() {
        var t = $('#au-detail-total');
        if (t) t.textContent = money(unitPrice() * current.qty);
    }

    function addCurrentToCart() {
        var item = current.item;
        sendItemEvent(item.id, 'add_to_cart');
        var variant = item.variants.find(function (v) { return v.id === current.variantId; });
        var extras = item.extras.filter(function (ex) { return current.extras.has(ex.id); })
            .map(function (ex) { return { id: ex.id, title: ex.title, price: ex.price }; });
        // Collect + validate option-group selections.
        var mods = [];
        var ruleErr = null;
        (item.optionGroups || []).forEach(function (g) {
            var set = current.groups[g.id] || new Set();
            var need = g.required ? Math.max(1, g.min || 0) : (g.min || 0);
            if (set.size < need) ruleErr = ruleErr || g.title;
            g.options.forEach(function (o) {
                if (set.has(o.i)) mods.push({ groupId: g.id, optIndex: o.i, groupTitle: g.title, label: o.label, price: o.price });
            });
        });
        if (ruleErr) { alert((CFG.i18n.choose || 'Please choose') + ': ' + ruleErr); return; }
        var noteInput = $('#au-item-note');
        var note = noteInput ? noteInput.value.trim() : '';
        var modKey = mods.map(function (m) { return m.groupId + ':' + m.optIndex; }).sort().join(',');
        var key = item.id + '|' + (current.variantId || 0) + '|' + extras.map(function (e) { return e.id; }).sort().join(',') + '|' + modKey + '|' + encodeURIComponent(note);
        var existing = cart.find(function (c) { return c.key === key; });
        if (existing) {
            existing.qty += current.qty;
        } else {
            cart.push({
                key: key,
                id: item.id,
                name: item.name,
                img: item.img,
                unitBase: variant ? variant.price : item.price,
                variantId: current.variantId || 0,
                variantLabel: variant ? variant.label : '',
                extras: extras,
                mods: mods,
                qty: current.qty,
                order_note: note
            });
        }
        writeCart(cart);
        renderCart();
        closeSheet('item');
        pulseCart();
    }

    /* open item triggers (cards, featured, specials) */
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest && e.target.closest('[data-open-item]');
        if (!trigger) return;
        if (e.target.closest('.au-fav')) return;
        openItem(trigger.getAttribute('data-open-item'));
    });

    /* inline quick-rate: tapping a star lights it and opens the shared review
       sheet preselected to that rating (one endpoint for stars + optional text). */
    document.addEventListener('click', function (e) {
        var rate = e.target.closest && e.target.closest('#au-rateStars [data-rate]');
        if (!rate) return;
        var n = Number(rate.getAttribute('data-rate'));
        $$('#au-rateStars [data-rate]').forEach(function (b, i) { b.classList.toggle('is-on', i < n); });
        if (window.mbItemReviews) window.mbItemReviews.open(n);
    });

    /* =====================================================================
       CART
       ===================================================================== */
    function cartCount() { return cart.reduce(function (n, c) { return n + c.qty; }, 0); }
    /* I5-1: pick the CLDR plural form for a count. Uses Intl.PluralRules where
       available (maps its category to Laravel's pipe order), else a 2-form
       fallback. `forms` is CFG.i18n.itemForms; each form may contain :count. */
    function mbPlural(forms, n) {
        if (!forms || !forms.length) return n + '';
        var order, cat;
        try {
            cat = new Intl.PluralRules(CFG.i18n.pluralLocale || 'en').select(n);
        } catch (e) { cat = (n === 1 ? 'one' : 'other'); }
        // Laravel MessageSelector pipe order per plural-form count.
        if (forms.length >= 6)      order = { zero: 0, one: 1, two: 2, few: 3, many: 4, other: 5 };
        else if (forms.length === 3) order = { one: 0, few: 1, many: 2, other: 2, two: 1, zero: 0 };
        else                         order = { one: 0, other: 1, zero: 1, two: 1, few: 1, many: 1 };
        var i = order[cat];
        if (i == null || i >= forms.length) i = forms.length - 1;
        return String(forms[i]).replace(/:count/g, n);
    }
    function lineUnit(c) {
        var s = c.unitBase;
        c.extras.forEach(function (e) { s += e.price; });
        (c.mods || []).forEach(function (m) { s += m.price; });
        return s;
    }
    /* Combos ride in the subtotal: the server taxes them, min-order counts them,
       and the coupon endpoint is quoted the same base the order will carry. */
    function subtotal() { return cart.reduce(function (n, c) { return n + lineUnit(c) * c.qty; }, 0) + comboTotal(); }

    /* COMBOS-V2 — the shared combos partial (_partials/combos.blade.php) keeps its
       own basket and publishes display state on window.mbCombos {count,total,lines}.
       The server prices combo lines into the ORDER subtotal (and taxes them like any
       other line), so the quote must fold them in too — QUOTED == CHARGED. Quantity
       edits stay in the combo sheet; here they render read-only. */
    function comboCount() { return (window.mbCombos && window.mbCombos.count) || 0; }
    function comboTotal() { return (window.mbCombos && window.mbCombos.total) || 0; }
    function comboLines() { return (window.mbCombos && window.mbCombos.lines) || []; }

    function renderCart() {
        var list = $('#au-cart-list');
        var emptyBox = $('#au-cart-empty');
        var form = $('#au-order-form');
        var foot = $('#au-cart-foot');
        if (!list) return;

        /* COMBOS-V2 — combos alone make the cart non-empty: aurora's cart sheet
           doubles as the checkout summary, so the form + footer (place-order CTA,
           totals) must unhide for a combos-only basket too. */
        if (!cart.length && comboCount() === 0) {
            list.innerHTML = '';
            if (emptyBox) emptyBox.style.display = '';
            if (form) form.style.display = 'none';
            if (foot) foot.style.display = 'none';
        } else {
            if (emptyBox) emptyBox.style.display = 'none';
            if (form) form.style.display = '';
            if (foot) foot.style.display = '';
            list.innerHTML = cart.map(function (c, i) {
                var meta = [];
                if (c.variantLabel) meta.push(c.variantLabel);
                if (c.mods && c.mods.length) meta.push(c.mods.map(function (m) { return (m.groupTitle ? m.groupTitle + ': ' : '') + m.label; }).join(', '));
                if (c.extras.length) meta.push(c.extras.map(function (e) { return e.title; }).join(', '));
                var unit = lineUnit(c);
                var it = ITEMS[c.id] || {};
                /* Happy-hour strike-through: original_price is the pre-schedule BASE
                   price, so the pre-discount unit is unit + (original − current base). */
                var wasUnit = it.original_price && Number(it.original_price) > Number(it.price)
                    ? unit + (Number(it.original_price) - Number(it.price)) : 0;
                return '<div class="au-cart-item">' +
                    '<button type="button" class="ci-im" data-open-item="' + c.id + '" aria-label="' + esc(c.name) + '"><img src="' + c.img + '" alt="" data-csp-error="opacity"></button>' +
                    '<div class="ci-main">' +
                    '<button type="button" class="ci-name" data-open-item="' + c.id + '">' + esc(c.name) + '</button>' +
                    (meta.length ? '<div class="ci-meta">' + esc(meta.join(' · ')) + '</div>' : '') +
                    (c.order_note ? '<div class="ci-meta" style="font-style:italic;color:var(--au-muted);margin-top:2px;">' + esc(c.order_note) + '</div>' : '') +
                    '<div class="ci-foot">' +
                    '<div class="au-qty sm" data-cart-qty="' + i + '">' +
                    '<button type="button" data-q="-1">&minus;</button><span>' + c.qty + '</span><button type="button" data-q="1">+</button>' +
                    '</div>' +
                    '<span class="ci-priceline"><span class="ci-price">' + money(unit * c.qty) + '</span>' +
                    (wasUnit ? '<del class="ci-was">' + money(wasUnit * c.qty) + '</del>' : '') +
                    (c.qty > 1 ? '<small class="ci-unit">' + c.qty + ' × ' + money(unit) + '</small>' : '') +
                    '</span>' +
                    /* Trash lives on the footer row now — the old standalone
                       "Remove" text row added ~40px of dead height per card. */
                    '<button type="button" class="au-remove" data-remove="' + i + '" aria-label="' + esc(CFG.i18n.remove) + '" title="' + esc(CFG.i18n.remove) + '"><i class="ico ico-trash" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12.5a1.5 1.5 0 0 0 1.5 1.5h7a1.5 1.5 0 0 0 1.5-1.5L18 7M9 7V4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V7"/></svg></i></button>' +
                    '</div>' +
                    '</div></div>';
            }).join('') + comboGroupHTML(false);
            bindCartControls();
        }
        renderTotals();
        updateCounters();
    }

    function bindCartControls() {
        $$('[data-cart-qty]').forEach(function (box) {
            var i = Number(box.getAttribute('data-cart-qty'));
            $$('button', box).forEach(function (b) {
                b.addEventListener('click', function () {
                    cart[i].qty += Number(b.getAttribute('data-q'));
                    if (cart[i].qty < 1) cart.splice(i, 1);
                    writeCart(cart);
                    renderCart();
                });
            });
        });
        $$('[data-remove]').forEach(function (b) {
            b.addEventListener('click', function () {
                cart.splice(Number(b.getAttribute('data-remove')), 1);
                writeCart(cart);
                renderCart();
            });
        });
    }

    function currentType() {
        var el = $('#au-ordering-type');
        return el ? el.value : '';
    }
    var currentTipPct = null;
    var customTipAmt = null;

    $$('.au-tip-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var pct = btn.getAttribute('data-tip-pct');
            if (btn.classList.contains('active')) {
                btn.classList.remove('active');
                currentTipPct = null;
                customTipAmt = null;
                var custInp = $('#au-custom-tip');
                if (custInp) custInp.style.display = 'none';
            } else {
                $$('.au-tip-btn').forEach(function(b){ b.classList.remove('active'); });
                btn.classList.add('active');
                var custInp = $('#au-custom-tip');
                if (pct === 'custom') {
                    currentTipPct = null;
                    customTipAmt = Number(custInp.value) || 0;
                    if (custInp) custInp.style.display = '';
                } else {
                    currentTipPct = Number(pct);
                    customTipAmt = null;
                    if (custInp) custInp.style.display = 'none';
                }
            }
            renderTotals();
        });
    });

    var customTipInp = $('#au-custom-tip');
    if (customTipInp) {
        customTipInp.addEventListener('input', function() {
            customTipAmt = Number(this.value) || 0;
            renderTotals();
        });
    }

    /* The cart exactly as the checkout receives it (send-order `items`); the server quote reads the same lines. */
    function orderPayload() {
        return cart.map(function (c) {
            return {
                id: c.id,
                quantity: c.qty,
                variants: c.variantId || 0,
                extras: c.extras.map(function (ex) { return { id: ex.id }; }),
                mods: (c.mods || []).map(function (m) { return { groupId: m.groupId, optIndex: m.optIndex }; }),
                /* WAVE4-V3: the per-line kitchen note was captured in the item sheet
                   and echoed back in the cart line, but never made it into the
                   checkout payload, so `order_items.order_note` came back empty on
                   every aurora order (verified on #99606) and the kitchen never saw
                   "no onions". StoreController@submitOrder reads `order_note` off each
                   item. */
                order_note: c.order_note || ''
            };
        });
    }

    /* P3-13 (THEME-CONTRACT §5.2): the rows and the total come from the server quote of this cart; the sum
       above is only the estimate shown until it answers. */
    function serverQuote(box, cta, tipAmt) {
        var form = $('#au-order-form');
        if (!window.mbQuote || !form || (!cart.length && !comboCount())) return;
        window.mbQuote.update('cart', window.mbQuote.urlFrom(form.getAttribute('action')), {
            items: orderPayload(),
            'ordering-type': currentType(),
            coupon_code: appliedCoupon ? appliedCoupon.code : '',
            tip_amount: tipAmt || 0,
            branch_id: new URLSearchParams(window.location.search).get('branch_id') || ''
        }, function (q) {
            var rows = window.mbQuote.rows(q).map(function (r) {
                return '<div class="tr' + (r.key === 'discount' ? ' au-tr-discount' : '') + '"><span>' + esc(r.label) + '</span><span>' + esc(r.formatted) + '</span></div>';
            }).join('');
            box.innerHTML = rows + '<div class="tr grand"><span>' + esc(q.total_label || CFG.i18n.total) + '</span><span>' + esc(q.total.formatted) + '</span></div>';
            if (cta) cta.textContent = q.total.formatted;
        }, [box]);
    }

    function renderTotals() {
        var box = $('#au-totals');
        if (!box) return;
        var sub = subtotal();
        var delivery = (currentType() === 'delivery') ? CFG.deliveryCharge : 0;
        var tax = computeTax(sub);
        var discount = 0;
        if (appliedCoupon) {
            /* CouponService rounds the discount in the store currency's exponent (bug batch 4 · 2) — so does
               this preview now (bug batch 10 · 4: was 2 decimals). A free_delivery coupon carries
               NO subtotal discount; it waives the delivery line instead, which
               is what StoreController@submitOrder does with $delivery_charge. */
            if (appliedCoupon.coupon_type === 'percentage') {
                discount = roundMoney(sub * (Number(appliedCoupon.percentage) || 0) / 100);
            } else if (appliedCoupon.coupon_type === 'free_delivery') {
                delivery = 0;
            } else {
                discount = roundMoney(Number(appliedCoupon.percentage) || 0);
            }
            if (discount > sub) {
                discount = sub;
            }
        }

        var appliedTipAmt = 0;
        if (currentTipPct) {
            appliedTipAmt = roundMoney(sub * (currentTipPct / 100));
        } else if (customTipAmt !== null) {
            appliedTipAmt = roundMoney(customTipAmt);
        }

        /* Round the quote itself to the store's currency: floating-point noise
           (0.1+0.2) can otherwise print a cent the server never charges. */
        var grand = roundMoney(sub + delivery + tax + appliedTipAmt - discount);
        var rows = '<div class="tr"><span>' + CFG.i18n.subtotal + '</span><span>' + money(sub) + '</span></div>';
        if (delivery > 0) rows += '<div class="tr"><span>' + CFG.i18n.delivery + '</span><span>' + money(delivery) + '</span></div>';
        if (tax > 0) rows += '<div class="tr"><span>' + CFG.i18n.tax + '</span><span>' + money(tax) + '</span></div>';
        if (appliedTipAmt > 0) rows += '<div class="tr"><span>' + esc(CFG.i18n.tip || 'Tip') + '</span><span>' + money(appliedTipAmt) + '</span></div>';
        if (discount > 0) rows += '<div class="tr au-tr-discount"><span>' + esc(CFG.i18n.discount + ' (' + appliedCoupon.code + ')') + '</span><span>-' + money(discount) + '</span></div>';
        rows += '<div class="tr grand"><span>' + CFG.i18n.total + '</span><span>' + money(grand) + '</span></div>';
        box.innerHTML = rows;
        var cta = $('#au-checkout-total');
        if (cta) cta.textContent = money(grand);
        
        var tipField = $('#au-tip-amount');
        if (tipField) tipField.value = appliedTipAmt;
        serverQuote(box, cta, appliedTipAmt);
    }

    function updateCounters() {
        var n = cartCount() + comboCount();
        var badge = $('#au-cart-count');
        if (badge) { badge.textContent = n; badge.classList.toggle('show', n > 0); }
        /* dish-sheet: a direct jump to the cart, shown once anything is in it */
        var jump = $('#au-cart-jump');
        if (jump) { jump.hidden = n === 0; var jc = $('#au-cart-jump-count'); if (jc) jc.textContent = n; }
        var bar = $('#au-cartbar');
        if (bar) {
            bar.classList.toggle('show', n > 0);
            var cnt = $('#au-cartbar-count');
            var tot = $('#au-cartbar-total');
            if (cnt) cnt.textContent = mbPlural(CFG.i18n.itemForms, n);
            if (tot) tot.textContent = money(subtotal());
        }
    }

    function pulseCart() {
        var btn = $('#au-cart-toggle');
        if (btn) { btn.style.transform = 'scale(1.18)'; setTimeout(function () { btn.style.transform = ''; }, 220); }
    }

    /* COMBOS-V2 — read-only "Combos" group appended to the cart list (aurora's
       cart sheet doubles as the checkout summary, so one group serves both).
       Tapping a row reopens the combo sheet's basket view, which is where
       quantities are edited. */
    function comboGroupHTML(compact) {
        var lines = comboLines();
        if (!lines.length) return '';
        var h = '<div class="au-combo-group" data-combo-open style="border-top:1px dashed var(--au-border,#8883);padding-top:8px;margin-top:8px;cursor:pointer">';
        lines.forEach(function (L) {
            h += '<div class="au-cart-item au-cart-item--combo">' +
                '<div class="ci-main"><span class="ci-name">' + esc(L.name) + '</span>' +
                (L.parts.length ? '<div class="ci-meta">' + esc(L.parts.join(' · ')) + '</div>' : '') +
                '</div>' +
                '<span class="ci-meta">&times;' + L.qty + '</span>' +
                '<span class="ci-price">' + money(L.price) + '</span></div>';
        });
        if (!compact) h += '<small style="opacity:.65">' + esc(CFG.i18n.edit || 'Edit') + ' &rsaquo;</small>';
        h += '</div>';
        return h;
    }
    document.addEventListener('click', function (e) {
        var g = e.target.closest && e.target.closest('[data-combo-open]');
        if (g && window.mbCombos && window.mbCombos.openSheet) window.mbCombos.openSheet('basket');
    });
    /* Re-quote whenever the combo basket changes (add/remove/qty in the sheet):
       renderCart() rebuilds the list + totals + counters in one pass. */
    window.addEventListener('mb:combos-changed', function () {
        try { renderCart(); pulseCart(); } catch (e) {}
    });

    var cartToggle = $('#au-cart-toggle');
    if (cartToggle) cartToggle.addEventListener('click', function () { renderCart(); openSheet('cart'); });
    var cartbar = $('#au-cartbar');
    if (cartbar) cartbar.addEventListener('click', function () { renderCart(); openSheet('cart'); });

    /* =====================================================================
       CHECKOUT
       ===================================================================== */
    var typeSeg = $('#au-ordertype-seg');
    function setType(type) {
        $('#au-ordering-type').value = type;
        $$('button', typeSeg).forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-type') === type); });
        toggleFields(type);
        renderTotals();
    }
    function toggleFields(type) {
        /* Room service is fulfilled in the guest's room, so like on-table it needs
           neither a phone number nor a delivery address -- the room rides along on
           the hidden `room` field the shared hotel partial renders. */
        var show = {
            table:   type === 'on-table',
            phone:   type !== 'on-table' && type !== 'room_service',
            address: type === 'delivery'
        };
        Object.keys(show).forEach(function (f) {
            var el = $('[data-field="' + f + '"]');
            if (!el) return;
            el.style.display = show[f] ? '' : 'none';
            /* A delivery order with no address, or a takeaway with no phone, is
               an order the restaurant cannot fulfil — the fields were shown but
               never made required, and nothing stopped the diner submitting
               them empty. Requiredness follows visibility, and hidden fields
               must LOSE it or the browser refuses to submit a form whose
               invalid control it cannot even scroll to. */
            var input = el.querySelector('input, textarea, select');
            if (!input) return;
            if (show[f] && (f === 'address' || f === 'phone' || f === 'table')) {
                input.required = true;
            } else {
                input.required = false;
            }
        });
    }
    if (typeSeg) {
        $$('button', typeSeg).forEach(function (b) {
            b.addEventListener('click', function () { setType(b.getAttribute('data-type')); });
        });
        /* default selection: room service > forced table > first available.
           The room-service button only renders when $allow_room_service is set, which
           already means hotel mode is on AND this QR resolved to a room — so its mere
           presence is the signal that the guest scanned from their room. Preferring it
           saves them a tap and, more importantly, stops a room order silently going to
           the kitchen as a table order when dine-in also happens to be enabled.
           setType() is used (not a bare class toggle) so #au-ordering-type actually
           carries room_service and toggleFields() runs. */
        var firstBtn = $('button', typeSeg);
        if ($('button[data-type="room_service"]', typeSeg)) {
            setType('room_service');
        } else if (CFG.forceTable && $('button[data-type="on-table"]', typeSeg)) {
            setType('on-table');
        } else if (firstBtn) {
            setType(firstBtn.getAttribute('data-type'));
        }
    }

    var form = $('#au-order-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var err = $('#au-form-error');
            err.style.display = 'none';

            if (!currentType()) {
                err.textContent = CFG.i18n.selectType; err.style.display = 'block'; return;
            }
            if (!cart.length && !comboCount()) return; /* combos-only orders are legal */
            // #17: Enforce minimum order value
            if (CFG.minOrderValue > 0) {
                if (subtotal() < CFG.minOrderValue) {
                    err.textContent = (CFG.i18n.min_order || 'Minimum order:') + ' ' + money(CFG.minOrderValue);
                    err.style.display = 'block'; return;
                }
            }

            var payload = orderPayload();

            var fd = new FormData(form);
            fd.append('items', JSON.stringify(payload));
            var auBid = new URLSearchParams(window.location.search).get('branch_id');
            if (auBid) { fd.append('branch_id', auBid); }
            if (appliedCoupon) {
                fd.set('coupon_code', appliedCoupon.code);
            } else {
                fd.delete('coupon_code');
            }

            var btn = $('#au-checkout-btn');
            var original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="ico ico-loader-2 ico-spin" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3a9 9 0 1 0 9 9"/></svg></i>';

            fetch(form.getAttribute('action'), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    btn.disabled = false;
                    btn.innerHTML = original;
                    if (res && res.success) {
                        cart = [];
                        writeCart(cart);
                        /* R5 · remember this order for the guest history list */
                        rememberOrder(res);
                        /* R6 · remember a delivery address for guests (no-op if logged in) */
                        if (currentType() === 'delivery') {
                            var _addrEl = $('#au-cust-addr'), _latEl = $('#au-cust-lat'), _lngEl = $('#au-cust-lng');
                            if (_addrEl) rememberAddress(_addrEl.value, _latEl && _latEl.value, _lngEl && _lngEl.value);
                        }
                        if (res.message) {
                            window.location.href = res.message; /* online payment */
                            return;
                        }
                        $('#au-order-form').style.display = 'none';
                        $('#au-cart-foot').style.display = 'none';
                        $('#au-order-success').classList.add('show');
                        var ono = $('#au-order-no');
                        if (ono && res.order_id) {
                            ono.textContent = (CFG.i18n.order_label || 'Order') + ' #' + res.order_id;
                            ono.hidden = false;
                        }
                        updateCounters();
                        
                        if (res.whatsapp_url) {
                            mbWhatsAppHandoff(res.whatsapp_url, res.whatsapp_handoff);
                        }
                        
                        setTimeout(function () {
                            window.location.href = res.track_url;
                        }, 1500);
                    } else if (res && res.otp_required) {
                        /* F7 · phone-verification gate — open OTP sheet, then re-submit */
                        var phEl = form.querySelector('input[name="phone-number"]');
                        if (typeof openCheckoutOtp === 'function') {
                            openCheckoutOtp((res.phone) || (phEl && phEl.value) || '');
                        }
                    } else {
                        /* textContent, not innerHTML: res.message is a server
                           string that can carry back user-supplied values (a
                           coupon code, an address, a name), and nothing in it
                           needs markup. */
                        err.textContent = (res && res.message) || CFG.i18n.action_failed || 'Something went wrong.';
                        err.style.display = 'block';
                    }
                })
                .catch(function () {
                    btn.disabled = false; btn.innerHTML = original;
                    err.textContent = CFG.i18n.networkError || 'Network error. Please try again.';
                    err.style.display = 'block';
                });
        });
    }

    var applyBtn = $('#au-apply-coupon');
    if (applyBtn) {
        applyBtn.addEventListener('click', function () {
            var codeInput = $('#au-coupon-code');
            var msgDiv = $('#au-coupon-msg');
            if (!codeInput || !msgDiv) return;

            var code = codeInput.value.trim();
            if (!code) {
                msgDiv.textContent = CFG.i18n.promo_enter || 'Please enter a promo code.';
                msgDiv.className = 'au-coupon-msg error';
                msgDiv.style.display = 'block';
                return;
            }

            var sub = subtotal();
            applyBtn.disabled = true;
            msgDiv.style.display = 'none';

            var couponUrl = form.getAttribute('action').replace('/send-order', '/coupon');

            fetch(couponUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': $('input[name="_token"]').value
                },
                body: JSON.stringify({ code: code, subtotal: sub })
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    applyBtn.disabled = false;
                    if (res && res.success) {
                        appliedCoupon = {
                            code: res.label,
                            coupon_type: res.coupon_type,
                            percentage: res.percentage
                        };
                        msgDiv.textContent = res.message;
                        msgDiv.className = 'au-coupon-msg success';
                        msgDiv.style.display = 'block';
                        renderTotals();
                    } else {
                        appliedCoupon = null;
                        msgDiv.textContent = (res && res.message) || CFG.i18n.promo_invalid || 'Invalid promo code.';
                        msgDiv.className = 'au-coupon-msg error';
                        msgDiv.style.display = 'block';
                        renderTotals();
                    }
                })
                .catch(function () {
                    applyBtn.disabled = false;
                    msgDiv.textContent = CFG.i18n.promo_error || 'Error validating promo code.';
                    msgDiv.className = 'au-coupon-msg error';
                    msgDiv.style.display = 'block';
                    appliedCoupon = null;
                    renderTotals();
                });
        });
    }

    /* =====================================================================
       LANGUAGE SWITCHER
       ===================================================================== */
    var lang = $('#au-lang');
    var langToggle = $('#au-lang-toggle');
    if (lang && langToggle) {
        langToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            lang.classList.toggle('open');
        });
        document.addEventListener('click', function () { lang.classList.remove('open'); });
        $$('.au-lang-menu button', lang).forEach(function (b) {
            b.addEventListener('click', function () {
                var code = b.getAttribute('data-code');
                var name = b.getAttribute('data-lang');
                setCookie('Quick_lang', name);
                setCookie('Quick_user_lang', name);
                setCookie('Quick_user_lang_code', code);
                window.location.reload();
            });
        });
    }
    function setCookie(k, v) { document.cookie = k + '=' + encodeURIComponent(v) + ';path=/;max-age=31536000'; }

    /* ----------------------------------------------------------- utils */
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* =====================================================================
       DIETARY & ALLERGEN FILTERS
       ===================================================================== */
    var filtersToggle = $('#au-filters-toggle');
    var filtersPanel = $('#au-filters-panel');
    if (filtersToggle && filtersPanel) {
        filtersToggle.addEventListener('click', function () {
            filtersToggle.classList.toggle('active');
            filtersPanel.classList.toggle('show');
        });
    }

    $$('.au-filter-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            chip.classList.toggle('active');
            applyFilters();
        });
    });

    var filterClearBtn = $('#au-filter-clear');
    if (filterClearBtn) {
        filterClearBtn.addEventListener('click', function () {
            $$('.au-filter-chip').forEach(function (chip) {
                chip.classList.remove('active');
            });
            applyFilters();
        });
    }

    /* =====================================================================
       RESUME ORDER BANNER
       ===================================================================== */
    var resumeBanner = $('#au-resume-banner');
    /* The banner and .au-cartbar share the same show condition (a non-empty
       cart), so both would sit at the bottom and overlap. Flag the root while
       the prompt is up so the cartbar stays slid out until it is dismissed. */
    var auRoot = $('#aurora');
    function hideResumeBanner() {
        resumeBanner.style.display = 'none';
        if (auRoot) auRoot.classList.remove('au-resume-open');
    }
    if (resumeBanner && showResumePrompt && cart.length > 0) {
        var resumeText = $('#au-resume-text');
        if (resumeText) {
            resumeText.textContent = mbPlural(CFG.i18n.itemForms, cart.length) + ' ' + CFG.i18n.resumeSuffix;
        }
        resumeBanner.style.display = 'flex';
        if (auRoot) auRoot.classList.add('au-resume-open');

        var resumeBtn = $('#au-resume-btn');
        if (resumeBtn) {
            resumeBtn.addEventListener('click', function () {
                hideResumeBanner();
                renderCart();
                openSheet('cart');
            });
        }

        var clearResumeBtn = $('#au-resume-clear-btn');
        if (clearResumeBtn) {
            clearResumeBtn.addEventListener('click', function () {
                hideResumeBanner();
                cart = [];
                writeCart(cart);
                renderCart();
            });
        }
    }

    /* =====================================================================
       AUTH SHEET & LOGIC
       ===================================================================== */
    var authSheet = $('#au-auth-sheet');
    var accountMenu = $('#au-account-menu');
    var loginToggleBtn = $('#au-login-toggle');
    var checkoutLoginBtn = $('#au-checkout-login-btn');
    var logoutBtn = $('#au-logout-btn');

    if (accountMenu) {
        var amBtn = accountMenu.querySelector('.au-icon-btn');
        if (amBtn) {
            amBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                accountMenu.classList.toggle('open');
            });
            document.addEventListener('click', function () { accountMenu.classList.remove('open'); });
        }
    }

    if (logoutBtn) {
        logoutBtn.addEventListener('click', function () {
            fetch(CFG.endpoints.logout, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': $('input[name="_token"]')?.value || '',
                    'Accept': 'application/json'
                }
            }).then(function() { window.location.reload(); });
        });
    }

    function openAuthSheet(tab) {
        if (!authSheet) return;
        var scrim = $('#au-item-scrim') || $('#au-cart-scrim');
        if (!scrim) {
            scrim = document.createElement('div');
            scrim.className = 'au-scrim';
            scrim.id = 'au-auth-scrim';
            document.body.appendChild(scrim);
            scrim.addEventListener('click', function() { closeSheet('auth'); });
        }
        
        authSheet.classList.add('open');
        authSheet.setAttribute('aria-hidden', 'false');
        scrim.classList.add('open');
        document.body.style.overflow = 'hidden';

        if (tab) {
            switchAuthTab(tab);
        }
    }

    if (loginToggleBtn) {
        loginToggleBtn.addEventListener('click', function() { openAuthSheet('login'); });
    }
    
    if (checkoutLoginBtn) {
        checkoutLoginBtn.addEventListener('click', function() {
            closeSheet('cart');
            setTimeout(function() { openAuthSheet('login'); }, 300);
        });
    }

    var authTabs = $$('#au-auth-seg button');
    var loginForm = $('#au-login-form');
    var registerForm = $('#au-register-form');
    var otpForm = $('#au-otp-form');
    var authError = $('#au-auth-error');

    function switchAuthTab(tabName) {
        if (!authTabs.length) return;
        authTabs.forEach(function(b) {
            b.classList.toggle('active', b.getAttribute('data-tab') === tabName);
        });
        if (loginForm) loginForm.style.display = tabName === 'login' ? 'block' : 'none';
        if (registerForm) registerForm.style.display = tabName === 'register' ? 'block' : 'none';
        if (otpForm) otpForm.style.display = tabName === 'otp' ? 'block' : 'none';
        if (authError) authError.style.display = 'none';
    }

    authTabs.forEach(function(btn) {
        btn.addEventListener('click', function() {
            switchAuthTab(this.getAttribute('data-tab'));
        });
    });

    function handleAuthSubmit(formEl, endpoint) {
        if (!formEl || !authError) return;
        formEl.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = formEl.querySelector('button[type="submit"]');
            var originalText = btn.innerHTML;
            btn.innerHTML = '<i class="ico ico-loader-2 ico-spin" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3a9 9 0 1 0 9 9"/></svg></i>';
            btn.disabled = true;
            authError.style.display = 'none';

            var formData = new FormData(formEl);
            var data = {};
            formData.forEach(function(val, key) { data[key] = val; });
            data.store_id = CFG.storeId;

            fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': $('input[name="_token"]')?.value || ''
                },
                body: JSON.stringify(data)
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    window.location.reload();
                } else {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    authError.textContent = res.message || CFG.i18n.action_failed || 'Error occurred';
                    authError.style.display = 'block';
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.innerHTML = originalText;
                authError.textContent = CFG.i18n.networkError || 'Network error. Please try again.';
                authError.style.display = 'block';
            });
        });
    }

    if (loginForm) handleAuthSubmit(loginForm, CFG.endpoints.login);
    if (registerForm) handleAuthSubmit(registerForm, CFG.endpoints.register);

    /* ── phone OTP login ── */
    (function () {
        if (!otpForm || !CFG.endpoints || !CFG.endpoints.otpRequest) return;
        var step1 = $('#au-otp-step1');
        var step2 = $('#au-otp-step2');
        var sendBtn = $('#au-otp-send');
        var verifyBtn = $('#au-otp-verify');
        var csrf = ($('input[name="_token"]') && $('input[name="_token"]').value) || CFG.csrf || '';

        function otpPost(url, body, btn) {
            var original = btn.innerHTML;
            btn.innerHTML = '<i class="ico ico-loader-2 ico-spin" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3a9 9 0 1 0 9 9"/></svg></i>';
            btn.disabled = true;
            if (authError) authError.style.display = 'none';
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(body)
            }).then(function (r) { return r.json(); }).then(function (res) {
                btn.disabled = false;
                btn.innerHTML = original;
                return res;
            }).catch(function () {
                btn.disabled = false;
                btn.innerHTML = original;
                if (authError) { authError.textContent = CFG.i18n.networkError || 'Network error. Please try again.'; authError.style.display = 'block'; }
                return null;
            });
        }

        if (sendBtn) sendBtn.addEventListener('click', function () {
            var phone = (otpForm.querySelector('input[name="phone"]').value || '').trim();
            if (!phone) return;
            otpPost(CFG.endpoints.otpRequest, { store_id: CFG.storeId, phone: phone }, sendBtn).then(function (res) {
                if (res && res.success) {
                    if (step1) step1.style.display = 'none';
                    if (step2) step2.style.display = 'block';
                    var codeInput = otpForm.querySelector('input[name="code"]');
                    if (codeInput) codeInput.focus();
                } else if (res && authError) {
                    authError.textContent = res.message || CFG.i18n.otp_send_failed || 'Could not send the code.';
                    authError.style.display = 'block';
                }
            });
        });

        if (verifyBtn) verifyBtn.addEventListener('click', function () {
            var phone = (otpForm.querySelector('input[name="phone"]').value || '').trim();
            var code = (otpForm.querySelector('input[name="code"]').value || '').trim();
            if (!code) return;
            otpPost(CFG.endpoints.otpVerify, { store_id: CFG.storeId, phone: phone, code: code }, verifyBtn).then(function (res) {
                if (res && res.success) {
                    window.location.reload();
                } else if (res && authError) {
                    authError.textContent = res.message || CFG.i18n.otp_invalid || 'Invalid or expired code.';
                    authError.style.display = 'block';
                }
            });
        });
    })();

    /* bespoke splash — the skip button just fast-forwards (and removes it so it can
       never trap taps once the animation has run). R2 · session-gate: the intro
       shows once per session; on later navigations it is removed instantly so it
       never replays. */
    (function () {
        var splash = $('#au-splash');
        if (!splash) return;
        function dismiss() {
            splash.classList.add('is-gone');
            setTimeout(function () { if (splash && splash.parentNode) splash.parentNode.removeChild(splash); }, 500);
        }
        var splashKey = 'au_splash_' + (CFG.restaurantSlug || 'default');
        var splashSeen = false;
        try { splashSeen = !!sessionStorage.getItem(splashKey); } catch (e) {}
        if (splashSeen) {
            /* already shown this session — hard-remove now, no replay, no flash */
            if (splash.parentNode) splash.parentNode.removeChild(splash);
            return;
        }
        /* A1: a ?item=/?table= deep link opens straight on the product — hard-remove
           the splash up front so it never overlays it (splash z-index sits above the
           sheet). Not persisted: a later plain visit this session still gets it. */
        var deepLinked = false;
        try { var _dl = new URLSearchParams(location.search); deepLinked = !!(_dl.get('item') || _dl.get('table')); } catch (e) {}
        if (deepLinked) { if (splash.parentNode) splash.parentNode.removeChild(splash); return; }
        try { sessionStorage.setItem(splashKey, '1'); } catch (e) {}
        var skip = $('#au-splash-skip');
        if (skip) skip.addEventListener('click', dismiss);
        /* safety net: hard-remove after the CSS fade window so it never lingers */
        setTimeout(dismiss, 2600);
    })();

    /* =====================================================================
       R5 · GUEST ORDER HISTORY (localStorage)
       Remembers each successful order's track link so a returning guest sees
       their recent orders without a phone/email lookup. Coexists with the
       server lookup (separate container), so a lookup never clobbers this list.
       ===================================================================== */
    var AU_RECENT_ORDERS_KEY = 'au_recent_orders_' + (CFG.restaurantSlug || 'default');
    function readRecentOrders() {
        try { return (JSON.parse(localStorage.getItem(AU_RECENT_ORDERS_KEY) || '[]') || []).filter(function (o) { return o && o.track_url; }); }
        catch (e) { return []; }
    }
    function rememberOrder(res) {
        if (!res || !res.track_url) return;
        var l = readRecentOrders().filter(function (o) { return o.track_url !== res.track_url; });
        l.unshift({ id: res.order_id || '', track_url: res.track_url, total: res.total || '', placed_at: Date.now() });
        try { localStorage.setItem(AU_RECENT_ORDERS_KEY, JSON.stringify(l.slice(0, 10))); } catch (e) {}
        renderRecentOrders();
    }
    function renderRecentOrders() {
        var box = $('#au-recent-orders');
        if (!box) return;
        var l = readRecentOrders();
        if (!l.length) { box.hidden = true; box.innerHTML = ''; return; }
        box.hidden = false;
        var head = '<div class="au-recent-orders-head">' + esc(CFG.i18n.recent_orders || 'Your recent orders') + '</div>';
        box.innerHTML = head + l.map(function (o) {
            var label = (CFG.i18n.order_label || 'Order') + (o.id ? ' #' + esc(String(o.id)) : '');
            var total = o.total ? '<span class="ro-total">' + esc(String(o.total)) + '</span>' : '';
            return '<a class="au-recent-order" href="' + esc(o.track_url) + '" target="_blank" rel="noopener">' +
                '<span class="ro-id"><i class="ico ico-receipt" style="margin-inline-end:6px;color:var(--au-fx)" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16l-3 -2l-2 2l-2 -2l-2 2l-2 -2l-3 2m4 -14h6m-6 4h6m-2 4h2"/></svg></i>' + label + '</span>' +
                total + '</a>';
        }).join('');
    }

    /* =====================================================================
       R6 · GUEST MULTI-ADDRESS (localStorage)
       Mirrors the server address book for guests only (!isLoggedIn): remembers
       delivery addresses and renders tap-to-fill chips into the same saved-
       addresses spot. The storage key is computed INLINE via addrKey() (never a
       hoisted var) so it is always defined when renderRecentAddrs() runs at boot.
       ===================================================================== */
    function addrKey() { return 'au_recent_addrs_' + (CFG.restaurantSlug || 'default'); }
    function readRecentAddrs() {
        try { return (JSON.parse(localStorage.getItem(addrKey()) || '[]') || []).filter(function (a) { return a && a.addr; }); }
        catch (e) { return []; }
    }
    function rememberAddress(addr, lat, lng) {
        addr = (addr || '').trim();
        if (!addr || CFG.isLoggedIn) return;
        var l = readRecentAddrs().filter(function (a) { return a.addr !== addr; });
        l.unshift({ addr: addr, lat: lat || '', lng: lng || '' });
        try { localStorage.setItem(addrKey(), JSON.stringify(l.slice(0, 5))); } catch (e) {}
    }
    function renderRecentAddrs() {
        var saved = $('#au-saved-addrs');
        if (!saved || CFG.isLoggedIn) return;
        var l = readRecentAddrs();
        if (!l.length) { saved.hidden = true; return; }
        saved.hidden = false;
        saved.innerHTML = l.map(function (a, i) {
            var head = a.addr.split(',')[0];
            return '<button class="au-saved-addr' + (i === 0 ? ' is-on' : '') + '" type="button" ' +
                'data-addr="' + esc(a.addr) + '" data-lat="' + esc(a.lat) + '" data-lng="' + esc(a.lng) + '">' +
                '<i class="ico ico-pin" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg></i>' +
                '<span class="sa-text"><b>' + esc(head) + '</b><small>' + esc(a.addr) + '</small></span></button>';
        }).join('');
    }

    /* =====================================================================
       R7 · ?item= DEEPLINK / SPA interceptor
       Intercept same-page ?item= link clicks to open the product in place (no
       reload) and open any ?item= product on boot.
       ===================================================================== */
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[href*="item="]');
        if (!a) return;
        var u;
        try { u = new URL(a.href, location.href); } catch (err) { return; }
        // Only intercept same-page links (avoid hijacking links to other paths).
        if (u.pathname !== location.pathname) return;
        var iid = u.searchParams.get('item');
        if (!iid || !ITEMS[iid]) return;
        e.preventDefault();
        openItem(iid);
    });

    /* boot */
    renderCart();
    applyFilters();
    renderRecentOrders();
    renderRecentAddrs();
    /* R7 · open a ?item= deeplink on boot. Deferred so the F6 review wrapper (which
       reassigns openItem further down this IIFE) is installed first. */
    (function () {
        var deepItem = new URLSearchParams(location.search).get('item');
        if (deepItem && ITEMS[deepItem]) setTimeout(function () { openItem(deepItem); }, 0);
    })();

    if (reorderPending && cart.length > 0) {
        try {
            sessionStorage.removeItem(REORDER_PENDING_KEY);
            sessionStorage.removeItem(REORDER_NOTICE_KEY);
        } catch (e) {}

        setTimeout(function () {
            renderCart();
            openSheet('cart');
            var err = $('#au-form-error');
            if (err && reorderNotice) {
                err.textContent = reorderNotice;
                err.style.display = 'block';
            }
        }, 60);
    }

    /* =====================================================================
       THEME-PACK-BLADE FEATURES (F1–F7)
       ===================================================================== */
    var EP = CFG.endpoints || {};
    var I18N = CFG.i18n || {};

    /* lightweight toast */
    var toastEl = $('#au-toast');
    var toastTimer = null;
    function toast(msg) {
        if (!toastEl || !msg) return;
        toastEl.textContent = msg;
        toastEl.classList.add('show');
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 2400);
    }

    var IMG_ERR = ' data-csp-error="opacity"';

    function tokenHeaders(json) {
        var h = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (json) h['Content-Type'] = 'application/json';
        if (CFG.csrf) h['X-CSRF-TOKEN'] = CFG.csrf;
        if (CFG.customerToken) h['Authorization'] = 'Bearer ' + CFG.customerToken;
        return h;
    }
    function hasToken() { return !!CFG.customerToken; }
    function requireLogin() {
        if (typeof openAuthSheet === 'function') openAuthSheet('login');
        else toast(I18N.failed || 'Please log in');
    }

    /* ── F2 · follow / verified brand (B4/B5/B6) ──────────── */
    (function () {
        var btn = $('#au-follow-btn');
        if (!btn || !EP.follow) return;
        var FOLLOW_INTENT = 'cv_follow_intent'; // shared key across themes
        var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function applyFollowState(d) {
            if (!d || typeof d.following === 'undefined') { return false; }
            btn.dataset.following = d.following ? '1' : '0';
            btn.classList.toggle('is-on', d.following);
            var c = $('#au-follower-count'); if (c && typeof d.follower_count !== 'undefined') c.textContent = d.follower_count;
            return true;
        }

        // B5: optimistic count nudge (the count span only exists past the B1 threshold).
        function bumpCount(delta) {
            var c = $('#au-follower-count'); if (!c) return;
            var n = parseInt(String(c.textContent || '').replace(/[^0-9]/g, ''), 10);
            if (!isNaN(n)) c.textContent = String(Math.max(0, n + delta));
        }

        // B5: scale-pop beat on a successful follow (skipped under reduced-motion).
        function popBeat() {
            if (REDUCED) return;
            btn.classList.add('au-follow-btn--pop');
            btn.addEventListener('animationend', function h() { btn.classList.remove('au-follow-btn--pop'); btn.removeEventListener('animationend', h); });
        }

        // B5: optimistic follow/unfollow — flip UI immediately, reconcile on response,
        // revert on failure.
        function doFollow(unfollow) {
            var prevFollowing = btn.dataset.following === '1';
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
                    toast(I18N.action_failed || I18N.failed || 'Failed');
                });
        }

        // B6: the "Following" management sheet — supersedes any two-tap guard when the
        // button is already is-on. Notifications toggle writes the follow row's notify
        // via the same follow endpoint ({notify: 0|1}); Unfollow runs the optimistic path.
        var notifyBox = $('#au-follow-notify');
        function openFollowingSheet() {
            if (notifyBox) notifyBox.checked = CFG.followNotify !== false;
            openSheet('following');
        }
        if (notifyBox) {
            notifyBox.addEventListener('change', function () {
                var want = notifyBox.checked;
                fetch(EP.follow, { method: 'POST', headers: tokenHeaders(true), body: JSON.stringify({ notify: want ? 1 : 0 }) })
                    .then(function (r) { return r.json(); }).then(function (d) {
                        if (d && typeof d.notify !== 'undefined') { CFG.followNotify = !!d.notify; notifyBox.checked = !!d.notify; }
                        else { throw new Error('bad'); }
                    }).catch(function () { notifyBox.checked = !want; toast(I18N.action_failed || I18N.failed || 'Failed'); });
            });
        }
        var unfollowBtn = $('#au-follow-unfollow');
        if (unfollowBtn) unfollowBtn.addEventListener('click', function () { closeSheet('following'); doFollow(true); });
        var cancelBtn = $('#au-follow-cancel');
        if (cancelBtn) cancelBtn.addEventListener('click', function () { closeSheet('following'); });

        btn.addEventListener('click', function () {
            // B4: guest tap records a follow intent, then opens the auth sheet. After a
            // successful sign-in the page reloads and the intent is replayed below.
            if (!hasToken()) {
                try { sessionStorage.setItem(FOLLOW_INTENT, '1'); } catch (e) {}
                requireLogin();
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
            if (btn.dataset.following === '1') return; // already following
            fetch(EP.follow, { method: 'POST', headers: tokenHeaders(true), body: '{}' })
                .then(function (r) { return r.json(); }).then(function (d) {
                    if (applyFollowState(d) && d.following) { toast(I18N.now_following || '✓'); }
                }).catch(function () {});
        })();
    })();

    /* ── F2b · about sheet (B8) ───────────────────────────── */
    (function () {
        if (!document.getElementById('au-about-sheet')) return;
        var info = $('#au-brand-info');
        if (!info) return;
        info.setAttribute('role', 'button');
        info.setAttribute('tabindex', '0');
        info.style.cursor = 'pointer';
        function openAbout() { openSheet('about'); }
        info.addEventListener('click', openAbout);
        info.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openAbout(); } });
    })();

    /* ── F3 · stories ─────────────────────────────────────── */
    (function () {
        var rail = $('#au-stories-rail');
        if (!rail || !EP.stories) return;
        var stories = [], idx = 0, timer = null, T = 0;
        var DUR = 5;

        (window.mbStoriesGet?window.mbStoriesGet(EP.stories):fetch(EP.stories).then(function(r){return r.json();})).then(function (d) {
            stories = (d && d.data) || [];
            if (!stories.length) { rail.hidden = true; return; }
            rail.hidden = false;
            rail.innerHTML = stories.map(function (s, i) {
                return '<button class="au-story-bubble" data-si="' + i + '" type="button" aria-label="' + esc((s.caption ? String(s.caption) : (rail.getAttribute('data-story-label') || 'Story')) + ' ' + (i + 1)) + '">' +
                    '<span class="au-sb-ring"><img src="' + esc(s.media_url) + '" alt=""' + IMG_ERR + '></span>' +
                    '<small>' + esc(s.caption ? String(s.caption).slice(0, 14) : (I18N.popular || '')) + '</small></button>';
            }).join('');
        }).catch(function () { rail.hidden = true; });

        var viewer = $('#au-story-viewer'), media = $('#au-sv-media'), cap = $('#au-sv-caption'), cta = $('#au-sv-cta'), prog = $('#au-sv-progress');
        function buildProgress() { prog.innerHTML = stories.map(function () { return '<span class="au-sv-seg"><i></i></span>'; }).join(''); }
        function paintSeg() {
            $$('.au-sv-seg i', prog).forEach(function (bar, i) {
                bar.style.width = i < idx ? '100%' : (i === idx ? (T / DUR * 100) + '%' : '0%');
            });
        }
        function render() {
            var s = stories[idx]; if (!s) return;
            media.src = s.media_url;
            cap.textContent = s.caption || '';
            if (s.cta_url && !(window.mbStoryCta && window.mbStoryCta.isCurrentPage(s.cta_url))) {
                cta.hidden = false; cta.href = s.cta_url;
 if (window.mbStoryCta) window.mbStoryCta.apply(cta, s.cta_url);
                cta.querySelector('span').textContent = s.cta_label || (I18N.popular || 'Order now');
            } else { cta.hidden = true; }
            T = 0; paintSeg();
        }
        function tick() { T += 0.05; if (T >= DUR) { next(); return; } paintSeg(); }
        function start() { stop(); render(); timer = setInterval(tick, 50); }
        function stop() { if (timer) { clearInterval(timer); timer = null; } }
        function open(i) {
            idx = i; buildProgress();
            viewer.hidden = false;
            void viewer.offsetWidth; /* forced reflow */
            viewer.classList.add('is-open');
            document.body.classList.add('au-noscroll');
            start();
        }
        function close() {
            stop();
            viewer.classList.remove('is-open');
            document.body.classList.remove('au-noscroll');
            setTimeout(function () { viewer.hidden = true; }, 300);
        }
        function next() { if (idx < stories.length - 1) { idx++; render(); } else { close(); } }
        function prev() { if (idx > 0) { idx--; render(); } else { T = 0; } }

        rail.addEventListener('click', function (e) {
            var b = e.target.closest('[data-si]'); if (b) open(Number(b.dataset.si));
        });
        var cl = $('#au-sv-close'); if (cl) cl.addEventListener('click', close);
        /* CTA opens the item in-page beneath this viewer; close the viewer so the item sheet is the top layer. */
        if (cta) cta.addEventListener('click', close);
        var nx = $('#au-sv-next'); if (nx) nx.addEventListener('click', next);
        var pv = $('#au-sv-prev'); if (pv) pv.addEventListener('click', prev);
        var stage = $('#au-sv-stage');
        if (stage) {
            stage.addEventListener('pointerdown', stop);
            stage.addEventListener('pointerup', function () { if (!viewer.hidden) timer = setInterval(tick, 50); });
        }
    })();

    /* ── F5 · address autocomplete + use-my-location ──────── */
    (function () {
        var input = $('#au-cust-addr'), box = $('#au-addr-suggest'), attrib = $('#au-geo-attrib');
        if (!input || !EP.geoAutocomplete) return;
        var t = null, lastAttrib = '';
        function setAttrib(a) { if (a && a !== lastAttrib) { attrib.textContent = a; attrib.hidden = false; lastAttrib = a; } }
        function hideBox() { if (box) { box.hidden = true; box.innerHTML = ''; } }
        input.addEventListener('input', function () {
            var q = input.value.trim();
            var la = $('#au-cust-lat'), lo = $('#au-cust-lng');
            if (la) la.value = ''; if (lo) lo.value = '';
            if (t) clearTimeout(t);
            if (q.length < 3) { hideBox(); return; }
            t = setTimeout(function () {
                fetch(EP.geoAutocomplete + '?q=' + encodeURIComponent(q))
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        setAttrib(d && d.attribution);
                        var res = (d && d.results) || [];
                        if (!res.length) { hideBox(); return; }
                        box.innerHTML = res.map(function (r) {
                            return '<button class="au-addr-opt" data-lat="' + (r.lat || '') + '" data-lng="' + (r.lng || '') + '" data-label="' + esc(r.label) + '" type="button">' + esc(r.label) + '</button>';
                        }).join('');
                        box.hidden = false;
                    }).catch(hideBox);
            }, 350);
        });
        if (box) box.addEventListener('click', function (e) {
            var opt = e.target.closest('.au-addr-opt'); if (!opt) return;
            input.value = opt.dataset.label;
            var la = $('#au-cust-lat'), lo = $('#au-cust-lng');
            if (la) la.value = opt.dataset.lat || '';
            if (lo) lo.value = opt.dataset.lng || '';
            hideBox();
        });
        input.addEventListener('blur', function () { setTimeout(hideBox, 200); });
        var locBtn = $('#au-use-location');
        if (locBtn) locBtn.addEventListener('click', function () {
            if (!navigator.geolocation) { toast(CFG.i18n.loc_unavailable || 'Location unavailable'); return; }
            locBtn.classList.add('is-loading');
            navigator.geolocation.getCurrentPosition(function (pos) {
                var lat = pos.coords.latitude, lng = pos.coords.longitude;
                fetch(EP.geoReverse + '?lat=' + lat + '&lng=' + lng)
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        locBtn.classList.remove('is-loading');
                        setAttrib(d && d.attribution);
                        if (d && d.result) {
                            input.value = d.result.label || '';
                            var la = $('#au-cust-lat'), lo = $('#au-cust-lng');
                            if (la) la.value = d.result.lat || lat;
                            if (lo) lo.value = d.result.lng || lng;
                        } else { toast(CFG.i18n.no_address || 'No address found'); }
                    }).catch(function () { locBtn.classList.remove('is-loading'); });
            }, function () { locBtn.classList.remove('is-loading'); toast(CFG.i18n.loc_denied || 'Location denied'); }, { enableHighAccuracy: true, timeout: 8000 });
        });
        /* Saved addresses (logged-in): tap a chip to fill the field + coords. */
        var savedWrap = $('#au-saved-addrs');
        if (savedWrap) savedWrap.addEventListener('click', function (e) {
            var b = e.target.closest('.au-saved-addr'); if (!b) return;
            input.value = b.dataset.addr || '';
            var la = $('#au-cust-lat'), lo = $('#au-cust-lng');
            if (la) la.value = b.dataset.lat || '';
            if (lo) lo.value = b.dataset.lng || '';
            $$('.au-saved-addr', savedWrap).forEach(function (x) { x.classList.remove('is-on'); });
            b.classList.add('is-on');
            hideBox();
        });
    })();

    /* ── F4 · nearest-branch map ──────────────────────────── */
    (function () {
        var openBtn = $('#au-nearest-branch-btn');
        if (!openBtn || !EP.branchesGeo || typeof L === 'undefined') { if (openBtn) openBtn.style.display = 'none'; return; }
        var map = null, markers = [];
        var icon = L.icon({
            iconUrl: CFG.leafletImgPath + '/marker-icon.png',
            iconRetinaUrl: CFG.leafletImgPath + '/marker-icon-2x.png',
            shadowUrl: CFG.leafletImgPath + '/marker-shadow.png',
            iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41],
        });
        function selectBranch(id) {
            var url = new URL(window.location.href);
            url.searchParams.set('branch_id', id);
            window.location.href = url.toString();
        }
        function renderList(branches) {
            $('#au-branch-geo-list').innerHTML = branches.map(function (b) {
                var dist = b.distance_km != null ? '<span class="au-branch-dist" dir="ltr">' + b.distance_km.toFixed(1) + ' km</span>' : '';
                return '<button class="au-branch-row" data-bid="' + b.id + '" type="button">' +
                    '<i class="ico ico-pin" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg></i>' +
                    '<b>' + esc(b.name) + (b.address ? '<small>' + esc(b.address) + '</small>' : '') + '</b>' + dist +
                    '</button>';
            }).join('') || '<p class="au-sheet-sub">' + esc('No branches') + '</p>';
        }
        function drawMap(branches) {
            var withCoords = branches.filter(function (b) { return b.latitude != null && b.longitude != null; });
            if (!map) {
                map = L.map('au-branchmap', { attributionControl: false });
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
                L.control.attribution({ prefix: false }).addAttribution('© OpenStreetMap').addTo(map);
            }
            markers.forEach(function (m) { map.removeLayer(m); }); markers = [];
            if (!withCoords.length) { map.setView([24.7136, 46.6753], 11); return; }
            var group = [];
            withCoords.forEach(function (b) {
                var mk = L.marker([b.latitude, b.longitude], { icon: icon }).addTo(map);
                mk.bindPopup('<b>' + esc(b.name) + '</b>');
                mk.on('click', function () { selectBranch(b.id); });
                markers.push(mk); group.push([b.latitude, b.longitude]);
            });
            map.fitBounds(group, { padding: [40, 40], maxZoom: 14 });
        }
        function load(lat, lng) {
            var url = EP.branchesGeo + (lat != null ? '?lat=' + lat + '&lng=' + lng : '');
            fetch(url).then(function (r) { return r.json(); }).then(function (d) {
                var branches = (d && d.branches) || [];
                $('#au-map-attrib').textContent = (d && d.attribution) || '© OpenStreetMap contributors';
                renderList(branches);
                setTimeout(function () { drawMap(branches); if (map) map.invalidateSize(); }, 80);
            }).catch(function () {});
        }
        openBtn.addEventListener('click', function () {
            openSheet('branchmap');
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function (p) { load(p.coords.latitude, p.coords.longitude); },
                    function () { load(); }, { timeout: 6000 });
            } else { load(); }
        });
        var glist = $('#au-branch-geo-list');
        if (glist) glist.addEventListener('click', function (e) {
            var b = e.target.closest('[data-bid]'); if (b) selectBranch(b.dataset.bid);
        });
    })();

    /* ── F6 · rich reviews (wraps openItem) ───────────────── */
    (function () {
        var curFilter = 'latest', curItemId = null, rvStars = 0;

        function starBar(n) { return '<span class="au-stars-mini">' + '★'.repeat(n) + '<span class="s-dim">' + '★'.repeat(5 - n) + '</span></span>'; }

        function render(container, d) {
            var avg = d.average || 0, count = d.count || 0, dist = d.distribution || {};
            var head = '<div class="au-rv-head"><div class="au-rv-avg"><b>' + avg.toFixed(1) + '</b>' + starBar(Math.round(avg)) +
                '<small>' + count + ' ' + esc(I18N.reviews || 'reviews') + '</small></div>' +
                '<div class="au-rv-dist">' + [5, 4, 3, 2, 1].map(function (s) {
                    var c = dist[s] || 0, pct = count ? Math.round(c / count * 100) : 0;
                    return '<span class="au-rv-bar"><em>' + s + '</em><i><s style="width:' + pct + '%"></s></i><b>' + c + '</b></span>';
                }).join('') + '</div></div>';
            var filters = '<div class="au-rv-filters">' +
                ['latest', 'verified', 'detailed'].map(function (f) {
                    return '<button data-rvf="' + f + '" class="' + (f === curFilter ? 'is-on' : '') + '" type="button">' + esc(I18N['rv_' + f] || f) + '</button>';
                }).join('') + '</div>';
            var list = (d.data || []).map(function (r) {
                var photos = (r.photos || []).map(function (p) { return '<img src="' + esc(p.url) + '" alt=""' + IMG_ERR + '>'; }).join('');
                return '<div class="au-rv-item"><div class="au-rv-item-top"><b>' + esc(r.name || '—') + '</b>' +
                    (r.is_verified ? '<span class="au-rv-verified">✓ ' + esc(I18N.rv_verified || 'Verified') + '</span>' : '') +
                    '</div>' + starBar(r.stars) + (r.body ? '<p>' + esc(r.body) + '</p>' : '') +
                    (photos ? '<div class="au-rv-photos">' + photos + '</div>' : '') + '</div>';
            }).join('');
            if (!(d.data || []).length) list = '<p class="au-rv-empty">' + esc(I18N.rv_empty || 'No reviews yet — be the first!') + '</p>';
            container.innerHTML = '<div class="au-detail-section"><h4>' + esc(I18N.reviews || 'Reviews') +
                ' <button class="au-rv-writebtn" id="au-rv-write" type="button">' + esc(I18N.rv_write || 'Write') + '</button></h4></div>' +
                head + filters + '<div class="au-rv-list">' + list + '</div>';
        }
        function load(itemId, filter) {
            var container = $('#au-rv-container'); if (!container) return;
            curItemId = itemId; curFilter = filter || 'latest';
            fetch(EP.reviewsBase + '/' + itemId + '/reviews?filter=' + curFilter)
                .then(function (r) { return r.json(); }).then(function (d) { render(container, d); })
                .catch(function () { container.innerHTML = ''; });
        }

        /* click delegation on the item body for filters + write */
        document.addEventListener('click', function (e) {
            var f = e.target.closest && e.target.closest('[data-rvf]');
            if (f) { load(curItemId, f.dataset.rvf); return; }
            if (e.target.closest && e.target.closest('#au-rv-write')) { openReview(); }
        });

        function openReview() {
            if (!curItemId) return;
            rvStars = 0;
            $$('#au-review-stars button').forEach(function (b) { b.classList.remove('is-on'); });
            var txt = $('#au-review-text'); if (txt) txt.value = '';
            var nm = $('#au-review-name-in'); if (nm) nm.value = '';
            var err = $('#au-review-err'); if (err) err.style.display = 'none';
            var it = ITEMS[curItemId];
            var nameEl = $('#au-review-item-name'); if (nameEl) nameEl.textContent = it ? it.name : '';
            openSheet('review');
        }
        $$('#au-review-stars button').forEach(function (b) {
            b.addEventListener('click', function () {
                rvStars = Number(b.dataset.star);
                $$('#au-review-stars button').forEach(function (x, i) { x.classList.toggle('is-on', i < rvStars); });
            });
        });
        var subBtn = $('#au-review-submit');
        if (subBtn) subBtn.addEventListener('click', function () {
            var err = $('#au-review-err');
            if (!rvStars) { err.textContent = I18N.choose || 'Choose a rating'; err.style.display = 'block'; return; }
            var body = { rating: rvStars, text: ($('#au-review-text').value || '').trim() };
            var nm = $('#au-review-name-in'); if (nm && nm.value.trim()) body.name = nm.value.trim();
            subBtn.disabled = true;
            fetch(EP.reviewsBase + '/' + curItemId + '/reviews', { method: 'POST', headers: tokenHeaders(true), body: JSON.stringify(body) })
                .then(function (r) { return r.json(); }).then(function (d) {
                    subBtn.disabled = false;
                    if (d && d.ok) {
                        closeSheet('review');
                        toast(I18N.thanks || 'Thanks!');
                        load(curItemId, curFilter);
                    } else { err.textContent = (d && d.message) || I18N.failed || 'Failed'; err.style.display = 'block'; }
                }).catch(function () { subBtn.disabled = false; err.textContent = I18N.networkError || 'Network error'; err.style.display = 'block'; });
        });

        /* wrap openItem to inject the rich reviews list under the quick-rate row */
        if (typeof openItem === 'function' && EP.reviewsBase) {
            var _openItem = openItem;
            openItem = function (id) {
                _openItem(id);
                var body = $('#au-item-body');
                if (!body) return;
                var container = document.createElement('div');
                container.id = 'au-rv-container';
                body.appendChild(container);
                load(id, 'latest');
            };
        }
    })();

    /* ── F1 · meal-plan create sheet ──────────────────────── */
    (function () {
        var sheetEl = $('#au-mealplan-sheet'), cta = $('#au-mealplan-cta');
        if (!cta) return;
        if (!CFG.mealPlansEnabled) { cta.style.display = 'none'; return; }
        var cadence = 'weekly', cadences = [], days = {};
        try { cadences = JSON.parse(sheetEl.getAttribute('data-cadences') || '[]'); } catch (e) {}
        if (!cadences.length) cadences = ['daily', 'weekly'];

        function label(c) { return I18N['cad_' + c] || { daily: 'Daily', weekly: 'Weekly', custom: 'Custom days' }[c] || c; }
        function buildCadence() {
            var el = $('#au-mp-cadence'); if (!el) return;
            cadence = cadences.indexOf('weekly') !== -1 ? 'weekly' : cadences[0];
            el.innerHTML = cadences.map(function (c) {
                return '<button data-cad="' + c + '" class="' + (c === cadence ? 'is-on' : '') + '" type="button">' + esc(I18N['cad_' + c] || label(c)) + '</button>';
            }).join('');
        }
        cta.addEventListener('click', function () {
            if (!cart.length) { toast(I18N.cart_empty || 'Cart is empty'); return; }
            if (!hasToken()) { closeSheet('cart'); requireLogin(); return; }
            buildCadence();
            var sum = $('#au-mp-items-summary');
            if (sum) sum.textContent = cart.reduce(function (a, l) { return a + l.qty; }, 0) + ' × ' + (I18N.items || 'items');
            $('#au-mp-days-wrap').hidden = cadence !== 'custom';
            openSheet('mealplan');
        });
        var loginBtn = $('#au-mp-login-btn');
        if (loginBtn) loginBtn.addEventListener('click', function () { closeSheet('mealplan'); requireLogin(); });

        if (sheetEl) sheetEl.addEventListener('click', function (e) {
            var c = e.target.closest('[data-cad]');
            if (c) {
                cadence = c.dataset.cad;
                $$('#au-mp-cadence button').forEach(function (b) { b.classList.toggle('is-on', b === c); });
                $('#au-mp-days-wrap').hidden = cadence !== 'custom';
                return;
            }
            var d = e.target.closest('[data-day]');
            if (d) { var k = d.dataset.day; days[k] = !days[k]; d.classList.toggle('is-on', days[k]); }
        });
        var mpSubmit = $('#au-mp-submit');
        if (mpSubmit) mpSubmit.addEventListener('click', function () {
            var err = $('#au-mp-err'); err.style.display = 'none';
            var items = cart.map(function (l) {
                return { item_id: l.id, quantity: l.qty, variation: l.variantId || null, extras: (l.extras || []).map(function (e) { return e.id; }) };
            });
            if (!items.length) { err.textContent = I18N.cart_empty || 'Cart is empty'; err.style.display = 'block'; return; }
            var body = { name: $('#au-mp-name').value.trim() || null, cadence: cadence, start_date: $('#au-mp-start').value, items: items };
            if (cadence === 'custom') {
                body.days = Object.keys(days).filter(function (k) { return days[k]; }).map(Number);
                if (!body.days.length) { err.textContent = I18N.choose || 'Pick at least one day'; err.style.display = 'block'; return; }
            }
            mpSubmit.disabled = true;
            fetch(EP.mealPlan, { method: 'POST', headers: tokenHeaders(true), body: JSON.stringify(body) })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (res) {
                    mpSubmit.disabled = false;
                    if (res.ok && res.j && res.j.id) {
                        $('#au-mp-form').hidden = true;
                        $('#au-mp-done').hidden = false;
                        $('#au-mp-done-sub').textContent = (I18N.mp_next || 'Next order') + ': ' + (res.j.next_run || '');
                    } else {
                        var msg = res.j && (res.j.message || (res.j.errors && Object.values(res.j.errors)[0][0]));
                        err.textContent = msg || I18N.failed || 'Failed'; err.style.display = 'block';
                    }
                }).catch(function () { mpSubmit.disabled = false; err.textContent = I18N.networkError || 'Network error'; err.style.display = 'block'; });
        });
    })();

    /* ── F7 · OTP checkout gate ───────────────────────────── */
    var openCheckoutOtp;
    (function () {
        var boxes = $$('#au-otp-gate-boxes input');
        var curPhone = '';
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
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CFG.csrf },
                body: JSON.stringify({ phone: phone, store_id: CFG.storeId }),
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d || !d.success) { var e = $('#au-otp-gate-err'); e.textContent = (d && d.message) || I18N.failed || 'Failed'; e.style.display = 'block'; }
            }).catch(function () {});
        }
        openCheckoutOtp = function (phone) {
            curPhone = phone;
            $('#au-otp-gate-phone').textContent = phone;
            boxes.forEach(function (b) { b.value = ''; });
            $('#au-otp-gate-err').style.display = 'none';
            openSheet('checkoutotp');
            setTimeout(function () { if (boxes[0]) boxes[0].focus(); }, 300);
            send(phone);
        };
        var resend = $('#au-otp-gate-resend');
        if (resend) resend.addEventListener('click', function () { $('#au-otp-gate-err').style.display = 'none'; send(curPhone); toast(I18N.sent || 'Sent'); });
        var verify = $('#au-otp-gate-verify');
        if (verify) verify.addEventListener('click', function () {
            var err = $('#au-otp-gate-err'); err.style.display = 'none';
            var c = code();
            if (c.length < 4) { err.textContent = I18N.enter_code || 'Enter the code'; err.style.display = 'block'; return; }
            verify.disabled = true;
            fetch(EP.otpVerify2, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CFG.csrf },
                body: JSON.stringify({ phone: curPhone, code: c, store_id: CFG.storeId }),
            }).then(function (r) { return r.json(); }).then(function (d) {
                verify.disabled = false;
                if (d && d.success) {
                    closeSheet('checkoutotp');
                    var f = $('#au-order-form');
                    if (f) { if (f.requestSubmit) f.requestSubmit(); else f.dispatchEvent(new Event('submit', { cancelable: true })); }
                } else { err.textContent = (d && d.message) || 'Invalid code'; err.style.display = 'block'; }
            }).catch(function () { verify.disabled = false; err.textContent = I18N.networkError || 'Network error'; err.style.display = 'block'; });
        });
    })();

    /* branchmap / review / mealplan / checkoutotp scrim close */
    ['branchmap', 'review', 'mealplan', 'checkoutotp'].forEach(function (n) {
        var scrim = $('#au-' + n + '-scrim');
        if (scrim) scrim.addEventListener('click', function () { closeSheet(n); });
    });
})();

/* ── AURORA 3.0 · bottom dock wiring — thin launchers over existing controls ── */
(function () {
    var dock = document.getElementById('auDock');
    if (!dock) return;
    function on(btn) {
        dock.querySelectorAll('.au2-dock-btn').forEach(function (b) { b.classList.remove('is-on'); });
        btn.classList.add('is-on');
    }
    dock.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-au2dock]');
        if (!btn) return;
        var act = btn.getAttribute('data-au2dock');
        if (act === 'home') {
            /* Favourites is a filter (favMode), not a view — Home must leave it, or the
               grid stays stuck showing only favourites. The topbar heart carries an
               inline colour while favMode is on; clicking it toggles favMode back off. */
            var ft = document.getElementById('au-fav-toggle');
            if (ft && ft.style.color) ft.click();
            window.scrollTo({ top: 0, behavior: 'smooth' });
            on(btn);
        }
        else if (act === 'menu') {
            var m = document.querySelector('[data-mb-grid-lead]') || document.getElementById('au-menu-root');
            if (m) m.scrollIntoView({ behavior: 'smooth', block: 'start' });
            on(btn);
        }
        else if (act === 'search') {
            var inp = document.getElementById('au-search-input');
            if (inp) {
                inp.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(function () { inp.focus(); }, 350);
            }
            on(btn);
        }
        else if (act === 'cart') {
            var t = document.getElementById('au-cart-toggle');
            if (t) t.click();
        }
        else if (act === 'favs') {
            var f = document.getElementById('au-fav-toggle');
            if (f) f.click();
        }
        else if (act === 'profile') {
            var p = document.querySelector('#au-account-menu .au-icon-btn') || document.getElementById('au-login-toggle');
            if (p) p.click();
        }
    });
    var src = document.getElementById('au-cart-count');
    var dst = document.getElementById('auDockCartCount');
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
