// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* =========================================================================
   VIVID — Vibrant Interactive Menu Template Behaviour
   Vanilla JS (jQuery available from layout). Handles:
   - Sticky header shrink + scroll-spy category nav
   - Hero parallax + ambient glow drift
   - Search and multi-axis dietary/allergen/spice filters
   - Scroll-reveal staggered card animations
   - Favorites with localStorage persistence
   - Item detail bottom-sheet (variants, extras, allergies, nutrition)
   - Cart with localStorage persistence + resume banner
   - Checkout (order type segmentation, tips, scheduling, coupons)
   - Theme toggle (light/dark), language switcher, branch selector
   - Call waiter / request bill
   - Login/logout/account dropdown
   ========================================================================= */
(function () {
    'use strict';

    var CFG = window.VIVID || {};
    var ITEMS = CFG.items || {};
    var CART_KEY = 'vd_cart_' + (CFG.restaurantSlug || 'default');
    var FAV_KEY  = 'vd_fav_'  + (CFG.restaurantSlug || 'default');
    var THEME_KEY = 'vd_theme_' + (CFG.restaurantSlug || 'default');

    /* ─── tiny DOM helpers ─── */
    var $  = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : (CFG.csrf || '');
    }

    /* ─── item-event analytics (view / add_to_cart) ─── */
    function sendItemEvent(menuId, type) {
        var url = CFG.endpoints && CFG.endpoints.itemEvent;
        if (!url || !menuId) return;
        try {
            var data = new FormData();
            data.append('menu_id', menuId);
            data.append('type', type);
            data.append('_token', csrfToken());
            if (navigator.sendBeacon) {
                navigator.sendBeacon(url, data);
            } else {
                fetch(url, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(function () {});
            }
        } catch (e) {}
    }

    /* ─── money formatting ─── */
    /* Decimals follow the DISPLAYED currency: the store's own code uses the
       server-supplied count, any converted code uses the shared map (else 2). */
    function decimalsFor(code) {
        var dp;
        if (!code || code === CFG.baseCurrency) dp = CFG.currencyDecimals;
        else dp = (CFG.decimalsByCode || {})[code];
        return dp == null ? 2 : dp;
    }

    function money(amount) {
        var str = (Number(amount) || 0).toFixed(decimalsFor(null));
        /* currencyPos is currency_pos_effective(): "1" = symbol BEFORE the number
           (sign-first), "0" = symbol after. The old test compared against 'right',
           which the "1"/"0" sentinel never matched, so every store rendered
           sign-first — wrong for symbol-after stores (RTL masked it). Match picnic/feast. */
        return CFG.currencyPos === '1'
            ? CFG.currencySign + (CFG.currencyGap || '') + str
            : str + (CFG.currencyGap || '') + CFG.currencySign;
    }

    /* ─── localStorage helpers ─── */
    function readJSON(key, fallback) {
        try { return JSON.parse(localStorage.getItem(key)) || fallback; }
        catch (e) { return fallback; }
    }
    function writeJSON(key, val) { try { localStorage.setItem(key, JSON.stringify(val)); } catch (e) {} }

    /* ─── cart state ─── */
    var rawCart = readJSON(CART_KEY, null);
    var cart = [];
    var showResume = false;

    if (rawCart && rawCart.items && Array.isArray(rawCart.items)) {
        if (Date.now() - (rawCart.timestamp || 0) < 86400000) {
            var valid = rawCart.items.filter(function (i) { return ITEMS[i.id] && !ITEMS[i.id].is_sold_out; });
            if (valid.length) { cart = valid; showResume = true; }
            else { try { localStorage.removeItem(CART_KEY); } catch (e) {} }
        } else { try { localStorage.removeItem(CART_KEY); } catch (e) {} }
    }

    var favorites = readJSON(FAV_KEY, []);

    /* ─── active filters ─── */
    var activeCat = 'all';
    var activeSub = 'root'; /* two-tier nav: 'root' = whole parent, or a subcategory id */
    var activeFilters = { vegan: false, halal: false, gf: false, popular: false };
    var activeSpice = [];
    var excludedAllergens = [];
    var searchQuery = '';

    /* ══════════════════════════ THEME TOGGLE ══════════════════════════ */
    (function initTheme() {
        /* Persistence + the data-theme attribute (on the .vd root, no longer
           data-vd-theme on <html>) are delegated to the shared engine
           (_shared/js/mb-theme.js, key mb_theme_<store-id>); the legacy
           vd_theme_<slug> key is a read-only fallback so existing diners keep
           their choice. Auto mode (nothing saved) now follows the OS via the
           prefers-color-scheme block in style.css. */
        var mbT = window.mbTheme || null;
        var vdRoot = document.querySelector('.vd');
        if (mbT) mbT.init({ rootSel: '.vd', legacyKeys: [THEME_KEY] });
        else if (vdRoot) {
            var saved = null;
            try { saved = localStorage.getItem(THEME_KEY); } catch (e) {}
            if (saved === 'dark' || saved === 'light') vdRoot.setAttribute('data-theme', saved);
        }

        function isDark() {
            if (mbT) return mbT.effective() === 'dark';
            if (vdRoot && vdRoot.getAttribute('data-theme')) return vdRoot.getAttribute('data-theme') === 'dark';
            return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        }

        var toggle = $('#vd-theme-toggle');
        var icon = $('#vd-theme-icon');
        function syncIcon() { if (!icon) return; icon.className = 'ico ico-' + (isDark() ? 'sun' : 'moon'); icon.innerHTML = isDark() ? "<svg width=\"1em\" height=\"1em\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.9\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\" focusable=\"false\"><path d=\"M8 12a4 4 0 1 0 8 0a4 4 0 1 0 -8 0\"/><path d=\"M3 12h1m8 -9v1m8 8h1m-9 8v1m-6.4 -15.4l.7 .7m12.1 -.7l-.7 .7m0 11.4l.7 .7m-12.1 -.7l-.7 .7\"/></svg>" : "<svg width=\"1em\" height=\"1em\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.9\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\" focusable=\"false\"><path d=\"M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446a9 9 0 1 1 -8.313 -12.454l0 .008\"/></svg>"; }
        if (toggle) {
            toggle.addEventListener('click', function () {
                var next = isDark() ? 'light' : 'dark';
                if (mbT) mbT.set(next);
                else if (vdRoot) {
                    vdRoot.setAttribute('data-theme', next);
                    try { localStorage.setItem(THEME_KEY, next); } catch (e) {}
                }
                syncIcon();
            });
            syncIcon();
        }
    })();

    /* ══════════════════════════ STICKY HEADER ═════════════════════════ */
    var topbar = $('#vd-topbar');
    function onScroll() {
        if (topbar) topbar.classList.toggle('is-stuck', window.pageYOffset > 40);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    /* ══════════════════════════ SPLASH ════════════════════════════════ */
    (function initSplash() {
        var splash = $('#vd-splash');
        if (!splash) return;
        function dismiss() {
            splash.classList.add('vd-splash-out');
            setTimeout(function () { if (splash && splash.parentNode) splash.parentNode.removeChild(splash); }, 600);
        }
        /* R2 · session-gate: the splash shows ONCE per browser session, not on every
           navigation. If it has already been seen this session, tear it down
           immediately — before the CSS auto-fade can even flash it — otherwise mark
           it seen and let the normal timed dismiss run. */
        var SPLASH_KEY = 'vd_splash_' + (CFG.restaurantSlug || 'default');
        var seenSplash = false;
        try { seenSplash = !!sessionStorage.getItem(SPLASH_KEY); } catch (e) {}
        if (seenSplash) {
            splash.style.display = 'none';
            if (splash.parentNode) splash.parentNode.removeChild(splash);
            return;
        }
        /* A1: a ?item=/?table= deep link opens straight on the product — tear the
           splash down up front so it never overlays it (splash z-index sits above
           the sheet). Not persisted: a later plain visit this session still gets it. */
        var deepLinked = false;
        try { var _dl = new URLSearchParams(location.search); deepLinked = !!(_dl.get('item') || _dl.get('table')); } catch (e) {}
        if (deepLinked) { if (splash.parentNode) splash.parentNode.removeChild(splash); return; }
        try { sessionStorage.setItem(SPLASH_KEY, '1'); } catch (e) {}
        var skip = $('#vd-splash-skip');
        if (skip) skip.addEventListener('click', dismiss);
        /* pure-CSS auto-fade drives the visual dismissal; this cleans the node up */
        setTimeout(dismiss, 2200);
    })();

    /* ══════════════════════════ HERO PARALLAX ════════════════════════ */
    (function heroParallax() {
        var cover = $('#vd-hero-cover');
        var hero = $('#vd-hero');
        if (!cover || !hero) return;

        var ticking = false;
        function update() {
            var rect = hero.getBoundingClientRect();
            if (rect.bottom < 0) { ticking = false; return; }
            var offset = Math.max(0, -rect.top) * 0.35;
            cover.style.transform = 'translateY(' + offset + 'px) scale(1.08)';
            cover.style.opacity = Math.max(0.2, 1 - Math.max(0, -rect.top) / 400);
            ticking = false;
        }

        window.addEventListener('scroll', function () {
            if (!ticking) {
                requestAnimationFrame(update);
                ticking = true;
            }
        }, { passive: true });
    })();

    /* ══════════════════════════ CATEGORY NAV + SCROLL SPY ═════════════ */
    var catPills = $$('.vd-cat-pill');
    var indicator = $('#vd-cat-indicator');
    var catSections = $$('.vd-cat-section');

    function moveIndicator(pill) {
        if (!pill || !indicator) return;
        indicator.style.width = pill.offsetWidth + 'px';
        indicator.style.transform = 'translateX(' + pill.offsetLeft + 'px)';
    }

    /* ─── two-tier sub-category nav ─── */
    var subnav = $('#vd-subnav');

    /* Reveal the active parent's sub-pill row (if any), reset its selection to
       "All", and hide every other group. Called on each parent change. */
    function syncSubPills() {
        if (!subnav) return;
        var parentId = (activeCat !== 'all') ? activeCat.replace('cat-', '') : null;
        var shown = null;
        $$('.vd-subgroup', subnav).forEach(function (g) {
            var match = parentId !== null && g.getAttribute('data-parent') === parentId;
            g.hidden = !match;
            if (match) {
                shown = g;
                $$('.vd-sub-pill', g).forEach(function (c) {
                    c.classList.toggle('active', c.getAttribute('data-sub') === 'root');
                });
            }
        });
        subnav.hidden = !shown;
    }

    catPills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            catPills.forEach(function (p) { p.classList.remove('active'); });
            pill.classList.add('active');
            moveIndicator(pill);

            activeCat = pill.getAttribute('data-target');
            activeSub = 'root';
            syncSubPills();
            applySearchTerm();
        });
    });
    moveIndicator($('.vd-cat-pill.active'));

    /* secondary sub-category pills — narrow within the active parent */
    $$('.vd-sub-pill').forEach(function (sp) {
        sp.addEventListener('click', function () {
            activeSub = sp.getAttribute('data-sub');
            var grp = sp.closest('.vd-subgroup');
            if (grp) $$('.vd-sub-pill', grp).forEach(function (c) { c.classList.toggle('active', c === sp); });
            applySearchTerm();
        });
    });

    /* scroll-spy: highlight nav pill on section enter */
    (function scrollSpy() {
        if (!catSections.length) return;
        var links = {};
        catPills.forEach(function (p) { links[p.getAttribute('data-target')] = p; });

        var spy = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var id = entry.target.id;
                    var pill = links['cat-' + entry.target.getAttribute('data-cat')] || links['all'];
                    /* use data-cat on the section */
                    var matchPill = $('[data-target="cat-' + entry.target.getAttribute('data-cat') + '"]');
                    if (matchPill) {
                        catPills.forEach(function (p) { p.classList.remove('active'); });
                        matchPill.classList.add('active');
                        moveIndicator(matchPill);
                    }
                }
            });
        }, { rootMargin: '-30% 0px -60% 0px', threshold: 0 });

        catSections.forEach(function (s) { spy.observe(s); });
    })();

    /* ══════════════════════════ SEARCH ════════════════════════════════ */
    var searchInput = $('#vd-search-input');
    var searchClear = $('#vd-search-clear');

    function applySearchTerm() {
        var term = searchQuery;
        var anyVisible = false;

        /* Section-based filtering (mirrors aurora). matchCat keys off the SECTION's
           data-cat = PARENT id, so a parent pill keeps every nested subcategory card;
           matchSub narrows to one subcategory by the card's OWN data-cat. */
        catSections.forEach(function (section) {
            var catId = section.getAttribute('data-cat');
            var matchCat = activeCat === 'all' || ('cat-' + catId === activeCat);
            var sectionVisible = false;

            $$('[data-item-card]', section).forEach(function (card) {
                var name = (card.getAttribute('data-name') || '').toLowerCase();
                var desc = (card.getAttribute('data-desc') || '').toLowerCase();
                var matchSearch = !term || name.includes(term) || desc.includes(term);
                var matchSub = activeSub === 'root' || card.getAttribute('data-cat') === activeSub;
                var matchFilters = checkFilters(card);

                var visible = matchCat && matchSub && matchSearch && matchFilters;
                card.style.display = visible ? '' : 'none';
                if (visible) { sectionVisible = true; anyVisible = true; }
            });

            section.style.display = sectionVisible ? '' : 'none';
            /* drop a whole subcategory wrapper (its label + grid) when it has no
               visible cards — keeps sub-headings from stranding under narrowing/search */
            $$('.vd-subwrap', section).forEach(function (wrap) {
                var vis = $$('[data-item-card]', wrap).some(function (c) { return c.style.display !== 'none'; });
                wrap.style.display = vis ? '' : 'none';
            });
        });

        /* specials rail only on the default unfiltered view */
        var specials = $('#vd-specials-section');
        var hasFilters = activeFilters.vegan || activeFilters.halal || activeFilters.gf ||
                         activeFilters.popular || activeSpice.length || excludedAllergens.length;
        if (specials) specials.style.display = (activeCat === 'all' && !term && !hasFilters) ? '' : 'none';

        var empty = $('#vd-empty');
        if (empty) empty.classList.toggle('show', !anyVisible);
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            searchQuery = this.value.toLowerCase().trim();
            if (searchClear) searchClear.style.display = searchQuery ? '' : 'none';
            applySearchTerm();
        });
    }
    if (searchClear) {
        searchClear.addEventListener('click', function () {
            if (searchInput) searchInput.value = '';
            searchQuery = '';
            searchClear.style.display = 'none';
            if (searchInput) searchInput.focus();
            applySearchTerm();
        });
    }

    /* ══════════════════════════ DIETARY FILTERS ═══════════════════════ */
    var filtersPanel = $('#vd-filters-panel');
    var filtersToggle = $('#vd-filters-toggle');

    if (filtersToggle && filtersPanel) {
        filtersToggle.addEventListener('click', function () {
            filtersPanel.classList.toggle('open');
            filtersToggle.classList.toggle('active');
        });
    }

    function checkFilters(card) {
        if (activeFilters.vegan && card.getAttribute('data-vegan') !== '1') return false;
        if (activeFilters.halal && card.getAttribute('data-halal') !== '1') return false;
        if (activeFilters.gf && card.getAttribute('data-gf') !== '1') return false;
        if (activeFilters.popular && card.getAttribute('data-popular') !== '1') return false;
        if (activeSpice.length) {
            var spice = parseInt(card.getAttribute('data-spice') || '0', 10);
            if (activeSpice.indexOf(spice) === -1) return false;
        }
        if (excludedAllergens.length) {
            var cardAllergens = (card.getAttribute('data-allergens') || '').split(',').filter(Boolean).map(function (s) { return s.trim(); });
            for (var i = 0; i < excludedAllergens.length; i++) {
                if (cardAllergens.indexOf(String(excludedAllergens[i])) !== -1) return false;
            }
        }
        return true;
    }

    $$('.vd-filter-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var filter = chip.getAttribute('data-filter');
            var allergenId = chip.getAttribute('data-allergen-id');

            if (allergenId) {
                /* allergen exclusion toggle */
                var idx = excludedAllergens.indexOf(allergenId);
                if (idx === -1) { excludedAllergens.push(allergenId); chip.classList.add('active'); }
                else { excludedAllergens.splice(idx, 1); chip.classList.remove('active'); }
            } else if (filter && filter.indexOf('spice-') === 0) {
                /* spice level toggle */
                var lvl = parseInt(filter.replace('spice-', ''), 10);
                var sIdx = activeSpice.indexOf(lvl);
                if (sIdx === -1) { activeSpice.push(lvl); chip.classList.add('active'); }
                else { activeSpice.splice(sIdx, 1); chip.classList.remove('active'); }
            } else if (filter) {
                /* boolean dietary toggle */
                activeFilters[filter] = !activeFilters[filter];
                chip.classList.toggle('active', activeFilters[filter]);
            }
            applySearchTerm();
        });
    });

    /* clear all filters */
    var clearBtn = $('#vd-filter-clear');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            activeFilters = { vegan: false, halal: false, gf: false, popular: false };
            activeSpice = [];
            excludedAllergens = [];
            $$('.vd-filter-chip').forEach(function (c) { c.classList.remove('active'); });
            applySearchTerm();
        });
    }

    /* ══════════════════════════ SCROLL REVEAL ════════════════════════ */
    var revealObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('vd-reveal--in');
                revealObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

    $$('.vd-reveal').forEach(function (el) { revealObserver.observe(el); });
    $$('.vd-card').forEach(function (el) { revealObserver.observe(el); });

    /* ══════════════════════════ FAVORITES ═════════════════════════════ */
    function updateFavUI() {
        $$('[data-fav]').forEach(function (btn) {
            var id = btn.getAttribute('data-fav');
            btn.classList.toggle('is-active', favorites.indexOf(id) !== -1);
        });
        var fc = $('#vd-fav-count');
        if (fc) fc.textContent = favorites.length;
    }

    $$('[data-fav]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var id = btn.getAttribute('data-fav');
            var idx = favorites.indexOf(id);
            if (idx === -1) { favorites.push(id); }
            else { favorites.splice(idx, 1); }
            writeJSON(FAV_KEY, favorites);
            updateFavUI();
        });
    });

    /* Favorites SHEET — vivid has hearts on items but nowhere to view the saved
       list, so the topbar heart was a dead counter. Both it and the dock button
       now open this. */
    function renderFavSheet() {
        var list = document.getElementById('vd-fav-list');
        var empty = document.getElementById('vd-fav-empty');
        if (!list) return;
        var ids = favorites.filter(function (id) { return ITEMS[id]; });
        if (!ids.length) { list.innerHTML = ''; if (empty) empty.style.display = ''; return; }
        if (empty) empty.style.display = 'none';
        list.innerHTML = ids.map(function (id) {
            var it = ITEMS[id];
            return '<button class="vd-fav-row" type="button" data-open-item="' + id + '">' +
                (it.img ? '<img class="vd-fav-row-img" src="' + fEsc(it.img) + '" alt=""' + IMG_ERR + '>' : '<span class="vd-fav-row-img vd-noimg"></span>') +
                '<span class="vd-fav-row-body"><span class="vd-fav-row-name">' + fEsc(it.name) + '</span>' +
                '<span class="vd-fav-row-price">' + money(it.price) + '</span></span>' +
                '<i class="ico ico-chevron vd-fav-row-arr" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 6 6 6-6 6"/></svg></i>' +
                '</button>';
        }).join('');
    }
    function openFavSheet() { renderFavSheet(); openSheet('vd-fav-sheet'); }
    var vdFavToggle = document.getElementById('vd-fav-toggle');
    if (vdFavToggle) vdFavToggle.addEventListener('click', openFavSheet);
    var vdFavList = document.getElementById('vd-fav-list');
    if (vdFavList) vdFavList.addEventListener('click', function (e) {
        if (e.target.closest('[data-open-item]')) closeSheet('vd-fav-sheet');
    });

    /* ══════════════════════════ SHEET MANAGEMENT ══════════════════════ */
    function openSheet(sheetId) {
        var sheet = document.getElementById(sheetId);
        if (!sheet) return;
        sheet.setAttribute('aria-hidden', 'false');
        sheet.classList.add('open');
        document.body.style.overflow = 'hidden';

        var scrimId = sheetId.replace('vd-', 'vd-').replace('-sheet', '-scrim');
        var scrim = document.getElementById(scrimId);
        if (!scrim) {
            /* fallback: find matching scrim by convention */
            if (sheetId === 'vd-item-sheet') scrim = $('#vd-item-scrim');
            else if (sheetId === 'vd-cart-sheet') scrim = $('#vd-cart-scrim');
            else if (sheetId === 'vd-waiter-sheet') scrim = $('#vd-waiter-scrim');
            else {
                /* generic: create ephemeral scrim */
                scrim = document.createElement('div');
                scrim.className = 'vd-scrim';
                sheet.parentNode.insertBefore(scrim, sheet);
            }
        }
        if (scrim) scrim.classList.add('open');
    }

    function closeSheet(sheetId) {
        var sheet = document.getElementById(sheetId);
        if (!sheet) return;
        sheet.setAttribute('aria-hidden', 'true');
        sheet.classList.remove('open');

        var scrim = document.getElementById(sheetId.replace('-sheet', '-scrim'));
        if (scrim) scrim.classList.remove('open');

        /* release body lock if no sheets open */
        var anyOpen = $$('.vd-sheet.open').length > 0;
        if (!anyOpen) document.body.style.overflow = '';
    }

    function closeAllSheets() {
        $$('.vd-sheet.open').forEach(function (s) {
            s.setAttribute('aria-hidden', 'true');
            s.classList.remove('open');
        });
        $$('.vd-scrim.open').forEach(function (s) { s.classList.remove('open'); });
        document.body.style.overflow = '';
    }

    /* close sheet buttons */
    $$('[data-close-sheet]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeSheet('vd-' + btn.getAttribute('data-close-sheet') + '-sheet');
        });
    });

    /* scrim clicks */
    $$('.vd-scrim').forEach(function (scrim) {
        scrim.addEventListener('click', function () {
            /* find nearest open sheet and close it */
            $$('.vd-sheet.open').forEach(function (s) {
                s.setAttribute('aria-hidden', 'true');
                s.classList.remove('open');
            });
            scrim.classList.remove('open');
            document.body.style.overflow = '';
        });
    });

    /* reservation / waitlist sheets */
    var _resOpen = document.getElementById('vd-res-open');
    if (_resOpen) _resOpen.addEventListener('click', function () { openSheet('vd-res-sheet'); });
    var _wlOpen = document.getElementById('vd-waitlist-open');
    if (_wlOpen) _wlOpen.addEventListener('click', function () { openSheet('vd-waitlist-sheet'); });
    if (window.VIVID && VIVID.openResSheet && document.getElementById('vd-res-sheet')) openSheet('vd-res-sheet');
    if (window.VIVID && VIVID.openWaitlistSheet && document.getElementById('vd-waitlist-sheet')) openSheet('vd-waitlist-sheet');

    /* loyalty stamp / points card */
    (function () {
        var block = $('#vd-loyalty');
        if (!block || !CFG.endpoints || !CFG.endpoints.loyaltyBalance) return;
        var type = CFG.loyaltyType;
        function paint(d) {
            var bal = d.balance || 0;
            var balEl = $('#vd-loyalty-balance'); if (balEl) balEl.textContent = bal;
            if (type === 'stamps') {
                /* --vd-brand is not a vivid token; the filled stamp was painted a
                   borrowed amber that ignored the restaurant's accent. Styling
                   moved to .vd-stamp-dot.is-on in style.css. */
                $$('#vd-stamp-dots .vd-stamp-dot').forEach(function (dot, i) {
                    dot.classList.toggle('is-on', i < bal);
                });
            } else {
                var bar = $('#vd-loyalty-bar'); if (bar) bar.style.inlineSize = Math.min(100, d.progress_pct || 0) + '%';
            }
            var rb = $('#vd-loyalty-redeem'); if (rb) rb.style.display = d.ready_to_redeem ? '' : 'none';
        }
        fetch(CFG.endpoints.loyaltyBalance).then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.active) paint(d); }).catch(function () {});
        var rb = $('#vd-loyalty-redeem');
        if (rb) rb.addEventListener('click', function () {
            rb.disabled = true;
            fetch(CFG.endpoints.loyaltyRedeem, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CFG.csrf }, body: '{}' })
                .then(function (r) { return r.json(); }).then(function (d) {
                    if (d && d.success) { rb.style.display = 'none'; $('#vd-loyalty-ok').style.display = ''; paint({ balance: d.new_balance, progress_pct: 0, ready_to_redeem: false }); }
                    else { rb.disabled = false; }
                }).catch(function () { rb.disabled = false; });
        });
    })();

    /* ESC key */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllSheets();
    });

    /* ══════════════════════════ ITEM DETAIL SHEET ═════════════════════ */
    var currentItem = null;
    var currentSheet = null;

    function renderDetail(itemId) {
        var item = ITEMS[itemId];
        if (!item) return;
        currentItem = item;
        sendItemEvent(item.id, 'view');

        var body = $('#vd-item-body');
        var foot = $('#vd-item-foot');
        if (!body) return;

        var hasVariants = item.variants && item.variants.length > 0;
        var hasExtras = item.extras && item.extras.length > 0;
        var customizable = hasVariants || hasExtras;

        /* nutrition block */
        var nutritionHtml = '';
        if (item.calories || item.protein || item.carbs || item.fat) {
            var parts = [];
            if (item.calories) parts.push('<span><i class="ico ico-flame" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 21a7 7 0 0 0 7-7c0-4-3-6-4-9-1.5 2-2 3.5-2 5-1-1-2-2-2.5-4C8 8 5 10.5 5 14a7 7 0 0 0 7 7z"/></svg></i> ' + item.calories + ' kcal</span>');
            if (item.protein)  parts.push('<span><i class="ico ico-meat" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M13.62 8.382l1.966 -1.967a2 2 0 1 1 3.414 -1.415a2 2 0 1 1 -1.413 3.414l-1.82 1.821"/><path d="M5.904 18.596c2.733 2.734 5.9 4 7.07 2.829c1.172 -1.172 -.094 -4.338 -2.828 -7.071c-2.733 -2.734 -5.9 -4 -7.07 -2.829c-1.172 1.172 .094 4.338 2.828 7.071"/><path d="M7.5 16l1 1"/><path d="M12.975 21.425c3.905 -3.906 4.855 -9.288 2.121 -12.021c-2.733 -2.734 -8.115 -1.784 -12.02 2.121"/></svg></i> ' + item.protein + 'g protein</span>');
            if (item.carbs)    parts.push('<span><i class="ico ico-bread" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 4a3 3 0 0 1 2 5.235v8.765a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-8.764a3 3 0 0 1 1.824 -5.231h12.176v-.005"/></svg></i> ' + item.carbs + 'g carbs</span>');
            if (item.fat)      parts.push('<span><i class="ico ico-meat" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M13.62 8.382l1.966 -1.967a2 2 0 1 1 3.414 -1.415a2 2 0 1 1 -1.413 3.414l-1.82 1.821"/><path d="M5.904 18.596c2.733 2.734 5.9 4 7.07 2.829c1.172 -1.172 -.094 -4.338 -2.828 -7.071c-2.733 -2.734 -5.9 -4 -7.07 -2.829c-1.172 1.172 .094 4.338 2.828 7.071"/><path d="M7.5 16l1 1"/><path d="M12.975 21.425c3.905 -3.906 4.855 -9.288 2.121 -12.021c-2.733 -2.734 -8.115 -1.784 -12.02 2.121"/></svg></i> ' + item.fat + 'g fat</span>');
            nutritionHtml = '<div class="vd-detail-nutrition">' + parts.join('') + '</div>';
        }

        /* allergies block */
        var allergiesHtml = '';
        if (item.allergies && item.allergies.length) {
            var chips = item.allergies.map(function (a) {
                return '<span class="vd-detail-allergen">' +
                    (a.img ? '<img src="' + fEsc(a.img) + '" alt="' + fEsc(a.title) + '" data-csp-error="hide">' : '') +
                    '<span>' + fEsc(a.title) + '</span></span>';
            }).join('');
            allergiesHtml = '<div class="vd-detail-allergies">' + chips + '</div>';
        }

        /* variants */
        var variantsHtml = '';
        if (hasVariants) {
            var vopts = item.variants.map(function (v, i) {
                return '<label class="vd-option' + (i === 0 ? ' selected' : '') + '">' +
                    '<input type="radio" name="vd-detail-variant" value="' + v.id + '" data-price="' + v.price + '" data-label="' + fEsc(v.label || '') + '"' + (i === 0 ? ' checked' : '') + '>' +
                    '<span class="vd-option-info"><span class="vd-option-label">' + fEsc(v.label || '') + '</span><span class="vd-option-price">' + money(v.price) + '</span></span>' +
                    '<span class="vd-option-check"><i class="ico ico-check" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></i></span>' +
                '</label>';
            }).join('');
            variantsHtml = '<div class="vd-detail-section vd-detail-variants">' +
                '<h4>' + CFG.i18n.customize + '</h4>' +
                '<div class="vd-options-list">' + vopts + '</div></div>';
        }

        /* extras */
        var extrasHtml = '';
        if (hasExtras) {
            var eopts = item.extras.map(function (e) {
                return '<label class="vd-option vd-option-toggle">' +
                    '<input type="checkbox" data-extra-id="' + e.id + '" data-price="' + e.price + '" data-label="' + fEsc(e.title || '') + '">' +
                    (e.img ? '<img class="vd-option-thumb" src="' + fEsc(e.img) + '" alt="' + fEsc(e.title || '') + '" data-csp-error="hide">' : '') +
                    '<span class="vd-option-info"><span class="vd-option-label">' + fEsc(e.title || '') + '</span>' +
                    '<span class="vd-option-price">+' + money(e.price) + '</span></span>' +
                    '<span class="vd-option-check"><i class="ico ico-check" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></i></span>' +
                '</label>';
            }).join('');
            extrasHtml = '<div class="vd-detail-section vd-detail-extras">' +
                '<h4>' + CFG.i18n.extras + '</h4>' +
                '<div class="vd-options-list">' + eopts + '</div></div>';
        }

        /* global option groups */
        var groupsHtml = '';
        (item.optionGroups || []).forEach(function (g) {
            var single = g.max === 1;
            var ruleTxt = single ? (g.required ? ' *' : '') : ' (' + (g.min || 0) + (g.max ? '-' + g.max : '+') + ')';
            var rows = g.options.map(function (o, oi) {
                var preset = single && g.required && oi === 0;
                var input = single
                    ? '<input type="radio" name="vd-group-' + g.id + '" data-group="' + g.id + '" data-opt="' + o.i + '" data-gmax="1" data-price="' + o.price + '" data-label="' + fEsc(o.label || '') + '"' + (preset ? ' checked' : '') + '>'
                    : '<input type="checkbox" data-group="' + g.id + '" data-opt="' + o.i + '" data-gmax="' + (g.max || 0) + '" data-price="' + o.price + '" data-label="' + fEsc(o.label || '') + '">';
                return '<label class="vd-option' + (preset ? ' selected' : '') + '">' + input +
                    '<span class="vd-option-info"><span class="vd-option-label">' + fEsc(o.label || '') + '</span>' +
                    '<span class="vd-option-price">' + (o.price > 0 ? '+' + money(o.price) : '') + '</span></span>' +
                    '<span class="vd-option-check"><i class="ico ico-check" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></i></span></label>';
            }).join('');
            groupsHtml += '<div class="vd-detail-section"><h4>' + fEsc(g.title) + ruleTxt + '</h4><div class="vd-options-list">' + rows + '</div></div>';
        });

        /* badges */
        var badges = '';
        if (item.type === 'veg') badges += '<span class="vd-badge vd-badge-veg"><i class="ico ico-circle-filled" style="font-size:7px" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false"><path d="M7 3.34a10 10 0 1 1 -4.995 8.984l-.005 -.324l.005 -.324a10 10 0 0 1 4.995 -8.336z"/></svg></i>' + 'Veg' + '</span>';
        else badges += '<span class="vd-badge vd-badge-nonveg"><i class="ico ico-circle-filled" style="font-size:7px" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false"><path d="M7 3.34a10 10 0 1 1 -4.995 8.984l-.005 -.324l.005 -.324a10 10 0 0 1 4.995 -8.336z"/></svg></i>' + 'Non Veg' + '</span>';
        if (item.is_vegan) badges += '<span class="vd-badge vd-badge-vegan"><i class="ico ico-leaf" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 21c.5 -4.5 2.5 -8 7 -10"/><path d="M9 18c6.218 0 10.5 -3.288 11 -12v-2h-4.014c-9 0 -11.986 4 -12 9c0 1 0 3 2 5h3l.014 0"/></svg></i> Vegan</span>';
        if (item.is_halal) badges += '<span class="vd-badge vd-badge-halal"><i class="ico ico-rosette-discount-check" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 7.2a2.2 2.2 0 0 1 2.2 -2.2h1a2.2 2.2 0 0 0 1.55 -.64l.7 -.7a2.2 2.2 0 0 1 3.12 0l.7 .7c.412 .41 .97 .64 1.55 .64h1a2.2 2.2 0 0 1 2.2 2.2v1c0 .58 .23 1.138 .64 1.55l.7 .7a2.2 2.2 0 0 1 0 3.12l-.7 .7a2.2 2.2 0 0 0 -.64 1.55v1a2.2 2.2 0 0 1 -2.2 2.2h-1a2.2 2.2 0 0 0 -1.55 .64l-.7 .7a2.2 2.2 0 0 1 -3.12 0l-.7 -.7a2.2 2.2 0 0 0 -1.55 -.64h-1a2.2 2.2 0 0 1 -2.2 -2.2v-1a2.2 2.2 0 0 0 -.64 -1.55l-.7 -.7a2.2 2.2 0 0 1 0 -3.12l.7 -.7a2.2 2.2 0 0 0 .64 -1.55v-1"/><path d="M9 12l2 2l4 -4"/></svg></i> Halal</span>';
        if (item.is_gluten_free) badges += '<span class="vd-badge vd-badge-gf"><i class="ico ico-wheat" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12.014 21.514v-3.75"/><path d="M5.93 9.504l-.43 1.604c-.712 2.659 .866 5.391 3.524 6.105c.997 .268 1.993 .535 2.99 .801v-3.44c-.164 -2.105 -1.637 -3.879 -3.676 -4.426l-2.408 -.644"/><path d="M13.744 11.164c.454 -.454 .815 -.994 1.061 -1.587c.246 -.594 .372 -1.23 .372 -1.873c0 -.643 -.126 -1.279 -.372 -1.872c-.246 -.594 -.606 -1.133 -1.061 -1.588l-1.73 -1.73l-1.73 1.73c-.454 .454 -.815 .994 -1.06 1.588c-.246 .594 -.372 1.23 -.373 1.872c0 .643 .127 1.279 .373 1.873c.246 .594 .606 1.133 1.06 1.587"/><path d="M18.099 9.504l.43 1.604c.712 2.659 -.866 5.391 -3.525 6.105c-.997 .268 -1.994 .535 -2.99 .801v-3.44c.164 -2.105 1.637 -3.879 3.677 -4.426l2.408 -.644"/></svg></i> GF</span>';
        if (item.is_popular) badges += '<span class="vd-badge vd-badge-popular"><i class="ico ico-flame" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 21a7 7 0 0 0 7-7c0-4-3-6-4-9-1.5 2-2 3.5-2 5-1-1-2-2-2.5-4C8 8 5 10.5 5 14a7 7 0 0 0 7 7z"/></svg></i> Popular</span>';
        if (item.spice_level) {
            var peppers = '';
            for (var s = 0; s < item.spice_level; s++) peppers += '🌶️';
            badges += '<span class="vd-badge vd-badge-spicy">' + peppers + '</span>';
        }

        /* stock info */
        var stockHtml = '';
        if (item.is_sold_out) {
            stockHtml = '<div class="vd-detail-soldout"><i class="ico ico-ban" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M5.7 5.7l12.6 12.6"/></svg></i> ' + CFG.i18n.soldOut + '</div>';
        } else if (item.track_inventory && item.stock_qty !== null && item.stock_qty <= (item.low_stock_threshold || 0) && item.stock_qty > 0) {
            stockHtml = '<div class="vd-detail-lowstock"><i class="ico ico-error" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16v.1"/></svg></i> Only ' + item.stock_qty + ' left</div>';
        }

        /* inline quick-rate — the stars OPEN the shared review sheet preselected
           to that rating, so the rating and any written text go through ONE
           endpoint. This replaced the old stars-only POST to CFG.endpoints.review,
           a second, text-less path that made its own pending review. The shared
           sheet is defined by _partials/item-reviews. */
        /* CFG.endpoints directly, NOT the EP alias: EP is assigned further down
           the IIFE (theme-pack section), but the R7 ?item= boot call runs BEFORE
           that line during evaluation — reading EP.reviewsBase there threw and
           aborted the whole IIFE, leaving every ?item= deep-link page with no
           working JS at all (dead sheet, dead cart). */
        var reviewHtml = (CFG.endpoints || {}).reviewsBase ? ('<div class="vd-detail-section vd-rate-row" id="vd-rate-row">' +
            '<span class="vd-rate-label">' + fEsc(CFG.i18n.rate_this_item || 'Rate this dish') + '</span>' +
            '<span class="vd-rate-stars" id="vd-rateStars">' +
            [1, 2, 3, 4, 5].map(function (n) {
                return '<button type="button" class="vd-rate-star" data-rate="' + n + '" aria-label="' + n + '"><i class="ico ico-star-filled" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false"><path d="M8.243 7.34l-6.38 .925l-.113 .023a1 1 0 0 0 -.44 1.684l4.622 4.499l-1.09 6.355l-.013 .11a1 1 0 0 0 1.464 .944l5.706 -3l5.693 3l.1 .046a1 1 0 0 0 1.352 -1.1l-1.091 -6.355l4.624 -4.5l.078 -.085a1 1 0 0 0 -.633 -1.62l-6.38 -.926l-2.852 -5.78a1 1 0 0 0 -1.794 0l-2.853 5.78z"/></svg></i></button>';
            }).join('') +
            '</span>' +
            '<span class="vd-rate-thanks" id="vd-rateThanks" hidden>' + fEsc(CFG.i18n.thanks || 'Thanks!') + '</span>' +
            '</div>') : '';

        body.innerHTML =
            '<div class="vd-detail-hero">' +
                '<div class="vd-detail-img' + (item.img ? '' : ' vd-noimg') + '">' +
                    (item.img
                        ? (window.mbCarousel ? mbCarousel.html([item.img].concat(item.gallery || []))
                            : '<img src="' + fEsc(item.img) + '" alt="' + fEsc(item.name) + '"' +
                              ' data-csp-error="parent-class" data-csp-class="vd-noimg" data-csp-remove="true">')
                        : '') +
                '</div>' +
                '<div class="vd-detail-badges">' + badges + '</div>' +
            '</div>' +
            '<div class="vd-detail-info">' +
                '<h2 class="vd-detail-title">' + fEsc(item.name) + '</h2>' +
                (item.desc ? '<p class="vd-detail-desc">' + fEsc(item.desc) + '</p>' : '') +
                (item.rating ?
                '<div class="vd-detail-rating">' +
                    '<i class="ico ico-star-filled" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false"><path d="M8.243 7.34l-6.38 .925l-.113 .023a1 1 0 0 0 -.44 1.684l4.622 4.499l-1.09 6.355l-.013 .11a1 1 0 0 0 1.464 .944l5.706 -3l5.693 3l.1 .046a1 1 0 0 0 1.352 -1.1l-1.091 -6.355l4.624 -4.5l.078 -.085a1 1 0 0 0 -.633 -1.62l-6.38 -.926l-2.852 -5.78a1 1 0 0 0 -1.794 0l-2.853 5.78z"/></svg></i> <strong>' + item.rating + '</strong>' +
                    (item.ratingCount ? '<span>(' + item.ratingCount + ')</span>' : '') +
                '</div>' : '') +
                '<div class="vd-detail-price">' + money(item.price) + (customizable ? '<small>' + ' from' + '</small>' : '') + '</div>' +
                stockHtml +
                nutritionHtml +
                allergiesHtml +
            '</div>' +
            variantsHtml +
            extrasHtml +
            groupsHtml +
            reviewHtml;

        /* footer button */
        if (foot) {
            if (item.is_sold_out) {
                foot.style.display = 'none';
            } else {
                foot.style.display = '';
                if (CFG.allowOrder) {
                    /* orbit #pCartJump / aurora #au-cart-jump parity: a direct jump to
                       the cart, shown once anything is already in it, so a shopper
                       does not have to close this sheet and hunt for the bag icon. */
                    var jumpCount = cart.reduce(function (s, c) { return s + c.qty; }, 0) + comboCount();
                    foot.innerHTML =
                        '<div class="vd-detail-qty">' +
                            '<button type="button" id="vd-qty-minus" class="vd-qty-btn"><i class="ico ico-minus" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12l14 0"/></svg></i></button>' +
                            '<span id="vd-qty-val">1</span>' +
                            '<button type="button" id="vd-qty-plus" class="vd-qty-btn"><i class="ico ico-plus" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg></i></button>' +
                        '</div>' +
                        '<button type="button" class="vd-cta" id="vd-detail-add">' +
                            '<i class="ico ico-plus" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg></i> ' + CFG.i18n.add +
                            ' <span class="vd-cta-price" id="vd-detail-total">' + money(item.price) + '</span>' +
                        '</button>' +
                        '<button type="button" class="vd-cart-jump" id="vd-cart-jump"' + (jumpCount === 0 ? ' hidden' : '') + ' aria-label="' + CFG.i18n.view_cart + '">' +
                            '<i class="ico ico-shopping-bag" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6.331 8h11.339a2 2 0 0 1 1.977 2.304l-1.255 8.152a3 3 0 0 1 -2.966 2.544h-6.852a3 3 0 0 1 -2.965 -2.544l-1.255 -8.152a2 2 0 0 1 1.977 -2.304"/><path d="M9 11v-5a3 3 0 0 1 6 0v5"/></svg></i><span class="vd-badge-count show" id="vd-cart-jump-count">' + jumpCount + '</span>' +
                        '</button>';
                } else {
                    /* no ordering — just show price info */
                    foot.style.display = 'none';
                }
            }
        }

        /* wire up variant selection */
        var variantRadios = body.querySelectorAll('input[name="vd-detail-variant"]');
        var extrasChecks   = body.querySelectorAll('input[type="checkbox"][data-extra-id]');
        var qtyVal         = $('#vd-qty-val');
        var detailTotal    = $('#vd-detail-total');
        var quantity       = 1;

        function recalcDetailPrice() {
            var base = item.price;
            var selVariant = body.querySelector('input[name="vd-detail-variant"]:checked');
            if (selVariant) base = parseFloat(selVariant.getAttribute('data-price'));
            extrasChecks.forEach(function (cb) {
                if (cb.checked) base += parseFloat(cb.getAttribute('data-price'));
            });
            body.querySelectorAll('[data-group]:checked').forEach(function (inp) {
                base += parseFloat(inp.getAttribute('data-price')) || 0;
            });
            if (detailTotal) detailTotal.textContent = money(base * quantity);
        }

        variantRadios.forEach(function (r) {
            r.addEventListener('change', function () {
                $$('input[name="vd-detail-variant"]').forEach(function (rr) {
                    rr.closest('.vd-option').classList.remove('selected');
                });
                r.closest('.vd-option').classList.add('selected');
                recalcDetailPrice();
            });
        });

        extrasChecks.forEach(function (cb) {
            cb.addEventListener('change', function () {
                cb.closest('.vd-option').classList.toggle('selected', cb.checked);
                recalcDetailPrice();
            });
        });

        /* option-group selection (radio = single, checkbox = multi w/ max) */
        body.querySelectorAll('[data-group]').forEach(function (inp) {
            inp.addEventListener('change', function () {
                if (inp.type === 'radio') {
                    body.querySelectorAll('input[name="' + inp.name + '"]').forEach(function (x) { x.closest('.vd-option').classList.remove('selected'); });
                    inp.closest('.vd-option').classList.add('selected');
                } else {
                    var gmax = parseInt(inp.getAttribute('data-gmax'), 10) || 0;
                    if (inp.checked && gmax > 0) {
                        var checked = body.querySelectorAll('[data-group="' + inp.getAttribute('data-group') + '"]:checked');
                        if (checked.length > gmax) { inp.checked = false; return; }
                    }
                    inp.closest('.vd-option').classList.toggle('selected', inp.checked);
                }
                recalcDetailPrice();
            });
        });

        /* inline quick-rate is wired by one delegated handler (below), not per
           render, so tapping a star lights it and opens the shared review sheet. */

        /* qty stepper */
        var qtyMinus = $('#vd-qty-minus');
        var qtyPlus  = $('#vd-qty-plus');
        if (qtyMinus) qtyMinus.addEventListener('click', function () {
            if (quantity > 1) { quantity--; if (qtyVal) qtyVal.textContent = quantity; recalcDetailPrice(); }
        });
        if (qtyPlus) qtyPlus.addEventListener('click', function () {
            if (item.track_inventory && item.stock_qty !== null && quantity >= item.stock_qty) return;
            quantity++; if (qtyVal) qtyVal.textContent = quantity; recalcDetailPrice();
        });

        /* sync the CTA total with any preselected variant / required-group option on open */
        recalcDetailPrice();

        /* add to cart */
        var addBtn = $('#vd-detail-add');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                var variantId = null;
                var variantLabel = '';
                var variantPrice = item.price;
                var selVariant = body.querySelector('input[name="vd-detail-variant"]:checked');
                if (selVariant) {
                    variantId = selVariant.value;
                    variantPrice = parseFloat(selVariant.getAttribute('data-price'));
                    variantLabel = selVariant.getAttribute('data-label') || '';
                }

                var selectedExtras = [];
                extrasChecks.forEach(function (cb) {
                    if (cb.checked) {
                        selectedExtras.push({
                            id: cb.getAttribute('data-extra-id'),
                            price: parseFloat(cb.getAttribute('data-price')),
                            label: cb.getAttribute('data-label') || ''
                        });
                    }
                });

                /* collect + validate option-group selections */
                var mods = [];
                var ruleErr = null;
                (item.optionGroups || []).forEach(function (g) {
                    var sel = body.querySelectorAll('[data-group="' + g.id + '"]:checked');
                    var need = g.required ? Math.max(1, g.min || 0) : (g.min || 0);
                    if (sel.length < need) ruleErr = ruleErr || g.title;
                    sel.forEach(function (inp) {
                        mods.push({
                            groupId: g.id, optIndex: parseInt(inp.getAttribute('data-opt'), 10),
                            groupTitle: g.title, label: inp.getAttribute('data-label') || '',
                            price: parseFloat(inp.getAttribute('data-price')) || 0
                        });
                    });
                });
                if (ruleErr) { alert((CFG.i18n.customize || 'Please choose') + ': ' + ruleErr); return; }

                addToCart(item.id, quantity, variantId, variantLabel, variantPrice, selectedExtras, mods);
                closeSheet('vd-item-sheet');
            });
        }

        var jumpBtn = $('#vd-cart-jump');
        if (jumpBtn) {
            jumpBtn.addEventListener('click', function () {
                closeSheet('vd-item-sheet');
                openSheet('vd-cart-sheet');
            });
        }

        openSheet('vd-item-sheet');

        /* Every open path funnels through here — the [data-open-item] click, the
           in-page `a[href*=item=]` share links (the whole menu grid), and the
           deep-link on load. Announce it so the reviews block injects regardless
           of HOW the sheet was opened; keying that off the click alone left the
           menu-grid and deep-link opens with no reviews. */
        document.dispatchEvent(new CustomEvent('vd:item-open', { detail: { id: itemId } }));
    }

    /* event delegation for opening items */
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-open-item]');
        if (trigger) {
            e.preventDefault();
            var id = trigger.getAttribute('data-open-item');
            if (id && ITEMS[id]) renderDetail(id);
        }
    });

    /* inline quick-rate: tapping a star lights it and opens the shared review
       sheet preselected to that rating (one endpoint for stars + optional text). */
    document.addEventListener('click', function (e) {
        var rate = e.target.closest && e.target.closest('#vd-rateStars [data-rate]');
        if (!rate) return;
        var n = Number(rate.getAttribute('data-rate'));
        $$('#vd-rateStars [data-rate]').forEach(function (b, i) { b.classList.toggle('on', i < n); });
        if (window.mbItemReviews) window.mbItemReviews.open(n);
    });

    /* R7 · intercept same-page ?item= share links — open the product in place
       instead of reloading. Links to another path (or an unknown item) fall
       through to the browser untouched. */
    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[href*="item="]');
        if (!link) return;
        var url;
        try { url = new URL(link.href, location.href); } catch (err) { return; }
        if (url.pathname !== location.pathname) return;
        var iid = url.searchParams.get('item');
        if (iid && ITEMS[iid]) { e.preventDefault(); renderDetail(iid); }
    });

    /* ══════════════════════════ CART ══════════════════════════════════ */
    function addToCart(itemId, qty, variantId, variantLabel, variantPrice, extras, mods, note) {
        var item = ITEMS[itemId];
        if (!item || item.is_sold_out) return;
        mods = mods || [];
        note = note || '';
        sendItemEvent(item.id, 'add_to_cart');

        var unitPrice = variantPrice || item.price;
        extras.forEach(function (e) { unitPrice += e.price; });
        mods.forEach(function (m) { unitPrice += m.price; });

        /* find matching cart line: same item + same variant + same extras + same mods */
        var existing = -1;
        for (var i = 0; i < cart.length; i++) {
            if (cart[i].id === itemId && String(cart[i].variantId || '') === String(variantId || '')) {
                var exMatch = JSON.stringify(cart[i].extras || []) === JSON.stringify(extras || []);
                var modMatch = JSON.stringify(cart[i].mods || []) === JSON.stringify(mods || []);
                /* a differing note is a different line — otherwise a reordered line
                   carrying "no onions" would silently absorb a plain one */
                var noteMatch = String(cart[i].order_note || '') === note;
                if (exMatch && modMatch && noteMatch) { existing = i; break; }
            }
        }

        if (existing !== -1) {
            cart[existing].qty += qty;
            cart[existing].total = cart[existing].qty * unitPrice;
        } else {
            cart.push({
                id: itemId,
                name: item.name,
                qty: qty,
                variantId: variantId,
                variantLabel: variantLabel,
                extras: extras,
                mods: mods,
                unitPrice: unitPrice,
                total: unitPrice * qty,
                img: item.img,
                order_note: note
            });
        }

        saveCart();
        updateCartUI();
        pulseCartBtn();
    }

    function saveCart() { writeJSON(CART_KEY, { items: cart, timestamp: Date.now() }); }

    function removeFromCart(idx) {
        cart.splice(idx, 1);
        saveCart();
        updateCartUI();
    }

    function changeQty(idx, delta) {
        cart[idx].qty += delta;
        if (cart[idx].qty <= 0) { removeFromCart(idx); return; }
        cart[idx].total = cart[idx].qty * cart[idx].unitPrice;
        saveCart();
        updateCartUI();
    }

    /**
     * Reorder handoff from the account page — a DEAD CONTROL until now.
     * account.blade.php POSTs to customer.orders.reorder, stashes the returned
     * lines under `vd_reorder_<slug>` and redirects here; nothing in this file
     * ever read that key back, so "Reorder" walked the customer to the menu with
     * an empty cart, the stash still sitting in localStorage, and no explanation.
     *
     * Lines are rebuilt against the LIVE catalogue (ITEMS) — titles and prices
     * come from the page, never from the stashed payload — so a since-changed
     * price cannot be resurrected, and an item that has vanished or sold out is
     * skipped rather than poisoning the cart with an unorderable row. The key is
     * removed before anything else so a mid-rebuild throw cannot leave a stash
     * that re-fires on every reload.
     */
    function pickupReorder() {
        var key = 'vd_reorder_' + (CFG.restaurantSlug || 'default');
        var lines = readJSON(key, null);
        if (!lines || !lines.length) return;
        try { localStorage.removeItem(key); } catch (e) {}

        var added = 0;
        var skipped = 0;

        lines.forEach(function (line) {
            var item = ITEMS[line.id];
            if (!item || item.is_sold_out) { skipped++; return; }

            /* variant: match by id against the live list; a variant that no longer
               exists drops the line rather than silently billing the base price. */
            var variantId = null, variantLabel = '', variantPrice = item.price;
            var wantVariant = Number(line.variantId || 0) || 0;
            if (wantVariant) {
                var v = (item.variants || []).filter(function (x) { return Number(x.id) === wantVariant; })[0];
                if (!v) { skipped++; return; }
                /* the detail sheet stores the radio's string value — mirror it so a
                   reordered line merges with a hand-added one instead of doubling */
                variantId = String(v.id);
                variantLabel = v.label || '';
                variantPrice = Number(v.price);
            }

            /* extras rebuilt from the live catalogue; the same {id, price, label}
               shape and key order the detail sheet emits, so the merge test in
               addToCart (a JSON.stringify compare) still matches. */
            var extras = [];
            (line.extras || []).forEach(function (e) {
                var id = Number(e && e.id != null ? e.id : e);
                var match = (item.extras || []).filter(function (x) { return Number(x.id) === id; })[0];
                if (match) extras.push({ id: String(match.id), price: Number(match.price), label: match.title || '' });
            });

            addToCart(
                item.id,
                Math.max(1, Number(line.qty || line.quantity || 1)),
                variantId, variantLabel, variantPrice,
                extras,
                [], /* option-group mods: the reorder endpoint does not return them */
                line.order_note || ''
            );
            added++;
        });

        if (!added) return;
        openSheet('vd-cart-sheet');
        /* CFG.i18n, not the `I18N` alias — that `var` is assigned far below and is
           still undefined when this runs from the init block. */
        var T = CFG.i18n || {};
        fToast(skipped
            ? (T.reorderPartial || 'Some items were unavailable and were skipped.')
            : (T.reorderAdded || 'Your previous order is back in the cart.'));
    }

    var tipPct = 0;
    var tipAmount = 0;
    var discount = 0;
    /* The code the server has approved, and a handle on the re-request. A coupon
       is validated AGAINST A SUBTOTAL, so once the basket changes the old discount
       is stale — and the server recomputes it at checkout from `coupon_code`, so a
       stale one is a wrong quote. Re-asked whenever the subtotal moves. */
    var appliedCoupon = '';
    var lastCouponSubtotal = null;
    var vdRequestCoupon = null;

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
        return cart.reduce(function (sum, c) { return sum + c.total; }, 0) + comboTotal();
    }

    /* TOTALS CONTRACT — round to the STORE's currency decimals, the way PHP's
       round() does. Math.round(0.425*100) is 42 because 0.425 is really
       0.42499999…; PHP pre-rounds to 15 significant digits first and returns
       0.43. Mirroring that keeps the quote and the charge on the same cent. */
    /* M16: this idiom was already correct; delegate to the shared money core so it
       cannot drift back and stays identical to the other 16 themes. */
    /* bug batch 10 · 4: order arithmetic at the stored precision (CFG.moneyDecimals); display keeps decimalsFor() */
    var MONEY_DP = CFG.moneyDecimals != null ? CFG.moneyDecimals : decimalsFor(null);
    function roundMoney(x) { return mbMoney.round(x, MONEY_DP); }

    /* TOTALS CONTRACT — mirrors Order::computeTax() in core/app/Models/Order.php.
       Three things this used to get wrong, each of them a wrong quote:
         - the tax was never rounded, so it drifted from the server by a cent on
           any basket whose 5% is not exact;
         - `restaurant_tax_type === 'fixed'` is a FLAT AMOUNT, not a rate — this
           multiplied it by the basket anyway;
         - the guard: a non-positive charge or subtotal is EXACTLY 0. */
    function computeTax(sub) {
        var charge = Number(CFG.taxCharge) || 0;
        if (charge <= 0 || sub <= 0) return 0;
        if (String(CFG.taxType || 'percentage') === 'fixed') return roundMoney(charge);
        return roundMoney(sub * (charge / 100));
    }

    /* TOTALS CONTRACT — mirrors Order::getTotalAttribute().
       TAX IS COMPUTED ON THE RAW SUBTOTAL and the discount is subtracted LAST;
       the discount is never taxed. Delivery applies to `delivery` only. */
    function cartGrandTotal() {
        var sub = cartSubtotal();
        var total = sub - discount;
        if (CFG.allowOrder && tipAmount) total += tipAmount;
        if (CFG.deliveryCharge && currentOrderType === 'delivery') total += CFG.deliveryCharge;
        total += computeTax(sub);
        return Math.max(0, roundMoney(total));
    }

    var currentOrderType = '';

    /* A percentage tip has to FOLLOW the basket. It used to be frozen at the
       moment the chip was tapped, so adding a dish afterwards left a button
       reading "10%" next to a tip that was 10% of an older, smaller cart —
       and that stale number is what got posted. Recomputed on every cart
       change, and the hidden tip_amount input is rewritten with it so the
       quoted tip is always the tip the server receives. */
    function syncTip() {
        if (!tipPct) return;
        tipAmount = roundMoney(cartSubtotal() * tipPct / 100);
        var tipHidden = $('#vd-tip-amount');
        if (tipHidden) tipHidden.value = tipAmount.toFixed(MONEY_DP);
    }

    /* The cart as submitOrder's `items` — also posted to the coupon check (bug batch 7 · 4). */
    function orderLines() {
        return cart.map(function (c) {
            // Field names must match StoreController@submitOrder: quantity / variants / extras[{id}].
            return {
                id: c.id,
                quantity: c.qty,
                variants: c.variantId || 0,
                extras: c.extras.map(function (e) { return { id: e.id }; }),
                mods: (c.mods || []).map(function (m) { return { groupId: m.groupId, optIndex: m.optIndex }; }),
                order_note: c.order_note || ''
            };
        });
    }
    function couponOrderType() { var el = document.getElementById('vd-ordering-type'); return (el && el.value) || ''; }
    /* Bug batch 7 · 4: what a coupon quote depends on — the subtotal AND the lines + order type (buy-X-get-Y is
       priced by the server on the cart lines, so a swap that keeps the subtotal still re-quotes). */
    function couponSig() { return JSON.stringify([cartSubtotal(), orderLines(), couponOrderType()]); }

    function updateCartUI() {
        syncTip();
        /* Re-price an applied coupon against the current basket (see appliedCoupon). */
        if (appliedCoupon && vdRequestCoupon) {
            var subNow = couponSig();
            if (lastCouponSubtotal !== subNow) {
                lastCouponSubtotal = subNow;
                vdRequestCoupon(appliedCoupon, true);
            }
        }
        var count = cart.reduce(function (s, c) { return s + c.qty; }, 0) + comboCount();
        var total = cartGrandTotal();

        /* badge counts */
        var cc = $('#vd-cart-count');
        if (cc) cc.textContent = count;
        var bc = $('#vd-cartbar-count');
        if (bc) bc.textContent = count + ' ' + (count === 1 ? CFG.i18n.item : CFG.i18n.items);
        var bt = $('#vd-cartbar-total');
        if (bt) bt.textContent = money(total);

        /* cart bar visibility */
        var cartbar = $('#vd-cartbar');
        if (cartbar) cartbar.classList.toggle('show', count > 0);

        /* item-sheet cart-jump: shown once anything is in the cart (orbit/aurora parity) */
        var jump = $('#vd-cart-jump');
        if (jump) {
            jump.hidden = count === 0;
            var jc = $('#vd-cart-jump-count');
            if (jc) jc.textContent = count;
        }

        /* cart list */
        var list = $('#vd-cart-list');
        var empty = $('#vd-cart-empty');
        var form = $('#vd-order-form');
        var foot = $('#vd-cart-foot');
        var checkoutTotal = $('#vd-checkout-total');

        if (list) {
            /* COMBOS-V2 — combos alone make the basket non-empty: the form and the
               checkout CTA must show for a combos-only order. */
            if (cart.length === 0 && comboCount() === 0) {
                list.innerHTML = '';
                if (empty) empty.style.display = '';
                if (form) form.style.display = 'none';
                if (foot) {
                    foot.style.display = 'none';
                    /* if auth required and not logged in, keep lock button */
                    var lockBtn = foot.querySelector('button.vd-cta');
                    if (lockBtn && lockBtn.querySelector('.ico-lock')) foot.style.display = '';
                }
            } else {
                if (empty) empty.style.display = 'none';
                if (form) form.style.display = '';

                list.innerHTML = cart.map(function (c, idx) {
                    var exList = c.extras && c.extras.length
                        ? '<div class="vd-cart-extras">' + c.extras.map(function (e) { return '<span>' + fEsc(e.label) + '</span>'; }).join('') + '</div>'
                        : '';
                    var modList = c.mods && c.mods.length
                        ? '<div class="vd-cart-extras">' + c.mods.map(function (m) { return '<span>' + fEsc((m.groupTitle ? m.groupTitle + ': ' : '') + m.label) + '</span>'; }).join('') + '</div>'
                        : '';
                    var it = ITEMS[c.id] || {};
                    /* Happy-hour strike-through: original_price is the pre-schedule BASE
                       price, so the pre-discount unit is unit + (original − current base). */
                    var wasUnit = it.original_price && Number(it.original_price) > Number(it.price)
                        ? c.unitPrice + (Number(it.original_price) - Number(it.price)) : 0;
                    return '<div class="vd-cart-line">' +
                        (c.img ? '<button type="button" class="vd-cart-line-open" data-open-item="' + c.id + '" aria-label="' + fEsc(c.name) + '"><img class="vd-cart-line-img" src="' + fEsc(c.img) + '" alt="" data-csp-error="hide"></button>' : '') +
                        '<div class="vd-cart-line-info">' +
                            '<h4><button type="button" class="vd-cart-line-name" data-open-item="' + c.id + '">' + fEsc(c.name) + '</button></h4>' +
                            (c.variantLabel ? '<span class="vd-cart-variant">' + fEsc(c.variantLabel) + '</span>' : '') +
                            modList +
                            exList +
                            '<div class="vd-cart-line-price">' + money(c.unitPrice) + ' × ' + c.qty + ' = <strong>' + money(c.total) + '</strong>' +
                            (wasUnit ? ' <del class="vd-cart-was">' + money(wasUnit * c.qty) + '</del>' : '') + '</div>' +
                        '</div>' +
                        '<div class="vd-cart-line-actions">' +
                            '<div class="vd-cart-qty">' +
                                '<button type="button" class="vd-qty-btn sm" data-cart-dec="' + idx + '"><i class="ico ico-minus" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12l14 0"/></svg></i></button>' +
                                '<span>' + c.qty + '</span>' +
                                '<button type="button" class="vd-qty-btn sm" data-cart-inc="' + idx + '"><i class="ico ico-plus" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg></i></button>' +
                            '</div>' +
                            '<button type="button" class="vd-cart-rm" data-cart-rm="' + idx + '"><i class="ico ico-trash" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12.5a1.5 1.5 0 0 0 1.5 1.5h7a1.5 1.5 0 0 0 1.5-1.5L18 7M9 7V4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V7"/></svg></i></button>' +
                        '</div>' +
                    '</div>';
                }).join('') + comboGroupHTML();

                /* wire up cart line buttons */
                $$('[data-cart-inc]').forEach(function (b) {
                    b.addEventListener('click', function () { changeQty(parseInt(b.getAttribute('data-cart-inc'), 10), 1); });
                });
                $$('[data-cart-dec]').forEach(function (b) {
                    b.addEventListener('click', function () { changeQty(parseInt(b.getAttribute('data-cart-dec'), 10), -1); });
                });
                $$('[data-cart-rm]').forEach(function (b) {
                    b.addEventListener('click', function () { removeFromCart(parseInt(b.getAttribute('data-cart-rm'), 10)); });
                });

                if (foot) foot.style.display = '';
                /* but if auth required and not logged in, the locked button is already shown in blade */
                if (checkoutTotal) checkoutTotal.textContent = money(total);
            }
        }

        renderTotals();
    }

    function renderTotals() {
        var totals = $('#vd-totals');
        if (!totals) return;

        var sub = cartSubtotal();
        var rows = '<div class="vd-total-row"><span>' + CFG.i18n.subtotal + '</span><span>' + money(sub) + '</span></div>';

        if (discount > 0) {
            rows += '<div class="vd-total-row vd-total-discount"><span>' + CFG.i18n.discount + '</span><span>−' + money(discount) + '</span></div>';
        }

        if (tipAmount > 0) {
            rows += '<div class="vd-total-row"><span>' + CFG.i18n.tip + '</span><span>' + money(tipAmount) + '</span></div>';
        }

        if (CFG.deliveryCharge && currentOrderType === 'delivery') {
            rows += '<div class="vd-total-row"><span>' + CFG.i18n.delivery + '</span><span>' + money(CFG.deliveryCharge) + '</span></div>';
        }

        var tax = computeTax(sub);
        if (tax > 0) {
            var taxLabel = String(CFG.taxType || 'percentage') === 'fixed'
                ? CFG.i18n.tax
                : CFG.i18n.tax + ' (' + CFG.taxCharge + '%)';
            rows += '<div class="vd-total-row"><span>' + taxLabel + '</span><span>' + money(tax) + '</span></div>';
        }

        var total = cartGrandTotal();
        rows += '<div class="vd-total-row vd-total-grand"><span>' + CFG.i18n.total + '</span><span>' + money(total) + '</span></div>';

        /* min order check */
        if (CFG.minOrderValue && sub < CFG.minOrderValue) {
            rows += '<div class="vd-total-row vd-total-warn"><i class="ico ico-info" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8v.1"/></svg></i> ' + CFG.i18n.minOrder + ': ' + money(CFG.minOrderValue) + '</div>';
        }

        totals.innerHTML = rows;
        serverQuote(totals);
    }

    /* P3-13 (THEME-CONTRACT §5.2): the totals rows and the total are the server quote of the cart this form posts
       (the coupon field and the tip field as they will be submitted); the rows above are only the estimate shown
       until it answers. The minimum-order notice stays the theme's. */
    function serverQuote(box) {
        var form = $('#vd-order-form');
        if (!window.mbQuote || !form || (!cart.length && !comboCount())) return;
        var codeEl = document.getElementById('vd-coupon-code');
        var bidEl = form.querySelector('input[name="branch_id"]');
        window.mbQuote.update('cart', window.mbQuote.urlFrom(form.getAttribute('action')), {
            items: orderLines(),
            'ordering-type': couponOrderType() || currentOrderType || '',
            coupon_code: codeEl ? codeEl.value.trim() : appliedCoupon,
            tip_amount: tipAmount || 0,
            branch_id: bidEl ? bidEl.value : ''
        }, function (q) {
            var warn = box.querySelector('.vd-total-warn');
            var html = window.mbQuote.rows(q).map(function (r) {
                return '<div class="vd-total-row' + (r.key === 'discount' ? ' vd-total-discount' : '') + '"><span>' + window.mbQuote.esc(r.label) + '</span><span>' + window.mbQuote.esc(r.formatted) + '</span></div>';
            }).join('') + '<div class="vd-total-row vd-total-grand"><span>' + window.mbQuote.esc(q.total_label || CFG.i18n.total) + '</span><span>' + window.mbQuote.esc(q.total.formatted) + '</span></div>';
            box.innerHTML = html;
            if (warn) box.appendChild(warn);
            var ct = $('#vd-checkout-total');
            if (ct) ct.textContent = q.total.formatted;
        }, [box]);
    }

    function pulseCartBtn() {
        var btn = $('#vd-cart-toggle');
        if (btn) {
            btn.classList.remove('pulse');
            void btn.offsetWidth; /* trigger reflow */
            btn.classList.add('pulse');
        }
    }

    /* COMBOS-V2 — read-only "Combos" group appended to the cart list. Vivid's cart
       sheet doubles as its checkout summary (one sheet holds list, form and totals),
       so this single group serves both. Tapping a row reopens the combo sheet's
       basket view, which is where quantities are edited. */
    function comboGroupHTML() {
        var lines = comboLines();
        if (!lines.length) return '';
        var h = '<div class="vd-combo-group" data-combo-open style="border-top:1px dashed rgba(128,128,128,.4);padding-top:8px;margin-top:8px;cursor:pointer">';
        lines.forEach(function (L) {
            h += '<div class="vd-cart-line vd-cart-line--combo">' +
                '<div class="vd-cart-line-info">' +
                    '<h4>' + fEsc(L.name) + '</h4>' +
                    (L.parts.length ? '<div class="vd-cart-extras"><span>' + fEsc(L.parts.join(' · ')) + '</span></div>' : '') +
                    '<div class="vd-cart-line-price">' + money(L.unit) + ' × ' + L.qty + ' = <strong>' + money(L.price) + '</strong></div>' +
                '</div>' +
            '</div>';
        });
        h += '<small style="opacity:.65;display:block;text-align:end">' + fEsc(CFG.i18n.edit || 'Edit') + ' ›</small></div>';
        return h;
    }
    document.addEventListener('click', function (e) {
        var g = e.target.closest && e.target.closest('[data-combo-open]');
        if (g && window.mbCombos && window.mbCombos.openSheet) window.mbCombos.openSheet('basket');
    });
    /* Re-quote whenever the combo basket changes (add/remove/qty in the sheet).
       updateCartUI covers badge, cart list, totals, checkout CTA total and the
       stale-coupon re-quote in one call. */
    window.addEventListener('mb:combos-changed', function () {
        try { updateCartUI(); } catch (e) {}
    });

    /* cart open/close */
    var cartToggle = $('#vd-cart-toggle');
    if (cartToggle) cartToggle.addEventListener('click', function () { openSheet('vd-cart-sheet'); });
    var cartbarEl = $('#vd-cartbar');
    if (cartbarEl) cartbarEl.addEventListener('click', function () { openSheet('vd-cart-sheet'); });

    /* ══════════════════════════ ORDER TYPE SEGMENT ════════════════════ */
    var segButtons = $$('#vd-ordertype-seg button');
    var orderTypeInput = $('#vd-ordering-type');

    segButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            segButtons.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            currentOrderType = btn.getAttribute('data-type');
            if (orderTypeInput) orderTypeInput.value = currentOrderType;

            /* show/hide conditional fields */
            $$('[data-field="table"]').forEach(function (f) {
                f.style.display = currentOrderType === 'on-table' ? '' : 'none';
            });
            $$('[data-field="phone"]').forEach(function (f) {
                f.style.display = currentOrderType === 'takeaway' || currentOrderType === 'delivery' ? '' : 'none';
            });
            $$('[data-field="address"]').forEach(function (f) {
                f.style.display = currentOrderType === 'delivery' ? '' : 'none';
            });

            renderTotals();
        });
    });

    /* auto-select the default order type: room service > first available.
       The room-service button only renders when $allow_room_service is set, which
       already means hotel mode is on AND this QR resolved to a room — so its mere
       presence is the signal that the guest scanned from their room. Preferring it
       saves them a tap and, more importantly, stops a room order silently going to
       the kitchen as a table order when dine-in also happens to be enabled.
       This theme has no named setter — the click handler above IS the setter — so
       the default is applied by clicking, exactly as before. That is what makes
       #vd-ordering-type actually hold room_service rather than just painting the
       segment active. */
    var vdRoomBtn = $('#vd-ordertype-seg button[data-type="room_service"]');
    if (vdRoomBtn) vdRoomBtn.click();
    else if (segButtons.length) segButtons[0].click();

    /* ══════════════════════════ TIPS ═════════════════════════════════ */
    $$('.vd-tip-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            $$('.vd-tip-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');

            var pct = btn.getAttribute('data-tip-pct');
            var customInput = $('#vd-custom-tip');
            var tipHidden = $('#vd-tip-amount');

            if (pct === 'custom') {
                if (customInput) customInput.style.display = '';
                tipPct = 0;
                tipAmount = 0;
                if (tipHidden) tipHidden.value = '0';
            } else {
                if (customInput) { customInput.style.display = 'none'; customInput.value = ''; }
                tipPct = parseInt(pct, 10);
                syncTip();
            }
            updateCartUI();
        });
    });

    var customTipInput = $('#vd-custom-tip');
    if (customTipInput) {
        customTipInput.addEventListener('input', function () {
            tipPct = 0;
            tipAmount = roundMoney(Math.max(0, parseFloat(customTipInput.value) || 0));
            var tipHidden = $('#vd-tip-amount');
            if (tipHidden) tipHidden.value = tipAmount.toFixed(MONEY_DP);
            updateCartUI();
        });
    }

    /* ══════════════════════════ COUPON ════════════════════════════════ */
    var applyCouponBtn = $('#vd-apply-coupon');
    if (applyCouponBtn) {
        /* Captured before the first click, because the old code restored the label
           from CFG.i18n.discount and left the button reading "Discount". */
        var applyLabel = applyCouponBtn.textContent;

        /* StoreController@applyCoupon validates {code, subtotal} and answers with the
           SERVER's discount for that basket. The old call POSTed {coupon_code,
           store_id} to a hardcoded "/api/apply-coupon" — a route that does not
           exist in this application — so every coupon 404'd and the theme showed
           "✗ Error". Nobody has ever redeemed a coupon on vivid. */
        function requestCoupon(code, quiet) {
            var couponMsg = $('#vd-coupon-msg');
            var url = (CFG.endpoints && CFG.endpoints.coupon) || '';
            if (!url || !code) return;
            if (!quiet) { applyCouponBtn.disabled = true; applyCouponBtn.textContent = '…'; }
            var sentSubtotal = couponSig(); // bug batch 7 · 4: the quote's subtotal + lines + order type

            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken()
                },
                body: JSON.stringify({ code: code, subtotal: cartSubtotal(), items: orderLines(), 'ordering-type': couponOrderType() })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                applyCouponBtn.disabled = false;
                applyCouponBtn.textContent = applyLabel;
                if (couponMsg) couponMsg.style.removeProperty('display');
                if (data && data.success) {
                    /* The discount the server computed for THIS subtotal — never a
                       percentage re-derived on the client. */
                    discount = parseFloat(data.discount || 0);
                    appliedCoupon = code;
                    lastCouponSubtotal = sentSubtotal;
                    if (couponMsg) {
                        couponMsg.className = 'vd-coupon-msg vd-coupon-ok';
                        couponMsg.textContent = '✓ ' + (data.message || data.label || code);
                    }
                } else {
                    discount = 0;
                    appliedCoupon = '';
                    if (couponMsg) {
                        couponMsg.className = 'vd-coupon-msg vd-coupon-err';
                        couponMsg.textContent = '✗ ' + String((data && data.message) || CFG.i18n.failed || 'Invalid coupon')
                            .replace(/<br\s*\/?>/gi, ' ');
                    }
                }
                updateCartUI();
            })
            .catch(function () {
                applyCouponBtn.disabled = false;
                applyCouponBtn.textContent = applyLabel;
                discount = 0;
                appliedCoupon = '';
                if (couponMsg) {
                    couponMsg.style.removeProperty('display');
                    couponMsg.className = 'vd-coupon-msg vd-coupon-err';
                    couponMsg.textContent = '✗ ' + (CFG.i18n.networkError || 'Network error');
                }
                updateCartUI();
            });
        }
        vdRequestCoupon = requestCoupon;

        applyCouponBtn.addEventListener('click', function () {
            var codeInput = $('#vd-coupon-code');
            if (!codeInput || !codeInput.value.trim()) return;
            requestCoupon(codeInput.value.trim(), false);
        });
    }

    /* ══════════════════════════ CHECKOUT ══════════════════════════════ */
    var checkoutBtn = $('#vd-checkout-btn');
    if (checkoutBtn) {
        checkoutBtn.addEventListener('click', function (e) {
            /* let form submit normally if it's a real submit button */
            /* form action is set in blade; we intercept to add hidden item data */
            if (!cart.length && !comboCount()) { e.preventDefault(); return; } /* combos-only orders are legal */

            if (!currentOrderType) {
                e.preventDefault();
                showError(CFG.i18n.selectType);
                return;
            }

            /* min order check */
            if (CFG.minOrderValue && cartSubtotal() < CFG.minOrderValue) {
                e.preventDefault();
                showError(CFG.i18n.minOrder + ': ' + money(CFG.minOrderValue));
                return;
            }

            /* build items json */
            var itemsJson = orderLines();

            var existingItems = document.querySelector('input[name="items"]');
            if (!existingItems) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'items';
                hidden.value = JSON.stringify(itemsJson);
                var orderForm = $('#vd-order-form');
                if (orderForm) orderForm.appendChild(hidden);
            } else {
                existingItems.value = JSON.stringify(itemsJson);
            }

            /* The order is placed by this form's NATIVE submission -- there is no
               fetch() on this path -- so the busy state has to be applied without
               disturbing it. Two things went wrong here and each one on its own
               was enough to make checkout do nothing at all, with no error and no
               request on the wire:

               1. Constraint validation. `name` is required; if it is empty the
                  browser refuses to submit. That refusal is silent, and because
                  the spinner below used to be applied first, the button latched
                  into a permanent busy state the guest could never clear. So
                  validate FIRST and hand the browser's own message to the user
                  via reportValidity(), before touching the button.

               2. Disabling a submit button inside its own click handler cancels
                  the button's activation behaviour, so the form never submits
                  even when it is perfectly valid. Deferring the disable to the
                  next task lets the submission dispatch first while still
                  blocking a double submit. */
            var checkoutForm = $('#vd-order-form');
            if (checkoutForm && typeof checkoutForm.checkValidity === 'function' && !checkoutForm.checkValidity()) {
                if (typeof checkoutForm.reportValidity === 'function') checkoutForm.reportValidity();
                return;
            }

            /* show spinner */
            checkoutBtn.innerHTML = '<i class="ico ico-loader-2 ico-spin" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3a9 9 0 1 0 9 9"/></svg></i> ...';
            setTimeout(function () { checkoutBtn.disabled = true; }, 0);
        });
    }

    /* Submit the order over fetch instead of letting the form navigate.
       submitOrder answers with JSON for every theme, so a native form POST landed
       the guest on a raw {"success":false,...} page -- and on success too. It also
       put this theme outside the reach of _partials/order-field-forwarder.blade.php,
       which hooks fetch and XHR only: on a native submit the hotel `room` and
       `tender` fields (and use_wallet, scheduled_at, gift_card_code, the curbside
       and vehicle fields) were never injected, so a room-service order came back
       "Please select your room." Going through fetch fixes the response handling
       and restores the forwarder in one move. */
    var vdOrderForm = $('#vd-order-form');
    if (vdOrderForm && checkoutBtn) {
        /* Captured before any click can swap in the spinner, so restoring after a
           failure gives back the real label AND the price span the blade rendered,
           rather than a hand-rebuilt approximation. */
        var checkoutCtaHtml = checkoutBtn.innerHTML;
        vdOrderForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var restore = function () {
                checkoutBtn.disabled = false;
                checkoutBtn.innerHTML = checkoutCtaHtml;
            };
            fetch(vdOrderForm.getAttribute('action'), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(vdOrderForm),
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (res && res.success) {
                    /* R5 · remember this order so the guest can re-open its tracker
                       without a phone/email lookup. R6 · remember the delivery address
                       for one-tap re-fill next time (guests only; rememberAddress
                       no-ops for logged-in diners). Both run BEFORE the redirect. */
                    rememberOrder(res);
                    if (currentOrderType === 'delivery') {
                        var vdAddrEl = vdOrderForm.querySelector('[data-field="address"] [name="address"]');
                        var vdLatEl  = vdOrderForm.querySelector('[data-field="address"] [name="address_lat"]');
                        var vdLngEl  = vdOrderForm.querySelector('[data-field="address"] [name="address_lng"]');
                        if (vdAddrEl) rememberAddress(vdAddrEl.value, vdLatEl && vdLatEl.value, vdLngEl && vdLngEl.value);
                    }
                    cart = [];
                    saveCart();
                    /* `message` carries an online-payment redirect when one applies. */
                    var next = res.message || res.track_url;
                    if (res.whatsapp_url) mbWhatsAppHandoff(res.whatsapp_url, res.whatsapp_handoff);
                    if (next) { window.location.href = next; return; }
                    restore();
                    return;
                }
                restore();
                showError((res && res.message) || CFG.i18n.failed || 'Failed');
            }).catch(function () {
                restore();
                showError(CFG.i18n.networkError || 'Network error');
            });
        });
    }

    function showError(msg) {
        var box = $('#vd-form-error');
        if (!box) return;
        box.textContent = msg;
        box.classList.add('is-on');          /* NOT style.display = '' — see style.css */
        box.style.removeProperty('display');
        if (typeof box.scrollIntoView === 'function') {
            box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    /* ══════════════════════════ CART RESUME ══════════════════════════ */
    if (showResume) {
        /* auto-open cart sheet if it exists */
        var cartToggleBtn = $('#vd-cart-toggle');
        if (cartToggleBtn) {
            /* gentle bounce after a short delay */
        }
    }

    /* ══════════════════════════ LOGIN / LOGOUT ════════════════════════ */
    function openAuthSheet() {
        if (document.getElementById('vd-auth-sheet')) openSheet('vd-auth-sheet');
        else if (CFG.endpoints && CFG.endpoints.login) window.location.href = CFG.endpoints.login;
    }
    var loginToggle = $('#vd-login-toggle');
    if (loginToggle) loginToggle.addEventListener('click', openAuthSheet);
    var checkoutLoginBtn = $('#vd-checkout-login-btn');
    if (checkoutLoginBtn) checkoutLoginBtn.addEventListener('click', openAuthSheet);

    /* auth sheet: login / register / phone-OTP (P2) */
    (function () {
        var sheet = document.getElementById('vd-auth-sheet');
        if (!sheet) return;
        var ep = CFG.endpoints || {};
        var errEl = document.getElementById('vd-auth-error');
        var tabs = document.getElementById('vd-auth-tabs');
        if (tabs) tabs.querySelectorAll('button[data-auth-tab]').forEach(function (b) {
            b.addEventListener('click', function () {
                tabs.querySelectorAll('button').forEach(function (x) { x.classList.toggle('is-on', x === b); });
                sheet.querySelectorAll('.vd-auth-form[data-auth]').forEach(function (f) {
                    f.style.display = f.getAttribute('data-auth') === b.getAttribute('data-auth-tab') ? '' : 'none';
                });
                if (errEl) errEl.style.display = 'none';
            });
        });
        function showErr(msg) { if (errEl) { errEl.textContent = msg || 'Failed.'; errEl.style.display = 'block'; } }
        function authPost(url, body) {
            if (!url) return;
            if (errEl) errEl.style.display = 'none';
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify(body)
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (res && res.success) window.location.reload();
                else showErr(res && res.message);
            }).catch(function () { showErr('Network error. Please try again.'); });
        }
        var loginBtn = document.getElementById('vd-login-btn');
        if (loginBtn) loginBtn.addEventListener('click', function () {
            authPost(ep.login, {
                store_id: CFG.storeId,
                email: (document.getElementById('vd-login-email').value || '').trim(),
                password: document.getElementById('vd-login-pass').value
            });
        });
        var regBtn = document.getElementById('vd-register-btn');
        if (regBtn) regBtn.addEventListener('click', function () {
            authPost(ep.register, {
                store_id: CFG.storeId,
                name: (document.getElementById('vd-reg-name').value || '').trim(),
                email: (document.getElementById('vd-reg-email').value || '').trim(),
                phone: (document.getElementById('vd-reg-phone').value || '').trim(),
                password: document.getElementById('vd-reg-pass').value,
                password_confirmation: document.getElementById('vd-reg-pass2').value
            });
        });
        var otpSend = document.getElementById('vd-otp-send');
        if (otpSend) otpSend.addEventListener('click', function () {
            var phone = (document.getElementById('vd-otp-phone').value || '').trim();
            if (!phone || !ep.otpRequest) return;
            if (errEl) errEl.style.display = 'none';
            fetch(ep.otpRequest, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify({ store_id: CFG.storeId, phone: phone })
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (res && res.success) {
                    document.getElementById('vd-otp-step1').style.display = 'none';
                    document.getElementById('vd-otp-step2').style.display = 'block';
                    document.getElementById('vd-otp-code').focus();
                } else showErr(res && res.message);
            }).catch(function () { showErr('Network error. Please try again.'); });
        });
        var otpVerify = document.getElementById('vd-otp-verify');
        if (otpVerify) otpVerify.addEventListener('click', function () {
            var phone = (document.getElementById('vd-otp-phone').value || '').trim();
            var code = (document.getElementById('vd-otp-code').value || '').trim();
            if (!code || !ep.otpVerify) return;
            authPost(ep.otpVerify, { store_id: CFG.storeId, phone: phone, code: code });
        });
    })();

    var logoutBtn = $('#vd-logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function () {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = CFG.endpoints.logout;
            var token = document.createElement('input');
            token.type = 'hidden';
            token.name = '_token';
            token.value = csrfToken();
            form.appendChild(token);
            document.body.appendChild(form);
            form.submit();
        });
    }

    /* account dropdown */
    var accMenu = $('#vd-account-menu');
    if (accMenu) {
        var accBtn = accMenu.querySelector('button');
        if (accBtn) {
            accBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var menu = accMenu.querySelector('.vd-dropdown-menu');
                if (menu) menu.classList.toggle('open');
            });
        }
        document.addEventListener('click', function () {
            var menu = accMenu.querySelector('.vd-dropdown-menu');
            if (menu) menu.classList.remove('open');
        });
    }

    /* language dropdown */
    var langToggle = $('#vd-lang-toggle');
    if (langToggle) {
        var langWrap = $('#vd-lang-wrap');
        if (langWrap) {
            langToggle.addEventListener('click', function (e) {
                e.stopPropagation();
                var menu = langWrap.querySelector('.vd-dropdown-menu');
                if (menu) menu.classList.toggle('open');
            });
            $$('[data-code]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var code = b.getAttribute('data-code');
                    var url = new URL(window.location.href);
                    url.searchParams.set('lang', code);
                    window.location.href = url.toString();
                });
            });
        }
        document.addEventListener('click', function () {
            if (langWrap) {
                var menu = langWrap.querySelector('.vd-dropdown-menu');
                if (menu) menu.classList.remove('open');
            }
        });
    }

    /* NPS survey (P2) */
    (function () {
        var row = document.getElementById('vd-nps-row');
        if (!row || !(CFG.endpoints && CFG.endpoints.nps)) return;
        row.querySelectorAll('button[data-nps]').forEach(function (b) {
            b.addEventListener('click', function () {
                row.querySelectorAll('button').forEach(function (x) { x.classList.remove('is-on'); });
                b.classList.add('is-on');
                /* The "Thanks for the feedback!" line used to be shown from the
                   click handler with the POST's failure swallowed by an empty
                   .catch, so a diner whose score never reached the server was
                   thanked for it anyway. It now waits for the server. */
                var thanks = document.getElementById('vd-nps-thanks');
                fetch(CFG.endpoints.nps, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                    body: JSON.stringify({ score: Number(b.dataset.nps) })
                }).then(function (r) {
                    if (!r.ok) throw new Error('nps');
                    if (thanks) thanks.style.display = 'block';
                }).catch(function () {
                    b.classList.remove('is-on');
                    if (thanks) thanks.style.display = 'none';
                });
            });
        });
    })();

    /* guest order lookup (P2) */
    (function () {
        var btn = document.getElementById('vd-lookup-btn');
        var input = document.getElementById('vd-lookup-input');
        var box = document.getElementById('vd-lookup-results');
        if (!btn || !input || !box || !(CFG.endpoints && CFG.endpoints.orderLookup)) return;
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        }); }
        function run() {
            var val = (input.value || '').trim();
            if (!val) return;
            box.innerHTML = '<div class="vd-lookup-loading">…</div>';
            fetch(CFG.endpoints.orderLookup, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify({ identifier: val })
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (!data.success) {
                    box.innerHTML = '<p class="vd-lookup-empty">' + esc(data.message || '') + '</p>';
                    return;
                }
                box.innerHTML = data.orders.map(function (o) {
                    var inner = '<div class="vd-lookup-order"><div><b>#' + esc(o.id) + ' · ' + esc(o.total) + '</b>' +
                        '<small>' + esc(o.date) + '</small></div><span class="vd-lookup-status">' + esc(o.status) + '</span></div>';
                    return o.track_url
                        ? '<a class="vd-lookup-item" href="' + esc(o.track_url) + '">' + inner + '</a>'
                        : '<div class="vd-lookup-item">' + inner + '</div>';
                }).join('');
            }).catch(function () { box.innerHTML = ''; });
        }
        btn.addEventListener('click', run);
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(); } });
    })();

    /* #27 multi-currency display switcher */
    (function () {
        var wrap = document.getElementById('vd-currency-wrap');
        if (!wrap || !CFG.multiCurrency) return;
        var toggle = document.getElementById('vd-currency-toggle');
        var label = document.getElementById('vd-cur-label');
        var CUR_KEY = 'vd_currency_' + (CFG.restaurantSlug || 'default');
        var activeCur = sessionStorage.getItem(CUR_KEY) || CFG.baseCurrency;
        var activeRate = parseFloat(sessionStorage.getItem(CUR_KEY + '_rate') || '1');

        if (toggle) toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            wrap.classList.toggle('open');
        });
        document.addEventListener('click', function () { wrap.classList.remove('open'); });

        function fmt(value, code, rate) {
            var n = ((Number(value) || 0) * rate).toFixed(decimalsFor(code));
            return rate === 1 ? money(value) : '≈ ' + code + ' ' + n;
        }

        var DISC_ID = 'vd-currency-disclaimer';
        function disclaimer(code) {
            var el = document.getElementById(DISC_ID);
            if (code === CFG.baseCurrency) { if (el) el.remove(); return; }
            if (!el) {
                el = document.createElement('div');
                el.id = DISC_ID;

                /* styled in vivid's style.css (#vd-currency-disclaimer) — inline styles here would beat the stylesheet */
                var tb = document.getElementById('vd-topbar');
                if (tb) tb.insertAdjacentElement('afterend', el); else document.body.prepend(el);
            }
            el.textContent = (CFG.i18n && CFG.i18n.currencyNote ? CFG.i18n.currencyNote : 'Prices shown in :cur (approximate). Charged in base currency.').replace(':cur', code);
        }

        function apply(code, rate) {
            disclaimer(code);
            document.querySelectorAll('.vd-amt[data-vd-base]').forEach(function (el) {
                el.textContent = fmt(parseFloat(el.dataset.vdBase), code, rate);
            });
            if (label) label.textContent = code;
        }

        wrap.querySelectorAll('button[data-cur]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                activeCur = btn.dataset.cur;
                activeRate = parseFloat(btn.dataset.rate);
                sessionStorage.setItem(CUR_KEY, activeCur);
                sessionStorage.setItem(CUR_KEY + '_rate', activeRate);
                apply(activeCur, activeRate);
                wrap.classList.remove('open');
            });
        });

        if (activeCur !== CFG.baseCurrency && activeRate) apply(activeCur, activeRate);
    })();

    /* branch selector */
    var branchSelect = $('#vd-branch-select');
    if (branchSelect) {
        branchSelect.addEventListener('change', function () {
            var url = new URL(window.location.href);
            url.searchParams.set('branch', branchSelect.value);
            window.location.href = url.toString();
        });
    }

    /* ══════════════════════════ CALL WAITER / BILL ════════════════════ */
    var waiterBtn = $('#vd-call-waiter-btn');
    var billBtn   = $('#vd-request-bill-btn');

    function openWaiterSheet(type) {
        var sheet = $('#vd-waiter-sheet');
        var title = $('#vd-waiter-title');
        var typeInput = $('#vd-waiter-type-input');
        var success = $('#vd-waiter-success');
        var form = $('#vd-waiter-form');
        var contextLabel = $('#vd-waiter-context-label');

        /* Every string this sheet paints comes from CFG.i18n. It used to hardcode
           English here and overwrite the blade's translated copy the instant the
           sheet opened, so an Arabic diner got an English sheet. */
        var T = CFG.i18n || {};
        if (title) title.textContent = type === 'bill' ? T.requestBill : T.callWaiter;
        if (typeInput) typeInput.value = type === 'bill' ? 'request_bill' : 'call_waiter';
        if (success) success.style.display = 'none';
        if (form) form.style.display = '';
        var wErr = $('#vd-waiter-error');
        if (wErr) wErr.classList.remove('is-on');
        if (contextLabel) {
            contextLabel.textContent = type === 'bill' ? T.billContext : T.waiterContext;
        }

        openSheet('vd-waiter-sheet');
    }

    if (waiterBtn) waiterBtn.addEventListener('click', function () { openWaiterSheet('waiter'); });
    if (billBtn)   billBtn.addEventListener('click',   function () { openWaiterSheet('bill'); });

    var waiterForm = $('#vd-waiter-form');
    if (waiterForm) {
        waiterForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var T = CFG.i18n || {};
            var formData = new FormData(waiterForm);
            var submitBtn = waiterForm.querySelector('button[type="submit"]');
            var sendHtml = submitBtn ? submitBtn.innerHTML : '';
            var errBox = $('#vd-waiter-error');
            function waiterFail(msg) {
                /* Inline, in the sheet, translated — not a native alert() that the
                   customer has to dismiss before they can see the form again. */
                if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = sendHtml; }
                if (errBox) { errBox.textContent = msg; errBox.style.removeProperty('display'); errBox.classList.add('is-on'); }
            }
            if (errBox) errBox.classList.remove('is-on');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="ico ico-loader-2 ico-spin" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3a9 9 0 1 0 9 9"/></svg></i> …'; }

            fetch((CFG.endpoints && CFG.endpoints.callWaiter) || '', {
                method: 'POST',
                body: formData,
                headers: { 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                /* Success is claimed ONLY when the server says a WaiterCall row was
                   written. A theme in this run told the diner a waiter was coming
                   while the POST was being rejected every time. */
                if (data && data.success) {
                    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = sendHtml; }
                    var success = $('#vd-waiter-success');
                    var formWrap = $('#vd-waiter-form');
                    var sentLabel = $('#vd-waiter-sent-label');
                    if (formWrap) formWrap.style.display = 'none';
                    if (success) success.style.display = '';
                    if (sentLabel) {
                        var typeInput = $('#vd-waiter-type-input');
                        var sentType = typeInput ? typeInput.value : 'call_waiter';
                        sentLabel.textContent = sentType === 'request_bill' ? T.billSent : T.waiterSent;
                    }
                    setTimeout(function () { closeSheet('vd-waiter-sheet'); }, 2500);
                    return;
                }
                waiterFail(
                    String((data && (data.message || data.error)) || T.failed || 'Failed')
                        .replace(/<br\s*\/?>/gi, ' ')
                );
            })
            .catch(function () { waiterFail(T.networkError || 'Network error'); });
        });
    }

    /* ═══════════════════════ R5 · GUEST ORDER HISTORY ═════════════════ */
    /* Recorded on order-success and painted into the guest lookup panel, so a
       returning guest sees their recent orders without a phone/email lookup.
       Server lookup stays as-is — it just replaces the panel when used. */
    function ordersKey() { return 'vd_recent_orders_' + (CFG.restaurantSlug || 'default'); }
    function readRecentOrders() {
        try { return (JSON.parse(localStorage.getItem(ordersKey()) || '[]') || []).filter(function (o) { return o && o.track_url; }); }
        catch (e) { return []; }
    }
    function rememberOrder(res) {
        if (!res || !res.track_url) return;
        var l = readRecentOrders().filter(function (o) { return o.track_url !== res.track_url; });
        l.unshift({ id: res.order_id || '', track_url: res.track_url, total: res.total || '', placed_at: Date.now() });
        try { localStorage.setItem(ordersKey(), JSON.stringify(l.slice(0, 10))); } catch (e) {}
        renderRecentOrders();
    }
    function renderRecentOrders() {
        var box = $('#vd-lookup-results'); if (!box) return;   /* guest lookup panel only */
        var l = readRecentOrders(); if (!l.length) return;
        box.innerHTML = l.map(function (o) {
            var when = new Date(o.placed_at || Date.now()).toLocaleDateString(document.documentElement.lang || 'en', { day: '2-digit', month: 'short' });
            var head = o.id ? '#' + fEsc(o.id) + (o.total ? ' · ' + fEsc(o.total) : '') : fEsc(when);
            var inner = '<div class="vd-lookup-order"><div><b>' + head + '</b><small>' + fEsc(when) + '</small></div>' +
                '<span class="vd-lookup-status"><i class="ico ico-arrow-right" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12l14 0"/><path d="M13 18l6 -6"/><path d="M13 6l6 6"/></svg></i></span></div>';
            return '<a class="vd-lookup-item" href="' + fEsc(o.track_url) + '">' + inner + '</a>';
        }).join('');
    }

    /* ═══════════════════════ R6 · GUEST SAVED ADDRESSES ═══════════════ */
    /* Guests get the same one-tap address chips the server renders for logged-in
       diners, backed by localStorage. The storage key is computed INLINE via
       addrKey() — never a top-of-file `var` that would be undefined when this
       renders at load (var-hoist trap). Gated on !CFG.isLoggedIn so it never
       fights the server address book. Chip clicks are handled by the shared
       saved-address delegate below (fills the field + hidden lat/lng). */
    function addrKey() { return 'vd_recent_addrs_' + (CFG.restaurantSlug || 'default'); }
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
        renderRecentAddrs();
    }
    function renderRecentAddrs() {
        var saved = $('#vd-saved-addrs');
        if (!saved || CFG.isLoggedIn) return;
        var l = readRecentAddrs();
        if (!l.length) { saved.hidden = true; return; }
        saved.hidden = false;
        saved.innerHTML = l.map(function (a, i) {
            return '<button class="vd-saved-addr' + (i === 0 ? ' is-on' : '') + '" type="button" ' +
                'data-addr="' + fEsc(a.addr) + '" data-lat="' + fEsc(a.lat) + '" data-lng="' + fEsc(a.lng) + '">' +
                '<i class="ico ico-pin" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg></i>' +
                '<span class="vd-sa-text"><b>' + fEsc(a.addr) + '</b></span>' +
            '</button>';
        }).join('');
    }

    /* ══════════════════════════ INIT ══════════════════════════════════ */
    updateFavUI();
    pickupReorder();
    updateCartUI();
    /* R5/R6 · paint this browser's remembered guest orders + delivery addresses. */
    renderRecentOrders();
    renderRecentAddrs();

    /* R7 · open a shared ?item= product in place on boot. Deferred a tick: the
       reviews block listens for the vd:item-open event this dispatches, and its
       listener is registered further down this same script — firing synchronously
       here would open the sheet before anyone is listening, so the deep-linked
       product would show no reviews. */
    var vdDeepItem = new URLSearchParams(location.search).get('item');
    if (vdDeepItem && ITEMS[vdDeepItem]) setTimeout(function () { renderDetail(vdDeepItem); }, 0);

    /* if cart has items and it's a resume, pulse the cart button */
    if (showResume) {
        var cToggle = $('#vd-cart-toggle');
        if (cToggle) {
            setTimeout(function () { cToggle.classList.add('pulse'); }, 800);
        }
    }

    /* ══════════════════════════════════════════════════════════
       theme-pack-blade features (F1–F7) — ported from crave
       ══════════════════════════════════════════════════════════ */
    var EP = CFG.endpoints || {};
    var I18N = CFG.i18n || {};
    function fEsc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    var IMG_ERR = ' data-csp-error="hide"';
    function tokenHeaders(json) {
        var h = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (json) h['Content-Type'] = 'application/json';
        if (CFG.csrf) h['X-CSRF-TOKEN'] = CFG.csrf;
        if (CFG.customerToken) h['Authorization'] = 'Bearer ' + CFG.customerToken;
        return h;
    }
    function hasToken() { return !!CFG.customerToken; }
    function fToast(msg) {
        if (!msg) return;
        var t = document.getElementById('vd-feat-toast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'vd-feat-toast';
            t.className = 'vd-feat-toast';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(t._tm);
        t._tm = setTimeout(function () { t.classList.remove('show'); }, 2200);
    }

    /* ── F2 · follow / verified brand (B4/B5/B6) ──────────── */
    (function () {
        var btn = $('#vd-follow-btn');
        if (!btn || !EP.follow) return;
        var FOLLOW_INTENT = 'cv_follow_intent';
        var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function applyFollowState(d) {
            if (!d || typeof d.following === 'undefined') { return false; }
            btn.setAttribute('data-following', d.following ? '1' : '0');
            btn.classList.toggle('is-on', d.following);
            var c = $('#vd-follower-count'); if (c && typeof d.follower_count !== 'undefined') c.textContent = d.follower_count;
            return true;
        }
        // B5: optimistic count nudge (the count span only exists past the B1 threshold).
        function bumpCount(delta) {
            var c = $('#vd-follower-count'); if (!c) return;
            var n = parseInt(String(c.textContent || '').replace(/[^0-9]/g, ''), 10);
            if (!isNaN(n)) c.textContent = String(Math.max(0, n + delta));
        }
        // B5: scale-pop beat on a successful follow (skipped under reduced-motion).
        function popBeat() {
            if (REDUCED) return;
            btn.classList.add('vd-followbtn--pop');
            btn.addEventListener('animationend', function h() { btn.classList.remove('vd-followbtn--pop'); btn.removeEventListener('animationend', h); });
        }
        // B5: optimistic follow/unfollow — flip UI immediately, reconcile, revert on fail.
        function doFollow(unfollow) {
            var prevFollowing = btn.getAttribute('data-following') === '1';
            btn.setAttribute('data-following', unfollow ? '0' : '1');
            btn.classList.toggle('is-on', !unfollow);
            bumpCount(unfollow ? -1 : 1);
            if (!unfollow) popBeat();
            fetch(EP.follow, { method: unfollow ? 'DELETE' : 'POST', headers: tokenHeaders(true), body: '{}' })
                .then(function (r) { return r.json(); }).then(function (d) {
                    if (!d || typeof d.following === 'undefined') { throw new Error('bad'); }
                    applyFollowState(d);
                }).catch(function () {
                    btn.setAttribute('data-following', prevFollowing ? '1' : '0');
                    btn.classList.toggle('is-on', prevFollowing);
                    bumpCount(unfollow ? 1 : -1);
                    fToast(I18N.action_failed || 'Failed');
                });
        }
        // B6: the "Following" management sheet — supersedes any two-tap guard when the
        // button is already is-on. Notifications toggle writes the follow row's notify via
        // the same follow endpoint ({notify: 0|1}); Unfollow runs the optimistic unfollow.
        var notifyBox = $('#vd-follow-notify');
        function openFollowingSheet() {
            if (notifyBox) notifyBox.checked = CFG.followNotify !== false;
            openSheet('vd-following-sheet');
        }
        if (notifyBox) {
            notifyBox.addEventListener('change', function () {
                var want = notifyBox.checked;
                fetch(EP.follow, { method: 'POST', headers: tokenHeaders(true), body: JSON.stringify({ notify: want ? 1 : 0 }) })
                    .then(function (r) { return r.json(); }).then(function (d) {
                        if (d && typeof d.notify !== 'undefined') { CFG.followNotify = !!d.notify; notifyBox.checked = !!d.notify; }
                        else { throw new Error('bad'); }
                    }).catch(function () { notifyBox.checked = !want; fToast(I18N.action_failed || 'Failed'); });
            });
        }
        var unfollowBtn = $('#vd-follow-unfollow');
        if (unfollowBtn) unfollowBtn.addEventListener('click', function () { closeSheet('vd-following-sheet'); doFollow(true); });
        var cancelBtn = $('#vd-follow-cancel');
        if (cancelBtn) cancelBtn.addEventListener('click', function () { closeSheet('vd-following-sheet'); });

        btn.addEventListener('click', function () {
            // B4: guest tap records a follow intent, then opens the auth sheet. After a
            // successful sign-in the page reloads and the intent is replayed below.
            if (!hasToken()) {
                try { sessionStorage.setItem(FOLLOW_INTENT, '1'); } catch (e) {}
                openAuthSheet();
                return;
            }
            if (btn.getAttribute('data-following') === '1') {
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
            if (btn.getAttribute('data-following') === '1') return;
            fetch(EP.follow, { method: 'POST', headers: tokenHeaders(true), body: '{}' })
                .then(function (r) { return r.json(); }).then(function (d) {
                    if (applyFollowState(d) && d.following) { fToast(I18N.now_following || '✓'); }
                }).catch(function () {});
        })();
    })();

    /* ── F2b · about sheet (B8) ───────────────────────────── */
    (function () {
        if (!document.getElementById('vd-about-sheet')) return;
        function openAbout() { openSheet('vd-about-sheet'); }
        var info = document.querySelector('.vd-brand-info');
        if (info) {
            info.setAttribute('role', 'button');
            info.setAttribute('tabindex', '0');
            info.style.cursor = 'pointer';
            info.addEventListener('click', openAbout);
            info.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openAbout(); } });
        }
        // The logo opens stories when it wears the live ring (B7); otherwise the about sheet.
        var logo = document.querySelector('.vd-brand-logo');
        if (logo) logo.addEventListener('click', function () { if (!logo.classList.contains('vd-brand-logo--live')) openAbout(); });
    })();

    /* ── F3 · stories ─────────────────────────────────────── */
    (function () {
        var rail = $('#vd-stories-rail');
        if (!rail || !EP.stories) return;
        var stories = [], idx = 0, timer = null, T = 0, DUR = 5;
        var viewer = $('#vd-story-viewer'), media = $('#vd-sv-media'), cap = $('#vd-sv-caption'), cta = $('#vd-sv-cta'), prog = $('#vd-sv-progress');

        (window.mbStoriesGet?window.mbStoriesGet(EP.stories):fetch(EP.stories).then(function(r){return r.json();})).then(function (d) {
            stories = (d && d.data) || [];
            if (!stories.length) { rail.hidden = true; return; }
            rail.hidden = false;
            rail.innerHTML = stories.map(function (s, i) {
                return '<button class="vd-story-bubble" data-si="' + i + '" type="button" aria-label="' + fEsc((s.caption ? String(s.caption) : (rail.getAttribute('data-story-label') || 'Story')) + ' ' + (i + 1)) + '">' +
                    '<span class="vd-sb-ring"><img src="' + fEsc(s.media_url) + '" alt=""' + IMG_ERR + '></span>' +
                    '<small>' + fEsc(s.caption ? String(s.caption).slice(0, 14) : (I18N.order_now || '')) + '</small></button>';
            }).join('');
            // B7: with stories live, the brand logo gets the story ring and opens the
            // viewer at index 0 (same call the first story bubble uses). No stories → no
            // change, so an untouched store renders identically.
            var brandLogo = document.querySelector('.vd-brand-logo');
            if (brandLogo && !brandLogo.classList.contains('vd-brand-logo--live')) {
                brandLogo.classList.add('vd-brand-logo--live');
                brandLogo.setAttribute('role', 'button');
                brandLogo.setAttribute('tabindex', '0');
                brandLogo.setAttribute('aria-label', I18N.view_stories || '');
                brandLogo.addEventListener('click', function () { open(0); });
                brandLogo.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(0); } });
            }
        }).catch(function () { rail.hidden = true; });

        function buildProgress() { if (prog) prog.innerHTML = stories.map(function () { return '<span class="vd-sv-seg"><i></i></span>'; }).join(''); }
        function paintSeg() { $$('.vd-sv-seg i', prog).forEach(function (bar, i) { bar.style.width = i < idx ? '100%' : (i === idx ? (T / DUR * 100) + '%' : '0%'); }); }
        function render() {
            var s = stories[idx]; if (!s) return;
            media.src = s.media_url;
            cap.textContent = s.caption || '';
            if (s.cta_url && !(window.mbStoryCta && window.mbStoryCta.isCurrentPage(s.cta_url))) { cta.hidden = false; cta.href = s.cta_url;
 if (window.mbStoryCta) window.mbStoryCta.apply(cta, s.cta_url); cta.querySelector('span').textContent = s.cta_label || (I18N.order_now || 'Order now'); }
            else { cta.hidden = true; }
            T = 0; paintSeg();
        }
        function tick() { T += 0.05; if (T >= DUR) { next(); return; } paintSeg(); }
        function start() { stop(); render(); timer = setInterval(tick, 50); }
        function stop() { if (timer) { clearInterval(timer); timer = null; } }
        function open(i) {
            idx = i; buildProgress();
            viewer.hidden = false; void viewer.offsetWidth; viewer.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            start();
        }
        function close() {
            stop(); viewer.classList.remove('is-open');
            if (!$$('.vd-sheet.open').length) document.body.style.overflow = '';
            setTimeout(function () { viewer.hidden = true; }, 300);
        }
        function next() { if (idx < stories.length - 1) { idx++; render(); } else { close(); } }
        function prev() { if (idx > 0) { idx--; render(); } else { T = 0; } }

        rail.addEventListener('click', function (e) { var b = e.target.closest('[data-si]'); if (b) open(Number(b.dataset.si)); });
        var cl = $('#vd-sv-close'); if (cl) cl.addEventListener('click', close);
        /* CTA opens the item in-page beneath this viewer; close the viewer so the item sheet is the top layer. */
        if (cta) cta.addEventListener('click', close);
        var nx = $('#vd-sv-next'); if (nx) nx.addEventListener('click', next);
        var pv = $('#vd-sv-prev'); if (pv) pv.addEventListener('click', prev);
        var stage = $('#vd-sv-stage');
        if (stage) {
            stage.addEventListener('pointerdown', stop);
            stage.addEventListener('pointerup', function () { if (!viewer.hidden) timer = setInterval(tick, 50); });
        }
    })();

    /* ── F5 · address autocomplete + use-my-location · & F4 launcher ─────── */
    (function () {
        if (!EP.geoAutocomplete) return;
        $$('[data-field="address"]').forEach(function (fieldWrap) {
            var input = fieldWrap.querySelector('textarea[name="address"], input[name="address"]');
            if (!input || input._vdGeo) return;
            input._vdGeo = true;
            input.setAttribute('autocomplete', 'off');

            var tools = document.createElement('div');
            tools.className = 'vd-addr-tools';
            var useBtn = document.createElement('button');
            useBtn.type = 'button'; useBtn.className = 'vd-useloc-btn';
            useBtn.innerHTML = '<i class="ico ico-location" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 3l-6.5 18a.55 .55 0 0 1 -1 0l-3.5 -7l-7 -3.5a.55 .55 0 0 1 0 -1l18 -6.5"/></svg></i> ' + (I18N.use_location || 'Use my location');
            tools.appendChild(useBtn);
            var findBtn = null;
            if (EP.branchesGeo && document.getElementById('vd-branchmap-sheet')) {
                findBtn = document.createElement('button');
                findBtn.type = 'button'; findBtn.className = 'vd-useloc-btn';
                findBtn.innerHTML = '<i class="ico ico-map-2" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 18.5l-3 -1.5l-6 3v-13l6 -3l6 3l6 -3v7.5"/><path d="M9 4v13"/><path d="M15 7v5.5"/><path d="M21.121 20.121a3 3 0 1 0 -4.242 0c.418 .419 1.125 1.045 2.121 1.879c1.051 -.89 1.759 -1.516 2.121 -1.879"/><path d="M19 18v.01"/></svg></i> ' + (I18N.find_branch || 'Find nearest branch');
                tools.appendChild(findBtn);
            }
            var suggest = document.createElement('div');
            suggest.className = 'vd-addr-suggest'; suggest.hidden = true;
            var attrib = document.createElement('p');
            attrib.className = 'vd-geo-attrib'; attrib.hidden = true;

            input.insertAdjacentElement('afterend', attrib);
            input.insertAdjacentElement('afterend', suggest);
            input.insertAdjacentElement('afterend', tools);

            var t = null, lastAttrib = '';
            /* hidden coord inputs (named like aurora/feast: address_lat/address_lng)
               ride along in the FormData order payload */
            var latEl = fieldWrap.querySelector('input[name="address_lat"]');
            var lngEl = fieldWrap.querySelector('input[name="address_lng"]');
            function setCoords(lat, lng) { if (latEl) latEl.value = lat == null ? '' : lat; if (lngEl) lngEl.value = lng == null ? '' : lng; }
            function setAttrib(a) { if (a && a !== lastAttrib) { attrib.textContent = a; attrib.hidden = false; lastAttrib = a; } }
            function hideBox() { suggest.hidden = true; suggest.innerHTML = ''; }
            input.addEventListener('input', function () {
                var q = input.value.trim();
                setCoords('', '');
                if (t) clearTimeout(t);
                if (q.length < 3) { hideBox(); return; }
                t = setTimeout(function () {
                    fetch(EP.geoAutocomplete + '?q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (d) {
                        setAttrib(d && d.attribution);
                        var res = (d && d.results) || [];
                        if (!res.length) { hideBox(); return; }
                        suggest.innerHTML = res.map(function (r) {
                            return '<button class="vd-addr-opt" data-lat="' + (r.lat || '') + '" data-lng="' + (r.lng || '') + '" data-label="' + fEsc(r.label) + '" type="button">' + fEsc(r.label) + '</button>';
                        }).join('');
                        suggest.hidden = false;
                    }).catch(hideBox);
                }, 350);
            });
            suggest.addEventListener('click', function (e) {
                var opt = e.target.closest('.vd-addr-opt'); if (!opt) return;
                input.value = opt.getAttribute('data-label');
                setCoords(opt.getAttribute('data-lat') || '', opt.getAttribute('data-lng') || '');
                hideBox();
            });
            input.addEventListener('blur', function () { setTimeout(hideBox, 200); });
            useBtn.addEventListener('click', function () {
                if (!navigator.geolocation) { fToast(I18N.loc_unavailable || 'Location unavailable'); return; }
                useBtn.classList.add('is-loading');
                navigator.geolocation.getCurrentPosition(function (pos) {
                    var lat = pos.coords.latitude, lng = pos.coords.longitude;
                    fetch(EP.geoReverse + '?lat=' + lat + '&lng=' + lng).then(function (r) { return r.json(); }).then(function (d) {
                        useBtn.classList.remove('is-loading');
                        setAttrib(d && d.attribution);
                        if (d && d.result) {
                            input.value = d.result.label || '';
                            setCoords(d.result.lat || lat, d.result.lng || lng);
                        }
                        else { fToast(I18N.no_address || 'No address found'); }
                    }).catch(function () { useBtn.classList.remove('is-loading'); });
                }, function () { useBtn.classList.remove('is-loading'); fToast(I18N.loc_denied || 'Location denied'); }, { enableHighAccuracy: true, timeout: 8000 });
            });
            if (findBtn) findBtn.addEventListener('click', function () { if (window.__vdOpenBranchMap) window.__vdOpenBranchMap(); });
        });
    })();

    /* ── saved addresses (logged-in) — tap a chip to fill the field + coords ── */
    (function () {
        var wrap = document.getElementById('vd-saved-addrs');
        if (!wrap) return;
        var field = wrap.closest('[data-field="address"]');
        var input = field ? field.querySelector('textarea[name="address"], input[name="address"]') : null;
        /* R6 · the logged-in block gives these hidden inputs ids; the guest block only
           names them, so fall back to a name lookup within the field so guest chips
           fill their coords too. */
        var latEl = document.getElementById('vd-addr-lat') || (field && field.querySelector('input[name="address_lat"]'));
        var lngEl = document.getElementById('vd-addr-lng') || (field && field.querySelector('input[name="address_lng"]'));
        wrap.addEventListener('click', function (e) {
            var chip = e.target.closest('.vd-saved-addr');
            if (!chip) return;
            if (input) input.value = chip.getAttribute('data-addr') || '';
            if (latEl) latEl.value = chip.getAttribute('data-lat') || '';
            if (lngEl) lngEl.value = chip.getAttribute('data-lng') || '';
            $$('.vd-saved-addr', wrap).forEach(function (c) { c.classList.toggle('is-on', c === chip); });
        });
    })();

    /* ── F4 · nearest-branch map ──────────────────────────── */
    (function () {
        var sheetEl = document.getElementById('vd-branchmap-sheet');
        if (!sheetEl || !EP.branchesGeo || typeof L === 'undefined') return;
        var map = null, markers = [];
        var icon = L.icon({
            iconUrl: CFG.leafletImgPath + '/marker-icon.png',
            iconRetinaUrl: CFG.leafletImgPath + '/marker-icon-2x.png',
            shadowUrl: CFG.leafletImgPath + '/marker-shadow.png',
            iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
        });
        function selectBranch(id) { var url = new URL(window.location.href); url.searchParams.set('branch_id', id); window.location.href = url.toString(); }
        function renderList(branches) {
            $('#vd-branch-geolist').innerHTML = branches.map(function (b) {
                var dist = b.distance_km != null ? '<span class="vd-branch-dist" dir="ltr">' + b.distance_km.toFixed(1) + ' km</span>' : '';
                return '<button class="vd-branch-georow" data-bid="' + b.id + '" type="button">' +
                    '<i class="ico ico-pin" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg></i><span class="vd-bg-name"><b>' + fEsc(b.name) + '</b>' + (b.address ? '<small>' + fEsc(b.address) + '</small>' : '') + '</span>' + dist + '</button>';
            }).join('') || '<p style="color:var(--vd-muted);text-align:center;padding:14px">No branches</p>';
        }
        function drawMap(branches) {
            var withCoords = branches.filter(function (b) { return b.latitude != null && b.longitude != null; });
            if (!map) {
                map = L.map('vd-branchmap', { attributionControl: false });
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
                L.control.attribution({ prefix: false }).addAttribution('© OpenStreetMap').addTo(map);
            }
            markers.forEach(function (m) { map.removeLayer(m); }); markers = [];
            if (!withCoords.length) { map.setView([24.7136, 46.6753], 11); return; }
            var group = [];
            withCoords.forEach(function (b) {
                var mk = L.marker([b.latitude, b.longitude], { icon: icon }).addTo(map);
                mk.bindPopup('<b>' + fEsc(b.name) + '</b>');
                mk.on('click', function () { selectBranch(b.id); });
                markers.push(mk); group.push([b.latitude, b.longitude]);
            });
            map.fitBounds(group, { padding: [40, 40], maxZoom: 14 });
        }
        function load(lat, lng) {
            var url = EP.branchesGeo + (lat != null ? '?lat=' + lat + '&lng=' + lng : '');
            fetch(url).then(function (r) { return r.json(); }).then(function (d) {
                var branches = (d && d.branches) || [];
                $('#vd-map-attrib').textContent = (d && d.attribution) || '© OpenStreetMap contributors';
                renderList(branches);
                setTimeout(function () { drawMap(branches); if (map) map.invalidateSize(); }, 60);
            }).catch(function () {});
        }
        window.__vdOpenBranchMap = function () {
            openSheet('vd-branchmap-sheet');
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function (p) { load(p.coords.latitude, p.coords.longitude); }, function () { load(); }, { timeout: 6000 });
            } else { load(); }
        };
        $('#vd-branch-geolist').addEventListener('click', function (e) {
            var b = e.target.closest('[data-bid]'); if (b) selectBranch(b.dataset.bid);
        });
    })();

    /* ── F6 · rich reviews (inside item detail sheet) ─────── */
    (function () {
        if (!EP.reviewsBase) return;
        var curFilter = 'latest', curItemId = null;

        function starBar(n) { return '<span class="vd-stars-mini">' + '★'.repeat(n) + '<span class="vd-s-dim">' + '★'.repeat(5 - n) + '</span></span>'; }
        function render(wrap, d) {
            var avg = d.average || 0, count = d.count || 0, dist = d.distribution || {};
            var head = '<div class="vd-rv-head"><div class="vd-rv-avg"><b>' + avg.toFixed(1) + '</b>' + starBar(Math.round(avg)) +
                '<small>' + count + ' ' + fEsc(I18N.reviews || 'reviews') + '</small></div>' +
                '<div class="vd-rv-dist">' + [5, 4, 3, 2, 1].map(function (s) {
                    var c = dist[s] || 0, pct = count ? Math.round(c / count * 100) : 0;
                    return '<span class="vd-rv-bar"><em>' + s + '</em><i><s style="width:' + pct + '%"></s></i><b>' + c + '</b></span>';
                }).join('') + '</div></div>';
            var filters = '<div class="vd-rv-filters">' + ['latest', 'verified', 'detailed'].map(function (f) {
                return '<button data-rvf="' + f + '" class="' + (f === curFilter ? 'is-on' : '') + '" type="button">' + fEsc(I18N['rv_' + f] || f) + '</button>';
            }).join('') + '</div>';
            var list = (d.data || []).map(function (r) {
                var photos = (r.photos || []).map(function (p) { return '<img src="' + fEsc(p.url) + '" alt=""' + IMG_ERR + '>'; }).join('');
                return '<div class="vd-rv-item"><div class="vd-rv-item-top"><b>' + fEsc(r.name || '—') + '</b>' +
                    (r.is_verified ? '<span class="vd-rv-verified"><i class="ico ico-check" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></i> ' + fEsc(I18N.rv_verified || 'Verified') + '</span>' : '') + '</div>' +
                    starBar(r.stars) + (r.body ? '<p>' + fEsc(r.body) + '</p>' : '') +
                    (photos ? '<div class="vd-rv-photos">' + photos + '</div>' : '') + '</div>';
            }).join('');
            if (!(d.data || []).length) list = '<p class="vd-rv-empty">' + fEsc(I18N.rv_empty || 'No reviews yet — be the first!') + '</p>';
            wrap.innerHTML = '<div class="vd-rv-secthead"><h4>' + fEsc(I18N.reviews || 'Reviews') + '</h4>' +
                '<button class="vd-ghostbtn sm" id="vd-write-review" type="button">' + fEsc(I18N.rv_write || 'Write') + '</button></div>' +
                head + filters + '<div class="vd-rv-list">' + list + '</div>';
        }
        function load(itemId, filter) {
            curItemId = itemId; curFilter = filter || 'latest';
            var wrap = $('#vd-reviews-block'); if (!wrap) return;
            fetch(EP.reviewsBase + '/' + itemId + '/reviews?filter=' + curFilter)
                .then(function (r) { return r.json(); }).then(function (d) { render(wrap, d); })
                .catch(function () { wrap.innerHTML = ''; });
        }

        /* inject a reviews block into the item sheet after renderDetail rebuilds
           the body. Driven by the shared vd:item-open event, not the open click:
           the menu grid opens items through `a[href*=item=]` links and share
           deep-links open on load, neither of which carries [data-open-item], so a
           click-scoped listener showed reviews only on the spotlight cards. */
        document.addEventListener('vd:item-open', function (e) {
            var id = e.detail && e.detail.id;
            if (!id || !ITEMS[id]) return;
            setTimeout(function () {
                var body = $('#vd-item-body'); if (!body) return;
                var existing = $('#vd-reviews-block');
                if (existing) existing.remove();
                var wrap = document.createElement('section');
                wrap.className = 'vd-detail-section vd-reviews-block';
                wrap.id = 'vd-reviews-block';
                body.appendChild(wrap);
                load(id, 'latest');
            }, 0);
        });

        /* filter + write delegation (block is re-created each open) */
        document.addEventListener('click', function (e) {
            var f = e.target.closest('[data-rvf]');
            if (f && $('#vd-reviews-block') && $('#vd-reviews-block').contains(f)) { load(curItemId, f.dataset.rvf); return; }
            if (e.target.closest('#vd-write-review')) { openReview(); }
        });

        /* write-review sheet */
        var rvStars = 0;
        function openReview() {
            if (!curItemId) return;
            rvStars = 0;
            $$('#vd-review-stars button').forEach(function (b) { b.classList.remove('on'); });
            var txt = $('#vd-review-text'); if (txt) txt.value = '';
            var nm = $('#vd-review-name'); if (nm) nm.value = '';
            var err = $('#vd-review-err'); if (err) err.style.display = 'none';
            var it = ITEMS[curItemId];
            var nmEl = $('#vd-review-itemname'); if (nmEl) nmEl.textContent = it ? it.name : '';
            openSheet('vd-review-sheet');
        }
        $$('#vd-review-stars button').forEach(function (b) {
            b.addEventListener('click', function () {
                rvStars = Number(b.getAttribute('data-star'));
                $$('#vd-review-stars button').forEach(function (x, i) { x.classList.toggle('on', i < rvStars); });
            });
        });
        var subBtn = $('#vd-review-submit');
        if (subBtn) subBtn.addEventListener('click', function () {
            var err = $('#vd-review-err');
            if (!rvStars) { if (err) { err.textContent = I18N.choose || 'Choose a rating'; err.style.display = 'block'; } return; }
            var body = { rating: rvStars, text: ($('#vd-review-text') || {}).value || '' };
            var nm = $('#vd-review-name'); if (nm && nm.value.trim()) body.name = nm.value.trim();
            subBtn.disabled = true;
            fetch(EP.reviewsBase + '/' + curItemId + '/reviews', { method: 'POST', headers: tokenHeaders(true), body: JSON.stringify(body) })
                .then(function (r) { return r.json(); }).then(function (d) {
                    subBtn.disabled = false;
                    if (d && d.ok) { closeSheet('vd-review-sheet'); fToast(I18N.thanks || 'Thanks!'); load(curItemId, curFilter); }
                    else { if (err) { err.textContent = (d && d.message) || 'Failed'; err.style.display = 'block'; } }
                }).catch(function () { subBtn.disabled = false; if (err) { err.textContent = I18N.networkError || 'Network error'; err.style.display = 'block'; } });
        });
    })();

    /* ── F1 · meal-plan create sheet ──────────────────────── */
    (function () {
        var cta = $('#vd-mealplan-cta');
        if (!cta) return;
        if (!CFG.mealPlansEnabled) { cta.style.display = 'none'; return; }
        var sheetEl = $('#vd-mealplan-sheet');
        var cadence = 'weekly', cadences = [], days = {};
        try { cadences = JSON.parse(sheetEl.getAttribute('data-cadences') || '[]'); } catch (e) {}
        if (!cadences.length) cadences = ['daily', 'weekly'];

        function buildCadence() {
            var el = $('#vd-mp-cadence'); if (!el) return;
            cadence = cadences.indexOf('weekly') !== -1 ? 'weekly' : cadences[0];
            el.innerHTML = cadences.map(function (c) {
                return '<button data-cad="' + c + '" class="' + (c === cadence ? 'active' : '') + '" type="button">' + fEsc(I18N['cad_' + c] || c) + '</button>';
            }).join('');
        }
        cta.addEventListener('click', function () {
            if (!cart.length) { fToast(I18N.cart_empty || 'Cart is empty'); return; }
            if (!hasToken()) { openAuthSheet(); return; }
            buildCadence();
            var sum = $('#vd-mp-summary');
            if (sum) sum.textContent = cart.reduce(function (a, l) { return a + l.qty; }, 0) + ' × ' + (I18N.mp_items || 'items');
            $('#vd-mp-days-wrap').style.display = cadence === 'custom' ? '' : 'none';
            openSheet('vd-mealplan-sheet');
        });
        var loginBtn = $('#vd-mp-login-btn');
        if (loginBtn) loginBtn.addEventListener('click', function () { closeSheet('vd-mealplan-sheet'); openAuthSheet(); });

        if (sheetEl) sheetEl.addEventListener('click', function (e) {
            var c = e.target.closest('[data-cad]');
            if (c) {
                cadence = c.getAttribute('data-cad');
                $$('#vd-mp-cadence button').forEach(function (b) { b.classList.toggle('active', b === c); });
                $('#vd-mp-days-wrap').style.display = cadence === 'custom' ? '' : 'none';
                return;
            }
            var d = e.target.closest('[data-day]');
            if (d) { var k = d.getAttribute('data-day'); days[k] = !days[k]; d.classList.toggle('active', days[k]); }
        });
        var mpSubmit = $('#vd-mp-submit');
        if (mpSubmit) mpSubmit.addEventListener('click', function () {
            var err = $('#vd-mp-err'); if (err) err.style.display = 'none';
            var items = cart.map(function (l) {
                return { item_id: l.id, quantity: l.qty, variation: l.variantId || null, extras: (l.extras || []).map(function (e) { return e.id; }) };
            });
            if (!items.length) { if (err) { err.textContent = I18N.cart_empty || 'Cart is empty'; err.style.display = 'block'; } return; }
            var body = { name: ($('#vd-mp-name') || {}).value.trim() || null, cadence: cadence, start_date: ($('#vd-mp-start') || {}).value, items: items };
            if (cadence === 'custom') {
                body.days = Object.keys(days).filter(function (k) { return days[k]; }).map(Number);
                if (!body.days.length) { if (err) { err.textContent = I18N.choose || 'Pick at least one day'; err.style.display = 'block'; } return; }
            }
            mpSubmit.disabled = true;
            fetch(EP.mealPlan, { method: 'POST', headers: tokenHeaders(true), body: JSON.stringify(body) })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (res) {
                    mpSubmit.disabled = false;
                    if (res.ok && res.j && res.j.id) {
                        $('#vd-mp-form').style.display = 'none';
                        $('#vd-mp-done').style.display = '';
                        $('#vd-mp-done-sub').textContent = (I18N.mp_next || 'Next order') + ': ' + (res.j.next_run || '');
                    } else {
                        var msg = res.j && (res.j.message || (res.j.errors && Object.values(res.j.errors)[0][0]));
                        if (err) { err.textContent = msg || 'Failed'; err.style.display = 'block'; }
                    }
                }).catch(function () { mpSubmit.disabled = false; if (err) { err.textContent = I18N.networkError || 'Network error'; err.style.display = 'block'; } });
        });
    })();

    /* ── F7 · OTP checkout gate ───────────────────────────── */
    (function () {
        var checkoutBtn = document.getElementById('vd-checkout-btn');
        if (!checkoutBtn || !CFG.otpGateEnabled) return;
        var OK_KEY = 'vd_otp_ok_' + (CFG.restaurantSlug || 'default');
        var boxes = $$('#vd-otp-gate-boxes input');
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
            fetch(EP.otpSend, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CFG.csrf }, body: JSON.stringify({ phone: phone, store_id: CFG.storeId }) })
                .then(function (r) { return r.json(); }).then(function (d) {
                    if (!d || !d.success) { var e = $('#vd-otp-gate-err'); if (e) { e.textContent = (d && d.message) || 'Failed'; e.style.display = 'block'; } }
                }).catch(function () {});
        }
        function openGate(phone) {
            curPhone = phone;
            var pe = $('#vd-otp-gate-phone'); if (pe) pe.textContent = phone;
            boxes.forEach(function (b) { b.value = ''; });
            var er = $('#vd-otp-gate-err'); if (er) er.style.display = 'none';
            openSheet('vd-checkoutotp-sheet');
            setTimeout(function () { if (boxes[0]) boxes[0].focus(); }, 300);
            send(phone);
        }
        function readPhone() {
            var p = document.querySelector('[name="phone-number"]');
            return p ? (p.value || '').trim() : '';
        }
        /* capture-phase gate: intercept BEFORE the normal checkout handler + native submit */
        checkoutBtn.addEventListener('click', function (e) {
            if (sessionStorage.getItem(OK_KEY) === '1') return;      /* already verified this session */
            if (!cart.length && !comboCount()) return;               /* combos-only orders still pass the OTP gate */
            var phone = readPhone();
            if (!phone) return;                                       /* no phone (e.g. on-table) → let server decide */
            e.preventDefault();
            e.stopImmediatePropagation();
            openGate(phone);
        }, true);

        var resend = $('#vd-otp-gate-resend');
        if (resend) resend.addEventListener('click', function () { var er = $('#vd-otp-gate-err'); if (er) er.style.display = 'none'; send(curPhone); fToast('Sent'); });
        var verify = $('#vd-otp-gate-verify');
        if (verify) verify.addEventListener('click', function () {
            var err = $('#vd-otp-gate-err'); if (err) err.style.display = 'none';
            var c = code();
            if (c.length < 4) { if (err) { err.textContent = I18N.enter_code || 'Enter the code'; err.style.display = 'block'; } return; }
            verify.disabled = true;
            fetch(EP.otpVerify2, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CFG.csrf }, body: JSON.stringify({ phone: curPhone, code: c, store_id: CFG.storeId }) })
                .then(function (r) { return r.json(); }).then(function (d) {
                    verify.disabled = false;
                    if (d && d.success) {
                        sessionStorage.setItem(OK_KEY, '1');
                        closeSheet('vd-checkoutotp-sheet');
                        checkoutBtn.click();  /* retry — gate now passes */
                    } else { if (err) { err.textContent = (d && d.message) || I18N.invalid_code || 'Invalid code'; err.style.display = 'block'; } }
                }).catch(function () { verify.disabled = false; if (err) { err.textContent = I18N.networkError || 'Network error'; err.style.display = 'block'; } });
        });
    })();

})();

