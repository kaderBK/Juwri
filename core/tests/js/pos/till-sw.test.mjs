// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// Cold start of the register's service worker (PosController::serviceWorker): the first online visit must be enough
// for a till restarted offline to get its own store's register and menu back. The worker source is taken from the
// controller and run against fake caches / fetch / clients.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const php = readFileSync(new URL('../../../app/Modules/Pos/Http/Controllers/Staff/PosController.php', import.meta.url), 'utf8');
const OFFLINE = { title: 'Not ready', body: 'Connect once', dir: 'ltr', lang: 'en' };
const OFFLINE_ALL = { en: OFFLINE, ar: { title: 'غير جاهز', body: 'اتصل مرة', dir: 'rtl', lang: 'ar' } };
const src = php.slice(php.indexOf("<<<'JS'", php.indexOf('function serviceWorker')) + 7, php.indexOf('\nJS;', php.indexOf('function serviceWorker')))
    .replace('__OFFLINE__', JSON.stringify(OFFLINE))
    .replace('__OFFLINE_ALL__', JSON.stringify(OFFLINE_ALL));
const ORIGIN = 'https://shop.test';

function worker({ server, windows = [] }) {
    const store = new Map(); // cache name -> Map(url -> Response)
    let online = true;
    const listeners = {};
    const key = (r) => (typeof r === 'string' ? new URL(r, ORIGIN).toString() : r.url);
    const cacheApi = (name) => {
        if (!store.has(name)) store.set(name, new Map());
        const m = store.get(name);
        return { put: async (r, res) => { m.set(key(r), res); }, match: async (r) => { const hit = m.get(key(r)); return hit ? hit.clone() : undefined; }, addAll: async () => {} };
    };
    const self = {
        location: new URL(ORIGIN + '/pos/sw.js'),
        addEventListener: (t, fn) => { listeners[t] = fn; },
        skipWaiting: () => {},
        clients: { claim: async () => {}, matchAll: async () => windows.map((url) => ({ url })) },
    };
    const ctx = {
        self, URL, Response, setTimeout, clearTimeout, AbortController, Request, Headers, Promise, encodeURIComponent, String, Array, JSON, console,
        caches: {
            open: async (n) => cacheApi(n),
            keys: async () => [...store.keys()],
            delete: async (n) => store.delete(n),
            match: async (r) => { for (const n of store.keys()) { const hit = await cacheApi(n).match(r); if (hit) return hit; } return undefined; },
        },
        fetch: async (r) => {
            if (!online) throw new TypeError('Failed to fetch');
            return server(new URL(key(r)));
        },
    };
    vm.createContext(ctx);
    vm.runInContext(src, ctx);
    const run = async (type, ev) => { const waits = []; listeners[type]({ ...ev, waitUntil: (p) => waits.push(p), respondWith: (p) => waits.push(p) }); return Promise.all(waits); };
    return {
        store,
        online: () => { online = true; },
        offline: () => { online = false; },
        install: () => run('install', {}),
        activate: () => run('activate', {}),
        message: async (data) => { let reply; await run('message', { data, ports: [{ postMessage: (m) => { reply = reply || m; } }] }); return reply; },
        navigateWith: async (url, al) => (await run('fetch', { request: { method: 'GET', mode: 'navigate', url: ORIGIN + url, headers: new Headers({ 'accept-language': al }) } }))[0],
        navigate: async (url) => (await run('fetch', { request: { method: 'GET', mode: 'navigate', url: ORIGIN + url } }))[0],
        get: async (url) => (await run('fetch', { request: { method: 'GET', mode: 'cors', url: ORIGIN + url } }))[0],
    };
}

const server = (u) => {
    if (u.pathname === '/pos') {
        const id = u.searchParams.get('restaurant') || '7';
        return new Response('<html>register ' + id + '</html>', { status: 200, headers: { 'X-Pos-Restaurant': id } });
    }
    if (u.pathname.startsWith('/assets/')) return new Response('/* ' + u.pathname + ' */', { status: 200 });
    if (u.pathname.startsWith('/pos/offline/')) return new Response(JSON.stringify({ escrow: 'S3CRET-KEY' }), { status: 200 }); // even without no-store
    if (u.pathname === '/pos/print/config') return new Response('{}', { status: 200, headers: { 'Cache-Control': 'private, no-store' } });
    if (u.pathname === '/pos/menu') return new Response(JSON.stringify({ categories: [{ id: 1, items: [{ id: 9 }] }] }), { status: 200 });
    return new Response('nope', { status: 404 });
};

test('cold start: one online visit is enough for the register, the bare /pos and the menu offline', async () => {
    const sw = worker({ server });
    await sw.install(); await sw.activate();
    assert.equal((await sw.message({ type: 'pos-shell', url: ORIGIN + '/pos?restaurant=3', warm: [ORIGIN + '/pos/menu?restaurant=3', ORIGIN + '/assets/pos/pos-terminal.js?v=1'] })).ok, true);
    sw.offline();
    assert.match(await (await sw.get('/assets/pos/pos-terminal.js?v=1')).text(), /pos-terminal/, 'scripts the first visit loaded, versioned');
    const reg = await sw.navigate('/pos?restaurant=3');
    assert.equal(reg.status, 200);
    assert.match(await reg.text(), /register 3/);
    assert.match(await (await sw.navigate('/pos')).text(), /register 3/, 'bare /pos opens the store this till last had');
    const menu = await sw.get('/pos/menu?restaurant=3');
    assert.equal((await menu.json()).categories[0].items[0].id, 9);
    assert.equal((await sw.navigate('/pos?restaurant=4')).status, 503, 'never another store\'s register');
});

