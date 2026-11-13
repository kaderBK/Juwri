// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* aisle: the retail kit theme. The shared kit script (../../_kit/kit.js) runs the cart hooks, the option rules and the live price;
   this file adds what a shop needs on top of it, and only ever calls the storefront's own endpoints (the view model names them):
     - the product form: stock per variant (sold-out sizes, "Only 2 left in M", Notify me instead of Add), the ?variant= link
     - the wishlist hearts (guest: a cookie token on the server; signed in: the account), the wishlist page, move-to-bag
     - the gallery, the search toggle, filters (a plain GET form submitted on change), "Load more"
     - the size guide, back-in-stock opt-in and the return request dialogs
     - the checkout: shipping methods for the address (the server's dates and fees), payment label
   No money is added, rounded or formatted here: every amount shown is a string the server sent. */
import { initKit, pickVariant } from '../../_kit/kit.js';

initKit();

const $ = (root, sel) => root.querySelector(sel);
const $$ = (root, sel) => Array.from(root.querySelectorAll(sel));
const J = () => window.Juwri;
const safe = (fn) => { try { return fn(); } catch (e) { return null; } };
const whenReady = () => (window.Juwri ? Promise.resolve() : new Promise((r) => document.addEventListener('juwri:ready', r, { once: true })));
const later = (fn, ms) => { let t = null; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

function boot() {
    const el = document.querySelector('script[type="application/json"][data-juwri-boot]');
    return safe(() => (el ? JSON.parse(el.textContent) : null));
}
const B = boot();
const endpoints = (B && B.endpoints) || {};
const say = (t) => { if (t && J() && J().announce) J().announce(t); };

function csrf() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') || '' : '';
}

/** JSON request with the session CSRF token; one retry after a stale token (419). Resolves {status, ok, json}. */
async function request(method, url, body) {
    const once = async (token) => {
        const res = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: Object.assign({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
                body === undefined ? {} : { 'Content-Type': 'application/json' }),
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        let json = null;
        try { json = await res.json(); } catch (e) { json = null; }
        return { status: res.status, ok: res.ok, json };
    };
    let r = await once(csrf());
    if (r.status === 419 && endpoints.home) {
        const page = await fetch(endpoints.home, { credentials: 'same-origin', cache: 'no-store' }).then((x) => x.text()).catch(() => '');
        const m = /<meta name="csrf-token" content="([^"]+)"/.exec(page);
        if (m) r = await once(m[1]);
    }
    return r;
}

/* ------------------------------------------------------------------ header */
function searchToggle() {
    const btn = $(document, '[data-kit-search-toggle]');
    const header = $(document, '.c-header');
    if (!btn || !header) return;
    btn.addEventListener('click', () => {
        const on = !header.classList.contains('is-searching');
        header.classList.toggle('is-searching', on);
        btn.setAttribute('aria-expanded', on ? 'true' : 'false');
        if (on) { const input = $(header, '.c-header__input'); if (input) input.focus(); }
    });
}

/* ------------------------------------------------------------------ gallery */
function gallery() {
    const root = $(document, '[data-kit-gallery]');
    if (!root) return;
    const track = $(root, '[data-kit-gallery-track]');
    const pager = $(root, '[data-kit-gallery-pager]');
    const total = pager ? Number(pager.dataset.total) : 0;
    const slideWidth = () => (track.firstElementChild ? track.firstElementChild.getBoundingClientRect().width : track.clientWidth) || 1;
    const index = () => {
        const dir = getComputedStyle(track).direction === 'rtl' ? -1 : 1;
        return Math.max(0, Math.min(total - 1, Math.round((dir * track.scrollLeft) / slideWidth())));
    };
    const go = (to) => {
        const dir = getComputedStyle(track).direction === 'rtl' ? -1 : 1;
        track.scrollTo({ left: dir * Math.max(0, Math.min(total - 1, to)) * slideWidth(), behavior: 'smooth' });
    };
    const paint = () => {
        if (!pager) return;
        pager.textContent = (pager.dataset.text || '').replace(':n', String(index() + 1)).replace(':total', String(total));
    };
    const prev = $(root, '[data-kit-gallery-prev]');
    const next = $(root, '[data-kit-gallery-next]');
    if (prev) prev.addEventListener('click', () => go(index() - 1));
    if (next) next.addEventListener('click', () => go(index() + 1));
    track.addEventListener('scroll', later(paint, 80), { passive: true });
    track.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
            const rtl = getComputedStyle(track).direction === 'rtl';
            go(index() + ((e.key === 'ArrowRight') !== rtl ? 1 : -1));
            e.preventDefault();
        }
    });
}

