// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* juwri storefront core — sheets and dialogs (THEME-CONTRACT §5.1, PORTING §3).
   Markup contract (themes only write markup, core owns behaviour):
     <button data-juwri-open="cart" aria-controls="cart" aria-expanded="false">
     <div id="cart" data-juwri-sheet role="dialog" aria-modal="true" aria-labelledby="…" hidden>
       … <button data-juwri-close>
   Opening: the sheet loses `hidden`, focus moves inside, Tab wraps (focus trap), the rest of the page becomes
   `inert`, Esc / backdrop (`data-juwri-overlay`) / `data-juwri-close` close it, focus returns to what opened it.
   Sheets stack; Esc closes the top one. Events: juwri:sheet:open / juwri:sheet:close {id}. */
import { emit } from './util.js';

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"]),summary';

/** Pure: the element focus goes to next when Tab / Shift+Tab is pressed on `current` (wraps). */
export function nextFocus(list, current, backwards) {
    if (!list.length) return null;
    const i = list.indexOf(current);
    if (i === -1) return backwards ? list[list.length - 1] : list[0];
    if (backwards) return i === 0 ? list[list.length - 1] : list[i - 1];
    return i === list.length - 1 ? list[0] : list[i + 1];
}

export function createSheets(doc = globalThis.document, hooks = {}) {
    const stack = []; // {id, el, opener, inerted}
    let bound = false;

    const visible = (el) => !el.hidden && el.getAttribute('aria-hidden') !== 'true' && !el.closest('[hidden]');
    const focusables = (root) => Array.from(root.querySelectorAll(FOCUSABLE)).filter(visible);
    const find = (id) => doc.getElementById(id);

    function setInert(top) {
        const inerted = [];
        for (const child of Array.from(doc.body.children)) {
            if (child === top || child.contains(top) || child.contains(find('juwri-live')) || child.id === 'juwri-live') continue;
            if (child.tagName === 'SCRIPT' || child.hasAttribute('inert')) continue;
            child.setAttribute('inert', '');
            inerted.push(child);
        }
        return inerted;
    }

    function open(id, opener) {
        const el = find(id);
        if (!el || stack.some((s) => s.id === id)) return false;
        const previous = stack[stack.length - 1];
        if (previous) for (const n of previous.inerted) n.removeAttribute('inert');
        const entry = { id, el, opener: opener || doc.activeElement, inerted: [] };
        stack.push(entry);
        el.hidden = false;
        for (const t of doc.querySelectorAll('[aria-controls="' + id + '"]')) t.setAttribute('aria-expanded', 'true');
        entry.inerted = setInert(el);
        doc.documentElement.classList.add('juwri-scroll-lock');
        const first = el.querySelector('[data-juwri-autofocus]') || focusables(el)[0] || el;
        if (first === el && !el.hasAttribute('tabindex')) el.setAttribute('tabindex', '-1');
        first.focus();
        emit('sheet:open', { id });
        if (hooks.onOpen) hooks.onOpen(id);
        return true;
    }

    function close(id) {
        const i = id ? stack.findIndex((s) => s.id === id) : stack.length - 1;
        if (i < 0) return false;
        const entry = stack[i];
        stack.splice(i, 1);
        entry.el.hidden = true;
        for (const t of doc.querySelectorAll('[aria-controls="' + entry.id + '"]')) t.setAttribute('aria-expanded', 'false');
        for (const n of entry.inerted) n.removeAttribute('inert');
        const top = stack[stack.length - 1];
        if (top) top.inerted = setInert(top.el);
        else doc.documentElement.classList.remove('juwri-scroll-lock');
        if (entry.opener && typeof entry.opener.focus === 'function' && doc.contains(entry.opener)) entry.opener.focus();
        emit('sheet:close', { id: entry.id });
        if (hooks.onClose) hooks.onClose(entry.id);
        return true;
    }

    function onKey(e) {
        const top = stack[stack.length - 1];
        if (!top) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            close();
        } else if (e.key === 'Tab') {
            const list = focusables(top.el);
            if (!list.length) { e.preventDefault(); top.el.focus(); return; }
            const active = doc.activeElement;
            const target = nextFocus(list, top.el.contains(active) ? active : null, e.shiftKey);
            const edge = e.shiftKey ? list[0] : list[list.length - 1];
            // Only step in by hand at the edges (or from outside); the browser moves focus inside the sheet.
            if (!top.el.contains(active) || active === edge) { e.preventDefault(); target.focus(); }
        }
    }

    function onClick(e) {
        const t = e.target.closest && e.target.closest('[data-juwri-open],[data-juwri-close],[data-juwri-overlay]');
        if (!t) return;
        if (t.hasAttribute('data-juwri-open')) {
            e.preventDefault();
            open(t.getAttribute('data-juwri-open'), t);
        } else if (t.hasAttribute('data-juwri-close')) {
            e.preventDefault();
            const own = t.closest('[data-juwri-sheet]');
            close(t.getAttribute('data-juwri-close') || (own && own.id) || undefined);
        } else if (t.hasAttribute('data-juwri-overlay') && e.target === t) {
            const own = t.closest('[data-juwri-sheet]');
            close((own && own.id) || undefined);
        }
    }

    /** `/store#cart` (the view model's cart / track links) opens the sheet with that id. */
    function fromHash() {
        const id = (globalThis.location && globalThis.location.hash || '').slice(1);
        if (id && find(id) && find(id).hasAttribute('data-juwri-sheet')) open(id);
    }

    function bind() {
        if (bound) return;
        bound = true;
        doc.addEventListener('click', onClick);
        doc.addEventListener('keydown', onKey);
        globalThis.addEventListener('hashchange', fromHash);
        // A sheet the server rendered already open (a deep link to an item, a static state) joins the stack: focus
        // trap, scroll lock and the inert page behind it, which the markup set only as a no-script fallback.
        for (const el of Array.from(doc.querySelectorAll('[data-juwri-sheet]')).filter((x) => !x.hidden && x.id)) {
            for (const child of Array.from(doc.body.children)) if (child.hasAttribute('inert') && !child.contains(el)) child.removeAttribute('inert');
            el.hidden = true;
            open(el.id, null);
        }
        fromHash();
    }

    return { open, close, bind, isOpen: (id) => stack.some((s) => s.id === id), depth: () => stack.length };
}
