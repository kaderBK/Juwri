// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Owner dashboard redesign — visual baseline (rev/mobile-app plan, step 2).
 *
 * Captures every parameter-free owner dashboard page (routes in the `owner.screen`
 * middleware group) in every variant the redesign must keep "same or better":
 * phone + desktop widths × light + dark × English + Arabic (RTL). Run it once as
 * `before` on main, again as `after` on the redesign branch, then open the
 * generated compare page.
 *
 *   E2E_BASE_URL=https://your-site.test E2E_RUN_BASELINE=1 E2E_BASELINE_LABEL=before \
 *     npm run test:e2e -- redesign-baseline
 *
 * Env:
 *   E2E_BASELINE_LABEL      folder name under __screenshots__/baseline (default "before")
 *   E2E_BASELINE_VIEWPORTS  "390x844,1280x900"
 *   E2E_BASELINE_THEMES     "light,dark"
 *   E2E_BASELINE_LOCALES    "en,ar"
 *   E2E_BASELINE_ONLY       comma list of route URIs to limit the sweep
 *   E2E_BASELINE_PATHS      comma list of exact paths to shoot instead (may include ids,
 *                           e.g. "dashboard,orders/12,stores/12/menu")
 *   E2E_BASELINE_CONCURRENCY parallel tabs in the one signed-in session (default 4)
 *   E2E_BASELINE_RESUME=1   keep variants already finished in this label's manifest
 *   E2E_BASELINE_AUDIT=1    also record per-page phone-audit signals (legacy Bootstrap
 *                           tables/buttons/cards outside the kit, kit adoption, tap
 *                           targets under 32px, text under 11px, elements wider than
 *                           the viewport) into manifest.json as `audit`
 *   E2E_BASELINE_NOSHOT=1   skip the screenshots (audit-only sweep)
 *
 * Output (gitignored): __screenshots__/baseline/<label>/<locale>-<theme>-<width>/<slug>.png,
 * manifest.json (status / landed path / console errors per shot), and
 * compare.html once both `before` and `after` exist.
 *
 * Credentials: ThemePackDemoSeeder owner (cfg.ownerEmail / cfg.ownerPass).
 */

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const CORE = path.resolve(__dirname, '..', '..', '..');

// GET pages that are not screens: downloads, OAuth hand-offs, polling feeds.
const SKIP = /(export|download|csv|\.pdf|oauth|callback|\/feed$|\/poll$|\.js$|\.json$|logout)/i;

function list(value, fallback) {
  return (value || fallback).split(',').map((s) => s.trim()).filter(Boolean);
}

