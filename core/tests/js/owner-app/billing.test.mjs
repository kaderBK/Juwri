// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// Owner app in-app purchase billing page (server item P5-22).
// The pure pieces of assets/owner-app/billing.js: state -> text, price choice,
// the buy / restore orchestration and the store verify bodies. node:test, no
// browser: a fake fetch and a fake bridge.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const billing = require('../../../../assets/owner-app/billing.js');

const SOURCE = readFileSync(new URL('../../../../assets/owner-app/billing.js', import.meta.url), 'utf8');

const t = (key) => key; // the server sends identity English in the tests
const dateOf = (iso) => `«${iso}»`;

// ── status -> text (all 6 states + none) ─────────────────────────────────────

test('no status shows the empty badge and nothing else', () => {
    const view = billing.statusView(null, t, dateOf);
    assert.equal(view.badge, 'No active subscription');
    assert.equal(view.tone, 'neutral');
    assert.equal(view.sentence, null);
    assert.equal(view.plan, null);
});

test('every state maps to its badge, tone and sentence', () => {
    const cases = {
        active: ['Active', 'success', `Renews on «2026-11-12T00:00:00+00:00»`],
        grace: ['Grace period', 'warning', 'Payment problem. Your plan stays active until «2026-11-12T00:00:00+00:00» while the store retries.'],
        cancelling: ['Cancels at period end', 'warning', 'Cancelled. Your plan stays active until «2026-11-12T00:00:00+00:00».'],
        expired: ['Expired', 'danger', 'Expired on «2026-11-12T00:00:00+00:00».'],
        refunded: ['Refunded', 'danger', 'Refunded. This plan is no longer active.'],
        revoked: ['Removed', 'danger', 'The store removed access to this plan.'],
    };

    for (const [state, [badge, tone, sentence]] of Object.entries(cases)) {
        const view = billing.statusView({ state, plan_id: 2, plan_name: 'Pro', expires_at: '2026-11-12T00:00:00+00:00' }, t, dateOf);
        assert.equal(view.badge, badge, state);
        assert.equal(view.tone, tone, state);
        assert.equal(view.sentence, sentence, state);
        assert.equal(view.plan, 'Pro', state);
    }
});

test('an unknown state degrades to the empty badge', () => {
    const view = billing.statusView({ state: 'who_knows' }, t, dateOf);
    assert.equal(view.badge, 'No active subscription');
    assert.equal(view.sentence, null);
});

test('dates are formatted in the document language with Intl', () => {
    const formatted = billing.formatDate('2026-11-12T00:00:00+00:00', 'en-US');
    assert.match(formatted, /2026/);
    assert.match(formatted, /November/);
});

// ── plan price / interval / store ────────────────────────────────────────────

test('a native price wins over the server price', () => {
    const plan = { product_id: 'com.x.pro.monthly', price: '29.00', currency: 'USD' };
    assert.equal(billing.planPrice(plan, { 'com.x.pro.monthly': '$29.00' }), '$29.00');
    assert.equal(billing.planPrice(plan, {}), '29.00 USD');
    assert.equal(billing.planPrice(plan, { 'other': '$1' }), '29.00 USD');
    assert.equal(billing.planPrice(plan, null), '29.00 USD');
});

test('interval labels', () => {
    assert.equal(billing.intervalLabel('MONTHLY', t), 'per month');
    assert.equal(billing.intervalLabel('ANNUAL', t), 'per year');
    assert.equal(billing.intervalLabel(undefined, t), 'per month');
});

test('the device store decides which plans are shown', () => {
    assert.equal(billing.storeForPlatform('ios'), 'apple');
    assert.equal(billing.storeForPlatform('android'), 'google');
    assert.equal(billing.storeForPlatform('windows'), null);
    assert.equal(billing.storeForPlatform(undefined), null);

    const plans = [
        { product_id: 'a1', store: 'apple' },
        { product_id: 'g1', store: 'google' },
        { product_id: 'a2', store: 'apple' },
    ];
    assert.deepEqual(billing.visiblePlans(plans, 'apple').map((p) => p.product_id), ['a1', 'a2']);
    assert.deepEqual(billing.visiblePlans(plans, 'google').map((p) => p.product_id), ['g1']);
    assert.equal(billing.visiblePlans(plans, null).length, 3);
});

