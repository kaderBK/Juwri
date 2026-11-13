---
title: "Courier dispatch & the dispatcher live map"
audience: owner
group: Integrations
updated: 2026-07-25
---

# Courier dispatch & the dispatcher live map

Two related surfaces under **Dashboard → Dispatch** (`/dispatch`):

- The **dispatch screen** (`feature:courier_dispatch`) — pending delivery orders with a
  side-by-side quote comparison (own fleet vs Uber Direct vs DoorDash Drive) and
  one-click dispatch.
- The **live map + report** (`feature:driver_app`) — `/dispatch/map`,
  `/dispatch/map/feed`, `/dispatch/{id}/assign`, `/dispatch/report`.

## Providers

Own fleet is always available. Third-party couriers appear only when the restaurant has
BYOK credentials in the Credential vault:

| Provider | Credential feature | Availability key | Driver switch | Base URL |
|---|---|---|---|---|
| Uber Direct | `uber_direct` | `client_id` | `UBER_DIRECT_DRIVER` | `UBER_DIRECT_BASE_URL` (default `https://api.uber.com`) |
| DoorDash Drive | `doordash` | `developer_id` | `DOORDASH_DRIVER` | `DOORDASH_BASE_URL` (default `https://openapi.doordash.com`) |

With the driver left at `fake` the provider still shows and quotes — the Fake client
serves it. The real client is used only when the driver is `real`, the credential above
is present, and the real client class exists.

There is no owner-facing form for courier credentials in this build; they are written
into the encrypted Credential vault under those feature names.

## Dispatch flow

- Quotes are taken at dispatch time. Uber Direct quote ids expire fast, so the provider
  re-quotes on dispatch rather than reusing a stale quote.
- Uber Direct: create quote → create delivery → a `CourierDelivery` row keyed by the
  provider's `external_delivery_id`.
- DoorDash keys deliveries by a **caller-supplied** id, so we generate
  `mb_{orderId}_{random}`.
- Cancel pulls the latest `CourierDelivery` for the order and cancels it, setting the
  local status to `canceled`.

## Auto-dispatch modes

Restaurant option `dispatch_mode`:

- `manual` (default) — nothing automatic; the owner assigns.
- `own` — always the own fleet.
- `cheapest` — lowest-fee feasible courier (own or third-party).
- `overflow` — own fleet now; a no-driver timeout escalates to the cheapest third-party
  courier.

## Status webhook

`POST /webhook/courier/{provider}` where provider is `uber_direct` or `doordash`
(anything else → 404). Rate-limited, CSRF-exempt.

- The delivery is looked up by `delivery_id` / `external_delivery_id` / `id` in the
  payload; a missing id is 422, an unknown delivery is 404.
- Signature: `HMAC-SHA256(raw body)` with the restaurant's stored `client_secret` (or
  `signing_secret`), compared against `X-Uber-Signature` / `X-DoorDash-Signature`.
  No secret, no header, or a mismatch → 401 and no side effects.
- Status map: created/pending → order `assigned`; pickup → `picked_up`;
  pickup_complete and dropoff → `out_for_delivery`; delivered → `delivered`;
  canceled → order `failed`.
- `courier.location` `{lat,lng}` is stored on the delivery with a timestamp — this is
  what the live map draws.

Uber retries only 3×, so a poll reconciliation job is the backstop.

## Live map

`/dispatch/map/feed` returns active drivers (with a `stale` flag when the last location
is older than 5 minutes), active delivery orders, and per-unassigned-order driver
suggestions. `/dispatch/{id}/assign` sets `driver_id` and `assigned_at`; the driver must
belong to the same restaurant (422 otherwise).

## Still required for live (owner)

- Uber Direct: merchant credentials, then flip `UBER_DIRECT_DRIVER=real`.
- DoorDash Drive: **production access is closed to new integrators (2025–26)** — the
  real client stays sandbox-only until DoorDash reopens certification.
