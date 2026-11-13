// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * p4-data-rights — P4-07 data export + account deletion (Terms 7.6, DPA 7.1 / 10, Privacy §8), in a real browser:
 *
 *  export    owner A (2 stores) opens Backup & export → "Download all your data" → prepares the export → downloads the
 *            archive (one zip: manifest + data/<table>.ndjson): it holds both of A's stores and their rows, and no row of
 *            owner B (another account)
 *  IDOR      owner B requesting A's download link → 404; B's own export holds none of A's rows
 *  delete    A deletes the account on Settings → Delete account (password + "I understand") → signed out, sign-in
 *            refused; A's storefronts stop serving, B's keeps serving
 *  purge     the 30-day window is moved to the past and `accounts:purge-deleted` runs; then the database is checked
 *            for EVERY table of the store-data registry in A's scope: nothing is left except exactly the retained
 *            records (invoices and order records, their catalogue rows, the anonymised account / owner / store rows and
 *            the deletion request — AccountDeletionService::RETAINED_TABLES); A's logo file and export archive are gone;
 *            every one of B's rows is still there
 *
 * Both builds (export and deletion are Community). The instance: `php -S` on E2E_P4_PORT_DATA (default 8621) from
 * E2E_APP_ROOT with QUEUE_CONNECTION=sync; DB from the environment (runner_e2e.sh) or E2E_DB.
 * Run (runner): runner_e2e.sh <slot> [--community] p4-data-rights
 */

const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const B = require('../../lib/lane-b');
const C = require('../../lib/lane-c');

const PASS = 'e2eC-Data-1!';
const A = { email: 'e2eC-dr-a@demo.test', username: 'e2ecdra' };
const BB = { email: 'e2eC-dr-b@demo.test', username: 'e2ecdrb' };

const dbEnv = () => (process.env.E2E_DB ? { DB_DATABASE: process.env.E2E_DB } : {});

function tinker(php) {
  const out = B.artisan(['tinker', '--execute', php], dbEnv());
  const m = out.match(/OUT=(\{.*\})/);
  if (!m) throw new Error(`p4-data-rights tinker failed: ${out.slice(-900)}`);
  return JSON.parse(m[1]);
}

function fixtures() {
  return tinker(`
    $mk = function ($em, $un) {
      $u = \\Juwri\\Core\\Models\\User::where('email', $em)->first();
      if ($u) { \\Juwri\\Core\\Models\\AccountDeletion::where('user_id', $u->id)->delete(); }
      $u = $u ?? new \\Juwri\\Core\\Models\\User(['email' => $em]);
      $u->forceFill(['email' => $em, 'username' => $un, 'name' => 'e2eC Data '.$un, 'password' => \\Illuminate\\Support\\Facades\\Hash::make('${PASS}'),
        'status' => 1, 'user_type' => 'user', 'email_verified_at' => now(), 'two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
      return $u->fresh();
    };
    $store = function ($u, $title) {
      $logo = 'e2ec-dr-'.\\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(10)).'.png';
      @mkdir(public_path('storage/store/logo'), 0775, true);
      file_put_contents(public_path('storage/store/logo/'.$logo), base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgYGAAAAAEAAH2FzhVAAAAAElFTkSuQmCC'));
      $s = \\Juwri\\Core\\Editions\\Limits\\StoreLimitGuard::bypass(fn () => \\Juwri\\Core\\Models\\Store::create(['user_id' => $u->id, 'store_type' => 'restaurant',
        'slug' => 'e2ec-dr-'.\\Illuminate\\Support\\Str::lower(\\Illuminate\\Support\\Str::random(6)), 'color' => '#111', 'title' => $title, 'sub_title' => 't',
        'description' => 'Private notes of '.$title, 'main_image' => $logo, 'cover_image' => 'default.png', 'address' => '1 Private St', 'phone' => '0555000111']));
      \\App\\Models\\StoreOption::updateStoreOption($s->id, 'store_template', 'juwri');
      $c = \\App\\Models\\MenuCategory::create(['store_id' => $s->id, 'name' => 'Main', 'active' => '1']);
      $m = \\Juwri\\Modules\\Menu\\Models\\Menu::create(['store_id' => $s->id, 'category_id' => $c->id, 'name' => 'e2eC '.$title.' tea', 'price' => 5, 'active' => 1]);
      $cu = \\App\\Models\\Customer::create(['store_id' => $s->id, 'name' => 'Customer of '.$title, 'phone' => '05'.random_int(10000000, 99999999)]);
      $o = \\App\\Models\\Order::create(['store_id' => $s->id, 'type' => 'takeaway', 'status' => 'completed', 'track_token' => \\Illuminate\\Support\\Str::random(32),
        'customer_name' => 'Customer of '.$title, 'phone_number' => '0500000009', 'address' => '9 Private Rd', 'customer_id' => $cu->id]);
      \\App\\Models\\OrderItem::create(['order_id' => $o->id, 'item_id' => $m->id, 'quantity' => 1]);
      return ['id' => $s->id, 'slug' => $s->slug, 'item' => $m->name, 'logo' => $logo];
    };
    $a = $mk('${A.email}', '${A.username}'); $b = $mk('${BB.email}', '${BB.username}');
    $aStores = [$store($a, 'Data A1'), $store($a, 'Data A2')]; $bStores = [$store($b, 'Data B1')];
    \\Illuminate\\Support\\Facades\\Artisan::call('cache:clear');
    echo 'OUT='.json_encode(['a' => $a->id, 'aAccount' => $a->fresh()->account_id, 'b' => $b->id, 'bAccount' => $b->fresh()->account_id, 'aStores' => $aStores, 'bStores' => $bStores]);
  `);
}

