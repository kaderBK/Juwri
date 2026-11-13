---
title: "Aggregator hub — Jahez, Keeta, Uber Eats"
audience: owner
group: Integrations
updated: 2026-07-25
---

# Aggregator hub — Jahez, Keeta, Uber Eats

One dashboard for delivery marketplaces: **Dashboard → Aggregators**
(`/aggregators`). The index is behind `feature:aggregator_hub`.

Registered providers (a driver is reachable ONLY if it is listed in
`AggregatorRegistry::builtins()` — there is no auto-discovery): `custom`,
`deliverect`, `uber_eats`, `keeta`, `jahez`, `zomato`, `swiggy`.
Deliverect, Zomato and Swiggy have their own docs.

## Connect a channel

`POST /aggregators/connect`:

| Field | Required | Notes |
|---|---|---|
| `provider` | yes | must resolve in the registry (422 otherwise) |
| `external_store_id` | yes | the channel's store/branch id — this is what resolves the tenant on every webhook |
| `webhook_secret` | yes | shared secret, stored encrypted (`feature` = provider) |
| `client_secret` | no | second secret for providers that verify with it (Uber Eats) |
| `branch_id` | no | ties the channel to one of our branches |

Other actions: `POST /aggregators/{id}/pause` (toggles paused/active),
`POST /aggregators/{id}/menu-push`, `DELETE /aggregators/{id}`.

The index also shows a per-channel commission report (order count + summed
`channel_fee`) and the last 10 sync-log rows.

## Inbound orders

`POST /webhook/delivery/{provider}` — rate-limited, CSRF-exempt. The shape is the same
for every provider:

1. Provider resolved from the registry; unknown → 404.
2. Tenant resolved from the stored connection by external store id — **never** from the
   raw payload; unknown store → 404.
3. Signature verified; failure → 401 and no order is created.
4. `AggregatorIngestor` creates the order through the one order-creation path
   (idempotent per provider + external order id).
5. A paused channel or an unusable payload returns 202 `skipped` so the aggregator
   stops retrying; a created order returns 201 with the order id.

Default verification (shared base) is `HMAC-SHA256` over the raw body with the
connection's `webhook_secret`, hex-compared against the provider's header; a
`sha256=`-style prefix is stripped. No secret configured → reject.

## Per-provider notes

### Jahez (`jahez`)

- Header `X-Jahez-Signature`, shared-secret HMAC.
- Line items map by product code: the trailing digits of the code are our menu id
  (OpenItemCode). Unmapped lines are listed on the order note for review.
- Auto-accepts on ingest unless the connection is set to manual accept. Jahez cannot
  accept an order older than 5 minutes.
- Menu push is supported: `POST /aggregators/{id}/menu-push` builds the canonical
  catalog payload and uploads it (reported as a category count).
- Real client credentials: `api_key` + `secret`, used only when `JAHEZ_DRIVER=real`.
- **Every Jahez payload field name is ASSUMED**, reconstructed from third-party
  connectors and isolated in `JahezPayloadMapper`. Certification (BD-ACTIONS #7) is
  what turns these into facts; only that mapper and the client change.

### Keeta (`keeta`)

- Header `X-Keeta-Signature`. Signature covers method, path, query and raw body
  (`KeetaSigner`) with the tenant's `app_secret` (falls back to `webhook_secret`).
- Event 1001 New Order; line SKUs are `sku_<menuId>` and map straight back to our menu.
- Replays are idempotent via the `delivery_orders` (provider, external_id) unique index.
- Keeta retries 3× at one-minute intervals if not acked within 5 minutes; we auto-confirm
  on successful ingest unless the connection is set to manual accept. Outbound lifecycle
  (prepare / collect / cancel) goes through `pushStatus`.
- Menu push is supported (full replace); unmapped allergens are reported back in the
  success message.
- Real client credentials: `app_id` + `app_secret`, used only when `KEETA_DRIVER=real`.
  Deferred until the Keeta test store arrives (BD-ACTIONS #5).

### Uber Eats (`uber_eats`)

- Header `X-Uber-Signature` = hex `HMAC-SHA256(raw body, client_secret)` — note the
  secret key here is `client_secret`, not `webhook_secret`.
- Uber sends ONE webhook per app with all events multiplexed, and
  `orders.notification` carries only a pointer, so the full order is fetched with the
  BYOK client before mapping.
- Items map by SKU (`external_data` / `sku` / `id`); unmapped lines go on the order note.
- Accept/deny must happen within 11.5 minutes — we auto-accept on successful ingest.
- Commission is not per-order here; it is reconciled from Uber payout reports, so
  `channel_fee` is 0 on ingest.
- Config: `UBER_EATS_DRIVER`, `UBER_EATS_BASE_URL` (default `https://api.uber.com`);
  real client needs a `client_id` credential.

## Still required for live (owner)

- Jahez portal access + certification (BD-ACTIONS #7). Note Deliverect's channel catalog
  includes Jahez — an indirect route while direct access is pending.
- Keeta partner credentials + test store (BD-ACTIONS #5).
- Uber Eats production access requires Uber approval.
