// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* juwri kit themes — the presentation behaviour every kit theme shares (plain ES module, no dependencies).
   The cart, the quote, sheets, focus and the offline queue belong to the storefront core (assets/storefront/core.js,
   `window.Juwri`); this file only wires the theme's own markup to it:
     [data-kit-open-item]     open an item's sheet (the server renders the sheet; this fetches it and hands it to the core)
     [data-kit-item-form]     option rules, quantity, the Add button (its total is the server quote: Juwri.cart.preview)
     [data-kit-order-type]    order-type radios -> Juwri.cart.setOrderType
     [data-kit-search]        menu search (names + descriptions, Arabic letters normalised)
     [data-kit-cat-more]      a category of a very large catalogue that shows part of its cards: "Load more" (and the scroll) fetch the rest in place
     [data-kit-share]         Web Share, else copy the link
     [data-kit-dismiss]       announcement bar (remembered per text)
     [data-kit-apply-offer]   offer chip -> coupon on the cart
     [data-kit-place-message] / [data-kit-confirm]  what the checkout answered (juwri:order:result)
   Money: nothing here adds, rounds or formats an amount. */

const $ = (root, sel) => root.querySelector(sel);
const $$ = (root, sel) => Array.from(root.querySelectorAll(sel));

function boot() {
    const el = document.querySelector('script[type="application/json"][data-juwri-boot]');
    try { return el ? JSON.parse(el.textContent) : null; } catch (e) { return null; }
}

const J = () => window.Juwri;
const text = (b, key, fallback) => (b && b.i18n && b.i18n[key]) || fallback;
const safe = (fn) => { try { return fn(); } catch (e) { return null; } };
const whenReady = () => (window.Juwri ? Promise.resolve() : new Promise((r) => document.addEventListener('juwri:ready', r, { once: true })));

/** Arabic search normalisation: alef forms, taa marbuta, alef maqsura, tashkeel and tatweel stripped; case folded. */
export function normalize(s) {
    return String(s || '')
        .normalize('NFKD')
        .replace(/[ً-ٰٟـ]/g, '')
        .replace(/[آأإ]/g, 'ا')
        .replace(/ة/g, 'ه')
        .replace(/ى/g, 'ي')
        .toLowerCase()
        .trim();
}

/** The most specific variant whose option map matches the shopper's picks (null when none / not a variant item). */
export function pickVariant(variants, picks) {
    if (!variants || !variants.length) return null;
    const groups = new Set();
    for (const v of variants) for (const g of Object.keys(v.options || {})) groups.add(g);
    const chosen = {};
    for (const g of groups) chosen[g] = picks[g] && picks[g][0];
    let best = null;
    for (const v of variants) {
        const keys = Object.keys(v.options || {});
        if (keys.every((g) => chosen[g] === v.options[g]) && (!best || keys.length > Object.keys(best.options || {}).length)) best = v;
    }
    return { variant: best, groups };
}

function readItemForm(form) {
    const picks = {};
    for (const input of $$(form, 'input[data-group]:checked')) (picks[input.dataset.group] = picks[input.dataset.group] || []).push(input.value);
    let variants = null;
    const data = $(form, 'script[data-kit-variants]');
    if (data) variants = safe(() => JSON.parse(data.textContent));
    const hit = pickVariant(variants, picks);
    const options = [];
    for (const g of Object.keys(picks)) {
        if (hit && hit.variant && hit.groups.has(g)) continue; // the variant's own price already includes these picks
        options.push(...picks[g]);
    }
    // grocery: the item's out-of-stock choice is not a priced option; it rides on the line as `substitution`
    const sub = $(form, 'input[data-kit-substitution]:checked');
    return {
        item: form.dataset.item,
        qty: Number($(form, '[data-kit-qty-value]').textContent) || 1,
        variant: hit && hit.variant ? (hit.variant.sku || null) : null,
        options,
        substitution: sub ? sub.value : null,
        note: ($(form, 'input[name="note"]') || {}).value || '',
        picks,
        unpurchasable: !!(hit && !hit.variant && variants && variants.length),
    };
}

