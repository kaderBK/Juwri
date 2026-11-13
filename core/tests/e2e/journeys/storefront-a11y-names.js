// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P1-G (accessibility, opt-in) — every interactive control needs an accessible
 * name (WCAG 4.1.2 / 2.4.4).
 *
 * The retained storefronts lean heavily on icon-only buttons: cart, favourites,
 * language, close, back, quantity steppers. An icon-only <button> with no text,
 * aria-label, aria-labelledby or title is announced as just "button" by a screen
 * reader, which makes the menu unusable without sight. The existing retained
 * template sweep covered viewport structure and skip-link focus, not names.
 *
 * Reports per store so a fix can be aimed, and only counts controls that are
 * actually rendered (offsetParent !== null) — hidden sheet scaffolding is not a
 * user-facing failure.
 *
 * Env: E2E_RUN_A11Y=1, E2E_A11Y_STORES (comma-separated slugs; defaults to the
 * seventeen retained demo stores), E2E_A11Y_MAX (allowed unnamed controls per
 * store before it counts as a failure, default 0).
 */

const RETAINED = require('../lib/retained').map((t) => `${t}-demo`);

module.exports = async function storefrontA11yNames({ page, cfg, log, shot, sleep }) {
  const stores = (process.env.E2E_A11Y_STORES || RETAINED.join(','))
    .split(',').map((s) => s.trim()).filter(Boolean);
  const max = Number(process.env.E2E_A11Y_MAX || 0);

  const report = [];

  for (const slug of stores) {
    const resp = await page.goto(`${cfg.baseUrl}/${slug}`, { waitUntil: 'networkidle2' });
    if (!resp || resp.status() >= 400) {
      report.push({ slug, error: `page returned ${resp && resp.status()}` });
      continue;
    }
    await sleep(400);

    const unnamed = await page.evaluate(() => {
      const SELECTOR = 'button, a[href], [role="button"], input[type="button"], input[type="submit"]';
      const named = (el) => {
        const text = (el.innerText || el.textContent || '').replace(/\s+/g, ' ').trim();
        if (text) return true;
        for (const attr of ['aria-label', 'title', 'aria-labelledby']) {
          if ((el.getAttribute(attr) || '').trim()) return true;
        }
        // An <img>/<svg> child can carry the name instead.
        const img = el.querySelector('img[alt], svg title, [aria-label]');
        if (img) {
          if (img.tagName === 'IMG' && (img.getAttribute('alt') || '').trim()) return true;
          if (img.tagName === 'title' && (img.textContent || '').trim()) return true;
          if ((img.getAttribute('aria-label') || '').trim()) return true;
        }
        return false;
      };

      const out = [];
      document.querySelectorAll(SELECTOR).forEach((el) => {
        if (el.offsetParent === null) return;                  // not rendered
        if (el.getAttribute('aria-hidden') === 'true') return;  // deliberately hidden from AT
        if (named(el)) return;
        const id = el.id ? `#${el.id}` : '';
        const cls = (el.className && typeof el.className === 'string')
          ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '';
        out.push(`${el.tagName.toLowerCase()}${id}${cls}`);
      });
      return out;
    });

    report.push({ slug, unnamed });
    if (unnamed.length > max) await shot(page, `a11y-${slug}`);
  }

  const broken = report.filter((r) => r.error || (r.unnamed && r.unnamed.length > max));
  report.filter((r) => !r.error).forEach((r) => {
    log(`a11y ${r.slug}: ${r.unnamed.length} unnamed control(s)${r.unnamed.length ? ' — ' + r.unnamed.slice(0, 6).join(', ') : ''}`);
  });

  if (broken.length) {
    throw new Error(
      `${broken.length}/${stores.length} storefronts have interactive controls with no accessible name:\n  - `
      + broken.map((r) => r.error ? `${r.slug}: ${r.error}` : `${r.slug}: ${r.unnamed.length} (${r.unnamed.slice(0, 8).join(', ')})`).join('\n  - ')
    );
  }

  log(`a11y: all ${stores.length} storefronts name every rendered interactive control`);
};
