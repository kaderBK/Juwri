'use strict';

/**
 * P6-108 — one owner onboarding journey, run once per launch country (SA, AE, JO, EG, LY).
 *
 *   1. signup at the public form, e-mail verified from the mailed link          (users row, email_verified_at)
 *   2. first store through the create form                                      (stores row, owner, slug)
 *   3. country defaults: country, currency, language, tax rate (J32) on the settings screen   (store options)
 *   4. menu: one category and two items                                         (categories / items rows)
 *   5. the country's gateway in TEST mode, fake/sandbox values only             (store_payment_gateways row, secret encrypted + masked)
 *   6. first order from the storefront                                          (orders row: currency, minor-unit totals, tax)
 *   7. the owner accepts it from the order list                                 (orders.status pending -> preparing)
 *   8. payout settings (the store's bank account for bank transfers) saved       (gateway row; the step-5 gateway survives the second save)
 *
 * Every record is made through the app (forms / the storefront's own request); the only direct writes are the platform
 * switches an admin turns on (fiscalization) and the e-mail verification fallback is NOT used (the mailed link is).
 * Read-only SQL through lane-b's dbq. Test values only: invented keys (`sk_test_e2e_…`) and public example IBANs.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const L = require('./lane-b');
const P4 = require('./p4');
const PL = require('./platform');

const PNG_1x1 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgYGAAAAAEAAH2FzhVAAAAAElFTkSuQmCC', 'base64');
// runner_e2e.sh sets E2E_DB=sqlite (a mode for the mysql shim, not a database name): the artisan env already points at the file.
const dbEnv = () => (process.env.E2E_DB && process.env.E2E_DB !== 'sqlite' ? { DB_DATABASE: process.env.E2E_DB } : {});
const q = (v) => JSON.stringify(String(v));

function tinker(php, key) {
  let out;
  try {
    out = L.artisan(['tinker', '--execute', php], dbEnv());
  } catch (e) {
    throw new Error(`tinker failed: ${String(e.stderr || '').slice(-800)} ${String(e.stdout || '').slice(-800)}`);
  }
  const m = out.match(new RegExp(`${key}=(.*)`));
  if (!m) throw new Error(`tinker fixture failed (no ${key}=): ${out.slice(-500)}`);
  return m[1].trim();
}

function tmpPng(tag) {
  const p = path.join(os.tmpdir(), `p6108-${tag}-${Date.now()}-${Math.random().toString(36).slice(2)}.png`);
  fs.writeFileSync(p, PNG_1x1);
  return p;
}

/**
 * The newest verification link in the lane mail log since `since` bytes. Not platform.readMailLink(): that decodes `=XX` hex
 * runs, which corrupts a signed URL (`expires=17…`) when the mail was written 8-bit (b-signup ISS-B-001); only the QP soft
 * line breaks are unfolded and the HTML copy's `&amp;` is restored.
 */
