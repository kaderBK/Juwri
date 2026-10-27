// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — B3: Admin CMS depth (render + persist round-trips).
 *
 *   - RENDER: the CMS admin surfaces (blogs, blog categories, FAQs, email
 *     templates) all return 200 for the superadmin (no auth bounce).
 *   - BLOG CATEGORY CRUD: create → row persists → delete → row gone (net-zero).
 *   - FAQ CRUD: create → persists (DB + reflected in the index) → EDIT → change
 *     persists → delete → gone (net-zero). The full create/update/delete cycle.
 *   - MAIL TEMPLATE persist: read a real subject field name+value from the
 *     rendered form, save a changed value, confirm it persisted to the option
 *     store, then restore the original (net-zero).
 *
 * Blog POST create is intentionally out of scope here — its store REQUIRES an
 * uploaded image (mimes:png,jpg,jpeg), so the blog CMS is exercised via category
 * CRUD + the blogs index render; noted in the ledger.
 *
 * Admin: E2E_ADMIN_EMAIL / E2E_ADMIN_PASS. Ground truth: HTTP status + DB rows.
 */

const V = require('../../lib/platform-v2');

function adminCreds() {
  const email = process.env.E2E_ADMIN_EMAIL;
  const pass = process.env.E2E_ADMIN_PASS;
  if (!email || !pass) throw new Error('B3: set E2E_ADMIN_EMAIL / E2E_ADMIN_PASS in the lane .env');
  return { email, pass };
}

// In-page fetch reusing the admin session + a CSRF token from the current page.
async function req(page, method, path, body, json = true) {
  return page.evaluate(async ({ method, path, body, json }) => {
    const csrf = window.CRAVE?.csrf || document.querySelector('meta[name="csrf-token"]')?.content;
    const headers = { Accept: 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) };
    let payload;
    if (body && json) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
    else if (body) { headers['Content-Type'] = 'application/x-www-form-urlencoded'; payload = new URLSearchParams(body).toString(); }
    const r = await fetch(path, { method, credentials: 'same-origin', headers, body: payload, redirect: 'follow' });
    let data = null; try { data = await r.json(); } catch { data = null; }
    return { status: r.status, data };
  }, { method, path, body, json });
}

const uniq = () => 'e2e-b3-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);

