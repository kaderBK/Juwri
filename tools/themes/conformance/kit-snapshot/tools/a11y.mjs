// axe-core accessibility run over every HTML prototype page, in headless Chrome over CDP.
// No npm install: point AXE at any local axe.min.js (axe-core ≥ 4.10).
//
//   AXE=/path/to/axe-core/axe.min.js node --experimental-websocket tools/a11y.mjs [theme…]
//
// Each page runs at phone (390) and desktop (1280), light and dark, with WCAG 2.2 A/AA rules
// (contract §8). Writes out/a11y.json and prints one line per distinct violation. Exit 1 on any
// serious or critical violation. The CI version is tools/conformance/axe/run-axe.mjs (Playwright).
import { spawn } from 'node:child_process';
import { mkdirSync, readdirSync, readFileSync, writeFileSync, rmSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CHROME = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
if (!process.env.AXE) { console.error('Set AXE to a local axe.min.js'); process.exit(2); }
const AXE = readFileSync(process.env.AXE, 'utf8');
const THEMES = ['table', 'brew', 'aisle', 'basket', 'book', 'carely'];
const PORT = Number(process.env.A11Y_PORT || 9335); // juwri: overridable
const SKIP = { book: ['cart'] }; // redirect page
const VIEWS = [
  { name: 'phone', width: 390, height: 844, mobile: true, schemes: ['light', 'dark'] },
  { name: 'desktop', width: 1440, height: 900, mobile: false, schemes: ['light', 'dark'] }, // juwri: 1440 (P3-04), kit had 1280
];
// WCAG 1.4.10 reflow: at 320 CSS px nothing may scroll sideways (wide tables excepted by the rule).
const REFLOW = { name: 'reflow-320', width: 320, height: 640, mobile: true };
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];
const args = process.argv.slice(2).filter((a) => !a.startsWith('--'));
const themes = args.length ? args : THEMES;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let ws, seq = 0;
const waiting = new Map(), listeners = [];
const send = (method, params = {}, sessionId) => new Promise((res, rej) => {
  const id = ++seq; waiting.set(id, { res, rej, method });
  ws.send(JSON.stringify({ id, method, params, sessionId }));
});
const once = (method, sessionId) => new Promise((res) => listeners.push({ method, sessionId, res }));
async function connect() {
  for (let i = 0; i < 50; i++) {
    try {
      const v = await (await fetch(`http://127.0.0.1:${PORT}/json/version`)).json();
      ws = new WebSocket(v.webSocketDebuggerUrl);
      await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; });
      ws.onmessage = (e) => {
        const m = JSON.parse(e.data);
        if (m.id && waiting.has(m.id)) {
          const w = waiting.get(m.id); waiting.delete(m.id);
          m.error ? w.rej(new Error(w.method + ': ' + m.error.message)) : w.res(m.result);
        } else if (m.method) {
          for (let k = listeners.length - 1; k >= 0; k--) {
            const l = listeners[k];
            if (l.method === m.method && l.sessionId === m.sessionId) { listeners.splice(k, 1); l.res(m.params); }
          }
        }
      };
      return;
    } catch { await sleep(200); }
  }
  throw new Error('Chrome did not start');
}

async function check(url, view) {
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId: s } = await send('Target.attachToTarget', { targetId, flatten: true });
  await send('Page.enable', {}, s);
  await send('Emulation.setDeviceMetricsOverride',
    { width: view.width, height: view.height, deviceScaleFactor: 1, mobile: view.mobile }, s);
  const loaded = once('Page.loadEventFired', s);
  await send('Page.navigate', { url }, s);
  await loaded;
  await send('Runtime.evaluate', { expression: 'document.fonts.ready', awaitPromise: true }, s);
  // Finish entrance animations first: a sheet caught mid fade-in reads as low contrast.
  await send('Runtime.evaluate', { expression: `document.head.insertAdjacentHTML('beforeend',
    '<style>*,*::before,*::after{animation:none!important;transition:none!important}</style>')` }, s);
  if (view.name === REFLOW.name) {
    const w = await send('Runtime.evaluate', { returnByValue: true, expression: `(() => {
      const W = document.documentElement.clientWidth, out = [];
      if (document.documentElement.scrollWidth <= W) return [];
      for (const e of document.querySelectorAll('body *')) {
        const r = e.getBoundingClientRect();
        if (r.width && (r.right > W + 1 || r.left < -1) && !e.closest('table,[data-scroll-x],.c-chips,.c-catnav,[class*=track],[class*=rail],[class*=scroller]'))
          out.push(e.tagName.toLowerCase() + '.' + [...e.classList].join('.'));
      }
      return out.length ? [{ id: 'reflow-320', impact: 'serious', help: 'Content scrolls sideways at 320px (WCAG 1.4.10)',
        nodes: out.slice(0, 5).map(t => ({ target: t, summary: '' })) }] : [];
    })()` }, s);
    await send('Target.closeTarget', { targetId });
    return w.result.value;
  }
  // Any view: the page itself must never scroll sideways (wide parts scroll inside their own box).
  const ov = await send('Runtime.evaluate', { returnByValue: true,
    expression: 'document.documentElement.scrollWidth - document.documentElement.clientWidth' }, s);
  if (ov.result.value > 1) {
    await send('Target.closeTarget', { targetId });
    return [{ id: 'page-overflow-x', impact: 'serious', help: `Page scrolls sideways by ${ov.result.value}px`, nodes: [{ target: 'html', summary: '' }] }];
  }
  await send('Runtime.evaluate', { expression: AXE }, s);
  const r = await send('Runtime.evaluate', {
    expression: `axe.run(document, { runOnly: { type: 'tag', values: ${JSON.stringify(TAGS)} } })
      .then(r => r.violations.map(v => ({ id: v.id, impact: v.impact, help: v.help,
        nodes: v.nodes.map(n => ({ target: n.target.join(' '), summary: n.failureSummary })) })))`,
    awaitPromise: true, returnByValue: true,
  }, s);
  await send('Target.closeTarget', { targetId });
  if (r.exceptionDetails) throw new Error(url + ': ' + r.exceptionDetails.text);
  return r.result.value;
}

