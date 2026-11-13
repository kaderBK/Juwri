// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Role/page production smoke.
 *
 * The seeded owner and customer roles run by default. Staff, reseller, and
 * admin are added only when their role-specific credentials are provided, so
 * the runner never reports an owner session as coverage for a privileged role.
 */

function csv(value, fallback) {
  return (value || fallback)
    .split(',')
    .map((path) => path.trim())
    .filter(Boolean)
    .map((path) => path.startsWith('/') ? path : `/${path}`);
}

async function resetBrowserSession(page) {
  const cookies = await page.cookies().catch(() => []);
  if (cookies.length) await page.deleteCookie(...cookies);
  await page.evaluate(async () => {
    localStorage.clear();
    sessionStorage.clear();
    if (window.caches) {
      const keys = await caches.keys();
      await Promise.all(keys.map((key) => caches.delete(key)));
    }
    if (navigator.serviceWorker) {
      const registrations = await navigator.serviceWorker.getRegistrations();
      await Promise.all(registrations.map((registration) => registration.unregister()));
    }
  }).catch(() => {});
}

async function submitWebLogin(page, cfg, role) {
  await resetBrowserSession(page);
  await page.goto(`${cfg.baseUrl}${role.loginPath}`, { waitUntil: 'domcontentloaded' });
  const usernameSelector = role.loginPath === '/staff/login'
    ? '#email, input[name="email"]'
    : '#username, input[name="username"]';
  await page.waitForSelector(usernameSelector, { timeout: cfg.navTimeout });
  await page.type(usernameSelector, role.username);
  await page.type('#password, input[name="password"]', role.password);
  await Promise.all([
    page.click('button[type="submit"], input[type="submit"]'),
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeout }).catch(() => {}),
  ]);

  const landed = new URL(page.url()).pathname;
  if (landed === '/login' || landed === '/staff/login') {
    throw new Error(`${role.name}: credentials were rejected (still on ${landed})`);
  }
}

async function assertPage(page, cfg, role, path, serverErrors) {
  const response = await page.goto(`${cfg.baseUrl}${path}`, { waitUntil: 'domcontentloaded' });
  const status = response ? response.status() : 0;
  const landed = new URL(page.url()).pathname;
  const body = await page.evaluate(() => (document.body?.innerText || '').slice(0, 5000));

  if (status >= 500 || serverErrors.length) {
    throw new Error(`${role}: ${path} returned a server error (${status || 'no response'}; ${serverErrors.join(', ')})`);
  }
  if (landed === '/login' || landed === '/staff/login') {
    throw new Error(`${role}: ${path} redirected back to ${landed}`);
  }
  if (/Whoops, looks like something went wrong|Illuminate\\|Fatal error|Server Error/i.test(body)) {
    throw new Error(`${role}: ${path} rendered an exception page`);
  }
}

async function smokeWebRole(page, cfg, log, shot, role, serverErrors) {
  await submitWebLogin(page, cfg, role);
  for (const path of role.paths) {
    serverErrors.length = 0;
    await assertPage(page, cfg, role.name, path, serverErrors);
    log(`role-pages: ${role.name} ${path} OK`);
  }
  await shot(page, `role-pages-${role.name}`);
}

async function smokeCustomer(page, cfg, log, shot, serverErrors) {
  await resetBrowserSession(page);
  const storePath = `/${cfg.storeSlug}`;
  await page.goto(`${cfg.baseUrl}${storePath}`, { waitUntil: 'domcontentloaded' });

  const attemptLogin = () => page.evaluate(async ({ email, password }) => {
    const csrf = window.CRAVE?.csrf || document.querySelector('meta[name="csrf-token"]')?.content;
    const storeId = window.CRAVE?.storeId;
    const endpoint = window.CRAVE?.endpoints?.login;
    if (!csrf || !storeId || !endpoint) {
      return { ok: false, status: 0, message: 'storefront did not expose its customer-auth configuration' };
    }

    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf,
      },
      body: JSON.stringify({ email, password, store_id: storeId }),
    });
    return { ok: response.ok, status: response.status, body: await response.json().catch(() => ({})) };
  }, { email: cfg.dinerEmail, password: cfg.dinerPass });

  let login = await attemptLogin();
  if (login.status === 419) {
    // A previously registered storefront service worker may have supplied HTML
    // carrying its old session's CSRF token. One network reload proves the
    // browser and server agree on a fresh session before we judge auth.
    await page.reload({ waitUntil: 'domcontentloaded' });
    login = await attemptLogin();
  }

  if (!login.ok) {
    throw new Error(`customer: session login failed (${login.status}: ${JSON.stringify(login.body || login.message)})`);
  }

  serverErrors.length = 0;
  const accountPath = `/${cfg.storeSlug}/customer/account`;
  const sessionProbe = await page.evaluate(async (path) => {
    const response = await fetch(path, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    });
    return {
      status: response.status,
      path: new URL(response.url).pathname,
      cookies: document.cookie.split(';').map((cookie) => cookie.trim().split('=')[0]).filter(Boolean),
    };
  }, accountPath);
  if (sessionProbe.path !== accountPath) {
    throw new Error(`customer: authenticated session probe redirected to ${sessionProbe.path} (${sessionProbe.status}; cookies=${sessionProbe.cookies.join(',') || 'none'})`);
  }
  await assertPage(page, cfg, 'customer', accountPath, serverErrors);
  const landed = new URL(page.url()).pathname;
  if (landed !== accountPath) {
    throw new Error(`customer: account redirected to ${landed}`);
  }
  await page.waitForSelector('[data-view="account"].is-active', {
    timeout: cfg.navTimeout,
  });
  await shot(page, 'role-pages-customer');
  log('role-pages: customer account OK');

  // The account/profile split moved the identity controls (name, logout) off
  // the order-history page onto /customer/profile — assert the authenticated
  // profile page still renders its logout control.
  serverErrors.length = 0;
  const profilePath = `/${cfg.storeSlug}/customer/profile`;
  await assertPage(page, cfg, 'customer', profilePath, serverErrors);
  await page.waitForSelector('[data-view="profile"].is-active #cvLogoutBtn', {
    timeout: cfg.navTimeout,
  });
  await shot(page, 'role-pages-customer-profile');
  log('role-pages: customer profile OK');
}

