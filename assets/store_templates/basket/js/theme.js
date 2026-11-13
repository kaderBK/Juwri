// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* basket: the grocery kit theme. The shared kit script (../../_kit/kit.js) runs the item sheet, the option rules and the live price;
   the storefront core (assets/storefront/core.js) runs the basket, the quote and the order. This file adds what a grocery needs on top,
   and only ever calls the storefront's own endpoints (the view model names them):
     - the product cards: Add turns into a quantity stepper once the product is in the basket; "Sort by" reorders each category
     - the checkout: the delivery / collection time (days, the store's hourly slots with their capacity, express), the 10-minute hold
       that keeps the chosen time (taken when it is picked, released when another is picked or the basket is emptied), the out-of-stock
       choice, the payment methods and the card hold note of the server quote, the place button's gate
     - the tracking page: keep or refuse a replacement the picker proposed
   No money is added, rounded or formatted here: every amount shown is a string the server sent. */
import { initKit } from '../../_kit/kit.js';

initKit();

const $ = (root, sel) => root.querySelector(sel);
const $$ = (root, sel) => Array.from(root.querySelectorAll(sel));
const J = () => window.Juwri;
const safe = (fn) => { try { return fn(); } catch (e) { return null; } };
const whenReady = () => (window.Juwri ? Promise.resolve() : new Promise((r) => document.addEventListener('juwri:ready', r, { once: true })));

function boot() {
    const el = document.querySelector('script[type="application/json"][data-juwri-boot]');
    return safe(() => (el ? JSON.parse(el.textContent) : null));
}
const B = boot();
const endpoints = (B && B.endpoints) || {};
const kiosk = !!(B && B.context && B.context.kiosk && B.context.kiosk.on);
const say = (t) => { if (t && J() && J().announce) J().announce(t); };

function csrf() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') || '' : '';
}

