// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * p3-theme-aliases — Phase 3 (P3-12 / P3-15): one aliased store per kit theme, in a real browser, on the armed instance of the
 * build (JUWRI_THEME_ALIASES=true there; the platform default is OFF). The store keeps its OLD template slug in the database; the
 * page renders the kit theme the slug maps to, in the mapped preset, takes a product into the basket and the server quotes it.
 *
 *   crave -> table/app (crave-demo)   matcha -> brew/matcha (bloom-coffee-demo)   shelf -> aisle/clean (dune-and-co-demo)
 *   zevoa -> basket/fresh (fresh-mart-demo)   bookla -> book/calm (sidra-salon-demo)
 *
 * Run: npm run test:e2e -- p3-theme-aliases   (E2E_ALIAS_URL = `artisan serve` of the build with JUWRI_THEME_ALIASES=true; the other journeys run with aliases off)
 */

const path = require('path');
const { execFileSync } = require('child_process');
const A = require('../../lib/lane-a');

const CORE = path.resolve(__dirname, '../../../..');
const ROWS = [
  { old: 'crave', theme: 'table', scheme: 'dark', slug: 'crave-demo' },
  { old: 'matcha', theme: 'brew', scheme: 'light', slug: 'bloom-coffee-demo' },
  { old: 'shelf', theme: 'aisle', scheme: 'light', slug: 'dune-and-co-demo' },
  { old: 'zevoa', theme: 'basket', scheme: 'light', slug: 'fresh-mart-demo' },
  { old: 'bookla', theme: 'book', scheme: 'light', slug: 'sidra-salon-demo' },
];