function applyLimits(form) {
    for (const g of $$(form, '[data-kit-group]')) {
        const max = Number(g.dataset.max) || 0;
        if (!max) continue;
        const boxes = $$(g, 'input[type="checkbox"]');
        const on = boxes.filter((b) => b.checked).length;
        for (const b of boxes) b.disabled = !b.checked && on >= max;
    }
}

function itemForm(root, b) {
    const form = $(root, '[data-kit-item-form]');
    if (!form || form.dataset.kitBound) return;
    form.dataset.kitBound = '1';
    const total = $(form, '[data-kit-add-total]');
    let seq = 0;
    const refresh = async () => {
        const mine = ++seq;
        const line = readItemForm(form);
        const j = J();
        if (!j || !j.cart.preview || line.unpurchasable) return;
        const r = await j.cart.preview(line);
        if (mine === seq && r.ok && r.quote && total) total.textContent = r.quote.total.formatted;
    };
    form.addEventListener('change', (e) => {
        if (e.target.matches('input[data-group]')) { applyLimits(form); for (const m of $$(form, '[data-kit-group-error]')) m.hidden = true; refresh(); }
    });
    form.addEventListener('click', (e) => {
        const q = e.target.closest('[data-kit-qty]');
        if (q) {
            const out = $(form, '[data-kit-qty-value]');
            out.textContent = Math.min(99, Math.max(1, (Number(out.textContent) || 1) + Number(q.dataset.kitQty)));
            refresh();
            return;
        }
        if (e.target.closest('[data-kit-add]')) {
            e.preventDefault();
            const missing = $$(form, '[data-kit-group][data-required]').find((g) => !$$(g, 'input:checked').length);
            if (missing) {
                const msg = $(missing, '[data-kit-group-error]');
                if (msg) msg.hidden = false;
                missing.scrollIntoView({ block: 'center' });
                const first = $(missing, 'input'); if (first) first.focus();
                return;
            }
            const line = readItemForm(form);
            if (line.unpurchasable) return;
            const name = (($(root, '#sheet-title') || $(root, '.c-pdp__name') || {}).textContent || '').trim();
            J().cart.add(line.item, { qty: line.qty, variant: line.variant, options: line.options, note: line.note, name, substitution: line.substitution });
            J().sheets.close('item-sheet');
        }
    });
    applyLimits(form);
    const h = $(root, '#sheet-title'); if (h) h.focus();
    refresh();
}

