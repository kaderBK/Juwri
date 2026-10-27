// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Owner app — in-app purchase billing page (server item P5-22).
 * Loaded only on the billing page (owner.owner-app.billing), inside or outside
 * the native shell. Talks to the native `purchase` bridge component
 * (assets/owner-app/bridge.js) for prices, purchases and restores; talks to the
 * server for the plan overview, the account token and the store verification.
 *
 * No inline handlers (CSP script-src-attr 'none'): every click is delegated from
 * the page root. All user text comes from the server through data-i18n.
 *
 * UMD: required from node:test as a CommonJS module (its pure pieces), or run in
 * the browser where it boots itself.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.OwnerAppBilling = api;
        api.boot();
    }
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    // ── text ─────────────────────────────────────────────────────────────────

    // English source string -> server translation, from data-i18n. The key is
    // also the fallback, so a missing entry degrades to the source string.
    function translator(i18n) {
        var dict = i18n || {};
        return function (key) {
            return Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : key;
        };
    }

    function fill(template, params) {
        return String(template == null ? '' : template).replace(/:([a-z_]+)/g, function (whole, name) {
            return params && Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : whole;
        });
    }

    // Dates in the document's language (the overview sends ISO 8601 UTC).
    function formatDate(iso, locale) {
        if (!iso) return '';
        try {
            return new Intl.DateTimeFormat(locale || undefined, { year: 'numeric', month: 'long', day: 'numeric' }).format(new Date(iso));
        } catch (e) {
            return String(iso);
        }
    }

    // status -> { tone, badge, sentence, plan } for the "Your plan" card.
    // `status` is the overview's status object, or null/undefined for none.
    function statusView(status, t, dateOf) {
        var date = (status && status.expires_at) ? dateOf(status.expires_at) : '';
        var view = { tone: 'neutral', badge: t('No active subscription'), sentence: null, plan: null };
        if (!status || !status.state) return view;

        switch (status.state) {
            case 'active':
                view = { tone: 'success', badge: t('Active'), sentence: fill(t('Renews on :date'), { date: date }) };
                break;
            case 'grace':
                view = { tone: 'warning', badge: t('Grace period'), sentence: fill(t('Payment problem. Your plan stays active until :date while the store retries.'), { date: date }) };
                break;
            case 'cancelling':
                view = { tone: 'warning', badge: t('Cancels at period end'), sentence: fill(t('Cancelled. Your plan stays active until :date.'), { date: date }) };
                break;
            case 'expired':
                view = { tone: 'danger', badge: t('Expired'), sentence: fill(t('Expired on :date.'), { date: date }) };
                break;
            case 'refunded':
                view = { tone: 'danger', badge: t('Refunded'), sentence: t('Refunded. This plan is no longer active.') };
                break;
            case 'revoked':
                view = { tone: 'danger', badge: t('Removed'), sentence: t('The store removed access to this plan.') };
                break;
        }

        view.plan = status.plan_name || null;
        return view;
    }

    function intervalLabel(interval, t) {
        return (interval === 'ANNUAL' || interval === 'YEARLY') ? t('per year') : t('per month');
    }

    // Native localized price when the store gave one, else the server price.
    function planPrice(plan, nativePrices) {
        if (nativePrices && plan && nativePrices[plan.product_id]) return nativePrices[plan.product_id];
        if (!plan) return '';
        return String(plan.price || '') + ' ' + String(plan.currency || '');
    }

    // Which store this device's shell uses; null outside the app (unknown platform).
    function storeForPlatform(platform) {
        if (platform === 'ios') return 'apple';
        if (platform === 'android') return 'google';
        return null;
    }

    // Inside the app: only the device store's plans. Outside (store null): all.
    function visiblePlans(plans, store) {
        var list = (plans || []).slice();
        if (!store) return list;
        return list.filter(function (plan) { return plan && plan.store === store; });
    }

    // ── server calls ─────────────────────────────────────────────────────────

    function verifyUrl(store, endpoints) {
        if (store === 'google') return endpoints.google;
        return endpoints.apple;
    }

    // The exact body each store's verify endpoint validates.
    function verifyBody(store, reply) {
        if (store === 'google') {
            return { product_id: reply.product_id, purchase_token: reply.purchase_token };
        }
        return { signed_transaction: reply.signed_transaction };
    }

    function loadOverview(ctx) {
        return ctx.fetch(ctx.endpoints.overview, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        }).then(function (res) {
            if (!res || !res.ok) return { ok: false, key: 'Billing is not available right now.' };
            return res.json().then(function (data) { return { ok: true, data: data }; });
        }).catch(function () {
            return { ok: false, key: 'Billing is not available right now.' };
        });
    }

    function getAccountToken(ctx) {
        return ctx.fetch(ctx.endpoints.token, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        }).then(function (res) {
            if (!res || !res.ok) throw new Error('account_token');
            return res.json();
        }).then(function (body) {
            var token = body && body.account_token;
            if (!token) throw new Error('account_token'); // missing/empty token: a failure, never sent to native
            return token;
        });
    }

    function postVerify(ctx, store, reply) {
        return ctx.fetch(verifyUrl(store, ctx.endpoints), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': ctx.csrf || '',
            },
            body: JSON.stringify(verifyBody(store, reply)),
        }).then(function (res) { return !!(res && res.ok); });
    }

    // The data the native side needs to finish/acknowledge exactly the purchase
    // the verify POST accepted. Apple has no parsed transaction id on the web
    // side (the signed transaction is not decoded in JS), so the signed string
    // itself is sent; Google sends the purchase token.
    function acknowledgeBody(store, productId, reply) {
        if (store === 'google') {
            return {
                store: 'google',
                product_id: (reply && reply.product_id) || productId || null,
                purchase_token: reply && reply.purchase_token,
            };
        }
        return {
            store: 'apple',
            product_id: productId || null,
            signed_transaction: reply && reply.signed_transaction,
        };
    }

    // Tell the shell a transaction is accepted, so it can finish it. Sent ONLY
    // after the verify POST returned 2xx. Fire-and-forget: the native side
    // finishes it and does not need to reply.
    function acknowledge(ctx, store, productId, reply) {
        try {
            var sent = ctx.request('acknowledge', acknowledgeBody(store, productId, reply));
            if (sent && typeof sent.catch === 'function') sent.catch(function () {});
        } catch (e) { /* finishing is best-effort here; the transaction stays open */ }
    }

    // Native bridge -> promise of the reply's data. `web` is window.HotwireNative.web.
    function makeBridge(web) {
        return function request(event, data) {
            return new Promise(function (resolve, reject) {
                var unavailable = function () { var e = new Error('bridge_unavailable'); e.bridge = true; return e; };
                if (!web || typeof web.send !== 'function') { reject(unavailable()); return; }
                if (typeof web.supportsComponent === 'function' && !web.supportsComponent('purchase')) { reject(unavailable()); return; }
                var id = web.send({
                    component: 'purchase',
                    event: event,
                    data: data || {},
                    callback: function (message) { resolve((message && message.data) || {}); },
                });
                if (id === null || id === undefined) reject(unavailable());
            });
        };
    }

    // ── buy / restore orchestration ──────────────────────────────────────────

    var WRONG = 'Something went wrong. Please try again.';

    function runBuy(ctx, plan) {
        ctx.setBusy(true);
        return getAccountToken(ctx).then(function (token) {
            return ctx.request('buy', { product_id: plan.product_id, store: plan.store, account_token: token });
        }).then(function (reply) {
            var status = reply && reply.status;
            if (status === 'cancelled') return { status: 'cancelled', key: 'Purchase cancelled.' };
            if (status === 'pending') return { status: 'pending', key: 'Waiting for approval from the store.' };
            if (status !== 'purchased') return { status: 'failed', key: WRONG };
            return postVerify(ctx, plan.store, reply).then(function (ok) {
                if (!ok) return { status: 'failed', key: WRONG };
                acknowledge(ctx, plan.store, plan.product_id, reply);
                return Promise.resolve(ctx.refresh()).then(function () {
                    return { status: 'purchased', key: 'Purchase complete.' };
                });
            });
        }).catch(function () {
            return { status: 'failed', key: WRONG };
        }).then(function (result) { ctx.setBusy(false); return result; });
    }

    function runRestore(ctx, store) {
        ctx.setBusy(true);
        var fail = { status: 'failed', key: WRONG };
        if (!store) { ctx.setBusy(false); return Promise.resolve(fail); }

        return getAccountToken(ctx).then(function (token) {
            return ctx.request('restore', { store: store, account_token: token });
        }).then(function (reply) {
            var status = reply && reply.status;
            if (status === 'empty') return { status: 'empty', key: 'No purchases to restore.' };
            if (status !== 'restored') return fail;

            var items = store === 'google' ? (reply.purchases || []) : (reply.transactions || []);
            if (!items.length) return { status: 'empty', key: 'No purchases to restore.' };

            // Verify every item and keep going after a per-item failure (a 422,
            // a 503, a network error): one bad item must not hide the rest.
            var verified = 0;
            var chain = Promise.resolve();
            items.forEach(function (item) {
                chain = chain.then(function () {
                    var record = store === 'google'
                        ? { product_id: item.product_id, purchase_token: item.purchase_token }
                        : { signed_transaction: item };
                    return postVerify(ctx, store, record).then(function (ok) {
                        if (ok) { verified++; acknowledge(ctx, store, item.product_id, record); }
                    }, function () { /* this item failed; the others still run */ });
                });
            });
            return chain.then(function () {
                if (verified === 0) return fail; // every item failed
                return Promise.resolve(ctx.refresh()).then(function () {
                    return { status: 'restored', key: 'Purchases restored.' };
                });
            });
        }).catch(function () {
            return fail;
        }).then(function (result) { ctx.setBusy(false); return result; });
    }

    // ── page rendering ───────────────────────────────────────────────────────

    function renderStatus(root, view) {
        var badge = root.querySelector('[data-status-badge]');
        if (badge) {
            badge.textContent = view.badge;
            badge.setAttribute('data-tone', view.tone);
        }
        var plan = root.querySelector('[data-status-plan]');
        if (plan) {
            plan.textContent = view.plan || '';
            plan.hidden = !view.plan;
        }
        var sentence = root.querySelector('[data-status-sentence]');
        if (sentence) {
            sentence.textContent = view.sentence || '';
            sentence.hidden = !view.sentence;
        }
    }

    function renderPlans(root, plans, opts) {
        opts = opts || {};
        var doc = opts.doc || document;
        var t = opts.t || function (k) { return k; };
        var list = root.querySelector('[data-plans-list]');
        if (!list) return;
        list.textContent = '';
        // A re-render (overview / products reply) during a buy or restore must not
        // re-enable the buttons: the busy flag lives on the root and is read here.
        var busy = !!(root.getAttribute && root.getAttribute('data-billing-busy') === '1');

        plans.forEach(function (plan) {
            var card = doc.createElement('div');
            card.className = 'owner-billing__plan';

            var head = doc.createElement('div');
            head.className = 'owner-billing__plan-head';
            var name = doc.createElement('span');
            name.className = 'owner-billing__plan-name';
            name.textContent = plan.name || '';
            var interval = doc.createElement('span');
            interval.className = 'owner-billing__plan-interval';
            interval.textContent = intervalLabel(plan.interval, t);
            head.appendChild(name);
            head.appendChild(interval);

            var price = doc.createElement('span');
            price.className = 'owner-billing__plan-price';
            price.textContent = planPrice(plan, opts.nativePrices);

            card.appendChild(head);
            card.appendChild(price);

            var isCurrent = opts.activePlanId != null && Number(opts.activePlanId) === Number(plan.plan_id);
            if (!isCurrent) {
                var button = doc.createElement('button');
                button.type = 'button';
                button.className = 'ui-btn ui-btn--block owner-billing__subscribe';
                button.setAttribute('data-buy', '');
                button.setAttribute('data-product', plan.product_id);
                button.setAttribute('data-store', plan.store);
                button.textContent = t('Subscribe');
                if (opts.disabled || busy) button.disabled = true;
                card.appendChild(button);
            }

            list.appendChild(card);
        });

        var loading = root.querySelector('[data-plans-loading]');
        if (loading) loading.hidden = true;
        list.hidden = false;
    }

    // Disable every buy/restore control while a native call is in flight, and
    // keep them disabled outside the app (no store to buy from).
    function setBusy(root, busy) {
        root.setAttribute('data-billing-busy', busy ? '1' : '0');
        var noStore = root.getAttribute('data-billing-nostore') === '1';
        root.querySelectorAll('[data-buy], [data-restore]').forEach(function (el) {
            el.disabled = !!busy || noStore;
        });
    }

    function showUnavailable(root, t) {
        var main = root.querySelector('[data-billing-main]');
        var error = root.querySelector('[data-billing-error]');
        if (main) main.hidden = true;
        if (error) {
            var message = error.querySelector('[data-billing-error-text]');
            if (message) message.textContent = t('Billing is not available right now.');
            error.hidden = false;
        }
        setBusy(root, true);
    }

    function toast(kind, message) {
        if (typeof window !== 'undefined' && typeof window.dashToast === 'function') {
            window.dashToast(kind, message);
        }
    }

    function resultKind(result) {
        if (!result) return 'danger';
        if (result.status === 'purchased' || result.status === 'restored') return 'success';
        if (result.status === 'failed') return 'danger';
        return 'info';
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }

    function init(root) {
        var doc = root.ownerDocument || document;
        var t;
        try {
            t = translator(JSON.parse(root.getAttribute('data-i18n') || '{}'));
        } catch (e) {
            t = translator({});
        }

        var endpoints = {
            overview: root.getAttribute('data-endpoint-overview') || 'owner-app/billing/overview',
            token: root.getAttribute('data-endpoint-token') || 'owner-app/billing/account-token',
            apple: root.getAttribute('data-endpoint-apple') || 'owner-app/billing/apple',
            google: root.getAttribute('data-endpoint-google') || 'owner-app/billing/google',
        };
        var locale = doc.documentElement.getAttribute('lang') || (typeof navigator !== 'undefined' ? navigator.language : undefined);
        var platform = doc.documentElement.getAttribute('data-bridge-platform');
        var store = storeForPlatform(platform);
        var web = (typeof window !== 'undefined' && window.HotwireNative) ? window.HotwireNative.web : null;

        var state = { plans: [], status: null, nativePrices: {} };
        root.setAttribute('data-billing-nostore', store ? '0' : '1');
        var ctx = {
            fetch: (typeof window !== 'undefined' && window.fetch) ? window.fetch.bind(window) : fetch,
            endpoints: endpoints,
            csrf: csrfToken(),
            request: makeBridge(web),
            refresh: function () { return refresh(); },
            setBusy: function (busy) { setBusy(root, busy); },
        };

        function render() {
            var view = statusView(state.status, t, function (iso) { return formatDate(iso, locale); });
            renderStatus(root, view);
            var current = state.status && state.status.plan_id != null ? state.status.plan_id : null;
            renderPlans(root, visiblePlans(state.plans, store), {
                store: store,
                nativePrices: state.nativePrices,
                activePlanId: current,
                disabled: !store,
                t: t,
                doc: doc,
            });
        }

        function refresh() {
            return loadOverview(ctx).then(function (result) {
                if (!result.ok) { showUnavailable(root, t); return; }
                var data = result.data || {};
                state.plans = data.plans || [];
                state.status = data.status || null;
                state.nativePrices = {};
                render();

                var ids = visiblePlans(state.plans, store).map(function (p) { return p.product_id; });
                if (!store || !ids.length) return;
                ctx.request('products', { product_ids: ids }).then(function (reply) {
                    if (reply && reply.prices) { state.nativePrices = reply.prices; render(); }
                }).catch(function () { /* server prices stay */ });
            });
        }

        function onBuy(button) {
            var productId = button.getAttribute('data-product');
            var plan = null;
            state.plans.forEach(function (p) { if (p.product_id === productId) plan = p; });
            if (!plan) return;
            runBuy(ctx, plan).then(function (result) {
                toast(resultKind(result), t(result.key));
            });
        }

        function onRestore() {
            toast('info', t('Restoring…'));
            runRestore(ctx, store).then(function (result) {
                toast(resultKind(result), t(result.key));
            });
        }

        root.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) return;
            var buy = target.closest('[data-buy]');
            if (buy) { event.preventDefault(); if (!buy.disabled) onBuy(buy); return; }
            var restore = target.closest('[data-restore]');
            if (restore) { event.preventDefault(); if (!restore.disabled) onRestore(); }
        });

        setBusy(root, false); // apply the outside-app disabled state before any call
        refresh();
    }

    function initAll(doc) {
        doc.querySelectorAll('[data-owner-billing]').forEach(function (root) {
            if (root.getAttribute('data-billing-ready') === '1') return;
            root.setAttribute('data-billing-ready', '1');
            init(root);
        });
    }

    function boot() {
        if (typeof document === 'undefined') return;
        var start = function () { initAll(document); };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start, { once: true });
        } else {
            start();
        }
        if (!boot.__bound) {
            boot.__bound = true;
            document.addEventListener('turbo:load', start); // Turbo swaps the body; re-init the fresh root
        }
    }

    // Export seam kept small and explicit for the unit tests.
    return {
        translator: translator,
        fill: fill,
        formatDate: formatDate,
        statusView: statusView,
        intervalLabel: intervalLabel,
        planPrice: planPrice,
        storeForPlatform: storeForPlatform,
        visiblePlans: visiblePlans,
        verifyUrl: verifyUrl,
        verifyBody: verifyBody,
        loadOverview: loadOverview,
        getAccountToken: getAccountToken,
        postVerify: postVerify,
        acknowledgeBody: acknowledgeBody,
        makeBridge: makeBridge,
        runBuy: runBuy,
        runRestore: runRestore,
        setBusy: setBusy,
        renderPlans: renderPlans,
        renderStatus: renderStatus,
        boot: boot,
    };
}));
