// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P4-19 — Safari / iPhone engine: the critical journeys in Playwright WebKit (E2E_RUN_P4=1 and E2E_WEBKIT=1; run through
 * tools/webkit_e2e.sh on the runner, which unpacks WebKit's libraries in user space — no root, no paid service).
 *
 * In an iPhone-14 WebKit context, for each of the five kit themes (table, brew, aisle, basket, book) on its demo store: the storefront loads
 * with no JavaScript error, nothing overflows the phone width, the theme's own add-to-cart control is tried (reported), a guest checkout
 * is placed from inside the WebKit page and its tracking page opens clean. Desktop WebKit: the owner signs in through the real login form and
 * reaches the dashboard; a cashier signs in to the staff app, opens the POS and rings up a sale (the order exists). The booking theme loads
 * its service/slot UI. What WebKit cannot show headless on Linux (Apple Pay, camera, push, iOS safe-area / keyboard behaviour, real touch
 * gestures, PWA install) is listed in the README for a manual iPhone check.
 */

const P = require('../lib/p4');

const { L } = P;

// Playwright's WebKit stalls the page's script thread while the dashboard's service worker registers (found by bisecting the owner-ui
// scripts: pwa.js; Chromium is fine), so service workers are blocked here; the PWA / service worker is on the manual iPhone list.
const NO_SW = process.env.E2E_WEBKIT_SW === '1' ? {} : { serviceWorkers: 'block' };

const THEMES = [
  { theme: 'table', slug: 'crave-demo', seeder: 'ThemeShowcaseSeeder' },
  { theme: 'brew', slug: 'bloom-coffee-demo', seeder: 'KitDemoSeeder' },
  { theme: 'aisle', slug: 'dune-and-co-demo', seeder: 'KitDemoSeeder' },
  { theme: 'basket', slug: 'fresh-mart-demo', seeder: 'KitDemoSeeder' },
  { theme: 'book', slug: 'sidra-salon-demo', seeder: 'BookDemoSeeder' },
];
const ADD_SELECTORS = ['[data-add-to-cart]', '[data-testid="add-to-cart"]', '#addToCart', 'button[aria-label*="Add" i]', '.add-btn', 'button.add', '[data-action="add"]'];

