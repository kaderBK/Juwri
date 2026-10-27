// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P3-08b — store fitment CSV import + the vehicle filter (opt-in with E2E_RUN_P308B=1).
 *
 * A car-parts store (a fixture named "e2eB-…", three parts with SKUs): the owner uploads one CSV for the whole catalogue in
 * the dashboard (one good range, one foreign store_id column, one unknown SKU, one bad year) → the preview shows the counts
 * and the problem rows and adds nothing → confirm → the fitments exist (year range expanded, only this store) → the headless
 * storefront API filters the catalogue by vehicle (a confirmed fit only, `fits_vehicle` on the cards, 422 for a bad key) →
 * another owner gets 404 on the import screens.
 *
 * Needs E2E_BASE_URL on an instance of this code (migrated, queue = sync) and the seeded owners of the other journeys.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const L = require('../lib/lane-b');
const { newPage } = require('../lib/harness');

const OWNER = { email: process.env.E2E_OWNER_EMAIL || 'themepack-owner@demo.test', pass: process.env.E2E_OWNER_PASS || 'password' };
const OTHER = { email: process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com', pass: process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass' };

function makeParts(raw) {
  const slug = `e2eb-parts-${Date.now().toString(36)}`;
  const php = `
    $owner = \\Juwri\\Core\\Models\\User::where('email', '${OWNER.email}')->firstOrFail();
    \\Juwri\\Core\\Models\\EntitlementOverride::firstOrCreate(['user_id' => $owner->id, 'feature_key' => 'multi_vertical'], ['value' => '1', 'source_type' => 'admin']);
    \\Juwri\\Core\\Models\\EntitlementOverride::firstOrCreate(['user_id' => $owner->id, 'feature_key' => 'storefront_api'], ['value' => '1', 'source_type' => 'admin']);
    \\App\\Services\\FeatureAccess::flush();
    $s = \\Juwri\\Core\\Models\\Store::create(['user_id' => $owner->id, 'slug' => '${slug}', 'title' => 'e2eB-Parts', 'sub_title' => 'e2e', 'description' => 'e2e',
      'color' => '#0f766e', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 Olaya St', 'phone' => '1', 'store_type' => 'carparts']);
    \\App\\Models\\StoreOption::updateStoreOption($s->id, 'currency_code', 'SAR');
    $c = \\App\\Models\\MenuCategory::create(['store_id' => $s->id, 'name' => 'Parts', 'active' => '1']);
    foreach ([['Brake pads', 'E2E-BRK', 40], ['Oil 5W30', 'E2E-OIL', 25], ['Wiper blade', 'E2E-WIP', 9]] as [$n, $sku, $p]) {
      \\Juwri\\Modules\\Menu\\Models\\Menu::create(['store_id' => $s->id, 'category_id' => $c->id, 'name' => $n, 'price' => $p, 'active' => 1, 'external_sku' => $sku]);
    }
    \\App\\Models\\StorefrontToken::create(['store_id' => $s->id, 'token' => hash('sha256', '${raw}'), 'scopes' => ['menu:read'], 'active' => true, 'allowed_origins' => [], 'browser' => false]);
    echo 'ID='.$s->id;
  `;
  const out = L.artisan(['tinker', '--execute', php]);
  const m = out.match(/ID=(\d+)/);
  if (!m) throw new Error(`could not create the car-parts store: ${out.slice(-500)}`);
  return { id: m[1], slug };
}

async function api(base, token, pathAndQuery) {
  const res = await fetch(base + '/api/v1/storefront' + pathAndQuery, { headers: { 'X-Storefront-Token': token, Accept: 'application/json' } });
  return { status: res.status, body: await res.json().catch(() => ({})) };
}

