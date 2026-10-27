// P3-04 browser half of the theme conformance gates. Node 20+, headless Chrome from the puppeteer in core/package.json
// (run `npm ci` in core first) plus the four devDependencies in this folder's package.json (axe-core, lighthouse,
// pixelmatch, pngjs; `npm ci` here). Never run next to a full test suite.
//
//   node browser-job.mjs [--steps axe,keyboard,csp,lighthouse,rtl] [--themes table,brew,...] [--root DIR]
//                        [--baseline-root DIR] [--items 30,300] [--runs 1] [--quick]
//
//   --root           tree to test: the Blade-rendered tree (THEME_CONFORMANCE_OUT of ThemeConformanceTest, keep it with
//                    THEME_CONFORMANCE_OUT=/some/dir) or, by default, kit-snapshot/ (the prototypes as designed).
//   --baseline-root  RTL baseline (the prototypes as designed); default kit-snapshot/. Screenshots are taken from both
//                    trees in the same Chrome and diffed, so no binary baselines are committed.
//   --quick          axe + keyboard + RTL in light only, Lighthouse once per page: a smoke run.
//
// Steps (THEME-CONTRACT §11): axe = kit tools/a11y.mjs (WCAG 2.2 A/AA, en+ar x light/dark x 390/1440, 320px reflow);
// keyboard = kit tools/keyboard.mjs (WCAG 2.1.1/2.1.2/2.4.3/2.4.7/2.4.11, dialogs); csp = every page in a real
// Chrome under the storefront policy (csp-policy.json, P1-20); lighthouse = §9 budgets at 30 and 300 catalogue items,
// en + ar; rtl = every ar page vs the baseline tree, 390/1440 x light/dark, <= 0.1 % of pixels.
//
// Report vs enforce: modes.json (kit_mode / premium_mode, env THEME_CONFORMANCE_KIT / THEME_CONFORMANCE_PREMIUM).
// A failing theme only sets a non-zero exit code when its class is `enforce`. The JSON report is written to
// $REPORT_DIR (default ./out) either way.
import { spawn } from 'node:child_process';
import { createReadStream, existsSync, mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { dirname, extname, join, resolve, sep } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { gzipSync } from 'node:zlib';
import { inflate } from './inflate.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(HERE, '..', '..', '..');
const SNAPSHOT = join(HERE, 'kit-snapshot');
const argv = process.argv.slice(2);
const opt = (name, dflt) => { const i = argv.indexOf(`--${name}`); return i >= 0 ? argv[i + 1] : dflt; };
const QUICK = argv.includes('--quick');
const ROOT = resolve(opt('root', SNAPSHOT));
const BASELINE = resolve(opt('baseline-root', SNAPSHOT));
const STEPS = opt('steps', 'axe,keyboard,csp,lighthouse,rtl').split(',');
const ITEMS = opt('items', '30,300').split(',').map(Number);
const RUNS = Number(opt('runs', QUICK ? 1 : 3));
const REPORT_DIR = resolve(process.env.REPORT_DIR || join(HERE, 'out'));

// ── modes ────────────────────────────────────────────────────────────────────────────────────────────────────────────
const modes = JSON.parse(readFileSync(join(HERE, 'modes.json'), 'utf8'));
for (const [k, env] of [['kit_mode', 'THEME_CONFORMANCE_KIT'], ['premium_mode', 'THEME_CONFORMANCE_PREMIUM']]) {
  if (['report', 'enforce'].includes(process.env[env])) modes[k] = process.env[env];
  if (!['report', 'enforce'].includes(modes[k])) throw new Error(`modes.json ${k} must be report or enforce`);
}
const modeOf = (theme) => (modes.kit_themes.includes(theme) && !(modes.report_until_ported || []).includes(theme) ? modes.kit_mode : modes.premium_mode);
const THEMES = opt('themes', [...modes.kit_themes, ...modes.premium_themes].join(',')).split(',');

// ── tooling ──────────────────────────────────────────────────────────────────────────────────────────────────────────
const need = (what, fn) => { try { return fn(); } catch (e) { console.error(`Missing ${what}: ${e.message.split('\n')[0]}`); process.exit(2); } };
const puppeteer = need('puppeteer (run `npm ci` in core/)', () => createRequire(join(REPO, 'core', 'package.json'))('puppeteer'));
const own = createRequire(join(HERE, 'package.json'));
const axePath = need('axe-core (run `npm ci` in tools/themes/conformance)', () => own.resolve('axe-core/axe.min.js'));
const CHROME = process.env.CHROME || puppeteer.executablePath();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ── static server with the storefront CSP, gzip and ?items=N catalogue sizing ───────────────────────────────────────
const CSP = Object.entries(JSON.parse(readFileSync(join(HERE, 'csp-policy.json'), 'utf8')).directives).map(([k, v]) => `${k} ${v}`).join('; ');
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.woff2': 'font/woff2', '.webp': 'image/webp' };
const COMPRESSIBLE = new Set(['.html', '.css', '.js', '.mjs', '.json', '.svg']);
function serve(root) {
  const server = createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    const file = resolve(root, '.' + decodeURIComponent(u.pathname));
    if (file !== root && !file.startsWith(root + sep)) { res.writeHead(403).end(); return; }
    let p = file;
    if (existsSync(p) && statSync(p).isDirectory()) p = join(p, 'index.html');
    if (!existsSync(p)) { res.writeHead(404).end('not found'); return; }
    const ext = extname(p);
    const headers = { 'Content-Type': TYPES[ext] || 'application/octet-stream', 'Content-Security-Policy': CSP, 'Cache-Control': 'no-store' };
    const gz = COMPRESSIBLE.has(ext) && /\bgzip\b/.test(req.headers['accept-encoding'] || '');
    if (ext === '.html' || gz) {
      let body = readFileSync(p);
      if (ext === '.html' && u.searchParams.has('items')) body = Buffer.from(inflate(body.toString('utf8'), Number(u.searchParams.get('items'))).html);
      if (gz) { body = gzipSync(body, { level: 9 }); headers['Content-Encoding'] = 'gzip'; }
      headers['Content-Length'] = body.length;
      res.writeHead(200, headers).end(body);
    } else {
      res.writeHead(200, headers); createReadStream(p).pipe(res);
    }
  });
  return new Promise((ok) => server.listen(0, '127.0.0.1', () => ok({ port: server.address().port, close: () => server.close() })));
}

