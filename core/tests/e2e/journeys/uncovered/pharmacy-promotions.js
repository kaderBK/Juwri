// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P1-23 follow-up — the pharmacy promotion block (DECISIONS 2026-10-04; help: pharmacy checklist, Saudi note), end to
 * end. Fills the "promotion rules" TODO of Lane A's pharmacy journey (a-pharmacy): that journey covers the licence gate,
 * the country switch, the age confirm and the quantity cap; this one covers every promotion rule on medicines.
 *
 *   Owner (dashboard)  creates a pharmacy store (Saudi Arabia), uploads its licence (superadmin approves), adds a
 *                      medicine and a product marked "not a medicine", a store-wide sale (price schedule −20 %), a
 *                      bundle holding the medicine and one without it, a loyalty programme and a coupon; the pharmacy
 *                      page says promotions on medicines are switched off in this market.
 *   Customer           sees the medicine at its list price (no sale) and the cosmetic on sale; the bundle holding the
 *                      medicine is not offered (and refused if posted); a coupon on a medicine order is declined with
 *                      the reason; loyalty points: earned on the cosmetic only, not redeemable on a medicine order.
 *   Owner (result)     the orders carry the list price on the medicine line (no schedule snapshot), no coupon, no
 *                      reward; the customer's points = the cosmetic's share only.
 *   IDOR               another owner cannot open this pharmacy's compliance page.
 *
 * Free gifts: the product has no free-gift feature (BOGO is a coupon type, covered by the coupon rule).
 *
 * Needs the armed instance (E2E_ARMED_URL started with PHARMACY_ENABLED_COUNTRIES=SA) running a build with the
 * promotion block; skips with a clear reason otherwise (juw.test serves MAIN, where SA is not enabled).
 *
 *   E2E_BASE_URL=https://juw.test E2E_ARMED_URL=http://127.0.0.1:8613 npm run test:e2e -- pharmacy-promotions
 */

const fs = require('fs');
const C = require('../../lib/lane-c');

const SALE = 20; // % off, store-wide

