// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * CSP hygiene (opt-in) — a storefront page load must trip ZERO Content-Security-Policy violations.
 *
 * The storefront ships a nonce'd report-only policy today and enforces it per theme (see
 * EnforceStorefrontCspPerTemplate). Every violation is a page that breaks the day the policy is enforced, and in
 * report-only mode each one is also a POST to /csp-report (a theme that sent ~60 of them on load used to spend the
 * visitor's whole throttle budget). This listens for `securitypolicyviolation` from the first byte of each document,
 * on a fresh load and on a reload (second load goes through the page cache + service worker), for every retained
 * theme, and fails with the directive, blocked uri, source line and the offending source text.
 *
 * Env: E2E_RUN_CSP_CLEAN=1, E2E_CSP_STORES (default: the retained demos). A name with a `/` is a full store slug
 * (`carely/e2ea-pharmacy`: label carely, store e2ea-pharmacy) — P3-13 checks the D11 premium themes on their demos.
 */

const RETAINED = require('../lib/retained');

module.exports = async function storefrontCspClean({ page, cfg, log, sleep }) {
  const stores = (process.env.E2E_CSP_STORES || RETAINED.join(',')).split(',').map((s) => s.trim()).filter(Boolean);

  await page.evaluateOnNewDocument(() => {
    window.__spv = [];
    document.addEventListener('securitypolicyviolation', (e) => {
      window.__spv.push({ d: e.effectiveDirective, b: e.blockedURI, l: e.lineNumber, s: (e.sample || '').slice(0, 80), f: e.sourceFile || '' });
    }, true);
  });

  const failures = [];
  const counts = [];

  for (const entry of stores) {
    const [theme, slug] = entry.includes('/') ? entry.split('/') : [entry, `${entry}-demo`];
    const url = `${cfg.baseUrl}/${slug}`;
    const loads = [];
    for (const kind of ['load', 'reload']) {
      const resp = kind === 'load'
        ? await page.goto(url, { waitUntil: 'networkidle2', timeout: cfg.navTimeout })
        : await page.reload({ waitUntil: 'networkidle2', timeout: cfg.navTimeout });
      if (!resp || resp.status() >= 400) { failures.push(`${theme} ${kind}: HTTP ${resp && resp.status()}`); continue; }
      if (process.env.E2E_CSP_DEBUG) { const h = resp.headers(); const hn = ((h['content-security-policy-report-only'] || h['content-security-policy'] || '').match(/nonce-([A-Za-z0-9+\/=]+)/) || [])[1]; const bn = await page.evaluate(() => (document.querySelector('script[nonce]') || {}).nonce); console.log('DBG', theme, kind, 'fromSW=' + resp.fromServiceWorker(), 'hdr=' + hn, 'body=' + bn, 'cache=' + h['x-storefront-cache'], 'status=' + resp.status()); }
      await sleep(3000); // late widgets: offers strip (2.5s), reviews, push prompts
      const all = await page.evaluate(() => window.__spv || []);
      const lines = all.length ? await page.evaluate(async (href) => {
        const t = await (await fetch(href, { cache: 'no-store' })).text();
        const rows = t.split('\n');
        return rows;
      }, url) : [];
      // Laravel Boost (a local-dev package, never installed in production) injects its un-nonced browser-logger
      // <script id="browser-logger-active"> into every HTML page of a local site: not the storefront's markup.
      const boost = (x) => /browser-logger-active/.test(x.s || '')
        || (x.l && x.f.indexOf(`/${slug}`) !== -1 && /browser-logger-active/.test(lines[x.l - 1] || ''));
      const v = all.filter((x) => !boost(x));
      loads.push(v.length);
      if (v.length) {
        const uniq = new Map();
        for (const x of v) {
          const src = x.l && x.f.indexOf(`/${slug}`) !== -1 ? (lines[x.l - 1] || '').trim().slice(0, 110) : '';
          const k = `${x.d} ${x.b} ${x.f.replace(cfg.baseUrl, '')}:${x.l} ${x.s} ${src}`;
          uniq.set(k, (uniq.get(k) || 0) + 1);
        }
        const sample = [...uniq.entries()].slice(0, 8).map(([k, n]) => `${n}x ${k}`).join('\n    ');
        failures.push(`${theme} ${kind}: ${v.length} violation(s)\n    ${sample}`);
      }
    }
    counts.push(`${theme}=${loads.join('/')}`);
  }

  log(`csp violations per load/reload — ${counts.join(' ')}`);
  if (failures.length) throw new Error('CSP violations on page load:\n  ' + failures.join('\n  '));
};
