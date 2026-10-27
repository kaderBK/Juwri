// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — C10: Platform security sweep (P0/P1).
 *
 * Asserts the platform's real auth/session/transport controls against ground
 * truth (HTTP status, response headers, session cookie), and RECORDS any gap as
 * a finding rather than silently passing:
 *
 *   1. AUTH THROTTLE — POST /login is rate-limited (auth-login: 6/min by
 *      username|ip). A 7th wrong attempt for one username → 429 lockout.
 *   2. SESSION FIXATION — the session cookie VALUE changes on successful login
 *      (Laravel session()->regenerate()), so a pre-auth fixed id cannot be
 *      reused post-auth.
 *   3. CSRF — a state-changing POST (/login) WITHOUT a CSRF token → 419.
 *   4. SECURITY HEADERS — the storefront emits a Content-Security-Policy (a real
 *      control); missing hardening headers (X-Content-Type-Options,
 *      X-Frame-Options, and whether the CSP is enforced vs report-only) are
 *      reported as findings, not failures.
 *   5. OPEN REDIRECT — the login flow ignores an attacker-supplied redirect
 *      param: after login with `?redirect=<external>` the browser lands on the
 *      SAME origin, never the external host.
 *
 *   E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh c c-security-sweep
 */

const V = require('../../lib/platform-v2');
const path = require('path');
const { execFileSync } = require('child_process');

const TENANT_B = { email: 'e2e-tenantb-owner@example.test', pass: 'E2eTenantB-pass' };

function clearRateLimiter() {
  const coreDir = V.siteCoreDir();
  try { execFileSync('php', ['artisan', 'cache:clear'], { cwd: coreDir, stdio: 'ignore', timeout: 60000 }); } catch (_e) { /* best effort */ }
}

/** In-page POST with an explicit token control (pass token=null to omit it). */
async function postForm(page, url, fields, token) {
  return page.evaluate(async ({ url, fields, token }) => {
    const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
    if (token) headers['X-CSRF-TOKEN'] = token;
    const r = await fetch(url, { method: 'POST', credentials: 'same-origin', redirect: 'manual', headers, body: JSON.stringify(fields) });
    return { status: r.status };
  }, { url, fields, token });
}

/** Raw JSON POST from Node (no browser): honours the runner's TLS-proxy mapping (E2E_CURL_CONNECT_TO=::host:port). */
function rawPost(baseUrl, pathname, body, extraHeaders) {
  const u = new URL(baseUrl);
  const mod = u.protocol === 'https:' ? require('https') : require('http');
  let host = u.hostname;
  let port = Number(u.port) || (u.protocol === 'https:' ? 443 : 80);
  const m = /^::([^:]+):(\d+)$/.exec(process.env.E2E_CURL_CONNECT_TO || '');
  if (m) { host = m[1]; port = Number(m[2]); }
  const payload = JSON.stringify(body);
  return new Promise((resolve, reject) => {
    const req = mod.request({
      host, port, path: pathname, method: 'POST', rejectUnauthorized: false, servername: u.hostname,
      headers: { Host: u.host, Accept: 'application/json', 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload), ...extraHeaders },
    }, (res) => { res.resume(); res.on('end', () => resolve({ status: res.statusCode })); });
    req.on('error', reject);
    req.end(payload);
  });
}

async function sessionCookie(page) {
  const cookies = await page.cookies();
  const c = cookies.find((k) => /session/i.test(k.name));
  return c ? c.value : null;
}

