---
title: "API tokens & abilities"
audience: developer
group: Api
updated: 2026-07-29
---

# API tokens & abilities

## Creating a token

Dashboard → **API tokens & webhooks**. Pick abilities, copy the token — it is
shown ONCE (stored hashed).

## Abilities

Abilities follow a `resource:action` pattern. The coarse `read` and `write`
abilities are supersets that grant all resources at that level.

| Ability | Grants |
| --- | --- |
| `read` | All `GET` endpoints (every resource) |
| `write` | All mutating endpoints; superset of `read` |
| `menu:read` | Read menu categories and items |
| `menu:write` | Create, update, delete menu items and categories |
| `orders:read` | List and view orders |
| `orders:write` | Create orders, update order/delivery status, assign drivers, create/edit coupons (MCP) |
| `customers:read` | List and view customer CRM records |
| `restaurants:read` | Read restaurant profile and settings |
| `restaurants:write` | Update restaurant settings (business hours — MCP tool only; no REST endpoint uses it) |
| `analytics:read` | Sales reports and analytics |
| `drivers:read` | List and view driver records |
| `drivers:write` | Create, update, delete drivers and rotate driver tokens |
| `webhooks:read` | List your webhook endpoints |
| `webhooks:write` | Subscribe and unsubscribe webhook endpoints (the scope Zapier / Make / n8n connections mint) |

A legacy coarse `read` or `write` token still covers the matching `webhooks:*`
routes through the `*` resource rule — this is accepted back-compat, not a gap.

Some endpoints need an entitlement on top of the ability — `customers:read`
also requires `crm_suite`, and every `drivers:*` route requires `driver_app`.
See [Admin API — feature gates](admin-api.md#feature-gates).

## Using it

`Authorization: Bearer <token>` on `/api/v1/...` owner endpoints, and on the
authenticated MCP server (`POST /mcp`) for owner data tools. Everything is
scoped to the token owner's restaurants — a token can never touch another
tenant.

## Token hygiene

- One token per integration; name them.
- Rotate by creating a new token and deleting the old.
- Send tokens in headers only. Production defaults `TOKEN_QUERY_FALLBACK=false`
  so query-string tokens are rejected; local/testing can temporarily enable
  the deprecated fallback for legacy clients.
- 401 = missing/invalid token; 403 = valid token, missing ability.
- Rate limit 120 req/min per token (this public dev MCP endpoint: 60/min).

## Storefront token scopes

Storefront tokens authenticate via the `X-Storefront-Token` header. A
deprecated `storefront_token` query fallback is available only when
`TOKEN_QUERY_FALLBACK=true`; keep it false in production. Each token carries
one or more scopes:

| Scope | Grants |
|-------|--------|
| `menu:read` | Read menu and restaurant info (`GET /api/v1/storefront/menu`, `GET /api/v1/storefront/restaurant`). Default scope. |
| `order:write` | Place orders (`POST /api/v1/storefront/orders`). Implicitly grants `menu:read`. |
| `agent` | Agent-scoped access to the Storefront MCP server (`POST /mcp/storefront`). |

A `:write` scope implicitly grants the matching `:read`. The wildcard `*`
grants all scopes. For write scopes the middleware enforces the token's
`allowed_origins` list when an `Origin` header is present.

## Other token types

- **Storefront token** — per-restaurant, scopes `menu:read`, `order:write`,
  `agent`. Public menu access and optional order placement. See the
  [storefront cookbook](storefront-cookbook.md).
- **Customer token** — end-customer bearer token obtained via register, login,
  or phone OTP. Used for ordering and profile management. See the
  [customer API](customer-orders.md).
- **Staff token** — issued via staff PIN or credential login
  (`POST /api/v1/stores/{id}/staff/login|pin`). Scoped to one restaurant.
  See the [Staff API](staff-api.md).
- **Driver token** — issued to delivery drivers. Scoped to assigned deliveries.
  See the [Driver API](driver-api.md).
