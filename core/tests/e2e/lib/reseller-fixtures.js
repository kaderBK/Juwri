'use strict';

/**
 * Platform E2E v2 — Lane-C reseller provisioning helpers (setup-only).
 *
 * The v2 reseller/security journeys (C1/C2/C6/C9) need TWO independent, active
 * resellers each with a known-password owner and a scoped customer, reachable on
 * a RESOLVABLE lane subdomain (Herd serves `*.e2e-c.test`, TrustHosts trusts
 * every subdomain of the app URL, Reseller::resolveForRequest matches
 * custom_domain by request host) — the same host model the PASSING v1
 * c-reseller / c-fusion journeys use (NO forbidden Host header).
 *
 * Provisioning here is db()-side SETUP, not the thing under test: the isolation /
 * RBAC / billing ASSERTIONS are driven through the real browser + HTTP layer.
 * A reseller owner is the user referenced by `resellers.owner_user_id` with
 * `active=1` (ResellerOwnerMiddleware); a reseller CUSTOMER is a user tagged with
 * `reseller_id` that is NOT the owner (PortalController scopes every customer
 * endpoint on `reseller_id` + firstOrFail → cross-reseller = 404).
 *
 * Every id is unique per run (unix + rand) so re-runs never collide and the
 * 300 s reseller:domain resolution cache is dodged by a fresh host each time.
 */

// A fixed, dev-only bcrypt hash so an inserted owner can use the real /login
// form. password_verify(OWNER_PASS, OWNER_HASH) === true (verified locally); the
// same hash the v1 c-reseller journey uses.
const OWNER_PASS = 'E2e-owner-c1-pass';
const OWNER_HASH = '$2y$10$L3VAT6Coa8OxNvrN53pvMeQHzCGK.ZRfBECbY.GmYfmhMb1g.j8zm';

let _seq = 0;
function uid() {
  _seq += 1;
  return `${Date.now()}${String(_seq).padStart(2, '0')}${Math.floor(Math.random() * 1000)}`;
}

/**
 * Provision one active reseller on a resolvable lane subdomain, returning the
 * ids + credentials the journey drives.
 *
 * @param {object} P the platform lib (for P.db / P.cfg)
 * @param {object} o
 * @param {string} o.tag short slug used in emails/brand (e.g. "a" / "b")
 * @returns {{resellerId:number, ownerId:number, ownerEmail:string, ownerPass:string,
 *            brandName:string, host:string, baseUrl:string,
 *            customerId:number, customerEmail:string}}
 */
function provisionReseller(P, { tag }) {
  const baseUrlObj = new URL(P.cfg.baseUrl);
  const baseHost = baseUrlObj.hostname;             // e2e-c.test (the stored custom domain never carries a port)
  const n = uid();
  const ownerEmail = `e2e-c-res-${tag}-${n}@local.invalid`;
  const brandName = `براند ${tag}-${n}`;              // real Arabic brand
  const host = `res${tag}-${n}.${baseHost}`;

  P.db(
    "INSERT INTO {p}users (email, username, name, password, user_type, status, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, 'user', 1, NOW(), NOW(), NOW())",
    [ownerEmail, `resown${tag}${n}`, `Reseller ${tag} Owner`, OWNER_HASH],
  );
  const ownerId = Number(P.db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [ownerEmail])[0][0]);

  P.db(
    "INSERT INTO {p}resellers (owner_user_id, brand_name, custom_domain, active, allow_signup, created_at, updated_at) VALUES (?, ?, ?, 1, 1, NOW(), NOW())",
    [ownerId, brandName, host],
  );
  const resellerId = Number(P.db('SELECT id FROM {p}resellers WHERE custom_domain = ? LIMIT 1', [host])[0][0]);

  // Provisioning stamps the owner with their own reseller_id (matches prod).
  P.db('UPDATE {p}users SET reseller_id = ? WHERE id = ?', [resellerId, ownerId]);

  // One scoped CUSTOMER (sub-merchant) under this reseller — a distinct user.
  const customerEmail = `e2e-c-rescust-${tag}-${n}@local.invalid`;
  P.db(
    "INSERT INTO {p}users (email, username, name, password, user_type, status, reseller_id, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, 'user', 1, ?, NOW(), NOW(), NOW())",
    [customerEmail, `rescust${tag}${n}`, `Reseller ${tag} Customer`, OWNER_HASH, resellerId],
  );
  const customerId = Number(P.db('SELECT id FROM {p}users WHERE email = ? LIMIT 1', [customerEmail])[0][0]);

  giveCustomerAStore(P, customerId, n);

  return {
    resellerId,
    ownerId,
    ownerEmail,
    ownerPass: OWNER_PASS,
    brandName,
    host,
    baseUrl: `${baseUrlObj.protocol}//${host}${baseUrlObj.port ? ':' + baseUrlObj.port : ''}`, // the scheme of the site under test (http on the runner)
    customerId,
    customerEmail,
  };
}

/**
 * Reseller rework v2: a support login goes into ONE store of the customer and needs that store's active consent
 * (SupportAccess::grant). Best effort: journeys that need the store say so themselves.
 */
function giveCustomerAStore(P, customerId, n) {
  if (typeof P.siteCoreDir !== 'function') return;
  try {
    require('child_process').execFileSync('php', [process.env.E2E_LICENSED_ARTISAN || 'artisan', 'tinker', '--execute',
      `$c = \\Juwri\\Core\\Models\\User::findOrFail(${customerId}); $s = \\Juwri\\Core\\Models\\Store::create(['user_id' => $c->id, 'store_type' => 'restaurant', 'slug' => 'e2e-rc-${n}', 'title' => 'E2E reseller customer store', 'main_image' => 'default.png', 'cover_image' => 'default.png']); $k = getenv('E2E_SUPPORT_ACCESS_CLASS'); if ($k && class_exists($k)) { $k::grant($s, $c); }`],
    { cwd: P.siteCoreDir(), stdio: 'ignore', timeout: 120000, env: process.env });
  } catch (_e) { /* see above */ }
}

module.exports = { OWNER_PASS, OWNER_HASH, provisionReseller, giveCustomerAStore };
