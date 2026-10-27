// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * B3 — Theme switch + customizer × 19 (platform E2E, lane B) — VISUAL.
 *
 * Drives the whole owner "pick a look" seam in a real browser in Arabic and
 * verifies every claim against DOM / DB ground truth, never pixels.
 *
 * REPEATABLE: every run bootstraps a FRESH owned store (B1/B2's proven
 * signup → verify → login → create path, unix-stamped slug), so runs never
 * accumulate cruft and never depend on shared demo data. One store is then
 * walked through ALL 19 kept templates — the switch is `store_template`
 * (a post option), and customizer settings are keyed per template, so a switch
 * never loses a previous theme's customizations.
 *
 * Per template:
 *   1. SWITCH   — click the customizer's Theme-library card (the real button,
 *                 found by its Alpine handler, not by its translated label).
 *                 Assert `store_template` in {p}stores.settings, and that the
 *                 storefront still renders on the new template (no 500).
 *   2. OPTIONS  — assert the schema's controls RENDER. flipbook ships no
 *                 theme.json → zero fields: assert no 500 and that no phantom
 *                 colour/font control is invented. pageview gets its own
 *                 29-field schema (incl. the pageview-only `colors.paper`).
 *   3. DRAFT    — set ONE colour token (`colors.primary`) + ONE font
 *                 (`fonts.pair`) through the real inputs, click Save draft.
 *                 Assert the draft reached the DB, that the owner-only preview
 *                 frame (`?preview_theme=1`) SHOWS it, and that the PUBLIC
 *                 `GET /{slug}` does NOT — the draft gate is the discriminator.
 *   4. PUBLISH  — click Publish. `GET /{slug}` must then serve the token: read
 *                 `--tc-primary` / `--tf-heading` off `document.documentElement`
 *                 with getComputedStyle (the WI's contract).
 *   5. REVERT   — publish a second, different colour + font, assert the
 *                 storefront moved, then REVERT to the first published version
 *                 and assert the storefront is back on the first colour/font.
 *                 Restoring a *different* earlier value (not "back to nothing")
 *                 is what makes this assertion discriminating.
 *
 * The two font pairings are chosen to be mutually exclusive in the emitted
 * `--tf-heading` stack ("Plus Jakarta Sans" vs "Syne") and the two colours are
 * distinct hexes, so a stale cache or a missed publish cannot accidentally
 * satisfy an assertion.
 *
 * Every dashboard page landed on is asserted `dir="rtl" lang="ar"`. Finishes by
 * asserting no uncaught JS errors / no ENFORCED CSP refusals (report-only-safe)
 * and no 5xx.
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
} = require('../../lib/platform');

// ── the 19 kept templates (HANDOFF §1) ──────────────────────────────────────
// Single source of truth: the 17 MODERN (KeptTemplates::MODERN mirror) + 2 display-only.
const TEMPLATES = [...require('../../lib/retained'), 'pageview']; // flipbook removed in WI-03

// flipbook ships no theme.json → ThemeCustomizerService::schemaFor() returns an
// empty schema. The WI's carve-out: assert no 500 and that whatever the schema
// allows renders — there is no colour or font control to drive.
const NO_SCHEMA = new Set(['flipbook']);

// Two mutually-exclusive looks, so "revert" cannot be satisfied by a no-op.
const COLOR_A = '#3a7bd5';
const COLOR_B = '#c2185b';
const FONT_A = 'jakarta';           // → --tf-heading contains 'Plus Jakarta Sans'
const FONT_B = 'syne-instrument';   // → --tf-heading contains 'Syne'
const NEEDLE_A = 'Plus Jakarta Sans';
const NEEDLE_B = 'Syne';

// Customizer controls carry `:id="'tcf-'+f.id"`, so the ids embed dots — always
// match them as attribute values, never as CSS id selectors.
const COLOR_INPUT = 'input[type="color"][id="tcf-colors.primary"]';
const FONT_SELECT = 'select[id="tcf-fonts.pair"]';

const normHex = (v) => String(v || '').trim().toLowerCase();

// These storefronts are heavy (feast/velvet ship ~450 KB of HTML plus their
// whole asset bundle) and the run drives 7 page loads per template × 19, so the
// harness default of 30 s is not a safe ceiling under Herd FPM contention —
// every wait in this journey uses this one instead.
const T = 90000;

// A minimal but valid opaque 1×1 PNG — a real file on disk, so the create form's
// required logo/cover inputs are satisfied without committing a binary fixture.
const PNG_1x1 = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgYGAAAAAEAAH2FzhVAAAAAElFTkSuQmCC',
  'base64',
);

function writeTempPng(tag) {
  const p = path.join(os.tmpdir(), `e2e-b-${tag}-${Date.now()}-${Math.random().toString(36).slice(2)}.png`);
  fs.writeFileSync(p, PNG_1x1);
  return p;
}

/**
 * The newest email-verification link written to the lane mail log since `since`
 * bytes. Mirrors b-menu.js's readVerifyLink: only unfold QP soft line-wraps and
 * decode `&amp;`, never the blanket `=XX` hex decode (that corrupts a signed
 * URL's `expires=`/`signature=` — ISS-B-001).
 */
