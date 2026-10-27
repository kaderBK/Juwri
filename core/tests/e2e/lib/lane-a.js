'use strict';

/**
 * P1-12 item 9 — shared helpers for the "every uncovered area" journeys (a–e).
 *
 * Layers on lib/platform.js (webLogin, collectConsole, …) and lib/order-kit-v2.js. Differences from the
 * platform packs, all because these journeys run on a Mac against Herd (`juw.test`) with no mysql CLI:
 *
 *  - sql(): a READ-ONLY probe through `php` PDO (credentials read from the Herd site's core/.env — never printed);
 *  - login(): a login that takes the base URL, so one journey can sign in on juw.test AND on the "armed" instance;
 *  - armed: E2E_ARMED_URL — a second local `php artisan serve` of the SAME code and database started with the
 *    test-only switches the Herd site does not have (E2E_STUBS=1 for the Stub gateway, PHARMACY_ENABLED_COUNTRIES=SA).
 *    Legs that need a switch run there; without it they skip with a clear reason, never silently pass;
 *  - ensureStore(): creates a store through the owner dashboard wizard (store type picker) the first time and reuses
 *    it afterwards, so a rerun does not pile up stores. Every record a journey creates is prefixed "e2eA-".
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const P = require('./platform');

const PREFIX = 'e2eA-';
/** A value from the Herd site's core/.env (the dev admin credentials live there; never printed or logged). */
function envFileVar(file, name) {
  try {
    const m = fs.readFileSync(file, 'utf8').match(new RegExp(`^${name}=(.*)$`, 'm'));
    return m ? m[1].trim().replace(/^["']|["']$/g, '') : '';
  } catch (_e) { return ''; }
}

// default: the sibling `juw` checkout's core/.env (the tree juw.test serves), else this tree's core/.env — never an
// absolute path (the file ships in the public mirror)
const SIBLING_ENV = path.resolve(__dirname, '../../../../../juw/core/.env');
const ENV_FILE = process.env.E2E_ENV_FILE || (fs.existsSync(SIBLING_ENV) ? SIBLING_ENV : path.resolve(__dirname, '../../../.env'));
const cfg = {
  baseUrl: P.cfg.baseUrl,
  armed: (process.env.E2E_ARMED_URL || '').replace(/\/$/, ''),
  envFile: ENV_FILE,
  php: process.env.E2E_PHP || 'php',
  phpArtisan: process.env.E2E_PHP_ARTISAN || process.env.E2E_PHP || 'php', // PHP 8.4 for `artisan db:seed` (the app needs it; the PDO probe does not)
  showcaseEmail: process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com',
  showcasePass: process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass',
  otherOwnerEmail: process.env.E2E_OWNER_EMAIL || 'themepack-owner@demo.test',
  otherOwnerPass: process.env.E2E_OWNER_PASS || 'password',
  adminEmail: process.env.E2E_ADMIN_EMAIL || envFileVar(ENV_FILE, 'E2E_ADMIN_EMAIL') || 'admin@juw.test',
  adminPass: process.env.E2E_ADMIN_PASS || envFileVar(ENV_FILE, 'E2E_ADMIN_PASS'),
};

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ── journey bookkeeping ──────────────────────────────────────────────────────

/** A checks recorder: ok(name) logs + counts, skip(name, why) logs a clearly-labelled skip (never a silent pass). */
function recorder(tag, log) {
  const checks = [];
  const skips = [];
  return {
    checks,
    skips,
    ok(name) { checks.push(name); log(`  ${tag} ok   ${name}`); },
    skip(name, why) { skips.push(`${name} — ${why}`); log(`  ${tag} SKIP ${name} — ${why}`); },
    fail(msg) { throw new Error(`${tag}: ${msg}`); },
    summary() { log(`${tag}: ${checks.length} checks passed, ${skips.length} skipped${skips.length ? ' (' + skips.join('; ') + ')' : ''}`); },
  };
}

/** Wrap a journey so E2E_DEBUG=1 prints the full stack of a failure (the runner only prints the message). */
function journey(fn) {
  return async (ctx) => {
    try { return await fn(ctx); } catch (e) { if (process.env.E2E_DEBUG) console.error(e.stack); throw e; }
  };
}

function expect(cond, msg) { if (!cond) throw new Error(msg); }

// ── editions ────────────────────────────────────────────────────────────────

let _ee = null;
/** True when the site under test runs the enterprise build (edition package installed): the ee-only admin plans route exists. */
async function hasEe(_page) {
  // curl probe of /admin/plans (P.eeActive): a fetch with redirect:'manual' answers status 0 (opaque redirect) for the licensed
  // build's 302-to-login, which this function used to read as "no Enterprise"
  if (_ee !== null) return _ee;
  _ee = P.eeActive();
  return _ee;
}

// ── read-only DB probe ──────────────────────────────────────────────────────

const PDO_SCRIPT = `
$in = json_decode(stream_get_contents(STDIN), true);
$env = [];
foreach (file($in['env']) as $l) { if (preg_match('/^([A-Z_]+)=(.*)$/', trim($l), $m)) { $env[$m[1]] = trim($m[2], "\\"'"); } }
if (getenv('E2E_DB_HOST')) { $env = ['DB_HOST' => getenv('E2E_DB_HOST'), 'DB_PORT' => getenv('E2E_DB_PORT') ?: '3306', 'DB_DATABASE' => getenv('E2E_DB'), 'DB_USERNAME' => getenv('E2E_DB_USER'), 'DB_PASSWORD' => getenv('E2E_DB_PASS') ?: '']; } // runner_e2e.sh: the slot's own database
$p = new PDO('mysql:host='.($env['DB_HOST'] ?? '127.0.0.1').';port='.($env['DB_PORT'] ?? '3306').';dbname='.$env['DB_DATABASE'].';charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'] ?? '');
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$s = $p->prepare($in['sql']);
$s->execute($in['params']);
echo json_encode($s->fetchAll(PDO::FETCH_NUM));
`;

/**
 * Run a SELECT against the Herd site's database and return rows as arrays of strings/nulls. Read-only by construction
 * (anything but SELECT/SHOW/DESCRIBE is refused); values are bound, never interpolated.
 * @param {string} sqlText
 * @param {Array<string|number|null>} [params]
 * @returns {Array<Array<string|null>>}
 */
function sql(sqlText, params = []) {
  if (!/^\s*(select|show|describe)\b/i.test(sqlText)) throw new Error('sql(): the e2e probe is read-only');
  let out;
  try {
    out = execFileSync(cfg.php, ['-r', PDO_SCRIPT], {
      input: JSON.stringify({ env: cfg.envFile, sql: sqlText, params }),
      encoding: 'utf8',
      maxBuffer: 32 * 1024 * 1024,
      stdio: ['pipe', 'pipe', 'pipe'],
    });
  } catch (e) {
    const why = String(e.stderr || e.message).split('\n').find((l) => /PDOException|Fatal/.test(l)) || String(e.message).slice(0, 200);
    throw new Error(`sql(): ${why.slice(0, 300)} [${sqlText.slice(0, 120)}]`);
  }
  return JSON.parse(out);
}
const sqlValue = (q, p) => { const r = sql(q, p); return r.length ? r[0][0] : null; };

/**
 * A store option from stores.settings (a JSON document grouped by area, see StoreSettingKeys): `path` is the grouped
 * name, e.g. 'kiosk.enabled'. Returns the value as a string ('true' / 'false' for switches) or null when unset.
 */
function storeOption(storeId, path) {
  const raw = sqlValue('SELECT settings FROM stores WHERE id = ?', [storeId]);
  if (!raw) return null;
  let v = JSON.parse(raw);
  for (const part of path.split('.')) { if (v === null || typeof v !== 'object') return null; v = v[part]; }
  return v === undefined || v === null ? null : String(v);
}

// ── login ───────────────────────────────────────────────────────────────────

async function resetSession(page) {
  const cookies = await page.cookies().catch(() => []);
  if (cookies.length) await page.deleteCookie(...cookies);
  await page.evaluate(async () => {
    try { localStorage.clear(); sessionStorage.clear(); } catch (_e) { /* opaque origin */ }
  }).catch(() => {});
}

/** Dashboard login (owner / admin share /login with a `username` field; staff use /staff/login). */
async function login(page, base, opts, retried = false) {
  const { email, pass, loginPath = '/login' } = opts;
  P.resetRateLimits(); // the login is throttled per IP and a fast host (AWS box) logs in more often per minute than the limit allows; test env only
  await page.goto(base + '/login', { waitUntil: 'domcontentloaded' }).catch(() => {});
  await resetSession(page);
  await page.goto(base + loginPath, { waitUntil: 'domcontentloaded' });
  const userSel = loginPath === '/staff/login' ? '#email, input[name="email"]' : '#username, input[name="username"], input[name="email"]';
  await page.waitForSelector(userSel);
  await page.type(userSel, email);
  await page.type('#password, input[name="password"]', pass);
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
  ]);
  const landed = new URL(page.url()).pathname;
  if (landed === '/login' || landed === '/staff/login') {
    const err = await page.evaluate(() => (document.querySelector('.alert, .invalid-feedback, [role="alert"]')?.textContent || `${document.title}: ${document.body ? document.body.innerText.slice(0, 160) : ''}`).replace(/\s+/g, ' ').trim());
    // auth-login allows 6 sign-ins a minute per account + IP. If the limiter reset above did not reach the instance's cache
    // (another store, another server), a 429 is the throttle working, not a rejection: wait out the window once.
    if (!retried && /\b429\b|too many/i.test(err)) {
      await new Promise((r) => setTimeout(r, 61000));
      return login(page, base, opts, true);
    }
    throw new Error(`login rejected for ${email} on ${base} (${err})`);
  }
  return page.url();
}

