'use strict';

/**
 * P1-12 item 9 h + the pharmacy promotion journey (Lane C) — small helpers, self-contained so they do not depend on
 * another lane's uncommitted lib.
 *
 *  - sql(): a READ-ONLY probe of the dev database through `php` PDO; the credentials are read by php from the Herd
 *    site's core/.env and never reach this process or the log. Setup goes through the dashboards (admin / owner /
 *    reseller) and seeders only; sql() just reads the ground truth.
 *  - base / armed: E2E_BASE_URL (juw.test, MAIN) and E2E_ARMED_URL — a second local server of the code under test
 *    started with the test-only switches the Herd site does not have (E2E_STUBS=1 for the Stub gateway,
 *    PHARMACY_ENABLED_COUNTRIES=SA). Legs that need a switch run on the armed URL; without it they skip with a clear
 *    reason (never a silent pass).
 *  - E2E_DB names another database than core/.env's (the armed instance on the lane's own database, e.g. juwri_c).
 *  - every record a journey creates is prefixed "e2eC-".
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const PREFIX = 'e2eC-';
const ENV_FILE = process.env.E2E_ENV_FILE || path.resolve(__dirname, '../../../.env'); // this tree's core/.env
const HERD_PHP = path.join(os.homedir(), 'Library/Application Support/Herd/bin/php84');

function envFileVar(name) {
  try {
    const m = fs.readFileSync(ENV_FILE, 'utf8').match(new RegExp(`^${name}=(.*)$`, 'm'));
    return m ? m[1].trim().replace(/^["']|["']$/g, '') : '';
  } catch (_e) { return ''; }
}

const cfg = {
  base: (process.env.E2E_BASE_URL || 'https://juw.test').replace(/\/$/, ''),
  armed: (process.env.E2E_ARMED_URL || '').replace(/\/$/, ''),
  php: process.env.E2E_PHP || (fs.existsSync(HERD_PHP) ? HERD_PHP : 'php'),
  adminEmail: process.env.E2E_ADMIN_EMAIL || envFileVar('E2E_ADMIN_EMAIL'),
  adminPass: process.env.E2E_ADMIN_PASS || envFileVar('E2E_ADMIN_PASS'),
  navTimeout: parseInt(process.env.E2E_NAV_TIMEOUT || '45000', 10),
};

const PHP_SQL = `
$f = getenv('E2E_C_ENV'); $env = [];
foreach (file($f, FILE_IGNORE_NEW_LINES) as $l) { if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $l, $m)) { $env[$m[1]] = trim($m[2], "\\"' "); } }
if (getenv('E2E_DB')) { $env['DB_DATABASE'] = getenv('E2E_DB'); }
if (getenv('E2E_DB_HOST')) { $env['DB_HOST'] = getenv('E2E_DB_HOST'); $env['DB_PORT'] = getenv('E2E_DB_PORT') ?: '3306'; $env['DB_USERNAME'] = getenv('E2E_DB_USER'); $env['DB_PASSWORD'] = getenv('E2E_DB_PASS') ?: ''; $env['DB_CONNECTION'] = 'mysql'; } // runner_e2e.sh // the armed instance may run on the lane's own database
$drv = $env['DB_CONNECTION'] ?? 'mysql';
$dsn = $drv === 'pgsql'
  ? "pgsql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']}"
  : "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']};charset=utf8mb4";
$pdo = new PDO($dsn, $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$q = getenv('E2E_C_SQL');
if (! preg_match('/^\\s*(select|show)\\b/i', $q)) { fwrite(STDERR, "sql(): read-only\\n"); exit(2); }
$st = $pdo->prepare($q); $st->execute(json_decode(getenv('E2E_C_PARAMS'), true) ?: []);
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
`;

/** Rows of a read-only query (objects keyed by column). */
function sql(query, params = []) {
  try {
    const out = execFileSync(cfg.php, ['-r', PHP_SQL], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      env: { ...process.env, E2E_C_ENV: ENV_FILE, E2E_C_SQL: query, E2E_C_PARAMS: JSON.stringify(params) },
      maxBuffer: 32 * 1024 * 1024,
    });
    return JSON.parse(out || '[]');
  } catch (e) {
    // Only the driver's message (never the DSN / credentials, which stay inside php).
    const msg = String((e.stderr || '') + (e.stdout || '')).match(/SQLSTATE[^\n]*|read-only/);
    throw new Error(`sql(): ${msg ? msg[0].slice(0, 300) : 'query failed'} — ${query.slice(0, 160)}`);
  }
}

function one(query, params = []) {
  const rows = sql(query, params);
  return rows.length ? rows[0] : null;
}

/** A checks recorder: ok() logs + counts, skip() logs a clearly labelled skip (never a silent pass). */
function recorder(tag, log) {
  const checks = [];
  const skips = [];
  return {
    checks,
    skips,
    ok(name) { checks.push(name); log(`  ${tag} ok   ${name}`); },
    skip(name, why) { skips.push(`${name} — ${why}`); log(`  ${tag} SKIP ${name} — ${why}`); },
    fail(msg) { throw new Error(`${tag}: ${msg}`); },
    summary() { log(`  ${tag} ${checks.length} checks passed, ${skips.length} skipped`); },
  };
}

