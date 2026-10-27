---
title: Branding and white-label setup
audience: reseller
group: White-label setup
updated: 2026-08-06
verified_against: e09d26f8
---

# Branding and white-label setup

As a reseller you run the platform under your own brand and domain, so your
customers never see the underlying platform. This page covers the brand fields
that exist, how each one is applied, how white-label email works, and the
custom-domain request/verify/approve flow.

Two surfaces configure a reseller today:

- The **platform admin panel** (`Admin > Resellers`), where a platform operator
  creates your reseller record and can edit the core brand fields and your
  domain directly.
- The **reseller portal** pages under `/reseller`, gated by
  `ResellerOwnerMiddleware` so you only ever see and change your own reseller.
  Everything below has a rendered portal page with a form: **Branding**,
  **Email & SMTP** (sender plus templates), **Plans & Pricing**, **Pricing
  Page**, and **Domain** all live in the Website & Brand sidebar group. The
  endpoints named below are the routes those pages post to; they also answer
  JSON directly.

## What a reseller record stores

The `resellers` row holds every brand and white-label field:

- `brand_name` (required, max 80) — your display name.
- `brand_logo` (optional, max 120) — a URL or path to your logo image.
- `primary_color` (optional, max 20) — a hex string such as `#6366f1`.
- `support_email` / `support_url` — where your customers reach you.
- `custom_domain` — the host your portal and storefronts answer on (set by
  admin, or activated by the approval flow below).
- `smtp` (JSON) — your own mail server for white-label email.
- `allow_signup` — whether public self-serve signup is open on your domain.
- Domain workflow fields: `requested_domain`, `domain_verify_token`,
  `domain_status` (`none | pending | verified | active`), `ssl_status`.

## How your brand is applied

Brand resolution is host-based. On every web request,
`ResolveResellerBranding` middleware calls `Reseller::resolveForRequest()`,
which matches the request host against an active reseller's `custom_domain`
(cached for 300 seconds). When it matches, the middleware shares an
`$activeBrand` array with all views and binds the `activeReseller` instance.

Two helper functions drive what visitors actually see:

- `brand_title()` returns your `brand_name` when a reseller is resolved,
  otherwise the platform's `settings.site_title`.
- `brand_logo()` returns your `brand_logo`, otherwise the platform
  `settings.site_logo`.

`brand_title()` is used across the owner product UI — the auth
(login/register/reset/verify) page titles, the auth layout's brand panel and
footer, the dashboard `<title>`/sidebar app name — and by the developer docs,
marketplace, app store, partner and OAuth-authorize pages, so your name replaces
the platform's on your domain. `brand_logo()` is wired into the app-store nav and
footer, and the auth layout swaps in the reseller's logo when one is resolved.
The public storefront menu themes (`store_templates/*`) render the *restaurant's*
own branding, not the reseller's — reseller logo substitution does not apply
there.

`primary_color` is stored and exposed in `$activeBrand`, and shown as a colour
swatch in the admin reseller list. In the owner product UI it *is* applied:
`partials/head.blade.php` validates the hex and injects it as the `--theme-color`
/ `--theme-color-rgb` CSS variables (falling back to `settings.theme_color`, then
`#6366f1`). It does not recolour the customer-facing storefront themes, which
follow the restaurant's own theme colour.

## Setting brand name, logo, colour and support details

Through the admin panel (`Admin > Resellers`), the **Add Reseller** form
captures the owner user id, brand name, primary colour, support email, and
custom domain. The update action (`PUT /admin/resellers/{id}`) additionally
accepts `brand_logo`, `support_url`, and the `active` flag.

Through the portal, `POST /reseller/branding` accepts and updates
`brand_name` (required), `brand_logo`, `primary_color`, `support_email`, and
`support_url` for your own reseller. It does not accept `custom_domain` — the
domain always goes through the request/approve flow below so a portal user can
never claim a domain and hijack brand resolution. Saving branding clears the
cached brand entries so the change takes effect on the next request.

## White-label email sender

Your customers' email can come from you instead of the platform. Mail identity
is resolved per recipient by `ResellerMailService`:

- **Reseller with its own SMTP** — the message is sent over your SMTP server.
  From address and name come from your SMTP settings, reply-to is your
  `support_email`.
- **Reseller without SMTP** — the message goes over the platform transport, but
  the from-name is your `brand_name` and the reply-to is your `support_email`,
  so replies still reach you.
- **No reseller** — the platform default sender.

