---
title: Reseller and white-label guide
audience: reseller
group: Index
updated: 2026-08-06
verified_against: e09d26f8
---

# Reseller and white-label guide

Admin and configuration guides for running the platform as a white-label
operator. A reseller runs the whole SaaS under their own brand, domain, and
pricing, with their own isolated set of customer restaurants. These pages cover
both sides of that arrangement: the platform admin who creates and configures
resellers, sets plans, tenants, gateways, and the theme marketplace, and the
reseller owner who signs into their own portal. Every route, field, and limit
below is drawn from the source, and anything specced but not yet wired is called
out explicitly. Most admin routes live under the configurable admin prefix
(`admin_url()`, `admin` by default, overridable via `APP_ADMIN`) and carry the
`admin.` route-name prefix behind the `admin` middleware.

## Start here

- [Reseller onboarding quickstart](onboarding.md) — the start-to-finish setup
  path (brand → 2FA → domain → gateway → plans → first customer), mirroring the
  portal's own Getting started checklist, with links into every detailed guide.
- [Reseller and white-label overview](overview.md) — how a reseller is created,
  brand resolution, the portal, customer isolation, wholesale billing, and a
  built-versus-planned breakdown of the whole feature.

## White-label setup

- [Branding and white-label setup](branding.md) — the brand fields a reseller
  record stores, how brand name/logo/colour and support details are applied,
  white-label email sender, and the custom-domain request/verify/approve flow.
- [Your own marketplace (reseller Fusion)](own-marketplace.md) — run your own
  food marketplace at `your-domain/fusion` listing only your stores, with
  per-tenant shoppers, home curation, branding, and analytics. Ships dark behind
  the `own_fusion` entitlement.

## Plans, tenants, and billing

- [Configuring plans and pricing](plans-config.md) — the plan list and editor,
  limits and the unlimited sentinel, the editable feature matrix, coupons,
  add-ons, and archive/duplicate/pause.
- [Managing tenants](tenants.md) — finding, editing, and impersonating tenant
  accounts, changing subscriptions, and wallet adjustments.
- [Wallet and coupons](wallet.md) — the reseller wallet (top up, settle platform
  invoices, fund merchants, commission) and reseller-issued plan coupons.
- [Payment gateway setup](payment-gateways.md) — enabling and configuring
  gateways, the platform-keys-versus-BYOK layers, webhook/IPN endpoints to
  register, and test-versus-live selection.

## Marketplace and operations

- [Theme marketplace administration](theme-marketplace-admin.md) — the theme
  review queue, the sandbox whitelist, purchases and revenue share through the
  wallet, and the seller cash-out (withdrawal) queue.
- [Platform operations](platform-ops.md) — publishing announcements,
  per-plan feature gating via the feature matrix, platform health counters, and
  the restaurant menu-template admin.

## Advanced features

- [Growth fast-follows](growth.md) — sale links, usage rebilling (absorb/at-cost/
  markup), wallet auto-recharge, team & roles, and the flag-gated Stripe Connect rail.
- [Reseller API](api.md) — the `ResellerApiAuth` middleware, hashed `rsk_` tokens,
  and the `/api/v1/reseller` endpoints (stats, customers, invoices, tier, plans-write).
- [Integrations and automation](integrations.md) — how the `integrations` and
  `aggregator_deliverect` keys gate the Deliverect panel, the Zapier/Make/n8n
  connectors, and scoped tokens & webhooks for your tenants (ships dark; no-code
  listings carry the platform brand in V1).
- [Portal security and 2FA](security.md) — portal-level two-factor authentication:
  enroll, confirm, challenge, recovery codes.
- [Commission](commission.md) — automatic wallet commission on merchant plan
  payments, configurable share, rounding, and idempotency.
- [Impersonation](impersonation.md) — portal-side impersonation of a reseller's
  own customers, with reason, audit trail, and safe exit.

## Coverage gaps

These are known gaps between what these guides describe and what is wired today.

- Reseller BYOK charging is deferred. The Stripe and PayPal reseller drivers
  return a deferred result with no live SDK call, so reseller checkout and
  wholesale invoice payment run in test mode until live gateway keys are wired
  (`Reseller::gatewayConfigured()` stays false). See
  [payment-gateways](payment-gateways.md).
- A reseller's `primary_color` recolours the owner product UI (injected as the
  `--theme-color` CSS variable) but not the customer-facing storefront menu
  themes, which follow each restaurant's own theme colour. Live TLS
  provisioning is also deferred: only manual domain provisioning is wired; the
  Cloudflare provisioner exists but is inactive. See [branding](branding.md).
- Two gateways (Tap and Flutterwave) have working payment processors but no
  admin credential form and no field mapping in the gateway editor, so their
  platform keys cannot be saved through the standard editor — they are
  configurable only at the restaurant (BYOK) level. See
  [payment-gateways](payment-gateways.md).
- The merchant-level admin wallet-grant endpoint
  (`admin.users.wallet.grant`, `WalletAdminController@grantCredit`) still has
  no admin form posting to it — the admin Wallet page's "Grant credit" button
  only links to the users list, and the user edit page has no wallet section.
  (Reseller-level wallet grants do have an admin button on the resellers list.)
  The former impersonation gaps are closed: a persistent banner with one-click
  exit and deny-by-family guardrails on sensitive actions now ship — see
  [impersonation](impersonation.md).
- Platform metrics are backend-only: the daily counters live in the
  `platform_metrics` table and alerts surface in the app log; there is no admin
  UI page. Likewise there is no `feature_flags` table or global kill-switch —
  gating is entirely per-plan through the feature matrix. See
  [platform-ops](platform-ops.md).
- There is no admin-facing theme upload form; theme submission runs through
  tooling and tests, not a route. The Lighthouse quality gate may
  stay pending in production when preview URLs are unavailable. See
  [theme-marketplace-admin](theme-marketplace-admin.md).