module.exports = async function p4Webkit({ log }) {
  // a viewport screenshot with a deadline: WebKit's software renderer can hang on a full-page capture of a long dashboard
  const shot = (page, name) => page.screenshot({ path: `${process.env.E2E_SHOT_DIR || '/tmp'}/${name}.png`, timeout: 15000 }).catch(() => {});
  if (process.env.E2E_WEBKIT !== '1') return L.skip(log, 'p4-webkit', 'E2E_WEBKIT=1 not set (run through tools/webkit_e2e.sh on the runner)');
  let playwright;
  try { playwright = require('playwright-core'); } catch (_e) { return L.skip(log, 'p4-webkit', 'playwright-core is not installed'); }
  const check = L.checker('p4-webkit');
  const base = L.baseUrl();
  const engine = process.env.E2E_WEBKIT_ENGINE === 'chromium' ? 'chromium' : 'webkit'; // chromium = a control run to tell a WebKit-only problem from a general one
  const browser = await playwright[engine].launch(engine === 'chromium' ? { executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined } : {});
  const errorsOf = new Map();
  const watch = (page, name) => {
    errorsOf.set(name, []);
    page.addInitScript(() => {
      window.__csp = [];
      document.addEventListener('securitypolicyviolation', (e) => window.__csp.push(`${e.effectiveDirective} blocked=${e.blockedURI} sample=${String(e.sample || '').slice(0, 90)} src=${e.sourceFile}:${e.lineNumber}`));
    });
    page.on('pageerror', (e) => errorsOf.get(name).push(`pageerror: ${String(e.message || e).slice(0, 160)}`));
    page.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource|net::ERR|ERR_|Failed to preconnect|TLS support is not available/.test(m.text())) errorsOf.get(name).push(`console: ${m.text().slice(0, 420)} @ ${(m.location() || {}).url || ''}`); });
  };
  const consent = (ctx) => ctx.addCookies([{ name: 'site_consent', value: 'v1.a0.m0', url: base }]);
  const created = [];
  const notes = [];
  try {
    // ── the five kit themes on a phone ─────────────────────────────────────────────────────────────────────────────────────
    const phone = playwright.devices['iPhone 14'];
    for (const t of THEMES.filter((x) => !process.env.E2E_WEBKIT_THEMES || process.env.E2E_WEBKIT_THEMES.split(',').includes(x.theme))) {
      if (t.theme === 'aisle') P.tinker(require('fs').readFileSync(require('path').join(__dirname, '..', 'lib', 'seed-dune.php'), 'utf8'), null); // Dune & Co. (test fixture seeder)
      else L.artisan(['db:seed', `--class=Juwri\\Modules\\Storefront\\Database\\Seeders\\${t.seeder}`, '--force']);
      const id = L.dbq('select id, user_id from stores where slug = ?', [t.slug])[0];
      if (!id) { check.ok(false, `${t.theme}: the demo store ${t.slug} exists`); continue; }
      P.tinker(`
        \\App\\Models\\StoreOption::updateStoreOption(${id[0]}, 'store_template', '${t.theme}');
        \\App\\Models\\StoreOption::updateStoreOption(${id[0]}, 'require_phone_verification', '0');
        \\Juwri\\Core\\Models\\EntitlementOverride::updateOrCreate(['user_id' => ${id[1]}, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
        $h = []; foreach (['mon','tue','wed','thu','fri','sat','sun'] as $d) { $h[$d] = ['open' => '00:00', 'close' => '23:59']; }
        \\Juwri\\Core\\Models\\Branch::where('store_id', ${id[0]})->update(['hours' => json_encode($h), 'accepting_orders' => 1, 'active' => 1]);
        \\Illuminate\\Support\\Facades\\Cache::flush();
        echo 'OK=1';`, 'OK');
      const ctx = await browser.newContext({ ...NO_SW, ...phone, locale: 'en-US' });
      await consent(ctx);
      const page = await ctx.newPage();
      watch(page, t.theme);
      const res = await page.goto(`${base}/${t.slug}`, { waitUntil: 'load', timeout: 45000 });
      check.ok(res && res.status() < 400, `${t.theme}: the storefront loads in WebKit (${res && res.status()})`);
      await page.waitForTimeout(1500);
      check.ok((await page.evaluate(() => document.body.innerText.trim().length)) > 40, `${t.theme}: it renders content`);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      check.ok(overflow <= 2, `${t.theme}: nothing overflows the phone width (${overflow}px)`);
      let clicked = null;
      for (const sel of ADD_SELECTORS) {
        const el = page.locator(sel).first();
        if (await el.count() && await el.isVisible().catch(() => false)) { await el.click({ timeout: 4000 }).then(() => { clicked = sel; }).catch(() => {}); if (clicked) break; }
      }
      notes.push(`${t.theme}: add-to-cart control ${clicked ? `clicked (${clicked})` : 'not found by the generic selectors (covered by the theme\'s own Chrome journey)'}`);
      await shot(page, `p4-webkit-${t.theme}`);

      if (t.theme !== 'book') {
        // a guest checkout from inside WebKit (the theme's own fetch / FormData / cookies run in Safari's engine)
        const items = L.dbq('select id from items where store_id = ? and active = 1 and deleted_at is null order by id limit 12', [id[0]]).map((r) => +r[0]);
        let order = null;
        for (const item of items) {
          const r = await page.evaluate(async ([storeId, itemId]) => {
            const csrf = (document.querySelector('meta[name=csrf-token]') || {}).content || (document.querySelector('input[name=_token]') || {}).value || '';
            const fd = new FormData();
            if (csrf) fd.append('_token', csrf);
            fd.append('items', JSON.stringify([{ id: itemId, quantity: 1, variants: 0, extras: [], mods: [] }]));
            for (const [k, v] of Object.entries({ name: 'WebKit Guest', 'ordering-type': 'takeaway', 'phone-number': '+16505550177', pay_via: 'cash', total: '1.00' })) fd.append(k, v);
            const x = await fetch(`/${storeId}/send-order`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, body: fd });
            let json = null; try { json = await x.json(); } catch (_e) { /* not json */ }
            return { status: x.status, json };
          }, [id[0], item]);
          if (r.json && r.json.success) { order = r.json; break; }
        }
        check.ok(!!order, `${t.theme}: a guest checkout from the WebKit page is accepted (tried ${items.length} items)`);
        if (order) {
          created.push(order.order_id);
          const token = L.dbq('select track_token from orders where id = ?', [order.order_id])[0];
          if (token && token[0]) {
            const tr = await page.goto(`${base}/order/track/${token[0]}`, { waitUntil: 'load', timeout: 45000 });
            check.ok(tr && tr.status() === 200, `${t.theme}: the tracking page opens (${tr && tr.status()})`);
            await page.waitForTimeout(800);
          }
        }
      }
      const csp = await page.evaluate(() => (window.__csp || []).concat([...document.querySelectorAll('style')].filter((n) => !n.nonce && !n.getAttribute('nonce')).map((n) => `style-without-nonce in <${n.parentElement.tagName.toLowerCase()}>: ${n.textContent.trim().slice(0, 80)}`))).catch(() => []);
      check.eq(errorsOf.get(t.theme).join(' | ') + (csp.length ? ` || CSP: ${csp.join(' ; ')}` : ''), '', `${t.theme}: no JavaScript error in WebKit`);
      await ctx.close();
    }

    // ── dashboard login (desktop WebKit) ─────────────────────────────────────────────────────────────────────────────────
    const dctx = await browser.newContext({ ...NO_SW, viewport: { width: 1280, height: 900 }, locale: 'en-US' });
    await consent(dctx);
    const dash = await dctx.newPage();
    watch(dash, 'dashboard');
    const reqs = []; dash.on('request', (r) => { if (r.resourceType() === 'script') reqs.push(r.url().replace(base, '').replace(/\?.*/, '')); });
    if (process.env.E2E_WEBKIT_BLOCK) { const re = new RegExp(process.env.E2E_WEBKIT_BLOCK.split(',').join('|')); /* comma list: a remote shell wrapper may not carry a pipe */ await dash.route('**/*', (rt) => (rt.request().resourceType() === 'script' && re.test(rt.request().url()) ? rt.abort() : rt.continue())); }
    if (process.env.E2E_WEBKIT_NOFX === '1') await dash.addInitScript(() => document.addEventListener('DOMContentLoaded', () => { const st = document.createElement('style'); st.textContent = '*{animation:none!important;transition:none!important;backdrop-filter:none!important;-webkit-backdrop-filter:none!important;filter:none!important}'; document.head.append(st); }));
    await dash.goto(`${base}/login`, { waitUntil: 'load', timeout: 45000 });
    await dash.fill('#username, input[name="username"], input[name="email"]', P.OWNER.email);
    await dash.fill('#password, input[name="password"]', P.OWNER.pass);
    await Promise.all([dash.waitForURL((u) => !/\/login/.test(u.pathname), { timeout: 30000 }).catch(() => {}), dash.click('button[type=submit], input[type=submit]')]);
    log('  step dashboard: clicked sign in');
    await dash.waitForTimeout(1500);
    check.ok(!/\/login/.test(new URL(dash.url()).pathname), `dashboard: the owner signs in through the form and lands on ${new URL(dash.url()).pathname}`);
    const navs = []; dash.on('framenavigated', (f) => { if (f === dash.mainFrame()) navs.push(`${Date.now() - t0} ${f.url().replace(base, '')}`); });
    const t0 = Date.now();
    await new Promise((r) => setTimeout(r, 8000));
    log(`  probe dashboard (${engine}): navigations ${navs.length} [${navs.slice(0, 6).join(' | ')}] scripts: ${reqs.join(' ; ')}`);
    const alive = await Promise.race([dash.evaluate(() => document.body.innerText.trim().length), new Promise((r) => setTimeout(() => r('HUNG'), 90000))]);
    check.ok(alive !== 'HUNG', `dashboard: the page answers script evaluation within 90 s (${engine}, took ${Date.now() - t0} ms, nofx=${process.env.E2E_WEBKIT_NOFX || 0})`);
    if (alive !== 'HUNG') check.ok(alive > 80, 'dashboard: it renders');
    log('  step dashboard rendered');
    const dcsp = alive === 'HUNG' ? [] : await dash.evaluate(() => (window.__csp || []).concat([...document.querySelectorAll('style')].filter((n) => !n.nonce && !n.getAttribute('nonce')).map((n) => `style-without-nonce in <${n.parentElement.tagName.toLowerCase()}>: ${n.textContent.trim().slice(0, 80)}`))).catch(() => []);
    check.eq(errorsOf.get('dashboard').join(' | ') + (dcsp.length ? ` || CSP: ${dcsp.join(' ; ')}` : ''), '', 'dashboard: no JavaScript error in WebKit');
    log('  step dashboard: closing context');
    await Promise.race([dctx.close(), new Promise((r) => setTimeout(r, 15000))]);

    // ── POS sale (staff app) ───────────────────────────────────────────────────────────────────────────────────────────
    log('  step pos: fixtures');
    const a = P.ownerRestaurant(P.OWNER.email);
    P.enable(P.OWNER.email, ['pos']);
    const prevA = P.setOptions(a, { require_phone_verification: '0' });
    const items = P.makeMenu(a, [['e2eB WebKit Latte', 6, {}]]);
    const cashier = P.makeStaff(a, 'Wk', 'cashier');
    try {
      const pctx = await browser.newContext({ ...NO_SW, viewport: { width: 1100, height: 800 }, locale: 'en-US' });
      await consent(pctx);
      const pos = await pctx.newPage();
      watch(pos, 'pos');
      await pos.goto(`${base}/staff/login`, { waitUntil: 'load', timeout: 45000 });
      await pos.fill('#username, input[name="username"], input[name="email"]', cashier.email);
      await pos.fill('#password, input[name="password"]', cashier.pass);
      await Promise.all([pos.waitForURL((u) => !/\/staff\/login/.test(u.pathname), { timeout: 30000 }).catch(() => {}), pos.click('button[type=submit], input[type=submit]')]);
      await pos.goto(`${base}/pos`, { waitUntil: 'load', timeout: 45000 });
      await pos.waitForTimeout(2000);
      check.ok(/\/pos/.test(new URL(pos.url()).pathname), 'pos: the cashier signs in to the staff app and opens the register');
      const sale = await pos.evaluate(async (itemId) => {
        const token = document.querySelector('meta[name=csrf-token]')?.content || '';
        const r = await fetch('/pos/orders', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ type: 'takeaway', items: [{ item_id: itemId, quantity: 1 }] }) });
        let json = null; try { json = await r.json(); } catch (_e) { /* not json */ }
        return { status: r.status, json };
      }, +items['e2eB WebKit Latte']);
      check.ok(sale.status === 200 || sale.status === 201, `pos: a sale is rung up from the WebKit register (${sale.status} ${JSON.stringify(sale.json).slice(0, 100)})`);
      log('  step pos sale done');
      check.eq(errorsOf.get('pos').join(' | '), '', 'pos: no JavaScript error in WebKit');
      await pctx.close();
    } finally {
      P.tinker(`
        \\Juwri\\Modules\\Menu\\Models\\Menu::whereIn('id', ${P.php(Object.values(items))})->get()->each(fn ($m) => $m->delete());
        \\App\\Models\\StaffMember::where('id', ${cashier.id})->delete();
        echo 'OK=1';`, 'OK');
      P.restoreOptions(a, prevA);
    }
  } finally {
    await browser.close();
    for (const n of notes) log(`  note ${n}`);
  }
  check.done(log);
};
