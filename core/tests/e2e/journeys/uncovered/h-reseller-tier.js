// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P1-12 item 9 h (2/2) — reseller tiers (ee): a reseller creates a store owner + store under its tier; the tier's
 * caps bind and the store never gets more than the platform plan behind it ("limits only lower").
 *
 *   1. Superadmin creates a reseller tier in Admin → Reseller tiers (1 customer, 1 retail plan, 1 team member).
 *   2. Superadmin creates the reseller's owner account (Admin → Users) and the reseller (Admin → Resellers), then
 *      assigns the tier (the edit form) → the reseller's limits are the tier's.
 *   3. The reseller signs in to its portal and creates a store-owner account: the first is created (tagged with the
 *      reseller), the second is refused at the tier cap (403).
 *   4. That store owner signs in and creates a store; a second store is refused — a reseller's store gets the limits
 *      of the platform plan it is on, never more (tiers carry no per-store limits — RESELLER-TIERS.md G7).
 *   5. Customer: the store's storefront renders. Reseller: the portal lists the store owner; the store console, a tier
 *      feature this tier lacks, stays closed (404).
 *   6. IDOR: the reseller cannot open an account that is not its customer (404).
 *
 * Needs ee (licensed build) — skips with a reason in Community. Runs on E2E_ARMED_URL when set, else E2E_BASE_URL.
 *
 *   E2E_BASE_URL=https://juw.test npm run test:e2e -- h-reseller-tier
 */

const fs = require('fs');
const C = require('../../lib/lane-c');