function readVerifyLink(since) {
  const logFile = resolveMailLog();
  if (!logFile || !fs.existsSync(logFile)) throw new Error(`b-themes: mail log not found (${logFile})`);
  const buf = fs.readFileSync(logFile);
  const text = buf.slice(Math.max(0, since || 0)).toString('utf8').replace(/=\r?\n/g, '');
  const urls = (text.match(/https?:\/\/[^\s"'<>)\]]+/g) || [])
    .filter((u) => /\/email\/verify\//.test(u))
    .map((u) => u.replace(/&amp;/g, '&'));
  if (!urls.length) throw new Error('b-themes: no verification link in the mail log since the checkpoint');
  return urls[urls.length - 1];
}

// Set a field's value directly in the DOM then fire input/change so any Alpine
// binding stays in sync.
async function setField(page, selector, value) {
  const ok = await page.$eval(selector, (el, v) => {
    el.value = v;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }, String(value)).catch(() => false);
  if (!ok) throw new Error(`b-themes: field ${selector} not found on ${page.url()}`);
}

// The shared webLogin resets the session, GETs /login and submits with no retry;
// under FPM pressure that can miss the field or hand a stale CSRF token (419).
// Both are transient — retry the whole login (WF.1 reproducibility).
async function loginWithRetry(page, creds, log, attempts = 4) {
  let lastErr;
  for (let i = 1; i <= attempts; i++) {
    try {
      return await loginOwner(page, creds);
    } catch (e) {
      lastErr = e;
      log(`b-themes: login attempt ${i}/${attempts} failed (${e.message}); retrying`);
      await resetBrowserSession(page).catch(() => {});
      await new Promise((r) => setTimeout(r, 750 * i));
    }
  }
  throw lastErr;
}

/** GET a same-origin JSON endpoint from the authenticated page context. */
async function getJson(page, urlPath) {
  return page.evaluate(async (u) => {
    const r = await fetch(u, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const t = await r.text();
    let j = null;
    try { j = JSON.parse(t); } catch (_e) { /* not json */ }
    return { status: r.status, ok: r.ok, json: j, text: (t || '').slice(0, 400) };
  }, urlPath);
}

/** The dashboard's CSRF token, read while a dashboard page is loaded. */
async function csrfToken(page) {
  return page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.content
    || document.querySelector('input[name="_token"]')?.value || '');
}

/**
 * POST JSON to a dashboard endpoint carrying the real CSRF token. `token` is
 * passed explicitly when the call is made from a page that is NOT a dashboard
 * page: a storefront carries no `csrf-token` meta (pageview's certainly does
 * not), so reading it there yields '' and the POST 419s.
 */
async function postJson(page, urlPath, body, token) {
  return page.evaluate(async ({ urlPath, body, token }) => {
    const csrf = token || document.querySelector('meta[name="csrf-token"]')?.content
      || document.querySelector('input[name="_token"]')?.value || '';
    const r = await fetch(urlPath, {
      method: 'POST',
      credentials: 'same-origin',
      redirect: 'manual',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf,
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(body || {}),
    });
    const raw = await r.text();
    let j = null;
    try { j = JSON.parse(raw); } catch (_e) { /* not json */ }
    return { status: r.status, ok: r.ok, json: j, text: (raw || '').slice(0, 400) };
  }, { urlPath, body, token });
}

// ── store bootstrap (B1/B2's proven path) ───────────────────────────────────

/**
 * Register → verify → login → create a fresh store owned by a new identity, on
 * the modern `crave` template. Returns { slug, rid, userId, email, pass, unix }.
 */
async function bootstrapOwnedStore(page, log, shot) {
  const id = newIdentity('b', 'themes');
  const unix = (id.email.match(/-(\d+)@/) || [])[1] || String(Date.now());
  const username = ('bt' + unix).slice(0, 16);
  const storeSlug = `e2e-b-themes-${unix}`;
  const storeName = `E2E B Themes ${unix}`;

  // 1) register
  await resetBrowserSession(page);
  await page.goto(localeUrl('/signup'), { waitUntil: 'domcontentloaded' });
  await assertRtl(page);
  await page.waitForSelector('input[name="username"]', { timeout: T });
  await setField(page, 'input[name="username"]', username);
  await setField(page, 'input[name="email"]', id.email);
  await setField(page, 'input[name="password"]', id.password);
  await setField(page, 'input[name="password_confirmation"]', id.password);
  const agree = await page.$('#agree_for_term, input[name="agree_for_term"]');
  if (agree) await agree.click();
  // P1-23: business-not-consumer confirmation (required where the legal pages are published).
  const business = await page.$('#business_confirm');
  if (business) await business.click();

  const mailSince = mailCheckpoint();
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: T }).catch(() => {}),
  ]);
  if (new URL(page.url()).pathname === '/signup') {
    const err = await page.evaluate(() => (document.querySelector('.invalid-feedback, .alert, [role="alert"]')?.textContent || '').replace(/\s+/g, ' ').trim());
    throw new Error(`b-themes: registration bounced back to /signup${err ? ' — ' + err : ''}`);
  }
  let rows = db('SELECT id, email_verified_at FROM {p}users WHERE email = ?', [id.email]);
  if (!rows.length) throw new Error(`b-themes: no user row for ${id.email} after registration`);
  const userId = rows[0][0];

  // 2) verify via the mailed link
  const verifyUrl = readVerifyLink(mailSince);
  if (!verifyUrl.startsWith(cfg.baseUrl)) throw new Error(`b-themes: verify link host mismatch (${verifyUrl})`);
  const verifyResp = await page.goto(verifyUrl, { waitUntil: 'domcontentloaded' });
  if (verifyResp && verifyResp.status() >= 400) throw new Error(`b-themes: verification link returned ${verifyResp.status()}`);
  rows = db('SELECT email_verified_at FROM {p}users WHERE id = ?', [userId]);
  if (!rows.length || !rows[0][0] || rows[0][0] === 'NULL') throw new Error('b-themes: email_verified_at still null after verify');
  log(`b-themes: owner ${id.email} (user ${userId}) verified`);

  // 3) fresh login → Arabic dashboard
  await loginWithRetry(page, { email: id.email, pass: id.password }, log);
  await page.goto(localeUrl('/dashboard'), { waitUntil: 'domcontentloaded' });
  await assertRtl(page);

  // 4) create the store on crave
  await gotoWithRetry(page, localeUrl('/stores/create'));
  await assertRtl(page);
  const FORM = 'form[action$="/stores"]';
  await page.waitForSelector(FORM, { timeout: T });

  // ── the WI's mandatory assertion: the template travels in a hidden input. ──
  const createHidden = await page.$(`${FORM} input[name="store_template"]`);
  if (!createHidden) throw new Error('b-themes: /stores/create has no store_template hidden input');

  await setField(page, `${FORM} input[name="title"]`, storeName);
  await setField(page, `${FORM} input[name="slug"]`, storeSlug);
  await setField(page, `${FORM} input[name="phone"]`, id.phone);
  await setField(page, `${FORM} input[name="address"]`, 'شارع الملك فهد، الرياض');
  await setField(page, `${FORM} [name="description"]`, 'متجر اختبار القوالب على قالب crave.');
  // Pin the template to the modern in-scope `crave` (the lane default is the
  // legacy Ice-cream-theme, out of scope per HANDOFF §4).
  await setField(page, `${FORM} input[name="store_template"]`, 'crave');

  const logoPath = writeTempPng('logo');
  const coverPath = writeTempPng('cover');
  try {
    const logoInput = await page.$(`${FORM} input[name="main_image"]`);
    const coverInput = await page.$(`${FORM} input[name="cover_image"]`);
    if (!logoInput || !coverInput) throw new Error('b-themes: create form missing logo/cover inputs');
    await logoInput.uploadFile(logoPath);
    await coverInput.uploadFile(coverPath);
    await shot(page, 'b-themes-create-form');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: T }).catch(() => {}),
      page.$eval(FORM, (f) => f.submit()),
    ]);
  } finally {
    fs.unlink(logoPath, () => {});
    fs.unlink(coverPath, () => {});
  }

  rows = db('SELECT id, user_id FROM {p}stores WHERE slug = ?', [storeSlug]);
  if (!rows.length) throw new Error(`b-themes: store row for slug "${storeSlug}" was not created`);
  if (String(rows[0][1]) !== String(userId)) throw new Error(`b-themes: store ${storeSlug} owned by ${rows[0][1]}, expected ${userId}`);
  const rid = Number(rows[0][0]);
  const tpl = db("SELECT JSON_UNQUOTE(JSON_EXTRACT(settings, '$.core.store_template')) FROM {p}stores WHERE id = ? AND settings IS NOT NULL", [rid]).filter((r) => r[0] !== 'NULL');
  if (!tpl.length || tpl[0][0] !== 'crave') throw new Error(`b-themes: store template is "${tpl[0] ? tpl[0][0] : '(none)'}", expected crave`);
  log(`b-themes: created store ${rid} slug ${storeSlug} on crave`);
  return { slug: storeSlug, rid, userId, email: id.email, pass: id.password, unix };
}

