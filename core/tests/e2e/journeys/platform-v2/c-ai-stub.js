// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — C7: AI stub write-path (biolink/blog/translate) (P1).
 *
 * With the stubs armed (E2E_STUBS=1), E2eStubServiceProvider binds AiService →
 * E2eStubAiService (deterministic, no real key/spend). This journey exercises a
 * REAL reseller AI write path end-to-end — the blog-manager translate endpoint
 * (Portal/BlogManagerController@translate → ResellerPages\TranslateService →
 * AiService::json) — and asserts the DETERMINISTIC stub output is PERSISTED to
 * the reseller_blog_posts row, with the AI call never touching a provider.
 *
 *   1. ARMED — the stub flag is set (fail fast otherwise).
 *   2. WRITE PATH — POST /reseller/blog-manager/{id}/translate {locale:en} → 200
 *      ok; the post's title gains an `en` value equal to the stub's deterministic
 *      output ("E2E Stub") — a value ONLY E2eStubAiService produces (a real
 *      provider would differ; NO provider would 502). Proves the binding + the
 *      persistence, not just an HTTP 200.
 *   3. TENANT FENCE — reseller A translating reseller B's post → 404
 *      (ResellerBlogPost::forReseller scoping), no cross-tenant AI write.
 *
 *   E2E_RUN_PLATFORM_V2=1 bash rev/platform-e2e/bin/e2e.sh c c-ai-stub
 */

const V = require('../../lib/platform-v2');
const { provisionReseller } = require('../../lib/reseller-fixtures');

const STUB_TITLE = 'E2E Stub';   // E2eStubAiService::json() candidate[0].title

function seedPost(resellerId, slug) {
  // content_default_locale drives the translate SOURCE; seed an `ar` source.
  V.db('UPDATE {p}resellers SET content_default_locale = ? WHERE id = ?', ['ar', resellerId]);
  V.db(
    "INSERT INTO {p}reseller_blog_posts (reseller_id, slug, title, excerpt, body, status, published_at, created_at, updated_at) "
    + "VALUES (?, ?, '{\"ar\":\"عنوان المصدر\"}', '{\"ar\":\"مقتطف\"}', '{\"ar\":\"<p>نص</p>\"}', 'published', NOW(), NOW(), NOW())",
    [resellerId, slug],
  );
  return Number(V.db('SELECT id FROM {p}reseller_blog_posts WHERE reseller_id = ? AND slug = ? LIMIT 1', [resellerId, slug])[0][0]);
}

async function postJson(page, url, body) {
  return page.evaluate(async ({ url, body }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const r = await fetch(url, {
      method: 'POST', credentials: 'same-origin', redirect: 'follow',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}) },
      body: JSON.stringify(body || {}),
    });
    let json = null; try { json = await r.json(); } catch (_e) { /* non-json */ }
    return { status: r.status, json };
  }, { url, body });
}

module.exports = async function cAiStub({ page, cfg, log }) {
  const eeOnly = V.requireEe('the Reseller blog manager (its AI write path)');
  if (eeOnly) return eeOnly;   // Community build: skipped with the reason
  if (!V.stubsArmed()) throw new Error('c-ai-stub: E2E_STUBS not armed — the AI stub is not bound');
  log('stub armed ✓');

  const A = provisionReseller(V, { tag: 'a' });
  const B = provisionReseller(V, { tag: 'b' });
  const unix = Date.now();
  const postA = seedPost(A.resellerId, `c7-a-${unix}`);
  const postB = seedPost(B.resellerId, `c7-b-${unix}`);
  log(`A(reseller=${A.resellerId} post=${postA}); B(reseller=${B.resellerId} post=${postB})`);

  await page.setViewport({ width: 390, height: 844 });
  await V.webLogin(page, { email: A.ownerEmail, pass: A.ownerPass });
  const portalResp = await page.goto(cfg.baseUrl + '/reseller', { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
  if (!portalResp || portalResp.status() !== 200) throw new Error(`A portal → ${portalResp ? portalResp.status() : 'no response'}`);

  // ── 2. AI WRITE PATH: translate → deterministic stub output persisted ──────
  const tr = await postJson(page, `/reseller/blog-manager/${postA}/translate`, { locale: 'en', mode: 'missing' });
  if (tr.status !== 200 || !tr.json || tr.json.ok !== true) {
    throw new Error(`translate expected 200 ok, got ${tr.status} (${JSON.stringify(tr.json)}) — AI stub may not be bound`);
  }
  const titleJson = V.db('SELECT title FROM {p}reseller_blog_posts WHERE id = ?', [postA])[0][0];
  const title = JSON.parse(titleJson || '{}');
  if (title.en !== STUB_TITLE) {
    throw new Error(`AI stub NOT persisted: post ${postA} title.en = ${JSON.stringify(title.en)} (expected "${STUB_TITLE}" from E2eStubAiService)\n  full: ${titleJson}`);
  }
  // The source stays intact (translate added a locale, didn't destroy the source).
  if (!title.ar) throw new Error(`translate destroyed the source locale (title.ar missing): ${titleJson}`);
  log(`write path: translate 200 ok; post ${postA} title.en = "${title.en}" (deterministic stub output persisted), source ar intact ✓`);

  // ── 3. TENANT FENCE: A cannot translate B's post ───────────────────────────
  const bTitleBefore = V.db('SELECT title FROM {p}reseller_blog_posts WHERE id = ?', [postB])[0][0];
  const cross = await postJson(page, `/reseller/blog-manager/${postB}/translate`, { locale: 'en', mode: 'missing' });
  if (![403, 404].includes(cross.status)) {
    throw new Error(`AI tenant fence: A translating B's post ${postB} → ${cross.status} (expected 404)`);
  }
  const bTitleAfter = V.db('SELECT title FROM {p}reseller_blog_posts WHERE id = ?', [postB])[0][0];
  if (bTitleBefore !== bTitleAfter) throw new Error(`cross-tenant AI WRITE LEAK: B's post ${postB} title changed via A`);
  log(`tenant fence: A→B post translate ${cross.status}, B's post unchanged ✓`);

  log(`c-ai-stub PASS — AI write path resolves to E2eStubAiService (deterministic "E2E Stub" persisted to reseller_blog_posts), source preserved, cross-tenant AI write denied 404 (A=${A.resellerId} B=${B.resellerId})`);
};
