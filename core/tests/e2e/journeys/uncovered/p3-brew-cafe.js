// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * p3-brew-cafe — Phase 3 (P3-07): a cafe on the `brew` kit theme, owner → customer → owner, plus cross-store IDOR.
 *
 *  owner      the demo cafe "Bloom Coffee" (KitDemoSeeder) is put on `brew`; the kitchen's usual prep time is set
 *  guest      the real storefront: compact header, pickup hero with "Ready in ~N min", grid cards; no "Order again" rail
 *             a drink with required choices opens the cafe sheet (segmented size, chips for milk), the one-line "Name on the
 *             cup" is typed, the cart bar shows the item count + ready time + the SERVER total, checkout pays at the counter
 *  customer   signs in; "Order again" lists the drink with its last choices; one tap puts it in the cart with those choices at
 *             the same server total; a choice-less row for another store's item can never be injected; when the owner marks the
 *             drink sold out the row disappears
 *  owner      the KDS ticket (poll JSON) and the order page carry the note; the order total equals the quoted total
 *  guest      the tracking page is the pickup ticket: big number, 3-step bar, the receipt shows the note once
 *  IDOR       another store's customer sees none of this store's recent lines
 *
 * Owner and customer steps run on E2E_ARMED_URL when set, else on E2E_BASE_URL. Run: npm run test:e2e -- p3-brew-cafe
 */

const path = require('path');
const { execFileSync } = require('child_process');
const A = require('../../lib/lane-a');

const SLUG = 'bloom-coffee-demo';
const OWNER = { email: 'kit-demo-owner@demo.test', pass: 'kit-demo-pass' };
const CUSTOMER = { email: 'e2e-brew-customer@local.invalid', pass: 'E2e-brew-customer-1', name: 'Noura E2E', phone: '+966500009911' };
const NOTE = 'Sara';
const CORE = path.resolve(__dirname, '../../../..'); // core/

