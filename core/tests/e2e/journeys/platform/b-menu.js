// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * B2 — Menu build → storefront reflects (platform E2E, lane B).
 *
 * Owns the whole owner menu-build seam, driven end-to-end in a real browser in
 * Arabic, then verified on the customer storefront by DOM/JS ground truth.
 *
 * REPEATABLE: every run bootstraps a FRESH owned store (B1's proven
 * signup → verify → login → create path, unix-stamped slug) on the modern
 * `crave` template, so runs never accumulate cruft and never depend on shared
 * demo data. The build then drives the REAL dashboard controllers
 * (User\StoreController + Concerns\ManagesMenu, PriceScheduleController,
 * MediaController) through authenticated same-origin fetches carrying the real
 * CSRF token — the same server contract the dashboard UI posts — and asserts
 * every write against DB ground truth via db().
 *
 * What it builds on the store:
 *   • 3 categories (A "mains", B "secret" → HIDDEN, C "desserts"). Three, not
 *     two, so the storefront can prove the documented "menu-skips-cats" bug is
 *     absent: hiding the MIDDLE category must not drop the one that follows it.
 *   • a real image uploaded through the media library (multipart, lands in the
 *     repo-root storage/menu/ and attaches to an item — ISS trap: the picker
 *     stores in storage/menu/ regardless of the folder tag).
 *   • 5 items — one with a REQUIRED modifier group (crave variant selector) + an
 *     OPTIONAL group (extras), one out-of-stock, one with allergens, one with a
 *     runtime discount (an always-on item PriceSchedule → original_price), plus
 *     one filler in the hidden category so hiding it is observable.
 *   • categories reordered (reverse), then the middle category hidden.
 *
 * Storefront (`GET /{slug}`, crave, db()-free DOM/JS assertions):
 *   • visible items render (window.CRAVE.items + grid cards),
 *   • the HIDDEN category is absent AND the category that follows it is NOT
 *     skipped (chip order),
 *   • the out-of-stock badge (.card-soldout) shows,
 *   • the REQUIRED + OPTIONAL modifier groups render on the item,
 *   • the uploaded image is attached, the discount strikes through.
 *
 * Every dashboard page landed on is asserted `dir="rtl" lang="ar"`. Finishes by
 * asserting no uncaught JS errors / no ENFORCED CSP refusals (report-only-safe).
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
  siteCoreDir,
} = require('../../lib/platform');