// ── theme-switch + customizer helpers ───────────────────────────────────────

async function templateInDb(rid) {
  const r = db("SELECT JSON_UNQUOTE(JSON_EXTRACT(settings, '$.core.store_template')) FROM {p}stores WHERE id = ? AND settings IS NOT NULL", [rid]).filter((r) => r[0] !== 'NULL');
  return r.length ? String(r[0][0]) : '';
}

/**
 * `page.goto` with a retry: one navigation can lose the race against Herd FPM
 * when 19 heavy storefronts are rendered back to back, and a single slow
 * response is not a product failure (WF.1 reproducibility).
 */
async function gotoWithRetry(page, url, tries = 3) {
  let lastErr;
  for (let i = 1; i <= tries; i++) {
    try {
      const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: T });
      if (!resp || resp.status() < 400) return resp;
      throw new Error(`HTTP ${resp.status()}`);
    } catch (e) {
      lastErr = e;
      await new Promise((r) => setTimeout(r, 1000 * i));
    }
  }
  throw new Error(`b-themes: GET ${url} failed after ${tries} attempts (${lastErr && lastErr.message})`);
}

async function waitForTemplateInDb(rid, folder, tries = 40) {
  for (let i = 0; i < tries; i++) {
    if ((await templateInDb(rid)) === folder) return true;
    await new Promise((r) => setTimeout(r, 250));
  }
  return false;
}

/**
 * Draft / published settings for this template, straight from the DB. Settings
 * are keyed per template (`theme_settings.template` = the folder for
 * first-party themes), so a switch never loses another theme's customizations.
 */
