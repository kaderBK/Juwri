---
title: "POS bridge — Square & Clover"
audience: owner
group: Integrations
updated: 2026-08-06
---

# POS bridge — Square & Clover

Pushes orders taken on our menu into the restaurant's POS. Owner screen:
**Dashboard → POS bridge** (`/pos-bridge`), behind the `pos_bridge` feature gate
(the whole route group carries `feature:pos_bridge`).

Providers with real drivers on this surface: `square`, `clover`. Foodics and Marn
have their own screens under the same gate (`/pos-bridge/foodics`, `/pos-bridge/marn`).

## Connect — Square (OAuth, preferred)

Square's App Marketplace requires OAuth; a personal access token disqualifies a
listing. The app-level credentials come from `services.square.app_id` /
`services.square.app_secret`, or from a platform-level `square` Credential row.

- `POST /pos-bridge/square/connect` (`sandbox=1` for the sandbox host) redirects to
  `connect.squareup.com/oauth2/authorize` (sandbox: `connect.squareupsandbox.com`)
  with scopes MERCHANT_PROFILE_READ, ORDERS_READ, ORDERS_WRITE, ITEMS_READ,
  PAYMENTS_WRITE and a per-connect `state`.
- `GET /pos-bridge/square/callback` verifies `state` (mismatch → 403) and exchanges
  the code. Stored encrypted: `access_token`, `refresh_token`, `expires_at`,
  `merchant_id`, `sandbox`, `auth=oauth`. Square access tokens expire (~30 days),
  so the refresh token is required.
- `POST /pos-bridge/square/disconnect` revokes the token.

Square webhook signatures are `base64(HMAC-SHA256(signatureKey, notificationUrl + body))`
compared against `x-square-hmacsha256-signature`.

## Connect — raw token (Clover, and legacy Square)

`POST /pos-bridge/connect` with:

| Field | Required | Notes |
|---|---|---|
| `provider` | yes | `square` or `clover` |
| `external_location_id` | yes | the POS location/merchant id |
| `access_token` | yes | stored encrypted in the Credential vault (`feature` = provider) |
| `branch_id` | no | ties the connection to one of our branches |

A raw-token Square connection keeps working for backwards compatibility but is
flagged legacy — reconnect via OAuth.

`POST /pos-bridge/disconnect/{provider}` removes the connection row and its
credentials.

## Item mapping

`POST /pos-bridge/mapping` with `provider`, `menu_id`, `external_id` maps one of our
menu items to a POS item. The menu must belong to the current tenant (403 otherwise).
Posting an empty `external_id` deletes the mapping.

## Live / fake driver

Both clients resolve: container binding (tests) → real client when
`SQUARE_DRIVER` / `CLOVER_DRIVER` is `real` **and** an `access_token` credential exists
**and** the real client class is present → otherwise a Fake. Without those, the screen
is fully usable and pushes are simulated.

## What the owner sees

The index lists connections per provider, the menu ↔ POS item mapping, and the last
50 rows of the order-push log. Toast is shown as a partner-gated "coming soon" — there
is no Toast driver.

**Retry (M5).** A failed push is retried automatically three times with a 30 s / 2 min /
5 min backoff; the log row reads `pending` while the queue still owns it and `failed` only
once the budget is spent. Every non-`pushed` row also carries a manual **Retry** button,
and the connections table shows the connection's last error. Replay is safe: `pos_order_pushes`
is unique on `(order_id, provider)` and every driver sends a stable per-order idempotency key,
so a retry after a lost response cannot duplicate the order in the POS.