/** Open an item: the sheet is server-rendered; fetch it once and let the core run the dialog. */
function itemOpener(b) {
    const host = document.querySelector('[data-kit-item-host]');
    if (!host || !b) return;
    const home = b.endpoints.home;
    let busy = false;

    async function open(id, opener) {
        if (busy || !J()) return;
        busy = true;
        try {
            const res = await fetch(home + '?fragment=item&item=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { Accept: 'text/html' } });
            if (!res.ok) return;
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            const sheet = doc.getElementById('item-sheet');
            if (!sheet) return;
            const old = document.getElementById('item-sheet'); if (old) old.remove();
            host.replaceChildren(document.importNode(sheet, true));
            const live = document.getElementById('item-sheet');
            live.hidden = true;
            itemForm(live, b);
            J().sheets.open('item-sheet', opener);
        } finally { busy = false; }
    }

    document.addEventListener('click', (e) => {
        const t = e.target.closest('[data-kit-open-item]');
        if (!t || e.defaultPrevented) return;
        e.preventDefault();
        open(t.dataset.kitOpenItem, t);
    });
    document.addEventListener('juwri:sheet:close', (e) => {
        if (e.detail && e.detail.id === 'item-sheet') {
            const old = document.getElementById('item-sheet'); if (old) old.remove();
            if (/^#item-/.test(location.hash)) history.replaceState(null, '', location.pathname + location.search);
        }
    });
    const fromHash = () => {
        const m = /^#item-([0-9a-f-]{36})$/i.exec(location.hash);
        if (m) open(m[1].toLowerCase(), null);
    };
    window.addEventListener('hashchange', fromHash);
    // a server-rendered deep link (?item=) arrives with its sheet in the page; the core must be up to run the dialog
    const pre = document.getElementById('item-sheet');
    whenReady().then(() => {
        if (pre && !pre.hidden) {
            itemForm(pre, b); // the core already opened it (a sheet the server rendered open joins the dialog stack)
        } else fromHash();
    });
}

function orderTypes() {
    document.addEventListener('change', (e) => {
        const r = e.target.closest && e.target.closest('input[data-kit-order-type]');
        if (r && r.checked && J()) J().cart.setOrderType(r.value);
    });
}

/* P5-20: a Google Ordering Redirect deep link arrives with `?order_type=<kit key>`. Preselect it (the same call
   the order-type radios make) when the store offers that key — a radio with that value is rendered only for an
   offered type — then drop ONLY that param so the utm_* campaign params and everything else survive. Defensive: no throw. */
function orderTypeDeepLink() {
    safe(() => {
        const search = location.search;
        if (!search || search === '?') return;
        const key = new URLSearchParams(search).get('order_type');
        if (!key) return;
        whenReady().then(() => safe(() => {
            const offered = Array.from(document.querySelectorAll('input[data-kit-order-type]')).some((r) => r.value === key);
            if (offered && J() && J().cart) J().cart.setOrderType(key);
        }));
        const kept = search.slice(1).split('&').filter((p) => {
            const k = p.split('=')[0].replace(/\+/g, '%20');
            try { return decodeURIComponent(k) !== 'order_type'; } catch (e) { return k !== 'order_type'; }
        });
        history.replaceState(null, '', location.pathname + (kept.length ? '?' + kept.join('&') : '') + location.hash);
    });
}

function checkoutResult(b) {
    const msg = () => document.querySelector('[data-kit-place-message]');
    const confirmBox = () => document.querySelector('[data-kit-confirm]');
    const button = () => document.querySelector('[data-juwri-place]');
    const say = (t) => { const m = msg(); if (m) m.textContent = t || ''; };
    document.addEventListener('juwri:order:result', (e) => {
        const r = e.detail || {};
        const box = confirmBox(); if (box) box.hidden = true;
        const btn = button(); if (btn) btn.removeAttribute('data-juwri-confirmed');
        say('');
        if (r.status === 'placed') {
            if (r.payment_url) location.assign(r.payment_url); // goods paid online: the store's pay page, then the order's tracking page
            else if (r.track_url) location.assign(r.track_url);
            else say(text(b, 'order_placed', 'Order placed'));
        } else if (r.status === 'confirm') {
            if (box) {
                const o = $(box, '[data-kit-old-total]'); const n = $(box, '[data-kit-new-total]');
                if (o) o.textContent = r.previous.total.formatted;
                if (n) n.textContent = r.current.total.formatted;
                box.hidden = false;
            }
            if (btn) btn.setAttribute('data-juwri-confirmed', '');
        } else if (r.status === 'queued') {
            J().sheets.close('checkout'); J().sheets.close('cart');
        } else if (r.status !== 'busy') {
            say(r.message || text(b, 'could_not_place_the_order', 'Could not place the order'));
        }
    });
}

/* P4-16: a very large catalogue draws its first cards; a category's "Load more" (and the scroll, one category at a time) fetches the next
   cards from `?fragment=cat` and appends them. Focus moves to the first new card on a click (never on the scroll), the status line is a
   polite live region, and the search first loads what is missing so it still finds every product. */
let catLoader = null;
function categoryMore() {
    const boxes = () => $$(document, '[data-kit-cat-more]');
    if (!boxes().length) return;
    let queue = Promise.resolve();
    const load = (box, focus) => {
        queue = queue.then(() => fetchCards(box, focus)).catch(() => {});
        return queue;
    };
    async function fetchCards(box, focus) {
        if (!box.isConnected || box.getAttribute('aria-busy') === 'true') return;
        const link = $(box, '[data-kit-cat-load]');
        const list = box.parentNode.querySelector('[data-kit-cat-list]');
        if (!list) return;
        box.setAttribute('aria-busy', 'true');
        try {
            const url = new URL(location.href);
            url.hash = '';
            url.searchParams.delete('category');
            url.searchParams.set('fragment', 'cat');
            url.searchParams.set('category', box.dataset.category);
            url.searchParams.set('offset', box.dataset.offset);
            const res = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'text/html' } });
            if (!res.ok) { if (focus && link) location.assign(link.getAttribute('href')); return; }
            const doc = new DOMParser().parseFromString('<ul>' + (await res.text()) + '</ul>', 'text/html');
            const marker = $(doc, 'li.c-cat__next');
            const cards = $$(doc, 'ul > li:not(.c-cat__next)').map((n) => document.importNode(n, true));
            list.hidden = false;
            for (const c of cards) list.appendChild(c);
            const total = Number(box.dataset.total) || 0;
            const loaded = marker ? Number(marker.dataset.loaded) || 0 : list.children.length;
            const next = marker ? marker.dataset.nextOffset : '';
            const status = $(box, '[data-kit-cat-status]');
            const bar = $(box, 'progress');
            if (status) status.textContent = status.textContent.replace(/\d[\d,.]*/, String(loaded));
            if (bar) bar.value = loaded;
            if (next && loaded < total) box.dataset.offset = next; else box.remove();
            document.dispatchEvent(new CustomEvent('kit:cards-added', { detail: { list } }));
            if (focus && cards[0]) { const f = $(cards[0], 'a, button'); if (f) f.focus(); }
        } finally { box.removeAttribute('aria-busy'); }
    }
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-kit-cat-load]');
        if (!btn) return;
        e.preventDefault();
        load(btn.closest('[data-kit-cat-more]'), true);
    });
    if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver((entries) => {
            for (const en of entries) if (en.isIntersecting) { io.unobserve(en.target); load(en.target, false); }
        }, { rootMargin: '0px 0px 600px 0px' }); // only what is coming up below, never a category already scrolled past
        for (const b of boxes()) io.observe(b);
    }
    catLoader = {
        all: async () => {
            for (const b of boxes()) {
                for (let guard = 0; b.isConnected && guard < 200; guard++) {
                    const before = b.dataset.offset;
                    await load(b, false);
                    if (b.isConnected && b.dataset.offset === before) break; // nothing arrived: stop, do not loop
                }
            }
        },
    };
}

