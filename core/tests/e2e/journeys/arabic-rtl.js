// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P1-12 item 9 — Arabic / RTL across customer, owner and superadmin. Opt-in with E2E_RUN_P112_B=1.
 *
 *   1  customer (crave-demo, /ar, 390px): dir=rtl + lang=ar, adds an item through the UI, the cart is called السلة (never
 *      الحقيبة), places a pay-on-counter order, lands on the Arabic track page (items in Arabic), USD stays sign-first
 *      ($ is the currency's own convention) and a SAR store (retail-demo) renders number-first with the sign after.
 *   2  owner -> customer -> owner: the owner opens the order the customer just placed (and the other owner is denied it: IDOR),
 *      the Arabic dashboard, creates a menu item through the dialog, rings a cash sale at the POS in Arabic, then removes the
 *      item again (cleanup of the e2eB- record).
 *   3  superadmin: /ar/admin dashboard, users, orders, settings, plans, legal-acceptances — Arabic, no leftovers.
 *
 * Every scanned page is checked for English leftovers (text without Arabic that is not data typed by an operator/seeder,
 * a brand or a unit — see lib/lane-b.js englishLeftovers + dataAllowlist) and for horizontal overflow at 390px.
 * Owner Settings (country and language pickers in Arabic) and Security (own legal acceptances) are scanned too; the superadmin
 * legal-acceptances list as well.
 */

const L = require('../lib/lane-b');
const { newPage } = require('../lib/harness');

const OWNER = { email: process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com', pass: process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass' };
const OTHER = { email: process.env.E2E_OWNER_EMAIL || 'themepack-owner@demo.test', pass: process.env.E2E_OWNER_PASS || 'password' };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const CURRENCY_SAR = /\d[\d.,]*\s*[⁦-⁩‎‏]*⃁|\d[\d.,]*\s*[⁦-⁩‎‏]*ر\.س/;

module.exports = async function arabicRtl({ browser, log, shot }) {
  const check = L.checker('arabic-rtl');
  const base = L.baseUrl();
  const store = (slug) => { const r = L.dbq('select id from stores where slug = ?', [slug]); return r.length ? Number(r[0][0]) : null; };
  const craveId = store('crave-demo');
  if (!craveId) return L.skip(log, 'arabic-rtl', 'crave-demo showcase store not seeded');
  const allow = [...L.dataAllowlist(['allergies', 'taxes']), 'American grill', 'VAT', 'Pro A',
    // orders.type of a fixture order another lane's Phase-3 build wrote to the shared dev DB; this build has no such fulfilment mode, so the admin list prints it as data
    'at_home'];
  const scan = async (page, label, extra = []) => {
    await sleep(400);
    let left = await L.englishLeftovers(page, [...allow, ...extra]);
    // data rows other journeys/lanes created after this run started (e2e* stores, users) are data too: refresh once
    if (left.length) left = await L.englishLeftovers(page, [...allow, ...L.dataAllowlist(['allergies', 'taxes']), ...extra]);
    check.eq(left.length, 0, `${label}: no English leftovers${left.length ? ' -> ' + JSON.stringify(left.slice(0, 6)) : ''}`);
    const ov = await L.overflowPx(page);
    check.ok(ov <= 1, `${label}: no horizontal overflow (${ov}px)`);
  };
  const rtlOf = (page) => page.evaluate(() => [document.documentElement.lang, document.documentElement.dir]);

  // ---------------------------------------------------------------- 1 customer
  const c = await L.freshPage(browser, newPage, { width: 390, height: 844 });
  let trackHref = null;
  const custName = 'e2eB-عميل';
  try {
    const p = c.page;
    await p.goto(`${base}/ar/crave-demo`, { waitUntil: 'networkidle2' });
    check.eq((await rtlOf(p)).join('/'), 'ar/rtl', 'storefront: lang=ar dir=rtl');
    await p.evaluate(() => { const s = document.querySelector('#cvSplashSkip'); if (s) s.click(); });
    await sleep(500);
    await scan(p, 'storefront home');
    const rt = await p.evaluate(() => ({ sign: window.CRAVE.currencySign, pos: window.CRAVE.currencyPos,
      items: Object.values(window.CRAVE.items).map((i) => ({ id: i.id, req: (i.optionGroups || i.groups || []).some((g) => g.required) })) }));
    const item = rt.items.find((i) => !i.req);
    check.ok(!!item, 'a plain (no required option) item exists to order');
    await p.evaluate((id) => document.querySelector(`[data-open="${id}"]`).click(), item.id);
    await sleep(500);
    await scan(p, 'product sheet');
    await p.evaluate(() => document.querySelector('#addToCart').click());
    await sleep(400);
    await p.evaluate(() => document.querySelector('[data-tab="cart"]').click());
    await sleep(500);
    const cartText = await p.evaluate(() => document.body.innerText);
    check.ok(/السلة|سلة|سلتي/.test(cartText), 'cart is called السلة / سلتي');
    check.ok(!/الحقيبة|حقيبة/.test(cartText), 'cart is never called الحقيبة (bag)');
    await scan(p, 'cart');
    await p.evaluate(() => document.querySelector('#cvOrderTypeSeg [data-ot="takeaway"]').click());
    await sleep(300);
    await p.evaluate(() => document.querySelector('#cvGoCheckout').click());
    await sleep(500);
    await scan(p, 'checkout');
    await p.type('#cvCustName', custName);
    await p.type('#cvCustPhone', '+966512345678');
    await p.evaluate(() => document.querySelector('.pm[data-pm="pay_on_counter"]').click());
    await p.evaluate(() => document.querySelector('#cvPlaceOrder').click());
    await p.waitForSelector('#cvConfirmTrack', { timeout: 15000 });
    // the link exists before the order answers; wait for the server's track URL to land in it (not the '#' placeholder)
    await p.waitForFunction(() => { const a = document.querySelector('#cvConfirmTrack'); return a && a.getAttribute('href') && a.getAttribute('href') !== '#'; }, { timeout: 15000 });
    await sleep(400);
    await scan(p, 'order confirmed', [custName]);
    trackHref = await p.evaluate(() => document.querySelector('#cvConfirmTrack').href);
    check.ok(/\/ar\/order\/track\//.test(trackHref), `track link keeps the Arabic locale (${trackHref.replace(base, '')})`);
    await p.goto(trackHref, { waitUntil: 'networkidle2' });
    check.eq((await rtlOf(p)).join('/'), 'ar/rtl', 'track page: lang=ar dir=rtl');
    await scan(p, 'track page', [custName]);
    await shot(p, 'arabic-track');
    // currency: USD store is sign-first; the sign sits before the digits ($20.80)
    const usd = await (async () => { await p.goto(`${base}/ar/crave-demo`, { waitUntil: 'networkidle2' }); return p.evaluate(() => document.body.innerText); })();
    const usdClean = usd.replace(/[\u2066-\u2069\u200e\u200f]/g, '');
    check.ok(/\$[^\S\n]*\d/.test(usdClean) && !/\d[^\S\n]*\$/.test(usdClean), 'USD store: $ before the digits (sign-first)');
    // SAR store: number first, sign after
    await p.goto(`${base}/ar/retail-demo`, { waitUntil: 'networkidle2' });
    const sar = await p.evaluate(() => document.body.innerText);
    check.eq((await rtlOf(p)).join('/'), 'ar/rtl', 'retail-demo: lang=ar dir=rtl');
    check.ok(CURRENCY_SAR.test(sar), 'SAR store: number first, riyal sign after');
    check.ok((await L.overflowPx(p)) <= 1, 'retail-demo: no horizontal overflow');
  } finally { await c.close(); }

  // ---------------------------------------------------------------- 2 owner
  const o = await L.freshPage(browser, newPage, { width: 390, height: 844 });
  let createdName = null;
  try {
    const p = o.page;
    await L.login(p, base, OWNER);
    await p.goto(`${base}/ar/dashboard`, { waitUntil: 'networkidle2' });
    check.eq((await rtlOf(p)).join('/'), 'ar/rtl', 'owner dashboard: lang=ar dir=rtl');
    await scan(p, 'owner dashboard');
    await shot(p, 'arabic-owner-dashboard');

    for (const pth of ['settings', 'two-factor']) {
      const r = await p.goto(`${base}/ar/${pth}`, { waitUntil: 'networkidle2' });
      check.eq(r && r.status(), 200, `owner /ar/${pth} opens`);
      check.eq((await rtlOf(p)).join('/'), 'ar/rtl', `owner ${pth}: lang=ar dir=rtl`);
      await scan(p, `owner ${pth}`);
    }

    // owner sees the customer's order; the other owner does not (IDOR)
    const ord = L.dbq('select id from orders where customer_name = ? and store_id = ? order by id desc limit 1', [custName, craveId]);
    check.eq(ord.length, 1, 'the customer order is stored in the crave-demo store');
    if (ord.length) {
      const hub = await p.goto(`${base}/ar/orders/${craveId}?q=${encodeURIComponent(custName)}`, { waitUntil: 'networkidle2' });
      check.eq(hub.status(), 200, `owner opens the Arabic orders hub of store #${craveId}`);
      await sleep(600);
      check.ok((await p.evaluate(() => document.body.innerText)).includes(custName), 'the owner sees the customer order (by name) in the Arabic orders hub');
      check.eq((await rtlOf(p)).join('/'), 'ar/rtl', 'orders hub: lang=ar dir=rtl');
      await scan(p, 'owner orders hub', [custName]);
      const other = await L.freshPage(browser, newPage, { width: 390, height: 844 });
      try {
        await L.login(other.page, base, OTHER);
        const denied = await L.status(other.page, `${base}/ar/orders/${craveId}`);
        check.ok([403, 404].includes(denied.status), `the other owner is denied store #${craveId}'s orders hub (${denied.status})`);
      } finally { await other.close(); }
    }

    // create a menu item through the dialog
    await p.goto(`${base}/ar/stores/${craveId}/menu`, { waitUntil: 'networkidle2' });
    await scan(p, 'owner menu');
    createdName = `e2eB-وجبة-${Date.now() % 100000}`;
    const opened = await p.evaluate(() => {
      const b = [...document.querySelectorAll('button')].find((x) => (x.getAttribute('@click') || '').startsWith('openAddItem('));
      if (!b) return false; b.click(); return true;
    });
    check.ok(opened, 'the add-item dialog opens');
    await sleep(700);
    await p.evaluate((name) => {
      const d = [...document.querySelectorAll('dialog')].find((x) => x.open);
      const set = (n, v) => { const i = d.querySelector(`[name="${n}"]`); i.value = v; i.dispatchEvent(new Event('input', { bubbles: true })); };
      set('name', name); set('price', '7.5');
    }, createdName);
    await scan(p, 'add-item dialog', [createdName]);
    await p.evaluate(() => { const d = [...document.querySelectorAll('dialog')].find((x) => x.open); d.querySelector('button[type="submit"]').click(); });
    await sleep(2500);
    const row = L.dbq('select id from items where name = ?', [createdName]);
    check.eq(row.length, 1, 'the new item is saved');
    if (row.length) {
      await p.goto(`${base}/ar/stores/${craveId}/menu`, { waitUntil: 'networkidle2' });
      check.ok((await p.evaluate(() => document.body.innerText)).includes(createdName), 'the owner sees the new item in the Arabic menu');
      await scan(p, 'owner menu with new item', [createdName]);
    }

    // POS cash sale in Arabic
    await p.goto(`${base}/ar/pos`, { waitUntil: 'networkidle2' });
    check.eq((await rtlOf(p)).join('/'), 'ar/rtl', 'POS: lang=ar dir=rtl');
    await scan(p, 'POS', [createdName]);
    const before = Number(L.dbq('select count(*) from orders where store_id = ?', [craveId])[0][0]);
    await p.evaluate(() => {
      const items = [...document.querySelectorAll('button.pos-item:not(.is-combo):not(.is-sold)')];
      const plain = items.find((b) => !b.querySelector('.pos-badge.is-options:not([style*="display: none"])')) || items[0];
      plain.click();
    });
    await sleep(600);
    await p.evaluate(() => { const fab = document.querySelector('.pos-cart-fab'); if (fab && fab.getClientRects().length) fab.click(); });
    await sleep(500);
    await scan(p, 'POS cart', [createdName]);
    await p.evaluate(() => document.querySelector('.acts .cash:not([disabled])').click());
    await p.waitForSelector('.pos-settle .pos-settle-confirm', { visible: true });
    await scan(p, 'POS cash settle', [createdName]);
    await p.evaluate(() => {
      const b = [...document.querySelectorAll('.pos-settle button')].find((x) => x.textContent.trim() === 'بالضبط');
      if (b) b.click();
    });
    await sleep(300);
    await p.evaluate(() => document.querySelector('.pos-settle .pos-settle-confirm:not([disabled])').click());
    await sleep(2500);
    const after = Number(L.dbq('select count(*) from orders where store_id = ?', [craveId])[0][0]);
    check.eq(after, before + 1, 'the POS cash sale created exactly one order');
    await scan(p, 'POS after sale', [createdName]);
    await shot(p, 'arabic-pos');

    // cleanup: remove the e2eB- item (and the POS-sold stock stays as the app computed it)
    if (row.length) {
      const del = await p.evaluate(async ({ url, id }) => {
        const token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        const fd = new FormData(); fd.append('id', id);
        const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, body: fd });
        return r.status;
      }, { url: `${base}/stores/${craveId}/delete-menu-item`, id: row[0][0] });
      check.eq(del, 200, 'the e2eB- item is removed again');
    }
  } finally { await o.close(); }

  // ---------------------------------------------------------------- 3 superadmin
  const email = process.env.E2E_ADMIN_EMAIL;
  const pass = process.env.E2E_ADMIN_PASS;
  if (!email || !pass) {
    log('arabic-rtl: superadmin leg skipped (E2E_ADMIN_EMAIL / E2E_ADMIN_PASS not set)');
  } else {
    const a = await L.freshPage(browser, newPage, { width: 390, height: 844 });
    try {
      await L.login(a.page, base, { email, pass });
      for (const pth of ['dashboard', 'users', 'orders', 'settings', 'plans', 'legal-acceptances']) {
        if (pth === 'plans' && !L.eePresentOnDisk()) { L.skip(log, 'arabic-rtl (admin plans)', 'Community build: plans are an Enterprise feature (no /admin/plans)'); continue; }
        const r = await a.page.goto(`${base}/ar/admin/${pth}`, { waitUntil: 'networkidle2' });
        if (pth === 'plans' && r && r.status() === 404 && (await L.status(a.page, `${base}/admin/plans`)).status === 404) {
          // edition on disk but not licensed on this site: its routes are absent by design (ModuleRoutes::slot)
          L.skip(log, 'arabic-rtl (admin plans)', 'Enterprise tree without a licence: no /admin/plans');
          continue;
        }
        if (!r || r.status() !== 200) { check.ok(false, `/ar/admin/${pth} -> ${r && r.status()}`); continue; }
        check.eq((await rtlOf(a.page)).join('/'), 'ar/rtl', `admin ${pth}: lang=ar dir=rtl`);
        await scan(a.page, `admin ${pth}`, ['Free Plan', 'Trial Plan']);
        if (pth === 'dashboard') await shot(a.page, 'arabic-admin-dashboard');
      }
    } finally { await a.close(); }
  }
  check.done(log);
};
