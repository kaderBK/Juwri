'use strict';

/**
 * Shared fixtures for the Phase 4 journeys (P4-02 HR, P4-04 inventory, P4-05 scheduled orders, P4-13 receipts).
 *
 * Every record is created through the app's own models by `artisan tinker` (seeders / factories only, never a dump) and is
 * named "e2eB-…" so journeys can be re-run on one database. Two owners are used: the ThemePackDemoSeeder owner (the
 * "owner" of every journey) and the showcase owner (the "other owner" of the cross-store IDOR checks).
 */

const L = require('./lane-b');

const OWNER = { email: process.env.E2E_OWNER_EMAIL || 'themepack-owner@demo.test', pass: process.env.E2E_OWNER_PASS || 'password' };
const OTHER = { email: process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com', pass: process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass' };
const STAFF_PASS = 'e2eB-staff-pass-1';

const q = (v) => JSON.stringify(String(v));
/** A JS value as a PHP expression (arrays / objects / null) that survives tinker's parser. */
const php = (v) => `json_decode(${q(JSON.stringify(v))}, true)`;

/** Run PHP in the app under test (tinker) and return the value of the LAST `echo 'K='.…;` marker with that key. */
function tinker(php, key) {
  const out = L.artisan(['tinker', '--execute', php]);
  if (!key) return out;
  const m = out.match(new RegExp(`${key}=([^\\r\\n]*)`));
  if (!m) throw new Error(`tinker fixture failed (no ${key}=…): ${out.slice(-600)}`);
  return m[1];
}

/** Features switched on for an owner by an admin override (plan gating off the table; the journeys test behaviour). */
function enable(ownerEmail, keys) {
  return tinker(`
    $o = \\Juwri\\Core\\Models\\User::where('email', ${q(ownerEmail)})->firstOrFail();
    foreach (${php(keys)} as $k) {
      \\Juwri\\Core\\Models\\EntitlementOverride::firstOrCreate(['user_id' => $o->id, 'feature_key' => $k], ['value' => '1', 'source_type' => 'admin']);
    }
    foreach (${php(keys)} as $k) { \\Juwri\\Core\\Settings\\PlatformSettings::set('admin_allow_'.$k, 1); } // the superadmin's kill-switch (dark by default)
    \\App\\Services\\FeatureAccess::flush();
    echo 'OK=1';
  `, 'OK');
}

/**
 * A store of the given owner. `type` is the store_type (null = any type the owner can still add: the Community build allows one
 * store per type, so the first free type of a short list is used), `options` are StoreOptions (key => value).
 * Returns { id, slug, title, type }.
 */
function makeStore(ownerEmail, title, { type = null, options = {} } = {}) {
  const slug = `e2eb-${title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${Date.now().toString(36)}`;
  const types = type ? [type] : ['retail', 'carparts', 'pharmacy', 'laundry', 'grocery', 'services', 'cafe', 'bakery', 'restaurant'];
  const out = tinker(`
    $o = \\Juwri\\Core\\Models\\User::where('email', ${q(ownerEmail)})->firstOrFail();
    $s = null; $used = null;
    foreach (${php(types)} as $t) {
      try {
        $s = \\Juwri\\Core\\Models\\Store::create(['user_id' => $o->id, 'slug' => ${q(slug)}, 'title' => ${q(title)}, 'sub_title' => 'e2e', 'description' => 'e2e',
          'color' => '#0f766e', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 Olaya St', 'phone' => '1', 'store_type' => $t]);
        $used = $t; break;
      } catch (\\Juwri\\Core\\Editions\\Limits\\StoreLimitReached $e) { continue; }
    }
    if (! $s) { echo 'NOSTORE=1'; return; }
    \\App\\Models\\StoreOption::updateStoreOption($s->id, 'currency_code', 'SAR');
    foreach (${php(options)} as $k => $v) { \\App\\Models\\StoreOption::updateStoreOption($s->id, $k, $v); }
    echo 'ID='.$s->id.' TYPE='.$used;
  `, null);
  const m = out.match(/ID=(\d+) TYPE=(\S+)/);
  if (!m) throw new Error(`no store could be created for ${ownerEmail} (${types.join(',')} all taken?): ${out.slice(-300)}`);
  return { id: m[1], slug, title, type: m[2] };
}

