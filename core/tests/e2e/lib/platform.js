'use strict';

/**
 * Platform E2E run (W0.2) — shared helpers layered on top of `lib/harness.js`.
 *
 * No new dependency: only `fs` + `child_process` (already used by the repo's
 * node tooling) plus the `page` object puppeteer hands each journey. Every knob
 * is env-driven so the same helpers run against a local Herd HTTPS lane site or
 * the nightly CI instance. See rev/platform-e2e/HANDOFF.md §2–§4.
 *
 * The run is Arabic-only: `ar` is the site default, so the unprefixed URL is
 * Arabic and `localeUrl('/x')` returns it with no prefix; `localeUrl('/x','en')`
 * prefixes `/en`. Detection of the site default is done once, lazily, via curl.
 */

const fs = require('fs');
const { execFileSync } = require('child_process');

const cfg = {
  baseUrl: (process.env.E2E_BASE_URL || 'https://e2e-f0.test').replace(/\/$/, ''),
  locale: process.env.E2E_LOCALE || 'ar',
  db: process.env.E2E_DB || '',
  // juwri tables carry no prefix (DB_PREFIX ''); an explicitly empty E2E_DB_PREFIX means none (it used to fall back
  // to the legacy 'qrtest_', which only the runner's sqlite shim stripped).
  dbPrefix: process.env.E2E_DB_PREFIX !== undefined ? process.env.E2E_DB_PREFIX : 'qrtest_',
  mysqlBin: process.env.E2E_MYSQL_BIN || 'mysql',
  log: process.env.E2E_LOG || '',
  navTimeout: parseInt(process.env.E2E_NAV_TIMEOUT || '30000', 10),
};

// ── locale-aware URLs ────────────────────────────────────────────────────────

let _siteDefault = null;
/**
 * The site's default locale (the one served with NO URL prefix). Detected once
 * from `<html lang>` on `GET /` via curl (synchronous, cert-ignoring), cached
 * for the process; falls back to cfg.locale if the probe fails.
 * @returns {string} e.g. "ar"
 */
function siteDefaultLocale() {
  if (_siteDefault) return _siteDefault;
  try {
    const html = execFileSync('curl', ['-sk', ...(process.env.E2E_CURL_CONNECT_TO ? ['--connect-to', process.env.E2E_CURL_CONNECT_TO] : []), '--max-time', '20', cfg.baseUrl + '/'], {
      encoding: 'utf8', maxBuffer: 16 * 1024 * 1024,
    });
    const m = html.match(/<html[^>]*\blang="([a-zA-Z-]+)"/i);
    _siteDefault = m ? m[1].toLowerCase().split('-')[0] : (cfg.locale || 'ar');
  } catch (_e) {
    _siteDefault = cfg.locale || 'ar';
  }
  return _siteDefault;
}

/**
 * Absolute URL for `path` in `locale`. The default locale is unprefixed
 * (`hideDefaultLocaleInURL`); any other locale gets a `/{locale}` prefix.
 * @param {string} path e.g. "/crave-demo" or "crave-demo"
 * @param {string} [locale=cfg.locale]
 * @returns {string} absolute URL
 */
function localeUrl(path, locale = cfg.locale) {
  const p = path.startsWith('/') ? path : '/' + path;
  const def = siteDefaultLocale();
  if (!locale || locale === def) return cfg.baseUrl + p;
  return cfg.baseUrl + '/' + locale + p;
}

// ── site tree ────────────────────────────────────────────────────────────────

/**
 * The core/ folder of the site under test: E2E_APP_DIR when the site is another tree (a Herd site driven by
 * sweep_local.sh), else this checkout's core/. Its parent is the repo root (storage/, assets/).
 * @returns {string}
 */
function siteCoreDir() {
  return process.env.E2E_APP_DIR || require('path').resolve(__dirname, '../../..');
}

// ── edition ──────────────────────────────────────────────────────────────────

let _ee = null;
/**
 * True when the site runs the Enterprise edition (the Enterprise package installed AND licensed): the ee-only admin plans route is
 * registered, so an anonymous GET answers a redirect to the login (302) instead of 404. A Community build (mirror,
 * no Enterprise package) or an unlicensed install answers 404. Synchronous (curl), cached for the process.
 * @returns {boolean}
 */
