// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* juwri storefront core — the JSON calls (quote, place, snapshot). The server is the only source of totals:
   nothing in this folder adds, rounds or formats money. */
import { apiError } from './util.js';

/** The same-origin request headers every storefront POST carries (session CSRF + JSON). */
export function csrfToken(doc = globalThis.document) {
    const m = doc && doc.querySelector && doc.querySelector('meta[name="csrf-token"]');
    return m ? m.getAttribute('content') || '' : '';
}

export function createApi({ fetch = globalThis.fetch.bind(globalThis), csrf = csrfToken, timeoutMs = 20000, homeUrl = null } = {}) {
    let token = null;

    async function refreshCsrf() {
        if (!homeUrl) return false;
        try {
            const res = await fetch(homeUrl, { headers: { Accept: 'text/html' }, credentials: 'same-origin', cache: 'no-store' });
            const m = /<meta name="csrf-token" content="([^"]+)"/.exec(await res.text());
            if (m) { token = m[1]; return true; }
        } catch (e) { /* offline: the caller sees the original failure */ }
        return false;
    }

    async function once(method, url, body, extra) {
        const ctl = typeof AbortController === 'function' ? new AbortController() : null;
        const timer = ctl ? setTimeout(() => ctl.abort(), timeoutMs) : null;
        try {
            const res = await fetch(url, {
                method,
                credentials: 'same-origin',
                signal: ctl ? ctl.signal : undefined,
                headers: Object.assign({
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': token || csrf(),
                }, extra || {}),
                body: body === undefined ? undefined : JSON.stringify(body),
            });
            let json = null;
            try { json = await res.json(); } catch (e) { json = null; }
            return { status: res.status, ok: res.ok, json };
        } finally {
            if (timer) clearTimeout(timer);
        }
    }

    /** POST JSON. Network failures throw a TypeError-like error (`offline = true`); HTTP errors throw an ApiError. */
    async function store(url, body, extra) {
        let r;
        try {
            r = await once('POST', url, body, extra);
            if (r.status === 419 && await refreshCsrf()) r = await once('POST', url, body, extra);
        } catch (e) {
            const err = new Error(e && e.message ? e.message : 'offline');
            err.offline = true;
            throw err;
        }
        if (!r.ok && !(r.status === 409 && r.json)) throw apiError(r.status, r.json);
        r.json = r.json || {};
        r.json.__status = r.status;
        return r.json;
    }

    async function getJson(url) {
        let res;
        try {
            res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        } catch (e) {
            const err = new Error('offline');
            err.offline = true;
            throw err;
        }
        if (!res.ok) throw apiError(res.status, null);
        return res.json();
    }

    return { post: store, getJson, refreshCsrf };
}
