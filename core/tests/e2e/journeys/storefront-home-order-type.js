// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * TPL (opt-in) — the home-page order type drives the theme's own control.
 *
 * Every retained theme keeps its order-type control inside checkout, so a diner
 * browsing the menu cannot say "this is for delivery" until they are already
 * paying. The shared bar is a remote control for the control the theme already
 * has: it clicks the theme's own (hidden) button rather than forcing a value
 * into the POST, so the theme renders its own dependent fields and its own
 * payload is already right.
 *
 * That contract cannot be checked on the server — it is entirely about whether
 * fourteen compiled bundles honour a click on a control that is not on screen.
 * They do; an earlier probe said eight of them did not, because it clicked
 * `delivery`, which is already their default.
 *
 * Env: E2E_RUN_ORDER_TYPE=1, E2E_OT_STORES (default: the 17 retained demos).
 */

const RETAINED = require('../lib/retained');

/**
 * Themes that show their OWN order-type control on the home page (the shared bar stands down
 * for them). None today: orbit, velora and crumb used to, but they now keep that picker hidden
 * in the DOM (`.home-order-types`, display:none) and rely on the shared #mbOrderType bar as the
 * visible one, so they are exercised like every other theme.
 */
const NATIVE_ON_HOME = [];

/** picnic and scoop speak their own words and map them at submit time. */
const ALIASES = { takeaway: ['takeaway', 'pickup'], delivery: ['delivery'], 'on-table': ['on-table', 'on_table', 'dinein', 'dine-in'] };

