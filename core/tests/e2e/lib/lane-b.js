'use strict';

/**
 * Shared helpers for the P1-12 item 9 journeys (licence states, P1-23 legal, OTP scoping, marketplace, Arabic RTL).
 *
 * - Every record a journey creates is prefixed "e2eB-" so three lanes on one local database never collide.
 * - `dbq()` is a READ-ONLY probe through PHP/PDO, so no `mysql` client has to be installed (DB host/user/name from
 *   E2E_DB_HOST / E2E_DB_USER / E2E_DB_PASS / E2E_DB, default 127.0.0.1 / root / '' / juwri_dev2).
 * - `startPhpServer()` runs a second, throwaway PHP built-in server on a local port against the SAME code and database as
 *   the site under test, with different environment variables (a different licence file). It never edits code and is
 *   always stopped by the journey.
 * - Nothing here sends mail or touches the production licence key (see the private dev_licence.php fixture tool).
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const { execFileSync, spawn } = require('child_process');

const REPO = path.resolve(__dirname, '../../../..'); // worktree root (…/juw-b)
const PREFIX = 'e2eB-';

function baseUrl() {
  return (process.env.E2E_BASE_URL || 'https://juw.test').replace(/\/$/, '');
}

/** PHP binary able to run the app (8.4). E2E_PHP wins; then Herd's php84; then `php`. */
function phpBin() {
  if (process.env.E2E_PHP) return process.env.E2E_PHP;
  const herd = path.join(os.homedir(), 'Library/Application Support/Herd/bin/php84');
  return fs.existsSync(herd) ? herd : 'php';
}

/**
 * Root of the checkout whose code juw.test serves (docroot with index.php + core/vendor). E2E_APP_ROOT wins; else this
 * worktree if it has a vendor folder; else the sibling "juw" (main) checkout.
 */
function appRoot() {
  if (process.env.E2E_APP_ROOT) return process.env.E2E_APP_ROOT;
  if (fs.existsSync(path.join(REPO, 'core/vendor/autoload.php'))) return REPO;
  const sibling = path.resolve(REPO, '..', 'juw');
  return sibling;
}

/**
 * Checkout the SITE UNDER TEST runs from (juw.test serves main). Its file cache / rate limiters live in its own storage,
 * so cache:clear for the site must run there, not in this worktree. E2E_SITE_ROOT wins; else the sibling "juw".
 */
function siteRoot() {
  if (process.env.E2E_SITE_ROOT) return process.env.E2E_SITE_ROOT;
  const sibling = path.resolve(REPO, '..', 'juw');
  return fs.existsSync(path.join(sibling, 'core/vendor/autoload.php')) ? sibling : appRoot();
}

/** Clear the site's cache (rate limiters, OTP codes) — test-env only, between journey legs. */
function clearSiteCache() {
  execFileSync(phpBin(), [path.join(siteRoot(), 'core/artisan'), 'cache:clear'], {
    cwd: path.join(siteRoot(), 'core'), stdio: 'ignore', timeout: 120000,
  });
}

/** True when the app root still has the Enterprise package (a Community mirror does not). */
function eePresentOnDisk() {
  return fs.existsSync(path.join(appRoot(), 'core', 'ee', 'juwri-edition.php'));
}

/**
 * Read-only SQL probe. Returns rows as arrays of strings (NULL → null). `?` placeholders are bound by PDO.
 * @param {string} sql
 * @param {Array<string|number|null>} [params]
 */