/** The first store of an owner that is a restaurant (used where the journey needs the food flow and a fresh restaurant cannot be added). */
function ownerRestaurant(ownerEmail) {
  const id = tinker(`
    $o = \\Juwri\\Core\\Models\\User::where('email', ${q(ownerEmail)})->firstOrFail();
    $s = \\Juwri\\Core\\Models\\Store::where('user_id', $o->id)->where('store_type', 'restaurant')->orderBy('id')->firstOrFail();
    echo 'ID='.$s->id;
  `, 'ID');
  const slug = L.dbq('select slug from stores where id = ?', [id])[0][0];
  return { id, slug, title: L.dbq('select title from stores where id = ?', [id])[0][0], type: 'restaurant', reused: true };
}

/** A staff member of a store (role: manager | cashier | kitchen | waiter). Returns { id, email, pass }. */
function makeStaff(store, name, role = 'cashier', permissions = null) {
  const email = `e2eb-${name.toLowerCase()}-${Date.now().toString(36)}@example.test`;
  const id = tinker(`
    $s = \\Juwri\\Core\\Models\\Store::findOrFail(${store.id});
    $m = \\App\\Models\\StaffMember::create(['owner_id' => $s->user_id, 'store_id' => $s->id, 'name' => ${q(name)}, 'email' => ${q(email)},
      'password' => bcrypt(${q(STAFF_PASS)}), 'role' => ${q(role)}, 'active' => 1, 'permissions' => ${php(permissions)}]);
    echo 'ID='.$m->id;
  `, 'ID');
  return { id, email, pass: STAFF_PASS, name };
}

/** A menu category + items ([[name, price, extra-attributes]]) in a store. Returns the item ids by name. */
function makeMenu(store, items) {
  const out = tinker(`
    $c = \\App\\Models\\MenuCategory::create(['store_id' => ${store.id}, 'name' => 'e2eB Menu', 'active' => '1']);
    $ids = [];
    foreach (${php(items)} as $row) {
      $m = \\Juwri\\Modules\\Menu\\Models\\Menu::create(array_merge(['store_id' => ${store.id}, 'category_id' => $c->id, 'name' => $row[0], 'price' => $row[1], 'active' => 1], $row[2] ?? []));
      $ids[$row[0]] = $m->id;
    }
    echo 'IDS='.json_encode($ids);
  `, 'IDS');
  return JSON.parse(out);
}

/** Staff sign-in (the /staff/login form). Returns the landed path. */
async function staffLogin(page, base, staff) {
  return L.login(page, base, { email: staff.email, pass: staff.pass }, '/staff/login');
}

/** A JSON request made from inside the signed-in page (same session, CSRF token read from the page's meta tag). */
async function pageJson(page, method, url, body, tokenFrom = null) {
  return page.evaluate(async (m, u, b, from) => {
    let token = document.querySelector('meta[name=csrf-token]')?.content || '';
    if (!token && from) { // a static page (a receipt) has no token: read it from another page of the same session
      const html = await (await fetch(from, { credentials: 'same-origin' })).text();
      token = (html.match(/name="csrf-token" content="([^"]+)"/) || [])[1] || '';
    }
    const r = await fetch(u, {
      method: m, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
      body: b === undefined ? undefined : JSON.stringify(b),
    });
    let json = null; try { json = await r.json(); } catch (_e) { /* not json */ }
    return { status: r.status, json };
  }, method, url, body, tokenFrom);
}

module.exports = { ownerRestaurant, php, L, OWNER, OTHER, STAFF_PASS, tinker, enable, makeStore, makeStaff, makeMenu, staffLogin, pageJson, q };

/** Delete the fixtures a journey made (stores + everything cascade-owned by them is removed by the app's own deletes). */
function cleanup(stores) {
  const ids = stores.filter(Boolean).map((s) => s.id);
  if (!ids.length) return;
  tinker(`
    $ids = ${php(ids)};
    \\App\\Models\\StaffMember::whereIn('store_id', $ids)->delete();
    \\Juwri\\Core\\Models\\Store::whereIn('id', $ids)->get()->each(fn ($s) => $s->delete());
    echo 'OK=1';
  `, 'OK');
}
module.exports.cleanup = cleanup;

/**
 * A guest places an order through the storefront page's own checkout request (the same fetch the theme makes), so the
 * server-side pricing / stock / hours rules run exactly as for a customer. `lines` = [{ id, quantity }]; `extra` form fields
 * (e.g. ordering-type, scheduled slot) are merged over the defaults. Returns { status, json }.
 */
