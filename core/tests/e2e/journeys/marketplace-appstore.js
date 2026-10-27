// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P1-12 item 9 f) — App Store: browse, install a free app, a paid app that needs money first, uninstall. Opt-in with
 * E2E_RUN_P112_B=1.
 *
 *   1  anyone browses the public store (/apps/store) and an app page;
 *   2  owner A (dashboard): browses /store/apps (free / paid counts), opens an app, installs a FREE app from the "My apps"
 *      page through the consent sheet (it lists the permissions being granted), sees it under "Installed apps";
 *   3  isolation: owner B (another business) sees nothing installed, cannot uninstall A's installation (404) and A's
 *      permissions are not B's; A uninstalls from the sheet -> the installation is closed (access revoked);
 *   4  PAID app: with an empty wallet the install is refused with the exact shortfall + a top-up form and NOTHING is
 *      installed or charged. With the licence in force (ee Billing = wallet top-ups) the owner tops up the shortfall through
 *      the stub gateway (a second throwaway server with E2E_STUBS=1, same code + DB) and the app installs, wallet back to 0;
 *      uninstall stops billing. Community has no wallet top-up rail: that leg is skipped with that reason, the refusal
 *      still applies (the same install code, no licence switch exists in the paid-install path today).
 *
 * Data: the platform demo apps of AppStoreSeeder (QuickOrder, LoyalLoop free; BlastMail 9/mo ...) — seeded here when the
 * catalogue is empty. Installations created here are always uninstalled again.
 * Env: E2E_SHOWCASE_EMAIL/PASS (owner A), E2E_OWNER_EMAIL/PASS (owner B, themepack), E2E_APPSTORE_PORT (default 8391).
 */

const L = require('../lib/lane-b');
const { newPage } = require('../lib/harness');

const A = { email: process.env.E2E_SHOWCASE_EMAIL || 'showcase-demo@example.com', pass: process.env.E2E_SHOWCASE_PASS || 'showcase-demo-pass' };
const B = { email: process.env.E2E_OWNER_EMAIL || 'themepack-owner@demo.test', pass: process.env.E2E_OWNER_PASS || 'password' };

const FREE = 'loyal-loop';
const PAID = 'blast-mail';

const appRow = (slug) => {
  const r = L.dbq('select id, name from apps where slug = ?', [slug]);
  return r.length ? { id: r[0][0], name: r[0][1] } : null;
};
const activeInstall = (userEmail, appId) => Number(L.dbq(
  "select count(*) from app_installations i join users u on u.id = i.user_id where u.email = ? and i.app_id = ? and i.status in ('active','suspended')",
  [userEmail, appId],
)[0][0]);
const wallet = (email) => Number(L.dbq('select balance_minor from users where email = ?', [email])[0][0]);

async function csrf(page) {
  return page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.content || '');
}

async function postJson(page, url) {
  return page.evaluate(async (u) => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const r = await fetch(u, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token } });
    let json = null; try { json = await r.json(); } catch (_e) { /* not json */ }
    return { status: r.status, json };
  }, url);
}

/** Open /apps, click "Install" on the card of `name`, confirm in the consent sheet; returns the permission lines shown. */
async function installFromAppsPage(page, base, name) {
  await page.goto(`${base}/apps`, { waitUntil: 'networkidle2' });
  const clicked = await page.evaluate((n) => {
    const card = [...document.querySelectorAll('.ui-apps__card')].find((c) => c.querySelector('.ui-apps__card-name')?.textContent.trim() === n);
    const btn = card && [...card.querySelectorAll('button')].find((b) => /^Install$/.test(b.textContent.trim()));
    if (!btn) return false;
    btn.click();
    return true;
  }, name);
  if (!clicked) throw new Error(`no "Install" card for ${name} on /apps`);
  await page.waitForFunction(() => { const s = document.getElementById('apps-install'); return s && (s.open || s.hasAttribute('open') || getComputedStyle(s).display !== 'none'); }, { timeout: 8000 }).catch(() => {});
  const perms = await page.evaluate(() => [...document.querySelectorAll('#apps-install .ui-apps__perms li')].map((li) => li.textContent.replace(/\s+/g, ' ').trim()));
  await page.evaluate(() => {
    const btn = [...document.querySelectorAll('#apps-install button')].find((b) => /Install app/.test(b.textContent) && !b.disabled);
    btn.click();
  });
  await new Promise((r) => setTimeout(r, 1500));
  return perms;
}

