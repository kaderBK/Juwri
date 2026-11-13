// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P1-12 item 9 h (1/2) — superadmin plans (ee): plan create → a store owner subscribes with the Stub gateway → the
 * plan's limits are enforced; owner → customer → owner, plus a cross-owner IDOR check.
 *
 *   1. Superadmin creates a plan in Admin → Plans (1 store, 1 category, 2 items per category, ordering on, 9/month).
 *   2. Superadmin creates a store-owner account in Admin → Users (verified, free plan).
 *   3. The owner opens the plan checkout, pays with the E2E Stub gateway → the plan is active: users.group_id,
 *      an active subscriptions row, a membership transaction settled by `stub`; the owner's subscription page names
 *      the plan.
 *   4. Limits: first store created through the real create form; a second store is refused; one category, the second
 *      refused; two items, the third refused ("Limit exceeded").
 *   5. Customer: the storefront shows the two items.
 *   6. Owner sees the result in the menu editor; another owner cannot open or add to this store (IDOR).
 *
 * Needs ee (licensed build) — skips with a reason in Community. The subscribe leg needs the Stub gateway: an armed
 * instance (E2E_ARMED_URL, started with E2E_STUBS=1) and its catalog row
 * (db:seed --class='Juwri\Modules\Storefront\Database\Seeders\E2eStubGatewaySeeder'); without them the journey
 * stops at step 2 with a clear SKIP (not a pass of the later steps).
 *
 *   E2E_BASE_URL=https://juw.test E2E_ARMED_URL=http://127.0.0.1:8613 npm run test:e2e -- h-superadmin-plans
 */

const fs = require('fs');
const C = require('../../lib/lane-c');

