// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * Platform E2E v2 — B1: Staff RBAC matrix (default-deny then widen).
 *
 * The contract under test is App\Http\Middleware\EnsureStaffDashboardPermission:
 * every staff-dashboard route maps to a permission key (ROUTE_PERMISSION_MAP);
 * an UNMAPPED route default-denies (owner-only); a mapped route is allowed only
 * when the staff member's role carries that permission (StaffMember::
 * ROLE_PERMISSIONS). We prove BOTH directions for every seeded staff role:
 *
 *   - DENY (security-critical): every (role × route) pair the role does NOT hold
 *     the permission for must be 403 or an auth bounce — asserted strictly.
 *   - WIDEN: a role WITH the permission reaches the always-available core routes
 *     (2xx) — asserted strictly on the core set (orders/menu/customers/analytics/
 *     kds/coupons/dashboard), and recorded (not asserted) for the feature-gated
 *     hotel/biolink routes so a store-level feature toggle can't flake the run.
 *   - CATCH-ALL: an unmapped owner-only route (settings, subscription) is denied
 *     for EVERY staff role incl. manager — proving default-deny, not allow-list-gap.
 *   - GUEST: an unauthenticated hit on each route is denied.
 *
 * Fixtures: E2ePlatformV2Seeder staff matrix on crave-demo  —
 *   e2e-staff-{manager,cashier,kitchen,waiter}@example.test / E2eStaff-pass.
 * Ground truth only (HTTP status + auth-bounce), no pixels. ar + (E2E_LOCALE=en).
 */

const V = require('../../lib/platform-v2');

let STAFF_STORE_ID = 0;   // crave-demo; resolved by slug when the journey runs (ids differ per database)
const STAFF_PASS = 'E2eStaff-pass';

// role => default permission set (mirror of StaffMember::ROLE_PERMISSIONS,
// pos_* omitted here because POS lives outside this dashboard middleware).
const ROLE_PERMS = {
  manager: ['orders', 'menu_edit', 'coupons', 'analytics', 'kds', 'customers', 'hotel_folio', 'hotel_requests', 'hotel_admin'],
  cashier: ['orders', 'kds'],
  kitchen: ['kds'],
  waiter:  ['orders', 'hotel_requests'],
};

const STAFF_EMAIL = (role) => `e2e-staff-${role}@example.test`;

// Representative GET route per permission key. `core` routes are always available
// on crave-demo (allow-side asserted); `feature` routes may be store-gated
// (allow-side recorded only); `unmapped` are owner-only (always deny for staff).
const ROUTES = [
  { label: 'dashboard',       url: '/dashboard',                       perm: null,            kind: 'core' },
  { label: 'orders',          url: '/orders',                          perm: 'orders',        kind: 'core' },
  { label: 'menu_edit',       url: '/stores/{store}/menu',   perm: 'menu_edit',  kind: 'core' },
  { label: 'customers',       url: '/customers',                       perm: 'customers',     kind: 'core' },
  { label: 'analytics',       url: '/analytics',                       perm: 'analytics',     kind: 'core' },
  { label: 'kds',             url: '/kds',                             perm: 'kds',           kind: 'core' },
  { label: 'coupons',         url: '/coupons',                         perm: 'coupons',       kind: 'core' },
  { label: 'hotel_folio',     url: '/hotel/stays',                     perm: 'hotel_folio',   kind: 'feature' },
  { label: 'hotel_requests',  url: '/hotel/requests',                  perm: 'hotel_requests',kind: 'feature' },
  { label: 'hotel_admin',     url: '/hotel/rooms',                     perm: 'hotel_admin',   kind: 'feature' },
  { label: 'biolink',         url: '/biolink',                         perm: 'biolink',       kind: 'feature' },
  { label: 'settings(owner)', url: '/settings',                        perm: 'UNMAPPED',      kind: 'unmapped' },
  { label: 'subscription',    url: '/subscription',                    perm: 'UNMAPPED',      kind: 'unmapped' },
];

function fmtRow(role, r, verdict) {
  return `  ${String(role).padEnd(8)} ${String(r.label).padEnd(15)} → ${String(r.status).padEnd(3)} ${r.allowed ? 'ALLOW' : (r.denied ? 'DENY ' : '???  ')}  [${verdict}]`;
}

module.exports = async function b1RbacMatrix({ page, log }) {
  STAFF_STORE_ID = V.craveStoreId();
  ROUTES.find((r) => r.label === 'menu_edit').url = `/stores/${STAFF_STORE_ID}/menu`;
  const failures = [];

  // ── 0. GUEST: every dashboard route denied (auth bounce / 403). ────────────
  // Land on the site origin first so the in-page same-origin fetches resolve
  // (a guest hit on /dashboard bounces to /login, 200, same origin).
  await V.resetBrowserSession(page);
  await page.goto(V.cfg.baseUrl + '/login', { waitUntil: 'domcontentloaded' });
  const guest = await V.rbacMatrix(page, { routes: ROUTES.map((r) => ({ url: r.url, label: r.label })) });
  log('RBAC matrix — GUEST (no session):');
  for (const r of guest.results) {
    const verdict = r.denied ? 'ok' : 'LEAK';
    log(fmtRow('guest', r, verdict));
    if (!r.denied) failures.push(`guest reached ${r.label} (${r.status}) — must be denied`);
  }

  // ── 1..N: each staff role — default-deny then widen. ───────────────────────
  for (const role of Object.keys(ROLE_PERMS)) {
    const perms = ROLE_PERMS[role];
    const login = { email: STAFF_EMAIL(role), pass: STAFF_PASS, loginPath: '/staff/login' };
    const m = await V.rbacMatrix(page, { login, routes: ROUTES.map((r) => ({ url: r.url, label: r.label })) });

    log(`RBAC matrix — role=${role} (perms: ${perms.join(',') || 'none'}):`);
    for (const r of m.results) {
      const spec = ROUTES.find((x) => x.label === r.label);
      const holds = spec.perm === null ? true : (spec.perm !== 'UNMAPPED' && perms.includes(spec.perm));
      let verdict = 'ok';

      if (spec.kind === 'unmapped') {
        // Owner-only route: staff (even manager) must be denied — proves default-deny.
        if (!r.denied) { verdict = 'LEAK'; failures.push(`${role} reached UNMAPPED ${r.label} (${r.status}) — default-deny broken`); }
      } else if (holds) {
        // Widen: role holds the permission.
        if (spec.kind === 'core') {
          if (!r.allowed) { verdict = 'BLOCKED'; failures.push(`${role} DENIED core ${r.label} (${r.status}) but holds ${spec.perm}`); }
        } else {
          // feature route — allow-side recorded only (store feature gate may 404/redirect).
          verdict = r.allowed ? 'ok(feat)' : 'feat-gated';
        }
      } else {
        // Default-deny: role lacks the permission → must be denied.
        if (!r.denied) { verdict = 'LEAK'; failures.push(`${role} reached ${r.label} (${r.status}) without ${spec.perm}`); }
      }
      log(fmtRow(role, r, verdict));
    }
  }

  if (failures.length) {
    throw new Error(`B1 RBAC matrix violations (${failures.length}):\n  - ${failures.join('\n  - ')}`);
  }
  log(`B1 RBAC matrix: ${ROUTES.length} routes × ${Object.keys(ROLE_PERMS).length} roles + guest — all default-deny/widen assertions held`);
};