function tinker(code) {
  return execFileSync(A.cfg.phpArtisan, ['artisan', 'tinker', '--execute', code], { cwd: process.env.E2E_APP_DIR || CORE, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

module.exports = A.journey(async function p3ThemeAliases({ browser, log }) {
  const R = A.recorder('p3-aliases', log);
  const app = (process.env.E2E_ALIAS_URL || '').replace(/\/$/, '') || A.cfg.armed;
  if (!app) {
    const why = 'needs an armed instance with JUWRI_THEME_ALIASES=true: set E2E_ARMED_URL';
    R.skip('whole journey', why);
    R.summary();
    return { skip: why };
  }
  const tpl = (id) => (tinker(`echo 'T='.\\Juwri\\Core\\Settings\\StoreSettings::string(${id}, 'store_template');`).match(/T=(\S*)/) || [])[1] || '';
  const present = (slug) => Number(A.sqlValue('SELECT COUNT(*) FROM stores WHERE slug = ?', [slug])) > 0;
  for (const row of ROWS) {
    if (!present(row.slug)) { R.skip(`${row.old} -> ${row.theme}`, `demo store ${row.slug} is not in this database`); continue; }
    const storeId = Number(A.sqlValue('SELECT id FROM stores WHERE slug = ?', [row.slug]));
    const before = tpl(storeId);
    tinker(`\\App\\Models\\StoreOption::updateStoreOption(${storeId}, 'store_template', '${row.old}'); \\Illuminate\\Support\\Facades\\Cache::flush();`);
    try {
      await A.withPage(browser, async (g) => {
        const errors = [];
        g.on('pageerror', (e) => errors.push(e.message));
        await g.setCookie({ name: 'site_consent', value: 'v1.a0.m0', url: app });
        const resp = await g.goto(`${app}/${row.slug}`, { waitUntil: 'networkidle2' });
        A.expect(resp.status() === 200, `${row.slug} answered ${resp.status()}`);
        const dom = await g.evaluate(() => ({ theme: document.documentElement.dataset.themeSlug, scheme: document.documentElement.dataset.scheme }));
        A.expect(dom.theme === row.theme, `${row.old} must render ${row.theme} (got ${dom.theme})`);
        A.expect(dom.scheme === row.scheme, `${row.old} -> ${row.theme}: preset scheme ${row.scheme} (got ${dom.scheme})`);
        A.expect(errors.length === 0, `page errors: ${errors.join(' | ')}`);
        const stored = tpl(storeId);
        A.expect(stored === row.old, `the stored slug is untouched (${stored})`);
        // a product into the basket, quoted by the server (not the book theme: its flow is the booking engine)
        if (row.theme !== 'book') {
          const added = await g.evaluate(async () => {
            // a card may open a variant / option sheet instead of adding (retail products with sizes): try the first few buttons
            // until one puts a line in the basket, then poll for the server's quote
            const buttons = [...document.querySelectorAll('[data-juwri-add]:not([disabled])')].slice(0, 8);
            for (const btn of buttons) {
              btn.click();
              for (let i = 0; i < 12; i++) {
                await new Promise((r) => setTimeout(r, 500));
                const s = window.Juwri && window.Juwri.cart.snapshot();
                if (s && s.count >= 1) {
                  for (let k = 0; k < 24; k++) {
                    const q = window.Juwri.cart.snapshot();
                    if (q.quote && q.quote.total && q.quote.total.minor > 0) return true;
                    await new Promise((r) => setTimeout(r, 500));
                  }
                  return false;
                }
              }
              document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            }
            const link = document.querySelector('.c-pcard:not(.is-soldout) .c-pcard__link') || document.querySelector('.c-pcard__link');
            return link ? 'NAV:' + link.href : false;
          });
          let ok = added === true;
          if (typeof added === 'string') { // products with variants (aisle): open the first product's page, pick the first free swatch + size, add
            await g.goto(added.slice(4), { waitUntil: 'networkidle2' });
            ok = await g.evaluate(async () => {
              for (let i = 0; i < 20 && !document.querySelector('[data-kit-add]'); i++) await new Promise((r) => setTimeout(r, 500));
              // try every colour x size until the stock note does not say sold out (what p3-aisle-retail ordered last is gone)
              const sw = [...document.querySelectorAll('.c-swatch__input')].filter((x) => !x.disabled);
              const sz = [...document.querySelectorAll('.c-size__input')].filter((x) => !x.disabled);
              outer: for (const a of (sw.length ? sw : [null])) {
                for (const b of (sz.length ? sz : [null])) {
                  if (a) a.click();
                  if (b) b.click();
                  await new Promise((r) => setTimeout(r, 250));
                  const note = (document.querySelector('[data-kit-stock]') || {}).textContent || '';
                  if (!/sold out/i.test(note) && !document.querySelector('[data-kit-add]')?.disabled) break outer;
                }
              }
              const add = document.querySelector('[data-kit-add]');
              if (!add) return 'no add button';
              add.click();
              for (let k = 0; k < 40; k++) {
                await new Promise((r) => setTimeout(r, 500));
                const q = window.Juwri && window.Juwri.cart.snapshot();
                if (q && q.count >= 1 && q.quote && q.quote.total && q.quote.total.minor > 0) return true;
              }
              return 'cart: ' + JSON.stringify(window.Juwri && window.Juwri.cart.snapshot()).slice(0, 300) + ' note: ' + (document.querySelector('[data-kit-stock]') || {}).textContent;
            });
          }
          A.expect(ok === true, `${row.old} -> ${row.theme}: a product goes into the basket and the server quotes it (${typeof ok === 'string' ? ok : ''})`);
        }
      }, { width: 430, height: 900 });
      R.ok(`${row.old} -> ${row.theme}: ${row.slug} renders the kit theme (${row.scheme}), slug untouched${row.theme === 'book' ? '' : ', basket quoted by the server'}`);
    } finally {
      const restore = before ? `'${before}'` : "'table'";
      tinker(`\\App\\Models\\StoreOption::updateStoreOption(${storeId}, 'store_template', ${restore}); \\Illuminate\\Support\\Facades\\Cache::flush();`);
    }
  }
  R.summary();
});