test('verify bodies are per store', () => {
    assert.deepEqual(billing.verifyBody('apple', { signed_transaction: 'S' }), { signed_transaction: 'S' });
    assert.deepEqual(billing.verifyBody('google', { product_id: 'p', purchase_token: 'pt' }), { product_id: 'p', purchase_token: 'pt' });
    const endpoints = { apple: '/apple', google: '/google' };
    assert.equal(billing.verifyUrl('apple', endpoints), '/apple');
    assert.equal(billing.verifyUrl('google', endpoints), '/google');
});

// ── fakes ────────────────────────────────────────────────────────────────────

function fakeFetch(routes) {
    const calls = [];
    const impl = (url, init = {}) => {
        calls.push({ url, init, body: init.body ? JSON.parse(init.body) : null });
        const handler = routes[url];
        if (!handler) return Promise.resolve({ ok: false, status: 404, json: () => Promise.resolve({}) });
        if (handler.throw) return Promise.reject(new Error('offline'));
        const { status = 200, body = {} } = handler;
        return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
    };
    return { impl, calls };
}

const ENDPOINTS = { overview: '/overview', token: '/token', apple: '/apple', google: '/google' };

function baseCtx(over = {}) {
    const busy = [];
    return Object.assign({
        fetch: () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) }),
        endpoints: ENDPOINTS,
        csrf: 'csrf-token',
        request: () => Promise.resolve({ status: 'cancelled' }),
        refresh: () => Promise.resolve(),
        setBusy: (value) => busy.push(value),
        _busy: busy,
    }, over);
}

const plan = { product_id: 'com.x.pro.monthly', store: 'apple', plan_id: 2, name: 'Pro', interval: 'MONTHLY', price: '29.00', currency: 'USD' };
const googlePlan = { product_id: 'com.x.pro.monthly', store: 'google', plan_id: 2, name: 'Pro', interval: 'MONTHLY', price: '29.00', currency: 'USD' };

const tokenFetch = (routes = {}) => fakeFetch(Object.assign({ '/token': { body: { account_token: 'tok' } } }, routes));

// Restore needs a different verify status per item: this fetch pops one status
// per verify call so a test can make the first fail and the second succeed.
function verifySeq(statuses) {
    const calls = [];
    const impl = (url, init = {}) => {
        calls.push({ url, init, body: init.body ? JSON.parse(init.body) : null });
        if (url === '/token') return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ account_token: 'tok' }) });
        if (url === '/apple' || url === '/google') {
            const status = statuses.length ? statuses.shift() : 200;
            if (status === 'network') return Promise.reject(new Error('offline'));
            return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve({}) });
        }
        return Promise.resolve({ ok: false, status: 404, json: () => Promise.resolve({}) });
    };
    return { impl, calls };
}

// ── buy flow ─────────────────────────────────────────────────────────────────

test('buy success verifies with the signed transaction and refetches', async () => {
    const { impl, calls } = tokenFetch({ '/apple': { status: 200 } });
    let refreshed = 0;
    const acks = [];
    const ctx = baseCtx({
        fetch: impl,
        request: (event, data) => {
            if (event === 'acknowledge') { acks.push(data); return Promise.resolve({}); }
            assert.equal(event, 'buy');
            assert.deepEqual(data, { product_id: plan.product_id, store: 'apple', account_token: 'tok' });
            return Promise.resolve({ status: 'purchased', signed_transaction: 'SIGNED' });
        },
        refresh: () => { refreshed += 1; return Promise.resolve(); },
    });

    const result = await billing.runBuy(ctx, plan);
    assert.deepEqual(result, { status: 'purchased', key: 'Purchase complete.' });
    assert.equal(refreshed, 1);
    assert.equal(calls.length, 2); // token, apple verify
    assert.deepEqual(calls[1].body, { signed_transaction: 'SIGNED' });
    assert.equal(calls[1].init.method, 'POST');
    assert.equal(calls[1].init.headers['X-CSRF-TOKEN'], 'csrf-token');
    assert.deepEqual(acks, [{ store: 'apple', product_id: plan.product_id, signed_transaction: 'SIGNED' }]);
});