const SKIP = { book: ['cart'] }; // meta-refresh redirect page (the kit's own tools skip it too)
const pagesOf = (root, theme, loc) => {
  const dir = join(root, 'prototypes', theme, loc);
  return existsSync(dir) ? readdirSync(dir).filter((f) => f.endsWith('.html')).map((f) => f.slice(0, -5)).filter((p) => !(SKIP[theme] || []).includes(p)).sort() : [];
};

// ── steps: each returns { ok, summary, details } for one theme ──────────────────────────────────────────────────────
function runNode(script, args, env) {
  return new Promise((ok) => {
    const p = spawn(process.execPath, ['--experimental-websocket', script, ...args], { cwd: ROOT, env: { ...process.env, ...env } });
    let out = '';
    p.stdout.on('data', (d) => (out += d));
    p.stderr.on('data', (d) => (out += d));
    p.on('close', (code) => ok({ code, out }));
  });
}
const tail = (s, n = 12) => s.trim().split('\n').slice(-n).join('\n');

// Every job picks its own block of ports: a fixed 8790/8791/9335/9340.. collided with another job or an e2e server on the same host
// (the keyboard pass then walked someone else's pages: 1 tab stop per run, "page has no #main").
const PORT_BASE = Number(process.env.JOB_PORT_BASE || 20000 + Math.floor(Math.random() * 30000));
async function axeStep(theme) {
  const r = await runNode(join(ROOT, 'tools', 'a11y.mjs'), [theme], { AXE: axePath, CHROME, A11Y_PORT: String(PORT_BASE), A11Y_CSP_PORT: String(PORT_BASE + 1) });
  return { ok: r.code === 0 && !/checked: 0;/.test(r.out), summary: r.out.trim().split('\n').pop(), details: tail(r.out) };
}
async function keyboardStep(theme) {
  const r = await runNode(join(ROOT, 'tools', 'keyboard.mjs'), [`${theme}/`, ...(QUICK ? ['--quick'] : [])], { CHROME, K_PORT: String(PORT_BASE + 10), K_SRV: String(PORT_BASE + 20) });
  return { ok: r.code === 0 && !/pages 0,/.test(r.out), summary: r.out.trim().split('\n').pop(), details: tail(r.out) };
}