function search() {
    // one or more search fields (a header one on desktop, one in the page on a phone): they share one query
    const forms = $$(document, '[data-kit-search]');
    if (!forms.length) return;
    const inputs = forms.map((f) => $(f, 'input[type="search"]')).filter(Boolean);
    const cards = () => $$(document, '.c-catalog .c-card, .c-catalog .c-gcard, .c-catalog .c-pcard');
    const run = (value, quiet) => {
        const q = normalize(value);
        let shown = 0;
        for (const c of cards()) {
            const hay = normalize(c.textContent);
            const hit = q === '' || hay.includes(q);
            c.hidden = !hit;
            if (hit) shown += 1;
        }
        for (const s of $$(document, '.c-catalog .c-cat')) s.hidden = !$$(s, '.c-card, .c-gcard, .c-pcard').some((c) => !c.hidden);
        const none = document.querySelector('[data-kit-noresults]');
        if (none) none.hidden = shown > 0 || q === '';
        if (J() && q !== '' && !quiet) J().announce(String(shown));
    };
    // cards that arrive later (a category's "Load more") are filtered by the query in the box
    document.addEventListener('kit:cards-added', () => { if (inputs[0] && inputs[0].value !== '') run(inputs[0].value, true); });
    for (const form of forms) form.addEventListener('submit', (e) => e.preventDefault());
    for (const input of inputs) {
        input.addEventListener('input', () => {
            for (const other of inputs) if (other !== input) other.value = input.value;
            run(input.value);
            // a very large catalogue is not all on the page: bring in the rest, then search again
            if (catLoader && normalize(input.value) !== '') catLoader.all().then(() => run(input.value));
        });
        input.addEventListener('keydown', (e) => { if (e.key === 'Escape') { input.value = ''; input.dispatchEvent(new Event('input')); } });
    }
}