function readVerifyLink(since) {
  const deadline = Date.now() + 30000;
  for (;;) {
    const logFile = PL.resolveMailLog();
    if (logFile && fs.existsSync(logFile)) {
      const text = fs.readFileSync(logFile).slice(Math.max(0, since || 0)).toString('utf8').replace(/=\r?\n/g, '');
      const urls = (text.match(/https?:\/\/[^\s"'<>)\]]+/g) || []).filter((u) => /\/email\/verify\//.test(u)).map((u) => u.replace(/&amp;/g, '&'));
      if (urls.length) return urls[urls.length - 1];
    }
    if (Date.now() > deadline) throw new Error('no verification link in the mail log within 30 s');
    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 500);
  }
}

async function setField(page, selector, value) {
  const ok = await page.$eval(selector, (el, v) => {
    el.value = v;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }, String(value)).catch(() => false);
  if (!ok) throw new Error(`field ${selector} not found on ${page.url()}`);
}

/** Set named controls of the store settings form (selects, inputs, textareas; `menu_languages[]` as a checkbox set), then submit it natively. */
async function saveSettings(page, base, storeId, values, checks = {}) {
  await page.goto(`${base}/stores/${storeId}/edit`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('form.restaurant-edit');
  const missing = await page.evaluate((vals, chk) => {
    const f = document.querySelector('form.restaurant-edit');
    const miss = [];
    for (const [name, v] of Object.entries(vals)) {
      const el = f.querySelector(`[name="${name}"]`);
      if (!el) { miss.push(name); continue; }
      if (el.tagName === 'SELECT' && ![...el.options].some((o) => o.value === String(v))) { miss.push(`${name}=${v} (no such option)`); continue; }
      el.value = String(v);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
    for (const [name, wanted] of Object.entries(chk)) {
      const boxes = [...f.querySelectorAll(`input[type=checkbox][name="${name}"]`)];
      if (!boxes.length) { miss.push(name); continue; }
      for (const b of boxes) b.checked = wanted.includes(b.value);
    }
    return miss;
  }, values, checks);
  if (missing.length) throw new Error(`settings form has no control for: ${missing.join(', ')}`);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
    page.$eval('form.restaurant-edit', (f) => f.submit()),
  ]);
  const html = await page.content();
  if (/Whoops|Server Error|Illuminate\\/.test(html.slice(0, 20000))) throw new Error(`settings save raised an error page (${page.url()})`);
}

/** Store options as the app reads them (gateway secrets come back through the vault in plaintext: ground truth only). */
function options(storeId, keys) {
  const out = tinker(`
    $o = []; foreach (${'json_decode(' + q(JSON.stringify(keys)) + ', true)'} as $k) { $o[$k] = \\App\\Models\\StoreOption::getStoreOption(${storeId}, $k); }
    echo 'OPT='.json_encode($o);
  `, 'OPT');
  return JSON.parse(out);
}

const rows = (sql, params) => L.dbq(sql, params);

async function guestOrder(page, base, store, lines, phone) {
  return P4.guestOrder(page, base, store, lines, { 'phone-number': phone, name: 'P6-108 Guest' });
}

/**
 * @param {object} ctx  the journey context ({ page, log, shot })
 * @param {object} C    the country case (see journeys/uncovered/p6108-onboarding-*.js)
 */
async function run({ page, log, shot }, C) {
  const tag = C.tag || C.cc.toLowerCase();
  const check = L.checker(`p6108-onboarding-${tag}`);
  const base = PL.cfg.baseUrl;
  const unix = Date.now();
  const id = { email: `e2e-p6108-${tag}-${unix}@local.invalid`, password: `E2e-pass-${unix}`, phone: C.phone };
  const username = (`o${tag.replace(/[^a-z]/g, '').slice(0, 4)}${unix}`).slice(0, 16);
  const slug = `p6108-${tag}-${unix.toString(36)}`;
  const title = `P6108 ${C.cc} ${unix}`;
  await page.setViewport({ width: 1100, height: 900 });
  PL.collectConsole(page);
  const t = (s) => `[${C.cc}] ${s}`;

  // platform switches an admin turns on for a deployment that sells in these countries (the country list needs fiscalization)
  tinker(`\\Juwri\\Core\\Settings\\PlatformSettings::set('admin_allow_fiscalization', '1'); echo 'OK=1';`, 'OK');

  // ── 1. signup + e-mail verification ─────────────────────────────────────────────────────────────────────────────────
  await PL.resetBrowserSession(page);
  await page.goto(PL.localeUrl('/signup'), { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('input[name="username"]');
  await setField(page, 'input[name="username"]', username);
  await setField(page, 'input[name="email"]', id.email);
  await setField(page, 'input[name="password"]', id.password);
  await setField(page, 'input[name="password_confirmation"]', id.password);
  const agree = await page.$('#agree_for_term, input[name="agree_for_term"]');
  if (agree) await agree.click();
  const business = await page.$('#business_confirm');
  if (business) await business.click();
  const since = PL.mailCheckpoint();
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
  ]);
  let user = rows('select id, email_verified_at, group_id, status from users where email = ?', [id.email]);
  check.ok(user.length === 1, t('signup created exactly one users row'));
  if (!user.length) check.done(log);
  const userId = user[0][0];
  check.ok(!user[0][1], t('email is not verified before the link is opened'));
  const verifyUrl = readVerifyLink(since);
  const vr = await page.goto(verifyUrl, { waitUntil: 'domcontentloaded' });
  log(t(`1 verify link ${verifyUrl.replace(/signature=.*/, 'signature=…')} -> ${vr ? vr.status() : '?'} ${page.url()}`));
  user = rows('select email_verified_at from users where id = ?', [userId]);
  check.ok(!!user[0][0], t('the mailed link verifies the e-mail (email_verified_at set)'));
  log(t(`1 signup ok: user ${userId}`));

  PL.resetRateLimits(); // five journeys from one IP in a row: the login throttle (5/min) would otherwise answer 429
  await PL.loginOwner(page, { email: id.email, pass: id.password });

  // ── 2. store creation ──────────────────────────────────────────────────────────────────────────────────────────────
  await page.goto(PL.localeUrl('/stores/create'), { waitUntil: 'domcontentloaded' });
  const FORM = 'form[action$="/stores"]';
  await page.waitForSelector(FORM);
  await setField(page, `${FORM} input[name="title"]`, title);
  await setField(page, `${FORM} input[name="slug"]`, slug);
  await setField(page, `${FORM} input[name="phone"]`, id.phone);
  await setField(page, `${FORM} input[name="address"]`, C.address);
  await setField(page, `${FORM} [name="description"]`, `P6-108 onboarding journey store for ${C.name}.`);
  const logo = tmpPng('logo'); const cover = tmpPng('cover');
  try {
    await (await page.$(`${FORM} input[name="main_image"]`)).uploadFile(logo);
    await (await page.$(`${FORM} input[name="cover_image"]`)).uploadFile(cover);
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      page.$eval(FORM, (f) => f.submit()),
    ]);
  } finally { fs.unlink(logo, () => {}); fs.unlink(cover, () => {}); }
  const st = rows('select id, user_id, store_type, title from stores where slug = ?', [slug]);
  check.ok(st.length === 1, t('the create form made a stores row'));
  if (!st.length) check.done(log);
  const storeId = st[0][0];
  check.eq(String(st[0][1]), String(userId), t('the store belongs to the new owner'));
  check.eq(st[0][2], 'restaurant', t('the store is a restaurant'));
  const platformCurrency = options(storeId, ['currency_code'])['currency_code'];
  log(t(`2 store ${storeId} created; currency before country defaults: ${platformCurrency}`));

  // ── 3. country defaults ────────────────────────────────────────────────────────────────────────────────────────────
  // The owner names the store's country and language only; the currency is NOT touched (P6-108b: it follows the
  // country because the owner never chose one) and the tax rate is left for the country default (J32).
  await saveSettings(page, base, storeId, {
    fiscal_country: C.cc,
    default_language: C.lang,
    restaurant_taxx_charge: '',
  }, { 'menu_languages[]': [C.lang, 'en'] });
  let o = options(storeId, ['fiscal_country', 'currency_code', 'default_language', 'menu_languages', 'restaurant_taxx_charge']);
  check.eq(o.fiscal_country, C.cc, t('3 the country is saved'));
  check.eq(o.currency_code, C.currency, t(`3 P6-108b: the currency follows the country without a manual step (was ${platformCurrency})`));
  check.eq(o.default_language, C.lang, t('3 the default language is saved'));
  check.ok(String(o.menu_languages || '').includes(C.lang), t(`3 the menu languages include ${C.lang} (${o.menu_languages})`));
  const j32 = fs.existsSync(path.join(L.appRoot(), 'core/app/Modules/Ordering/Services/Tax/TaxCountryRules.php'));
  let rate = Number(o.restaurant_taxx_charge || 0);
  if (j32) {
    check.eq(rate, C.rate, t(`3 J32: an empty tax rate takes the country default (${C.rate}%)`));
  } else {
    log(t(`3 SKIPPED leg: J32 country tax defaults (P6-116) are not on this tree; the owner sets ${C.rate}% by hand`));
    await saveSettings(page, base, storeId, { restaurant_taxx_charge: String(C.rate) });
    rate = Number(options(storeId, ['restaurant_taxx_charge']).restaurant_taxx_charge || 0);
    check.eq(rate, C.rate, t('3 the hand-set tax rate is saved'));
  }
  log(t(`3 defaults ok: ${C.cc} ${C.currency} ${C.lang} tax ${rate}%`));

  // ── 4. menu ────────────────────────────────────────────────────────────────────────────────────────────────────────
  const post = (url, fields) => P4.pageJson(page, 'POST', url, fields);
  await page.goto(`${base}/stores/${storeId}/menu`, { waitUntil: 'domcontentloaded' });
  const cat = await post(`/stores/${storeId}/add-category`, { name: `Menu ${unix}` });
  const catRow = rows('select id from categories where store_id = ? order by id desc limit 1', [storeId]);
  check.ok(catRow.length === 1, t(`4 category created (${cat.status})`));
  const items = [['Item A', 20], ['Item B', 10]];
  const ids = {};
  for (const [name, price] of items) {
    const r = await post(`/stores/${storeId}/add-menu-item`, { name: `${name} ${unix}`, category_id: catRow[0][0], price, type: 'veg', active: 1 });
    check.ok(r.status < 400 && !(r.json && r.json.success === false), t(`4 ${name} accepted (${r.status} ${JSON.stringify(r.json || '').slice(0, 100)})`));
    const ir = rows('select id, price_minor, currency_code, active from items where store_id = ? and name like ?', [storeId, `${name} ${unix}%`]);
    check.ok(ir.length === 1, t(`4 ${name} has an items row`));
    if (ir.length) {
      ids[name] = ir[0][0];
      check.eq(Number(ir[0][1]), price * 10 ** C.decimals, t(`4 ${name} price is stored in ${C.currency} minor units`));
      check.eq(ir[0][2], C.currency, t(`4 ${name} carries the store currency`));
    }
  }
  check.eq(Number(rows('select count(*) from items where store_id = ?', [storeId])[0][0]), 2, t('4 the store has exactly two items'));
  log(t('4 menu ok'));

  // ── 5. gateway in TEST mode ────────────────────────────────────────────────────────────────────────────────────────
  const gk = C.gateway.key;
  const gvals = { [`restaurant_${gk}_install`]: '1', [`restaurant_${gk}_title`]: C.gateway.title };
  if (C.gateway.sandbox) gvals[`restaurant_${gk}_sandbox_mode`] = 'Yes';
  for (const [f, v] of Object.entries(C.gateway.fields)) gvals[`restaurant_${gk}_${f}`] = v;
  await saveSettings(page, base, storeId, gvals);
  const gw = rows('select enabled, config, credentials from store_payment_gateways where store_id = ? and gateway = ?', [storeId, gk]);
  check.ok(gw.length === 1, t(`5 ${gk}: a store_payment_gateways row exists`));
  if (gw.length) {
    check.eq(String(gw[0][0]), '1', t(`5 ${gk}: enabled`));
    const cfgJson = String(gw[0][1] || '');
    check.ok(cfgJson.includes(C.gateway.title), t(`5 ${gk}: title kept in config`));
    if (C.gateway.sandbox) check.ok(/sandbox_mode"?\s*:\s*"?Yes/.test(cfgJson), t(`5 ${gk}: sandbox mode is Yes in the stored config (${cfgJson.slice(0, 120)})`));
    const secret = C.gateway.secret;
    check.ok(!!gw[0][2] && !String(gw[0][2]).includes(secret), t(`5 ${gk}: the secret is stored encrypted (not readable in the credentials column)`));
    check.ok(!cfgJson.includes(secret), t(`5 ${gk}: the secret is not in the plain config`));
  }
  if (C.gateway.testKey) check.ok(C.gateway.fields.secret_key.startsWith('sk_test_'), t('5 the key is a test key'));
  const back = options(storeId, [`restaurant_${gk}_install`, `restaurant_${gk}_${C.gateway.secretField}`]);
  check.eq(String(back[`restaurant_${gk}_${C.gateway.secretField}`]), C.gateway.secret, t('5 the app reads the stored secret back for payments'));
  await page.goto(`${base}/stores/${storeId}/edit`, { waitUntil: 'domcontentloaded' });
  const editHtml = await page.content();
  check.ok(!editHtml.includes(C.gateway.secret), t('5 the settings screen never prints the stored secret'));
  const offered = tinker(`echo 'OFFER='.(\\Juwri\\Modules\\Ordering\\Http\\Controllers\\User\\PaymentController::hasStoreGateway(\\Juwri\\Core\\Models\\Store::find(${storeId}), 30.0) ? 'yes' : 'no');`, 'OFFER');
  check.eq(offered, 'yes', t(`5 ${gk} is offered on the pay page`));
  if (C.gateway.region) {
    const region = tinker(`$r = \\Juwri\\Modules\\Payments\\Services\\Gateways\\PaymobAdapter::resolveRegion((string) store_options(${storeId}, 'restaurant_paymob_region'), (string) store_options(${storeId}, 'fiscal_country'), (string) store_options(${storeId}, 'currency_code')); echo 'REGION='.$r.' '.\\Juwri\\Modules\\Payments\\Services\\Gateways\\PaymobAdapter::HOSTS[$r];`, 'REGION');
    check.eq(region, `${C.gateway.region.key} ${C.gateway.region.host}`, t(`5 ${gk}: the store's region host is ${C.gateway.region.host}`));
  }
  log(t(`5 gateway ${gk} (test mode) ok`));

  // ── 6. first order from the storefront ────────────────────────────────────────────────────────────────────────────
  PL.resetRateLimits();
  const guest = await L.freshPage(page.browser(), require('./harness').newPage);
  let orderId;
  try {
    await guest.page.setViewport({ width: 420, height: 900 });
    const res = await guestOrder(guest.page, base, { id: storeId, slug }, [{ id: ids['Item A'], quantity: 1 }, { id: ids['Item B'], quantity: 1 }], C.guestPhone);
    check.ok(res.json && res.json.success, t(`6 the storefront accepts the order (${res.status} ${JSON.stringify(res.json || '').slice(0, 200)})`));
    const ord = rows('select id, currency_code, status, type, customer_name, subtotal_minor, tax_total_minor, grand_total_minor, payment_gateway from orders where store_id = ? order by id desc limit 1', [storeId]);
    check.ok(ord.length === 1, t('6 an orders row exists'));
    if (ord.length) {
      orderId = ord[0][0];
      const [, cur, status, type, , sub, tax, grand] = ord[0];
      check.eq(cur, C.currency, t(`6 the order is in ${C.currency}`));
      check.eq(status, 'pending', t('6 a new order waits for the owner'));
      const subExp = 30 * 10 ** C.decimals;
      if (sub !== null) check.eq(Number(sub), subExp, t('6 subtotal in minor units'));
      if (tax !== null && rate > 0) {
        const taxExp = Math.round(subExp * rate / 100);
        check.ok(Math.abs(Number(tax) - taxExp) <= 1, t(`6 tax ${tax} equals ${rate}% of the subtotal (${taxExp})`));
        if (grand !== null) check.ok(Math.abs(Number(grand) - (subExp + taxExp)) <= 1, t(`6 grand total ${grand} = subtotal + tax`));
      } else if (rate === 0) {
        check.ok(tax === null || Number(tax) === 0, t(`6 no tax on a ${C.cc} order (${tax})`));
      }
      log(t(`6 order ${orderId}: ${cur} sub=${sub} tax=${tax} grand=${grand} type=${type}`));
    }
  } finally { await guest.close(); }

  // ── 7. the owner accepts it ───────────────────────────────────────────────────────────────────────────────────────
  if (orderId) {
    await page.goto(`${base}/orders/${storeId}?q=P6-108+Guest`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector(`[data-order-id="${orderId}"] select`).catch(() => {});
    const has = await page.$(`[data-order-id="${orderId}"] select`);
    check.ok(!!has, t('7 the order is in the owner\'s list'));
    if (has) {
      await Promise.all([
        page.waitForResponse((r) => r.request().method() === 'POST' && r.url().endsWith(`/order/${orderId}/status`), { timeout: 20000 }),
        page.select(`[data-order-id="${orderId}"] select`, 'preparing'),
      ]);
      await page.waitForNetworkIdle({ idleTime: 500, timeout: 10000 }).catch(() => {});
      check.eq(rows('select status from orders where id = ?', [orderId])[0][0], 'preparing', t('7 the owner accepted the order (status preparing)'));
    }
  }
  log(t('7 accepted'));

  // ── 8. payout settings ────────────────────────────────────────────────────────────────────────────────────────────
  const bank = `Account holder: ${title}\nBank: Example Bank ${C.cc}\nIBAN: ${C.iban}`;
  await saveSettings(page, base, storeId, { restaurant_wire_transfer_install: '1', restaurant_wire_transfer_title: 'Bank transfer', restaurant_wire_transfer_bank_info: bank });
  const wt = rows('select enabled, config from store_payment_gateways where store_id = ? and gateway = ?', [storeId, 'wire_transfer']);
  check.ok(wt.length === 1, t('8 a wire_transfer gateway row holds the payout account'));
  if (wt.length) {
    check.eq(String(wt[0][0]), '1', t('8 bank transfer is on'));
    check.ok(String(wt[0][1] || '').includes(C.iban) || String(wt[0][1] || '').includes(C.iban.slice(0, 8)), t('8 the IBAN is stored with the store'));
  }
  const ob = options(storeId, ['restaurant_wire_transfer_bank_info']);
  check.ok(String(ob.restaurant_wire_transfer_bank_info || '').includes(C.iban), t('8 the app reads the saved IBAN back'));
  const still = rows('select enabled from store_payment_gateways where store_id = ? and gateway = ?', [storeId, gk]);
  check.ok(still.length === 1 && String(still[0][0]) === '1', t(`8 saving the payout settings left ${gk} switched on`));
  const keep = options(storeId, ['fiscal_country', 'currency_code', `restaurant_${gk}_${C.gateway.secretField}`, 'restaurant_taxx_charge']);
  check.eq(keep.fiscal_country, C.cc, t('8 the country survives the second save'));
  check.eq(keep.currency_code, C.currency, t('8 the currency survives the second save'));
  check.eq(String(keep[`restaurant_${gk}_${C.gateway.secretField}`]), C.gateway.secret, t(`8 the ${gk} secret survives the second save`));
  check.eq(Number(keep.restaurant_taxx_charge || 0), rate, t('8 the tax rate survives the second save'));
  log(t('8 payout settings ok'));

  const errs = ((page.__platformConsole && page.__platformConsole.errors) || []).filter((e) => /^(pageerror:|CSP:)/.test(e) && !/report[\s-]?only/i.test(e));
  check.ok(errs.length === 0, t(`no page errors / enforced CSP refusals (${errs.slice(0, 2).join(' | ')})`));
  await shot(page, `p6108-${tag}-done`);
  check.done(log);
}

module.exports = { run };