function eeActive() {
  if (_ee !== null) return _ee;
  let code = '';
  try {
    code = execFileSync('curl', ['-sk', ...(process.env.E2E_CURL_CONNECT_TO ? ['--connect-to', process.env.E2E_CURL_CONNECT_TO] : []),
      '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '30', cfg.baseUrl + '/admin/plans'], { encoding: 'utf8' }).trim();
  } catch (_e) { code = ''; }
  if (!/^[1-5]\d\d$/.test(code)) throw new Error(`eeActive: could not probe ${cfg.baseUrl}/admin/plans (got "${code}")`);
  _ee = code !== '404';
  return _ee;
}

/**
 * For a journey that needs the Enterprise edition: `{ skip: reason }` on Community (return it from the journey so
 * run.js reports it as skipped), null when ee is active.
 * @param {string} what the Enterprise feature the journey exercises, e.g. "Reseller"
 */
function requireEe(what) {
  return eeActive() ? null : { skip: `Community build: ${what} is an Enterprise feature (Enterprise package not installed / not licensed)` };
}

// ── DOM assertions ───────────────────────────────────────────────────────────

/**
 * Assert the landed page is right-to-left for the run locale. For `ar` requires
 * `<html dir="rtl" lang="ar">`. Returns the observed shell for logging.
 * @param {import('puppeteer').Page} page
 */
async function assertRtl(page) {
  const shell = await page.evaluate(() => ({
    lang: (document.documentElement.lang || '').toLowerCase(),
    dir: (document.documentElement.dir || '').toLowerCase(),
    url: location.href,
  }));
  if (cfg.locale === 'ar') {
    if (shell.dir !== 'rtl') {
      throw new Error(`assertRtl: expected dir="rtl", got dir="${shell.dir}" (lang="${shell.lang}", ${shell.url})`);
    }
    if (shell.lang && shell.lang.split('-')[0] !== 'ar') {
      throw new Error(`assertRtl: expected lang="ar", got lang="${shell.lang}" (${shell.url})`);
    }
  }
  return shell;
}

/**
 * Assert the document does not scroll horizontally (scrollWidth ≤ innerWidth,
 * 1px tolerance). Returns the overflow in px.
 * @param {import('puppeteer').Page} page
 */
async function assertNoOverflow(page) {
  const overflow = await page.evaluate(() => Math.max(
    document.documentElement.scrollWidth,
    document.body ? document.body.scrollWidth : 0,
  ) - window.innerWidth);
  if (overflow > 1) {
    // Name the offenders (elements poking out past the viewport edge) so a failure is actionable.
    const culprits = await page.evaluate(() => {
      const w = window.innerWidth;
      return [...document.querySelectorAll('body *')]
        .map((e) => ({ e, r: e.getBoundingClientRect() }))
        .filter(({ r }) => r.width > 0 && (r.right > w + 1 || r.left < -1))
        .slice(0, 6)
        .map(({ e, r }) => `${e.tagName.toLowerCase()}${e.id ? '#' + e.id : ''}${e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/).slice(0, 2).join('.') : ''}[${Math.round(r.left)}..${Math.round(r.right)}]`);
    }).catch(() => []);
    throw new Error(`assertNoOverflow: ${overflow}px horizontal overflow at ${page.url()} (viewport ${await page.evaluate(() => window.innerWidth)}px)${culprits.length ? ' — ' + culprits.join(', ') : ''}`);
  }
  return overflow;
}

// ── console / CSP capture ────────────────────────────────────────────────────

// Console `error` lines that are benign page noise, not app breakage: a missing
// favicon/image is a 404 resource load, not a JS fault. CSP "Refused to" lines
// are never ignored.
const BENIGN_CONSOLE = /Failed to load resource:.*(favicon|\.png|\.jpg|\.jpeg|\.webp|\.svg|\.gif|\.ico)/i;

/**
 * Start capturing console errors, page errors and CSP refusals on `page`.
 * Returns a collector `{ errors, stop() }`; also stashed on the page so
 * `assertNoConsoleErrors(page)` can find it without threading it through.
 * @param {import('puppeteer').Page} page
 * @param {{ignore?: RegExp[]}} [opts] extra patterns to treat as benign
 */
function collectConsole(page, opts = {}) {
  const ignore = [BENIGN_CONSOLE, ...(opts.ignore || [])];
  const errors = [];
  // Only an ENFORCED refusal ("Refused to …") is a real block. Report-only CSP
  // violations are advisory (the storefront CSP soaks in report-only; GA/GTM
  // trip connect-src there) — strict CSP is the separate E2E_REQUIRE_CSP path.
  // Chrome prints report-only violations two ways: an inline "…report-only…"
  // AND a "[Report Only] Refused to …" prefix (a SPACE, not a hyphen). Match
  // both, or a space-prefixed advisory refusal is mis-tagged as enforced.
  const reportOnly = /report[\s-]?only/i;
  const isCsp = (t) => /Refused to (load|execute|apply|connect|frame|run)/i.test(t) && !reportOnly.test(t);
  const onConsole = (m) => {
    const t = m.text();
    if (isCsp(t)) { errors.push('CSP: ' + t); return; }
    if (reportOnly.test(t)) return; // advisory CSP report, not breakage
    if (m.type() === 'error' && !ignore.some((re) => re.test(t))) errors.push('console.error: ' + t + ' [at ' + (page.url() || '').slice(0, 120) + ']');
  };
  const onPageError = (e) => errors.push('pageerror: ' + e.message + (e.stack ? ' @ ' + String(e.stack).split('\n').slice(1, 3).map((x) => x.trim()).join(' | ') : ''));
  page.on('console', onConsole);
  page.on('pageerror', onPageError);
  const collector = {
    errors,
    stop() { page.off('console', onConsole); page.off('pageerror', onPageError); return errors; },
  };
  page.__platformConsole = collector;
  return collector;
}

/**
 * Fail if any console error / page error / CSP refusal was captured since
 * `collectConsole(page)` (or the passed collector) was started.
 * @param {import('puppeteer').Page} page
 * @param {{errors:string[]}} [collector]
 */
async function assertNoConsoleErrors(page, collector) {
  const c = collector || page.__platformConsole;
  if (!c) throw new Error('assertNoConsoleErrors: call collectConsole(page) first');
  if (c.errors.length) throw new Error(`console/CSP errors (${c.errors.length}):\n  ${c.errors.join('\n  ')}`);
}

// ── logins ───────────────────────────────────────────────────────────────────

async function resetBrowserSession(page) {
  // Storage first (needs the page's origin), then leave the page so no in-flight request or poll of the old session
  // can write a cookie back, then drop the cookies of the base site AND of the page we were on (page.cookies() with
  // no URL only returns the current page's cookies; a journey may end on another host or path).
  const urls = [cfg.baseUrl + '/'];
  try { const u = page.url(); if (/^https?:/.test(u)) urls.push(u); } catch (_e) { /* blank page */ }
  await page.evaluate(async () => {
    try { localStorage.clear(); sessionStorage.clear(); } catch (_e) { /* opaque origin */ }
    if (window.caches) { const keys = await caches.keys(); await Promise.all(keys.map((k) => caches.delete(k))); }
    if (navigator.serviceWorker) {
      const regs = await navigator.serviceWorker.getRegistrations();
      await Promise.all(regs.map((r) => r.unregister()));
    }
  }).catch(() => {});
  await page.goto('about:blank').catch(() => {});
  const cookies = await page.cookies(...urls).catch(() => []);
  if (cookies.length) await page.deleteCookie(...cookies);
}

/**
 * Submit the web login form (owner / reseller / admin all share `/login` with a
 * `username` field; staff use `/staff/login` with `email`). Mirrors
 * role-pages-smoke.js `submitWebLogin`. Returns the landed URL; throws if the
 * credentials bounce back to the login page.
 */
async function webLogin(page, { email, pass, loginPath = '/login' }, retried = false) {
  await resetBrowserSession(page);
  await page.goto(cfg.baseUrl + loginPath, { waitUntil: 'domcontentloaded' });
  const userSel = loginPath === '/staff/login'
    ? '#email, input[name="email"]'
    : '#username, input[name="username"], input[name="email"]';
  await page.waitForSelector(userSel, { timeout: cfg.navTimeout });
  await page.type(userSel, email);
  await page.type('#password, input[name="password"]', pass);
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
  ]);
  const landed = new URL(page.url()).pathname;
  if (landed === '/login' || landed === '/staff/login') {
    // The login form is throttled per username + IP (6/min) and the whole pack signs in from one IP, so a
    // bounce can be the limiter rather than the credentials: reset it once and retry before giving up.
    if (!retried) {
      resetRateLimits();
      return webLogin(page, { email, pass, loginPath }, true);
    }
    const err = await page.evaluate(() => (document.querySelector('.alert, .invalid-feedback, [role="alert"]')?.textContent || '').replace(/\s+/g, ' ').trim());
    throw new Error(`login rejected for ${email} (still on ${landed}${err ? ': ' + err : ''})`);
  }
  return page.url();
}