function dbq(sql, params = []) {
  if (!/^\s*(select|show|describe|explain)\b/i.test(sql)) throw new Error('dbq() is read-only');
  const code = `
    $in = json_decode(stream_get_contents(STDIN), true);
    // runner_e2e.sh (sqlite runner): E2E_DB=sqlite + SHIM_SQLITE_DB = the file the app under test uses.
    $pdo = $in['sqlite'] !== '' ? new PDO('sqlite:'.$in['sqlite'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) : new PDO('mysql:host='.$in['host'].';port='.($in['port'] ?? '3306').';dbname='.$in['db'].';charset=utf8mb4', $in['user'], $in['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $st = $pdo->prepare($in['sql']); $st->execute($in['params']);
    echo json_encode($st->fetchAll(PDO::FETCH_NUM));
  `;
  const out = execFileSync(phpBin(), ['-r', code], {
    input: JSON.stringify({
      host: process.env.E2E_DB_HOST || '127.0.0.1',
      port: process.env.E2E_DB_PORT || '3306',
      db: process.env.E2E_DB || 'juwri_dev2',
      sqlite: process.env.E2E_DB === 'sqlite' ? (process.env.SHIM_SQLITE_DB || '') : '',
      user: process.env.E2E_DB_USER || 'root',
      pass: process.env.E2E_DB_PASS || '',
      sql,
      params,
    }),
    encoding: 'utf8',
    maxBuffer: 32 * 1024 * 1024,
  });
  return JSON.parse(out);
}

/**
 * Run an artisan command of the app under test (seeding fixtures only). Output is returned; a failure throws.
 * Only seeders/factories are expected here — never a dump import.
 */
function artisan(args, env = {}) {
  return execFileSync(phpBin(), [path.join(appRoot(), 'core/artisan'), ...args], {
    cwd: path.join(appRoot(), 'core'),
    env: { ...process.env, ...env },
    encoding: 'utf8',
    timeout: 180000,
    maxBuffer: 32 * 1024 * 1024,
  });
}

function httpGet(url) {
  return new Promise((resolve) => {
    const req = http.get(url, (res) => { res.resume(); resolve(res.statusCode); });
    req.on('error', () => resolve(0));
    req.setTimeout(2000, () => { req.destroy(); resolve(0); });
  });
}

/**
 * Start a PHP built-in server on 127.0.0.1:<port> serving the app root with extra environment (e.g. JUWRI_LICENCE_PATH).
 * Resolves `{ url, stop() }` once it answers; stop() kills only this child.
 */
async function startPhpServer(port, env = {}) {
  const root = appRoot();
  // the runner's licensed Enterprise tree (runner_e2e.sh): the throw-away licence's seam router binds the test verifier; without it
  // a throwaway server would run the same code as Community (reseller hosts, wallet top-ups off)
  const seamRouter = path.join(root, 'core', 'ee', 'tests', 'Support', 'e2e', 'licensed-router.php');
  const router = process.env.E2E_LICENCE_KEY && fs.existsSync(seamRouter) ? seamRouter : path.join(root, 'router.php');
  const child = spawn(phpBin(), ['-S', `127.0.0.1:${port}`, '-t', root, router], {
    cwd: root,
    env: { ...process.env, ...env, PHP_CLI_SERVER_WORKERS: '4' },
    stdio: 'ignore',
    detached: true, // own process group: the built-in server forks workers, stop() must take the whole group
  });
  let exited = false;
  child.on('exit', () => { exited = true; });
  const url = `http://127.0.0.1:${port}`;
  for (let i = 0; i < 50; i++) {
    if (exited) throw new Error(`php -S on ${port} exited early (is the port busy?)`);
    if ((await httpGet(`${url}/login`)) > 0) break;
    await new Promise((r) => setTimeout(r, 200));
  }
  return { url, stop: () => { try { process.kill(-child.pid, 'SIGTERM'); } catch (_e) { try { child.kill('SIGTERM'); } catch (_e2) { /* gone */ } } } };
}

/** A fresh isolated browser context + page (own cookies). Close via the returned `close()`. */
async function freshPage(browser, newPage, viewport) {
  const context = (await browser.createBrowserContext?.()) || browser;
  const page = await newPage(context);
  if (viewport) await page.setViewport(viewport);
  return { page, close: async () => { await page.close().catch(() => {}); if (context !== browser) await context.close().catch(() => {}); } };
}