async function settingsInDb(rid, template, column) {
  const r = db('SELECT ' + column + ' FROM {p}theme_settings WHERE store_id = ? AND template = ?', [rid, template]);
  return r.length ? String(r[0][0] || '') : '';
}

/**
 * Poll `fn` until it returns a truthy value. Every write in this journey is an
 * async round trip through Herd FPM, and a fixed sleep is exactly the kind of
 * timing guess that makes an e2e green on one machine and flaky on the next
 * (WF.1 reproducibility) — so each observable is waited for, never assumed.
 * Returns the last (falsy) result when it gives up.
 */
/**
 * The customizer's own status line ("Draft saved." / "Some values were
 * invalid: …"). Read when a write does not land, so the failure names the
 * server's answer instead of a bare "did not persist".
 */
async function customizerMessage(page) {
  return page.evaluate(() => {
    const el = document.querySelector('[x-text="msg"], .tc-msg, [role="status"]');
    return el ? String(el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 200) : '';
  }).catch(() => '');
}

/**
 * Click Save draft and wait for the draft to reach the DB.
 *
 * One retry is allowed: this run drives ~140 page loads through Herd FPM, and a
 * single dropped POST is a flake, not a product failure (WF.1 reproducibility).
 * On give-up the customizer's own message is included so the reason is visible.
 */
async function saveDraftAndWait(page, rid, template, hex) {
  const landed = () => pollFor(async () => {
    const s = await settingsInDb(rid, template, 'draft');
    return s.includes(hex) ? s : '';
  }, 30, 600);

  await clickAlpine(page, 'saveDraft()', 'Save draft');
  let draft = await landed();
  if (!draft) {
    const why = await customizerMessage(page);
    log(`b-themes: ${template} draft did not land on the first save (${why || 'no message'}); retrying once`);
    await clickAlpine(page, 'saveDraft()', 'Save draft (retry)');
    draft = await landed();
  }
  if (!draft) {
    const why = await customizerMessage(page);
    throw new Error(`b-themes: ${template} draft did not persist ${hex}`
      + ` (draft="${(await settingsInDb(rid, template, 'draft')).slice(0, 200)}"${why ? `, customizer said: "${why}"` : ''})`);
  }
  return draft;
}

/**
 * Record the customizer's draft saves on `page` (POST /theme-customizer/{rid}/draft): the colour/font sent, the
 * status, the rate-limit headers and the start of the body; plus any state snapshots noted by the caller.
 */
function traceDraftSaves(page, rid) {
  const lines = [];
  const isDraft = (u) => u.includes(`/theme-customizer/${rid}/draft`);
  const onRequest = (req) => {
    if (req.method() !== 'POST' || !isDraft(req.url())) return;
    let sent = '';
    try {
      const v = (JSON.parse(req.postData() || '{}').values) || {};
      sent = `colors.primary=${v['colors.primary']} fonts.pair=${v['fonts.pair']}`;
    } catch (e) { sent = 'unparsed body'; }
    lines.push(`POST sent ${sent}`);
  };
  const onResponse = async (res) => {
    if (res.request().method() !== 'POST' || !isDraft(res.url())) return;
    const h = res.headers();
    const body = await res.text().catch(() => '');
    lines.push(`answer ${res.status()} retry-after=${h['retry-after'] || '-'} x-ratelimit-remaining=${h['x-ratelimit-remaining'] || '-'} `
      + `x-ratelimit-limit=${h['x-ratelimit-limit'] || '-'} body=${body.replace(/\s+/g, ' ').slice(0, 200)}`);
  };
  const onFailed = (req) => { if (isDraft(req.url())) lines.push(`request failed: ${(req.failure() || {}).errorText}`); };
  page.on('request', onRequest);
  page.on('response', onResponse);
  page.on('requestfailed', onFailed);
  return {
    note(label, state) { lines.push(`${label}: ${JSON.stringify(state)}`); },
    report() { return lines.length ? lines.join(' || ') : 'no draft POST was sent'; },
    stop() { page.off('request', onRequest); page.off('response', onResponse); page.off('requestfailed', onFailed); },
  };
}

/** The customizer component's save-relevant state (Alpine data on the root). */
async function customizerState(page) {
  return page.evaluate(() => {
    const root = document.querySelector('.ui-tc[x-data]');
    const d = root && window.Alpine ? window.Alpine.$data(root) : null;
    if (!d) return { alpine: false };
    return { dirty: d.dirty, saving: d.saving, msg: d.msg, err: d.err, primary: (d.values || {})['colors.primary'],
      font: (d.values || {})['fonts.pair'], hasDraft: d.hasDraft, url: location.pathname + location.search + location.hash };
  }).catch((e) => ({ error: e.message }));
}

async function pollFor(fn, tries = 24, waitMs = 500) {
  let last;
  for (let i = 0; i < tries; i++) {
    last = await fn();
    if (last) return last;
    await new Promise((r) => setTimeout(r, waitMs));
  }
  return last;
}

/**
 * Click a control by its Alpine handler name. The run is `ar`, so every visible
 * label is translated — matching on text would be encoding-fragile. The
 * `@click` expression is authored data and never translated. (Alpine attribute
 * names start with `@`, so they are read with getAttribute rather than through
 * a CSS attribute selector.)
 */