To configure your own mail server, `POST /reseller/smtp` accepts `host`
(required), `port`, `username`, `password`, `secure` (`tls` or `ssl`),
`from_email` (required), and `from_name`. The password is encrypted at write
with Laravel's `Crypt` before it is stored in the `smtp` JSON, and decrypted
only when a runtime mailer is built to send. Saving SMTP clears the reseller
cache.

Delivery still depends on your SMTP server being reachable with valid
credentials; the transport swap and sender identity are what the platform
provides. A send failure is logged and never thrown back to the caller.

## Pricing page builder

A reseller can build a public pricing page served on their custom domain. The
portal exposes two endpoints:

- `reseller.pricingPage` (`GET /reseller/pricing-page`) — returns the current
  config and a list of the reseller's active plans with monthly and annual prices.
- `reseller.pricingPage.save` (`POST /reseller/pricing-page`) — saves `enabled`
  (boolean), `headline` (max 120), `subline` (max 240), and per-plan feature
  lines (each capped at 120 characters, filtered to plans owned by this reseller).

The saved configuration is stored on the reseller record as `pricing_page` and
the cache is busted on save so the public page reflects changes immediately.

## White-label email templates

Resellers can customise the transactional emails sent to their merchants. The
`ResellerEmailTemplate` model stores per-key templates with an enable flag,
subject line, and rich-text body.

- `reseller.emailTemplates` (`GET /reseller/email-templates`) — returns all
  templates for this reseller, one per key defined in
  `ResellerEmailTemplateService::KEYS`, together with the available placeholders.
- `reseller.emailTemplates.save` (`POST /reseller/email-templates`) — create or
  update a template by key. The body is sanitised through `Html::clean()` with a
  `richtext` profile.

`ResellerEmailTemplateService` resolves templates at send time: when a reseller
has an enabled template for the given key, it is used in place of the platform
default.

## Landing and storefront customisation

On your custom domain the platform reuses its normal marketing and storefront
templates, personalised through `brand_title()` as described above. Logo
substitution is wired into the owner auth layout and the app-store pages, but the
marketing landing partials (`nav-site`/`footer-site`) and the storefront menu
themes still render the platform's or the restaurant's own logo. Your reseller plans (`reseller_plans`, each mapped to a platform plan for
limits) are what your customers see and buy on your domain, and public signup on
your domain is gated by the `allow_signup` flag. Reseller plan CRUD lives at
`GET/POST/DELETE /reseller/plans`.

## Custom domain for the platform

A reseller custom domain is the host your entire branded portal and your
customers' storefronts answer on. This is distinct from a single restaurant's
custom domain, which is documented in
[Custom domain](../users/custom-domain.md).

Activation is intentionally split so a portal user can request and verify a
domain but only an admin can activate it:

1. **Request** — `POST /reseller/domain` with `domain`. The service checks the
   domain is not already used by another reseller, stores it as
   `requested_domain` with `domain_status = pending`, and returns the records to
   add:
   - a **CNAME** pointing your domain at the platform CNAME target
     (`settings.reseller_cname_target`, defaulting to `cname.<platform host>`),
     and
   - a **TXT** record `juwri-verify=<token>`.
2. **Add DNS** — create either the CNAME to the target or the TXT record at your
   DNS provider. Either one satisfies verification.
3. **Verify** — `POST /reseller/domain/verify` runs a real DNS lookup
   (`DomainVerifier`): it passes when a CNAME on the domain targets the platform
   host, or a TXT record contains your token. On success `domain_status` becomes
   `verified`; otherwise it stays `pending`. `GET /reseller/domain` returns the
   current status.
4. **Admin approval** — `POST /admin/resellers/{id}/approve-domain` activates a
   *verified* domain: it sets `custom_domain`, marks `domain_status = active`,
   clears the request fields, and provisions TLS through the configured driver.

The only domain provisioner wired today is the manual driver: it hands back the
CNAME target and sets `ssl_status = manual`, expecting an ops step or on-demand
TLS (for example Caddy) to issue the certificate. A live Cloudflare-for-SaaS API
driver is defined behind the `DomainProvisioner` interface but is not yet
active. An admin can also set `custom_domain` directly on the admin form,
bypassing the request/verify steps.

## Current limitations

- **Primary colour does not recolour storefronts.** `primary_color` is applied
  to the owner product UI (injected as `--theme-color`, as described above) but
  not to the customer-facing storefront menu themes, which follow each
  restaurant's own theme colour.
- **Admin add form omits some fields.** The admin *Add Reseller* form does not
  include logo or support URL inputs; both are accepted by the update route and
  the portal branding endpoint.
- **Live TLS provisioning is deferred.** Only the manual domain driver is wired;
  automatic certificate issuance via Cloudflare-for-SaaS is planned, not yet
  available.