// ── in-page requests ────────────────────────────────────────────────────────

/** fetch() from the page (its cookies + CSRF meta). `form` = plain object posted as multipart; `json` posts JSON. */
async function req(page, url, { method = 'GET', form, json, headers = {}, redirect = 'manual' } = {}) {
  return page.evaluate(async ({ url, method, form, json, headers, redirect }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const h = Object.assign({ Accept: 'application/json, text/html' }, csrf ? { 'X-CSRF-TOKEN': csrf } : {}, headers);
    let body;
    if (form) { body = new FormData(); Object.keys(form).forEach((k) => { if (form[k] !== undefined && form[k] !== null) body.append(k, form[k]); }); }
    else if (json) { body = JSON.stringify(json); h['Content-Type'] = 'application/json'; }
    const r = await fetch(url, { method, headers: h, body, credentials: 'same-origin', redirect });
    const text = await r.text().catch(() => '');
    let parsed = null; try { parsed = JSON.parse(text); } catch (_e) { /* html */ }
    return { status: r.status, type: r.type, location: r.headers.get('location'), json: parsed, text: text.replace(/\s+/g, ' ').slice(0, 4000) };
  }, { url, method, form, json, headers, redirect });
}

/** The CSRF token of a storefront page: the meta tag when there is one, else the `CSRF = "…"` the themes inline. */
async function storefrontCsrf(page) {
  return page.evaluate(async () => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    const html = await (await fetch(location.href, { credentials: 'same-origin' })).text();
    const m = html.match(/CSRF\s*=\s*"([^"]+)"/) || html.match(/csrf[-_]?token['"]?\s*[:=]\s*['"]([^'"]+)['"]/i);
    return m ? m[1] : null;
  });
}