module.exports = async function hResellerTier({ browser, page, log, shot }) {
  const R = C.recorder('h-reseller', log);
  const base = C.cfg.armed || C.cfg.base;
  const tag = C.stamp();
  if (!C.cfg.adminEmail || !C.cfg.adminPass) R.fail('admin credentials missing (E2E_ADMIN_EMAIL / E2E_ADMIN_PASS or the site .env)');

  // ── 1. tier ───────────────────────────────────────────────────────────────
  await C.login(page, base, C.cfg.adminEmail, C.cfg.adminPass);
  if (!(await C.eeActive(page, base))) {
    const why = `ee is not active on ${base} (Community build: resellers are an Enterprise feature)`;
    R.skip('whole journey', why);
    return { skip: why }; // run.js counts it as skipped, never as a pass
  }
  await page.goto(base + '/admin/reseller-tiers', { waitUntil: 'domcontentloaded' });
  const slug = `e2ec-tier-${tag}`;
  const tierPost = await C.call(page, 'POST', base + '/admin/reseller-tiers', {
    name: `${C.PREFIX}tier ${tag}`.slice(0, 40), slug, monthly_price: 0, annual_price: 0, base_fee: 0, seat_price: 0,
    trial_days: 0, included_seats: 0, sort: 99, commission_share: 0, application_fee_pct: 0, active: 1,
    limits: { customers: 1, reseller_plans: 1, team_members: 1, announcements_per_month: 1 },
    wholesale_usage: { whatsapp_msg: 0, ai_credit: 0, sms: 0 },
  }, { accept: 'text/html' });
  const tier = C.one('SELECT id, limits FROM reseller_tiers WHERE slug = ?', [slug]);
  if (!tier) R.fail(`tier create → ${tierPost.status} (landed ${tierPost.url})`);
  R.ok(`superadmin created tier #${tier.id} (customers 1)`);

  // ── 2. reseller owner + reseller, tier assigned ───────────────────────────
  const sa = C.one("SELECT id FROM countries WHERE code = 'SA'");
  const mkUser = async (who) => {
    const email = `${C.PREFIX}${who}-${tag}@local.invalid`;
    const pass = `E2eC-${tag}-pass`;
    const r = await C.call(page, 'POST', base + '/admin/users', {
      name: `${C.PREFIX}${who} ${tag}`, username: `e2ec${who}${tag}`.slice(0, 40), email, password: pass, country: Number(sa.id),
    });
    const row = C.one('SELECT id FROM users WHERE email = ?', [email]);
    if (!row) R.fail(`admin user create (${who}) → ${r.status} ${JSON.stringify(r.data || r.text.slice(0, 200))}`);
    return { id: Number(row.id), email, pass };
  };
  const resOwner = await mkUser('resowner');
  const stranger = await mkUser('stranger');
  await page.goto(base + '/admin/resellers', { waitUntil: 'domcontentloaded' });
  const brand = `${C.PREFIX}brand ${tag}`;
  await C.call(page, 'POST', base + '/admin/resellers', { owner_user_id: resOwner.id, brand_name: brand }, { accept: 'text/html' });
  const reseller = C.one('SELECT id FROM resellers WHERE owner_user_id = ?', [resOwner.id]);
  if (!reseller) R.fail('reseller not created');
  await C.call(page, 'POST', `${base}/admin/resellers/${reseller.id}`, {
    _method: 'PUT', brand_name: brand, tier_id: Number(tier.id), active: 1,
  }, { accept: 'text/html' });
  const assigned = C.one('SELECT tier_id, active FROM resellers WHERE id = ?', [reseller.id]);
  if (String(assigned.tier_id) !== String(tier.id) || String(assigned.active) !== '1') R.fail(`tier not assigned: ${JSON.stringify(assigned)}`);
  R.ok(`superadmin created reseller #${reseller.id} on the new tier`);

  // ── 3. the reseller creates store owners up to the tier cap ───────────────
  const RS = await C.actorPage(browser);
  try {
    await C.login(RS.page, base, resOwner.email, resOwner.pass);
    const portal = await RS.page.goto(base + '/reseller', { waitUntil: 'domcontentloaded' });
    if (!portal || portal.status() >= 400) R.fail(`reseller portal → ${portal && portal.status()}`);
    await shot(RS.page, 'h-reseller-portal');
    const merchant = { email: `${C.PREFIX}merchant-${tag}@local.invalid`, pass: `E2eC-${tag}-merchant` };
    const first = await C.call(RS.page, 'POST', base + '/reseller/customers', { name: `${C.PREFIX}merchant ${tag}`, email: merchant.email, password: merchant.pass });
    const row = C.one('SELECT id, reseller_id FROM users WHERE email = ?', [merchant.email]);
    if (first.status !== 201 || !row || String(row.reseller_id) !== String(reseller.id)) R.fail(`first store owner → ${first.status} ${JSON.stringify(first.data)} row=${JSON.stringify(row)}`);
    merchant.id = Number(row.id);
    R.ok(`reseller created store owner #${merchant.id} (tagged with the reseller)`);
    const second = await C.call(RS.page, 'POST', base + '/reseller/customers', { name: `${C.PREFIX}merchant2 ${tag}`, email: `${C.PREFIX}merchant2-${tag}@local.invalid`, password: merchant.pass });
    if (second.status !== 403 || C.one('SELECT id FROM users WHERE email = ?', [`${C.PREFIX}merchant2-${tag}@local.invalid`])) {
      R.fail(`second store owner past the tier cap → ${second.status} (expected 403, no account)`);
    }
    R.ok('a second store owner is refused at the tier cap (403)');

    // ── 4. the store owner creates a store; the platform plan's limit binds ─
    const M = await C.actorPage(browser);
    const storeSlug = `e2ec-res-${tag}`;
    try {
      await C.login(M.page, base, merchant.email, merchant.pass);
      const makeStore = async (s) => {
        await M.page.goto(base + '/stores/create', { waitUntil: 'domcontentloaded' });
        const FORM = 'form[action$="/stores"]';
        if (!(await M.page.$(FORM))) return 'no-form';
        await M.page.evaluate((F, v) => {
          const set = (n, x) => { const el = document.querySelector(`${F} [name="${n}"]`); if (el) { el.value = x; el.dispatchEvent(new Event('input', { bubbles: true })); } };
          set('title', v.title); set('slug', v.slug); set('phone', '0500000001'); set('address', '2 Olaya St'); set('description', 'e2e reseller store');
        }, FORM, { title: `${C.PREFIX}res store ${s}`, slug: s });
        const logo = C.tempPng('logo');
        const cover = C.tempPng('cover');
        try {
          const li = await M.page.$(`${FORM} input[name="main_image"]`);
          const ci = await M.page.$(`${FORM} input[name="cover_image"]`);
          if (li) await li.uploadFile(logo);
          if (ci) await ci.uploadFile(cover);
          await Promise.all([M.page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}), M.page.$eval(FORM, (f) => f.submit())]);
        } finally { fs.unlink(logo, () => {}); fs.unlink(cover, () => {}); }
        return 'submitted';
      };
      await makeStore(storeSlug);
      const store = C.one('SELECT id, user_id FROM stores WHERE slug = ?', [storeSlug]);
      if (!store || Number(store.user_id) !== merchant.id) R.fail('the reseller merchant\'s store was not created');
      R.ok(`reseller's store owner created store #${store.id}`);
      const plan = C.one('SELECT group_id FROM users WHERE id = ?', [merchant.id]);
      const extra = await makeStore(`${storeSlug}-2`);
      if (C.one('SELECT id FROM stores WHERE slug = ?', [`${storeSlug}-2`])) R.fail(`a second store was created on plan ${plan.group_id} (restaurant_limit 1)`);
      R.ok(`a second store is refused: the store has its platform plan's limits (plan ${plan.group_id}, ${extra})`);

      // ── 5. customer + reseller see the result ─────────────────────────────
      const CU = await C.actorPage(browser);
      try {
        const sf = await CU.page.goto(`${base}/${storeSlug}`, { waitUntil: 'domcontentloaded' });
        if (!sf || sf.status() >= 400) R.fail(`storefront /${storeSlug} → ${sf && sf.status()}`);
        if (!(await CU.page.content()).includes(`${C.PREFIX}res store ${storeSlug}`)) R.fail('storefront does not render the store name');
        R.ok('customer opens the reseller store\'s storefront');
      } finally { await CU.close(); }
      const list = await C.call(RS.page, 'GET', `${base}/reseller/customers?q=${encodeURIComponent(merchant.email)}`);
      const listed = ((list.data && list.data.data) || []).some((c) => Number(c.id) === merchant.id);
      if (!listed) R.fail(`reseller customers list → ${list.status} without the store owner`);
      const detail = await RS.page.goto(`${base}/reseller/customers/${merchant.id}`, { waitUntil: 'domcontentloaded' });
      if (!detail || detail.status() >= 400) R.fail(`reseller customer page → ${detail && detail.status()}`);
      await shot(RS.page, 'h-reseller-customer');
      R.ok('reseller sees its store owner in the portal (list + detail)');
      // The store console is a tier FEATURE (store_console) this tier does not include → not reachable.
      const consoleResp = await RS.page.goto(`${base}/reseller/store`, { waitUntil: 'domcontentloaded' });
      if (!consoleResp || consoleResp.status() !== 404) R.fail(`store console on a tier without it → ${consoleResp && consoleResp.status()} (expected 404)`);
      R.ok('tier features bind too: the store console is closed on this tier (404)');
    } finally { await M.close(); }

    // ── 6. IDOR ─────────────────────────────────────────────────────────────
    const idor = await RS.page.goto(`${base}/reseller/customers/${stranger.id}`, { waitUntil: 'domcontentloaded' });
    const leaked = (await RS.page.content()).includes(stranger.email);
    if (!idor || idor.status() !== 404 || leaked) R.fail(`IDOR: reseller opened a non-customer account → ${idor && idor.status()} (leaked=${leaked})`);
    R.ok('IDOR: the reseller cannot open an account that is not its customer (404)');
  } finally {
    await RS.close();
  }
  R.summary();
};
