// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * p4-fiscal — P4-01 Fiscal / ZATCA end to end (owner → customer → owner, plus a cross-store IDOR check).
 *
 *  owner     store editor (real form): fiscal country SA, legal name, VAT and CR numbers; Fiscalization console:
 *            onboards a ZATCA device with an OTP (FakeZatcaClient: compliance CSID → 6 checks → production CSID)
 *  customer  places a pay-at-counter takeaway order on the storefront (the theme's own send-order endpoint)
 *  owner     settles it at the register → the simplified e-invoice is issued and reported (SIM-…, accepted); the
 *            printable receipt shows the invoice number and the ZATCA QR (9-tag TLV, read back from the document);
 *            refunds part of it at the register → a credit note (CN-…, 381 / 0200000, BillingReference = the invoice,
 *            InstructionNote = the reason, tax pro rata) and the receipt still shows the SALE's QR; the console lists
 *            both documents
 *  IDOR      a second owner: the first store's receipt, resubmit and console are refused / scoped; another store's
 *            branch id is refused by the onboarding form
 *
 * Fixtures (tinker on the app under test, database E2E_DB): the platform switches `fiscalization` + `fiscal_sa`
 * (PlatformSettings, as the E2E seeders do), the demo café's branch hours open all day (so the journey does not depend
 * on the clock) and the KitDemoSeeder / ThemePackDemoSeeder stores. Everything else goes
 * through the owner screens. Needs QUEUE_CONNECTION=sync on the instance (the reporting job runs in the request).
 *
 * The instance: E2E_ARMED_URL when set, else this journey starts `php -S` on E2E_P4_PORT (default 8613) from
 * E2E_APP_ROOT (default: this worktree) with DB_DATABASE=E2E_DB — so the same file runs the Enterprise worktree and
 * the Community mirror (E2E_APP_ROOT=<mirror>).
 *
 * Run: E2E_DB=juwri_c npm run test:e2e -- p4-fiscal
 */

const B = require('../../lib/lane-b');
const C = require('../../lib/lane-c');
const K = require('../../lib/order-kit-v2');

const OWNER = { email: process.env.E2E_KIT_OWNER_EMAIL || 'kit-demo-owner@demo.test', pass: process.env.E2E_KIT_OWNER_PASS || 'kit-demo-pass' };
const OTHER = { email: process.env.E2E_TP_OWNER_EMAIL || 'themepack-owner@demo.test', pass: process.env.E2E_TP_OWNER_PASS || 'password' };
const STORE = 'bloom-coffee-demo';
const OTHER_STORE = 'themepack-demo';
const VAT = '300000000000003';

const dbEnv = () => (process.env.E2E_DB ? { DB_DATABASE: process.env.E2E_DB } : {});

function fixtures() {
  const php = `
    \\Juwri\\Core\\Settings\\PlatformSettings::set('admin_allow_fiscalization', '1');
    \\Juwri\\Core\\Settings\\PlatformSettings::set('admin_allow_fiscal_sa', '1');
    $a = \\Juwri\\Core\\Models\\Store::where('slug', '${STORE}')->firstOrFail();
    $b = \\Juwri\\Core\\Models\\Store::where('slug', '${OTHER_STORE}')->firstOrFail();
    // the journey must not depend on the clock: the demo café's hours open all day (e2e fixture)
    $day = ['open' => '00:00', 'close' => '23:59', 'closed' => false];
    \\App\\Models\\StoreOption::updateStoreOption($a->id, 'open_close_store', '1'); // undo a pre-fix run (the editor closed it)
    // the order helper (order-kit-v2) drives the legacy storefront runtime: the demo café is a kit (brew) store, so give it a legacy theme for this journey (p3-brew-cafe puts brew back)
    \\App\\Models\\StoreOption::updateStoreOption($a->id, 'store_template', 'crave'); \\Illuminate\\Support\\Facades\\Cache::flush();
    \\DB::table('branches')->where('store_id', $a->id)->update(['hours' => json_encode(array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], $day))]);
    echo 'OUT='.json_encode(['a' => $a->id, 'b' => $b->id,
      'branchA' => \\DB::table('branches')->where('store_id', $a->id)->value('id'),
      'branchB' => \\DB::table('branches')->where('store_id', $b->id)->value('id')]);
  `;
  const out = B.artisan(['tinker', '--execute', php], dbEnv());
  const m = out.match(/OUT=(\{.*\})/);
  if (!m) throw new Error(`p4-fiscal fixtures failed: ${out.slice(-600)}`);
  return JSON.parse(m[1]);
}