async function cspCheck(url) {
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId: s } = await send('Target.attachToTarget', { targetId, flatten: true });
  await send('Page.enable', {}, s);
  await send('Page.addScriptToEvaluateOnNewDocument', { source: `window.__csp = [];
    document.addEventListener('securitypolicyviolation', e => window.__csp.push(e.violatedDirective + ' ' + (e.blockedURI || '') + ' ' + (e.sample || '')));` }, s);
  const loaded = once('Page.loadEventFired', s);
  await send('Page.navigate', { url }, s);
  await loaded;
  await sleep(200);
  const r = await send('Runtime.evaluate', { expression: 'window.__csp', returnByValue: true }, s);
  await send('Target.closeTarget', { targetId });
  const v = [...new Set(r.result.value || [])];
  return v.length ? [{ id: 'csp', impact: 'serious', help: 'Content-Security-Policy violation (storefront policy, no style attributes)',
    nodes: v.slice(0, 5).map((t) => ({ target: t, summary: '' })) }] : [];
}

const profile = mkdtempSync(join(tmpdir(), 'a11y-'));
const chrome = spawn(CHROME, ['--headless=new', '--window-size=1280,900', `--remote-debugging-port=${PORT}`,
  `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', 'about:blank'], { stdio: 'ignore' });
// --csp: also load each page (phone, light) from tools/csp_server.py and fail on any CSP violation.
const CSP = process.argv.includes('--csp');
const CSP_PORT = Number(process.env.A11Y_CSP_PORT || 8790); // juwri: overridable
const server = CSP ? spawn('python3', [join(ROOT, 'tools', 'csp_server.py'), String(CSP_PORT)], { stdio: 'ignore' }) : null;
const report = [];
const byRule = new Map();
try {
  await connect();
  for (const theme of themes) {
    for (const loc of ['en', 'ar']) {
      const pages = readdirSync(join(ROOT, 'prototypes', theme, loc)).filter((f) => f.endsWith('.html'))
        .map((f) => f.slice(0, -5)).filter((p) => !(SKIP[theme] || []).includes(p));
      for (const page of pages) {
        for (const view of [...VIEWS, { ...REFLOW, schemes: ['light'] }]) {
          for (const scheme of view.schemes) {
            const url = pathToFileURL(join(ROOT, 'prototypes', theme, loc, page + '.html')).href + `?bar=0&scheme=${scheme}`;
            const where = `${theme}/${loc}/${page} ${view.name} ${scheme}`;
            const v = await check(url, view);
            report.push({ where, violations: v });
            for (const x of v) {
              const k = `${x.impact}|${x.id}`;
              if (!byRule.has(k)) byRule.set(k, { ...x, where: [], targets: new Set() });
              const e = byRule.get(k); e.where.push(where);
              x.nodes.forEach((n) => e.targets.add(n.target));
            }
          }
        }
      }
      if (CSP) {
        for (const page of pages) {
          const where = `${theme}/${loc}/${page} csp`;
          const v = await cspCheck(`http://127.0.0.1:${CSP_PORT}/prototypes/${theme}/${loc}/${page}.html`);
          report.push({ where, violations: v });
          for (const x of v) {
            const k = `${x.impact}|${x.id}`;
            if (!byRule.has(k)) byRule.set(k, { ...x, where: [], targets: new Set() });
            const e = byRule.get(k); e.where.push(where);
            x.nodes.forEach((n) => e.targets.add(n.target));
          }
        }
      }
      console.error(`${theme}/${loc}: done`);
    }
  }
} finally {
  try { ws?.close(); } catch {}
  chrome.kill(); server?.kill(); await sleep(300);
  rmSync(profile, { recursive: true, force: true });
}

mkdirSync(join(ROOT, 'out'), { recursive: true });
writeFileSync(join(ROOT, 'out', 'a11y.json'), JSON.stringify(report, null, 1));
const order = { critical: 0, serious: 1, moderate: 2, minor: 3 };
const rules = [...byRule.values()].sort((a, b) => order[a.impact] - order[b.impact]);
for (const r of rules) {
  console.log(`${r.impact.padEnd(8)} ${r.id} (${r.where.length} views): ${r.help}`);
  console.log(`         e.g. ${r.where[0]} → ${[...r.targets].slice(0, 3).join(' | ')}`);
}
console.log(`pages×views checked: ${report.length}; distinct rules violated: ${rules.length}`);
process.exit(rules.some((r) => r.impact === 'critical' || r.impact === 'serious') ? 1 : 0);
