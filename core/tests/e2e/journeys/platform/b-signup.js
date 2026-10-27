// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * B1 — Owner signup → verified → first store (Arabic dashboard).
 *
 * The whole owner onboarding seam, driven end-to-end in a real browser in ar:
 *   1. Register a fresh owner at the public signup form (`register`).
 *   2. Pull the verification link out of the lane mail log (MAIL_MAILER=log) and
 *      visit it while the just-registered session is still authenticated.
 *   3. Prove `email_verified_at` is now set (DB ground truth, not the flash).
 *   4. Log in from a clean session and land on the Arabic dashboard.
 *   5. The onboarding checklist card (#ob-checklist) is present with "add your
 *      first store" still open (a fresh owner has zero stores).
 *   6. Create the first store through the real multi-step create form
 *      (`restaurants.store`): name, slug, phone, address, description, brand
 *      colour, logo + cover uploads, the default template.
 *   7. The store now shows in the owner's stores list (DOM + DB), and the public
 *      storefront `/{slug}` renders on the default template.
 *
 * Every dashboard/auth page landed on is asserted `dir="rtl" lang="ar"`.
 *
 * The created slug is printed (SLUG=…) for the ledger Note — B2 builds its menu
 * on this store.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const {
  cfg,
  localeUrl,
  assertRtl,
  collectConsole,
  resetBrowserSession,
  loginOwner,
  resolveMailLog,
  mailCheckpoint,
  db,
  newIdentity,
  alpineHideRaceErrors,
} = require('../../lib/platform');

/**
 * The newest verification link written to the lane mail log since `since` bytes.
 *
 * NOT `readMailLink()`: that helper unconditionally runs a quoted-printable
 * hex-decode (`=XX` → byte) over the body, which corrupts a signed URL whose
 * query carries literal `=` (`expires=…`, `signature=…`) when the mail part is
 * 8-bit (as the Herd log mailer writes it) — see ISS-B-001. Here we only unfold
 * QP soft line-wraps and decode the HTML `&amp;`, leaving the signature intact.
 */
function readVerifyLink(since) {
  const logFile = resolveMailLog();
  if (!logFile || !fs.existsSync(logFile)) throw new Error(`b-signup: mail log not found (${logFile})`);
  const buf = fs.readFileSync(logFile);
  const text = buf.slice(Math.max(0, since || 0)).toString('utf8').replace(/=\r?\n/g, '');
  const urls = (text.match(/https?:\/\/[^\s"'<>)\]]+/g) || [])
    .filter((u) => /\/email\/verify\//.test(u))
    .map((u) => u.replace(/&amp;/g, '&'));
  if (!urls.length) throw new Error('b-signup: no verification link in the mail log since the checkpoint');
  return urls[urls.length - 1];
}

// A minimal but valid opaque 1×1 PNG. The create form's logo + cover inputs are
// `required|image|mimes:png,jpg,jpeg`, so a real file on disk is needed to
// upload. Written to the OS temp dir per run and removed afterwards — no binary
// fixture is committed.
const PNG_1x1 = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgYGAAAAAEAAH2FzhVAAAAAElFTkSuQmCC',
  'base64',
);

function writeTempPng(tag) {
  const p = path.join(os.tmpdir(), `e2e-b-${tag}-${Date.now()}-${Math.random().toString(36).slice(2)}.png`);
  fs.writeFileSync(p, PNG_1x1);
  return p;
}

// The shared webLogin() resets the session then GETs /login and submits with no
// retry. Under PHP-FPM pressure (rapid repeat runs) that GET can be slow enough
// to miss the field, or a just-torn-down session can hand the form a stale CSRF
// token → a 419 that bounces back to /login. Both are transient: a fresh
// attempt gets a new session + token. Retry the whole login a few times so the
// journey is reproducible (WF.1) without touching the shared helper.
async function loginWithRetry(page, creds, log, resetSession, attempts = 4) {
  let lastErr;
  for (let i = 1; i <= attempts; i++) {
    try {
      return await loginOwner(page, creds);
    } catch (e) {
      lastErr = e;
      log(`b-signup: login attempt ${i}/${attempts} failed (${e.message}); retrying`);
      await resetSession(page).catch(() => {});
      await new Promise((r) => setTimeout(r, 750 * i));
    }
  }
  throw lastErr;
}

// Set a field's value directly in the DOM (robust for <textarea> and for
// controls inside Alpine `x-show` steps, where click-then-type is flaky), then
// fire input/change so any Alpine binding (preview, x-model) stays in sync.
async function setField(page, selector, value) {
  const ok = await page.$eval(selector, (el, v) => {
    el.value = v;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }, String(value)).catch(() => false);
  if (!ok) throw new Error(`b-signup: field ${selector} not found on ${page.url()}`);
}

module.exports = async function bSignup({ page, log, shot }) {
  await page.setViewport({ width: 390, height: 844 });
  collectConsole(page);
  // Track resource 404s (URL + page) so we can report the count without failing
  // the flow on environment/legacy asset gaps (see the final hygiene check).
  const resource404s = [];
  page.on('response', (r) => {
    if (r.status() === 404) resource404s.push(`${new URL(r.url()).pathname} @${new URL(page.url()).pathname}`);
  });

  const id = newIdentity('b', 'owner');
  const unix = (id.email.match(/-(\d+)@/) || [])[1] || String(Date.now());
  // username: alpha_dash, unique, ≤16 chars. Login is by e-mail so the username
  // only has to be unique and valid.
  const username = ('b' + unix).slice(0, 16);
  const storeSlug = `e2e-b-store-${unix}`;
  const storeName = `E2E B Store ${unix}`;

  // ── 1. Register ────────────────────────────────────────────────────────────
  await resetBrowserSession(page);
  await page.goto(localeUrl('/signup'), { waitUntil: 'domcontentloaded' });
  await assertRtl(page);
  await page.waitForSelector('input[name="username"]', { timeout: cfg.navTimeout });

  await setField(page, 'input[name="username"]', username);
  await setField(page, 'input[name="email"]', id.email);
  await setField(page, 'input[name="password"]', id.password);
  await setField(page, 'input[name="password_confirmation"]', id.password);
  const agree = await page.$('#agree_for_term, input[name="agree_for_term"]');
  if (agree) await agree.click();
  // P1-23: business-not-consumer confirmation (required where the legal pages are published).
  const business = await page.$('#business_confirm');
  if (business) await business.click();
  await shot(page, 'b-signup-form');

  // Scope the mail-log scan to bytes written by THIS registration.
  const mailSince = mailCheckpoint();
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
  ]);

  const afterSignup = new URL(page.url()).pathname;
  if (afterSignup === '/signup') {
    const err = await page.evaluate(() => (document.querySelector('.invalid-feedback, .alert, [role="alert"]')?.textContent || '').replace(/\s+/g, ' ').trim());
    throw new Error(`b-signup: registration bounced back to /signup${err ? ' — ' + err : ''}`);
  }
  log(`b-signup: registered ${id.email} (username ${username}) → ${afterSignup}`);

  // The user row exists and is NOT yet verified.
  let rows = db('SELECT id, email_verified_at FROM {p}users WHERE email = ?', [id.email]);
  if (!rows.length) throw new Error(`b-signup: no user row for ${id.email} after registration`);
  const userId = rows[0][0];
  if (rows[0][1] && rows[0][1] !== 'NULL' && rows[0][1] !== '') {
    throw new Error(`b-signup: email_verified_at was already set (${rows[0][1]}) before visiting the link`);
  }

  // ── 2. Verify via the mailed link ──────────────────────────────────────────
  const verifyUrl = readVerifyLink(mailSince);
  if (!verifyUrl.startsWith(cfg.baseUrl)) {
    throw new Error(`b-signup: verify link host mismatch — expected ${cfg.baseUrl}, got ${verifyUrl}`);
  }
  const verifyResp = await page.goto(verifyUrl, { waitUntil: 'domcontentloaded' });
  if (verifyResp && verifyResp.status() >= 400) {
    throw new Error(`b-signup: verification link returned ${verifyResp.status()} (${verifyUrl})`);
  }
  await assertRtl(page);

  // ── 3. DB ground truth: now verified ───────────────────────────────────────
  rows = db('SELECT email_verified_at FROM {p}users WHERE id = ?', [userId]);
  const verifiedAt = rows.length ? rows[0][0] : null;
  if (!verifiedAt || verifiedAt === 'NULL' || verifiedAt === '') {
    throw new Error('b-signup: email_verified_at is still null after visiting the verification link');
  }
  log(`b-signup: verified user ${userId} at ${verifiedAt}`);

  // ── 4. Fresh login → Arabic dashboard ──────────────────────────────────────
  await loginWithRetry(page, { email: id.email, pass: id.password }, log, resetBrowserSession);
  await page.goto(localeUrl('/dashboard'), { waitUntil: 'domcontentloaded' });
  await assertRtl(page);

  // ── 5. Onboarding checklist visible, "add store" step still open ───────────
  const checklist = await page.evaluate(() => {
    const card = document.getElementById('ob-checklist');
    if (!card) return { present: false };
    const style = window.getComputedStyle(card);
    return {
      present: true,
      visible: card.offsetParent !== null && style.display !== 'none' && style.visibility !== 'hidden',
      text: (card.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 400),
    };
  });
  if (!checklist.present) throw new Error('b-signup: onboarding checklist (#ob-checklist) is not on the dashboard');
  if (!checklist.visible) throw new Error('b-signup: onboarding checklist (#ob-checklist) is present but not visible');
  await shot(page, 'b-signup-dashboard-checklist');
  log(`b-signup: onboarding checklist visible — "${checklist.text}"`);

  // ── 6. Create the first store ──────────────────────────────────────────────
  await page.goto(localeUrl('/stores/create'), { waitUntil: 'domcontentloaded' });
  await assertRtl(page);
  // Scope EVERY field + the submit to the create <form> itself. The dashboard
  // shell carries other controls (a stray `description` field lives elsewhere in
  // the chrome), so an unscoped `[name="description"]` sets the wrong element and
  // the create textarea posts empty.
  const FORM = 'form[action$="/stores"]';
  await page.waitForSelector(FORM, { timeout: cfg.navTimeout });

  // The flake this journey hit once ("pageerror: u is not a function") was Alpine 3.13's x-show hide cascade calling
  // an already-consumed hide promise when one element is hidden twice within a frame (fixed by the vendored Alpine
  // 3.14.9). Drive that sequence deterministically on the shipped Alpine so a regression fails here every time.
  const raceErrors = await alpineHideRaceErrors(page);
  if (raceErrors.length) throw new Error(`b-signup: Alpine x-show hide race raised: ${raceErrors.join(' | ')}`);

  await setField(page, `${FORM} input[name="title"]`, storeName);
  await setField(page, `${FORM} input[name="slug"]`, storeSlug);
  await setField(page, `${FORM} input[name="phone"]`, id.phone);
  await setField(page, `${FORM} input[name="address"]`, 'شارع الملك فهد، الرياض');
  await setField(page, `${FORM} [name="description"]`, 'متجر اختبار شامل لمسار تسجيل المالك.');

  const logoPath = writeTempPng('logo');
  const coverPath = writeTempPng('cover');
  try {
    const logoInput = await page.$(`${FORM} input[name="main_image"]`);
    const coverInput = await page.$(`${FORM} input[name="cover_image"]`);
    if (!logoInput || !coverInput) throw new Error('b-signup: create form is missing the logo/cover file inputs');
    await logoInput.uploadFile(logoPath);
    await coverInput.uploadFile(coverPath);

    // The default template is pre-selected in the hidden `store_template`
    // input; confirm it is non-empty before submitting.
    const template = await page.$eval(`${FORM} input[name="store_template"]`, (el) => el.value);
    if (!template) throw new Error('b-signup: store_template hidden input is empty on the create form');
    log(`b-signup: creating store "${storeName}" (${storeSlug}) on template "${template}"`);
    await shot(page, 'b-signup-create-form');

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
      // Native submit — the form is one <form> whose steps are only visually
      // toggled by Alpine (no @submit handler), and there is no client
      // validation we need to satisfy that the server does not re-check.
      page.$eval(FORM, (f) => f.submit()),
    ]);
  } finally {
    fs.unlink(logoPath, () => {});
    fs.unlink(coverPath, () => {});
  }

  // The store row exists, owned by the new user, on the chosen slug.
  rows = db('SELECT id, slug, user_id FROM {p}stores WHERE slug = ?', [storeSlug]);
  if (!rows.length) {
    const landed = new URL(page.url()).pathname;
    const toast = await page.evaluate(() => {
      const q = (window.__dashToastQueue || []).map((t) => t[1]).join(' | ');
      const m = (document.documentElement.outerHTML.match(/var flashes = (\[.*?\]);/s) || [])[1];
      return q || m || '(no flash captured)';
    });
    throw new Error(`b-signup: store row for slug "${storeSlug}" was not created (landed ${landed}): ${toast}`);
  }
  if (String(rows[0][2]) !== String(userId)) {
    throw new Error(`b-signup: store ${storeSlug} is owned by user ${rows[0][2]}, expected ${userId}`);
  }
  const storeId = rows[0][0];
  log(`b-signup: created store post ${storeId} slug ${storeSlug} owner ${userId}`);

  // ── 7. Dashboard/stores list shows it; storefront renders ──────────────────
  await page.goto(localeUrl('/stores'), { waitUntil: 'domcontentloaded' });
  await assertRtl(page);
  const listShowsStore = await page.evaluate((needle) => (document.body.innerText || '').includes(needle), storeName);
  if (!listShowsStore) {
    // fall back to slug — the card may render the slug rather than the title
    const listShowsSlug = await page.evaluate((needle) => (document.body.innerText || '').includes(needle), storeSlug);
    if (!listShowsSlug) throw new Error(`b-signup: new store "${storeName}"/"${storeSlug}" not visible on /stores`);
  }
  await shot(page, 'b-signup-stores-list');

  // Public storefront on the default template renders (no 4xx/5xx, no exception,
  // shows the store name).
  const storeResp = await page.goto(localeUrl('/' + storeSlug), { waitUntil: 'domcontentloaded' });
  const storeStatus = storeResp ? storeResp.status() : 0;
  if (storeStatus >= 400) throw new Error(`b-signup: storefront /${storeSlug} returned ${storeStatus}`);
  const storeBody = await page.evaluate(() => (document.body.innerText || '').slice(0, 5000));
  if (/Whoops, looks like something went wrong|Illuminate\\|Fatal error|Server Error/i.test(storeBody)) {
    throw new Error(`b-signup: storefront /${storeSlug} rendered an exception page`);
  }
  if (!storeBody.includes(storeName)) {
    throw new Error(`b-signup: storefront /${storeSlug} did not render the store name "${storeName}"`);
  }
  await shot(page, 'b-signup-storefront');
  log(`b-signup: storefront /${storeSlug} rendered (status ${storeStatus})`);

  // ── Hygiene: fail ONLY on real JS page errors and ENFORCED CSP refusals.
  //
  // Match lib/platform.js's intended semantics but robustly. collectConsole tags
  // each captured line: `pageerror:` (uncaught JS), `CSP:` (an enforced
  // "Refused to …"), or `console.error:` (everything else, dominated here by
  // resource-load 404s). We keep only pageerror + enforced-CSP as failures and
  // tolerate every `console.error:` line — the 37 known resource-404s (site
  // branding logo, missing on the copied storage so it 404s on the stock /signup
  // and /login too; and the LEGACY default template's `assets/templates/classic/**`
  // bundle, out of scope per HANDOFF §4 — see ISS-B-002/003), plus any transient
  // async beacon miss.
  //
  // Report-only CSP violations are advisory and MUST NOT fail the run. Chrome
  // prints them as "[Report Only] Refused to …" (a space), but collectConsole
  // only skips the hyphenated "report-only", so a report-only beacon slips
  // through mis-tagged as `CSP:` and flakes the journey (F0 gate). Drop anything
  // that reads report-only in either spelling before judging enforced refusals.
  const collected = (page.__platformConsole && page.__platformConsole.errors) || [];
  const real = collected.filter((e) => !/report[\s-]?only/i.test(e) && /^(pageerror:|CSP:)/.test(e));
  if (real.length) {
    throw new Error(`b-signup: CSP refusals / JS errors (${real.length}):\n  ${real.join('\n  ')}`);
  }
  const uniq404 = [...new Set(resource404s)];
  log(`b-signup: tolerated ${resource404s.length} resource-404s (${uniq404.length} unique; site-logo + legacy template assets — see ISS-B-002/003)`);
  log(`b-signup: PASS — SLUG=${storeSlug} POST_ID=${storeId} USER_ID=${userId}`);
};
