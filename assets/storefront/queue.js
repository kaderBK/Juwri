// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* juwri storefront core — the offline order queue (THEME-CONTRACT §5.5, owner decisions D4 / Q5).
   - Only dine-in at a table and takeaway / pickup paid on collection may queue; delivery and online-paid orders
     never do. The store's address of truth is the page's boot data (`offline_queue`): any level off (platform,
     reseller, owner) arrives here as `enabled: false`, and then the queue is dropped with a notice.
   - A queued order keeps the client UUIDv7 it was minted with. When the network returns it is re-quoted; if the
     total moved or an item vanished the shopper sees old vs new and must confirm again. Nothing changed = sent.
   - Submission is idempotent: the server dedupes by that UUIDv7 (J27-A2), so a retry never makes a second order.
   States: waiting | sending | confirm | placed | expired | failed. */
import { clone, emit as defaultEmit } from './util.js';

export const STATES = ['waiting', 'sending', 'confirm', 'placed', 'expired', 'failed'];
const DEFAULT_EXPIRY_MIN = 30;
const PLACED_KEEP_MS = 24 * 3600 * 1000;

export function createQueue(deps) {
    const { boot, db, api } = deps;
    const emit = deps.emit || defaultEmit;
    const announce = deps.announce || (() => {});
    const now = deps.now || Date.now;
    const text = deps.text || ((k, d) => d);
    const isOnline = deps.isOnline || (() => (typeof navigator === 'undefined' ? true : navigator.onLine !== false));

    const cfg = () => boot.offline_queue || { enabled: false, order_types: [], expiry_min: DEFAULT_EXPIRY_MIN };
    let busy = false;

    const enabled = () => cfg().enabled === true;

    function eligible(orderType, fields = {}) {
        if (!enabled()) return false;
        if (!orderType || !(cfg().order_types || []).includes(orderType)) return false;
        return fields.pay_via !== 'pay_online'; // online-paid orders never queue
    }

    async function list() {
        const rows = await db.all('queue');
        return rows.sort((a, b) => a.created_at - b.created_at);
    }

    async function save(item) {
        await db.put('queue', item.id, item);
        emit('queue:changed', { item: clone(item) });
        return item;
    }

    async function enqueue(entry) {
        const created = now();
        const minutes = Number(cfg().expiry_min) > 0 ? Number(cfg().expiry_min) : DEFAULT_EXPIRY_MIN;
        const item = Object.assign({}, entry, {
            created_at: created,
            expires_at: created + minutes * 60000,
            state: 'waiting',
            body: Object.assign({}, entry.body, { queued_at: new Date(created).toISOString() }),
        });
        await save(item);
        announce(text('waiting_for_connection', 'Waiting for connection'));
        return item;
    }

    function setState(item, state, extra) {
        item.state = state;
        Object.assign(item, extra || {});
        return save(item);
    }

    /** Throw every unsent order away (kill switch, or the server said the queue is off). Placed ones stay as receipts. */
    async function dropAll(reason) {
        const rows = (await list()).filter((i) => i.state !== 'placed');
        for (const r of rows) await db.del('queue', r.id);
        if (rows.length) {
            emit('queue:dropped', { count: rows.length, reason });
            announce(text('waiting_orders_were_removed_because_offline_ordering_was_switched_off', 'Waiting orders were removed because offline ordering was switched off.'));
        }
        emit('queue:changed', { dropped: rows.length });
        return rows.length;
    }

    async function send(item) {
        await setState(item, 'sending');
        try {
            const res = await api.post(boot.endpoints.place, item.body, { 'Idempotency-Key': item.id, 'X-Juwri-Queued': '1' });
            if (res.__status === 409 || res.conflict) {
                return setState(item, 'failed', { reason: 'conflict', message: res.message || '' });
            }
            if (res.code === 'offline_queue_disabled') {
                await dropAll('disabled');
                return null;
            }
            if (res.success === false) {
                if (res.code === 'offline_queue_expired') return setState(item, 'expired', { message: res.message || '' });
                return setState(item, 'failed', { reason: 'refused', message: res.message || '' });
            }
            await setState(item, 'placed', { response: { track_url: res.track_url || null, duplicate: !!res.duplicate }, placed_at: now() });
            emit('order:placed', { id: item.id, queued: true, track_url: res.track_url || null, duplicate: !!res.duplicate });
            announce(text('order_placed', 'Order placed'));
            return item;
        } catch (e) {
            if (e.offline) return setState(item, 'waiting');
            if (e.code === 'offline_queue_disabled') { await dropAll('disabled'); return null; }
            if (e.code === 'offline_queue_expired') return setState(item, 'expired', { message: e.message });
            return setState(item, 'failed', { reason: 'refused', message: e.message });
        }
    }

    async function quoteOf(item) {
        const body = { lines: item.lines };
        if (item.order_type) body.order_type = item.order_type;
        if (item.coupon) body.coupon = item.coupon;
        if (item.tip_minor > 0) body.tip_minor = item.tip_minor;
        if (item.branch) body.branch = item.branch;
        return api.post(boot.endpoints.quote, body);
    }

    /** Work through the waiting orders (called on load and when the browser reports it is online again). */
    async function flush() {
        if (busy) return;
        busy = true;
        try {
            if (!enabled()) { await dropAll('disabled'); return; }
            if (!isOnline()) return;
            for (const item of await list()) {
                if (item.state === 'placed' && now() - (item.placed_at || 0) > PLACED_KEEP_MS) { await db.del('queue', item.id); continue; }
                if (item.state !== 'waiting' && item.state !== 'sending') continue;
                if (now() > item.expires_at) {
                    await setState(item, 'expired');
                    announce(text('order_expired', 'Order expired'));
                    continue;
                }
                let current;
                try {
                    current = (await quoteOf(item)).quote;
                } catch (e) {
                    if (e.offline) { await setState(item, 'waiting'); break; }
                    // an item is gone or the store cannot serve it: the shopper decides
                    await setState(item, 'confirm', { reason: 'unavailable', old: item.total_label || null, message: e.message });
                    announce(text('needs_your_confirmation', 'Needs your confirmation'));
                    continue;
                }
                if (item.total_minor === null || item.total_minor === undefined || current.total.minor !== item.total_minor) {
                    await setState(item, 'confirm', { reason: 'changed', old: item.total_label || null, new_total: current.total, new_quote: current });
                    announce(text('needs_your_confirmation', 'Needs your confirmation'));
                    emit('queue:confirm', { id: item.id, previous: item.total_label, current });
                    continue;
                }
                await send(item);
            }
        } finally {
            busy = false;
        }
    }

    /** The shopper confirmed a changed order: send it with the same UUIDv7 (the new quote is what they saw). */
    async function confirm(id) {
        const item = await db.get('queue', id);
        if (!item || item.state !== 'confirm') return null;
        if (now() > item.expires_at) { await setState(item, 'expired'); return item; }
        if (item.new_quote) { item.total_minor = item.new_quote.total.minor; item.total_label = item.new_quote.total.formatted; }
        return send(item);
    }

    async function retry(id) {
        const item = await db.get('queue', id);
        if (!item || item.state !== 'failed') return null;
        await setState(item, 'waiting');
        await flush();
        return db.get('queue', id);
    }

    async function dismiss(id) {
        await db.del('queue', id);
        emit('queue:changed', { dismissed: id });
    }

    function start() {
        if (typeof globalThis.addEventListener === 'function') {
            globalThis.addEventListener('online', () => { flush(); });
        }
        return flush();
    }

    return { enabled, eligible, list, enqueue, flush, confirm, retry, dismiss, dropAll, start, STATES };
}