/** Visible text of the current page (collapsed). */
const pageText = (page) => page.evaluate(() => document.body.innerText.replace(/\s+/g, ' ').trim());

// ── fixture files ───────────────────────────────────────────────────────────

/** A valid 1x1 PNG on disk (used for store logo/cover and licence scans). */
function pngFile(name = 'e2ea.png') {
  const f = path.join(os.tmpdir(), name);
  if (!fs.existsSync(f)) {
    fs.writeFileSync(f, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==', 'base64'));
  }
  return f;
}

// ── store creation through the dashboard ────────────────────────────────────

/**
 * The store with `slug`, created through the owner dashboard wizard when missing (the signed-in owner owns it).
 * Returns { id, slug, created }.
 */
async function ensureStore(page, base, { type, title, slug, template, ownerEmail }) {
  const row = sql('SELECT id FROM stores WHERE slug = ? LIMIT 1', [slug]);
  if (row.length) return { id: Number(row[0][0]), slug, created: false };

  await page.goto(base + '/stores/create', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('input[name="store_type"]');
  await page.evaluate((t) => { const r = document.querySelector(`input[name="store_type"][value="${t}"]`); r.click(); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }, type);
  await page.type('input[name="title"]', title);
  await page.evaluate(() => { document.querySelector('input[name="slug"]').value = ''; });
  await page.type('input[name="slug"]', slug);
  await page.type('input[name="address"]', '1 Test Street, Riyadh');
  await page.type('textarea[name="description"]', 'Created by the e2eA journeys.');
  await page.type('input[name="phone"]', '+966500000001');
  // step 2
  await page.evaluate(() => [...document.querySelectorAll('button')].find((b) => /choose template/i.test(b.textContent))?.click());
  await sleep(400);
  const png = pngFile();
  await (await page.$('#create-main-image')).uploadFile(png);
  await (await page.$('#create-cover-image')).uploadFile(png);
  if (template) {
    await page.evaluate((tpl) => document.querySelector(`input.ui-create__tpl-input[value="${tpl}"]`)?.click(), template);
  } else {
    // the first visible template card of this store type's set
    await page.evaluate(() => {
      const card = [...document.querySelectorAll('.ui-create__tpl')].find((c) => c.offsetParent !== null);
      card?.querySelector('input')?.click();
    });
  }
  await sleep(300);
  await page.evaluate(() => [...document.querySelectorAll('button')].find((b) => /^\s*review/i.test(b.textContent))?.click());
  await sleep(800);
  await Promise.all([
    page.evaluate(() => document.querySelector('form[action$="/stores"] button[type="submit"]')?.click()),
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
  ]);
  let made = sql('SELECT id FROM stores WHERE slug = ? LIMIT 1', [slug]);
  if (!made.length && await page.$('[data-store-limit]')) {
    // Community build: the owner already has a store of this type (one per type per business). These journeys test
    // other features, so the extra store comes from a fixture seeder (db:seed is not limited); the limit itself has
    // its own journey (uncovered/f-store-limits.js).
    execFileSync(cfg.phpArtisan, ['artisan', 'db:seed', '--class=Juwri\\Modules\\Storefront\\Database\\Seeders\\E2eLaneAStoreSeeder', '--force'], {
      cwd: P.siteCoreDir(), encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
      env: { ...process.env, E2E_STORE_OWNER_EMAIL: ownerEmail || LANE_OWNER.email, E2E_STORE_TYPE: type, E2E_STORE_TITLE: title, E2E_STORE_SLUG: slug },
    });
    made = sql('SELECT id FROM stores WHERE slug = ? LIMIT 1', [slug]);
  }
  if (!made.length) {
    const t = (await pageText(page)).slice(0, 600);
    throw new Error(`ensureStore(${slug}): the dashboard wizard did not create the store (${page.url()}: ${t})`);
  }
  return { id: Number(made[0][0]), slug, created: true };
}

// ── catalog helpers (owner dashboard, goods stores) ────────────────────────

/** Submit a form on the current page with the page's own submit path and wait for the redirect. */
async function submitForm(page, selector) {
  await Promise.all([
    page.evaluate((sel) => { const f = document.querySelector(sel); if (!f) throw new Error('no form ' + sel); f.requestSubmit ? f.requestSubmit() : f.submit(); }, selector),
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
  ]);
}

/** A product category through the dashboard (Products page); returns its id (existing one reused by name). */
async function ensureProductCategory(page, base, storeId, name) {
  const have = sql('SELECT id FROM categories WHERE store_id = ? AND name = ? LIMIT 1', [storeId, name]);
  if (have.length) return Number(have[0][0]);
  await page.goto(`${base}/stores/${storeId}/products`, { waitUntil: 'domcontentloaded' });
  await page.type(`form[action$="/stores/${storeId}/product-categories"] input[name=name]`, name);
  await submitForm(page, `form[action$="/stores/${storeId}/product-categories"]`);
  const made = sql('SELECT id FROM categories WHERE store_id = ? AND name = ? LIMIT 1', [storeId, name]);
  expect(made.length, `ensureProductCategory(${name}): not created`);
  return Number(made[0][0]);
}

/**
 * A product through the dashboard's Add product form (goods stores: retail family + pharmacy). Reuses an existing
 * product of that name. Returns { id, name }.
 * @param {{name:string, price:number|string, categoryId?:number, stock?:number, barcode?:string, weight?:number, active?:boolean}} p
 */
async function ensureProduct(page, base, storeId, p) {
  const have = sql('SELECT id FROM items WHERE store_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1', [storeId, p.name]);
  if (have.length) return { id: Number(have[0][0]), name: p.name };
  await page.goto(`${base}/stores/${storeId}/products/create`, { waitUntil: 'domcontentloaded' });
  const form = `form[action$="/stores/${storeId}/products"]`;
  await page.waitForSelector(`${form} input[name=name]`);
  // Bug fixed on lane/a (price/cost/weight lacked `step`, so the browser refused 4.50): until that merges the Herd site
  // still serves the old form, so relax the constraint the same way the fixed markup does.
  await page.evaluate((sel) => document.querySelectorAll(`${sel} input[name=price], ${sel} input[name=cost], ${sel} input[name=weight]`).forEach((i) => { if (!i.getAttribute('step')) i.setAttribute('step', 'any'); }), form);
  await page.type(`${form} input[name=name]`, p.name);
  if (p.categoryId) await page.select(`${form} select[name=category_id]`, String(p.categoryId));
  await page.type(`${form} input[name=price]`, String(p.price));
  if (p.barcode) await page.type(`${form} input[name=barcode]`, p.barcode);
  if (p.weight) await page.type(`${form} input[name=weight]`, String(p.weight));
  if (p.stock !== undefined) {
    await page.evaluate((sel) => { const c = document.querySelector(`${sel} input[name=track_inventory]`); if (c && !c.checked) c.click(); }, form);
    await page.evaluate((sel, q) => { const e = document.querySelector(`${sel} input[name=stock_qty]`); e.value = q; e.dispatchEvent(new Event('input', { bubbles: true })); }, form, String(p.stock));
  }
  await page.evaluate((sel, on) => { const c = document.querySelector(`${sel} input[name=active]`); if (c && c.checked !== on) c.click(); }, form, p.active !== false);
  await submitForm(page, form);
  const made = sql('SELECT id FROM items WHERE store_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1', [storeId, p.name]);
  if (!made.length) {
    const t = await page.evaluate(() => [...document.querySelectorAll('[role=alert],.ui-banner,.ui-field__error')].map((e) => e.innerText.trim()).join(' | '));
    throw new Error(`ensureProduct(${p.name}): not created (${page.url()} ${t})`);
  }
  return { id: Number(made[0][0]), name: p.name };
}

/** A percentage coupon through the owner dashboard (Coupons > Add); returns its id (an existing code is reused). */
async function ensureCoupon(page, base, ownerId, code, percentage) {
  const have = sql('SELECT id FROM coupons WHERE user_id = ? AND code = ? LIMIT 1', [ownerId, code]);
  if (have.length) return Number(have[0][0]);
  await page.goto(base + '/coupons/create', { waitUntil: 'domcontentloaded' });
  const f = 'form[action$="/coupons"]';
  await page.waitForSelector(`${f} input[name=code]`);
  await page.type(`${f} input[name=code]`, code);
  await page.select(`${f} select[name=coupon_type]`, 'percentage');
  await page.evaluate((sel, v) => { const e = document.querySelector(`${sel} input[name=percentage]`); e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); }, f, String(percentage));
  await submitForm(page, f);
  const made = sql('SELECT id FROM coupons WHERE user_id = ? AND code = ? LIMIT 1', [ownerId, code]);
  expect(made.length, `ensureCoupon(${code}): not created (${page.url()})`);
  return Number(made[0][0]);
}

/**
 * Save store settings through the owner's store editor (the one big form at /stores/{id}/edit): sets each named
 * control (input / select / checkbox) and submits the whole form, so every other value is posted back unchanged.
 */
async function setStoreOptions(page, base, storeId, values) {
  await page.goto(`${base}/stores/${storeId}/edit`, { waitUntil: 'domcontentloaded' });
  const form = `form[action$="/stores/${storeId}"]`;
  await page.waitForSelector(`${form} [name]`);
  const missing = await page.evaluate((sel, vals) => {
    const f = document.querySelector(sel); const miss = [];
    for (const [name, v] of Object.entries(vals)) {
      const els = [...f.querySelectorAll(`[name="${name}"]`)].filter((e) => e.type !== 'hidden' || f.querySelectorAll(`[name="${name}"]`).length === 1);
      const el = els[els.length - 1];
      if (!el) { miss.push(name); continue; }
      if (el.type === 'checkbox') { if (el.checked !== !!v) el.click(); }
      else { el.value = String(v); el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }
    }
    return miss;
  }, form, values);
  expect(!missing.length, `setStoreOptions: no control named ${missing.join(', ')} on the store editor`);
  await submitForm(page, form);
}

/**
 * A service category for a laundry store. Through the category form on the Services page (added on lane/a — a laundry
 * owner had no way to create one, and the storefront lists services only under a category). Until that merges, the
 * Herd site still serves the old page without the form: then the legacy add-category endpoint is used (same owner
 * session + CSRF) and the fallback is logged by the caller via the returned `viaForm`.
 */
async function ensureServiceCategory(page, base, storeId, name) {
  const have = sql('SELECT id FROM categories WHERE store_id = ? AND name = ? LIMIT 1', [storeId, name]);
  if (have.length) return { id: Number(have[0][0]), viaForm: true };
  await page.goto(`${base}/stores/${storeId}/services`, { waitUntil: 'domcontentloaded' });
  const form = `form[action$="/stores/${storeId}/product-categories"]`;
  const hasForm = await page.$(form);
  if (hasForm) {
    await page.type(`${form} input[name=name]`, name);
    await submitForm(page, form);
  } else {
    await req(page, `${base}/stores/${storeId}/add-category`, { method: 'POST', form: { name } });
  }
  const made = sql('SELECT id FROM categories WHERE store_id = ? AND name = ? LIMIT 1', [storeId, name]);
  expect(made.length, `ensureServiceCategory(${name}): not created`);
  return { id: Number(made[0][0]), viaForm: !!hasForm };
}

/** A laundry/service item through the dashboard's Add service form (reused by name). Returns { id, name }. */
async function ensureService(page, base, storeId, s) {
  const have = sql('SELECT id FROM items WHERE store_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1', [storeId, s.name]);
  if (have.length) return { id: Number(have[0][0]), name: s.name };
  await page.goto(`${base}/stores/${storeId}/services/create`, { waitUntil: 'domcontentloaded' });
  const form = `form[action$="/stores/${storeId}/services"]`;
  await page.waitForSelector(`${form} input[name=name]`);
  await page.evaluate((sel) => { const p = document.querySelector(`${sel} input[name=price]`); if (p && !p.getAttribute('step')) p.setAttribute('step', 'any'); }, form); // same bug-fix note as ensureProduct
  await page.type(`${form} input[name=name]`, s.name);
  if (s.categoryId) await page.select(`${form} select[name=category_id]`, String(s.categoryId));
  await page.type(`${form} input[name=price]`, String(s.price));
  await page.select(`${form} select[name=pricing_unit]`, s.unit || 'each');
  if (s.turnaround) await page.type(`${form} input[name=turnaround_hours]`, String(s.turnaround));
  await submitForm(page, form);
  const made = sql('SELECT id FROM items WHERE store_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1', [storeId, s.name]);
  if (!made.length) {
    const t = await page.evaluate(() => [...document.querySelectorAll('[role=alert],.ui-banner,.ui-field__error')].map((e) => e.innerText.trim()).join(' | '));
    throw new Error(`ensureService(${s.name}): not created (${page.url()} ${t})`);
  }
  return { id: Number(made[0][0]), name: s.name };
}

/** Run `fn(page)` in a fresh isolated browser context (own cookies) and close it afterwards. */
async function withPage(browser, fn, { width = 1280, height = 900 } = {}) {
  const ctx = (await browser.createBrowserContext?.()) || browser;
  const page = await ctx.newPage();
  page.setDefaultNavigationTimeout(P.cfg.navTimeout);
  page.setDefaultTimeout(P.cfg.navTimeout);
  page.on('dialog', (d) => d.accept().catch(() => {}));
  await page.setViewport({ width, height });
  try { return await fn(page); } finally {
    await page.close().catch(() => {});
    if (ctx !== browser) await ctx.close().catch(() => {});
  }
}

/** HTTP status of a plain GET with the page's cookies, without following redirects ("redirect" = a 3xx). */
async function status(page, url) {
  return page.evaluate(async (u) => {
    const r = await fetch(u, { credentials: 'same-origin', redirect: 'manual', headers: { Accept: 'text/html' } });
    return r.type === 'opaqueredirect' ? 'redirect' : r.status;
  }, url);
}

/** What an anonymous customer gets for `url` (fresh browser context, no cookies): the HTTP status after redirects. */
async function publicStatus(browser, url) {
  return withPage(browser, async (p) => {
    const r = await p.goto(url, { waitUntil: 'domcontentloaded' });
    return r ? r.status() : 0;
  });
}

// ── owner provisioning through the superadmin dashboard ─────────────────────

const LANE_OWNER = { email: 'e2ea-owner@local.invalid', username: 'e2eaowner', name: 'e2eA Owner', pass: 'E2eA-owner-pass-1' };

/**
 * The lane-A owner account: created by the superadmin in the dashboard (Users > Add), put on the dev "Pro" plan
 * (the plan the themepack demo owner uses) and granted the features the journeys need (multi_vertical lets it create
 * non-restaurant stores). Idempotent: an existing user / grant is left alone. Returns { id, email, pass }.
 * Needs the dev superadmin (E2E_ADMIN_EMAIL / E2E_ADMIN_PASS from core/.env, seeded by E2eAdminSeeder).
 */
async function ensureLaneOwner(browser, { features = ['multi_vertical'] } = {}) {
  const ctx = (await browser.createBrowserContext?.()) || browser;
  const page = await ctx.newPage();
  try {
    expect(cfg.adminPass, 'ensureLaneOwner: E2E_ADMIN_PASS is not available (core/.env) — run E2eAdminSeeder');
    await login(page, cfg.baseUrl, { email: cfg.adminEmail, pass: cfg.adminPass });
    let row = sql('SELECT id FROM users WHERE email = ? LIMIT 1', [LANE_OWNER.email]);
    if (!row.length) {
      await page.goto(cfg.baseUrl + '/admin/users/create', { waitUntil: 'domcontentloaded' });
      await page.type('input[name="name"]', LANE_OWNER.name);
      await page.type('input[name="username"]', LANE_OWNER.username);
      await page.type('input[name="email"]', LANE_OWNER.email);
      await page.select('select[name="country"]', await page.evaluate(() => [...document.querySelectorAll('select[name="country"] option')].find((o) => /saudi/i.test(o.textContent))?.value));
      await page.type('input[name="password"]', LANE_OWNER.pass);
      await Promise.all([
        page.evaluate(() => document.querySelector('form[action$="/admin/users"]').submit() /* the Add-user panel fragment: its Save button is wired by the parent list page */),
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      ]);
      row = sql('SELECT id FROM users WHERE email = ? LIMIT 1', [LANE_OWNER.email]);
      expect(row.length, `ensureLaneOwner: the admin form did not create ${LANE_OWNER.email} (${page.url()})`);
    }
    const id = Number(row[0][0]);
    // Community build (no edition package, so no `plans` table and no Admin > Feature catalog): nothing is sold there,
    // every feature key is included (CommunityEntitlementSource) — no plan to set, no grant to add.
    // (an Enterprise package on disk WITHOUT a licence runs as Community too: /admin/plans and the feature catalog answer 404)
    if (!sql("SHOW TABLES LIKE 'plans'").length || !P.eeActive()) return { id, email: LANE_OWNER.email, pass: LANE_OWNER.pass };
    // plan: the Pro plan (same as the themepack demo owner)
    const proPlan = sqlValue("SELECT id FROM plans WHERE name = 'Pro Packege' LIMIT 1");
    if (proPlan && String(sqlValue('SELECT group_id FROM users WHERE id = ?', [id])) !== String(proPlan)) {
      await page.goto(`${cfg.baseUrl}/admin/users/${id}/edit`, { waitUntil: 'domcontentloaded' });
      await page.select(`form[action$="/admin/users/${id}/edit/updateplan"] select[name="plan"]`, String(proPlan));
      const nextYear = new Date(Date.now() + 365 * 86400000).toISOString().slice(0, 10);
      await Promise.all([
        page.evaluate((u, d) => {
          const f = document.querySelector(`form[action$="/admin/users/${u}/edit/updateplan"]`);
          const e = f.querySelector('[name="plan_expiration_date"]'); if (e) e.value = d;
          f.submit();
        }, id, nextYear),
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      ]);
      expect(String(sqlValue('SELECT group_id FROM users WHERE id = ?', [id])) === String(proPlan), 'ensureLaneOwner: the admin plan change did not stick');
    }
    // Delivery and coupons are plan keys since the pricing build (PlanGate): a plan that holds 0 for them refuses delivery orders / coupon
    // creation. The journeys test the fulfilment and coupon flows, not the gate, so the lane owner always has them (the gate has its own tests).
    for (const feature of [...new Set([...features, 'delivery', 'coupons'])]) {
      if (sql('SELECT 1 FROM entitlement_overrides WHERE user_id = ? AND feature_key = ? LIMIT 1', [id, feature]).length) continue;
      await page.goto(cfg.baseUrl + '/admin/feature-catalog', { waitUntil: 'domcontentloaded' });
      await page.evaluate(({ id, feature }) => {
        const f = document.querySelector('form[action$="/admin/feature-catalog/grant"]');
        f.querySelector('[name="user_id"]').value = id;
        f.querySelector('[name="feature_key"]').value = feature;
        const r = f.querySelector('[name="reason"]'); if (r) r.value = 'e2eA journeys';
      }, { id, feature });
      await Promise.all([
        page.evaluate(() => document.querySelector('form[action$="/admin/feature-catalog/grant"] [type="submit"]')?.click()),
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      ]);
      expect(sql('SELECT 1 FROM entitlement_overrides WHERE user_id = ? AND feature_key = ? LIMIT 1', [id, feature]).length, `ensureLaneOwner: grant ${feature} did not stick`);
    }
    return { id, email: LANE_OWNER.email, pass: LANE_OWNER.pass };
  } finally {
    await page.close().catch(() => {});
    if (ctx !== browser) await ctx.close().catch(() => {});
  }
}

// ── food-menu helpers (restaurant-type stores: accounting, kiosk, channels and driver journeys) ─────────────────────

/** A menu category through the dashboard Menu page (existing one reused by name). Returns its id. */
async function ensureMenuCategory(page, base, storeId, name) {
  const have = sql('SELECT id FROM categories WHERE store_id = ? AND name = ? LIMIT 1', [storeId, name]);
  if (have.length) return Number(have[0][0]);
  await page.goto(`${base}/stores/${storeId}/menu`, { waitUntil: 'domcontentloaded' });
  const form = `form[action$="/stores/${storeId}/add-category"]`;
  await page.waitForSelector(`${form} input[name=name]`);
  await page.type(`${form} input[name=name]`, name);
  await submitForm(page, form);
  const made = sql('SELECT id FROM categories WHERE store_id = ? AND name = ? LIMIT 1', [storeId, name]);
  expect(made.length, `ensureMenuCategory(${name}): not created (${page.url()})`);
  return Number(made[0][0]);
}

/**
 * A food menu item through the dashboard's add-item endpoint (the Menu page's own modal posts these fields with the
 * page's CSRF token). Reuses an existing item of that name. Returns { id, name }.
 */
async function ensureMenuItem(page, base, storeId, { name, price, categoryId }) {
  const have = sql('SELECT id FROM items WHERE store_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1', [storeId, name]);
  if (have.length) return { id: Number(have[0][0]), name };
  await page.goto(`${base}/stores/${storeId}/menu`, { waitUntil: 'domcontentloaded' });
  const res = await page.evaluate(async (url, fields) => {
    const token = document.querySelector('input[name=_token]').value;
    const fd = new FormData();
    fd.append('_token', token);
    Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
    const r = await fetch(url, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token } });
    return { status: r.status, json: await r.json().catch(() => null) };
  }, `${base}/stores/${storeId}/add-menu-item`, { category_id: String(categoryId), name, price: String(price), type: 'veg', active: '1' });
  const made = sql('SELECT id FROM items WHERE store_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1', [storeId, name]);
  expect(made.length, `ensureMenuItem(${name}): not created (${JSON.stringify(res)})`);
  return { id: Number(made[0][0]), name };
}