/** Fresh browser context page (own cookies) for a second actor. */
async function actorPage(browser) {
  const ctx = (await browser.createBrowserContext?.()) || browser;
  const page = await ctx.newPage();
  page.setDefaultNavigationTimeout(cfg.navTimeout);
  page.on('dialog', (d) => d.accept().catch(() => {}));

  return { ctx, page, async close() { await page.close().catch(() => {}); if (ctx !== browser) await ctx.close().catch(() => {}); } };
}

/** Sign in through the real login form on `base` (owner, admin and reseller share /login). */
async function login(page, base, email, pass, loginPath = '/login') {
  const client = await page.target().createCDPSession();
  await client.send('Network.clearBrowserCookies').catch(() => {});
  await page.goto(base + loginPath, { waitUntil: 'domcontentloaded' });
  const userSel = '#username, input[name="username"], input[name="email"]';
  await page.waitForSelector(userSel, { timeout: cfg.navTimeout });
  await page.type(userSel, email);
  await page.type('#password, input[name="password"]', pass);
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
  ]);
  const landed = new URL(page.url()).pathname;
  if (/\/login$/.test(landed)) throw new Error(`login rejected for ${email} on ${base} (still on ${landed})`);
  return landed;
}

/**
 * fetch() from inside the page (same origin, session cookie + CSRF token), as the dashboard's own forms and scripts
 * do. body: object → form-encoded (arrays as key[]=…), FormData-able, or JSON when json=true.
 */
async function call(page, method, url, body = null, { json = false, accept = 'application/json' } = {}) {
  return page.evaluate(async ({ method, url, body, json, accept }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || window.CRAVE?.csrf || '';
    const headers = { Accept: accept, 'X-Requested-With': 'XMLHttpRequest', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) };
    let payload;
    if (body && json) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
    else if (body) {
      const p = new URLSearchParams();
      const add = (k, v) => {
        if (Array.isArray(v)) v.forEach((x) => add(`${k}[]`, x));
        else if (v && typeof v === 'object') Object.entries(v).forEach(([kk, vv]) => add(`${k}[${kk}]`, vv));
        else p.append(k, v === true ? '1' : v === false ? '0' : String(v));
      };
      Object.entries(body).forEach(([k, v]) => add(k, v));
      if (csrf && !p.has('_token')) p.append('_token', csrf);
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
      payload = p.toString();
    }
    const r = await fetch(url, { method, credentials: 'same-origin', headers, body: payload, redirect: 'follow' });
    const text = await r.text();
    let data = null; try { data = JSON.parse(text); } catch (_e) { data = null; }
    return { status: r.status, url: r.url, data, text: data ? '' : text.slice(0, 4000) };
  }, { method, url, body, json, accept });
}

/** A short-lived temp PNG (logo / cover uploads need a real image). */
function tempPng(tag) {
  const p = path.join(os.tmpdir(), `e2e-c-${tag}-${Date.now()}-${Math.random().toString(36).slice(2)}.png`);
  fs.writeFileSync(p, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgYGAAAAAEAAH2FzhVAAAAAElFTkSuQmCC', 'base64'));
  return p;
}

/** Whether the ee edition is active on `base` (the admin plans page exists only with ee). */
async function eeActive(page, base) {
  const r = await page.goto(base + '/admin/plans', { waitUntil: 'domcontentloaded' }).catch(() => null);
  return !!r && r.status() < 400 && !/\/login$/.test(new URL(page.url()).pathname);
}

const stamp = () => `${Date.now().toString(36)}${Math.floor(Math.random() * 1e4).toString(36)}`;

/**
 * P4 journeys: a `php -S` instance of the app at `root` (core/ under it) on 127.0.0.1:<port>, its own process group,
 * UNREF'd so a finished run exits (lane-b's startPhpServer keeps node alive). stop() kills only this group.
 */
async function startServer(root, port, env = {}, router = null) {
  const { spawn } = require('child_process');
  const http = require('http');
  const child = spawn(cfg.php, ['-S', `127.0.0.1:${port}`, '-t', root, router || path.join(root, 'router.php')], {
    cwd: root, env: { ...process.env, ...env, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore', detached: true,
  });
  child.unref();
  let exited = false;
  child.on('exit', () => { exited = true; });
  const ping = () => new Promise((resolve) => {
    const req = http.get(`http://127.0.0.1:${port}/login`, (res) => { res.resume(); resolve(res.statusCode); });
    req.on('error', () => resolve(0));
    req.setTimeout(2000, () => { req.destroy(); resolve(0); });
  });
  for (let i = 0; i < 75 && !(await ping()); i++) {
    if (exited) throw new Error(`php -S on ${port} exited early (is the port busy?)`);
    await new Promise((r) => setTimeout(r, 200));
  }
  return { stop() { try { process.kill(-child.pid, 'SIGTERM'); } catch (_e) { /* gone */ } } };
}

module.exports = { PREFIX, cfg, sql, one, recorder, actorPage, login, call, tempPng, eeActive, stamp, startServer };