test('buy with google posts product_id + purchase_token', async () => {
    const { impl, calls } = tokenFetch({ '/google': { status: 200 } });
    const acks = [];
    const ctx = baseCtx({
        fetch: impl,
        request: (event, data) => {
            if (event === 'acknowledge') { acks.push(data); return Promise.resolve({}); }
            return Promise.resolve({ status: 'purchased', product_id: 'com.x.pro.monthly', purchase_token: 'PT' });
        },
    });

    const result = await billing.runBuy(ctx, googlePlan);
    assert.equal(result.status, 'purchased');
    assert.equal(calls[1].url, '/google');
    assert.deepEqual(calls[1].body, { product_id: 'com.x.pro.monthly', purchase_token: 'PT' });
    assert.deepEqual(acks, [{ store: 'google', product_id: 'com.x.pro.monthly', purchase_token: 'PT' }]);
});

test('buy cancelled and pending stop before verifying', async () => {
    for (const [status, key] of [['cancelled', 'Purchase cancelled.'], ['pending', 'Waiting for approval from the store.']]) {
        const { impl, calls } = tokenFetch({ '/apple': { status: 200 } });
        const ctx = baseCtx({ fetch: impl, request: () => Promise.resolve({ status }) });
        const result = await billing.runBuy(ctx, plan);
        assert.equal(result.status, status);
        assert.equal(result.key, key);
        assert.equal(calls.length, 1, `${status}: only the token is fetched`);
    }
});

test('buy failure: rejected by the store, rejected by verify, or no bridge', async () => {
    // Store said failed.
    let ctx = baseCtx({ fetch: tokenFetch().impl, request: () => Promise.resolve({ status: 'failed' }) });
    assert.equal((await billing.runBuy(ctx, plan)).key, 'Something went wrong. Please try again.');

    // Store said purchased but the server rejected the verification.
    const { impl } = tokenFetch({ '/apple': { status: 422, body: { code: 'unknown_product' } } });
    ctx = baseCtx({ fetch: impl, request: () => Promise.resolve({ status: 'purchased', signed_transaction: 'S' }) });
    assert.equal((await billing.runBuy(ctx, plan)).key, 'Something went wrong. Please try again.');

    // No bridge at all.
    ctx = baseCtx({ fetch: tokenFetch().impl, request: () => Promise.reject(Object.assign(new Error('bridge_unavailable'), { bridge: true })) });
    assert.equal((await billing.runBuy(ctx, plan)).key, 'Something went wrong. Please try again.');
});

test('buy buttons are busy for the whole call', async () => {
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    const ctx = baseCtx({ fetch: tokenFetch().impl, request: () => gate.then(() => ({ status: 'cancelled' })) });

    const running = billing.runBuy(ctx, plan);
    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(ctx._busy, [true], 'busy is set before the native call resolves');
    release();
    await running;
    assert.deepEqual(ctx._busy, [true, false], 'busy is cleared after the call');
});

// ── acknowledge only after a 2xx verify ─────────────────────────────────────

test('acknowledge is sent after a 2xx verify, never on 422 / 503 / network error', async () => {
    // 2xx: the signed transaction is acknowledged with the plan's product id.
    for (const { store, status } of [{ store: 'apple', status: 200 }]) {
        const { impl } = tokenFetch({ '/apple': { status } });
        const events = [];
        const ctx = baseCtx({
            fetch: impl,
            request: (event, data) => {
                events.push({ event, data });
                return Promise.resolve({ status: 'purchased', signed_transaction: 'SIGNED' });
            },
        });
        await billing.runBuy(ctx, plan);
        assert.deepEqual(events.map((e) => e.event), ['buy', 'acknowledge'], store);
        assert.deepEqual(events[1].data, { store: 'apple', product_id: plan.product_id, signed_transaction: 'SIGNED' });
    }

    // 422 / 503: a handled failure, so nothing is sent to native beyond `buy`.
    for (const status of [422, 503]) {
        const { impl } = tokenFetch({ '/apple': { status } });
        const events = [];
        const ctx = baseCtx({
            fetch: impl,
            request: (event) => { events.push(event); return Promise.resolve({ status: 'purchased', signed_transaction: 'S' }); },
        });
        assert.equal((await billing.runBuy(ctx, plan)).status, 'failed');
        assert.deepEqual(events, ['buy'], `status ${status}`);
    }

    // Network error during the verify: same, no acknowledge.
    const { impl } = tokenFetch({ '/apple': { throw: true } });
    const events = [];
    const ctx = baseCtx({
        fetch: impl,
        request: (event) => { events.push(event); return Promise.resolve({ status: 'purchased', signed_transaction: 'S' }); },
    });
    assert.equal((await billing.runBuy(ctx, plan)).status, 'failed');
    assert.deepEqual(events, ['buy']);
});

