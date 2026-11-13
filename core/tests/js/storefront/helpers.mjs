// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// Shared fakes for the storefront core unit tests (node --test, no dependencies).
import { memoryStore } from '../../../../assets/storefront/db.js';

export const money = (minor, currency = 'SAR') => ({ minor, currency, formatted: `${currency} ${(minor / 100).toFixed(2)}` });

/** A fake server: records every call; `handlers` map a URL to a function(body, headers) -> {status, json} | throws. */
export function fakeApi(handlers = {}) {
    const calls = [];
    return {
        calls,
        offline: false,
        async post(url, body, headers) {
            calls.push({ url, body: JSON.parse(JSON.stringify(body)), headers: headers || {} });
            if (this.offline) { const e = new Error('offline'); e.offline = true; throw e; }
            const h = handlers[url];
            if (!h) throw new Error('no handler ' + url);
            const r = await h(body, headers || {});
            if (r && r.throw) { const e = new Error(r.throw.message || 'x'); Object.assign(e, r.throw); throw e; }
            return Object.assign({ __status: 200 }, r);
        },
        async getJson() { return {}; },
        async refreshCsrf() { return false; },
    };
}

export function bootData(over = {}) {
    return Object.assign({
        store: { slug: 'bloom' },
        endpoints: { quote: '/bloom/quote', place: '/bloom/send-order', home: '/bloom' },
        context: { order_type: 'dine_in' },
        offline_queue: { enabled: true, order_types: ['dine_in', 'takeaway', 'pickup'], expiry_min: 30 },
        i18n: {},
    }, over);
}

/** Quote of a cart whose total the SERVER decided (the tests never compute one). */
export const quoteOf = (totalMinor) => ({ quote: { lines: [], rows: [], total: money(totalMinor), cta_label: 'Go' } });

export const db = () => memoryStore();
export const tick = () => new Promise((r) => setImmediate(r));