module.exports = async function hSuperadminPlans({ browser, page, log, shot }) {
  const R = C.recorder('h-plans', log);
  const base = C.cfg.armed || C.cfg.base;
  const tag = C.stamp();
  if (!C.cfg.adminEmail || !C.cfg.adminPass) R.fail('admin credentials missing (E2E_ADMIN_EMAIL / E2E_ADMIN_PASS or the site .env)');

  // ── 1. superadmin creates the plan ────────────────────────────────────────
  await C.login(page, base, C.cfg.adminEmail, C.cfg.adminPass);
  if (!(await C.eeActive(page, base))) {
    const why = `ee is not active on ${base} (Community build: plans are an Enterprise feature)`;
    R.skip('whole journey', why);
    return { skip: why }; // run.js counts it as skipped, never as a pass
  }
  const planName = `${C.PREFIX}plan-${tag}`;
  const created = await C.call(page, 'POST', base + '/admin/plans', {
    name: planName, status: 1, monthly_price: 9, annual_price: 90, lifetime_price: 0,
    restaurant_limit: 1, category_limit: 1, menu_limit: 2, scan_limit: 100, allow_ordering: 1,
  }, { json: true });
  if (!(created.data && created.data.success)) R.fail(`plan create → ${created.status} ${JSON.stringify(created.data || created.text.slice(0, 300))}`);
  const plan = C.one('SELECT id, settings FROM plans WHERE name = ?', [planName]);
  if (!plan) R.fail(`plan ${planName} not stored`);
  const planId = Number(plan.id);
  const ent = C.one("SELECT int_value FROM plan_entitlements WHERE plan_id = ? AND ent_key = 'restaurant_limit'", [planId]);
  if (!ent || Number(ent.int_value) !== 1) R.fail(`plan #${planId}: restaurant_limit entitlement ${ent ? ent.int_value : '(none)'}, expected 1`);
  R.ok(`superadmin created plan #${planId} (1 store, 1 category, 2 items/category) + entitlements mirrored`);

  // ── 2. superadmin creates two store-owner accounts (owner + IDOR probe) ───
  const sa = C.one("SELECT id FROM countries WHERE code = 'SA'");
  const owners = [];
  for (const who of ['owner', 'other']) {
    const email = `${C.PREFIX}${who}-${tag}@local.invalid`;
    const pass = `E2eC-${tag}-pass`;
    const r = await C.call(page, 'POST', base + '/admin/users', {
      name: `${C.PREFIX}${who} ${tag}`, username: `e2ec${who.slice(0, 2)}${tag}`.slice(0, 40), email, password: pass, country: Number(sa.id),
    });
    const row = C.one('SELECT id, group_id, email_verified_at FROM users WHERE email = ?', [email]);
    if (!row) R.fail(`admin user create (${who}) → ${r.status} ${JSON.stringify(r.data || r.text.slice(0, 300))}`);
    owners.push({ id: Number(row.id), email, pass, group: String(row.group_id) });
  }
  const [owner, other] = owners;
  R.ok(`superadmin created owners #${owner.id} and #${other.id} (plan ${owner.group})`);

  // ── 3. the owner subscribes with the Stub gateway ─────────────────────────
  const stub = C.one("SELECT id FROM payment_gateways WHERE payment_folder = 'stub' AND payment_install = 1");
  if (!C.cfg.armed || !stub) {
    R.skip('subscribe + limits', !stub
      ? "the stub payment_gateways row is missing — db:seed --class='Juwri\\Modules\\Storefront\\Database\\Seeders\\E2eStubGatewaySeeder'"
      : 'no armed instance (E2E_ARMED_URL with E2E_STUBS=1) — juw.test cannot take a Stub payment');
    R.summary();
    return;
  }
  const O = await C.actorPage(browser);
  try {
    await C.login(O.page, base, owner.email, owner.pass);
    const co = await O.page.goto(`${base}/checkout?plan=${planId}&interval=monthly`, { waitUntil: 'domcontentloaded' });
    if (!co || co.status() >= 400) R.fail(`plan checkout page → ${co && co.status()}`);
    const offersStub = await O.page.evaluate((id) => !!document.querySelector(`[name="payment_method"][value="${id}"]`), String(stub.id));
    if (!offersStub) R.fail('plan checkout does not offer the Stub gateway on the armed instance');
    await shot(O.page, 'h-plans-checkout');
    R.ok('owner sees the plan checkout with the Stub gateway');

    const paid = await C.call(O.page, 'POST', base + '/checkout', {
      payment_submit: 1, plan: planId, interval: 'monthly', payment_method: Number(stub.id), e2e_outcome: 'success',
      type: 'personal', name: `${C.PREFIX}owner ${tag}`, address: '1 King Fahd Rd', city: 'Riyadh', state: 'Riyadh',
      zip: '11564', country: 'SA', tax_id: '',
    }, { accept: 'text/html' });
    const user = C.one('SELECT group_id FROM users WHERE id = ?', [owner.id]);
    const sub = C.one("SELECT status FROM subscriptions WHERE user_id = ? AND plan_id = ? ORDER BY id DESC LIMIT 1", [owner.id, planId]);
    const tx = C.one("SELECT status, transaction_gatway FROM transaction WHERE user_id = ? AND transaction_method = 'membership' ORDER BY id DESC LIMIT 1", [owner.id]);
    if (String(user.group_id) !== String(planId) || !sub || !tx || tx.transaction_gatway !== 'stub') {
      R.fail(`subscribe via stub: group_id=${user.group_id} sub=${JSON.stringify(sub)} tx=${JSON.stringify(tx)} (landed ${paid.url}, ${paid.status})`);
    }
    R.ok(`owner subscribed with the Stub gateway: plan active (subscription ${sub.status}, transaction ${tx.status})`);
    await O.page.goto(base + '/subscription', { waitUntil: 'domcontentloaded' });
    if (!(await O.page.evaluate((n) => document.body.innerText.includes(n), planName))) R.fail('the subscription page does not name the new plan');
    await shot(O.page, 'h-plans-subscription');
    R.ok('owner subscription page names the plan');

    // ── 4. limits ───────────────────────────────────────────────────────────
    const slug = `e2ec-plan-${tag}`;
    const makeStore = async (s) => {
      await O.page.goto(base + '/stores/create', { waitUntil: 'domcontentloaded' });
      const FORM = 'form[action$="/stores"]';
      const has = await O.page.$(FORM);
      if (!has) return 'no-form';
      await O.page.evaluate((F, v) => {
        const set = (n, x) => { const el = document.querySelector(`${F} [name="${n}"]`); if (el) { el.value = x; el.dispatchEvent(new Event('input', { bubbles: true })); } };
        set('title', v.title); set('slug', v.slug); set('phone', '0500000000'); set('address', '1 King Fahd Rd');
        set('description', 'e2e plan limits');
      }, FORM, { title: `${C.PREFIX}store ${s}`, slug: s });
      const logo = C.tempPng('logo');
      const cover = C.tempPng('cover');
      try {
        const li = await O.page.$(`${FORM} input[name="main_image"]`);
        const ci = await O.page.$(`${FORM} input[name="cover_image"]`);
        if (li) await li.uploadFile(logo);
        if (ci) await ci.uploadFile(cover);
        await Promise.all([
          O.page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
          O.page.$eval(FORM, (f) => f.submit()),
        ]);
      } finally { fs.unlink(logo, () => {}); fs.unlink(cover, () => {}); }
      return 'submitted';
    };
    await makeStore(slug);
    const store = C.one('SELECT id, user_id FROM stores WHERE slug = ?', [slug]);
    if (!store || Number(store.user_id) !== owner.id) R.fail(`first store ${slug} was not created for the owner`);
    const storeId = Number(store.id);
    R.ok(`store 1 of 1 created (#${storeId})`);
    const second = await makeStore(`${slug}-2`);
    if (C.one('SELECT id FROM stores WHERE slug = ?', [`${slug}-2`])) R.fail('a second store was created past the plan limit of 1');
    R.ok(`a second store is refused at the plan limit (${second})`);

    await O.page.goto(`${base}/stores/${storeId}/menu`, { waitUntil: 'domcontentloaded' });
    for (const n of [1, 2]) {
      await C.call(O.page, 'POST', `${base}/stores/${storeId}/add-category`, { name: `${C.PREFIX}cat${n}-${tag}` }, { accept: 'text/html' });
    }
    const cats = C.sql('SELECT id FROM categories WHERE store_id = ? ORDER BY id', [storeId]);
    if (cats.length !== 1) R.fail(`categories after two adds: ${cats.length}, expected 1 (category_limit 1)`);
    R.ok('one category created, the second refused (category_limit 1)');
    const catId = Number(cats[0].id);
    const answers = [];
    for (const n of [1, 2, 3]) {
      const r = await C.call(O.page, 'POST', `${base}/stores/${storeId}/add-menu-item`, {
        category_id: catId, name: `${C.PREFIX}item${n}-${tag}`, price: 5 + n, type: 'veg', active: 1, // the form's "active" box is ticked by default
      });
      answers.push(r.data);
    }
    const items = C.sql('SELECT id, name FROM items WHERE store_id = ? AND deleted_at IS NULL', [storeId]);
    if (items.length !== 2) R.fail(`items after three adds: ${items.length}, expected 2 (menu_limit 2) — ${JSON.stringify(answers)}`);
    if (!answers[2] || answers[2].success !== false || !/limit|الحد الأقصى/i.test(String(answers[2].message || ''))) {
      R.fail(`third item answer: ${JSON.stringify(answers[2])}`);
    }
    R.ok('two items created, the third refused with the plan-limit message (menu_limit 2)');

    // ── 5. customer sees the menu ───────────────────────────────────────────
    const CU = await C.actorPage(browser);
    try {
      const sf = await CU.page.goto(`${base}/${slug}`, { waitUntil: 'domcontentloaded' });
      if (!sf || sf.status() >= 400) R.fail(`storefront /${slug} → ${sf && sf.status()}`);
      // The menu may sit in a tab the theme has not opened yet: read the rendered document, not only visible text.
      const html = await CU.page.content();
      if (!html.includes(`${C.PREFIX}item1-${tag}`) || !html.includes(`${C.PREFIX}item2-${tag}`)) R.fail('storefront does not render both items');
      await shot(CU.page, 'h-plans-storefront');
      R.ok('customer sees both items on the storefront');
    } finally { await CU.close(); }

    // ── 6. owner sees the result; another owner cannot reach the store ──────
    await O.page.goto(`${base}/stores/${storeId}/menu`, { waitUntil: 'domcontentloaded' });
    if (!(await O.page.evaluate((n) => document.body.innerText.includes(n), `${C.PREFIX}item2-${tag}`))) R.fail('menu editor does not list the items');
    R.ok('owner sees the items in the menu editor');

    const X = await C.actorPage(browser);
    try {
      await C.login(X.page, base, other.email, other.pass);
      const r = await X.page.goto(`${base}/stores/${storeId}/menu`, { waitUntil: 'domcontentloaded' });
      const leaked = await X.page.evaluate((n) => document.body.innerText.includes(n), `${C.PREFIX}item1-${tag}`);
      if (leaked) R.fail(`IDOR: another owner sees this store's menu (status ${r && r.status()})`);
      await C.call(X.page, 'POST', `${base}/stores/${storeId}/add-menu-item`, { category_id: catId, name: `${C.PREFIX}idor-${tag}`, price: 1, type: 'veg' });
      if (C.one('SELECT id FROM items WHERE name = ?', [`${C.PREFIX}idor-${tag}`])) R.fail('IDOR: another owner added an item to this store');
      R.ok(`IDOR: another owner cannot open (${r && r.status()}) or add to this store`);
    } finally { await X.close(); }
  } finally {
    await O.close();
  }
  R.summary();
};
