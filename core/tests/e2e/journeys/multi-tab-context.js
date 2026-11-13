// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * E2E-008 (opt-in) — multi-tab store context isolation.
 *
 * Two dashboard tabs share one session. Tab A sits on store A's menu page;
 * tab B then opens store B (moving the session's active store). Tab A's
 * URL-scoped mutation must still land in store A — never bleed into store B —
 * and a write against a store this owner does NOT own must be refused.
 *
 * Env: E2E_RUN_MULTITAB=1, E2E_OWNER_EMAIL / E2E_OWNER_PASS,
 * E2E_STORE_A / E2E_STORE_B (ids owned by the owner), E2E_FOREIGN_STORE
 * (id NOT owned by them).
 */

module.exports = async function multiTabContext({ browser, page, cfg, log, shot, sleep }) {
  const email = process.env.E2E_MULTITAB_OWNER_EMAIL || process.env.E2E_OWNER_EMAIL;
  const pass = process.env.E2E_MULTITAB_OWNER_PASS || process.env.E2E_OWNER_PASS;
  const storeA = +process.env.E2E_STORE_A;
  const storeB = +process.env.E2E_STORE_B;
  const foreign = +process.env.E2E_FOREIGN_STORE;
  if (!email || !pass || !storeA || !storeB || !foreign) {
    throw new Error('multi-tab: set E2E_OWNER_EMAIL/PASS, E2E_STORE_A/B, E2E_FOREIGN_STORE');
  }
  const probe = 'TAB-A-PROBE-' + Math.random().toString(36).slice(2, 8).toUpperCase();

  // ── login (tab A) ──
  await page.goto(`${cfg.baseUrl}/login`, { waitUntil: 'networkidle2' });
  await page.waitForSelector('#username', { timeout: cfg.navTimeout });
  await page.type('#username', email);
  await page.type('#password', pass);
  await Promise.all([
    page.evaluate(() => document.querySelector('form').submit()),
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: cfg.navTimeout }).catch(() => {}),
  ]);

  // Tab A on store A's menu page.
  const respA = await page.goto(`${cfg.baseUrl}/stores/${storeA}/menu`, { waitUntil: 'networkidle2' });
  if (!respA || respA.status() >= 400) throw new Error(`multi-tab: store A menu returned ${respA && respA.status()}`);
  await shot(page, 'multitab-1-storeA');

  // Tab B (same browser context/session) opens store B — session store moves.
  const pageB = await (page.browserContext ? page.browserContext() : browser).newPage();
  const respB = await pageB.goto(`${cfg.baseUrl}/stores/${storeB}/menu`, { waitUntil: 'networkidle2' });
  if (!respB || respB.status() >= 400) throw new Error(`multi-tab: store B menu returned ${respB && respB.status()}`);
  await shot(pageB, 'multitab-2-storeB');

  // Tab A now mutates via ITS page's URL scope (session says store B).
  const post = (p, url, body) => p.evaluate(async (u, b) => {
    const token = document.querySelector('meta[name=csrf-token]')?.content
      || document.querySelector('input[name=_token]')?.value;
    const fd = new URLSearchParams({ ...b, _token: token });
    const r = await fetch(u, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, body: fd });
    let data = null; try { data = await r.json(); } catch (e) {}
    return { status: r.status, data };
  }, url, body);

  const create = await post(page, `${cfg.baseUrl}/stores/${storeA}/add-category`, { name: probe });
  if (create.status >= 400) throw new Error(`multi-tab: tab A category create failed (${create.status})`);
  await sleep(400);

  // Foreign-store write must be refused. The controller refuses by silently
  // redirecting (302→200) rather than a 403 — recorded as finding F7 — so the
  // wall is asserted by data: the probe must never appear on the foreign
  // store's public menu (checked below after E2E_FOREIGN_SLUG fetch).
  const stolen = await post(page, `${cfg.baseUrl}/stores/${foreign}/add-category`, { name: probe + '-X' });
  const foreignSlug = process.env.E2E_FOREIGN_SLUG;
  if (foreignSlug) {
    const clean = await page.evaluate(async (u, n) => {
      const r = await fetch(u, { headers: { Accept: 'text/html' } });
      const html = await r.text();
      return !html.includes(n);
    }, `${cfg.baseUrl}/${foreignSlug}`, probe + '-X');
    if (!clean) throw new Error('multi-tab: foreign-store write LANDED — tenant wall breached');
  } else if (stolen.status < 400) {
    log(`multi-tab: foreign write returned ${stolen.status} (silent refusal — set E2E_FOREIGN_SLUG for the data assertion)`);
  }

  // Tab A reload stays coherent (no error page after the session moved).
  const reload = await page.goto(`${cfg.baseUrl}/stores/${storeA}/menu`, { waitUntil: 'networkidle2' });
  if (!reload || reload.status() >= 400) throw new Error(`multi-tab: tab A reload broke (${reload && reload.status()})`);
  const probeVisibleA = await page.evaluate((n) => document.body.innerText.includes(n), probe);
  await shot(page, 'multitab-3-storeA-probe');

  // Store B must NOT contain the probe.
  await pageB.goto(`${cfg.baseUrl}/stores/${storeB}/menu`, { waitUntil: 'networkidle2' });
  const probeVisibleB = await pageB.evaluate((n) => document.body.innerText.includes(n), probe);
  await shot(pageB, 'multitab-4-storeB-clean');
  await pageB.close().catch(() => {});

  if (!probeVisibleA) throw new Error('multi-tab: probe category not visible on store A after reload');
  if (probeVisibleB) throw new Error('multi-tab: probe category LEAKED into store B');

  log(`multi-tab: ${probe} scoped to store ${storeA}, foreign write ${stolen.status}, no leak into ${storeB}`);
};