/**
 * The fixtures the lane-A journeys need beyond the dashboard: the test-only `stub` payment gateway row and the
 * default-OFF platform switches (accounting, kiosk, driver app) — through E2eLaneAJourneysSeeder, run from the lane's
 * own code against the same database. The stub gateway stays inert unless the serving app is local/testing with
 * E2E_STUBS armed, so journeys that pay through it run on E2E_ARMED_URL.
 */
function seedLaneFixtures() {
  execFileSync(cfg.phpArtisan, ['artisan', 'db:seed', '--class=Juwri\\Modules\\Storefront\\Database\\Seeders\\E2eLaneAJourneysSeeder', '--force'], {
    // the SITE's tree (E2E_APP_DIR via P.siteCoreDir): its .env names the site's database; this tree's .env may not
    cwd: P.siteCoreDir(), encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
  });
  expect(sql("SELECT 1 FROM payment_gateways WHERE payment_folder = 'stub' AND payment_install = '1' LIMIT 1").length, 'seedLaneFixtures: the stub gateway row was not seeded');
}

module.exports = {
  storeOption, ensureMenuCategory, ensureMenuItem, seedLaneFixtures, storefrontCsrf, ensureServiceCategory, setStoreOptions, ensureService, journey, publicStatus, withPage, status, ensureCoupon, submitForm, ensureProductCategory, ensureProduct, ensureLaneOwner, LANE_OWNER,
  PREFIX, cfg, sleep, recorder, expect, hasEe, sql, sqlValue, login, req, pageText, pngFile, ensureStore, resetSession,
  P,
};
