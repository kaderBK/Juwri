---
title: "Storefront API"
audience: developer
group: Api
updated: 2026-09-10
---

# Storefront API

Browse a restaurant's live menu, and optionally place orders. Tokens are
per-restaurant; the read-only `menu:read` token is safe to embed in client
code.

## Auth

Header: `X-Storefront-Token: <token>`. Restaurant owners issue tokens from
their dashboard. Each token carries a **scope**, and the scope decides which
routes it opens:

| Scope | Opens |
|---|---|
| `menu:read` | `GET /storefront/menu`, `GET /storefront/restaurant` |
| `order:write` | `POST /storefront/orders` |
| `agent` | `POST /mcp/storefront` (the agent-facing MCP server) |

The restaurant is always resolved **from the token**, never from the request
— there is no restaurant id in any of these paths. A token used on a route
outside its scope gets `403 {"message": "Token lacks required scope: agent", "code": "forbidden"}`.

Only `menu:read` is safe to ship in a browser bundle. `order:write` and
`agent` tokens place real orders — keep them server-side.

## Endpoints

- `GET /api/v1/storefront/menu` — the full menu: categories → items. Each
  item: `id`, `name`, `price`, `variants[]`, `extras[]`, `variant_groups[]`,
  `gallery[]`, plus availability flags.
- `GET /api/v1/storefront/restaurant` — restaurant profile (name, branding,
  hours-adjacent info).
- `POST /api/v1/storefront/orders` — place an order (scope `order:write`).

Neither endpoint above includes `store_type` or the other multi-vertical
fields (brand, stock, variant counts, …) the Admin API's restaurant/menu
resources now carry — a Storefront token is scoped to reading/ordering one
restaurant's public menu, not to catalog metadata. To read a store's
`store_type` and vertical capabilities, or its brands/variants/shipments/
vehicle-fitment records, use an owner API token against the Admin API — see
[Admin API reference](admin-api.md) and its
[Vertical resources](admin-api.md#vertical-resources) section.

## Placing an order

```json
{
  "type": "takeaway",
  "items": [{ "item_id": 41, "quantity": 2, "variation": 7, "extras": [3] }],
  "customer_name": "Sara",
  "phone": "+9665...",
  "table_number": "12",
  "coupon_code": "WELCOME10",
  "client_uuid": "6f2c...",
  "scheduled_at": "2026-07-26 19:30"
}
```

Only `items` is required; everything else is optional. Orders are created
**UNPAID** — the diner pays on arrival — and are priced entirely server-side
through the same builder the ordering agents use. `client_uuid` is an
idempotency key — resend the same value on retry and the order will not
duplicate. `scheduled_at` (`YYYY-MM-DD HH:MM`, the restaurant's local time)
requests a pre-order and is rejected if it breaks the restaurant's
scheduling rules.

**`type`** used to be implicitly `takeaway` or `on-table` for every store.
It is now validated against that store's own `store_type`: the full mode
catalog is `on-table`, `takeaway`, `delivery`, `room_service` (the original,
food-only literals) plus `ship`, `pickup_slot`, `appointment`, `at_home`, `in_store`
(multi-vertical); which of these a given store may use depends on its
`store_type` (see the `vertical.fulfillment_modes` field on the Admin API's
restaurant resource) — `on-table`/`takeaway` are always accepted in addition,
for backward compatibility. An invalid mode for the store is rejected `422`
naming the allowed set. Note that today only `takeaway` and `on-table` are
fully processed end-to-end through this endpoint — a store's other allowed
modes currently pass validation but the order is still handled as a
`takeaway`; full handling of the newer modes through this specific endpoint
is still rolling out.

Success is `201` with the created order (including its track token).
Failures:

- `403 {"message": "Storefront ordering is not enabled on this plan.", "code": "forbidden"}` — the
  owner's plan does not include `storefront_api`.
- `422` — `items` was not an array, or the order was rejected on a business
  rule (unknown item, sold out, restaurant closed, invalid coupon or
  schedule). The reason is in `message` (`code` is `unprocessable`). A reused idempotency key with a different cart is `409` (`code` `conflict`).

All errors from this endpoint use the standard `{message, code, errors?}` envelope. The legacy `{"error": "..."}` key was removed (API-4).

## Agent MCP

An `agent`-scoped token also unlocks `POST /mcp/storefront`, a JSON-RPC MCP
server exposing the same ordering surface as 12 tools (search, cart
validation, order placement, tracking). See
[MCP servers](../mcp/mcp-servers.md#storefront-mcp).

## Rules

- Rate limit 120 req/min per **token**, and the shared api limiter adds
  60/min per **IP** on top — a request must pass both, so a single-IP client
  effectively tops out at 60/min. HTTP 429; back off exponentially.
- Item/variant/extra ids from this payload are the ONLY valid ids to send
  when ordering through the Customer API.
- Prices in the payload are display values; the server recomputes prices at
  order time — never do money math client-side and send it back.
- The root `GET /api/v1` returns a machine-readable index of endpoints
  (no auth).