/* ------------------------------------------------------------------ wishlist */
const wish = { ids: new Set(), loaded: false };

function wishTexts() {
    const el = $(document, '[data-kit-wish-texts]');
    return el ? el.dataset : {};
}

function paintHearts() {
    for (const h of $$(document, '[data-kit-wish]')) h.setAttribute('aria-pressed', wish.ids.has(h.dataset.kitWish) ? 'true' : 'false');
}

async function wishlistSync() {
    if (!endpoints.wishlist || !$(document, '[data-kit-wish]')) return;
    const r = await request('GET', endpoints.wishlist).catch(() => null);
    if (r && r.ok && r.json && Array.isArray(r.json.items)) {
        wish.ids = new Set(r.json.items.map((i) => i.item_id));
        wish.loaded = true;
        paintHearts();
    }
}

async function wishSet(id, on, variant) {
    if (!endpoints.wishlist) return false;
    const r = on
        ? await request('POST', endpoints.wishlist, Object.assign({ item_id: id }, variant ? { variant_id: variant } : {})).catch(() => null)
        : await request('DELETE', endpoints.wishlist + '/' + encodeURIComponent(id)).catch(() => null);
    if (!r || !r.ok) { say(wishTexts().failed); return false; }
    if (on) wish.ids.add(id); else wish.ids.delete(id);
    paintHearts();
    say(on ? wishTexts().saved : wishTexts().removed);
    return true;
}

function wishlist() {
    document.addEventListener('click', async (e) => {
        const heart = e.target.closest('[data-kit-wish]');
        if (heart) {
            e.preventDefault();
            const id = heart.dataset.kitWish;
            const on = heart.getAttribute('aria-pressed') !== 'true';
            const ok = await wishSet(id, on);
            const onPage = heart.closest('[data-kit-wishlist]');
            if (ok && !on && onPage) dropFromWishlistPage(id);
            return;
        }
        const move = e.target.closest('[data-kit-wish-move]');
        if (move && J()) {
            e.preventDefault();
            const id = move.dataset.kitWishMove;
            J().cart.add(id, { variant: move.dataset.variant || null, name: move.dataset.name || '' });
            if (await wishSet(id, false)) dropFromWishlistPage(id);
            return;
        }
        const remove = e.target.closest('[data-kit-wish-remove]');
        if (remove) {
            e.preventDefault();
            const id = remove.dataset.kitWishRemove;
            if (await wishSet(id, false)) dropFromWishlistPage(id);
            return;
        }
        const toBag = e.target.closest('[data-kit-move-to-wishlist]');
        if (toBag && J()) {
            e.preventDefault();
            const row = toBag.closest('.c-bagline');
            const rm = row && $(row, '[data-juwri-remove]');
            const key = rm && rm.getAttribute('data-juwri-remove');
            const line = key ? J().cart.snapshot().lines.find((l) => l.key === key) : null;
            if (line && (await wishSet(line.item, true))) J().cart.remove(key);
        }
    });
    whenReady().then(wishlistSync);
}

