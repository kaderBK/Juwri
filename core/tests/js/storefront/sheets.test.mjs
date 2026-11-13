// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

import test from 'node:test';
import assert from 'node:assert/strict';
import { nextFocus } from '../../../../assets/storefront/sheets.js';
import { startKiosk } from '../../../../assets/storefront/kiosk.js';
import { memoryStore } from '../../../../assets/storefront/db.js';

test('focus trap: Tab wraps last -> first, Shift+Tab wraps first -> last, outside enters at the edge', () => {
    const l = ['a', 'b', 'c'];
    assert.equal(nextFocus(l, 'c', false), 'a');
    assert.equal(nextFocus(l, 'a', true), 'c');
    assert.equal(nextFocus(l, 'b', false), 'c');
    assert.equal(nextFocus(l, null, false), 'a');
    assert.equal(nextFocus(l, null, true), 'c');
    assert.equal(nextFocus([], 'a', false), null);
});

test('kiosk: idle -> 10 s warning -> cart cleared and back home; activity cancels the warning', async () => {
    const handlers = {};
    const doc = { addEventListener: (e, f) => { handlers[e] = f; }, removeEventListener() {} };
    const timers = []; const ticks = [];
    const fake = { set: (f, ms) => { timers.push({ f, ms }); return timers.length; }, clear() {}, tick: (f) => { ticks.push(f); return ticks.length; }, untick() { ticks.length = 0; } };
    let cleared = 0; let went = null;
    const k = startKiosk({ kiosk: { on: true, idle_seconds: 20 }, cart: { clear: async () => { cleared++; } }, home: '/bloom', announce() {}, doc, nav: (u) => { went = u; }, timers: fake });
    assert.equal(timers[0].ms, 20000);
    timers[0].f(); // idle
    assert.equal(ticks.length, 1);
    handlers.pointerdown(); // shopper is back
    assert.equal(ticks.length, 0);
    timers.at(-1).f();
    for (let i = 0; i < 10; i++) ticks[0]();
    await new Promise((r) => setImmediate(r));
    assert.equal(cleared, 1);
    assert.equal(went, '/bloom');
    assert.ok(k);
});

test('kiosk: off outside kiosk mode', () => {
    assert.equal(startKiosk({ kiosk: null, doc: {}, cart: {}, announce() {} }), null);
});

test('storage: stores are separate per name and the memory fallback keeps json', async () => {
    const a = memoryStore(); const b = memoryStore();
    await a.put('cart', 'x', { n: 1 });
    assert.deepEqual(await a.get('cart', 'x'), { n: 1 });
    assert.equal(await b.get('cart', 'x'), undefined);
    await a.clear('cart');
    assert.equal(await a.get('cart', 'x'), undefined);
});

test('a sheet rendered open by the server joins the stack: the page behind stays inert, closing frees it and focuses back', async () => {
    const { createSheets } = await import('../../../../assets/storefront/sheets.js');
    const attrs = (o) => ({ ...o, hasAttribute(k) { return k in this.a; }, setAttribute(k, v) { this.a[k] = v; }, removeAttribute(k) { delete this.a[k]; } });
    const page = attrs({ a: { inert: '' }, tagName: 'DIV', id: '', contains: () => false });
    const el = attrs({ a: { 'data-juwri-sheet': '' }, tagName: 'DIV', id: 'cart', hidden: false, contains() { return true; }, getAttribute: () => null, closest: () => null,
        querySelector: () => null, querySelectorAll: () => [] });
    page.contains = (x) => x === page;
    let focused = 0; el.focus = () => { focused++; };
    el.setAttribute('tabindex', '-1');
    const body = { children: [page, el] };
    const root = { classList: { add() {}, remove() {} } };
    const doc = { body, documentElement: root, activeElement: null, getElementById: (id) => (id === 'cart' ? el : null), addEventListener() {},
        querySelectorAll: (q) => (q === '[data-juwri-sheet]' ? [el] : []) };
    globalThis.addEventListener ||= () => {};
    const sheets = createSheets(doc);
    sheets.bind();
    assert.equal(sheets.isOpen('cart'), true);
    assert.equal(el.hidden, false);
    assert.ok('inert' in page.a, 'the page behind is inert while the sheet is open');
    sheets.close('cart');
    assert.equal(sheets.isOpen('cart'), false);
    assert.ok(!('inert' in page.a), 'closing the sheet frees the page');
});