/** Web login (owner / admin / reseller share /login) against any base URL. Returns the landed path. */
async function login(page, base, { email, pass }, loginPath = '/login') {
  const cookies = await page.cookies().catch(() => []);
  if (cookies.length) await page.deleteCookie(...cookies);
  await page.goto(base + loginPath, { waitUntil: 'domcontentloaded' });
  const userSel = '#username, input[name="username"], input[name="email"]';
  await page.waitForSelector(userSel);
  await page.type(userSel, email);
  await page.type('#password, input[name="password"]', pass);
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
  ]);
  const landed = new URL(page.url()).pathname;
  if (landed === loginPath) {
    const err = await page.evaluate(() => (document.querySelector('.alert, .invalid-feedback, [role="alert"]')?.textContent || '').replace(/\s+/g, ' ').trim());
    throw new Error(`login rejected for ${email} (still on ${landed}${err ? ': ' + err : ''})`);
  }
  return landed;
}

/** HTTP status of a same-origin GET from inside the page session (follows redirects, reports the final URL). */
async function status(page, url) {
  return page.evaluate(async (u) => {
    const r = await fetch(u, { credentials: 'same-origin', redirect: 'follow', headers: { Accept: 'text/html' } });
    return { status: r.status, url: r.url };
  }, url);
}

/** Collects assertion failures so a journey reports everything wrong at once, then throws. */
function checker(name) {
  const fails = [];
  const passes = [];
  return {
    ok(cond, msg) { (cond ? passes : fails).push(msg); if (!cond) console.log(`  FAIL ${name}: ${msg}`); else if (process.env.E2E_VERBOSE) console.log(`  ok   ${msg}`); return !!cond; },
    eq(actual, expected, msg) { return this.ok(actual === expected, `${msg} (expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)})`); },
    passes,
    fails,
    done(log) {
      for (const p of passes) log(`  ok  ${p}`);
      if (fails.length) throw new Error(`${name}: ${fails.length} failed:\n  - ${fails.join('\n  - ')}`);
    },
  };
}

/** Log a skip. A journey that RETURNS this (`return L.skip(...)`) is counted by run.js as skipped, never as a pass;
 *  a skipped leg inside a journey just logs it. */
function skip(log, journey, reason) {
  log(`SKIP ${journey}: ${reason}`);
  return { skip: reason };
}


/**
 * Visible English left on a page that should be fully translated. Walks the rendered text nodes (and placeholder /
 * aria-label / title / alt attributes) and returns the distinct runs of 3+ Latin letters, minus `allow` (case-insensitive
 * words or strings that are DATA, not interface: store / item / customer names created in English, the brand, units,
 * currency codes ...). Skips script / style / code, anything inside `lang="en"`, `[data-allow-latin]` or `.ui-ltr`
 * (numbers, e-mails, URLs the app deliberately isolates), and hidden nodes.
 *
 * @param {import('puppeteer').Page} page
 * @param {string[]} [allow]
 * @returns {Promise<string[]>} e.g. ["Add to cart", "Skip to content"]
 */
const escapeRe = (str) => str.replace(/[.*+?^$()|[\]\\{}]/g, '\\$&');