async function clickAlpine(page, handler, what, tries = 30) {
  // Save draft is `::disabled="saving || !dirty"` and Publish `::disabled="saving"`,
  // so a click can land while the previous fetch is still settling. The enabled
  // state is waited for rather than slept for (WF.1 reproducibility).
  for (let i = 0; i < tries; i++) {
    const r = await page.evaluate((h) => {
      const el = Array.from(document.querySelectorAll('button, a, [role="button"]'))
        .find((b) => (b.getAttribute('@click') || '').includes(h));
      if (!el) return 'missing';
      if (el.disabled) return 'disabled';
      el.click();
      return 'ok';
    }, handler);
    if (r === 'ok') return;
    if (r === 'missing') throw new Error(`b-themes: ${what} control not found on ${page.url()}`);
    await new Promise((res) => setTimeout(res, 400));
  }
  throw new Error(`b-themes: ${what} control stayed disabled (a fetch never settled)`);
}

/**
 * Click the Theme-library card for `folder`. Cards carry
 * `@click="applyTemplate('<folder>', '<name>')"`, so the folder is matched in
 * authored data — no translated label, no card index.
 */
async function clickLibraryCard(page, folder) {
  const r = await page.evaluate((f) => {
    const needle = `applyTemplate('${f}'`;
    const btn = Array.from(document.querySelectorAll('button'))
      .find((b) => (b.getAttribute('@click') || '').includes(needle));
    if (!btn) return 'missing';
    if (btn.disabled) return 'disabled';
    btn.click();
    return 'ok';
  }, folder);
  if (r !== 'ok') throw new Error(`b-themes: theme-library card for "${folder}" is ${r}`);
}

/** Wait for the customizer's Alpine component to have rendered its controls. */
async function waitForCustomizerReady(page, expectControls) {
  await page.waitForSelector('#tc-group-library', { visible: true, timeout: T });
  await page.waitForFunction(
    () => Array.from(document.querySelectorAll('button')).some((b) => (b.getAttribute('@click') || '').includes('applyTemplate(')),
    { timeout: T },
  );
  if (expectControls) {
    await page.waitForSelector(COLOR_INPUT, { timeout: T });
    await page.waitForSelector(FONT_SELECT, { timeout: T });
  }
}

/** Drive the real colour swatch + font select (Alpine `set()` via DOM events). */
async function setColorToken(page, hex) {
  const got = await page.$eval(COLOR_INPUT, (el, v) => {
    el.value = v;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return el.value;
  }, hex);
  if (String(got).toLowerCase() !== hex.toLowerCase()) {
    throw new Error(`b-themes: colour input kept "${got}", expected ${hex}`);
  }
}

async function setFontToken(page, pair) {
  await page.select(FONT_SELECT, pair);
  const got = await page.$eval(FONT_SELECT, (el) => el.value);
  if (got !== pair) throw new Error(`b-themes: font select kept "${got}", expected ${pair}`);
}

/** The live customizer schema as the server reports it (field ids + count). */
async function schemaFields(page, rid) {
  const res = await getJson(page, `/theme-customizer/${rid}`);
  if (!res.ok || !res.json) throw new Error(`b-themes: GET /theme-customizer/${rid} -> ${res.status} ${res.text}`);
  const groups = (res.json.schema && res.json.schema.settings) || [];
  const ids = [];
  groups.forEach((g) => (g.fields || []).forEach((f) => f && f.id && ids.push(f.id)));
  return { template: res.json.template, ids, groups: groups.length };
}

/**
 * Read the design tokens the STOREFRONT actually serves, off
 * `document.documentElement` — the WI's contract. getComputedStyle resolves the
 * `:root{…}` block the theme-tokens partial emits.
 */
async function storefrontTokens(page, slug) {
  const resp = await gotoWithRetry(page, localeUrl('/' + slug));
  if (resp && resp.status() >= 400) throw new Error(`b-themes: GET /${slug} -> ${resp.status()}`);
  return page.evaluate(() => {
    const cs = getComputedStyle(document.documentElement);
    return {
      primary: (cs.getPropertyValue('--tc-primary') || '').trim(),
      heading: (cs.getPropertyValue('--tf-heading') || '').trim(),
    };
  });
}

/**
 * The owner-only preview (`?preview_theme=1`) must show the DRAFT: theme_setting()
 * merges the draft into the render ONLY for the authenticated owner under that
 * flag, so the public page and the owner preview must disagree — that pair is
 * the assertion.
 *
 * Asserted on the HTML the preview URL serves rather than on the preview
 * iframe's computed style: the iframe reloads asynchronously after every save,
 * so reading it is a race that is expensive to retry and flaky under FPM
 * contention (WF.1). The served HTML is the same contract the iframe renders,
 * deterministically.
 */