/** Rows per registered table in an account's scope (the store-data registry — what export and deletion use). */
function scopeCounts(accountId) {
  return tinker(`
    $scope = \\Juwri\\Core\\Services\\Tenancy\\StoreDataScope::account(${Number(accountId)});
    $out = [];
    foreach ($scope->orderedTables(\\DB::connection()) as $t) { $q = $scope->query(\\DB::connection(), $t); if ($q && ($n = $q->count()) > 0) { $out[$t->table] = $n; } }
    ksort($out);
    echo 'OUT='.json_encode(['counts' => (object) $out, 'retained' => \\App\\Services\\Account\\AccountDeletionService::RETAINED_TABLES]);
  `);
}

/** A tiny zip reader (central directory → entries, inflate-raw). */
function unzip(buf) {
  const eocd = buf.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
  if (eocd < 0) throw new Error('not a zip');
  const total = buf.readUInt16LE(eocd + 10);
  let pos = buf.readUInt32LE(eocd + 16);
  const files = {};
  for (let i = 0; i < total; i++) {
    const method = buf.readUInt16LE(pos + 10);
    const csize = buf.readUInt32LE(pos + 20);
    const nlen = buf.readUInt16LE(pos + 28);
    const elen = buf.readUInt16LE(pos + 30);
    const clen = buf.readUInt16LE(pos + 32);
    const local = buf.readUInt32LE(pos + 42);
    const name = buf.slice(pos + 46, pos + 46 + nlen).toString('utf8');
    const lnlen = buf.readUInt16LE(local + 26);
    const lelen = buf.readUInt16LE(local + 28);
    const data = buf.slice(local + 30 + lnlen + lelen, local + 30 + lnlen + lelen + csize);
    files[name] = method === 8 ? zlib.inflateRawSync(data) : data;
    pos += 46 + nlen + elen + clen;
  }
  return files;
}

async function downloadExport(page, base) {
  await page.goto(`${base}/backup`, { waitUntil: 'domcontentloaded' });
  if (!(await page.$('[data-account-export-form]'))) throw new Error('no "Download all your data" card on the backup page');
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.evaluate(() => document.querySelector('[data-account-export-form]').submit())]);
  await page.goto(`${base}/backup`, { waitUntil: 'domcontentloaded' });
  const href = await page.evaluate(() => document.querySelector('[data-account-export-download]')?.href || null);
  if (!href) throw new Error('the export did not become ready');
  const b64 = await page.evaluate(async (url) => {
    const r = await fetch(url, { credentials: 'same-origin' });
    const bytes = new Uint8Array(await r.arrayBuffer());
    let s = ''; for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    return { status: r.status, type: r.headers.get('content-type'), b64: btoa(s) };
  }, href);
  if (b64.status !== 200) throw new Error(`export download → ${b64.status}`);
  return { href, files: unzip(Buffer.from(b64.b64, 'base64')) };
}

