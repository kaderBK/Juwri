'use strict';

/**
 * Platform E2E v2 — shared helpers for the coverage-expansion pack.
 *
 * Layers on top of `lib/platform.js` (re-exported verbatim as `P.*`, and also
 * spread onto this module so a journey can `const V = require('../../lib/platform-v2')`
 * and reach BOTH the v1 helpers and the v2 additions from one import). The v2
 * additions are:
 *
 *   - English / LTR (V0.3): assertLtr(page), enUrl(path), localeShell(page)
 *   - Fake stubs (V0.2): stub gateway name + guard docs, readSmsOtp (re-export),
 *     aiStubProbe() — all test-env only, armed by E2E_STUBS on the PHP side.
 *   - Security (V0.4): idorProbe(), rbacMatrix(), raceN()
 *
 * No new npm dependency: only the puppeteer `page` and the v1 lib. Every knob is
 * env-driven so the same helpers run against a Herd HTTPS lane site or CI.
 *
 * ── Stub guards (single source of truth; the PHP side enforces them) ──────────
 *   All fake gateways/AI live behind `App\Providers\E2eStubServiceProvider`,
 *   whose register()/boot() early-returns UNLESS
 *       app()->environment(['local','testing'])  AND  env('E2E_STUBS') is truthy.
 *   In production (APP_ENV=production) the guard is false, so NOTHING binds and
 *   the stub classes are inert — they can never charge, message, or call an AI
 *   provider. The v2 bin (rev/platform-e2e/bin/e2e.sh) exports E2E_STUBS=1 for
 *   v2 runs only. Individual stubs:
 *     • Payment (A4): PaymentMethods/StubController — settles via the real
 *       paySuccess() on `e2e_outcome=success`, fails (unsettled) otherwise. Its
 *       pay() abort(404)s unless the guard holds AND a seeded `stub` gateway row
 *       exists (E2E DBs only). Partial-refund = drive a stub-success order, then
 *       exercise the app's real refund path and assert the ledger.
 *     • AI (C7): AiService bound to E2eStubAiService (deterministic text/JSON).
 *     • Reseller billing (C6): ResellerGatewayDriver bound to FakeResellerDriver
 *       (existing prod test seam; success/fail via E2E_STUB_GATEWAY_FAIL).
 *     • SMS/OTP (C8): SMS_PROVIDER=log writes the code to sms_log; read it back
 *       with readSmsOtp(phone). Config, not code — no stub class needed.
 */

const P = require('./platform');
const cfg = P.cfg;

// ── English / LTR (V0.3) ─────────────────────────────────────────────────────

/**
 * Absolute URL for `path` in English. Since the site default is `ar`, English is
 * always the `/en`-prefixed form.
 * @param {string} path e.g. "/crave-demo"
 * @returns {string} absolute /en URL
 */
function enUrl(path) {
  return P.localeUrl(path, 'en');
}

/** `{ lang, dir, url }` of the landed document shell. */
async function localeShell(page) {
  return page.evaluate(() => ({
    lang: (document.documentElement.lang || '').toLowerCase(),
    dir: (document.documentElement.dir || '').toLowerCase(),
    url: location.href,
  }));
}

/**
 * Assert the landed page is left-to-right English: `<html>` must NOT be
 * dir="rtl" (ltr or an unset/auto dir are both acceptable LTR) and, when a lang
 * is present, its primary subtag must be `en`. The mirror of P.assertRtl.
 * @param {import('puppeteer').Page} page
 * @returns {Promise<{lang:string,dir:string,url:string}>} the observed shell
 */
async function assertLtr(page) {
  const shell = await localeShell(page);
  if (shell.dir === 'rtl') {
    throw new Error(`assertLtr: expected LTR, got dir="rtl" (lang="${shell.lang}", ${shell.url})`);
  }
  if (shell.lang && shell.lang.split('-')[0] !== 'en') {
    throw new Error(`assertLtr: expected lang="en", got lang="${shell.lang}" (${shell.url})`);
  }
  return shell;
}

// ── Fake stubs (V0.2) ────────────────────────────────────────────────────────

/** The seeded test-only payment gateway's payment_folder / payment_method slug. */
const STUB_GATEWAY = 'stub';

/**
 * True iff the test-only stub gateway row is installed for `storeId`
 * (payment_install='1'), i.e. the storefront will offer the fake gateway. Uses
 * the mysql probe; a lane can gate an A4 journey on this instead of guessing.
 * @param {number} storeId
 */
function stubGatewayInstalled(storeId) {
  const rows = P.db(
    "SELECT COUNT(*) FROM {p}payment_gateways WHERE payment_folder = ? AND payment_install = '1'",
    [STUB_GATEWAY],
  );
  // The gateway row is global (install toggles per store live elsewhere); a
  // present, installed `stub` row means the fake method is dispatchable.
  return Number(rows[0][0]) > 0;
}

