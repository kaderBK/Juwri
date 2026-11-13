---
title: "App platform"
audience: developer
group: Apps
updated: 2026-07-20
---

# App platform

The app platform lets a third-party developer publish an app that restaurant owners install into their dashboard. Your code runs on your own servers; the platform holds only the app's identity, its requested OAuth scopes, its webhook subscription, and a per-tenant install ledger. Apps reach tenant data exclusively through OAuth2 (authorization_code) access tokens and the documented API surface.

This page covers what exists in code today. Where a piece is specced but not yet built, it says so.

## Registration

An app is a row in the `apps` table. The columns that define it:

- `developer_user_id` — the owning developer account, or null for platform-owned apps. Revenue share is credited to this user.
- `name`, `slug` (unique), `description`.
- `oauth_client_id` — the Laravel Passport client that backs the app's OAuth flow and signs its embedded session tokens.
- `scopes` (JSON) — the OAuth scopes the app requests.
- `webhook_url`, `webhook_secret`, `webhook_events` (JSON) — the app's event receiver, its Standard-Webhooks HMAC key, and the events it subscribes to.
- `status` — `draft`, `review`, `approved`, `rejected`, or `delisted`. Only `approved` apps are ever listed or installable (`App::isInstallable()` checks `status === 'approved'`).
- `embed_url` — set for embedded (App-Bridge-style) apps; see [Embedded apps](#embedded-apps).
- Billing columns — see [Billing](#billing).

There is no self-serve developer registration UI and no admin review screen wired into the routes yet. App rows and their status transitions (`draft` → `review` → `approved`) are managed at the data layer; the superadmin approval gate is enforced by the `status` column, not by a built page. Treat developer onboarding and the review dashboard as planned, not yet available.

## OAuth and scopes

Apps authenticate with the Passport authorization_code grant. The consent screen (`oauth.authorize`) confirms the requested scopes with the restaurant owner. Token lifetimes are set in `AuthServiceProvider`: access tokens expire in 12 hours, refresh tokens in 30 days, so partners re-authorize silently.

Standard Passport endpoints are available:

- `GET /oauth/authorize` — start the consent flow.
- `POST /oauth/token` — exchange the code (and later refresh).

The registered scopes mirror the owner-token abilities (`resource:action`), so route gates read the same way everywhere:

| Scope | Grants |
| --- | --- |
| `menu:read` | Read menus, categories and items (default scope) |
| `menu:write` | Create and update items items |
| `orders:read` | Read orders and their status |
| `orders:write` | Create and update orders |
| `analytics:read` | Read scan and sales analytics |
| `restaurant:read` | Read restaurant profile and settings |

`menu:read` is the default scope when none is requested. See [Auth tokens](../api/auth-tokens.md) for the wider token model.

### The grant is checked on every request

A token's scopes are only half the story. Each install freezes the scopes that
merchant consented to in `app_installations.granted_scopes`, and every scoped
partner request is intersected against it at request time:

```
effective = token scopes  ∩  installation.granted_scopes  ∩  app.scopes
```

A route that needs a scope you do not effectively hold answers `403` with
`{"error": "insufficient_scope", "required_scope": "…", "granted_scopes": […]}`
— never a redirect, and never a `401` (your token is fine; the grant is not).

Consequences worth designing for:

- Publishing a version that requests **more** scopes does not widen existing
  installs. Each owner accepts the new permissions on their Installed apps page,
  and the wider scope starts working on your very next call — the same access
  token keeps working, and no re-install or re-authorization is needed.
- The reverse is just as immediate: if an owner re-consents down, or a scope is
  pulled from your app's approved list, calls needing it start failing at once.
- `GET /api/app/license` reports the current `granted_scopes` — poll it (or read
  the 403 body) to detect that you are waiting on a re-consent.

## Install and uninstall

Owners manage apps from the dashboard "Installed apps" page (route `apps.page`). Install and uninstall are POST actions (`apps.install`, `apps.uninstall`) that resolve an approved app for the signed-in user.

Install (`AppInstallService::install`):

1. If the app declares a `webhook_url`, a per-install `WebhookEndpoint` is created on the existing webhook delivery rails (signed, queued, retried). Its subscription is the app's `webhook_events` plus `app/uninstalled`, which is always included.
2. An `app_installations` row is created (or reactivated on reinstall — the row is unique per `app_id` + `user_id`), storing the granted scopes and the endpoint id, with `status = active`.
3. For paid apps, the first period is charged through the wallet (see [Billing](#billing)). A failed charge rolls the install back and throws.

Uninstall (`AppInstallService::uninstall`):

1. Every Passport access token (and its refresh chain) the app's client holds for that tenant is revoked — API access stops immediately.
2. An `app/uninstalled` webhook is dispatched so the partner can clean up. The endpoint stays active long enough for the in-flight delivery and its retries to complete, then its subscription is emptied so no future event can match.
3. The installation row is kept as an audit trail with `status = uninstalled` and `uninstalled_at` set.

## Embedded apps

An app with an `embed_url` renders inside a dashboard iframe (the App-Bridge pattern). Two routes back this:

- `apps.embed` — the host page. It requires an active installation and sends `Content-Security-Policy: frame-ancestors 'self'` so only the dashboard can frame it. Your app's own server must in turn allow the dashboard as a frame ancestor.
- `apps.sessionToken` — mints a short-lived session token for the iframe.

The session token (`AppSessionToken`) is a 60-second HS256 JWT signed with the app's Passport client secret. Because the iframe's server knows its own client secret, it verifies the token independently and uses it as the Bearer credential for API calls — no long-lived secret ever sits in the browser, and an intercepted token dies within a minute. Claims:

```json
{
  "iss": "<platform app url>",
  "aud": "<oauth_client_id>",
  "sub": "<restaurant owner user id>",
  "app": "<app slug>",
  "iat": 1700000000,
  "nbf": 1700000000,
  "exp": 1700000060,
  "jti": "<random>"
}
```

`AppSessionToken::verify` checks the signature and that `aud` equals the app's `oauth_client_id`.

## Billing

An app declares a `billing_type`: `free`, `recurring`, or `usage`. Every charge runs through the same wallet choke point (`WalletService::debit`) used by top-ups and other metered features — ledgered and idempotent by reference. Developer revenue share is credited to `developer_user_id` via `SellerPayoutService::creditSale` on each charge.

Recurring apps (`AppBillingService`):

- `price` is charged per `billing_period_days` (default 30).
- The first period is charged at install; `current_period_ends_at` is set to `now + billing_period_days`.
- `renewDue()` runs daily (scheduled `apps:renew` at 02:30) and charges every active installation whose period has ended. The renewal ref is idempotent per due period, so a double-fired cron never double-charges.
- On insufficient funds the installation is set to `status = suspended`. The grant is preserved; access resumes when the owner reinstalls after topping up.

Usage apps:

- Declare a `usage_unit` and a `usage_price` per unit.
- Your server reports usage with its OAuth token (server-to-server) to `POST /api/app/usage` (`auth:api`). The app identity comes from the token's client, so a partner can only ever meter its own app for the tenant that granted the token.

Usage request body:

```json
{ "quantity": 12.5, "ref": "your-idempotency-key" }
```

- `quantity` is required and must be greater than zero. `ref` is optional (max 120 chars); repeats with the same `ref` return the already-charged record instead of charging again.
- The charge is `max(0.01, ceil(quantity * usage_price * 100) / 100)`, recorded in `app_usage_records`.

Responses:

- `201` — `{ "ok": true, "id": …, "charged": …, "unit": … }`.
- `402` — the wallet could not cover the charge.
- `403` — the token does not belong to a registered app, or the app is not installed for that account.

## Lifecycle summary

- App status gates visibility: only `approved` apps appear to owners and can be installed.
- Installing grants the app's scopes, subscribes its webhook, and (for paid apps) charges the first period.
- Uninstalling revokes tokens, fires `app/uninstalled`, and keeps the row for audit.
- Recurring apps renew nightly and suspend on payment failure; usage apps meter per report and return `402` when the wallet is empty.

## Related

- [Auth tokens](../api/auth-tokens.md) — the token and scope model shared with owner API tokens.
- [Webhooks](../api/webhooks.md) and [webhook events](../api/webhooks-events.md) — signing and delivery for the events your app subscribes to.
- [Storefront API](../api/storefront.md) and [Admin API](../api/admin-api.md) — the endpoints a scoped app token reaches.