const loginOwner = (page, { email, pass }) => webLogin(page, { email, pass });
const loginReseller = (page, { email, pass }) => webLogin(page, { email, pass });
const loginAdmin = (page, { email, pass }) => webLogin(page, { email, pass });
const loginStaff = (page, { email, pass }) => webLogin(page, { email, pass, loginPath: '/staff/login' });

/**
 * Storefront customer login by password. The page must already be on the store
 * (so `window.CRAVE` / the csrf meta are present); pass `slug` to derive the
 * store from the current URL if needed. Returns `{ ok, status }`.
 */
async function loginCustomer(page, { email, pass, slug }) {
  slug = slug || page.url().replace(cfg.baseUrl, '').split('?')[0].split('/').filter(Boolean)[0];
  const result = await page.evaluate(async ({ email, pass, slug }) => {
    const csrf = window.CRAVE?.csrf || document.querySelector('meta[name="csrf-token"]')?.content;
    const rid = window.CRAVE?.storeId || document.querySelector('meta[name="store-id"]')?.content;
    const endpoint = window.CRAVE?.endpoints?.login || `/${slug}/customer/login`;
    if (!csrf || !rid) return { ok: false, status: 0, message: 'storefront did not expose csrf/restaurant id' };
    const r = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ email, password: pass, store_id: Number(rid) }),
    });
    return { ok: r.ok, status: r.status, body: await r.json().catch(() => ({})) };
  }, { email, pass, slug });
  if (!result.ok) throw new Error(`loginCustomer(${email}): ${result.status} ${JSON.stringify(result.body || result.message)}`);
  return result;
}