module.exports = async function fitmentImport({ browser, log, shot }) {
  try {
    L.dbq('select count(*) from fitment_imports');
  } catch (_e) {
    return L.skip(log, 'fitment-import', 'the app under test has no fitment_imports table');
  }
  L.clearSiteCache();
  const check = L.checker('fitment-import');
  const base = L.baseUrl();
  const token = `sfx_e2eb_${Date.now().toString(36)}_read_0001`;
  const store = makeParts(token);
  const csv = path.join(os.tmpdir(), `e2eb-fitments-${Date.now()}.csv`);
  fs.writeFileSync(csv, [
    'store_id,sku,year,make,model,engine,vin',
    `1,E2E-BRK,2015-2017,Honda,Civic,2.0L,`,
    `1,E2E-OIL,2016,Toyota,Corolla,1.8L,JTDKB20U`,
    `1,NOPE-404,2016,Honda,Civic,,`,
    `1,E2E-BRK,20x6,Honda,Civic,,`,
  ].join('\n') + '\n');

  const c = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  const { page } = c;
  const nav = () => page.waitForNavigation({ waitUntil: 'domcontentloaded' });
  const count = () => Number(L.dbq('select count(*) from vehicle_fitments where store_id = ?', [store.id])[0][0]);
  try {
    await L.login(page, base, OWNER);
    await page.goto(`${base}/stores/${store.id}/fitments/import`, { waitUntil: 'domcontentloaded' });
    check.ok((await page.$('input[type=file][name=csv_file]')) !== null, 'the owner sees the upload form');
    await (await page.$('input[type=file][name=csv_file]')).uploadFile(csv);
    await Promise.all([nav(), page.click('form[action$="/import"] button[type=submit]')]);
    check.ok(/\/fitments\/import\/[0-9a-f-]{36}$/.test(page.url()), `the preview page opens (${new URL(page.url()).pathname})`);
    const text = await page.evaluate(() => document.querySelector('main, body').innerText);
    check.ok(/Nothing has been added yet/.test(text), 'the preview says nothing has been added');
    check.ok(/store_id/.test(text), 'the foreign store_id column is listed as ignored');
    check.ok(/NOPE-404/.test(text), 'the unknown SKU is reported');
    check.eq(count(), 0, 'a preview writes no fitment');
    await shot(page, 'p308b-import-preview');
    await Promise.all([nav(), page.click('form[action$="/confirm"] button[type=submit]')]);
    check.eq(count(), 4, 'confirm adds the new fitments only (3 years + 1), the bad rows are skipped');
    const imp = L.dbq('select status, imported_rows, error_rows from fitment_imports where store_id = ? order by id desc limit 1', [store.id])[0];
    check.eq(imp[0], 'done', 'the import is done');
    check.eq(Number(imp[2]), 2, 'two rows are reported as problems');
    check.eq(Number(L.dbq('select count(*) from vehicle_fitments where store_id <> ? and menu_id in (select id from items where store_id = ?)', [store.id, store.id])[0][0]), 0, 'no row points at another store');
    await shot(page, 'p308b-import-done');

    // the headless storefront filters by vehicle
    const names = (r) => Object.values(r.body.catalog?.items || {}).map((i) => i.name).sort();
    const all = await api(base, token, '/catalog');
    if (all.status === 403) {
      check.ok(false, `the storefront API is not enabled for the owner's plan (${JSON.stringify(all.body).slice(0, 120)})`);
    } else {
      check.eq(names(all).join(','), 'Brake pads,Oil 5W30,Wiper blade', 'without a vehicle the whole catalogue is listed');
      const civic = await api(base, token, '/catalog?vehicle=' + encodeURIComponent('2016|Honda|Civic'));
      check.eq(names(civic).join(','), 'Brake pads', `a 2016 Honda Civic: only the part with a confirmed fitment (${civic.status} ${JSON.stringify(civic.body).slice(0, 300)})`);
      const card = Object.values(civic.body.catalog.items)[0];
      check.eq(card.fits_vehicle, true, 'the card says it fits');
      check.eq(names(await api(base, token, '/catalog?vehicle=' + encodeURIComponent('2018|Honda|Civic'))).length, 0, 'a year outside the range matches nothing');
      check.eq(names(await api(base, token, '/catalog?vehicle=' + encodeURIComponent('vin:JTDKB20U123456789'))).join(','), 'Oil 5W30', 'a VIN matches the VIN prefix of a fitment');
      check.eq((await api(base, token, '/catalog?vehicle=nonsense')).status, 422, 'a bad vehicle key is 422');
      const st = await api(base, token, '/store?vehicle=' + encodeURIComponent('2016|Honda|Civic'));
      check.eq(st.body.context?.vehicle?.label, '2016 Honda Civic', 'context.vehicle carries the vehicle');
    }

    // another owner: 404 on the import screens of this store
    const other = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
    try {
      await L.login(other.page, base, OTHER);
      check.eq((await L.status(other.page, `${base}/stores/${store.id}/fitments/import`)).status, 404, 'another owner gets 404 on the import screen');
      const id = L.dbq('select public_id from fitment_imports where store_id = ? order by id desc limit 1', [store.id])[0][0];
      check.eq((await L.status(other.page, `${base}/stores/${store.id}/fitments/import/${id}`)).status, 404, 'and on the finished import');
    } finally {
      await other.close();
    }
  } finally {
    fs.rmSync(csv, { force: true });
    await c.close();
  }
  check.done(log);
};
