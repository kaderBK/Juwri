// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P4-22 — import screens (E2E_RUN_P4=1). Owner: downloads the three templates → uploads a Foodics-style menu file with a bad price
 * and a duplicate SKU → the check page shows the steps, the counts, the row errors and writes nothing → confirms → the result page
 * shows what was imported and offers no second import → the same file again is all "no change" → an Arabic-header file is read →
 * a wrong file type is refused with a message → another owner gets 404 for this import and a staff session is refused.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const P = require('../lib/p4');
const { newPage } = require('../lib/harness');

const { L } = P;

module.exports = async function p4Import({ browser, log, shot }) {
  const check = L.checker('p4-import');
  const base = L.baseUrl();
  const a = P.ownerRestaurant(P.OWNER.email);
  const staff = P.makeStaff(a, 'ImportStaff', 'manager');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'e2eb-import-'));
  const file = (name, body) => { const f = path.join(dir, name); fs.writeFileSync(f, body); return f; };
  const owner = await L.freshPage(browser, newPage, { width: 1280, height: 1100 });
  const staffPage = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const other = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const text = (page) => page.evaluate(() => document.body.innerText);
  const menuCount = () => Number(L.dbq("select count(*) from items where store_id = ? and external_sku like 'E2EBIMP-%'", [a.id])[0][0]);
  const upload = async (f, dataset = 'menu') => {
    await owner.page.goto(`${base}/integrations/switch`, { waitUntil: 'domcontentloaded' });
    await owner.page.select('#switch-dataset', dataset);
    const input = await owner.page.$('#switch-file');
    await input.uploadFile(f);
    await Promise.all([owner.page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }), owner.page.$eval('[data-switch-form] button[type=submit]', (b) => b.click())]);
  };
  let step = 'start';
  try {
    step = 'templates';
    await L.login(owner.page, base, P.OWNER);
    await owner.page.goto(`${base}/integrations/switch`, { waitUntil: 'domcontentloaded' });
    check.ok(!!(await owner.page.$('[data-switch-steps] [aria-current="step"]')), 'the steps are shown, with the current one marked');
    const links = await owner.page.$$eval('[data-switch-template]', (a) => a.map((x) => [x.getAttribute('href'), x.dataset.style, x.dataset.switchTemplate]));
    check.eq(links.length, 9, 'three datasets in three styles (juwri, Foodics-style, Arabic headers) are offered');
    for (const [href, style, d] of links) {
      const r = await owner.page.evaluate(async (u) => { const x = await fetch(u, { credentials: 'same-origin' }); return [x.status, x.headers.get('content-type'), (await x.text()).split('\n')[0]]; }, href);
      check.ok(r[0] === 200 && /csv/.test(r[1]) && r[2].length > 3, `the ${style} ${d} template downloads (${r[0]})`);
    }

    step = 'foodics-style file, check, import';
    const csv = 'name,name_localized,description,sku,category_reference,price,is_active\n'
      + 'E2EB Flat white,فلات وايت,Double shot,E2EBIMP-1,Hot drinks,14.50,1\n'
      + 'E2EB Tea,شاي,,E2EBIMP-2,Hot drinks,4.00,yes\n'
      + 'E2EB Cake,كيك,,E2EBIMP-3,Desserts,12.345,1\n'
      + 'E2EB Tea again,,,E2EBIMP-2,Hot drinks,5,1\n';
    const before = menuCount();
    await upload(file('foodics.csv', csv));
    check.ok(/\/integrations\/switch\/\d+$/.test(owner.page.url()), 'the file is checked and the check page opens');
    check.eq(await owner.page.$eval('[data-switch-plan]', (e) => e.dataset.status), 'previewed', 'it is only a preview');
    check.eq(await owner.page.$eval('[data-count="error"]', (e) => e.innerText.replace(/\D+/g, '')), '2', 'two rows are counted as errors');
    check.eq(await owner.page.$$eval('[data-switch-errors] tbody tr', (r) => r.map((x) => x.cells[0].innerText).join()), '4,5', 'the errors name their rows (the header is row 1)');
    check.eq(await owner.page.$$eval('[data-switch-errors] td[data-field]', (r) => r.map((x) => x.dataset.field).join()), 'price,sku', 'each error names the column that is wrong');
    check.eq(menuCount(), before, 'the check wrote nothing');
    check.ok(!!(await owner.page.$('[data-switch-dryrun]')) && !!(await owner.page.$('[data-switch-commit] button')), 'a clear "preview" note and an Import button');
    await shot(owner.page, 'p4-import-check');
    await Promise.all([owner.page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 120000 }), owner.page.click('[data-switch-commit] button')]);
    check.eq(await owner.page.$eval('[data-switch-plan]', (e) => e.dataset.status), 'committed', 'the result page shows the import as done');
    check.ok(!!(await owner.page.$('[data-switch-result]')) && !(await owner.page.$('[data-switch-commit]')), 'with a result note and no second Import button');
    check.eq(menuCount() - before, 2, 'exactly the two good rows were imported');
    await shot(owner.page, 'p4-import-result');

    step = 'same file again';
    await upload(file('foodics2.csv', csv));
    check.eq(await owner.page.$eval('[data-count="skip"]', (e) => e.innerText.replace(/\D+/g, '')), '2', 'the same file again is all "no change"');
    await owner.page.$eval('form[action$="/discard"] button', (b) => b.click());
    await owner.page.waitForNavigation({ waitUntil: 'domcontentloaded' });

    step = 'arabic headers';
    await upload(file('arabic.csv', 'الاسم,السعر,رمز المنتج\nE2EB Arabic,7.50,E2EBIMP-4\n'));
    check.eq(await owner.page.$eval('[data-count="create"]', (e) => e.innerText.replace(/\D+/g, '')), '1', 'an Arabic-header file is read');
    const importId = (owner.page.url().match(/switch\/(\d+)$/) || [])[1];
    await owner.page.$eval('[data-switch-commit] button', (b) => b.click());
    await owner.page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 120000 });

    step = 'wrong file type';
    await upload(file('notes.pdf', '%PDF-1.4 nope'));
    check.ok(!!(await owner.page.$('[data-switch-error]')), 'a wrong file type is refused with a message');

    step = 'other owner and staff';
    await L.login(other.page, base, P.OTHER);
    const o = await other.page.goto(`${base}/integrations/switch/${importId}`, { waitUntil: 'domcontentloaded' });
    check.eq(o.status(), 404, 'another owner gets 404 for this import');
    await P.staffLogin(staffPage.page, base, staff);
    const s = await staffPage.page.goto(`${base}/integrations/switch`, { waitUntil: 'domcontentloaded' });
    check.ok(s.status() !== 200 || !(await staffPage.page.$('[data-switch-form]')), 'a staff session does not get the import screen');
  } catch (e) {
    e.message += ` [in section: ${step}]`;
    throw e;
  } finally {
    await Promise.all([owner.close(), staffPage.close(), other.close()]);
    fs.rmSync(dir, { recursive: true, force: true });
    P.tinker(`
      \\Juwri\\Modules\\Menu\\Models\\Menu::where('store_id', ${a.id})->where('external_sku', 'like', 'E2EBIMP-%')->get()->each(fn ($m) => $m->delete());
      \\Juwri\\Modules\\Integrations\\Models\\SwitchImport::where('store_id', ${a.id})->delete();
      \\App\\Models\\StaffMember::where('id', ${staff.id})->delete();
      echo 'OK=1';`, 'OK');
  }
  check.done(log);
};