function dropFromWishlistPage(id) {
    const grid = $(document, '[data-kit-wishlist]');
    if (!grid) return;
    const card = $(grid, '.c-pcard[data-item="' + id + '"]');
    if (card) card.remove();
    const left = $$(grid, '.c-pcard').length;
    if (left === 0) location.reload();
}

/* ------------------------------------------------------------------ the product form */
function readVariants(form) {
    const data = $(form, 'script[data-kit-variants]');
    return data ? safe(() => JSON.parse(data.textContent)) : null;
}

function productForm() {
    const form = $(document, '.c-pdp [data-kit-item-form], .c-sheet--aisle-item [data-kit-item-form]');
    if (!form) return;
    paintProduct(form);
    form.addEventListener('change', (e) => { if (e.target.matches('input[data-group]')) paintProduct(form, true); });
    document.addEventListener('juwri:sheet:open', (e) => {
        if (e.detail && e.detail.id === 'item-sheet') { const f = $(document, '#item-sheet [data-kit-item-form]'); if (f) paintProduct(f); }
    });
}

function picksOf(form) {
    const picks = {};
    for (const input of $$(form, 'input[data-group]:checked')) (picks[input.dataset.group] = picks[input.dataset.group] || []).push(input.value);
    return picks;
}

/** stock of every variant of a product that matches `want` (group id => option id): 'out' when all are out, 'ok' otherwise, null when none exists */
function cellState(variants, want) {
    const hits = variants.filter((v) => Object.keys(want).every((g) => v.options[g] === want[g]));
    if (!hits.length) return null;
    return hits.every((v) => v.stock === 0) ? 'out' : 'ok';
}

function paintProduct(form, fromChange) {
    const variants = readVariants(form) || [];
    const picks = picksOf(form);
    const chosen = {};
    for (const g of Object.keys(picks)) chosen[g] = picks[g][0];

    // legends: "Colour: Sand"
    for (const group of $$(form, '[data-kit-opt]')) {
        const on = $(group, 'input:checked');
        const out = $(group, '[data-kit-opt-value]');
        if (out) out.textContent = on ? on.dataset.label || '' : '';
        if (on) { const err = $(group, '[data-kit-group-error]'); if (err) err.hidden = true; }
    }

    // sold-out marks: an option is struck through when every variant with it (and the other choices made) is out
    if (variants.length) {
        for (const group of $$(form, '[data-kit-opt]')) {
            const gid = group.dataset.kitGroup;
            for (const input of $$(group, 'input[data-group]')) {
                const want = Object.assign({}, chosen);
                want[gid] = input.value;
                const state = cellState(variants, want);
                const label = input.closest('label');
                const out = state === 'out' || (state === null && Object.keys(chosen).length > 1);
                if (label) label.classList.toggle('is-soldout', out);
                if (out) input.setAttribute('aria-disabled', 'true'); else input.removeAttribute('aria-disabled');
            }
        }
    }

    // the stock note, Add vs Notify me
    const note = $(form, '[data-kit-stock]');
    const noteText = note ? $(note, '[data-kit-stock-text]') : null;
    const add = $(form, '[data-kit-add]');
    const notify = $(form, '[data-kit-notify-open]');
    const hit = pickVariant(variants, picks);
    const required = $$(form, '[data-kit-group][data-required]');
    const complete = required.every((g) => $(g, 'input:checked'));
    let state = 'ok';
    let message = '';
    if (variants.length && complete && hit) {
        if (!hit.variant) { state = 'none'; message = note ? note.dataset.textNone : ''; }
        else if (hit.variant.stock === 0) { state = 'out'; message = note ? note.dataset.textOut : ''; }
        else if (typeof hit.variant.stock === 'number' && hit.variant.stock > 0) {
            const size = $(form, '[data-kit-opt="size"]');
            const picked = size ? $(size, 'input:checked') : null;
            const tpl = note ? (picked ? note.dataset.textLow : note.dataset.textLowPlain) : '';
            message = (tpl || '').replace(':count', String(hit.variant.stock)).replace(':option', picked ? picked.dataset.label || '' : '');
            state = 'low';
        }
    }
    if (note) { note.hidden = message === ''; if (noteText) noteText.textContent = message; }
    if (add) add.hidden = state === 'out' || state === 'none';
    if (notify) notify.hidden = state !== 'out' && !notify.hasAttribute('data-sold-out');
    if (notify && state === 'out') {
        const what = $(document, '[data-kit-notify-what]');
        const labels = $$(form, 'input[data-group]:checked').map((i) => i.dataset.label).filter(Boolean).join(' · ');
        const name = ($(document, '.c-pdp__name') || $(document, '#sheet-title') || {}).textContent || '';
        if (what) what.textContent = [name.trim(), labels].filter(Boolean).join(' — ');
        notify.dataset.variant = hit && hit.variant ? hit.variant.sku || '' : '';
    }

    // the shareable link: ?variant=<sku> (replace, not push)
    if (fromChange && form.dataset.variantUrl && form.closest('.c-pdp')) {
        const url = form.dataset.variantUrl + (hit && hit.variant ? '&variant=' + encodeURIComponent(hit.variant.sku || '') : '');
        safe(() => history.replaceState(null, '', url));
    }
}

