#!/usr/bin/env node
// Check 6, visual half (THEME-CONTRACT §6, §11.6): screenshot each Arabic screen and diff it against a stored baseline.
//
// Needs (NOT installed in the design repo): npm i -D playwright pixelmatch pngjs ; npx playwright install chromium
// Usage:  node rtl/snapshot.mjs [--urls axe/urls.json] [--base http://localhost:8765]
//                               [--update]              write/overwrite baselines instead of comparing
//                               [--threshold 0.001]     max share of differing pixels (default 0.1 %)
//                               [--only <substring>]
// Pages come from the same file as axe (urls.json); only entries with lang "ar" are used, in the phone and desktop viewports,
// light and dark. Baselines live in rtl/baseline/<page>__<viewport>__<scheme>.png and should be exported/approved against
// designs/<theme>.pen (the ar frame) the first time; later runs catch regressions. A missing baseline fails the run.
// Diff images are written to rtl/diff/ (red = changed pixels). Animations are disabled and the caret is hidden.
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { chromium } from 'playwright';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

const args = Object.fromEntries(process.argv.slice(2).reduce((a, x, i, all) => {
  if (x.startsWith('--')) a.push([x.slice(2), all[i + 1] && !all[i + 1].startsWith('--') ? all[i + 1] : true]);
  return a;
}, []));
const cfg = JSON.parse(readFileSync(args.urls || 'axe/urls.json', 'utf8'));
const base = (args.base || process.env.BASE_URL || cfg.base || 'http://localhost:8765').replace(/\/$/, '');
const threshold = Number(args.threshold ?? 0.001);
const update = !!args.update;
const BASE_DIR = new URL('./baseline/', import.meta.url).pathname;
const DIFF_DIR = new URL('./diff/', import.meta.url).pathname;
mkdirSync(BASE_DIR, { recursive: true }); mkdirSync(DIFF_DIR, { recursive: true });

const browser = await chromium.launch();
let failed = 0, n = 0;
for (const p of cfg.pages.filter(p => p.lang === 'ar' && (!args.only || p.name.includes(args.only)))) {
  for (const vp of cfg.viewports || [{ name: 'phone', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 900 }]) {
    for (const scheme of cfg.schemes || ['light', 'dark']) {
      n++;
      const name = `${p.name.replace(/\//g, '-')}__${vp.name}__${scheme}`;
      const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height }, colorScheme: scheme, locale: 'ar', reducedMotion: 'reduce' });
      const page = await ctx.newPage();
      await page.goto(base + p.path + (cfg.scheme_mode === 'query' ? `?scheme=${scheme}` : ''), { waitUntil: 'networkidle' });
      if (cfg.scheme_mode !== 'query') await page.evaluate(s => document.documentElement.setAttribute('data-scheme', s), scheme);
      await page.evaluate(() => document.fonts.ready);
      const shot = await page.screenshot({ fullPage: true, animations: 'disabled', caret: 'hide' });
      await ctx.close();
      const file = `${BASE_DIR}${name}.png`;
      if (update || !existsSync(file)) {
        if (!update) { console.log(`MISSING baseline ${name} (run with --update after checking it against designs/)`); failed++; continue; }
        writeFileSync(file, shot); console.log(`wrote ${name}`); continue;
      }
      const a = PNG.sync.read(readFileSync(file)), b = PNG.sync.read(shot);
      if (a.width !== b.width || a.height !== b.height) {
        console.log(`FAIL ${name}: size ${b.width}x${b.height} vs baseline ${a.width}x${a.height}`); failed++; writeFileSync(`${DIFF_DIR}${name}.png`, shot); continue;
      }
      const diff = new PNG({ width: a.width, height: a.height });
      const bad = pixelmatch(a.data, b.data, diff.data, a.width, a.height, { threshold: 0.1 });
      const share = bad / (a.width * a.height);
      if (share > threshold) { failed++; writeFileSync(`${DIFF_DIR}${name}.png`, PNG.sync.write(diff)); console.log(`FAIL ${name}: ${(share * 100).toFixed(3)} % pixels differ (limit ${(threshold * 100).toFixed(3)} %)`); }
      else console.log(`ok   ${name} (${(share * 100).toFixed(3)} %)`);
    }
  }
}
await browser.close();
console.log(`\nrtl snapshot: ${n} screenshot(s), ${failed} failure(s)${update ? ' (baselines updated)' : ''}`);
process.exit(failed ? 1 : 0);
