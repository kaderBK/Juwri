// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * a-pharmacy — P1-12 item 9 (a): the pharmacy (OTC) store type, owner → customer → owner, plus cross-store IDOR.
 *
 *  owner      creates the store through the dashboard wizard (store type "Pharmacy (OTC)"), a category and products,
 *             flags one product age-restricted and one "not a medicine", creates a coupon, uploads licence documents
 *  admin      reviews the licence (reject with a note → owner sees it → corrected upload → approve)
 *  gate       the storefront is a 404 until the licence is approved AND the country is switched on
 *             (PHARMACY_ENABLED_COUNTRIES is empty by default: on juw.test it stays unpublished even when approved)
 *  customer   (armed instance only) age confirm before add-to-cart, per-order quantity cap (market cap, then the
 *             store's lower cap, none on a non-medicine), a promo code refused on a medicine — at the pre-flight and
 *             at settlement — and a placed order
 *  owner      sees the order on the orders board
 *  IDOR       another owner cannot read or change this store's compliance page, licence upload, settings or items;
 *             a store owner cannot reach the admin licence review or the licence scan
 *
 * Legs that need the country switch run on E2E_ARMED_URL (a second `php artisan serve` of the same code and database
 * with PHARMACY_ENABLED_COUNTRIES=SA — see lib/lane-a.js) and skip with a reason without it.
 *
 * TODO(lane C): the extra promotion rules (gift cards, loyalty redemption, free-delivery coupons, BOGO on medicines)
 * land with Lane C's pharmacy promotion work — add their asserts next to the coupon leg below.
 *
 * Run: npm run test:e2e -- a-pharmacy   (E2E_BASE_URL=https://juw.test E2E_ARMED_URL=http://127.0.0.1:8766)
 */

const A = require('../../lib/lane-a');

const SLUG = 'e2ea-pharmacy';
const TITLE = 'e2eA Pharmacy';
const COUPON = 'E2EAPH10';

module.exports = A.journey(async function aPharmacy({ browser, log }) {
  const R = A.recorder('a-pharmacy', log);
  const herd = A.cfg.baseUrl;
  const armed = A.cfg.armed;

  // ── setup: lane owner (superadmin dashboard) + store, catalog, flags, coupon (owner dashboard) ─────────────────────
  const owner = await A.ensureLaneOwner(browser);
  R.ok(`lane owner #${owner.id} provisioned through the superadmin dashboard (plan + multi_vertical grant)`);

  const store = await A.withPage(browser, async (page) => {
    await A.login(page, herd, { email: owner.email, pass: owner.pass });
    const s = await A.ensureStore(page, herd, { type: 'pharmacy', title: TITLE, slug: SLUG, template: 'carely' });
    A.expect(A.sqlValue('SELECT store_type FROM stores WHERE id = ?', [s.id]) === 'pharmacy', 'store was not created as a pharmacy');
    const cat = await A.ensureProductCategory(page, herd, s.id, `${A.PREFIX}Pain relief`);
    const med = await A.ensureProduct(page, herd, s.id, { name: `${A.PREFIX}Paracetamol 500mg`, price: '4.50', categoryId: cat, stock: 50 });
    const age = await A.ensureProduct(page, herd, s.id, { name: `${A.PREFIX}Cough syrup`, price: '7.25', categoryId: cat, stock: 50 });
    const plasters = await A.ensureProduct(page, herd, s.id, { name: `${A.PREFIX}Plasters`, price: '2.00', categoryId: cat, stock: 50 });

    // compliance flags through the OTC compliance form
    const flag = async (product, { ageRestricted, notMedicine }) => {
      await page.goto(`${herd}/stores/${s.id}/pharmacy/${product.id}/edit`, { waitUntil: 'domcontentloaded' });
      await page.evaluate((a, n) => {
        const set = (id, on) => { const c = document.getElementById(id); if (c && c.checked !== on) c.click(); };
        set('age_restricted', a); set('not_medicine', n);
      }, ageRestricted, notMedicine);
      await A.submitForm(page, `form[action$="/pharmacy/${product.id}"]`);
    };
    await flag(med, { ageRestricted: false, notMedicine: false });
    await flag(age, { ageRestricted: true, notMedicine: false });
    await flag(plasters, { ageRestricted: false, notMedicine: true });
    const f = (id) => A.sql('SELECT age_restricted, is_medicine FROM items WHERE id = ?', [id])[0].map(Number);
    A.expect(f(med.id).join() === '0,1', 'paracetamol must be a plain medicine');
    A.expect(f(age.id).join() === '1,1', 'cough syrup must be an age-restricted medicine');
    A.expect(f(plasters.id).join() === '0,0', 'plasters must be flagged "not a medicine"');

    // no own cap from an earlier run; coupon through the coupons form
    await page.goto(`${herd}/stores/${s.id}/pharmacy`, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { const q = document.querySelector('input[name=quantity_cap]'); if (q) q.value = '0'; });
    await A.submitForm(page, `form[action$="/stores/${s.id}/pharmacy/settings"]`);
    const couponId = await A.ensureCoupon(page, herd, owner.id, COUPON, 10);
    return { ...s, med, age, plasters, couponId };
  });
  R.ok(`store #${store.id} "${SLUG}" (pharmacy) + 3 products + coupon ${COUPON} set up in the owner dashboard; flags saved (age-restricted / not a medicine)`);

  // ── gate: licence upload → admin review → publish (country switch off by default) ─────────────────────────────────
  const uploadLicence = (page, base, number) => (async () => {
    await page.goto(`${base}/stores/${store.id}/pharmacy`, { waitUntil: 'domcontentloaded' });
    const f = `form[action$="/stores/${store.id}/pharmacy/licence"]`;
    await page.type(`${f} [name=country]`, 'SA');
    await page.type(`${f} [name=licence_number]`, number);
    await page.type(`${f} [name=issuing_authority]`, `${A.PREFIX}Authority`);
    await page.evaluate((sel) => { document.querySelector(`${sel} [name=expires_on]`).value = new Date(Date.now() + 400 * 864e5).toISOString().slice(0, 10); }, f);
    await (await page.$(`${f} [name=document]`)).uploadFile(A.pngFile());
    await A.submitForm(page, f);
  })();
  const licenceRow = (number) => A.sql('SELECT id, status, review_note FROM pharmacy_licences WHERE store_id = ? AND licence_number = ? ORDER BY id DESC LIMIT 1', [store.id, number])[0];
  const stamp = Date.now();

  await A.withPage(browser, async (owner1) => {
    await A.withPage(browser, async (admin) => {
      await A.login(owner1, herd, { email: owner.email, pass: owner.pass });
      await A.login(admin, herd, { email: A.cfg.adminEmail, pass: A.cfg.adminPass });

      // reject path
      const lic1 = `e2eA-LIC-${stamp}-R`;
      await uploadLicence(owner1, herd, lic1);
      const r1 = licenceRow(lic1);
      A.expect(r1 && r1[1] === 'pending', 'an uploaded licence must wait for review');
      A.expect(await A.publicStatus(browser, `${herd}/${SLUG}`) === 404, 'the storefront must be a 404 while the licence is pending');
      R.ok('licence upload → pending; storefront is a 404 (not published)');

      await admin.goto(`${herd}/admin/pharmacy-licences`, { waitUntil: 'domcontentloaded' });
      const adminText = await A.pageText(admin);
      A.expect(/Pharmacy selling is not enabled in any country/.test(adminText) || /enabled in/.test(adminText), 'the admin licence page must say which countries are enabled');
      A.expect(adminText.includes(lic1), 'the pending licence must be listed for the reviewer');
      await admin.evaluate((id) => {
        const f = document.querySelector(`form[action$="/admin/pharmacy-licences/${id}/reject"]`);
        f.querySelector('[name=review_note]').value = 'e2eA: scan unreadable, please upload again';
      }, r1[0]);
      await A.submitForm(admin, `form[action$="/admin/pharmacy-licences/${r1[0]}/reject"]`);
      A.expect(licenceRow(lic1)[1] === 'rejected', 'the reviewer rejection did not stick');
      await owner1.goto(`${herd}/stores/${store.id}/pharmacy`, { waitUntil: 'domcontentloaded' });
      const rejectedText = await A.pageText(owner1);
      A.expect(/not accepted|were not accepted|e2eA: scan unreadable/i.test(rejectedText), `the owner must see the rejection (${rejectedText.slice(0, 300)})`);
      A.expect(await A.publicStatus(browser, `${herd}/${SLUG}`) === 404, 'a rejected licence keeps the storefront unpublished');
      R.ok('admin rejects with a note → owner sees "not accepted" + the note; storefront still a 404');

      // approve path
      const lic2 = `e2eA-LIC-${stamp}-A`;
      await uploadLicence(owner1, herd, lic2);
      const r2 = licenceRow(lic2);
      A.expect(r2 && r2[1] === 'pending', 'the corrected upload must wait for review');
      await admin.goto(`${herd}/admin/pharmacy-licences`, { waitUntil: 'domcontentloaded' });
      await A.submitForm(admin, `form[action$="/admin/pharmacy-licences/${r2[0]}/approve"]`);
      A.expect(licenceRow(lic2)[1] === 'approved', 'the approval did not stick');
      R.ok('corrected upload → admin approves');

      // country switch off by default: approved, but still not published on juw.test
      A.expect(await A.publicStatus(browser, `${herd}/${SLUG}`) === 404, 'an approved licence must NOT publish the store while the country switch is off');
      await owner1.goto(`${herd}/stores/${store.id}/pharmacy`, { waitUntil: 'domcontentloaded' });
      const offText = await A.pageText(owner1);
      A.expect(/not enabled in your country/i.test(offText), `the owner must be told the country is not enabled (${offText.slice(0, 300)})`);
      R.ok('country switch OFF (default): licence approved yet the storefront is a 404 and the owner is told "not enabled in your country"');
    });
  });

  // ── customer leg (armed instance: country switch on) ─────────────────────────────────────────────────────────────
  if (!armed) {
    R.skip('customer leg (age confirm, quantity cap, coupon refused, order)', 'E2E_ARMED_URL not set — the country switch is off on this site, the storefront never opens');
  } else {
    await A.withPage(browser, async (oa) => {
      await A.login(oa, armed, { email: owner.email, pass: owner.pass });
      await oa.goto(`${armed}/stores/${store.id}/pharmacy`, { waitUntil: 'domcontentloaded' });
      A.expect(/Published: pharmacy selling is enabled/.test(await A.pageText(oa)), 'with the country on and the licence approved the owner must see "Published"');
      R.ok('country switch ON + approved licence: owner page says Published');

      await A.withPage(browser, async (c) => {
        const click = (txt) => c.evaluate((t) => {
          const b = [...document.querySelectorAll('button')].filter((x) => x.offsetParent !== null).find((x) => x.innerText.trim().startsWith(t));
          if (b) b.click(); return !!b;
        }, txt);
        const cartPosts = [];
        c.on('response', async (r) => {
          if (r.request().method() === 'POST' && /\/goods\/\d+\/cart/.test(r.url())) {
            cartPosts.push({ status: r.status(), body: await r.text().catch(() => '') });
          }
        });
        const openItem = async (id) => {
          const ok = await c.evaluate((i) => { const b = document.querySelector(`[data-open-item-link="${i}"]`); if (b) b.click(); return !!b; }, id); // the card image opens the sheet; the + button quick-adds
          if (!ok) throw new Error(`no [data-open-item-link=${id}] on ${c.url()}: ${(await A.pageText(c)).slice(0, 200)}`);
          await A.sleep(500);
        };
        // a fresh page load is the robust way back to the shelf (the sheet pushes history entries); the cart is in the session
        const closeSheet = async () => { await c.goto(`${armed}/${SLUG}`, { waitUntil: 'networkidle2' }); await click('Continue'); await A.sleep(300); };

        const resp = await c.goto(`${armed}/${SLUG}`, { waitUntil: 'networkidle2' });
        A.expect(resp.status() === 200, `the published pharmacy storefront must open (got ${resp.status()})`);
        await c.setViewport({ width: 430, height: 1100 });
        R.ok('published pharmacy storefront opens (200)');
        await click('Continue'); await A.sleep(400); // the OTC disclaimer gate

        // age confirm at add-to-cart
        await openItem(store.age.id);
        const gated = await c.evaluate(() => { const g = document.querySelector('.cl-agegate'); return !!g && g.offsetParent !== null; });
        A.expect(gated, 'an age-restricted item must show the age confirmation in its sheet');
        await click('Add to cart'); await A.sleep(600); // before confirming: the theme keeps the gate up and posts nothing
        A.expect(cartPosts.length === 0, 'Add to cart before the age is confirmed must not reach the cart');
        A.expect(await c.evaluate(() => { const g = document.querySelector('.cl-agegate'); return !!g && g.offsetParent !== null; }), 'the age gate must stay up after a premature Add to cart');
        await click('Cancel'); await A.sleep(300);
        A.expect(cartPosts.length === 0, 'cancelling the age confirmation must not add anything to the cart');
        await openItem(store.age.id);
        A.expect(await click('Confirm'), 'no Confirm button in the age gate');
        await A.sleep(400);
        A.expect(await click('Add to cart'), 'Add to cart must appear after the age is confirmed');
        await A.sleep(900);
        A.expect(cartPosts.length === 1 && cartPosts[0].status === 200, `the confirmed add must reach the cart (${JSON.stringify(cartPosts)})`);
        R.ok('age-restricted item: Add to cart does nothing before Confirm; Cancel adds nothing; confirmed add lands in the server cart');
        await closeSheet();

        // quantity cap (market cap 2) on a medicine, in the item sheet
        const addQty = async (id, clicksOnPlus) => {
          await openItem(id);
          for (let i = 0; i < clicksOnPlus; i += 1) {
            await c.evaluate(() => [...document.querySelectorAll('button')].filter((b) => b.offsetParent !== null).find((b) => b.innerText.trim() === '+')?.click());
            await A.sleep(150);
          }
          const before = cartPosts.length;
          await click('Add to cart'); await A.sleep(1000);
          const last = cartPosts.length > before ? cartPosts[cartPosts.length - 1] : null;
          await closeSheet();
          return last;
        };
        const over = await addQty(store.med.id, 2); // 3 units, cap is 2
        A.expect(over && over.status === 422 && /at most 2/.test(over.body), `3 units of a medicine must be refused with the market cap (${JSON.stringify(over)})`);
        const atCap = await addQty(store.med.id, 1); // 2 units
        A.expect(atCap && atCap.status === 200, `2 units (the cap) must be accepted (${JSON.stringify(atCap)})`);
        R.ok('quantity cap: 3 of a medicine refused ("at most 2"), 2 accepted');
        const manyPlasters = await addQty(store.plasters.id, 4); // 5 units of a non-medicine
        A.expect(manyPlasters && manyPlasters.status === 200, `a non-medicine is not capped (${JSON.stringify(manyPlasters)})`);
        R.ok('a product flagged "not a medicine" is not capped (5 plasters accepted)');

        // checkout: promo code refused on a medicine, order placed at full price
        await c.evaluate(() => document.querySelector('[data-tab="cart"]').click()); await A.sleep(700);
        A.expect(await click('Checkout'), 'no Checkout button in the cart');
        await A.sleep(900);
        await c.type('input[name=name]', `${A.PREFIX}Customer ${stamp}`);
        await c.type('input[name=phone]', '+966500000777');
        await c.select('select[name=mode]', 'in_store');
        const couponUi = await c.evaluate(async (code) => {
          const apply = [...document.querySelectorAll('button')].find((b) => b.innerText.trim() === 'Apply');
          const input = apply.parentElement.querySelector('input');
          input.value = code; input.dispatchEvent(new Event('input', { bubbles: true }));
          apply.click();
          await new Promise((r) => setTimeout(r, 1500));
          return (document.getElementById('clCouponMsg') || {}).innerText || '';
        }, COUPON);
        A.expect(/cannot be used on orders that contain medicines/i.test(couponUi), `the coupon pre-flight must refuse a code on a medicine order (got "${couponUi}")`);
        R.ok('promo code on a medicine order: refused at the pre-flight with a plain message');
        const usedBefore = Number(A.sqlValue('SELECT `used` FROM coupons WHERE id = ?', [store.couponId]));
        const placed = c.waitForResponse((r) => /\/checkout$/.test(r.url()) && r.request().method() === 'POST');
        A.expect(await click('Place order'), 'no Place order button');
        const placedBody = await (await placed).json();
        A.expect(placedBody.success === true, `the order must be placed (${JSON.stringify(placedBody)})`);
        const order = A.sql('SELECT id, coupon_id, discount_amount_minor, subtotal_minor, grand_total_minor, customer_name FROM orders WHERE track_token = ?', [placedBody.track_token])[0];
        A.expect(order, 'the placed order is not in the database');
        A.expect(order[1] === null && Number(order[2]) === 0, `a medicine order must carry no coupon and no discount (coupon_id=${order[1]}, discount=${order[2]})`);
        A.expect(Number(order[3]) === Number(order[4]), 'the order is settled at full price (subtotal = grand total, no tax set)');
        A.expect(Number(A.sqlValue('SELECT `used` FROM coupons WHERE id = ?', [store.couponId])) === usedBefore, 'the refused coupon must not be burned');
        R.ok(`order #${order[0]} placed at full price, no coupon recorded, coupon use count untouched`);
        // TODO(lane C): promotion rules beyond "no coupon on a medicine" — add the asserts here.

        // owner sees the order on the board
        await A.withPage(browser, async (ob) => {
          await A.login(ob, herd, { email: owner.email, pass: owner.pass });
          await ob.goto(`${herd}/orders/${store.id}`, { waitUntil: 'domcontentloaded' });
          await A.sleep(700);
          const board = await ob.evaluate(() => [...document.querySelectorAll('article.ui-order')].map((a) => a.innerText.replace(/\s+/g, ' ')));
          A.expect(board.some((t) => t.includes(`#${order[0]}`) && t.includes(`${A.PREFIX}Customer ${stamp}`)), `the owner's orders board must show order #${order[0]} (${board.slice(0, 2).join(' || ')})`);
          R.ok('the owner sees the order on the orders board');
        });

        // store's own lower cap (owner dashboard) is enforced at add-to-cart
        await A.withPage(browser, async (os) => {
          await A.login(os, herd, { email: owner.email, pass: owner.pass });
          await os.goto(`${herd}/stores/${store.id}/pharmacy`, { waitUntil: 'domcontentloaded' });
          await os.evaluate(() => { document.querySelector('input[name=quantity_cap]').value = '1'; });
          await A.submitForm(os, `form[action$="/stores/${store.id}/pharmacy/settings"]`);
          await os.goto(`${herd}/stores/${store.id}/pharmacy`, { waitUntil: 'domcontentloaded' });
          A.expect(await os.evaluate(() => document.querySelector('input[name=quantity_cap]').value) === '1', 'the own cap did not save');
        });
        const lowCap = await addQty(store.med.id, 1); // the cart already holds 2: any add is above 1
        A.expect(lowCap && lowCap.status === 422 && /at most 1/.test(lowCap.body), `the store's own cap of 1 must be enforced (${JSON.stringify(lowCap)})`);
        R.ok('the store can lower the cap (own cap 1 enforced at add-to-cart)');
        await A.withPage(browser, async (os) => { // leave the store as found
          await A.login(os, herd, { email: owner.email, pass: owner.pass });
          await os.goto(`${herd}/stores/${store.id}/pharmacy`, { waitUntil: 'domcontentloaded' });
          await os.evaluate(() => { document.querySelector('input[name=quantity_cap]').value = '0'; });
          await A.submitForm(os, `form[action$="/stores/${store.id}/pharmacy/settings"]`);
        });
      }, { width: 430, height: 1100 });
    });
  }

  // ── cross-store IDOR ──────────────────────────────────────────────────────────────────────────────────────────────
  const licenceId = A.sqlValue('SELECT id FROM pharmacy_licences WHERE store_id = ? ORDER BY id DESC LIMIT 1', [store.id]);
  const otherStore = A.sql("SELECT id, user_id FROM stores WHERE store_type = 'pharmacy' AND id <> ? AND user_id <> ? ORDER BY id LIMIT 1", [store.id, owner.id])[0];
  await A.withPage(browser, async (other) => {
    await A.login(other, herd, { email: A.cfg.otherOwnerEmail, pass: A.cfg.otherOwnerPass });
    const base = `${herd}/stores/${store.id}/pharmacy`;
    A.expect(await A.status(other, base) === 404, "another owner must get a 404 on this store's compliance page");
    A.expect(await A.status(other, `${base}/${store.med.id}/edit`) === 404, "another owner must get a 404 on this store's item compliance form");
    const post = (url, form) => A.req(other, url, { method: 'POST', form: Object.assign({ _method: 'PUT' }, form) });
    A.expect((await post(`${base}/settings`, { quantity_cap: '1' })).status === 404, "another owner must not change this store's settings");
    A.expect((await post(`${base}/${store.med.id}`, { age_restricted: '1' })).status === 404, "another owner must not change this store's product flags");
    const lic = await A.req(other, `${base}/licence`, { method: 'POST', form: { country: 'SA', licence_number: 'x', issuing_authority: 'x', expires_on: '2099-01-01' } });
    A.expect([404, 419].includes(lic.status), `another owner must not upload a licence for this store (${lic.status})`);
    // and a non-admin must not reach the platform's review
    const adminList = await A.status(other, `${herd}/admin/pharmacy-licences`);
    A.expect(adminList !== 200, `a store owner must not open the licence review (${adminList})`);
    const scan = await A.status(other, `${herd}/admin/pharmacy-licences/${licenceId}/document`);
    A.expect(scan !== 200, `a store owner must not download a licence scan (${scan})`);
    const reviewedBefore = A.sql('SELECT reviewed_by, reviewed_at FROM pharmacy_licences WHERE id = ?', [licenceId])[0].join('|');
    await A.req(other, `${herd}/admin/pharmacy-licences/${licenceId}/approve`, { method: 'POST', form: {} });
    await A.req(other, `${herd}/admin/pharmacy-licences/${licenceId}/reject`, { method: 'POST', form: { review_note: 'outsider' } });
    A.expect(A.sql('SELECT reviewed_by, reviewed_at FROM pharmacy_licences WHERE id = ?', [licenceId])[0].join('|') === reviewedBefore, 'a licence review must not be changed by a store owner');
    R.ok("IDOR: another owner gets 404 on this store's compliance page, item form, settings, product flags and licence upload; no store owner reaches the admin review or the scan");
  });
  await A.withPage(browser, async (mine) => { // and the other direction: the lane owner on a store that is not theirs
    await A.login(mine, herd, { email: owner.email, pass: owner.pass });
    if (otherStore) {
      A.expect(await A.status(mine, `${herd}/stores/${otherStore[0]}/pharmacy`) === 404, "the lane owner must get a 404 on someone else's pharmacy");
      R.ok("IDOR (reverse): the lane owner gets 404 on another owner's pharmacy compliance page");
    } else {
      R.skip('IDOR reverse leg', 'no second pharmacy store in the database');
    }
  });
  // an anonymous visitor
  await A.withPage(browser, async (anon) => {
    await anon.goto(`${herd}/login`, { waitUntil: 'domcontentloaded' });
    const s = await A.status(anon, `${herd}/stores/${store.id}/pharmacy`);
    A.expect(s === 'redirect' || s === 401 || s === 403 || s === 404, `an anonymous visitor must not read the compliance page (${s})`);
    R.ok('IDOR: an anonymous visitor is bounced from the compliance page');
  });

  R.summary();
});
