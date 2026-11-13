---
title: "Building marketplace apps"
audience: developer
group: Dev
updated: 2026-07-09
---

# Building marketplace apps

Apps extend a restaurant's account through scoped, revocable OAuth access. Your
code runs on YOUR servers; the platform holds the registry (identity + requested
scopes + webhook subscription), the install ledger (who granted what), and the
billing rails. Only `approved` apps are listed and installable.

## The build loop

1. **Register** — create an app in the partner dashboard (`/partner/apps/create`):
   name, category, the scopes you need, an optional webhook URL, and a billing
   model. Submitting mints an OAuth client (client id + secret) and puts the app
   in `review`. An admin approves it into `approved` (installable).
2. **Get installed** — an owner installs from the App Store detail page, granting
   the requested scopes. Installation creates an `AppInstallation` and (for paid
   apps) charges the first period from their wallet.
3. **Authenticate** — OAuth 2.0 (Laravel Passport), standard authorization-code
   flow. The token carries the scopes the owner approved. Requests needing a scope
   the owner did not grant fail — request the minimum set.
4. **Call the API** — use the bearer token against the REST API
   (`/api/v1/...`, see the storefront-api / customer-api primers and the human
   docs). Prices are always computed server-side.
5. **Session tokens** — for embedded pages, mint a short-lived token from an
   active install at `GET /apps/{appId}/session-token` (expires in 60 seconds).
6. **Embedded apps** — declare an `embed_url` and the platform hosts it in an
   iframe at `/apps/{appId}/embed`; authenticate it with a session token.
7. **Webhooks** — subscribe to events (`order.created`, `order.status_changed`,
   `order.paid`) at your `webhook_url`. Deliveries are queued, retried, and signed
   with the Standard Webhooks scheme using your app's secret. Endpoints must be
   public HTTPS (no private/loopback/metadata hosts). Verify the signature before
   trusting a payload.
8. **Usage billing** — usage-billed apps report units:
   `POST /api/app/usage` with `quantity` (>0) and an optional idempotency `ref`.
   Each report charges the owner's wallet at your per-unit price.

## Billing types

- **free** — no charge on install.
- **recurring** — a fixed price every billing period (default 30 days).
- **usage** — a per-unit price billed as you report usage.

## Uninstall

On uninstall the platform revokes the app's tokens and fires `app/uninstalled`.
Stop background work and delete stored data for that account.

## Listing & review requirements

Request only the scopes you use (each justified in the description), provide a
working public-HTTPS webhook if you subscribe to events, declare accurate pricing,
include a tagline/description/category and at least one screenshot, and handle
uninstall cleanly. Metadata edits go live immediately; price or scope changes
re-queue review.

Full human docs, code samples, and the reflected scope/event reference:
`/developers/apps`.
