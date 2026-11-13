'use strict';

/**
 * T8 — shared puppeteer harness for the storefront / PWA / POS e2e smoke.
 *
 * No new dependency: reuses the `puppeteer` already locked in
 * core/package.json. Every knob is env-driven so the same journeys run against
 * a local Herd HTTPS site or a CI `php artisan serve` instance.
 */

const fs = require('fs');
const path = require('path');
// puppeteer is loaded on first launch(), not at require time: `run.js --list`
// (the E2eRunnerContractTest contract) must work where the e2e node_modules
// are not installed.
let puppeteer = null;

const SCREENSHOT_DIR = path.resolve(__dirname, '..', '__screenshots__');

const cfg = {
  // Base URL of the seeded instance under test. Herd locally, serve in CI.
  baseUrl: (process.env.E2E_BASE_URL || 'https://imp-a.test').replace(/\/$/, ''),
  // Store seeded by ThemePackDemoSeeder — a CRAVE (legacy) theme storefront.
  storeSlug: process.env.E2E_STORE_SLUG || 'themepack-demo',
  // Run locale — the platform group is Arabic-only; `ar` is the site default.
  locale: process.env.E2E_LOCALE || 'ar',
  // Owner + diner credentials from ThemePackDemoSeeder.
  ownerEmail: process.env.E2E_OWNER_EMAIL || 'themepack-owner@demo.test',
  ownerPass: process.env.E2E_OWNER_PASS || 'password',
  dinerEmail: process.env.E2E_DINER_EMAIL || 'demo-diner@demo.test',
  dinerPass: process.env.E2E_DINER_PASS || 'password',
  // Self-signed Herd certs → ignore TLS errors locally.
  ignoreHttps: process.env.E2E_IGNORE_HTTPS !== '0',
  headless: process.env.E2E_HEADLESS !== '0',
  slowMo: parseInt(process.env.E2E_SLOWMO || '0', 10),
  navTimeout: parseInt(process.env.E2E_NAV_TIMEOUT || '30000', 10),
};

function log(...args) {
  console.log('[e2e]', ...args);
}

// puppeteer >= 22 removed page.waitForTimeout(); use a plain sleep.
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function launch() {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
  puppeteer = puppeteer || require('puppeteer');
  return puppeteer.launch({
    headless: cfg.headless ? 'new' : false,
    slowMo: cfg.slowMo,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-gpu',
      '--disable-dev-shm-usage',
      ...(cfg.ignoreHttps ? ['--ignore-certificate-errors'] : []),
      // Extra Chrome flags (';;'-separated), e.g. --host-resolver-rules for wildcard test hosts.
      ...(process.env.E2E_CHROME_ARGS ? process.env.E2E_CHROME_ARGS.split(';;').filter(Boolean) : []),
    ],
    ignoreHTTPSErrors: cfg.ignoreHttps,
  });
}

async function newPage(browser) {
  const page = await browser.newPage();
  page.setDefaultNavigationTimeout(cfg.navTimeout);
  page.setDefaultTimeout(cfg.navTimeout);
  // Auto-resolve native dialogs. An unhandled alert/confirm/beforeunload blocks
  // Puppeteer indefinitely (every subsequent page op waits on the modal) — the
  // classic "journey hangs for hours" failure. Accept so navigations/beforeunload
  // proceed; a genuine unexpected dialog is still surfaced in the console log.
  page.on('dialog', async (d) => {
    try { console.log(`[e2e] auto-accepted ${d.type()} dialog: ${(d.message() || '').slice(0, 120)}`); } catch (_e) { /* noop */ }
    await d.accept().catch(() => d.dismiss().catch(() => {}));
  });
  await page.setViewport({ width: 420, height: 900 });
  return page;
}

async function shot(page, name) {
  const file = path.join(SCREENSHOT_DIR, `${name}.png`);
  await page.screenshot({ path: file, fullPage: true }).catch(() => {});
  return file;
}

/**
 * Clear the service worker + caches for the current origin. The staff PWA
 * registers a SW whose stale cache is the documented source of a flaky
 * form.submit() login (memory staff-pwa-browser-verify).
 */
async function clearServiceWorkers(page) {
  await page.evaluate(async () => {
    if (window.caches) {
      const keys = await caches.keys();
      await Promise.all(keys.map((k) => caches.delete(k)));
    }
    if (navigator.serviceWorker) {
      const regs = await navigator.serviceWorker.getRegistrations();
      await Promise.all(regs.map((r) => r.unregister()));
    }
  }).catch(() => {});
}

module.exports = { cfg, log, sleep, launch, newPage, shot, clearServiceWorkers, SCREENSHOT_DIR };
