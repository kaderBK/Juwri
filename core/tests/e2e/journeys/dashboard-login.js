// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Journey 2 — merchant dashboard login.
 *
 * Drives the real login form (name="username" / name="password") and asserts
 * the owner lands on the dashboard. A green HTTP suite says nothing about the
 * login form actually submitting in a browser.
 *
 * Credentials: ThemePackDemoSeeder owner (themepack-owner@demo.test / password).
 */

module.exports = async function dashboardLogin({ page, cfg, log, shot, sleep }) {
  await page.goto(`${cfg.baseUrl}/login`, { waitUntil: 'networkidle2' });
  await page.waitForSelector('#username, input[name="username"]', { timeout: cfg.navTimeout });
  await shot(page, 'dashboard-1-login');

  await page.type('#username', cfg.ownerEmail);
  await page.type('#password', cfg.ownerPass);

  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: cfg.navTimeout }).catch(() => {}),
  ]);
  await sleep(800);
  await shot(page, 'dashboard-2-after-login');

  const landed = await page.evaluate(() => location.pathname);
  const onDashboard = await page.evaluate(() =>
    !!document.querySelector('nav, aside, .sidebar') &&
    !!document.querySelector('[href*="logout"], [action*="logout"]'));

  if (!landed.includes('dashboard') && !onDashboard) {
    throw new Error(`dashboard-login: did not reach the dashboard (landed on ${landed}) — login form submit failed`);
  }
  log(`dashboard-login: reached ${landed} OK`);
};
