// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* juwri storefront core — the cart client (THEME-CONTRACT §5.1-5.3).
   Lines are public ids; every amount the shopper sees is the server quote (`quote.total`, `quote.rows`).
   This file never adds, multiplies, rounds or formats money. */
import { clone, emit as defaultEmit, lineKey, uuidv7 } from './util.js';

const MAX_QTY = 999;

export function createCart(deps) {
    const { boot, db, api, queue } = deps;
    const emit = deps.emit || defaultEmit;
    const announce = deps.announce || (() => {});
    const now = deps.now || Date.now;
    const text = deps.text || ((k, d) => d);
    const later = deps.setTimeout || setTimeout;
    const debounceMs = deps.debounceMs === undefined ? 150 : deps.debounceMs;

    const state = {
        lines: [],
        orderType: (boot.context && boot.context.order_type) || null,
        coupon: null,
        tipMinor: 0,
        branch: boot.branch || null,
        shipping: null, // goods: { method: <shipping method public id>, address: {country, city, ...} } — in memory only
        quoteHash: null, // goods: the server's hash of the quote the shopper saw (the order must be placed against it)
        extras: {}, // grocery: slot_id / hold_id / express / substitution_preference — sent with the quote and the order, in memory only
        holdToken: null, // grocery: the token of the slot hold (the order carries it as X-Hold-Token)
        clientId: null,
        quote: null, // the last server quote (`Quote`), never edited here
        meta: null, // goods: what else the quote answered — {warnings, requires, payment_methods, payment_modes, weighed}, read-only
        shown: null, // the quote the shopper last saw and agreed to (re-confirm compares against it)
        status: 'idle', // idle | loading | ok | error | offline
        stale: false,
        error: null,
        placing: false,
    };

    let seq = 0;
    let timer = null;
    let ready = null;

    const changed = (why) => emit('cart:changed', { why, cart: api_.snapshot() });

    async function save() {
        try {
            await db.put('cart', 'current', {
                lines: state.lines, orderType: state.orderType, coupon: state.coupon, tipMinor: state.tipMinor,
                clientId: state.clientId, quote: state.quote, shown: state.shown, savedAt: now(),
            });
        } catch (e) { /* storage unavailable: the cart lives in memory */ }
    }

    function bodyLines() {
        return state.lines.map((l) => {
            const out = { item: l.item, qty: l.qty };
            if (l.variant) out.variant = l.variant;
            if (l.options && l.options.length) out.options = l.options.slice();
            if (l.note) out.note = l.note;
            if (l.substitution) out.substitution = l.substitution;
            return out;
        });
    }

    function quoteBody() {
        const b = { lines: bodyLines() };
        if (state.orderType) b.order_type = state.orderType;
        if (state.coupon) b.coupon = state.coupon;
        if (state.tipMinor > 0) b.tip_minor = state.tipMinor;
        if (state.branch) b.branch = state.branch;
        if (state.shipping) {
            b.shipping_method = state.shipping.method;
            if (state.shipping.address) b.address = state.shipping.address;
        }
        Object.assign(b, state.extras);
        return b;
    }

    function setStatus(status, extra) {
        state.status = status;
        state.stale = status === 'offline';
        state.error = extra && extra.error ? extra.error : null;
    }

    /** One quote round trip. Only the newest request may change the state (older answers are dropped). */
    async function requote(forPlace = false) {
        const mine = ++seq;
        if (state.lines.length === 0) {
            state.quote = null;
            state.meta = null;
            state.shown = null;
            setStatus('idle');
            emit('quote:updated', { quote: null, status: 'idle' });
            await save();
            return { ok: true, quote: null };
        }
        setStatus('loading');
        emit('quote:loading', {});
        try {
            const res = await api.post(boot.endpoints.quote, quoteBody());
            if (mine !== seq) return { ok: false, superseded: true };
            state.quote = res.quote || null;
            state.quoteHash = res.quote_hash || null;
            state.meta = res.quote ? {
                warnings: res.warnings || [], requires: res.requires || [], payment_methods: res.payment_methods || [],
                payment_modes: res.payment_modes || [], weighed: res.weighed || null,
            } : null;
            setStatus('ok');
            if (!forPlace) state.shown = clone(state.quote); // what the shopper now sees is what they agree to
            emit('quote:updated', { quote: state.quote, status: 'ok' });
            await save();
            return { ok: true, quote: state.quote };
        } catch (e) {
            if (mine !== seq) return { ok: false, superseded: true };
            if (e.offline) {
                setStatus('offline');
                emit('quote:updated', { quote: state.quote, status: 'offline', stale: true });
                announce(text('offline_prices_may_change', 'Offline: prices may change'));
                return { ok: false, offline: true, quote: state.quote };
            }
            setStatus('error', { error: e.message });
            emit('quote:error', { message: e.message, status: e.status, errors: e.errors });
            return { ok: false, error: e };
        }
    }

    function scheduleQuote() {
        if (timer) clearTimeout(timer);
        if (debounceMs === 0) { timer = null; return requote(); }
        timer = later(() => { timer = null; requote(); }, debounceMs);
        return null;
    }

    function mutated(why) {
        changed(why);
        save();
        return scheduleQuote();
    }

    function clampQty(q) {
        q = Math.floor(Number(q));
        return Number.isFinite(q) ? Math.min(MAX_QTY, Math.max(0, q)) : 0;
    }

    const api_ = {
        ready: () => ready,
        get state() { return state; },

        /** Read-only copy for themes and events. */
        snapshot() {
            return {
                lines: clone(state.lines), order_type: state.orderType, coupon: state.coupon, tip_minor: state.tipMinor,
                count: api_.count(), quote: clone(state.quote), meta: clone(state.meta), status: state.status, stale: state.stale,
            };
        },

        count() { return state.lines.reduce((n, l) => n + l.qty, 0); },

        /** Add a line (same item + variant + options + note merges). `name` is display-only. */
        add(item, opts = {}) {
            if (typeof item !== 'string' || item === '') throw new TypeError('item public id required');
            const line = {
                item, qty: clampQty(opts.qty === undefined ? 1 : opts.qty) || 1,
                variant: opts.variant || null, options: (opts.options || []).slice().sort(),
                note: (opts.note || '').trim(), name: opts.name || '', substitution: opts.substitution || null,
            };
            line.key = lineKey(line);
            const same = state.lines.find((l) => l.key === line.key);
            if (same) same.qty = Math.min(MAX_QTY, same.qty + line.qty); else state.lines.push(line);
            announce(text('added_to_your_order', 'Added to your order'));
            emit('cart:add', { item, key: line.key });
            return mutated('add');
        },

        /** Set a line's quantity; 0 removes it. */
        update(key, qty) {
            const line = state.lines.find((l) => l.key === key);
            if (!line) return null;
            const q = clampQty(qty);
            if (q === 0) return api_.remove(key);
            line.qty = q;
            return mutated('update');
        },

        remove(key) {
            const i = state.lines.findIndex((l) => l.key === key);
            if (i < 0) return null;
            state.lines.splice(i, 1);
            announce(text('removed_from_your_order', 'Removed from your order'));
            emit('cart:remove', { key });
            return mutated('remove');
        },

        clear() {
            state.lines = [];
            state.coupon = null;
            state.tipMinor = 0;
            state.shown = null;
            return mutated('clear');
        },

        setOrderType(type) { state.orderType = type || null; return mutated('order_type'); },
        setCoupon(code) { state.coupon = (code || '').trim() || null; return mutated('coupon'); },
        setTip(minor) { state.tipMinor = Math.max(0, Math.floor(Number(minor) || 0)); return mutated('tip'); },
        setBranch(id) { state.branch = id || null; return mutated('branch'); },
        /** Goods: the shipping method chosen for an address (null clears it); the quote then carries the shipping row. */
        setShipping(method, address) {
            state.shipping = method ? { method, address: address ? Object.assign({}, address) : null } : null;
            return mutated('shipping');
        },

        /**
         * Grocery: the delivery / collection time and the out-of-stock choice the quote and the order carry — `slot_id`,
         * `hold_id` (+ its token, sent as X-Hold-Token), `express`, `substitution_preference`, and the address the radius is
         * checked on. A null / false / empty value clears the key. Never prices anything here: the server quote does.
         */
        setExtras(values, holdToken) {
            for (const [k, v] of Object.entries(values || {})) {
                if (v === null || v === undefined || v === '' || v === false) delete state.extras[k]; else state.extras[k] = v;
            }
            if (holdToken !== undefined) state.holdToken = holdToken || null;
            return mutated('extras');
        },

        requote,

        /**
         * The server quote of ONE hypothetical line (an item sheet's live total) — never touches the cart.
         * Resolves `{ok, quote}`; the total the shopper sees on the Add button is the server's.
         */
        async preview(line) {
            const body = { lines: [{ item: line.item, qty: clampQty(line.qty === undefined ? 1 : line.qty) || 1 }] };
            const l = body.lines[0];
            if (line.variant) l.variant = line.variant;
            if (line.options && line.options.length) l.options = line.options.slice();
            if (state.orderType) body.order_type = state.orderType;
            if (state.branch) body.branch = state.branch;
            try {
                const res = await api.post(boot.endpoints.quote, body);
                return { ok: true, quote: res.quote || null };
            } catch (e) {
                return { ok: false, offline: !!e.offline, message: e.message };
            }
        },

        /** The shopper agreed to the quote now on screen (re-confirm after a change). */
        confirmShown() { state.shown = clone(state.quote); save(); },

        /**
         * Place the order. Re-quotes first; if the total moved since the shopper last saw it, nothing is placed
         * and `{status:'confirm', previous, current}` comes back — the shopper confirms (`place(fields, {confirm:true})`).
         * The client UUIDv7 stays the same for every retry of this intent (J27-A2); a 409 mints a new one.
         *
         * @returns {Promise<{status:'placed'|'confirm'|'queued'|'offline'|'unavailable'|'failed'|'conflict', ...}>}
         */
        async place(fields = {}, opts = {}) {
            if (state.placing) return { status: 'busy' };
            if (state.lines.length === 0) return { status: 'failed', message: text('nothing_in_your_order_yet', 'Nothing in your order yet') };
            state.placing = true;
            if (!state.clientId) state.clientId = uuidv7();
            emit('order:placing', { id: state.clientId });
            try {
                if (timer) { clearTimeout(timer); timer = null; }
                const before = state.shown ? clone(state.shown) : null;
                const q = await requote(true);
                const offline = q.offline === true || (typeof navigator !== 'undefined' && navigator.onLine === false && !q.ok);

                if (offline) {
                    return await queueOrOffline(fields);
                }
                if (!q.ok) {
                    const message = (q.error && q.error.message) || text('some_items_are_no_longer_available', 'Some items are no longer available');
                    return { status: 'unavailable', message };
                }

                if (!opts.confirm && before && q.quote && q.quote.total.minor !== before.total.minor) {
                    announce(text('prices_changed_while_you_were_offline', 'Prices changed while you were offline.'));
                    emit('order:confirm', { previous: before, current: q.quote });
                    return { status: 'confirm', previous: before, current: q.quote };
                }
                state.shown = clone(state.quote);

                let res;
                try {
                    res = await api.post(boot.endpoints.place, placeBody(fields), Object.assign({ 'Idempotency-Key': state.clientId }, state.holdToken ? { 'X-Hold-Token': state.holdToken } : {}));
                } catch (e) {
                    if (e.offline) return await queueOrOffline(fields);
                    emit('order:failed', { message: e.message, status: e.status });
                    return { status: 'failed', message: e.message, errors: e.errors };
                }

                if (res.__status === 409 || res.conflict) {
                    state.clientId = uuidv7();
                    emit('order:failed', { message: res.message, conflict: true });
                    return { status: 'conflict', message: res.message };
                }
                if (res.success === false) {
                    emit('order:failed', { message: res.message });
                    return { status: 'failed', message: res.message, response: res };
                }
                const placed = { id: state.clientId, response: res, track_url: res.track_url || null, payment_url: res.payment_url || null, duplicate: !!res.duplicate };
                resetAfterOrder();
                emit('order:placed', placed);
                announce(text('order_placed', 'Order placed'));
                return Object.assign({ status: 'placed' }, placed);
            } finally {
                state.placing = false;
            }
        },
    };

    /** Offline: queue the order when the store's queue is on and the order type may queue; else say so. */
    async function queueOrOffline(fields) {
        if (queue && queue.eligible(state.orderType, fields)) {
            const item = await queue.enqueue({
                id: state.clientId, body: placeBody(fields), lines: bodyLines(), order_type: state.orderType,
                total_minor: state.quote ? state.quote.total.minor : null,
                total_label: state.quote ? state.quote.total.formatted : null,
                coupon: state.coupon, tip_minor: state.tipMinor, branch: state.branch,
            });
            resetAfterOrder();
            return { status: 'queued', item };
        }
        const message = text('connect_to_place_this_order', 'Connect to place this order');
        announce(message);
        return { status: 'offline', message };
    }

    function placeBody(fields) {
        const b = Object.assign({}, fields, {
            client_order_id: state.clientId,
            lines: bodyLines(),
        });
        if (state.orderType) b.order_type = state.orderType;
        if (state.coupon) b.coupon_code = state.coupon;
        if (state.tipMinor > 0) b.tip_minor = state.tipMinor;
        if (state.branch) b.branch = state.branch;
        if (state.shipping) {
            b.shipping_method = state.shipping.method;
            if (state.shipping.address) b.address = state.shipping.address;
        }
        Object.assign(b, state.extras);
        if (state.quoteHash) b.quote_hash = state.quoteHash;
        return b;
    }

    function resetAfterOrder() {
        state.lines = [];
        state.coupon = null;
        state.tipMinor = 0;
        state.quote = null;
        state.quoteHash = null;
        state.shipping = null;
        state.extras = {};
        state.holdToken = null;
        state.shown = null;
        state.clientId = null;
        setStatus('idle');
        seq++;
        save();
        changed('placed');
        emit('quote:updated', { quote: null, status: 'idle' });
    }

    ready = (async () => {
        try {
            const saved = await db.get('cart', 'current');
            if (saved && Array.isArray(saved.lines)) {
                state.lines = saved.lines.filter((l) => l && typeof l.item === 'string' && l.qty > 0);
                state.orderType = (boot.context && boot.context.order_type) || saved.orderType || state.orderType;
                state.coupon = saved.coupon || null;
                state.tipMinor = saved.tipMinor || 0;
                state.clientId = saved.clientId || null;
                state.quote = saved.quote || null;
                state.shown = saved.shown || null;
            }
        } catch (e) { /* fresh cart */ }
        changed('restore');
        if (state.lines.length) { state.status = 'ok'; scheduleQuote(); }
    })();

    return api_;
}
