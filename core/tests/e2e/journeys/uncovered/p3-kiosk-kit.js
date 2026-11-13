// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * p3-kiosk-kit — Phase 3 (P3-06): the kiosk in the kit themes, for each food theme (table = restaurant, brew = cafe).
 *
 *  tablet   opens {slug}/kiosk: the theme's own start screen; the language button switches it and a fresh start is the default again;
 *           "Tap to start" opens the store page in kiosk mode (order panel, no account / share / cart bar)
 *  order    one dish into the panel, Review -> optional name -> pay at the counter -> the done screen with the big order number;
 *           the order row is source=kiosk, "Guest", no phone; the countdown returns to the start screen by itself
 *  idle     after the idle time the "Are you still there?" dialog opens with a countdown; Continue keeps the order,
 *           then silence ends in the start screen with the panel empty
 *  lock     /login and the other site pages go back to the start screen, the back button stays on the page, another store's
 *           order endpoint is refused (403); a wrong staff PIN is refused, a 3 s press on the logo opens the pad, the right PIN
 *           leaves the kiosk (the next order is not source=kiosk, /login opens)
 *
 * Runs on E2E_ARMED_URL (an `artisan serve` of this worktree) when set, else E2E_BASE_URL.
 * Run: npm run test:e2e -- p3-kiosk-kit
 */

const path = require('path');
const { execFileSync } = require('child_process');
const A = require('../../lib/lane-a');

const CORE = path.resolve(__dirname, '../../../..');
const EXIT_PIN = '1357';
const STORES = [
  { theme: 'table', slug: 'crave-demo', seeder: 'Juwri\\Modules\\Storefront\\Database\\Seeders\\ThemeShowcaseSeeder' },
  { theme: 'brew', slug: 'bloom-coffee-demo', seeder: 'Juwri\\Modules\\Storefront\\Database\\Seeders\\KitDemoSeeder' },
];