function scrollSpy() {
    const links = $$(document, '.c-catnav__link');
    if (!links.length || !('IntersectionObserver' in window)) return;
    const byId = new Map(links.map((l) => [l.getAttribute('href').slice(1), l]));
    const io = new IntersectionObserver((entries) => {
        for (const en of entries) {
            if (!en.isIntersecting) continue;
            const l = byId.get(en.target.id);
            if (!l) continue;
            for (const o of links) o.removeAttribute('aria-current');
            l.setAttribute('aria-current', 'true');
        }
    }, { rootMargin: '-20% 0px -70% 0px' });
    for (const s of $$(document, '.c-cat')) io.observe(s);
}

function chrome(b) {
    document.addEventListener('click', async (e) => {
        const share = e.target.closest('[data-kit-share]');
        if (share) {
            const data = { title: document.title, url: location.href };
            if (navigator.share) { safe(() => navigator.share(data)); return; }
            if (navigator.clipboard) { await safe(() => navigator.clipboard.writeText(data.url)); if (J()) J().announce(text(b, 'link_copied', 'Link copied')); }
            return;
        }
        const dismiss = e.target.closest('[data-kit-dismiss]');
        if (dismiss) {
            const bar = dismiss.closest('.c-announce');
            if (bar) { safe(() => localStorage.setItem('juwri-announce:' + location.host + location.pathname, bar.textContent.trim().slice(0, 80))); bar.remove(); }
            return;
        }
        const offer = e.target.closest('[data-kit-apply-offer]');
        if (offer && J()) { J().cart.setCoupon(offer.dataset.kitApplyOffer); J().sheets.open('cart', offer); }
    });
    const bar = document.querySelector('.c-announce');
    if (bar && safe(() => localStorage.getItem('juwri-announce:' + location.host + location.pathname)) === bar.textContent.trim().slice(0, 80)) bar.remove();
    document.addEventListener('submit', (e) => { if (e.target.matches('[data-kit-coupon]')) e.preventDefault(); });
}

/** A product that is a page of its own (aisle) carries its form in the page, not in a sheet: bind it once the core is up. */
function pageItem(b) {
    const form = document.querySelector('[data-kit-item-form]');
    if (!form || form.closest('#item-sheet') || !b) return;
    whenReady().then(() => itemForm(form.parentNode, b));
}

/** the delivery pin at checkout (a store with a delivery radius): the shared map picker fills delivery_lat/lng */
function deliveryPin() {
    const box = document.querySelector('[data-kit-pin]');
    if (!box || !window.mbMapPicker) return;
    const $ = (id) => document.getElementById(id);
    let center = null;
    try { center = JSON.parse(box.dataset.center || 'null'); } catch (_e) { center = null; }
    window.mbMapPicker({
        trigger: $('f-pin-open'), modal: $('f-pin-modal'), map: $('f-pin-map'), input: $('f-address') || $('f-pin-status'),
        confirm: $('f-pin-confirm'), close: $('f-pin-cancel'), preview: $('f-pin-status'), lat: $('f-pin-lat'), lng: $('f-pin-lng'),
        center: Array.isArray(center) ? center : undefined, i18n: { locating: box.dataset.locating, hint: box.dataset.hint },
    });
}

export function initKit() {
    const b = boot();
    itemOpener(b);
    pageItem(b);
    orderTypes();
    orderTypeDeepLink();
    checkoutResult(b);
    categoryMore();
    search();
    scrollSpy();
    chrome(b);
    deliveryPin();
}