/* ------------------------------------------------------------------ filters, sort, load more */
function filters() {
    const submit = (form) => {
        // empty fields are left out of the address: a tidy, shareable URL
        const blank = $$(form, 'input[name]:not([type="checkbox"]):not([type="radio"])').filter((i) => i.value.trim() === '');
        for (const i of blank) i.disabled = true;
        const none = $$(form, 'input[type="radio"][name="category"]:checked').filter((i) => i.value === '');
        for (const i of none) i.disabled = true;
        form.submit();
    };
    for (const form of $$(document, 'form[data-kit-filters]')) {
        const auto = later(() => { if (!form.closest('[data-juwri-sheet]')) submit(form); }, 350);
        form.addEventListener('change', (e) => { if (!e.target.matches('input[name^="price_"]')) auto(); });
        form.addEventListener('submit', (e) => { e.preventDefault(); submit(form); });
        for (const p of $$(form, 'input[name^="price_"]')) p.addEventListener('blur', () => { if (p.value !== p.defaultValue && !form.closest('[data-juwri-sheet]')) auto(); });
    }
    const sort = $(document, '[data-kit-sort]');
    if (sort) sort.addEventListener('change', () => { const f = document.getElementById(sort.getAttribute('form')); if (f) submit(f); });
}

function loadMore() {
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-kit-load-more]');
        if (!btn) return;
        e.preventDefault();
        const grid = $(document, '[data-kit-grid]');
        if (!grid || btn.disabled) return;
        btn.disabled = true;
        try {
            const url = new URL(btn.getAttribute('href'), location.href);
            url.searchParams.set('fragment', 'grid');
            const res = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'text/html' } });
            if (!res.ok) { location.assign(btn.getAttribute('href')); return; }
            const doc = new DOMParser().parseFromString('<ul>' + (await res.text()) + '</ul>', 'text/html');
            const cards = $$(doc, 'li.c-pcard').map((n) => document.importNode(n, true));
            const marker = $(doc, 'li.c-pgrid__next');
            for (const c of cards) grid.appendChild(c);
            const nextUrl = marker ? marker.dataset.nextUrl : '';
            const more = $(document, '[data-kit-more]');
            const shown = grid.children.length;
            if (more) {
                const bar = $(more, 'progress');
                const text = $(more, '.c-more__text');
                if (bar) bar.value = shown;
                if (text) text.textContent = text.textContent.replace(/\d[\d,.]*/, String(shown));
                if (nextUrl) btn.setAttribute('href', nextUrl); else more.remove();
            }
            paintHearts();
            const first = cards[0] && $(cards[0], '.c-pcard__link');
            if (first) first.focus();
        } finally { btn.disabled = false; }
    });
}