test('a missing or empty account token fails before anything reaches native', async () => {
    for (const body of [{}, { account_token: '' }]) {
        const { impl } = fakeFetch({ '/token': { body } });
        const events = [];
        const ctx = baseCtx({
            fetch: impl,
            request: (event) => { events.push(event); return Promise.resolve({ status: 'purchased', signed_transaction: 'S' }); },
        });
        const result = await billing.runBuy(ctx, plan);
        assert.equal(result.status, 'failed');
        assert.equal(result.key, 'Something went wrong. Please try again.');
        assert.deepEqual(events, [], 'no buy is sent to native');
    }

    // Restore is refused the same way.
    const { impl } = fakeFetch({ '/token': { body: {} } });
    const ctx = baseCtx({ fetch: impl, request: () => { throw new Error('must not be called'); } });
    assert.equal((await billing.runRestore(ctx, 'apple')).status, 'failed');
});

test('getAccountToken rejects a non-2xx account-token response', async () => {
    const { impl } = fakeFetch({ '/token': { status: 503 } });
    await assert.rejects(() => billing.getAccountToken(baseCtx({ fetch: impl })));
});

// ── restore flow ─────────────────────────────────────────────────────────────

test('restore with apple verifies every transaction', async () => {
    const { impl, calls } = tokenFetch({ '/apple': { status: 200 } });
    let refreshed = 0;
    const acks = [];
    const ctx = baseCtx({
        fetch: impl,
        request: (event, data) => {
            if (event === 'acknowledge') { acks.push(data); return Promise.resolve({}); }
            assert.equal(event, 'restore');
            assert.deepEqual(data, { store: 'apple', account_token: 'tok' });
            return Promise.resolve({ status: 'restored', transactions: ['S1', 'S2'] });
        },
        refresh: () => { refreshed += 1; return Promise.resolve(); },
    });

    const result = await billing.runRestore(ctx, 'apple');
    assert.deepEqual(result, { status: 'restored', key: 'Purchases restored.' });
    assert.equal(refreshed, 1);
    assert.equal(calls.length, 3); // token + 2 verifies
    assert.deepEqual(calls[1].body, { signed_transaction: 'S1' });
    assert.deepEqual(calls[2].body, { signed_transaction: 'S2' });
    assert.deepEqual(acks, [
        { store: 'apple', product_id: null, signed_transaction: 'S1' },
        { store: 'apple', product_id: null, signed_transaction: 'S2' },
    ]);
});

test('restore with google posts each purchase and an empty restore says so', async () => {
    const { impl, calls } = tokenFetch({ '/google': { status: 200 } });
    const acks = [];
    const ctx = baseCtx({
        fetch: impl,
        request: (event, data) => {
            if (event === 'acknowledge') { acks.push(data); return Promise.resolve({}); }
            return Promise.resolve({ status: 'restored', purchases: [{ product_id: 'p1', purchase_token: 't1' }] });
        },
    });
    const result = await billing.runRestore(ctx, 'google');
    assert.equal(result.status, 'restored');
    assert.deepEqual(calls[1].body, { product_id: 'p1', purchase_token: 't1' });
    assert.deepEqual(acks, [{ store: 'google', product_id: 'p1', purchase_token: 't1' }]);

    const empty = baseCtx({ fetch: tokenFetch().impl, request: () => Promise.resolve({ status: 'empty' }) });
    assert.deepEqual(await billing.runRestore(empty, 'apple'), { status: 'empty', key: 'No purchases to restore.' });
});

