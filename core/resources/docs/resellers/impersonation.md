---
title: Portal-side impersonation
audience: reseller
group: Advanced features
updated: 2026-08-06
verified_against: e09d26f8
---

# Portal-side impersonation

A reseller owner can sign in as one of their own customers from the portal to
troubleshoot or configure a merchant's account. This is separate from admin-level
impersonation — it is scoped to the reseller's own customers and gated by
`ResellerOwnerMiddleware`.

## Starting impersonation

`POST /reseller/customers/{id}/impersonate` (route
`reseller.customers.impersonate`) on `PortalController@impersonate`.

Requirements:

- A `reason` string (required, max 255 characters) explaining why.
- The target customer must belong to this reseller (`reseller_id` check with
  `firstOrFail`).
- Nested impersonation is blocked: if the owner is already impersonating
  (session key `impersonating` is set), the request returns `409`.

On success the controller:

1. Writes an audit-log entry for the impersonation start.
2. Logs out the reseller owner.
3. Logs in as the target customer.
4. Stamps the session with an `impersonating` context containing:
   `customer_id`, `customer_name`, `reseller_id`, `reseller_owner_id`,
   `reseller_brand`, `reason`, and `started_at`.

## During the session: banner, expiry, and guard

While the `impersonating` session context is set, every web request passes
through the `ImpersonationGuard` middleware
(`core/app/Http/Middleware/ImpersonationGuard.php`), which enforces three
things:

- **A persistent banner.** The dashboard layout renders a sticky warning bar —
  "Support session — viewing *customer* as *brand*" — with a one-click **Exit
  impersonation** button and a summary of what is disabled. The guard shares
  the impersonation context with all views to drive it.
- **A 30-minute hard expiry**, server-enforced. Past the TTL the session is
  ended automatically (audit action `customer.impersonate.expire`), the owner
  is re-authenticated, and the browser is redirected back to the portal (JSON
  requests get a `419` with a `redirect` field instead).
- **A guard list of sensitive actions**, below.

## Blocked actions (403) — the deny list

Sensitive account actions return **403** ("This action is disabled during an
impersonation session.") while impersonating. Expect these — they are not
errors. Since the H2 hardening the list matches **whole route-name families**
(deny-by-family, prefix-matched), not just individual routes, so a new route
added to a guarded namespace later is blocked automatically.

Blocked route families:

| Family | What it covers |
| --- | --- |
| `transfer.*` | Store/branch ownership transfer: offer, accept, decline, cancel, move |
| `api.token.*` | Platform API tokens (create, revoke, …) |
| `api.webhook.*` | Webhook endpoints: create, delete, toggle, test, redeliver |
| `storefront.token.*` | Per-restaurant public storefront API tokens |

Individually blocked routes, on top of the families:

- `settings.editProfile` (email + password change) and `settings.editBilling`
  (payment methods)
- `subscription.cancel`, `checkout.index`, `payment.pay`
- every `2fa.*` management route (show, enable, confirm, disable, recovery,
  recovery regenerate)
- `reseller.customers.impersonate` (nested impersonation)

One read-only exception: `transfer.index` still renders, so the transfer page
can *show* why its controls are disabled instead of returning a bare 403. The
decision logic is a single shared method (`ImpersonationGuard::blocks()`)
consulted by both the middleware and the UI, so on-screen controls for guarded
actions render disabled with an explanation rather than failing on submit.

### Blocked attempts are audited

A blocked attempt is itself recorded, attributed to the **reseller owner** who
is impersonating (not the customer whose session it superficially is):

- a `ResellerAuditLog` row with action `customer.impersonate.blocked` (visible
  on the portal's own Audit log page), and
- a platform `ActivityLog` row with action `impersonation.blocked`,

both carrying the route name, method, path, and customer id. Audit-log failures
never suppress the 403.

## Exiting impersonation

`POST /reseller/impersonate/exit` (route `reseller.impersonate.exit`) on
`ImpersonationController@exit` — the banner's **Exit impersonation** button
posts here. This route uses the `auth` middleware only (not the reseller-owner
gate, since the authenticated user during impersonation is the customer, not
the owner).

On exit the controller:

1. Reads the `impersonating` session context. If absent, redirects to `/`.
2. Records an audit-log entry (`customer.impersonate.exit`) with `duration_s`.
3. Forgets the `impersonating` session key.
4. Logs out the customer session.
5. Re-authenticates the original reseller owner (from `reseller_owner_id`).
6. Redirects to `route('reseller.portal')`.

## Differences from admin impersonation

- Portal impersonation is scoped: the owner can only impersonate their own
  customers, not any user on the platform.
- A reason is required and recorded in the reseller audit log.
- Exit re-authenticates the owner automatically rather than relying on a
  separate session stack.

## Related pages

- [Overview](overview.md) — built-versus-planned summary.
- [Tenants](tenants.md) — admin-level tenant management and impersonation.