/* ------------------------------------------------------------------ size guide */
function sizeGuide() {
    let url = '';
    let data = null;
    let unit = 'cm';
    document.addEventListener('click', (e) => {
        const t = e.target.closest('[data-kit-size-guide]');
        if (t) url = t.dataset.kitSizeGuide;
        const u = e.target.closest('[data-kit-unit]');
        if (u) { unit = u.dataset.kitUnit; paint(); }
    });
    const dialog = () => $(document, '[data-kit-guide]');
    const number = (v) => {
        const n = Number(String(v).replace(',', '.'));
        if (!Number.isFinite(n) || unit === (data && data.unit)) return String(v);
        const out = unit === 'in' ? n / 2.54 : n * 2.54;
        return new Intl.NumberFormat(document.documentElement.lang || undefined, { maximumFractionDigits: 1 }).format(out);
    };
    function paint() {
        const d = dialog();
        if (!d || !data) return;
        const head = $(d, 'thead tr');
        const body = $(d, 'tbody');
        head.replaceChildren(...data.columns.map((c) => { const th = document.createElement('th'); th.scope = 'col'; th.textContent = c; return th; }));
        body.replaceChildren(...data.rows.map((row) => {
            const tr = document.createElement('tr');
            row.forEach((cell, i) => { const el = document.createElement(i === 0 ? 'th' : 'td'); if (i === 0) el.scope = 'row'; el.textContent = i === 0 ? cell : number(cell); tr.appendChild(el); });
            return tr;
        }));
        const metric = data.unit === 'cm' || data.unit === 'in';
        const units = $(d, '[data-kit-guide-units]');
        if (units) { units.hidden = !metric; for (const b of $$(units, 'button')) b.setAttribute('aria-pressed', b.dataset.kitUnit === unit ? 'true' : 'false'); }
        const note = $(d, '[data-kit-guide-note]');
        if (note) note.textContent = [metric ? (unit === 'cm' ? d.dataset.textCm : d.dataset.textIn) : '', data.note || ''].filter(Boolean).join(' · ');
    }
    document.addEventListener('juwri:sheet:open', async (e) => {
        if (!(e.detail && e.detail.id === 'size-guide') || !url) return;
        const d = dialog();
        const note = d && $(d, '[data-kit-guide-note]');
        const r = await request('GET', url).catch(() => null);
        if (!r || !r.ok || !r.json) { if (note) note.textContent = d.dataset.textError; return; }
        data = r.json;
        unit = data.unit === 'in' ? 'in' : 'cm';
        paint();
    });
}

/* ------------------------------------------------------------------ back in stock */
function notifyMe() {
    const form = $(document, '[data-kit-notify]');
    if (!form) return;
    const message = $(form, '[data-kit-notify-message]');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        message.textContent = '';
        const contact = form.elements.contact.value.trim();
        const opener = $(document, '[data-kit-notify-open]');
        const item = ($(document, '[data-kit-item-form]') || {}).dataset ? $(document, '[data-kit-item-form]').dataset.item : '';
        const body = {
            item_id: item,
            channel: contact.indexOf('@') >= 0 ? 'email' : 'whatsapp',
            contact,
            marketing_opt_in: !!form.elements.marketing_opt_in.checked,
        };
        if (opener && opener.dataset.variant) body.variant_id = opener.dataset.variant;
        const r = await request('POST', endpoints.stock_alerts, body).catch(() => null);
        if (r && r.ok && r.json) {
            say(r.json.message);
            message.textContent = r.json.message || '';
            message.classList.remove('c-field__error');
            form.reset();
        } else {
            message.classList.add('c-field__error');
            message.textContent = (r && r.json && r.json.message) || '';
        }
    });
}