async function englishLeftovers(page, allow = []) {
  const found = await page.evaluate(() => {
    const out = new Set();
    const skipTag = /^(SCRIPT|STYLE|NOSCRIPT|CODE|PRE|TEXTAREA|SVG|TITLE)$/;
    const hidden = (el) => {
      for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
        if (e.hidden || e.getAttribute('aria-hidden') === 'true' && !e.textContent.trim()) return true;
        const cs = getComputedStyle(e);
        if (cs.display === 'none' || cs.visibility === 'hidden') return true;
      }
      return false;
    };
    const skipped = (el) => !!el.closest('[lang="en"], [data-allow-latin], [data-licensed-to], .ui-ltr, [dir="ltr"]');
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      const el = n.parentElement;
      if (!el || skipTag.test(el.tagName) || skipped(el) || hidden(el)) continue;
      const t = n.nodeValue.replace(/\s+/g, ' ').trim();
      if (/[A-Za-z]{3,}/.test(t)) out.add(t);
    }
    for (const el of document.body.querySelectorAll('[placeholder],[aria-label],[title],img[alt]')) {
      if (skipped(el) || hidden(el)) continue;
      for (const a of ['placeholder', 'aria-label', 'title', 'alt']) {
        const v = (el.getAttribute(a) || '').replace(/\s+/g, ' ').trim();
        if (/[A-Za-z]{3,}/.test(v)) out.add(`[${a}] ${v}`);
      }
    }
    return [...out];
  });
  // longest first: "Havenwell Pharmacy" must go before the shorter data word "Pharmacy" can leave "Havenwell" behind
  const allowed = [...new Set(allow.map((a) => String(a).toLowerCase()).filter(Boolean))].sort((x, y) => y.length - x.length);
  return found.filter((t) => {
    // strip every allowed string, then see whether a 3-letter Latin run remains
    let rest = t.toLowerCase().replace(/^\[(placeholder|aria-label|title|alt)\] /, '');
    for (const a of allowed) {
      if (!rest.includes(a)) continue;
      // whole-word match only, so a data string like "Plate" cannot hide the interface word inside "template"
      rest = rest.replace(new RegExp('(?<![a-z])' + escapeRe(a) + '(?![a-z])', 'g'), ' ');
    }
    if (!/[a-z]{3,}/.test(rest)) return false;
    // A technical token inside an Arabic sentence (SMTP, SEO, /pricing, HTTPS) is not a leftover: flag a text that has no
    // Arabic at all, or that is mostly Latin once the data strings are removed.
    const latin = (rest.match(/[a-z]/g) || []).length;
    const arabic = (rest.match(/[\u0600-\u06FF]/g) || []).length;
    return arabic === 0 || latin / (latin + arabic) > 0.6;
  });
}

/** Brand and unit words that are never translated. */
const FIXED_ALLOW = ['juwri', 'whatsapp', 'google', 'apple', 'facebook', 'instagram', 'tiktok', 'snapchat', 'kcal', 'csv', 'pdf', 'qr', 'api', 'sms', 'otp', 'zomato'];  // zomato: a delivery-aggregator brand an order source can name

/**
 * English that is DATA in the demo database (store / item / category names, descriptions, reviews, customers, plans,
 * wallet ledger reasons, language names ...), read straight from it, so the leftover scan reports interface strings only.
 * Values shorter than 3 characters and JSON columns are skipped.
 */
function dataAllowlist(extraTables = []) {
  const tables = ['stores', 'items', 'categories', 'branches', 'customers', 'users', 'plans', 'store_reviews', 'menu_reviews', 'combos',
    'modifier_groups', 'modifiers', 'store_tables', 'coupons', 'stories', 'wallet_transactions', 'languages', 'menu_variants', 'apps', ...extraTables];
  const out = new Set(FIXED_ALLOW);
  for (const t of tables) {
    let cols;
    try { cols = L_dbq(`show columns from \`${t}\``).filter((c) => /char|text/i.test(c[1])).map((c) => c[0]); } catch (_e) { continue; }
    if (!cols.length) continue;
    for (const c of cols) {
      if (/password|token|secret|remember|translations/i.test(c)) continue;
      try {
        for (const r of L_dbq(`select distinct \`${c}\` from \`${t}\` where \`${c}\` is not null and char_length(\`${c}\`) between 3 and 400 limit 4000`)) out.add(String(r[0]));
      } catch (_e) { /* column not selectable */ }
    }
  }
  return [...out];
}
const L_dbq = (...a) => dbq(...a);

/** Horizontal overflow in px of the current page (0 when it fits the viewport). */
async function overflowPx(page) {
  return page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body ? document.body.scrollWidth : 0) - window.innerWidth);
}

module.exports = {
  englishLeftovers, overflowPx, dataAllowlist,
  REPO, PREFIX, baseUrl, phpBin, appRoot, siteRoot, clearSiteCache, eePresentOnDisk, dbq, artisan, startPhpServer, freshPage, login, status, checker, skip,
};
