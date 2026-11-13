{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function escAttr(s) { return escHtml(s); }
    var ITEMS = @json($jsItems);
    var MODES = @json($modes);
    var STORE_ID = @json((string) $store->id);
    var AUTH_MODE = @json($postOptions->customer_auth_mode ?? '');
    var REFUND_CSRF = @json(csrf_token());
    function refundUrl(id) { return '{{ url('restaurant') }}/' + STORE_ID + '/orders/' + id + '/refund-request'; }
    function refundLabel(s, method) { return s === 'requested' ? T.refundRequested : s === 'approved' ? (method === 'original_payment' ? T.refundApprovedCard : T.refundApproved) : s === 'rejected' ? T.refundRejected : ''; }
    var CHECKOUT_ROUTE = @json($checkoutRoute);
    var CART_LINE_ROUTE_TEMPLATE = @json($cartLineRouteTemplate);
    var SLOT_ROUTE = @json($slotRoute);
    var SKIN_SCAN_ROUTE = @json($skinScanRoute);
    var MY_ORDERS_ROUTE = @json($lmMyOrdersRoute ?? null);
    var COUPON_ROUTE = @json(\Illuminate\Support\Facades\Route::has('store.applyCoupon') ? route('store.applyCoupon', $store->id) : null);
    var SKIN_PROFILE_ROUTE = @json($skinProfileRoute);
    // Feature-wiring pack: story rail / item reviews / loyalty endpoints
    // (see scratchpad FEATURE-WIRING.md — all three are existing platform
    // JSON endpoints, built from $store in index.blade.php's data-prep block).
    var STORIES_ROUTE = @json($storiesRoute);
    var MBR_BASE = @json($mbrBase);
    var LOYALTY_BALANCE_ROUTE = @json($loyaltyBalanceRoute);
    var CSRF = @json(csrf_token());
    var CUR = {
        sign: @json($curSign),
        gap: @json($curGap),
        pos: @json($curPos),
        decimals: @json($curDecimals),
    };
    // Mirrors Order::computeTax() exactly — client estimate of the real store
    // tax, never a fabricated fee. 0 when no tax is configured.
    var TAX = { charge: @json($taxCharge), type: @json($taxType) };
    function computeTax(subtotal) {
        if (TAX.charge <= 0 || subtotal <= 0) { return 0; }
        var raw = TAX.type === 'fixed' ? TAX.charge : subtotal * (TAX.charge / 100);
        var f = Math.pow(10, CUR.decimals);
        return Math.round(raw * f) / f;
    }
    var T = {
        addToBasket: @json($vocab['add_to_cart'] ?? ___('Add to Basket')),
        chooseOption: @json(___('Please choose an option.')),
        outOfStock: @json(___('Out of stock')),
        cartEmpty: @json(___('Your basket is empty')),
        nameRequired: @json(___('Please enter your name.')),
        addressRequired: @json(___('Please enter a delivery address.')),
        placing: @json(___('Placing order…')),
        genericError: @json(___('Something went wrong. Please try again.')),
        added: @json(___('Added to basket')),
        removed: @json(___('Removed')),
        saved: @json(___('Saved to wishlist')),
        soldOut: @json(___('Sold out')),
        removeFav: @json(___('Remove from favorites')),
        linkCopied: @json(___('Link copied')),
        reorder: @json(___('Reorder')),
        track: @json(___('Track')),
        items: @json(___('items')),
        ordersError: @json(___('Could not load your orders.')),
        requestRefund: @json(___('Request refund')),
        refundReasonPrompt: @json(___('Tell us why (optional):')),
        refundSent: @json(___('Refund request sent')),
        refundRequested: @json(___('Refund requested')),
        refundApproved: @json(___('Refunded to wallet')),
        refundApprovedCard: @json(___('Refunded to card')),
        refundRejected: @json(___('Refund declined')),
        couponApplied: @json(___('Coupon applied')),
        couponInvalid: @json(___('Coupon code is invalid.')),
        remove: @json(___('Remove')),
        analyzing: @json(___('Analyzing…')),
        scanFace: @json(___('Scan Your Face')),
        allCaughtUp: @json(___("You're all caught up.")),
        today: @json(___('Today')),
        yesterday: @json(___('Yesterday')),
        daysAgo: @json(___(':count days ago')),
        skinProgress: @json(___('Your Skin Progress')),
        genericDesc: @json(___('A high-performance formula designed to deliver visible results with regular use — clean, lightweight and gentle on skin.')),
        anonymous: @json(___('Anonymous')),
        chooseRatingFirst: @json(___('Choose a rating first')),
        reviewThanks: @json(___('Thank you! Your review will appear once approved.')),
        reviewFailed: @json(___('Could not send your review. Please try again.')),
        loyaltyPoints: @json(___('points')),
        loyaltyStamps: @json(___('stamps')),
        loyaltyApply: @json(___('Apply :amount off your order')),
    };

    var app = document.getElementById('lmApp');
    if (!app) { return; }

    /* ---------------- dark mode ---------------- */
    var THEME_KEY = 'lumiere-theme-' + STORE_ID;
    var themeSwitch = document.getElementById('lmThemeSwitch');
    var themeIcon = document.getElementById('lmProfileThemeIcon');
    function isDark() {
        var attr = document.documentElement.getAttribute('data-theme');
        if (attr) { return attr === 'dark'; }
        try { return window.matchMedia('(prefers-color-scheme: dark)').matches; } catch (e) { return false; }
    }
    function paintTheme() {
        var dark = isDark();
        if (themeSwitch) { themeSwitch.setAttribute('aria-checked', dark ? 'true' : 'false'); }
        if (themeIcon) {
            themeIcon.innerHTML = dark
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/></svg>';
        }
    }
    try {
        var savedTheme = window.localStorage.getItem(THEME_KEY);
        if (savedTheme) { document.documentElement.setAttribute('data-theme', savedTheme); }
    } catch (e) { /* storage may be unavailable — system preference stands */ }
    if (themeSwitch) {
        themeSwitch.addEventListener('click', function () {
            var next = isDark() ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { window.localStorage.setItem(THEME_KEY, next); } catch (e) { /* best-effort */ }
            paintTheme();
        });
    }
    paintTheme();

    /* ---------------- currency ---------------- */
    function money(n) {
        var amount = Number(n) || 0;
        var str;
        try {
            str = amount.toLocaleString(document.documentElement.lang || undefined, {
                minimumFractionDigits: CUR.decimals, maximumFractionDigits: CUR.decimals,
            });
        } catch (e) { str = amount.toFixed(CUR.decimals); }
        return '⁦' + (CUR.pos === '1' ? (CUR.sign + CUR.gap + str) : (str + CUR.gap + CUR.sign)) + '⁩';
    }

    /* ---------------- toast ---------------- */
    var toastTimer;
    function toast(msg) {
        var el = document.getElementById('lmToast');
        if (!el) { return; }
        el.textContent = msg;
        el.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.classList.remove('show'); }, 1700);
    }

    /* ---------------- screen navigation ---------------- */
    var TABBAR_SCREENS = { home: 1, scan: 1, basket: 1, calendar: 1, profile: 1, favorites: 1, orders: 1 };
    var stack = ['home'];
    function currentScreen() { return stack[stack.length - 1] || 'home'; }
    function paintScreen(name) {
        app.querySelectorAll('.lm-screen').forEach(function (el) {
            el.hidden = el.getAttribute('data-screen') !== name;
        });
        app.querySelectorAll('.lm-tab').forEach(function (tab) {
            tab.setAttribute('aria-current', tab.getAttribute('data-nav') === name ? 'true' : 'false');
        });
        var tabbar = document.getElementById('lmTabbar');
        if (tabbar) { tabbar.hidden = !TABBAR_SCREENS[name]; }
        window.scrollTo(0, 0);
    }
    function go(name) {
        if (!name) { return; }
        if (name !== currentScreen()) { stack.push(name); }
        paintScreen(name);
        // Remember the active screen for THIS tab so a same-tab reload (a
        // language switch is a full navigation) lands back here, not on home.
        try { window.sessionStorage.setItem('mb-screen-' + STORE_ID, name); } catch (e) {}
        if (name === 'basket') { renderBasket(); }
        if (name === 'checkout') { renderCheckout(); }
        if (name === 'calendar') { renderCalendar(); }
        if (name === 'favorites') { renderFavorites(); }
        if (name === 'orders') { renderOrders(); }
    }
    function back() {
        if (stack.length > 1) { stack.pop(); }
        paintScreen(currentScreen());
    }

    /* ---------------- onboarding / sign-in overlay (first-time GUEST only) ---------------- */
    var ONBOARD_KEY = 'lumiere-onboarded-' + STORE_ID;
    var IS_GUEST = @json(! $customer);
    function closeOverlays() {
        ['lmOb1', 'lmOb2', 'lmAuth'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.hidden = true; }
        });
        try { window.localStorage.setItem(ONBOARD_KEY, '1'); } catch (e) { /* best-effort */ }
    }
    function openOverlay(id) {
        // Real auth: 'lmAuth' routes to the shared, wired auth sheet (mbAuthOpen)
        // instead of the presentational stub. Close any onboarding card first so
        // the real sheet isn't stacked over a full-screen overlay. Demo stores
        // fall through to the presentational lmAuth overlay.
        if (id === 'lmAuth' && AUTH_MODE !== 'demo' && window.mbAuthOpen) {
            ['lmOb1', 'lmOb2', 'lmAuth'].forEach(function (oid) {
                var el = document.getElementById(oid); if (el) { el.hidden = true; }
            });
            window.mbAuthOpen();
            return;
        }
        ['lmOb1', 'lmOb2', 'lmAuth'].forEach(function (oid) {
            var el = document.getElementById(oid);
            if (el) { el.hidden = oid !== id; }
        });
    }
    (function bootOverlay() {
        if (!IS_GUEST) { return; }
        var seen = false;
        try { seen = window.localStorage.getItem(ONBOARD_KEY) === '1'; } catch (e) { seen = false; }
        if (!seen) { openOverlay('lmOb1'); }
    })();
    var lmProfileSigninBtn = document.getElementById('lmProfileSigninBtn');
    if (lmProfileSigninBtn) {
        lmProfileSigninBtn.addEventListener('click', function () { openOverlay('lmAuth'); });
    }

    /* ---------------- wishlist ---------------- */
    var WISH_KEY = 'lumiere-wish-' + STORE_ID;
    var wish = {};
    try { (JSON.parse(window.localStorage.getItem(WISH_KEY) || '[]') || []).forEach(function (id) { wish[id] = true; }); } catch (e) { wish = {}; }
    function saveWish() { try { window.localStorage.setItem(WISH_KEY, JSON.stringify(Object.keys(wish))); } catch (e) { /* best-effort */ } }
    function paintWishButtons(id) {
        app.querySelectorAll('[data-heart="' + id + '"]').forEach(function (btn) {
            btn.setAttribute('aria-pressed', wish[id] ? 'true' : 'false');
        });
    }
    function toggleWish(id) {
        if (wish[id]) { delete wish[id]; toast(T.removed); } else { wish[id] = true; toast(T.saved); }
        saveWish();
        paintWishButtons(id);
        syncFavCount();
        if (currentScreen() === 'favorites') { renderFavorites(); }
    }
    Object.keys(ITEMS).forEach(function (id) { paintWishButtons(id); });

    /* ---------------- favorites screen ---------------- */
    var favGrid = document.getElementById('lmFavGrid');
    var favEmpty = document.getElementById('lmFavEmpty');
    var favCount = document.getElementById('lmFavCount');
    function favIds() {
        // preserve insertion order (most-recently-saved handled by localStorage order)
        return Object.keys(wish).filter(function (id) { return ITEMS[id]; });
    }
    function syncFavCount() {
        if (!favCount) { return; }
        var n = favIds().length;
        favCount.textContent = n;
        favCount.hidden = n === 0;
    }
    function favCardHtml(item) {
        var img = item.img
            ? '<img src="' + item.img + '" alt="" loading="lazy" data-csp-error="hide">'
            : '<div class="lm-ptilewrap-ph" role="img" aria-label="' + escAttr(item.name) + '"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 2c4 5 8 9.5 8 14a8 8 0 01-16 0c0-4.5 4-9 8-14z"/></svg></div>';
        var brand = item.brand ? '<div class="lm-pbrand">' + escHtml(item.brand) + '</div>' : '';
        var stock = (item.stockBadge && !item.soldOut)
            ? '<span class="lm-stock lm-stock--' + item.stockBadge.type + '">' + escHtml(item.stockBadge.text) + '</span>' : '';
        var sold = item.soldOut ? '<span class="lm-soldout-tag">' + escHtml(T.soldOut || 'Sold out') + '</span>' : '';
        return '<article class="lm-pcard" data-lm-pid="' + item.id + '">'
            + '<div class="lm-ptilewrap">' + sold
            + '<a href="?item=' + item.id + '" class="lm-media-link" data-open-item-link="' + item.id + '" aria-label="' + escAttr(item.name) + '">' + img + '</a>'
            + '<button type="button" class="lm-corner lm-heart" data-heart="' + item.id + '" aria-pressed="true" aria-label="' + escAttr(T.removeFav || 'Remove from favorites') + '">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20s-7-4.5-9.5-9A5 5 0 0112 5a5 5 0 019.5 6c-2.5 4.5-9.5 9-9.5 9z"/></svg></button>'
            + '</div>'
            + '<a href="?item=' + item.id + '" class="lm-pinfo" data-open-item-link="' + item.id + '">'
            + brand
            + '<div class="lm-pname">' + escHtml(item.name) + '</div>'
            + '<div class="lm-prow"><span class="lm-price">' + escHtml(item.priceFormatted) + '</span></div>'
            + stock
            + '</a></article>';
    }
    function renderFavorites() {
        if (!favGrid) { return; }
        var ids = favIds();
        if (!ids.length) {
            favGrid.innerHTML = '';
            favGrid.hidden = true;
            if (favEmpty) { favEmpty.hidden = false; }
            return;
        }
        if (favEmpty) { favEmpty.hidden = true; }
        favGrid.hidden = false;
        favGrid.innerHTML = ids.map(function (id) { return favCardHtml(ITEMS[id]); }).join('');
    }
    syncFavCount();

    /* ---------------- order history ---------------- */
    var ordersLoaded = false;
    var ORDER_LABELS = {
        unpaid: @json(___('Awaiting payment')), pending: @json(___('Received')), new: @json(___('Received')),
        preparing: @json(___('Preparing')), ready: @json(___('Ready')),
        out_for_delivery: @json(___('On the way')), delivered: @json(___('Delivered')),
        completed: @json(___('Completed')), cancelled: @json(___('Cancelled'))
    };
    function orderStatusLabel(s) {
        return ORDER_LABELS[s] || (s ? String(s).charAt(0).toUpperCase() + String(s).slice(1).replace(/_/g, ' ') : '');
    }
    function reorder(ids) {
        if (!ids || !ids.length) { return; }
        var added = 0;
        ids.forEach(function (id) { if (ITEMS[String(id)]) { addToCart(String(id), null, null); added++; } });
        if (added) { go('basket'); } else { toast(T.ordersError); }
    }
    (function () {
        var ol = document.getElementById('lmOrdersList');
        if (ol) {
            ol.addEventListener('click', function (e) {
                var b = e.target.closest('[data-reorder]');
                if (!b) { return; }
                var ids = (b.getAttribute('data-reorder') || '').split(',').filter(Boolean);
                reorder(ids);
            });
        }
    })();
    // Device-local guest history: render THIS device's recent orders for a guest
    // (no account), each with a Track link, plus a sign-in note to sync. Returns
    // the number of rows rendered so the caller can fall back to the sign-in
    // prompt when there is nothing on this device.
    function renderGuestLocalOrders(list) {
        var local = (window.mbGuestOrders ? window.mbGuestOrders.list(STORE_ID) : []);
        if (!local.length) { list.innerHTML = ''; return 0; }
        list.innerHTML = local.map(function (o) {
            var d = o.at ? new Date(o.at) : null;
            var when = d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
            var canReorder = (o.itemIds || []).some(function (id) { return ITEMS[String(id)]; });
            return '<div class="lm-order">'
                + '<div class="lm-order-top"><b>' + escHtml(T.orderThisDevice || '{{ ___('Recent order') }}') + '</b></div>'
                + '<div class="lm-order-meta">' + escHtml(when) + (o.itemCount ? ' · ' + o.itemCount + ' ' + escHtml(T.items) : '') + (o.total ? ' · ' + escHtml(o.total) : '') + '</div>'
                + '<div class="lm-order-actions">'
                + (o.trackUrl ? '<a class="lm-order-btn" href="' + escAttr(o.trackUrl) + '">' + escHtml(T.track) + '</a>' : '')
                + (canReorder ? '<button type="button" class="lm-order-btn lm-order-btn--p" data-reorder="' + escAttr((o.itemIds || []).join(',')) + '">' + escHtml(T.reorder) + '</button>' : '')
                + '</div></div>';
        }).join('')
            + '<div class="lm-order lm-order--signin"><div class="lm-order-meta">' + escHtml('{{ ___('Sign in to sync your orders across devices.') }}') + '</div>'
            + '<div class="lm-order-actions"><button type="button" class="lm-order-btn lm-order-btn--p lm-guest-signin">' + escHtml('{{ ___('Sign in') }}') + '</button></div></div>';
        return local.length;
    }

    function renderOrders() {
        var list = document.getElementById('lmOrdersList');
        var empty = document.getElementById('lmOrdersEmpty');
        var guest = document.getElementById('lmOrdersGuest');
        var loading = document.getElementById('lmOrdersLoading');
        if (!list) { return; }
        function show(el) { [list, empty, guest, loading].forEach(function (n) { if (n) { n.hidden = n !== el; } }); }
        if (IS_GUEST || !MY_ORDERS_ROUTE) { show(renderGuestLocalOrders(list) ? list : guest); return; }
        if (ordersLoaded) { show(list.children.length ? list : empty); }
        else { show(loading); }
        fetch(MY_ORDERS_ROUTE, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { if (r.status === 401) { throw 'guest'; } return r.json(); })
            .then(function (res) {
                ordersLoaded = true;
                var orders = (res && res.orders) || [];
                if (!orders.length) { list.innerHTML = ''; show(empty); return; }
                list.innerHTML = orders.map(function (o) {
                    var d = o.at ? new Date(o.at) : null;
                    var when = d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
                    var canReorder = (o.itemIds || []).some(function (id) { return ITEMS[String(id)]; });
                    return '<div class="lm-order">'
                        + '<div class="lm-order-top"><b>' + escHtml(o.number) + '</b>'
                        + '<span class="lm-order-status lm-os--' + escAttr(o.status) + '">' + escHtml(orderStatusLabel(o.status)) + '</span></div>'
                        + '<div class="lm-order-meta">' + escHtml(when) + ' · ' + o.itemCount + ' ' + escHtml(T.items) + ' · ' + escHtml(o.total) + '</div>'
                        + '<div class="lm-order-actions">'
                        + (o.trackUrl ? '<a class="lm-order-btn" href="' + escAttr(o.trackUrl) + '">' + escHtml(T.track) + '</a>' : '')
                        + (canReorder ? '<button type="button" class="lm-order-btn lm-order-btn--p" data-reorder="' + escAttr((o.itemIds || []).join(',')) + '">' + escHtml(T.reorder) + '</button>' : '')
                        + (o.canRequestRefund ? '<button type="button" class="lm-order-btn" data-refund-request="' + escAttr(o.id) + '">' + escHtml(T.requestRefund) + '</button>' : '')
                        + (o.refundStatus ? '<span class="lm-order-refund lm-order-refund--' + escAttr(o.refundStatus) + '">' + escHtml(refundLabel(o.refundStatus, o.refundMethod)) + '</span>' : '')
                        + '</div></div>';
                }).join('');
                show(list);
            })
            .catch(function (e) {
                if (e === 'guest') { show(guest); return; }
                if (!ordersLoaded && loading) { loading.querySelector('p').textContent = T.ordersError; }
            });
    }
    (function () {
        var sib = document.getElementById('lmOrdersSignin');
        if (sib) { sib.addEventListener('click', function () { openOverlay('lmAuth'); }); }
        // The sign-in CTA appended under the device-local guest orders is added
        // dynamically, so bind it via delegation.
        document.addEventListener('click', function (e) {
            if (e.target && e.target.closest && e.target.closest('.lm-guest-signin')) { openOverlay('lmAuth'); }
        });
    })();

    // Customer refund request (delegated). Prompts a reason, POSTs, and reflects
    // the new "requested" state on the row without a reload.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-refund-request]');
        if (!btn) { return; }
        e.preventDefault();
        var id = btn.getAttribute('data-refund-request');
        var reason = window.prompt(T.refundReasonPrompt) ;
        if (reason === null) { return; } // cancelled
        btn.disabled = true;
        fetch(refundUrl(id), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                       'X-CSRF-TOKEN': REFUND_CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ reason: reason })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, b: j }; }); })
        .then(function (res) {
            if (res.ok && res.b.success) {
                var badge = document.createElement('span');
                badge.className = 'lm-order-refund lm-order-refund--requested';
                badge.textContent = T.refundRequested;
                btn.replaceWith(badge);
                if (window.toast) { toast(T.refundSent); }
            } else {
                btn.disabled = false;
                if (window.toast) { toast((res.b && res.b.message) || T.ordersError); }
            }
        }).catch(function () { btn.disabled = false; });
    });

    /* ---------------- F3: stories rail + full-screen viewer ---------------- */
    var STORY_VIEWED_KEY = 'lumiere-stories-viewed-' + STORE_ID;
    var storiesData = [];
    var storyIndex = 0;
    var storyTimer = null;
    var STORY_SEG_MS = 5000;
    function readViewedStories() {
        try { return JSON.parse(window.localStorage.getItem(STORY_VIEWED_KEY) || '[]') || []; } catch (e) { return []; }
    }
    function markStoryViewed(id) {
        var seen = readViewedStories();
        if (seen.indexOf(id) === -1) {
            seen.push(id);
            try { window.localStorage.setItem(STORY_VIEWED_KEY, JSON.stringify(seen)); } catch (e) { /* best-effort */ }
        }
    }
    var storiesRailEl = document.getElementById('lmStoriesRail');
    function renderStoriesRail() {
        if (!storiesRailEl) { return; }
        if (!storiesData.length) { storiesRailEl.hidden = true; storiesRailEl.innerHTML = ''; return; }
        var viewed = readViewedStories();
        storiesRailEl.innerHTML = '';
        storiesData.forEach(function (s, i) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'lm-story-item';
            btn.setAttribute('data-story-open', String(i));
            var isViewed = viewed.indexOf(s.id) !== -1;
            var thumb = s.media_url
                ? '<img src="' + s.media_url + '" alt="" data-csp-error="hide">'
                : '';
            btn.innerHTML =
                '<span class="lm-story-ring' + (isViewed ? ' is-viewed' : '') + '"><span class="lm-story-thumb">' + thumb + '</span></span>' +
                '<span class="lm-story-label"></span>';
            btn.querySelector('.lm-story-label').textContent = s.caption ? String(s.caption).slice(0, 14) : @json(___('Story'));
            storiesRailEl.appendChild(btn);
        });
        storiesRailEl.hidden = false;
    }
    function fetchStories() {
        if (!STORIES_ROUTE) { return; }
        fetch(STORIES_ROUTE, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (payload) {
                var list = payload && Array.isArray(payload.data) ? payload.data : (Array.isArray(payload) ? payload : []);
                storiesData = list;
                renderStoriesRail();
            })
            .catch(function () { /* rail stays hidden — never an empty shell */ });
    }
    fetchStories();

    var storyViewerEl = document.getElementById('lmStoryViewer');
    function stopStoryTimer() { if (storyTimer) { clearInterval(storyTimer); storyTimer = null; } }
    function closeStoryViewer() {
        stopStoryTimer();
        if (storyViewerEl) { storyViewerEl.hidden = true; }
        renderStoriesRail(); // repaint viewed rings
    }
    function paintStorySegments() {
        var bars = document.getElementById('lmSvBars');
        if (!bars) { return; }
        bars.innerHTML = '';
        storiesData.forEach(function (s, i) {
            var seg = document.createElement('span');
            seg.className = 'lm-sv-seg';
            var fill = document.createElement('i');
            fill.style.width = (i < storyIndex ? '100' : '0') + '%';
            seg.appendChild(fill);
            bars.appendChild(seg);
        });
    }
    function runStoryTimer() {
        stopStoryTimer();
        var bars = document.getElementById('lmSvBars');
        var segEl = bars ? bars.children[storyIndex] : null;
        var fillEl = segEl ? segEl.querySelector('i') : null;
        var start = Date.now();
        storyTimer = setInterval(function () {
            var pct = Math.min(100, ((Date.now() - start) / STORY_SEG_MS) * 100);
            if (fillEl) { fillEl.style.width = pct + '%'; }
            if (pct >= 100) { nextStory(); }
        }, 60);
    }
    function paintStory() {
        var story = storiesData[storyIndex];
        if (!story) { closeStoryViewer(); return; }
        var media = document.getElementById('lmSvMedia');
        var cap = document.getElementById('lmSvCaption');
        var cta = document.getElementById('lmSvCta');
        if (media) { media.src = story.media_url || ''; }
        if (cap) {
            if (story.caption) { cap.textContent = story.caption; cap.hidden = false; } else { cap.hidden = true; }
        }
        if (cta) {
            if (story.cta_url) {
                cta.hidden = false;
                cta.textContent = story.cta_label || @json(___('Order now'));
                cta.setAttribute('href', story.cta_url);
                if (/^\//.test(story.cta_url)) { cta.removeAttribute('target'); cta.removeAttribute('rel'); }
                else { cta.setAttribute('target', '_blank'); cta.setAttribute('rel', 'noopener'); }
            } else {
                cta.hidden = true;
            }
        }
        markStoryViewed(story.id);
        paintStorySegments();
        runStoryTimer();
    }
    function nextStory() {
        if (storyIndex < storiesData.length - 1) { storyIndex += 1; paintStory(); }
        else { closeStoryViewer(); }
    }
    function prevStory() {
        storyIndex = storyIndex > 0 ? storyIndex - 1 : 0;
        paintStory();
    }
    function openStoryViewer(index) {
        if (!storiesData.length || !storyViewerEl) { return; }
        storyIndex = Math.max(0, Math.min(storiesData.length - 1, index));
        storyViewerEl.hidden = false;
        paintStory();
    }
    if (storiesRailEl) {
        storiesRailEl.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-story-open]');
            if (btn) { openStoryViewer(Number(btn.getAttribute('data-story-open'))); }
        });
    }
    if (storyViewerEl) {
        var svClose = document.getElementById('lmSvClose');
        if (svClose) { svClose.addEventListener('click', closeStoryViewer); }
        var svStage = storyViewerEl.querySelector('.lm-sv-stage');
        if (svStage) {
            svStage.addEventListener('click', function (e) {
                if (e.target.closest('#lmSvCta')) { return; }
                var rect = svStage.getBoundingClientRect();
                var x = e.clientX - rect.left;
                if (x < rect.width / 2) { prevStory(); } else { nextStory(); }
            });
            var svTouchX = null;
            svStage.addEventListener('touchstart', function (e) {
                svTouchX = e.changedTouches[0].clientX;
            }, { passive: true });
            svStage.addEventListener('touchend', function (e) {
                if (svTouchX === null) { return; }
                var dx = e.changedTouches[0].clientX - svTouchX;
                svTouchX = null;
                if (Math.abs(dx) < 40) { return; }
                if (dx > 0) { prevStory(); } else { nextStory(); }
            }, { passive: true });
        }
        document.addEventListener('keydown', function (e) {
            if (storyViewerEl.hidden) { return; }
            if (e.key === 'Escape') { closeStoryViewer(); }
            else if (e.key === 'ArrowLeft') { prevStory(); }
            else if (e.key === 'ArrowRight') { nextStory(); }
        });
    }

    /* ---------------- F4: client-side product search (live filter) ---------------- */
    var lmSearchInput = document.getElementById('lmSearchInput');
    var lmActiveCat = '';
    var lmSearchEmptyEl = document.getElementById('lmSearchEmpty');
    var lmWatchlistSectEl = document.getElementById('lmWatchlistSect');
    function applyHomeFilter() {
        var q = lmSearchInput ? lmSearchInput.value.trim().toLowerCase() : '';
        var cards = app.querySelectorAll('[data-screen="home"] .lm-grid .lm-pcard[data-lm-pid]');
        var visible = 0;
        Array.prototype.forEach.call(cards, function (card) {
            var item = ITEMS[card.getAttribute('data-lm-pid')];
            var hay = item ? ((item.name || '') + ' ' + (item.brand || '')).toLowerCase() : '';
            var matchQ = !q || hay.indexOf(q) !== -1;
            var matchCat = !lmActiveCat || (item && String(item.cat) === lmActiveCat);
            var show = matchQ && matchCat;
            card.hidden = !show;
            if (show) { visible += 1; }
        });
        if (lmSearchEmptyEl) { lmSearchEmptyEl.hidden = !((q || lmActiveCat) && visible === 0); }
        // Curated watchlist isn't part of the filtered set — hide while a
        // search or a category filter is active so it doesn't read as matching.
        if (lmWatchlistSectEl) { lmWatchlistSectEl.hidden = !!(q || lmActiveCat); }
    }
    if (lmSearchInput) { lmSearchInput.addEventListener('input', applyHomeFilter); }
    var lmCatRow = document.getElementById('lmCatRow');
    if (lmCatRow) {
        lmCatRow.addEventListener('click', function (e) {
            var chip = e.target.closest('.lm-catchip');
            if (!chip) { return; }
            lmActiveCat = chip.getAttribute('data-cat') || '';
            lmCatRow.querySelectorAll('.lm-catchip').forEach(function (c) {
                var on = c === chip;
                c.classList.toggle('is-on', on);
                c.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            applyHomeFilter();
        });
    }

    /* ---------------- client-side cart preview (kept in sync with the real
       server-side cart via each item's own addRoute / the cart-line route) ---------------- */
    var CART_KEY = 'lumiere-cart-' + STORE_ID;
    var cart = [];
    try { cart = JSON.parse(window.localStorage.getItem(CART_KEY) || '[]') || []; } catch (e) { cart = []; }
    function saveCart() { try { window.localStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch (e) { /* best-effort */ } }
    // Drop stored lines whose menu item no longer exists — otherwise the cart
    // badge counts a line that the (missing-item-skipping) render never shows,
    // reading "N items" over an empty basket. Prune once so badge/list/total agree.
    (function () { var before = cart.length; cart = cart.filter(function (l) { return l && ITEMS[l.id]; }); if (cart.length !== before) { saveCart(); } })();
    function itemUnitPrice(item, variantId) {
        if (variantId) {
            var v = (item.variants || []).filter(function (x) { return x.id === variantId; })[0];
            if (v) { return v.price; }
        }
        return item.price;
    }
    function cartCount() { return cart.reduce(function (n, l) { return n + l.qty; }, 0); }
    function cartSubtotal() {
        return cart.reduce(function (sum, l) {
            var item = ITEMS[l.id];
            return item ? sum + itemUnitPrice(item, l.variantId) * l.qty : sum;
        }, 0);
    }
    function paintCartBadge() {
        // Updates every .lm-badge in the app — the mobile tabbar FAB badge
        // (#lmCartBadge) AND the desktop top-nav basket badge (#lmCartBadgeTop,
        // see partials/_topnav.blade.php) share this one class/contract.
        var n = cartCount();
        app.querySelectorAll('.lm-badge').forEach(function (badge) {
            badge.textContent = String(n);
            badge.hidden = n === 0;
        });
    }
    function cartLineRoute(lineId) { return CART_LINE_ROUTE_TEMPLATE.replace('999999999', String(lineId)); }
    function postAddToCart(itemId, variantId, qty) {
        var item = ITEMS[itemId];
        if (!item) { return Promise.reject(new Error('unknown item')); }
        var fd = new FormData();
        fd.append('_token', CSRF);
        if (variantId) { fd.append('variation', variantId); }
        fd.append('quantity', qty);
        return fetch(item.addRoute, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (data) {
                if (!data || !data.success) { throw new Error((data && data.message) || 'add-to-cart failed'); }
                return data;
            });
    }
    function addToCart(itemId, variantId, fromEl) {
        var item = ITEMS[itemId];
        if (!item || item.soldOut) { return; }
        if ((item.variants || []).length && !variantId) { toast(T.chooseOption); return; }
        var key = itemId + (variantId ? ':' + variantId : '');
        postAddToCart(itemId, variantId, 1).then(function (data) {
            var existing = cart.filter(function (l) { return l.key === key; })[0];
            if (existing) { existing.qty += 1; if (data.line_id) { existing.lineId = data.line_id; } }
            else { cart.push({ key: key, id: itemId, variantId: variantId || null, qty: 1, lineId: data.line_id || null }); }
            saveCart();
            paintCartBadge();
            toast(T.added);
            flyToBasket(fromEl, item);
        }).catch(function () { toast(T.genericError); });
    }
    function flyToBasket(fromEl, item) {
        var fab = app.querySelector('.lm-fab');
        // The mobile tabbar (and its .lm-fab basket button) is hidden at the
        // desktop breakpoint — fall back to the desktop top-nav basket button
        // as the fly-to-basket landing target so the animation still lands
        // somewhere visible instead of an invisible, zero-size element.
        if (!fab || !fab.getClientRects().length) {
            fab = app.querySelector('.lm-topnav [data-nav="basket"]') || fab;
        }
        if (!fromEl || !fab || !item.img) { return; }
        var a = fromEl.getBoundingClientRect();
        var b = fab.getBoundingClientRect();
        var el = document.createElement('div');
        el.className = 'lm-fly';
        var img = document.createElement('img');
        img.src = item.img;
        img.alt = '';
        el.appendChild(img);
        el.style.left = (a.left + a.width / 2 - 26) + 'px';
        el.style.top = (a.top + a.height / 2 - 30) + 'px';
        document.body.appendChild(el);
        try {
            el.animate([
                { transform: 'translate(0,0) scale(1)', opacity: 1 },
                { transform: 'translate(' + (b.left + b.width / 2 - (a.left + a.width / 2)) + 'px,' + (b.top + b.height / 2 - (a.top + a.height / 2)) + 'px) scale(.25)', opacity: .3 },
            ], { duration: 550, easing: 'cubic-bezier(.5,-0.2,.4,1)' }).onfinish = function () { el.remove(); };
        } catch (e) { el.remove(); }
    }
    paintCartBadge();

    function renderBasket() {
        var linesEl = document.getElementById('lmBasketLines');
        var emptyEl = document.getElementById('lmBasketEmpty');
        var summaryEl = document.getElementById('lmBasketSummary');
        if (!linesEl) { return; }
        linesEl.innerHTML = '';
        if (!cart.length) {
            emptyEl.hidden = false;
            summaryEl.hidden = true;
            return;
        }
        emptyEl.hidden = true;
        summaryEl.hidden = false;
        cart.forEach(function (line) {
            var item = ITEMS[line.id];
            if (!item) { return; }
            var unit = itemUnitPrice(item, line.variantId);
            var row = document.createElement('div');
            row.className = 'lm-citem';
            var variant = (item.variants || []).filter(function (v) { return v.id === line.variantId; })[0];
            row.innerHTML =
                '<div class="lm-top">' +
                    '<div class="lm-cthumb">' + (item.img ? '<img src="' + item.img + '" alt="" data-csp-error="hide">' : '') + '</div>' +
                    '<div class="lm-cinfo"><div class="lm-cname"></div><div class="lm-ccol"></div></div>' +
                    '<div class="lm-cprice"></div>' +
                '</div>' +
                '<div class="lm-cfoot">' +
                    '<button type="button" class="lm-trash" data-del aria-label="' + T.remove + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5a2 2 0 012-2h2a2 2 0 012 2v2M6 7l1 13a1 1 0 001 1h8a1 1 0 001-1l1-13"/></svg></button>' +
                    '<div class="lm-stepper"><button type="button" class="lm-step" data-dec aria-label="' + T.remove + '">&minus;</button><span class="lm-qty"></span><button type="button" class="lm-step" data-inc>+</button></div>' +
                '</div>';
            row.querySelector('.lm-cname').textContent = item.name + (variant ? ' · ' + variant.label : '');
            row.querySelector('.lm-ccol').textContent = item.brand || '';
            row.querySelector('.lm-cprice').textContent = money(unit * line.qty);
            row.querySelector('.lm-qty').textContent = line.qty;
            row.querySelector('[data-del]').addEventListener('click', function () { removeCartLine(line); });
            row.querySelector('[data-inc]').addEventListener('click', function () { changeCartQty(line, 1); });
            row.querySelector('[data-dec]').addEventListener('click', function () { changeCartQty(line, -1); });
            linesEl.appendChild(row);
        });
        var sub = cartSubtotal();
        var tax = computeTax(sub);
        document.getElementById('lmBasketSubtotal').textContent = money(sub);
        var taxRow = document.getElementById('lmBasketTaxRow');
        if (taxRow) { taxRow.hidden = tax <= 0; }
        var taxEl = document.getElementById('lmBasketTax');
        if (taxEl) { taxEl.textContent = money(tax); }
        document.getElementById('lmBasketDiscount').textContent = money(0);
        document.getElementById('lmBasketTotal').textContent = money(sub + tax);
        var lbl = document.getElementById('lmBasketCheckoutLabel');
        if (lbl) { lbl.textContent = @json(___('Checkout')) + ' · ' + money(sub + tax); }
    }
    function zeroLineOnServer(lineId) {
        if (!lineId) { return; }
        var fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('quantity', '0');
        fetch(cartLineRoute(lineId), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } }).catch(function () { /* best-effort */ });
    }
    function removeCartLine(line) {
        cart = cart.filter(function (l) { return l.key !== line.key; });
        saveCart(); paintCartBadge(); renderBasket();
        zeroLineOnServer(line.lineId);
    }
    function changeCartQty(line, delta) {
        var item = ITEMS[line.id];
        var fd = new FormData();
        fd.append('_token', CSRF);
        if (delta > 0) {
            postAddToCart(line.id, line.variantId, 1).then(function (data) {
                line.qty += 1; if (data.line_id) { line.lineId = data.line_id; }
                saveCart(); paintCartBadge(); renderBasket();
            }).catch(function () { toast(T.genericError); });
            return;
        }
        if (line.qty > 1) { line.qty -= 1; saveCart(); paintCartBadge(); renderBasket();
            if (line.lineId) {
                fd.append('quantity', String(line.qty));
                fetch(cartLineRoute(line.lineId), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } }).catch(function () {});
            }
        } else { removeCartLine(line); }
    }

    /* ---------------- product detail screen ---------------- */
    var selectedVariant = null;
    var currentProductId = null;
    function stockText(item) {
        if (item.soldOut) { return { type: 'out', text: T.outOfStock }; }
        if (item.stockBadge) { return item.stockBadge; }
        return null;
    }
    function openProduct(id) {
        var item = ITEMS[id];
        if (!item) { return; }
        currentProductId = id;
        selectedVariant = (item.variants || []).length === 1 ? item.variants[0].id : null;

        var imgEl = document.getElementById('lmProductImg');
        var phEl = document.getElementById('lmProductImgPh');
        if (item.img) { imgEl.src = item.img; imgEl.hidden = false; phEl.hidden = true; }
        else { imgEl.hidden = true; phEl.hidden = false; }
        renderProductThumbs(item);

        var brandEl = document.getElementById('lmProductBrand');
        if (item.brand) { brandEl.textContent = item.brand; brandEl.hidden = false; } else { brandEl.hidden = true; }
        document.getElementById('lmProductTitle').textContent = item.name;
        document.getElementById('lmProductDesc').textContent = item.desc || T.genericDesc;

        var ratingEl = document.getElementById('lmProductRating');
        if (item.rating) {
            ratingEl.hidden = false;
            document.getElementById('lmProductRatingVal').textContent = item.rating;
            document.getElementById('lmProductRatingCount').textContent = item.ratingCount ? ('· ' + item.ratingCount) : '';
        } else { ratingEl.hidden = true; }

        var benefitsWrap = document.getElementById('lmProductBenefitsWrap');
        var benefitsEl = document.getElementById('lmProductBenefits');
        if ((item.benefits || []).length) {
            benefitsEl.innerHTML = item.benefits.map(function (b) { return '<span class="lm-benefitchip"></span>'; }).join('');
            Array.prototype.forEach.call(benefitsEl.children, function (chip, i) { chip.textContent = item.benefits[i]; });
            benefitsWrap.hidden = false;
        } else { benefitsWrap.hidden = true; }

        var ingWrap = document.getElementById('lmProductIngredientsWrap');
        var ingEl = document.getElementById('lmProductIngredients');
        if ((item.ingredients || []).length) {
            ingEl.innerHTML = item.ingredients.map(function () { return '<span class="lm-benefitchip"></span>'; }).join('');
            Array.prototype.forEach.call(ingEl.children, function (chip, i) { chip.textContent = item.ingredients[i]; });
            ingWrap.hidden = false;
        } else { ingWrap.hidden = true; }

        var variantsWrap = document.getElementById('lmProductVariantsWrap');
        var variantsEl = document.getElementById('lmProductVariants');
        variantsEl.innerHTML = '';
        if ((item.variants || []).length > 1) {
            item.variants.forEach(function (v) {
                var chip = document.createElement('button');
                chip.type = 'button';
                chip.className = 'lm-optchip';
                chip.textContent = v.label;
                chip.setAttribute('aria-pressed', String(v.id === selectedVariant));
                if (v.stockQty !== null && v.stockQty <= 0) { chip.disabled = true; }
                chip.addEventListener('click', function () {
                    selectedVariant = v.id;
                    Array.prototype.forEach.call(variantsEl.children, function (c) { c.setAttribute('aria-pressed', 'false'); });
                    chip.setAttribute('aria-pressed', 'true');
                    paintProductPrice(item);
                });
                variantsEl.appendChild(chip);
            });
            variantsWrap.hidden = false;
        } else { variantsWrap.hidden = true; }

        var stockEl = document.getElementById('lmProductStock');
        var sb = stockText(item);
        if (sb) { stockEl.hidden = false; stockEl.textContent = sb.text; stockEl.className = 'lm-stock lm-stock--' + sb.type; }
        else { stockEl.hidden = true; }

        var heartBtn = document.getElementById('lmProductHeart');
        heartBtn.setAttribute('data-heart', id);
        heartBtn.setAttribute('aria-pressed', wish[id] ? 'true' : 'false');

        var addBtn = document.getElementById('lmProductAddBtn');
        addBtn.setAttribute('data-add', id);
        addBtn.disabled = !!item.soldOut;
        document.getElementById('lmProductAddLabel').textContent = item.soldOut ? T.outOfStock : T.addToBasket;

        paintProductPrice(item);
        loadReviews(id);

        try {
            var url = new URL(window.location.href);
            url.searchParams.set('item', id);
            window.history.replaceState(window.history.state, '', url.pathname + url.search);
        } catch (e) { /* best-effort */ }

        go('product');
    }
    function renderProductThumbs(item) {
        var wrap = document.getElementById('lmProductThumbs');
        if (!wrap) { return; }
        var imgs = (item && item.gallery) ? item.gallery : [];
        wrap.innerHTML = '';
        if (imgs.length < 2) { wrap.hidden = true; return; }
        wrap.hidden = false;
        var mainImg = document.getElementById('lmProductImg');
        var phEl = document.getElementById('lmProductImgPh');
        imgs.forEach(function (src, i) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'lm-pd-thumb';
            b.setAttribute('aria-current', i === 0 ? 'true' : 'false');
            var im = document.createElement('img');
            im.src = src; im.alt = ''; im.setAttribute('data-csp-error', 'hide');
            b.appendChild(im);
            b.addEventListener('click', function () {
                mainImg.src = src; mainImg.hidden = false;
                if (phEl) { phEl.hidden = true; }
                Array.prototype.forEach.call(wrap.children, function (c) { c.setAttribute('aria-current', c === b ? 'true' : 'false'); });
            });
            wrap.appendChild(b);
        });
    }
    function paintProductPrice(item) {
        var unit = itemUnitPrice(item, selectedVariant);
        document.getElementById('lmProductPrice').textContent = money(unit);
    }

    /* ---------------- F6: item reviews ---------------- */
    function starsMarkup(n) {
        n = Math.max(0, Math.min(5, Math.round(Number(n) || 0)));
        var out = '';
        for (var i = 1; i <= 5; i++) { out += '<span class="lm-star-ic' + (i <= n ? ' is-on' : '') + '">★</span>'; }
        return out;
    }
    var reviewStars = 0;
    var reviewStarsWrap = document.getElementById('lmReviewStars');
    function paintReviewStars() {
        if (!reviewStarsWrap) { return; }
        Array.prototype.forEach.call(reviewStarsWrap.querySelectorAll('[data-star]'), function (btn) {
            btn.classList.toggle('is-on', Number(btn.getAttribute('data-star')) <= reviewStars);
        });
    }
    if (reviewStarsWrap) {
        Array.prototype.forEach.call(reviewStarsWrap.querySelectorAll('[data-star]'), function (btn) {
            btn.addEventListener('click', function () {
                reviewStars = Number(btn.getAttribute('data-star'));
                paintReviewStars();
            });
        });
    }
    var reviewsWriteBtn = document.getElementById('lmReviewsWriteBtn');
    var reviewForm = document.getElementById('lmReviewForm');
    function resetReviewForm() {
        reviewStars = 0;
        paintReviewStars();
        if (reviewForm) {
            reviewForm.hidden = true;
            document.getElementById('lmReviewName').value = '';
            document.getElementById('lmReviewText').value = '';
            var msgEl = document.getElementById('lmReviewMsg');
            if (msgEl) { msgEl.textContent = ''; msgEl.classList.remove('is-bad'); }
        }
        if (reviewsWriteBtn) { reviewsWriteBtn.hidden = false; }
    }
    if (reviewsWriteBtn && reviewForm) {
        reviewsWriteBtn.addEventListener('click', function () {
            reviewForm.hidden = false;
            reviewsWriteBtn.hidden = true;
            var firstStar = reviewStarsWrap && reviewStarsWrap.querySelector('[data-star="1"]');
            if (firstStar) { firstStar.focus(); }
        });
    }
    var reviewCancelBtn = document.getElementById('lmReviewCancelBtn');
    if (reviewCancelBtn) {
        reviewCancelBtn.addEventListener('click', function (e) { e.preventDefault(); resetReviewForm(); });
    }
    if (reviewForm) {
        reviewForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var msgEl = document.getElementById('lmReviewMsg');
            msgEl.textContent = '';
            msgEl.classList.remove('is-bad');
            if (!reviewStars) { msgEl.textContent = T.chooseRatingFirst; msgEl.classList.add('is-bad'); return; }
            if (!currentProductId || !MBR_BASE) { return; }
            var submitBtn = document.getElementById('lmReviewSubmitBtn');
            submitBtn.disabled = true;
            var forId = currentProductId;
            fetch(MBR_BASE + '/' + forId + '/reviews', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({
                    rating: reviewStars,
                    text: document.getElementById('lmReviewText').value.trim() || null,
                    name: document.getElementById('lmReviewName').value.trim() || null,
                }),
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    submitBtn.disabled = false;
                    if (!data || !data.ok) { msgEl.textContent = T.reviewFailed; msgEl.classList.add('is-bad'); return; }
                    msgEl.textContent = T.reviewThanks;
                    setTimeout(function () {
                        resetReviewForm();
                        if (currentProductId === forId) { loadReviews(forId); }
                    }, 1600);
                })
                .catch(function () { submitBtn.disabled = false; msgEl.textContent = T.reviewFailed; msgEl.classList.add('is-bad'); });
        });
    }
    function loadReviews(id) {
        var listEl = document.getElementById('lmReviewsList');
        var summaryEl = document.getElementById('lmReviewsSummary');
        var emptyEl = document.getElementById('lmReviewsEmpty');
        if (!listEl || !MBR_BASE) { return; }
        listEl.innerHTML = '';
        if (summaryEl) { summaryEl.hidden = true; }
        if (emptyEl) { emptyEl.hidden = true; }
        resetReviewForm();
        fetch(MBR_BASE + '/' + id + '/reviews?filter=latest', { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (payload) {
                if (!payload || currentProductId !== id) { return; }
                var count = Number(payload.count || 0);
                if (count > 0 && summaryEl) {
                    summaryEl.hidden = false;
                    document.getElementById('lmRvAvgNum').textContent = Number(payload.average || 0).toFixed(1);
                    document.getElementById('lmRvAvgStars').innerHTML = starsMarkup(payload.average || 0);
                    document.getElementById('lmRvAvgCount').textContent = '(' + count + ')';
                    var distEl = document.getElementById('lmRvDist');
                    distEl.innerHTML = '';
                    [5, 4, 3, 2, 1].forEach(function (star) {
                        var c = Number((payload.distribution || {})[star] || 0);
                        var pct = count ? Math.round((c / count) * 100) : 0;
                        var row = document.createElement('div');
                        row.className = 'lm-rv-bar';
                        var barTrack = document.createElement('i');
                        var barFill = document.createElement('s');
                        barFill.style.width = pct + '%';
                        barTrack.appendChild(barFill);
                        var starLabel = document.createElement('span');
                        starLabel.textContent = String(star);
                        var countLabel = document.createElement('b');
                        countLabel.textContent = String(c);
                        row.appendChild(starLabel);
                        row.appendChild(barTrack);
                        row.appendChild(countLabel);
                        distEl.appendChild(row);
                    });
                } else if (emptyEl) {
                    emptyEl.hidden = false;
                }
                (payload.data || []).forEach(function (rv) {
                    var card = document.createElement('div');
                    card.className = 'lm-rv-item';
                    var top = document.createElement('div');
                    top.className = 'lm-rv-item-top';
                    var starsSpan = document.createElement('span');
                    starsSpan.className = 'lm-rv-stars';
                    starsSpan.innerHTML = starsMarkup(rv.stars);
                    var nameSpan = document.createElement('span');
                    nameSpan.className = 'lm-rv-name';
                    nameSpan.textContent = rv.name || T.anonymous;
                    var dateSpan = document.createElement('span');
                    dateSpan.className = 'lm-rv-date';
                    dateSpan.textContent = relativeDay(String(rv.created_at || '').slice(0, 10));
                    top.appendChild(starsSpan);
                    top.appendChild(nameSpan);
                    top.appendChild(dateSpan);
                    var body = document.createElement('p');
                    body.className = 'lm-rv-body';
                    body.textContent = rv.body || '';
                    card.appendChild(top);
                    card.appendChild(body);
                    listEl.appendChild(card);
                });
            })
            .catch(function () { /* best-effort — section stays hidden-empty */ });
    }

    /* ---------------- scan ---------------- */
    var SKIN_KEY = 'lumiere-skin-' + STORE_ID;
    function readSkinProfile() {
        try { return JSON.parse(window.localStorage.getItem(SKIN_KEY) || 'null'); } catch (e) { return null; }
    }
    function saveSkinProfile(profile) {
        try { window.localStorage.setItem(SKIN_KEY, JSON.stringify(profile)); } catch (e) { /* best-effort */ }
        if (SKIN_PROFILE_ROUTE) {
            var fd = new FormData();
            fd.append('_token', CSRF);
            Object.keys(profile).forEach(function (k) { fd.append(k, profile[k]); });
            fetch(SKIN_PROFILE_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } }).catch(function () { /* best-effort — local copy already saved */ });
        }
    }
    function scoreFromMetrics(m) {
        var avgBad = (m.acne + m.oily + m.wrinkles + m.dryness) / 4;
        return Math.max(10, Math.min(96, Math.round(100 - avgBad)));
    }
    function relativeDay(iso) {
        try {
            var then = new Date(iso + 'T00:00:00');
            var days = Math.round((new Date(new Date().toDateString()) - then) / 86400000);
            if (days <= 0) { return T.today; }
            if (days === 1) { return T.yesterday; }
            return T.daysAgo.replace(':count', String(days));
        } catch (e) { return ''; }
    }
    function ringSvg(pct, size, stroke) {
        size = size || 74; stroke = stroke || 8;
        pct = Math.max(0, Math.min(100, pct));
        var r = (size - stroke) / 2, c = 2 * Math.PI * r, off = c * (1 - pct / 100);
        return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '" aria-hidden="true">' +
            '<circle cx="' + (size / 2) + '" cy="' + (size / 2) + '" r="' + r + '" fill="none" stroke="var(--lm-hair)" stroke-width="' + stroke + '"/>' +
            '<circle cx="' + (size / 2) + '" cy="' + (size / 2) + '" r="' + r + '" fill="none" stroke="var(--lm-accent)" stroke-width="' + stroke + '" stroke-linecap="round" stroke-dasharray="' + c + '" stroke-dashoffset="' + off + '" style="transition:stroke-dashoffset .6s ease"/>' +
        '</svg>';
    }
    function paintHomeRoutineCard() {
        var profile = readSkinProfile();
        var pct = profile ? scoreFromMetrics(profile) : 40;
        var ringbox = document.querySelector('[data-screen="home"] .lm-ringbox');
        if (ringbox) { ringbox.innerHTML = ringSvg(pct) + '<span class="lm-pct">' + pct + '%</span>'; }
        var sub = document.getElementById('lmRoutineSub');
        if (sub) {
            sub.textContent = profile
                ? @json(___('Last analysis :when')).replace(':when', relativeDay(profile.date))
                : @json(___('Scan your face to build a personalized routine.'));
        }
    }
    paintHomeRoutineCard();

    var scanBtn = document.getElementById('lmScanBtn');
    if (scanBtn) {
        var scanBtnLabel = document.getElementById('lmScanBtnLabel');
        var originalScanLabel = scanBtnLabel ? scanBtnLabel.textContent : T.scanFace;
        scanBtn.addEventListener('click', function () {
            scanBtn.disabled = true;
            if (scanBtnLabel) { scanBtnLabel.textContent = T.analyzing; }
            var finish = function (metrics) {
                var prev = readSkinProfile();
                var profile = {
                    date: new Date().toISOString().slice(0, 10),
                    acne: metrics.acne, oily: metrics.oily, wrinkles: metrics.wrinkles, dryness: metrics.dryness,
                    skinAge: metrics.skinAge,
                };
                paintScanResult(profile, prev);
                saveSkinProfile(profile);
                scanBtn.disabled = false;
                if (scanBtnLabel) { scanBtnLabel.textContent = originalScanLabel; }
                paintHomeRoutineCard();
                go('scanresult');
            };
            if (SKIN_SCAN_ROUTE) {
                var fd = new FormData();
                fd.append('_token', CSRF);
                fetch(SKIN_SCAN_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        finish({
                            acne: Number(data.acne) || 18, oily: Number(data.oily) || 45,
                            wrinkles: Number(data.wrinkles) || 12, dryness: Number(data.dryness) || 30,
                            skinAge: Number(data.skin_age || data.skinAge) || 25,
                        });
                    })
                    .catch(function () { finish({ acne: 18, oily: 45, wrinkles: 12, dryness: 30, skinAge: 25 }); });
            } else {
                setTimeout(function () { finish({ acne: 18, oily: 45, wrinkles: 12, dryness: 30, skinAge: 25 }); }, 1200);
            }
        });
    }
    function paintScanResult(profile, prevProfile) {
        var skinAgeEl = document.getElementById('lmSkinAge');
        if (skinAgeEl) { skinAgeEl.textContent = profile.skinAge; }
        ['acne', 'oily', 'wrinkles', 'dryness'].forEach(function (k) {
            var val = document.getElementById('lmMetric-' + k + '-val');
            var bar = document.getElementById('lmMetric-' + k + '-bar');
            if (val) { val.textContent = profile[k] + '%'; }
            if (bar) { bar.style.width = profile[k] + '%'; }
        });
        var titleEl = document.getElementById('lmProgressStatTitle');
        if (titleEl) {
            if (prevProfile) {
                var delta = scoreFromMetrics(profile) - scoreFromMetrics(prevProfile);
                var sign = delta > 0 ? '+' : '';
                titleEl.textContent = T.skinProgress + ' ' + sign + delta + '%';
            } else {
                titleEl.textContent = T.skinProgress;
            }
        }
    }

    /* ---------------- calendar / routine checklist ---------------- */
    var ROUTINE_KEY = 'lumiere-routine-' + STORE_ID;
    var ROUTINE_STEPS = ['cleanse', 'tone', 'serum', 'moisturize', 'spf'];
    function readRoutineHistory() {
        try { return JSON.parse(window.localStorage.getItem(ROUTINE_KEY) || '{}') || {}; } catch (e) { return {}; }
    }
    function saveRoutineHistory(hist) {
        try { window.localStorage.setItem(ROUTINE_KEY, JSON.stringify(hist)); } catch (e) { /* best-effort */ }
    }
    function dayKey(offset) {
        var d = new Date();
        d.setDate(d.getDate() - offset);
        return d.toISOString().slice(0, 10);
    }
    var calMode = 'daily';
    function renderCalendar() {
        var hist = readRoutineHistory();
        var today = hist[dayKey(0)] || {};
        ROUTINE_STEPS.forEach(function (step) {
            var btn = app.querySelector('[data-screen="calendar"] [data-routine="' + step + '"]');
            if (btn) { btn.setAttribute('aria-pressed', today[step] ? 'true' : 'false'); }
        });
        var done = ROUTINE_STEPS.filter(function (s) { return today[s]; }).length;
        var pct = Math.round((done / ROUTINE_STEPS.length) * 100);
        var ringbox = document.querySelector('#lmCalDailyCard .lm-ringbox');
        if (ringbox) { ringbox.innerHTML = ringSvg(pct) + '<span class="lm-pct">' + pct + '%</span>'; }
        var doneEl = document.getElementById('lmCalDone');
        if (doneEl) { doneEl.textContent = done + '/' + ROUTINE_STEPS.length + ' ' + @json(___('Routine Checklist')); }

        var bars = document.getElementById('lmCalBars');
        if (bars) {
            bars.innerHTML = '';
            for (var i = 6; i >= 0; i--) {
                var key = dayKey(i);
                var rec = hist[key] || {};
                var doneCount = ROUTINE_STEPS.filter(function (s) { return rec[s]; }).length;
                var barPct = Math.round((doneCount / ROUTINE_STEPS.length) * 100);
                var label = '';
                try { label = new Intl.DateTimeFormat(document.documentElement.lang || undefined, { weekday: 'short' }).format(new Date(key + 'T00:00:00')); } catch (e) { label = key.slice(5); }
                var col = document.createElement('div');
                col.className = 'lm-bcol';
                col.innerHTML = '<div class="lm-bfill"><i style="height:' + barPct + '%"></i></div><span class="lm-blabel"></span>';
                col.querySelector('.lm-blabel').textContent = label;
                bars.appendChild(col);
            }
        }
    }
    var calDailyBtn = document.getElementById('lmCalDaily');
    var calMonthlyBtn = document.getElementById('lmCalMonthly');
    if (calDailyBtn && calMonthlyBtn) {
        calDailyBtn.addEventListener('click', function () {
            calMode = 'daily';
            calDailyBtn.setAttribute('aria-pressed', 'true'); calMonthlyBtn.setAttribute('aria-pressed', 'false');
            document.getElementById('lmCalDailyCard').hidden = false;
            document.getElementById('lmCalMonthlyCard').hidden = true;
        });
        calMonthlyBtn.addEventListener('click', function () {
            calMode = 'monthly';
            calMonthlyBtn.setAttribute('aria-pressed', 'true'); calDailyBtn.setAttribute('aria-pressed', 'false');
            document.getElementById('lmCalDailyCard').hidden = true;
            document.getElementById('lmCalMonthlyCard').hidden = false;
        });
    }
    function toggleRoutineStep(step) {
        var hist = readRoutineHistory();
        var key = dayKey(0);
        hist[key] = hist[key] || {};
        hist[key][step] = !hist[key][step];
        saveRoutineHistory(hist);
        renderCalendar();
    }

    /* ---------------- checkout ---------------- */
    var chosenSlot = null;
    function selectedMode() {
        var pressed = app.querySelector('[data-screen="checkout"] [data-mode][aria-pressed="true"]');
        if (pressed) {
            return { value: pressed.getAttribute('data-mode'), needsAddress: pressed.getAttribute('data-needs-address') === '1', needsSlot: pressed.getAttribute('data-needs-slot') === '1' };
        }
        var single = document.getElementById('lmModeSingle');
        if (single) { return { value: single.value, needsAddress: single.getAttribute('data-needs-address') === '1', needsSlot: single.getAttribute('data-needs-slot') === '1' }; }
        var only = MODES[0] || { value: 'in_store', needsAddress: false, needsSlot: false };
        return only;
    }
    function loadSlots() {
        var wrap = document.getElementById('lmSlots');
        if (!wrap) { return; }
        wrap.innerHTML = '';
        chosenSlot = null;
        fetch(SLOT_ROUTE + '?type=' + encodeURIComponent(selectedMode().value), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.enabled || !data.slots || !data.slots.length) { return; }
                data.slots.slice(0, 8).forEach(function (slot) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'lm-slot';
                    btn.textContent = slot.label;
                    btn.disabled = !!slot.full;
                    btn.setAttribute('aria-pressed', 'false');
                    btn.addEventListener('click', function () {
                        chosenSlot = slot.value;
                        wrap.querySelectorAll('.lm-slot').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
                        btn.setAttribute('aria-pressed', 'true');
                    });
                    wrap.appendChild(btn);
                });
            })
            .catch(function () { /* slot picker is best-effort; checkout still works without it */ });
    }
    function updateModeFields() {
        var mode = selectedMode();
        var addrField = document.getElementById('lmAddressField');
        var slotField = document.getElementById('lmSlotField');
        if (addrField) { addrField.hidden = !mode.needsAddress; }
        if (slotField) { slotField.hidden = !mode.needsSlot; }
        if (mode.needsSlot) { loadSlots(); }
    }
    // Crave-style delivery-address autocomplete (geocode suggest + use-my-location).
    // The input lives in the DOM the whole time, so wiring once here is safe even
    // while the address field is still hidden.
    if (window.mbAddrAutocomplete) {
        window.mbAddrAutocomplete(document.getElementById('lmAddress'), {
            suggest: document.getElementById('lmAddrSuggest'),
            locBtn: document.getElementById('lmAddrLoc')
        });
    }

    /* Address map picker — lumiere-native .lm-msheet bottom sheet. lumiere has no
       shared drawer primitive, so this block owns a small open/close (scrim +
       .is-open slide + Escape + focus return); the map logic itself is the shared
       window.mbMapPicker engine, filling the same #lmAddress the order submits. */
    (function () {
        var mapBtn = document.getElementById('lmMapBtn');
        if (!mapBtn) { return; }
        var sheet = document.getElementById('lmMapSheet');
        var scrim = document.getElementById('lmMapScrim');
        if (!window.mbMapPicker || !sheet || !scrim) { mapBtn.style.display = 'none'; return; }
        var title = document.getElementById('lmMapTitle');
        var lastFocus = null;
        function onKey(e) { if (e.key === 'Escape') { closeSheet(); } }
        function openSheet() {
            lastFocus = document.activeElement;
            scrim.hidden = false; sheet.hidden = false;
            sheet.removeAttribute('inert'); sheet.setAttribute('aria-hidden', 'false');
            requestAnimationFrame(function () { scrim.classList.add('is-open'); sheet.classList.add('is-open'); });
            if (title) { setTimeout(function () { try { title.focus(); } catch (e) { /* ignore */ } }, 60); }
            document.addEventListener('keydown', onKey);
        }
        function closeSheet() {
            scrim.classList.remove('is-open'); sheet.classList.remove('is-open');
            sheet.setAttribute('aria-hidden', 'true');
            document.removeEventListener('keydown', onKey);
            setTimeout(function () {
                sheet.hidden = true; scrim.hidden = true; sheet.setAttribute('inert', '');
                if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) { /* ignore */ } }
            }, 300);
        }
        scrim.addEventListener('click', closeSheet);
        window.mbMapPicker({
            trigger: mapBtn,
            modal:   sheet,
            map:     document.getElementById('lmMap'),
            input:   document.getElementById('lmAddress'),
            preview: document.getElementById('lmMapPreview'),
            confirm: document.getElementById('lmMapConfirm'),
            close:   document.getElementById('lmMapClose'),
            toggle:  function (open) { if (open) { openSheet(); } else { closeSheet(); } },
            i18n: { locating: @json(___('Finding address…')), hint: @json(___('Move the map to place the pin')) }
        });
    })();

    /* Clear saved data on this device — profile account row (the profile screen is
       a guest-reachable tab, so the control lives there, not the checkout footer).
       Wipes lumiere's OWN cart / wishlist / stories / scan / routine / onboarding
       keys; the shared engine keeps the light/dark choice (THEME_KEY matches
       /theme/), then reloads. */
    (function () {
        var cd = document.getElementById('lmClearData');
        if (!cd) { return; }
        if (!window.mbClearDeviceData) {
            cd.style.display = 'none';
            var h = document.getElementById('lmClearDataHint'); if (h) { h.style.display = 'none'; }
            return;
        }
        cd.addEventListener('click', function () {
            window.mbClearDeviceData({
                keys: [
                    'lumiere-cart-' + STORE_ID,
                    'lumiere-wish-' + STORE_ID,
                    'lumiere-stories-viewed-' + STORE_ID,
                    'lumiere-skin-' + STORE_ID,
                    'lumiere-routine-' + STORE_ID,
                    'lumiere-onboarded-' + STORE_ID
                ],
                storeId: STORE_ID,
                slug: @json($store->slug ?? ''),
                confirm: @json(___('Clear your cart, favourites and saved addresses on this device?'))
            });
        });
    })();

    var lmCouponDiscount = 0, lmCouponSig = '';
    /* Bug batch 7 · 4: the cart lines go to the coupon check (submitOrder's `items` shape) — a buy-X-get-Y coupon is
       priced on them, exactly as the placed order is — and the quote is refreshed when the basket changes. */
    function couponLines() {
        return cart.map(function (l) {
            return { id: l.id, quantity: l.qty, variants: l.variantId || 0, extras: (l.extras || []).map(function (x) { return { id: (x && x.id) || x }; }) };
        });
    }
    function lmCouponForm(code, sub) {
        var m = selectedMode(), fd = new FormData();
        fd.append('_token', CSRF); fd.append('code', code); fd.append('subtotal', sub);
        fd.append('items', JSON.stringify(couponLines())); fd.append('ordering-type', (m && m.value) || '');
        return fd;
    }
    function lmCouponSigFor(sub) { var m = selectedMode(); return JSON.stringify([sub, couponLines(), (m && m.value) || '']); }
    function lmRequoteCoupon(sub) {
        var field = document.getElementById('lmCouponField');
        if (!field || !field.value || !COUPON_ROUTE) { return; }
        var sig = lmCouponSigFor(sub);
        if (sig === lmCouponSig) { return; }
        lmCouponSig = sig;
        var code = field.value;
        fetch(COUPON_ROUTE, { method: 'POST', body: lmCouponForm(code, sub), headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (field.value !== code || lmCouponSig !== sig) { return; }
                lmCouponDiscount = d && d.success ? (parseFloat(d.discount) || 0) : 0;
                renderCheckout();
            })
            .catch(function () {});
    }
    function renderCheckout() {
        var sub = cartSubtotal();
        var tax = computeTax(sub);
        lmRequoteCoupon(sub); // bug batch 7 · 4: the basket changed since the quote → re-quote
        var disc = Math.min(lmCouponDiscount, sub);
        var subEl = document.getElementById('lmCheckoutSubtotal');
        var totEl = document.getElementById('lmCheckoutTotal');
        var dRow = document.getElementById('lmCheckoutDiscountRow');
        var dEl = document.getElementById('lmCheckoutDiscount');
        var taxRow = document.getElementById('lmCheckoutTaxRow');
        var taxEl = document.getElementById('lmCheckoutTax');
        if (subEl) { subEl.textContent = money(sub); }
        if (taxRow) { taxRow.hidden = tax <= 0; }
        if (taxEl) { taxEl.textContent = money(tax); }
        if (dRow && dEl) {
            if (disc > 0) { dRow.hidden = false; dEl.textContent = '-' + money(disc); }
            else { dRow.hidden = true; }
        }
        if (totEl) { totEl.textContent = money(Math.max(0, sub + tax - disc)); }
        updateModeFields();
        loadLoyaltyCheckout();
    }
    (function () {
        var applyBtn = document.getElementById('lmCouponApply');
        if (!applyBtn || !COUPON_ROUTE) { return; }
        var input = document.getElementById('lmCouponInput');
        var msg = document.getElementById('lmCouponMsg');
        var field = document.getElementById('lmCouponField');
        applyBtn.addEventListener('click', function () {
            var code = (input.value || '').trim();
            if (!code) { return; }
            var fd = lmCouponForm(code, cartSubtotal());
            var sentSig = lmCouponSigFor(cartSubtotal());
            applyBtn.disabled = true;
            fetch(COUPON_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    applyBtn.disabled = false;
                    msg.hidden = false;
                    if (d && d.success) {
                        field.value = code;
                        lmCouponDiscount = parseFloat(d.discount) || 0;
                        lmCouponSig = sentSig;
                        msg.textContent = d.message || T.couponApplied;
                        msg.classList.remove('is-bad');
                    } else {
                        field.value = '';
                        lmCouponDiscount = 0;
                        msg.textContent = (d && d.message) || T.couponInvalid;
                        msg.classList.add('is-bad');
                    }
                    renderCheckout();
                })
                .catch(function () { applyBtn.disabled = false; });
        });
    })();

    /* ---------------- K3: loyalty redeem (checkout) ---------------- */
    var lmLoyToggle = document.getElementById('lmLoyToggle');
    var lmLoyaltyRedeemField = document.getElementById('lmLoyaltyRedeemField');
    if (lmLoyToggle && lmLoyaltyRedeemField) {
        lmLoyToggle.addEventListener('click', function () {
            var next = lmLoyToggle.getAttribute('aria-checked') !== 'true';
            lmLoyToggle.setAttribute('aria-checked', next ? 'true' : 'false');
            lmLoyaltyRedeemField.value = next ? '1' : '0';
        });
    }
    var loyaltyCheckoutChecked = false;
    function loadLoyaltyCheckout() {
        // Balance is auth:customer (guests 401) — never bothered for a guest,
        // and only ever fetched once per app load since it can't change mid-session.
        if (loyaltyCheckoutChecked || IS_GUEST || !LOYALTY_BALANCE_ROUTE) { return; }
        loyaltyCheckoutChecked = true;
        fetch(LOYALTY_BALANCE_ROUTE, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.active || !data.ready_to_redeem || !data.reward_discount_display) { return; }
                var teaser = document.getElementById('lmLoyaltyTeaser');
                var box = document.getElementById('lmLoyaltyRedeemBox');
                if (teaser) { teaser.hidden = true; }
                if (box) {
                    box.hidden = false;
                    var unit = data.type === 'stamps' ? T.loyaltyStamps : T.loyaltyPoints;
                    document.getElementById('lmLoyBalanceVal').textContent = String(data.balance) + ' ' + unit;
                    if (data.tier) { document.getElementById('lmLoyTierVal').textContent = data.tier; }
                    document.getElementById('lmLoyOfferVal').textContent = T.loyaltyApply.replace(':amount', data.reward_discount_display);
                }
            })
            .catch(function () { /* best-effort — teaser stays as-is */ });
    }
    var placeOrderBtn = document.getElementById('lmPlaceOrderBtn');
    if (placeOrderBtn) {
        placeOrderBtn.addEventListener('click', function () {
            var msg = document.getElementById('lmCheckoutMsg');
            msg.innerHTML = '';
            if (!cart.length) { msg.innerHTML = '<div class="lm-msg lm-msg--error">' + T.cartEmpty + '</div>'; return; }
            var name = document.getElementById('lmName').value.trim();
            var phone = document.getElementById('lmPhone').value.trim();
            if (!name) { msg.innerHTML = '<div class="lm-msg lm-msg--error">' + T.nameRequired + '</div>'; return; }
            var mode = selectedMode();
            if (mode.needsAddress && !document.getElementById('lmAddress').value.trim()) {
                msg.innerHTML = '<div class="lm-msg lm-msg--error">' + T.addressRequired + '</div>'; return;
            }
            var fd = new FormData();
            fd.append('_token', CSRF);
            fd.append('customer_name', name);
            fd.append('phone_number', phone);
            fd.append('type', mode.value);
            if (mode.needsAddress) { fd.append('address', document.getElementById('lmAddress').value.trim()); }
            if (mode.needsSlot && chosenSlot) { fd.append('scheduled_at', chosenSlot); }
            // K3: forward the loyalty redeem toggle — the server re-derives the
            // discount from the diner's real balance, no client amount is trusted.
            fd.append('loyalty_redeem', (lmLoyaltyRedeemField && lmLoyaltyRedeemField.value === '1') ? '1' : '0');
            var couponField = document.getElementById('lmCouponField');
            if (couponField && couponField.value) { fd.append('coupon_code', couponField.value); }
            var giftField = document.getElementById('lmGiftCardInput');
            if (giftField && giftField.value.trim()) { fd.append('gift_card_code', giftField.value.trim()); }

            placeOrderBtn.disabled = true;
            msg.innerHTML = '<div class="lm-msg">' + T.placing + '</div>';
            fetch(CHECKOUT_ROUTE, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    placeOrderBtn.disabled = false;
                    if (data && data.success) {
                        // Device-local guest history: remember this order on THIS
                        // device so a guest (no account) still sees it on the
                        // Orders screen. Snapshot BEFORE the cart is cleared.
                        try {
                            if (data.track_token && window.mbGuestOrders) {
                                var _sub = cartSubtotal();
                                window.mbGuestOrders.save(STORE_ID, {
                                    token: data.track_token,
                                    trackUrl: data.redirect || null,
                                    total: money(_sub + computeTax(_sub)),
                                    itemCount: cart.reduce(function (n, l) { return n + l.qty; }, 0),
                                    itemIds: cart.map(function (l) { return l.id; })
                                });
                            }
                        } catch (e) {}
                        cart = []; saveCart(); paintCartBadge();
                        var orderNoEl = document.getElementById('lmOrderNo');
                        if (orderNoEl) { orderNoEl.textContent = data.track_token ? ('#' + data.track_token) : ''; }
                        var trackLink = document.getElementById('lmTrackOrderLink');
                        if (trackLink && data.redirect) {
                            trackLink.href = data.redirect;
                            setTimeout(function () { window.location.href = data.redirect; }, 2600);
                        }
                        go('confirm');
                    } else {
                        msg.innerHTML = '<div class="lm-msg lm-msg--error">' + ((data && data.message) || T.genericError) + '</div>';
                    }
                })
                .catch(function () {
                    placeOrderBtn.disabled = false;
                    msg.innerHTML = '<div class="lm-msg lm-msg--error">' + T.genericError + '</div>';
                });
        });
    }

    /* ---------------- profile: logout ---------------- */
    var logoutBtn = document.getElementById('lmLogoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('_token', CSRF);
            fetch(logoutBtn.getAttribute('data-logout-url'), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                .then(function () { window.location.reload(); })
                .catch(function () { window.location.reload(); });
        });
    }

    /* ---------------- K3: profile Rewards card — highlight the diner's
       current tier when signed in (best-effort; the card itself is already
       server-rendered from the public teaser, so this only ever adds a
       highlight, never blocks/replaces anything if the fetch fails). ---------------- */
    if (!IS_GUEST && LOYALTY_BALANCE_ROUTE && document.querySelector('.lm-rw-tiers')) {
        fetch(LOYALTY_BALANCE_ROUTE, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.active || !data.tier) { return; }
                var el = document.getElementById('lmRwTier' + String(data.tier).replace(/[^a-zA-Z]/g, ''));
                if (el) { el.classList.add('is-current'); }
            })
            .catch(function () { /* best-effort */ });
    }

    /* ---------------- deep link: ?item=ID ---------------- */
    (function bootDeepLink() {
        try {
            var params = new URLSearchParams(window.location.search);
            var deepId = params.get('item');
            if (deepId && ITEMS[deepId]) { openProduct(deepId); }
        } catch (e) { /* best-effort */ }
    })();

    /* Restore the screen across a same-tab reload (a language switch is a full
       navigation, which otherwise dumps the shopper back on home and makes their
       order history look "gone"). sessionStorage is per-tab, so a fresh visit
       still opens on home. Skipped when a ?item deep-link drives the screen. */
    (function restoreScreen() {
        try {
            if (new URLSearchParams(window.location.search).get('item')) { return; }
            var saved = window.sessionStorage.getItem('mb-screen-' + STORE_ID);
            if (saved && saved !== 'home' && document.querySelector('[data-screen="' + saved + '"]')) { go(saved); }
        } catch (e) { /* best-effort */ }
    })();

    /* ---------------- delegated click handling ---------------- */
    app.addEventListener('click', function (e) {
        var el = e.target.closest(
            '[data-nav],[data-back],[data-open],[data-open-item-link],[data-add],[data-heart],' +
            '[data-mode],[data-routine],[data-ov-next],[data-ov-done],[data-see-all]'
        );
        if (!el) { return; }
        var d = el.dataset;

        if (d.add !== undefined) {
            e.preventDefault();
            addToCart(d.add, d.add === String(currentProductId) ? selectedVariant : null, el);
            return;
        }
        if (d.heart !== undefined) {
            e.preventDefault();
            toggleWish(d.heart);
            if (el.classList.contains('lm-heart')) { el.classList.add('lm-pop'); setTimeout(function () { el.classList.remove('lm-pop'); }, 400); }
            return;
        }
        if (d.openItemLink !== undefined) { e.preventDefault(); openProduct(d.openItemLink); return; }
        if (d.open !== undefined) { e.preventDefault(); openProduct(d.open); return; }
        if (d.nav !== undefined) { go(d.nav); return; }
        if (d.back !== undefined) { back(); return; }
        if (d.mode !== undefined) {
            app.querySelectorAll('[data-screen="checkout"] [data-mode]').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
            el.setAttribute('aria-pressed', 'true');
            updateModeFields();
            return;
        }
        if (d.seeAll !== undefined) {
            e.preventDefault();
            if (currentScreen() !== 'home') { go('home'); }
            var grid = document.getElementById('lmProductGrid');
            var si = document.getElementById('lmSearchInput');
            if (si && si.value) { si.value = ''; si.dispatchEvent(new Event('input', { bubbles: true })); }
            if (grid) { grid.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            return;
        }
        if (d.routine !== undefined) { toggleRoutineStep(d.routine); return; }
        if (d.ovNext !== undefined) { openOverlay(d.ovNext); return; }
        if (d.ovDone !== undefined) { closeOverlays(); return; }
    });

    // Notification centre. Both visible bells (mobile #lmBellBtn + desktop
    // #lmBellBtnTop) forward to the hidden #mbNotifBell proxy that the shared
    // mb-notify.js engine binds (real inbox for signed-in diners, recent-orders
    // for guests). The engine toggles #mbNotifDot's [hidden] for unread state;
    // mirror that onto each bell's own dot. If the engine failed to load, fall
    // back to the old "all caught up" toast so the bell is never dead.
    (function () {
        var proxy = document.getElementById('mbNotifBell');
        var srcDot = document.getElementById('mbNotifDot');
        // Both header bells AND the Profile → Notifications row open the panel.
        var bells = app.querySelectorAll('.lm-bellbtn, [data-nb-open]');
        bells.forEach(function (bellBtn) {
            bellBtn.addEventListener('click', function () {
                if (proxy && window.mbNotify) { proxy.click(); }
                else { toast(T.allCaughtUp); }
            });
        });
        function syncDots() {
            var unread = srcDot ? !srcDot.hidden : false;
            bells.forEach(function (b) {
                var dot = b.querySelector('.lm-bell-dot');
                if (dot) { dot.hidden = !unread; }
            });
        }
        if (srcDot) {
            new MutationObserver(syncDots).observe(srcDot, { attributes: true, attributeFilter: ['hidden'] });
            syncDots();
        }
    })();

    renderCalendar();

    /* ---------------- share this store ---------------- */
    (function () {
        var sb = document.getElementById('lmShareBtn');
        if (!sb) { return; }
        sb.addEventListener('click', function () {
            var url = sb.getAttribute('data-share-url') || window.location.href;
            var title = sb.getAttribute('data-share-title') || document.title;
            if (navigator.share) {
                navigator.share({ title: title, url: url }).catch(function () {});
            } else if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () { toast(T.linkCopied || 'Link copied'); }, function () {});
            } else {
                toast(T.linkCopied || 'Link copied');
            }
        });
    })();

    /* ---------------- branch switch (reload with ?branch_id) ---------------- */
    (function () {
        var bs = document.getElementById('lmBranchSel');
        if (!bs) { return; }
        bs.addEventListener('change', function (e) {
            var v = e.target.value;
            if (v) { window.location.assign(v); }
        });
    })();

    /* ---------------- promo banner slider (dots + gentle auto-advance) ------- */
    (function () {
        var track = document.getElementById('lmBannerTrack');
        var dotsWrap = document.getElementById('lmBannerDots');
        if (!track) { return; }
        // Promo-banner CTA: an empty or in-app link_url renders href="#" with
        // data-banner-nav; intercept it and route to the catalog screen via
        // the theme's own screen switcher so a banner is never a dead tap.
        Array.prototype.forEach.call(track.querySelectorAll('.lm-banner[data-banner-nav]'), function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                if (typeof closeStoryViewer === 'function') { closeStoryViewer(); }
                go(el.getAttribute('data-banner-nav') || 'home');
            });
        });
        var slides = track.children;
        if (slides.length < 2) { return; }
        function scrollToSlide(i) {
            // Scroll the horizontal track ONLY. scrollIntoView() also scrolls the whole
            // PAGE up to the banner whenever it is out of view, so the auto-advance timer
            // yanks the shopper back to the top. A relative scrollBy delta from the visual
            // centres is direction-agnostic (correct in both LTR and RTL)
            // [rtl-flex-carousel-translatex] without touching vertical page scroll.
            var s = slides[i]; if (!s) { return; }
            var sr = s.getBoundingClientRect(), tr = track.getBoundingClientRect();
            track.scrollBy({ left: (sr.left + sr.width / 2) - (tr.left + tr.width / 2), behavior: 'smooth' });
        }
        var dots = [];
        if (dotsWrap) {
            for (var i = 0; i < slides.length; i++) {
                var d = document.createElement('i');
                if (i === 0) { d.className = 'on'; }
                (function (idx) {
                    d.addEventListener('click', function () { scrollToSlide(idx); });
                })(i);
                dotsWrap.appendChild(d);
                dots.push(d);
            }
        }
        var cur = 0;
        function paint(i) { cur = i; dots.forEach(function (d, j) { d.className = j === i ? 'on' : ''; }); }
        var raf = null;
        track.addEventListener('scroll', function () {
            if (raf) { return; }
            raf = requestAnimationFrame(function () {
                raf = null;
                // Nearest-to-centre wins — direction-agnostic (scrollLeft is reversed/
                // negative in RTL, so Math.round(scrollLeft/…) lit the wrong dot).
                var tc = track.getBoundingClientRect().left + track.clientWidth / 2, bi = 0, bd = Infinity;
                for (var k = 0; k < slides.length; k++) {
                    var r = slides[k].getBoundingClientRect(), d = Math.abs(r.left + r.width / 2 - tc);
                    if (d < bd) { bd = d; bi = k; }
                }
                if (bi !== cur && dots[bi]) { paint(bi); }
            });
        }, { passive: true });
        // Gentle auto-advance; pauses on interaction / hidden tab.
        var timer = null, touched = false;
        function next() {
            if (touched || document.hidden) { return; }
            scrollToSlide((cur + 1) % slides.length);
        }
        function arm() { timer = setInterval(next, 5000); }
        ['touchstart', 'pointerdown', 'wheel'].forEach(function (ev) {
            track.addEventListener(ev, function () { touched = true; if (timer) { clearInterval(timer); timer = null; } }, { passive: true, once: true });
        });
        arm();
    })();
})();
</script>