module.exports = async function storefrontHomeOrderType({ page, cfg, log, shot, sleep }) {
  const stores = (process.env.E2E_OT_STORES || RETAINED.join(','))
    .split(',').map((s) => s.trim()).filter(Boolean);

  const failures = [];
  const rows = [];

  for (const theme of stores) {
    const resp = await page.goto(`${cfg.baseUrl}/${theme}-demo`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
    if (!resp || resp.status() >= 400) { failures.push(`${theme}: page returned ${resp && resp.status()}`); continue; }
    await sleep(2600);
    await page.evaluate(() => {
      document.querySelectorAll(
        '#splashSkip, #mbSplashSkip, #obSkip, #onbSkip, .splash-skip, .ob-skip, .onb-skip, [data-csp-action="splash-skip"]'
      ).forEach((skip) => skip.click());
    });
    await sleep(600);

    const seen = await page.evaluate(async (aliases) => {
      const bar = document.getElementById('mbOrderType');
      if (!bar) return { removed: true };

      // Hit-test rather than measure: these sections are moved next to the
      // menu and can end up under a theme's fixed chrome.
      bar.scrollIntoView({ block: 'center' });
      await new Promise((r) => setTimeout(r, 400));
      const r = bar.getBoundingClientRect();
      const hit = document.elementFromPoint(
        Math.round(r.left + r.width / 2),
        Math.round(Math.max(1, Math.min(r.top + 14, window.innerHeight - 1))),
      );

      const chips = [...bar.querySelectorAll('.mbt-chip')].filter((c) => !c.hidden);
      const ATTRS = ['data-ot', 'data-otype', 'data-order-type', 'data-type'];
      const valueOf = (e) => ATTRS.map((a) => e.getAttribute(a)).find(Boolean);
      const themeSelected = () => [...document.querySelectorAll('[' + ATTRS.join('],[') + ']')]
        .filter((e) => !bar.contains(e))
        .filter((e) => /(^|\s)(is-on|active|selected|is-active|current)(\s|$)/.test(String(e.className)))
        .map(valueOf);

      /* Click TAKEAWAY specifically. delivery is the default on most themes, so
         picking it proves nothing — that is exactly how an earlier probe
         concluded eight bundles ignored the click. */
      const chip = chips.find((c) => c.getAttribute('data-mbt') === 'takeaway');
      const hitDesc = hit ? `${hit.tagName.toLowerCase()}#${hit.id}.${String(hit.className).slice(0, 60)}` : 'nothing';
      if (!chip) return { visible: !!hit && bar.contains(hit), chips: chips.length, noTakeaway: true, hitDesc };

      chip.click();
      await new Promise((r) => setTimeout(r, 900));
      const afterChip = {
        themeSelected: themeSelected(),
        barPressed: chips.filter((c) => c.getAttribute('aria-pressed') === 'true').map((c) => c.getAttribute('data-mbt')),
      };

      /* And the other direction. A guest who changes their mind inside checkout
         must not come back to a bar showing the old choice — and the bar must
         still BE there: its stand-down check asks whether the theme shows an
         order-type control, which every theme does once checkout is open, so a
         version that re-decided on each sync deleted itself the first time
         anyone touched the theme's own control. */
      const native = [...document.querySelectorAll('[' + ATTRS.join('],[') + ']')]
        .filter((e) => !bar.contains(e))
        .find((e) => /on.table|dinein/.test(valueOf(e) || ''));
      if (native) native.click();
      await new Promise((r) => setTimeout(r, 900));
      const live = document.getElementById('mbOrderType');

      return {
        visible: !!hit && bar.contains(hit),
        hitDesc,
        chips: chips.length,
        themeSelected: afterChip.themeSelected,
        barPressed: afterChip.barPressed,
        accepted: aliases.takeaway,
        survivedNative: !!live,
        mirrored: live
          ? [...live.querySelectorAll('.mbt-chip')].filter((c) => c.getAttribute('aria-pressed') === 'true').map((c) => c.getAttribute('data-mbt'))
          : null,
      };
    }, ALIASES);

    const shouldStandDown = NATIVE_ON_HOME.includes(theme);
    if (shouldStandDown) {
      if (!seen.removed) failures.push(`${theme}: surfaces its own order type on home, so the shared bar must stand down`);
      else rows.push(`${theme}: stood down (native control on home)`);
      continue;
    }

    if (seen.removed) { failures.push(`${theme}: no home order-type bar rendered`); continue; }
    if (!seen.visible) { failures.push(`${theme}: bar is in the DOM but not visible (the element at its centre: ${seen.hitDesc})`); continue; }
    if (seen.chips < 2) { failures.push(`${theme}: bar offers ${seen.chips} option(s) — not a choice`); continue; }
    if (seen.noTakeaway) { failures.push(`${theme}: no takeaway chip to exercise`); continue; }

    // The whole point: the THEME's own control moved.
    const moved = (seen.themeSelected || []).some((v) => seen.accepted.includes(v));
    if (!moved) failures.push(`${theme}: theme's own control still reads ${JSON.stringify(seen.themeSelected)} after picking takeaway`);
    if (seen.barPressed.join() !== 'takeaway') failures.push(`${theme}: bar shows ${JSON.stringify(seen.barPressed)} after picking takeaway`);
    if (!seen.survivedNative) failures.push(`${theme}: the bar removed itself when the theme's own control was used`);
    else if ((seen.mirrored || []).join() !== 'on-table') failures.push(`${theme}: bar shows ${JSON.stringify(seen.mirrored)} after the theme switched to dine-in`);

    rows.push(`${theme}: ${seen.chips} chips, theme→${JSON.stringify(seen.themeSelected)}, mirrors back`);
    if (theme === stores[0]) await shot(page, `home-order-type-${theme}`);
  }

  /* One end-to-end proof that the choice reaches the wire. Every theme has its
     own checkout markup, so this drives ONE of them rather than pretending to
     drive seventeen: pick delivery on the home bar, fill crave's checkout, and
     read the /send-order body. The request is intercepted, never placed. */
  if (stores.includes('crave')) {
    await page.goto(`${cfg.baseUrl}/crave-demo`, { waitUntil: 'domcontentloaded', timeout: cfg.navTimeout });
    await sleep(2600);
    const wire = await page.evaluate(async () => {
      window.__sent = [];
      const of = window.fetch;
      window.fetch = function (i, init) {
        const u = (typeof i === 'string') ? i : (i && i.url);
        if (u && u.indexOf('/send-order') !== -1) {
          let s = '';
          try {
            const b = init && init.body;
            if (b instanceof FormData) { const o = {}; b.forEach((v, k) => { o[k] = v; }); s = JSON.stringify(o); } else s = String(b);
          } catch (e) { s = '?'; }
          window.__sent.push(s);
          return Promise.resolve(new Response(JSON.stringify({ success: false, message: 'intercepted' }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        }
        return of.apply(this, arguments);
      };

      document.querySelector('[data-open]:not(.mb-combo-sheet):not([data-open="0"])').click();
      await new Promise((r) => setTimeout(r, 1200));
      const add = [...document.querySelectorAll('button')].find((b) => /add|أضف/i.test(b.textContent));
      if (add) add.click();
      await new Promise((r) => setTimeout(r, 800));
      const back = document.querySelector('.fab.back');
      if (back) back.click();
      await new Promise((r) => setTimeout(r, 700));

      const bar = document.getElementById('mbOrderType');
      const chip = bar && [...bar.querySelectorAll('.mbt-chip')].find((c) => c.getAttribute('data-mbt') === 'delivery');
      if (!chip) return { error: 'no delivery chip' };
      chip.click();
      await new Promise((r) => setTimeout(r, 900));

      const set = (sel, v) => {
        const e = document.querySelector(sel);
        if (!e) return false;
        e.value = v;
        e.dispatchEvent(new Event('input', { bubbles: true }));
        e.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
      };
      set('#cvCustName', 'Test Diner');
      set('#cvCustPhone', '966500000123');
      const addressed = set('#cvCustAddr', '1 King Fahd Rd');

      const place = document.querySelector('#cvPlaceOrder');
      if (place) place.click();
      await new Promise((r) => setTimeout(r, 1500));

      return { addressed, sent: window.__sent };
    });

    if (wire.error) failures.push(`crave wire check: ${wire.error}`);
    else if (!wire.sent || !wire.sent.length) failures.push('crave wire check: no /send-order request was made');
    else {
      const body = wire.sent[0];
      if (!/"ordering-type"\s*:\s*"delivery"/.test(body)) {
        failures.push(`crave wire check: payload does not carry ordering-type=delivery — ${body.slice(0, 300)}`);
      }
      // The reason the bar drives the theme instead of overriding the POST:
      // submitOrder validates address as required_if delivery.
      if (!/"address"\s*:\s*"[^"]+"/.test(body)) {
        failures.push('crave wire check: delivery payload carries no address — the theme did not switch mode');
      }
      rows.push('crave: /send-order carries ordering-type=delivery with an address');
    }
  }

  log('home order type —\n  ' + rows.join('\n  '));
  if (failures.length) throw new Error('home order type:\n  - ' + failures.join('\n  - '));
  return { checked: rows.length };
};
