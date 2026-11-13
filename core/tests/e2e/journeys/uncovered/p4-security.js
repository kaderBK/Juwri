// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * p4-security — P4-06 account security end to end, in a real (headless) browser.
 *
 *  2FA       owner enables two-factor on Settings > Security (password re-confirm → secret → code), signs out, signs
 *            in: password → challenge; a wrong code is refused; the right code signs in; the SAME code again is refused
 *            (one use per code); a recovery code signs in once and is refused the second time
 *  passkeys  Chrome's virtual authenticator (CDP WebAuthn): the owner adds a passkey on the security page, signs out,
 *            "Sign in with a passkey" signs straight in (a passkey counts as two-step)
 *  reset     "Forgot password" → the reset mail (MAIL_MAILER=log on the instance, read from its laravel.log) → new
 *            password → the 2FA challenge still applies → signed in; the old password and the used link are refused
 *  staff PIN wrong PINs on the staff app's PIN sign-in (API v1) lock that STORE's PIN sign-in after 5 failures (the
 *            route throttle allows 10 a minute per client, so the journey paces them); then the right PIN is refused
 *            too; another store's PIN sign-in is not locked
 *  IDOR      a second account cannot rename or remove the first one's passkey, nor read its recovery codes
 *
 * Fixtures (tinker on the app under test, database E2E_DB): two owner accounts e2eC-sec@ / e2eC-sec2@demo.test
 * (copies of the kit demo owner, no stores; password, 2FA and passkeys reset each run) and one PIN staff member at
 * each of two demo stores; the PIN failure counters and caches are cleared first.
 *
 * The instance: started here with `php -S` on localhost:E2E_P4_PORT (default 8615 — passkeys need a host name, not an
 * IP) from E2E_APP_ROOT (default this worktree), APP_URL = that origin (the passkey relying party), MAIL_MAILER=log.
 *
 * Run: E2E_DB=juwri_c npm run test:e2e -- p4-security
 */

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const B = require('../../lib/lane-b');
const C = require('../../lib/lane-c');

const PASS = 'e2eC-Pass-1!';
const NEW_PASS = 'e2eC-Pass-2!';
const U1 = { email: 'e2eC-sec@demo.test', username: 'e2ecsec' };
const U2 = { email: 'e2eC-sec2@demo.test', username: 'e2ecsec2' };
const PIN_A = '482613';
const PIN_B = '482614';

const dbEnv = () => (process.env.E2E_DB ? { DB_DATABASE: process.env.E2E_DB } : {});

function fixtures() {
  const php = `
    $kit = \\Juwri\\Core\\Models\\User::where('email', 'kit-demo-owner@demo.test')->firstOrFail();
    $ids = [];
    foreach ([['${U1.email}', '${U1.username}'], ['${U2.email}', '${U2.username}']] as [$em, $un]) {
      $u = \\Juwri\\Core\\Models\\User::where('email', $em)->first();
      if (! $u) { $u = $kit->replicate(['remember_token']); $u->email = $em; $u->username = $un; $u->name = 'e2eC Security'; }
      $u->password = \\Illuminate\\Support\\Facades\\Hash::make('${PASS}');
      $u->two_factor_secret = null; $u->two_factor_recovery_codes = null; $u->two_factor_confirmed_at = null;
      $u->status = 1; $u->email_verified_at = $u->email_verified_at ?? now();
      $u->save();
      \\DB::table('passkeys')->where('user_id', $u->id)->delete();
      $ids[] = $u->id;
    }
    $a = \\Juwri\\Core\\Models\\Store::where('slug', 'bloom-coffee-demo')->firstOrFail();
    $b = \\Juwri\\Core\\Models\\Store::where('slug', 'themepack-demo')->firstOrFail();
    foreach ([[$a, '${PIN_A}'], [$b, '${PIN_B}']] as [$s, $pin]) {
      $st = \\App\\Models\\StaffMember::where('store_id', $s->id)->where('email', 'e2ec-pin-'.$s->id.'@demo.test')->first()
        ?? new \\App\\Models\\StaffMember(['owner_id' => $s->user_id, 'store_id' => $s->id, 'name' => 'e2eC PIN', 'email' => 'e2ec-pin-'.$s->id.'@demo.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1]);
      $st->forceFill(['pin_hash' => \\Illuminate\\Support\\Facades\\Hash::make($pin), 'pin' => null, 'active' => 1])->save();
    }
    \\Illuminate\\Support\\Facades\\Artisan::call('cache:clear');
    echo 'OUT='.json_encode(['u1' => $ids[0], 'u2' => $ids[1], 'a' => $a->id, 'b' => $b->id]);
  `;
  const out = B.artisan(['tinker', '--execute', php], dbEnv());
  const m = out.match(/OUT=(\{.*\})/);
  if (!m) throw new Error(`p4-security fixtures failed: ${out.slice(-800)}`);
  return JSON.parse(m[1]);
}

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s) for a base32 secret, at time step `step` (default: now). */
function totp(secret, step = Math.floor(Date.now() / 30000)) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const ch of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(ch).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
  const msg = Buffer.alloc(8);
  msg.writeBigUInt64BE(BigInt(step));
  const h = crypto.createHmac('sha1', key).update(msg).digest();
  const o = h[h.length - 1] & 0xf;
  return String((h.readUInt32BE(o) & 0x7fffffff) % 1000000).padStart(6, '0');
}

