// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P3-13 (premium Stage 0, item 4/5) — axe smoke on the 8 D11 premium themes: 0 serious / critical violations.
 *
 * Per theme demo store, in en and ar: the home page as it loads. axe-core comes from the conformance job's own dev
 * dependency (tools/themes/conformance/node_modules/axe-core), rules WCAG 2.0/2.1/2.2 A + AA. Fails with rule id,
 * impact, target and count for every serious or critical result; moderate/minor ones are only logged.
 *
 * One narrow exception: a `target-size` result whose only "obscuring" elements belong to a sticky/fixed bar at the
 * viewport edge (a bottom tab bar) is the scroll position axe happened to see, not the control's size — scrolling
 * one notch uncovers it. Those are logged as (scroll) and not failed; any other target-size result fails.
 *
 * Env: E2E_RUN_PREMIUM_AXE=1, E2E_AXE_STORES (`theme/slug` list; default: the D11 demo stores of the dev seeders).
 */

const fs = require('fs');
const path = require('path');

const DEFAULT = 'aurora/aurora-demo,velvet/velvet-demo,ember/ember-demo,glow/glow-demo,vivid/vivid-demo,carely/e2ea-pharmacy,aurelia/hotel-demo,freshly/laundry-demo';

module.exports = async function premiumAxeSmoke({ page, cfg, log, sleep }) {
  const axePath = path.resolve(__dirname, '../../../../tools/themes/conformance/node_modules/axe-core/axe.min.js');
  if (!fs.existsSync(axePath)) throw new Error('axe-core missing: run `npm ci` in tools/themes/conformance');
  const axe = fs.readFileSync(axePath, 'utf8');
  const stores = (process.env.E2E_AXE_STORES || DEFAULT).split(',').map((s) => s.trim()).filter(Boolean);
  const failures = [];
  const summary = [];

  const run = async (label) => {
    // axe is injected through the DevTools protocol (not a <script> tag), so the page CSP is not loosened.
    await page.evaluate(axe);
    const res = await page.evaluate(async () => {
      const r = await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] }, resultTypes: ['violations'] });
      const pinned = (sel) => {
        for (let el = document.querySelector(sel); el && el !== document.body; el = el.parentElement) {
          const pos = getComputedStyle(el).position;
          if (pos === 'fixed' || pos === 'sticky') return true;
        }
        return false;
      };
      const underBar = (node) => {
        const rel = ((node.any[0] || {}).relatedNodes || []).map((x) => x.target.join(' '));
        return /obscured/.test((node.any[0] || {}).message || '') && rel.length > 0 && rel.every(pinned);
      };
      const out = [];
      for (const v of r.violations) {
        const nodes = v.id === 'target-size' ? v.nodes.filter((x) => !underBar(x)) : v.nodes;
        if (nodes.length < v.nodes.length) out.push({ id: v.id, impact: 'scroll', n: v.nodes.length - nodes.length, t: '' });
        if (nodes.length) out.push({ id: v.id, impact: v.impact, n: nodes.length, t: nodes.slice(0, 3).map((x) => x.target.join(' ')).join(' | ') });
      }
      return out;
    });
    const bad = res.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    const other = res.filter((v) => !(v.impact === 'serious' || v.impact === 'critical'));
    summary.push(`${label}=${bad.length}/${other.length}`);
    for (const v of bad) failures.push(`${label}: ${v.id} x${v.n} ${v.t.split(' | ')[0].replace(/\[href=.*$/, '').slice(-40)}`);
    for (const v of other) log(`  (minor) ${label}: ${v.impact} ${v.id} ×${v.n}`);
  };

  for (const entry of stores) {
    const [theme, slug] = entry.split('/');
    for (const locale of ['en', 'ar']) {
      const resp = await page.goto(`${cfg.baseUrl}/${locale}/${slug}`, { waitUntil: 'networkidle2', timeout: cfg.navTimeout });
      // carely's demo store (e2ea-pharmacy) is made by the a-pharmacy journey on the armed server, not by a seeder: absent = not run yet.
      if (theme === 'carely' && resp && resp.status() === 404) { log(`  (skip) ${theme}/${locale}: ${slug} not created in this run`); continue; }
      if (!resp || resp.status() >= 400) { failures.push(`${theme} ${locale}: HTTP ${resp && resp.status()}`); continue; }
      await sleep(1500);
      await run(`${theme}/${locale}/home`);
    }
  }

  log(`axe serious+critical/other per page — ${summary.join(' ')}`);
  for (const f of failures) log(`  (violation) ${f}`);
  if (failures.length) throw new Error('axe serious/critical violations: ' + failures.join(' ;; '));
};
