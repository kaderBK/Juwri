---
title: "Storefront & Customer APIs"
audience: developer
group: Dev
updated: 2026-07-25
---

# Storefront & Customer APIs

REST, JSON, versioned under `/api/v1`. Two token types with different trust:

## Storefront token (public, read-only)

- Header: `X-Storefront-Token: <token>`, scope `menu:read`.
- Per-restaurant; safe to embed in client code. Restaurant is resolved from
  the token, never from the request.
- `GET /api/v1/storefront/menu` — categories → items with `id`, `name`,
  `price`, `variants[]`, `extras[]`, `variant_groups[]`, `gallery[]`, flags.
- `GET /api/v1/storefront/restaurant` — restaurant profile.
- `POST /api/v1/storefront/orders` — place an order via the storefront token
  (alternative to the customer-token `POST /api/v1/customer/orders` flow).
  Needs the `order:write` scope, not `menu:read` — keep that token
  server-side.

## Agent scope (MCP)

A third scope, `agent`, unlocks `POST /mcp/storefront` — the JSON-RPC MCP
server that exposes the ordering surface as 12 tools for AI agents (search
menu, validate cart, create order, track). Same token header, same
per-restaurant resolution. Out-of-scope calls answer
`403 {"error": "Token lacks required scope: agent"}`. See
[MCP servers](mcp/mcp-servers.md#storefront-mcp).

## Customer token (ordering)

- Obtain via customer register / login / OTP endpoints; use as
  `Authorization: Bearer <token>`.
- `POST /api/v1/customer/orders` — body:
  `{"type":"takeaway|on-table|delivery","client_uuid":"<uuid>","items":[{"item_id":N,"quantity":N,"variation":N?,"extras":[N]}]}`.
- `client_uuid` is an idempotency key — resend the same uuid on retry, the
  order will not duplicate.
- `GET /api/v1/track/{track_token}` — order status for a placed order.

## Invariants

- Prices are computed server-side from the live menu. Requests never contain
  prices; a payload with prices is a bug in the client.
- `variation` and `extras[]` ids must come from the `/menu` response.
- Two limiters stack: 120 req/min per **storefront token**, plus the shared
  api-group limiter at 60/min per **IP** across all `/api/v1/*` traffic. A
  request must pass both, so one IP effectively tops out at 60/min however
  many tokens it holds. HTTP 429 on exceed — back off, do not retry in a
  tight loop.
- Webhooks (order.paid etc.) are signed with the Standard Webhooks scheme —
  verify signatures server-side; see the human docs at `/developers`.