test('restore keeps going after a per-item failure: first fails, second succeeds', async () => {
    const { impl, calls } = verifySeq([422, 200]);
    let refreshed = 0;
    const acks = [];
    const ctx = baseCtx({
        fetch: impl,
        request: (event, data) => {
            if (event === 'acknowledge') { acks.push(data); return Promise.resolve({}); }
            return Promise.resolve({ status: 'restored', transactions: ['S1', 'S2'] });
        },
        refresh: () => { refreshed += 1; return Promise.resolve(); },
    });

    const result = await billing.runRestore(ctx, 'apple');
    assert.deepEqual(result, { status: 'restored', key: 'Purchases restored.' });
    assert.equal(calls.length, 3, 'both transactions were verified');
    assert.equal(refreshed, 1);
    assert.deepEqual(acks, [{ store: 'apple', product_id: null, signed_transaction: 'S2' }], 'only the accepted one is acknowledged');
});

test('restore fails only when every item failed', async () => {
    const { impl, calls } = verifySeq([422, 503]);
    let refreshed = 0;
    const events = [];
    const ctx = baseCtx({
        fetch: impl,
        request: (event) => { events.push(event); return Promise.resolve({ status: 'restored', transactions: ['S1', 'S2'] }); },
        refresh: () => { refreshed += 1; return Promise.resolve(); },
    });

    const result = await billing.runRestore(ctx, 'apple');
    assert.deepEqual(result, { status: 'failed', key: 'Something went wrong. Please try again.' });
    assert.equal(calls.length, 3, 'the second verify still runs');
    assert.equal(refreshed, 0);
    assert.deepEqual(events, ['restore'], 'no acknowledge when nothing verified');
});

test('restore is mixed: successes win, failures and a network error are skipped', async () => {
    const { impl, calls } = verifySeq([200, 422, 'network', 200]);
    let refreshed = 0;
    const acks = [];
    const ctx = baseCtx({
        fetch: impl,
        request: (event, data) => {
            if (event === 'acknowledge') { acks.push(data); return Promise.resolve({}); }
            return Promise.resolve({ status: 'restored', transactions: ['T1', 'T2', 'T3', 'T4'] });
        },
        refresh: () => { refreshed += 1; return Promise.resolve(); },
    });

    const result = await billing.runRestore(ctx, 'apple');
    assert.equal(result.status, 'restored');
    assert.equal(calls.length, 5, 'every transaction was attempted');
    assert.equal(refreshed, 1);
    assert.deepEqual(acks, [
        { store: 'apple', product_id: null, signed_transaction: 'T1' },
        { store: 'apple', product_id: null, signed_transaction: 'T4' },
    ]);
});

test('restore says so when the store has nothing', async () => {
    const ctx = baseCtx({ fetch: verifySeq([]).impl, request: () => Promise.resolve({ status: 'restored', transactions: [] }) });
    assert.deepEqual(await billing.runRestore(ctx, 'apple'), { status: 'empty', key: 'No purchases to restore.' });
});

// ── overview ─────────────────────────────────────────────────────────────────

test('a 404, a 503 and a network error all show the unavailable message', async () => {
    for (const routes of [{ '/overview': { status: 404 } }, { '/overview': { status: 503 } }, { '/overview': { throw: true } }]) {
        const { impl } = fakeFetch(routes);
        const result = await billing.loadOverview(baseCtx({ fetch: impl }));
        assert.deepEqual(result, { ok: false, key: 'Billing is not available right now.' });
    }
});

test('a good overview is returned as data', async () => {
    const payload = { stores: { apple: true, google: false }, plans: [], status: null };
    const { impl } = fakeFetch({ '/overview': { status: 200, body: payload } });
    const result = await billing.loadOverview(baseCtx({ fetch: impl }));
    assert.equal(result.ok, true);
    assert.deepEqual(result.data, payload);
});

// ── busy survives a re-render ────────────────────────────────────────────────