test('a login redirect or error is never kept, and the page is told it is not ready', async () => {
    const sw = worker({ server: () => new Response('login', { status: 200 }) }); // no X-Pos-Restaurant: not a register
    await sw.install(); await sw.activate();
    assert.equal((await sw.message({ type: 'pos-shell', url: ORIGIN + '/pos?restaurant=3', warm: [ORIGIN + '/pos/menu?restaurant=3'] })).ok, false);
    sw.offline();
    assert.equal((await sw.navigate('/pos?restaurant=3')).status, 503);
});

test('other origins and non-register paths are ignored', async () => {
    const seen = [];
    const sw = worker({ server: (u) => { seen.push(u.toString()); return server(u); } });
    await sw.install(); await sw.activate();
    assert.equal((await sw.message({ type: 'pos-shell', url: ORIGIN + '/pos?restaurant=3', warm: ['https://evil.test/pos/menu', ORIGIN + '/admin/secret'] })).ok, true);
    assert.deepEqual(seen, [ORIGIN + '/pos?restaurant=3']);
});

test('activation keeps the shell of a register tab opened before the worker existed', async () => {
    const sw = worker({ server, windows: [ORIGIN + '/pos?restaurant=5', ORIGIN + '/dashboard'] });
    await sw.install(); await sw.activate();
    sw.offline();
    assert.match(await (await sw.navigate('/pos?restaurant=5')).text(), /register 5/);
});

test('keys, tokens and till status are never cached: network only, and offline they fail (the till stays locked)', async () => {
    const sw = worker({ server });
    await sw.install(); await sw.activate();
    for (const u of ['/pos/offline/devices/escrow?restaurant=3', '/pos/offline/devices/status?restaurant=3', '/pos/offline/snapshot?restaurant=3&branch=1', '/pos/offline/zatca/status?restaurant=3']) {
        assert.equal(await sw.get(u), undefined, u + ' is not handled by the worker (the browser goes to the network)');
    }
    await sw.get('/pos/print/config?restaurant=3'); // a no-store response
    // the page cannot get them cached through the warm-up either
    assert.equal((await sw.message({ type: 'pos-shell', url: ORIGIN + '/pos?restaurant=3', warm: [ORIGIN + '/pos/offline/devices/escrow?restaurant=3', ORIGIN + '/pos/menu?restaurant=3'] })).ok, true);
    const cached = [...sw.store.values()].flatMap((m) => [...m.keys()]);
    assert.ok(cached.some((u) => u.includes('/pos/menu')), 'the menu is cached');
    assert.deepEqual(cached.filter((u) => u.includes('/pos/offline/') || u.includes('/pos/print/config')), [], 'no key, token, status or no-store response in any cache');
    sw.offline();
    assert.equal(await sw.get('/pos/offline/devices/escrow?restaurant=3'), undefined, 'offline: nothing serves the escrow');
});

test('the precache carries the offline pricing scripts and the cache is bumped to pos-v16', () => {
    const cache = /const CACHE = '([^']+)'/.exec(src);
    assert.equal(cache[1], 'pos-v16', 'the cache name changes with the shell, so registers never serve the old one');
    const shell = src.slice(src.indexOf('const SHELL = ['), src.indexOf('];', src.indexOf('const SHELL = [')));
    assert.ok(shell.includes("'/assets/pos/till-calculator.js'"), 'the shared calculator is precached');
    assert.ok(shell.includes("'/assets/pos/pos-offline.js'"), 'the offline register module is precached');
});

test('the offline page wraps its text in <html lang dir> and defaults to the current locale', async () => {
    const sw = worker({ server: () => new Response('login', { status: 200 }) }); // no shell kept → the "connect once" page
    await sw.install(); await sw.activate();
    sw.offline();
    const page = await sw.navigate('/pos?restaurant=9');
    assert.equal(page.status, 503);
    const html = await page.text();
    assert.match(html, /^<!doctype html><html lang="en" dir="ltr">/);
    assert.match(html, /<head>[\s\S]*<\/head><body>[\s\S]*<\/body><\/html>$/);
    assert.match(html, /Not ready/);
});

test('the worker carries the per-locale offline texts (OFFLINE_ALL) and picks by the first path segment', () => {
    assert.match(src, /const OFFLINE_ALL = \{/);
    assert.match(src, /accept-language/);
    assert.match(src, /const OFFLINE = (\{[^\n]*\});/);
});

test('the offline page follows Accept-Language (ar -> rtl Arabic text), else the registering locale', async () => {
    const sw = worker({ server: () => new Response('login', { status: 200 }) });
    await sw.install(); await sw.activate();
    sw.offline();
    const get = async (al) => (await (await sw.navigateWith('/pos?restaurant=9', al)).text());
    const ar = await get('ar-SA,ar;q=0.9,en;q=0.5');
    assert.match(ar, /<html lang="ar" dir="rtl">/);
    assert.match(ar, /غير جاهز/);
    assert.match(await get('fr-FR,en;q=0.8'), /<html lang="en" dir="ltr">/, 'first carried tag wins');
    assert.match(await get('xx'), /<html lang="en" dir="ltr">/, 'unknown -> the registering locale');
    assert.match(await get('constructor'), /<html lang="en" dir="ltr">/, 'no prototype keys');
});
