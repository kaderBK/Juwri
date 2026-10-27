---
title: Reseller and white-label overview
audience: reseller
group: Start here
updated: 2026-08-06
verified_against: e09d26f8
---

# Reseller and white-label overview

The reseller portal lets a partner run the platform under their own brand. A reseller has their own logo, colours, support contacts, optional custom domain, their own customers (restaurants), and their own pricing, so end users never see the underlying "juwri" brand. This page explains what the portal offers today, how resellers are set up, and which pieces are shipped versus still planned.

Two roles interact with the feature:

- A platform admin creates and configures resellers, approves custom domains, and views platform-wide reseller reporting.
- A reseller owner signs in to their own portal to manage customers, branding, plans, invoices, and their domain.

## How a reseller is created

Resellers are created by a platform admin, not self-serve. In the admin panel under Resellers (`admin.resellers.index`), an admin picks an existing user to be the reseller owner and sets the brand fields:

- `brand_name` (required), `brand_logo`, `primary_color`
- `support_email`, `support_url`
- `custom_domain` (optional, must be unique)

On save (`admin.resellers.store`) the platform creates the `resellers` row and stamps `reseller_id` on the owner user. An admin can later edit these fields (`admin.resellers.update`), toggle the reseller `active`, or remove the reseller (`admin.resellers.destroy`), which unlinks all of its customers. Each owner user can own at most one reseller.

## Brand resolution

The active brand is resolved per HTTP request by hostname. `ResolveResellerBranding` middleware calls `Reseller::resolveForRequest()`, which matches `request()->getHost()` against an active reseller's `custom_domain` (cached for five minutes). When a reseller matches, its name, logo, colour, and support links are shared with every view as `activeBrand`, and the `brand_title()` / `brand_logo()` helpers return the reseller's values instead of the platform defaults. On the platform's own domain (no match) everything falls back to the configured site title and logo.

If the `resellers` table does not exist yet (a fresh, pre-migration install), resolution degrades to the platform default rather than erroring.

## The reseller portal

A reseller owner reaches their portal at `/reseller` (route `reseller.portal`). Access is gated by `ResellerOwnerMiddleware`, which requires the signed-in user to own an active reseller; the resolved reseller is bound server-side as `portalReseller` and every portal query scopes to it. Ids never come from the client, so a reseller can only ever see or act on its own customers.

The shipped portal is a full multi-page panel (the reseller v2 rebuild — the earlier single-page Alpine dashboard is retired). Server-rendered pages live under `core/resources/views/reseller/pages/` and are reachable from a grouped sidebar:

- **Dashboard** — Overview (KPIs, wallet balance, tasks, recent charges, setup checklist), Analytics, Portfolio (stats), and the Getting started onboarding checklist (see the [onboarding quickstart](onboarding.md)).
- **Customers** — searchable, paginated Restaurants list with per-row and bulk suspend/unsuspend, a customer drill-down (status, subscription, recent transactions), CSV export, Seat usage, and Announcements scoped to the reseller's customers.
- **Billing** — Invoices (wholesale), Wallet, Subscriptions, Coupons, and Referrals (capability-gated).
- **Fusion** — the reseller's own marketplace group (Overview, Store approvals, Home curation, Branding, Settings, Analytics), dark behind the `own_fusion` entitlement (see [own-marketplace](own-marketplace.md)).
- **Website & Brand** — Plans & Pricing, Pricing Page, Branding, Landing Page (or the full Site & Pages builder plus Blog when the page-builder capability is on), Domain, Payments / BYOK (capability-gated), Email & SMTP (sender plus per-key templates), Team & Roles, Settings (2FA and API token), and Audit log.

Most portal routes content-negotiate: the same endpoint renders the HTML page for a browser and returns JSON when requested with `Accept: application/json`, so the endpoint list below doubles as the portal's page map:

