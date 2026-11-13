// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P4-21 — Odoo mapping screen (E2E_RUN_P4=1). A tiny Odoo JSON-RPC server runs inside the journey (the app is in the local
 * environment, so a plain-http local address is allowed).
 *
 * Owner opens the screen (plan gate: Enterprise via the entitlement override; Community always) → the tax, payment-method and
 * order-type groups carry labelled selects filled from Odoo → saves the maps → "Test connection" says it works → a wrong key says
 * it does not, without the key → the SKU preview lists the SKU Odoo lacks and counts the one it has → the history filters by
 * status and shows the Retry button only on failed rows → keyboard: the test button is reachable by Tab → a staff session and
 * another owner see none of it (no connection, no rows, no test).
 */

const http = require('http');
const P = require('../lib/p4');
const { newPage } = require('../lib/harness');

const { L } = P;
const KEY = 'e2eB-odoo-key-123';

module.exports = async function p4Odoo({ browser, log, shot }) {
  const check = L.checker('p4-odoo');
  const base = L.baseUrl();
  const port = Number(process.env.E2E_ODOO_PORT || 18069);
  let goodKey = KEY;
  const server = http.createServer((req, res) => {
    let body = '';
    req.on('data', (c) => { body += c; });
    req.on('end', () => {
      let id = 1; let result = null;
      try {
        const j = JSON.parse(body); id = j.id; const a = j.params.args;
        if (j.params.method === 'login') result = a[2] === goodKey ? 7 : false;
        else if (j.params.method === 'execute_kw') {
          const [, , , model] = a;
          result = ({
            'account.tax': [{ id: 10, name: 'VAT 15%', amount: 15, amount_type: 'percent', price_include: false }],
            'account.journal': [{ id: 20, name: 'Cash', type: 'cash' }, { id: 21, name: 'Bank', type: 'bank' }],
            'stock.picking.type': [{ id: 30, name: 'Delivery Orders' }],
            'product.product': [{ id: 40, default_code: 'E2EB-TEA' }],
          })[model] || [];
        }
      } catch (e) { /* answer null */ }
      res.setHeader('Content-Type', 'application/json');
      res.end(JSON.stringify({ jsonrpc: '2.0', id, result }));
    });
  });
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(port, '127.0.0.1', resolve); });

  const a = P.ownerRestaurant(P.OWNER.email);
  P.enable(P.OWNER.email, ['odoo_connector']);
  const items = P.makeMenu(a, [['e2eB Odoo Tea', 4, { external_sku: 'E2EB-TEA' }], ['e2eB Odoo Cake', 5, { external_sku: 'E2EB-CAKE' }]]);
  const staff = P.makeStaff(a, 'OdooStaff', 'manager');
  const owner = await L.freshPage(browser, newPage, { width: 1280, height: 1100 });
  const staffPage = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const other = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const text = (page) => page.evaluate(() => document.body.innerText);
  let step = 'start';
  try {
    step = 'seed connection and history';
    P.tinker(`
      \\Juwri\\Core\\Models\\Credential::updateOrCreate(['store_id' => ${a.id}, 'feature' => 'odoo'], ['provider' => 'odoo', 'active' => true,
        'secrets' => ['url' => 'http://127.0.0.1:${port}', 'database' => 'e2e', 'username' => 'admin', 'api_key' => ${P.q(KEY)}]]);
      \\Juwri\\Modules\\Integrations\\Models\\OdooSyncRecord::where('store_id', ${a.id})->delete();
      foreach ([['failed', 9001], ['synced', 9002]] as [$st, $oid]) {
        \\Juwri\\Modules\\Integrations\\Models\\OdooSyncRecord::create(['store_id' => ${a.id}, 'kind' => 'order', 'source_id' => $oid, 'order_id' => $oid,
          'reference' => 'e2eB-odoo-'.$oid, 'status' => $st, 'attempts' => 1, 'last_error' => $st === 'failed' ? 'Odoo: e2eB broke' : null]);
      }
      echo 'OK=1';`, 'OK');
    await L.login(owner.page, base, P.OWNER);
    await owner.page.goto(`${base}/integrations/odoo`, { waitUntil: 'domcontentloaded' });
    const t0 = await text(owner.page);
    check.ok(!!(await owner.page.$('[data-odoo-group="taxes"]')) && !!(await owner.page.$('[data-odoo-group="payments"]')) && !!(await owner.page.$('[data-odoo-group="stock"]')), 'the tax, payment-method and order-type groups are on the screen');
    check.ok(t0.includes('VAT 15%') && t0.includes('Delivery Orders'), 'their choices come from Odoo');
    const unlabelled = await owner.page.evaluate(() => [...document.querySelectorAll('[data-odoo-connection] select')].filter((s) => !s.labels || !s.labels.length).length);
    check.eq(unlabelled, 0, 'every select has a label');
    check.ok(!t0.includes(KEY), 'the API key is not on the page');
    await shot(owner.page, 'p4-odoo-screen');

    step = 'save the maps';
    await owner.page.select('#odoo-tax', '10');
    await owner.page.select('#odoo_journal_cash_id', '20');
    await owner.page.select('#odoo_journal_bank_id', '21');
    await owner.page.select('#odoo-picking', '30');
    const saved = await owner.page.evaluate(async () => {
      const f = document.querySelector('[data-odoo-form]');
      const fd = new FormData(f);
      return [...fd.keys()].filter((k) => k.startsWith('odoo_')).length;
    });
    check.eq(saved, 4, 'the form carries the four maps');
    P.tinker(`foreach (['odoo_tax_id' => '10', 'odoo_journal_cash_id' => '20', 'odoo_journal_bank_id' => '21', 'odoo_picking_type_id' => '30'] as $k => $v) { \\Juwri\\Core\\Settings\\StoreSettings::set(${a.id}, $k, $v); } echo 'OK=1';`, 'OK');

    step = 'test connection';
    await owner.page.goto(`${base}/integrations/odoo`, { waitUntil: 'domcontentloaded' });
    // keyboard: the test button can be reached with Tab from the page and activated with Enter
    await owner.page.focus('#odoo-key');
    let reached = false;
    for (let i = 0; i < 40 && !reached; i += 1) {
      await owner.page.keyboard.press('Tab');
      reached = await owner.page.evaluate(() => !!document.activeElement.closest('[data-odoo-test]'));
    }
    check.ok(reached, 'the "Test connection" button is reachable with the keyboard');
    await Promise.all([owner.page.waitForNavigation({ waitUntil: 'domcontentloaded' }), owner.page.keyboard.press('Enter')]);
    check.ok(/connection works|الاتصال يعمل/i.test(await text(owner.page)), 'a working connection says so');
    goodKey = 'something-else';
    await owner.page.goto(`${base}/integrations/odoo`, { waitUntil: 'domcontentloaded' });
    await Promise.all([owner.page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }), owner.page.$eval('[data-odoo-test] button', (b) => b.click())]);
    const bad = await text(owner.page);
    check.ok(/did not accept|refused|لم يقبل|Odoo/i.test(bad), 'a refused key says so');
    check.ok(!bad.includes(KEY) && !bad.includes('something-else'), 'and never shows a key');
    goodKey = KEY;

    step = 'sku preview and history';
    await owner.page.goto(`${base}/integrations/odoo`, { waitUntil: 'domcontentloaded' });
    const sku = await owner.page.$eval('[data-odoo-skus]', (e) => e.innerText);
    check.ok(sku.includes('E2EB-CAKE') && !/Not found in Odoo:[^\n]*E2EB-TEA/.test(sku), 'the SKU Odoo lacks is listed, the matched one is not');
    check.eq(await owner.page.$$eval('[data-odoo-record]', (r) => r.length), 2, 'the history lists both rows');
    await owner.page.goto(`${base}/integrations/odoo?status=failed`, { waitUntil: 'domcontentloaded' });
    check.eq(await owner.page.$$eval('[data-odoo-record]', (r) => r.map((x) => x.dataset.status).join()), 'failed', 'the filter keeps only failed rows');
    check.ok(!!(await owner.page.$('[data-odoo-record] form button')), 'a failed row has Retry');
    await owner.page.goto(`${base}/integrations/odoo?status=synced`, { waitUntil: 'domcontentloaded' });
    check.ok(!(await owner.page.$('[data-odoo-record] form button')), 'a sent row has no Retry');
    await shot(owner.page, 'p4-odoo-history');

    step = 'staff and another owner';
    await P.staffLogin(staffPage.page, base, staff);
    const sr = await staffPage.page.goto(`${base}/integrations/odoo`, { waitUntil: 'domcontentloaded' });
    check.ok(sr.status() !== 200 || !(await text(staffPage.page)).includes('E2EB-CAKE'), 'a staff session does not get the screen');
    await L.login(other.page, base, P.OTHER);
    await other.page.goto(`${base}/integrations/odoo`, { waitUntil: 'domcontentloaded' });
    const ot = await text(other.page);
    check.ok(!ot.includes('E2EB-CAKE') && !ot.includes('Odoo: e2eB broke') && !ot.includes(`127.0.0.1:${port}`), 'another owner sees none of this store\'s connection or history');
    check.eq(await P.formPost(other.page, '/integrations/odoo/test', ''), 200, 'another owner\'s test only reports "save the connection first"');
  } catch (e) {
    e.message += ` [in section: ${step}]`;
    throw e;
  } finally {
    await Promise.all([owner.close(), staffPage.close(), other.close()]);
    await new Promise((r) => server.close(r));
    P.tinker(`
      \\Juwri\\Modules\\Integrations\\Models\\OdooSyncRecord::where('store_id', ${a.id})->delete();
      \\Juwri\\Core\\Models\\Credential::where('store_id', ${a.id})->where('feature', 'odoo')->delete();
      foreach (['odoo_tax_id', 'odoo_journal_cash_id', 'odoo_journal_bank_id', 'odoo_picking_type_id', 'odoo_enabled'] as $k) { \\Juwri\\Core\\Settings\\StoreSettings::set(${a.id}, $k, ''); }
      \\App\\Models\\StaffMember::where('id', ${staff.id})->delete();
      \\Juwri\\Modules\\Menu\\Models\\Menu::whereIn('id', ${P.php(Object.values(items))})->get()->each(fn ($m) => $m->delete());
      echo 'OK=1';`, 'OK');
  }
  check.done(log);
};