// A minimal but valid opaque 1×1 PNG — a real file on disk, so the create form's
// required logo/cover inputs and the media library's `image` validation are
// satisfied without committing a binary fixture (see b-signup.js).
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
 * bytes. Mirrors b-signup.js's readVerifyLink: only unfold QP soft line-wraps
 * and decode `&amp;`, never the blanket `=XX` hex decode (that corrupts a signed
 * URL's `expires=`/`signature=` — ISS-B-001).
 */
function readVerifyLink(since) {
  const logFile = resolveMailLog();
  if (!logFile || !fs.existsSync(logFile)) throw new Error(`b-menu: mail log not found (${logFile})`);
  const buf = fs.readFileSync(logFile);
  const text = buf.slice(Math.max(0, since || 0)).toString('utf8').replace(/=\r?\n/g, '');
  const urls = (text.match(/https?:\/\/[^\s"'<>)\]]+/g) || [])
    .filter((u) => /\/email\/verify\//.test(u))
    .map((u) => u.replace(/&amp;/g, '&'));
  if (!urls.length) throw new Error('b-menu: no verification link in the mail log since the checkpoint');
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
  if (!ok) throw new Error(`b-menu: field ${selector} not found on ${page.url()}`);
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
      log(`b-menu: login attempt ${i}/${attempts} failed (${e.message}); retrying`);
      await resetBrowserSession(page).catch(() => {});
      await new Promise((r) => setTimeout(r, 750 * i));
    }
  }
  throw lastErr;
}

/**
 * POST to a dashboard endpoint from the authenticated page context, carrying the
 * real CSRF token — the same contract the dashboard JS uses. Arrays post as
 * `k[]`, plain objects as `k[sub]`. Returns `{status, ok, json, text}`; the
 * caller VERIFIES every write against db() ground truth, so a back()-redirect
 * (opaque under redirect:'manual') is not itself treated as failure.
 */
async function store(page, urlPath, fields) {
  const res = await page.evaluate(async ({ urlPath, fields }) => {
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
  return res;
}

// ── store bootstrap (B1's proven path) ───────────────────────────────────────

/**
 * Register → verify → login → create a fresh store owned by a new identity, on
 * the modern `crave` template. Returns { slug, rid, userId, email, pass }.
 * Asserts RTL on every dashboard/auth page it lands on.
 */
async function bootstrapOwnedStore(page, log, shot) {
  const id = newIdentity('b', 'menu');
  const unix = (id.email.match(/-(\d+)@/) || [])[1] || String(Date.now());
  const username = ('bm' + unix).slice(0, 16);
  const storeSlug = `e2e-b-menu-${unix}`;
  const storeName = `E2E B Menu ${unix}`;

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
    throw new Error(`b-menu: registration bounced back to /signup${err ? ' — ' + err : ''}`);
  }
  let rows = db('SELECT id, email_verified_at FROM {p}users WHERE email = ?', [id.email]);
  if (!rows.length) throw new Error(`b-menu: no user row for ${id.email} after registration`);
  const userId = rows[0][0];

  // 2) verify via the mailed link
  const verifyUrl = readVerifyLink(mailSince);
  if (!verifyUrl.startsWith(cfg.baseUrl)) throw new Error(`b-menu: verify link host mismatch (${verifyUrl})`);
  const verifyResp = await page.goto(verifyUrl, { waitUntil: 'domcontentloaded' });
  if (verifyResp && verifyResp.status() >= 400) throw new Error(`b-menu: verification link returned ${verifyResp.status()}`);
  rows = db('SELECT email_verified_at FROM {p}users WHERE id = ?', [userId]);
  if (!rows.length || !rows[0][0] || rows[0][0] === 'NULL') throw new Error('b-menu: email_verified_at still null after verify');
  log(`b-menu: owner ${id.email} (user ${userId}) verified`);

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
  await setField(page, `${FORM} [name="description"]`, 'متجر اختبار بناء القائمة على قالب crave.');
  // Pin the template to the modern in-scope `crave` (the lane default is the
  // legacy Ice-cream-theme, out of scope per HANDOFF §4).
  await setField(page, `${FORM} input[name="store_template"]`, 'crave');

  const logoPath = writeTempPng('logo');
  const coverPath = writeTempPng('cover');
  try {
    const logoInput = await page.$(`${FORM} input[name="main_image"]`);
    const coverInput = await page.$(`${FORM} input[name="cover_image"]`);
    if (!logoInput || !coverInput) throw new Error('b-menu: create form missing logo/cover inputs');
    await logoInput.uploadFile(logoPath);
    await coverInput.uploadFile(coverPath);
    await shot(page, 'b-menu-create-form');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
      page.$eval(FORM, (f) => f.submit()),
    ]);
  } finally {
    fs.unlink(logoPath, () => {});
    fs.unlink(coverPath, () => {});
  }

  rows = db('SELECT id, user_id FROM {p}stores WHERE slug = ?', [storeSlug]);
  if (!rows.length) throw new Error(`b-menu: store row for slug "${storeSlug}" was not created`);
  if (String(rows[0][1]) !== String(userId)) throw new Error(`b-menu: store ${storeSlug} owned by ${rows[0][1]}, expected ${userId}`);
  const rid = Number(rows[0][0]);
  // Confirm the template actually persisted to crave.
  const tpl = db("SELECT JSON_UNQUOTE(JSON_EXTRACT(settings, '$.core.store_template')) FROM {p}stores WHERE id = ? AND settings IS NOT NULL", [rid]).filter((r) => r[0] !== 'NULL');
  if (!tpl.length || tpl[0][0] !== 'crave') throw new Error(`b-menu: store template is "${tpl[0] ? tpl[0][0] : '(none)'}", expected crave`);
  log(`b-menu: created store ${rid} slug ${storeSlug} on crave`);
  return { slug: storeSlug, rid, userId, email: id.email, pass: id.password, unix };
}

// ── DB helpers (prefix-aware via db()) ───────────────────────────────────────

// Match on the ASCII tag (LIKE) — never the Arabic name (the CLI mangles it).
// Only ASCII/numeric columns are selected back, so the output is never mangled.
function catByTag(rid, tag) {
  const r = db('SELECT id, position, active FROM {p}categories WHERE store_id = ? AND name LIKE ?', [rid, '%' + tag + '%']);
  return r.length ? { id: Number(r[0][0]), position: Number(r[0][1]), active: r[0][2] } : null;
}
function itemByTag(rid, tag) {
  const r = db('SELECT id, category_id, is_sold_out, active, image, allergies FROM {p}items WHERE store_id = ? AND name LIKE ?', [rid, '%' + tag + '%']);
  return r.length ? { id: Number(r[0][0]), category_id: Number(r[0][1]), is_sold_out: r[0][2], active: r[0][3], image: r[0][4], allergies: r[0][5] } : null;
}

// ── the journey ──────────────────────────────────────────────────────────────

module.exports = async function bMenu({ page, log, shot, sleep }) {
  await page.setViewport({ width: 390, height: 844 });
  collectConsole(page);
  const resource404s = [];
  page.on('response', (r) => {
    if (r.status() === 404) resource404s.push(`${new URL(r.url()).pathname} @${new URL(page.url()).pathname}`);
  });

  const store = await bootstrapOwnedStore(page, log, shot);
  const { slug, rid, unix } = store;

  // Every name carries a distinct ASCII tag. The names render in Arabic (the run
  // is ar), but the lane's mysql CLI mangles Arabic to '?' on both input and
  // output, so db() lookups match on the ASCII tag (LIKE) and never on the
  // Arabic literal; the storefront DOM assertions match the same tag (it is part
  // of the rendered name), which is encoding-proof.
  const T = {
    catA: `CATA${unix}`, catB: `CATB${unix}`, catC: `CATC${unix}`,
    item1: `ITM1${unix}`, item2: `ITM2${unix}`, item3: `ITM3${unix}`, item4: `ITM4${unix}`, item5: `ITM5${unix}`,
    size: `SIZE${unix}`, ex1: `EXA${unix}`, ex2: `EXB${unix}`,
  };
  const N = {
    catA: `الأطباق الرئيسية ${T.catA}`,
    catB: `قسم مخفي ${T.catB}`,         // hidden
    catC: `الحلويات ${T.catC}`,
    item1: `برجر مميز ${T.item1}`,      // required + optional modifier groups
    item2: `كنافة نافدة ${T.item2}`,    // out of stock
    item3: `سلطة الحساسية ${T.item3}`,  // allergens
    item4: `بيتزا مخفضة ${T.item4}`,    // discount
    item5: `طبق مخفي ${T.item5}`,       // filler in the hidden category
    sizeGroup: `الحجم ${T.size}`,
    extra1: `جبنة إضافية ${T.ex1}`,
    extra2: `صلصة حارة ${T.ex2}`,
  };

  // Land on the menu editor: a dashboard page (csrf meta) scoped to this store.
  await page.goto(localeUrl(`/stores/${rid}/menu`), { waitUntil: 'domcontentloaded' });
  await assertRtl(page);
  await shot(page, 'b-menu-editor');

  // ── 1. upload an image via the media library (real multipart) ──────────────
  await page.evaluate(() => {
    if (!document.getElementById('__e2eFile')) {
      const inp = document.createElement('input');
      inp.type = 'file'; inp.id = '__e2eFile';
      inp.style.cssText = 'position:fixed;left:-9999px;top:0';
      document.body.appendChild(inp);
    }
  });
  const pngPath = writeTempPng('item');
  let uploadedFilename;
  try {
    const fileHandle = await page.$('#__e2eFile');
    await fileHandle.uploadFile(pngPath);
    const media = await page.evaluate(async (storeId) => {
      const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
      const inp = document.getElementById('__e2eFile');
      const f = inp.files[0];
      const fd = new FormData();
      fd.append('store_id', String(storeId));
      fd.append('folder', 'menu');
      fd.append('images[]', f, f.name);
      const r = await fetch('/media', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
      });
      const j = await r.json().catch(() => ({}));
      return { status: r.status, assets: j.assets || [] };
    }, rid);
    if (media.status >= 400 || !media.assets.length || !media.assets[0].filename) {
      throw new Error(`b-menu: media upload failed (status ${media.status}, assets ${JSON.stringify(media.assets)})`);
    }
    uploadedFilename = media.assets[0].filename;
  } finally {
    fs.unlink(pngPath, () => {});
  }
  // The picker stores in the repo-root storage/menu/ regardless of the folder
  // tag (HANDOFF §9 trap). Assert the byte file landed there AND the MediaAsset
  // row exists.
  const storageMenu = path.resolve(siteCoreDir(), '..', 'storage/menu', uploadedFilename);
  if (!fs.existsSync(storageMenu)) throw new Error(`b-menu: uploaded image not found at repo-root storage/menu/${uploadedFilename}`);
  const assetRow = db('SELECT id, folder FROM {p}media_assets WHERE store_id = ? AND filename = ?', [rid, uploadedFilename]);
  if (!assetRow.length) throw new Error(`b-menu: no media_assets row for ${uploadedFilename}`);
  log(`b-menu: uploaded ${uploadedFilename} → storage/menu/ (asset ${assetRow[0][0]}, folder=${assetRow[0][1]})`);

  // ── 2. create 3 categories (A, B→hidden, C) ────────────────────────────────
  for (const name of [N.catA, N.catB, N.catC]) {
    await post(page, `/stores/${rid}/add-category`, { name });
  }
  const catA = catByTag(rid, T.catA);
  const catB = catByTag(rid, T.catB);
  const catC = catByTag(rid, T.catC);
  if (!catA || !catB || !catC) throw new Error(`b-menu: categories not all created (A=${!!catA} B=${!!catB} C=${!!catC})`);
  log(`b-menu: categories A=${catA.id} B=${catB.id} C=${catC.id}`);

  // ── 3. create 5 items ──────────────────────────────────────────────────────
  const allergyRows = db('SELECT id FROM {p}allergies WHERE store_id IS NULL ORDER BY id LIMIT 2');
  const allergyIds = allergyRows.map((r) => Number(r[0]));
  if (allergyIds.length < 2) throw new Error('b-menu: fewer than 2 global allergies available to attach');

  const items = [
    { name: N.item1, category_id: catA.id, price: 30, type: 'nonveg', active: 1, image: uploadedFilename, description: 'برجر مع خيارات.' },
    { name: N.item4, category_id: catA.id, price: 50, type: 'veg', active: 1 },
    { name: N.item5, category_id: catB.id, price: 20, type: 'veg', active: 1 },
    { name: N.item2, category_id: catC.id, price: 25, type: 'veg', active: 1, is_sold_out: 1 },
    { name: N.item3, category_id: catC.id, price: 15, type: 'veg', active: 1, allergies: allergyIds },
  ];
  for (const it of items) {
    const res = await post(page, `/stores/${rid}/add-menu-item`, it);
    if (res.json && res.json.success === false) {
      throw new Error(`b-menu: add-menu-item "${it.name}" refused: ${res.json.message}`);
    }
  }
  const item1 = itemByTag(rid, T.item1);
  const item2 = itemByTag(rid, T.item2);
  const item3 = itemByTag(rid, T.item3);
  const item4 = itemByTag(rid, T.item4);
  const item5 = itemByTag(rid, T.item5);
  for (const [k, v] of Object.entries({ item1, item2, item3, item4, item5 })) {
    if (!v) throw new Error(`b-menu: ${k} was not created`);
  }
  // DB ground truth on the feature flags.
  if (String(item2.is_sold_out) !== '1') throw new Error(`b-menu: item2 is_sold_out not set (got ${item2.is_sold_out})`);
  if (item1.image !== uploadedFilename) throw new Error(`b-menu: item1.image is "${item1.image}", expected ${uploadedFilename}`);
  if (!item3.allergies || item3.allergies.split(',').filter(Boolean).length < 2) throw new Error(`b-menu: item3 allergies not attached (got "${item3.allergies}")`);
  log(`b-menu: items 1=${item1.id} 2=${item2.id}(soldout) 3=${item3.id}(allergens) 4=${item4.id} 5=${item5.id}`);

  // ── 4. item1 modifiers: a REQUIRED variant group + an OPTIONAL extras group ─
  // Variant option group (defines the choice labels the crave "Required" variant
  // selector renders), then two priced variants referencing it, then two extras.
  await post(page, `/stores/${rid}/menu/${item1.id}/extras/add-variant-option`, {
    title: N.sizeGroup, options: 'صغير,وسط,كبير',
  });
  const voRow = db("SELECT id FROM {p}modifier_groups WHERE kind = 'variant' AND menu_id = ? AND title LIKE ?", [item1.id, '%' + T.size + '%']);
  if (!voRow.length) throw new Error('b-menu: variant option group not created on item1');
  const voId = Number(voRow[0][0]);
  await post(page, `/stores/${rid}/menu/${item1.id}/extras/add-variant`, { price: 30, options: { [voId]: 0 } });
  await post(page, `/stores/${rid}/menu/${item1.id}/extras/add-variant`, { price: 34, options: { [voId]: 1 } });
  await post(page, `/stores/${rid}/menu/${item1.id}/extras/add-extra`, { title: N.extra1, price: 5 });
  await post(page, `/stores/${rid}/menu/${item1.id}/extras/add-extra`, { title: N.extra2, price: 3 });

  const variantCount = Number(db('SELECT COUNT(*) FROM {p}menu_variants WHERE menu_id = ?', [item1.id])[0][0]);
  const extraCount = Number(db("SELECT COUNT(*) FROM {p}modifiers WHERE kind = 'addon' AND menu_id = ?", [item1.id])[0][0]);
  if (variantCount < 2) throw new Error(`b-menu: expected ≥2 variants on item1, got ${variantCount}`);
  if (extraCount < 2) throw new Error(`b-menu: expected ≥2 extras on item1, got ${extraCount}`);
  log(`b-menu: item1 modifiers — ${variantCount} variants (required group), ${extraCount} extras (optional group)`);

  // ── 5. discount on item4: an always-on item PriceSchedule (20% off) ─────────
  await post(page, `/schedules?store_id=${rid}`, {
    name: `تخفيض ${unix}`, scope: 'item', adjustment_type: 'percent',
    adjustment_value: 20, active: 1, target_ids: [item4.id],
  });
  const schedRow = db('SELECT id FROM {p}price_schedules WHERE store_id = ? AND scope = ? AND active = 1', [rid, 'item']);
  if (!schedRow.length) throw new Error('b-menu: item price schedule (discount) not created');
  const schedId = Number(schedRow[0][0]);
  const targetRow = db('SELECT id FROM {p}price_schedule_targets WHERE schedule_id = ? AND target_type = ? AND target_id = ?', [schedId, 'item', item4.id]);
  if (!targetRow.length) throw new Error('b-menu: discount schedule not targeted at item4');
  log(`b-menu: discount schedule ${schedId} → item4 (20% off)`);

  // ── 6. reorder categories (reverse: C, B, A) ───────────────────────────────
  await post(page, `/stores/${rid}/reorder-category`, { position: [catC.id, catB.id, catA.id] });
  const posAfter = {
    C: catByTag(rid, T.catC).position,
    B: catByTag(rid, T.catB).position,
    A: catByTag(rid, T.catA).position,
  };
  if (!(posAfter.C < posAfter.B && posAfter.B < posAfter.A)) {
    throw new Error(`b-menu: reorder did not take effect (positions ${JSON.stringify(posAfter)})`);
  }
  log(`b-menu: reordered categories to C(${posAfter.C}) < B(${posAfter.B}) < A(${posAfter.A})`);

  // ── 7. hide the MIDDLE category (B) ────────────────────────────────────────
  await post(page, `/stores/${rid}/update-category`, { id: catB.id, name: N.catB, active: 0 });
  const catBHidden = catByTag(rid, T.catB);
  if (String(catBHidden.active) !== '0') throw new Error(`b-menu: category B not hidden (active=${catBHidden.active})`);
  log(`b-menu: hid category B (${catB.id}) — active=0`);

  // ═══════════════ STOREFRONT: db()-free DOM/JS ground truth ═════════════════
  const storeResp = await page.goto(localeUrl('/' + slug), { waitUntil: 'domcontentloaded' });
  if (storeResp && storeResp.status() >= 400) throw new Error(`b-menu: storefront /${slug} returned ${storeResp.status()}`);
  await assertRtl(page);
  // crave root + the grid populated from window.CRAVE.items.
  await page.waitForSelector('#crave.crave', { timeout: cfg.navTimeout });
  await page.waitForFunction(
    () => window.CRAVE && window.CRAVE.items && document.querySelectorAll('#cvGrid [data-open]').length > 0,
    { timeout: cfg.navTimeout },
  );
  await shot(page, 'b-menu-storefront');

  // (a) VISIBLE items render; the hidden category's item does NOT. Matched by
  // the ASCII tag embedded in each rendered name.
  const payload = await page.evaluate((tags) => {
    const items = (window.CRAVE && window.CRAVE.items) || {};
    const gridNames = Array.from(document.querySelectorAll('#cvGrid .card .card-name')).map((e) => e.textContent);
    const byTag = {};
    for (const k in items) {
      const it = items[k];
      for (const key in tags) if (String(it.name || '').includes(tags[key])) byTag[key] = it;
    }
    const gridHasTag = {};
    for (const key in tags) gridHasTag[key] = gridNames.some((n) => n.includes(tags[key]));
    return { byTag, gridHasTag, cardCount: gridNames.length };
  }, T);
  for (const key of ['item1', 'item2', 'item3', 'item4']) {
    if (!payload.byTag[key]) throw new Error(`b-menu: visible ${key} missing from window.CRAVE.items`);
    if (!payload.gridHasTag[key]) throw new Error(`b-menu: visible ${key} not rendered in #cvGrid`);
  }
  if (payload.byTag.item5) throw new Error('b-menu: hidden-category item5 leaked onto the storefront');
  log(`b-menu: ${payload.cardCount} cards render; hidden-category item absent`);

  // (b) HIDDEN category absent AND the FOLLOWING category not skipped.
  // Server-rendered chips (#cvChips .chip[data-cat]) in category-position order.
  const chips = await page.evaluate(() => Array.from(document.querySelectorAll('#cvChips .chip[data-cat]'))
    .filter((c) => c.dataset.cat !== 'all')
    .map((c) => ({ id: c.dataset.cat, name: c.textContent.replace(/\s+/g, ' ').trim() })));
  const chipNames = chips.map((c) => c.name);
  const hasChip = (tag) => chipNames.some((c) => c.includes(tag));
  if (hasChip(T.catB)) throw new Error(`b-menu: hidden category (${T.catB}) still has a chip (${JSON.stringify(chipNames)})`);
  if (!hasChip(T.catA)) throw new Error(`b-menu: category A (${T.catA}) chip missing (${JSON.stringify(chipNames)})`);
  if (!hasChip(T.catC)) throw new Error(`b-menu: category C (${T.catC}) chip missing (${JSON.stringify(chipNames)})`);
  // Order proves no skip: reordered [C, B, A]; with B hidden the visible order is
  // [C, A] — A (which FOLLOWS the hidden B) must still be present and after C.
  const idxC = chipNames.findIndex((c) => c.includes(T.catC));
  const idxA = chipNames.findIndex((c) => c.includes(T.catA));
  if (!(idxC >= 0 && idxA >= 0 && idxC < idxA)) {
    throw new Error(`b-menu: category order wrong — expected C before A, got ${JSON.stringify(chipNames)}`);
  }
  log(`b-menu: chips = [${chipNames.join(' | ')}] — hidden B absent, following A present (no skip)`);

  // (c) out-of-stock badge shows on item2's card.
  const soldOut = await page.evaluate((id) => {
    const card = document.querySelector(`#cvGrid [data-open="${id}"]`);
    if (!card) return { card: false };
    const badge = card.querySelector('.card-soldout');
    return { card: true, badge: !!badge, text: badge ? badge.textContent.trim() : '' };
  }, item2.id);
  if (!soldOut.card) throw new Error('b-menu: sold-out item card not found in grid');
  if (!soldOut.badge) throw new Error('b-menu: out-of-stock badge (.card-soldout) not shown on item2');
  if (payload.byTag.item2.is_sold_out !== true) throw new Error('b-menu: item2 is_sold_out flag not true in payload');
  log(`b-menu: out-of-stock badge shows ("${soldOut.text}")`);

  // (d) REQUIRED + OPTIONAL modifier groups render on item1's product sheet.
  const opened = await page.evaluate((id) => {
    const card = document.querySelector(`#cvGrid [data-open="${id}"]`);
    if (!card) return false;
    card.scrollIntoView({ block: 'center' });
    card.click();
    return true;
  }, item1.id);
  if (!opened) {
    await page.goto(localeUrl('/' + slug + '?item=' + item1.id), { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => window.CRAVE && window.CRAVE.items, { timeout: cfg.navTimeout });
  }
  await page.waitForFunction(
    () => { const v = document.querySelector('[data-view="product"]'); return v && v.offsetParent !== null; },
    { timeout: cfg.navTimeout },
  ).catch(() => {});
  const mods = await page.evaluate(() => {
    const body = document.querySelector('#pBody');
    if (!body) return { body: false };
    const variant = body.querySelector('.opt-group[data-kind="variant"]');
    const extras = body.querySelector('.opt-group[data-kind="extras"]');
    return {
      body: true,
      requiredGroup: !!variant,
      requiredLabel: variant ? (variant.querySelector('.opt-req')?.textContent || '').trim() : '',
      variantOptions: variant ? variant.querySelectorAll('.opt[data-variant]').length : 0,
      optionalGroup: !!extras,
      optionalLabel: extras ? (extras.querySelector('.opt-req')?.textContent || '').trim() : '',
      extraOptions: extras ? extras.querySelectorAll('.opt[data-extra]').length : 0,
      extrasOptionalClass: extras ? !!extras.querySelector('.opt-req.optional') : false,
    };
  });
  if (!mods.body) throw new Error('b-menu: product sheet (#pBody) did not render for item1');
  if (!mods.requiredGroup || mods.variantOptions < 2) throw new Error(`b-menu: required modifier group did not render (${JSON.stringify(mods)})`);
  if (!mods.optionalGroup || mods.extraOptions < 2 || !mods.extrasOptionalClass) throw new Error(`b-menu: optional modifier group did not render (${JSON.stringify(mods)})`);
  await shot(page, 'b-menu-item-modifiers');
  log(`b-menu: modifiers render — required "${mods.requiredLabel}" (${mods.variantOptions} opts), optional "${mods.optionalLabel}" (${mods.extraOptions} opts)`);

  // (e) uploaded image is attached; (f) discount strikes through.
  if (!String(payload.byTag.item1.img || '').includes(uploadedFilename)) {
    throw new Error(`b-menu: item1 image not attached on storefront (img="${payload.byTag.item1.img}")`);
  }
  const disc = payload.byTag.item4;
  if (!disc.original_price || Number(disc.original_price) <= Number(disc.price)) {
    throw new Error(`b-menu: discount not reflected on item4 (price=${disc.price}, original_price=${disc.original_price})`);
  }
  log(`b-menu: image attached (${uploadedFilename}); discount item4 ${disc.price} was ${disc.original_price}`);

  // ── dashboard RTL (explicit) ───────────────────────────────────────────────
  await page.goto(localeUrl(`/stores/${rid}/menu`), { waitUntil: 'domcontentloaded' });
  await assertRtl(page);

  // ── hygiene: fail ONLY on real page errors / ENFORCED CSP refusals ─────────
  // Report-only CSP violations and resource 404s are advisory (see b-signup.js
  // ISS-B-005). Keep only pageerror + enforced CSP.
  const collected = (page.__platformConsole && page.__platformConsole.errors) || [];
  const real = collected.filter((e) => !/report[\s-]?only/i.test(e) && /^(pageerror:|CSP:)/.test(e));
  if (real.length) throw new Error(`b-menu: CSP refusals / JS errors (${real.length}):\n  ${real.join('\n  ')}`);
  const uniq404 = [...new Set(resource404s)];
  log(`b-menu: tolerated ${resource404s.length} resource-404s (${uniq404.length} unique)`);
  log(`b-menu: PASS — SLUG=${slug} RID=${rid}`);
};