async function cspStep(theme, browser, origin) {
  const bad = [];
  let n = 0;
  for (const loc of ['en', 'ar']) {
    for (const page of pagesOf(ROOT, theme, loc)) {
      for (const scheme of QUICK ? ['light'] : ['light', 'dark']) {
        const pg = await browser.newPage();
        await pg.setViewport({ width: 390, height: 844, isMobile: true });
        const seen = [];
        await pg.evaluateOnNewDocument(() => { window.__csp = []; document.addEventListener('securitypolicyviolation', (e) => window.__csp.push(`${e.violatedDirective} ${e.blockedURI || ''} ${e.sample || ''}`.trim())); });
        pg.on('console', (m) => { if (/Content Security Policy|Refused to/.test(m.text())) seen.push(m.text().slice(0, 160)); });
        await pg.goto(`${origin}/prototypes/${theme}/${loc}/${page}.html?bar=0&scheme=${scheme}`, { waitUntil: 'load' });
        await sleep(150);
        const events = await pg.evaluate(() => window.__csp);
        await pg.close();
        n++;
        for (const v of new Set([...events, ...seen])) bad.push(`${theme}/${loc}/${page} ${scheme}: ${v}`);
      }
    }
  }
  return { ok: bad.length === 0, summary: `${n} page loads, ${bad.length} CSP violation(s)`, details: bad.slice(0, 20).join('\n') };
}

