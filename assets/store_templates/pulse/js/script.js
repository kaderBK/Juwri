// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ==========================================================================
   PULSE — Interactive behaviours
   Theme switching, category/spice/diet filtering, search, dish detail
   bottom-sheet, cart, favourites, scroll animations, combos, reorder.
   Vanilla JS — no framework dependency.
   ========================================================================== */
(function () {
    'use strict';

    /* ── Config from PHP ─────────────────────────────────────────────── */
    var CFG = window.PULSE || {};
    var ITEMS = CFG.items || {};
    var RESTAURANT_SLUG = CFG.restaurantSlug || '';
    var CURRENCY_SIGN = CFG.currencySign || '$';
    var CURRENCY_POS = String(CFG.currencyPos) === '1';
    var CURRENCY_DP = CFG.currencyDecimals != null ? CFG.currencyDecimals : 2;

    /* ── Helpers ─────────────────────────────────────────────────────── */
    var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

    function money(amount) {
        var f = Math.pow(10, CURRENCY_DP);
        var n = Math.round((Number(amount) || 0) * f) / f;
        var str = n.toFixed(CURRENCY_DP);
        return CURRENCY_POS ? (CURRENCY_SIGN + str) : (str + CURRENCY_SIGN);
    }

    /* ── LocalStorage helpers ────────────────────────────────────────── */
    var STORAGE_PREFIX = 'pulse_';
    function readJSON(key, fallback) {
        try { return JSON.parse(localStorage.getItem(STORAGE_PREFIX + key)) || fallback; }
        catch (e) { return fallback; }
    }
    function writeJSON(key, val) {
        try { localStorage.setItem(STORAGE_PREFIX + key, JSON.stringify(val)); }
        catch (e) { /* quota exceeded */ }
    }

    /* ── State ───────────────────────────────────────────────────────── */
    var currentTheme = readJSON('theme', CFG.defaultTheme || 'light');
    var cart = readJSON('cart_' + RESTAURANT_SLUG, []);
    var favourites = readJSON('favourites_' + RESTAURANT_SLUG, []);
    var activeCategory = 'all';
    var activeSpice = 'all';
    var searchQuery = '';

    /* ── Theme ───────────────────────────────────────────────────────── */
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        currentTheme = theme;
        writeJSON('theme', theme);
        // Update radio buttons if settings panel is open
        $$('input[name="pulse-theme"]').forEach(function (radio) {
            radio.checked = radio.value === theme;
        });
    }

    function initTheme() {
        applyTheme(currentTheme);
        // Listen for theme toggle buttons
        $$('[data-theme-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                applyTheme(btn.getAttribute('data-theme-toggle'));
            });
        });
        // Listen for theme radio buttons in settings
        $$('input[name="pulse-theme"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                applyTheme(radio.value);
            });
        });
    }

    /* ── Settings panel ──────────────────────────────────────────────── */
    function initSettings() {
        var toggle = $('#settingsToggle');
        var panel = $('#settingsPanel');
        var closeBtn = $('#settingsClose');
        var overlay = $('#settingsOverlay');

        if (!toggle || !panel) return;

        function openPanel() {
            panel.classList.add('open');
            if (overlay) overlay.classList.add('visible');
            document.body.style.overflow = 'hidden';
        }
        function closePanel() {
            panel.classList.remove('open');
            if (overlay) overlay.classList.remove('visible');
            document.body.style.overflow = '';
        }

        toggle.addEventListener('click', openPanel);
        if (closeBtn) closeBtn.addEventListener('click', closePanel);
        if (overlay) overlay.addEventListener('click', closePanel);
    }

    /* ── Mobile navigation drawer ────────────────────────────────────── */
    function initMobileNav() {
        var toggle = $('#mobileNavToggle');
        var drawer = $('#mobileNavDrawer');
        var overlay = $('#mobileNavOverlay');
        var closeBtn = $('#mobileNavClose');

        if (!toggle || !drawer) return;

        function open() {
            drawer.classList.add('open');
            if (overlay) overlay.classList.add('visible');
            document.body.style.overflow = 'hidden';
        }
        function close() {
            drawer.classList.remove('open');
            if (overlay) overlay.classList.remove('visible');
            document.body.style.overflow = '';
        }

        toggle.addEventListener('click', open);
        if (closeBtn) closeBtn.addEventListener('click', close);
        if (overlay) overlay.addEventListener('click', close);

        // Close on nav link click
        $$('.nav-drawer-link', drawer).forEach(function (link) {
            link.addEventListener('click', close);
        });
    }

    /* ── Search ──────────────────────────────────────────────────────── */
    function initSearch() {
        var input = $('#pulseSearch');
        var clearBtn = $('#searchClear');
        var resultsInfo = $('#searchResultsInfo');

        if (!input) return;

        var debounceTimer;

        input.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                searchQuery = input.value.trim().toLowerCase();
                if (clearBtn) clearBtn.style.display = searchQuery ? '' : 'none';
                filterAndRender();
                if (resultsInfo) {
                    var visible = $$('.dish-card').filter(function (c) {
                        return c.style.display !== 'none';
                    }).length;
                    var total = $$('.dish-card').length;
                    resultsInfo.textContent = searchQuery
                        ? visible + ' / ' + total + ' items found'
                        : '';
                }
            }, 150);
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                input.value = '';
                searchQuery = '';
                clearBtn.style.display = 'none';
                if (resultsInfo) resultsInfo.textContent = '';
                filterAndRender();
            });
        }
    }

    /* ── Category pills ──────────────────────────────────────────────── */
    function initCategoryPills() {
        $$('.category-pill').forEach(function (pill) {
            pill.addEventListener('click', function () {
                activeCategory = pill.getAttribute('data-cat') || 'all';
                $$('.category-pill').forEach(function (p) {
                    p.classList.toggle('active', p === pill);
                });
                filterAndRender();
                // Scroll active pill into view
                pill.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            });
        });
    }

    /* ── Spice filter pills ──────────────────────────────────────────── */
    function initSpicePills() {
        $$('.spice-pill').forEach(function (pill) {
            pill.addEventListener('click', function () {
                activeSpice = pill.getAttribute('data-spice') || 'all';
                $$('.spice-pill').forEach(function (p) {
                    p.classList.toggle('active', p === pill);
                });
                filterAndRender();
            });
        });
    }

    /* ── Filter logic ────────────────────────────────────────────────── */
    function filterAndRender() {
        var cards = $$('.dish-card');
        var visibleCount = 0;

        cards.forEach(function (card) {
            var matchCategory = activeCategory === 'all' ||
                (card.getAttribute('data-category') || '').split(',').indexOf(activeCategory) !== -1;
            var matchSpice = activeSpice === 'all' ||
                (card.getAttribute('data-spice') || '') === activeSpice;
            var matchSearch = !searchQuery ||
                (card.getAttribute('data-name') || '').indexOf(searchQuery) !== -1 ||
                (card.getAttribute('data-desc') || '').indexOf(searchQuery) !== -1 ||
                (card.getAttribute('data-tags') || '').indexOf(searchQuery) !== -1;
            var matchDiet = true;
            if ($('#gfToggle') && $('#gfToggle').checked) {
                matchDiet = matchDiet && card.getAttribute('data-gf') === '1';
            }
            if ($('#vegToggle') && $('#vegToggle').checked) {
                matchDiet = matchDiet && card.getAttribute('data-veg') === '1';
            }

            var visible = matchCategory && matchSpice && matchSearch && matchDiet;
            card.style.display = visible ? '' : 'none';
            if (visible) visibleCount++;
        });

        // Show/hide empty states
        var dishGrid = $('#dishGrid');
        if (dishGrid) {
            var emptyState = dishGrid.querySelector('.empty-state');
            if (emptyState) {
                emptyState.style.display = visibleCount === 0 ? '' : 'none';
            }
        }
    }

    /* ── Dish detail bottom-sheet ────────────────────────────────────── */
    function initDishSheet() {
        var sheet = $('#dishSheet');
        var overlay = $('#dishSheetOverlay');
        var closeBtn = $('#dishSheetClose');
        var sheetContent = $('#dishSheetContent');
        var sheetBackdrop = $('#dishSheetBackdrop');

        if (!sheet) return;

        function openSheet(menuId) {
            var item = ITEMS[menuId];
            if (!item) return;

            // Populate sheet image (carousel when multiple images; data field is item.img)
            var img = sheet.querySelector('.dish-sheet-image');
            var host = sheet.querySelector('[data-mic-host]') || (img && img.parentNode);
            var pImgs = [item.img || item.image].concat(item.gallery || []).filter(Boolean);
            if (host && window.mbCarousel && pImgs.length) {
                host.setAttribute('data-mic-host', '1');
                host.innerHTML = mbCarousel.html(pImgs);
            } else if (img) {
                img.src = (item.img || item.image) || '/images/placeholder-dish.jpg';
            }

            var title = sheet.querySelector('.dish-sheet-title');
            if (title) title.textContent = item.name || '';

            var price = sheet.querySelector('.dish-sheet-price');
            if (price) price.textContent = money(item.price);

            var desc = sheet.querySelector('.dish-sheet-description');
            if (desc) desc.textContent = item.description || '';

            // Favourite button
            var favBtn = sheet.querySelector('.dish-sheet-fav');
            if (favBtn) {
                var isFav = favourites.indexOf(menuId) !== -1;
                favBtn.classList.toggle('active', isFav);
                favBtn.onclick = function () { toggleFavourite(menuId); };
            }

            // Cart controls
            var qty = sheet.querySelector('.dish-sheet-qty');
            var minusBtn = sheet.querySelector('.dish-sheet-minus');
            var plusBtn = sheet.querySelector('.dish-sheet-plus');
            var addBtn = sheet.querySelector('.dish-sheet-add');
            var currentQty = getCartQty(menuId);

            if (qty) qty.textContent = currentQty;
            if (minusBtn) {
                minusBtn.onclick = function () {
                    currentQty = Math.max(0, currentQty - 1);
                    if (qty) qty.textContent = currentQty;
                };
            }
            if (plusBtn) {
                plusBtn.onclick = function () {
                    currentQty++;
                    if (qty) qty.textContent = currentQty;
                };
            }
            if (addBtn) {
                addBtn.onclick = function () {
                    if (currentQty > 0) {
                        setCartQty(menuId, currentQty);
                        updateCartBadge();
                        closeSheet();
                    }
                };
            }

            // Show sheet
            sheet.classList.add('open');
            if (overlay) overlay.classList.add('visible');
            if (sheetBackdrop) sheetBackdrop.classList.add('visible');
            document.body.style.overflow = 'hidden';
        }

        function closeSheet() {
            sheet.classList.remove('open');
            if (overlay) overlay.classList.remove('visible');
            if (sheetBackdrop) sheetBackdrop.classList.remove('visible');
            document.body.style.overflow = '';
        }

        // Open from card tap (desktop: full view; mobile: bottom sheet)
        $$('.dish-card').forEach(function (card) {
            card.addEventListener('click', function (e) {
                // Don't open if clicking cart/add/qty buttons
                if (e.target.closest('.dish-card-cart') || e.target.closest('.dish-card-fav')) return;
                var id = card.getAttribute('data-menu-id');
                if (id) openSheet(id);
            });
        });

        // Close handlers
        if (closeBtn) closeBtn.addEventListener('click', closeSheet);
        if (overlay) overlay.addEventListener('click', closeSheet);
        if (sheetBackdrop) sheetBackdrop.addEventListener('click', closeSheet);

        // Drag to close on mobile
        initSheetDragToClose(sheet, closeSheet);
    }

    function initSheetDragToClose(sheet, closeSheet) {
        var handle = sheet.querySelector('.dish-sheet-handle');
        if (!handle) return;

        var startY = 0;
        var currentY = 0;
        var isDragging = false;

        handle.addEventListener('touchstart', function (e) {
            startY = e.touches[0].clientY;
            isDragging = true;
            sheet.style.transition = 'none';
        }, { passive: true });

        handle.addEventListener('touchmove', function (e) {
            if (!isDragging) return;
            currentY = e.touches[0].clientY;
            var diff = currentY - startY;
            if (diff > 0) {
                sheet.style.transform = 'translateY(' + diff + 'px)';
            }
        }, { passive: true });

        handle.addEventListener('touchend', function () {
            if (!isDragging) return;
            isDragging = false;
            sheet.style.transition = '';
            sheet.style.transform = '';
            var diff = currentY - startY;
            if (diff > 100) {
                closeSheet();
            }
        }, { passive: true });
    }

    /* ── Favourites ──────────────────────────────────────────────────── */
    function toggleFavourite(menuId) {
        var idx = favourites.indexOf(menuId);
        if (idx === -1) {
            favourites.push(menuId);
        } else {
            favourites.splice(idx, 1);
        }
        writeJSON('favourites_' + RESTAURANT_SLUG, favourites);
        updateFavBadges();
    }

    function updateFavBadges() {
        $$('.dish-card-fav').forEach(function (btn) {
            var id = btn.getAttribute('data-menu-id');
            btn.classList.toggle('active', favourites.indexOf(id) !== -1);
        });
    }

    function initFavButtons() {
        $$('.dish-card-fav').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var id = btn.getAttribute('data-menu-id');
                if (id) toggleFavourite(id);
            });
        });
    }

    /* ── Cart operations ─────────────────────────────────────────────── */
    function getCartQty(menuId) {
        for (var i = 0; i < cart.length; i++) {
            if (cart[i].menu_id === menuId) return cart[i].quantity;
        }
        return 0;
    }

    function setCartQty(menuId, qty) {
        var found = false;
        for (var i = 0; i < cart.length; i++) {
            if (cart[i].menu_id === menuId) {
                if (qty <= 0) {
                    cart.splice(i, 1);
                } else {
                    cart[i].quantity = qty;
                }
                found = true;
                break;
            }
        }
        if (!found && qty > 0) {
            cart.push({ menu_id: menuId, quantity: qty });
        }
        writeJSON('cart_' + RESTAURANT_SLUG, cart);
        updateCardQtyDisplays();
    }

    function updateCardQtyDisplays() {
        $$('.dish-card').forEach(function (card) {
            var id = card.getAttribute('data-menu-id');
            var qty = getCartQty(id);
            var qtyEl = card.querySelector('.dish-card-qty');
            if (qtyEl) {
                qtyEl.textContent = qty;
                qtyEl.style.display = qty > 0 ? '' : 'none';
            }
            var minusBtn = card.querySelector('.dish-card-minus');
            var plusBtn = card.querySelector('.dish-card-plus');
            if (minusBtn) minusBtn.style.display = qty > 0 ? '' : 'none';
            if (plusBtn) plusBtn.style.display = qty > 0 ? '' : 'none';
        });
    }

    function updateCartBadge() {
        var total = 0;
        cart.forEach(function (item) { total += item.quantity; });
        var badge = $('#cartBadge');
        if (badge) {
            badge.textContent = total;
            badge.style.display = total > 0 ? '' : 'none';
        }
        // Also update floating button badge
        var floatBadge = $('#floatingCartBadge');
        if (floatBadge) {
            floatBadge.textContent = total;
            floatBadge.style.display = total > 0 ? '' : 'none';
        }
    }

    function initCartButtons() {
        // Quick-add buttons on cards
        $$('.dish-card-quick-add').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var card = btn.closest('.dish-card');
                var id = card.getAttribute('data-menu-id');
                if (id) {
                    setCartQty(id, getCartQty(id) + 1);
                    updateCartBadge();
                    // Animate button
                    btn.classList.add('pulse-anim');
                    setTimeout(function () { btn.classList.remove('pulse-anim'); }, 400);
                }
            });
        });

        // Card-level minus/plus
        $$('.dish-card-minus').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var card = btn.closest('.dish-card');
                var id = card.getAttribute('data-menu-id');
                if (id) {
                    var newQty = Math.max(0, getCartQty(id) - 1);
                    setCartQty(id, newQty);
                    updateCartBadge();
                }
            });
        });
        $$('.dish-card-plus').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var card = btn.closest('.dish-card');
                var id = card.getAttribute('data-menu-id');
                if (id) {
                    setCartQty(id, getCartQty(id) + 1);
                    updateCartBadge();
                }
            });
        });
    }

    /* ── Floating cart button ────────────────────────────────────────── */
    function initFloatingCart() {
        var fab = $('#floatingCartFab');
        if (!fab) return;
        fab.addEventListener('click', function () {
            // Scroll to cart section or open cart page
            var cartSection = $('#cartSection');
            if (cartSection) {
                cartSection.scrollIntoView({ behavior: 'smooth' });
            }
        });

        // Show/hide based on scroll
        var lastScroll = 0;
        window.addEventListener('scroll', function () {
            var scrollY = window.scrollY || window.pageYOffset;
            if (scrollY > 400) {
                fab.classList.add('visible');
            } else {
                fab.classList.remove('visible');
            }
            lastScroll = scrollY;
        }, { passive: true });
    }

    /* ── Health & Diet toggles ───────────────────────────────────────── */
    function initDietToggles() {
        ['gfToggle', 'vegToggle'].forEach(function (id) {
            var el = $('#' + id);
            if (el) {
                el.addEventListener('change', function () {
                    filterAndRender();
                });
            }
        });
    }

    /* ── Combo section ───────────────────────────────────────────────── */
    function initCombos() {
        $$('.combo-card').forEach(function (card) {
            var addBtn = card.querySelector('.combo-add-btn');
            if (addBtn) {
                addBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var comboId = card.getAttribute('data-combo-id');
                    // For now, just animate the button
                    addBtn.classList.add('pulse-anim');
                    addBtn.textContent = '✓ Added';
                    setTimeout(function () {
                        addBtn.classList.remove('pulse-anim');
                        addBtn.textContent = '+ Add Combo';
                    }, 1200);
                });
            }
        });
    }

    /* ── Scroll animations ───────────────────────────────────────────── */
    function initScrollAnimations() {
        var animElements = $$('.pulse-animate');
        if (!animElements.length) return;

        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        var delay = parseInt(entry.target.getAttribute('data-delay')) || 0;
                        setTimeout(function () {
                            entry.target.classList.add('animate-in');
                        }, delay);
                        observer.unobserve(entry.target);
                    }
                });
            }, {
                threshold: CFG.scrollThreshold || 0.1,
                rootMargin: '0px 0px -40px 0px'
            });

            animElements.forEach(function (el) { observer.observe(el); });
        } else {
            // Fallback: show all immediately
            animElements.forEach(function (el) { el.classList.add('animate-in'); });
        }
    }

    /* ── Parallax hero background ────────────────────────────────────── */
    function initParallax() {
        var heroBg = $('.pulse-hero-bg');
        if (!heroBg || !CFG.parallaxEnabled) return;

        window.addEventListener('scroll', function () {
            var scrollY = window.scrollY || window.pageYOffset;
            var rate = scrollY * (CFG.parallaxSpeed || 0.5);
            heroBg.style.transform = 'translate3d(0,' + rate + 'px,0)';
        }, { passive: true });
    }

    /* ── Order counter ───────────────────────────────────────────────── */
    function initOrderCounter() {
        var counter = $('#pulseOrderCounter');
        if (!counter) return;

        function update() {
            var total = 0;
            cart.forEach(function (item) {
                var dish = ITEMS[item.menu_id];
                if (dish) {
                    total += (Number(dish.price) || 0) * item.quantity;
                }
            });
            counter.textContent = money(total);
        }

        // Update on cart changes (via MutationObserver on badge)
        var badge = $('#cartBadge');
        if (badge) {
            var mo = new MutationObserver(function () { update(); });
            mo.observe(badge, { attributes: true, childList: true, characterData: true });
        }
        update();
    }

    /* ── Reorder from previous session ───────────────────────────────── */
    function initReorder() {
        var reorderKey = 'pulse_reorder_' + RESTAURANT_SLUG;
        var pending = readJSON(reorderKey, null);
        if (!pending || !pending.items || !pending.items.length) return;

        // Show reorder prompt
        var prompt = $('#reorderPrompt');
        var acceptBtn = $('#reorderAccept');
        var dismissBtn = $('#reorderDismiss');

        if (!prompt || !acceptBtn) return;

        prompt.style.display = 'block';

        acceptBtn.addEventListener('click', function () {
            pending.items.forEach(function (item) {
                setCartQty(item.menu_id, item.quantity);
            });
            updateCartBadge();
            prompt.style.display = 'none';
            writeJSON(reorderKey, null);
        });

        if (dismissBtn) {
            dismissBtn.addEventListener('click', function () {
                prompt.style.display = 'none';
                writeJSON(reorderKey, null);
            });
        }
    }

    /* ── Save order state for potential reorder ──────────────────────── */
    function saveOrderState() {
        if (cart.length > 0) {
            writeJSON('pulse_reorder_' + RESTAURANT_SLUG, { items: cart, timestamp: Date.now() });
        }
    }

    /* ── Page load animation ─────────────────────────────────────────── */
    function initLoadAnimation() {
        var header = $('.pulse-header');
        var hero = $('.pulse-hero');
        var sections = $$('.pulse-section');
        var footer = $('.pulse-footer');

        var timeline = CFG.animationTimeline || 0.8;

        if (header) {
            header.style.opacity = '0';
            header.style.transform = 'translateY(-20px)';
            setTimeout(function () {
                header.style.transition = 'opacity ' + timeline + 's ease, transform ' + timeline + 's ease';
                header.style.opacity = '1';
                header.style.transform = 'translateY(0)';
            }, 50);
        }

        if (hero) {
            hero.style.opacity = '0';
            setTimeout(function () {
                hero.style.transition = 'opacity ' + (timeline + 0.2) + 's ease';
                hero.style.opacity = '1';
            }, 150);
        }

        sections.forEach(function (section, i) {
            section.style.opacity = '0';
            section.style.transform = 'translateY(24px)';
            setTimeout(function () {
                section.style.transition = 'opacity ' + timeline + 's ease, transform ' + timeline + 's ease';
                section.style.opacity = '1';
                section.style.transform = 'translateY(0)';
            }, 300 + i * 100);
        });

        if (footer) {
            footer.style.opacity = '0';
            setTimeout(function () {
                footer.style.transition = 'opacity ' + timeline + 's ease';
                footer.style.opacity = '1';
            }, 300 + sections.length * 100 + 100);
        }
    }

    /* ── Category navigation links (smooth scroll) ───────────────────── */
    function initCategoryNavLinks() {
        $$('.category-nav-link').forEach(function (link) {
            link.addEventListener('click', function (e) {
                var href = link.getAttribute('href');
                if (href && href.charAt(0) === '#') {
                    e.preventDefault();
                    var target = $(href);
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            });
        });
    }

    /* ── Close mobile nav on anchor click ────────────────────────────── */
    function initMobileNavLinks() {
        $$('.nav-drawer-link').forEach(function (link) {
            link.addEventListener('click', function () {
                var drawer = $('#mobileNavDrawer');
                var overlay = $('#mobileNavOverlay');
                if (drawer) drawer.classList.remove('open');
                if (overlay) overlay.classList.remove('visible');
                document.body.style.overflow = '';
            });
        });
    }

    /* ── Cleanup: remove placeholder class on real content ───────────── */
    function cleanup() {
        // Remove .pulse-page--loading after animations complete
        setTimeout(function () {
            document.body.classList.remove('pulse-page--loading');
        }, 1500);
    }

    /* ── Initialise ──────────────────────────────────────────────────── */
    function init() {
        initTheme();
        initSettings();
        initMobileNav();
        initSearch();
        initCategoryPills();
        initSpicePills();
        initDietToggles();
        initFavButtons();
        initCartButtons();
        initFloatingCart();
        initCombos();
        initDishSheet();
        initScrollAnimations();
        initParallax();
        initOrderCounter();
        initReorder();
        initCategoryNavLinks();
        initMobileNavLinks();
        updateCartBadge();
        updateCardQtyDisplays();
        updateFavBadges();
        initLoadAnimation();
        cleanup();

        // Save state before unload
        window.addEventListener('beforeunload', saveOrderState);
    }

    /* ── Boot ────────────────────────────────────────────────────────── */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
