// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * b-accounting — P1-12 item 9 (b): the books follow the money, owner → customer → owner, plus cross-store IDOR.
 * TOUCHES PAYMENTS AND TENANCY — for coordinator review.
 *
 *  owner      gets the built-in accounting (the accounting_gl grant), creates two food stores through the dashboard
 *             wizard, each with a menu category and one dish
 *  customer   pays online through the Stub gateway on store A (2 dishes, then 1 dish) and on store B (1 dish); one more
 *             order on store A is left unpaid (pay on counter)
 *  owner      Books > Journals shows one balanced sales journal per PAID order (cash/clearing debited, revenue
 *             credited, unpaid order absent); Reports shows the trial balance balanced and the P&L revenue equal to the
 *             sum of the paid orders; store B's books hold only store B's order (per-store isolation)
 *  refund     the owner refunds part of a paid order to its original payment: a refund journal appears, the P&L revenue
 *             falls by exactly that amount, the trial balance stays balanced; a second partial refund is booked too, a replay and an over-refund post nothing
 *  register   a cash sale rung at the owner's POS register lands in the books as a cash-on-hand journal (fixed on lane/a:
 *             register sales, split bills and refunds to the original payment used to skip the books)
 *  IDOR       another owner cannot open this store's journals or reports (403), cannot refund its orders, and
 *             an anonymous visitor is sent to the login
 *
 * Needs the Stub gateway: E2E_STUBS armed on the serving app AND the `stub` payment_gateways row (seeded here through
 * E2eLaneAJourneysSeeder, which also switches the accounting feature on platform-wide). Runs on E2E_ARMED_URL; without it the journey skips with the reason.
 *
 * Run: E2E_ARMED_URL=… npm run test:e2e -- b-accounting
 */

const crypto = require('crypto');
const A = require('../../lib/lane-a');
const K = require('../../lib/order-kit-v2');

const SLUG_A = 'e2ea-books-a';
const SLUG_B = 'e2ea-books-b';
const DISH_A = { name: `${A.PREFIX}Falafel plate`, price: 20 };
const DISH_B = { name: `${A.PREFIX}Shawarma wrap`, price: 15 };