// Budgets for the Blade reference theme, measured ON THE RUNNER (Linux, headless Chrome, Lighthouse mobile preset, median of 3):
// `table` en/ar at 30 and 300 items: lcp 1.96-2.71 s, cls <= 0.003, tbt <= 3 ms, script 29.5 KB, css 17.1 KB, fonts 93.5 KB (2 files) en /
// 179.5 KB (4 files) ar. The Arabic page carries IBM Plex Sans Arabic (2 weights) next to the Latin pair, hence the larger font budget.
// The limits sit ~15-20 % above the worst measured value so run-to-run noise does not break the build.
const BUDGET = { lcp: 3200, cls: 0.1, tbt: 200, script: 92160, stylesheet: 40960, fontBytes: 204800, fontCount: 4, thirdParty: 0, a11y: 0.95 };
const medianOf = (xs) => [...xs].sort((a, b) => a - b)[Math.floor(xs.length / 2)];
async function lighthouseStep(theme, browser, origin, lighthouse) {
  const failures = [], rows = [];
  for (const loc of ['en', 'ar']) {
    for (const items of ITEMS) {
      const url = `${origin}/prototypes/${theme}/${loc}/home.html?bar=0&items=${items}`;
      const runs = [];
      for (let i = 0; i < RUNS; i++) {
        const { lhr } = await lighthouse(url, { port: Number(new URL(browser.wsEndpoint()).port), output: 'json', logLevel: 'error',
          onlyCategories: ['performance', 'accessibility', 'best-practices'] });
        runs.push(lhr);
      }
      runs.sort((a, b) => a.audits['largest-contentful-paint'].numericValue - b.audits['largest-contentful-paint'].numericValue);
      const lhr = runs[Math.floor(runs.length / 2)];
      const a = lhr.audits;
      const rs = Object.fromEntries((a['resource-summary'].details?.items ?? []).map((x) => [x.resourceType, x]));
      const doc = (a['network-requests'].details?.items ?? []).find((x) => x.resourceType === 'Document');
      const m = { lcp: a['largest-contentful-paint'].numericValue, cls: a['cumulative-layout-shift'].numericValue, tbt: medianOf(runs.map((r) => r.audits['total-blocking-time'].numericValue)), // the median of the runs' own TBT (the run picked for the other metrics is the LCP median: one slow-start run made book 878 ms in a 5 KB-script page)
       
        script: rs.script?.transferSize ?? 0, stylesheet: rs.stylesheet?.transferSize ?? 0, fontBytes: rs.font?.transferSize ?? 0, fontCount: rs.font?.requestCount ?? 0,
        thirdParty: rs['third-party']?.requestCount ?? 0, a11y: lhr.categories.accessibility.score, perf: lhr.categories.performance.score,
        docGz: doc?.transferSize ?? 0 };
      const where = `${theme}/${loc} ${items} items`;
      rows.push({ where, ...Object.fromEntries(Object.entries(m).map(([k, v]) => [k, Math.round(v * 1000) / 1000])), tbtRuns: runs.map((r) => Math.round(r.audits['total-blocking-time'].numericValue)).join('/') });
      // Arabic: a two-family Latin pair (fraunces + jakarta: 3 files) plus the shared IBM Plex Sans Arabic 400/700 is 5 files, 162-186 KB: the
      // byte budget (200 KB) is the real limit, so the Arabic page may use 5 files (owner decision default, P3-15; the English page stays at 4)
      const budget = loc === 'ar' ? { ...BUDGET, fontCount: 5 } : BUDGET;
      for (const [k, limit] of Object.entries(budget)) {
        if (k === 'a11y' ? m[k] < limit : m[k] > limit) failures.push(`${where}: ${k} ${Math.round(m[k] * 1000) / 1000} vs budget ${limit}`);
      }
      for (const id of ['unsized-images', 'font-display', 'uses-text-compression']) {
        if (a[id] && a[id].score !== null && a[id].score < 1) failures.push(`${where}: ${id} failed`);
      }
    }
  }
  return { ok: failures.length === 0, summary: `${rows.length} runs, ${failures.length} budget failure(s)`, details: failures.slice(0, 20).join('\n'), rows };
}

async function rtlStep(theme, browser, origin, baseOrigin) {
  const { PNG } = need('pngjs', () => own('pngjs'));
  const pixelmatch = need('pixelmatch', () => own('pixelmatch')).default ?? own('pixelmatch');
  const bad = [];
  let n = 0, worst = 0;
  const shoot = async (o, page, loc, view, scheme) => {
    const pg = await browser.newPage();
    await pg.setBypassCSP(true); // screenshots only: the animation-freeze style tag is inline
    await pg.setViewport({ width: view.w, height: 900, isMobile: view.w < 768 });
    await pg.goto(`${o}/prototypes/${theme}/${loc}/${page}.html?bar=0&scheme=${scheme}`, { waitUntil: 'load' });
    await pg.evaluate(() => document.fonts.ready);
    await pg.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}' });
    const buf = await pg.screenshot({ fullPage: true });
    await pg.close();
    return PNG.sync.read(Buffer.from(buf));
  };
  // modes.json `rtl.<theme>`: { limit: share of pixels that may differ, exempt: { page: reason } } for a theme ported to Blade, whose
  // pages hold real store data and the product's wording instead of the prototype's showcase content. An exempt page is named in the summary.
  const cfg = (modes.rtl && modes.rtl[theme]) || {};
  const limit = cfg.limit ?? 0.001;
  const exempt = Object.keys(cfg.exempt || {});
  for (const page of pagesOf(ROOT, theme, 'ar').filter((p) => !exempt.includes(p))) {
    for (const view of [{ name: 'phone', w: 390 }, { name: 'desktop', w: 1440 }]) {
      for (const scheme of QUICK ? ['light'] : ['light', 'dark']) {
        const a = await shoot(baseOrigin, page, 'ar', view, scheme), b = await shoot(origin, page, 'ar', view, scheme);
        n++;
        const where = `${theme}/ar/${page} ${view.name} ${scheme}`;
        if (a.width !== b.width || a.height !== b.height) { bad.push(`${where}: size ${a.width}x${a.height} vs ${b.width}x${b.height}`); continue; }
        const diff = pixelmatch(a.data, b.data, null, a.width, a.height, { threshold: 0.1 });
        const ratio = diff / (a.width * a.height);
        worst = Math.max(worst, ratio);
        if (ratio > limit) bad.push(`${where}: ${(ratio * 100).toFixed(3)} % of pixels differ`);
      }
    }
  }
  return { ok: bad.length === 0, summary: `${n} screens, worst diff ${(worst * 100).toFixed(3)} %, ${bad.length} over ${(limit * 100).toFixed(1)} %${exempt.length ? `, exempt: ${exempt.join('/')}` : ''}`, details: bad.slice(0, Number(process.env.RTL_DETAILS_MAX) || 20).join('\n') };
}