async function uninstallFromAppsPage(page, base, name) {
  await page.goto(`${base}/apps`, { waitUntil: 'networkidle2' });
  const clicked = await page.evaluate((n) => {
    const row = [...document.querySelectorAll('.ui-apps__list li, ul li')].find((li) => li.textContent.includes(n) && li.querySelector('.ui-menu-item__delete'));
    if (!row) return false;
    row.querySelector('.ui-menu-item__delete').click();
    return true;
  }, name);
  if (!clicked) return false;
  await new Promise((r) => setTimeout(r, 600));
  await page.evaluate(() => {
    const btn = [...document.querySelectorAll('#apps-uninstall button')].find((b) => /^Uninstall$/.test(b.textContent.trim()));
    btn.click();
  });
  await new Promise((r) => setTimeout(r, 1500));
  return true;
}

module.exports = async function marketplaceAppstore({ browser, log, shot }) {
  const check = L.checker('marketplace-appstore');
  const base = L.baseUrl();

  // ---- data: the demo apps -----------------------------------------------------------------------------------------
  let free = appRow(FREE);
  let paid = appRow(PAID);
  if (!free || !paid) {
    log('demo apps missing: seeding AppStoreSeeder');
    L.artisan(['db:seed', '--class=Juwri\\Modules\\AppStore\\Database\\Seeders\\AppStoreSeeder', '--force'], {});
    free = appRow(FREE);
    paid = appRow(PAID);
  }
  if (!free || !paid) return L.skip(log, 'marketplace-appstore', 'AppStoreSeeder did not provide the demo apps');

  const cleanup = [];
  try {
    // ---- 1 public browse ---------------------------------------------------------------------------------------------
    const pub = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
    try {
      await pub.page.goto(`${base}/apps/store`, { waitUntil: 'networkidle2' });
      const listing = await pub.page.evaluate(() => document.body.innerText);
      check.ok(listing.includes(free.name) && listing.includes(paid.name), 'public App Store lists the free and the paid app');
      check.ok(/\$\s*9\.00/.test(listing.replace(/[⁦-⁩]/g, '')) && /Free/.test(listing), 'prices are shown (Free, $9.00 /mo)');
      const detail = await pub.page.goto(`${base}/apps/store/${PAID}`, { waitUntil: 'networkidle2' });
      check.eq(detail.status(), 200, 'public app page opens');
      const nope = await pub.page.goto(`${base}/apps/store/e2eb-no-such-app`, { waitUntil: 'domcontentloaded' });
      check.eq(nope.status(), 404, 'an unknown app is a 404');
    } finally { await pub.close(); }

    // ---- 2 owner A browses + installs the free app -------------------------------------------------------------------
    const a = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
    const b = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
    try {
      await L.login(a.page, base, A);
      await L.login(b.page, base, B);
      // leave nothing behind from earlier runs
      for (const app of [free, paid]) {
        if (activeInstall(A.email, app.id)) await postJson(a.page, `${base}/apps/${app.id}/uninstall`);
      }

      await a.page.goto(`${base}/store/apps`, { waitUntil: 'networkidle2' });
      const store = await a.page.evaluate(() => document.body.innerText);
      check.ok(/Browse apps/.test(store) && store.includes(free.name) && store.includes(paid.name), 'owner A: the in-dashboard App Store lists the apps');
      check.ok(/Free\s*\n?\s*\d/.test(store) && /Paid\s*\n?\s*\d/.test(store), 'owner A: Free / Paid filters carry counts');
      await a.page.goto(`${base}/store/apps/${FREE}`, { waitUntil: 'networkidle2' });
      const page1 = await a.page.evaluate(() => document.body.innerText);
      check.ok(/Install app/.test(page1) && /Permissions requested/.test(page1), 'owner A: the app page offers Install and lists the permissions requested');

      const perms = await installFromAppsPage(a.page, base, free.name);
      check.ok(perms.length >= 1, `owner A: the consent sheet lists the access being granted (${perms.join(' / ')})`);
      check.eq(activeInstall(A.email, free.id), 1, 'owner A: the free app is installed (active installation row)');
      cleanup.push(async () => { if (activeInstall(A.email, free.id)) await postJson(a.page, `${base}/apps/${free.id}/uninstall`); });
      await a.page.goto(`${base}/apps`, { waitUntil: 'networkidle2' });
      const installed = await a.page.evaluate(() => {
        const rows = [...document.querySelectorAll('li')].filter((li) => li.querySelector('.ui-menu-item__delete'));
        return rows.map((li) => li.textContent.replace(/\s+/g, ' ').trim());
      });
      check.ok(installed.some((t) => t.includes(free.name) && /Free/.test(t)), 'owner A: "Installed apps" shows the app with its price');
      await shot(a.page, 'appstore-installed');

      // ---- 3 isolation + uninstall -----------------------------------------------------------------------------------
      await b.page.goto(`${base}/apps`, { waitUntil: 'networkidle2' });
      const bRows = await b.page.evaluate(() => [...document.querySelectorAll('li')].filter((li) => li.querySelector('.ui-menu-item__delete')).map((li) => li.textContent));
      check.ok(!bRows.some((t) => t.includes(free.name)), "owner B: A's installation is not in B's installed apps");
      check.eq(activeInstall(B.email, free.id), 0, 'owner B: no installation row of their own');
      const idor = await postJson(b.page, `${base}/apps/${free.id}/uninstall`);
      check.eq(idor.status, 404, "owner B cannot uninstall A's installation (404)");
      check.eq(activeInstall(A.email, free.id), 1, "A's installation is untouched");
      await b.page.goto(`${base}/store/apps/${FREE}`, { waitUntil: 'networkidle2' });
      check.ok(/Install app/.test(await b.page.evaluate(() => document.body.innerText)), 'owner B: the app page still offers Install (it is not installed for B)');

      const did = await uninstallFromAppsPage(a.page, base, free.name);
      check.ok(did, 'owner A: uninstalled through the confirm sheet');
      check.eq(activeInstall(A.email, free.id), 0, 'owner A: the installation is closed (access revoked)');
      const closed = L.dbq("select i.status, i.uninstalled_at from app_installations i join users u on u.id = i.user_id where u.email = ? and i.app_id = ?", [A.email, free.id])[0];
      check.ok(closed && closed[0] === 'uninstalled' && closed[1], `owner A: status uninstalled with a timestamp (${closed && closed[0]})`);
    } finally {
      for (const fn of cleanup.splice(0)) await fn().catch(() => {});
      await a.close();
      await b.close();
    }

    // ---- 4 paid app: needs money first -------------------------------------------------------------------------------
    const enterprise = (() => { try { return L.eePresentOnDisk(); } catch (_e) { return false; } })();
    const port = parseInt(process.env.E2E_APPSTORE_PORT || '8391', 10);
    const server = await L.startPhpServer(port, { E2E_STUBS: '1' });
    const p = await L.freshPage(browser, newPage, { width: 1280, height: 900 });
    try {
      await L.login(p.page, server.url, A);
      if (activeInstall(A.email, paid.id)) await postJson(p.page, `${server.url}/apps/${paid.id}/uninstall`);
      const before = wallet(A.email);
      await p.page.goto(`${server.url}/store/apps/${PAID}`, { waitUntil: 'networkidle2' });
      await p.page.evaluate(() => [...document.querySelectorAll('button')].find((x) => /Install app/.test(x.textContent)).click());
      await new Promise((r) => setTimeout(r, 1500));
      const buy = await p.page.evaluate(() => document.querySelector('.ui-appshow__buy').innerText.replace(/\s+/g, ' '));
      check.ok(/Top up .*9\.00.* to complete this purchase/.test(buy.replace(/[⁦-⁩]/g, '')), `paid app, empty wallet: the exact shortfall is shown (${buy.slice(0, 120)})`);
      check.eq(activeInstall(A.email, paid.id), 0, 'paid app, empty wallet: nothing was installed');
      check.eq(wallet(A.email), before, 'paid app, empty wallet: nothing was charged');
      check.ok(await p.page.evaluate(() => !!document.querySelector('#app-topup-method')), 'paid app: a "Pay with" top-up form is offered');
      await shot(p.page, 'appstore-paid-shortfall');

      // on disk is not enough: an Enterprise tree without a licence registers no edition routes (ModuleRoutes::slot), so
      // /admin/plans is a 404 there even for a signed-in owner (a registered admin route answers 302/403)
      const licensed = enterprise && (await L.status(p.page, `${server.url}/admin/plans`)).status !== 404;
      if (!licensed) {
        L.skip(log, 'marketplace-appstore (stub top-up leg)', enterprise ? 'Enterprise tree without a licence: no edition Billing, so no top-up credit' : 'Enterprise package absent: wallet top-up credit is Enterprise Billing, Community has no top-up rail');
      } else {
        const stub = await p.page.evaluate(() => { const o = [...document.querySelectorAll('#app-topup-method option')].find((x) => /Stub/i.test(x.textContent)); return o ? o.value : null; });
        if (!stub) {
          L.skip(log, 'marketplace-appstore (stub top-up leg)', 'the stub payment gateway is not seeded (E2ePlatformV2Seeder)');
        } else {
          await p.page.select('#app-topup-method', stub);
          await Promise.all([
            p.page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
            p.page.evaluate(() => [...document.querySelectorAll('button')].find((x) => /Top up & install/.test(x.textContent)).click()),
          ]);
          check.eq(activeInstall(A.email, paid.id), 1, 'paid app: after the stub top-up the app is installed');
          check.eq(wallet(A.email), before, 'paid app: the wallet was credited the shortfall and debited the price (net unchanged)');
          const log1 = L.dbq("select count(*) from wallet_transactions w join users u on u.id = w.user_id where u.email = ?", [A.email]);
          check.ok(Number(log1[0][0]) >= 2, 'paid app: the wallet ledger shows the top-up and the purchase');
          await p.page.goto(`${server.url}/apps`, { waitUntil: 'networkidle2' });
          const rows = await p.page.evaluate(() => [...document.querySelectorAll('li')].filter((li) => li.querySelector('.ui-menu-item__delete')).map((li) => li.textContent.replace(/[⁦-⁩]/g, '').replace(/\s+/g, ' ')));
          check.ok(rows.some((t) => t.includes(paid.name) && /9\.00/.test(t)), 'paid app: "Installed apps" shows it with its price');
        }
      }
    } finally {
      try {
        if (activeInstall(A.email, paid.id)) {
          const un = await postJson(p.page, `${server.url}/apps/${paid.id}/uninstall`);
          check.ok(un.status === 200, 'paid app: uninstall works');
          check.eq(activeInstall(A.email, paid.id), 0, 'paid app: uninstalled, billing stops (no active installation)');
        }
      } catch (e) { log(`paid app cleanup: ${e.message}`); }
      await p.close();
      server.stop();
    }
  } finally {
    for (const fn of cleanup.splice(0)) await fn().catch(() => {});
  }
  check.done(log);
};