function ownerRoutes() {
  const paths = list(process.env.E2E_BASELINE_PATHS, '').map((p) => p.replace(/^\/+/, ''));
  if (paths.length) return paths;
  const res = spawnSync('php', ['artisan', 'route:list', '--json', '--method=GET'], { cwd: CORE, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  if (res.status !== 0) {
    throw new Error(`route:list failed: ${(res.stderr || '').slice(0, 400)}`);
  }
  const routes = JSON.parse(res.stdout);
  const only = new Set(list(process.env.E2E_BASELINE_ONLY, ''));
  const seen = new Set();
  return routes
    .filter((r) => {
      const mw = Array.isArray(r.middleware) ? r.middleware.join(' ') : String(r.middleware || '');
      return /owner\.screen|TrackOwnerScreenView/.test(mw);
    })
    .map((r) => r.uri.replace(/^\/+/, ''))
    .filter((uri) => !uri.includes('{') && !SKIP.test(uri))
    .filter((uri) => (only.size ? only.has(uri) : true))
    .filter((uri) => (seen.has(uri) ? false : seen.add(uri)))
    .sort();
}

function slug(uri) {
  return uri.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').toLowerCase() || 'root';
}

async function login(page, cfg) {
  const cookies = await page.cookies().catch(() => []);
  if (cookies.length) await page.deleteCookie(...cookies);
  await page.goto(`${cfg.baseUrl}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#username, input[name="username"]', { timeout: cfg.navTimeout });
  await page.type('#username, input[name="username"]', cfg.ownerEmail);
  await page.type('#password, input[name="password"]', cfg.ownerPass);
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
  ]);
  if (new URL(page.url()).pathname === '/login') {
    throw new Error('redesign-baseline: owner credentials were rejected');
  }
}

function writeCompare(root) {
  const before = path.join(root, 'before', 'manifest.json');
  const after = path.join(root, 'after', 'manifest.json');
  if (!fs.existsSync(before) || !fs.existsSync(after)) return null;
  const a = JSON.parse(fs.readFileSync(before, 'utf8'));
  const b = JSON.parse(fs.readFileSync(after, 'utf8'));
  const byKey = new Map(b.shots.map((s) => [s.file, s]));
  const esc = (v) => String(v).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  // Regressions first: status change, NEW horizontal overflow, new console errors.
  const items = a.shots.map((s) => {
    const n = byKey.get(s.file) || {};
    const flags = [];
    if (n.status !== undefined && n.status !== s.status && ![0, 429].includes(n.status)) flags.push(`status ${s.status} → ${n.status}`);
    if ((n.overflowX || 0) > 2 && (n.overflowX || 0) > (s.overflowX || 0)) flags.push(`overflow +${n.overflowX}px`);
    // Rate limits (429) and capture hiccups are harness load, not the page.
    const real = (list) => (list || []).filter((e) => !/status of 429|^capture:/.test(e)).length;
    if (real(n.errors) > real(s.errors)) flags.push(`console errors ${real(s.errors)} → ${real(n.errors)}`);
    return { s, n, flags };
  }).sort((x, y) => y.flags.length - x.flags.length);
  const flagged = items.filter((i) => i.flags.length).length;
  const rows = items.map(({ s, flags }) => `<section id="${esc(s.file)}"><h2>${esc(s.route)} <small>${esc(s.variant)}</small>`
    + (flags.length ? ` <b>${esc(flags.join(' · '))}</b>` : '') + '</h2><div class="pair">'
    + `<figure><figcaption>before</figcaption><img loading="lazy" src="before/${esc(s.file)}"></figure>`
    + `<figure><figcaption>after</figcaption><img loading="lazy" src="after/${esc(s.file)}"></figure></div></section>`).join('\n');
  const html = '<!doctype html><meta charset="utf-8"><title>Owner redesign — before / after</title>'
    + '<style>body{font:14px system-ui;margin:24px;background:#f4f2ed}section{margin:0 0 32px}h2{font-size:15px}'
    + 'small{color:#666;font-weight:400}b{color:#b91c1c}.pair{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}'
    + 'figure{margin:0;background:#fff;padding:8px;border-radius:8px}img{width:100%;border:1px solid #ddd}</style>'
    + `<h1>Owner redesign — before / after</h1><p>${a.shots.length} shots · ${flagged} flagged (listed first)</p>${rows}`;
  const file = path.join(root, 'compare.html');
  fs.writeFileSync(file, html);
  fs.writeFileSync(path.join(root, 'compare.json'), JSON.stringify(items.filter((i) => i.flags.length).map((i) => ({ file: i.s.file, flags: i.flags })), null, 2));
  return file;
}

/**
 * Runs in the page. Phone-audit signals for one owner screen: how much of the
 * content area is still legacy Bootstrap chrome vs the owner UI kit, plus the
 * mobile-usability basics. Only the main content column is inspected (the shell
 * — header, sidebar, tab bar — is shared and audited once on its own).
 */
function auditProbe() {
  const main = document.querySelector('main, .app-content, #content, .page-content') || document.body;
  const vis = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
  const inKit = (el) => !!el.closest('.ui-scope, .ui-card, .ui-sheet, .ui-table');
  const all = (sel) => Array.from(main.querySelectorAll(sel)).filter(vis);
  const legacyTables = all('table.table').filter((t) => !t.classList.contains('ui-table'));
  const legacyBtns = all('.btn').filter((b) => !inKit(b) || /\bbtn-(primary|secondary|outline|light|danger|success|warning|info|dark)/.test(b.className));
  const legacyCards = all('.card').filter((c) => !c.classList.contains('ui-card'));
  const iw = window.innerWidth;
  const small = [];
  all('a[href], button, [role="button"], input:not([type="hidden"]), select, summary, label.form-check').forEach((el) => {
    const r = el.getBoundingClientRect();
    if ((r.height < 32 || r.width < 32) && r.height > 0 && small.length < 400) small.push(el);
  });
  let tiny = 0;
  const walker = document.createTreeWalker(main, NodeFilter.SHOW_TEXT);
  const seenParents = new Set();
  while (walker.nextNode()) {
    const t = walker.currentNode; const p = t.parentElement;
    if (!p || seenParents.has(p) || !t.textContent.trim() || !vis(p)) continue;
    seenParents.add(p);
    if (parseFloat(getComputedStyle(p).fontSize) < 11) tiny++;
  }
  const wide = [];
  all('*').forEach((el) => {
    if (wide.length >= 5) return;
    const r = el.getBoundingClientRect();
    if (r.right > iw + 1 || r.left < -1) {
      let q = el.parentElement; let clipped = false;
      while (q && q !== main.parentElement) { const ox = getComputedStyle(q).overflowX; if (ox === 'auto' || ox === 'scroll' || ox === 'hidden' || ox === 'clip') { clipped = true; break; } q = q.parentElement; }
      if (!clipped) wide.push((el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '')).slice(0, 60));
    }
  });
  return {
    kit: !!main.querySelector('.ui-scope'),
    kitNodes: all('[class*="ui-"]').length,
    legacyTables: legacyTables.length,
    legacyBtns: legacyBtns.length,
    legacyCards: legacyCards.length,
    smallTargets: small.length,
    tinyText: tiny,
    wide,
  };
}

module.exports = redesignBaseline;
module.exports.writeCompare = writeCompare;

async function redesignBaseline({ page, cfg, log, sleep }) {
  const label = process.env.E2E_BASELINE_LABEL || 'before';
  const root = path.join(CORE, 'tests', 'e2e', '__screenshots__', 'baseline');
  const outDir = path.join(root, label);
  fs.mkdirSync(outDir, { recursive: true });

  const routes = ownerRoutes();
  const viewports = list(process.env.E2E_BASELINE_VIEWPORTS, '390x844,1280x900').map((v) => v.split('x').map(Number));
  const themes = list(process.env.E2E_BASELINE_THEMES, 'light,dark');
  const locales = list(process.env.E2E_BASELINE_LOCALES, 'en,ar');
  log(`redesign-baseline: ${routes.length} routes × ${viewports.length} viewports × ${themes.length} themes × ${locales.length} locales → ${label}`);

  const host = new URL(cfg.baseUrl).hostname;
  const concurrency = Math.max(1, parseInt(process.env.E2E_BASELINE_CONCURRENCY || '4', 10));
  await login(page, cfg);

  // Workers share the signed-in browser context (one session, N tabs).
  const context = page.browserContext();
  async function openWorker() {
    const p = await context.newPage();
    p.setDefaultNavigationTimeout(cfg.navTimeout);
    p.setDefaultTimeout(cfg.navTimeout);
    p.on('dialog', (d) => d.dismiss().catch(() => {}));
    return p;
  }
  const workers = [page];
  for (let i = 1; i < concurrency; i++) workers.push(await openWorker());

  // A tab that stops answering the DevTools protocol is replaced, never waited on.
  const withTimeout = (promise, ms, what) => Promise.race([
    promise,
    new Promise((_, reject) => setTimeout(() => reject(new Error(`${what} timed out`)), ms)),
  ]);
  async function healthy(i, width, height) {
    try {
      await withTimeout(workers[i].setViewport({ width, height, deviceScaleFactor: 1 }), 15000, 'setViewport');
    } catch (e) {
      log(`redesign-baseline: replacing stuck tab ${i} (${e.message})`);
      await withTimeout(workers[i].close(), 5000, 'close').catch(() => {});
      workers[i] = await openWorker();
      await workers[i].setViewport({ width, height, deviceScaleFactor: 1 });
    }
  }

  async function capture(p, uri, locale, theme, variant) {
    const file = `${variant}/${slug(uri)}.png`;
    const errors = [];
    const onConsole = (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); };
    const onError = (e) => errors.push(`pageerror: ${String(e.message || e).slice(0, 200)}`);
    p.on('console', onConsole);
    p.on('pageerror', onError);
    let status = 0;
    let landed = '';
    let overflowX = 0;
    let height = 0;
    let audit = null;
    try {
      // "load" + a short settle: live boards poll forever and never reach network-idle.
      // Every owner page must answer under the locale prefix (a 404 here once hid
      // the whole hotel PMS module from the sweeps) — no bare-path fallback.
      const res = await p.goto(`${cfg.baseUrl}/${locale}/${uri}`, { waitUntil: 'load', timeout: cfg.navTimeout });
      status = res ? res.status() : 0;
      landed = new URL(p.url()).pathname;
      await p.waitForNetworkIdle({ idleTime: 400, timeout: 2500 }).catch(() => {});
      await p.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}.cookieConsentContainer{display:none!important}' }).catch(() => {});
      await sleep(200);
      // The cookie notice is hidden (not accepted) so it never covers content.
      // Horizontal overflow = content wider than the viewport (a phone layout break).
      ({ overflowX, height } = await p.evaluate(() => ({
        overflowX: Math.max(0, document.documentElement.scrollWidth - window.innerWidth),
        height: document.documentElement.scrollHeight,
      })));
      if (process.env.E2E_BASELINE_AUDIT === '1') audit = await p.evaluate(auditProbe).catch((e) => ({ error: String(e.message || e).slice(0, 120) }));
      if (process.env.E2E_BASELINE_NOSHOT !== '1') {
        await p.screenshot({ path: path.join(outDir, file), fullPage: true, captureBeyondViewport: true });
      }
    } catch (e) {
      errors.push(`capture: ${String(e.message || e).slice(0, 200)}`);
    } finally {
      p.off('console', onConsole);
      p.off('pageerror', onError);
    }
    return { route: uri, variant, file, status, landed, overflowX, height, errors: errors.slice(0, 5), ...(audit ? { audit } : {}) };
  }

  // Resume: keep finished variants from a previous run of the same label.
  const manifestFile = path.join(outDir, 'manifest.json');
  const prior = process.env.E2E_BASELINE_RESUME === '1' && fs.existsSync(manifestFile)
    ? JSON.parse(fs.readFileSync(manifestFile, 'utf8')) : null;
  const doneVariants = new Set(prior ? prior.variantsDone || [] : []);
  const shots = prior ? prior.shots.filter((s) => doneVariants.has(s.variant)) : [];
  const saveManifest = () => fs.writeFileSync(manifestFile, JSON.stringify({
    label, base: cfg.baseUrl, at: new Date().toISOString(), routes, variantsDone: [...doneVariants], shots,
  }, null, 2));
  const started = Date.now();
  for (const locale of locales) {
    for (const theme of themes) {
      await page.setCookie({ name: 'theme', value: theme, domain: host, path: '/' });
      for (const [width, height] of viewports) {
        const variant = `${locale}-${theme}-${width}`;
        if (doneVariants.has(variant)) {
          log(`redesign-baseline: ${variant} already done (resume)`);
          continue;
        }
        fs.mkdirSync(path.join(outDir, variant), { recursive: true });
        for (let i = 0; i < workers.length; i++) await healthy(i, width, height);
        const queue = routes.slice();
        await Promise.all(workers.map(async (_, i) => {
          while (queue.length) {
            const uri = queue.shift();
            try {
              shots.push(await withTimeout(capture(workers[i], uri, locale, theme, variant), cfg.navTimeout + 30000, 'capture'));
            } catch (e) {
              shots.push({ route: uri, variant, file: `${variant}/${slug(uri)}.png`, status: 0, landed: '', overflowX: 0, height: 0, errors: [`capture: ${e.message}`] });
              await withTimeout(workers[i].close(), 5000, 'close').catch(() => {});
              workers[i] = await openWorker();
              await workers[i].setViewport({ width, height, deviceScaleFactor: 1 });
            }
          }
        }));
        doneVariants.add(variant);
        saveManifest();
        if (shots.some((s) => s.landed.endsWith('/login'))) {
          throw new Error('redesign-baseline: session expired mid-sweep (landed on /login)');
        }
        log(`redesign-baseline: ${variant} done (${shots.length} shots, ${Math.round((Date.now() - started) / 1000)}s)`);
      }
    }
  }
  shots.sort((a, b) => a.file.localeCompare(b.file));

  saveManifest();
  const bad = shots.filter((s) => s.status >= 500);
  log(`redesign-baseline: ${shots.length} shots in ${outDir}; ${bad.length} with 5xx`);
  const compare = writeCompare(root);
  if (compare) log(`redesign-baseline: compare page → ${compare}`);
}