// ── main ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
console.log(`conformance browser job: root=${ROOT}\n  themes=${THEMES.join(',')} steps=${STEPS.join(',')} quick=${QUICK} kit=${modes.kit_mode} premium=${modes.premium_mode}`);
const report = [];
const needBrowser = STEPS.some((s) => ['csp', 'lighthouse', 'rtl'].includes(s));
let browser, srv, baseSrv, lighthouse;
if (needBrowser) {
  browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  srv = await serve(ROOT);
  baseSrv = BASELINE === ROOT ? srv : await serve(BASELINE);
  if (STEPS.includes('lighthouse')) lighthouse = (await import(pathToFileURL(need('lighthouse (run `npm ci` in tools/themes/conformance)', () => own.resolve('lighthouse'))).href)).default;
}
try {
  for (const theme of THEMES) {
    for (const step of STEPS) {
      const t0 = Date.now();
      let r;
      try {
        if (step === 'axe') r = await axeStep(theme);
        else if (step === 'keyboard') r = await keyboardStep(theme);
        else if (step === 'csp') r = await cspStep(theme, browser, `http://127.0.0.1:${srv.port}`);
        else if (step === 'lighthouse') r = await lighthouseStep(theme, browser, `http://127.0.0.1:${srv.port}`, lighthouse);
        else if (step === 'rtl') r = await rtlStep(theme, browser, `http://127.0.0.1:${srv.port}`, `http://127.0.0.1:${baseSrv.port}`);
        else throw new Error(`unknown step ${step}`);
      } catch (e) { r = { ok: false, summary: `crashed: ${e.message}`, details: String(e.stack || e) }; }
      const entry = { theme, step, mode: modeOf(theme), seconds: Math.round((Date.now() - t0) / 1000), ...r };
      report.push(entry);
      console.log(`${r.ok ? 'PASS' : modeOf(theme) === 'enforce' ? 'FAIL' : 'REPORT'}  ${theme.padEnd(8)} ${step.padEnd(10)} ${r.summary}`);
      if (!r.ok && r.details) console.log(r.details.split('\n').map((l) => '        ' + l).join('\n'));
    }
  }
} finally {
  await browser?.close();
  srv?.close(); if (baseSrv !== srv) baseSrv?.close();
}
mkdirSync(REPORT_DIR, { recursive: true });
writeFileSync(join(REPORT_DIR, 'browser-job-report.json'), JSON.stringify({ root: ROOT, modes, report }, null, 1));
const blocking = report.filter((e) => !e.ok && e.mode === 'enforce');
const reported = report.filter((e) => !e.ok && e.mode !== 'enforce');
console.log(`\nresult: ${report.length - blocking.length - reported.length} pass, ${reported.length} report-only failure(s), ${blocking.length} blocking failure(s); report ${join(REPORT_DIR, 'browser-job-report.json')}`);
process.exit(blocking.length ? 1 : 0);
