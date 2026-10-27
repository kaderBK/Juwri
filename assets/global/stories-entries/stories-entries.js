// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Stories Entry Points — shared storefront bundle ("SE" run).
 *
 * Mounted ONLY by the folio + pageview standalone display themes. Self-hosted,
 * CSP-clean: no inline handlers, no on*= attributes, all listeners bound with
 * addEventListener. No Blade echo lives in this file — every value arrives via
 * the #seRoot data-attributes the layouts render.
 *
 * Contract (SPEC §4.1): the layout renders
 *   <div id="seRoot" class="se-root" data-se-entry="{slug}" data-se-feed="{url}"
 *        data-se-rtl="0|1" data-se-name="..." data-se-logo="..." data-se-post="{id}">
 * We read it, fetch the public story feed once, render the entry variant, and
 * own the premium viewer. Empty feed = no-op (nothing renders).
 *
 * Sections:
 *   [0] boot + config + utilities   (A1)
 *   [1] premium viewer              (A2)
 *   [2] entries: ribbon / orb       (A3)
 *   [3] entries: toolbar / capsule  (A4)
 *   [4] entries: tassel / seal      (A5)
 *   [5] entries: dogear / filmstrip (A6)
 *   [6] entries: medallion / sheet  (A7)
 */
(function () {
    'use strict';

    var root = document.getElementById('seRoot');
    if (!root) { return; }

    var ENTRY = root.getAttribute('data-se-entry') || 'none';
    if (ENTRY === 'none') { return; }

    /* ---------------------------------------------- [0] config + utilities -- */
    var CFG = {
        entry: ENTRY,
        feed:  root.getAttribute('data-se-feed') || '',
        rtl:   root.getAttribute('data-se-rtl') === '1',
        name:  root.getAttribute('data-se-name') || '',
        logo:  root.getAttribute('data-se-logo') || '',
        post:  root.getAttribute('data-se-post') || '0',
        /* localized UI strings, supplied by the layout (no Blade echo in JS). */
        t: {
            stories: root.getAttribute('data-se-t-stories') || 'Stories',
            open:    root.getAttribute('data-se-t-open') || 'View stories',
            close:   root.getAttribute('data-se-t-close') || 'Close'
        }
    };

    var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    /* Entry variant registry — each section below registers into it. */
    var ENTRIES = {};

    /* Small DOM builder. attrs: {class, text, aria, html, ...data-*}. */
    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === false || typeof v === 'undefined') { return; }
            if (k === 'class') { node.className = v; }
            else if (k === 'text') { node.textContent = v; }
            else if (k === 'html') { node.innerHTML = v; }
            else if (k === 'style') { node.setAttribute('style', v); }
            else { node.setAttribute(k, v); }
        });
        (children || []).forEach(function (c) {
            if (c === null || typeof c === 'undefined') { return; }
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return node;
    }

    /* A real, keyboard-focusable, aria-labelled trigger for an entry variant.
       The .se-rtl hook rides along so a variant can flip physical transforms
       (logical properties handle the rest) regardless of where it is mounted. */
    function entryButton(variant, label) {
        var btn = el('button', {
            'type': 'button',
            'class': 'se-entry se-entry--' + variant + (CFG.rtl ? ' se-rtl' : ''),
            'aria-label': label,
            'aria-haspopup': 'dialog'
        });
        return btn;
    }

    /* Escape user text before it reaches innerHTML (names/captions). */
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /* ---- seen-state: sessionStorage only, key se_seen_<storeId>_<storyId> ---- */
    function seenKey(id) { return 'se_seen_' + CFG.post + '_' + id; }

    function isSeen(id) {
        try { return window.sessionStorage.getItem(seenKey(id)) === '1'; }
        catch (e) { return false; }
    }

    function markSeen(id) {
        try { window.sessionStorage.setItem(seenKey(id), '1'); } catch (e) {}
    }

    function unseenCount(stories) {
        var n = 0;
        stories.forEach(function (s) { if (!isSeen(s.id)) { n++; } });
        return n;
    }

    function firstUnseenIndex(stories) {
        for (var i = 0; i < stories.length; i++) {
            if (!isSeen(stories[i].id)) { return i; }
        }
        return 0;
    }

    /* First glyph of the store name, for avatar fallbacks. */
    function initial() {
        var t = (CFG.name || '').trim();
        return t ? t.charAt(0).toUpperCase() : '✦'; /* ✦ */
    }

    /* Anchor discovery — folio rides its book stage, pageview its first page.
       Falls back to the fixed #seRoot handle when neither exists. */
    function stageHost() {
        return document.getElementById('folioStage')
            || document.getElementById('folioTheme')
            || document.getElementById('pv-main')
            || document.querySelector('.pv-shell')
            || root;
    }

    function isFolio() { return !!document.getElementById('folioTheme'); }

    /* Ensure a re-parent host can hold an absolutely-positioned child without us
       editing another theme's stylesheet. Only promotes a static host. */
    function ensurePositioned(host) {
        if (!host || host === root) { return; }
        var pos = window.getComputedStyle(host).position;
        if (pos === 'static') { host.style.position = 'relative'; }
    }

    /* ------------------------------------------------------- [1] viewer ----- */
    /* Premium full-screen story viewer (SPEC §4.4). Self-contained, scoped under
       .se-viewer so it can never leak into a legacy theme. Built lazily on first
       open. Gold segmented progress (4s auto-advance, pause on press), page-frame
       media card, avatar ring + serif name + relative time, gold CTA pill,
       close/Esc/tap-zones, focus trap, seen-state, reduced-motion, RTL. */
    var Viewer = (function () {
        var DURATION = 4000;
        var built = false, onSeen = null;
        var overlay, card, progWrap, frame, mediaImg, capEl, avatarEl, nameEl, timeEl, ctaEl, closeBtn, zonePrev, zoneNext, live;
        var segs = [];
        var STORIES = [], idx = 0;
        var running = false, rafId = 0, startTs = 0, pausedAccum = 0, pauseTs = 0, held = false, holdTimer = 0;
        var lastFocus = null;

        function relTime(iso) {
            if (!iso) { return ''; }
            var then = Date.parse(iso);
            if (isNaN(then)) { return ''; }
            var s = Math.max(0, Math.floor((Date.now() - then) / 1000));
            if (s < 60) { return 'now'; }
            var m = Math.floor(s / 60); if (m < 60) { return m + 'm'; }
            var h = Math.floor(m / 60); if (h < 24) { return h + 'h'; }
            var d = Math.floor(h / 24); if (d < 7) { return d + 'd'; }
            return Math.floor(d / 7) + 'w';
        }

        function build() {
            if (built) { return; }
            built = true;

            overlay = el('div', {
                'class': 'se-viewer', 'role': 'dialog', 'aria-modal': 'true',
                'aria-label': (CFG.name ? CFG.name + ' — ' : '') + CFG.t.stories, 'hidden': 'hidden'
            });
            if (CFG.rtl) { overlay.setAttribute('dir', 'rtl'); }

            card = el('div', { 'class': 'se-v-card' });

            progWrap = el('div', { 'class': 'se-v-prog', 'aria-hidden': 'true' });

            var top = el('div', { 'class': 'se-v-top' });
            avatarEl = el('span', { 'class': 'se-v-avatar' });
            var avInner = el('i', {});
            avatarEl.appendChild(avInner);
            var meta = el('div', { 'class': 'se-v-meta' });
            nameEl = el('b', { 'class': 'se-v-name' });
            timeEl = el('span', { 'class': 'se-v-time' });
            meta.appendChild(nameEl); meta.appendChild(timeEl);
            top.appendChild(avatarEl); top.appendChild(meta);

            closeBtn = el('button', { 'type': 'button', 'class': 'se-v-close', 'aria-label': CFG.t.close });
            closeBtn.innerHTML = '&#10005;'; /* ✕ */

            frame = el('div', { 'class': 'se-v-frame' });
            mediaImg = el('img', { 'class': 'se-v-media', 'alt': '', 'decoding': 'async' });
            capEl = el('p', { 'class': 'se-v-cap' });
            frame.appendChild(mediaImg); frame.appendChild(capEl);

            ctaEl = el('a', { 'class': 'se-v-cta', 'rel': 'noopener' });

            /* Tap zones (prev/end thirds). Buttonised for a11y is overkill — keyboard
               arrows/close cover it — so they are aria-hidden pointer targets. */
            zonePrev = el('div', { 'class': 'se-v-zone se-v-zone--prev', 'aria-hidden': 'true' });
            zoneNext = el('div', { 'class': 'se-v-zone se-v-zone--next', 'aria-hidden': 'true' });

            live = el('span', { 'class': 'se-sr', 'aria-live': 'polite' });

            card.appendChild(progWrap);
            card.appendChild(zonePrev);
            card.appendChild(zoneNext);
            card.appendChild(top);
            card.appendChild(closeBtn);
            card.appendChild(frame);
            card.appendChild(ctaEl);
            card.appendChild(live);
            overlay.appendChild(card);
            document.body.appendChild(overlay);

            avInner.textContent = initial();

            /* wiring — all addEventListener, no inline handlers (CSP) */
            closeBtn.addEventListener('click', function (e) { e.preventDefault(); close(); });
            overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });

            /* The zones are physically mirrored for RTL by inset-inline-* (the
               overlay carries dir=rtl), so prev/next stay logical here — no
               CFG.rtl inversion, or the two flips would cancel. */
            zonePrev.addEventListener('click', function () { go(-1); });
            zoneNext.addEventListener('click', function () { go(1); });

            /* pause on press anywhere on the media */
            frame.addEventListener('pointerdown', onHoldStart);
            frame.addEventListener('pointerup', onHoldEnd);
            frame.addEventListener('pointercancel', onHoldEnd);
            frame.addEventListener('pointerleave', onHoldEnd);

            document.addEventListener('keydown', onKey, true);
            mediaImg.addEventListener('load', function () { frame.classList.remove('se-v-frame--empty'); });
            mediaImg.addEventListener('error', function () { frame.classList.add('se-v-frame--empty'); });
        }

        function onHoldStart() {
            held = false;
            holdTimer = window.setTimeout(function () { held = true; pause(); }, 220);
        }
        function onHoldEnd() {
            window.clearTimeout(holdTimer);
            if (held) { resume(); held = false; }
        }

        function buildSegments() {
            progWrap.innerHTML = '';
            segs = STORIES.map(function () {
                var seg = el('span', { 'class': 'se-v-seg' });
                var fill = el('i', { 'class': 'se-v-seg-fill' });
                seg.appendChild(fill);
                progWrap.appendChild(seg);
                return fill;
            });
        }

        function paintProgress(activeRatio) {
            for (var i = 0; i < segs.length; i++) {
                var w = i < idx ? 1 : (i === idx ? activeRatio : 0);
                segs[i].style.width = (w * 100) + '%';
            }
        }

        function render(i) {
            idx = Math.max(0, Math.min(i, STORIES.length - 1));
            var st = STORIES[idx];

            frame.classList.remove('se-v-fade');
            /* force reflow for the crossfade to replay */
            void frame.offsetWidth;
            frame.classList.add('se-v-fade');

            if (st.media_url) {
                frame.classList.remove('se-v-frame--empty');
                mediaImg.src = st.media_url;
                mediaImg.style.display = '';
            } else {
                frame.classList.add('se-v-frame--empty');
                mediaImg.removeAttribute('src');
                mediaImg.style.display = 'none';
            }

            if (st.caption) { capEl.textContent = st.caption; capEl.style.display = ''; }
            else { capEl.textContent = ''; capEl.style.display = 'none'; }

            nameEl.textContent = CFG.name || '';
            timeEl.textContent = relTime(st.starts_at);

            if (st.cta_url) {
                ctaEl.textContent = (st.cta_label || 'View');
                ctaEl.setAttribute('href', st.cta_url);
                ctaEl.setAttribute('target', '_self');
                ctaEl.style.display = '';
            } else {
                ctaEl.style.display = 'none';
                ctaEl.removeAttribute('href');
            }

            if (live) { live.textContent = (idx + 1) + ' / ' + STORIES.length; }

            markSeen(st.id);
            if (typeof onSeen === 'function') { onSeen(st.id); }

            paintProgress(0);
            play();
        }

        function frameLoop() {
            if (!running) { return; }
            var elapsed = Date.now() - startTs - pausedAccum;
            var ratio = DURATION > 0 ? elapsed / DURATION : 1;
            if (ratio >= 1) {
                paintProgress(1);
                running = false;
                go(1);
                return;
            }
            paintProgress(ratio);
            rafId = window.requestAnimationFrame(frameLoop);
        }

        function play() {
            running = true;
            startTs = Date.now();
            pausedAccum = 0;
            if (reduceMotion) {
                /* No smooth fill under reduced motion — hold the frame, advance on timer. */
                paintProgress(1);
                window.clearTimeout(rafId);
                rafId = window.setTimeout(function () { running = false; go(1); }, DURATION);
            } else {
                window.cancelAnimationFrame(rafId);
                rafId = window.requestAnimationFrame(frameLoop);
            }
        }

        function pause() {
            if (!running || pauseTs) { return; }
            pauseTs = Date.now();
            card.classList.add('se-v-paused');
            if (reduceMotion) { window.clearTimeout(rafId); }
            else { window.cancelAnimationFrame(rafId); }
        }

        function resume() {
            if (!running || !pauseTs) { return; }
            pausedAccum += Date.now() - pauseTs;
            pauseTs = 0;
            card.classList.remove('se-v-paused');
            if (reduceMotion) {
                var remain = Math.max(0, DURATION - (Date.now() - startTs - pausedAccum));
                rafId = window.setTimeout(function () { running = false; go(1); }, remain);
            } else {
                rafId = window.requestAnimationFrame(frameLoop);
            }
        }

        function go(delta) {
            pauseTs = 0;
            var next = idx + delta;
            if (next < 0) { render(0); return; }
            if (next >= STORIES.length) { close(); return; }
            render(next);
        }

        function stopTimers() {
            running = false;
            if (reduceMotion) { window.clearTimeout(rafId); }
            else { window.cancelAnimationFrame(rafId); }
        }

        function focusables() {
            return [closeBtn].concat(
                ctaEl.style.display !== 'none' && ctaEl.getAttribute('href') ? [ctaEl] : []
            );
        }

        function onKey(e) {
            if (overlay.hasAttribute('hidden')) { return; }
            if (e.key === 'Escape') { e.preventDefault(); close(); return; }
            if (e.key === 'ArrowRight') { e.preventDefault(); CFG.rtl ? go(-1) : go(1); return; }
            if (e.key === 'ArrowLeft') { e.preventDefault(); CFG.rtl ? go(1) : go(-1); return; }
            if (e.key === ' ' || e.key === 'Spacebar') { e.preventDefault(); pauseTs ? resume() : pause(); return; }
            if (e.key === 'Tab') {
                var f = focusables();
                if (!f.length) { e.preventDefault(); return; }
                var first = f[0], last = f[f.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        }

        function open(stories, start, seenCb) {
            build();
            STORIES = stories || [];
            if (!STORIES.length) { return; }
            onSeen = seenCb || null;
            lastFocus = document.activeElement;
            buildSegments();
            overlay.removeAttribute('hidden');
            document.documentElement.classList.add('se-noscroll');
            /* next frame so the opacity transition runs */
            window.requestAnimationFrame(function () { overlay.classList.add('se-open'); });
            render(typeof start === 'number' ? start : 0);
            try { closeBtn.focus(); } catch (e) {}
        }

        function close() {
            stopTimers();
            pauseTs = 0;
            overlay.classList.remove('se-open');
            document.documentElement.classList.remove('se-noscroll');
            var hide = function () { overlay.setAttribute('hidden', 'hidden'); };
            if (reduceMotion) { hide(); }
            else { window.setTimeout(hide, 220); }
            if (lastFocus && typeof lastFocus.focus === 'function') {
                try { lastFocus.focus(); } catch (e) {}
            }
        }

        return { open: open };
    })();

    /* ---------------------------------------------- shared entry plumbing ---- */
    var mountedEntries = [];

    /* Toggle the "unseen" affordance (pulse / gold ring) on every mounted entry. */
    function refreshUnseen(stories) {
        var has = unseenCount(stories) > 0;
        mountedEntries.forEach(function (n) { n.classList.toggle('se-has-unseen', has); });
    }

    /* Thumbnail seen-state: filmstrip/sheet dim a thumb once its story is seen. */
    var thumbRefs = [];
    function refreshThumbs() {
        thumbRefs.forEach(function (t) { t.btn.classList.toggle('se-seen', isSeen(t.id)); });
    }

    function afterSeen(stories) { refreshUnseen(stories); refreshThumbs(); }

    function openFromEntry(stories, start) {
        Viewer.open(
            stories,
            typeof start === 'number' ? start : firstUnseenIndex(stories),
            function () { afterSeen(stories); }
        );
    }

    /* A single thumbnail button (filmstrip + sheet). Gold ring when unseen, dim
       once seen; opens the viewer on its own story index. */
    function thumbButton(story, index, stories) {
        var b = el('button', {
            'type': 'button',
            'class': 'se-thumb' + (isSeen(story.id) ? ' se-seen' : ''),
            'aria-label': CFG.t.open + ' ' + (index + 1)
        });
        if (story.media_url) {
            b.appendChild(el('img', { 'class': 'se-thumb-img', 'src': story.media_url, 'alt': '' }));
        } else {
            b.appendChild(el('span', { 'class': 'se-thumb-ph' }));
        }
        b.addEventListener('click', function (e) { e.preventDefault(); openFromEntry(stories, index); });
        thumbRefs.push({ btn: b, id: story.id });
        return b;
    }

    /* Bind a click on an entry (or thumb) to open the viewer, and register it for
       unseen-state refresh. startIndex lets a thumbnail open on its own story. */
    function wireEntry(node, stories, startIndex) {
        node.addEventListener('click', function (e) {
            e.preventDefault();
            openFromEntry(stories, startIndex);
        });
        mountedEntries.push(node);
        if (unseenCount(stories) > 0) { node.classList.add('se-has-unseen'); }
        return node;
    }

    /* Viewport-fixed entries live on the zero-size #seRoot handle. */
    function mountFixed(node) { root.appendChild(node); }

    /* Stage-riding entries are re-parented onto the theme's book/page stage so
       they track zoom/resize (SPEC §4.3). Falls back to fixed if no stage. */
    function mountStage(node) {
        var host = stageHost();
        if (host === root) { node.classList.add('se-detached'); }
        ensurePositioned(host);
        host.appendChild(node);
    }

    /* Avatar glyph: store logo image when present, else the store initial. */
    function avatarInner() {
        if (CFG.logo) {
            return el('img', { 'class': 'se-av-img', 'src': CFG.logo, 'alt': '' });
        }
        return el('span', { 'class': 'se-av-txt', 'text': initial() });
    }

    /* -------------------------------------------- [2] entries: ribbon/orb ---- */

    /* №1 · Gilded bookmark ribbon — draped over the book/page top edge. */
    ENTRIES.ribbon = function (stories) {
        var btn = entryButton('ribbon', CFG.t.open);
        btn.appendChild(el('span', { 'class': 'se-ribbon-silk', 'aria-hidden': 'true' }));
        btn.appendChild(el('span', { 'class': 'se-ribbon-count', 'aria-hidden': 'true', 'text': String(stories.length) }));
        btn.appendChild(el('span', { 'class': 'se-ribbon-lbl', 'aria-hidden': 'true', 'text': CFG.t.stories }));
        wireEntry(btn, stories);
        mountStage(btn);
    };

    /* №2 · Glass story orb — fixed top-start corner, pulse while unseen + spin. */
    ENTRIES.orb = function (stories) {
        var btn = entryButton('orb', CFG.t.open);
        btn.appendChild(el('span', { 'class': 'se-orb-halo', 'aria-hidden': 'true' }));
        var ring = el('span', { 'class': 'se-orb-ring', 'aria-hidden': 'true' });
        var glass = el('span', { 'class': 'se-orb-glass' });
        glass.appendChild(avatarInner());
        ring.appendChild(glass);
        btn.appendChild(ring);
        btn.appendChild(el('span', { 'class': 'se-count se-orb-count', 'aria-hidden': 'true', 'text': String(stories.length) }));
        wireEntry(btn, stories);
        mountFixed(btn);
    };

    /* ------------------------------------------ [3] entries: toolbar/capsule -- */

    /* №3 · Toolbar story button — appended into folio's own toolbar row at
       runtime (no edit to folio's index blade). On pageview the layout already
       remapped 'toolbar' → 'orb', so this only runs on folio; with no toolbar
       (empty store) it degrades to the orb. */
    ENTRIES.toolbar = function (stories) {
        var row = document.querySelector('#folioTheme .folio-tb-row');
        if (!row) { ENTRIES.orb(stories); return; }
        var btn = entryButton('toolbar', CFG.t.open);
        var ring = el('span', { 'class': 'se-tb-ring', 'aria-hidden': 'true' });
        var inner = el('span', { 'class': 'se-tb-in' });
        inner.appendChild(avatarInner());
        ring.appendChild(inner);
        btn.appendChild(ring);
        btn.appendChild(el('span', { 'class': 'se-count se-tb-count', 'aria-hidden': 'true', 'text': String(stories.length) }));
        wireEntry(btn, stories);
        row.appendChild(btn);
    };

    /* №4 · Title capsule — jewelry pill under the store title with a running
       gold→violet border, an avatar trio and "Stories · N". Inserted in-flow
       under the theme's title; fixed top-center if no title host is found. */
    ENTRIES.capsule = function (stories) {
        var btn = entryButton('capsule', CFG.t.open);
        var avs = el('span', { 'class': 'se-cap-avs', 'aria-hidden': 'true' });
        var shown = Math.min(3, stories.length);
        for (var i = 0; i < shown; i++) { avs.appendChild(el('i', { 'class': 'se-cap-av' })); }
        var txt = el('span', { 'class': 'se-cap-txt', 'aria-hidden': 'true' });
        txt.appendChild(el('b', { 'text': CFG.t.stories }));
        txt.appendChild(document.createTextNode(' · ' + stories.length));
        btn.appendChild(avs);
        btn.appendChild(txt);
        wireEntry(btn, stories);

        var host = document.querySelector('#folioTheme .folio-head-txt')
            || document.querySelector('.pv-cover')
            || null;
        if (host) { btn.classList.add('se-inflow'); host.appendChild(btn); }
        else { mountFixed(btn); }
    };

    /* -------------------------------------------- [4] entries: tassel/seal ---- */

    /* №5 · Leather page tassel — peeks from the end edge, slides out on
       hover/focus and gives a first-load peek; rests as a slim sliver. Logical
       properties + the .se-rtl transform flip keep the edge correct in RTL. */
    ENTRIES.tassel = function (stories) {
        var btn = entryButton('tassel', CFG.t.open);
        var tab = el('span', { 'class': 'se-tassel-tab', 'aria-hidden': 'true' });
        var dot = el('span', { 'class': 'se-tassel-dot' });
        dot.appendChild(avatarInner());
        tab.appendChild(dot);
        tab.appendChild(el('span', { 'class': 'se-tassel-lbl', 'text': CFG.t.stories }));
        tab.appendChild(el('span', { 'class': 'se-count se-tassel-count', 'text': String(stories.length) }));
        btn.appendChild(tab);
        btn.appendChild(el('span', { 'class': 'se-tassel-string', 'aria-hidden': 'true' }));
        wireEntry(btn, stories);
        mountFixed(btn);

        if (!reduceMotion) {
            btn.classList.add('se-peek');
            window.setTimeout(function () { btn.classList.remove('se-peek'); }, 2600);
        }
    };

    /* №6 · Wax seal — crimson blob + gold monogram; the tap "breaks" the seal
       (crack + fade) before opening the viewer. */
    ENTRIES.seal = function (stories) {
        var btn = entryButton('seal', CFG.t.open);
        btn.appendChild(el('span', { 'class': 'se-seal-wax', 'aria-hidden': 'true' }));
        btn.appendChild(el('span', { 'class': 'se-seal-mono', 'aria-hidden': 'true', 'text': initial() }));
        btn.appendChild(el('span', { 'class': 'se-count se-seal-count', 'aria-hidden': 'true', 'text': String(stories.length) }));
        btn.appendChild(el('span', { 'class': 'se-seal-tag', 'aria-hidden': 'true', 'text': CFG.t.stories }));

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            if (reduceMotion) { openFromEntry(stories); return; }
            btn.classList.add('se-cracked');
            window.setTimeout(function () {
                openFromEntry(stories);
                btn.classList.remove('se-cracked');
            }, 360);
        });
        mountedEntries.push(btn);
        if (unseenCount(stories) > 0) { btn.classList.add('se-has-unseen'); }
        mountFixed(btn);
    };

    /* ------------------------------------------ [5] entries: dogear/filmstrip -- */

    /* №7 · Dog-ear page curl — folded top-end corner of the content area. The
       fold visuals are pointer-events:none so they never swallow a page-flip
       drag; only the small gold dot is interactive (SPEC §4.3). */
    ENTRIES.dogear = function (stories) {
        var btn = entryButton('dogear', CFG.t.open);
        btn.appendChild(el('span', { 'class': 'se-dogear-under', 'aria-hidden': 'true' }));
        btn.appendChild(el('span', { 'class': 'se-dogear-shine', 'aria-hidden': 'true' }));
        btn.appendChild(el('span', { 'class': 'se-dogear-dot', 'aria-hidden': 'true', 'text': String(stories.length) }));
        wireEntry(btn, stories);
        mountStage(btn);
    };

    /* №8 · Filmstrip tray — up to 4 real story thumbnails in a sprocketed strip
       above the toolbar/footer. Unseen thumbs carry the gold ring, seen ones dim;
       each thumb opens the viewer on its own story. */
    ENTRIES.filmstrip = function (stories) {
        var wrap = el('div', {
            'class': 'se-entry--filmstrip' + (CFG.rtl ? ' se-rtl' : ''),
            'role': 'group', 'aria-label': CFG.t.stories
        });
        var strip = el('div', { 'class': 'se-fs-strip' });
        var max = Math.min(4, stories.length);
        for (var i = 0; i < max; i++) { strip.appendChild(thumbButton(stories[i], i, stories)); }
        wrap.appendChild(strip);
        wrap.appendChild(el('span', { 'class': 'se-fs-lbl', 'aria-hidden': 'true', 'text': CFG.t.stories }));
        mountFixed(wrap);
    };

    /* ----------------------------------------- [6] entries: medallion/sheet --- */

    /* №9 · Spine medallion — gold-foil coin embossed at the top of the book
       spine (folio: stage top-center, rides zoom/resize; pageview: centered
       above the first page). Sheen sweeps, halo pulses while unseen, violet pip. */
    ENTRIES.medallion = function (stories) {
        var btn = entryButton('medallion', CFG.t.open);
        btn.appendChild(el('span', { 'class': 'se-medallion-halo', 'aria-hidden': 'true' }));
        btn.appendChild(el('span', { 'class': 'se-medallion-coin', 'aria-hidden': 'true' }));
        btn.appendChild(el('span', { 'class': 'se-medallion-glyph', 'aria-hidden': 'true', 'text': initial() }));
        btn.appendChild(el('span', { 'class': 'se-count se-medallion-count', 'aria-hidden': 'true', 'text': String(stories.length) }));
        wireEntry(btn, stories);
        mountStage(btn);
    };

    /* №10 · Bottom-sheet peek — a grabber peeks from the bottom edge; hover/
       focus/first-tap slides up a thumbnail row. On folio it is offset to sit
       above the toolbar rather than overlap it. */
    ENTRIES.sheet = function (stories) {
        var wrap = el('div', {
            'class': 'se-entry--sheet' + (CFG.rtl ? ' se-rtl' : '') + (isFolio() ? ' se-above-toolbar' : ''),
            'role': 'group', 'aria-label': CFG.t.stories
        });
        wrap.appendChild(el('span', { 'class': 'se-sheet-grab', 'aria-hidden': 'true' }));
        var lbl = el('span', { 'class': 'se-sheet-lbl', 'aria-hidden': 'true' });
        lbl.appendChild(el('b', { 'text': CFG.t.stories }));
        lbl.appendChild(document.createTextNode(' · ' + stories.length));
        wrap.appendChild(lbl);
        var row = el('div', { 'class': 'se-sheet-row' });
        var max = Math.min(4, stories.length);
        for (var i = 0; i < max; i++) { row.appendChild(thumbButton(stories[i], i, stories)); }
        wrap.appendChild(row);
        mountFixed(wrap);

        if (!reduceMotion) {
            wrap.classList.add('se-peek');
            window.setTimeout(function () { wrap.classList.remove('se-peek'); }, 2600);
        }
    };

    /* -------------------------------------------------------- boot ---------- */
    function start(stories) {
        if (!stories || !stories.length) { return; } /* empty feed = no-op */
        var render = ENTRIES[CFG.entry];
        if (typeof render !== 'function') { return; }
        render(stories);
    }

    function boot() {
        if (!CFG.feed) { return; }
        fetch(CFG.feed, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.ok ? r.json() : { data: [] }; })
            .then(function (j) { start((j && j.data) || []); })
            .catch(function () { /* network/parse failure: render nothing */ });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