/** JSON request with the session CSRF token; one retry after a stale token (419). Resolves {status, ok, json}. */
async function request(method, url, body, headers) {
    const once = async (token) => {
        const res = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: Object.assign({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
                body === undefined ? {} : { 'Content-Type': 'application/json' }, headers || {}),
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

/* ------------------------------------------------------------------ product cards */
/** the plain line of a product: no variant, choices, note or out-of-stock choice (what the card's Add makes) */
function plainLine(snap, item) {
    return snap.lines.find((l) => l.item === item && !l.variant && !(l.options && l.options.length) && !l.note && !l.substitution) || null;
}

function paintCards() {
    if (!J()) return;
    const snap = J().cart.snapshot();
    const total = {};
    for (const l of snap.lines) total[l.item] = (total[l.item] || 0) + l.qty;
    for (const card of $$(document, '.c-pcard[data-item]')) {
        const id = card.dataset.item;
        const qty = total[id] || 0;
        card.classList.toggle('is-incart', qty > 0);
        const stepper = $(card, '[data-bk-stepper]');
        const add = $(card, '[data-bk-add]');
        if (stepper && add) {
            const line = plainLine(snap, id);
            const q = line ? line.qty : 0;
            stepper.hidden = q === 0;
            add.hidden = q > 0;
            const out = $(stepper, '[data-bk-qty]');
            if (out) out.textContent = String(q);
        }
        const badge = $(card, '[data-bk-qty-badge]');
        if (badge) { badge.hidden = qty === 0; badge.textContent = qty ? String(qty) : ''; }
    }
}

function cards() {
    document.addEventListener('click', (e) => {
        const inc = e.target.closest('[data-bk-inc]');
        const dec = e.target.closest('[data-bk-dec]');
        const btn = inc || dec;
        if (!btn || !J()) return;
        const card = btn.closest('.c-pcard');
        if (!card) return;
        e.preventDefault();
        const id = card.dataset.item;
        const line = plainLine(J().cart.snapshot(), id);
        if (inc) {
            if (line) J().cart.update(line.key, line.qty + 1); else J().cart.add(id, { name: card.dataset.name || '' });
        } else if (line) {
            J().cart.update(line.key, line.qty - 1);
        }
    });
    for (const ev of ['cart:changed', 'ready']) document.addEventListener('juwri:' + ev, paintCards);
    document.addEventListener('kit:cards-added', paintCards); // P4-16: cards a category's "Load more" brought in
    whenReady().then(paintCards);
}

function sorting() {
    const select = $(document, '[data-bk-sort]');
    if (!select) return;
    const lang = document.documentElement.lang || undefined;
    select.addEventListener('change', () => {
        for (const grid of $$(document, '[data-bk-grid]')) {
            const items = Array.from(grid.children);
            const by = {
                popular: (a, b) => Number(a.dataset.pos) - Number(b.dataset.pos),
                price_asc: (a, b) => Number(a.dataset.price) - Number(b.dataset.price),
                price_desc: (a, b) => Number(b.dataset.price) - Number(a.dataset.price),
                name: (a, b) => String(a.dataset.name).localeCompare(String(b.dataset.name), lang),
            }[select.value] || ((a, b) => 0);
            items.sort(by);
            for (const li of items) grid.appendChild(li);
        }
        say(select.options[select.selectedIndex].textContent);
    });
    // P4-16: cards a category's "Load more" brought in take the chosen order too
    document.addEventListener('kit:cards-added', () => { if (select.value !== 'popular') select.dispatchEvent(new Event('change')); });
}

/* A large catalogue is listed by the server (search, category, sort, "Load more"): a sort change is a new page of the list, and
   the next page's cards are appended in place. */
function paging() {
    const sort = $(document, '[data-bk-sort-server]');
    if (sort) {
        sort.addEventListener('change', () => {
            const q = new URLSearchParams(sort.dataset.query || '');
            if (sort.value === 'position') q.delete('sort'); else q.set('sort', sort.value);
            const qs = q.toString();
            location.assign((sort.dataset.url || '/') + (qs ? '?' + qs : ''));
        });
    }
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-bk-load-more]');
        if (!btn) return;
        e.preventDefault();
        const grid = $(document, '[data-bk-grid]');
        if (!grid || btn.disabled) return;
        btn.disabled = true;
        try {
            const url = new URL(btn.getAttribute('href'), location.href);
            url.searchParams.set('fragment', 'grid');
            const res = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'text/html' } });
            if (!res.ok) { location.assign(btn.getAttribute('href')); return; }
            const doc = new DOMParser().parseFromString('<ul>' + (await res.text()) + '</ul>', 'text/html');
            const fresh = $$(doc, 'li.c-pcard').map((n) => document.importNode(n, true));
            const marker = $(doc, 'li.c-pgrid__next');
            for (const c of fresh) grid.appendChild(c);
            const more = $(document, '[data-bk-more]');
            const shown = grid.children.length;
            if (more) {
                const bar = $(more, 'progress');
                const text = $(more, '.c-more__text');
                if (bar) bar.value = shown;
                if (text) text.textContent = text.textContent.replace(/\d[\d,.]*/, String(shown));
                const nextUrl = marker ? marker.dataset.nextUrl : '';
                if (nextUrl) btn.setAttribute('href', nextUrl); else more.remove();
            }
            paintCards();
            const first = fresh[0] && $(fresh[0], '.c-pcard__link');
            if (first) first.focus();
        } finally { btn.disabled = false; }
    });
}

/* ------------------------------------------------------------------ checkout */
/** a store with several branches keeps its slots per branch: the branch the basket is ordering from rides on every slot call */
const branchQuery = () => (B && B.branch ? '&branch=' + encodeURIComponent(B.branch) : '');
const holdKey = () => 'juwri-hold:' + location.host + ':' + ((B && B.store && B.store.slug) || '');
const S = { data: null, day: 0, picked: null, express: false, hold: null, timer: null, seq: 0 };

function loadHold() {
    const raw = safe(() => sessionStorage.getItem(holdKey()));
    const h = raw ? safe(() => JSON.parse(raw)) : null;
    return h && h.id && h.token && h.slot && h.until > Date.now() ? h : null;
}
function saveHold() {
    safe(() => (S.hold ? sessionStorage.setItem(holdKey(), JSON.stringify(S.hold)) : sessionStorage.removeItem(holdKey())));
}

