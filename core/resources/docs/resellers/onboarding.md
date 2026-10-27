---
title: Reseller onboarding quickstart
audience: reseller
group: Start here
updated: 2026-08-06
verified_against: e09d26f8
---

# Reseller onboarding quickstart

A start-to-finish path from "the platform admin just created my reseller" to
"my first customer is live on my brand". The portal tracks the same journey as
a six-step checklist at **Getting started** in the portal sidebar (route
`reseller.onboarding`, on `PortalController@onboarding`): brand set, two-factor
enabled, custom domain active, payment gateway configured, at least one plan
defined, first customer created. The checklist page shows a done/total/percent
rollup and each step below maps 1:1 to one of its booleans, so you can follow
this page and watch the checklist fill in. The same rollup also renders on the
portal Overview page until every step is complete.

Before you start, read the
[reseller and white-label overview](overview.md) once — it explains how your
reseller record is created (admin-side, not self-serve), how host-based brand
resolution works, and how the portal scopes everything to your own customers.

## Step 0 — Get your reseller created and sign in

A platform admin creates your reseller record, picking your user account as
the owner and setting at least your `brand_name`. Once that is saved, signing
in with your owner account gives you the portal at `/reseller` (route
`reseller.portal`), gated by `ResellerOwnerMiddleware`. If you can sign in but
`/reseller` refuses you, your user is not the owner of an active reseller —
ask your platform admin.

## Step 1 — Branding and email sender

Checklist step: **brand** (satisfied when `brand_name` is set — usually already
done at creation; finish the rest of the fields now).

From **Website & Brand > Branding** in the portal (page `reseller.branding`),
set your logo, primary colour, support email, and support URL. Then set your
white-label email identity — with your own SMTP server your customers' mail
comes entirely from you; without it, mail still carries your brand name and
reply-to. Both are covered field-by-field in
[branding and white-label setup](branding.md), including the pricing-page
builder and per-key email templates you can customise later.

## Step 2 — Two-factor authentication

Checklist step: **two_factor** (satisfied when the reseller *owner* account has
confirmed 2FA).

Enroll at **Settings** in the portal sidebar (page `reseller.security`):
enroll, confirm with a code, and store your recovery codes. The full flow is in
[portal security and 2FA](security.md). Do this before you invite any team
members or hold real customer data.

## Step 3 — Custom domain

Checklist step: **domain** (satisfied when `custom_domain` is active).

From **Domain** in the sidebar (`reseller.domain.status`), request your domain,
add the TXT record it gives you (a CNAME alone does not verify) at your DNS provider, and verify.
Activation is admin-only: a platform admin approves the verified request, after
which brand resolution starts answering on your host. The request/verify/
approve flow and its DNS records are documented in
[branding and white-label setup](branding.md#custom-domain-for-the-platform).

## Step 4 — Payment gateway

Checklist step: **gateway** (satisfied when `Reseller::gatewayConfigured()` is
true — you have working gateway credentials of your own).

Customer charges run through *your* gateway keys (bring-your-own-keys), never
the platform's. Configure them at **Payments / BYOK** in the sidebar
(`reseller.payments`; the entry appears when your reseller has the
`byok_gateway` capability). Until live keys are wired, checkout records
test-mode transactions rather than taking real payments. Gateway options,
webhook endpoints to register, and test-versus-live selection are in
[payment gateway setup](payment-gateways.md). While you are in billing
territory, also skim [wallet and coupons](wallet.md) — the reseller wallet is
how you settle platform invoices, fund merchants, and receive commission.

## Step 5 — Plans and pricing

Checklist step: **plans** (satisfied when at least one reseller plan exists).

Define what your customers can buy at **Plans & Pricing**
(`reseller.plans`). Each reseller plan maps to a platform plan that supplies
the actual limits and capabilities; your plan sets the name and price your
customers see. The platform-side plan mechanics (limits, the unlimited
sentinel, the feature matrix, add-ons) are documented in
[configuring plans and pricing](plans-config.md). Then build your public
pricing page at **Pricing Page** (`reseller.pricingPage`) so your domain has
something to sell from.

## Step 6 — First customer

Checklist step: **first_customer** (satisfied when at least one user carries
your `reseller_id`).

Two ways to get one:

- Create the customer yourself from **Restaurants** in the sidebar
  (`reseller.customers`), then optionally charge them for a plan or sign in as
  them to set the account up (see [impersonation](impersonation.md) — note the
  actions that are blocked during a support session).
- Turn on public self-serve signup (`allow_signup` on your reseller record) so
  visitors on your domain can register themselves against your plans.

## After the checklist

- Running your own food marketplace? See
  [your own marketplace (reseller Fusion)](own-marketplace.md) — the **Fusion**
  sidebar group, dark behind the `own_fusion` entitlement.
- Invite staff with scoped roles at **Team & Roles** (`reseller.team`).
- Watch **Invoices** (`reseller.invoices`) for the wholesale seat invoices the
  platform bills you, and **Seat usage** (`reseller.seatAlert`) to see where
  you stand against your included-seats allotment.

## Related pages

- [Overview](overview.md) — the whole reseller model and built-versus-planned.
- [Branding](branding.md) · [Plans](plans-config.md) · [Wallet](wallet.md) ·
  [Payment gateways](payment-gateways.md) ·
  [Own marketplace](own-marketplace.md)
