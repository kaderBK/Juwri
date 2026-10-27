// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * B4 — Publish, QR, links (platform E2E, lane B).
 *
 * Owns the owner "get my menu out into the world" seam, driven end-to-end in a
 * real browser in Arabic, then verified by DOM / DB / response-BYTES ground
 * truth. Bootstraps a FRESH owned store on the modern `crave` template every
 * run (B1's proven signup → verify → login → create path, unix-stamped slug), so
 * runs never accumulate cruft and the publish toggle is exercised on a store this
 * journey wholly owns.
 *
 * What it verifies:
 *   1. Publish / unpublish — the store-visibility gate is the owner's `status`
 *      column (StoreController@index: `$store->user->status == 1`). PUBLISHED
 *      (status=1) SERVES the crave menu; UNPUBLISHED (status=0) returns 404 and
 *      the storefront no longer renders the menu (the "not available" state,
 *      the AuroraPublicViewTest precedent). Re-publish serves again. Toggled via
 *      db() on the fresh store's OWN owner, so it never touches another tenant.
 *   2. QR builder page (`restaurants.qrbuilder`) renders (RTL, #qr-code-wrapper).
 *   3. SVG bytes — GET `restaurants.qrSvg` returns image/svg+xml whose body
 *      contains `<svg` (the QR the builder page shows + downloads).
 *   4. PNG bytes — GET `qr.designer.download` returns image/png whose first four
 *      bytes are the PNG magic (89 50 4E 47). The response bytes are asserted,
 *      not merely a 200.
 *   5. QR designer (`qr.designer`) renders and SAVES a design — the POST
 *      persists a `qr_designs` row (DB-verified), the canonical seam every QR
 *      surface then reads through `store_qr_settings()`.
 *   6. Table QR list (`restaurants.tables`) renders.
 *   7. Branch QR (`branches.qr`) renders (a fresh store has one default branch,
 *      so a per-branch QR card is present).
 *   8. Custom-domain settings page (`restaurants.domains.index`) renders — NO
 *      verification is attempted (no `restaurants.domains.verify` POST).
 *
 * Every dashboard page landed on is asserted `dir="rtl" lang="ar"`. Finishes by
 * asserting no uncaught JS errors / no ENFORCED CSP refusals (report-only-safe,
 * matching b-signup.js / b-menu.js).
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

// A minimal but valid opaque 1×1 PNG — a real file on disk for the create form's
// required logo/cover inputs (see b-signup.js / b-menu.js).
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
 * bytes. Mirrors b-signup.js/b-menu.js: only unfold QP soft line-wraps and decode
 * `&amp;`, never the blanket `=XX` hex decode (that corrupts a signed URL's
 * `expires=`/`signature=` — ISS-B-001).
 */
function readVerifyLink(since) {
  const logFile = resolveMailLog();
  if (!logFile || !fs.existsSync(logFile)) throw new Error(`b-publish: mail log not found (${logFile})`);
  const buf = fs.readFileSync(logFile);
  const text = buf.slice(Math.max(0, since || 0)).toString('utf8').replace(/=\r?\n/g, '');
  const urls = (text.match(/https?:\/\/[^\s"'<>)\]]+/g) || [])
    .filter((u) => /\/email\/verify\//.test(u))
    .map((u) => u.replace(/&amp;/g, '&'));
  if (!urls.length) throw new Error('b-publish: no verification link in the mail log since the checkpoint');
  return urls[urls.length - 1];
}

// Set a field's value directly in the DOM then fire input/change so any Alpine
// binding stays in sync (robust for textarea + x-show steps).
async function setField(page, selector, value) {
  const ok = await page.$eval(selector, (el, v) => {
    el.value = v;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }, String(value)).catch(() => false);
  if (!ok) throw new Error(`b-publish: field ${selector} not found on ${page.url()}`);
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
      log(`b-publish: login attempt ${i}/${attempts} failed (${e.message}); retrying`);
      await resetBrowserSession(page).catch(() => {});
      await new Promise((r) => setTimeout(r, 750 * i));
    }
  }
  throw lastErr;
}

/**
 * POST to a dashboard endpoint from the authenticated page context, carrying the
 * real CSRF token — the same contract the dashboard JS uses. Returns
 * `{status, ok, json, text}`; callers verify against db() ground truth.
 */
async function store(page, urlPath, fields) {
  return page.evaluate(async ({ urlPath, fields }) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content
      || document.querySelector('input[name="_token"]')?.value || '';
    const fd = new FormData();
    const add = (k, v) => {
      if (v === null || v === undefined) return;
      if (Array.isArray(v)) v.forEach((x) => fd.append(k + '[]', x));
      else if (typeof v === 'object') Object.entries(v).forEach(([kk, vv]) => fd.append(`${k}[${kk}]`, vv));
      else fd.append(k, v);
    };
    for (const [k, v] of Object.entries(fields)) add(k, v);
    const r = await fetch(urlPath, {
      method: 'POST',
      credentials: 'same-origin',
      redirect: 'manual',
      headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
    });
    let json = null; let text = '';
    try { text = await r.text(); json = JSON.parse(text); } catch (_e) { /* redirect / html */ }
    return { status: r.status, type: r.type, ok: r.ok, json, text: (text || '').slice(0, 240) };
  }, { urlPath, fields });
}

/**
 * Fetch a URL from the authenticated page context and return the RAW bytes'
 * fingerprint: status, content-type/disposition headers, byte length, the first
 * 8 bytes (for a magic-number check) and the first 512 bytes decoded as UTF-8
 * (for a textual `<svg` check). Same-origin credentials so the owner session
 * carries the QR download exactly as a click would.
 */
async function fetchBytes(page, urlPath) {
  return page.evaluate(async (u) => {
    const r = await fetch(u, { credentials: 'same-origin' });
    const ab = await r.arrayBuffer();
    const bytes = new Uint8Array(ab);
    return {
      status: r.status,
      contentType: r.headers.get('content-type') || '',
      disposition: r.headers.get('content-disposition') || '',
      length: bytes.length,
      head: Array.from(bytes.slice(0, 8)),
      textHead: new TextDecoder('utf-8', { fatal: false }).decode(bytes.slice(0, 512)),
    };
  }, urlPath);
}

// Match a category on its ASCII tag (LIKE) — the mysql CLI mangles the Arabic
// name, so never look up by the literal (see b-menu.js).
function catByTag(rid, tag) {
  const r = db('SELECT id FROM {p}categories WHERE store_id = ? AND name LIKE ?', [rid, '%' + tag + '%']);
  return r.length ? Number(r[0][0]) : null;
}

/**
 * Register → verify → login → create a fresh store owned by a new identity, on
 * the modern `crave` template. Returns { slug, rid, userId, email, pass, unix }.
 * Asserts RTL on every dashboard/auth page it lands on.
 */
async function bootstrapOwnedStore(page, log, shot) {
  const id = newIdentity('b', 'publish');
  const unix = (id.email.match(/-(\d+)@/) || [])[1] || String(Date.now());
  const username = ('bp' + unix).slice(0, 16);
  const storeSlug = `e2e-b-pub-${unix}`;
  const storeName = `E2E B Publish ${unix}`;

  // 1) register
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

  const mailSince = mailCheckpoint();
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
  ]);
  if (new URL(page.url()).pathname === '/signup') {
    const err = await page.evaluate(() => (document.querySelector('.invalid-feedback, .alert, [role="alert"]')?.textContent || '').replace(/\s+/g, ' ').trim());
    throw new Error(`b-publish: registration bounced back to /signup${err ? ' — ' + err : ''}`);
  }
  let rows = db('SELECT id FROM {p}users WHERE email = ?', [id.email]);
  if (!rows.length) throw new Error(`b-publish: no user row for ${id.email} after registration`);
  const userId = Number(rows[0][0]);

  // Per-table QR is a plan-gated feature (`plan_feature('table_qr')` → 404 on the tables screen
  // without it), and the platform's default Free plan does not carry it. This journey only checks
  // that the table-QR screen renders for an owner who has it, so grant the entitlement the same way
  // an admin grant does (a feature override row) instead of assuming the default plan includes it.
  db("INSERT INTO {p}entitlement_overrides (user_id, feature_key, value, expires_at, reason, source_type, created_at, updated_at) VALUES (?, 'table_qr', '1', NULL, 'E2E b-publish table QR', 'e2e', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)", [userId]);

  // 2) verify via the mailed link
  const verifyUrl = readVerifyLink(mailSince);
  if (!verifyUrl.startsWith(cfg.baseUrl)) throw new Error(`b-publish: verify link host mismatch (${verifyUrl})`);
  const verifyResp = await page.goto(verifyUrl, { waitUntil: 'domcontentloaded' });
  if (verifyResp && verifyResp.status() >= 400) throw new Error(`b-publish: verification link returned ${verifyResp.status()}`);
  rows = db('SELECT email_verified_at FROM {p}users WHERE id = ?', [userId]);
  if (!rows.length || !rows[0][0] || rows[0][0] === 'NULL') throw new Error('b-publish: email_verified_at still null after verify');
  log(`b-publish: owner ${id.email} (user ${userId}) verified`);

  // 3) fresh login → Arabic dashboard
  await loginWithRetry(page, { email: id.email, pass: id.password }, log);
  await page.goto(localeUrl('/dashboard'), { waitUntil: 'domcontentloaded' });
  await assertRtl(page);

  // 4) create the store on crave
  await page.goto(localeUrl('/stores/create'), { waitUntil: 'domcontentloaded' });
  await assertRtl(page);
  const FORM = 'form[action$="/stores"]';
  await page.waitForSelector(FORM, { timeout: cfg.navTimeout });
  await setField(page, `${FORM} input[name="title"]`, storeName);
  await setField(page, `${FORM} input[name="slug"]`, storeSlug);
  await setField(page, `${FORM} input[name="phone"]`, id.phone);
  await setField(page, `${FORM} input[name="address"]`, 'شارع الملك فهد، الرياض');
  await setField(page, `${FORM} [name="description"]`, 'متجر اختبار النشر ورموز QR على قالب crave.');
  await setField(page, `${FORM} input[name="store_template"]`, 'crave');

  const logoPath = writeTempPng('logo');
  const coverPath = writeTempPng('cover');
  try {
    const logoInput = await page.$(`${FORM} input[name="main_image"]`);
    const coverInput = await page.$(`${FORM} input[name="cover_image"]`);
    if (!logoInput || !coverInput) throw new Error('b-publish: create form missing logo/cover inputs');
    await logoInput.uploadFile(logoPath);
    await coverInput.uploadFile(coverPath);
    await shot(page, 'b-publish-create-form');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
      page.$eval(FORM, (f) => f.submit()),
    ]);
  } finally {
    fs.unlink(logoPath, () => {});
    fs.unlink(coverPath, () => {});
  }

  rows = db('SELECT id, user_id FROM {p}stores WHERE slug = ?', [storeSlug]);
  if (!rows.length) throw new Error(`b-publish: store row for slug "${storeSlug}" was not created`);
  if (String(rows[0][1]) !== String(userId)) throw new Error(`b-publish: store ${storeSlug} owned by ${rows[0][1]}, expected ${userId}`);
  const rid = Number(rows[0][0]);
  const tpl = db("SELECT JSON_UNQUOTE(JSON_EXTRACT(settings, '$.core.store_template')) FROM {p}stores WHERE id = ? AND settings IS NOT NULL", [rid]).filter((r) => r[0] !== 'NULL');
  if (!tpl.length || tpl[0][0] !== 'crave') throw new Error(`b-publish: store template is "${tpl[0] ? tpl[0][0] : '(none)'}", expected crave`);
  log(`b-publish: created store ${rid} slug ${storeSlug} on crave`);
  return { slug: storeSlug, rid, userId, email: id.email, pass: id.password, unix };
}