/* ── VIVID 3.0 · bottom dock wiring — thin launchers over existing controls ── */
(function () {
    var dock = document.getElementById('vdDock');
    if (!dock) return;
    function on(btn) {
        dock.querySelectorAll('.vd-dock-btn').forEach(function (b) { b.classList.remove('is-on'); });
        btn.classList.add('is-on');
    }
    dock.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-vddock]');
        if (!btn) return;
        var act = btn.getAttribute('data-vddock');
        if (act === 'home') { window.scrollTo({ top: 0, behavior: 'smooth' }); on(btn); }
        else if (act === 'menu') {
            var m = document.querySelector('[data-mb-grid-lead]') || document.getElementById('vd-menu-root');
            if (m) m.scrollIntoView({ behavior: 'smooth', block: 'start' });
            on(btn);
        }
        else if (act === 'search') {
            var w = document.querySelector('.vd-search-wrap');
            if (w) w.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var inp = document.getElementById('vd-search-input');
            if (inp) setTimeout(function () { inp.focus(); }, 350);
            on(btn);
        }
        else if (act === 'cart') {
            var t = document.getElementById('vd-cart-toggle');
            if (t) t.click();
        }
        else if (act === 'favs') {
            var fv = document.getElementById('vd-fav-toggle');
            if (fv) fv.click();
        }
        else if (act === 'profile') {
            var p = document.querySelector('#vd-account-menu > button, #vd-login-toggle');
            if (p) p.click();
        }
    });
    var src = document.getElementById('vd-cart-count');
    var dst = document.getElementById('vdDockCartCount');
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
