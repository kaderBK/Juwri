// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * a-retail-family — P1-12 item 9 (a): the retail-family store types — clothing, retail, cosmetics, car parts —
 * owner → customer → owner, plus cross-store IDOR.
 *
 *  clothing   size × colour variants with their own stock and price; the shopper picks size + colour; the variant's
 *             stock drops when the order is placed; a variant with no stock / switched off cannot be bought
 *  retail     stock-tracked product ("3 left"): the stock limit is enforced, the last unit sells it out, and the
 *             owner's restock puts it back on sale
 *  cosmetics  brands, shade variants and an expiry date (expired products are hidden when the owner turns that on)
 *  car parts  vehicle fitment (make / model / year range) + OEM numbers: the shopper's vehicle decides Fits / Does not
 *             fit, and the OEM number finds the part
 *  IDOR       another owner cannot read or change this store's catalog or variants
 *
 * Sold-by-weight is NOT part of the retail family: the per-kilogram price (`pricing_unit`) and the actual-weight
 * repricing exist only for laundry services (a-laundry); the retail item form's weight is a shipping weight. Barcodes
 * exist on the item and per variant (saved here, shown on the owner list) but nothing resolves a scanned code yet —
 * BarcodeResolver has no caller. Both are reported, not invented.
 *
 * Owner and customer steps run on E2E_ARMED_URL when set (same code and database as juw.test), else E2E_BASE_URL.
 *
 * Run: npm run test:e2e -- a-retail-family
 */

const A = require('../../lib/lane-a');

const app0 = () => A.cfg.armed || A.cfg.baseUrl;