module.exports = A.journey(async function bAccounting({ browser, log }) {
  const R = A.recorder('b-accounting', log);
  const stamp = Date.now();
  const app = A.cfg.armed;
  if (!app) {
    const why = 'needs the Stub gateway: set E2E_ARMED_URL to an instance of this code with E2E_STUBS=1 (the Herd site has no E2E_STUBS)';
    R.skip('whole journey', why);
    R.summary();
    return { skip: why }; // run.js counts it as skipped, never as a pass
  }
  const owner = await A.ensureLaneOwner(browser, { features: ['multi_vertical', 'accounting_gl'] });

  // ── owner: two stores with a dish each ──────────────────────────────────────────────────────────────────────────
  A.seedLaneFixtures();
  const stores = {};
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    for (const [key, slug, title, dish] of [['a', SLUG_A, 'e2eA Books A', DISH_A], ['b', SLUG_B, 'e2eA Books B', DISH_B]]) {
      const s = await A.ensureStore(page, app, { type: 'restaurant', title, slug });
      const cat = await A.ensureMenuCategory(page, app, s.id, `${A.PREFIX}Mains`);
      const item = await A.ensureMenuItem(page, app, s.id, { ...dish, categoryId: cat });
      stores[key] = { ...s, item };
    }
    for (const p of ['/books', '/books/journals', '/books/reports']) {
      A.expect(await A.status(page, app + p) === 200, `the owner must reach ${p} once accounting_gl is granted`);
    }
  });
  R.ok(`accounting granted to the owner; stores #${stores.a.id} and #${stores.b.id} with one dish each created in the dashboard; Stub gateway installed`);

  const journalsOf = (storeId) => A.sql("SELECT j.id, j.rule_key, j.source_id FROM accounting_journals j WHERE j.store_id = ? AND j.source_type = 'Order' ORDER BY j.id", [storeId]);
  const linesOf = (journalId) => A.sql('SELECT a.code, l.debit_minor, l.credit_minor FROM accounting_journal_lines l JOIN accounting_accounts a ON a.id = l.gl_account_id WHERE l.journal_id = ?', [journalId])
    .map(([code, d, c]) => ({ code, debit: Number(d) / 100, credit: Number(c) / 100 }));
  const near = (a, b) => Math.abs(a - b) < 0.005;
  const reportNumbers = async (page, storeId) => {
    await page.goto(`${app}/books/reports?store_id=${storeId}`, { waitUntil: 'domcontentloaded' });
    return page.evaluate(() => {
      const t = document.body.innerText.replace(/\s+/g, ' ');
      const num = (re) => { const m = t.match(re); return m ? parseFloat(m[1].replace(/,/g, '')) : null; };
      return { revenue: num(/Total revenue\s*(-?[\d,]+\.\d+)/i), balanced: /Balanced/.test(t), out: /\bOut\b/.test(t) };
    });
  };

  // the books before this run's orders (the stores and their journals persist between runs)
  const base = {};
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    base.a = await reportNumbers(page, stores.a.id);
    base.b = await reportNumbers(page, stores.b.id);
    base.journalId = Number(A.sqlValue('SELECT COALESCE(MAX(id), 0) FROM accounting_journals'));
  });

  // ── customer: pays through the Stub gateway ─────────────────────────────────────────────────────────────────────
  const place = async (c, store, quantity, pay) => {
    const rt = await K.loadRuntime(c, `${app}/${store.slug}`, { slug: store.slug });
    const res = await K.submitOrder(c, {
      rt, items: [{ id: store.item.id, quantity }],
      fields: { name: `${A.PREFIX}Diner`, 'ordering-type': 'takeaway', 'phone-number': '+966512345678', pay_via: pay, client_uuid: crypto.randomUUID() },
    });
    A.expect(res.success, `order rejected on ${store.slug}: ${JSON.stringify(res)}`);
    const token = K.trackToken(res.track_url);
    const row = A.sql('SELECT id, grand_total_minor, is_paid FROM orders WHERE track_token = ?', [token])[0];
    const txn = (String(res.message || '').match(/\/payment\/(\d+)/) || [])[1];
    return { id: Number(row[0]), totalMinor: Number(row[1]), paid0: Number(row[2]), txn: txn ? Number(txn) : null };
  };
  const payStub = async (c, txn) => c.evaluate(async (id, csrf) => {
    const fd = new FormData(); fd.append('payment_method', 'stub'); fd.append('e2e_outcome', 'success');
    const r = await fetch(`/payment/${id}/pay`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'text/html,application/json', 'X-CSRF-TOKEN': csrf }, body: fd });
    return r.status;
  }, txn, await A.storefrontCsrf(c));

  const orders = {};
  await A.withPage(browser, async (c) => {
    for (const [key, store, qty] of [['a1', stores.a, 2], ['a2', stores.a, 1], ['b1', stores.b, 1]]) {
      const o = await place(c, store, qty, 'pay_online');
      A.expect(o.paid0 === 0 && o.txn, `order ${key} must be unpaid with a payment transaction first (${JSON.stringify(o)})`);
      const st = await payStub(c, o.txn);
      A.expect(st < 400, `the Stub gateway answered ${st} for order ${key}`);
      A.expect(Number(A.sqlValue('SELECT is_paid FROM orders WHERE id = ?', [o.id])) === 1, `order ${key} must be paid after the Stub gateway succeeded`);
      orders[key] = o;
    }
    orders.unpaid = await place(c, stores.a, 1, 'pay_on_counter');
    A.expect(orders.unpaid.paid0 === 0, 'the pay-on-counter order must stay unpaid');
  }, { width: 430, height: 900 });
  const paidA = (orders.a1.totalMinor + orders.a2.totalMinor) / 100;
  R.ok(`Stub gateway: store A orders #${orders.a1.id} (${orders.a1.totalMinor / 100}) and #${orders.a2.id} (${orders.a2.totalMinor / 100}) and store B order #${orders.b1.id} (${orders.b1.totalMinor / 100}) paid; order #${orders.unpaid.id} left unpaid`);

  // ── owner: the books ────────────────────────────────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    // one balanced journal per PAID order, none for the unpaid one
    const ja = journalsOf(stores.a.id).filter((j) => Number(j[0]) > base.journalId);
    const sold = ja.filter((j) => j[1] === 'order_paid').map((j) => Number(j[2])).sort();
    A.expect(sold.join() === [orders.a1.id, orders.a2.id].sort().join(), `store A must hold exactly one sales journal per paid order (got ${sold}; unpaid is #${orders.unpaid.id})`);
    for (const key of ['a1', 'a2']) {
      const j = ja.find((x) => Number(x[2]) === orders[key].id && x[1] === 'order_paid');
      const lines = linesOf(j[0]);
      const debit = lines.reduce((s, l) => s + l.debit, 0); const credit = lines.reduce((s, l) => s + l.credit, 0);
      const total = orders[key].totalMinor / 100;
      A.expect(near(debit, total) && near(credit, total), `journal of order ${key} must balance at ${total} (debit ${debit}, credit ${credit})`);
      A.expect(lines.some((l) => l.code === '1130' && near(l.debit, total)), `order ${key}: the gateway clearing account must be debited ${total} (${JSON.stringify(lines)})`);
      A.expect(lines.some((l) => l.code === '4100' && l.credit > 0), `order ${key}: revenue must be credited (${JSON.stringify(lines)})`);
    }
    await page.goto(`${app}/books/journals?store_id=${stores.a.id}`, { waitUntil: 'domcontentloaded' });
    const jt = await A.pageText(page);
    A.expect(jt.includes(`Sales — order #${orders.a1.id}`) && jt.includes(`Sales — order #${orders.a2.id}`), 'the Journals page must list both sales journals');
    A.expect(!jt.includes(`order #${orders.unpaid.id}`) && !jt.includes(`order #${orders.b1.id}`), 'the Journals page of store A must not show the unpaid order or store B\'s order');
    const repA = await reportNumbers(page, stores.a.id);
    A.expect(repA.balanced && !repA.out, 'the trial balance of store A must read Balanced');
    A.expect(near(repA.revenue - base.a.revenue, paidA), `P&L revenue of store A must grow by the paid orders (${paidA}); it went ${base.a.revenue} → ${repA.revenue}`);
    // per-store isolation: B's books hold only B's order
    const jb = journalsOf(stores.b.id).filter((j) => Number(j[0]) > base.journalId && j[1] === 'order_paid').map((j) => Number(j[2]));
    A.expect(jb.join() === String(orders.b1.id), `store B's books must hold only its own order (${jb})`);
    const repB = await reportNumbers(page, stores.b.id);
    A.expect(near(repB.revenue - base.b.revenue, orders.b1.totalMinor / 100) && repB.balanced, `store B's P&L must grow by its one order ${orders.b1.totalMinor / 100} (${base.b.revenue} → ${repB.revenue})`);
  });
  R.ok(`books: one balanced sales journal per paid order (clearing debited, revenue credited), none for the unpaid order; trial balance Balanced; P&L revenue of A = ${paidA}, of B = ${orders.b1.totalMinor / 100} — stores isolated`);

  // ── refund to the original payment ──────────────────────────────────────────────────────────────────────────────
  const refundAmount = 10;
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    await page.goto(`${app}/orders/${stores.a.id}`, { waitUntil: 'domcontentloaded' });
    const before = await reportNumbers(page, stores.a.id);
    const r = await A.req(page, `${app}/order/${orders.a1.id}/refund-original`, { method: 'POST', form: { amount: String(refundAmount), idempotency_key: `e2ea-${stamp}-1` } });
    A.expect(r.status === 200 && r.json && r.json.success, `the refund to the original payment must be accepted (${r.status} ${r.text.slice(0, 200)})`);
    const refunds = journalsOf(stores.a.id).filter((j) => Number(j[0]) > base.journalId && /^refund/.test(j[1]));
    A.expect(refunds.length === 1 && Number(refunds[0][2]) === orders.a1.id, `exactly one refund journal for order #${orders.a1.id} (${JSON.stringify(refunds)})`);
    const lines = linesOf(refunds[0][0]);
    A.expect(lines.some((l) => l.code === '4900' && near(l.debit, refundAmount)) && lines.some((l) => l.code === '1130' && near(l.credit, refundAmount)),
      `refund journal must debit refunds and credit the clearing account by ${refundAmount} (${JSON.stringify(lines)})`);
    const after = await reportNumbers(page, stores.a.id);
    A.expect(near(after.revenue, before.revenue - refundAmount) && after.balanced, `P&L revenue must fall by ${refundAmount}: ${before.revenue} → ${after.revenue}`);
    const refundJournals = () => journalsOf(stores.a.id).filter((j) => Number(j[0]) > base.journalId && /^refund/.test(j[1]));
    // a second PARTIAL refund of the same order is booked as its own journal; a replay of the first is not
    const second = await A.req(page, `${app}/order/${orders.a1.id}/refund-original`, { method: 'POST', form: { amount: String(refundAmount), idempotency_key: `e2ea-${stamp}-2` } });
    A.expect(second.status === 200 && second.json && second.json.success, `a second partial refund must be accepted (${second.status} ${second.text.slice(0, 160)})`);
    const replay = await A.req(page, `${app}/order/${orders.a1.id}/refund-original`, { method: 'POST', form: { amount: String(refundAmount), idempotency_key: `e2ea-${stamp}-1` } });
    A.expect(replay.status === 200 && replay.json && replay.json.replayed === true, `replaying the first refund must change nothing (${replay.status} ${replay.text.slice(0, 160)})`);
    A.expect(refundJournals().length === 2, `two refunds → two journals (got ${refundJournals().length})`);
    const afterTwo = await reportNumbers(page, stores.a.id);
    A.expect(near(afterTwo.revenue, before.revenue - 2 * refundAmount) && afterTwo.balanced, `P&L revenue must have fallen by ${2 * refundAmount}: ${before.revenue} → ${afterTwo.revenue}`);
    // 40 collected, 20 refunded: 25 is more than is left
    const over = await A.req(page, `${app}/order/${orders.a1.id}/refund-original`, { method: 'POST', form: { amount: '25' } });
    A.expect(over.status === 422, `a refund above what is left must be refused (${over.status})`);
    A.expect(refundJournals().length === 2, 'the refused refund must post nothing');
  });
  R.ok(`refund: two partial refunds (${refundAmount} each) to the original payment of order #${orders.a1.id} → two refund journals (DR refunds, CR clearing), P&L revenue down exactly ${2 * refundAmount}, trial balance Balanced; a replay changes nothing; a refund above what is left is refused and posts nothing`);

  // ── register sale (cash) ────────────────────────────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    const resp = await page.goto(`${app}/pos?store=${stores.b.id}`, { waitUntil: 'domcontentloaded' });
    if (!resp || resp.status() >= 400) { R.skip('register sale', `the owner cannot open the POS register here (${resp && resp.status()}); covered by AccountingAutoJournalTest`); return; }
    const boot = await page.waitForFunction(() => window.PosBoot && document.querySelector('meta[name=csrf-token]'), { timeout: 20000 }).then(() => true).catch(() => false);
    if (!boot) { R.skip('register sale', 'the POS register did not boot for the owner; covered by AccountingAutoJournalTest'); return; }
    const sale = await page.evaluate(async (itemId) => {
      const csrf = document.querySelector('meta[name=csrf-token]').content;
      const q = '?store=' + window.PosBoot.storeId;
      const post = async (url, body) => { const r = await fetch(url + (url.includes('?') ? '&' : '?') + 'restaurant=' + window.PosBoot.storeId, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, body: JSON.stringify(body) }); return { status: r.status, data: await r.json().catch(() => ({})) }; };
      const o = await post(window.PosBoot.routes.ordersStore, { type: 'takeaway', items: [{ item_id: itemId, quantity: 1 }] });
      if (o.status !== 201) return { error: `create ${o.status}` };
      const s = await post(window.PosBoot.routes.ordersBase + '/' + o.data.id + '/settle', { method: 'cash', tendered: 100 });
      return { id: o.data.id, settle: s.status };
    }, stores.b.item.id);
    A.expect(!sale.error && sale.settle === 200, `register sale failed (${JSON.stringify(sale)})`);
    const j = journalsOf(stores.b.id).find((x) => Number(x[2]) === Number(sale.id) && x[1] === 'order_paid');
    A.expect(j, `the cash sale #${sale.id} must post a sales journal on store B`);
    A.expect(linesOf(j[0]).some((l) => l.code === '1110' && l.debit > 0), 'a cash sale debits cash on hand (1110)');
    R.ok(`register: cash sale #${sale.id} on store B → sales journal with cash on hand debited`);
  });

  // ── IDOR ────────────────────────────────────────────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (other) => {
    await A.login(other, app, { email: A.cfg.otherOwnerEmail, pass: A.cfg.otherOwnerPass });
    for (const p of [`/books/journals?store_id=${stores.a.id}`, `/books/reports?store_id=${stores.a.id}`, `/books?store_id=${stores.b.id}`]) {
      const s = await A.status(other, app + p);
      A.expect(s === 403 || s === 404, `another owner must be refused on ${p} (got ${s})`);
    }
    const r = await A.req(other, `${app}/order/${orders.a2.id}/refund-original`, { method: 'POST', form: { amount: '5' } });
    A.expect(r.status === 403 || r.status === 404, `another owner must not refund this store's order (${r.status})`);
  });
  await A.withPage(browser, async (anon) => {
    await anon.goto(`${app}/books/journals?store_id=${stores.a.id}`, { waitUntil: 'domcontentloaded' });
    A.expect(new URL(anon.url()).pathname === '/login', `an anonymous visitor must be sent to the login (${anon.url()})`);
  });
  A.expect(journalsOf(stores.a.id).filter((j) => Number(j[0]) > base.journalId && /^refund/.test(j[1])).length === 2, 'no refund journal may come out of the refused cross-store attempt');
  R.ok('IDOR: another owner gets 403/404 on this store\'s journals, reports and books and cannot refund its orders; anonymous is sent to the login');

  R.summary();
});