/* ------------------------------------------------------------------ returns */
function returns() {
    const dialog = $(document, '[data-kit-returns]');
    if (!dialog) return;
    const form = $(dialog, '[data-kit-returns-form]');
    const closed = $(dialog, '[data-kit-returns-closed]');
    const done = $(dialog, '[data-kit-returns-done]');
    const message = $(dialog, '[data-kit-returns-message]');
    let url = '';
    document.addEventListener('click', (e) => { const t = e.target.closest('[data-kit-returns-open]'); if (t) url = t.dataset.url; });
    const labels = $(dialog, '[data-kit-resolution-labels]');

    document.addEventListener('juwri:sheet:open', async (e) => {
        if (!(e.detail && e.detail.id === 'returns') || !url) return;
        done.hidden = true; message.textContent = '';
        const r = await request('GET', url).catch(() => null);
        const d = r && r.ok ? r.json : null;
        if (!d || !d.offered) { form.hidden = true; closed.hidden = false; return; }
        closed.hidden = true; form.hidden = false;
        const until = $(form, '[data-kit-returns-until]');
        if (until && d.until) until.textContent = dialog.dataset.textUntil.replace(':date', new Date(d.until).toLocaleDateString(document.documentElement.lang || undefined, { dateStyle: 'medium' }));
        const list = $(form, '[data-kit-returns-lines]');
        const tpl = $(list, 'template');
        for (const old of $$(list, 'li')) old.remove();
        d.lines.forEach((line, i) => {
            const li = tpl.content.firstElementChild.cloneNode(true);
            const box = $(li, 'input');
            const name = $(li, '[data-kit-returns-name]');
            const sel = $(li, 'select');
            box.id = 'ret-' + i; box.value = String(line.order_item_id);
            li.querySelector('label').setAttribute('for', box.id);
            name.textContent = line.name;
            for (let q = 1; q <= line.qty_returnable; q += 1) sel.appendChild(new Option(String(q), String(q)));
            sel.value = String(line.qty_returnable);
            list.appendChild(li);
        });
        const host = $(form, '[data-kit-returns-resolutions]');
        const rtpl = $(host, 'template');
        for (const old of $$(host, 'label')) old.remove();
        d.resolutions.forEach((res, i) => {
            const el = rtpl.content.firstElementChild.cloneNode(true);
            const input = $(el, 'input');
            input.value = res; input.id = 'res-' + i; input.checked = i === 0;
            el.setAttribute('for', input.id);
            $(el, '[data-kit-returns-resolution-label]').textContent = (labels && labels.getAttribute('data-text-' + res)) || res;
            host.appendChild(el);
        });
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        message.textContent = '';
        const lines = $$(form, '[data-kit-returns-lines] li').filter((li) => $(li, 'input').checked)
            .map((li) => ({ order_item_id: Number($(li, 'input').value), qty: Number($(li, 'select').value) }));
        const resolution = ($(form, 'input[name="resolution"]:checked') || {}).value;
        if (!lines.length || !resolution) { message.textContent = dialog.dataset.textError; return; }
        const r = await request('POST', url, { resolution, reason: form.elements.reason.value.trim(), lines }).catch(() => null);
        if (r && r.ok) {
            form.hidden = true; done.hidden = false; done.textContent = (r.json && r.json.message) || dialog.dataset.textSent;
            say(done.textContent);
        } else message.textContent = (r && r.json && r.json.message) || dialog.dataset.textError;
    });
}

