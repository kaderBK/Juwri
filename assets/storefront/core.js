// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* juwri storefront core — entry point (plain ES module; no Livewire, no Alpine, no bundler).
   Loaded once by the page partial; reads the page's boot data (an inline JSON block), starts the cart, the offline
   queue, sheets, the announcer, the kiosk timer, registers the per-store service worker and publishes
   `window.Juwri` (the only way a theme may change cart state) plus `juwri:*` events on `document`.

   Boot data: <script type="application/json" data-juwri-boot>{ store:{slug}, endpoints:{quote,place,home,…},
   context:{order_type,kiosk,…}, offline_queue:{enabled,order_types,expiry_min}, i18n:{…}, sw:{url,scope}|null }</script> */
import { createAnnouncer } from './announcer.js';
import { createApi } from './api.js';
import { createCart } from './cart.js';
import { dbName, openStore } from './db.js';
import { bindDom } from './dom.js';
import { startKiosk } from './kiosk.js';
import { startKioskLoyalty } from './loyalty.js';
import { createQueue } from './queue.js';
import { createSheets } from './sheets.js';
import { emit } from './util.js';

export const VERSION = '1.0.0';

export function readBoot(doc = globalThis.document) {
    const el = doc.querySelector('script[type="application/json"][data-juwri-boot]');
    if (!el) return null;
    try { return JSON.parse(el.textContent); } catch (e) { return null; }
}

export async function start(boot, env = {}) {
    const doc = env.doc || globalThis.document;
    const loc = env.location || globalThis.location;
    const i18n = boot.i18n || {};
    const text = (key, fallback) => i18n[key] || fallback;
    const announce = createAnnouncer(doc);
    const db = await openStore(dbName(loc.host, boot.store.slug));
    const api = createApi({ homeUrl: boot.endpoints.home });
    const queue = createQueue({ boot, db, api, announce, text });
    const cart = createCart({ boot, db, api, queue, announce, text });
    const sheets = createSheets(doc);
    sheets.bind();
    await cart.ready();
    bindDom({ doc, cart, queue, text, announce, home: boot.endpoints.home, onPlaced: (result) => emit('order:result', result) });
    startKiosk({
        kiosk: boot.context && boot.context.kiosk, cart, home: boot.endpoints.kiosk_start || boot.endpoints.home, announce, text, doc,
        endpoints: boot.endpoints, slug: boot.store.slug, queueEnabled: !!(boot.offline_queue && boot.offline_queue.enabled),
    });
    startKioskLoyalty({ kiosk: boot.context && boot.context.kiosk, endpoints: boot.endpoints, api, cart, doc }); // item 26
    await queue.start();

    if (boot.sw && 'serviceWorker' in navigator) {
        // One worker per store address. Scope "/" is shared with the owner dashboard's worker: leave that one alone;
        // a legacy menu worker left at "/" on the shared path-form host is removed (J28).
        navigator.serviceWorker.getRegistration('/').then((reg) => {
            const current = reg && (reg.active || reg.waiting || reg.installing);
            const rootScope = reg && reg.scope === loc.origin + '/';
            if (boot.sw.scope !== '/') {
                if (rootScope && current && current.scriptURL.indexOf('/sw-menu.js') !== -1) reg.unregister();
            } else if (current && current.scriptURL.indexOf('/dashboard-sw.js') !== -1) {
                return null;
            }
            return navigator.serviceWorker.register(boot.sw.url, { scope: boot.sw.scope });
        }).catch(() => {});
        navigator.serviceWorker.addEventListener('message', (e) => {
            if (e.data && e.data.type === 'juwri:queue-dropped' && e.data.count > 0) {
                emit('queue:dropped', { count: e.data.count, reason: 'disabled' });
                announce(text('waiting_orders_were_removed_because_offline_ordering_was_switched_off', 'Waiting orders were removed because offline ordering was switched off.'));
                emit('queue:changed', { dropped: e.data.count });
            }
        });
    }

    // What a theme may call. No money, no state objects to mutate.
    const publicCart = Object.freeze({
        add: cart.add, update: cart.update, remove: cart.remove, clear: cart.clear,
        setOrderType: cart.setOrderType, setCoupon: cart.setCoupon, setTip: cart.setTip, setBranch: cart.setBranch, setShipping: cart.setShipping, setExtras: cart.setExtras,
        requote: () => cart.requote(), preview: cart.preview, place: cart.place, snapshot: cart.snapshot, count: cart.count,
    });
    const publicQueue = Object.freeze({
        list: queue.list, confirm: queue.confirm, retry: queue.retry, dismiss: queue.dismiss, enabled: queue.enabled,
    });
    const juwri = {
        version: VERSION, cart: publicCart, queue: publicQueue, announce, hooks: {},
        sheets: Object.freeze({ open: sheets.open, close: sheets.close, isOpen: sheets.isOpen }),
    };
    globalThis.Juwri = Object.assign(globalThis.Juwri || {}, juwri);
    emit('ready', { version: VERSION });
    return globalThis.Juwri;
}

if (typeof document !== 'undefined') {
    const boot = readBoot(document);
    if (boot) start(boot);
}
