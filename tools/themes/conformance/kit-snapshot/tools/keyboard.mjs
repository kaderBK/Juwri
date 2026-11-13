// Keyboard and focus pass over every HTML prototype page, in headless Chrome over CDP.
// axe-core (tools/a11y.mjs) does not press keys; this does. No npm install.
//
//   node --experimental-websocket tools/keyboard.mjs [filter…] [--quick]
//
// filter: substrings of the page path ("table", "aisle/ar", "_customizer"); none = every page.
// --quick: light scheme only (skips the dark focus-contrast run).
//
// Pages are served by tools/csp_server.py (the storefront CSP), so proto.js runs as it would live.
// Every page × view (phone 390, desktop 1440) × scheme (light; dark for the focus-ring contrast):
//   1. presses Tab (real CDP key events) until focus cycles, then Shift+Tab back round the cycle;
//   2. checks (WCAG 2.2): every interactive element is reachable (2.1.1); no positive tabindex;
//      the skip link is the first stop and lands in #main (2.4.1); focus order follows the visual
//      reading order, mirrored for RTL (2.4.3); every stop shows an indicator ≥ 2 px with ≥ 3:1
//      against the pixels it covers when unfocused (2.4.7, 1.4.11); the focused element is not
//      entirely hidden by a fixed/sticky bar (2.4.11); no keyboard trap (2.1.2);
//   3. dialogs: a dialog open on load (static prototype state) must take the first Tab stop and
//      keep every stop inside it; an interactive (:target) dialog must take focus when opened,
//      keep Tab inside, and return focus to its trigger when closed. Esc needs script, which
//      prototypes do not have (README "No JS behaviour"): it is reported, not failed (see NO_JS).
// Writes out/keyboard.json. Exit 1 on any violation.
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync, rmSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CHROME = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const THEMES = ['table', 'brew', 'aisle', 'basket', 'book', 'carely'];
const PORT = Number(process.env.K_PORT || 9340), SRV = Number(process.env.K_SRV || 8791), PAR = 4; // juwri: overridable (concurrent jobs / e2e servers on one host) // Chrome debug ports PORT…PORT+PAR-1
const SKIP = { book: ['cart'] }; // meta-refresh redirect page
// States boards show several screens (and specimen dialogs) side by side: modal containment does not apply.
const BOARDS = ['states', 'kiosk-states'];
const VIEWS = [
  { name: 'phone', width: 390, height: 844, mobile: true },
  { name: 'desktop', width: 1440, height: 900, mobile: false },
];
// Allowed exceptions, listed so the report says what was not counted and why.
const ALLOWED = {
  'proto-bar': 'The prototype preview bar (.proto-bar, proto.js) is review chrome, not theme UI, and does not exist in the Blade theme. Page runs load ?bar=0 (as a11y.mjs does); the bar\'s own controls are checked in a separate "+bar" run on each home page, where only bar stops count.',
  'fixed-transition': 'Order is not compared across a fixed/sticky container boundary (sticky header, cart bar, sheet overlay, preview bar): their position is the viewport, not the reading flow. Order inside each container is still checked.',
  'column-jump': 'Moving up into the next column towards the inline end (desktop sidebars, card grids, side-by-side states) is reading order, not a backwards jump.',
  'board-dialogs': 'States boards (states, kiosk-states) show dialogs as specimens next to other screens: modal containment is not tested there.',
  'no-js-esc': 'Esc-to-close and focus return for always-open dialogs need script (core sheet-a11y JS, contract §8 4.1.2); prototypes have none, so these are reported as pending, not failed.',
  'skip-not-needed': 'A page whose first tab stop is already inside #main needs no skip link (gallery index).',
  'iframe': 'Customizer preview iframes are one stop group; the framed page is checked on its own.',
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const args = process.argv.slice(2);
const QUICK = args.includes('--quick');
const filters = args.filter((a) => !a.startsWith('--'));

// ── pages ────────────────────────────────────────────────────────────
function pages() {
  const out = [];
  for (const t of THEMES) for (const loc of ['en', 'ar']) {
    for (const f of readdirSync(join(ROOT, 'prototypes', t, loc)).filter((f) => f.endsWith('.html')).sort()) {
      const p = f.slice(0, -5);
      if ((SKIP[t] || []).includes(p)) continue;
      out.push({ id: `${t}/${loc}/${p}`, path: `prototypes/${t}/${loc}/${f}`, theme: t, page: p, board: BOARDS.includes(p) });
    }
  }
  const cz = join(ROOT, 'prototypes', '_customizer');
  if (existsSync(cz)) {
    out.push({ id: '_customizer/index', path: 'prototypes/_customizer/index.html', page: 'index', board: false });
    for (const loc of ['en', 'ar']) for (const f of readdirSync(join(cz, loc)).filter((f) => f.endsWith('.html')).sort())
      out.push({ id: `_customizer/${loc}/${f.slice(0, -5)}`, path: `prototypes/_customizer/${loc}/${f}`, page: f.slice(0, -5), board: false });
  }
  out.push({ id: 'index', path: 'prototypes/index.html', page: 'index', board: false });
  return out.filter((p) => !filters.length || filters.some((f) => p.id.includes(f)));
}

// ── minimal CDP client (same pattern as a11y.mjs / shoot.mjs), one headless Chrome per worker ──
// Separate browsers, not tabs of one: key events sent to several tabs of one browser at once can stall.
let seq = 0;
const waiting = new Map(), listeners = [], sess = new Map(), B = [];
const send = (method, params = {}, sessionId, b = 0) => new Promise((res, rej) => {
  const id = ++seq; waiting.set(id, { res, rej, method });
  (sessionId ? sess.get(sessionId) : B[b].ws).send(JSON.stringify({ id, method, params, sessionId }));
});
const once = (method, sessionId, ms = 15000) => new Promise((res) => {
  const l = { method, sessionId, res }; listeners.push(l);
  setTimeout(() => { const i = listeners.indexOf(l); if (i >= 0) { listeners.splice(i, 1); res(null); } }, ms);
});
function onmessage(e) {
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
}
async function startBrowser(b) {
  const port = PORT + b, profile = mkdtempSync(join(tmpdir(), 'kb-'));
  const proc = spawn(CHROME, ['--headless=new', '--window-size=1440,900', `--remote-debugging-port=${port}`,
    `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', 'about:blank'], { stdio: 'ignore' });
  for (let i = 0; i < 100; i++) {
    try {
      const v = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json();
      const ws = new WebSocket(v.webSocketDebuggerUrl);
      await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; });
      ws.onmessage = onmessage;
      B[b] = { ws, proc, profile };
      return;
    } catch { await sleep(200); }
  }
  throw new Error('Chrome did not start');
}
async function stopBrowser(b) {
  const x = B[b]; if (!x) return;
  try { x.ws.close(); } catch {}
  x.proc.kill(); await sleep(300);
  rmSync(x.profile, { recursive: true, force: true });
  B[b] = null;
}

// ── in-page helper (stringified and evaluated once per load) ─────────
function helper() {
  const W = window;
  const ids = new WeakMap(); let nextId = 1;
  const idOf = (e) => { if (!ids.has(e)) ids.set(e, nextId++); return ids.get(e); };
  const cv = document.createElement('canvas'); cv.width = cv.height = 1;
  const cx = cv.getContext('2d', { willReadFrequently: true });
  const rgba = (c) => { // any CSS colour → [r,g,b,a] (canvas resolves oklch, color-mix, color())
    if (!c || c === 'transparent') return [0, 0, 0, 0];
    cx.clearRect(0, 0, 1, 1); cx.fillStyle = '#000'; cx.fillStyle = c; cx.fillRect(0, 0, 1, 1);
    const d = cx.getImageData(0, 0, 1, 1).data; return [d[0], d[1], d[2], d[3] / 255];
  };
  const over = (f, b) => [0, 1, 2].map((i) => f[i] * f[3] + b[i] * (1 - f[3])).concat(1);
  const lum = (c) => { const v = c.slice(0, 3).map((x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4; }); return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2]; };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const bgOf = (e) => { // effective opaque background behind/at e
    const stack = []; let approx = false;
    for (let n = e; n && n.nodeType === 1; n = n.parentElement) {
      const s = getComputedStyle(n);
      if (s.backgroundImage !== 'none') approx = true;
      const c = rgba(s.backgroundColor); if (c[3] > 0) stack.push(c);
      if (c[3] >= 1) break;
    }
    let b = [255, 255, 255, 1];
    if (!stack.length || stack[stack.length - 1][3] < 1) b = rgba(getComputedStyle(document.documentElement).getPropertyValue('color-scheme').includes('dark') ? '#121212' : '#fff');
    for (let i = stack.length - 1; i >= 0; i--) b = over(stack[i], b);
    return { c: b, approx };
  };
  const snap = new Map();
  const PROPS = ['outlineStyle', 'outlineWidth', 'outlineColor', 'outlineOffset', 'boxShadow', 'borderTopWidth', 'borderTopColor', 'borderBottomColor', 'borderLeftColor', 'borderRightColor', 'backgroundColor', 'color', 'textDecorationLine'];
  const read = (e, ps) => { const s = getComputedStyle(e, ps); const o = {}; for (const p of PROPS) o[p] = s[p]; return o; };
  const PSEUDO = ['', '::after', '::before']; // rings drawn on a stretched ::after (card links) count too
  const snapAll = () => { for (const e of document.querySelectorAll('body *')) for (const ps of PSEUDO) snap.set(ps + idOf(e), read(e, ps || undefined)); };
  // the box a pseudo-element ring is drawn around: an absolutely placed pseudo fills its containing block
  const pseudoBox = (n, ps) => {
    if (!ps) return n; const st = getComputedStyle(n, ps);
    if (st.content === 'none' || st.content === 'normal') return null;
    if (st.position !== 'absolute' && st.position !== 'fixed') return n;
    for (let a = n; a && a !== document.body; a = a.parentElement) if (getComputedStyle(a).position !== 'static') return a;
    return n;
  };
  const fixedRoot = (e) => { for (let n = e; n && n !== document.documentElement; n = n.parentElement) { const p = getComputedStyle(n).position; if (p === 'fixed') return n; } return null; };
  const stickyOrFixed = (e) => { for (let n = e; n && n !== document.documentElement; n = n.parentElement) { const p = getComputedStyle(n).position; if (p === 'fixed' || p === 'sticky') return n; } return null; };
  const desc = (e) => {
    if (!e || e.nodeType !== 1) return String(e);
    const cls = [...e.classList].slice(0, 2).join('.');
    const t = (e.getAttribute('aria-label') || e.textContent || e.value || '').replace(/\s+/g, ' ').trim().slice(0, 30);
    return e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (cls ? '.' + cls : '') + (t ? ` "${t}"` : '');
  };
  const tiny = (e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width < 4 || r.height < 4 || s.clipPath.startsWith('inset(50%') || s.opacity === '0' || s.visibility === 'hidden'; };
  // natural layout position: undo scroll of scroll containers (and of the page unless inside a fixed box)
  const layoutRect = (e) => {
    const r = e.getBoundingClientRect(); let x = r.left, y = r.top; const fr = fixedRoot(e);
    for (let n = e.parentElement; n; n = n.parentElement) {
      if (fr && !fr.contains(n)) break;
      if (n === document.body) continue;
      if (n === document.documentElement) { x += W.scrollX; y += W.scrollY; break; }
      x += n.scrollLeft; y += n.scrollTop;
    }
    // RTL: mirror x, so "forward" is always increasing x. The element's own direction counts: a PIN keypad
    // keeps dir=ltr inside an Arabic page, so 1 → 2 → 3 runs left to right there.
    const vw = document.documentElement.clientWidth, rtl = getComputedStyle(e.parentElement || e).direction === 'rtl';
    const page = getComputedStyle(document.documentElement).direction === 'rtl';
    return { x: rtl ? vw - (x + r.width) : x, px: page ? vw - (x + r.width) : x, rtl, y, w: r.width, h: r.height };
  };
  const expected = () => { // what a keyboard user must be able to reach
    const q = 'a[href], area[href], button, input:not([type=hidden]), select, textarea, summary, iframe, [tabindex], [contenteditable=""], [contenteditable=true], audio[controls], video[controls]';
    const out = [];
    for (const e of document.querySelectorAll(q)) {
      if (e.closest('[inert]') || e.disabled || e.closest('fieldset:disabled')) continue;
      if (e.tabIndex < 0) continue;
      if (e.tagName === 'SUMMARY' && e.parentElement.tagName === 'DETAILS' && e.parentElement.querySelector('summary') !== e) continue;
      if (!e.checkVisibility({ visibilityProperty: true })) continue;
      const dd = e.parentElement && e.parentElement.closest('details:not([open])');
      if (dd && !(e.tagName === 'SUMMARY' && e.parentElement === dd)) continue;
      out.push(e);
    }
    return out;
  };
  // scrollable ancestors (outermost first, not past a fixed box): a pinned footer after a scrolling body is
  // compared with the scrolling box, not with a position deep inside its scrolled content
  const scrollers = (e) => {
    const out = [], fr = fixedRoot(e);
    for (let n = e.parentElement; n && n !== document.body && n !== document.documentElement; n = n.parentElement) {
      if (fr && !fr.contains(n)) break;
      const st = getComputedStyle(n);
      const sx = /auto|scroll/.test(st.overflowX) && n.scrollWidth > n.clientWidth + 1, sy = /auto|scroll/.test(st.overflowY) && n.scrollHeight > n.clientHeight + 1;
      if (sx || sy) out.unshift(n);
    }
    return out;
  };
  const posTab = () => [...document.querySelectorAll('[tabindex]')].filter((e) => e.tabIndex > 0).map(desc);
  // indicator: compare focused styles with the unfocused snapshot on the element and its neighbours
  const indicator = (e) => {
    const cands = [e];
    for (let n = e.parentElement, i = 0; n && i < 3 && n !== document.body; n = n.parentElement, i++) cands.push(n);
    // a visually hidden input's ring is often drawn on its face (next sibling) or a part inside it
    const withKids = (n) => { cands.push(n); for (const a of n.children) { cands.push(a); for (const b of a.children) cands.push(b); } };
    if (e.nextElementSibling) withKids(e.nextElementSibling);
    if (e.previousElementSibling) cands.push(e.previousElementSibling);
    for (const ch of e.children) cands.push(ch);
    if (e.labels) for (const l of e.labels) withKids(l);
    let best = null; const tried = [];
    for (const cn of cands) for (const ps of PSEUDO) {
      const n = pseudoBox(cn, ps);
      if (!n || !snap.has(ps + idOf(cn)) || tiny(n)) continue;
      const a = snap.get(ps + idOf(cn)), b = read(cn, ps || undefined);
      if (PROPS.every((p) => a[p] === b[p])) continue;
      const outer = bgOf(n.parentElement || n), inner = bgOf(n);
      const res = [];
      if ((a.outlineStyle !== b.outlineStyle || a.outlineWidth !== b.outlineWidth || a.outlineColor !== b.outlineColor) && b.outlineStyle !== 'none') {
        const w = parseFloat(b.outlineWidth), off = parseFloat(b.outlineOffset);
        const on = off < 0 ? inner.c : outer.c;
        res.push({ kind: 'outline', px: w, cr: ratio(over(rgba(b.outlineColor), on), on), approx: outer.approx, clip: clipped(n, off + w) });
      }
      if (a.boxShadow !== b.boxShadow && b.boxShadow !== 'none') {
        for (const sh of b.boxShadow.split(/,(?![^(]*\))/)) {
          const col = (sh.match(/(rgba?|color|oklch|oklab|lab|lch|hsla?)\([^)]*\)/) || [])[0] || 'transparent';
          const nums = sh.replace(/(rgba?|color|oklch|oklab|lab|lch|hsla?)\([^)]*\)/, '').match(/-?[\d.]+px/g) || [];
          const [x = 0, y = 0, , sp = 0] = nums.map(parseFloat);
          const ins = /inset/.test(sh), px = Math.max(sp, 0) + Math.max(Math.abs(x), Math.abs(y));
          const on = ins ? inner.c : outer.c;
          if (px > 0) res.push({ kind: 'box-shadow', px, cr: ratio(over(rgba(col), on), on), approx: outer.approx, clip: ins ? 0 : clipped(n, px) });
        }
      }
      const bc = ['borderTopColor', 'borderBottomColor', 'borderLeftColor', 'borderRightColor'];
      if ((bc.some((p) => a[p] !== b[p]) || a.borderTopWidth !== b.borderTopWidth) && parseFloat(b.borderTopWidth) > 0) {
        const was = parseFloat(a.borderTopWidth) > 0 ? over(rgba(a.borderTopColor), outer.c) : outer.c;
        res.push({ kind: 'border', px: parseFloat(b.borderTopWidth), cr: ratio(over(rgba(b.borderTopColor), outer.c), was), approx: outer.approx, clip: 0 });
      }
      if (a.backgroundColor !== b.backgroundColor) {
        const was = over(rgba(a.backgroundColor), outer.c), now = over(rgba(b.backgroundColor), outer.c);
        res.push({ kind: 'background', px: 99, cr: ratio(was, now), approx: outer.approx, clip: 0 });
      }
      if (a.textDecorationLine !== b.textDecorationLine || a.color !== b.color) res.push({ kind: 'text', px: 1, cr: 1, approx: false, clip: 0 });
      for (const r of res) {
        r.on = (cn === e ? 'self' : desc(cn)) + ps;
        r.ok = r.px >= 2 && r.cr >= 3 && r.clip < 4;
        tried.push(r);
        const score = (r.ok ? 1000 : 0) + Math.min(r.cr, 21) * 10 + Math.min(r.px, 9) - r.clip * 50;
        if (!best || score > best.score) best = { ...r, score };
      }
    }
    return best ? { ...best, tried: tried.length } : null;
  };
  // how many sides of a ring of thickness t around n are cut off by an overflow-clipping ancestor
  const clipped = (n, t) => {
    const r = n.getBoundingClientRect();
    const ring = { l: r.left - t, t: r.top - t, r: r.right + t, b: r.bottom + t };
    let sides = 0;
    for (let a = n.parentElement; a && a !== document.body && a !== document.documentElement; a = a.parentElement) {
      const s = getComputedStyle(a);
      if (s.overflowX === 'visible' && s.overflowY === 'visible' && s.clipPath === 'none') continue;
      const c = a.getBoundingClientRect();
      const bl = parseFloat(s.borderLeftWidth), bt = parseFloat(s.borderTopWidth);
      const box = { l: c.left + bl, t: c.top + bt, r: c.left + bl + a.clientWidth, b: c.top + bt + a.clientHeight };
      let k = 0;
      if (ring.l < box.l - 0.5 && r.left - 0.5 <= box.l) k++;
      if (ring.r > box.r + 0.5 && r.right + 0.5 >= box.r) k++;
      if (ring.t < box.t - 0.5 && r.top - 0.5 <= box.t) k++;
      if (ring.b > box.b + 0.5 && r.bottom + 0.5 >= box.b) k++;
      sides = Math.max(sides, k);
    }
    return sides;
  };
  // 2.4.11: sample points of the visible box; covered when the top hit is inside another fixed/sticky box
  const obscured = (e, box) => {
    const r = (box || e).getBoundingClientRect();
    const vw = document.documentElement.clientWidth, vh = W.innerHeight;
    const L = Math.max(r.left, 0), R = Math.min(r.right, vw), T = Math.max(r.top, 0), B = Math.min(r.bottom, vh);
    if (R - L < 1 || B - T < 1) return { offscreen: true, covered: 1, by: '' };
    let n = 0, cov = 0, by = '', bar = 0;
    for (const fx of [0.1, 0.5, 0.9]) for (const fy of [0.15, 0.5, 0.85]) {
      const x = L + (R - L) * fx, y = T + (B - T) * fy; n++;
      const hit = document.elementFromPoint(x, y);
      if (!hit || e.contains(hit) || hit.contains(e) || (box && (box.contains(hit) || hit.contains(box)))) continue;
      const sf = stickyOrFixed(hit);
      if (sf && !sf.contains(e)) { cov++; by = desc(sf); if (sf.closest('.proto-bar')) bar++; }
    }
    return { offscreen: false, covered: cov / n, by, protoBar: bar > 0 && bar === cov };
  };
  const visibleBox = (e) => { // the box a sighted user sees for e (visually hidden inputs → their face/label)
    if (!tiny(e)) return null;
    for (const n of [e.nextElementSibling, e.parentElement, ...(e.labels || [])]) if (n && !tiny(n)) return n;
    return null;
  };
  const inDialog = (e) => { const d = e && e.closest && e.closest('[role=dialog],[role=alertdialog],dialog'); return d ? desc(d) : null; };
  const deep = () => { let a = document.activeElement, fr = null; while (a && a.tagName === 'IFRAME') { try { const d = a.contentDocument; if (!d) break; fr = fr || a; a = d.activeElement; if (!a || a === d.body) { a = fr; break; } } catch { break; } } return { a, fr }; };
  W.__kb = {
    init() { snapAll(); },
    expected() { return expected().map((e) => ({ id: idOf(e), d: desc(e), radio: e.type === 'radio' ? (e.form ? idOf(e.form) : 0) + ':' + e.name : null, bar: !!e.closest('.proto-bar') })); },
    posTab,
    key() { const { a, fr } = deep(); if (!a || a === document.body || a === document.documentElement) return 'body'; return (fr && fr !== a ? 'f' + idOf(fr) + '>' : '') + idOf(a); },
    stop(full) {
      const { a, fr } = deep();
      if (!a || a === document.body || a === document.documentElement) return { key: 'body' };
      if (fr && fr !== a) return { key: 'f' + idOf(fr) + '>' + idOf(a), d: desc(fr) + ' > ' + desc(a), inner: true };
      const e = a, box = visibleBox(e);
      const o = { key: String(idOf(e)), id: idOf(e), d: desc(e), bar: !!e.closest('.proto-bar'), dialog: inDialog(e), inMain: !!e.closest('#main'),
        fixed: (() => { const f = fixedRoot(e); return f ? idOf(f) + ':' + desc(f) : null; })(),
        sticky: (() => { const f = stickyOrFixed(e); return f ? desc(f) : null; })(), iframe: e.tagName === 'IFRAME' };
      if (!full) return o;
      o.pos = layoutRect(box || e);
      o.sc = scrollers(e).map((n) => ({ id: idOf(n), pos: layoutRect(n) }));
      o.ind = indicator(e);
      o.obs = obscured(e, box);
      o.hidden = !box && tiny(e);
      o.inNav = !!e.closest('nav');
      return o;
    },
    focus(id) { for (const e of document.querySelectorAll('*')) if (ids.get(e) === id) { e.focus(); return desc(document.activeElement); } return null; },
    byId(id) { for (const e of document.querySelectorAll('*')) if (ids.get(e) === id) return e; return null; },
    activeIs(id) { return ids.get(document.activeElement) === id; },
    activeDesc() { return desc(document.activeElement); },
    skip() { const s = document.querySelector('a.c-skip, a[href="#main"]'); return s ? { id: idOf(s), d: desc(s), href: s.getAttribute('href') } : null; },
    hasMain() { return !!document.getElementById('main'); },
    insideMain() { return !!(document.activeElement && document.activeElement.closest('#main')) || document.activeElement === document.getElementById('main'); },
    dialogs() {
      return [...document.querySelectorAll('[role=dialog],[role=alertdialog],dialog')].map((d) => {
        const vis = d.checkVisibility({ visibilityProperty: true }) && !d.closest('[inert]');
        const acts = [...d.querySelectorAll('a[href],button')].filter((b) => b.tabIndex >= 0);
        const close = acts.find((b) => /close|إغلاق|dismiss/i.test((b.getAttribute('aria-label') || '') + ' ' + b.className + ' ' + b.textContent) || /__close/.test(b.className));
        // a trigger sits outside every dialog and is on screen (the empty-cart bar of a live store is hidden: nothing to press)
        const trig = d.id ? [...document.querySelectorAll(`a[href="#${CSS.escape(d.id)}"], [aria-controls="${CSS.escape(d.id)}"]`)].find((t) => !t.closest('[role=dialog],[role=alertdialog],dialog,[inert]') && t.checkVisibility({ visibilityProperty: true })) ?? null : null;
        const title = d.querySelector('[tabindex="-1"]');
        // a gate has no close button: its own actions (accept / leave) dismiss it
        const dismiss = close || (acts.length && !d.id ? acts[0] : null);
        return { id: idOf(d), d: desc(d), open: vis, dismiss: dismiss ? idOf(dismiss) : null, dismissD: dismiss ? desc(dismiss) : null, modal: d.getAttribute('aria-modal') === 'true', close: close ? idOf(close) : null, closeD: close ? desc(close) : null,
          closeHref: close && close.getAttribute('href'), trigger: trig ? idOf(trig) : null, triggerD: trig ? desc(trig) : null, focusTarget: title ? desc(title) : null, target: !!d.id };
      });
    },
    dialogOpen(id) { const d = this.byId(id); return !!d && d.checkVisibility({ visibilityProperty: true }); },
    activeInside(id) { const d = this.byId(id); return !!d && d.contains(document.activeElement); },
  };
}

// ── one page in one view/scheme ──────────────────────────────────────
const KEYS = {
  Tab: { key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9, nativeVirtualKeyCode: 9 },
  Enter: { key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13, text: '\r' },
  Escape: { key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27, nativeVirtualKeyCode: 27 },
};
async function press(s, name, shift = false) {
  const k = KEYS[name], modifiers = shift ? 8 : 0;
  await send('Input.dispatchKeyEvent', { type: k.text ? 'keyDown' : 'rawKeyDown', modifiers, ...k }, s);
  await send('Input.dispatchKeyEvent', { type: 'keyUp', modifiers, ...k }, s);
}
const evalv = async (s, expr) => {
  const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }, s);
  if (r.exceptionDetails) throw new Error(expr.slice(0, 60) + ': ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
  return r.result.value;
};
const HELPER = `(${helper.toString()})()`;
const QUIET = `(() => { const s = new CSSStyleSheet(); s.replaceSync('*,*::before,*::after{animation:none!important;transition:none!important}html{scroll-behavior:auto!important}'); document.adoptedStyleSheets = [...document.adoptedStyleSheets, s]; })()`;

async function open(pg, view, scheme, bar, b) {
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' }, undefined, b);
  const { sessionId: s } = await send('Target.attachToTarget', { targetId, flatten: true }, undefined, b);
  sess.set(s, B[b].ws);
  await send('Page.enable', {}, s);
  await send('Emulation.setFocusEmulationEnabled', { enabled: true }, s);
  await send('Emulation.setDeviceMetricsOverride', { width: view.width, height: view.height, deviceScaleFactor: 1, mobile: view.mobile }, s);
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: scheme }, { name: 'prefers-reduced-motion', value: 'reduce' }] }, s);
  const q = pg.theme ? `?scheme=${scheme}${bar ? '' : '&bar=0'}` : '';
  const loaded = once('Page.loadEventFired', s);
  await send('Page.navigate', { url: `http://127.0.0.1:${SRV}/${pg.path}${q}` }, s);
  await loaded;
  await evalv(s, 'document.fonts.ready.then(() => 1)');
  await evalv(s, QUIET);
  await sleep(60);
  await evalv(s, HELPER);
  // module scripts (core JS) finish starting before the unfocused style snapshot: it may move focus into a dialog that opened
  // with the page (onto its title); the Tab cycle is then walked from the document start
  for (let w = 0; w < 80 && await evalv(s, `!!document.querySelector('[data-juwri-boot]') && !(window.Juwri && window.Juwri.cart)`); w++) await sleep(100);
  await sleep(400);
  await evalv(s, `(() => { const a = document.activeElement; if (a && a !== document.body) a.blur(); getSelection().setPosition(document.body, 0); return 1; })()`);
  await evalv(s, '__kb.init(), 1');
  return { s, targetId };
}

async function runOne(pg, view, scheme, bar = false, b = 0) {
  const V = [], WARN = [], INFO = {};
  const viol = (type, msg, el) => V.push({ type, msg, el });
  const warn = (type, msg, el) => WARN.push({ type, msg, el });
  let { s, targetId } = await open(pg, view, scheme, bar, b);
  const full = true, light = scheme === 'light';
  try {
    const exp = await evalv(s, '__kb.expected()');
    const pos = await evalv(s, '__kb.posTab()');
    for (const p of pos) viol('positive-tabindex', 'tabindex > 0 overrides the reading order (2.4.3)', p);
    // 1. forward Tab cycle
    const stops = [], frames = new Set(); let innerStops = 0; const seenAt = new Map(); let wraps = 0, done = false, prev = null, same = 0;
    const MAX = exp.length * 3 + 40 + exp.filter((e) => /^iframe/.test(e.d)).length * 600;
    for (let i = 0; i < MAX; i++) {
      await press(s, 'Tab');
      const st = await evalv(s, `__kb.stop(${full})`);
      if (st.key === 'body') { wraps++; if (!stops.length && wraps > 2) { done = true; break; } continue; }
      if (st.inner) { // inside a framed page: walk through it without checks
        if (prev === st.key) { if (++same > 3) break; } else same = 0;
        prev = st.key; frames.add(Number(st.key.slice(1, st.key.indexOf('>')))); innerStops++; continue;
      }
      if (stops.length && st.key === stops[0].key) { done = true; break; }
      if (prev === st.key) { if (++same > 3) { viol('keyboard-trap', 'Tab does not move focus away (2.1.2)', st.d); break; } continue; }
      same = 0; prev = st.key;
      if (seenAt.has(st.key)) { viol('keyboard-trap', `Tab cycle returns to an earlier stop without passing the start (2.1.2)`, st.d); done = true; break; }
      seenAt.set(st.key, stops.length); stops.push(st);
    }
    if (!done) viol('keyboard-trap', `focus did not cycle within ${MAX} Tab presses (2.1.2)`, stops.at(-1)?.d);
    INFO.stops = stops.length; INFO.expected = exp.length;
    // reachability (2.1.1)
    const reached = new Set([...stops.map((x) => x.id), ...frames]);
    const radioHit = new Set(exp.filter((e) => e.radio && reached.has(e.id)).map((e) => e.radio));
    for (const e of exp) {
      if (bar && !e.bar) continue;
      if (reached.has(e.id)) continue;
      if (e.radio && radioHit.has(e.radio)) continue; // one stop per radio group
      if (stops.some((x) => x.iframe)) { /* framed page checked separately */ }
      viol('unreachable', 'interactive element is never reached by Tab (2.1.1)', e.d);
    }
    const expIds = new Set(exp.map((e) => e.id));
    INFO.extra = stops.filter((x) => !expIds.has(x.id)).map((x) => x.d); // e.g. scroll containers Chrome makes focusable
    // per-stop checks
    let prevFlow = null;
    for (const st of stops) {
      if (st.iframe || (bar && !st.bar)) continue;
      // 2.4.7 / 1.4.11 visible indicator
      const ind = st.ind;
      if (st.hidden) viol('focus-invisible', 'focused element is invisible (no visible box)', st.d);
      else if (!ind) viol('no-indicator', 'no style changes on focus (2.4.7)', st.d);
      else if (!ind.ok) {
        const why = ind.clip >= 4 ? 'ring clipped on all sides' : ind.px < 2 ? `${ind.kind} only ${ind.px}px` : `${ind.kind} contrast ${ind.cr.toFixed(2)}:1`;
        viol('weak-indicator', `focus indicator too weak: ${why} (2.4.7/1.4.11)`, st.d + (ind.on !== 'self' ? ` [on ${ind.on}]` : ''));
      } else {
        if (ind.clip >= 2) warn('ring-clipped', `focus ring clipped on ${ind.clip} sides by an overflow container`, st.d);
        if (ind.approx) warn('contrast-approx', 'indicator sits on a background image/gradient: contrast approximated', st.d);
      }
      // 2.4.11 not obscured
      if (st.obs) {
        if (st.obs.offscreen) viol('focus-offscreen', 'focused element is outside the viewport after focusing (2.4.11)', st.d);
        else if (st.obs.covered >= 1) (st.obs.protoBar || st.bar ? warn : viol)('focus-obscured', `focused element entirely hidden by ${st.obs.by} (2.4.11)`, st.d);
        else if (st.obs.covered > 0.3) warn('focus-partly-obscured', `${Math.round(st.obs.covered * 100)}% hidden by ${st.obs.by}`, st.d);
      }
      // 2.4.3 order
      if (prevFlow && prevFlow.fixed === st.fixed && st.pos.w && prevFlow.pos.w) {
        let a = prevFlow.pos, b = st.pos; const tol = 8;
        const sa = prevFlow.sc || [], sb = st.sc || []; let k = 0;
        while (k < sa.length && k < sb.length && sa[k].id === sb[k].id) k++;
        if (sa[k]) a = sa[k].pos; if (sb[k]) b = sb[k].pos; // lift each side to its own scroll box
        if (a.rtl !== b.rtl) { a = { ...a, x: a.px }; b = { ...b, x: b.px }; } // crossing a dir island: page direction
        if (b.y + b.h <= a.y + tol && a.y - b.y > 120) { // moved up
          if (!(b.x >= a.x + a.w - tol)) viol('order-backwards', `focus jumps up ${Math.round(a.y - b.y)}px from ${prevFlow.d} (2.4.3)`, st.d);
          else INFO.columnJumps = (INFO.columnJumps || 0) + 1;
        } else if (b.y < a.y + a.h && b.y + b.h > a.y && a.x - b.x > 48 && b.y + tol < a.y + a.h) { // same line, going back
          // a navigation landmark in a side column (the desktop category list) is reached after the content it filters: reading order, not a backwards jump
          if (st.inNav) INFO.columnJumps = (INFO.columnJumps || 0) + 1;
          else viol('order-backwards', `focus jumps ${Math.round(a.x - b.x)}px towards the inline start on the same line from ${prevFlow.d} (2.4.3)`, st.d);
        }
      } else if (prevFlow && prevFlow.fixed !== st.fixed) {
        (INFO.fixedTransitions ||= new Set()).add((prevFlow.fixed || 'page').replace(/^\d+:/, '') + ' → ' + (st.fixed || 'page').replace(/^\d+:/, ''));
      }
      prevFlow = st;
    }
    if (INFO.fixedTransitions) INFO.fixedTransitions = [...INFO.fixedTransitions];
    if (!light || bar) return { V, WARN, INFO };

    // 2. Shift+Tab: the reverse cycle must be the forward cycle backwards
    if (done && stops.length > 1) {
      const rev = []; prev = null;
      for (let i = 0; i < stops.length * 2 + innerStops * 2 + 10; i++) {
        await press(s, 'Tab', true);
        const k = await evalv(s, '__kb.key()');
        if (k === 'body' || k === prev || k.includes('>') || frames.has(Number(k))) continue; // framed pages: own run
        if (k === stops[0].key) break;
        prev = k; rev.push(k);
      }
      const want = stops.slice(1).filter((x) => !frames.has(x.id)).map((x) => x.key).reverse();
      if (rev.join() !== want.join()) {
        const at = want.findIndex((k, i) => rev[i] !== k);
        viol('reverse-order', `Shift+Tab order differs from Tab order at reverse step ${at + 1} (2.4.3/2.1.2)`, stops[stops.length - 1 - at]?.d);
      }
    }

    // 3. skip link (2.4.1)
    const hasMain = await evalv(s, '__kb.hasMain()');
    const dialogs = await evalv(s, '__kb.dialogs()');
    const staticModal = dialogs.filter((d) => d.open && d.modal);
    const flow = stops.filter((x) => !x.bar);
    if (!hasMain) viol('no-main', 'page has no #main target', 'html');
    else if (staticModal.length && !pg.board) { /* skip link sits behind the inert page: not expected */ }
    else {
      const sk = await evalv(s, '__kb.skip()');
      const firstMain = flow.findIndex((x) => x.inMain);
      if (!sk) { if (firstMain === 0) INFO.allowed = [...(INFO.allowed || []), 'skip-not-needed']; else viol('no-skip-link', `no skip link, and ${firstMain < 0 ? 'no' : firstMain} stops come before #main (2.4.1)`, flow[0]?.d); }
      else {
        if (String(sk.id) !== flow[0]?.key) viol('skip-not-first', 'the skip link is not the first Tab stop (2.4.1)', flow[0]?.d);
        await evalv(s, `__kb.focus(${sk.id})`);
        await press(s, 'Enter'); await sleep(80);
        let ok = await evalv(s, '__kb.insideMain()');
        if (!ok) { await press(s, 'Tab'); ok = await evalv(s, '__kb.insideMain()'); }
        if (!ok) viol('skip-broken', `skip link (${sk.href}) does not move focus into #main`, sk.d);
      }
    }

    // 4. dialogs
    INFO.dialogs = [];
    if (pg.board) { if (dialogs.length) INFO.allowed = [...(INFO.allowed || []), 'board-dialogs']; }
    else {
      for (const d of staticModal) {
        const r = { dialog: d.d, kind: 'static (open on load)', checks: {} };
        r.checks.firstStopInside = flow[0]?.dialog === d.d;
        if (!r.checks.firstStopInside) viol('dialog-focus', 'first Tab stop is not inside the open dialog', flow[0]?.d);
        const out = flow.filter((x) => x.dialog !== d.d);
        r.checks.tabContained = !out.length;
        for (const x of out) viol('dialog-escape', 'Tab reaches an element outside the open modal dialog (page behind must be inert)', x.d);
        r.checks.closeControl = !!d.dismiss && stops.some((x) => x.id === d.dismiss) ? (d.close ? d.closeD : `dismissed by its actions (${d.dismissD})`) : false;
        if (!r.checks.closeControl) viol('dialog-no-close', 'open dialog has no keyboard-reachable close or dismiss control', d.d);
        r.checks.focusTarget = d.focusTarget; // element core JS focuses on open (tabindex=-1 title)
        if (!d.focusTarget) warn('dialog-no-focus-target', 'no tabindex=-1 title for script to focus on open', d.d);
        r.checks.escape = 'pending core JS (no-js-esc)';
        INFO.allowed = [...new Set([...(INFO.allowed || []), 'no-js-esc'])];
        INFO.dialogs.push(r);
      }
      for (const d of dialogs.filter((d) => !d.open && d.target && d.trigger)) { // :target dialogs
        const r = { dialog: d.d, kind: 'interactive (:target)', trigger: d.triggerD, checks: {} };
        await evalv(s, `__kb.focus(${d.trigger})`);
        await press(s, 'Enter'); await sleep(80);
        r.checks.opens = await evalv(s, `__kb.dialogOpen(${d.id})`);
        if (!r.checks.opens) { viol('dialog-open', 'Enter on the trigger does not open the dialog', d.triggerD); INFO.dialogs.push(r); continue; }
        await press(s, 'Tab');
        r.checks.focusMovesIn = await evalv(s, `__kb.activeInside(${d.id})`);
        if (!r.checks.focusMovesIn) viol('dialog-focus', 'opening the dialog does not move focus inside it', `${d.d} (now ${await evalv(s, '__kb.activeDesc()')})`);
        let left = null;
        for (let i = 0; i < 12; i++) { await press(s, 'Tab'); if (!(await evalv(s, `__kb.activeInside(${d.id})`))) { const ad = await evalv(s, '__kb.activeDesc()'); if (!/proto-bar|^body|^html/.test(ad)) { left = ad; break; } } } // past the last stop focus may go to the browser (body), as with a native modal
        r.checks.tabContained = !left;
        if (left) viol('dialog-escape', `Tab leaves the open dialog (to ${left})`, d.d);
        await press(s, 'Escape'); await sleep(50);
        r.checks.escapeCloses = !(await evalv(s, `__kb.dialogOpen(${d.id})`));
        if (!r.checks.escapeCloses) warn('dialog-esc', 'Esc does not close the dialog (needs core JS, no-js-esc)', d.d);
        if (d.close) {
          if (r.checks.escapeCloses) { await evalv(s, `__kb.focus(${d.trigger})`); await press(s, 'Enter'); await sleep(80); }
          await evalv(s, `__kb.focus(${d.close})`);
          await press(s, 'Enter'); await sleep(80);
          r.checks.closeCloses = !(await evalv(s, `__kb.dialogOpen(${d.id})`));
          if (!r.checks.closeCloses) viol('dialog-close', 'the close control does not close the dialog', d.closeD);
          let back = await evalv(s, `__kb.activeIs(${d.trigger})`);
          if (!back) { // no-JS fragment navigation moves the focus starting point: next Tab must land just after the trigger
            await press(s, 'Tab');
            const k = await evalv(s, '__kb.key()');
            const ti = stops.findIndex((x) => x.id === d.trigger);
            back = ti >= 0 && stops[ti + 1] && stops[ti + 1].key === k;
          }
          r.checks.focusReturns = back;
          if (!back) viol('dialog-return', `closing does not return focus to the trigger (close href ${d.closeHref})`, d.triggerD);
        }
        INFO.dialogs.push(r);
      }
    }
    if (!INFO.dialogs.length) delete INFO.dialogs;
    return { V, WARN, INFO };
  } finally {
    await send('Target.closeTarget', { targetId }, undefined, b).catch(() => {});
    sess.delete(s);
  }
}

// ── main ─────────────────────────────────────────────────────────────
const server = spawn('python3', [join(ROOT, 'tools', 'csp_server.py'), String(SRV)], { stdio: 'ignore' });
const report = []; const t0 = Date.now();
try {
  await Promise.all(Array.from({ length: PAR }, (_, b) => startBrowser(b)));
  for (let i = 0; i < 50; i++) { try { await fetch(`http://127.0.0.1:${SRV}/prototypes/index.html`); break; } catch { await sleep(100); } }
  const jobs = [];
  for (const pg of pages()) {
    for (const view of VIEWS) for (const scheme of QUICK ? ['light'] : ['light', 'dark']) jobs.push({ pg, view, scheme });
    // the preview bar is the same on every page: check its own controls once per theme × locale
    if (pg.theme && pg.page === 'home') for (const view of VIEWS) jobs.push({ pg, view, scheme: 'light', bar: true });
  }
  let next = 0, fin = 0;
  const worker = async (b) => {
    while (next < jobs.length) {
      const j = jobs[next++];
      let res; const t1 = Date.now();
      for (let attempt = 0; attempt < 2 && !res; attempt++) {
        let timer;
        try {
          res = await Promise.race([runOne(j.pg, j.view, j.scheme, j.bar, b),
            new Promise((_, rej) => { timer = setTimeout(() => rej(new Error('run timed out after 120s')), 120000); })]);
        } catch (e) {
          if (attempt) res = { V: [{ type: 'runner-error', msg: e.message, el: '' }], WARN: [], INFO: {} };
          console.error(`retry ${j.pg.id} ${j.view.name} ${j.scheme}: ${e.message}`);
          await stopBrowser(b); await startBrowser(b); // a stalled browser is replaced, the run tried once more
        } finally { clearTimeout(timer); }
      }
      report.push({ page: j.pg.id, view: j.view.name + (j.bar ? '+bar' : ''), scheme: j.scheme, violations: res.V, warnings: res.WARN, info: res.INFO });
      if (Date.now() - t1 > 30000) console.error(`slow: ${j.pg.id} ${j.view.name} ${j.scheme} ${((Date.now() - t1) / 1000).toFixed(0)}s`);
      if (++fin % 40 === 0) console.error(`${fin}/${jobs.length} runs`);
    }
  };
  await Promise.all(Array.from({ length: PAR }, (_, b) => worker(b)));
} finally {
  for (let b = 0; b < B.length; b++) await stopBrowser(b);
  server.kill();
}

// ── report ───────────────────────────────────────────────────────────
report.sort((a, b) => (a.page + a.view + a.scheme).localeCompare(b.page + b.view + b.scheme));
const byPage = new Map();
for (const r of report) {
  if (!byPage.has(r.page)) byPage.set(r.page, []);
  byPage.get(r.page).push(r);
}
const byType = new Map(), warnType = new Map(), allowed = new Set();
for (const r of report) {
  for (const v of r.violations) { const k = v.type; if (!byType.has(k)) byType.set(k, []); byType.get(k).push({ ...v, where: `${r.page} ${r.view} ${r.scheme}` }); }
  for (const v of r.warnings) { const k = v.type; if (!warnType.has(k)) warnType.set(k, []); warnType.get(k).push({ ...v, where: `${r.page} ${r.view} ${r.scheme}` }); }
  for (const a of r.info.allowed || []) allowed.add(a);
  if (r.info.fixedTransitions) allowed.add('fixed-transition');
  if (r.info.columnJumps) allowed.add('column-jump');
  if (r.violations.length + r.warnings.length === 0 && r.info.stops) { /* clean */ }
}
allowed.add('proto-bar');
if (report.some((r) => (r.info.extra || []).some((x) => /iframe/.test(x))) || report.some((r) => r.page.includes('preview'))) allowed.add('iframe');
for (const [page, rs] of byPage) {
  const v = rs.reduce((n, r) => n + r.violations.length, 0), w = rs.reduce((n, r) => n + r.warnings.length, 0);
  const st = rs.map((r) => `${r.view[0]}${r.scheme[0]}:${r.info.stops ?? '?'}`).join(' ');
  console.log(`${v ? 'FAIL' : 'ok  '} ${page.padEnd(30)} stops ${st}  violations ${v}  warnings ${w}`);
}
console.log('');
for (const [k, list] of [...byType].sort((a, b) => b[1].length - a[1].length)) {
  const els = [...new Set(list.map((x) => x.el))];
  console.log(`VIOLATION ${k} ×${list.length}: ${list[0].msg}`);
  for (const x of list.slice(0, 4)) console.log(`   ${x.where} → ${x.el}${x.msg !== list[0].msg ? '  (' + x.msg + ')' : ''}`);
  if (els.length > 4) console.log(`   … ${els.length} distinct elements`);
}
for (const [k, list] of [...warnType].sort((a, b) => b[1].length - a[1].length))
  console.log(`warning   ${k} ×${list.length}: ${list[0].msg} — e.g. ${list[0].where} → ${list[0].el}`);
console.log('\nAllowed exceptions (not counted):');
for (const a of allowed) console.log(` - ${a}: ${ALLOWED[a]}`);
const nv = report.reduce((n, r) => n + r.violations.length, 0), nw = report.reduce((n, r) => n + r.warnings.length, 0);
const ns = report.reduce((n, r) => n + (r.info.stops || 0), 0);
console.log(`\npages ${byPage.size}, runs ${report.length} (pages × ${VIEWS.length} views × ${QUICK ? 1 : 2} schemes, + preview-bar runs), tab stops checked ${ns}, violations ${nv}, warnings ${nw}, ${((Date.now() - t0) / 1000).toFixed(0)}s`);
mkdirSync(join(ROOT, 'out'), { recursive: true });
writeFileSync(join(ROOT, 'out', 'keyboard.json'), JSON.stringify({ allowed: Object.fromEntries([...allowed].map((a) => [a, ALLOWED[a]])), runs: report }, null, 1));
process.exit(nv ? 1 : 0);