function fakeElement(tag = 'div') {
    const node = {
        tagName: tag,
        className: '',
        textContent: '',
        hidden: false,
        disabled: false,
        type: '',
        children: [],
        attrs: Object.create(null),
        appendChild(child) { this.children.push(child); return child; },
        setAttribute(name, value) { this.attrs[name] = String(value); },
        getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null; },
        querySelector() { return null; },
        querySelectorAll() { return []; },
    };
    Object.defineProperty(node, 'descendants', {
        get() {
            const out = [];
            const walk = (n) => n.children.forEach((c) => { out.push(c); walk(c); });
            walk(node);
            return out;
        },
    });
    return node;
}

function fakeBillingRoot() {
    const root = fakeElement('div');
    const list = fakeElement('div');
    const loading = fakeElement('div');
    root.appendChild(list);
    root.appendChild(loading);
    root.querySelector = (sel) => ({ '[data-plans-list]': list, '[data-plans-loading]': loading }[sel] || null);
    root.querySelectorAll = (sel) => root.descendants.filter((n) => sel.split(',').some((token) => {
        const name = token.trim().replace(/^\[|\]$/g, '');
        return n.attrs[name] !== undefined;
    }));
    return { root, list, doc: { createElement: (tag) => fakeElement(tag) } };
}

const subscribeButton = (list) => list.descendants.find((n) => String(n.className).includes('owner-billing__subscribe'));

test('an overview reply during a buy keeps the subscribe buttons disabled', async () => {
    const { root, list, doc } = fakeBillingRoot();
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    const ctx = baseCtx({
        fetch: tokenFetch().impl,
        request: () => gate.then(() => ({ status: 'cancelled' })),
        setBusy: (value) => billing.setBusy(root, value),
    });

    const running = billing.runBuy(ctx, plan);
    await new Promise((resolve) => setImmediate(resolve));

    // The overview (or products) reply lands now and re-renders the plan list.
    billing.renderPlans(root, [plan], { doc, t, disabled: false });
    assert.ok(subscribeButton(list), 'a subscribe button was rendered');
    assert.equal(subscribeButton(list).disabled, true, 'stays disabled while the buy runs');

    release();
    await running;
    assert.equal(subscribeButton(list).disabled, false, 'enabled again once the buy ends');
});

// ── bridge adapter + CSP-cleanliness ─────────────────────────────────────────

test('makeBridge rejects when the page is not in a purchase-capable shell', async () => {
    await assert.rejects(() => billing.makeBridge(null)('buy', {}));
    await assert.rejects(() => billing.makeBridge({ send: () => '1', supportsComponent: () => false })('buy', {}));
});

test('makeBridge sends the purchase component event and resolves the reply data', async () => {
    let sent;
    const web = {
        supportsComponent: (component) => component === 'purchase',
        send: (message) => { sent = message; message.callback({ data: { prices: { p: '$9' } } }); return '7'; },
    };
    const reply = await billing.makeBridge(web)('products', { product_ids: ['p'] });
    assert.deepEqual(reply, { prices: { p: '$9' } });
    assert.equal(sent.component, 'purchase');
    assert.equal(sent.event, 'products');
    assert.deepEqual(sent.data, { product_ids: ['p'] });
});

test('no output and no source line carries an http(s) URL', () => {
    const outputs = [
        billing.statusView({ state: 'active', plan_name: 'Pro', expires_at: '2026-11-12T00:00:00+00:00' }, t, dateOf),
        billing.statusView(null, t, dateOf),
    ];
    const strings = [];
    outputs.forEach((view) => strings.push(view.badge || '', view.sentence || '', view.plan || ''));
    strings.push(billing.planPrice({ product_id: 'p', price: '29.00', currency: 'USD' }, {}));
    strings.push('Purchase complete.', 'Purchase cancelled.', 'Waiting for approval from the store.', 'Something went wrong. Please try again.', 'Purchases restored.', 'No purchases to restore.', 'Restoring…', 'Billing is not available right now.');

    for (const value of strings) {
        assert.doesNotMatch(String(value), /https?:\/\//i, `URL in output: ${value}`);
    }
    assert.doesNotMatch(SOURCE, /https?:\/\//i, 'billing.js must not build a store/checkout URL');
    assert.doesNotMatch(SOURCE, /href\s*=/i, 'billing.js must not render a link');
});
