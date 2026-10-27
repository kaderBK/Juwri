// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P1-G (locales, opt-in) — the storefront language switch must actually change
 * the interface language, on every retained theme.
 *
 * Ten themes used to switch by writing the menu-language cookie and reloading.
 * Under prefix-mode locales the reload keeps the existing /xx/ segment and the
 * server puts the page straight back into the language the guest just left: the
 * menu item names changed, everything around them did not. This drives the real
 * control and asserts the document's own lang/dir moved.
 *
 * Env: E2E_RUN_LANG=1, E2E_LANG_STORES (comma-separated slugs, default
 * crumb-demo,soiree-demo,crave-demo,orbit-demo).
 */

module.exports = async function storefrontLanguageSwitch({ page, cfg, log, shot, sleep }) {
  const stores = (process.env.E2E_LANG_STORES || 'crumb-demo,soiree-demo,crave-demo,orbit-demo')
    .split(',').map((s) => s.trim()).filter(Boolean);

  const failures = [];
  const passes = [];

  for (const slug of stores) {
    const resp = await page.goto(`${cfg.baseUrl}/en/${slug}`, { waitUntil: 'networkidle2' });
    if (!resp || resp.status() >= 400) { failures.push(`${slug}: page returned ${resp && resp.status()}`); continue; }

    const before = await page.evaluate(() => ({
      lang: document.documentElement.lang,
      dir: document.documentElement.dir,
      path: location.pathname,
    }));

    // Find a control offering a language OTHER than the current one.
    const target = await page.evaluate(() => {
      const els = [...document.querySelectorAll('[data-lang-code]')].filter((e) => (e.getAttribute('data-lang-code') || '').trim() !== '');
      if (!els.length) return null;
      const cur = (document.documentElement.lang || '').slice(0, 2).toLowerCase();
      const other = els.find((e) => (e.getAttribute('data-lang-code') || '').toLowerCase() !== cur);
      return other ? (other.getAttribute('data-lang-code') || '').toLowerCase() : null;
    });
    if (!target) { failures.push(`${slug}: no [data-lang-code] control offering another language`); continue; }

    // Click it for real — the shared handler runs in the capture phase.
    await Promise.all([
      page.evaluate((code) => {
        const el = [...document.querySelectorAll('[data-lang-code]')].filter((e) => (e.getAttribute('data-lang-code') || '').trim() !== '')
          .find((e) => (e.getAttribute('data-lang-code') || '').toLowerCase() === code);
        el.click();
      }, target),
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: cfg.navTimeout }).catch(() => {}),
    ]);
    await sleep(500);

    const after = await page.evaluate(() => ({
      lang: document.documentElement.lang,
      dir: document.documentElement.dir,
      path: location.pathname,
    }));

    const langMoved = (after.lang || '').slice(0, 2).toLowerCase() === target;
    if (!langMoved) {
      failures.push(`${slug}: clicked "${target}" but <html lang> is still "${after.lang}" (was "${before.lang}", path ${before.path} -> ${after.path})`);
      continue;
    }

    /* The URL shape is deliberately NOT asserted: the app runs
       hideDefaultLocaleInURL, so the default locale (ar) is served unprefixed
       and /ar/slug 302s to /slug. What actually matters is that the choice
       STICKS — the old cookie-and-reload themes changed the labels for one
       render and reverted on the next request. So navigate again and re-check. */
    await page.goto(`${cfg.baseUrl}${after.path}`, { waitUntil: 'networkidle2' });
    const persisted = await page.evaluate(() => (document.documentElement.lang || '').slice(0, 2).toLowerCase());
    if (persisted !== target) {
      failures.push(`${slug}: switched to "${target}" but a second visit to ${after.path} came back as "${persisted}" — the choice does not persist`);
      continue;
    }

    passes.push(`${slug}: ${before.lang}${before.dir ? '/' + before.dir : ''} -> ${after.lang}${after.dir ? '/' + after.dir : ''} (${after.path}, persists)`);
    await shot(page, `lang-${slug}`);
  }

  passes.forEach((p) => log(`lang: ${p}`));
  if (failures.length) throw new Error('language switch broken:\n  - ' + failures.join('\n  - '));

  log(`lang: ${passes.length}/${stores.length} storefronts switch language correctly`);
};
