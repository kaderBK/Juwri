// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P1-12 item 9 — licence states in the admin (valid / grace banner / expired → Community / none → Community) and
 * the "Licensed to" footer. Opt-in with E2E_RUN_P112_B=1.
 *
 * Only juwri's own development licence is used, through the documented dev tool
 * (the private dev_licence.php fixture tool): the running site keeps its real dev licence ("valid"); for the other
 * states the tool's `fixture` command signs a short-lived / already-expired DEV licence into a temp file, and a second
 * throwaway PHP server (same code, same database) is started with JUWRI_LICENCE_PATH pointing at it. The production
 * key and tools/licence/sign.php are never touched. A dev licence works only where APP_ENV is local/testing.
 *
 * Journey: operator (superadmin) opens the admin → sees the banner/footer for the licence state → an Enterprise-only admin
 * page (the app-review queue) is reachable only while the licence is valid or in grace → expired falls back to Community.
 * (No customer / per-store data here: licence state is instance-wide, so no IDOR leg.)
 *
 * Env: E2E_ADMIN_EMAIL / E2E_ADMIN_PASS (the E2eAdminSeeder superadmin), E2E_LICENCE_PORT (first of 3 ports, default 8191).
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const L = require('../lib/lane-b');
const { newPage } = require('../lib/harness');

const EE_ONLY_ADMIN_PATH = '/admin/app-reviews'; // AppStore ee slot — 404 without an active licence

async function inspect(page, base) {
  await page.goto(`${base}/admin/dashboard`, { waitUntil: 'networkidle2' });
  const dash = await page.evaluate(() => ({
    notices: [...document.querySelectorAll('[data-licence-notice]')].map((e) => e.textContent.replace(/\s+/g, ' ').trim()),
    licensedTo: [...document.querySelectorAll('[data-licensed-to]')].map((e) => e.textContent.replace(/\s+/g, ' ').trim()),
    path: location.pathname,
  }));
  const ee = await L.status(page, `${base}${EE_ONLY_ADMIN_PATH}`);
  return { ...dash, eeStatus: ee.status };
}

module.exports = async function licenceStates({ browser, log }) {
  const email = process.env.E2E_ADMIN_EMAIL;
  const pass = process.env.E2E_ADMIN_PASS;
  if (!email || !pass) return L.skip(log, 'licence-states', 'E2E_ADMIN_EMAIL / E2E_ADMIN_PASS not set (run E2eAdminSeeder)');

  const base = L.baseUrl();
  const check = L.checker('licence-states');

  // --- state 1: the site as it runs (its own dev licence) --------------------------------------------------------
  let first = L.freshPage(browser, newPage);
  const a = await first;
  await L.login(a.page, base, { email, pass });
  const valid = await inspect(a.page, base);
  await a.close();

  const enterprise = valid.eeStatus === 200 || valid.licensedTo.length > 0;
  if (!enterprise) {
    return L.skip(log, 'licence-states', `site under test runs Community (no licence line, ${EE_ONLY_ADMIN_PATH} -> ${valid.eeStatus}); licence states are ee-only`);
  }
  if (!L.eePresentOnDisk()) {
    return L.skip(log, 'licence-states', `the Enterprise package is absent from the app root ${L.appRoot()}; cannot start the extra licence-state servers`);
  }
  const devKey = path.join(os.homedir(), '.juwri/dev-licence/dev.secret');
  if (!fs.existsSync(devKey)) return L.skip(log, 'licence-states', 'no dev licence key on this machine (~/.juwri/dev-licence/dev.secret)');

  check.ok(/^Licensed to JUWRI LTD development · DEV-/.test(valid.licensedTo[0] || ''), `valid: footer reads "Licensed to <licensee> · <id>" (${valid.licensedTo[0]})`);
  check.ok(valid.notices.some((n) => /Development licence — not for production/.test(n)), 'valid: dev-licence notice shown');
  check.ok(!valid.notices.some((n) => /expired/i.test(n)), 'valid: no grace/expiry banner');
  check.eq(valid.eeStatus, 200, `valid: Enterprise admin page ${EE_ONLY_ADMIN_PATH} reachable`);

  // --- states 2..4: second servers with fixture licences --------------------------------------------------------
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'juwri-e2e-licence-'));
  const tool = path.join(L.REPO, 'rev', 'juwri-phase1', 'tools', 'p113', 'dev_licence.php') /* private tooling; a Community checkout skips this journey before here */;
  const states = [
    { name: 'grace', days: -3, file: path.join(tmp, 'grace.licence') },
    { name: 'expired', days: -30, file: path.join(tmp, 'expired.licence') },
    { name: 'none', days: null, file: path.join(tmp, 'absent.licence') }, // never written → no licence file
  ];
  const firstPort = parseInt(process.env.E2E_LICENCE_PORT || '8191', 10);
  const servers = [];
  try {
    for (const s of states) {
      if (s.days !== null) execFileSync(L.phpBin(), [tool, 'fixture', String(s.days), s.file], { stdio: 'pipe' });
    }
    for (const [i, s] of states.entries()) {
      servers.push(await L.startPhpServer(firstPort + i, { JUWRI_LICENCE_PATH: s.file }));
    }
    const seen = {};
    for (const [i, s] of states.entries()) {
      const p = await L.freshPage(browser, newPage);
      try {
        await L.login(p.page, servers[i].url, { email, pass });
        seen[s.name] = await inspect(p.page, servers[i].url);
      } finally { await p.close(); }
    }

    const g = seen.grace;
    check.ok(g.notices.some((n) => /expired on \d{4}-\d{2}-\d{2}.*Enterprise features stop on \d{4}-\d{2}-\d{2}/.test(n)), `grace: banner names the expiry and the stop date (${g.notices.join(' / ')})`);
    check.ok(g.licensedTo.length === 1, 'grace: "Licensed to" footer still shown');
    check.eq(g.eeStatus, 200, 'grace: Enterprise admin page still reachable (14-day grace)');

    for (const name of ['expired', 'none']) {
      const x = seen[name];
      check.eq(x.path, '/admin/dashboard', `${name}: admin dashboard still opens (falls back to Community)`);
      check.eq(x.notices.length, 0, `${name}: no grace/dev banner (${x.notices.join(' / ')})`);
      check.eq(x.licensedTo.length, 0, `${name}: no "Licensed to" footer`);
      check.eq(x.eeStatus, 404, `${name}: Enterprise admin page gone (Community)`);
    }
  } finally {
    for (const s of servers) s.stop();
    fs.rmSync(tmp, { recursive: true, force: true });
  }
  check.done(log);
};
