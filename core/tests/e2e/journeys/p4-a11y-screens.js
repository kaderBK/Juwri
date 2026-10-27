// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Accessibility + RTL audit of the newest screens (E2E_RUN_P4=1). REPORT ONLY unless E2E_A11Y_STRICT=1.
 *
 * Screens: the manager's offline-exceptions screen (en, ar; 1280 and 390 wide), the help center (ar, ur = RTL; en, de = LTR),
 * the POS lock overlay (en, ar), the service worker's "connect once" page (en, ar, ur; the markup the worker builds), and the
 * storage-full / offline notices on the register. For each page: axe-core (WCAG 2.0/2.1/2.2 A+AA + best-practice, every impact is
 * listed), a keyboard pass (Tab order reaches every control, accessible names, a visible focus ring, no positive tabindex, the lock
 * overlay holds focus), and the RTL facts (lang, dir, horizontal overflow, header alignment).
 *
 * Output: one line per finding, `FINDING <screen> | <kind> | <impact> | <detail>`, then a summary. Findings are logged, not
 * failed, so the audit can run on a tree that still has open issues; set E2E_A11Y_STRICT=1 to fail on serious/critical ones.
 */

const fs = require('fs');
const path = require('path');
const P = require('../lib/p4');
const { newPage } = require('../lib/harness');

const { L } = P;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const AXE_PATHS = [
  '../../../../tools/themes/conformance/node_modules/axe-core/axe.min.js',
  '../../node_modules/axe-core/axe.min.js',
];

function loadAxe() {
  for (const p of AXE_PATHS) {
    const f = path.resolve(__dirname, p);
    if (fs.existsSync(f)) return fs.readFileSync(f, 'utf8');
  }
  throw new Error('axe-core missing: run `npm ci` in tools/themes/conformance');
}