module.exports = A.journey(async function aRetailFamily({ browser, log }) {
  const R = A.recorder('a-retail-family', log);
  const app = app0();
  const stamp = Date.now();
  const owner = await A.ensureLaneOwner(browser);

  const click = (c, sel) => c.evaluate((s) => { const e = document.querySelector(s); if (e) e.click(); return !!e; }, sel);
  const text = (c) => c.evaluate(() => document.body.innerText.replace(/\s+/g, ' '));
  const trackOrder = async (c, slug, fields) => { // the theme's own checkout call (same endpoint + session + CSRF)
    const csrf = await A.storefrontCsrf(c);
    return c.evaluate(async (u, f, t) => {
      const fd = new FormData(); Object.entries(f).forEach(([k, v]) => fd.append(k, v));
      const r = await fetch(u, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': t } });
      return { status: r.status, json: await r.json().catch(() => null) };
    }, `${app}/${slug}/checkout`, { type: 'in_store', customer_name: `${A.PREFIX}Shopper ${stamp}`, phone_number: '+966500000771', ...fields }, csrf);
  };
  const addToCart = async (c, slug, menuId, fields) => {
    const csrf = await A.storefrontCsrf(c);
    return c.evaluate(async (u, f, t) => {
      const fd = new FormData(); Object.entries(f).forEach(([k, v]) => fd.append(k, v));
      const r = await fetch(u, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': t } });
      return { status: r.status, json: await r.json().catch(() => null) };
    }, `${app}/${slug}/goods/${menuId}/cart`, fields, csrf);
  };

  // ── stores through the owner dashboard ──────────────────────────────────────────────────────────────────────────
  const S = {};
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    for (const [key, type, title, slug, template] of [
      ['clothing', 'clothing', 'e2eA Clothing', 'e2ea-clothing', 'maison'],
      ['retail', 'retail', 'e2eA Retail', 'e2ea-retail', 'zevoa'],
      ['cosmetic', 'cosmetic', 'e2eA Cosmetics', 'e2ea-cosmetic', 'lumiere'],
      ['carparts', 'carparts', 'e2eA Car Parts', 'e2ea-carparts', 'torque'],
    ]) {
      const s = await A.ensureStore(page, app, { type, title, slug, template });
      A.expect(A.sqlValue('SELECT store_type FROM stores WHERE id = ?', [s.id]) === type, `${slug} was not created as ${type}`);
      const have = A.sql("SELECT JSON_UNQUOTE(JSON_EXTRACT(settings, '$.template')) FROM stores WHERE id = ?", [s.id])[0][0];
      if (have !== template) await A.setStoreOptions(page, app, s.id, { store_template: template });
      S[key] = { ...s, type, template };
    }
  });
  R.ok(`4 retail-family stores created through the dashboard wizard: ${Object.values(S).map((s) => `${s.type} #${s.id}`).join(', ')}`);

  // ═════════════════════════════ CLOTHING: size × colour variants ═════════════════════════════════════════════════════
  const cl = S.clothing;
  const tee = await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    const cat = await A.ensureProductCategory(page, app, cl.id, `${A.PREFIX}Tees`);
    const p = await A.ensureProduct(page, app, cl.id, { name: `${A.PREFIX}Basic tee`, price: '19.90', categoryId: cat, barcode: 'E2EA-TEE-001' });
    await page.goto(`${app}/stores/${cl.id}/products/${p.id}/edit`, { waitUntil: 'domcontentloaded' });
    const wrongAxes = A.sql('SELECT id FROM menu_variants WHERE menu_id = ? AND options NOT LIKE ?', [p.id, '%"Colour"%']);
    for (const [vid] of wrongAxes) { // variants left by an earlier, differently named matrix: remove them through the dashboard
      await A.req(page, `${app}/stores/${cl.id}/products/${p.id}/variants/${vid}`, { method: 'POST', form: { _method: 'DELETE' } });
    }
    if (!A.sql('SELECT 1 FROM menu_variants WHERE menu_id = ? LIMIT 1', [p.id]).length) {
      await page.goto(`${app}/stores/${cl.id}/products/${p.id}/edit`, { waitUntil: 'domcontentloaded' });
      const gen = `form[action$="/products/${p.id}/variants/generate"]`;
      await page.evaluate((sel) => {
        const f = document.querySelector(sel); const names = f.querySelectorAll('[name="axis_name[]"]'); const vals = f.querySelectorAll('[name="axis_values[]"]');
        names[0].value = 'Size'; vals[0].value = 'S, M, L'; names[1].value = 'Colour'; vals[1].value = 'Black, White';
      }, gen);
      await A.submitForm(page, gen);
    }
    const variants = A.sql('SELECT id, options FROM menu_variants WHERE menu_id = ?', [p.id]).map(([id, o]) => ({ id: Number(id), ...JSON.parse(o) }));
    A.expect(variants.length === 6, `Size × Colour must give 6 variants (got ${variants.length})`);
    const find = (size, colour) => variants.find((v) => v.Size === size && v.Colour === colour);
    // per-variant stock / price / switch through the variant grid
    const plan = { 'S|Black': { stock: 1 }, 'M|Black': { stock: 5 }, 'L|Black': { stock: 5, price: '21.90' }, 'S|White': { stock: 5 }, 'M|White': { stock: 5 }, 'L|White': { stock: 5, off: true } };
    await page.goto(`${app}/stores/${cl.id}/products/${p.id}/edit`, { waitUntil: 'domcontentloaded' });
    const bulk = `form[action$="/products/${p.id}/variants/bulk-update"]`;
    await page.evaluate((sel, rows) => {
      const f = document.querySelector(sel);
      for (const r of rows) {
        const set = (n, v) => { const e = f.querySelector(`[name="variants[${r.id}][${n}]"]`); e.value = v; };
        set('stock_qty', r.stock); if (r.price) { const e = f.querySelector(`[name="variants[${r.id}][price]"]`); e.setAttribute('step', 'any'); e.value = r.price; }
        const a = f.querySelector(`[name="variants[${r.id}][active]"]`); if (a && a.checked === !!r.off) a.click();
        if (r.barcode) set('barcode', r.barcode);
      }
    }, bulk, Object.entries(plan).map(([k, v]) => { const [s, c] = k.split('|'); return { id: find(s, c).id, stock: v.stock, price: v.price, off: v.off, barcode: `E2EA-TEE-${s}${c[0]}` }; }));
    await A.submitForm(page, bulk);
    const row = (v) => A.sql('SELECT stock_qty, active, price_minor, barcode FROM menu_variants WHERE id = ?', [v.id])[0].map(String);
    A.expect(row(find('S', 'Black')).join() === '1,1,1990,E2EA-TEE-SB', `S/Black variant saved wrong: ${row(find('S', 'Black'))}`);
    A.expect(row(find('L', 'Black'))[2] === '2190', `L/Black price must be 21.90: ${row(find('L', 'Black'))}`);
    A.expect(row(find('L', 'White'))[1] === '0', `L/White must be switched off: ${row(find('L', 'White'))}`);
    const list = await (async () => { await page.goto(`${app}/stores/${cl.id}/products`, { waitUntil: 'domcontentloaded' }); return A.pageText(page); })();
    A.expect(list.includes(`${A.PREFIX}Basic tee`) && /6/.test(list), 'the owner product list must show the tee with its 6 variants');
    return { ...p, find, variants };
  });
  R.ok('clothing: size × colour matrix generated (6 variants); per-variant stock, price (L/Black 21.90), switch-off (L/White) and barcodes saved from the dashboard');

  await A.withPage(browser, async (c) => {
    // the shopper picks size + colour on the storefront (maison theme)
    const posts = [];
    c.on('request', (r) => { if (r.method() === 'POST' && /\/goods\/\d+\/cart/.test(r.url())) posts.push(r.postData() || ''); });
    await c.goto(`${app}/${cl.slug}`, { waitUntil: 'networkidle2' });
    A.expect(await click(c, `[data-open-item-link="${tee.id}"]`), 'no product card for the tee');
    await A.sleep(900);
    const swatches = await c.evaluate(() => [...document.querySelectorAll('.ms-sw')].map((b) => (b.title || b.getAttribute('aria-label') || b.dataset.color || b.dataset.value || b.style.background || b.innerText).toString().trim()));
    const sizes = await c.evaluate(() => [...document.querySelectorAll('.ms-size')].map((b) => b.innerText.trim()));
    A.expect(sizes.join() === 'S,M,L', `the sheet must offer the sizes S, M, L (got ${sizes})`);
    A.expect(swatches.length === 2, `the sheet must offer 2 colours (got ${JSON.stringify(swatches)})`);
    const blackIdx = swatches.findIndex((s) => /black/i.test(s));
    await c.evaluate((i) => document.querySelectorAll('.ms-sw')[i].click(), blackIdx >= 0 ? blackIdx : 0);
    await c.evaluate(() => [...document.querySelectorAll('.ms-size')].find((b) => b.innerText.trim() === 'S').click());
    await A.sleep(300);
    A.expect(await c.evaluate(() => { const b = [...document.querySelectorAll('button')].find((x) => /^Add to cart/i.test(x.innerText.trim())); if (b) b.click(); return !!b; }), 'no Add to cart in the sheet');
    await A.sleep(1000);
    const sb = tee.find('S', 'Black');
    A.expect(posts.length === 1 && posts[0].includes(`name="variation"`) && new RegExp(`name="variation"\\s*${sb.id}`).test(posts[0].replace(/\r?\n/g, ' ')), `the shopper's choice must reach the cart as the S/Black variant #${sb.id} (${posts.join('|').replace(/\s+/g, ' ').slice(0, 300)})`);
    R.ok(`clothing storefront: the shopper picked size S + colour Black → the cart holds variant #${sb.id}`);

    // order it: the last S/Black unit
    const placed = await trackOrder(c, cl.slug, {});
    A.expect(placed.json && placed.json.success === true, `the order must be placed (${JSON.stringify(placed)})`);
    const o = A.sql('SELECT id, grand_total_minor FROM orders WHERE track_token = ?', [placed.json.track_token])[0];
    const line = A.sql('SELECT variation, quantity FROM order_items WHERE order_id = ?', [o[0]])[0];
    A.expect(Number(line[0]) === sb.id && Number(line[1]) === 1 && Number(o[1]) === 1990, `the order line must be S/Black x1 at 19.90 (${line}, ${o[1]})`);
    A.expect(Number(A.sqlValue('SELECT stock_qty FROM menu_variants WHERE id = ?', [sb.id])) === 0, 'the S/Black stock must drop 1 → 0');
    A.expect(Number(A.sqlValue('SELECT stock_qty FROM menu_variants WHERE id = ?', [tee.find('M', 'Black').id])) === 5, 'another variant must be untouched');
    R.ok(`order #${o[0]}: S/Black x1 = 19.90; that variant's stock 1 → 0, the others untouched`);

    // sold-out and switched-off variants cannot be bought, whatever the page says
    const soldOut = await addToCart(c, cl.slug, tee.id, { variation: sb.id, quantity: 1 });
    A.expect(soldOut.status === 422 && /sold out/i.test(soldOut.json.message), `a variant with no stock must be refused (${JSON.stringify(soldOut)})`);
    const off = await addToCart(c, cl.slug, tee.id, { variation: tee.find('L', 'White').id, quantity: 1 });
    A.expect(off.status === 422 && /no longer available/i.test(off.json.message), `a switched-off variant must be refused (${JSON.stringify(off)})`);
    const over = await addToCart(c, cl.slug, tee.id, { variation: tee.find('M', 'White').id, quantity: 6 });
    A.expect(over.status === 422 && /Only 5/.test(over.json.message), `more than the variant's stock must be refused (${JSON.stringify(over)})`);
    R.ok('sold-out variant, switched-off variant and above-stock quantity are all refused by the server (422)');

    // the variant's own price is honoured
    const lb = await addToCart(c, cl.slug, tee.id, { variation: tee.find('L', 'Black').id, quantity: 1 });
    A.expect(lb.status === 200, `L/Black must be orderable (${JSON.stringify(lb)})`);
    const placed2 = await trackOrder(c, cl.slug, {});
    const o2 = A.sql('SELECT id, grand_total_minor FROM orders WHERE track_token = ?', [placed2.json.track_token])[0];
    A.expect(Number(o2[1]) === 2190, `L/Black must be charged 21.90 (${o2[1]})`);
    R.ok(`order #${o2[0]}: L/Black charged its own price 21.90`);
    tee.orderIds = [Number(o[0]), Number(o2[0])];
  }, { width: 430, height: 1400 });

  await A.withPage(browser, async (ob) => { // owner sees the variant on the order
    await A.login(ob, app, { email: owner.email, pass: owner.pass });
    await ob.goto(`${app}/orders/${cl.id}`, { waitUntil: 'domcontentloaded' });
    await A.sleep(700);
    const board = await ob.evaluate(() => [...document.querySelectorAll('article.ui-order')].map((a) => a.innerText.replace(/\s+/g, ' ')));
    const mine = board.find((t) => t.includes(`#${tee.orderIds[0]}`));
    A.expect(mine && mine.includes(`${A.PREFIX}Basic tee`) && /S/.test(mine), `the owner board must show order #${tee.orderIds[0]} (${board.slice(0, 2).join(' || ')})`);
    R.ok('owner sees the clothing order on the orders board');
  });

  // ═════════════════════════════ RETAIL: tracked stock, sell-out, restock ═════════════════════════════════════════════
  const rt = S.retail;
  const lamp = await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    const cat = await A.ensureProductCategory(page, app, rt.id, `${A.PREFIX}Home`);
    const p = await A.ensureProduct(page, app, rt.id, { name: `${A.PREFIX}Desk lamp`, price: '24.50', categoryId: cat, barcode: 'E2EA-LAMP-01', stock: 3 });
    // known start: tracking on, 3 in stock, on sale (a previous run sold it out)
    await page.goto(`${app}/stores/${rt.id}/products/${p.id}/edit`, { waitUntil: 'domcontentloaded' });
    const f = `form[action$="/stores/${rt.id}/products/${p.id}"]`;
    await page.evaluate((sel) => {
      const ff = document.querySelector(sel);
      const t = ff.querySelector('[name=track_inventory]'); if (!t.checked) t.click();
      ff.querySelector('[name=stock_qty]').value = '3';
      const pr = ff.querySelector('[name=price]'); pr.setAttribute('step', 'any');
    }, f);
    await A.submitForm(page, f);
    return p;
  });
  let reset = A.sql('SELECT stock_qty, is_sold_out FROM items WHERE id = ?', [lamp.id])[0].map(Number);
  if (reset[1] === 1) {
    // left sold out by an earlier run: the dashboard's own "back in stock" action (the sold-out banner's Un-86 link)
    await A.withPage(browser, async (page) => {
      await A.login(page, app, { email: owner.email, pass: owner.pass });
      await A.req(page, `${app}/stores/${rt.id}/menu/un86`, { method: 'POST', form: { 'ids[]': lamp.id }, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    });
    reset = A.sql('SELECT stock_qty, is_sold_out FROM items WHERE id = ?', [lamp.id])[0].map(Number);
  }
  A.expect(reset[0] === 3 && reset[1] === 0, `the lamp must start with stock 3, on sale (${reset})`);
  await A.withPage(browser, async (c) => {
    await c.goto(`${app}/${rt.slug}`, { waitUntil: 'networkidle2' });
    const card = await c.evaluate((id) => { const a = document.querySelector(`article[data-id="${id}"]`); return a ? a.innerText.replace(/\s+/g, ' ') : null; }, lamp.id);
    A.expect(card && /3 left/.test(card), `the shelf must show "3 left" (${card})`);
    // 3 units go in, the 4th is refused
    for (let i = 0; i < 3; i += 1) {
      const r = await addToCart(c, rt.slug, lamp.id, { quantity: 1 });
      A.expect(r.status === 200, `unit ${i + 1} must be accepted (${JSON.stringify(r)})`);
    }
    const fourth = await addToCart(c, rt.slug, lamp.id, { quantity: 1 });
    A.expect(fourth.status === 422 && /Only 3/.test(fourth.json.message), `the 4th unit must be refused (${JSON.stringify(fourth)})`);
    const placed = await trackOrder(c, rt.slug, {});
    A.expect(placed.json && placed.json.success === true, `3 lamps must sell (${JSON.stringify(placed)})`);
    const o = A.sql('SELECT id, grand_total_minor FROM orders WHERE track_token = ?', [placed.json.track_token])[0];
    A.expect(Number(o[1]) === 7350, `3 x 24.50 = 73.50 (${o[1]})`);
    const after = A.sql('SELECT stock_qty, is_sold_out FROM items WHERE id = ?', [lamp.id])[0].map(Number);
    A.expect(after[0] === 0 && after[1] === 1, `the last unit must sell the product out (stock/sold-out ${after})`);
    await c.goto(`${app}/${rt.slug}`, { waitUntil: 'networkidle2' });
    const soldCard = await c.evaluate((id) => { const a = document.querySelector(`article[data-id="${id}"]`); return a ? a.innerText.replace(/\s+/g, ' ') : null; }, lamp.id);
    A.expect(soldCard && !/3 left/.test(soldCard) && /sold out/i.test(soldCard), `the shelf must show Sold out (${soldCard})`);
    const blocked = await addToCart(c, rt.slug, lamp.id, { quantity: 1 });
    A.expect(blocked.status === 422 && /sold out/i.test(blocked.json.message), `a sold-out product must be refused (${JSON.stringify(blocked)})`);
    R.ok(`retail: shelf says "3 left"; 3 accepted, the 4th refused; order #${o[0]} = 73.50; the last unit sells it out (shelf "Sold out", server refuses)`);

    // the owner restocks through the product form; the product must go back on sale
    await A.withPage(browser, async (page) => {
      await A.login(page, app, { email: owner.email, pass: owner.pass });
      await page.goto(`${app}/stores/${rt.id}/products/${lamp.id}/edit`, { waitUntil: 'domcontentloaded' });
      const f = `form[action$="/stores/${rt.id}/products/${lamp.id}"]`;
      await page.evaluate((sel) => { const ff = document.querySelector(sel); ff.querySelector('[name=stock_qty]').value = '3'; ff.querySelector('[name=price]').setAttribute('step', 'any'); }, f);
      await A.submitForm(page, f);
    });
    const restocked = A.sql('SELECT stock_qty, is_sold_out FROM items WHERE id = ?', [lamp.id])[0].map(Number);
    A.expect(restocked[0] === 3 && restocked[1] === 0, `restocking to 3 must put the product back on sale (stock ${restocked[0]}, sold-out flag ${restocked[1]})`);
    const again = await addToCart(c, rt.slug, lamp.id, { quantity: 1 });
    A.expect(again.status === 200, `the restocked product must be orderable (${JSON.stringify(again)})`);
    R.ok('owner restock (3) puts the product back on sale: the sold-out flag clears and the shopper can buy it again');
  }, { width: 430, height: 1400 });

  // ═════════════════════════════ COSMETICS: brand, shades, expiry ═════════════════════════════════════════════════════
  const cs = S.cosmetic;
  await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    await page.goto(`${app}/stores/${cs.id}/brands`, { waitUntil: 'domcontentloaded' });
    if (!A.sql('SELECT 1 FROM brands WHERE store_id = ? AND name = ? LIMIT 1', [cs.id, `${A.PREFIX}Lumo`]).length) {
      await page.type(`form[action$="/stores/${cs.id}/brands"] [name=name]`, `${A.PREFIX}Lumo`);
      await A.submitForm(page, `form[action$="/stores/${cs.id}/brands"]`);
    }
    const brandId = Number(A.sqlValue('SELECT id FROM brands WHERE store_id = ? AND name = ?', [cs.id, `${A.PREFIX}Lumo`]));
    const cat = await A.ensureProductCategory(page, app, cs.id, `${A.PREFIX}Lips`);
    const lip = await A.ensureProduct(page, app, cs.id, { name: `${A.PREFIX}Lip tint`, price: '12.00', categoryId: cat, stock: 20 });
    const old = await A.ensureProduct(page, app, cs.id, { name: `${A.PREFIX}Old serum`, price: '30.00', categoryId: cat, stock: 4 });
    // brand on the product through the edit form
    await page.goto(`${app}/stores/${cs.id}/products/${lip.id}/edit`, { waitUntil: 'domcontentloaded' });
    const f = `form[action$="/stores/${cs.id}/products/${lip.id}"]`;
    await page.select(`${f} select[name=brand_id]`, String(brandId));
    await page.evaluate((sel) => document.querySelector(`${sel} [name=price]`).setAttribute('step', 'any'), f);
    await A.submitForm(page, f);
    A.expect(Number(A.sqlValue('SELECT brand_id FROM items WHERE id = ?', [lip.id])) === brandId, 'the brand must be saved on the product');
    // shades = a one-axis variant matrix
    await page.goto(`${app}/stores/${cs.id}/products/${lip.id}/edit`, { waitUntil: 'domcontentloaded' });
    if (!A.sql('SELECT 1 FROM menu_variants WHERE menu_id = ? LIMIT 1', [lip.id]).length) {
      const gen = `form[action$="/products/${lip.id}/variants/generate"]`;
      await page.evaluate((sel) => {
        const ff = document.querySelector(sel); const n = ff.querySelectorAll('[name="axis_name[]"]'); const v = ff.querySelectorAll('[name="axis_values[]"]');
        n[0].value = 'Shade'; v[0].value = 'Rose, Nude, Berry'; n[1].value = ''; v[1].value = '';
      }, gen);
      await A.submitForm(page, gen);
    }
    A.expect(Number(A.sql('SELECT COUNT(*) FROM menu_variants WHERE menu_id = ?', [lip.id])[0][0]) === 3, 'three shades must exist (the matrix needs a second axis name left empty to give one axis)');
    // expiry: with "hide expired" on, saving an expired date takes the product off the shelf
    await page.goto(`${app}/stores/${cs.id}/pharmacy`, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { const c = document.querySelector('[name=hide_expired_items]'); if (c && !c.checked) c.click(); });
    await A.submitForm(page, `form[action$="/stores/${cs.id}/pharmacy/settings"]`);
    await page.goto(`${app}/stores/${cs.id}/pharmacy/${old.id}/edit`, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => { document.querySelector('[name=batch_no]').value = 'E2EA-B1'; document.querySelector('[name=expiry_date]').value = '2020-01-31'; });
    await A.submitForm(page, `form[action$="/pharmacy/${old.id}"]`);
    A.expect(A.sqlValue('SELECT active FROM items WHERE id = ?', [old.id]) === '0' || Number(A.sqlValue('SELECT active FROM items WHERE id = ?', [old.id])) === 0, 'saving an expired date with "hide expired" on must switch the product off');
    S.cosmeticItems = { brandId, lip, old };
  });
  await A.withPage(browser, async (c) => {
    await c.goto(`${app}/${cs.slug}`, { waitUntil: 'networkidle2' });
    const html = await c.content();
    A.expect(html.includes(`${A.PREFIX}Lip tint`) && html.includes(`${A.PREFIX}Lumo`), 'the storefront must list the lip tint with its brand');
    A.expect(['Rose', 'Nude', 'Berry'].every((s) => html.includes(s)), 'the storefront must offer the three shades');
    A.expect(!html.includes(`${A.PREFIX}Old serum`), 'an expired product must be off the storefront when "hide expired" is on');
    R.ok('cosmetics: brand + 3 shades shown on the storefront; the serum saved with an expired date is off the shelf ("hide expired" on)');
    const shade = A.sql('SELECT id FROM menu_variants WHERE menu_id = ? ORDER BY id LIMIT 1', [S.cosmeticItems.lip.id])[0][0];
    const r = await addToCart(c, cs.slug, S.cosmeticItems.lip.id, { variation: shade, quantity: 2 });
    A.expect(r.status === 200, `a shade must be orderable (${JSON.stringify(r)})`);
    const placed = await trackOrder(c, cs.slug, {});
    const o = A.sql('SELECT id, grand_total_minor FROM orders WHERE track_token = ?', [placed.json.track_token])[0];
    A.expect(Number(o[1]) === 2400, `2 x 12.00 = 24.00 (${o[1]})`);
    R.ok(`cosmetics: a shade ordered (order #${o[0]} = 24.00)`);
  }, { width: 430, height: 1400 });

  // ═════════════════════════════ CAR PARTS: fitment + OEM ═════════════════════════════════════════════════════════════
  const cp = S.carparts;
  const parts = await A.withPage(browser, async (page) => {
    await A.login(page, app, { email: owner.email, pass: owner.pass });
    const cat = await A.ensureProductCategory(page, app, cp.id, `${A.PREFIX}Brakes`);
    const pads = await A.ensureProduct(page, app, cp.id, { name: `${A.PREFIX}Brake pad set`, price: '45.00', categoryId: cat, barcode: 'E2EA-BRK-001', stock: 6 });
    const filter = await A.ensureProduct(page, app, cp.id, { name: `${A.PREFIX}Oil filter`, price: '9.00', categoryId: cat, barcode: 'E2EA-OIL-001', stock: 6 });
    const fit = async (p, { from, to, make, model }) => {
      if (A.sql('SELECT 1 FROM vehicle_fitments WHERE menu_id = ? LIMIT 1', [p.id]).length) return;
      await page.goto(`${app}/stores/${cp.id}/fitments/${p.id}`, { waitUntil: 'domcontentloaded' });
      const f = `form[action$="/fitments/${p.id}/bulk"]`;
      await page.type(`${f} [name=year_from]`, String(from)); await page.type(`${f} [name=year_to]`, String(to));
      await page.type(`${f} [name=make]`, make); await page.type(`${f} [name=model]`, model);
      await A.submitForm(page, f);
    };
    await fit(pads, { from: 2018, to: 2020, make: 'Toyota', model: 'Corolla' });
    await fit(filter, { from: 2021, to: 2022, make: 'Honda', model: 'Civic' });
    await page.goto(`${app}/stores/${cp.id}/fitments/${pads.id}`, { waitUntil: 'domcontentloaded' });
    if (!A.sql("SELECT 1 FROM items WHERE id = ? AND attributes LIKE '%E2EA-OEM-123%'", [pads.id]).length) {
      await page.evaluate(() => { document.querySelector('[name=oem_numbers]').value = 'E2EA-OEM-123'; });
      await A.submitForm(page, `form[action$="/fitments/${pads.id}/oem"]`);
    }
    A.expect(Number(A.sql('SELECT COUNT(*) FROM vehicle_fitments WHERE menu_id = ?', [pads.id])[0][0]) === 3, 'the 2018-2020 range must give 3 fitment rows');
    return { pads, filter };
  });
  R.ok('car parts: fitments (Toyota Corolla 2018-2020 on the pads, Honda Civic 2021-2022 on the filter) + an OEM number saved from the dashboard');
  await A.withPage(browser, async (c) => {
    await c.goto(`${app}/${cp.slug}`, { waitUntil: 'networkidle2' });
    const opts = await c.evaluate(async () => (await (await fetch(location.pathname + '/parts/fitment-options')).json()));
    A.expect(opts.enabled === true && opts.fitments.some((f) => f.make === 'Toyota' && f.model === 'Corolla' && f.year === 2019), 'the fitment options endpoint must serve the saved fitments');
    // the shopper adds a vehicle: year → make → model, through the garage wizard
    const chips = async () => c.evaluate(() => Object.fromEntries([...document.querySelectorAll('article[data-id]')].map((a) => [a.dataset.id, (a.querySelector('[data-fitchip]') && !a.querySelector('[data-fitchip]').hidden ? a.querySelector('[data-fitchip]').innerText.trim() : '')])));
    const addVehicle = async (year, make, model) => {
      await c.evaluate(() => document.getElementById('tqHudChange').click()); await A.sleep(700);
      await c.evaluate(() => document.getElementById('tqGarageAdd').click()); await A.sleep(700);
      await c.evaluate(() => document.getElementById('tqVinManual').click()); await A.sleep(700);
      for (const v of [String(year), make, model]) {
        const ok = await c.evaluate((val) => { const b = [...document.querySelectorAll('#tqWzOptions [data-pick]')].find((x) => x.getAttribute('data-pick') === val); if (b) b.click(); return !!b; }, v);
        A.expect(ok, `the vehicle wizard must offer "${v}"`);
        await A.sleep(500);
      }
      await A.sleep(700);
    };
    await addVehicle(2019, 'Toyota', 'Corolla');
    let ch = await chips();
    A.expect(/fit/i.test(ch[parts.pads.id]) && !/not|no|won/i.test(ch[parts.pads.id]), `the pads must say Fits for a 2019 Corolla (${JSON.stringify(ch)})`);
    A.expect(/not|no|won/i.test(ch[parts.filter.id]), `the Honda filter must say it does not fit a Corolla (${JSON.stringify(ch)})`);
    R.ok(`car parts storefront: for a 2019 Toyota Corolla the pads show "${ch[parts.pads.id]}" and the Honda filter "${ch[parts.filter.id]}"`);
    // the OEM number finds the part
    await c.evaluate(() => { const i = document.getElementById('tqOemSearch'); i.value = 'E2EA-OEM-123'; i.dispatchEvent(new Event('input', { bubbles: true })); }); await A.sleep(700);
    const oemApi = await c.evaluate(async (slug) => (await (await fetch(`/${slug}/parts/oem-search?q=E2EA-OEM-123`)).json()), cp.slug);
    A.expect(Array.isArray(oemApi.menu_ids) && oemApi.menu_ids.length === 1 && oemApi.menu_ids[0] === parts.pads.id, `the OEM search endpoint must return only the pads (${JSON.stringify(oemApi)})`);
    await A.sleep(600);
    const shown = await c.evaluate(() => [...document.querySelectorAll('article[data-id]')].filter((a) => a.offsetParent !== null && !a.hidden && getComputedStyle(a).display !== 'none').map((a) => a.dataset.id));
    A.expect(shown.length === 1 && shown[0] === String(parts.pads.id), `the OEM number must find only the pads (${shown})`);
    R.ok('car parts storefront: the OEM number E2EA-OEM-123 finds only the brake pads');
    const r = await addToCart(c, cp.slug, parts.pads.id, { quantity: 1 });
    A.expect(r.status === 200, `the part must be orderable (${JSON.stringify(r)})`);
    const placed = await trackOrder(c, cp.slug, {});
    const o = A.sql('SELECT id, grand_total_minor FROM orders WHERE track_token = ?', [placed.json.track_token])[0];
    A.expect(Number(o[1]) === 4500, `45.00 (${o[1]})`);
    R.ok(`car parts: order #${o[0]} = 45.00`);
  }, { width: 430, height: 1400 });

  // ═════════════════════════════ IDOR ══════════════════════════════════════════════════════════════════════════════════
  await A.withPage(browser, async (other) => {
    await A.login(other, app, { email: A.cfg.otherOwnerEmail, pass: A.cfg.otherOwnerPass });
    const before = A.sql('SELECT stock_qty, price_minor FROM menu_variants WHERE menu_id = ? ORDER BY id', [tee.id]).map((r) => r.join('/')).join(',');
    for (const path of [`/stores/${cl.id}/products`, `/stores/${cl.id}/products/${tee.id}/edit`, `/stores/${cl.id}/brands`, `/stores/${cp.id}/fitments`, `/stores/${cp.id}/fitments/${parts.pads.id}`, `/stores/${cs.id}/pharmacy`]) {
      A.expect(await A.status(other, app + path) === 404, `another owner must get a 404 on ${path}`);
    }
    const v = tee.variants[0];
    const writes = [
      [`/stores/${cl.id}/products/${tee.id}/variants/bulk-update`, { [`variants[${v.id}][stock_qty]`]: '999', [`variants[${v.id}][price]`]: '1' }],
      [`/stores/${cl.id}/products/${tee.id}/variants/generate`, { 'axis_name[]': 'X', 'axis_values[]': 'a,b' }],
      [`/stores/${cl.id}/products`, { name: 'outsider', price: '1' }],
      [`/stores/${cp.id}/fitments/${parts.pads.id}`, { year: 2000, make: 'X', model: 'Y' }],
    ];
    for (const [path, form] of writes) {
      const r = await A.req(other, app + path, { method: 'POST', form });
      A.expect([403, 404].includes(r.status), `another owner must not write to ${path} (${r.status})`);
    }
    const del = await A.req(other, `${app}/stores/${cl.id}/products/${tee.id}/variants/${v.id}`, { method: 'POST', form: { _method: 'DELETE' } });
    A.expect([403, 404].includes(del.status), `another owner must not delete a variant (${del.status})`);
    A.expect(A.sql('SELECT stock_qty, price_minor FROM menu_variants WHERE menu_id = ? ORDER BY id', [tee.id]).map((r) => r.join('/')).join(',') === before, 'the variants must be untouched by the outsider');
    A.expect(A.sqlValue("SELECT 1 FROM items WHERE name = 'outsider'") === null, 'no product may be created by the outsider');
    R.ok("IDOR: another owner gets 404 on the catalog, variants, brands, fitments and compliance pages of all four stores and cannot change or delete anything");
  });
  await A.withPage(browser, async (c) => { // a customer cannot put another store's variant into this store's cart
    await c.goto(`${app}/${S.retail.slug}`, { waitUntil: 'domcontentloaded' });
    const r = await addToCart(c, S.retail.slug, lamp.id, { variation: tee.variants[0].id, quantity: 1 });
    A.expect([404, 422].includes(r.status), `another store's variant must not enter this cart (${r.status})`);
    const r2 = await addToCart(c, S.retail.slug, tee.id, { quantity: 1 });
    A.expect([404, 422].includes(r2.status), `another store's product must not enter this cart (${r2.status})`);
    R.ok("IDOR: a variant or product of another store cannot be added to this store's cart");
  }, { width: 430, height: 900 });

  R.summary();
});