/**
 * Probe that the AI stub is armed WITHOUT spending an AI call: reads the run env
 * the harness exported. The real assertion (deterministic output) is done by the
 * C7 journey through an app write-path; this is just a pre-flight so a journey
 * fails fast with a clear message if E2E_STUBS was not set.
 */
function stubsArmed() {
  return String(process.env.E2E_STUBS || '') === '1';
}

// ── Security helpers (V0.4) ──────────────────────────────────────────────────

/**
 * In-page fetch of `url` reusing the page's current session cookies. Follows
 * redirects so an auth-bounce to /login is observable as `redirectedToAuth`.
 * Returns { status, finalUrl, redirected, redirectedToAuth, bodySample }.
 * @param {import('puppeteer').Page} page
 * @param {string} url absolute or root-relative
 * @param {{method?:string, headers?:object}} [opts]
 */
async function probe(page, url, opts = {}) {
  const abs = /^https?:\/\//.test(url) ? url : cfg.baseUrl + (url.startsWith('/') ? url : '/' + url);
  return page.evaluate(async ({ abs, method, headers }) => {
    try {
      const r = await fetch(abs, {
        method: method || 'GET',
        credentials: 'same-origin',
        redirect: 'follow',
        headers: Object.assign({ Accept: 'text/html,application/json' }, headers || {}),
      });
      const finalUrl = r.url || abs;
      const body = await r.text().catch(() => '');
      return {
        status: r.status,
        finalUrl,
        redirected: !!r.redirected,
        redirectedToAuth: /\/(login|staff\/login)(\?|#|$)/.test(finalUrl),
        bodySample: body.slice(0, 200),
      };
    } catch (e) {
      return { status: 0, finalUrl: abs, redirected: false, redirectedToAuth: false, error: String(e && e.message || e) };
    }
  }, { abs, method: opts.method, headers: opts.headers });
}

/**
 * IDOR probe: log in as tenant/user B (via P.webLogin with `asUser`), then
 * request `targetUrl` — a resource that belongs to a DIFFERENT tenant/user — and
 * assert the response is one of `expect` (default [403,404]), OR an auth bounce
 * (treated as denied). Throws with the actual status/body if the resource LEAKS
 * (a 200 that is not the login page).
 *
 * @param {import('puppeteer').Page} page
 * @param {object} o
 * @param {{email:string,pass:string,loginPath?:string}} o.asUser  the ATTACKER
 * @param {string} o.targetUrl  the victim's resource (root-relative or absolute)
 * @param {number[]} [o.expect=[403,404]]  acceptable denial statuses
 * @param {string} [o.method='GET']
 * @returns {Promise<{ok:boolean, status:number, finalUrl:string, denied:boolean}>}
 */
async function idorProbe(page, { asUser, targetUrl, expect = [403, 404], method = 'GET' }) {
  if (asUser) await P.webLogin(page, asUser);
  const r = await probe(page, targetUrl, { method });
  const denied = expect.includes(r.status) || (r.redirectedToAuth && r.status === 200);
  if (!denied) {
    throw new Error(
      `idorProbe LEAK: ${method} ${targetUrl} as ${asUser ? asUser.email : 'guest'} → ${r.status} `
      + `(final ${r.finalUrl}); expected one of [${expect.join(',')}] or an auth bounce.`
      + (r.bodySample ? `\n  body: ${r.bodySample.replace(/\s+/g, ' ')}` : ''),
    );
  }
  return { ok: true, status: r.status, finalUrl: r.finalUrl, denied: true };
}

/**
 * RBAC matrix: log in as `login` (a role's credentials) and request each of
 * `routes`, returning the per-route result so a journey can assert a role's
 * allow/deny expectations. Does NOT throw on any single status — the caller
 * decides which routes must be allowed (2xx) and which denied (403/404/auth).
 *
 * @param {import('puppeteer').Page} page
 * @param {object} o
 * @param {{email:string,pass:string,loginPath?:string}} [o.login]  omit to probe as guest
 * @param {(string|{url:string,method?:string,label?:string})[]} o.routes
 * @returns {Promise<{login:string, results:Array<{label:string,url:string,method:string,status:number,allowed:boolean,denied:boolean,redirectedToAuth:boolean}>}>}
 */
async function rbacMatrix(page, { login, routes }) {
  if (login) await P.webLogin(page, login);
  const results = [];
  for (const raw of routes) {
    const spec = typeof raw === 'string' ? { url: raw } : raw;
    const method = spec.method || 'GET';
    const r = await probe(page, spec.url, { method });
    const allowed = r.status >= 200 && r.status < 300 && !r.redirectedToAuth;
    const denied = [401, 403, 404].includes(r.status) || r.redirectedToAuth;
    results.push({
      label: spec.label || spec.url,
      url: spec.url,
      method,
      status: r.status,
      allowed,
      denied,
      redirectedToAuth: r.redirectedToAuth,
    });
  }
  return { login: login ? login.email : 'guest', results };
}

/**
 * Fire `n` identical operations in PARALLEL and return every result, for
 * double-submit / coupon-claim / stock-decrement race assertions. `fn(i)` is an
 * async function (typically an in-page fetch) returning any value; failures are
 * captured as `{ error }` rather than rejecting the whole batch.
 *
 * @template T
 * @param {(i:number)=>Promise<T>} fn
 * @param {number} n
 * @returns {Promise<Array<T|{error:string}>>}
 */
async function raceN(fn, n) {
  const jobs = Array.from({ length: n }, (_, i) => Promise.resolve()
    .then(() => fn(i))
    .catch((e) => ({ error: String(e && e.message || e) })));
  return Promise.all(jobs);
}

/**
 * Convenience: POST the same JSON body to `endpoint` `n` times in parallel from
 * the page context (reusing session + CSRF), returning the HTTP statuses. Handy
 * for double-submit dedup — one 2xx/created, the rest deduped/rejected.
 * @param {import('puppeteer').Page} page
 * @param {string} endpoint root-relative
 * @param {object} body
 * @param {number} n
 */
async function racePost(page, endpoint, body, n) {
  return raceN((i) => page.evaluate(async ({ endpoint, body }) => {
    const csrf = window.CRAVE?.csrf || document.querySelector('meta[name="csrf-token"]')?.content;
    const r = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
      },
      body: JSON.stringify(body),
    });
    return { status: r.status };
  }, { endpoint, body }), n);
}