async function waitNextStep(log) {
  const ms = 30000 - (Date.now() % 30000) + 1500;
  log(`  p4-security: waiting ${Math.round(ms / 1000)} s for the next TOTP step`);
  await new Promise((r) => setTimeout(r, ms));
}

async function fillSubmit(page, formSel, values) {
  const has = await page.$(formSel);
  if (!has) {
    const body = await page.evaluate(() => document.body ? document.body.innerText.slice(0, 200) : '');
    throw new Error(`no form ${formSel} on ${page.url()}: ${body.replace(/\s+/g, ' ')}`);
  }
  await page.evaluate(({ formSel, values }) => {
    const form = document.querySelector(formSel);
    for (const [k, v] of Object.entries(values)) { form.querySelector(`[name="${k}"]`).value = v; }
  }, { formSel, values });
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    page.evaluate((s) => document.querySelector(s).submit(), formSel),
  ]);
}

async function logout(page, base) {
  // the real sign-out (POST /logout with the page's CSRF token), then a clean cookie jar
  await page.evaluate(async () => {
    const csrf = (document.querySelector('meta[name=csrf-token]') || {}).content;
    if (csrf) await fetch('/logout', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'text/html' } }).catch(() => {});
  }).catch(() => {});
  const cookies = await page.cookies(base);
  if (cookies.length) await page.deleteCookie(...cookies);
  await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
  if (!/\/login/.test(page.url())) throw new Error(`still signed in after sign-out (${page.url()})`);
}

async function passwordLogin(page, base, user, pass) {
  await logout(page, base);
  await fillSubmit(page, 'form:has(input[name="password"])', { username: user.email, password: pass });
  return new URL(page.url()).pathname;
}

/** On the password re-confirm page, confirm and come back. */
async function reconfirm(page, pass) {
  if (!/password\/confirm/.test(page.url())) return;
  await fillSubmit(page, 'form[action*="password/confirm"]', { password: pass });
}

