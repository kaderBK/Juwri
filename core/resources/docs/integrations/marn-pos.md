---
title: "Marn two-way POS (BYOK)"
audience: owner
group: Integrations
updated: 2026-07-25
---

# Marn two-way POS (BYOK)

Marn is a two-way POS connection: menu import in, orders out, order state back via
webhook. Owner screen: **Dashboard → POS bridge → Marn** (`/pos-bridge/marn`), behind
the `pos_bridge` feature gate.

## Connect

`POST /pos-bridge/marn/connect` — all credentials are BYOK (the merchant's own Marn
credentials), stored encrypted in the Credential vault under `feature=marn`.

| Field | Required | Stored as |
|---|---|---|
| `client_id` | yes | credential `client_id` |
| `client_secret` | no on re-save | credential `client_secret` (left blank keeps the existing one) |
| `brand_key` | yes | credential `brand_key` + connection setting |
| `location_key` | yes | connection `external_location_id` |
| `hold_mode` | no | connection setting (boolean) |

On save the connection mints an inbound-webhook token (`ensureWebhookToken()`), stored
in the encrypted vault — not in the connection settings.

`POST /pos-bridge/marn/disconnect` deletes the connection and its credentials.

## Menu import (dry-run first)

- `POST /pos-bridge/marn/import/preview` returns the JSON preview — nothing is written.
- `POST /pos-bridge/marn/import` commits, reports created/updated counts, and busts the
  catalog export cache.

Both run against the stored `location_key`.

## Webhook

`POST /webhook/marn/{token}` — rate-limited (`ratelimit:webhook-in`), CSRF-exempt.

Marn publishes no signature scheme, so the tenant is resolved **from the per-connection
token in the URL**, never from the payload. An unknown token returns 404 with no side
effects. When the payload carries a `locationKey`, it is cross-checked against the
stored connection; a mismatch is a 404.

- `servicesUpdate` (or `event=services.update`) flags `menu_drift` on the connection —
  a re-import suggestion. It never auto-imports.
- An order payload with `orderNo` updates the matching push record's `provider_meta`
  with the Marn state and status.

Order state map: 1 waiting, 2 in_kitchen, 3 ready, 4 served, 5 on_the_way, 6 delivered.
Status values Marn sends: Pending, Paid, Void, Refund, PartialRefund.

## Live / fake driver

The real client is used only when `MARN_DRIVER=real`, both `client_id` and
`client_secret` credentials exist, and the real client class is present. Otherwise a
Fake serves the screen. The real client (client-credentials token lifecycle, UAT/prod
base URLs) lands with Marn-issued credentials — deferred (BD-ACTIONS #3).