/** a short PHP statement run through artisan tinker in this worktree (test data only: a customer row with a known password) */
function tinker(code) {
  return execFileSync(A.cfg.phpArtisan, ['artisan', 'tinker', '--execute', code], { cwd: CORE, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

module.exports = A.journey(async function p3BrewCafe({ browser, log }) {
  const R = A.recorder('p3-brew', log);
  const app = A.cfg.armed || A.cfg.baseUrl;
  const sleep = A.sleep;

  // ── owner: the demo cafe on brew, a known prep time ─────────────────────────────────────────────────────────────
  execFileSync(A.cfg.phpArtisan, ['artisan', 'db:seed', '--class=Juwri\\Modules\\Storefront\\Database\\Seeders\\KitDemoSeeder', '--force'], { cwd: CORE, stdio: ['ignore', 'pipe', 'pipe'] });
  const storeId = Number(A.sqlValue('SELECT id FROM stores WHERE slug = ?', [SLUG]));
  A.expect(storeId > 0, 'the demo cafe was not seeded');
  const ownerId = Number(A.sqlValue('SELECT user_id FROM stores WHERE id = ?', [storeId]));
  tinker(`
    \\App\\Models\\StoreOption::updateStoreOption(${storeId}, 'store_template', 'brew');
    \\App\\Models\\StoreOption::updateStoreOption(${storeId}, 'prep_eta_static', '6-10');
    $all = []; foreach (['sun','mon','tue','wed','thu','fri','sat'] as $d) { $all[$d] = ['open' => '00:00', 'close' => '23:59', 'closed' => false]; }
    \\Juwri\\Core\\Models\\Branch::query()->where('store_id', ${storeId})->get()->each(fn ($b) => $b->forceFill(['hours' => $all])->save()); // open round the clock: the ready-in chip only shows while the kitchen is open
    \\Illuminate\\Support\\Facades\\Cache::flush();
    \\Juwri\\Core\\Models\\EntitlementOverride::updateOrCreate(['user_id' => ${ownerId}, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
    \\App\\Models\\Customer::where('email', '${CUSTOMER.email}')->delete();
    \\App\\Models\\Customer::create(['store_id' => ${storeId}, 'name' => '${CUSTOMER.name}', 'email' => '${CUSTOMER.email}', 'phone' => '${CUSTOMER.phone}', 'password' => \\Illuminate\\Support\\Facades\\Hash::make('${CUSTOMER.pass}')]);
  `);
  R.ok(`the demo cafe #${storeId} "${SLUG}" is on brew, the kitchen's usual time is 6-10 min, a customer account exists`);

  const flatWhiteId = A.sqlValue("SELECT public_id FROM items WHERE store_id = ? AND name = 'Flat white' AND deleted_at IS NULL LIMIT 1", [storeId]);
  A.expect(flatWhiteId, 'the demo menu has a Flat white');
  const orderIds = [];

  /** essential cookies only (P1-23): the banner would sit over the phone cart bar */
  const consent = (page) => page.setCookie({ name: 'site_consent', value: 'v1.a0.m0', url: app });

  async function signIn(page) {
    const slug = SLUG;
    const res = await page.evaluate(async (slug, email, pass, id) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
      const r = await fetch(`/${slug}/customer/login`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ email, password: pass, store_id: id }) });
      return { status: r.status, body: await r.json().catch(() => ({})) };
    }, slug, CUSTOMER.email, CUSTOMER.pass, storeId);
    A.expect(res.status === 200 && res.body.success, `the customer could not sign in (${JSON.stringify(res)})`);
  }

  /** the cart's visible total text and the server's quote for the same lines */
  async function serverQuote(page, lines, orderType = 'takeaway') {
    return page.evaluate(async (slug, lines, type) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
      const r = await fetch(`/${slug}/quote`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ lines, order_type: type }) });
      return r.json();
    }, SLUG, lines, orderType);
  }

  // ── guest: the page, the cafe sheet, the note, the cart, the order ──────────────────────────────────────────────
  await A.withPage(browser, async (g) => {
    g.on('pageerror', (e) => log(`  p3-brew pageerror: ${e.message}`));
    await consent(g);
    await g.goto(`${app}/${SLUG}`, { waitUntil: 'networkidle2' });
    const dom = await g.evaluate(() => ({
      theme: document.documentElement.dataset.themeSlug,
      pickup: !!document.querySelector('.c-pickup .c-hero--pickup'),
      compact: !!document.querySelector('.c-header .c-header__search') && !document.querySelector('.c-header__cover'),
      grid: document.querySelectorAll('.c-gcard').length,
      chip: [...document.querySelectorAll('.c-hchip')].map((e) => e.textContent.trim()),
      reorder: !!document.querySelector('.c-reorder'),
      h1: document.querySelectorAll('h1').length,
    }));
    A.expect(dom.theme === 'brew', `the page must render brew (${dom.theme})`);
    A.expect(dom.pickup && dom.compact && dom.grid > 20, `brew home markup missing: ${JSON.stringify(dom)}`);
    A.expect(dom.chip.some((c) => /Ready in ~8 min/.test(c)), `the hero must show the kitchen's time (${JSON.stringify(dom.chip)})`);
    A.expect(!dom.reorder, 'a guest has no "Order again" rail');
    A.expect(dom.h1 === 1, `exactly one h1 (${dom.h1})`);
    R.ok('guest: brew home — compact header with search, pickup hero "Ready in ~8 min", grid cards, one h1, no Order again rail');

    // the cafe sheet
    await g.click(`.c-gcard[data-item="${flatWhiteId}"] .c-gcard__add`);
    await g.waitForSelector('#item-sheet:not([hidden]) .c-sheet--cafe');
    const sheet = await g.evaluate(() => ({
      seg: document.querySelectorAll('#item-sheet .c-group--seg .c-opt--seg').length,
      chips: document.querySelectorAll('#item-sheet .c-group--chips .c-opt--chip').length,
      note: (() => { const n = document.querySelector('#item-sheet input[name=note]'); return n ? { label: document.querySelector('label[for=note]').textContent.trim(), max: n.maxLength } : null; })(),
      focusInside: document.getElementById('item-sheet').contains(document.activeElement),
    }));
    A.expect(sheet.seg >= 2 && sheet.chips >= 2, `size is segmented, milk is chips (${JSON.stringify(sheet)})`);
    A.expect(sheet.note && sheet.note.label === 'Name on the cup' && sheet.note.max === 20, `the cafe sheet has the 20-character name field (${JSON.stringify(sheet.note)})`);
    A.expect(sheet.focusInside, 'focus is inside the sheet');
    // pick Medium (2nd size), Oat (a milk chip), type the name
    await g.evaluate(() => {
      const size = [...document.querySelectorAll('#item-sheet [data-kit-group]')].find((f) => /Size/i.test(f.querySelector('.c-group__title').textContent));
      size.querySelectorAll('input')[1].click();
    });
    await g.type('#item-sheet input[name=note]', NOTE);
    const picks = await g.evaluate(() => [...document.querySelectorAll('#item-sheet input[data-group]:checked')].map((i) => i.value));
    const q = await serverQuote(g, [{ item: flatWhiteId, qty: 1, options: picks, note: NOTE }]);
    A.expect(q && q.quote, `the server must quote the picks (${JSON.stringify(q).slice(0, 200)})`);
    // the Add button's total follows the picks: the server quote (Juwri.cart.preview), settled after the last change
    await g.waitForFunction((want) => document.querySelector('#item-sheet [data-kit-add-total]').textContent.trim() === want, { timeout: 8000 }, q.quote.total.formatted)
      .catch(async () => { throw new Error(`the Add button shows "${await g.$eval('#item-sheet [data-kit-add-total]', (e) => e.textContent.trim())}", the server quotes "${q.quote.total.formatted}"`); });
    const sheetTotal = q.quote.total.formatted;
    await g.click('#item-sheet [data-kit-add]');
    await g.waitForSelector('.c-cartbar:not([hidden])');
    await g.waitForFunction(() => document.querySelector('.c-cartbar [data-juwri-total]').textContent.trim() !== '', { timeout: 8000 });
    const bar = await g.$eval('.c-cartbar', (e) => ({ text: e.innerText.replace(/\s+/g, ' '), total: e.querySelector('[data-juwri-total]').textContent.trim() }));
    A.expect(/Ready ~8 min/.test(bar.text), `the cart bar shows the ready time (${bar.text})`);
    const cartQuote = await serverQuote(g, [{ item: flatWhiteId, qty: 1, options: picks, note: NOTE }]);
    A.expect(bar.total === cartQuote.quote.total.formatted, `the cart bar total is the server total (${bar.total} vs ${cartQuote.quote.total.formatted})`);
    R.ok(`guest: sheet is segmented + chips with "Name on the cup" (20), Add shows the server price (${sheetTotal}); the cart bar shows "Ready ~8 min" and the server total`);

    // checkout: pay at the counter, take-away
    await g.click('.c-cartbar [data-juwri-open="cart"]');
    await g.waitForSelector('#cart:not([hidden])');
    await g.click('#cart [data-juwri-open="checkout"]');
    await g.waitForSelector('#checkout:not([hidden])');
    await g.type('#checkout #f-name', 'Guest E2E');
    await g.type('#checkout #f-mobile', '+966500009922');
    await g.evaluate(() => { const p = [...document.querySelectorAll('#checkout input[name=pay_via]')]; (p.find((i) => /cash|counter/i.test(i.value)) || p[0]).click(); });
    const before = Number(A.sqlValue('SELECT COUNT(*) FROM orders WHERE store_id = ?', [storeId]));
    await g.click('#checkout [data-juwri-place]');
    await g.waitForFunction(() => /\/order\/track\//.test(location.href) || document.querySelector('[data-kit-place-message]')?.textContent.trim(), { timeout: 20000 });
    const said = await g.evaluate(() => (document.querySelector('[data-kit-place-message]') || {}).textContent || '');
    A.expect(!said.trim(), `placing the order was refused: "${said.trim()}"`);
    await g.waitForFunction(() => document.querySelector('.c-ticket'), { timeout: 20000 });
    A.expect(/\/order\/track\//.test(g.url()), `placing must land on the tracking page (${g.url()})`);
    A.expect(Number(A.sqlValue('SELECT COUNT(*) FROM orders WHERE store_id = ?', [storeId])) === before + 1, 'exactly one order was placed');
    const track = g.url().split('/').pop();
    const row = A.sql('SELECT id, grand_total_minor, type FROM orders WHERE track_token = ?', [track])[0];
    orderIds.push(Number(row[0]));
    A.expect(Number(row[1]) === cartQuote.quote.total.minor, `the order total (${row[1]}) is the quoted total (${cartQuote.quote.total.minor})`);
    const noteRow = A.sqlValue('SELECT order_note FROM order_items WHERE order_id = ? ORDER BY id LIMIT 1', [row[0]]);
    A.expect(String(noteRow).includes(NOTE), `the line note is stored (${noteRow})`);

    // the pickup ticket
    const ticket = await g.evaluate(() => ({
      theme: document.documentElement.dataset.themeSlug,
      big: document.querySelector('.c-ticket__no bdi')?.textContent.trim(),
      steps: document.querySelectorAll('.c-progress__item').length,
      note: [...document.querySelectorAll('.c-line__note')].map((e) => e.textContent.trim()),
      eta: document.querySelector('.c-ticket__eta')?.textContent.replace(/\s+/g, ' ').trim() || null,
    }));
    A.expect(ticket.theme === 'brew' && ticket.big && ticket.steps >= 3, `the tracking page is the pickup ticket (${JSON.stringify(ticket)})`);
    A.expect(ticket.note.length === 1 && ticket.note[0] === NOTE, `the receipt shows the note once (${JSON.stringify(ticket.note)})`);
    R.ok(`guest: order #${row[0]} placed at the quoted total; note stored; tracking is the pickup ticket (${ticket.big}, ${ticket.steps} steps) with the note on the receipt`);
  }, { width: 430, height: 900 });

  // ── customer: Order again with last choices ─────────────────────────────────────────────────────────────────────
  await A.withPage(browser, async (c) => {
    c.on('pageerror', (e) => log(`  p3-brew pageerror: ${e.message}`));
    await consent(c);
    await c.goto(`${app}/${SLUG}`, { waitUntil: 'domcontentloaded' });
    await signIn(c);
    // that guest order was placed by another browser; the customer's own first order, signed in:
    await c.goto(`${app}/${SLUG}`, { waitUntil: 'networkidle2' });
    A.expect(!(await c.$('.c-reorder')), 'no orders yet for this customer: no rail');
    await c.evaluate((id) => window.Juwri.cart.add(id, { qty: 1, name: 'Flat white' }), flatWhiteId); // (required choices missing: refused by the server at quote time)
    await c.evaluate(() => window.Juwri.cart.clear());
    const detail = await c.evaluate(async (slug, id) => (await (await fetch(`/${slug}?fragment=item&item=${id}`, { credentials: 'same-origin' })).text()), SLUG, flatWhiteId);
    const options = [...detail.matchAll(/data-group="[^"]+"[^>]*>/g)].length;
    A.expect(options > 0, 'the sheet fragment lists choices');
    // order the line once as the customer (through the page's own cart + checkout)
    const picks = await c.evaluate(async (slug, id) => {
      const html = await (await fetch(`/${slug}?fragment=item&item=${id}`, { credentials: 'same-origin' })).text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const out = [];
      for (const g of doc.querySelectorAll('[data-kit-group][data-required]')) out.push(g.querySelector('input').value);
      return out;
    }, SLUG, flatWhiteId);
    const placed = await c.evaluate(async (id, picks) => {
      window.Juwri.cart.clear();
      await window.Juwri.cart.add(id, { qty: 1, options: picks, note: 'Noura', name: 'Flat white' });
      await new Promise((r) => setTimeout(r, 700));
      return window.Juwri.cart.place({ name: 'Noura E2E', 'phone-number': '+966500009911', pay_via: 'pay_on_counter' });
    }, flatWhiteId, picks);
    A.expect(placed.status === 'placed', `the signed-in customer must place an order (${JSON.stringify(placed).slice(0, 300)})`);
    const mine = A.sql('SELECT id, customer_id, grand_total_minor FROM orders WHERE store_id = ? ORDER BY id DESC LIMIT 1', [storeId])[0];
    A.expect(mine[1] !== null, 'the order belongs to the signed-in customer');
    orderIds.push(Number(mine[0]));

    await c.goto(`${app}/${SLUG}`, { waitUntil: 'networkidle2' });
    await c.waitForSelector('.c-reorder .c-rpill');
    const rail = await c.$$eval('.c-reorder .c-rpill', (els) => els.map((e) => ({ name: e.querySelector('.c-rpill__name').textContent.trim(), sub: e.querySelector('.c-rpill__sub')?.textContent.trim() || '', add: e.querySelector('button').dataset.juwriAdd || null, label: e.querySelector('button').getAttribute('aria-label') })));
    A.expect(rail.length >= 1 && rail[0].name === 'Flat white', `the rail lists the customer's drink (${JSON.stringify(rail)})`);
    A.expect(rail[0].sub !== '' && /again$/.test(rail[0].label), `the row names its choices and the button says "again" (${JSON.stringify(rail[0])})`);
    // one tap: the line is in the cart with the last choices, at the server total of the earlier order
    await c.click('.c-reorder .c-rpill__btn');
    await c.waitForSelector('.c-cartbar:not([hidden])');
    await c.waitForFunction(() => document.querySelector('.c-cartbar [data-juwri-total]').textContent.trim() !== '', { timeout: 8000 });
    const total = await c.$eval('.c-cartbar [data-juwri-total]', (e) => e.textContent.trim());
    const lines = await c.evaluate(() => window.Juwri.cart.snapshot().lines.map((l) => ({ item: l.item, options: l.options.slice().sort() })));
    A.expect(lines.length === 1 && lines[0].item === flatWhiteId && JSON.stringify(lines[0].options) === JSON.stringify(picks.slice().sort()), `one tap adds the line with its last choices (${JSON.stringify(lines)})`);
    const earlier = await serverQuote(c, [{ item: flatWhiteId, qty: 1, options: picks }]);
    A.expect(total === earlier.quote.total.formatted, `the re-added line is quoted by the server (${total} vs ${earlier.quote.total.formatted})`);
    R.ok(`customer: signed in, ordered once; "Order again" lists Flat white with its choices ("${rail[0].sub}"); one tap re-adds it with those choices at the server total ${total}`);

    // the owner marks it sold out: the row goes
    tinker(`\\Juwri\\Modules\\Menu\\Models\\Menu::where('public_id', '${flatWhiteId}')->update(['is_sold_out' => 1]); \\Illuminate\\Support\\Facades\\Cache::flush();`);
    await c.evaluate(() => window.Juwri.cart.clear());
    await c.goto(`${app}/${SLUG}`, { waitUntil: 'networkidle2' });
    const names = await c.$$eval('.c-reorder .c-rpill__name', (els) => els.map((e) => e.textContent.trim()));
    A.expect(!names.includes('Flat white'), `a sold-out drink must leave the rail (${JSON.stringify(names)})`);
    tinker(`\\Juwri\\Modules\\Menu\\Models\\Menu::where('public_id', '${flatWhiteId}')->update(['is_sold_out' => 0]); \\Illuminate\\Support\\Facades\\Cache::flush();`);
    R.ok('server filter: a drink the owner marks sold out leaves the "Order again" rail');
  }, { width: 430, height: 900 });

  // ── owner: the KDS ticket and the order page carry the note ─────────────────────────────────────────────────────
  await A.withPage(browser, async (o) => {
    await A.login(o, app, OWNER);
    // the owner accepts the first order (a pay-at-the-counter order waits as "pending"): it goes to the kitchen
    const acc = await A.req(o, `${app}/order/${orderIds[0]}/status`, { method: 'POST', form: { status: 'preparing' } });
    A.expect(acc.status < 400 || acc.type === 'opaqueredirect', `the owner could not accept the order (${acc.status})`);
    A.expect(A.sqlValue('SELECT status FROM orders WHERE id = ?', [orderIds[0]]) === 'preparing', 'the order must be in preparation');
    const poll = await A.req(o, `${app}/kds/poll?store_id=${storeId}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    A.expect(poll.status === 200, `the KDS poll must answer (${poll.status})`);
    const tickets = Array.isArray(poll.json) ? poll.json : [];
    const mineTicket = tickets.find((t) => t.id === orderIds[0]);
    A.expect(mineTicket, `the KDS must list order #${orderIds[0]} (${poll.status}; ${poll.json ? 'ids ' + JSON.stringify(tickets.map((t) => t.id)) : poll.text.slice(0, 200)})`);
    A.expect(mineTicket.items.some((i) => String(i.note || '').includes(NOTE)), `the KDS ticket carries the line note (${JSON.stringify(mineTicket.items.map((i) => i.note))})`);
    await o.goto(`${app}/order/${orderIds[0]}`, { waitUntil: 'domcontentloaded' });
    A.expect((await A.pageText(o)).includes(NOTE), "the owner's order page shows the note");
    R.ok(`owner: the KDS ticket and the order page of #${orderIds[0]} carry the note "${NOTE}"`);
  });

  // ── IDOR: another store's customer sees none of this store's lines ──────────────────────────────────────────────
  const other = A.sql('SELECT slug, id FROM stores WHERE id <> ? AND user_id <> ? AND slug LIKE ? LIMIT 1', [storeId, ownerId, '%-demo'])[0];
  if (other) {
    await A.withPage(browser, async (x) => {
      await x.goto(`${app}/${other[0]}`, { waitUntil: 'domcontentloaded' });
      const res = await x.evaluate(async (slug, email, pass, id) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const r = await fetch(`/${slug}/customer/login`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ email, password: pass, store_id: id }) });
        return r.status;
      }, other[0], CUSTOMER.email, CUSTOMER.pass, Number(other[1]));
      A.expect(res === 401 || res === 422, `this store's customer cannot sign in to another store (${res})`);
      R.ok(`IDOR: the cafe customer cannot sign in to "${other[0]}" (${res}), so no cafe line can reach its page`);
    }, { width: 430, height: 900 });
  } else {
    R.skip('cross-store leg', 'no other demo store in the database');
  }

  R.summary();
});