module.exports = async function bPublish({ page, log, shot }) {
  await page.setViewport({ width: 390, height: 844 });
  collectConsole(page);
  const resource404s = [];
  page.on('response', (r) => {
    if (r.status() === 404) resource404s.push(`${new URL(r.url()).pathname} @${new URL(page.url()).pathname}`);
  });

  const store = await bootstrapOwnedStore(page, log, shot);
  const { slug, rid, userId, unix } = store;

  // Seed one category + one item so a PUBLISHED storefront has real menu content
  // to serve (the ASCII tag survives the mysql CLI and appears in the rendered
  // name, so the storefront assertion is encoding-proof — see b-menu.js).
  const catTag = `PCAT${unix}`;
  const itemTag = `PITM${unix}`;
  await post(page, `/stores/${rid}/add-category`, { name: `القائمة ${catTag}` });
  const catId = catByTag(rid, catTag);
  if (!catId) throw new Error('b-publish: seed category was not created');
  const addItem = await post(page, `/stores/${rid}/add-menu-item`, {
    name: `طبق ${itemTag}`, category_id: catId, price: 20, type: 'veg', active: 1,
  });
  if (addItem.json && addItem.json.success === false) throw new Error(`b-publish: seed item refused: ${addItem.json.message}`);
  const itemRow = db('SELECT id FROM {p}items WHERE store_id = ? AND name LIKE ?', [rid, '%' + itemTag + '%']);
  if (!itemRow.length) throw new Error('b-publish: seed item was not created');
  const itemId = Number(itemRow[0][0]);
  log(`b-publish: seeded category ${catId} + item ${itemId}`);

  // ══════════════════ 2. QR BUILDER page renders ══════════════════
  let resp = await page.goto(localeUrl(`/stores/${rid}/qr-designer`), { waitUntil: 'domcontentloaded' });
  if (!resp || resp.status() !== 200) throw new Error(`b-publish: qr-designer returned ${resp ? resp.status() : 'no response'}`);
  await assertRtl(page);
  const builderMarkers = await page.evaluate(() => ({
    wrapper: !!document.getElementById('qr-code-wrapper'),
    img: !!document.getElementById('qr-img'),
    download: !!document.getElementById('qr-download'),
  }));
  if (!builderMarkers.wrapper || !builderMarkers.img) {
    throw new Error(`b-publish: qr-designer page did not render the QR wrapper/img (${JSON.stringify(builderMarkers)})`);
  }
  await shot(page, 'b-publish-qr-designer');
  log(`b-publish: QR builder renders (wrapper+img+download=${JSON.stringify(builderMarkers)})`);

  // ══════════════════ 3. SVG bytes (restaurants.qrSvg) ══════════════════
  const svg = await fetchBytes(page, `/stores/${rid}/qr-svg?fg=%230a0a0a&bg=%23ffffff&padding=2`);
  if (svg.status !== 200) throw new Error(`b-publish: qr-svg returned ${svg.status}`);
  if (!/image\/svg\+xml/i.test(svg.contentType)) throw new Error(`b-publish: qr-svg content-type is "${svg.contentType}", expected image/svg+xml`);
  if (!svg.textHead.includes('<svg')) throw new Error(`b-publish: qr-svg body does not contain <svg (head="${svg.textHead.slice(0, 80)}")`);
  log(`b-publish: SVG bytes OK — ${svg.length}B, ${svg.contentType}, contains <svg`);

  // ══════════════════ 4. PNG bytes (qr.designer.download) ══════════════════
  const png = await fetchBytes(page, `/qr-designer/download?store_id=${rid}`);
  if (png.status !== 200) throw new Error(`b-publish: qr download returned ${png.status}`);
  if (!/image\/png/i.test(png.contentType)) throw new Error(`b-publish: qr PNG content-type is "${png.contentType}", expected image/png`);
  // PNG magic number: 89 50 4E 47 0D 0A 1A 0A.
  const magic = [0x89, 0x50, 0x4e, 0x47];
  if (!magic.every((b, i) => png.head[i] === b)) {
    throw new Error(`b-publish: qr PNG magic bytes wrong — got [${png.head.join(',')}], expected [${magic.join(',')}...]`);
  }
  if (png.length < 100) throw new Error(`b-publish: qr PNG suspiciously small (${png.length}B)`);
  if (!/attachment/i.test(png.disposition)) throw new Error(`b-publish: qr PNG is not an attachment download (disposition="${png.disposition}")`);
  log(`b-publish: PNG bytes OK — ${png.length}B, magic ${png.head.slice(0, 4).map((b) => b.toString(16)).join(' ')}, ${png.disposition}`);

  // ══════════════════ 5. QR DESIGNER page + SAVE (db-verified) ══════════════════
  resp = await page.goto(localeUrl(`/qr-designer?store_id=${rid}`), { waitUntil: 'domcontentloaded' });
  if (!resp || resp.status() !== 200) throw new Error(`b-publish: qr-designer returned ${resp ? resp.status() : 'no response'}`);
  await assertRtl(page);
  const designerRendered = await page.evaluate(() => document.body.innerHTML.includes('qrDesigner(') && !!document.querySelector('svg'));
  if (!designerRendered) throw new Error('b-publish: qr-designer page did not render the designer (qrDesigner x-data + preview svg)');
  await shot(page, 'b-publish-qr-designer');

  const designName = `E2E QR ${unix}`;
  // store_id must be in the QUERY string — ResolvesCurrentStore reads
  // $request->query('store_id'), not the body. Black-on-white so the save's
  // QrScanGuard::verify passes (a low-contrast design is refused with 422).
  const saveRes = await post(page, `/qr-designer?store_id=${rid}`, {
    name: designName, fg_color: '#0a0a0a', bg_color: '#ffffff', eye_color: '#0a0a0a',
  });
  if (!(saveRes.json && saveRes.json.ok === true)) {
    throw new Error(`b-publish: qr-designer save did not return ok:true (status ${saveRes.status}, body ${JSON.stringify(saveRes.json || saveRes.text)})`);
  }
  const design = db('SELECT id, is_default, fg_color, bg_color FROM {p}qr_designs WHERE store_id = ?', [rid]);
  if (!design.length) throw new Error('b-publish: qr_designs row was not persisted after save');
  if (String(design[0][1]) !== '1') throw new Error(`b-publish: saved design is not the default (is_default=${design[0][1]})`);
  if (String(design[0][2]).toLowerCase() !== '#0a0a0a') throw new Error(`b-publish: saved design fg_color is "${design[0][2]}", expected #0a0a0a`);
  log(`b-publish: QR designer saved design ${design[0][0]} (fg=${design[0][2]} bg=${design[0][3]}, default)`);

  // ══════════════════ 6. TABLE QR list renders ══════════════════
  resp = await page.goto(localeUrl(`/stores/${rid}/tables`), { waitUntil: 'domcontentloaded' });
  if (!resp || resp.status() !== 200) throw new Error(`b-publish: tables page returned ${resp ? resp.status() : 'no response'} (table_qr entitlement?)`);
  await assertRtl(page);
  const tablesRendered = await page.evaluate(() => document.body.innerHTML.includes('tablesPage'));
  if (!tablesRendered) throw new Error('b-publish: tables page did not render the tables manager (tablesPage x-data)');
  await shot(page, 'b-publish-tables');
  log('b-publish: table QR list renders');

  // ══════════════════ 7. BRANCH QR renders ══════════════════
  resp = await page.goto(localeUrl(`/branches/qr/${rid}`), { waitUntil: 'domcontentloaded' });
  if (!resp || resp.status() !== 200) throw new Error(`b-publish: branch QR returned ${resp ? resp.status() : 'no response'}`);
  await assertRtl(page);
  // A fresh store carries one default branch → one per-branch QR card, each an
  // <img> pointing at restaurants.qrSvg?branch_id=.
  const branchQr = await page.evaluate(() => ({
    cards: document.querySelectorAll('.branch-qr-card').length,
    qrImg: !!document.querySelector('img[src*="qr-svg"][src*="branch_id"]'),
  }));
  if (!branchQr.qrImg) throw new Error(`b-publish: branch QR page rendered no per-branch QR image (${JSON.stringify(branchQr)})`);
  await shot(page, 'b-publish-branch-qr');
  log(`b-publish: branch QR renders (${branchQr.cards} card(s))`);

  // ══════════════════ 8. CUSTOM DOMAIN settings render (no verify) ══════════════════
  resp = await page.goto(localeUrl(`/stores/${rid}/domains`), { waitUntil: 'domcontentloaded' });
  if (!resp || resp.status() !== 200) throw new Error(`b-publish: domains page returned ${resp ? resp.status() : 'no response'} (custom_domain gate?)`);
  await assertRtl(page);
  const domainsForm = await page.evaluate(() => ({
    input: !!document.getElementById('domain-input'),
    // the add-domain form posts to restaurants.domains.store — assert its presence
    // but the journey NEVER submits it (no domain verification attempted).
    form: !!document.querySelector('form[action*="/domains"]'),
  }));
  if (!domainsForm.input || !domainsForm.form) {
    throw new Error(`b-publish: custom-domain settings page did not render the add-domain form (${JSON.stringify(domainsForm)})`);
  }
  await shot(page, 'b-publish-domains');
  log('b-publish: custom-domain settings render (no verification attempted)');

  // ══════════════════ 1. PUBLISH / UNPUBLISH seam (last; anonymous storefront) ══════════════════
  // The storefront is a public, anonymous surface — drop the owner session so the
  // gate is exercised exactly as a diner hits it. A cache-buster defeats the
  // storefront's public max-age so the status flip is observed immediately.
  await resetBrowserSession(page);
  const cb = () => `?nc=${Date.now()}-${Math.random().toString(36).slice(2)}`;

  const assertServes = async (label) => {
    const r = await page.goto(localeUrl('/' + slug) + cb(), { waitUntil: 'domcontentloaded' });
    if (!r || r.status() !== 200) throw new Error(`b-publish: ${label} — storefront returned ${r ? r.status() : 'no response'}, expected 200`);
    await assertRtl(page);
    await page.waitForSelector('#crave.crave', { timeout: cfg.navTimeout });
    // crave builds the grid cards from window.CRAVE.items AFTER load — wait for the
    // DOM cards, not just the data, or the assertion races the render.
    await page.waitForFunction(
      () => window.CRAVE && window.CRAVE.items && document.querySelectorAll('#cvGrid [data-open]').length > 0,
      { timeout: cfg.navTimeout },
    );
    const served = await page.evaluate((tag) => {
      const items = (window.CRAVE && window.CRAVE.items) || {};
      const inData = Object.values(items).some((it) => String(it.name || '').includes(tag));
      const inGrid = Array.from(document.querySelectorAll('#cvGrid .card .card-name')).some((e) => e.textContent.includes(tag));
      return { inData, inGrid };
    }, itemTag);
    if (!served.inData || !served.inGrid) throw new Error(`b-publish: ${label} — published menu did not serve the seeded item (${JSON.stringify(served)})`);
  };

  // PUBLISHED (status=1, the signup default): serves.
  await assertServes('published');
  await shot(page, 'b-publish-storefront-published');
  log('b-publish: PUBLISHED storefront serves the crave menu');

  // UNPUBLISH via the owner status gate (StoreController@index: user->status==1).
  db('UPDATE {p}users SET status = 0 WHERE id = ?', [userId]);
  const unpub = await page.goto(localeUrl('/' + slug) + cb(), { waitUntil: 'domcontentloaded' });
  const unpubStatus = unpub ? unpub.status() : 0;
  if (unpubStatus !== 404) {
    // restore before throwing so a failed run never leaves the owner disabled
    db('UPDATE {p}users SET status = 1 WHERE id = ?', [userId]);
    throw new Error(`b-publish: UNPUBLISHED storefront returned ${unpubStatus}, expected 404`);
  }
  const menuGone = await page.evaluate(() => !document.querySelector('#crave.crave'));
  if (!menuGone) {
    db('UPDATE {p}users SET status = 1 WHERE id = ?', [userId]);
    throw new Error('b-publish: UNPUBLISHED storefront still rendered the crave menu');
  }
  await shot(page, 'b-publish-storefront-unpublished');
  log(`b-publish: UNPUBLISHED storefront no longer serves the menu (HTTP ${unpubStatus})`);

  // RE-PUBLISH: serves again.
  db('UPDATE {p}users SET status = 1 WHERE id = ?', [userId]);
  await assertServes('re-published');
  log('b-publish: RE-PUBLISHED storefront serves the menu again');

  // ── hygiene: fail ONLY on real page errors / ENFORCED CSP refusals ──────────
  // Report-only CSP violations and resource 404s are advisory (ISS-B-005). The
  // storefront 404 leg intentionally produces one navigation 404, already scoped
  // out of resource404s (that hook records sub-resource 404s, keyed by the page
  // pathname; a top-level navigation status is not a resource load).
  const collected = (page.__platformConsole && page.__platformConsole.errors) || [];
  const real = collected.filter((e) => !/report[\s-]?only/i.test(e) && /^(pageerror:|CSP:)/.test(e));
  if (real.length) throw new Error(`b-publish: CSP refusals / JS errors (${real.length}):\n  ${real.join('\n  ')}`);
  const uniq404 = [...new Set(resource404s)];
  log(`b-publish: tolerated ${resource404s.length} resource-404s (${uniq404.length} unique)`);
  log(`b-publish: PASS — SLUG=${slug} RID=${rid}`);
};