function checkout() {
    const form = $(document, '[data-bk-checkout]');
    if (!form) return;
    const sheet = form.closest('[data-juwri-sheet]');
    const slotsBox = $(form, '[data-bk-slots]');
    const txt = (name) => form.dataset[name] || '';
    const orderType = () => (J() ? J().cart.snapshot().order_type : null) || (B && B.context && B.context.order_type) || '';
    const delivery = () => orderType() === 'delivery';

    const dayHost = slotsBox && $(slotsBox, '[data-bk-days]');
    const list = slotsBox && $(slotsBox, '[data-bk-slotlist]');
    const express = slotsBox && $(slotsBox, '[data-bk-express]');
    const note = slotsBox && $(slotsBox, '[data-bk-hold]');
    const sel = slotsBox && $(slotsBox, '[data-bk-selection]');
    const slotError = slotsBox && $(slotsBox, '[data-bk-slot-error]');
    const place = $(form, '[data-juwri-place]');
    const warn = $(form, '[data-bk-warning]');

    const setError = (m) => { if (slotError) slotError.textContent = m || ''; };

    // ---- the hold: its countdown
    function tickHold() {
        if (!note) return;
        if (!S.hold) { note.textContent = ''; return; }
        const left = Math.max(0, Math.round((S.hold.until - Date.now()) / 1000));
        if (left === 0) { note.textContent = txt('textExpired'); return; }
        const m = Math.floor(left / 60);
        const s = left % 60;
        note.textContent = txt('textHeld').replace(':time', m + ':' + String(s).padStart(2, '0'));
    }
    function startTimer() {
        clearInterval(S.timer);
        S.timer = S.hold ? setInterval(tickHold, 1000) : null;
        tickHold();
    }

    async function release(keepExtras) {
        const h = S.hold;
        S.hold = null;
        saveHold();
        startTimer();
        if (!keepExtras && J()) J().cart.setExtras({ slot_id: null, hold_id: null }, null);
        if (h && endpoints.hold) await request('DELETE', endpoints.hold + '/' + encodeURIComponent(h.id), undefined, { 'X-Hold-Token': h.token }).catch(() => null);
    }

    function paintSelection() {
        if (!sel) return;
        if (S.express && S.data && S.data.express) { sel.textContent = (S.data.express.display || {}).label || ''; return; }
        const day = S.data && S.data.days[S.day];
        const slot = day && day.slots.find((x) => x.id === S.picked);
        sel.textContent = slot ? [day.display.label, slot.display.label].join(' · ') : '';
    }

    async function pick(slotId) {
        const mine = ++S.seq;
        setError('');
        S.express = false;
        S.picked = slotId;
        paintSelection();
        await release(true);
        const r = await request('POST', endpoints.hold, Object.assign({ slot_id: slotId }, B && B.branch ? { branch: B.branch } : {})).catch(() => null);
        if (mine !== S.seq) return;
        if (r && r.ok && r.json && r.json.id) {
            S.hold = { id: r.json.id, token: r.json.token, slot: slotId, until: Date.now() + (Number(r.json.ttl_seconds) || 600) * 1000 };
            saveHold();
            J().cart.setExtras({ slot_id: slotId, hold_id: r.json.id, express: false }, r.json.token);
            startTimer();
            say(r.json.hold_note);
            return;
        }
        // refused: another shopper took the last seat, or the time passed — say so and show the live capacity
        S.picked = null;
        J().cart.setExtras({ slot_id: null, hold_id: null }, null);
        setError((r && r.json && r.json.message) || '');
        paintSelection();
        await refresh();
    }

    function chooseExpress() {
        ++S.seq;
        setError('');
        S.picked = null;
        S.express = true;
        release(true);
        J().cart.setExtras({ slot_id: null, hold_id: null, express: true }, null);
        paintSelection();
    }

    // ---- the slots
    function renderDays() {
        if (!dayHost || !S.data) return;
        const tpl = $(slotsBox, 'template[data-bk-day-template]');
        for (const old of $$(dayHost, '.c-daytab')) old.remove();
        S.data.days.forEach((d, i) => {
            const el = tpl.content.firstElementChild.cloneNode(true);
            el.id = 'tab-' + d.display.id;
            el.dataset.day = d.display.id;
            el.setAttribute('aria-selected', i === S.day ? 'true' : 'false');
            if (i !== S.day) el.setAttribute('tabindex', '-1');
            if (d.display.state === 'closed') el.setAttribute('aria-disabled', 'true');
            $(el, '[data-bk-day-label]').textContent = d.display.label;
            const sub = $(el, '[data-bk-day-sub]');
            sub.textContent = d.display.sub ? ', ' + d.display.sub : '';
            dayHost.appendChild(el);
        });
    }
    function renderSlots() {
        if (!list || !S.data) return;
        const day = S.data.days[S.day];
        const tpl = $(slotsBox, 'template[data-bk-slot-template]');
        for (const old of $$(list, '.c-slot')) old.remove();
        list.setAttribute('aria-label', day ? day.display.label : '');
        for (const slot of (day ? day.slots : [])) {
            const el = tpl.content.firstElementChild.cloneNode(true);
            const input = $(el, 'input');
            const id = 'slot-' + slot.id;
            input.id = id;
            input.value = slot.id;
            el.setAttribute('for', id);
            $(el, '.c-slot__time bdi').textContent = slot.display.label;
            const sub = $(el, '.c-slot__sub');
            if (slot.display.sub) { sub.hidden = false; $(sub, 'span').textContent = slot.display.sub; }
            if (slot.state === 'full') { el.classList.add('is-full'); input.disabled = true; input.setAttribute('aria-disabled', 'true'); }
            if (slot.state === 'few') el.classList.add('is-few');
            input.checked = slot.id === S.picked && !S.express;
            list.appendChild(el);
        }
        if (express) {
            const offer = S.data.express;
            const label = express.closest('.c-express');
            label.hidden = !offer;
            if (offer) {
                $(label, '.c-express__label').textContent = offer.display.label;
                const fee = $(label, '.c-express__fee');
                if (fee) fee.textContent = offer.display.amount_label || '';
            }
            express.checked = S.express;
        }
        const tz = $(slotsBox, '[data-bk-tz]');
        if (tz && S.data.picker && S.data.picker.timezone_note) tz.textContent = S.data.picker.timezone_note;
        const title = $(slotsBox, '[data-bk-slots-title]');
        if (title && S.data.picker && S.data.picker.title) title.textContent = S.data.picker.title;
        paintSelection();
    }

    async function refresh() {
        if (!slotsBox || !endpoints.slots || !J() || kiosk) return;
        const type = orderType();
        if (!type) return;
        const r = await request('GET', endpoints.slots + '?order_type=' + encodeURIComponent(type) + '&days=3' + branchQuery()).catch(() => null);
        if (!r || !r.ok || !r.json || !Array.isArray(r.json.days)) return;
        S.data = r.json;
        // keep the shopper's day; else the first day with room
        const first = S.data.days.findIndex((d) => d.display.state !== 'closed');
        if (S.picked) {
            const at = S.data.days.findIndex((d) => d.slots.some((x) => x.id === S.picked && x.state !== 'full'));
            if (at >= 0) S.day = at; else if (!S.hold) S.picked = null;
        } else if (S.day >= S.data.days.length || (S.data.days[S.day] && S.data.days[S.day].display.state === 'closed')) S.day = Math.max(0, first);
        renderDays();
        renderSlots();
    }

    if (slotsBox) {
        slotsBox.addEventListener('change', (e) => {
            if (!e.target.matches('input[name="slot"]')) return;
            if (e.target.value === 'express') chooseExpress(); else pick(e.target.value);
        });
        slotsBox.addEventListener('click', (e) => {
            const tab = e.target.closest('.c-daytab');
            if (!tab || tab.getAttribute('aria-disabled') === 'true' || !S.data) return;
            const i = S.data.days.findIndex((d) => d.display.id === tab.dataset.day);
            if (i < 0 || i === S.day) return;
            S.day = i;
            renderDays();
            renderSlots();
            const again = $(dayHost, '.c-daytab[aria-selected="true"]');
            if (again) again.focus();
        });
        slotsBox.addEventListener('keydown', (e) => {
            const tab = e.target.closest('.c-daytab');
            if (!tab) return;
            const tabs = $$(dayHost, '.c-daytab');
            const rtl = getComputedStyle(dayHost).direction === 'rtl';
            const i = tabs.indexOf(tab);
            let to = -1;
            if (e.key === 'ArrowRight') to = i + (rtl ? -1 : 1);
            else if (e.key === 'ArrowLeft') to = i + (rtl ? 1 : -1);
            else if (e.key === 'Home') to = 0;
            else if (e.key === 'End') to = tabs.length - 1;
            if (to < 0 || to >= tabs.length) return;
            e.preventDefault();
            tabs[to].click();
        });
    }

    // ---- the order type decides the address and which times are on offer
    function paintType() {
        const address = $(form, '[data-bk-address]');
        if (address) address.hidden = !delivery();
        if (slotsBox) slotsBox.hidden = !orderType();
    }
    let lastType = orderType();
    document.addEventListener('juwri:cart:changed', (e) => {
        const why = e.detail && e.detail.why;
        if (why === 'order_type' || orderType() !== lastType) {
            lastType = orderType();
            S.picked = null;
            S.express = false;
            S.data = null;
            release(false);
            paintType();
            if (J() && J().sheets.isOpen('checkout')) refresh();
        }
        if (J() && J().cart.snapshot().count === 0 && (S.hold || S.picked || S.express)) {
            S.picked = null;
            S.express = false;
            release(false);
            if (S.data) renderSlots();
        }
    });

    // ---- the out-of-stock choice of the order
    const subs = $$(form, 'input[data-bk-substitution]');
    const sendPreference = () => {
        const on = subs.find((i) => i.checked);
        if (on && J()) J().cart.setExtras({ substitution_preference: on.value });
    };
    form.addEventListener('change', (e) => { if (e.target.matches('input[data-bk-substitution]')) sendPreference(); });

    // ---- payment: the methods the server quote offers, the card hold note, the label of the button
    const payHost = $(form, '[data-bk-pay]');
    const payTpl = $(form, 'template[data-bk-pay-template]');
    const holdNote = $(form, '[data-bk-holdnote]');
    const selectedPay = () => ($(form, 'input[name="pay_via"]:checked') || {}).value || '';
    function paintPayLabel() {
        const online = selectedPay() === 'card';
        const pay = $(form, '[data-bk-place-pay]');
        const order = $(form, '[data-bk-place-order]');
        if (pay) pay.hidden = !online;
        if (order) order.hidden = online;
    }
    function paintPayment(meta) {
        if (!payHost || !payTpl || !meta || !Array.isArray(meta.payment_methods) || !meta.payment_methods.length) { paintPayLabel(); return; }
        const keep = selectedPay();
        let methods = meta.payment_methods;
        if (kiosk) methods = methods.filter((m) => m.id !== 'card'); // a kiosk pays at the counter
        const weighed = !!meta.weighed;
        for (const old of $$(payHost, '.c-payrow')) old.remove();
        let note = '';
        const ids = methods.map((m) => m.id);
        methods.forEach((m, i) => {
            const el = payTpl.content.firstElementChild.cloneNode(true);
            const input = $(el, 'input');
            input.id = 'pay-' + m.id;
            input.value = m.id;
            input.checked = ids.includes(keep) ? m.id === keep : (m.selected === true || (i === 0 && !methods.some((x) => x.selected)));
            el.setAttribute('for', input.id);
            $(el, '.c-choice__label').textContent = m.label || '';
            const sub = $(el, '.c-choice__sub');
            if (m.id === 'card' && weighed) note = m.sub || '';
            else if (m.sub) { sub.hidden = false; sub.textContent = m.sub; }
            payHost.appendChild(el);
        });
        if (!$(payHost, 'input:checked')) { const first = $(payHost, 'input'); if (first) first.checked = true; }
        if (holdNote) {
            const on = note !== '' && selectedPay() === 'card';
            holdNote.hidden = !on;
            const t = $(holdNote, '[data-bk-holdnote-text]');
            if (t) t.textContent = on ? note : '';
            holdNote.dataset.note = note;
        }
        paintPayLabel();
    }
    form.addEventListener('change', (e) => {
        if (!e.target.matches('input[name="pay_via"]')) return;
        paintPayLabel();
        if (holdNote) {
            const note = holdNote.dataset.note || '';
            holdNote.hidden = !(note && selectedPay() === 'card');
            const t = $(holdNote, '[data-bk-holdnote-text]');
            if (t) t.textContent = holdNote.hidden ? '' : note;
        }
    });

    // ---- the place button's gate: what the quote still needs (a time, the minimum) and the fields of the order
    let gate = '';
    function problems(meta) {
        const out = [];
        const m = meta || (J() ? J().cart.snapshot().meta : null) || {};
        if (!kiosk && (m.requires || []).includes('slot') && !S.express && !S.picked) out.push({ code: 'slot', text: delivery() ? txt('textNeedDelivery') : txt('textNeedCollection') });
        for (const w of (m.warnings || [])) if (w.code === 'below_minimum' || w.code === 'slot_full' || w.code === 'out_of_zone') out.push({ code: w.code, text: w.message });
        return out;
    }
    function missingField() {
        for (const input of $$(form, 'input[aria-required="true"]')) {
            if (input.closest('[hidden]')) continue;
            if (input.value.trim() === '') return input;
        }
        return null;
    }
    function paintGate(meta) {
        const p = problems(meta);
        gate = p.length ? p[0].code : '';
        if (warn) warn.textContent = p.map((x) => x.text).join(' ');
        if (place) { if (p.length) place.setAttribute('aria-disabled', 'true'); else place.removeAttribute('aria-disabled'); }
    }
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-juwri-place]');
        if (!btn || !form.contains(btn)) return;
        const missing = missingField();
        const p = problems();
        if (!p.length && !missing) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        const msg = $(form, '[data-kit-place-message]');
        if (p.length) {
            if (msg) msg.textContent = p[0].text;
            if (p[0].code === 'slot' && slotsBox) { const tab = $(slotsBox, '.c-daytab[aria-selected="true"]') || $(slotsBox, 'input[name="slot"]'); if (tab) tab.focus(); }
        } else {
            if (msg) msg.textContent = txt('textNeedFields');
            missing.focus();
        }
    }, true);
    document.addEventListener('juwri:quote:updated', (e) => {
        const meta = J() ? J().cart.snapshot().meta : null;
        paintPayment(meta);
        paintGate(meta);
    });
    document.addEventListener('juwri:order:result', (e) => {
        const r = e.detail || {};
        if (r.status === 'placed') {
            S.hold = null; S.picked = null; S.express = false; saveHold(); startTimer();
        } else if (r.status === 'failed' || r.status === 'unavailable' || r.status === 'conflict') {
            refresh();
        }
        paintGate();
    });
    document.addEventListener('juwri:sheet:open', (e) => {
        if (!(e.detail && e.detail.id === 'checkout')) return;
        paintType();
        paintPayLabel();
        refresh();
        paintGate();
    });
    if (sheet) sheet.addEventListener('input', () => { const msg = $(form, '[data-kit-place-message]'); if (msg && msg.textContent) msg.textContent = ''; });

    whenReady().then(() => {
        // a hold taken earlier in this tab (the page was reloaded or the shopper came back) keeps its time
        const h = loadHold();
        if (h && J().cart.snapshot().count > 0) {
            S.hold = h;
            S.picked = h.slot;
            J().cart.setExtras({ slot_id: h.slot, hold_id: h.id, express: false }, h.token);
            startTimer();
        } else if (h) {
            S.hold = h;
            release(false);
        }
        sendPreference();
        paintType();
        paintPayLabel();
    });
    window.addEventListener('pagehide', () => { /* the hold ends by itself in 10 minutes; a reload keeps it (sessionStorage) */ });
}

/* ------------------------------------------------------------------ tracking: keep or refuse a replacement */
function review() {
    const box = $(document, '[data-bk-review]');
    if (!box) return;
    const url = box.dataset.url || endpoints.replacement;
    const msg = $(box, '[data-bk-review-message]');
    box.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-bk-answer]');
        if (!btn || !url) return;
        e.preventDefault();
        const row = btn.closest('.c-swap');
        for (const b of $$(row, '[data-bk-answer]')) b.disabled = true;
        const r = await request('POST', url, { line: btn.dataset.line, answer: btn.dataset.bkAnswer }).catch(() => null);
        if (r && r.ok && r.json && r.json.success) {
            say(btn.textContent.trim());
            location.reload(); // the receipt, the total and the list of changes are the server's: read them again
            return;
        }
        for (const b of $$(row, '[data-bk-answer]')) b.disabled = false;
        if (msg) msg.textContent = (r && r.json && r.json.message) || '';
    });
}

cards();
sorting();
paging();
whenReady().then(checkout);
review();
