#!/usr/bin/env node
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Theme Studio phase E1 — browser gate for an AI-written section
 * (driven by Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor\BrowserGate).
 *
 *   node browser-check.mjs <job.json>
 *
 * job = { pages: [{ name, file, lang }], outDir }
 * For every page (a standalone HTML file: library.css + draft CSS + the
 * rendered section) at 360px (mobile) and 1280px (desktop):
 *   - screenshot  <outDir>/<name>-<viewport>.png
 *   - overflow    document wider than the viewport (horizontal scroll)
 *   - empty       the section root has no box (height 0)
 *   - cls         layout-shift sum over the first second
 *   - a11y        axe-core (when the package is installed) on the section;
 *                 otherwise basic checks (img alt, control names, duplicate
 *                 ids) and `axe: "skipped: …"` in the report
 * Only file:// requests are allowed (nothing leaves the machine).
 * Prints one JSON object on stdout.
 */
import { readFileSync, mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join } from 'node:path';

const require = createRequire(import.meta.url);
const job = JSON.parse(readFileSync(process.argv[2], 'utf8'));
mkdirSync(job.outDir, { recursive: true });

let puppeteer;
try {
  puppeteer = (await import('puppeteer')).default;
} catch (e) {
  console.log(JSON.stringify({ skipped: 'puppeteer is not installed (npm install in core/)' }));
  process.exit(0);
}

let axeSource = null;
let axeSkipped = null;
try {
  axeSource = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
} catch (e) {
  axeSkipped = 'skipped: axe-core is not installed (basic a11y checks ran instead)';
}

const VIEWPORTS = { mobile: { width: 360, height: 740, isMobile: true, deviceScaleFactor: 1 }, desktop: { width: 1280, height: 800, deviceScaleFactor: 1 } };
const browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox', '--disable-gpu', '--allow-file-access-from-files'] });
const out = { axe: axeSkipped || 'axe-core', pages: [] };

try {
  for (const p of job.pages) {
    for (const [vp, viewport] of Object.entries(VIEWPORTS)) {
      const page = await browser.newPage();
      await page.setViewport(viewport);
      await page.setRequestInterception(true);
      page.on('request', (r) => (r.url().startsWith('file://') || r.url().startsWith('data:') ? r.continue() : r.abort()));
      await page.evaluateOnNewDocument(() => {
        window.__cls = 0;
        try {
          new PerformanceObserver((list) => { for (const e of list.getEntries()) { if (!e.hadRecentInput) window.__cls += e.value; } })
            .observe({ type: 'layout-shift', buffered: true });
        } catch (e) { /* unsupported */ }
      });
      await page.goto('file://' + p.file, { waitUntil: 'load', timeout: 20000 });
      await new Promise((r) => setTimeout(r, 600));

      const metrics = await page.evaluate(() => {
        const root = document.querySelector('[data-ai-section-root]');
        const box = root ? root.getBoundingClientRect() : { height: 0 };
        const doc = document.documentElement;
        const problems = [];
        if (root) {
          root.querySelectorAll('img').forEach((img) => { if (!img.hasAttribute('alt')) problems.push('img without alt'); });
          root.querySelectorAll('a[href], button, [role="button"], input, select, textarea').forEach((el) => {
            const name = (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.textContent || el.getAttribute('title') || el.getAttribute('placeholder') || '').trim();
            const labelled = el.id && document.querySelector('label[for="' + el.id + '"]');
            if (!name && !labelled) problems.push(el.tagName.toLowerCase() + ' without an accessible name');
          });
          const ids = {};
          root.querySelectorAll('[id]').forEach((el) => { ids[el.id] = (ids[el.id] || 0) + 1; });
          Object.keys(ids).forEach((id) => { if (ids[id] > 1) problems.push('duplicate id "' + id + '"'); });
        }
        return {
          height: Math.round(box.height),
          overflow: doc.scrollWidth > window.innerWidth + 1,
          scrollWidth: doc.scrollWidth,
          cls: Math.round((window.__cls || 0) * 1000) / 1000,
          basic: Array.from(new Set(problems)),
        };
      });

      let axe = null;
      if (axeSource && vp === 'mobile') {
        await page.addScriptTag({ content: axeSource });
        axe = await page.evaluate(async () => {
          const r = await window.axe.run(document.querySelector('[data-ai-section-root]') || document, { resultTypes: ['violations'] });
          return r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length }));
        });
      }

      const shot = join(job.outDir, `${p.name}-${vp}.png`);
      const clipHeight = Math.max(200, Math.min(2400, metrics.height + 40));
      await page.screenshot({ path: shot, clip: { x: 0, y: 0, width: viewport.width, height: clipHeight } });
      out.pages.push({ name: p.name, lang: p.lang, viewport: vp, width: viewport.width, screenshot: shot, ...metrics, axe });
      await page.close();
    }
  }
} finally {
  await browser.close();
}

console.log(JSON.stringify(out));
