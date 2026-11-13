// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P3 accessibility: while the register is locked, Tab/Shift+Tab must never land on anything outside the
// .lock-overlay. The DOM core (assets/pos/pos-core.js: lockTrap) is exercised against a minimal plain-object
// DOM — no browser, no Alpine.
import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { lockTrap } = require('../../../../assets/pos/pos-core.js');

function descendant(root, node) {
    for (const c of (root.children || [])) {
        if (c === node || descendant(c, node)) return true;
    }
    return false;
}

// body > [ topbar, main ]; main > [ preInert(already inert), overlay, trailing ]; overlay > [ pin(INPUT), unlock(BUTTON) ]
function build() {
    const doc = {
        body: null,
        activeElement: null,
        _listeners: {},
        contains(n) { return n === this.body || descendant(this.body, n); },
        addEventListener(t, fn) { (this._listeners[t] = this._listeners[t] || []).push(fn); },
        removeEventListener(t, fn) { this._listeners[t] = (this._listeners[t] || []).filter((f) => f !== fn); },
    };
    const el = (tag, children = [], attrs = {}) => {
        const node = {
            tagName: tag.toUpperCase(),
            children,
            parentElement: null,
            _attrs: Object.assign({}, attrs),
            _visible: true, // x-show starts hidden: focus() is a no-op and rects are empty
            focused: false,
            setAttribute(k, v) { this._attrs[k] = v; },
            getAttribute(k) { return Object.prototype.hasOwnProperty.call(this._attrs, k) ? this._attrs[k] : null; },
            removeAttribute(k) { delete this._attrs[k]; },
            hasAttribute(k) { return Object.prototype.hasOwnProperty.call(this._attrs, k); },
            contains(n) { return n === this || descendant(this, n); },
            getClientRects() { return this._visible ? [{}] : []; },
            focus() { if (!this._visible) return; this.focused = true; doc.activeElement = this; },
        };
        children.forEach((c) => { c.parentElement = node; });
        return node;
    };
    const pin = el('input');
    const unlock = el('button');
    const overlay = el('div', [pin, unlock], { class: 'lock-overlay' });
    const preInert = el('section', [], { inert: '' });
    const trailing = el('footer');
    const main = el('main', [preInert, overlay, trailing]);
    const topbar = el('header');
    const body = el('body', [topbar, main]);
    doc.body = body;
    return { doc, overlay, pin, unlock, preInert, trailing, main, topbar, body };
}

test('lock(): marks the overlay\'s siblings and each ancestor\'s siblings up to <body> inert, sparing pre-existing inert', () => {
    const { doc, overlay, preInert, trailing, topbar, main, body } = build();
    const trap = lockTrap(doc, overlay);
    trap.lock();
    assert.ok(topbar.hasAttribute('inert'), 'the body-level sibling is inert');
    assert.ok(trailing.hasAttribute('inert'), 'the overlay\'s trailing sibling is inert');
    assert.ok(preInert.hasAttribute('inert'), 'the already-inert sibling stays inert');
    assert.ok(!main.hasAttribute('inert'), 'the overlay\'s own ancestor is not inert');
    assert.ok(!overlay.hasAttribute('inert'), 'the overlay itself is never inert');
    assert.ok(!body.hasAttribute('inert'), 'body is never inert');
});

test('unlock(): removes only what it set, leaving pre-existing inert alone', () => {
    const { doc, overlay, preInert, trailing, topbar } = build();
    const trap = lockTrap(doc, overlay);
    trap.lock();
    trap.unlock();
    assert.ok(!topbar.hasAttribute('inert'), 'the mark lock() added is gone');
    assert.ok(!trailing.hasAttribute('inert'), 'the mark lock() added is gone');
    assert.ok(preInert.hasAttribute('inert'), 'an inert attribute it never set is untouched');
});

test('focus is pulled into the overlay on lock and on every focusin while locked', () => {
    const { doc, overlay, pin, topbar } = build();
    doc.activeElement = topbar; // focus starts outside the overlay
    const trap = lockTrap(doc, overlay);
    trap.lock();
    assert.equal(doc.activeElement, pin, 'focus moved to the first focusable inside the overlay');
    // the cashier Tabs out: the focusin listener must pull focus back inside
    doc.activeElement = topbar;
    for (const fn of (doc._listeners.focusin || [])) fn();
    assert.equal(doc.activeElement, pin, 'focus is pulled back inside while locked');
    trap.unlock();
    assert.equal((doc._listeners.focusin || []).length, 0, 'the focusin listener is removed on unlock');
});

