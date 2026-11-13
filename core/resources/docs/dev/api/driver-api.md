---
title: "Driver API reference"
audience: developer
group: Api
updated: 2026-07-25
---

# Driver API reference

The driver API lives under `/api/v1/driver` and is authenticated with a
per-driver bearer token. Every endpoint is scoped to the authenticated
driver — a driver only ever sees or touches their own assigned deliveries.

Responses are JSON. The deliveries list uses standard Laravel pagination.

## Authentication

There are two ways a driver app gets a token.

**1. Owner-issued (dashboard).** The restaurant owner mints or rotates the
token from the dashboard (Settings → Drivers → **Rotate token**).

**2. OTP login (self-service, recommended for a real app).** The driver
signs in with their phone number and a one-time SMS code, and the endpoint
hands back a freshly rotated token. Both login routes are public (no token
needed) and share a tight throttle of **12 req/min per IP**.

| Method | Path |
|--------|------|
| POST | `/api/v1/driver/login/request` |
| POST | `/api/v1/driver/login/verify` |

`POST /driver/login/request` body: `{"phone": "+9665..."}` (max 32 chars). It
always answers `200` with the same generic body regardless of whether the
phone belongs to a driver — this is deliberate anti-enumeration, so never
treat the response as an existence check:

```json
{ "ok": true, "message": "If that phone belongs to a driver, a code was sent." }
```

`POST /driver/login/verify` body: `{"phone": "+9665...", "code": "123456"}`
(`code` max 8 chars). On success it rotates the driver's token and returns
the raw value once:

```json
{
  "token": "RAW_DRIVER_TOKEN",
  "driver": { "id": 12, "name": "Sami", "is_fleet": false, "shift_status": "offline" }
}
```

- `422 {"error": "Invalid code."}` — unknown phone **or** wrong/expired code
  (the two are indistinguishable, again by design).
- `403 {"error": "...", "feature": "driver_app"}` — the code was correct but
  the driver's merchant is not entitled to `driver_app`. Checked *after* the
  OTP so it cannot be used to enumerate drivers. Platform **fleet** drivers
  (no merchant) are outside the SKU and are never refused here.

Send the token as a bearer header (preferred) or the `driver_token` query
parameter:

```bash
curl https://your-domain/api/v1/driver/me \
  -H "Authorization: Bearer DRIVER_TOKEN"
```

Tokens are stored hashed (SHA-256) — the raw value is shown once at creation,
rotation, or OTP login and cannot be recovered. Rotating a token (including
via OTP login) immediately invalidates the previous one.

## Feature gate: `driver_app`

The whole `/api/v1/driver/*` group sits behind the `driver_app` entitlement,
enforced **after** `DriverTokenAuth` so the gate can ask about the driver's
merchant. A valid token belonging to an unentitled merchant gets a JSON
`403` on every endpoint in the group — never a redirect:

```json
{ "error": "This feature is not included in your current plan.", "feature": "driver_app" }
```

This closes the door that `feature:driver_app` on the owner-side driver-admin
routes left open: without it, tokens already sitting in drivers' phones would
keep working after the merchant lost the feature. Fleet drivers (no merchant)
are unaffected.

## Endpoints

All paths are prefixed with `/api/v1/driver` and require the
`DriverTokenAuth` middleware (bearer token) plus the `driver_app`
entitlement. Rate limit: 120 req/min per IP (the two `/driver/login/*`
routes are public and capped at 12 req/min per IP instead).

### Profile

| Method | Path |
|--------|------|
| GET | `/api/v1/driver/me` |

Returns the authenticated driver's profile (id, name, phone, vehicle info,
active status, and current location if set).

### Deliveries

| Method | Path |
|--------|------|
| GET | `/api/v1/driver/deliveries` |
| GET | `/api/v1/driver/available` |
| POST | `/api/v1/driver/deliveries/{orderId}/claim` |
| PUT | `/api/v1/driver/deliveries/{orderId}/status` |
| POST | `/api/v1/driver/deliveries/{orderId}/pod-otp` |
| POST | `/api/v1/driver/deliveries/{orderId}/pod-photo` |

`GET /driver/deliveries` lists orders assigned to this driver, newest first,
paginated 30 per page. Pass `?active=1` to hide finished deliveries (status
`delivered` or `failed`).