- `reseller.stats` — customers, seats, included seats, billing status, next-invoice estimate and date, ARPU, and 30-day churn rate.
- `reseller.onboarding` — first-run checklist (brand set, two-factor, domain, gateway, plans, first customer) with a done/total/percent rollup.
- `reseller.seatAlert` — seat count versus included allotment.
- `reseller.customers.store` / `suspend` / `unsuspend` / `bulkStatus` / `delete` — customer lifecycle (creating customers is blocked while the reseller's own billing is suspended).
- `reseller.customers.impersonate` — sign in as a customer with a recorded reason (see [impersonation](impersonation.md)).
- `reseller.branding.update` — edit own brand fields (the custom domain is deliberately not editable here; it stays admin-approved).
- `reseller.smtp.update` — set the reseller's own SMTP for white-label mail (password encrypted at write).
- `reseller.plans` / `plans.store` / `plans.delete` — manage reseller plans.
- `reseller.checkout.charge` — charge a customer for a plan (see below).
- `reseller.invoices` / `invoices.pay` — wholesale invoices the reseller owes the platform.
- `reseller.invoices.payWallet` — pay a wholesale invoice from the reseller wallet balance.
- `reseller.wallet` / `wallet.topup` / `reseller.customers.fund` — wallet balance, top up, and fund a merchant.
- `reseller.pricingPage` / `pricingPage.save` — read and edit the public pricing page.
- `reseller.emailTemplates` / `emailTemplates.save` — manage white-label email templates.
- `reseller.security` / `security.2fa.*` — portal-level 2FA enrollment and challenge.
- `reseller.domain.request` / `domain.status` / `domain.verify` — custom-domain flow.
- `reseller.apiToken` — regenerate the API token.

All of these areas — branding, SMTP, plans, invoices, wallet, checkout, domain, and the rest — have rendered portal pages with forms; nothing requires calling endpoints directly or going through the platform admin (the admin panel can still edit the same fields).

## Customers and isolation

A reseller's customers are ordinary platform users tagged with `reseller_id`. New customers are created either by the reseller from the portal (`storeCustomer`) or via public self-serve signup on the reseller's storefront (`reseller.signup`, `POST /reseller-signup/{resellerId}`), which is only allowed when the reseller has `allow_signup` enabled. In both cases `reseller_id` is hard-set from the resolved reseller and never taken from client input.

Every portal query filters by `reseller_id`, and single-record actions use `where('reseller_id', ...)->firstOrFail()`, so a reseller cannot reach another reseller's customers. Portal actions that change data are written to `ResellerAuditLog`.

## Pricing, seats, and wholesale billing

The platform bills the reseller (the wholesale side) on a seat model:

- A seat is one active restaurant (`Post`) owned by one of the reseller's non-suspended customers.
- Each reseller has a `base_fee`, an `included_seats` allotment, and a `seat_price` for seats beyond the allotment. The next invoice estimate is `base_fee + max(0, seats - included_seats) * seat_price`.

`ResellerBillingService` generates monthly seat invoices, marks them paid, and runs a dunning ladder on overdue invoices: `pending` moves to `past_due` after a grace window (default 3 days) and to `suspended` after a longer window (default 10 days), both configurable via settings. Dunning transitions email the reseller owner (platform-branded, since the platform is billing them). A suspended reseller cannot add new customers until the outstanding invoice is settled. Invoicing and renewal run on schedule (`reseller:renew` daily); a reseller pays a wholesale invoice from the portal via `invoices.pay`.

`ResellerBillingService` also includes proration (credit for unused time on a mid-cycle switch) and VAT-inclusive totals.

## Selling plans to customers

A reseller defines its own plans (`ResellerPlan`), each mapping to a platform `Plan` that supplies the actual limits. When a reseller charges a customer (`checkout.charge`) or a customer self-signs-up on a chosen plan, the platform assigns the mapped platform plan to the customer and records a `Transaction` tagged with the reseller context.

Reseller-to-customer charges are meant to run through the reseller's own payment gateway (bring-your-own-keys via `ResellerSubscriptionService` and the Stripe/PayPal drivers), never through platform keys. Until a reseller's live gateway keys are wired, `Reseller::gatewayConfigured()` is false and checkout records a test-mode transaction rather than taking a real payment. Wholesale invoice payment (`invoices.pay`) is likewise a test-mode stub pending the live gateway.

## Custom domains

A reseller requests a domain from the portal (`domain.request`), verifies DNS (`domain.verify`), and checks status (`domain.status`) through `ResellerDomainService`. Activation is admin-only: a platform admin approves a verified request (`admin.resellers.approveDomain`), which sets `custom_domain` and makes brand resolution start matching that host. Resellers cannot self-activate a domain.

## Reseller API token

From the portal Settings tab a reseller can generate an API token (an `rsk_`-prefixed key stored hashed on the reseller row). The token is consumed by `ResellerApiAuth` middleware, which resolves the reseller from a `Bearer` token by SHA-256 hash lookup (with automatic upgrade of any legacy plaintext tokens). Three read-only endpoints live at `/api/v1/reseller`: stats, customers, and invoices, throttled at 60 requests per minute. See [Reseller API](api.md).

## Platform-side reporting

Admins get a platform-wide roll-up via `admin.resellers.report`, backed by `ResellerReportService`: reseller counts (total, active, suspended), total seats, estimated wholesale MRR (billable resellers only), realised paid-invoice revenue, ARPU (MRR divided by active resellers), 30-day churn rate, and a per-reseller breakdown. The portal `stats` endpoint also computes per-reseller ARPU and churn for the reseller's own customers.

## What is built versus planned

Built and working today:

- Admin create/edit/delete of resellers and brand fields.
- Host-based brand resolution (name, logo, colour, support links) across app views.
- The scoped reseller portal — a full multi-page panel (dashboard, customers, billing, Fusion, website & brand groups) with strict per-reseller isolation and an audit log.
- Customer management: create, list/search, suspend/unsuspend, bulk status, drill-down, CSV export, GDPR delete.
- Reseller plans mapped to platform plans; customer self-serve signup gated by `allow_signup`.
- Seat-based wholesale billing: invoice generation, dunning ladder, past-due/suspended emails, proration and VAT helpers.
- White-label SMTP and branding endpoints; onboarding checklist and seat-alert endpoints.
- Custom-domain request/verify with admin-only activation.
- Platform reseller reporting: MRR, seats, active/suspended counts, paid-invoice revenue, ARPU, and 30-day churn rate.
- Reseller-scoped impersonation with reason, audit log, and safe exit (see [impersonation](impersonation.md)).
- Portal-level two-factor authentication: enroll, confirm, challenge, recovery codes (see [security](security.md)).
- `ResellerApiAuth` middleware with SHA-256 hashed token lookup, automatic legacy-token upgrade, and three read-only `/api/v1/reseller` endpoints (see [api](api.md)).
- Pricing page builder: a portal editor for headline, subline, and per-plan feature lines, served on reseller hosts.
- Email template editor: per-reseller templates with rich-text body and enable/disable per key (see [branding](branding.md)).
- Churn and ARPU metrics in `ResellerReportService` and in the portal stats endpoint.
- Reseller wallet: top up, pay platform invoices from balance, fund merchants, and automatic commission (see [wallet](wallet.md) and [commission](commission.md)).

Planned, not yet available:

- Live payment-gateway keys for reseller checkout and wholesale invoice payment (both run in test mode until keys ship).

## Related pages

- [Plans and billing](../users/plans-billing.md) — the platform plans that reseller plans map to.
- [Custom domain](../users/custom-domain.md) — customer-facing domain setup.
- [API tokens](../users/api-tokens.md) — restaurant-level API access (distinct from the reseller token).