async function guestOrder(page, base, store, lines, extra = {}) {
  await page.goto(`${base}/${store.slug}`, { waitUntil: 'domcontentloaded' });
  return page.evaluate(async (storeId, ls, ex) => {
    const csrf = (document.querySelector('meta[name=csrf-token]') || {}).content || (document.querySelector('input[name=_token]') || {}).value || '';
    const fd = new FormData();
    if (csrf) fd.append('_token', csrf);
    fd.append('items', JSON.stringify(ls.map((l) => ({ id: +l.id, quantity: l.quantity || 1, variants: 0, extras: [], mods: [] }))));
    const fields = Object.assign({ name: 'E2E Guest', 'ordering-type': 'takeaway', 'phone-number': '+16505550123', pay_via: 'cash', total: '1.00' }, ex);
    for (const [k, v] of Object.entries(fields)) fd.append(k, v);
    const r = await fetch(`/${storeId}/send-order`, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, body: fd });
    let json = null; try { json = await r.json(); } catch (_e) { /* not json */ }
    return { status: r.status, json };
  }, store.id, lines, extra);
}
module.exports.guestOrder = guestOrder;

/** The register's own create call, from a signed-in staff (or owner) session on /pos. lines = [{ item_id, quantity }]. */
async function posOrder(page, base, lines, extra = {}) {
  if (!page.url().includes('/pos')) await page.goto(`${base}/pos`, { waitUntil: 'domcontentloaded' });
  return pageJson(page, 'POST', '/pos/orders', Object.assign({ type: 'takeaway', items: lines.map((l) => ({ item_id: +l.item_id, quantity: l.quantity || 1 })) }, extra));
}
module.exports.posOrder = posOrder;

/** A form POST (e.g. `_method=PUT&…`) from inside the signed-in page, with the page's CSRF token. Returns the HTTP status. */
async function formPost(page, url, body) {
  const token = await page.$eval('meta[name=csrf-token]', (m) => m.content).catch(() => '');
  return page.evaluate(async (u, bd, t) => (await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': t, 'Content-Type': 'application/x-www-form-urlencoded' }, body: bd })).status, url, body, token);
}
module.exports.formPost = formPost;

/** Set store options (StoreOptions); returns the previous values so a journey can put a REUSED store back as it found it. */
function setOptions(store, options) {
  const out = tinker(`
    $prev = [];
    foreach (${php(options)} as $k => $v) {
      $prev[$k] = \\App\\Models\\StoreOption::getStoreOption(${store.id}, $k);
      \\App\\Models\\StoreOption::updateStoreOption(${store.id}, $k, $v);
    }
    echo 'PREV='.json_encode($prev);
  `, 'PREV');
  return JSON.parse(out);
}
/** Put options back (null = it had no row: set empty). */
function restoreOptions(store, prev) {
  tinker(`
    foreach (${php(prev)} as $k => $v) { \\App\\Models\\StoreOption::updateStoreOption(${store.id}, $k, $v ?? ''); }
    echo 'OK=1';
  `, 'OK');
}
module.exports.setOptions = setOptions;
module.exports.restoreOptions = restoreOptions;

/** A store option as the app reads it (StoreOption). */
function getOption(store, key) {
  return tinker(`echo 'VAL='.json_encode(\\App\\Models\\StoreOption::getStoreOption(${store.id}, ${q(key)}));`, 'VAL');
}
module.exports.getOption = getOption;

/**
 * A money figure out of a piece of text, whatever currency symbol / code / position the store uses ("$28.50", "SAR 28.50",
 * "28.50 ر.س", "28,50 €", "1,234.50"). Arabic-Indic digits are read too. Returns null when there is no number.
 */
function parseAmount(str) {
  const s = String(str || '').replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))).replace(/٫/g, '.').replace(/٬/g, ',');
  const m = s.match(/\d[\d.,]*/);
  if (!m) return null;
  let n = m[0].replace(/[.,]+$/, '');
  const lastDot = n.lastIndexOf('.');
  const lastComma = n.lastIndexOf(',');
  if (lastDot >= 0 && lastComma >= 0) {
    const dec = lastDot > lastComma ? '.' : ',';
    n = n.split(dec === '.' ? ',' : '.').join('').replace(dec, '.');
  } else if (lastComma >= 0) {
    n = /,\d{1,2}$/.test(n) && n.indexOf(',') === lastComma ? n.replace(',', '.') : n.split(',').join('');
  }
  const v = Number(n);
  return Number.isFinite(v) ? v : null;
}

/** The amount printed on the line that carries `label` (the number may come before or after the label, with any currency mark). */
function amountOf(text, label) {
  for (const line of String(text).split('\n')) {
    const i = line.indexOf(label);
    if (i < 0) continue;
    const after = parseAmount(line.slice(i + label.length));
    if (after !== null) return after;
    const before = parseAmount(line.slice(0, i));
    if (before !== null) return before;
  }
  return null;
}
module.exports.parseAmount = parseAmount;
module.exports.amountOf = amountOf;