module.exports = async function p4A11yScreens({ browser, log }) {
  const base = L.baseUrl();
  const axe = loadAxe();
  const findings = [];
  const add = (screen, kind, impact, detail) => { findings.push({ screen, kind, impact, detail }); console.log(`FINDING ${screen} | ${kind} | ${impact} | ${detail}`); };

  const a = P.ownerRestaurant(P.OWNER.email);
  // ur and de ship inactive (their help-center URL is a 404): switch them on here, in the fixture only
  P.tinker(`\\Juwri\\Core\\Models\\Language::whereIn('code', ['ur', 'de'])->update(['active' => 1]); echo 'LANG=1';`, 'LANG');
  const staff = P.makeStaff(a, 'A11yMgr', 'manager');
  // one declined card, one flagged record, so the exceptions screen has rows in every table
  P.tinker(`
    $s = \\Juwri\\Core\\Models\\Store::findOrFail(${a.id});
    $acc = $s->account_id;
    $o = \\App\\Models\\Order::create(['store_id' => $s->id, 'type' => 'takeaway', 'status' => 'completed']);
    \\Illuminate\\Support\\Facades\\DB::table('pos_offline_records')->insert([
      'account_id' => $acc, 'store_id' => $s->id, 'device_id' => 'a11y-till-0001', 'device_seq' => 1, 'client_key' => (string) \\Illuminate\\Support\\Str::uuid(),
      'type' => 'sale', 'payload_hash' => str_repeat('a', 64), 'status' => 'conflict', 'reason' => 'pricing_mismatch', 'order_id' => $o->id,
      'flags' => json_encode(['oversell' => [1], 'late_for_closed_shift' => 3]), 'business_at' => now(), 'created_at' => now()]);
    \\Illuminate\\Support\\Facades\\DB::table('pos_offline_card_payments')->insert([
      'account_id' => $acc, 'store_id' => $s->id, 'order_id' => $o->id, 'client_key' => (string) \\Illuminate\\Support\\Str::uuid(), 'device_id' => 'a11y-till-0001',
      'provider' => 'e2e', 'provider_reference' => 'A11Y-'.uniqid(), 'amount_minor' => 4500, 'currency_code' => 'SAR', 'cap_amount_minor' => 25000, 'cap_total_minor' => 250000,
      'state' => 'declined_after_offline', 'masked_pan' => '**** 4242', 'scheme' => 'visa', 'taken_at' => now(), 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    echo 'OK=1';
  `, 'OK');

  // ------------------------------------------------------------------ the per-page audit
  const audit = async (page, screen, { keyboard = true, scope = null } = {}) => {
    await sleep(500);
    // axe through the DevTools protocol, so the page CSP is not loosened
    await page.evaluate(axe);
    const res = await page.evaluate(async (scopeSel) => {
      const ctx = scopeSel ? document.querySelector(scopeSel) : document;
      const r = await window.axe.run(ctx || document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] }, resultTypes: ['violations'] });
      return r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, n: v.nodes.length, t: v.nodes.slice(0, 3).map((x) => x.target.join(' ')).join(' | ') }));
    }, scope);
    for (const v of res) add(screen, `axe:${v.id}`, v.impact, `x${v.n} ${v.t} (${v.help})`);

    const facts = await page.evaluate(() => {
      const de = document.documentElement;
      const wide = document.documentElement.scrollWidth - document.documentElement.clientWidth;
      const th = [...document.querySelectorAll('th')].slice(0, 6).map((x) => getComputedStyle(x).textAlign);
      return { lang: de.lang, dir: de.dir || getComputedStyle(de).direction, overflow: wide, th };
    });
    if (!facts.lang) add(screen, 'lang', 'serious', 'the <html> element has no lang attribute');
    if (facts.overflow > 1) add(screen, 'overflow', 'moderate', `horizontal overflow ${facts.overflow}px`);
    if (facts.dir === 'rtl') {
      const phys = facts.th.filter((t) => t === 'left');
      if (phys.length) add(screen, 'rtl-align', 'moderate', `table headers aligned physically left in an RTL page (${phys.length})`);
    }
    if (!keyboard) return facts;

    // a headless page is not "focused": :focus / :focus-visible would never match and every ring looked missing
    try { await page.bringToFront(); } catch (_) { /* best effort */ }
    try { const c = await page.target().createCDPSession(); await c.send('Emulation.setFocusEmulationEnabled', { enabled: true }); } catch (_) { /* best effort */ }
    // keyboard pass: focus every visible control, check its name and ring; then really press Tab
    const kb = await page.evaluate(() => {
      const vis = (el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
      const sel = 'a[href],button,input:not([type=hidden]),select,textarea,summary,[tabindex]:not([tabindex="-1"])';
      const els = [...document.querySelectorAll(sel)].filter((e) => !e.disabled && vis(e));
      const name = (e) => {
        const lab = e.getAttribute('aria-label') || '';
        if (lab.trim()) return lab.trim();
        const by = e.getAttribute('aria-labelledby');
        if (by) return by.split(/\s+/).map((i) => (document.getElementById(i) || {}).textContent || '').join(' ').trim();
        if (e.labels && e.labels.length) return [...e.labels].map((l) => l.textContent).join(' ').trim();
        const t = (e.textContent || '').trim() || e.getAttribute('title') || e.getAttribute('alt') || '';
        return t.trim();
      };
      const out = { count: els.length, unnamed: [], noRing: [], positive: [] };
      for (const e of els) {
        const id = e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/)[0] : '');
        if (!name(e) && !(e.tagName === 'INPUT' && ['submit', 'button'].includes(e.type) && e.value)) out.unnamed.push(id + (e.placeholder ? ` [placeholder only: ${e.placeholder}]` : ''));
        if (parseInt(e.getAttribute('tabindex') || '0', 10) > 0) out.positive.push(id);
        e.focus({ preventScroll: true });
        const cs = getComputedStyle(e);
        const ring = (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0) || (cs.boxShadow && cs.boxShadow !== 'none');
        if (document.activeElement === e && !ring) out.noRing.push(id);
      }
      return out;
    });
    if (kb.unnamed.length) add(screen, 'kb:no-accessible-name', 'serious', `${kb.unnamed.length}: ${kb.unnamed.slice(0, 5).join(', ')}`);
    if (kb.positive.length) add(screen, 'kb:positive-tabindex', 'moderate', kb.positive.slice(0, 5).join(', '));
    if (kb.noRing.length) add(screen, 'kb:no-focus-ring', 'serious', `${kb.noRing.length}: ${kb.noRing.slice(0, 5).join(', ')}`);
    await page.evaluate(() => document.activeElement && document.activeElement.blur());
    const seen = new Set();
    for (let i = 0; i < Math.min(kb.count + 4, 60); i++) {
      await page.keyboard.press('Tab');
      seen.add(await page.evaluate(() => { const e = document.activeElement; return e && e !== document.body ? e.tagName + '#' + (e.id || '') + '.' + (e.className || '') + '@' + [...e.parentNode.children].indexOf(e) : 'body'; }));
    }
    seen.delete('body');
    if (kb.count > 0 && seen.size < Math.min(kb.count, 8)) add(screen, 'kb:tab-reach', 'serious', `Tab reached ${seen.size} controls, ${kb.count} are focusable`);
    return facts;
  };

  const staffCtx = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  try {
    await P.staffLogin(staffCtx.page, base, staff);
    const sp = staffCtx.page;

    // ---------------------------------------------------------------- 1 exceptions screen
    for (const [loc, w] of [['', 1280], ['/ar', 1280], ['', 390], ['/ar', 390]]) {
      await sp.setViewport({ width: w, height: 900 });
      const screen = `exceptions ${loc ? loc.slice(1) : 'en'} ${w}`;
      await sp.goto(`${base}${loc}/pos/offline/exceptions/screen?store=${a.id}`, { waitUntil: 'networkidle2' });
      await sp.waitForSelector('table, [role="status"], .hint', { timeout: 15000 }).catch(() => {});
      await audit(sp, screen);
    }

    // ---------------------------------------------------------------- 2 POS lock overlay (and the register notices)
    for (const loc of ['', '/ar']) {
      await sp.setViewport({ width: 1280, height: 900 });
      await sp.goto(`${base}${loc}/pos?store=${a.id}`, { waitUntil: 'networkidle2' });
      const screen = `pos-lock ${loc ? loc.slice(1) : 'en'}`;
      const locked = await sp.evaluate(() => { const d = window.Alpine && Alpine.$data(document.body); if (!d) return false; d.locked = true; return true; });
      if (!locked) { add(screen, 'setup', 'serious', 'register has no Alpine state to lock'); continue; }
      await sleep(400);
      await audit(sp, screen, { scope: '.lock-overlay' });
      // the overlay must hold focus: press Tab around and count how often focus lands on the register behind it
      let behind = 0;
      for (let i = 0; i < 25; i++) {
        await sp.keyboard.press('Tab');
        if (await sp.evaluate(() => { const e = document.activeElement; return e && e !== document.body && !e.closest('.lock-overlay'); })) behind++;
      }
      if (behind) add(screen, 'kb:focus-escapes-lock', 'serious', `Tab reached the register behind the lock ${behind} times in 25 presses`);
      const dlg = await sp.evaluate(() => { const o = document.querySelector('.lock-overlay'); return o ? { role: o.getAttribute('role'), modal: o.getAttribute('aria-modal'), lab: o.getAttribute('aria-labelledby') || o.getAttribute('aria-label') } : null; });
      if (dlg && (dlg.role !== 'dialog' || dlg.modal !== 'true' || !dlg.lab)) add(screen, 'dialog-semantics', 'serious', `lock overlay is not a labelled modal dialog (${JSON.stringify(dlg)})`);
      const live = await sp.evaluate(() => { const e = document.querySelector('.lock-err'); return e ? { role: e.getAttribute('role'), live: e.getAttribute('aria-live') } : null; });
      if (live && !live.role && !live.live) add(screen, 'error-not-announced', 'serious', 'the lock error message is not a live region / alert');

      // storage-full / offline notices: the toast and the offline banners must be live regions that exist before they fill
      await sp.evaluate(() => { const d = Alpine.$data(document.body); d.locked = false; d.storageWarning = true; d.showToast('Storage is full: this sale was NOT saved.', 'error'); });
      await sleep(300);
      const notices = await sp.evaluate(() => {
        const t = document.querySelector('.pos-toast'); const b = document.querySelector('.pos-offline-banner');
        const live = (e) => e ? (e.getAttribute('role') || e.getAttribute('aria-live') || (e.closest('[role],[aria-live]') || {}).localName || '') : null;
        return { toast: live(t), banner: live(b), srLive: !!document.querySelector('[aria-live] .sr-only, .sr-only[aria-live], [role="status"].sr-only, [role="alert"].sr-only, .pos-sr-live') };
      });
      if (!notices.toast && !notices.srLive) add(`pos-notices ${loc ? loc.slice(1) : 'en'}`, 'toast-not-live', 'serious', 'the toast (carries "Storage is full: this sale was NOT saved") is not announced to screen readers');
      if (!notices.banner) add(`pos-notices ${loc ? loc.slice(1) : 'en'}`, 'banner-not-live', 'moderate', 'the offline banner has no status role');
      await sp.evaluate(() => { Alpine.$data(document.body).storageWarning = false; });
    }

    // ---------------------------------------------------------------- 3 "connect once" page (the service worker's offline answer)
    // The real worker route is pos/pos-sw.js (scope /pos, so no locale in the URL): the text a till shows comes from the browser's
    // Accept-Language, picked inside the worker from OFFLINE_ALL (else the locale of the request that registered it, OFFLINE).
    const getWorker = (lang) => sp.evaluate(async (u, l) => { const r = await fetch(u, { credentials: 'same-origin', headers: { 'Accept-Language': l } }); return r.ok ? r.text() : ''; }, `${base}/pos/pos-sw.js`, lang);
    const grab = (src, name) => { const mm = src.match(new RegExp(`const ${name} = (\\{[^\\n]*\\});`)); try { return mm ? JSON.parse(mm[1]) : null; } catch (_) { return null; } };
    const esc = (v) => String(v).replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));
    // the same markup the worker builds (lang + dir from the chosen text)
    const swHtml = (T) => `<!doctype html><html lang="${esc(T.lang || '')}" dir="${esc(T.dir || '')}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${esc(T.title)}</title></head><body><main style="font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem"><h1>${esc(T.title)}</h1><p>${esc(T.body)}</p></main></body></html>`;
    let enTitle = null;
    for (const loc of ['en', 'ar', 'ur']) {
      const screen = `connect-once ${loc}`;
      const src = await getWorker(loc);
      if (!src) { add(screen, 'setup', 'serious', 'could not fetch /pos/pos-sw.js'); continue; }
      const all = grab(src, 'OFFLINE_ALL');
      const registered = grab(src, 'OFFLINE');
      if (!all) {
        add(screen, 'setup', 'moderate', `the worker has no per-locale texts (OFFLINE_ALL): the page text is in the locale of the request that registered it${registered ? ` (${registered.lang || 'no lang'})` : ''}`);
        continue;
      }
      const T = all[loc];
      if (!T) { add(screen, 'setup', 'serious', `OFFLINE_ALL has no "${loc}" entry`); continue; }
      if (loc === 'en') enTitle = T.title;
      else if (enTitle && T.title === enTitle) add(screen, 'untranslated', 'serious', 'the connect-once title is identical to English');
      if (T.lang !== loc) add(screen, 'lang', 'serious', `lang is "${T.lang}", expected "${loc}"`);
      const wantDir = ['ar', 'ur'].includes(loc) ? 'rtl' : 'ltr';
      if (T.dir !== wantDir) add(screen, 'rtl-missing', 'serious', `dir is "${T.dir}", expected "${wantDir}"`);
      const pg = await L.freshPage(browser, newPage, { width: 390, height: 800 });
      await pg.page.setContent(swHtml(T));
      await audit(pg.page, screen, { keyboard: false });
      const got = await pg.page.evaluate(() => ({ lang: document.documentElement.lang, dir: document.documentElement.dir }));
      if (got.lang !== loc || got.dir !== wantDir) add(screen, 'markup', 'serious', `rendered <html lang="${got.lang}" dir="${got.dir}">`);
      await pg.close();
    }
  } finally {
    await staffCtx.close();
  }

  // ------------------------------------------------------------------ 4 help center (RTL ar/ur, LTR en/de)
  const pub = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
  try {
    for (const loc of ['ar', 'ur', 'en', 'de']) {
      for (const w of [1280, 390]) {
        await pub.page.setViewport({ width: w, height: 900 });
        const screen = `help-center ${loc} ${w}`;
        await pub.page.goto(`${base}/${loc}/help-center`, { waitUntil: 'networkidle2' });
        await audit(pub.page, `${screen} index`);
        const href = await pub.page.evaluate(() => { const a = [...document.querySelectorAll('main a[href*="help-center/"], a[href*="/help-center/"]')].find((x) => !/#/.test(x.getAttribute('href'))); return a ? a.href : null; });
        if (href) {
          await pub.page.goto(href, { waitUntil: 'networkidle2' });
          await audit(pub.page, `${screen} article`);
        } else add(screen, 'setup', 'moderate', 'no article link found on the index');
      }
    }
  } finally {
    await pub.close();
    P.cleanup([]);
  }

  const by = (imp) => findings.filter((f) => f.impact === imp).length;
  const summary = `a11y audit: ${findings.length} findings (critical ${by('critical')}, serious ${by('serious')}, moderate ${by('moderate')}, minor ${by('minor')})`;
  log(summary);
  fs.writeFileSync(path.join(process.env.TMPDIR || '/tmp', 'a11y-findings.json'), JSON.stringify(findings, null, 1));
  if (process.env.E2E_A11Y_STRICT === '1' && (by('critical') || by('serious'))) throw new Error(summary);
};
