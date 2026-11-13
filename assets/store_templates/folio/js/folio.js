// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* Folio — flip-book storefront behavior.
 * Reads its config from the #folioConfig JSON block; no inline JS in the theme.
 * Library: self-hosted StPageFlip (assets/global/page-flip/, global St.PageFlip).
 *
 * Display modes (SPEC §3):
 *   auto   → usePortrait:true — portrait viewport shows a single page,
 *            landscape shows the double spread (StPageFlip's own logic).
 *   single → portrait rendering forced: usePortrait:true + a narrow stage cap
 *            (CSS [data-folio-display="single"]) + minWidth so the orientation
 *            check always lands portrait.
 *   double → usePortrait:false — the spread renders even on narrow screens.
 * anim=swipe → no book at all: a plain horizontal scroll-snap pager
 *            (a11y / low-end fallback), same toolbar/counter contract.
 *
 * Toolbar: counter, prev/next, first/last, zoom (1×/1.5×/2× wrapper transform
 * + drag-pan; flips disabled while zoomed), fullscreen, lazy thumbnail strip.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    ready(function () {
        var root = document.getElementById('folioTheme');
        var cfgEl = document.getElementById('folioConfig');
        var book = document.getElementById('folioBook');
        if (!root || !cfgEl || !book) { return; } // empty state — nothing to boot

        var cfg;
        try { cfg = JSON.parse(cfgEl.textContent); } catch (e) { return; }

        var els = {
            loader: document.getElementById('folioLoader'),
            error: document.getElementById('folioError'),
            stage: document.getElementById('folioStage'),
            zoomWrap: document.getElementById('folioZoomWrap'),
            pan: document.getElementById('folioPan'),
            cur: document.getElementById('folioCur'),
            total: document.getElementById('folioTotal'),
            first: document.getElementById('folioFirst'),
            prev: document.getElementById('folioPrev'),
            next: document.getElementById('folioNext'),
            last: document.getElementById('folioLast'),
            zoom: document.getElementById('folioZoom'),
            full: document.getElementById('folioFull'),
            thumbs: document.getElementById('folioThumbs'),
            toolbar: root.querySelector('.folio-toolbar')
        };

        var ZOOM_STEPS = [1, 1.5, 2];
        var state = {
            mode: cfg.anim === 'swipe' ? 'swipe' : 'flip',
            flip: null,
            scroller: null,
            count: 0,
            zoom: 1,
            pan: { x: 0, y: 0 }
        };

        // Polish-feature state, declared BEFORE start() runs — the synchronous
        // images/swipe start() path would otherwise read an un-initialised var.
        var deepLinkApplied = false;
        var deepLinkTimer = 0;
        var hotspots = null;
        var spotsLayer = null;
        var sheet = null;
        var sheetEls = {};
        var sheetFocusReturn = null;
        var itemPageIndex = {};
        var flipAnimating = false;
        var flashItem = null;

        function hideLoader() { if (els.loader) { els.loader.hidden = true; } }
        function showError() {
            hideLoader();
            if (els.error) { els.error.hidden = false; }
        }

        var pages = Array.isArray(cfg.pages) ? cfg.pages : [];
        // A5: swap in ≤900px variant URLs for the canvas book (once, at boot).
        var urls = pickVariantUrls(pages.map(function (p) { return p.url; }));
        if (cfg.source === 'pdf' && cfg.pdfUrl) {
            // PDF pipeline: rasterise client-side (vendored pdf.js) into data
            // URLs, then feed the same book/pager path as images mode. The
            // loader stays up for the whole render; a 404/broken PDF shows
            // the error panel instead of a dead book.
            renderPdf(cfg.pdfUrl).then(function (list) {
                if (!list.length) { showError(); return; }
                // Server-side reversal only covers images mode; a PDF's page
                // count is unknown until rasterised, so RTL reverses here.
                if (cfg.rtl) { list.reverse(); }
                start(list);
            }).catch(showError);
        } else if (urls.length) {
            start(urls);
        } else {
            hideLoader();
        }

        function renderPdf(url) {
            if (!window.renderPdfUrlToPages) {
                return Promise.reject(new Error('pdf helper missing'));
            }
            var buffer = document.createElement('div');
            buffer.className = 'folio-pdf-buffer';
            buffer.setAttribute('aria-hidden', 'true');
            root.appendChild(buffer);
            return window.renderPdfUrlToPages(url, buffer, { scale: 2 })
                .then(function () {
                    var out = [];
                    Array.prototype.forEach.call(buffer.querySelectorAll('canvas'), function (c) {
                        out.push(c.toDataURL('image/jpeg', 0.9));
                    });
                    buffer.remove();
                    return out;
                })
                .catch(function (e) {
                    buffer.remove();
                    throw e;
                });
        }

        function start(list) {
            state.count = list.length;
            if (els.total) { els.total.textContent = String(list.length); }
            buildThumbs(list);
            buildChips();
            buildBookChips();
            bindToolbar();
            bindListToggle();
            initSound();
            initHotspots();
            initMetrics();
            if (state.mode === 'swipe') { buildSwipe(list); } else { buildFlip(list); }
        }

        /* ---- flip mode (StPageFlip) ---- */

        function buildFlip(list) {
            if (!window.St || !window.St.PageFlip) { showError(); return; }
            // Base page size from the first image so the stretch ratio matches
            // the uploaded pages; A4-ish portrait fallback when it cannot load.
            var probe = new Image();
            probe.onload = function () {
                initFlip(list, probe.naturalWidth || 800, probe.naturalHeight || 1131);
            };
            probe.onerror = function () { initFlip(list, 800, 1131); };
            probe.src = list[0];
        }

        function initFlip(list, w, h) {
            try {
                // A4 low-end tuning: drop page shadows and shorten the flip on
                // constrained devices so the animation stays smooth. Reduced
                // motion is the most aggressive (300ms); a low core/memory
                // budget alone lands at 450ms. Everything else keeps defaults.
                var reduce = prefersReducedMotion();
                var lowEnd = reduce
                    || (typeof navigator.hardwareConcurrency === 'number' && navigator.hardwareConcurrency <= 4)
                    || (typeof navigator.deviceMemory === 'number' && navigator.deviceMemory <= 2);
                var flippingTime = reduce ? 300 : (lowEnd ? 450 : 700);
                var flip = new window.St.PageFlip(book, {
                    width: w,
                    height: h,
                    size: 'stretch',
                    // "single" must always resolve portrait: the stage is capped
                    // narrow by CSS and minWidth keeps StPageFlip's orientation
                    // check (blockWidth < 2*minWidth) in portrait territory.
                    minWidth: cfg.display === 'single' ? 300 : 100,
                    usePortrait: cfg.display !== 'double',
                    // RTL: pages arrive reversed, so the logical first page
                    // sits at the LAST index — open the book there.
                    startPage: cfg.rtl ? list.length - 1 : 0,
                    showCover: !!cfg.hardcover,
                    maxShadowOpacity: 0.4,
                    drawShadow: !lowEnd,
                    flippingTime: flippingTime,
                    mobileScrollSupport: false,
                    autoSize: true
                });
                state.flip = flip;
                flip.on('init', function () { hideLoader(); update(); applyDeepLink(); initHint(); });
                flip.on('flip', function () {
                    update();
                    if (state.hintDismiss) { state.hintDismiss(); }
                    if (state.playTurn) { state.playTurn(); }
                });
                flip.on('changeOrientation', update);
                // A7: StPageFlip fires 'flip' before the page settles — hide the
                // spot overlay while animating, reposition once the page reads.
                flip.on('changeState', function (e) {
                    var st = e && e.data;
                    if (st === 'flipping' || st === 'user_fold') { flipAnimating = true; hideSpots(); }
                    else if (st === 'read') { flipAnimating = false; positionFlipSpots(); }
                });
                flip.loadFromImages(list);
            } catch (e) {
                showError();
            }
        }

        /* ---- swipe fallback (scroll-snap pager) ---- */

        function buildSwipe(list) {
            var scroller = document.createElement('div');
            scroller.className = 'folio-swipe';
            // The array is already in RTL visual order when cfg.rtl; pin the
            // scroller to ltr so reversed pages + an end start read naturally
            // (scrollLeft stays positive and index math stays one-branch).
            scroller.setAttribute('dir', 'ltr');
            list.forEach(function (u, i) {
                var pg = document.createElement('div');
                pg.className = 'folio-swipe-page';
                var img = document.createElement('img');
                img.src = u;
                img.loading = i > 1 ? 'lazy' : 'eager';
                img.alt = pageLabel(i);
                img.draggable = false;
                pg.appendChild(img);
                scroller.appendChild(pg);
            });
            book.appendChild(scroller);
            state.scroller = scroller;
            if (cfg.rtl) { scroller.scrollLeft = scroller.scrollWidth; } // open on logical page 1
            hideLoader();
            var raf = 0;
            scroller.addEventListener('scroll', function () {
                if (raf) { return; }
                raf = window.requestAnimationFrame(function () { raf = 0; update(); });
            }, { passive: true });
            injectSwipeSpots(scroller);
            update();
            applyDeepLink();
            initHint();
        }

        /* ---- thumbnails ---- */

        function buildThumbs(list) {
            if (!els.thumbs) { return; }
            // Thumbnails read in LOGICAL page order (1..N); under RTL that is
            // the reverse of the (already reversed) array order.
            for (var i = 0; i < list.length; i++) {
                (function (logical) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'folio-thumb';
                    b.setAttribute('aria-label', pageLabel(logical));
                    var ai = arrayOf(logical, list.length);
                    var img = document.createElement('img');
                    img.src = list[ai];
                    // A5: responsive thumbnails when the srcset helper supplied one.
                    if (pages[ai] && pages[ai].srcset) { img.srcset = pages[ai].srcset; }
                    img.loading = 'lazy';
                    img.alt = '';
                    img.draggable = false;
                    b.appendChild(img);
                    b.addEventListener('click', function () { goTo(logical); });
                    els.thumbs.appendChild(b);
                })(i);
            }
        }

        function pageLabel(i) {
            return (cfg.labels && cfg.labels.page ? cfg.labels.page : 'Page') + ' ' + (i + 1);
        }

        /* ---- navigation (logical page space; RTL maps onto reversed array) ---- */

        // Array index <-> logical page index. The page array is reversed under
        // RTL, so both directions are the same mirror.
        function arrayOf(logical, count) {
            count = count || state.count;
            return cfg.rtl ? count - 1 - logical : logical;
        }

        function currentArrayIndex() {
            if (state.mode === 'flip') {
                return state.flip ? state.flip.getCurrentPageIndex() : 0;
            }
            if (state.scroller) {
                return Math.max(0, Math.min(state.count - 1,
                    Math.round(Math.abs(state.scroller.scrollLeft) / Math.max(1, state.scroller.clientWidth))));
            }
            return 0;
        }

        function currentIndex() { // logical
            return arrayOf(currentArrayIndex());
        }

        function goTo(logical) { // logical page index
            logical = Math.max(0, Math.min(state.count - 1, logical));
            if (state.zoom > 1) { return; } // flips disabled while zoomed
            var target = arrayOf(logical);
            if (state.mode === 'flip') {
                if (state.flip) { state.flip.turnToPage(target); update(); }
            } else if (state.scroller) {
                state.scroller.scrollTo({ left: target * state.scroller.clientWidth, behavior: 'smooth' });
            }
        }

        function goNext() { // logical forward — RTL flips the book backwards
            if (state.zoom > 1) { return; }
            if (state.mode === 'flip' && state.flip) {
                if (cfg.rtl) { state.flip.flipPrev(); } else { state.flip.flipNext(); }
            } else { goTo(currentIndex() + 1); }
        }

        function goPrev() {
            if (state.zoom > 1) { return; }
            if (state.mode === 'flip' && state.flip) {
                if (cfg.rtl) { state.flip.flipNext(); } else { state.flip.flipPrev(); }
            } else { goTo(currentIndex() - 1); }
        }

        function update() {
            var idx = currentIndex(); // logical
            root.setAttribute('data-folio-page', String(idx + 1));
            if (els.cur) { els.cur.textContent = String(idx + 1); }
            var zoomed = state.zoom > 1;
            if (els.prev) { els.prev.disabled = zoomed || idx <= 0; }
            if (els.first) { els.first.disabled = zoomed || idx <= 0; }
            if (els.next) { els.next.disabled = zoomed || idx >= state.count - 1; }
            if (els.last) { els.last.disabled = zoomed || idx >= state.count - 1; }
            if (els.thumbs) {
                Array.prototype.forEach.call(els.thumbs.children, function (t, i) {
                    t.classList.toggle('is-active', i === idx);
                    if (i === idx) { t.setAttribute('aria-current', 'true'); }
                    else { t.removeAttribute('aria-current'); }
                });
            }
            afterUpdate(idx);
        }

        /* ---- shared helpers ---- */

        function prefersReducedMotion() {
            return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        }

        // One settle hook, grown by the polish features. Called at the tail of
        // every update(); each branch guards its own feature being active.
        function afterUpdate(idx) {
            syncDeepLink(idx);
            syncChips(idx);
            if (spotsLayer) { positionFlipSpots(); }
            if (state.onPageSettled) { state.onPageSettled(idx); }
        }

        /* ---- A1 deep links (?page=N, logical 1-based) ---- */

        // Apply ?page=N once, after the book/pager has settled its first render.
        function applyDeepLink() {
            if (deepLinkApplied) { return; }
            deepLinkApplied = true;
            var target = null;
            try {
                var raw = new URLSearchParams(window.location.search).get('page');
                var n = raw === null ? NaN : parseInt(raw, 10);
                if (isFinite(n) && n >= 1) { target = n - 1; } // logical, 0-based
            } catch (e) { target = null; }
            if (target !== null) { goTo(target); } // goTo() clamps
        }

        // Rewrite ?page= on every settled change, preserving other params
        // (?book= included). replaceState never grows the history stack.
        function syncDeepLink(idx) {
            if (!window.history || !window.history.replaceState) { return; }
            if (deepLinkTimer) { window.clearTimeout(deepLinkTimer); }
            deepLinkTimer = window.setTimeout(function () {
                deepLinkTimer = 0;
                try {
                    var params = new URLSearchParams(window.location.search);
                    params.set('page', String(idx + 1));
                    var qs = params.toString();
                    window.history.replaceState(
                        window.history.state,
                        '',
                        window.location.pathname + (qs ? '?' + qs : '') + window.location.hash
                    );
                } catch (e) { /* history unavailable — no-op */ }
            }, 300);
        }

        /* ---- A2 section chips ---- */

        // Group CONSECUTIVE logical pages sharing the same non-empty trimmed
        // name into one section. cfg.pages is in ARRAY order (RTL-reversed), so
        // read it through arrayOf() to walk LOGICAL order. PDF mode has no page
        // names → no sections.
        function buildSections() {
            var pagesArr = Array.isArray(cfg.pages) ? cfg.pages : [];
            if (!pagesArr.length) { return []; }
            var sections = [];
            for (var logical = 0; logical < state.count; logical++) {
                var p = pagesArr[arrayOf(logical, state.count)];
                var name = p && p.name ? String(p.name).trim() : '';
                if (!name) { continue; } // an unnamed page breaks the run
                var last = sections.length ? sections[sections.length - 1] : null;
                if (last && last.name === name && last.end === logical - 1) {
                    last.end = logical;
                } else {
                    sections.push({ name: name, start: logical, end: logical });
                }
            }
            return sections;
        }

        function buildChips() {
            if (!els.toolbar) { return; }
            var sections = buildSections();
            if (sections.length < 2) { return; } // hidden below 2 sections
            var row = document.createElement('div');
            row.className = 'folio-chips';
            sections.forEach(function (sec) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'folio-chip';
                b.textContent = sec.name;
                b.addEventListener('click', function () { goTo(sec.start); });
                row.appendChild(b);
                sec.el = b;
            });
            // Above the thumbs; A8 book chips insert before this row.
            els.toolbar.insertBefore(row, els.toolbar.firstChild);
            state.sections = sections;
        }

        function syncChips(idx) {
            if (!state.sections) { return; }
            state.sections.forEach(function (sec) {
                var active = idx >= sec.start && idx <= sec.end;
                sec.el.classList.toggle('is-active', active);
                if (active) { sec.el.setAttribute('aria-current', 'true'); }
                else { sec.el.removeAttribute('aria-current'); }
            });
        }

        /* ---- A8 book switcher chips (above the section chips) ---- */

        function buildBookChips() {
            if (!els.toolbar || !cfg.books || !Array.isArray(cfg.books.list) || cfg.books.list.length < 2) { return; }
            var row = document.createElement('div');
            row.className = 'folio-bookchips';
            cfg.books.list.forEach(function (bk) {
                if (!bk || !bk.key) { return; }
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'folio-bookchip';
                if (bk.key === cfg.books.active) {
                    b.classList.add('is-active');
                    b.setAttribute('aria-current', 'true');
                }
                b.textContent = bk.label || bk.key;
                b.addEventListener('click', function () {
                    try {
                        var params = new URLSearchParams(window.location.search);
                        params.set('book', bk.key);
                        params.delete('page'); // deep-link dropped on book switch
                        window.location.search = params.toString(); // full reload — server refilters
                    } catch (e) { /* navigation unavailable — no-op */ }
                });
                row.appendChild(b);
            });
            // Insert at the toolbar top; buildChips() ran first, so this row ends
            // up ABOVE the section chips.
            els.toolbar.insertBefore(row, els.toolbar.firstChild);
        }

        /* ---- A3 first-visit hint ---- */

        // One-time coach overlay over the stage. The glyph + its animation are
        // pure CSS (data-URI SVG); reduced-motion drops the animation to static
        // text. pointer-events:none means it never blocks a flip. Dismissed on
        // the first flip / tap / keydown or after 4s, then remembered globally.
        function initHint() {
            if (!els.stage) { return; }
            var seen = null;
            try { seen = window.localStorage.getItem('folio-hint'); } catch (e) { seen = null; }
            if (seen) { return; }

            var hint = document.createElement('div');
            hint.className = prefersReducedMotion() ? 'folio-hint is-static' : 'folio-hint';
            var glyph = document.createElement('span');
            glyph.className = 'folio-hint-glyph';
            glyph.setAttribute('aria-hidden', 'true');
            var txt = document.createElement('span');
            txt.className = 'folio-hint-txt';
            txt.textContent = (cfg.labels && cfg.labels.hint) ? cfg.labels.hint : '';
            hint.appendChild(glyph);
            hint.appendChild(txt);
            els.stage.appendChild(hint);

            var dismissed = false;
            function dismiss() {
                if (dismissed) { return; }
                dismissed = true;
                try { window.localStorage.setItem('folio-hint', '1'); } catch (e) {}
                hint.classList.add('is-out');
                window.setTimeout(function () {
                    if (hint.parentNode) { hint.parentNode.removeChild(hint); }
                }, 320);
                document.removeEventListener('keydown', dismiss);
                els.stage.removeEventListener('pointerdown', dismiss);
            }
            state.hintDismiss = dismiss;
            els.stage.addEventListener('pointerdown', dismiss);
            document.addEventListener('keydown', dismiss);
            window.setTimeout(dismiss, 4000);
        }

        /* ---- A5 responsive variants (canvas book) + view-as-list ---- */

        // For ≤900px viewports, swap each book image for its -w900 variant when
        // the server supplied a map. Full resolution otherwise, and when absent.
        function pickVariantUrls(list) {
            if (!cfg.pageVariants) { return list; }
            var narrow = window.matchMedia && window.matchMedia('(max-width: 900px)').matches;
            if (!narrow) { return list; }
            return list.map(function (u) { return cfg.pageVariants[u] || u; });
        }

        // Swap stage⇄list. The list section is server-rendered (crawlable); JS
        // just flips the root view flag + the section's hidden attr. Not
        // persisted (SPEC §5).
        function bindListToggle() {
            var toggle = document.getElementById('folioListToggle');
            var listEl = document.getElementById('folioList');
            if (!toggle || !listEl) { return; }
            toggle.addEventListener('click', function () {
                var toList = root.getAttribute('data-folio-view') !== 'list';
                if (toList) {
                    root.setAttribute('data-folio-view', 'list');
                    listEl.hidden = false;
                    toggle.setAttribute('aria-pressed', 'true');
                } else {
                    root.removeAttribute('data-folio-view');
                    listEl.hidden = true;
                    toggle.setAttribute('aria-pressed', 'false');
                }
            });
        }

        /* ---- A6 page-turn sound (WebAudio; lazy, gesture-gated, silent) ---- */

        // ~120ms band-passed noise burst synthesised per flip. The AudioContext
        // is created/resumed only after a user gesture (autoplay policy); every
        // failure path is swallowed so audio never breaks the book.
        function initSound() {
            if (root.getAttribute('data-folio-sound') !== '1') { return; }
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) { return; }
            var ctx = null;
            function ensure() {
                if (!ctx) {
                    try { ctx = new AC(); } catch (e) { ctx = null; }
                }
                if (ctx && ctx.state === 'suspended') {
                    try { ctx.resume(); } catch (e) { /* ignore */ }
                }
                return ctx;
            }
            document.addEventListener('pointerdown', ensure, { once: true });
            document.addEventListener('keydown', ensure, { once: true });
            state.playTurn = function () {
                var c = ensure();
                if (!c) { return; }
                try {
                    var frames = Math.max(1, Math.floor(c.sampleRate * 0.12));
                    var buffer = c.createBuffer(1, frames, c.sampleRate);
                    var data = buffer.getChannelData(0);
                    for (var i = 0; i < frames; i++) {
                        var env = 1 - (i / frames);
                        data[i] = (Math.random() * 2 - 1) * env * env;
                    }
                    var srcNode = c.createBufferSource();
                    srcNode.buffer = buffer;
                    var filter = c.createBiquadFilter();
                    filter.type = 'bandpass';
                    filter.frequency.value = 1400;
                    filter.Q.value = 0.6;
                    var gain = c.createGain();
                    gain.gain.value = 0.22;
                    srcNode.connect(filter);
                    filter.connect(gain);
                    gain.connect(c.destination);
                    srcNode.start();
                } catch (e) { /* silent */ }
            };
        }

        /* ---- A7 hotspots: overlay + item sheet + search ---- */

        function initHotspots() {
            hotspots = (cfg.hotspots && cfg.hotspots.spots) ? cfg.hotspots : null;
            if (!hotspots) { return; }
            // item id -> first LOGICAL page it appears on (drives search jumps).
            Object.keys(hotspots.spots).forEach(function (key) {
                var logical = parseInt(key, 10);
                (hotspots.spots[key] || []).forEach(function (s) {
                    if (!(s.item in itemPageIndex)) { itemPageIndex[s.item] = logical; }
                });
            });
            buildSheet();
            buildSearch();
            // Flip mode paints to canvas — spots ride an absolute overlay over
            // the stage. Swipe/list spots live inside each page's own box.
            if (state.mode === 'flip') {
                spotsLayer = document.createElement('div');
                spotsLayer.className = 'folio-spots';
                spotsLayer.id = 'folioSpots';
                if (els.stage) { els.stage.appendChild(spotsLayer); }
                window.addEventListener('resize', positionFlipSpots);
                window.addEventListener('orientationchange', positionFlipSpots);
            }
            injectListSpots();
        }

        function makeSpot(s) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = prefersReducedMotion() ? 'folio-spot is-static' : 'folio-spot';
            btn.setAttribute('data-spot-item', String(s.item));
            var item = hotspots.items[s.item];
            btn.setAttribute('aria-label', (item && item.name) ? item.name : '');
            if (flashItem !== null && s.item === flashItem) {
                btn.classList.add('is-flash');
                (function (b) {
                    window.setTimeout(function () { b.classList.remove('is-flash'); }, 2000);
                })(btn);
            }
            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                openSheet(s.item);
            });
            return btn;
        }

        function hideSpots() {
            if (spotsLayer) { spotsLayer.style.display = 'none'; }
        }

        // Flip mode: recompute the visible page rect(s) from the book box and
        // place each spot by % within it. Hidden while flipping or zoomed.
        function positionFlipSpots() {
            if (!spotsLayer || state.mode !== 'flip' || !state.flip) { return; }
            while (spotsLayer.firstChild) { spotsLayer.removeChild(spotsLayer.firstChild); }
            if (flipAnimating || state.zoom > 1) { spotsLayer.style.display = 'none'; return; }
            spotsLayer.style.display = '';
            if (!els.stage) { return; }
            var stageRect = els.stage.getBoundingClientRect();
            var bookRect = book.getBoundingClientRect();
            if (!bookRect.width || !bookRect.height) { return; }
            var orient = state.flip.getOrientation ? state.flip.getOrientation() : 'portrait';
            var cur = state.flip.getCurrentPageIndex();
            var visible = orient === 'landscape'
                ? [{ arrayIdx: cur, side: 'left' }, { arrayIdx: cur + 1, side: 'right' }]
                : [{ arrayIdx: cur, side: 'full' }];
            var baseLeft = bookRect.left - stageRect.left;
            var baseTop = bookRect.top - stageRect.top;
            visible.forEach(function (v) {
                if (v.arrayIdx < 0 || v.arrayIdx >= state.count) { return; }
                var logical = arrayOf(v.arrayIdx, state.count);
                var spots = hotspots.spots[logical];
                if (!spots) { return; }
                var subLeft = baseLeft;
                var subW = bookRect.width;
                if (v.side === 'left') { subW = bookRect.width / 2; }
                else if (v.side === 'right') { subLeft = baseLeft + bookRect.width / 2; subW = bookRect.width / 2; }
                spots.forEach(function (s) {
                    var btn = makeSpot(s);
                    // Physical left/top: spot coords are image-relative and are
                    // NEVER mirrored under RTL (the page image is not flipped).
                    btn.style.left = (subLeft + s.x * subW) + 'px';
                    btn.style.top = (baseTop + s.y * bookRect.height) + 'px';
                    btn.style.width = (s.w * subW) + 'px';
                    btn.style.height = (s.h * bookRect.height) + 'px';
                    spotsLayer.appendChild(btn);
                });
            });
        }

        // Swipe/list: spots sit inside a relative host wrapping the page image,
        // positioned by percentage (image-relative — not mirrored under RTL).
        function injectInlineSpots(hostEl, logical) {
            var spots = hotspots.spots[logical];
            if (!spots) { return; }
            spots.forEach(function (s) {
                var btn = makeSpot(s);
                btn.style.left = (s.x * 100) + '%';
                btn.style.top = (s.y * 100) + '%';
                btn.style.width = (s.w * 100) + '%';
                btn.style.height = (s.h * 100) + '%';
                hostEl.appendChild(btn);
            });
        }

        function injectSwipeSpots(scroller) {
            if (!hotspots || !scroller) { return; }
            var cells = scroller.querySelectorAll('.folio-swipe-page');
            Array.prototype.forEach.call(cells, function (cell, i) {
                var logical = arrayOf(i, cells.length);
                if (!hotspots.spots[logical]) { return; }
                var img = cell.querySelector('img');
                if (!img) { return; }
                var host = document.createElement('div');
                host.className = 'folio-spot-host';
                cell.insertBefore(host, img);
                host.appendChild(img);
                injectInlineSpots(host, logical);
            });
        }

        function injectListSpots() {
            var listEl = document.getElementById('folioList');
            if (!listEl || !hotspots) { return; }
            var imgs = listEl.querySelectorAll('.folio-list-img');
            Array.prototype.forEach.call(imgs, function (img, logical) {
                // The list is server-rendered in LOGICAL order, so index=logical.
                if (!hotspots.spots[logical]) { return; }
                var host = document.createElement('div');
                host.className = 'folio-spot-host';
                img.parentNode.insertBefore(host, img);
                host.appendChild(img);
                injectInlineSpots(host, logical);
            });
        }

        function buildSheet() {
            sheet = document.createElement('div');
            sheet.className = 'folio-sheet';
            sheet.hidden = true;
            sheet.setAttribute('role', 'dialog');
            sheet.setAttribute('aria-modal', 'true');

            var backdrop = document.createElement('div');
            backdrop.className = 'folio-sheet-backdrop';
            backdrop.addEventListener('click', closeSheet);

            var panel = document.createElement('div');
            panel.className = 'folio-sheet-panel';

            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'folio-sheet-close';
            close.setAttribute('aria-label', (cfg.labels && cfg.labels.close) ? cfg.labels.close : 'Close');
            close.textContent = '×';
            close.addEventListener('click', closeSheet);

            var media = document.createElement('div');
            media.className = 'folio-sheet-media';
            var img = document.createElement('img');
            img.className = 'folio-sheet-img';
            img.alt = '';
            img.draggable = false;
            media.appendChild(img);

            var titleRow = document.createElement('div');
            titleRow.className = 'folio-sheet-titlerow';
            var name = document.createElement('h2');
            name.className = 'folio-sheet-name';
            var price = document.createElement('span');
            price.className = 'folio-sheet-price';
            titleRow.appendChild(name);
            titleRow.appendChild(price);

            var sold = document.createElement('span');
            sold.className = 'folio-sheet-sold';
            sold.textContent = (cfg.labels && cfg.labels.soldOut) ? cfg.labels.soldOut : 'Sold out';
            sold.hidden = true;

            var desc = document.createElement('p');
            desc.className = 'folio-sheet-desc';

            panel.appendChild(close);
            panel.appendChild(media);
            panel.appendChild(titleRow);
            panel.appendChild(sold);
            panel.appendChild(desc);
            sheet.appendChild(backdrop);
            sheet.appendChild(panel);
            root.appendChild(sheet);

            sheetEls = { img: img, media: media, name: name, price: price, sold: sold, desc: desc, close: close };

            document.addEventListener('keydown', function (e) {
                if (!sheet.hidden && e.key === 'Escape') { closeSheet(); }
            });
        }

        function openSheet(itemId) {
            if (!sheet) { return; }
            var item = hotspots.items[itemId];
            if (!item) { return; }
            sheetFocusReturn = document.activeElement;
            if (item.image) { sheetEls.img.src = item.image; sheetEls.media.hidden = false; }
            else { sheetEls.img.removeAttribute('src'); sheetEls.media.hidden = true; }
            sheetEls.name.textContent = item.name || '';
            sheetEls.price.textContent = item.price || ''; // pre-formatted server-side
            sheetEls.desc.textContent = item.desc || '';
            sheetEls.sold.hidden = !item.sold_out;
            sheet.hidden = false;
            try { sheetEls.close.focus(); } catch (e) { /* ignore */ }
        }

        function closeSheet() {
            if (!sheet || sheet.hidden) { return; }
            sheet.hidden = true;
            if (sheetFocusReturn && sheetFocusReturn.focus) {
                try { sheetFocusReturn.focus(); } catch (e) { /* ignore */ }
            }
            sheetFocusReturn = null;
        }

        function buildSearch() {
            if (!els.toolbar) { return; }
            var label = (cfg.labels && cfg.labels.search) ? cfg.labels.search : 'Search';

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'folio-btn folio-btn-search';
            btn.setAttribute('aria-label', label);
            btn.setAttribute('aria-expanded', 'false');

            var wrap = document.createElement('div');
            wrap.className = 'folio-search';
            wrap.hidden = true;
            var input = document.createElement('input');
            input.type = 'search';
            input.className = 'folio-search-input';
            input.setAttribute('placeholder', label);
            input.setAttribute('aria-label', label);
            var results = document.createElement('div');
            results.className = 'folio-search-results';
            wrap.appendChild(input);
            wrap.appendChild(results);

            var tbRow = els.toolbar.querySelector('.folio-tb-row');
            if (tbRow) { tbRow.appendChild(btn); } else { els.toolbar.appendChild(btn); }
            els.toolbar.appendChild(wrap);

            btn.addEventListener('click', function () {
                var opening = wrap.hidden;
                wrap.hidden = !opening;
                btn.setAttribute('aria-expanded', opening ? 'true' : 'false');
                if (opening) { try { input.focus(); } catch (e) { /* ignore */ } }
            });
            input.addEventListener('input', function () { renderSearchResults(input.value, results); });
        }

        function renderSearchResults(q, container) {
            while (container.firstChild) { container.removeChild(container.firstChild); }
            q = (q || '').trim().toLowerCase();
            if (!q) { return; }
            var items = hotspots.items || {};
            var count = 0;
            Object.keys(items).forEach(function (id) {
                if (count >= 8) { return; }                     // max 8 results
                if (!(id in itemPageIndex)) { return; }         // only placed items
                var name = (items[id] && items[id].name) ? items[id].name : '';
                if (name.toLowerCase().indexOf(q) === -1) { return; }
                count++;
                var r = document.createElement('button');
                r.type = 'button';
                r.className = 'folio-search-result';
                r.textContent = name;
                r.addEventListener('click', function () { jumpToItem(id); });
                container.appendChild(r);
            });
        }

        function jumpToItem(itemId) {
            if (!(itemId in itemPageIndex)) { return; }
            // Leave list view so the spot is visible on its page.
            if (root.getAttribute('data-folio-view') === 'list') {
                var t = document.getElementById('folioListToggle');
                if (t) { t.click(); }
            }
            goTo(itemPageIndex[itemId]);
            flashItem = itemId;
            applyFlash(itemId);
            window.setTimeout(function () { flashItem = null; }, 2200);
        }

        function applyFlash(itemId) {
            Array.prototype.forEach.call(root.querySelectorAll('.folio-spot'), function (n) {
                if (n.getAttribute('data-spot-item') === String(itemId)) {
                    n.classList.add('is-flash');
                    window.setTimeout(function () { n.classList.remove('is-flash'); }, 2000);
                }
            });
        }

        /* ---- A9 metrics beacon (page + dwell only; no identifiers) ---- */

        // On each settled page change, once it has held 800ms, beacon the
        // PREVIOUS page + how long it was read. pagehide flushes the final
        // dwell. Null url / no sendBeacon => inert. No cookies, no ids.
        function initMetrics() {
            var url = cfg.metricsUrl;
            if (!url || !navigator.sendBeacon) { return; }
            var lastPage = null;
            var lastArrival = Date.now();
            var pendingPage = null;
            var pendingArrival = 0;
            var timer = 0;
            function send(page, ms) {
                try {
                    var blob = new Blob([JSON.stringify({ page: page, ms: ms })], { type: 'application/json' });
                    navigator.sendBeacon(url, blob);
                } catch (e) { /* silent */ }
            }
            state.onPageSettled = function (logical) {
                var page = logical + 1;
                if (lastPage === null) { lastPage = page; lastArrival = Date.now(); return; }
                if (page === lastPage) { return; }
                pendingPage = page;
                pendingArrival = Date.now();
                if (timer) { window.clearTimeout(timer); }
                timer = window.setTimeout(function () {
                    timer = 0;
                    send(lastPage, pendingArrival - lastArrival);
                    lastPage = pendingPage;
                    lastArrival = pendingArrival;
                }, 800);
            };
            window.addEventListener('pagehide', function () {
                if (lastPage !== null) { send(lastPage, Date.now() - lastArrival); }
            });
        }

        /* ---- toolbar bindings ---- */

        function bindToolbar() {
            if (els.prev) { els.prev.addEventListener('click', goPrev); }
            if (els.next) { els.next.addEventListener('click', goNext); }
            if (els.first) { els.first.addEventListener('click', function () { goTo(0); }); }
            if (els.last) { els.last.addEventListener('click', function () { goTo(state.count - 1); }); }
            if (els.zoom) { els.zoom.addEventListener('click', cycleZoom); }
            if (els.full) { els.full.addEventListener('click', toggleFullscreen); }

            document.addEventListener('keydown', function (e) {
                // Arrows are VISUAL: in RTL, right = backwards through the book.
                if (e.key === 'ArrowRight') { if (cfg.rtl) { goPrev(); } else { goNext(); } }
                else if (e.key === 'ArrowLeft') { if (cfg.rtl) { goNext(); } else { goPrev(); } }
                else if (e.key === 'Home') { goTo(0); }
                else if (e.key === 'End') { goTo(state.count - 1); }
            });
        }

        /* ---- zoom + drag-pan (flips disabled while zoomed) ---- */

        function cycleZoom() {
            var i = ZOOM_STEPS.indexOf(state.zoom);
            setZoom(ZOOM_STEPS[(i + 1) % ZOOM_STEPS.length]);
        }

        function setZoom(z) {
            state.zoom = z;
            state.pan = { x: 0, y: 0 };
            applyTransform();
            var zoomed = z > 1;
            if (els.pan) { els.pan.hidden = !zoomed; }
            if (els.zoom) {
                els.zoom.textContent = (z === 1 ? '1' : String(z)) + '×';
                els.zoom.classList.toggle('is-zoomed', zoomed);
            }
            update();
        }

        function applyTransform() {
            if (!els.zoomWrap) { return; }
            els.zoomWrap.style.transform = state.zoom > 1
                ? 'scale(' + state.zoom + ') translate(' + state.pan.x + 'px, ' + state.pan.y + 'px)'
                : '';
        }

        var drag = null;
        if (els.pan) {
            els.pan.addEventListener('pointerdown', function (e) {
                drag = { x: e.clientX, y: e.clientY, px: state.pan.x, py: state.pan.y };
                els.pan.setPointerCapture(e.pointerId);
                els.pan.classList.add('is-panning');
            });
            els.pan.addEventListener('pointermove', function (e) {
                if (!drag) { return; }
                var limX = (els.stage ? els.stage.clientWidth : 600) / 2;
                var limY = (els.stage ? els.stage.clientHeight : 600) / 2;
                state.pan.x = clamp(drag.px + (e.clientX - drag.x) / state.zoom, -limX, limX);
                state.pan.y = clamp(drag.py + (e.clientY - drag.y) / state.zoom, -limY, limY);
                applyTransform();
            });
            ['pointerup', 'pointercancel'].forEach(function (ev) {
                els.pan.addEventListener(ev, function () {
                    drag = null;
                    els.pan.classList.remove('is-panning');
                });
            });
            // Double-tap / double-click the pan layer to reset to 1×.
            els.pan.addEventListener('dblclick', function () { setZoom(1); });
        }

        function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

        /* ---- fullscreen ---- */

        function toggleFullscreen() {
            var target = els.stage || root;
            if (document.fullscreenElement) {
                if (document.exitFullscreen) { document.exitFullscreen(); }
            } else if (target.requestFullscreen) {
                target.requestFullscreen();
            } else if (target.webkitRequestFullscreen) {
                target.webkitRequestFullscreen();
            }
        }
    });
})();
