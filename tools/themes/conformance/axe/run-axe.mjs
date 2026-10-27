#!/usr/bin/env node
// Check 4 (THEME-CONTRACT §8, §11.4): axe-core (WCAG 2.2 AA) over every screen x language x scheme x viewport.
//
// Needs (NOT installed in the design repo): npm i -D playwright @axe-core/playwright ; npx playwright install chromium
// Usage:  node axe/run-axe.mjs [--urls axe/urls.json] [--base http://localhost:8765] [--out axe/report.json]
//                              [--only <substring of page name>] [--impact minor|moderate|serious|critical]
// Exit code 1 when any violation at or above --impact (default: config axe.min_impact, else "minor") is found.
//
// urls.json shape (see urls.example.json):
//   base           origin to prefix to every page path (override with --base or env BASE_URL)
//   schemes        ["light","dark"]
//   scheme_mode    "attribute" (default: sets <html data-scheme>) | "query" (adds ?scheme=light|dark)
//   viewports      [{name,width,height}]       390 phone and 1440 desktop per §8
//   axe            {tags, disable_rules, min_impact}
//   pages          [{name, path, lang, states?: [{name, click?: selector, query?: "?state=sheet"}]}]
// A page may list `states` (open sheet, error banner...): each one is loaded as its own run, after an optional click.
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';

const args = Object.fromEntries(process.argv.slice(2).reduce((a, x, i, all) => {
  if (x.startsWith('--')) a.push([x.slice(2), all[i + 1] && !all[i + 1].startsWith('--') ? all[i + 1] : true]);
  return a;
}, []));
const cfg = JSON.parse(readFileSync(args.urls || 'axe/urls.json', 'utf8'));
const base = (args.base || process.env.BASE_URL || cfg.base || 'http://localhost:8765').replace(/\/$/, '');
const schemes = cfg.schemes || ['light', 'dark'];
const viewports = cfg.viewports || [{ name: 'phone', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }];
const tags = cfg.axe?.tags || ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const order = ['minor', 'moderate', 'serious', 'critical'];
const minImpact = order.indexOf(args.impact || cfg.axe?.min_impact || 'minor');
const pages = cfg.pages.filter(p => !args.only || p.name.includes(args.only));

const browser = await chromium.launch();
const results = [];
let failures = 0;
for (const p of pages) {
  for (const state of p.states || [{ name: '' }]) {
    for (const vp of viewports) {
      for (const scheme of schemes) {
        const ctx = await browser.newContext({
          viewport: { width: vp.width, height: vp.height },
          colorScheme: scheme,                 // also drives data-scheme="auto"
          locale: p.lang === 'ar' ? 'ar' : 'en',
          reducedMotion: 'reduce',
        });
        const page = await ctx.newPage();
        const q = [cfg.scheme_mode === 'query' ? `scheme=${scheme}` : '', (state.query || '').replace(/^\?/, '')].filter(Boolean).join('&');
        const url = base + p.path + (q ? `?${q}` : '');
        const label = `${p.name}${state.name ? ' [' + state.name + ']' : ''} ${vp.name} ${scheme}`;
        try {
          await page.goto(url, { waitUntil: 'networkidle' });
          if (cfg.scheme_mode !== 'query') await page.evaluate(s => document.documentElement.setAttribute('data-scheme', s), scheme);
          if (state.click) await page.click(state.click);
          await page.waitForTimeout(150);
          const r = await new AxeBuilder({ page }).withTags(tags).disableRules(cfg.axe?.disable_rules || []).analyze();
          const v = r.violations.filter(x => order.indexOf(x.impact || 'minor') >= minImpact);
          results.push({ label, url, violations: v.map(x => ({ id: x.id, impact: x.impact, help: x.help, helpUrl: x.helpUrl, nodes: x.nodes.map(n => ({ target: n.target, html: n.html.slice(0, 200) })) })) });
          if (v.length) {
            failures += v.length;
            console.log(`FAIL ${label}  ${url}`);
            for (const x of v) console.log(`  ${x.impact} ${x.id}: ${x.help} (${x.nodes.length} node(s)) e.g. ${x.nodes[0].target.join(' ')}`);
          } else console.log(`ok   ${label}`);
        } catch (e) {
          failures++; results.push({ label, url, error: String(e) });
          console.log(`ERR  ${label}  ${url}: ${e.message}`);
        }
        await ctx.close();
      }
    }
  }
}
await browser.close();
const out = args.out || 'axe/report.json';
mkdirSync(dirname(out), { recursive: true });
writeFileSync(out, JSON.stringify(results, null, 1));
console.log(`\naxe: ${results.length} run(s), ${failures} violation(s)/error(s). Report: ${out}`);
process.exit(failures ? 1 : 0);
