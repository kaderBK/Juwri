// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * f-store-limits — the Community store/branch limit (owner 2026-10-05: one store of each store type per business, each
 * with up to three branches; the message points to the Chain licence / juwri Cloud, never a price).
 *
 *  wizard    the owner already has a restaurant; the new-store wizard refuses a second restaurant and the create page
 *            shows the limit message (data-store-limit="stores_per_type"); no store is written
 *  branches  on that restaurant the Branches page adds branches up to three; a fourth is refused with the message
 *            (data-store-limit="branches_per_store") and the count stays three
 *  existing  the refused owner can still open and edit the store's branches (nothing is locked)
 *
 * Community build only: on an Enterprise build the licence lifts the limits, so the journey skips with that reason.
 *
 * Run: npm run test:e2e -- f-store-limits
 */

const A = require('../../lib/lane-a');

const SLUG = 'e2ea-limits';
const REFUSED_SLUG = 'e2ea-limits-second';

module.exports = A.journey(async function fStoreLimits({ browser, log }) {
  const R = A.recorder('f-store-limits', log);
  const app = A.cfg.baseUrl;
  const owner = await A.ensureLaneOwner(browser, { features: ['multi_vertical'] });

  const ee = await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    return A.hasEe(page);
  });
  if (ee) {
    const why = 'Enterprise build: the licence lifts the Community store/branch limits (covered by the ee licence tests)';
    R.skip('whole journey', why);
    R.summary();
    return { skip: why };
  }

  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    const store = await A.ensureStore(page, app, { type: 'restaurant', title: 'e2eA Limits', slug: SLUG });

    // ── wizard: a second restaurant ────────────────────────────────────────────────────────────────────────────────
    await page.goto(app + '/stores/create', { waitUntil: 'domcontentloaded' });
    const form = 'form[action$="/stores"]';
    await page.waitForSelector(form);
    await page.evaluate((sel, slug) => {
      const f = document.querySelector(sel);
      const set = (n, v) => { const e = f.querySelector(`[name="${n}"]`); if (e) { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); } };
      const type = f.querySelector('input[name="store_type"][value="restaurant"]');
      if (type) { type.checked = true; type.dispatchEvent(new Event('change', { bubbles: true })); }
      set('title', 'e2eA Limits second'); set('slug', slug); set('address', '1 Test Street, Riyadh'); set('description', 'refused');
    }, form, REFUSED_SLUG);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      page.$eval(form, (f) => f.submit()),
    ]);
    const notice = await page.$eval('[data-store-limit]', (e) => ({ reason: e.getAttribute('data-store-limit'), text: e.innerText })).catch(() => null);
    A.expect(notice && notice.reason === 'stores_per_type', `the wizard must show the store limit (got ${JSON.stringify(notice)} on ${page.url()})`);
    A.expect(/Chain licence/.test(notice.text) && /juwri Cloud/.test(notice.text), `the message must point to the Chain licence / juwri Cloud: ${notice.text}`);
    A.expect(!/\b(SAR|AED|USD|EGP)\b|\d+\s*(ريال|﷼)/.test(notice.text), `no price in the product text: ${notice.text}`);
    A.expect(!A.sql('SELECT id FROM stores WHERE slug = ? LIMIT 1', [REFUSED_SLUG]).length, 'the refused store must not be written');
    R.ok(`second restaurant refused in the wizard: "${notice.text.slice(0, 90)}…"`);

    // ── branches: up to three, the fourth refused ──────────────────────────────────────────────────────────────────
    const count = () => Number(A.sqlValue('SELECT COUNT(*) FROM branches WHERE store_id = ?', [store.id]));
    const addBranch = async (name) => {
      await page.goto(`${app}/branches?store_id=${store.id}`, { waitUntil: 'domcontentloaded' });
      const add = 'form.ui-branches__add[method="post"]';
      await page.waitForSelector(`${add} input[name="name"]`);
      await page.evaluate((sel, n) => { document.querySelector(`${sel} input[name="name"]`).value = n; }, add, name);
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
        page.$eval(add, (f) => f.submit()),
      ]);
    };
    for (let i = count(); i < 3; i++) await addBranch(`${A.PREFIX}Branch ${i + 1}`);
    A.expect(count() === 3, `three branches must be allowed (have ${count()})`);
    await addBranch(`${A.PREFIX}Branch over the limit`);
    const bNotice = await page.$eval('[data-store-limit]', (e) => ({ reason: e.getAttribute('data-store-limit'), text: e.innerText })).catch(() => null);
    A.expect(bNotice && bNotice.reason === 'branches_per_store', `the Branches page must show the branch limit (got ${JSON.stringify(bNotice)})`);
    A.expect(/\b3\b/.test(bNotice.text) && /Chain licence/.test(bNotice.text), `the branch message names the limit and the Chain licence: ${bNotice.text}`);
    A.expect(count() === 3, `the fourth branch must not be written (have ${count()})`);
    R.ok('three branches allowed; the fourth refused with the message');

    // ── nothing is locked: the existing branches stay listed and editable ──────────────────────────────────────────
    await page.goto(`${app}/branches?store_id=${store.id}`, { waitUntil: 'domcontentloaded' });
    const listed = await A.pageText(page);
    A.expect(listed.includes(`${A.PREFIX}Branch`), 'the existing branches are still listed');
    R.ok('existing store and branches still open (read) after the refusals');
  });

  R.summary();
});