// ── crave-demo fixture ids ──────────────────────────────────────────────────
// The v2 journeys used to pin the ids of one legacy dev database (store 3440, owner 10145,
// items 64944 / 64945). Ids differ per database, so resolve by slug / item name — lazily (at
// journey run time, never at require time: `run.js --list` has no database).
const CRAVE_SLUG = 'crave-demo';
let craveMemo = null;
function crave() {
  if (!craveMemo) {
    const rows = P.db('SELECT id, user_id FROM {p}stores WHERE slug = ? LIMIT 1', [CRAVE_SLUG]);
    if (!rows.length) throw new Error(`fixture store "${CRAVE_SLUG}" not found — seed E2ePlatformSeeder first`);
    craveMemo = { storeId: Number(rows[0][0]), ownerId: Number(rows[0][1]) };
  }
  return craveMemo;
}
const craveStoreId = () => crave().storeId;
const craveOwnerId = () => crave().ownerId;
/** Id of the crave-demo item whose name contains `name` (the fixture names are English). */
function craveItemId(name) {
  const rows = P.db("SELECT id FROM {p}items WHERE store_id = ? AND name LIKE ? AND deleted_at IS NULL ORDER BY id LIMIT 1", [craveStoreId(), `%${name}%`]);
  if (!rows.length) throw new Error(`fixture item "${name}" not found on ${CRAVE_SLUG}`);
  return Number(rows[0][0]);
}

/**
 * J27-A2: a client order key — RFC 9562 UUIDv7 (48-bit ms time + random), the shape the
 * storefront / POS mint. Order endpoints refuse a key that is not a UUID (422). Keys made
 * with the same `ms` share their first 13 characters, so a run can be scoped with
 * `client_uuid LIKE uuid7Prefix(ms) || '%'`.
 */
function uuid7(ms = Date.now()) {
  const b = require('crypto').randomBytes(16);
  let t = ms;
  for (let i = 5; i >= 0; i--) { b[i] = t % 256; t = Math.floor(t / 256); }
  b[6] = (b[6] & 0x0f) | 0x70;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = b.toString('hex');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}
function uuid7Prefix(ms) { return uuid7(ms).slice(0, 13); }

module.exports = Object.assign({}, P, {
  uuid7,
  uuid7Prefix,
  CRAVE_SLUG,
  craveStoreId,
  craveOwnerId,
  craveItemId,
  // v1 handle for callers that want the namespace explicitly
  P,
  cfg,
  // V0.3 English/LTR
  enUrl,
  localeShell,
  assertLtr,
  // V0.2 stubs
  STUB_GATEWAY,
  stubGatewayInstalled,
  stubsArmed,
  // V0.4 security
  probe,
  idorProbe,
  rbacMatrix,
  raceN,
  racePost,
});