// ── mail / SMS log readers ───────────────────────────────────────────────────

const path = require('path');

/**
 * The actual mail-log file. `MAIL_MAILER=log` writes to the default log channel,
 * which is the `daily` driver here (`laravel-YYYY-MM-DD.log`), not the fixed
 * `laravel.log` that E2E_LOG names. Resolve to E2E_LOG if it exists, else the
 * newest `laravel*.log` in the same directory.
 */
function resolveMailLog() {
  // The log channel is `daily` (laravel-<date>.log) — that is where mail lands.
  // A stale plain `laravel.log` (the E2E_LOG path) may also exist and be EMPTY of
  // mail, so never just prefer the exact path: pick the NEWEST laravel*.log by
  // mtime (the file the channel is actively writing), considering E2E_LOG too.
  const dir = cfg.log ? path.dirname(cfg.log) : null;
  const candidates = new Set();
  if (cfg.log && fs.existsSync(cfg.log)) candidates.add(cfg.log);
  if (dir && fs.existsSync(dir)) {
    for (const f of fs.readdirSync(dir)) {
      if (/^laravel.*\.log$/.test(f)) candidates.add(path.join(dir, f));
    }
  }
  const list = [...candidates];
  if (!list.length) return cfg.log;
  list.sort((a, b) => fs.statSync(b).mtimeMs - fs.statSync(a).mtimeMs);
  return list[0];
}

/** Current byte length of the newest mail log — pass as `{ since }` to scope a scan. */
function mailCheckpoint() {
  try { return fs.statSync(resolveMailLog()).size; } catch (_e) { return 0; }
}

/**
 * The newest URL in the lane's mail log (`MAIL_MAILER=log`) matching `regex`.
 * `since` scopes the scan to bytes written after that offset (see
 * `mailCheckpoint()`). The `=XX` hex un-escape is applied ONLY when the scanned
 * region declares `Content-Transfer-Encoding: quoted-printable` — otherwise the
 * mailer wrote the body 8-bit and a literal `=17` / `=23` in a signed URL's
 * `expires=`/`signature=` params would be wrongly decoded into control chars,
 * 403-ing the link (ISS-B-001). Soft line-breaks are always unfolded (they occur
 * only in QP, harmless otherwise).
 * @param {RegExp|string} regex
 * @param {{since?: number}} [opts]
 * @returns {string} the matched URL
 */
