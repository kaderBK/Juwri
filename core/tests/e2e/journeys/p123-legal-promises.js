// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P1-12 item 9 / P1-23 — the legal promises, as a person sees them. Opt-in with E2E_RUN_P112_B=1.
 *
 *   1  footer legal links (marketing site) all resolve; /.well-known/security.txt is RFC 9116 shaped; /cookies lists the
 *      cookies by category; with legal pages OFF (an instance that does not publish JUWRI LTD's documents) the same
 *      paths 404 and /cookies still works (Community);
 *   2  sign-up: refused without the Terms box and the business confirmation; accepted with both -> an acceptance record
 *      per document version (terms / dpa / aup, version, source signup, time, IP), product-news opt-in NOT stored when
 *      left unticked — read back from the database (no UI/endpoint exposes the record yet, see the progress file);
 *   3  cookie banner, owner -> customer -> owner: the owner sets a Google Analytics id + Meta Pixel id on a store (dashboard
 *      integrations page); a first-time storefront visitor sees the banner, NO tracking script runs and NO analytics /
 *      marketing cookie exists until they choose; "Essential only" keeps both off, "Accept" starts them (the choice is the
 *      site_consent cookie and survives a reload); the owner restores the previous ids;
 *   4  a reseller's white-label host (ee): no platform brand in its pages, no legal links / company line, JUWRI LTD's
 *      documents are not served there, and it names the reseller in its cookie banner.
 *
 * Instance under test: E2E_BASE_URL. If it does not publish the legal pages (LEGAL_PAGES unset) a throwaway second PHP
 * server (same code + database) is started with LEGAL_PAGES=true — documented env, nothing in the code or .env changes.
 * Everything created here is prefixed "e2eB-"; no mail leaves the machine (MAIL_MAILER=log).
 *
 * Env: E2E_ADMIN_EMAIL/PASS (superadmin), E2E_SHOWCASE_EMAIL/PASS (store owner, defaults = E2ePlatformSeeder),
 * E2E_LEGAL_PORT (default 8291), E2E_LEGAL_STORE (default crave-demo).
 */

const http = require('http');
const L = require('../lib/lane-b');
const { newPage } = require('../lib/harness');

const TRACKER_COOKIE = /^(_ga|_gid|_gat|_gcl|_fbp|_fbc|tawk|ajs_|_hj)/i;
const GA_ID = 'G-E2EB123456';
const PIXEL_ID = '2026100512345';

function rawGet(port, host, p) {
  return new Promise((resolve, reject) => {
    http.get({ host: '127.0.0.1', port, path: p, headers: { Host: host } }, (r) => {
      let b = '';
      r.on('data', (d) => { b += d; });
      r.on('end', () => resolve({ status: r.statusCode, body: b, location: r.headers.location || '' }));
    }).on('error', reject);
  });
}

const visible = (html) => html
  .replace(/<script[\s\S]*?<\/script>/gi, ' ')
  .replace(/<style[\s\S]*?<\/style>/gi, ' ')
  .replace(/<[^>]+>/g, ' ');

async function cookieNames(page) {
  return (await page.cookies()).map((c) => c.name);
}

/** Abort third-party tracker hosts so a real GA / Meta call can never leave the machine. */
async function blockTrackers(page) {
  await page.setRequestInterception(true);
  page.on('request', (req) => {
    if (/googletagmanager\.com|google-analytics\.com|facebook\.net|facebook\.com\/tr|embed\.tawk\.to/i.test(req.url())) {
      req.abort('blockedbyclient').catch(() => {});
    } else {
      req.continue().catch(() => {});
    }
  });
}

module.exports = async function p123LegalPromises({ browser, log, shot }) {
  const adminEmail = process.env.E2E_ADMIN_EMAIL;
  const adminPass = process.env.E2E_ADMIN_PASS;
  const ownerEmail = process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com';
  const ownerPass = process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass';
  const storeSlug = process.env.E2E_LEGAL_STORE || 'crave-demo';
  if (!adminEmail || !adminPass) return L.skip(log, 'p123-legal-promises', 'E2E_ADMIN_EMAIL / E2E_ADMIN_PASS not set (run E2eAdminSeeder)');

  const check = L.checker('p123-legal-promises');
  const base = L.baseUrl();
  let server = null;
  let legalBase = base;
  const restore = {}; // owner integrations to put back

  try {
    // ---- which instance publishes the legal pages -------------------------------------------------------------------
    const probe = await fetch(`${base}/terms`, { redirect: 'manual' }).catch(() => ({ status: 0 }));
    const publishedOnBase = probe.status === 200;
    if (!publishedOnBase) {
      // Control: with LEGAL_PAGES off nothing of JUWRI LTD's is served; the cookie list stays (Community).
      for (const p of ['/terms', '/privacy', '/legal/dpa', '/legal/aup', '/.well-known/security.txt']) {
        const r = await fetch(`${base}${p}`, { redirect: 'manual' });
        check.eq(r.status, 404, `legal pages OFF: ${p} is not served`);
      }
      const ck = await fetch(`${base}/cookies`);
      check.eq(ck.status, 200, 'legal pages OFF: /cookies (the software\'s own cookie list) is still served');
      const reg = await (await fetch(`${base}/signup`)).text();
      check.ok(!/name="business_confirm"/.test(reg), 'legal pages OFF: sign-up keeps the plain form (no JUWRI business box)');

      const port = parseInt(process.env.E2E_LEGAL_PORT || '8291', 10);
      server = await L.startPhpServer(port, { LEGAL_PAGES: 'true' });
      legalBase = server.url;
      log(`legal pages not published on ${base}: using a second server ${legalBase} with LEGAL_PAGES=true`);
    }

    // ---- 1 footer links, security.txt, cookie list -----------------------------------------------------------------
    const f = await L.freshPage(browser, newPage);
    try {
      await f.page.goto(`${legalBase}/contact`, { waitUntil: 'networkidle2' });
      const links = await f.page.evaluate(() => [...document.querySelectorAll('footer a[href]')].map((a) => new URL(a.href).pathname));
      const expected = ['/terms', '/privacy', '/legal/dpa', '/legal/aup', '/legal/sla', '/legal/subprocessors', '/security', '/cookies'];
      for (const p of expected) {
        check.ok(links.includes(p), `footer links to ${p}`);
        const st = await L.status(f.page, `${legalBase}${p}`);
        check.eq(st.status, 200, `footer link ${p} resolves`);
      }
      const company = await f.page.evaluate(() => !!document.querySelector('[data-legal-company]'));
      check.ok(company, 'footer carries the JUWRI LTD company line');

      const sec = await (await fetch(`${legalBase}/.well-known/security.txt`)).text();
      check.ok(/^Contact: /m.test(sec), 'security.txt has a Contact');
      const exp = (sec.match(/^Expires: (.+)$/m) || [])[1];
      check.ok(exp && new Date(exp) > new Date(), `security.txt Expires is in the future (${exp})`);
      check.ok(/^Canonical: .*\/\.well-known\/security\.txt$/m.test(sec), 'security.txt names its canonical URL');

      await f.page.goto(`${legalBase}/cookies`, { waitUntil: 'domcontentloaded' });
      const list = await f.page.evaluate(() => document.body.innerText);
      check.ok(/essential|necessary/i.test(list) && /analytics/i.test(list) && /marketing/i.test(list), '/cookies lists the essential, analytics and marketing categories');
      check.ok(/site_consent/.test(list), '/cookies names the consent cookie itself');
    } finally { await f.close(); }

    // ---- 2 sign-up acceptance record --------------------------------------------------------------------------------
    const stamp = Date.now();
    const username = `e2eb${String(stamp).slice(-9)}`; // usernames max 16 chars
    const email = `e2eb-signup-${stamp}@local.invalid`;
    const pw = `E2eB-pass-${stamp}`;
    const s = await L.freshPage(browser, newPage);
    let newUserId = null;
    try {
      await s.page.goto(`${legalBase}/signup`, { waitUntil: 'domcontentloaded' });
      const boxes = await s.page.evaluate(() => ({
        terms: !!document.querySelector('input[name="agree_for_term"]'),
        business: !!document.querySelector('input[name="business_confirm"]'),
        optin: document.querySelector('input[name="marketing_optin"]')?.checked,
      }));
      check.ok(boxes.terms && boxes.business, 'sign-up shows the Terms box and the business confirmation');
      check.eq(boxes.optin, false, 'product-news opt-in is not pre-ticked');

      const fill = async () => {
        await s.page.$eval('input[name="username"]', (e) => { e.value = ''; });
        await s.page.type('input[name="username"]', username);
        await s.page.$eval('input[name="email"]', (e) => { e.value = ''; });
        await s.page.type('input[name="email"]', email);
        await s.page.$eval('input[name="password"]', (e) => { e.value = ''; });
        await s.page.type('input[name="password"]', pw);
        const conf = await s.page.$('input[name="password_confirmation"]');
        if (conf) { await s.page.$eval('input[name="password_confirmation"]', (e) => { e.value = ''; }); await s.page.type('input[name="password_confirmation"]', pw); }
      };
      const submit = async (waitNav = true) => {
        // Drop the browser's own `required` check so the SERVER refusal is what we observe.
        await s.page.evaluate(() => document.querySelectorAll('form [required]').forEach((e) => e.removeAttribute('required')));
        const nav = waitNav ? s.page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}) : Promise.resolve();
        await s.page.evaluate(() => (document.querySelector('form [type="submit"]') || document.querySelector('form button')).click());
        await nav;
      };

      // 2a nothing ticked -> refused, no user
      await fill();
      await submit();
      check.ok(/\/signup/.test(new URL(s.page.url()).pathname), 'sign-up without the boxes stays on the form');
      const errs = await s.page.evaluate(() => [...document.querySelectorAll('.ui-auth__error, .invalid-feedback')].map((e) => e.textContent.trim()).filter(Boolean));
      check.ok(errs.length >= 2, `both acceptances are required (${errs.join(' | ')})`);
      check.eq(Number(L.dbq('select count(*) from users where email = ?', [email])[0][0]), 0, 'no account was created without the acceptance');

      // 2b only the Terms box -> still refused (business confirmation missing)
      await fill();
      await s.page.click('input[name="agree_for_term"]');
      await submit();
      const errs2 = await s.page.evaluate(() => [...document.querySelectorAll('.ui-auth__error, .invalid-feedback')].map((e) => e.textContent.trim()).filter(Boolean));
      const at2 = await s.page.evaluate(() => `${location.pathname} ${document.title} ${document.body.innerText.replace(/\s+/g, ' ').slice(0, 160)}`);
      check.ok(errs2.length >= 1, `Terms alone is not enough: the business confirmation is required too (${errs2.length ? errs2.join(' | ') : at2})`);
      check.eq(Number(L.dbq('select count(*) from users where email = ?', [email])[0][0]), 0, 'no account with only the Terms box');

      // 2c both boxes, opt-in left unticked
      await fill();
      const ticked = await s.page.evaluate(() => ({ a: document.querySelector('input[name="agree_for_term"]').checked, b: document.querySelector('input[name="business_confirm"]').checked }));
      if (!ticked.a) await s.page.click('input[name="agree_for_term"]');
      if (!ticked.b) await s.page.click('input[name="business_confirm"]');
      await submit();
      const rows = L.dbq('select id from users where email = ?', [email]);
      check.eq(rows.length, 1, `account created (landed on ${new URL(s.page.url()).pathname})`);
      newUserId = rows[0] ? rows[0][0] : null;
    } finally { await s.close(); }

    if (newUserId) {
      const acc = L.dbq('select document, version, source, ip, accepted_at from legal_acceptances where user_id = ? order by document', [newUserId]);
      check.eq(acc.map((r) => r[0]).join(','), 'aup,dpa,terms', 'acceptance recorded for Terms, DPA and AUP');
      check.ok(acc.every((r) => r[1] === '1.0' && r[2] === 'signup' && r[3] && r[4]), 'each row has version 1.0, source signup, IP and time');
      const prefs = L.dbq('select preferences from users where id = ?', [newUserId])[0][0] || '';
      check.ok(!/marketing_optin/.test(prefs), 'no product-news opt-in stored when the box stayed unticked');

      // Read-back: no owner/admin screen or read-only endpoint shows the acceptance record today (the account export
      // `store-data:export` is a CLI tool and needs an account, which exists only after the first store) — so the
      // database row is the evidence; it is also what the export/erase registry tests cover (LegalAcceptanceTest).
    }

    // ---- 3 cookie banner: owner sets trackers -> visitor -> owner ---------------------------------------------------
    const storeRow = L.dbq('select id from stores where slug = ?', [storeSlug]);
    if (!storeRow.length) {
      log(`store ${storeSlug} not found: cookie leg skipped (seed E2ePlatformSeeder)`);
    } else {
      const storeId = storeRow[0][0];
      const o = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
      try {
        await L.login(o.page, legalBase, { email: ownerEmail, pass: ownerPass });
        const integ = `${legalBase}/integrations-structured/${storeId}`;
        await o.page.goto(integ, { waitUntil: 'domcontentloaded' });
        const prev = await o.page.evaluate(() => ({
          ga: document.querySelector('input[name="ga_id"]')?.value || '',
          pixel: document.querySelector('input[name="meta_pixel_id"]')?.value || '',
        }));
        Object.assign(restore, { storeId, integ, ...prev });
        const setIds = async (ga, pixel) => {
          await o.page.goto(integ, { waitUntil: 'domcontentloaded' });
          await o.page.$eval('input[name="ga_id"]', (e, v) => { e.value = v; }, ga);
          await o.page.$eval('input[name="meta_pixel_id"]', (e, v) => { e.value = v; }, pixel);
          await Promise.all([
            o.page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
            o.page.evaluate(() => document.querySelector('input[name="ga_id"]').form.submit()),
          ]);
          await o.page.goto(integ, { waitUntil: 'domcontentloaded' });
          return o.page.evaluate(() => ({ ga: document.querySelector('input[name="ga_id"]')?.value, pixel: document.querySelector('input[name="meta_pixel_id"]')?.value }));
        };
        const saved = await setIds(GA_ID, PIXEL_ID);
        check.ok(saved.ga === GA_ID && saved.pixel === PIXEL_ID, 'owner saved the Analytics and Pixel ids on the integrations page');

        const visit = async (choose) => {
          const v = await L.freshPage(browser, newPage, { width: 420, height: 900 });
          try {
            await blockTrackers(v.page);
            await v.page.goto(`${legalBase}/${storeSlug}`, { waitUntil: 'networkidle2' });
            const before = await v.page.evaluate(() => {
              const box = document.getElementById('siteCookieConsent');
              const gated = [...document.querySelectorAll('script[data-cookie-consent]')].map((s) => ({ cat: s.dataset.cookieConsent, type: s.type }));
              const live = [...document.querySelectorAll('script')].filter((s) => s.type !== 'text/plain' && /googletagmanager|fbevents|gtag\(|fbq\(/.test((s.src || '') + (s.textContent || ''))).length;
              return { bannerVisible: !!box && !box.hidden && getComputedStyle(box).display !== 'none', gated, live, cookieStr: document.cookie };
            });
            check.ok(before.bannerVisible, 'first visit: the cookie banner is shown');
            check.ok(before.gated.some((g) => g.cat === 'analytics') && before.gated.some((g) => g.cat === 'marketing') && before.gated.every((g) => g.type === 'text/plain'), 'first visit: analytics + marketing scripts are inert (type=text/plain)');
            check.eq(before.live, 0, 'first visit: no tracking script is running');
            const names0 = await cookieNames(v.page);
            check.ok(!names0.some((n) => TRACKER_COOKIE.test(n)) && !names0.includes('site_consent'), `first visit: no analytics/marketing cookie, no consent cookie yet (${names0.join(',') || 'none'})`);
            await shot(v.page, 'p123-banner');

            const live = () => v.page.evaluate(() => {
              const run = [...document.querySelectorAll('script')].filter((x) => x.type !== 'text/plain');
              const has = (re) => run.some((x) => re.test((x.src || '') + (x.textContent || '')));
              return { ga: has(/googletagmanager\.com\/gtag|gtag\(|G-E2EB/), pixel: has(/fbevents|fbq\(/) };
            });
            const consent = () => v.page.evaluate(() => (document.cookie.match(/site_consent=([^;]+)/) || [])[1] || '');
            await v.page.click(`#siteCookieConsent [data-ckc="${choose}"]`);
            await new Promise((r) => setTimeout(r, 800));
            const hidden = await v.page.evaluate(() => document.getElementById('siteCookieConsent')?.hidden !== false);
            check.eq(await consent(), choose === 'accept' ? 'v1.a1.m0' : 'v1.a0.m0', `"${choose}": the choice is stored in the consent cookie (accept = analytics only)`);
            check.ok(hidden, `"${choose}": the banner closes`);
            let st = await live();
            if (choose === 'accept') {
              check.ok(st.ga, '"accept": Analytics starts');
              check.ok(!st.pixel, '"accept": the Meta Pixel (marketing) stays off — it needs its own consent');
            } else {
              check.ok(!st.ga && !st.pixel, '"reject": nothing starts');
            }

            await v.page.reload({ waitUntil: 'networkidle2' });
            const again = await v.page.evaluate(() => ({ hidden: document.getElementById('siteCookieConsent')?.hidden !== false, link: !!document.querySelector('[data-cookie-settings]') }));
            check.ok(again.hidden, `"${choose}": after a reload the banner does not come back`);
            check.ok(again.link, `"${choose}": the footer keeps a "cookie settings" link to change the choice`);
            st = await live();
            check.eq(st.ga, choose === 'accept', `"${choose}": after a reload Analytics ${choose === 'accept' ? 'starts again' : 'stays off'}`);

            if (choose === 'accept') {
              // change of mind from the footer: add marketing, then take analytics away again
              await v.page.evaluate(() => document.querySelector('[data-cookie-settings]').click());
              await v.page.evaluate(() => { document.querySelector('#siteCookieConsent [data-ckc-cat="marketing"]').click(); });
              await v.page.click('#siteCookieConsent [data-ckc="save"]');
              await new Promise((r) => setTimeout(r, 800));
              check.eq(await consent(), 'v1.a1.m1', 'settings: marketing added -> v1.a1.m1');
              st = await live();
              check.ok(st.ga && st.pixel, 'settings: Analytics and the Pixel are both running');
              await v.page.evaluate(() => document.querySelector('[data-cookie-settings]').click());
              await v.page.evaluate(() => { document.querySelector('#siteCookieConsent [data-ckc-cat="analytics"]').click(); });
              await Promise.all([v.page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}), v.page.click('#siteCookieConsent [data-ckc="save"]')]);
              check.eq(await consent(), 'v1.a0.m1', 'settings: analytics withdrawn -> v1.a0.m1 (page reloads)');
              st = await live();
              check.ok(!st.ga && st.pixel, 'settings: after withdrawing, Analytics is off and the Pixel (still consented) runs');
              const names1 = await cookieNames(v.page);
              check.ok(!names1.some((n) => /^(_ga|_gid)/.test(n)), `settings: no Google Analytics cookie remains after withdrawal (${names1.join(',')})`);
            }
          } finally { await v.close(); }
        };
        await visit('reject');
        await visit('accept');
      } finally {
        // owner restores what the store had before
        try {
          if (restore.integ) {
            await o.page.goto(restore.integ, { waitUntil: 'domcontentloaded' });
            await o.page.$eval('input[name="ga_id"]', (e, v) => { e.value = v; }, restore.ga || '');
            await o.page.$eval('input[name="meta_pixel_id"]', (e, v) => { e.value = v; }, restore.pixel || '');
            await Promise.all([
              o.page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
              o.page.evaluate(() => document.querySelector('input[name="ga_id"]').form.submit()),
            ]);
          }
        } catch (e) { log(`could not restore the integrations of store ${restore.storeId}: ${e.message}`); }
        await o.close();
      }
    }

    // ---- 4 reseller white-label host --------------------------------------------------------------------------------
    const reseller = (() => { try { return L.dbq('select custom_domain, brand_name from resellers where active = 1 and custom_domain is not null limit 1'); } catch (_e) { return null; } })();
    if (!reseller) {
      L.skip(log, 'p123-legal-promises (reseller leg)', 'no resellers table / no active reseller with a custom domain (ee absent or not seeded)');
    } else if (!server) {
      // the base site already publishes the pages; reach the reseller host by name through the same server
      log('base publishes legal pages; reseller leg uses a Host header against a second server');
    }
    if (reseller && reseller.length) {
      if (!server) server = await L.startPhpServer(parseInt(process.env.E2E_LEGAL_PORT || '8291', 10), { LEGAL_PAGES: 'true' });
      const port = parseInt(new URL(server.url).port, 10);
      const [host, brand] = reseller[0];
      for (const p of ['/', '/cookies']) {
        const r = await rawGet(port, host, p);
        check.eq(r.status, 200, `reseller host ${p} loads`);
        const text = visible(r.body);
        check.ok(!/juwri/i.test(text), `reseller host ${p}: no platform brand in the visible text`);
        check.ok(!/data-legal-company/.test(r.body), `reseller host ${p}: no JUWRI LTD company line`);
        check.ok(!/href="[^"]*\/(terms|privacy|legal\/(dpa|aup|sla|subprocessors))"/.test(r.body), `reseller host ${p}: no links to JUWRI LTD's legal documents`);
        check.ok(!/"name":"juwri"/i.test(r.body), `reseller host ${p}: no platform brand in the structured data either`);
        check.ok(new RegExp(`id="siteCookieConsent"[\\s\\S]*?<p>[^<]*${brand.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`).test(r.body), `reseller host ${p}: the cookie banner names the reseller (${brand})`);
      }
      for (const p of ['/terms', '/privacy', '/legal/dpa', '/legal/aup', '/security', '/.well-known/security.txt']) {
        const r = await rawGet(port, host, p);
        check.eq(r.status, 404, `reseller host: JUWRI LTD's ${p} is not served under the reseller's domain`);
      }
      const plat = await rawGet(port, `127.0.0.1:${port}`, '/cookies');
      check.ok(/data-legal-company/.test(plat.body), 'control: the platform host keeps the company line');
    }
  } finally {
    if (server) server.stop();
  }
  check.done(log);
};