module.exports = async function pharmacyPromotions({ browser, page, log, shot }) {
  const R = C.recorder('pharm-promo', log);
  if (!C.cfg.armed) {
    const why = 'no armed instance (E2E_ARMED_URL with PHARMACY_ENABLED_COUNTRIES=SA): pharmacy selling is off on juw.test';
    R.skip('whole journey', why);
    return { skip: why }; // run.js counts it as skipped, never as a pass
  }
  const base = C.cfg.armed;
  const tag = C.stamp();
  if (!C.cfg.adminEmail || !C.cfg.adminPass) R.fail('admin credentials missing');

  // ── setup: admin creates the owner (+ an IDOR probe owner) and grants the features ──
  await C.login(page, base, C.cfg.adminEmail, C.cfg.adminPass);
  const ee = await C.eeActive(page, base);
  const sa = C.one("SELECT id FROM countries WHERE code = 'SA'");
  const mkOwner = async (who) => {
    const email = `${C.PREFIX}${who}-${tag}@local.invalid`;
    const pass = `E2eC-${tag}-pass`;
    await C.call(page, 'POST', base + '/admin/users', {
      name: `${C.PREFIX}${who} ${tag}`, username: `e2ec${who}${tag}`.slice(0, 40), email, password: pass, country: Number(sa.id),
    });
    const row = C.one('SELECT id FROM users WHERE email = ?', [email]);
    if (!row) R.fail(`admin could not create ${who}`);
    return { id: Number(row.id), email, pass };
  };
  const owner = await mkOwner('pharm');
  const other = await mkOwner('pharmx');
  if (ee) {
    await page.goto(base + '/admin/feature-catalog', { waitUntil: 'domcontentloaded' }).catch(() => {});
    for (const key of ['multi_vertical', 'menu_schedules', 'combos', 'loyalty']) {
      await C.call(page, 'POST', base + '/admin/feature-catalog/grant', { user_id: owner.id, feature_key: key, reason: `${C.PREFIX}pharmacy promotions journey` }, { accept: 'text/html' });
    }
    const granted = C.sql("SELECT feature_key FROM entitlement_overrides WHERE user_id = ? AND feature_key IN ('multi_vertical','menu_schedules','combos','loyalty')", [owner.id]);
    if (granted.length < 4) R.fail(`feature grants: ${JSON.stringify(granted)}`);
    R.ok('superadmin created the pharmacy owner and granted pharmacy, sale prices, combos and loyalty');
  } else {
    R.skip('feature grants', 'Community build: features are not plan-gated');
  }

  const O = await C.actorPage(browser);
  const CU = await C.actorPage(browser);
  try {
    // ── owner: pharmacy store ───────────────────────────────────────────────
    await C.login(O.page, base, owner.email, owner.pass);
    const slug = `e2ec-pharm-${tag}`;
    await O.page.goto(base + '/stores/create', { waitUntil: 'domcontentloaded' });
    const FORM = 'form[action$="/stores"]';
    await O.page.waitForSelector(FORM);
    const picked = await O.page.evaluate(() => {
      const r = document.querySelector('input[name="store_type"][value="pharmacy"]');
      if (!r) return false;
      r.click();
      return true;
    });
    if (!picked) R.fail('the store type picker has no pharmacy option');
    await new Promise((r) => setTimeout(r, 300));
    await O.page.evaluate((F, v) => {
      const set = (n, x) => { const el = document.querySelector(`${F} [name="${n}"]`); if (el) { el.value = x; el.dispatchEvent(new Event('input', { bubbles: true })); } };
      set('title', v.title); set('slug', v.slug); set('phone', '0500000002'); set('address', 'Olaya St, Riyadh'); set('description', 'e2e pharmacy promotions');
    }, FORM, { title: `${C.PREFIX}Pharmacy ${tag}`, slug });
    const logo = C.tempPng('logo');
    const cover = C.tempPng('cover');
    try {
      await (await O.page.$(`${FORM} input[name="main_image"]`)).uploadFile(logo);
      await (await O.page.$(`${FORM} input[name="cover_image"]`)).uploadFile(cover);
      await Promise.all([O.page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}), O.page.$eval(FORM, (f) => f.submit())]);
    } finally { fs.unlink(logo, () => {}); fs.unlink(cover, () => {}); }
    const store = C.one('SELECT id, store_type FROM stores WHERE slug = ?', [slug]);
    if (!store || store.store_type !== 'pharmacy') R.fail(`pharmacy store not created (${JSON.stringify(store)})`);
    const sid = Number(store.id);
    R.ok(`owner created pharmacy #${sid}`);

    const pharmPage = async () => {
      await O.page.goto(`${base}/stores/${sid}/pharmacy`, { waitUntil: 'domcontentloaded' });
      return O.page.evaluate(() => (document.querySelector('[data-testid="pharmacy-promotions-rule"]')?.textContent || '').trim());
    };
    if (!(await pharmPage())) {
      R.skip('promotion rules', `this build has no pharmacy promotion block (no "Promotions on medicines" row on ${base})`);
      return;
    }

    // licence → superadmin approval
    const doc = C.tempPng('licence');
    try {
      await O.page.evaluate(() => {
        const f = document.querySelector('form[action*="/pharmacy/licence"]');
        const set = (n, v) => { const el = f && f.querySelector(`[name="${n}"]`); if (el) el.value = v; };
        set('country', 'SA'); set('licence_number', 'E2EC-LIC-1'); set('issuing_authority', 'Saudi FDA');
        const d = new Date(Date.now() + 365 * 864e5); set('expires_on', d.toISOString().slice(0, 10));
      });
      await (await O.page.$('form[action*="/pharmacy/licence"] input[name="document"]')).uploadFile(doc);
      await Promise.all([O.page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}), O.page.$eval('form[action*="/pharmacy/licence"]', (f) => f.submit())]);
    } finally { fs.unlink(doc, () => {}); }
    const lic = C.one('SELECT id, status FROM pharmacy_licences WHERE store_id = ? ORDER BY id DESC LIMIT 1', [sid]);
    if (!lic) R.fail('licence upload not stored');
    await page.goto(base + '/admin/pharmacy-licences', { waitUntil: 'domcontentloaded' });
    await C.call(page, 'POST', `${base}/admin/pharmacy-licences/${lic.id}/approve`, {}, { accept: 'text/html' });
    if (C.one('SELECT status FROM pharmacy_licences WHERE id = ?', [lic.id]).status !== 'approved') R.fail('licence not approved');
    const rule = await pharmPage();
    if (!/Switched off/i.test(rule)) R.fail(`pharmacy page rule: "${rule}" (expected switched off in SA)`);
    await shot(O.page, 'pharm-promo-owner-rule');
    R.ok('licence approved; the pharmacy page says promotions on medicines are switched off in this market');

    // takeaway ordering on (store settings form)
    await O.page.goto(`${base}/stores/${sid}/edit`, { waitUntil: 'domcontentloaded' });
    const editForm = `form[action$="/stores/${sid}"]`;
    if (await O.page.$(editForm)) {
      await O.page.evaluate((F) => {
        const f = document.querySelector(F);
        for (const n of ['restaurant_takeaway_order', 'allow_coupons']) {
          const el = f.querySelector(`input[type="checkbox"][name="${n}"]`);
          if (el && !el.checked) el.click();
        }
      }, editForm);
      await Promise.all([O.page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}), O.page.$eval(editForm, (f) => f.submit())]);
    }

    // catalogue: a medicine + a product marked "not a medicine"
    await O.page.goto(`${base}/stores/${sid}/menu`, { waitUntil: 'domcontentloaded' });
    await C.call(O.page, 'POST', `${base}/stores/${sid}/add-category`, { name: `${C.PREFIX}Pain relief` }, { accept: 'text/html' });
    const cat = C.one('SELECT id FROM categories WHERE store_id = ? ORDER BY id DESC LIMIT 1', [sid]);
    const medName = `${C.PREFIX}Paracetamol ${tag}`;
    const cosName = `${C.PREFIX}Hand cream ${tag}`;
    for (const [name, price] of [[medName, 5], [cosName, 10]]) {
      await C.call(O.page, 'POST', `${base}/stores/${sid}/add-menu-item`, { category_id: Number(cat.id), name, price, active: 1 });
    }
    const med = C.one('SELECT id FROM items WHERE store_id = ? AND name = ?', [sid, medName]);
    const cos = C.one('SELECT id FROM items WHERE store_id = ? AND name = ?', [sid, cosName]);
    if (!med || !cos) R.fail('catalogue items not created');
    await O.page.goto(`${base}/stores/${sid}/pharmacy/${cos.id}/edit`, { waitUntil: 'domcontentloaded' });
    await C.call(O.page, 'POST', `${base}/stores/${sid}/pharmacy/${cos.id}`, { _method: 'PUT', not_medicine: 1 }, { accept: 'text/html' });
    const flags = C.sql('SELECT id, is_medicine FROM items WHERE id IN (?, ?)', [Number(med.id), Number(cos.id)]);
    const isMed = Object.fromEntries(flags.map((f) => [Number(f.id), Number(f.is_medicine)]));
    if (isMed[Number(med.id)] !== 1 || isMed[Number(cos.id)] !== 0) R.fail(`medicine flags ${JSON.stringify(isMed)}`);
    R.ok('owner added a medicine (5.00) and a product marked "not a medicine" (10.00)');

    // promotions: store-wide sale, two bundles, loyalty programme, coupon
    await O.page.goto(`${base}/schedules?store_id=${sid}`, { waitUntil: 'domcontentloaded' });
    await C.call(O.page, 'POST', `${base}/schedules?store_id=${sid}`, {
      name: `${C.PREFIX}Sale ${tag}`, label: 'Sale', scope: 'store', adjustment_type: 'percent', adjustment_value: SALE, active: 1,
    }, { accept: 'text/html' });
    if (!C.one('SELECT id FROM price_schedules WHERE store_id = ?', [sid])) R.fail('sale (price schedule) not created');
    await O.page.goto(`${base}/combos`, { waitUntil: 'domcontentloaded' });
    const bundle = async (name, menuIds) => C.call(O.page, 'POST', `${base}/combos`, {
      store_id: sid, name, price: 12, active: true,
      slots: [{ title: 'Pick one', min: 1, max: 1, options: menuIds.map((id) => ({ menu_id: Number(id), upcharge: 0, active: true })) }],
    }, { json: true });
    await bundle(`${C.PREFIX}Bundle with medicine ${tag}`, [med.id, cos.id]);
    await bundle(`${C.PREFIX}Bundle cosmetics ${tag}`, [cos.id]);
    const combos = C.sql('SELECT id, name FROM combos WHERE store_id = ?', [sid]);
    if (combos.length !== 2) R.fail(`bundles created: ${combos.length}`);
    const medCombo = combos.find((c) => c.name.includes('with medicine'));
    await O.page.goto(`${base}/restaurant/${sid}/loyalty`, { waitUntil: 'domcontentloaded' });
    await C.call(O.page, 'POST', `${base}/restaurant/${sid}/loyalty`, {
      type: 'points', earn_rate: 100, reward_threshold: 5, reward_description: 'Reward', reward_discount: 3, active: 1,
    }, { accept: 'text/html' });
    if (!C.one('SELECT id FROM loyalty_programs WHERE store_id = ? AND active = 1', [sid])) R.fail('loyalty programme not saved');
    const code = `E2EC${tag}`.toUpperCase().slice(0, 30);
    await O.page.goto(`${base}/coupons/create`, { waitUntil: 'domcontentloaded' });
    await C.call(O.page, 'POST', `${base}/coupons`, { code, coupon_type: 'percentage', percentage: 20, limit: 50 }, { accept: 'text/html' });
    if (!C.one('SELECT id FROM coupons WHERE code = ?', [code])) R.fail('coupon not created');
    R.ok(`owner set up a ${SALE}% store-wide sale, 2 bundles, a loyalty programme and coupon ${code}`);

    // ── customer: what the storefront offers ────────────────────────────────
    const sf = await CU.page.goto(`${base}/${slug}`, { waitUntil: 'networkidle2' });
    if (!sf || sf.status() >= 400) R.fail(`storefront → ${sf && sf.status()}`);
    // The price the customer sees on each product card (pharmacy storefront: shelf cards, [data-role="price"]).
    const shown = await CU.page.evaluate((names) => names.map((n) => {
      const nameEl = [...document.querySelectorAll('*')].find((el) => el.children.length === 0 && el.textContent.trim() === n);
      let card = nameEl;
      for (let i = 0; card && i < 6 && !card.querySelector('[data-role="price"], .cl-price, [class*="price"]'); i += 1) card = card.parentElement;
      const priceEl = card && card.querySelector('[data-role="price"], .cl-price, [class*="price"]');
      const txt = priceEl ? priceEl.textContent.replace(/[^0-9.,]/g, '').replace(',', '.') : '';
      return txt ? Number(txt) : null;
    }), [medName, cosName]);
    if (shown[0] === null || shown[1] === null) R.fail(`could not read the card prices (${JSON.stringify(shown)})`);
    if (Math.abs(shown[0] - 5) > 0.001) R.fail(`storefront medicine price ${shown[0]}, expected the list price 5.00`);
    if (Math.abs(shown[1] - 8) > 0.001) R.fail(`storefront cosmetic price ${shown[1]}, expected the sale price 8.00`);
    const html = await CU.page.content();
    if (html.includes(`${C.PREFIX}Bundle with medicine ${tag}`)) R.fail('the bundle holding a medicine is offered on the storefront');
    if (html.includes(`${C.PREFIX}Bundle cosmetics ${tag}`)) R.ok('the storefront offers the bundle without a medicine');
    else R.skip('bundle shown', 'the pharmacy storefront theme renders no bundles at all (the medicine bundle is refused server-side below)');
    await shot(CU.page, 'pharm-promo-storefront');
    R.ok('customer sees the medicine at its list price (5.00) and the cosmetic on sale (8.00); no bundle holding a medicine');

    // customer account (loyalty)
    const cust = { email: `${C.PREFIX}cust-${tag}@local.invalid`, pass: `E2eC-${tag}-Cust1!` };
    const reg = await C.call(CU.page, 'POST', `${base}/${slug}/customer/register`, {
      name: `${C.PREFIX}customer`, email: cust.email, password: cust.pass, password_confirmation: cust.pass, store_id: sid, phone: '0551234567',
    }, { json: true });
    const customer = C.one('SELECT id FROM customers WHERE email = ? AND store_id = ?', [cust.email, sid]);
    if (!customer) R.fail(`customer register → ${reg.status} ${JSON.stringify(reg.data)}`);
    await CU.page.goto(`${base}/${slug}`, { waitUntil: 'domcontentloaded' });

    // The storefront's own goods cart + checkout (what the shelf theme's buttons post).
    const buy = async (lines, extra = {}) => {
      for (const [id, q] of lines) {
        const a = await C.call(CU.page, 'POST', `${base}/${slug}/goods/${id}/cart`, { quantity: q });
        if (a.status >= 400) R.fail(`add to cart #${id} → ${a.status} ${JSON.stringify(a.data)}`);
      }
      return C.call(CU.page, 'POST', `${base}/${slug}/checkout`, Object.assign({
        type: 'ship', address: 'Olaya St 1, Riyadh', customer_name: 'E2E C Customer', phone_number: '0551234567',
      }, extra));
    };
    const lastOrder = () => C.one('SELECT id, coupon_id, discount_amount_minor FROM orders WHERE store_id = ? ORDER BY id DESC LIMIT 1', [sid]);
    const lineOf = (orderId, itemId) => C.one('SELECT unit_price_minor, price_schedule_id FROM order_items WHERE order_id = ? AND item_id = ?', [orderId, itemId]);
    const points = () => Number((C.one('SELECT balance FROM loyalty_accounts WHERE customer_id = ? AND store_id = ?', [Number(customer.id), sid]) || { balance: 0 }).balance);

    // 1. medicine + cosmetic: the cosmetic on sale (snapshot), the medicine at list price; points on the cosmetic only
    const o1 = await buy([[Number(med.id), 1], [Number(cos.id), 1]]);
    if (!o1.data || !o1.data.success) R.fail(`mixed order → ${o1.status} ${JSON.stringify(o1.data || o1.text.slice(0, 300))}`);
    const ord1 = lastOrder();
    const m1 = lineOf(Number(ord1.id), Number(med.id));
    const c1 = lineOf(Number(ord1.id), Number(cos.id));
    if (m1.price_schedule_id || !c1.price_schedule_id || Number(c1.unit_price_minor) !== 800) R.fail(`mixed order lines: medicine ${JSON.stringify(m1)}, cosmetic ${JSON.stringify(c1)}`);
    const p1 = points();
    if (p1 !== 8) R.fail(`points after the mixed order: ${p1}, expected 8 (the cosmetic's 8.00 only)`);
    R.ok('mixed order: medicine at list price, cosmetic at the sale price; 8 points (cosmetic only)');

    // 2. medicine + coupon: declined with the reason
    const o2 = await buy([[Number(med.id), 1]], { coupon_code: code });
    if (!o2.data || !o2.data.success || !o2.data.promotions_restricted || !o2.data.promotions_message) R.fail(`medicine order with a coupon → ${JSON.stringify(o2.data)}`);
    const ord2 = lastOrder();
    if (ord2.coupon_id) R.fail('the coupon was applied to a medicine order');
    if (points() !== p1) R.fail(`a medicine order earned points (${points()} vs ${p1})`);
    R.ok(`medicine order: coupon declined ("${o2.data.promotions_message}"), no points earned`);

    // 3. medicine + loyalty reward: refused, nothing spent
    const o3 = await buy([[Number(med.id), 1]], { loyalty_redeem: 1 });
    if (!o3.data || !o3.data.success || !o3.data.promotions_restricted) R.fail(`medicine order with a reward → ${JSON.stringify(o3.data)}`);
    const ord3 = lastOrder();
    if (Number(ord3.discount_amount_minor || 0) !== 0 || points() !== p1) R.fail(`reward applied on a medicine order (discount ${ord3.discount_amount_minor}, points ${points()})`);
    R.ok('medicine order: loyalty reward refused with the reason, no points spent');

    // 4. the bundle holding a medicine, posted anyway (storefront order endpoint): refused
    const comboSlot = C.one('SELECT id FROM combo_slots WHERE combo_id = ?', [Number(medCombo.id)]);
    const comboOpt = C.one('SELECT id FROM combo_slot_options WHERE combo_id = ? AND menu_id = ?', [Number(medCombo.id), Number(cos.id)]);
    const before = Number(C.one('SELECT COUNT(*) AS c FROM orders WHERE store_id = ?', [sid]).c);
    const o4 = await C.call(CU.page, 'POST', `${base}/${sid}/send-order`, {
      name: 'E2E C Customer', 'phone-number': '0551234567', 'ordering-type': 'takeaway', pay_via: 'cash', total: '0', items: '[]',
      combos: JSON.stringify([{ combo_id: Number(medCombo.id), quantity: 1, picks: [{ slot_id: Number(comboSlot.id), option_ids: [Number(comboOpt.id)] }] }]),
    });
    const after = Number(C.one('SELECT COUNT(*) AS c FROM orders WHERE store_id = ?', [sid]).c);
    const why = JSON.stringify(o4.data || {});
    if ((o4.data && o4.data.success) || after !== before || !/medicines/i.test(why)) R.fail(`a bundle holding a medicine was not refused for that reason → ${o4.status} ${why}`);
    R.ok(`a posted bundle holding a medicine is refused with the reason (${o4.status})`);

    // ── owner sees the result ───────────────────────────────────────────────
    const orders = C.sql('SELECT id, coupon_id, discount_amount_minor FROM orders WHERE store_id = ? ORDER BY id', [sid]);
    await O.page.goto(`${base}/restaurant/${sid}/loyalty`, { waitUntil: 'domcontentloaded' });
    const loyaltyPage = await O.page.content();
    if (orders.length !== 3 || orders.some((o) => o.coupon_id)) R.fail(`owner orders: ${JSON.stringify(orders)}`);
    const acct = loyaltyPage.match(new RegExp(`#${customer.id}\\s*</td>\\s*<td[^>]*>\\s*([0-9,]+)`));
    if (!acct || Number(acct[1].replace(/,/g, '')) !== 8) R.fail(`owner loyalty page: customer #${customer.id} balance ${acct ? acct[1] : '(not listed)'}, expected 8`);
    const hub = await O.page.goto(`${base}/orders/${sid}`, { waitUntil: 'domcontentloaded' });
    const hubHtml = await O.page.content();
    const listed = orders.filter((o) => hubHtml.includes(`#${o.id}`) || hubHtml.includes(`"${o.id}"`) || hubHtml.includes(`/${o.id}`)).length;
    if (!hub || hub.status() >= 400) R.fail(`owner orders page → ${hub && hub.status()}`);
    log(`  pharm-promo owner orders page lists ${listed}/${orders.length} of the orders by id`);
    await shot(O.page, 'pharm-promo-owner-loyalty');
    R.ok('owner: 3 orders, none couponed, no reward; the customer is in the loyalty list with the cosmetic\'s 8 points only');

    // ── IDOR ────────────────────────────────────────────────────────────────
    const X = await C.actorPage(browser);
    try {
      await C.login(X.page, base, other.email, other.pass);
      const r = await X.page.goto(`${base}/stores/${sid}/pharmacy`, { waitUntil: 'domcontentloaded' });
      if (!r || r.status() < 400) R.fail(`IDOR: another owner opened this pharmacy's compliance page (${r && r.status()})`);
      R.ok(`IDOR: another owner cannot open this pharmacy's compliance page (${r.status()})`);
    } finally { await X.close(); }
  } finally {
    await O.close();
    await CU.close();
  }
  R.summary();
};