function rowsOf(files) {
  const rows = {};
  for (const [name, data] of Object.entries(files)) {
    if (!name.startsWith('data/')) continue;
    rows[name.slice(5, -7)] = data.toString('utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l));
  }
  return rows;
}

module.exports = async function p4DataRights({ browser, log, shot }) {
  const R = C.recorder('p4-data-rights', log);
  const root = B.appRoot();
  const port = parseInt(process.env.E2E_P4_PORT_DATA || '8621', 10);
  const base = `http://127.0.0.1:${port}`;
  const server = await C.startServer(root, port, { ...dbEnv(), QUEUE_CONNECTION: 'sync', APP_URL: base, MAIL_MAILER: 'log', SESSION_DOMAIN: '' });
  log(`  p4-data-rights: ${base} (${B.eePresentOnDisk() ? 'Enterprise' : 'Community'} build, DB ${process.env.E2E_DB || '(env)'})`);
  const a = await C.actorPage(browser);
  const b = await C.actorPage(browser);
  const guest = await C.actorPage(browser);
  try {
    const f = fixtures();
    const aIds = f.aStores.map((s) => Number(s.id));
    const bIds = f.bStores.map((s) => Number(s.id));

    // ── export ──────────────────────────────────────────────────────────────────────────────────────────────────
    await C.login(a.page, base, A.email, PASS);
    const exA = await downloadExport(a.page, base);
    await shot(a.page, 'p4-data-rights-1-export');
    const manifest = JSON.parse(exA.files['manifest.json'].toString('utf8'));
    const rows = rowsOf(exA.files);
    const aAll = C.sql('select id from stores where user_id = ? order by id', [f.a]).map((r) => Number(r.id));
    const got = [...manifest.stores].map(Number).sort((x, y) => x - y);
    if (JSON.stringify(got) !== JSON.stringify(aAll) || !aIds.every((id) => got.includes(id))) R.fail(`export stores ${JSON.stringify(got)} ≠ A's ${JSON.stringify(aAll)}`);
    const items = (rows.items || []).map((r) => r.name);
    if (!f.aStores.every((s) => items.includes(s.item))) R.fail(`export misses A's items: ${JSON.stringify(items)}`);
    if (!(rows.orders || []).length || !(rows.customers || []).length) R.fail('export misses orders or customers');
    for (const [table, list] of Object.entries(rows)) {
      for (const r of list) {
        if (bIds.includes(Number(r.store_id)) || (r.account_id != null && Number(r.account_id) !== Number(f.aAccount))) R.fail(`export of A holds a row of B in ${table}`);
      }
    }
    if (JSON.stringify(rows).includes(f.bStores[0].item)) R.fail("export of A names B's item");
    R.ok(`export: one archive, both of A's stores (${Object.keys(rows).length} tables), no row of another account`);

    // ── IDOR ────────────────────────────────────────────────────────────────────────────────────────────────────
    await C.login(b.page, base, BB.email, PASS);
    const stolen = await b.page.evaluate(async (url) => (await fetch(url, { credentials: 'same-origin' })).status, exA.href);
    if (stolen !== 404) R.fail(`B downloading A's export → ${stolen}`);
    const exB = await downloadExport(b.page, base);
    const rowsB = JSON.stringify(rowsOf(exB.files));
    if (f.aStores.some((s) => rowsB.includes(s.item))) R.fail("B's export holds A's rows");
    R.ok(`IDOR: B → A's export link ${stolen}; B's own export holds none of A's rows`);

    // ── delete ──────────────────────────────────────────────────────────────────────────────────────────────────
    const bBefore = scopeCounts(f.bAccount).counts;
    const aBefore = scopeCounts(f.aAccount);
    await a.page.goto(`${base}/settings/delete-account`, { waitUntil: 'domcontentloaded' });
    await a.page.evaluate((pass) => {
      const form = [...document.querySelectorAll('form')].find((x) => x.action.endsWith('/settings/delete-account'));
      form.querySelector('[name="password"]').value = pass;
      const u = form.querySelector('[name="understand"]'); if (u) u.checked = true;
      form.submit();
    }, PASS);
    await a.page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {});
    const pending = C.one('select status from account_deletions where user_id = ? order by id desc limit 1', [f.a]);
    if (!pending || pending.status !== 'pending') R.fail(`account deletion not requested: ${JSON.stringify(pending)}`);
    let refused = false;
    try { await C.login(a.page, base, A.email, PASS); } catch (_e) { refused = true; }
    if (!refused) R.fail('A can still sign in after deleting the account');
    for (const s of f.aStores) {
      const r = await guest.page.goto(`${base}/${s.slug}`, { waitUntil: 'domcontentloaded' });
      if (r && r.status() === 200 && (await guest.page.content()).includes(s.item)) R.fail(`A's storefront ${s.slug} still serves`);
    }
    const rb = await guest.page.goto(`${base}/${f.bStores[0].slug}`, { waitUntil: 'domcontentloaded' });
    if (!rb || rb.status() !== 200) R.fail(`B's storefront → ${rb && rb.status()}`);
    R.ok('delete: Settings → Delete account closes it at once (sign-in refused, storefronts offline); B unaffected');

    // ── purge after the window ──────────────────────────────────────────────────────────────────────────────────
    tinker(`\\Juwri\\Core\\Models\\AccountDeletion::where('user_id', ${Number(f.a)})->where('status', 'pending')->update(['purge_after' => now()->subMinute()]); echo 'OUT={}';`);
    B.artisan(['accounts:purge-deleted'], dbEnv());
    const purged = C.one('select status from account_deletions where user_id = ? order by id desc limit 1', [f.a]);
    if (!purged || purged.status !== 'purged') R.fail(`purge did not run: ${JSON.stringify(purged)}`);
    const after = scopeCounts(f.aAccount);
    const left = Object.keys(after.counts).sort();
    const notRetained = left.filter((t) => !after.retained.includes(t));
    if (notRetained.length) R.fail(`rows of A left after the purge: ${JSON.stringify(notRetained.map((t) => `${t}=${after.counts[t]}`))}`);
    const expected = Object.keys(aBefore.counts).filter((t) => after.retained.includes(t)).concat(['account_deletions']).filter((v, i, x) => x.indexOf(v) === i).sort();
    if (JSON.stringify(left) !== JSON.stringify(expected)) R.fail(`kept ${JSON.stringify(left)}, expected exactly ${JSON.stringify(expected)}`);
    const orderRow = C.one('select customer_name, phone_number, address, customer_id from orders where store_id = ? limit 1', [aIds[0]]);
    if (!orderRow || orderRow.customer_name !== '' || orderRow.phone_number !== '' || orderRow.address !== '' || orderRow.customer_id !== null) R.fail(`kept order still carries personal data: ${JSON.stringify(orderRow)}`);
    const owner = C.one('select name, email from users where id = ?', [f.a]);
    if (owner.name !== 'Deleted user' || !/@deleted\.invalid$/.test(owner.email)) R.fail(`owner row not anonymised: ${JSON.stringify(owner)}`);
    const appRoot = process.env.E2E_APP_ROOT || root;
    const logosLeft = f.aStores.filter((s) => fs.existsSync(path.join(appRoot, 'storage/store/logo', s.logo)) || fs.existsSync(path.join(appRoot, 'core/public/storage/store/logo', s.logo)));
    if (logosLeft.length) R.fail(`A's logo files left: ${logosLeft.map((s) => s.logo)}`);
    if (fs.existsSync(path.join(appRoot, 'core/storage/app/account-exports', String(f.aAccount)))) R.fail("A's export archives left");
    R.ok(`purge: A's scope holds only the retained records (${left.join(', ')}), customers' details stripped, owner anonymised, logo + export files gone`);

    const bAfter = scopeCounts(f.bAccount).counts;
    if (JSON.stringify(bAfter) !== JSON.stringify(bBefore)) R.fail(`B's rows changed: ${JSON.stringify(bBefore)} → ${JSON.stringify(bAfter)}`);
    if (!f.bStores.every((s) => fs.existsSync(path.join(appRoot, 'storage/store/logo', s.logo)) || fs.existsSync(path.join(appRoot, 'core/public/storage/store/logo', s.logo)))) R.fail("B's logo file was deleted");
    R.ok(`another account: every one of B's rows (${Object.keys(bAfter).length} tables) and files still there`);

    R.summary();
    return undefined;
  } finally {
    await a.close();
    await b.close();
    await guest.close();
    server.stop();
  }
};