test('unlock(): restores the previously focused element when it is still in the document', () => {
    const { doc, overlay, topbar } = build();
    doc.activeElement = topbar;
    const trap = lockTrap(doc, overlay);
    trap.lock();
    assert.notEqual(doc.activeElement, topbar, 'focus is inside while locked');
    trap.unlock();
    assert.equal(doc.activeElement, topbar, 'focus returns to where it was before the lock');
});

// A deterministic stand-in for setTimeout/clearTimeout: the test drains the queue.
function deferQueue() {
    const items = [];
    const defer = (fn) => { const h = { fn, cancelled: false }; items.push(h); return h; };
    const cancel = (h) => { if (h) h.cancelled = true; };
    const runAll = () => {
        while (items.length) {
            const h = items.shift();
            if (!h.cancelled) h.fn();
        }
    };
    return { defer, cancel, runAll, items, get size() { return items.length; } };
}

test('lock(): focus prefers input.pin over an earlier focusable', () => {
    const { doc, overlay, pin, unlock, topbar } = build();
    pin._attrs.class = 'pin';
    overlay.children = [unlock, pin]; // a focusable now comes before the pin
    unlock.parentElement = overlay;
    pin.parentElement = overlay;
    doc.activeElement = topbar;
    const trap = lockTrap(doc, overlay);
    trap.lock();
    assert.equal(doc.activeElement, pin, 'the class="pin" input wins over the first focusable');
    trap.unlock();
});

test('lock(): retries until input.pin becomes visible (x-show delay)', () => {
    const { doc, overlay, pin, unlock, body } = build();
    pin._attrs.class = 'pin';
    pin._visible = false;
    unlock._visible = false;
    overlay._visible = false; // x-show: not displayed yet, so focus() is a no-op
    const timers = deferQueue();
    doc.activeElement = body;
    const trap = lockTrap(doc, overlay, { defer: timers.defer, cancel: timers.cancel });
    trap.lock();
    assert.equal(doc.activeElement, body, 'nothing focusable yet → focus stays put');
    assert.ok(timers.size > 0, 'a bounded retry was scheduled');
    pin._visible = true;
    unlock._visible = true;
    overlay._visible = true;
    timers.runAll();
    assert.equal(doc.activeElement, pin, 'focus lands on the pin once it is visible');
    trap.unlock();
});

test('Tab wraps inside the overlay: last→first, first→last', () => {
    const { doc, overlay, pin, unlock } = build();
    const trap = lockTrap(doc, overlay);
    trap.lock();
    const keydown = (doc._listeners.keydown || [])[0];
    assert.ok(keydown, 'a keydown listener is registered while locked');
    let prevented = 0;
    doc.activeElement = unlock;
    keydown({ key: 'Tab', shiftKey: false, preventDefault() { prevented++; } });
    assert.equal(doc.activeElement, pin, 'Tab on the last focusable wraps to the first');
    assert.equal(prevented, 1, 'the wrap is prevented');
    doc.activeElement = pin;
    keydown({ key: 'Tab', shiftKey: true, preventDefault() { prevented++; } });
    assert.equal(doc.activeElement, unlock, 'Shift+Tab on the first wraps to the last');
    assert.equal(prevented, 2, 'the wrap is prevented');
    trap.unlock();
});

test('Tab from outside the overlay goes to the first focusable', () => {
    const { doc, overlay, pin, body } = build();
    const trap = lockTrap(doc, overlay);
    trap.lock();
    const keydown = (doc._listeners.keydown || [])[0];
    let prevented = 0;
    doc.activeElement = body;
    keydown({ key: 'Tab', shiftKey: false, preventDefault() { prevented++; } });
    assert.equal(doc.activeElement, pin, 'focus enters the overlay at the first focusable');
    assert.equal(prevented, 1, 'the leak is prevented');
    trap.unlock();
});

test('unlock(): removes the Tab/focus handlers and cancels pending retries', () => {
    const { doc, overlay, pin, unlock, body } = build();
    pin._attrs.class = 'pin';
    pin._visible = false;
    unlock._visible = false;
    overlay._visible = false;
    const timers = deferQueue();
    doc.activeElement = body;
    const trap = lockTrap(doc, overlay, { defer: timers.defer, cancel: timers.cancel });
    trap.lock();
    assert.equal((doc._listeners.keydown || []).length, 1, 'the Tab handler is registered');
    assert.equal(timers.size, 1, 'one retry is pending');
    trap.unlock();
    assert.equal((doc._listeners.keydown || []).length, 0, 'the Tab handler is removed');
    assert.equal((doc._listeners.focusin || []).length, 0, 'the focusin handler is removed');
    assert.ok(timers.items.every((h) => h.cancelled), 'the pending retry was cancelled');
    pin._visible = true;
    unlock._visible = true;
    timers.runAll();
    assert.notEqual(doc.activeElement, pin, 'a cancelled retry never steals focus after unlock');
});
