---
title: "Customer API"
audience: developer
group: Api
updated: 2026-08-06
---

# Customer API

## Auth

Customer endpoints use `Authorization: Bearer <token>` obtained from the
customer register / login / OTP endpoints (phone OTP supported).

### Obtaining a token

All auth endpoints are scoped to a restaurant:

- `POST /api/v1/stores/{id}/customer/register` — create account (name,
  email/phone, password).
- `POST /api/v1/stores/{id}/customer/login` — email + password login.
- `POST /api/v1/stores/{id}/customer/otp/request` — request a phone OTP
  (throttled 6/min).
- `POST /api/v1/stores/{id}/customer/otp/verify` — verify the OTP and
  receive a Bearer token (throttled 10/min).
- `POST /api/v1/customer/logout` — revoke the current token.

## Profile & addresses

These endpoints require a valid Bearer token:

- `GET  /api/v1/customer/me` — current customer profile.
- `PUT  /api/v1/customer/me` — update profile fields.
- `GET  /api/v1/customer/addresses` — list saved addresses.
- `POST /api/v1/customer/addresses` — add a new address.
- `DELETE /api/v1/customer/addresses/{id}` — remove an address.

## Orders

- `GET  /api/v1/customer/orders` — the signed-in customer's order history
  (most recent first), including each order's status and `track_token`.
- `POST /api/v1/customer/orders` — place an order (below).

## Place an order

`POST /api/v1/customer/orders`

```json
{
  "type": "takeaway",            // fulfillment mode — depends on store_type (see below)
  "client_uuid": "b3b1…",        // YOUR generated uuid — idempotency key
  "items": [
    { "item_id": 12, "quantity": 2, "variation": 5, "extras": [3, 4] }
  ]
}
```

- `type`: the store's fulfillment mode. Food stores accept `takeaway`,
  `on-table`, `delivery`, and `room_service`; non-food stores accept the
  non-food modes their `store_type` vertical allows — `ship`, `pickup_slot`,
  `appointment`, `at_home`, or `in_store`. A store only accepts the modes its vertical
  permits (an invalid mode is rejected `422`). See the full mode catalog and
  per-vertical rules in [Storefront API](storefront.md#placing-an-order).
- `client_uuid`: resend the SAME uuid on retry — the order will not
  duplicate. Generate a fresh uuid per new order.
- `variation` (single variant id) and `extras[]` ids MUST come from the
  Storefront `/menu` payload.
- Send NO prices. The server prices every line from the live menu; a request
  carrying prices is a client bug.

## Track

`GET /api/v1/track/{track_token}` — status of a placed order; the token is
returned by the order call.

## Public menu

`GET /api/v1/menu/{storeId}` — the restaurant's live menu (categories,
items, variants, extras) with no authentication. Use the ids it returns when
placing orders. Throttled at 120 req/min per IP (see
[errors & rate limits](errors-rate-limits.md)).

## Store credit

`GET /api/v1/customer/store-credit` — the customer's wallet / store-credit
balance at this restaurant.

## Gift cards

`GET /api/v1/customer/gift-cards` — the gift cards this customer **purchased** at
this restaurant (cards sent to them by others are not listed). Response:

```json
{
  "wallet_enabled": true,
  "gift_cards": [
    {
      "id": 42,
      "code": "GC-7F3K-9QW2",
      "balance": 25.00,
      "formatted": "$25.00",
      "status": "active",
      "expired": false,
      "redeemable": true,
      "expires_at": "2027-01-01T00:00:00+00:00",
      "wallet_save_url": "https://pay.google.com/gp/v/save/…"
    }
  ]
}
```

`wallet_save_url` is a Google Wallet "Add to Google Wallet" save-link for that
card. It is present only when the store has Google Wallet enabled **and** the card
is still redeemable — otherwise it is `null` and `wallet_enabled` is `false`. Hand
the URL to the customer to open; do not store it (the signed link is short-lived).

## Failure handling

- 422 = validation problem (unknown item id, closed restaurant, bad type).
- 429 = rate limit (120/min per token) — back off, do not tight-loop.
- Network timeout after POST → retry with the same `client_uuid`.