module.exports = async function b3AdminCms({ page, log }) {
  await V.loginAdmin(page, adminCreds());

  // ── 1. RENDER the CMS surfaces. ────────────────────────────────────────────
  for (const path of ['/admin/blogs', '/admin/blog/categories', '/admin/faqs', '/admin/email-templates']) {
    const resp = await page.goto(V.cfg.baseUrl + path, { waitUntil: 'domcontentloaded' });
    const status = resp ? resp.status() : 0;
    const landed = new URL(page.url()).pathname;
    if (status < 200 || status >= 400 || /\/login$/.test(landed)) {
      throw new Error(`B3 render: ${path} → ${status} (landed ${landed}) — admin CMS did not render`);
    }
    log(`B3 render: ${path} → ${status} ok`);
  }

  // ── 2. BLOG CATEGORY CRUD. ─────────────────────────────────────────────────
  await page.goto(V.cfg.baseUrl + '/admin/blog/categories', { waitUntil: 'domcontentloaded' });
  const catTitle = uniq() + '-cat';
  const catCreate = await req(page, 'POST', '/admin/blog/categories', { title: catTitle, active: '1' });
  if (!(catCreate.data && catCreate.data.success)) throw new Error(`B3 blog-cat create: ${catCreate.status} ${JSON.stringify(catCreate.data)}`);
  const catRows = V.db('SELECT id FROM {p}blog_categories WHERE title = ?', [catTitle]);
  if (catRows.length !== 1) throw new Error(`B3 blog-cat create: expected 1 row for '${catTitle}', found ${catRows.length}`);
  const catId = Number(catRows[0][0]);
  log(`B3 blog-category: created #${catId} '${catTitle}'`);

  // The resource `destroy` deliberately 404s; the real delete is POST /delete {ids:[]}.
  const catDel = await req(page, 'POST', '/admin/blog/categories/delete', { ids: [catId] });
  const catGone = V.db('SELECT COUNT(*) FROM {p}blog_categories WHERE id = ?', [catId]);
  if (Number(catGone[0][0]) !== 0) throw new Error(`B3 blog-cat delete: row #${catId} still present (delete status ${catDel.status})`);
  log(`B3 blog-category: deleted #${catId} (net-zero)`);

  // ── 3. FAQ CRUD (create → edit → delete). ──────────────────────────────────
  await page.goto(V.cfg.baseUrl + '/admin/faqs', { waitUntil: 'domcontentloaded' });
  const faqTitle = uniq() + '-faq';
  const faqCreate = await req(page, 'POST', '/admin/faqs', { faq_title: faqTitle, faq_content: 'B3 content', active: '1' });
  if (!(faqCreate.data && faqCreate.data.success)) throw new Error(`B3 faq create: ${faqCreate.status} ${JSON.stringify(faqCreate.data)}`);
  const faqRows = V.db('SELECT id, faq_content FROM {p}faq_entries WHERE faq_title = ?', [faqTitle]);
  if (faqRows.length !== 1) throw new Error(`B3 faq create: expected 1 row, found ${faqRows.length}`);
  const faqId = Number(faqRows[0][0]);

  // persists into the index render
  const idxResp = await page.goto(V.cfg.baseUrl + '/admin/faqs', { waitUntil: 'domcontentloaded' });
  const idxHtml = await page.content();
  if (idxResp.status() === 200 && !idxHtml.includes(faqTitle)) {
    throw new Error(`B3 faq: created FAQ '${faqTitle}' not reflected in the admin FAQ index`);
  }
  log(`B3 faq: created #${faqId} '${faqTitle}' (visible in index)`);

  // EDIT (update) — change the content, assert it persists.
  const faqEdit = await req(page, 'PUT', `/admin/faqs/${faqId}`, { faq_title: faqTitle, faq_content: 'B3 content EDITED', active: '1' });
  const edited = V.db('SELECT faq_content FROM {p}faq_entries WHERE id = ?', [faqId]);
  if (String(edited[0][0]) !== 'B3 content EDITED') {
    throw new Error(`B3 faq edit: content is '${edited[0][0]}', expected 'B3 content EDITED' (status ${faqEdit.status})`);
  }
  log(`B3 faq: edited #${faqId} content persisted`);

  const faqDel = await req(page, 'DELETE', `/admin/faqs/${faqId}`, null);
  const faqGone = V.db('SELECT COUNT(*) FROM {p}faq_entries WHERE id = ?', [faqId]);
  if (Number(faqGone[0][0]) !== 0) throw new Error(`B3 faq delete: row #${faqId} still present (status ${faqDel.status})`);
  log(`B3 faq: deleted #${faqId} (net-zero)`);

  // ── 4. MAIL TEMPLATE render + persist (net-zero). ──────────────────────────
  await page.goto(V.cfg.baseUrl + '/admin/email-templates', { waitUntil: 'domcontentloaded' });
  const field = await page.evaluate(() => {
    // The email form is #saveEmailTemplate; email_setting is the submit BUTTON.
    const form = document.querySelector('#saveEmailTemplate')
      || document.querySelector('form[action*="email-templates"]');
    if (!form) return null;
    // Pick a real subject text input (not the CSRF token).
    const input = Array.from(form.querySelectorAll('input[type="text"]'))
      .find((i) => i.name && i.name !== 'email_setting' && i.name !== '_token');
    return input ? { name: input.name, value: input.value } : null;
  });
  if (!field) {
    throw new Error('B3 mail-template: could not find an editable subject field on the form');
  }
  const original = field.value;
  const changed = (original || 'Subject') + ' [e2e]';
  const save = await req(page, 'POST', '/admin/email-templates/update',
    { email_setting: '1', [field.name]: changed }, false);
  if (save.status !== 200) throw new Error(`B3 mail-template save: ${save.status}`);
  // P1-07: platform_settings.value is JSON (a JSON string for a text setting).
  const settingText = (rows) => (rows.length ? JSON.parse(String(rows[0][0])) : null);
  const persisted = V.db('SELECT value FROM {p}platform_settings WHERE name = ?', [field.name]);
  if (!persisted.length || settingText(persisted) !== changed) {
    throw new Error(`B3 mail-template: option '${field.name}' did not persist ('${persisted[0] ? persisted[0][0] : '(none)'}' != '${changed}')`);
  }
  // restore
  await req(page, 'POST', '/admin/email-templates/update', { email_setting: '1', [field.name]: original }, false);
  const restored = V.db('SELECT value FROM {p}platform_settings WHERE name = ?', [field.name]);
  if (settingText(restored) !== original) {
    throw new Error(`B3 mail-template restore: option '${field.name}' is '${restored[0][0]}', expected original '${original}'`);
  }
  log(`B3 mail-template: field '${field.name}' persist verified + restored (net-zero)`);

  log('B3 admin CMS: 4 surfaces render; blog-category + FAQ full CRUD; mail-template persist — all net-zero');
};