async function withIsolatedRolePage(parentPage, cfg, callback) {
  const context = await parentPage.browser().createBrowserContext();
  const isolatedPage = await context.newPage();
  isolatedPage.setDefaultNavigationTimeout(cfg.navTimeout);
  isolatedPage.setDefaultTimeout(cfg.navTimeout);
  await isolatedPage.setViewport({ width: 420, height: 900 });

  const serverErrors = [];
  isolatedPage.on('response', (response) => {
    if (response.status() >= 500 && response.url().startsWith(cfg.baseUrl)) {
      serverErrors.push(`${response.status()} ${new URL(response.url()).pathname}`);
    }
  });

  try {
    return await callback(isolatedPage, serverErrors);
  } finally {
    await context.close();
  }
}

module.exports = async function rolePagesSmoke({ page, cfg, log, shot }) {
  const roles = [{
    name: 'owner',
    loginPath: '/login',
    username: cfg.ownerEmail,
    password: cfg.ownerPass,
    paths: csv(process.env.E2E_OWNER_PATHS, '/dashboard,/stores,/orders,/customers,/settings'),
  }];

  if (process.env.E2E_STAFF_USER && process.env.E2E_STAFF_PASS) {
    roles.push({
      name: 'staff',
      loginPath: '/staff/login',
      username: process.env.E2E_STAFF_USER,
      password: process.env.E2E_STAFF_PASS,
      // The staff PWA is a single-page application. Orders, Kitchen, Tasks,
      // and the other workspaces are in-app tabs, not nested URL routes.
      paths: csv(process.env.E2E_STAFF_PATHS, '/staff-app'),
    });
  }

  if (process.env.E2E_RESELLER_EMAIL && process.env.E2E_RESELLER_PASS) {
    roles.push({
      name: 'reseller',
      loginPath: '/login',
      username: process.env.E2E_RESELLER_EMAIL,
      password: process.env.E2E_RESELLER_PASS,
      paths: csv(process.env.E2E_RESELLER_PATHS, '/reseller,/reseller/customers,/reseller/security'),
    });
  }

  if (process.env.E2E_ADMIN_EMAIL && process.env.E2E_ADMIN_PASS) {
    const adminPrefix = (process.env.E2E_ADMIN_PREFIX || '/admin').replace(/\/$/, '');
    roles.push({
      name: 'admin',
      loginPath: '/login',
      username: process.env.E2E_ADMIN_EMAIL,
      password: process.env.E2E_ADMIN_PASS,
      paths: csv(process.env.E2E_ADMIN_PATHS, `${adminPrefix}/dashboard,${adminPrefix}/orders,${adminPrefix}/settings`),
    });
  }

  for (const role of roles) {
    await withIsolatedRolePage(page, cfg, (rolePage, serverErrors) =>
      smokeWebRole(rolePage, cfg, log, shot, role, serverErrors));
  }
  await withIsolatedRolePage(page, cfg, (customerPage, serverErrors) =>
    smokeCustomer(customerPage, cfg, log, shot, serverErrors));

  const optional = ['staff', 'reseller', 'admin'];
  const executed = roles.map((role) => role.name);
  log(`role-pages: executed=${[...executed, 'customer'].join(',')} not-configured=${optional.filter((role) => !executed.includes(role)).join(',') || 'none'}`);
};