function tinker(code) {
  return execFileSync(A.cfg.phpArtisan, ['artisan', 'tinker', '--execute', code], { cwd: CORE, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

module.exports = A.journey(async function p3KioskKit({ browser, log }) {
  const R = A.recorder('p3-kiosk', log);
  const app = A.cfg.armed || A.cfg.baseUrl;
  const sleep = A.sleep;

  for (const s of STORES) {
    execFileSync(A.cfg.phpArtisan, ['artisan', 'db:seed', `--class=${s.seeder}`, '--force'], { cwd: CORE, stdio: ['ignore', 'pipe', 'pipe'] });
    const id = Number(A.sqlValue('SELECT id FROM stores WHERE slug = ?', [s.slug]));
    A.expect(id > 0, `${s.slug} was not seeded`);
    s.id = id;
    const owner = Number(A.sqlValue('SELECT user_id FROM stores WHERE id = ?', [id]));
    tinker(`
      \\App\\Models\\StoreOption::updateStoreOption(${id}, 'store_template', '${s.theme}');
      \\App\\Models\\StoreOption::updateStoreOption(${id}, 'kiosk_enabled', '1');
      \\App\\Models\\StoreOption::updateStoreOption(${id}, 'kiosk_exit_pin', '${EXIT_PIN}');
      \\App\\Models\\StoreOption::updateStoreOption(${id}, 'kiosk_setup_pin', '');
      \\App\\Models\\StoreOption::updateStoreOption(${id}, 'kiosk_idle_seconds', '15');
      \\App\\Models\\StoreOption::updateStoreOption(${id}, 'menu_languages', json_encode(['en', 'ar']));
      \\App\\Models\\StoreOption::updateStoreOption(${id}, 'default_language', 'en');
      \\Juwri\\Core\\Models\\EntitlementOverride::updateOrCreate(['user_id' => ${owner}, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
      $h = []; foreach (['mon','tue','wed','thu','fri','sat','sun'] as $d) { $h[$d] = ['open' => '00:00', 'close' => '23:59']; }
      \\Juwri\\Core\\Models\\Branch::where('store_id', ${id})->update(['hours' => json_encode($h), 'accepting_orders' => 1, 'active' => 1]);
      \\Illuminate\\Support\\Facades\\Cache::flush();
    `);
  }
  R.ok('both demo stores are on their kit theme with the kiosk on (idle 15 s, test exit PIN), open all day');

  const consent = (page, url) => page.setCookie({ name: 'site_consent', value: 'v1.a0.m0', url });
  const lastOrder = (id) => A.sql('SELECT source, customer_name, phone_number FROM orders WHERE store_id = ? ORDER BY id DESC LIMIT 1', [id])[0] || [];

  for (const s of STORES) {
    const tag = `${s.theme}`;
    await A.withPage(browser, async (p) => {
      p.on('pageerror', (e) => log(`  p3-kiosk ${tag} pageerror: ${e.message}`));
      await consent(p, app);
      const start = `${app}/${s.slug}/kiosk`;

      // ── start screen, language ───────────────────────────────────────────────────────────────────────────────
      await p.goto(start, { waitUntil: 'networkidle2' });
      let dom = await p.evaluate(() => ({ theme: document.documentElement.dataset.themeSlug, start: !!document.querySelector('[data-kiosk-start]'), go: !!document.querySelector('[data-kiosk-go]'), dir: document.documentElement.dir, ar: !!document.querySelector('a.c-pill--lang[lang="ar"]') }));
      A.expect(dom.theme === s.theme && dom.start && dom.go && dom.ar && dom.dir === 'ltr', `${tag}: the start screen is the theme's own (${JSON.stringify(dom)})`);
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle2' }), p.click('a.c-pill--lang[lang="ar"]')]);
      A.expect((await p.evaluate(() => document.documentElement.dir)) === 'rtl', `${tag}: the Arabic button switches the start screen`);
      await p.goto(start, { waitUntil: 'networkidle2' });
      A.expect((await p.evaluate(() => document.documentElement.dir)) === 'ltr', `${tag}: a fresh start is the store default again`);
      R.ok(`${tag}: start screen is the theme's own; the language button switches it and a fresh start returns to the default`);

      // ── the ordering screen ──────────────────────────────────────────────────────────────────────────────────
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle2' }), p.click('[data-kiosk-go]')]);
      dom = await p.evaluate(() => ({
        kiosk: document.body.classList.contains('p-kiosk'), panel: !!document.querySelector('.c-kiosk__panel'), idle: !!document.querySelector('[data-kiosk-overlay="idle"]'),
        account: !!document.querySelector('.c-pill--account, [data-kit-share]'), bar: !!document.querySelector('.c-cartbar'), exit: !!document.querySelector('[data-kiosk-exit]'),
      }));
      A.expect(dom.kiosk && dom.panel && dom.idle && dom.exit && !dom.account && !dom.bar, `${tag}: the kiosk layout (${JSON.stringify(dom)})`);
      R.ok(`${tag}: kiosk layout — order panel, idle + PIN dialogs, no account / share / cart bar`);

      // ── order: one dish without choices, review, pay at the counter ──────────────────────────────────────────
      await p.waitForSelector('.c-kiosk__menu [data-juwri-add]');
      await p.click('.c-kiosk__menu [data-juwri-add]');
      await p.waitForFunction(() => Number(document.querySelector('.c-kiosk__panel [data-juwri-count]').textContent) >= 1, { timeout: 10000 });
      await p.waitForFunction(() => document.querySelector('.c-kiosk__panel [data-juwri-total]').textContent.trim() !== '', { timeout: 10000 });
      await p.click('.c-kiosk__panel [data-juwri-open="checkout"]');
      await p.waitForSelector('#checkout:not([hidden])');
      A.expect(!(await p.$('#checkout input[name="phone-number"]')), `${tag}: no phone field at a kiosk`);
      await p.evaluate(() => { const r = document.querySelector('#checkout input[name="pay_via"][value="cash"]'); if (r) r.click(); });
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }), p.click('#checkout [data-juwri-place]')]);
      await p.waitForSelector('[data-kiosk-done]');
      const done = await p.evaluate(() => ({ no: document.querySelector('.c-kdone__no').textContent.trim(), left: document.querySelector('[data-kiosk-left]').textContent.trim() }));
      A.expect(done.no.length > 2, `${tag}: the done screen shows the order number (${JSON.stringify(done)})`);
      const row = lastOrder(s.id);
      A.expect(row[0] === 'kiosk' && !row[2], `${tag}: the order is source=kiosk without a phone (${JSON.stringify(row)})`);
      R.ok(`${tag}: ordered at the kiosk — done screen ${done.no}, order row source=kiosk, "${row[1]}", no phone`);

      // ── back to start by itself ──────────────────────────────────────────────────────────────────────────────
      await p.waitForFunction((u) => location.pathname === new URL(u).pathname, { timeout: 20000 }, start);
      A.expect(!!(await p.$('[data-kiosk-start]')), `${tag}: the done countdown returns to the start screen`);
      R.ok(`${tag}: the done countdown returns to the start screen`);

      // ── idle dialog ──────────────────────────────────────────────────────────────────────────────────────────
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle2' }), p.click('[data-kiosk-go]')]);
      await p.waitForSelector('.c-kiosk__menu [data-juwri-add]');
      await p.click('.c-kiosk__menu [data-juwri-add]');
      await p.waitForFunction(() => Number(document.querySelector('.c-kiosk__panel [data-juwri-count]').textContent) >= 1, { timeout: 10000 });
      await p.waitForSelector('[data-kiosk-overlay="idle"]:not([hidden])', { timeout: 25000 });
      const idle = await p.evaluate(() => ({ left: document.querySelector('[data-kiosk-overlay="idle"] [data-kiosk-left]').textContent.trim(), inert: document.querySelector('[data-kit-page]').hasAttribute('inert'), focus: !!document.activeElement.closest('[data-kiosk-overlay="idle"]') }));
      A.expect(Number(idle.left) <= 15 && idle.inert && idle.focus, `${tag}: the idle dialog has a countdown, the page is inert, focus is inside (${JSON.stringify(idle)})`);
      await p.click('[data-kiosk-continue]');
      A.expect((await p.evaluate(() => Number(document.querySelector('.c-kiosk__panel [data-juwri-count]').textContent))) >= 1, `${tag}: Continue keeps the order`);
      await p.waitForSelector('[data-kiosk-overlay="idle"]:not([hidden])', { timeout: 25000 });
      await p.waitForFunction((u) => location.pathname === new URL(u).pathname, { timeout: 30000 }, start);
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle2' }), p.click('[data-kiosk-go]')]);
      await sleep(800);
      A.expect((await p.evaluate(() => Number(document.querySelector('.c-kiosk__panel [data-juwri-count]').textContent))) === 0, `${tag}: after the idle reset the next customer starts with an empty order`);
      R.ok(`${tag}: idle dialog (countdown, inert page, focus inside), Continue keeps the order, silence returns to the start with an empty order`);

      // ── lock ─────────────────────────────────────────────────────────────────────────────────────────────────
      for (const u of ['/login', '/pricing']) {
        await p.goto(`${app}${u}`, { waitUntil: 'networkidle2' });
        A.expect(new URL(p.url()).pathname === `/${s.slug}/kiosk`, `${tag}: ${u} goes back to the start screen (${p.url()})`);
      }
      const other = STORES.find((o) => o !== s);
      const refused = await p.evaluate(async (slug) => { const r = await fetch(`/${slug}/quote`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: '{}' }); return r.status; }, other.slug);
      A.expect(refused === 403, `${tag}: another store's endpoint is refused (${refused})`);
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle2' }), p.click('[data-kiosk-go]')]);
      const href = p.url();
      await p.evaluate(() => history.back());
      await sleep(600);
      A.expect(p.url() === href, `${tag}: the back button stays on the kiosk page (${p.url()})`);
      R.ok(`${tag}: /login and other pages return to the start screen, another store's endpoint is 403, back stays`);

      // ── staff exit ───────────────────────────────────────────────────────────────────────────────────────────
      const logo = await p.$('[data-kiosk-exit]');
      const box = await logo.boundingBox();
      const press = async () => { await p.mouse.move(box.x + box.width / 2, box.y + box.height / 2); await p.mouse.down(); await sleep(3400); await p.mouse.up(); };
      await press();
      await p.waitForSelector('[data-kiosk-overlay="pin"]:not([hidden])');
      const pin = async (digits) => { for (const d of digits) await p.click(`[data-kiosk-overlay="pin"] [data-key="${d}"]`); await p.click('[data-kiosk-pin-submit]'); };
      await pin('0000');
      await p.waitForFunction(() => document.querySelector('[data-kiosk-pin-error]').textContent.trim() !== '', { timeout: 8000 });
      A.expect(!new URL(p.url()).pathname.endsWith('/login') && !!(await p.$('[data-kiosk-overlay="pin"]:not([hidden])')), `${tag}: a wrong PIN keeps the kiosk on`);
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle2' }), pin(EXIT_PIN)]);
      A.expect(new URL(p.url()).pathname === `/${s.slug}`, `${tag}: the right PIN leaves for the store page (${p.url()})`);
      const login = await p.goto(`${app}/login`, { waitUntil: 'domcontentloaded' });
      A.expect(new URL(p.url()).pathname === '/login', `${tag}: after the exit /login opens (${p.url()})`);
      R.ok(`${tag}: wrong PIN refused, a 3 s press on the logo opens the pad, the right PIN leaves the kiosk and ends the lock`);
    });
  }
  R.summary();
});