/* ------------------------------------------------------------------ checkout: shipping methods */
function checkout() {
    const form = $(document, '[data-kit-shipping-form]');
    if (!form || !endpoints.shipping_quote) return;
    const host = $(form, '[data-kit-shipping-methods]');
    if (!host) return; // a kiosk checkout has no shipping
    const tpl = $(form, 'template[data-kit-shipping-template]');
    const hint = $(form, '[data-kit-shipping-hint]');
    const msg = $(form, '[data-kit-shipping-message]');
    let seq = 0;
    let methods = [];

    const address = () => {
        const out = {};
        for (const [field, name] of [['country', 'country'], ['line1', 'line1'], ['city', 'city'], ['region', 'region'], ['postal_code', 'postal_code']]) {
            const el = form.elements[name];
            const v = el && el.value ? el.value.trim() : '';
            if (v) out[field] = field === 'country' ? v.toUpperCase() : v;
        }
        return out;
    };
    const lines = () => J().cart.snapshot().lines.map((l) => {
        const out = { item: l.item, qty: l.qty };
        if (l.variant) out.variant = l.variant;
        if (l.options && l.options.length) out.options = l.options;
        return out;
    });
    const selected = () => {
        const r = $(host, 'input[name="shipping_method"]:checked');
        return r ? r.value : null;
    };
    const choose = () => {
        const id = selected();
        J().cart.setShipping(id, id ? address() : null);
    };

    function render(list, keep) {
        for (const old of $$(host, 'label')) old.remove();
        methods = list;
        list.forEach((m, i) => {
            const d = m.display || {};
            const el = tpl.content.firstElementChild.cloneNode(true);
            const input = $(el, 'input');
            input.value = d.id || m.id;
            input.id = 'ship-' + i;
            input.checked = keep ? (d.id || m.id) === keep : i === 0;
            el.setAttribute('for', input.id);
            $(el, '[data-kit-method-label]').textContent = d.label || '';
            $(el, '[data-kit-method-sub]').textContent = d.sub || '';
            $(el, '[data-kit-method-price]').textContent = d.amount_label || (m.fee && m.fee.formatted) || '';
            host.appendChild(el);
        });
        if (list.length && !$(host, 'input:checked')) { const first = $(host, 'input'); if (first) first.checked = true; }
    }

    const ask = async () => {
        const mine = ++seq;
        const a = address();
        if (!J() || !J().sheets.isOpen('checkout') || !J().cart.snapshot().lines.length || !a.country) return;
        const keep = selected();
        const r = await request('POST', endpoints.shipping_quote, { lines: lines(), address: a, shipping_method: keep || undefined }).catch(() => null);
        if (mine !== seq) return;
        if (!r || !r.ok || !r.json) { if (hint) hint.hidden = false; return; }
        render(r.json.methods || [], keep);
        if (hint) hint.hidden = (r.json.methods || []).length > 0;
        if (msg) msg.hidden = (r.json.methods || []).length > 0;
        choose();
    };
    const askSoon = later(ask, 450);

    form.addEventListener('input', (e) => { if (e.target.matches('[data-kit-address] input')) askSoon(); });
    form.addEventListener('change', (e) => {
        if (e.target.matches('input[name="shipping_method"]')) choose();
        if (e.target.matches('input[name="pay_via"]')) payLabel();
    });
    function payLabel() {
        const online = ($(form, 'input[name="pay_via"]:checked') || {}).value === 'pay_online';
        const pay = $(form, '[data-kit-place-pay]');
        const order = $(form, '[data-kit-place-order]');
        if (pay) pay.hidden = !online;
        if (order) order.hidden = online;
    }
    document.addEventListener('juwri:sheet:open', (e) => { if (e.detail && e.detail.id === 'checkout') { payLabel(); ask(); } });
    document.addEventListener('juwri:cart:changed', (e) => {
        const why = e.detail && e.detail.why;
        if (why === 'add' || why === 'update' || why === 'remove') askSoon();
    });
}

/* ------------------------------------------------------------------ the bag opens after an add on desktop */
function bagOnAdd() {
    document.addEventListener('juwri:cart:add', () => {
        if (!J() || !window.matchMedia('(min-width: 1024px)').matches || !$(document, '.c-pdp')) return;
        J().sheets.open('cart', $(document, '.c-bagbtn--drawer'));
    });
}

searchToggle();
gallery();
wishlist();
productForm();
filters();
loadMore();
sizeGuide();
notifyMe();
returns();
bagOnAdd();
whenReady().then(checkout);