function readMailLink(regex, opts = {}) {
  // The sites under test queue mail (QUEUE_CONNECTION=database + a worker, see sweep_local.sh) — unlike the runner's
  // sync queue the mail can land a moment after the request, longer while the worker drains earlier jobs. Poll for it
  // (E2E_MAIL_WAIT_MS, default 30 s) instead of failing on the first read.
  const waitMs = opts.waitMs ?? parseInt(process.env.E2E_MAIL_WAIT_MS || '30000', 10);
  const deadline = Date.now() + waitMs;
  for (;;) {
    try {
      return readMailLinkOnce(regex, opts);
    } catch (e) {
      if (Date.now() >= deadline || /mail log not found/.test(e.message)) throw e;
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 500); // synchronous 0.5 s pause (callers are sync)
    }
  }
}

function readMailLinkOnce(regex, opts = {}) {
  const logFile = resolveMailLog();
  if (!logFile || !fs.existsSync(logFile)) throw new Error(`readMailLink: mail log not found (E2E_LOG="${cfg.log}")`);
  const fd = fs.openSync(logFile, 'r');
  try {
    const size = fs.fstatSync(fd).size;
    const start = Math.max(0, Number(opts.since) || 0);
    const len = size - start;
    if (len <= 0) throw new Error('readMailLink: nothing written to the mail log since the checkpoint');
    const buf = Buffer.alloc(len);
    fs.readSync(fd, buf, 0, len, start);
    const raw = buf.toString('utf8');
    const isQP = /Content-Transfer-Encoding:\s*quoted-printable/i.test(raw);
    let decoded = raw.replace(/=\r?\n/g, '');                            // soft breaks (QP only, harmless in 8-bit)
    if (isQP) decoded = decoded.replace(/=([0-9A-Fa-f]{2})/g, (_m, h) => String.fromCharCode(parseInt(h, 16)));
    const re = regex instanceof RegExp ? regex : new RegExp(regex);
    const urls = decoded.match(/https?:\/\/[^\s"'<>)\]]+/g) || [];
    const matches = urls.filter((u) => re.test(u));
    if (!matches.length) throw new Error(`readMailLink: no URL matching ${re} in the mail log since the checkpoint`);
    return matches[matches.length - 1];
  } finally {
    fs.closeSync(fd);
  }
}

const AR_DIGITS = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9', '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9' };
function normalizeDigits(s) {
  return String(s).replace(/[٠-٩۰-۹]/g, (d) => AR_DIGITS[d] || d);
}

/**
 * The 4–8 digit OTP from the newest SMS-log row for `phone` (SMS_PROVIDER=log
 * writes the code into `message_preview`). Normalises Arabic-Indic digits.
 * @param {string} phone
 * @returns {string} the code
 */
function readSmsOtp(phone) {
  const rows = db('SELECT message_preview FROM {p}sms_logs WHERE `to` = ? ORDER BY id DESC LIMIT 1', [phone]);
  if (!rows.length) throw new Error(`readSmsOtp: no sms_log row for ${phone}`);
  const text = normalizeDigits(rows[0][0] || '');
  const m = text.match(/(\d{4,8})/);
  if (!m) throw new Error(`readSmsOtp: no numeric code in "${rows[0][0]}"`);
  return m[1];
}

// ── DB probe ─────────────────────────────────────────────────────────────────

/**
 * Run `sql` against the lane DB via the mysql CLI and return rows as arrays of
 * string columns. `{p}` expands to the table prefix (E2E_DB_PREFIX; `qrtest_` when unset); `?` params are
 * substituted with basic escaping (dev-only probe, not user input).
 * @param {string} sql
 * @param {(string|number|null)[]} [params]
 * @returns {string[][]}
 */
function db(sql, params = []) {
  if (!cfg.db) throw new Error('db(): E2E_DB is not set in the environment');
  let q = sql.replace(/\{p\}/g, cfg.dbPrefix);
  if (params.length) {
    let i = 0;
    q = q.replace(/\?/g, () => {
      const v = params[i++];
      if (v === null || v === undefined) return 'NULL';
      if (typeof v === 'number') return String(v);
      return "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
    });
  }
  const out = execFileSync(cfg.mysqlBin, ['-u' + (process.env.E2E_DB_USER || 'root'), '-h' + (process.env.E2E_DB_HOST || '127.0.0.1'), '-P' + (process.env.E2E_DB_PORT || '3306'), '-N', '--batch', '--default-character-set=utf8mb4', cfg.db, '-e', q], {
    encoding: 'utf8', maxBuffer: 64 * 1024 * 1024,
  });
  const trimmed = out.replace(/\n$/, '');
  if (trimmed === '') return [];
  return trimmed.split('\n').map((line) => line.split('\t'));
}

// ── rate limiters ───────────────────────────────────────────────────────────
// The storefront order route is throttled (10/min per store + IP) and the whole pack runs from one IP,
// so journeys that place orders in a burst reset the (file-cache) limiters first — test-env only, no app
// setting changes. Needs `php` on PATH (the runner puts an 8.4 shim there) and the lane's cache on `file`.
// E2E_APP_DIR = the core/ folder of the site under test when it is not this tree (a Herd site, sweep_local.sh).
function resetRateLimits() {
  try {
    require('child_process').execFileSync('php', ['artisan', 'cache:clear'], {
      cwd: siteCoreDir(), stdio: 'ignore', timeout: 60000,
    });
  } catch (_e) { /* best effort: a warm limiter just means the order may 429 */ }
}

// ── misc ─────────────────────────────────────────────────────────────────────

/**
 * Set (or clear, with a falsy `host`) a Host-header override on `page`, so a
 * lane site can be reached under a reseller's `custom_domain` without DNS. Same
 * mechanism as fusion-tenant-isolation.js.
 */
async function withHost(page, host) {
  await page.setExtraHTTPHeaders(host ? { Host: host } : {});
}

/**
 * `{ slug, url }` for a `{template}-demo` storefront in the run locale.
 * @param {string} template e.g. "crave"
 */
function onStore(template) {
  const slug = `${template}-demo`;
  return { slug, url: localeUrl('/' + slug) };
}

/**
 * A fresh, unique dev identity for a signup flow (HANDOFF §3):
 * email `e2e-<lane>-<purpose>-<unix>@local.invalid`, phone `+9665` + 8 digits,
 * password `E2e-pass-<unix>`.
 * @param {string} lane a|b|c|f0
 * @param {string} purpose short slug, e.g. "owner"
 */
function newIdentity(lane, purpose) {
  const unix = Date.now();
  const eight = String(Math.floor(10000000 + Math.random() * 90000000));
  return {
    email: `e2e-${lane}-${purpose}-${unix}@local.invalid`,
    phone: `+9665${eight}`,
    password: `E2e-pass-${unix}`,
  };
}

/**
 * Exercise the page's own Alpine x-show hide cascade the way the flaky b-signup run did: one x-show element hidden
 * twice before the next frame (false -> true -> false). Alpine < 3.14 consumed the hide promise on the first pass and
 * then called `undefined` on the second -> uncaught "TypeError: u is not a function" (alpinejs/alpine#4204). Returns the
 * page errors (and unhandled rejections) raised meanwhile; [] when the page has no Alpine.
 * @param {import('puppeteer').Page} page
 * @returns {Promise<string[]>}
 */
async function alpineHideRaceErrors(page) {
  const errors = [];
  const onPageError = (e) => errors.push(e.message);
  page.on('pageerror', onPageError);
  try {
    await page.evaluate(async () => {
      if (!window.Alpine || typeof window.Alpine.$data !== 'function') return;
      const host = document.createElement('div');
      host.setAttribute('x-data', '{ open: true }');
      host.hidden = true;
      host.innerHTML = '<span x-show="open">e2e</span>';
      document.body.appendChild(host);
      const tick = () => new Promise((r) => setTimeout(r, 0));
      for (let i = 0; i < 20 && !host._x_dataStack; i++) await tick();
      if (!host._x_dataStack) window.Alpine.initTree(host);
      const data = window.Alpine.$data(host);
      data.open = false; await tick();
      data.open = true; await tick();
      data.open = false; await tick();
      await new Promise((r) => setTimeout(r, 300));
      host.remove();
    });
  } finally {
    page.off('pageerror', onPageError);
  }
  return errors;
}

module.exports = {
  alpineHideRaceErrors,
  cfg,
  siteDefaultLocale,
  eeActive,
  requireEe,
  siteCoreDir,
  localeUrl,
  assertRtl,
  assertNoOverflow,
  resetRateLimits,
  collectConsole,
  assertNoConsoleErrors,
  resetBrowserSession,
  webLogin,
  loginOwner,
  loginReseller,
  loginAdmin,
  loginStaff,
  loginCustomer,
  resolveMailLog,
  mailCheckpoint,
  readMailLink,
  readSmsOtp,
  normalizeDigits,
  db,
  withHost,
  onStore,
  newIdentity,
};