async function call(page, method, url, body) {
  return page.evaluate(async ({ method, url, body }) => {
    const csrf = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
    const r = await fetch(url, { method, credentials: 'same-origin', redirect: 'manual',
      headers: Object.assign({ Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body ? { 'Content-Type': 'application/json' } : {}),
      body: body ? JSON.stringify(body) : undefined });
    return { status: r.status, text: (await r.text()).slice(0, 400) };
  }, { method, url, body });
}

function resetLinkFromLog(logFiles, since) {
  // the daily or the single log channel, whichever the instance writes (the runner uses `single`; a daily file may
  // still exist from another process): every candidate is read from its own offset
  const text = logFiles.filter((f) => fs.existsSync(f)).map((f) => fs.readFileSync(f, 'utf8').slice(since[f] || 0)).join('\n');
  const links = [...text.matchAll(/https?:\/\/[^\s"'<>]+\/password\/reset\/[A-Za-z0-9]+[^\s"'<>]*/g)].map((m) => m[0].replace(/&amp;/g, '&').replace(/=3D/g, '='));
  return links.length ? links[links.length - 1] : null;
}

module.exports = async function p4Security({ browser, log, shot }) {
  const R = C.recorder('p4-security', log);
  const port = parseInt(process.env.E2E_P4_PORT || '8615', 10);
  const appUrl = `http://localhost:${port}`;
  const server = await C.startServer(B.appRoot(), port, { ...dbEnv(), QUEUE_CONNECTION: 'sync', APP_URL: appUrl, APP_ENV: 'local', MAIL_MAILER: 'log', SESSION_DOMAIN: '' });
  const base = appUrl;
  // the daily log channel (laravel-YYYY-MM-DD.log, the server's local date)
  const d = new Date();
  const day = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const logDir = path.join(process.env.E2E_APP_ROOT || B.appRoot(), 'core/storage/logs');
  const logFiles = [path.join(logDir, `laravel-${day}.log`), path.join(logDir, 'laravel.log')];
  log(`  p4-security: ${base} (${B.eePresentOnDisk() ? 'Enterprise' : 'Community'} build, DB ${process.env.E2E_DB || '(core/.env)'})`);

  const one = await C.actorPage(browser);
  const two = await C.actorPage(browser);
  const page = one.page;
  try {
    const ids = fixtures();

    // ── 2FA: enrol ────────────────────────────────────────────────────────────────────────────────────────────────
    const landed = await passwordLogin(page, base, U1, PASS);
    if (/login/.test(landed)) R.fail(`password sign-in failed (${landed})`);
    await page.goto(`${base}/two-factor`, { waitUntil: 'domcontentloaded' });
    await fillSubmit(page, 'form[action$="/two-factor/enable"]', {});
    await reconfirm(page, PASS);
    if (!/two-factor/.test(page.url())) await page.goto(`${base}/two-factor`, { waitUntil: 'domcontentloaded' });
    if (await page.$('form[action$="/two-factor/enable"]')) { // the re-confirm hop returns here: enable for real now
      await fillSubmit(page, 'form[action$="/two-factor/enable"]', {});
    }
    const secret = await page.evaluate(() => {
      const m = document.body.innerText.match(/\b([A-Z2-7]{16,64})\b/);
      return m ? m[1] : null;
    });
    if (!secret) R.fail('no 2FA secret shown on the security page');
    await shot(page, 'p4-security-1-2fa-secret');
    await fillSubmit(page, 'form[action$="/two-factor/confirm"]', { code: '000000' });
    if (C.one('select two_factor_confirmed_at from users where id = ?', [ids.u1]).two_factor_confirmed_at) R.fail('a wrong code confirmed 2FA');
    await fillSubmit(page, 'form[action$="/two-factor/confirm"]', { code: totp(secret) });
    if (!C.one('select two_factor_confirmed_at from users where id = ?', [ids.u1]).two_factor_confirmed_at) R.fail('the right code did not confirm 2FA');
    R.ok('enrol 2FA: password re-confirm → secret → wrong code refused → right code confirms');
    const rec = await call(page, 'GET', `${base}/two-factor/recovery-codes`);
    const recovery = rec.status === 200 ? JSON.parse(rec.text).recovery_codes : null;
    if (!recovery || recovery.length !== 10) R.fail(`recovery codes not readable (${rec.status})`);

    // ── 2FA: sign in ──────────────────────────────────────────────────────────────────────────────────────────────
    await waitNextStep(log); // the confirm code is used up
    let at = await passwordLogin(page, base, U1, PASS);
    if (!/two-factor\/challenge/.test(at)) R.fail(`password sign-in was not challenged (${at})`);
    await fillSubmit(page, 'form[action$="/two-factor/challenge"]', { code: '123456' });
    if (!/two-factor\/challenge/.test(page.url())) R.fail('a wrong code passed the challenge');
    const code = totp(secret);
    await fillSubmit(page, 'form[action$="/two-factor/challenge"]', { code });
    if (/two-factor|login/.test(new URL(page.url()).pathname)) R.fail(`the right code did not sign in (${page.url()})`);
    R.ok('sign in: password → challenge; wrong code refused; right code signs in');

    at = await passwordLogin(page, base, U1, PASS);
    await fillSubmit(page, 'form[action$="/two-factor/challenge"]', { code });
    if (!/two-factor\/challenge|login/.test(new URL(page.url()).pathname)) R.fail('the SAME code signed in twice (replay)');
    R.ok('the same code is refused the second time (one use per code)');

    at = await passwordLogin(page, base, U1, PASS);
    await fillSubmit(page, 'form[action$="/two-factor/challenge"]', { code: recovery[0] });
    if (/two-factor|login/.test(new URL(page.url()).pathname)) R.fail('a recovery code did not sign in');
    at = await passwordLogin(page, base, U1, PASS);
    await fillSubmit(page, 'form[action$="/two-factor/challenge"]', { code: recovery[0] });
    if (!/two-factor\/challenge|login/.test(new URL(page.url()).pathname)) R.fail('a recovery code signed in twice');
    R.ok('a recovery code signs in once, then is refused');

    // ── passkeys (virtual authenticator) ──────────────────────────────────────────────────────────────────────────
    const cdp = await page.target().createCDPSession();
    await cdp.send('WebAuthn.enable');
    const auth = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: {
      protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true,
    } });
    await waitNextStep(log);
    at = await passwordLogin(page, base, U1, PASS);
    await fillSubmit(page, 'form[action$="/two-factor/challenge"]', { code: totp(secret) });
    await page.goto(`${base}/two-factor#passkeys`, { waitUntil: 'domcontentloaded' });
    const addPasskey = async () => {
      await page.waitForSelector('[data-passkey-add]', { timeout: 15000 });
      await page.type('[data-passkey-name]', 'e2eC laptop');
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {}),
        page.evaluate(() => document.querySelector('[data-passkey-add]').click()), // the cookie banner sits over it in a fresh browser
      ]);
    };
    const pkLog = [];
    page.on('response', async (r) => { if (/passkeys/.test(r.url())) pkLog.push(`${r.status()} ${r.url().replace(base, '')} ${(await r.text().catch(() => '')).slice(0, 160)}`); });
    page.on('console', (m) => { if (/passkey|webauthn|credential/i.test(m.text())) pkLog.push(`console: ${m.text().slice(0, 200)}`); });
    await addPasskey();
    if (/password\/confirm/.test(page.url())) { await reconfirm(page, PASS); if (!/two-factor/.test(page.url())) await page.goto(`${base}/two-factor`, { waitUntil: 'domcontentloaded' }); await addPasskey(); }
    await new Promise((r) => setTimeout(r, 1500));
    const pk = C.one('select id, name from passkeys where user_id = ? order by id desc', [ids.u1]);
    if (!pk) {
      const err = await page.evaluate(() => (document.querySelector('[data-passkey-error]') || {}).textContent || '');
      R.fail(`no passkey stored (page: ${page.url()} ${err}) ${pkLog.join(' | ')}`);
    }
    await shot(page, 'p4-security-2-passkey');
    R.ok(`passkey "${pk.name}" added on the security page (virtual authenticator)`);

    // The login page offers the passkey in the username autofill (conditional mediation); the virtual authenticator
    // answers that request at once, as a person picking the passkey there. The button is the fallback.
    await logout(page, base);
    await page.waitForFunction(() => !/\/login/.test(location.pathname), { timeout: 10000 }).catch(() => {});
    if (/\/login/.test(new URL(page.url()).pathname)) {
      await page.waitForSelector('[data-passkey-button]:not([hidden])', { timeout: 15000 });
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {}),
        page.evaluate(() => document.querySelector('[data-passkey-button]').click()),
      ]);
    }
    await new Promise((r) => setTimeout(r, 1000));
    if (/login|two-factor/.test(new URL(page.url()).pathname)) {
      const err = await page.evaluate(() => (document.querySelector('[data-passkey-login] [data-passkey-error]') || {}).textContent || '');
      const creds = await cdp.send('WebAuthn.getCredentials', { authenticatorId: auth.authenticatorId }).catch((e) => ({ e: e.message }));
      R.fail(`passkey sign-in failed (${page.url()}): ${err} ${pkLog.slice(-4).join(' | ')} creds=${JSON.stringify(creds).slice(0, 300)}`);
    }
    R.ok('"Sign in with a passkey" signs straight in (no 2FA challenge: a passkey is two-step)');
    // put the authenticator away: it would answer every later login page's passkey autofill on its own
    await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId: auth.authenticatorId });

    // ── IDOR: a second account ────────────────────────────────────────────────────────────────────────────────────
    const at2 = await passwordLogin(two.page, base, U2, PASS);
    if (/login/.test(at2)) R.fail('second account sign-in failed');
    await two.page.goto(`${base}/two-factor`, { waitUntil: 'domcontentloaded' });
    const ren = await call(two.page, 'PATCH', `${base}/passkeys/${pk.id}`, { name: 'e2eC stolen' });
    const del = await call(two.page, 'DELETE', `${base}/passkeys/${pk.id}`);
    const still = C.one('select name from passkeys where id = ?', [pk.id]);
    if (!still || still.name !== pk.name) R.fail('another account renamed or removed the passkey');
    if (ren.status < 400 || (del.status < 400 && del.status !== 302 && del.status !== 423)) R.fail(`another account: rename ${ren.status}, remove ${del.status}`);
    R.ok(`another account: rename → ${ren.status}, remove → ${del.status}; the passkey is untouched`);
    const rc2 = await call(two.page, 'GET', `${base}/two-factor/recovery-codes`);
    if (rc2.status === 200 && recovery.some((c) => rc2.text.includes(c))) R.fail('another account read the recovery codes');
    R.ok(`another account: recovery codes → ${rc2.status} (none of the first account's)`);

    // ── password reset ────────────────────────────────────────────────────────────────────────────────────────────
    await logout(page, base);
    const since = Object.fromEntries(logFiles.map((f) => [f, fs.existsSync(f) ? fs.statSync(f).size : 0]));
    await page.goto(`${base}/password/reset`, { waitUntil: 'domcontentloaded' });
    await fillSubmit(page, 'form[action$="/password/email"]', { email: U1.email });
    let link = null;
    for (let i = 0; i < 20 && !link; i++) { link = resetLinkFromLog(logFiles, since); if (!link) await new Promise((r) => setTimeout(r, 500)); }
    if (!link) R.fail('no reset mail in the instance log (MAIL_MAILER=log)');
    R.ok('forgot password: the reset mail carries a reset link');
    await waitNextStep(log);
    await page.goto(link.replace(/^https?:\/\/[^/]+/, base), { waitUntil: 'domcontentloaded' });
    await fillSubmit(page, 'form[action$="/password/reset"]', { email: U1.email, password: NEW_PASS, password_confirmation: NEW_PASS });
    if (!/two-factor\/challenge/.test(page.url())) R.fail(`the reset signed in without the 2FA challenge (${page.url()})`);
    if (30000 - (Date.now() % 30000) < 8000) await waitNextStep(log); // never submit a code about to roll over
    // six challenge posts a minute per IP (auth-login): the whole story runs inside that window on a fast host
    try { B.artisan(['cache:clear'], dbEnv()); } catch (_e) { /* best effort */ }
    await fillSubmit(page, 'form[action$="/two-factor/challenge"]', { code: totp(secret) });
    if (/two-factor\/challenge/.test(new URL(page.url()).pathname)) {
      // the replay guard keeps the newest time step used (±1 window): a code accepted earlier at the next step makes
      // this step's code a replay. One fresh step later the code is new — the guard working, not a failure.
      const before = await page.evaluate(() => [...document.querySelectorAll('.invalid-feedback, .alert, [role=alert], .ui-field__error')].map((e) => e.textContent.trim()).join(' | ').slice(0, 200));
      log(`  p4-security: first challenge code refused (${before}); retrying on the next step`);
      await waitNextStep(log);
      try { B.artisan(['cache:clear'], dbEnv()); } catch (_e) { /* best effort */ }
      await page.goto(`${base}/two-factor/challenge`, { waitUntil: 'domcontentloaded' });
      await fillSubmit(page, 'form[action$="/two-factor/challenge"]', { code: totp(secret) });
    }
    if (/login|two-factor/.test(new URL(page.url()).pathname)) {
      const why = await page.evaluate(() => [...document.querySelectorAll('.invalid-feedback, .alert, [role=alert], .ui-field__error')].map((e) => e.textContent.trim()).join(' | ').slice(0, 300));
      R.fail(`after the reset the challenge did not sign in (${new URL(page.url()).pathname}: ${why})`);
    }
    R.ok('reset → new password → the 2FA challenge still applies → signed in');
    const hops = [];
    const onDoc = (r) => { if (r.request().resourceType() === 'document') hops.push(`${r.status()} ${r.request().method()} ${r.url().replace(base, '')}`); };
    page.on('response', onDoc);
    at = await passwordLogin(page, base, U1, PASS);
    page.off('response', onDoc);
    if (/two-factor\/challenge|dashboard/.test(at) || C.one('select 1 as x from users where id = ? and two_factor_confirmed_at is null', [ids.u1])) R.fail(`the OLD password still signs in (${at}): ${hops.join(' | ')}`);
    await logout(page, base);
    await page.goto(link.replace(/^https?:\/\/[^/]+/, base), { waitUntil: 'domcontentloaded' });
    await fillSubmit(page, 'form[action$="/password/reset"]', { email: U1.email, password: 'e2eC-Pass-3!', password_confirmation: 'e2eC-Pass-3!' });
    if (/two-factor\/challenge/.test(page.url()) || !/password\/reset/.test(page.url())) R.fail(`a used reset link worked again (${page.url()})`);
    R.ok('the old password and the used reset link are refused');

    // ── staff PIN lockout (per store) ─────────────────────────────────────────────────────────────────────────────
    await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
    const pin = (store, value) => call(page, 'POST', `${base}/api/v1/stores/${store}/staff/pin`, { pin: value });
    let failures = 0;
    let locked = false;
    let tries = 0;
    while (!locked && tries < 60) {
      tries += 1;
      const r = await pin(ids.a, String(100000 + tries));
      if (r.status === 401) failures += 1;
      else if (r.status === 429 && /locked/i.test(r.text)) locked = true;
      else if (r.status === 429) { await new Promise((res) => setTimeout(res, 20000)); } // route throttle: pace
      else R.fail(`unexpected PIN answer ${r.status} ${r.text}`);
    }
    if (!locked) R.fail(`PIN sign-in not locked after ${failures} wrong PINs`);
    if (failures + 1 !== 5) R.fail(`the store's PIN sign-in locked at wrong PIN #${failures + 1}, not #5 (DECISIONS 2026-10-07; the 5th wrong PIN is answered "locked")`);
    R.ok(`${failures + 1} wrong PINs → the store's PIN sign-in is locked`);
    await new Promise((res) => setTimeout(res, 61000)); // past the per-client route throttle
    const right = await pin(ids.a, PIN_A);
    if (right.status !== 429) R.fail(`the right PIN signed in on a locked store (${right.status})`);
    const other = await pin(ids.b, PIN_B);
    if (other.status !== 200) R.fail(`another store's PIN sign-in was affected (${other.status} ${other.text})`);
    R.ok('locked store refuses even the right PIN; another store\'s PIN sign-in still works');

    R.summary();
    return undefined;
  } finally {
    await one.close();
    await two.close();
    server.stop();
  }
};