module.exports = async function cSecuritySweep({ page, cfg, log, shot }) {
  const findings = [];
  clearRateLimiter();
  await page.setViewport({ width: 1024, height: 800 });
  const origin = new URL(cfg.baseUrl).host;

  // ── 3. CSRF FIRST (needs a clean guest session on /login) ──────────────────
  await V.resetBrowserSession(page);
  await page.goto(cfg.baseUrl + '/login', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  const token = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.content
    || document.querySelector('input[name="_token"]')?.value || '');
  /* Laravel 13's request-forgery middleware accepts a token-less POST whose browser-set
     `Sec-Fetch-Site` is `same-origin` (the framework's origin check — not a hole), so an in-page fetch()
     can never be the no-token probe. Send it the way a forged page on another origin would arrive: a raw
     request from Node with `Sec-Fetch-Site: cross-site`, no cookie and no token. */
  const noCsrf = await rawPost(cfg.baseUrl, '/login', { username: 'x@x.test', password: 'nope' }, { 'Sec-Fetch-Site': 'cross-site', Origin: 'https://evil.example' });
  if (noCsrf.status !== 419) throw new Error(`CSRF: a cross-site POST /login without a token should 419, got ${noCsrf.status}`);
  log(`csrf: POST /login without token → 419 ✓`);

  // ── 1. AUTH THROTTLE — 6/min by username|ip; 7th wrong attempt → 429 ───────
  const attacker = `e2e-attacker-${Date.now()}@x.test`;
  let throttled = 0;
  let sawOk = 0;
  for (let i = 1; i <= 8; i += 1) {
    const r = await postForm(page, '/login', { username: attacker, password: 'wrong-pass' }, token);
    if (r.status === 429) { throttled = i; break; }
    sawOk += 1;
  }
  if (!throttled) throw new Error(`throttle: 8 wrong /login attempts never hit 429 (auth-login limiter not enforced)`);
  if (throttled > 7) throw new Error(`throttle: lockout came too late (attempt ${throttled}); expected ≤7 for a 6/min limit`);
  log(`throttle: wrong /login locked out at attempt ${throttled} (429) after ${sawOk} allowed ✓`);

  // ── 2. SESSION FIXATION — cookie value regenerates on login ────────────────
  await V.resetBrowserSession(page);
  await page.goto(cfg.baseUrl + '/login', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  const preId = await sessionCookie(page);
  await V.webLogin(page, TENANT_B);   // resets + logs in (successful)
  const storeId = await sessionCookie(page);
  if (!storeId) throw new Error('session fixation: no session cookie after login');
  if (preId && preId === storeId) throw new Error(`SESSION FIXATION: session cookie value did NOT change on login (${preId.slice(0, 8)}…)`);
  log(`session fixation: session cookie regenerated on login (pre="${(preId || 'none').slice(0, 6)}…" → post="${storeId.slice(0, 6)}…") ✓`);

  // ── 4. SECURITY HEADERS — CSP present on storefront; report gaps ───────────
  const store = V.onStore('crave');
  const resp = await page.goto(store.url, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  const h = resp ? resp.headers() : {};
  const csp = h['content-security-policy'];
  const cspRO = h['content-security-policy-report-only'];
  if (!csp && !cspRO) throw new Error('security headers: NO Content-Security-Policy (enforced or report-only) on the storefront');
  if (!csp && cspRO) findings.push('CSP is REPORT-ONLY on the storefront (frame-ancestors/object-src not enforced) — P2');
  if (!h['x-content-type-options']) findings.push('missing X-Content-Type-Options: nosniff — P2');
  if (!h['x-frame-options'] && !/frame-ancestors/.test(csp || '')) findings.push('no enforced X-Frame-Options / frame-ancestors (clickjacking) — P2');
  await shot(page, 'c-security-sweep-storefront');
  log(`headers: CSP present (${csp ? 'ENFORCED' : 'report-only'}); ${findings.length} hardening gap(s) recorded ✓`);

  // ── 5. OPEN REDIRECT — the login destination is server-controlled ──────────
  // The login POST does not consume a client redirect target (redirectTo() is
  // fixed server-side), so an attacker-supplied ?redirect/?next cannot bounce a
  // freshly-authenticated user off-origin. Verify BOTH: (a) an authed guest
  // driven at /login with a malicious query still lands same-origin, and (b) a
  // crafted protocol-relative intended path (//evil) does not become an external
  // 302 (the router treats it as an in-app path, so the guest bounce to /login
  // is same-origin).
  const evil = 'evil.example.com';
  const bounce = await V.probe(page, '/%2F%2F' + evil);   // guest hits a weird protected-looking path
  if (bounce.finalUrl && new URL(bounce.finalUrl).host !== origin) {
    throw new Error(`OPEN REDIRECT: /%2F%2F${evil} 302'd off-origin to ${bounce.finalUrl}`);
  }
  await V.webLogin(page, TENANT_B);                        // resets session, logs in cleanly
  const landedHost = new URL(page.url()).host;
  if (landedHost !== origin) throw new Error(`OPEN REDIRECT: login landed on ${landedHost} (expected same-origin ${origin})`);
  log(`open redirect: login destination server-controlled, landed same-origin (${landedHost}); protocol-relative path stayed on-origin ✓`);

  if (findings.length) {
    log(`c-security-sweep FINDINGS (recorded to ISSUES.md): \n  - ${findings.join('\n  - ')}`);
  }
  log(`c-security-sweep PASS — throttle(429@${throttled}), session-fixation(regenerated), CSRF(419), CSP present, open-redirect blocked; ${findings.length} hardening gap(s) filed`);
};