async function previewShowsDraft(page, slug, expectHex, expectNeedle) {
  const r = await page.evaluate(async (s) => {
    const res = await fetch('/' + s + '?preview_theme=1', { credentials: 'same-origin', headers: { Accept: 'text/html' } });
    return { status: res.status, html: await res.text() };
  }, slug);
  if (r.status >= 400) throw new Error(`b-themes: preview /${slug}?preview_theme=1 -> ${r.status}`);
  return {
    primary: (r.html.match(/--tc-primary:(#[0-9a-fA-F]{3,8})/) || [])[1] || '',
    colourOk: r.html.includes(`--tc-primary:${expectHex}`),
    fontOk: !expectNeedle || r.html.includes(expectNeedle),
  };
}

/** The customizer's preview iframe is pointed at the owner preview URL. */
async function previewFrameSrc(page) {
  return page.evaluate(() => {
    const f = Array.from(document.querySelectorAll('iframe')).find((i) => (i.src || '').includes('preview_theme=1'));
    return f ? f.src : '';
  });
}

/** Raw HTML of the PUBLIC storefront, fetched from the owner's own session. */
async function publicHtml(page, slug) {
  return page.evaluate(async (s) => {
    const r = await fetch('/' + s, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
    return { status: r.status, html: await r.text() };
  }, slug);
}

/**
 * Read the storefront until it serves the expected colour + font, then hand the
 * observed values back. Publishing is async and the storefront is a separate
 * request, so the token is waited for rather than slept for. On give-up the
 * last observation is returned and the caller raises a descriptive failure.
 */
async function storefrontTokensPolled(page, slug, expectHex, expectNeedle) {
  return pollFor(async () => {
    const v = await storefrontTokens(page, slug);
    if (normHex(v.primary) !== normHex(expectHex)) return null;
    if (expectNeedle && !v.heading.includes(expectNeedle)) return null;
    return v;
  }, 14, 700);
}

/** Publish: click the real Publish button (it saves any pending draft first). */
async function publishDraft(page) {
  await clickAlpine(page, 'publish()', 'Publish');
}

// ── the journey ──────────────────────────────────────────────────────────────

module.exports = async function bThemes({ page, log, shot }) {
  await page.setViewport({ width: 1440, height: 900 });
  page.setDefaultNavigationTimeout(T);
  collectConsole(page);
  // Track uncaught JS errors ourselves (with the stack) so we can exclude the
  // vendored flipbook page-flip library turn.min.js — it throws its own "b"
  // assertion during preview init on the DISPLAY-ONLY flipbook template (a known
  // third-party quirk, not platform code; the customizer draft/publish/revert
  // still works). Every other pageerror still fails the journey.
  const jsErrors = [];
  page.on('pageerror', (e) => {
    if (/store_templates\/flipbook\/js\/turn(\.min)?\.js/.test(e.stack || '')) {
      log(`b-themes: tolerated flipbook turn.js pageerror ("${e.message}") — vendored flip lib on display-only template`);
      return;
    }
    jsErrors.push(`pageerror: ${e.message}`);
  });
  const resource404s = [];
  const http5xx = [];
  page.on('response', (r) => {
    if (r.status() === 404) resource404s.push(`${new URL(r.url()).pathname} @${new URL(page.url()).pathname}`);
    if (r.status() >= 500) http5xx.push(`${r.status()} ${new URL(r.url()).pathname}`);
  });

  const store = await bootstrapOwnedStore(page, log, shot);
  const { slug, rid } = store;

  // ── 0. the `store_template` hidden input (WI: "must be present") ──────
  // The create form's input is asserted inside bootstrapOwnedStore; the edit
  // form is the one an owner actually returns to when changing a store's look.
  await gotoWithRetry(page, localeUrl(`/stores/${rid}/edit`));
  await assertRtl(page);
  const editHidden = await page.$('form input[name="store_template"]');
  if (!editHidden) throw new Error(`b-themes: /stores/${rid}/edit has no store_template hidden input`);
  const editHiddenType = await page.$eval('form input[name="store_template"]', (el) => el.type);
  log(`b-themes: store_template hidden input present on create + edit (type=${editHiddenType})`);

  // The dashboard theme gallery (user.store.themes) must render. The 19 kept
  // templates are platform templates, not marketplace products, so the switch
  // itself happens in the customizer's Theme library — noted for the run report.
  const gallery = await gotoWithRetry(page, localeUrl('/store/themes'));
  if (gallery && gallery.status() >= 400) throw new Error(`b-themes: GET /store/themes -> ${gallery.status()}`);
  await assertRtl(page);
  log('b-themes: /store/themes renders (dashboard theme gallery)');

  // ── the 19 ───────────────────────────────────────────────────────────────
  const results = [];
  await gotoWithRetry(page, localeUrl(`/theme-customizer?store=${rid}#library`));
  await assertRtl(page);
  await waitForCustomizerReady(page, false);
  const cardCount = await page.$$eval('button', (els) => els.filter((e) => (e.getAttribute('@click') || '').includes('applyTemplate(')).length);
  log(`b-themes: theme library renders ${cardCount} cards`);

  for (const tpl of TEMPLATES) {
    // (1) SWITCH — the real Theme-library button.
    await clickLibraryCard(page, tpl);
    if (!(await waitForTemplateInDb(rid, tpl))) {
      throw new Error(`b-themes: switch to "${tpl}" did not persist (store_template="${await templateInDb(rid)}")`);
    }
    // The storefront must survive the switch — a template that 500s on render is
    // exactly the failure this journey exists to catch.
    const afterSwitch = await gotoWithRetry(page, localeUrl('/' + slug));
    if (afterSwitch && afterSwitch.status() >= 400) {
      throw new Error(`b-themes: GET /${slug} -> ${afterSwitch.status()} after switching to "${tpl}"`);
    }
    await gotoWithRetry(page, localeUrl(`/theme-customizer?store=${rid}#library`));
    await assertRtl(page);

    // (2) OPTIONS RENDER — what this template's schema allows.
    const sch = await schemaFields(page, rid);
    if (sch.template !== tpl) {
      throw new Error(`b-themes: schema reports template "${sch.template}", expected "${tpl}"`);
    }
    if (NO_SCHEMA.has(tpl)) {
      // flipbook: no theme.json → no colour/font controls at all. The WI's
      // carve-out is "no 500 and the options render" — there is nothing to
      // render, and crucially no control may be invented.
      if (sch.ids.length !== 0) throw new Error(`b-themes: ${tpl} unexpectedly exposes ${sch.ids.length} fields`);
      const phantomColor = await page.$(COLOR_INPUT);
      const phantomFont = await page.$(FONT_SELECT);
      if (phantomColor || phantomFont) throw new Error(`b-themes: ${tpl} renders a colour/font control with an empty schema`);
      // The customizer's own endpoints must still answer, not 500.
      const hist = await getJson(page, `/theme-customizer/${rid}/history`);
      if (!hist.ok) throw new Error(`b-themes: ${tpl} GET /history -> ${hist.status} ${hist.text}`);
      const tok = await postJson(page, `/theme-customizer/${rid}/tokens`, { values: {} });
      if (!tok.ok) throw new Error(`b-themes: ${tpl} POST /tokens -> ${tok.status} ${tok.text}`);
      results.push({ tpl, fields: 0, mode: 'no-schema', ok: true });
      log(`b-themes: ${tpl} — schema empty (no colour/font options), no 500, no phantom controls`);
      continue;
    }

    await waitForCustomizerReady(page, true);
    if (!sch.ids.includes('colors.primary')) throw new Error(`b-themes: ${tpl} schema lacks colors.primary`);
    if (!sch.ids.includes('fonts.pair')) throw new Error(`b-themes: ${tpl} schema lacks fonts.pair`);

    // (3) DRAFT — one colour token + one font.
    await setColorToken(page, COLOR_A);
    await setFontToken(page, FONT_A);
    await saveDraftAndWait(page, rid, tpl, COLOR_A);

    // The owner-only preview must show the DRAFT…
    const prev = await previewShowsDraft(page, slug, COLOR_A, NEEDLE_A);
    if (!prev.colourOk) {
      throw new Error(`b-themes: ${tpl} draft NOT shown in the owner preview (--tc-primary="${prev.primary}", expected ${COLOR_A})`);
    }
    if (!prev.fontOk) {
      throw new Error(`b-themes: ${tpl} draft font not shown in the owner preview (missing ${NEEDLE_A})`);
    }
    // …and the iframe the owner is actually looking at is that preview.
    const frameSrc = await previewFrameSrc(page);
    if (!frameSrc.includes('preview_theme=1')) throw new Error(`b-themes: ${tpl} preview iframe is not the owner preview (${frameSrc})`);
    // …and the PUBLIC storefront must NOT (the draft gate is the discriminator).
    const pub = await publicHtml(page, slug);
    if (pub.status >= 400) throw new Error(`b-themes: ${tpl} public /${slug} -> ${pub.status}`);
    if (pub.html.includes(`--tc-primary:${COLOR_A}`)) {
      throw new Error(`b-themes: ${tpl} draft leaked onto the PUBLIC storefront before publish`);
    }

    // (4) PUBLISH → the storefront serves the token.
    await publishDraft(page);
    const pubA = await storefrontTokensPolled(page, slug, COLOR_A, NEEDLE_A);
    if (!pubA) {
      const seen = await storefrontTokens(page, slug);
      throw new Error(`b-themes: ${tpl} storefront --tc-primary="${seen.primary}" / --tf-heading="${seen.heading}" after publishing ${COLOR_A}`);
    }
    const histA = await getJson(page, `/theme-customizer/${rid}/history`);
    if (!histA.ok || !Array.isArray(histA.json.history) || !histA.json.history.length) {
      throw new Error(`b-themes: ${tpl} no version history after the first publish (${histA.text})`);
    }
    const versionA = histA.json.history[histA.json.history.length - 1].id;

    // (5) A SECOND look, then REVERT — proves the storefront tracks publishes
    // both ways and that revert is a real restore, not a no-op.
    // The editor must load its state ONCE per page: Alpine runs init() itself, and the old extra x-init="init()" made a
    // second load() land after the owner's first edit and wipe it (the "second draft did not persist" failure).
    const stateLoads = [];
    const onStateLoad = (req) => {
      if (req.method() === 'GET' && new URL(req.url()).pathname === `/theme-customizer/${rid}`) stateLoads.push(req.url());
    };
    const onNavigated = (frame) => { if (frame === page.mainFrame()) stateLoads.length = 0; }; // count the final page only
    page.on('request', onStateLoad);
    page.on('framenavigated', onNavigated);
    await gotoWithRetry(page, localeUrl(`/theme-customizer?store=${rid}#library`));
    await waitForCustomizerReady(page, true);
    await new Promise((r) => setTimeout(r, 1500));
    page.off('request', onStateLoad);
    page.off('framenavigated', onNavigated);
    if (stateLoads.length !== 1) {
      throw new Error(`b-themes: ${tpl} the customizer loaded its state ${stateLoads.length}x on one page load (expected 1)`);
    }
    // Take the CSRF token while a dashboard page is loaded: the revert below is
    // issued after a storefront visit, and a storefront carries no csrf-token
    // meta (pageview's certainly does not) → reading it there 419s.
    const csrf = await csrfToken(page);
    if (!csrf) throw new Error(`b-themes: ${tpl} no CSRF token on the customizer page`);
    // Every draft POST of this step is recorded (what was sent, what the server answered) so a lost save names its
    // cause instead of a bare "did not persist".
    const draftTrace = traceDraftSaves(page, rid);
    let draftDbB;
    try {
      await setColorToken(page, COLOR_B);
      draftTrace.note('after colour', await customizerState(page));
      await setFontToken(page, FONT_B);
      draftTrace.note('after font', await customizerState(page));
      await clickAlpine(page, 'saveDraft()', 'Save draft');
      // Wait for the draft to land before publishing, so Publish can never race
      // ahead of it and re-publish the FIRST look (which would make the "revert"
      // assertion pass for the wrong reason).
      draftDbB = await pollFor(async () => {
        const s = await settingsInDb(rid, tpl, 'draft');
        return s.includes(COLOR_B) ? s : '';
      });
      if (!draftDbB) {
        draftTrace.note('after poll', await customizerState(page));
        const dbDraft = (await settingsInDb(rid, tpl, 'draft')).slice(0, 300);
        throw new Error(`b-themes: ${tpl} second draft did not persist ${COLOR_B} — db draft: ${dbDraft || '(none)'}; trace: ${draftTrace.report()}`);
      }
    } finally {
      draftTrace.stop();
    }
    await publishDraft(page);
    const pubB = await storefrontTokensPolled(page, slug, COLOR_B, NEEDLE_B);
    if (!pubB) {
      const seen = await storefrontTokens(page, slug);
      throw new Error(`b-themes: ${tpl} storefront --tc-primary="${seen.primary}" / --tf-heading="${seen.heading}" after publishing ${COLOR_B}`);
    }

    // Re-grab the CSRF token from a dashboard page before revert, since
    // storefrontTokensPolled left us on the public storefront which has no CSRF
    // token meta (pageview least of all). The token taken at the beginning of
    // this template's cycle is now stale.
    await gotoWithRetry(page, localeUrl(`/theme-customizer?store=${rid}`));
    const csrfFresh = await csrfToken(page);
    if (!csrfFresh) throw new Error(`b-themes: ${tpl} no CSRF token before revert`);

    const rev = await postJson(page, `/theme-customizer/${rid}/version/${versionA}/revert`, {}, csrfFresh);
    if (!rev.ok) throw new Error(`b-themes: ${tpl} revert to v${versionA} -> ${rev.status} ${rev.text}`);
    const pubR = await storefrontTokensPolled(page, slug, COLOR_A, NEEDLE_A);
    if (!pubR) {
      const seen = await storefrontTokens(page, slug);
      throw new Error(`b-themes: ${tpl} revert to v${versionA} did not restore ${COLOR_A} (--tc-primary="${seen.primary}" / --tf-heading="${seen.heading}")`);
    }

    results.push({
      tpl, fields: sch.ids.length, mode: 'full', ok: true,
      draft: prev.primary, pubA: pubA.primary, pubB: pubB.primary, reverted: pubR.primary,
    });
    log(`b-themes: ${tpl} — ${sch.ids.length} fields; draft ${COLOR_A} → preview ✓ → publish ✓ (${pubA.primary}) → ${COLOR_B} ✓ (${pubB.primary}) → revert ✓ (${pubR.primary})`);

    // Back to the customizer for the next template's switch.
    await gotoWithRetry(page, localeUrl(`/theme-customizer?store=${rid}#library`));
    await waitForCustomizerReady(page, false);
  }

  // A framed screenshot of the last-driven customizer for the VISUAL design-QA
  // pass (RTL, spacing, orphan headings, contrast).
  await shot(page, 'b-themes-customizer-final');

  // ── hygiene: fail ONLY on real page errors / ENFORCED CSP refusals / 5xx ───
  const uniq5xx = [...new Set(http5xx)];
  if (uniq5xx.length) throw new Error(`b-themes: server 5xx (${uniq5xx.length}):\n  ${uniq5xx.slice(0, 10).join('\n  ')}`);
  // ENFORCED CSP refusals come from the collector; JS pageerrors come from our
  // own listener above (which already excludes the vendored flipbook turn.js).
  const collected = (page.__platformConsole && page.__platformConsole.errors) || [];
  const cspRefusals = collected.filter((e) => !/report[\s-]?only/i.test(e) && /^CSP:/.test(e));
  const real = [...cspRefusals, ...jsErrors];
  if (real.length) throw new Error(`b-themes: CSP refusals / JS errors (${real.length}):\n  ${real.join('\n  ')}`);
  const uniq404 = [...new Set(resource404s)];
  log(`b-themes: tolerated ${resource404s.length} resource-404s (${uniq404.length} unique)`);

  const full = results.filter((r) => r.mode === 'full').length;
  const bare = results.length - full;
  log(`b-themes: PASS — SLUG=${slug} RID=${rid} TEMPLATES=${results.length}/19 (full cycle ${full}, schema-limited ${bare})`);
};