`PUT /driver/deliveries/{orderId}/status` updates the delivery status of an
assigned order. Body:

```json
{
  "delivery_status": "out_for_delivery",
  "note": "Customer not answering — will retry in 5 min."
}
```

Valid statuses (driver-settable): `picked_up`, `out_for_delivery`,
`delivered`, `failed`.

The optional `note` field (max 255 chars) is stored as `driver_note` on the
order.

### Self-claim (owner opt-in)

`GET /driver/available` lists unassigned `delivery` orders at the driver's
restaurant that are not yet `delivered`/`failed`, newest first, up to 30. It
returns an **empty `data` array** unless the owner has switched on
`driver_self_claim` for that restaurant — an empty list is not proof there
is no work.

`POST /driver/deliveries/{orderId}/claim` takes one of those orders. The
claim is a conditional write, so two drivers racing for the same order do not
both win: the loser gets `409 {"error": "Order already taken."}`. On success
the response is the full order detail.

Assignment can still be done owner-side via the admin API; self-claim and
dispatch offers are the driver-initiated paths.

### Proof of delivery

`POST /driver/deliveries/{orderId}/pod-otp` confirms the diner's handover
code. Body: `{"otp": "1234"}` (max 8 chars). Returns `{"ok": true}`, or
`422 {"error": "OTP does not match."}`.

`POST /driver/deliveries/{orderId}/pod-photo` uploads a doorstep photo as
`multipart/form-data` with a `photo` file part (jpg/jpeg/png, max 5 MB).
Returns `{"ok": true, "path": "..."}`.

### Location

| Method | Path |
|--------|------|
| PUT | `/api/v1/driver/location` |

Reports the driver's live GPS position for real-time tracking. Body:

```json
{
  "lat": 24.7136,
  "lng": 46.6753
}
```

`lat` must be between -90 and 90; `lng` between -180 and 180. Updates
`last_lat`, `last_lng`, and `last_location_at` on the driver record.

### Dispatch offers (fleet)

| Method | Path |
|--------|------|
| GET | `/api/v1/driver/offers` |
| POST | `/api/v1/driver/offers/{offerId}/accept` |
| POST | `/api/v1/driver/offers/{offerId}/reject` |

`GET /driver/offers` lists the dispatch offers currently open to this driver;
each carries an `expires_at` (ISO-8601) after which it is no longer
acceptable.

`POST /offers/{offerId}/accept` returns the full order detail on success, or
`409 {"error": "Offer is no longer available."}` if it expired or another
driver took it. `POST /offers/{offerId}/reject` returns `{"ok": true}`.

Both go through the dispatch orchestrator — a driver never assigns an order
to themselves directly through these routes.

### Shift

| Method | Path |
|--------|------|
| POST | `/api/v1/driver/shift` |

Sets the driver's availability. Body: `{"status": "online"}`. Valid values:
`offline`, `online`, `break`. Returns
`{"ok": true, "shift_status": "online"}`.

### Earnings

| Method | Path |
|--------|------|
| GET | `/api/v1/driver/earnings` |

Delivered-order totals for the **last 30 days**, grouped per day, plus a
summary:

```json
{
  "data": [
    { "date": "2026-07-24", "deliveries": 9, "total": 412.5, "tips": 38.0 }
  ],
  "summary": { "deliveries": 214, "total": 9870.25, "tips": 742.5 }
}
```

Only orders with `delivery_status: delivered` and a `delivered_at` timestamp
are counted. `total` is the computed order total (from line items), not a
driver payout figure.

## Errors

- **401 Unauthenticated** — no token, unknown token, or inactive driver.
- **403** — the driver's merchant is not entitled to `driver_app` (see the
  feature-gate section above). Body carries `"feature": "driver_app"`.
- **404** — the order is not assigned to this driver, or does not exist.
- **409** — the claim or offer was already taken by another driver.
- **422** — validation failed, a POD OTP mismatch, or a bad login code.

## Related pages

- [API tokens and abilities](auth-tokens.md) — token types overview.
- [Admin API](admin-api.md) — owner-scoped driver management endpoints.
- [Staff API](staff-api.md) — staff app endpoints.
- [Errors and rate limits](errors-rate-limits.md) — standard error envelope.