/** POST/GET from inside the page with its session + CSRF (the register's / dashboard's own calls). */
async function call(page, method, url, body) {
  return page.evaluate(async ({ method, url, body }) => {
    const csrf = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
    const r = await fetch(url, {
      method,
      credentials: 'same-origin',
      redirect: 'manual',
      headers: Object.assign({ Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body ? { 'Content-Type': 'application/json' } : {}),
      body: body ? JSON.stringify(body) : undefined,
    });
    const text = await r.text();
    let json = null;
    try { json = JSON.parse(text); } catch (_e) { /* html */ }
    return { status: r.status, json, text };
  }, { method, url, body });
}

async function submitForm(page, selector, values) {
  await page.evaluate(({ selector, values }) => {
    const form = document.querySelector(selector);
    for (const [k, v] of Object.entries(values)) {
      const el = form.querySelector(`[name="${k}"]`);
      el.value = v;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, { selector, values });
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    page.evaluate((s) => document.querySelector(s).requestSubmit(), selector),
  ]);
}

module.exports = async function p4Fiscal({ browser, log, shot }) {
  const R = C.recorder('p4-fiscal', log);
  let server = null;
  let base = (process.env.E2E_ARMED_URL || '').replace(/\/$/, '');
  if (!base) {
    const port = parseInt(process.env.E2E_P4_PORT || '8613', 10);
    server = await C.startServer(B.appRoot(), port, { ...dbEnv(), QUEUE_CONNECTION: 'sync', APP_URL: `http://127.0.0.1:${port}`, APP_ENV: 'local' });
    base = server.url;
  }
  const ee = B.eePresentOnDisk();
  log(`  p4-fiscal: ${base} (${ee ? 'Enterprise' : 'Community'} build, DB ${process.env.E2E_DB || '(core/.env)'})`);

  const owner = await C.actorPage(browser);
  const customer = await C.actorPage(browser);
  const other = await C.actorPage(browser);
  try {
    const ids = fixtures();
    const docs = () => C.sql('select id, type, number, status, order_id, source_ref, total_minor, tax_total_minor, payload, authority_response from fiscal_documents where store_id = ? order by id', [ids.a]);
    const before = docs().length;

    // ── owner: fiscal identity in the store editor ───────────────────────────────────────────────────────────────
    await C.login(owner.page, base, OWNER.email, OWNER.pass);
    await owner.page.goto(`${base}/stores/${ids.a}/edit`, { waitUntil: 'domcontentloaded' });
    const saved = await owner.page.evaluate(async (vat) => {
      const form = document.querySelector('form.restaurant-edit');
      if (!form || !form.querySelector('[name="fiscal_country"]')) return { error: 'no fiscal fields on the store editor' };
      form.querySelector('[name="fiscal_country"]').value = 'SA';
      form.querySelector('[name="fiscal_legal_name"]').value = 'e2eC-Bloom Coffee Co';
      form.querySelector('[name="fiscal_vat_number"]').value = vat;
      form.querySelector('[name="fiscal_cr_number"]').value = '1010101010';
      // P6-176: the KSA national address (its fields render once the store is SA; set them on this first save)
      const fd = new FormData(form);
      for (const [k, v] of Object.entries({ fiscal_street: 'King Fahd Road', fiscal_building_number: '1234', fiscal_district: 'Al Olaya', fiscal_city: 'Riyadh', fiscal_postal_code: '12211' })) fd.set(k, v);
      const r = await fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      return { status: r.status, body: (await r.text()).slice(0, 300) };
    }, VAT);
    if (saved.error || saved.status >= 400) R.fail(`store editor save failed: ${JSON.stringify(saved)}`);
    R.ok('owner saves fiscal country SA + VAT/CR in the store editor');

    // ── owner: onboard a ZATCA device in the Fiscalization console ──────────────────────────────────────────────
    await owner.page.goto(`${base}/fiscal?store_id=${ids.a}`, { waitUntil: 'domcontentloaded' });
    if (!(await owner.page.$('form[action$="/fiscal/zatca/onboard"]'))) R.fail('no ZATCA onboarding form on the console');
    await submitForm(owner.page, 'form[action$="/fiscal/zatca/onboard"]', { device_key: 'e2eC-EGS-1', otp: '123456' });
    let device = C.one('select status, branch_id from fiscal_devices where store_id = ? and device_key = ?', [ids.a, 'e2eC-EGS-1']);
    if (!device || device.status !== 'production') R.fail(`device not onboarded: ${JSON.stringify(device)}`);
    await shot(owner.page, 'p4-fiscal-1-onboarded');
    R.ok('console onboarding: OTP → compliance → production device');

    // another store's branch id is refused (IDOR)
    if (ids.branchB) {
      await owner.page.goto(`${base}/fiscal?store_id=${ids.a}`, { waitUntil: 'domcontentloaded' });
      await submitForm(owner.page, 'form[action$="/fiscal/zatca/onboard"]', { device_key: 'e2eC-EGS-X', otp: '123456', branch_id: String(ids.branchB) });
      if (C.one('select id from fiscal_devices where device_key = ?', ['e2eC-EGS-X'])) R.fail('a device was created on another store\'s branch');
      R.ok('onboarding refuses another store\'s branch id');
    } else {
      R.skip('foreign branch onboarding', 'the other store has no branch');
    }

    // ── customer: pay-at-counter order on the storefront ────────────────────────────────────────────────────────
    const rt = await K.loadRuntime(customer.page, `${base}/${STORE}`, { slug: STORE, baseUrl: base });
    const lines = K.pickSimpleLines(rt, { count: 1, quantity: 2 });
    const placed = await K.submitOrder(customer.page, { rt, items: lines, fields: { 'ordering-type': 'takeaway', name: 'e2eC Diner' } });
    if (!placed.success) R.fail(`customer order refused: ${JSON.stringify(placed).slice(0, 300)}`);
    const token = K.trackToken(placed.track_url);
    const order = C.one('select id, is_paid from orders where track_token = ?', [token]);
    if (!order) R.fail('customer order not found');
    if (docs().length !== before) R.fail('an e-invoice was issued before payment');
    R.ok(`customer places order #${order.id} (unpaid → no e-invoice yet)`);

    // ── owner: settle at the register → e-invoice ───────────────────────────────────────────────────────────────
    await owner.page.goto(`${base}/pos?store=${ids.a}`, { waitUntil: 'domcontentloaded' });
    await owner.page.waitForFunction(() => window.PosBoot && document.querySelector('meta[name=csrf-token]'), { timeout: 30000 });
    const posBase = await owner.page.evaluate(() => window.PosBoot.routes.ordersBase);
    const q = `?store=${ids.a}`;
    const settle = await call(owner.page, 'POST', `${posBase}/${order.id}/settle${q}`, { method: 'cash', tendered: 1000 });
    if (settle.status !== 200) R.fail(`settle failed: ${settle.status} ${settle.text.slice(0, 200)}`);
    let all = docs();
    const inv = all.find((d) => d.source_ref === `order:${order.id}`);
    if (!inv) R.fail(`no e-invoice for the paid order (documents: ${JSON.stringify(all.map((d) => [d.type, d.source_ref]))})`);
    if (inv.type !== 'simplified' || !/^SIM-/.test(inv.number)) R.fail(`invoice type/number: ${inv.type} ${inv.number}`);
    if (inv.status !== 'accepted') R.fail(`invoice not reported: ${inv.status}`);
    if (!/name="0200000">388</.test(inv.payload)) R.fail('invoice is not a simplified 388');
    if (Number(inv.tax_total_minor) <= 0) R.fail('invoice carries no VAT');
    R.ok(`settle → simplified invoice ${inv.number} reported (accepted), total ${inv.total_minor} tax ${inv.tax_total_minor}`);

    // receipt: invoice number + ZATCA QR
    const receiptUrl = `${base}/pos/orders/${order.id}/receipt${q}&lang=en`;
    await owner.page.goto(receiptUrl, { waitUntil: 'domcontentloaded' });
    const receipt = await owner.page.evaluate(() => ({ text: document.body.innerText, svg: document.querySelectorAll('svg').length }));
    await shot(owner.page, 'p4-fiscal-2-receipt');
    if (!receipt.text.includes(inv.number)) R.fail(`receipt does not show the invoice number ${inv.number}`);
    if (!receipt.text.includes(VAT)) R.fail('receipt does not show the VAT number');
    if (!receipt.svg) R.fail('receipt has no QR');
    const tags = JSON.parse(inv.authority_response).tags;
    if (tags !== 9) R.fail(`QR is ${tags}-tag, expected 9 (production device)`);
    R.ok('receipt shows the invoice number, VAT number and the 9-tag ZATCA QR');

    // ── owner: partial refund at the register → credit note ─────────────────────────────────────────────────────
    await owner.page.goto(`${base}/pos?store=${ids.a}`, { waitUntil: 'domcontentloaded' });
    await owner.page.waitForFunction(() => window.PosBoot && document.querySelector('meta[name=csrf-token]'), { timeout: 30000 });
    const refundAmount = Math.floor(Number(inv.total_minor) / 2) / 100;
    const refund = await call(owner.page, 'POST', `${posBase}/${order.id}/refund${q}`, { amount: refundAmount, method: 'cash', reason: 'e2eC wrong size' });
    if (refund.status !== 201) R.fail(`refund failed: ${refund.status} ${refund.text.slice(0, 200)}`);
    all = docs();
    const cn = all.find((d) => d.source_ref === `refund:${refund.json.adjustment_id}`);
    if (!cn) R.fail('no credit note for the refund');
    if (cn.type !== 'credit_note' || !/^CN-/.test(cn.number)) R.fail(`credit note type/number: ${cn.type} ${cn.number}`);
    if (!/name="0200000">381</.test(cn.payload)) R.fail('credit note is not a simplified 381');
    if (!cn.payload.includes(`<cbc:ID>${inv.number}</cbc:ID>`) || !cn.payload.includes('<cac:BillingReference>')) R.fail('credit note does not reference the invoice');
    if (!cn.payload.includes('<cbc:InstructionNote>e2eC wrong size</cbc:InstructionNote>')) R.fail('credit note has no reason');
    if (Number(cn.total_minor) !== Math.round(refundAmount * 100)) R.fail(`credit note total ${cn.total_minor} ≠ refund ${refundAmount}`);
    const wantTax = Math.round(refundAmount * Number(inv.tax_total_minor) / Number(inv.total_minor) * 100);
    if (Math.abs(Number(cn.tax_total_minor) - wantTax) > 1) R.fail(`credit note tax ${cn.tax_total_minor} ≠ pro rata ${wantTax}`);
    if (cn.status !== 'accepted') R.fail(`credit note not reported: ${cn.status}`);
    R.ok(`refund ${refundAmount} → credit note ${cn.number} (381/0200000, references ${inv.number}, reason, tax pro rata), reported`);

    // replaying the same refund idempotency key issues nothing new
    const n = docs().length;
    await owner.page.goto(receiptUrl, { waitUntil: 'domcontentloaded' });
    const after = await owner.page.evaluate(() => document.body.innerText);
    if (!after.includes(inv.number) || after.includes(cn.number)) R.fail('after the refund the receipt does not show the sale invoice');
    if (docs().length !== n) R.fail('opening the receipt issued a document');
    R.ok('receipt keeps the sale invoice (not the credit note) after the refund');

    // console lists both
    await owner.page.goto(`${base}/fiscal?store_id=${ids.a}`, { waitUntil: 'domcontentloaded' });
    const log1 = await owner.page.evaluate(() => document.body.innerText);
    await shot(owner.page, 'p4-fiscal-3-console');
    if (!log1.includes(inv.number) || !log1.includes(cn.number)) R.fail('console document log misses the invoice or the credit note');
    R.ok('console document log lists the invoice and the credit note');

    // ── IDOR: a second owner ────────────────────────────────────────────────────────────────────────────────────
    await C.login(other.page, base, OTHER.email, OTHER.pass);
    const r1 = await other.page.goto(receiptUrl, { waitUntil: 'domcontentloaded' });
    const r1text = await other.page.evaluate(() => document.body.innerText);
    if (r1.status() < 400 && r1text.includes(inv.number)) R.fail(`another owner read the receipt (${r1.status()})`);
    R.ok(`another owner: the first store's receipt → ${r1.status()}`);
    await other.page.goto(`${base}/fiscal?store_id=${ids.a}`, { waitUntil: 'domcontentloaded' });
    const otherConsole = await other.page.evaluate(() => document.body.innerText);
    if (otherConsole.includes(inv.number) || otherConsole.includes(cn.number)) R.fail('another owner sees the first store\'s documents');
    R.ok('another owner: console (?store_id of the first store) shows none of its documents');
    const rs = await call(other.page, 'POST', `${base}/fiscal/documents/${inv.id}/resubmit?store_id=${ids.a}`);
    if (![403, 404].includes(rs.status)) R.fail(`another owner resubmit → ${rs.status}`);
    const st = C.one('select status from fiscal_documents where id = ?', [inv.id]);
    if (st.status !== 'accepted') R.fail('resubmit changed the document');
    R.ok(`another owner: resubmit of the first store's document → ${rs.status}`);
    const rf = await call(other.page, 'POST', `${base}/pos/orders/${order.id}/refund?store=${ids.a}`, { amount: 1, method: 'cash', reason: 'e2eC idor' });
    if (rf.status < 400) R.fail(`another owner refunded the first store's order (${rf.status})`);
    R.ok(`another owner: refund of the first store's order → ${rf.status}`);
    if (docs().length !== n) R.fail('the IDOR probes issued a document');

    R.summary();
    return undefined;
  } finally {
    await owner.close();
    await customer.close();
    await other.close();
    if (server) server.stop();
  }
};
