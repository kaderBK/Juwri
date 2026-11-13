// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import test from 'node:test';
import assert from 'node:assert/strict';
import { startKiosk } from '../../../../assets/storefront/kiosk.js';

/* A very small DOM: elements with attributes, children, hidden, textContent, classList, listeners; selectors `tag`, `[attr]`, `[attr="v"]`,
   `tag[attr="v"]` (what kiosk.js asks for). */
function matches(el, sel) {
    const m = /^([a-z]*)(?:\[([a-z0-9-]+)(?:="([^"]*)")?\])?$/.exec(sel.trim());
    if (!m) return false;
    if (m[1] && el.tag !== m[1]) return false;
    if (m[2]) {
        if (!(m[2] in el.attrs)) return false;
        if (m[3] !== undefined && el.attrs[m[2]] !== m[3]) return false;
    }
    return true;
}
function el(tag, attrs = {}, children = []) {
    const e = {
        tag, attrs: { ...attrs }, children: [], parent: null, hidden: false, textContent: '', listeners: {},
        classes: new Set(),
        classList: { toggle: (c, on) => { if (on) e.classes.add(c); else e.classes.delete(c); } },
        hasAttribute: (n) => n in e.attrs,
        getAttribute: (n) => (n in e.attrs ? e.attrs[n] : null),
        setAttribute: (n, v) => { e.attrs[n] = String(v); },
        removeAttribute: (n) => { delete e.attrs[n]; },
        addEventListener: (t, f) => { (e.listeners[t] ||= []).push(f); },
        removeEventListener: () => {},
        appendChild: (c) => { c.parent = e; e.children.push(c); return c; },
        focus: () => { e.doc && (e.doc.activeElement = e); },
        closest: (sel) => { for (let n = e; n; n = n.parent) if (n.tag !== '#doc' && matches(n, sel)) return n; return null; },
        querySelector: (sel) => e.querySelectorAll(sel)[0] || null,
        querySelectorAll: (sel) => {
            const out = [];
            const walk = (n) => { for (const c of n.children) { if (matches(c, sel)) out.push(c); walk(c); } };
            walk(e);
            return out;
        },
    };
    for (const c of children) e.appendChild(c);
    return e;
}
function page(opts = {}) {
    const q = {};
    const doc = el('#doc');
    doc.documentElement = el('html');
    doc.createElement = (t) => { const e = el(t); e.classes = new Set(); return e; };
    const add = (name, node) => { q[name] = node; doc.appendChild(node); return node; };
    add('meta', el('meta', { name: 'csrf-token', content: 'tok' }));
    add('page', el('div', { 'data-kit-page': '' }));
    if (opts.idle !== false) {
        add('idle', el('div', { 'data-kiosk-overlay': 'idle' }, [el('h2', { tabindex: '-1' }), el('span', { 'data-kiosk-left': '' }), el('button', { 'data-kiosk-continue': '' }), el('button', { 'data-kiosk-startover': '' })]));
        q.idle.hidden = true;
    }
    if (opts.pin !== false) {
        const dots = el('div', { 'data-kiosk-pin-dots': '' });
        for (let i = 0; i < 4; i++) dots.appendChild(el('span'));
        const submit = el('button', { 'data-kiosk-pin-submit': '' }, [el('span')]);
        add('pin', el('div', { 'data-kiosk-overlay': 'pin' }, [
            el('h2', { 'data-kiosk-pin-title': '', tabindex: '-1' }), dots, el('p', { 'data-kiosk-pin-error': '' }),
            ...['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'].map((k) => el('button', { 'data-key': k })),
            el('button', { 'data-kiosk-pin-delete': '' }), el('button', { 'data-kiosk-pin-cancel': '' }), submit,
        ]));
        q.pin.hidden = true;
        q.dots = dots; q.submit = submit; q.err = q.pin.querySelector('[data-kiosk-pin-error]'); q.title = q.pin.querySelector('[data-kiosk-pin-title]');
        q.logo = add('logo', el('span', { 'data-kiosk-exit': '' }));
    }
    if (opts.start) {
        q.go = el('a', { 'data-kiosk-go': '', href: '/bloom?kiosk=1' });
        q.banner = el('div', { 'data-kiosk-offline': '' }, [el('span')]);
        const s = el('div', { 'data-kiosk-start': '', ...(opts.setup ? { 'data-kiosk-needs-setup': '' } : {}) }, [q.go, q.banner]);
        add('start', s);
    }
    if (opts.done) {
        q.left = el('bdi', { 'data-kiosk-left': '' });
        q.receipt = el('p', { 'data-kiosk-receipt': '' });
        q.failed = el('p', { 'data-kiosk-print-failed': '' }); q.failed.hidden = true;
        q.now = el('a', { 'data-kiosk-startnow': '' });
        add('done', el('div', { 'data-kiosk-done': '', 'data-seconds': '10', 'data-start': '/bloom/kiosk' }, [q.left, q.receipt, q.failed, q.now]));
    }
    q.a11y = add('a11y', el('button', { 'data-kiosk-a11y': '', 'aria-pressed': 'false' }));
    for (const n of Object.values(q)) n.doc = doc;
    doc.querySelector = (sel) => {
        const r = doc.querySelectorAll(sel);
        return r[0] || null;
    };
    doc.dispatch = (type, target = doc, extra = {}) => {
        const e = { type, target, key: '', preventDefault() { e.defaultPrevented = true; }, defaultPrevented: false, ...extra };
        // bubble: the target, its parents, then the document (an element outside the tree still reaches the document)
        const chain = [];
        for (let n = target; n && n !== doc; n = n.parent) chain.push(n);
        chain.push(doc);
        for (const n of chain) for (const f of n.listeners[type] || []) f(e);
        return e;
    };
    return { doc, q };
}

function harness(over = {}, popts = {}) {
    const { doc, q } = page(popts);
    const timers = []; const ticks = []; let nowTs = 0;
    const fake = {
        set: (f, ms) => { const t = { f, ms, id: timers.length + 1, live: true }; timers.push(t); return t; },
        clear: (t) => { if (t) t.live = false; },
        tick: (f) => { const t = { f, live: true }; ticks.push(t); return t; },
        untick: (t) => { if (t) t.live = false; },
    };
    const calls = []; const went = []; const announced = []; const stored = {};
    const store = { local: { getItem: (k) => stored[k] ?? null, setItem: (k, v) => { stored[k] = v; }, removeItem: (k) => { delete stored[k]; } }, session: { clear() {} } };
    Object.defineProperty(store.local, 'length', { get: () => Object.keys(stored).length });
    const api = {
        responses: [],
        fetchFn: async (url, init) => {
            calls.push({ url, init });
            const r = api.responses.shift() || { status: 200, json: { ok: true } };
            return { status: r.status, json: async () => r.json };
        },
    };
    let cleared = 0;
    const k = startKiosk({
        kiosk: { on: true, idle_seconds: 45 },
        cart: { clear: async () => { cleared++; } },
        home: '/bloom',
        endpoints: { kiosk_start: '/bloom/kiosk', kiosk_reset: '/bloom/kiosk/reset', kiosk_exit: '/bloom/kiosk/exit', kiosk_setup: '/bloom/kiosk/setup' },
        slug: 'bloom',
        announce: (m) => announced.push(m),
        text: (key, d) => ({ incorrect_pin: 'Incorrect PIN', too_many_attempts_wait_a_moment: 'Too many attempts. Wait a moment.' }[key] || d),
        doc, nav: (u) => went.push(u), fetchFn: api.fetchFn, storage: () => ({ local: store.local, session: store.session }), timers: fake,
        win: { navigator: { onLine: true } },
        ...over,
    });
    const flush = () => new Promise((r) => setImmediate(r));
    return { k, doc, q, timers, ticks, calls, went, announced, stored, api, flush, get cleared() { return cleared; }, nowTs };
}
const live = (list) => list.filter((t) => t.live);

test('kiosk idle: the dialog opens with a visible 15 s countdown, announced once; Continue keeps the order', async () => {
    const h = harness();
    assert.equal(live(h.timers).length, 1);
    assert.equal(live(h.timers)[0].ms, 45000);
    live(h.timers)[0].f(); // idle_seconds without a touch
    assert.equal(h.q.idle.hidden, false);
    assert.equal(h.q.page.attrs.inert, '', 'the page behind is inert while the dialog is open');
    assert.equal(h.q.idle.querySelector('[data-kiosk-left]').textContent, '15');
    assert.equal(h.announced.length, 1, 'announced politely once, not every second');
    for (let i = 0; i < 4; i++) live(h.ticks)[0].f();
    assert.equal(h.q.idle.querySelector('[data-kiosk-left]').textContent, '11');
    assert.equal(h.announced.length, 1);
    h.doc.dispatch('pointerdown'); // a touch outside the dialog buttons does not answer it
    assert.equal(h.q.idle.hidden, false);

    h.doc.dispatch('click', h.q.idle.querySelector('[data-kiosk-continue]'));
    assert.equal(h.q.idle.hidden, true);
    assert.equal('inert' in h.q.page.attrs, false);
    assert.equal(live(h.ticks).length, 0);
    assert.equal(h.cleared, 0);
    assert.deepEqual(h.went, []);
    assert.equal(live(h.timers).length, 1, 're-armed');
});

test('kiosk idle: the end of the countdown clears the cart, tells the server and opens the start screen', async () => {
    const h = harness();
    live(h.timers)[0].f();
    for (let i = 0; i < 15; i++) live(h.ticks)[0] && live(h.ticks)[0].f();
    await h.flush(); await h.flush();
    assert.equal(h.cleared, 1);
    assert.equal(h.calls[0].url, '/bloom/kiosk/reset');
    assert.equal(h.calls[0].init.headers['X-CSRF-TOKEN'], 'tok');
    assert.deepEqual(h.went, ['/bloom/kiosk']);
});

test('kiosk idle: Start over resets at once', async () => {
    const h = harness();
    live(h.timers)[0].f();
    h.doc.dispatch('click', h.q.idle.querySelector('[data-kiosk-startover]'));
    await h.flush(); await h.flush();
    assert.equal(h.cleared, 1);
    assert.deepEqual(h.went, ['/bloom/kiosk']);
});

test('kiosk exit: a 3 s press on the logo opens the PIN pad; a shorter one does not; the PIN is checked on the server', async () => {
    const h = harness();
    h.doc.dispatch('pointerdown', h.q.logo);
    const press = live(h.timers).find((t) => t.ms === 3000);
    assert.ok(press, 'a 3 s timer');
    h.doc.dispatch('pointerup'); // let go early
    assert.equal(press.live, false);
    assert.equal(h.q.pin.hidden, true);

    h.doc.dispatch('pointerdown', h.q.logo);
    live(h.timers).find((t) => t.ms === 3000).f();
    assert.equal(h.q.pin.hidden, false);
    assert.equal(h.q.title.textContent, 'Enter the staff PIN');

    // a wrong PIN: refused, digits cleared, nothing leaves
    h.api.responses.push({ status: 200, json: { ok: false } });
    for (const d of ['0', '0', '0', '0']) h.doc.dispatch('click', h.q.pin.querySelector(`[data-key="${d}"]`));
    h.doc.dispatch('click', h.q.submit);
    await h.flush(); await h.flush();
    assert.equal(h.q.err.textContent, 'Incorrect PIN');
    assert.deepEqual(h.went, []);
    assert.equal(h.calls.at(-1).url, '/bloom/kiosk/exit');
    assert.deepEqual(JSON.parse(h.calls.at(-1).init.body), { pin: '0000' });

    // throttled
    h.api.responses.push({ status: 429, json: { ok: false, throttled: true } });
    for (const d of ['4', '3', '2', '1']) h.doc.dispatch('click', h.q.pin.querySelector(`[data-key="${d}"]`));
    h.doc.dispatch('click', h.q.submit);
    await h.flush(); await h.flush();
    assert.equal(h.q.err.textContent, 'Too many attempts. Wait a moment.');
    assert.deepEqual(h.went, []);

    // the right PIN leaves the kiosk for the store page
    h.api.responses.push({ status: 200, json: { ok: true } });
    for (const d of ['4', '3', '2', '1']) h.doc.dispatch('click', h.q.pin.querySelector(`[data-key="${d}"]`));
    h.doc.dispatch('click', h.q.submit);
    await h.flush(); await h.flush();
    assert.deepEqual(h.went, ['/bloom']);
});

test('kiosk exit: the pad cancels, grows its dots and ignores a ninth digit', async () => {
    const h = harness();
    h.doc.dispatch('pointerdown', h.q.logo);
    live(h.timers).find((t) => t.ms === 3000).f();
    for (let i = 0; i < 9; i++) h.doc.dispatch('click', h.q.pin.querySelector('[data-key="5"]'));
    assert.equal(h.q.dots.children.length, 8);
    h.doc.dispatch('click', h.q.pin.querySelector('[data-kiosk-pin-delete]'));
    assert.equal([...h.q.dots.children].filter((d) => d.classes.has('c-pin__dot--on')).length, 7);
    h.doc.dispatch('click', h.q.pin.querySelector('[data-kiosk-pin-cancel]'));
    assert.equal(h.q.pin.hidden, true);
    assert.equal('inert' in h.q.page.attrs, false);
});

test('kiosk lock: a link that leaves the store is blocked, a store link and an anchor are not; no other hidden exit', () => {
    const h = harness();
    const link = (href, extra = {}) => el('a', { href, ...extra });
    assert.equal(h.doc.dispatch('click', link('https://example.com/')).defaultPrevented, true);
    assert.equal(h.doc.dispatch('click', link('//example.com/')).defaultPrevented, true);
    assert.equal(h.doc.dispatch('click', link('tel:+966500000000')).defaultPrevented, true);
    assert.equal(h.doc.dispatch('click', link('/bloom/page/terms', { target: '_blank' })).defaultPrevented, true);
    assert.equal(h.doc.dispatch('click', link('/bloom?lang=ar')).defaultPrevented, false);
    assert.equal(h.doc.dispatch('click', link('#cat-mezze')).defaultPrevented, false);
    assert.equal(h.doc.dispatch('contextmenu', h.doc).defaultPrevented, true);
});

test('kiosk lock: an in-page link moves focus itself (no fragment navigation, so the skip link keeps working)', () => {
    const h = harness();
    const main = el('main', { id: 'main' });
    main.doc = h.doc;
    let scrolled = 0; main.scrollIntoView = () => { scrolled++; };
    h.doc.getElementById = (id) => (id === 'main' ? main : null);
    const e = h.doc.dispatch('click', el('a', { href: '#main' }));
    assert.equal(e.defaultPrevented, true);
    assert.equal(main.getAttribute('tabindex'), '-1');
    assert.equal(h.doc.activeElement, main);
    assert.equal(scrolled, 1);
    assert.equal(h.doc.dispatch('click', el('a', { href: '#nowhere' })).defaultPrevented, false);
});

test('kiosk accessibility button toggles the larger-text, lower-half mode and its pressed state', () => {
    const h = harness();
    h.doc.dispatch('click', h.q.a11y);
    assert.equal(h.doc.documentElement.getAttribute('data-kiosk-a11y'), 'on');
    assert.equal(h.q.a11y.getAttribute('aria-pressed'), 'true');
    h.doc.dispatch('click', h.q.a11y);
    assert.equal(h.doc.documentElement.getAttribute('data-kiosk-a11y'), null);
    assert.equal(h.q.a11y.getAttribute('aria-pressed'), 'false');
});

test('kiosk done screen: a visible countdown, then the start screen; the idle timer never runs there; the print-failed event shows the fallback line', () => {
    const h = harness({}, { done: true, idle: false });
    assert.equal(live(h.timers).length, 0, 'no idle timer on the done screen');
    assert.equal(h.q.left.textContent, '10');
    for (let i = 0; i < 4; i++) live(h.ticks)[0].f();
    assert.equal(h.q.left.textContent, '6');
    assert.deepEqual(h.went, []);
    h.doc.dispatch('juwri:kiosk:print-failed');
    assert.equal(h.q.receipt.hidden, true);
    assert.equal(h.q.failed.hidden, false);
    for (let i = 0; i < 6; i++) live(h.ticks)[0] && live(h.ticks)[0].f();
    assert.deepEqual(h.went, ['/bloom/kiosk']);
});

test('kiosk start screen: a device without its setup PIN asks for it first; the right PIN unlocks Tap to start and is remembered', async () => {
    const h = harness({}, { start: true, setup: true, idle: false });
    assert.equal(h.q.pin.hidden, false);
    assert.equal(h.q.title.textContent, 'Device setup PIN');
    assert.equal(h.q.go.getAttribute('aria-disabled'), 'true');
    assert.equal(h.doc.dispatch('click', h.q.go).defaultPrevented, true);
    assert.equal(live(h.timers).length, 0, 'no idle timer on the start screen');

    h.api.responses.push({ status: 200, json: { ok: false } });
    for (const d of ['1', '1', '1', '1']) h.doc.dispatch('click', h.q.pin.querySelector(`[data-key="${d}"]`));
    h.doc.dispatch('click', h.q.submit);
    await h.flush(); await h.flush();
    assert.equal(h.q.err.textContent, 'Incorrect PIN');
    assert.equal(h.q.go.getAttribute('aria-disabled'), 'true');

    h.api.responses.push({ status: 200, json: { ok: true } });
    for (const d of ['2', '4', '6', '8']) h.doc.dispatch('click', h.q.pin.querySelector(`[data-key="${d}"]`));
    h.doc.dispatch('click', h.q.submit);
    await h.flush(); await h.flush();
    assert.equal(h.calls.at(-1).url, '/bloom/kiosk/setup');
    assert.equal(h.q.go.getAttribute('aria-disabled'), null);
    assert.equal(h.stored.kiosk_setup_bloom, '1');
    assert.equal(h.q.pin.hidden, true);
    assert.equal(h.doc.dispatch('click', h.q.go).defaultPrevented, false);
});

test('kiosk start screen: the offline banner follows the connection; with no queue Tap to start waits for it', () => {
    const g = harness({ win: { navigator: { onLine: false }, addEventListener() {} }, queueEnabled: false }, { start: true, idle: false });
    assert.equal(g.q.banner.hidden, false, 'offline: the banner shows');
    assert.equal(g.q.banner.querySelector('span').textContent, 'Ordering is paused until the connection returns');
    assert.equal(g.q.go.getAttribute('aria-disabled'), 'true', 'no queue: ordering waits for the connection');
    const online = harness({ win: { navigator: { onLine: false }, addEventListener() {} }, queueEnabled: true }, { start: true, idle: false });
    assert.equal(online.q.banner.hidden, false);
    assert.equal(online.q.banner.querySelector('span').textContent, 'Connection lost — pay at the counter to order now');
    assert.equal(online.q.go.getAttribute('aria-disabled'), null, 'pay at the counter is allowed through the offline queue');
});

test('kiosk: off outside kiosk mode, and the old flow (no dialog markup) still warns and resets', async () => {
    assert.equal(startKiosk({ kiosk: null, doc: {}, cart: {}, announce() {} }), null);
    const h = harness({}, { idle: false, pin: false });
    live(h.timers)[0].f();
    assert.equal(live(h.ticks).length, 1);
    for (let i = 0; i < 10; i++) live(h.ticks)[0] && live(h.ticks)[0].f();
    await h.flush(); await h.flush();
    assert.equal(h.cleared, 1);
    assert.deepEqual(h.went, ['/bloom/kiosk']);
});
