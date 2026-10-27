// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Owner dashboard mobile shell (assets/owner-ui/shell.css + partials/mobile-tabbar).
 *
 *  1. Phone (390×844): the tab bar is pinned to the bottom of the viewport, the
 *     current tab is marked, and "More" opens the existing sidebar drawer.
 *  2. Desktop (1280×900): no tab bar.
 *  3. Inside the native app (JuwriApp/ user agent, server item S2): no web
 *     top bar, sidebar or tab bar, and the content starts at the left edge.
 *
 * Credentials: ThemePackDemoSeeder owner.
 */

module.exports = async function ownerShell({ page, cfg, log, shot, clearServiceWorkers }) {
  // The owner has already answered the cookie banner (essential only): its bar sits over the phone tab bar until a
  // choice is made (P1-23 consent), which is not what this journey measures.
  await page.setCookie({ name: 'site_consent', value: 'v1.a0.m0', url: cfg.baseUrl });
  await page.goto(`${cfg.baseUrl}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#username, input[name="username"]');
  await page.type('#username, input[name="username"]', cfg.ownerEmail);
  await page.type('#password, input[name="password"]', cfg.ownerPass);
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
  ]);

  // 1. Phone
  await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  await page.goto(`${cfg.baseUrl}/en/dashboard`, { waitUntil: 'load' });
  const bar = await page.evaluate(() => {
    const nav = document.querySelector('.ui-tabbar');
    if (!nav) return null;
    const r = nav.getBoundingClientRect();
    const current = nav.querySelector('[aria-current="page"]');
    return {
      visible: getComputedStyle(nav).display !== 'none',
      pinned: Math.abs(r.bottom - window.innerHeight) <= 1,
      items: nav.querySelectorAll('.ui-tabbar__item').length,
      current: current ? current.textContent.trim() : null,
    };
  });
  if (!bar || !bar.visible || !bar.pinned || bar.items !== 5 || !bar.current) {
    throw new Error(`owner-shell: phone tab bar wrong: ${JSON.stringify(bar)}`);
  }
  await shot(page, 'owner-shell-1-phone');
  const hit = await page.evaluate(() => {
    const b = document.querySelector('.ui-tabbar [data-ui-menu]').getBoundingClientRect();
    const el = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
    return el ? (el.closest('[data-ui-menu]') ? 'tab' : (el.className || el.tagName).toString().slice(0, 80)) : 'none';
  });
  log(`owner-shell: element under the More tab = ${hit}`);
  await page.click('.ui-tabbar [data-ui-menu]');
  await page.waitForFunction(() => document.getElementById('appMenubar')?.classList.contains('is-open'), { timeout: 5000 })
    .catch(() => { throw new Error('owner-shell: "More" did not open the sidebar drawer'); });
  await shot(page, 'owner-shell-2-more');
  log(`owner-shell: phone tab bar OK (current "${bar.current}"), More opens the drawer`);

  // 2. Desktop
  await page.setViewport({ width: 1280, height: 900 });
  await page.goto(`${cfg.baseUrl}/en/dashboard`, { waitUntil: 'load' });
  const desktopShown = await page.evaluate(() => {
    const nav = document.querySelector('.ui-tabbar');
    return !!nav && getComputedStyle(nav).display !== 'none';
  });
  if (desktopShown) throw new Error('owner-shell: tab bar visible on desktop');

  // 3. Native app (S2)
  // The dashboard PWA service worker / HTTP cache can replay the page rendered for the
  // previous (browser) user agent — clear both so the server sees the app UA.
  await clearServiceWorkers(page);
  await page.setCacheEnabled(false);
  await page.setUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) JuwriApp/1.0.0 Hotwire Native iOS');
  await page.setViewport({ width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  await page.goto(`${cfg.baseUrl}/en/dashboard`, { waitUntil: 'load' });
  log(`owner-shell: app UA url=${page.url()} ua=${await page.evaluate(() => navigator.userAgent)}`);
  const app = await page.evaluate(() => {
    const hidden = (sel) => { const el = document.querySelector(sel); return !el || getComputedStyle(el).display === 'none'; };
    const main = document.querySelector('.ui-shell__main');
    return {
      flagged: document.documentElement.getAttribute('data-owner-app') === '1',
      header: hidden('.ui-top'),
      sidebar: hidden('#appMenubar'),
      tabbar: hidden('.ui-tabbar'),
      top: main ? Math.round(main.getBoundingClientRect().top) : null,
      left: main ? Math.round(main.getBoundingClientRect().left) : null,
    };
  });
  await shot(page, 'owner-shell-3-in-app');
  if (!app.flagged || !app.header || !app.sidebar || !app.tabbar || app.left !== 0 || app.top > 1) {
    throw new Error(`owner-shell: in-app chrome not hidden: ${JSON.stringify(app)}`);
  }
  log('owner-shell: desktop has no tab bar; in-app hides web chrome');
};
